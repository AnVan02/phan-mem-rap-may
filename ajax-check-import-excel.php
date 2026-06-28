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

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    json_exit(['success' => false, 'message' => 'Không có file hoặc lỗi khi tải lên']);
}

if (!file_exists('vendor/autoload.php')) {
    json_exit(['success' => false, 'message' => 'Thiếu thư viện PhpSpreadsheet (chạy composer install)']);
}

require_once 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// -------------------------------------------------------
// ĐỌC FILE EXCEL
// toArray(null, false, false, false) → giá trị raw, không format
// -------------------------------------------------------
try {
    $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);
    $sheet       = $spreadsheet->getActiveSheet();
    // Lấy array 0-indexed; merged cells → chỉ ô đầu tiên có giá trị, các ô còn lại null
    $allRows = $sheet->toArray(null, false, false, false);
} catch (Throwable $e) {
    json_exit(['success' => false, 'message' => 'Không đọc được file: ' . $e->getMessage()]);
}

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

            // Merged cells trong cột A/B → giữ lại giá trị từ dòng đầu của merge
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
                'type'   => $type,
                'model'  => $model,
                'serial' => $serialCell,
            ];
        }
        unset($block);
    }
}

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

// Map tên display từ file xuất → từ khóa loại DB
$typeMap = [
    'cpu'           => ['cpu'],
    'mainboard'     => ['main', 'mainboard'],
    'ram'           => ['ram'],
    'ssd'           => ['ssd', 'hdd'],
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
    'hdd'           => ['ssd', 'hdd'],
    'vga'           => ['vga'],
    'psu'           => ['psu'],
    'fan'           => ['fan'],
    'win'           => ['win'],
    'windows'       => ['win'],
];

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
        $matched  = in_array($imeiLower, $dbImei)
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
        $dbSer    = getDbSerials($dbRows, $may, $typeNorm);
        $cellSerials = [];

        foreach ($serials as $sn) {
            if ($sn === '' || $sn === '-' || $sn === '—') {
                $cellSerials[] = ['status' => 'skip', 'value' => ''];
                $hasError = true; // serial trống → máy chưa đầy đủ, bỏ qua
            } else {
                $matched = in_array(mb_strtolower($sn, 'UTF-8'), $dbSer);
                $cellSerials[] = ['status' => $matched ? 'ok' : 'error', 'value' => $sn,
                                  'note' => $matched ? '' : 'Serial không khớp DB'];
                if (!$matched) $hasError = true;
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
    'success' => true,
    'columns' => $columns,
    'rows'    => $resultRows,
    'summary' => [
        'total'    => count($resultRows),
        'ok'       => $totalOk,
        'errors'   => $totalErrors,
        'warnings' => 0,
    ],
], JSON_UNESCAPED_UNICODE);
