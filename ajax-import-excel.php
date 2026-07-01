<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false,
            'message' => 'Lỗi PHP: ' . $err['message'] . ' (dòng ' . $err['line'] . ')'],
            JSON_UNESCAPED_UNICODE);
    }
});

session_start();
require "config.php";
header('Content-Type: application/json; charset=utf-8');

@ini_set('memory_limit', '256M');
@set_time_limit(60);

function jimport_exit(array $data): void {
    if (ob_get_level()) ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) jimport_exit(['success' => false, 'message' => 'Chưa đăng nhập']);
$session_user_id = (int)$_SESSION['user_id'];

$order_id = (int)($_POST['order_id'] ?? 0);
if ($order_id <= 0) jimport_exit(['success' => false, 'message' => 'Thiếu ID đơn hàng']);

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK)
    jimport_exit(['success' => false, 'message' => 'Không có file hoặc lỗi khi tải lên']);

if (!file_exists('vendor/autoload.php'))
    jimport_exit(['success' => false, 'message' => 'Thiếu thư viện PhpSpreadsheet']);

require_once 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// -------------------------------------------------------
// ĐỌC FILE EXCEL (giống ajax-check-import-excel.php)
// -------------------------------------------------------
try {
    $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);
    $sheet       = $spreadsheet->getActiveSheet();
    $allRows     = $sheet->toArray(null, false, false, false);
} catch (Throwable $e) {
    jimport_exit(['success' => false, 'message' => 'Không đọc được file: ' . $e->getMessage()]);
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

if (empty($allRows)) jimport_exit(['success' => false, 'message' => 'File rỗng']);

// -------------------------------------------------------
// PARSE: tìm tất cả block "Máy X"
// -------------------------------------------------------
$machineBlocks = [];
foreach ($allRows as $rIdx => $row) {
    foreach ($row as $cIdx => $cellVal) {
        if (preg_match('/^máy\s*(\d+)$/ui', trim((string)($cellVal ?? '')), $m)) {
            $machineBlocks[] = [
                'col'      => $cIdx,
                'row'      => $rIdx,
                'so_may'   => (int)$m[1],
                'cfg_name' => trim((string)($row[$cIdx + 1] ?? '')),
                'imei'     => ltrim(trim((string)($row[$cIdx + 2] ?? '')), " \t"),
                'items'    => [],
            ];
        }
    }
}

if (empty($machineBlocks))
    jimport_exit(['success' => false, 'message' => 'Không tìm thấy dòng "Máy X" trong file']);

// Đọc items cho mỗi block
$blocksByCol = [];
foreach ($machineBlocks as $bIdx => $b) $blocksByCol[$b['col']][] = $bIdx;

foreach ($blocksByCol as $col => $bIdxList) {
    usort($bIdxList, fn($a, $b) => $machineBlocks[$a]['row'] <=> $machineBlocks[$b]['row']);
    for ($bi = 0; $bi < count($bIdxList); $bi++) {
        $bIdx  = $bIdxList[$bi];
        $block = &$machineBlocks[$bIdx];
        $startRow = $block['row'] + 2;
        $endRow   = ($bi + 1 < count($bIdxList)) ? $machineBlocks[$bIdxList[$bi + 1]]['row'] : count($allRows);

        $lastType = $lastModel = '';
        for ($r = $startRow; $r < $endRow; $r++) {
            $row = $allRows[$r] ?? [];
            $typeCell  = trim((string)($row[$col]     ?? ''));
            $modelCell = trim((string)($row[$col + 1] ?? ''));
            $serialCell= trim((string)($row[$col + 2] ?? ''));

            // Reset model khi chuyển sang loại linh kiện mới (tránh kế thừa sai)
            if ($typeCell !== '' && $typeCell !== $lastType) $lastModel = '';
            if ($typeCell  !== '') $lastType  = $typeCell;
            if ($modelCell !== '') $lastModel = $modelCell;

            $type = $lastType;
            if ($type === '' || mb_strtolower($type, 'UTF-8') === 'thành phần') continue;

            $hasData = false;
            foreach ($row as $v) { if (trim((string)($v ?? '')) !== '') { $hasData = true; break; } }
            if (!$hasData) { $lastType = $lastModel = ''; break; }

            $block['items'][] = ['type' => $type, 'model' => $lastModel, 'model_fresh' => ($modelCell !== ''), 'serial' => $serialCell];
        }
        unset($block);
    }
}

// -------------------------------------------------------
// LẤY DỮ LIỆU DB (IMEI)
// -------------------------------------------------------
$orderImeis = [];
try {
    $s = $pdo->prepare("SELECT imei FROM donhang WHERE id_donhang = ?");
    $s->execute([$order_id]);
    $raw = $s->fetchColumn();
    if ($raw) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) $orderImeis = array_map(fn($v) => mb_strtolower(trim((string)$v), 'UTF-8'), $dec);
    }
} catch (PDOException $e) {}

// IMEI từ chitiet_donhang — theo so_may VÀ toàn đơn hàng (fallback)
$dbImeiRows = []; // so_may → [serial_lower, ...]
$allOrderImeiSerials = []; // tất cả IMEI/IMER của đơn, không phân biệt so_may
try {
    $s2 = $pdo->prepare("SELECT so_may, so_serial FROM chitiet_donhang WHERE id_donhang = ? AND UPPER(loai_linhkien) IN ('IMEI','IMER') AND so_serial IS NOT NULL AND so_serial <> ''");
    $s2->execute([$order_id]);
    foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sl = mb_strtolower(trim($r['so_serial']), 'UTF-8');
        $dbImeiRows[(int)$r['so_may']][] = $sl;
        $allOrderImeiSerials[] = $sl;
    }
} catch (PDOException $e) {}

// -------------------------------------------------------
// MAP TÊN HIỂN THỊ → LOẠI DB
// -------------------------------------------------------
function getTypeKeywordsForImport(string $displayType): array {
    $map = [
        'cpu'          => ['cpu'],
        'mainboard'    => ['main', 'mainboard'],
        'main'         => ['main', 'mainboard'],
        'ram'          => ['ram'],
        'ssd'          => ['ssd', 'hdd'],
        'hdd'          => ['ssd', 'hdd'],
        'đồ họa'       => ['vga'],
        'vga'          => ['vga'],
        'nguồn'        => ['psu'],
        'psu'          => ['psu'],
        'case'         => ['case'],
        'tản'          => ['fan'],
        'fan'          => ['fan'],
        'hệ điều hành' => ['win', 'windows'],
        'phần mềm'     => ['win', 'software'],
        'win'          => ['win'],
        'windows'      => ['win'],
        'key board'    => ['key'],
        'mouse'        => ['mouse'],
        'lcd'          => ['lcd'],
    ];
    $key = mb_strtolower(trim($displayType), 'UTF-8');
    return $map[$key] ?? [$key];
}
function isSerialRequired(string $type): bool {
    $type = mb_strtolower(trim($type), 'UTF-8');
    $noSerialTypes = ['case', 'vỏ case', 'vo case'];
    return !in_array($type, $noSerialTypes, true);
}
function isOsType(string $type): bool {
    $type = mb_strtolower(trim($type), 'UTF-8');
    return in_array($type, ['hệ điều hành', 'phần mềm', 'win', 'windows'], true);
}

// Hàm tìm và cập nhật serial cho 1 linh kiện
function importOneSerial(PDO $pdo, int $order_id, int $so_may, string $cfgNorm,
                          string $displayType, string $modelName, string $serial): string
{
    $keywords = getTypeKeywordsForImport($displayType);
    $kw_likes = array_map(fn($k) => '%' . $k . '%', $keywords);
    $placeholders = implode(' OR ', array_fill(0, count($keywords), 'LOWER(loai_linhkien) LIKE ?'));

    // Chiến lược 1: khớp so_may + ten_linhkien
    $sql = "SELECT id_ct FROM chitiet_donhang
            WHERE id_donhang = ? AND so_may = ?  AND ($placeholders) AND ten_linhkien = ?
            ORDER BY id_ct ASC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$order_id, $so_may], $kw_likes, [$modelName]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Chiến lược 2: khớp so_may, không cần ten_linhkien
    if (!$row) {
        $sql2 = "SELECT id_ct FROM chitiet_donhang
                 WHERE id_donhang = ? AND so_may = ? AND ($placeholders)
                 ORDER BY id_ct ASC LIMIT 1";
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute(array_merge([$order_id, $so_may], $kw_likes));
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    // Chiến lược 3: hàng chưa gán (so_may NULL/0), khớp ten_linhkien
    if (!$row) {
        $sql3 = "SELECT id_ct FROM chitiet_donhang
                 WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($placeholders) AND ten_linhkien = ?
                 ORDER BY id_ct ASC LIMIT 1";
        $stmt3 = $pdo->prepare($sql3);
        $stmt3->execute(array_merge([$order_id], $kw_likes, [$modelName]));
        $row = $stmt3->fetch(PDO::FETCH_ASSOC);
    }

    // Chiến lược 4: hàng chưa gán, không cần ten_linhkien
    if (!$row) {
        $sql4 = "SELECT id_ct FROM chitiet_donhang
                 WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($placeholders)
                 ORDER BY id_ct ASC LIMIT 1";
        $stmt4 = $pdo->prepare($sql4);
        $stmt4->execute(array_merge([$order_id], $kw_likes));
        $row = $stmt4->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return 'not_found';

    // Cập nhật serial + so_may + linhkien_chon (giống luu-serial-db.php)
    $upd = $pdo->prepare("UPDATE chitiet_donhang
                          SET so_serial = ?, so_may = ?, linhkien_chon = ?, user_id = NULL, user_id_save = NULL
                          WHERE id_ct = ?");
    $upd->execute([$serial, $so_may, $cfgNorm, $row['id_ct']]);
    return 'ok';
}

// -------------------------------------------------------
// THỰC HIỆN NHẬP
// -------------------------------------------------------
$results       = [];
$totalImported = 0;
$totalSkipped  = 0;
$totalNotFound = 0;

// Nhóm theo cấu hình và số máy
$byMachine = [];
foreach ($machineBlocks as $b) {
    $mayKey = mb_strtolower(trim($b['cfg_name']), 'UTF-8') . '_' . $b['so_may'];
    if (!isset($byMachine[$mayKey])) {
        $byMachine[$mayKey] = [
            'so_may'   => $b['so_may'], 
            'cfg_name' => $b['cfg_name'],
            'imei'     => $b['imei'], 
            'items'    => []
        ];
    }
    foreach ($b['items'] as $it) $byMachine[$mayKey]['items'][] = $it;
}

// -------------------------------------------------------
// KIỂM TRA: tên linh kiện trong file Excel phải hợp lệ
// -------------------------------------------------------
$validImportTypes = [
    'cpu', 'mainboard', 'main', 'ram', 'ssd', 'hdd',
    'đồ họa', 'vga', 'nguồn', 'psu', 'case',
    'tản', 'fan', 'hệ điều hành', 'phần mềm',
    'win', 'windows', 'key board', 'mouse', 'lcd',
];

foreach ($byMachine as $machine) {
    foreach ($machine['items'] as $it) {
        $typeKey = mb_strtolower(trim($it['type']), 'UTF-8');
        if (!in_array($typeKey, $validImportTypes, true)) {
            jimport_exit(['success' => false,
                'message' => "❌ Tên linh kiện \"" . $it['type'] . "\" tại Máy {$machine['so_may']} ({$machine['cfg_name']}) không được nhận dạng. Vui lòng kiểm tra lại tên trong cột \"Thành Phần\" của file Excel.\nCác tên hợp lệ: CPU, Mainboard, Main, RAM, SSD, HDD, Đồ họa, VGA, Nguồn, PSU, Case, Tản, Fan, Hệ điều hành, Phần mềm, Win, Windows, Key Board, Mouse, LCD."
            ]);
        }
    }
}

// -------------------------------------------------------
// KIỂM TRA: serial trong file Excel phải khớp với DB
// -------------------------------------------------------

// Tải toàn bộ serial đã nhập cho đơn hàng này (từ nhap-serial.php), theo TỪNG máy + TỪNG loại linh kiện
// (không dùng 1 tập phẳng chung cho cả đơn — nếu không, serial của CPU máy A có thể trùng ngẫu nhiên
// với serial của RAM máy B và làm "khớp giả" dù thực chất sai linh kiện/sai máy)
$dbSerialsByMachine = []; // [so_may][loai_linhkien_lower] => [ ['serial'=>.., 'cauhinh'=>..], ... ]  (so_may=0 = chưa gán máy)
try {
    $stAll = $pdo->prepare(
        "SELECT so_may, LOWER(TRIM(loai_linhkien)) as loai, LOWER(TRIM(so_serial)) as serial,
                LOWER(TRIM(ten_cauhinh)) as cauhinh
         FROM chitiet_donhang
         WHERE id_donhang = ? AND so_serial IS NOT NULL AND so_serial <> ''"
    );
    $stAll->execute([$order_id]);
    foreach ($stAll->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dbSerialsByMachine[(int)($r['so_may'] ?? 0)][$r['loai']][] = ['serial' => $r['serial'], 'cauhinh' => $r['cauhinh']];
    }
} catch (PDOException $e) {
    jimport_exit(['success' => false, 'message' => 'Lỗi tải dữ liệu: ' . $e->getMessage()]);
}

// $cfgName: tên cấu hình (từ Excel) — bắt buộc lọc theo đúng cấu hình, tránh 2 máy khác cấu hình
// (nên khác linh kiện) nhưng cùng đang ở pool "chưa gán máy" (so_may=0) bị lẫn serial của nhau.
function getDbSerialsForImport(array $dbSerialsByMachine, int $so_may, string $displayType, string $cfgName): array {
    $keywords = getTypeKeywordsForImport($displayType);
    $cfgNorm  = mb_strtolower(trim($cfgName), 'UTF-8');
    $result   = [];
    // Ưu tiên serial đã gán đúng máy này
    foreach ($keywords as $kw) {
        foreach ($dbSerialsByMachine[$so_may] ?? [] as $dbLoai => $entries) {
            if (!str_contains($dbLoai, $kw)) continue;
            foreach ($entries as $e) {
                if (str_contains($e['cauhinh'], $cfgNorm)) $result[] = $e['serial'];
            }
        }
    }
    // Fallback: serial cùng loại linh kiện + đúng cấu hình nhưng chưa gán máy (so_may = 0/NULL)
    foreach ($keywords as $kw) {
        foreach ($dbSerialsByMachine[0] ?? [] as $dbLoai => $entries) {
            if (!str_contains($dbLoai, $kw)) continue;
            foreach ($entries as $e) {
                if (str_contains($e['cauhinh'], $cfgNorm)) $result[] = $e['serial'];
            }
        }
    }
    return $result;
}

// Tải tên linh kiện (model) từ DB để kiểm tra khớp với Excel
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
} catch (PDOException $e) {
    jimport_exit(['success' => false, 'message' => 'Lỗi tải dữ liệu: ' . $e->getMessage()]);
}

// Kiểm tra tên model linh kiện trong Excel phải khớp với DB của đơn hàng
foreach ($byMachine as $machine) {
    foreach ($machine['items'] as $it) {
        $keywords  = getTypeKeywordsForImport($it['type']);
        $modelName = trim($it['model']);

        // Hàm kiểm tra loại linh kiện có tên model trong DB không
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
                jimport_exit(['success' => false,
                    'message' => "❌ Thiếu tên linh kiện ({$it['type']}) tại Máy {$machine['so_may']} ({$machine['cfg_name']}). Vui lòng điền tên model trong file Excel."
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
            jimport_exit(['success' => false,
                'message' => "❌ Tên linh kiện \"$modelName\" ({$it['type']}) tại Máy {$machine['so_may']} ({$machine['cfg_name']}) không khớp với đơn hàng #$order_id. Vui lòng kiểm tra lại tên model trong file Excel."
            ]);
        }
    }
}

// Kiểm tra từng serial linh kiện trong file
$seenSerialsInExcel = []; // Để kiểm tra trùng lặp serial trong chính file Excel

foreach ($byMachine as $mayKey => $machine) {
    $may = $machine['so_may'];
    $cfg = $machine['cfg_name'];
    $totalItems = count($machine['items']);
    $filledCount = 0;
    $emptyCount = 0;

    foreach ($machine['items'] as $it) {
        $sn = trim($it['serial']);
        $isOs = isOsType($it['type']);

        if ($sn === '' || $sn === '-' || $sn === '—') {
            $emptyCount++;

            // ✅ Chỉ bắt buộc nếu ĐÚNG máy này đã có sẵn serial cho loại linh kiện này trong SQL
            // (riêng Hệ điều hành/Win/Phần mềm không bao giờ bắt buộc serial)
            $dbSerialsForThisEmpty = getDbSerialsForImport($dbSerialsByMachine, $may, $it['type'], $cfg);
            if (!$isOs && !empty($dbSerialsForThisEmpty)) {
                $modelText = $it['model'] !== '' ? " ({$it['model']})" : '';
                jimport_exit(['success' => false,
                    'message' => "❌ Thiếu Serial: Linh kiện \"{$it['type']}\"{$modelText} tại Máy {$may} - {$cfg} chưa có số Serial trong file Excel. Vui lòng điền đầy đủ trước khi import."
                ]);
            }
            continue; // máy này chưa có serial cho loại này trong SQL → bỏ qua, không báo lỗi
        }
        $filledCount++;
        if ($isOs) continue; // Hệ điều hành: không kiểm tra trùng lặp/khớp DB cho serial

        $snLower = mb_strtolower($sn, 'UTF-8');

        $typeNorm = mb_strtolower($it['type'], 'UTF-8');
        // 1. Kiểm tra trùng lặp trong file Excel (theo từng loại linh kiện)
        if (isset($seenSerialsInExcel[$typeNorm][$snLower])) {
            $prev = $seenSerialsInExcel[$typeNorm][$snLower];
            jimport_exit(['success' => false,
                'message' => "Lỗi trùng lặp Serial: Số Serial \"$sn\" bị nhập trùng ở Máy $may - $cfg và Máy {$prev['may']} - {$prev['cfg']} (Loại: {$it['type']}). Mỗi Serial chỉ được dùng cho một linh kiện duy nhất!"
            ]);
        }
        $seenSerialsInExcel[$typeNorm][$snLower] = ['may' => $may, 'cfg' => $cfg, 'type' => $it['type']];

        // 2. Kiểm tra tồn tại trong DB của đơn hàng — đúng máy này VÀ đúng loại linh kiện này,
        //    không chỉ khớp ngẫu nhiên với serial của linh kiện khác/máy khác trong cùng đơn hàng.
        //    Excel phải khớp CHÍNH XÁC serial đã có sẵn trong SQL mới được cập nhật — nếu khác
        //    (kể cả khi SQL chưa có serial nào) thì không cập nhật, tránh ghi đè nhầm sang máy/
        //    cấu hình khác khi chỉ khớp lỏng theo loại linh kiện.
        $dbSerialsForThis = getDbSerialsForImport($dbSerialsByMachine, $may, $it['type'], $cfg);
        if (!in_array($snLower, $dbSerialsForThis, true)) {
            jimport_exit(['success' => false,
                'message' => "❌ Lỗi Máy $may - $cfg ({$it['type']}): Serial \"$sn\" không khớp với dữ liệu đã nhập cho linh kiện này trong đơn hàng #$order_id. Vui lòng kiểm tra lại!"
            ]);
        }
    }

    // Ít nhất phải có 1 linh kiện được điền serial trong máy
    if ($filledCount === 0) {
        jimport_exit(['success' => false,
            'message' => "⚠️ Máy $may (cấu hình: $cfg) chưa có số serial nào trong file Excel bạn vừa tải lên. Hãy mở file Excel, điền serial cho máy này, lưu lại rồi import lại file."
        ]);
    }
}

// Kiểm tra IMEI/IMER trong file phải khớp với DB của đơn hàng
foreach ($byMachine as $mayKey => $machine) {
    $may = $machine['so_may'];
    $cfg = $machine['cfg_name'];
    $imeiExcel = $machine['imei'];
    if ($imeiExcel === '') {
        jimport_exit(['success' => false,
            'message' =>"⚠️ Không thể import Máy $may - $cfg vì chưa có số IMEI/IMER. Đây là thông tin bắt buộc để xác minh thiết bị."
        ]);
    }
    $imeiLower = mb_strtolower($imeiExcel, 'UTF-8');

    // 1. Kiểm tra IMEI trùng với linh kiện khác trong file Excel
    if (isset($seenSerialsInExcel['imei'][$imeiLower])) {
        $prev = $seenSerialsInExcel['imei'][$imeiLower];
        jimport_exit(['success' => false,
            'message' => "🔁 Trùng IMEI/IMER: Số \"$imeiExcel\" tại Máy $may - $cfg đã được sử dụng trước đó ở Máy {$prev['may']} - {$prev['cfg']}. Mỗi IMEI chỉ được dùng cho 1 máy duy nhất, vui lòng kiểm tra lại file Excel."
        ]);
    }
    $seenSerialsInExcel['imei'][$imeiLower] = ['may' => $may, 'cfg' => $cfg, 'type' => 'IMEI'];

    // 2. Kiểm tra IMEI tồn tại trong đơn hàng — ưu tiên đúng máy này, fallback pool chưa gán máy
    $jsonImei    = $orderImeis[$may - 1] ?? '';
    $imeiMatched = in_array($imeiLower, $dbImeiRows[$may] ?? [], true)
        || in_array($imeiLower, $dbImeiRows[0] ?? [], true)
        || ($jsonImei !== '' && $imeiLower === $jsonImei)
        || in_array($imeiLower, $allOrderImeiSerials, true);
    if (!$imeiMatched) {
        jimport_exit(['success' => false,
            'message' =>"❗Số IMEI/IMER \"$imeiExcel\" (Máy $may - $cfg) không trùng khớp với đơn hàng #$order_id. Vui lòng kiểm tra lại file Excel."
        ]);
    }

}

// Kiểm tra tổng số máy trong file Excel phải khớp với DB
$excelMachineCount = count($byMachine);
$dbMachineCount = 0;
try {
    // Luôn đếm tổng số máy bằng cách đếm CPU (hoặc MAIN)
    $stCpu = $pdo->prepare(
        "SELECT COUNT(*) FROM chitiet_donhang
         WHERE id_donhang = ? AND UPPER(loai_linhkien) = 'CPU'"
    );
    $stCpu->execute([$order_id]);
    $dbMachineCount = (int)$stCpu->fetchColumn();

    if ($dbMachineCount === 0) {
        $stMain = $pdo->prepare(
            "SELECT COUNT(*) FROM chitiet_donhang
             WHERE id_donhang = ? AND UPPER(loai_linhkien) IN ('MAIN','MAINBOARD')"
        );
        $stMain->execute([$order_id]);
        $dbMachineCount = (int)$stMain->fetchColumn();
    }
} catch (PDOException $e) {}

if ($dbMachineCount > 0 && $excelMachineCount !== $dbMachineCount) {
    jimport_exit(['success' => false,
        'message' => "Số lượng máy không khớp: File Excel có $excelMachineCount máy, nhưng đơn hàng #$order_id trong hệ thống có $dbMachineCount máy. Vui lòng kiểm tra lại file Excel cho khớp với số máy của đơn hàng."
    ]);
}

// Không cần kiểm tra may < 1 || may > dbMachineCount nữa vì mỗi cấu hình có số máy riêng

// -------------------------------------------------------
// THỰC HIỆN NHẬP (sau khi đã qua toàn bộ kiểm tra)
// -------------------------------------------------------
try {
    $pdo->beginTransaction();

    foreach ($byMachine as $mayKey => $machine) {
        $may = $machine['so_may'];
        $imeiExcel = $machine['imei'];

        // Ghi serial từng linh kiện
        $cfgNorm    = mb_strtolower(trim($machine['cfg_name']), 'UTF-8');
        $serialDone = 0;
        $serialFail = 0;
        $details    = [];

        // Gom theo type+model
        $groups = [];
        foreach ($machine['items'] as $it) {
            $gKey = mb_strtolower($it['type'], 'UTF-8') . '|||' . $it['model'];
            $groups[$gKey][] = $it['serial'];
        }

        $cfgLike = '%' . $machine['cfg_name'] . '%';

        foreach ($groups as $gKey => $serials) {
            [$typeNorm, $modelName] = explode('|||', $gKey, 2);
            $keywords  = getTypeKeywordsForImport($typeNorm);
            $kw_likes  = array_map(fn($k) => '%' . $k . '%', $keywords);
            $ph        = implode(' OR ', array_fill(0, count($keywords), 'LOWER(loai_linhkien) LIKE ?'));

            // Ưu tiên 1: Khớp so_may, type, ten_cauhinh, ten_linhkien
            $sql1 = "SELECT id_ct FROM chitiet_donhang
                       WHERE id_donhang = ? AND so_may = ? AND ($ph)
                         AND ten_cauhinh LIKE ? AND LOWER(TRIM(ten_linhkien)) = LOWER(TRIM(?))
                         AND (linhkien_chon = ? OR linhkien_chon IS NULL OR linhkien_chon = '')
                       ORDER BY id_ct ASC";
            $st1 = $pdo->prepare($sql1);
            $st1->execute(array_merge([$order_id, $may], $kw_likes, [$cfgLike, $modelName, $cfgNorm]));
            $matchedRows = $st1->fetchAll(PDO::FETCH_COLUMN);

            // Ưu tiên 2: Khớp so_may, type, ten_cauhinh (bỏ qua ten_linhkien để tương thích ngược)
            if (empty($matchedRows)) {
                $sql2 = "SELECT id_ct FROM chitiet_donhang
                           WHERE id_donhang = ? AND so_may = ? AND ($ph)
                             AND ten_cauhinh LIKE ?
                             AND (linhkien_chon = ? OR linhkien_chon IS NULL OR linhkien_chon = '')
                           ORDER BY id_ct ASC";
                $st2 = $pdo->prepare($sql2);
                $st2->execute(array_merge([$order_id, $may], $kw_likes, [$cfgLike, $cfgNorm]));
                $matchedRows = $st2->fetchAll(PDO::FETCH_COLUMN);
            }

            // Ưu tiên 3: Khớp chưa gán so_may, type, ten_cauhinh, ten_linhkien
            if (empty($matchedRows)) {
                $sql3 = "SELECT id_ct FROM chitiet_donhang
                          WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($ph)
                            AND ten_cauhinh LIKE ? AND LOWER(TRIM(ten_linhkien)) = LOWER(TRIM(?))
                            AND (linhkien_chon = ? OR linhkien_chon IS NULL OR linhkien_chon = '')
                          ORDER BY id_ct ASC";
                $st3 = $pdo->prepare($sql3);
                $st3->execute(array_merge([$order_id], $kw_likes, [$cfgLike, $modelName, $cfgNorm]));
                $matchedRows = $st3->fetchAll(PDO::FETCH_COLUMN);
            }

            // Ưu tiên 4: Khớp chưa gán so_may, type, ten_cauhinh
            if (empty($matchedRows)) {
                $sql4 = "SELECT id_ct FROM chitiet_donhang
                          WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($ph)
                            AND ten_cauhinh LIKE ?
                            AND (linhkien_chon = ? OR linhkien_chon IS NULL OR linhkien_chon = '')
                          ORDER BY id_ct ASC";
                $st4 = $pdo->prepare($sql4);
                $st4->execute(array_merge([$order_id], $kw_likes, [$cfgLike, $cfgNorm]));
                $matchedRows = $st4->fetchAll(PDO::FETCH_COLUMN);
            }

            // Ưu tiên 5: Khớp chưa gán so_may, type (Dự phòng cuối cùng)
            if (empty($matchedRows)) {
                $sql5 = "SELECT id_ct FROM chitiet_donhang
                           WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($ph)
                           AND (linhkien_chon = ? OR linhkien_chon IS NULL OR linhkien_chon = '')
                           ORDER BY id_ct ASC";
                $st5 = $pdo->prepare($sql5);
                $st5->execute(array_merge([$order_id], $kw_likes, [$cfgNorm]));
                $matchedRows = $st5->fetchAll(PDO::FETCH_COLUMN);
            }

            $upd = $pdo->prepare("UPDATE chitiet_donhang
                                  SET so_serial = ?, so_may = ?, linhkien_chon = ?,
                                      user_id = NULL, user_id_save = ?
                                  WHERE id_ct = ?");

            foreach ($serials as $i => $serial) {
                if ($serial === '' || $serial === '-' || $serial === '—') continue;
                if (!isset($matchedRows[$i])) { $serialFail++; continue; }
                $upd->execute([$serial, $may, $cfgNorm, $session_user_id, $matchedRows[$i]]);
                $serialDone++;
            }
        }

        if ($serialFail > 0) {
            $pdo->rollBack();
            jimport_exit(['success' => false, 'message' => "Lỗi Máy $may: Có $serialFail serial linh kiện không khớp cấu hình trong cơ sở dữ liệu."]);
        }

        $results[] = [
            'so_may'       => $may,
            'cfg_name'     => $machine['cfg_name'],
            'imei'         => $imeiExcel,
            'status'       => 'ok',
            'serial_done'  => $serialDone,
            'serial_fail'  => 0,
            'note'         => '',
        ];
        $totalImported++;
    }

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    jimport_exit(['success' => false, 'message' => 'Lỗi DB khi nhập: ' . $e->getMessage()]);
}

ob_end_clean();
if ($totalImported === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi: Không có máy nào được nhập (Serial chưa đầy đủ hoặc sai IMEI).',
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success'        => true,
        'total_imported' => $totalImported,
        'total_skipped'  => $totalSkipped,
        'total_not_found'=> $totalNotFound,
        'results'        => $results,
    ], JSON_UNESCAPED_UNICODE);
}