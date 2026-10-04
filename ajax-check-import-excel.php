<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

// Bắt fatal error (memory, class not found...) trả về JSON thay vì im lặng
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi PHP: ' . $err['message'] . ' (dòng ' . $err['line'] . ')',
        ], JSON_UNESCAPED_UNICODE);
    }
});

// Đảm bảo session.save_path đúng TRƯỚC khi start
$_session_path = __DIR__ . '/sessions';
if (!is_dir($_session_path)) mkdir($_session_path, 0755, true);
ini_set('session.save_path', $_session_path);
session_start();
require "config.php";
header('Content-Type: application/json; charset=utf-8');

// Tăng giới hạn tài nguyên cho việc đọc file Excel lớn
@ini_set('memory_limit', '256M');
@set_time_limit(60);

function json_exit(array $data): void {
    if (ob_get_level()) ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    json_exit(['success' => false, 'message' => 'Chưa đăng nhập']);
}

$order_id = (int)($_POST['order_id'] ?? 0);
if ($order_id <= 0) {
    json_exit(['success' => false, 'message' => 'Thiếu ID đơn hàng']);
}

function readTxtOrCsvFile($filePath) {
    $content = file_get_contents($filePath);
    if ($content === false) {
        throw new Exception("Không thể đọc nội dung file.");
    }
    
    // Kiểm tra và chuyển đổi bảng mã UTF-16 (Excel hay dùng khi xuất Unicode Text)
    if (substr($content, 0, 2) === "\xFF\xFE") {
        $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
    } elseif (substr($content, 0, 2) === "\xFE\xFF") {
        $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
    } elseif (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3); // Bỏ UTF-8 BOM
    }
    
    // Tách các dòng (hỗ trợ cả \r\n, \r, \n)
    $lines = preg_split('/\r\n|\r|\n/', $content);
    
    // Tự động nhận diện dấu phân cách bằng cách đếm số lần xuất hiện ở 10 dòng đầu
    $delimiter = "\t"; // Mặc định là Tab
    $possibleDelimiters = ["\t", ",", ";"];
    $counts = ["\t" => 0, "," => 0, ";" => 0];
    
    $sampleLines = array_slice($lines, 0, 10);
    foreach ($sampleLines as $line) {
        foreach ($possibleDelimiters as $delim) {
            $counts[$delim] += substr_count($line, $delim);
        }
    }
    
    arsort($counts);
    $detected = array_key_first($counts);
    if ($counts[$detected] > 0) {
        $delimiter = $detected;
    }
    
    $rows = [];
    $maxCols = 0;
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        
        // str_getcsv giúp handle cả các trường có dấu nháy bao bọc
        $cells = str_getcsv($line, $delimiter);
        
        $cells = array_map(function($val) {
            return $val !== null ? trim($val) : '';
        }, $cells);
        
        $rows[] = $cells;
        if (count($cells) > $maxCols) {
            $maxCols = count($cells);
        }
    }
    
    // Điền thêm các cột trống để đảm bảo các dòng đều có độ dài bằng cột dài nhất
    foreach ($rows as &$row) {
        if (count($row) < $maxCols) {
            $row = array_pad($row, $maxCols, '');
        }
    }
    
    return $rows;
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    json_exit(['success' => false, 'message' => 'Không có file hoặc lỗi khi tải lên']);
}

$fileName = $_FILES['excel_file']['name'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($ext === 'txt' || $ext === 'csv') {
    try {
        $allRows = readTxtOrCsvFile($_FILES['excel_file']['tmp_name']);
    } catch (Throwable $e) {
        json_exit(['success' => false, 'message' => 'Không đọc được file TXT/CSV: ' . $e->getMessage()]);
    }
} else {
    if (!file_exists('vendor/autoload.php')) {
        json_exit(['success' => false, 'message' => 'Thiếu thư viện PhpSpreadsheet (chạy composer install)']);
    }
    
    require_once 'vendor/autoload.php';
    
    // -------------------------------------------------------
    // ĐỌC FILE EXCEL
    // toArray(null, false, false, false) → giá trị raw, không format
    // -------------------------------------------------------
    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($_FILES['excel_file']['tmp_name']);
        $reader->setReadDataOnly(true); // Bỏ qua styles/format, chỉ lấy giá trị thô — nhanh hơn ~40%
        $spreadsheet = $reader->load($_FILES['excel_file']['tmp_name']);
        $sheet       = $spreadsheet->getActiveSheet();
        
        // Giới hạn vùng đọc theo dữ liệu thực tế để tránh file Excel có hàng triệu dòng trống ở cuối
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $allRows = $sheet->rangeToArray('A1:' . $highestColumn . $highestRow, null, false, false, false);
    } catch (Throwable $e) {
        json_exit(['success' => false, 'message' => 'Không đọc được file Excel: ' . $e->getMessage()]);
    }
}

// Excel lưu serial/IMEI dạng số (cột không format Text) sẽ bị PHP đọc thành float
// và ép (string) ra ký hiệu khoa học (VD: 3.59E+14), làm sai lệch so với DB.
// Chuẩn hóa lại các ô dạng số nguyên về chuỗi số thật trước khi xử lý.
array_walk_recursive($allRows, function (&$v) {
    if (is_float($v) && $v == (int)$v && abs($v) < 9.0e15) {
        $v = (string)(int)$v;
    } elseif (is_int($v)) {
        $v = (string)$v;
    }
});

if (empty($allRows)) {
    json_exit(['success' => false, 'message' => 'File rỗng']);
}

// -------------------------------------------------------
// PARSE FORMAT XUẤT: mỗi cấu hình = 4 cột + 1 cột trống
// Header máy:  [Máy X] | [Tên cấu hình] | [IMEI]  | []
// Sub-header:  [Thành Phần] | [Mã SP] | [] | [SLƯỢNG]
// Linh kiện:   [CPU/MAIN/...] | [Tên] | [Serial] | [1]
// Dòng trống ngăn cách giữa các máy
// -------------------------------------------------------

$machineBlocks = []; // [{col, row, so_may, cfg_name, imei}]

foreach ($allRows as $rIdx => $row) {
    foreach ($row as $cIdx => $cellVal) {
        $cellStr = trim((string)($cellVal ?? ''));
        if (preg_match('/^máy\s*(\d+)$/ui', $cellStr, $m)) {
            $so_may   = (int)$m[1];
            $cfg_name = trim((string)($row[$cIdx + 1] ?? ''));
            $imei_raw = trim((string)($row[$cIdx + 2] ?? ''));
            // Bỏ khoảng trắng đầu mà file xuất thêm vào IMEI (" " + giá trị)
            $imei = ltrim($imei_raw, " \t");

            $machineBlocks[] = [
                'col'      => $cIdx,
                'row'      => $rIdx,
                'so_may'   => $so_may,
                'cfg_name' => $cfg_name,
                'imei'     => $imei,
                'items'    => [],
            ];
        }
    }
}

if (empty($machineBlocks)) {
    json_exit(['success' => false, 'message' => 'Không tìm thấy dòng "Máy X" trong file. Hãy dùng đúng file Excel được xuất từ hệ thống.']);
}

// Với mỗi block: đọc các dòng linh kiện
// Dòng linh kiện bắt đầu từ block_row + 2 (bỏ sub-header ở +1)
// Kết thúc khi gặp "Máy X" tiếp theo trong cùng cột, hoặc dòng trống, hoặc hết sheet

// Nhóm block theo cột để tìm end row dễ hơn
$blocksByCol = [];
foreach ($machineBlocks as $bIdx => $block) {
    $blocksByCol[$block['col']][] = $bIdx;
}

foreach ($blocksByCol as $col => $bIdxList) {
    // Sắp xếp theo row
    usort($bIdxList, fn($a, $b) => $machineBlocks[$a]['row'] <=> $machineBlocks[$b]['row']);

    for ($bi = 0; $bi < count($bIdxList); $bi++) {
        $bIdx  = $bIdxList[$bi];
        $block = &$machineBlocks[$bIdx];
        $startRow = $block['row'] + 2; // bỏ dòng sub-header

        // Tìm end row: row của block tiếp theo trong cùng cột
        $endRow = count($allRows);
        if ($bi + 1 < count($bIdxList)) {
            $endRow = $machineBlocks[$bIdxList[$bi + 1]]['row'];
        }

        // Theo dõi last known type/model cho merged cells
        $lastType  = '';
        $lastModel = '';

        for ($r = $startRow; $r < $endRow; $r++) {
            $row = $allRows[$r] ?? [];

            $typeCell   = trim((string)($row[$col]      ?? ''));
            $modelCell  = trim((string)($row[$col + 1]  ?? ''));
            $serialCell = trim((string)($row[$col + 2]  ?? ''));

            // Nếu cả 3 ô chính trong block này của hàng đều trống thì máy này đã hết danh sách linh kiện
            if ($typeCell === '' && $modelCell === '' && $serialCell === '') {
                break;
            }

            // Merged cells trong cột A/B → giữ lại giá trị từ dòng đầu của merge
            // Reset model khi chuyển sang loại linh kiện mới (tránh kế thừa sai)
            if ($typeCell !== '' && $typeCell !== $lastType) $lastModel = '';
            if ($typeCell  !== '') $lastType  = $typeCell;
            if ($modelCell !== '') $lastModel = $modelCell;
            $type  = $lastType;
            $model = $lastModel;

            if ($type === '' || mb_strtolower($type, 'UTF-8') === 'thành phần') continue;

            $rowHasData = false;
            foreach ($row as $v) {
                if (trim((string)($v ?? '')) !== '') { $rowHasData = true; break; }
            }
            if (!$rowHasData) { $lastType = ''; $lastModel = ''; break; }

            $block['items'][] = [
                'type'        => $type,
                'model'       => $model,
                'model_fresh' => ($modelCell !== ''),
                'serial'      => $serialCell,
            ];
        }
        unset($block);
    }
}

// -------------------------------------------------------
// LƯU DỮ LIỆU PARSE VÀO FILE TẠM (để bước import dùng lại, không cần đọc Excel lần 2)
// -------------------------------------------------------
$_cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'excel_import_' . session_id() . '_' . $order_id . '.json';
file_put_contents($_cacheFile, json_encode($machineBlocks, JSON_UNESCAPED_UNICODE));

// -------------------------------------------------------
// LẤY DỮ LIỆU DB MỘT LẦN
// -------------------------------------------------------
$dbRows = []; // [so_may][loai_lower][] = serial_lower
try {
    $stmt = $pdo->prepare("SELECT so_may, loai_linhkien, so_serial FROM chitiet_donhang WHERE id_donhang = ? AND so_serial IS NOT NULL AND so_serial <> ''");
    $stmt->execute([$order_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $may    = (int)$r['so_may'];
        $type   = mb_strtolower(trim($r['loai_linhkien']), 'UTF-8');
        $serial = mb_strtolower(trim($r['so_serial']), 'UTF-8');
        $dbRows[$may][$type][] = $serial;
    }
} catch (PDOException $e) {
    json_exit(['success' => false, 'message' => 'Lỗi DB: ' . $e->getMessage()]);
}

// IMEI từ cột JSON donhang.imei
$orderImeis = [];
try {
    $s2 = $pdo->prepare("SELECT imei FROM donhang WHERE id_donhang = ?");
    $s2->execute([$order_id]);
    $raw = $s2->fetchColumn();
    if ($raw) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) $orderImeis = array_map('strtolower', array_map('trim', $dec));
    }
} catch (PDOException $e) {}

// Tất cả IMEI/IMER của đơn hàng (không phân biệt so_may)
// Dùng làm fallback khi so_may = 0/NULL (chưa gán máy)
$allOrderImeiSerials = [];
try {
    $sAll = $pdo->prepare("SELECT LOWER(TRIM(so_serial)) FROM chitiet_donhang
                           WHERE id_donhang = ? AND UPPER(loai_linhkien) IN ('IMEI','IMER')
                           AND so_serial IS NOT NULL AND so_serial <> ''");
    $sAll->execute([$order_id]);
    $allOrderImeiSerials = $sAll->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// Tên linh kiện (model) từ DB cho đơn hàng — dùng để kiểm tra model trong Excel
$dbTenLinhKien = []; // loai_lower => [ten_lower => true]
try {
    $sTen = $pdo->prepare(
        "SELECT LOWER(TRIM(loai_linhkien)) as loai, LOWER(TRIM(ten_linhkien)) as ten
         FROM chitiet_donhang WHERE id_donhang = ?
         AND ten_linhkien IS NOT NULL AND ten_linhkien <> ''"
    );
    $sTen->execute([$order_id]);
    foreach ($sTen->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dbTenLinhKien[$r['loai']][$r['ten']] = true;
    }
} catch (PDOException $e) {}

// Map tên display từ file xuất → từ khóa loại DB
$typeMap = [
    'cpu'           => ['cpu'],
    'mainboard'     => ['main', 'mainboard'],
    'ram'           => ['ram'],
    'ssd'           => ['ssd'],
    'hdd'           => ['hdd'],
    'ổ cứng'        => ['hdd'],
    'hard disk'     => ['hdd'],
    'harddisk'      => ['hdd'],
    'đồ họa'        => ['vga'],
    'nguồn'         => ['psu'],
    'case'          => ['case'],
    'tản'           => ['fan'],
    'hệ điều hành'  => ['win', 'windows'],
    'phần mềm'      => ['win', 'software'],
    'key board'     => ['key'],
    'mouse'         => ['mouse'],
    'lcd'           => ['lcd'],
    // fallback bắt thêm các tên viết tắt có thể xuất hiện
    'main'          => ['main', 'mainboard'],
    'vga'           => ['vga'],
    'psu'           => ['psu'],
    'fan'           => ['fan'],
    'win'           => ['win'],
    'windows'       => ['win'],
];

// Kiểm tra tên loại linh kiện trong file Excel có hợp lệ không
foreach ($machineBlocks as $block) {
    foreach ($block['items'] as $it) {
        $typeKey = mb_strtolower(trim($it['type']), 'UTF-8');
        if (!array_key_exists($typeKey, $typeMap)) {
            json_exit(['success' => false,
                'message' => "❌ Tên linh kiện \"" . $it['type'] . "\" tại Máy {$block['so_may']} không được nhận dạng. Vui lòng kiểm tra lại tên trong cột \"Thành Phần\" của file Excel.\nCác tên hợp lệ: CPU, Mainboard, Main, RAM, SSD, HDD, Đồ họa, VGA, Nguồn, PSU, Case, Tản, Fan, Hệ điều hành, Phần mềm, Win, Windows, Key Board, Mouse, LCD."
            ]);
        }
    }
}

// Kiểm tra tên model linh kiện trong Excel phải khớp với DB của đơn hàng
foreach ($machineBlocks as $block) {
    foreach ($block['items'] as $it) {
        $typeKey   = mb_strtolower(trim($it['type']), 'UTF-8');
        $keywords  = $typeMap[$typeKey] ?? [$typeKey];
        $modelName = trim($it['model']);

        $typeHasModels = false;
        foreach ($keywords as $kw) {
            foreach ($dbTenLinhKien as $dbLoai => $tenMap) {
                if (str_contains($dbLoai, $kw) && !empty($tenMap)) {
                    $typeHasModels = true;
                    break 2;
                }
            }
        }

        if ($modelName === '') {
            // Excel không có tên model — báo lỗi nếu DB yêu cầu model
            if ($typeHasModels) {
                json_exit(['success' => false,
                    'message' => "❌ Thiếu tên linh kiện ({$it['type']}) tại Máy {$block['so_may']}. Vui lòng điền tên model trong file Excel."
                ]);
            }
            continue;
        }

        // Model có giá trị — kiểm tra khớp với DB
        if (!$typeHasModels) continue; // DB không có model cho loại này, bỏ qua

        $modelLower = mb_strtolower($modelName, 'UTF-8');
        $foundInDb  = false;
        foreach ($keywords as $kw) {
            foreach ($dbTenLinhKien as $dbLoai => $tenMap) {
                if (str_contains($dbLoai, $kw) && isset($tenMap[$modelLower])) {
                    $foundInDb = true;
                    break 2;
                }
            }
        }

        if (!$foundInDb) {
            json_exit(['success' => false,
                'message' => "❌ Tên linh kiện \"$modelName\" ({$it['type']}) tại Máy {$block['so_may']} không khớp với đơn hàng #$order_id. Vui lòng kiểm tra lại tên model trong file Excel."
            ]);
        }
    }
}

function getDbSerials(array $dbRows, int $so_may, string $displayType): array
{
    global $typeMap;
    $key      = mb_strtolower(trim($displayType), 'UTF-8');
    $keywords = $typeMap[$key] ?? [$key];
    $result   = [];
    foreach ($keywords as $kw) {
        foreach ($dbRows[$so_may] ?? [] as $dbType => $serials) {
            if (str_contains($dbType, $kw)) {
                $result = array_merge($result, $serials);
            }
        }
    }
    return $result;
}

// ĐẶT Ở NGOÀI HÀM, phạm vi toàn cục
$noWarnTypes = ['case', 'tản', 'fan', 'key board', 'mouse', 'lcd', 'hệ điều hành', 'phần mềm', 'win', 'windows'];

// -------------------------------------------------------
// KIỂM TRA TỪNG BLOCK MÁY
// -------------------------------------------------------
$resultRows   = [];
$totalOk      = 0;
$totalErrors  = 0;

// Nhóm blocks theo so_may để render gọn (mỗi máy 1 dòng trong bảng)
$byMachine = []; // so_may → {cfg_name, imei, items[], has_error}
foreach ($machineBlocks as $block) {
    $may = $block['so_may'];
    if (!isset($byMachine[$may])) {
        $byMachine[$may] = [
            'so_may'    => $may,
            'cfg_name'  => $block['cfg_name'],
            'imei'      => $block['imei'],
            'items'     => [],
            'has_error' => false,
        ];
    }
    // Gộp items từ nhiều config block cùng số máy (hiếm nhưng có thể có)
    foreach ($block['items'] as $it) {
        $byMachine[$may]['items'][] = $it;
    }
}
ksort($byMachine);

// Tập hợp tất cả loại linh kiện gặp → làm cột kết quả
$allTypes = [];
foreach ($byMachine as $machine) {
    foreach ($machine['items'] as $it) {
        $typeNorm = mb_strtolower(trim($it['type']), 'UTF-8');
        if (!in_array($typeNorm, $allTypes)) $allTypes[] = $typeNorm;
    }
}

foreach ($byMachine as $may => $machine) {
    $cells    = [];
    $hasError = false;

    // Kiểm tra IMEI
    $imeiVal = $machine['imei'];
    if ($imeiVal !== '') {
        $dbImei   = array_merge(
            getDbSerials($dbRows, $may, 'imei'),
            getDbSerials($dbRows, $may, 'imer')
        );
        $jsonImei = $orderImeis[$may - 1] ?? '';
        $imeiLower = mb_strtolower($imeiVal, 'UTF-8');
        $matched  = (empty($allOrderImeiSerials) && empty($orderImeis))
                 || in_array($imeiLower, $dbImei)
                 || ($jsonImei !== '' && $imeiLower === $jsonImei)
                 || in_array($imeiLower, $allOrderImeiSerials);
        $cells['imei'] = ['status' => $matched ? 'ok' : 'error', 'value' => $imeiVal,
                          'note' => $matched ? '' : 'IMEI không khớp DB'];
        if (!$matched) $hasError = true;
    } else {
        $cells['imei'] = ['status' => 'skip', 'value' => ''];
    }

    // Kiểm tra từng serial linh kiện
    // Gom theo type: có thể nhiều dòng cùng type (RAM x2, SSD x2...)
    $itemsByType = [];
    foreach ($machine['items'] as $it) {
        $typeNorm = mb_strtolower(trim($it['type']), 'UTF-8');
        $itemsByType[$typeNorm][] = $it['serial'];
    }

    foreach ($allTypes as $typeNorm) {
        $serials  = $itemsByType[$typeNorm] ?? [];
        $cellSerials = [];

        $requiresSerial = !in_array($typeNorm, $noWarnTypes, true) && !in_array($typeNorm, ['hệ điều hành', 'phần mềm', 'win', 'windows'], true);

        foreach ($serials as $sn) {
            if ($sn === '' || $sn === '-' || $sn === '—') {
                $cellSerials[] = ['status' => $requiresSerial ? 'warn' : 'skip', 'value' => ''];
            } else {
                $cellSerials[] = ['status' => 'ok', 'value' => $sn];
            }
        }

        if (empty($cellSerials)) {
            $cells[$typeNorm] = ['status' => 'skip', 'value' => ''];
        } elseif (count($cellSerials) === 1) {
            $cells[$typeNorm] = $cellSerials[0];
        } else {
            // Nhiều serial cùng loại → gộp thành 1 ô multi
            $anyError  = array_filter($cellSerials, fn($c) => $c['status'] === 'error');
            $allSkip   = count(array_filter($cellSerials, fn($c) => $c['status'] === 'skip')) === count($cellSerials);
            $cells[$typeNorm] = [
                'status' => $allSkip ? 'skip' : ($anyError ? 'error' : 'ok'),
                'value'  => implode(' / ', array_column(array_filter($cellSerials, fn($c) => $c['value'] !== ''), 'value')),
                'multi'  => $cellSerials,
            ];
        }
    }

    $row = [
        'so_may'     => $may,
        'cfg_name'   => $machine['cfg_name'],
        'cells'      => $cells,
        'row_status' => $hasError ? 'error' : 'ok',
    ];
    $resultRows[] = $row;
    if ($hasError) $totalErrors++; else $totalOk++;
}

// Build columns list (IMEI luôn đầu, rồi các loại linh kiện)
$typeDisplayMap = [
    'cpu'          => 'CPU',
    'mainboard'    => 'Mainboard',
    'ram'          => 'RAM',
    'ssd'          => 'SSD',
    'hdd'          => 'HDD',
    'đồ họa'       => 'Đồ họa',
    'nguồn'        => 'Nguồn (PSU)',
    'case'         => 'Case',
    'tản'          => 'Quạt (FAN)',
    'hệ điều hành' => 'Hệ Điều Hành',
    'phần mềm'     => 'Phần Mềm',
    'key board'    => 'Bàn phím',
    'mouse'        => 'Chuột',
    'lcd'          => 'Màn hình (LCD)',
    'main'         => 'Mainboard',
    'vga'          => 'VGA',
    'psu'          => 'Nguồn (PSU)',
    'fan'          => 'Quạt (FAN)',
    'win'          => 'Windows',
    'windows'      => 'Windows',
];

$columns = [['key' => 'imei', 'label' => 'IMEI / IMER']];
foreach ($allTypes as $t) {
    $columns[] = ['key' => $t, 'label' => $typeDisplayMap[$t] ?? strtoupper($t)];
}

ob_end_clean(); // xả buffer, loại bỏ mọi output PHP lọt vào
echo json_encode([
    'success'    => true,
    'columns'    => $columns,
    'rows'       => $resultRows,
    'cache_token'=> session_id() . '_' . $order_id, // token để bước import nhận ra cache
    'summary'    => [
        'total'    => count($resultRows),
        'ok'       => $totalOk,
        'errors'   => $totalErrors,
        'warnings' => 0,
    ],
], JSON_UNESCAPED_UNICODE);