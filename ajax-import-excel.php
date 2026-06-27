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

            if ($typeCell  !== '') $lastType  = $typeCell;
            if ($modelCell !== '') $lastModel = $modelCell;

            $type = $lastType;
            if ($type === '' || mb_strtolower($type, 'UTF-8') === 'thành phần') continue;

            $hasData = false;
            foreach ($row as $v) { if (trim((string)($v ?? '')) !== '') { $hasData = true; break; } }
            if (!$hasData) { $lastType = $lastModel = ''; break; }

            $block['items'][] = ['type' => $type, 'model' => $lastModel, 'serial' => $serialCell];
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

// Hàm tìm và cập nhật serial cho 1 linh kiện
function importOneSerial(PDO $pdo, int $order_id, int $so_may, string $cfgNorm,
                          string $displayType, string $modelName, string $serial): string
{
    $keywords = getTypeKeywordsForImport($displayType);
    $kw_likes = array_map(fn($k) => '%' . $k . '%', $keywords);
    $placeholders = implode(' OR ', array_fill(0, count($keywords), 'LOWER(loai_linhkien) LIKE ?'));

    // Chiến lược 1: khớp so_may + ten_linhkien
    $sql = "SELECT id_ct FROM chitiet_donhang
            WHERE id_donhang = ? AND so_may = ? AND ($placeholders) AND ten_linhkien = ?
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

// Nhóm theo số máy
$byMachine = [];
foreach ($machineBlocks as $b) {
    $may = $b['so_may'];
    if (!isset($byMachine[$may])) {
        $byMachine[$may] = ['so_may' => $may, 'cfg_name' => $b['cfg_name'],
                            'imei' => $b['imei'], 'items' => []];
    }
    foreach ($b['items'] as $it) $byMachine[$may]['items'][] = $it;
}
ksort($byMachine);

try {
    $pdo->beginTransaction();

    foreach ($byMachine as $may => $machine) {
        $imeiExcel = $machine['imei'];
        $jsonImei  = $orderImeis[$may - 1] ?? '';
        $dbImei    = $dbImeiRows[$may] ?? [];

        // Kiểm tra IMEI: bỏ qua nếu Excel có IMEI nhưng không khớp DB
        // Fallback: tìm trong toàn bộ IMEI/IMER đơn hàng vì so_may có thể = 0
        $imeiOk = true;
        if ($imeiExcel !== '') {
            $imeiLower = mb_strtolower($imeiExcel, 'UTF-8');
            $imeiOk    = ($jsonImei !== '' && $imeiLower === $jsonImei)
                      || in_array($imeiLower, $dbImei)
                      || in_array($imeiLower, $allOrderImeiSerials);
        }

        if (!$imeiOk) {
            $results[] = ['so_may' => $may, 'status' => 'skip_imei',
                          'imei' => $imeiExcel, 'note' => 'IMEI không khớp, bỏ qua'];
            $totalSkipped++;
            continue;
        }

        // Ghi serial từng linh kiện
        $cfgNorm    = mb_strtolower(trim($machine['cfg_name']), 'UTF-8');
        $serialDone = 0;
        $serialFail = 0;
        $details    = [];

        // Gom theo type+model để xử lý nhiều dòng cùng loại (2 RAM...)
        $groups = [];
        foreach ($machine['items'] as $it) {
            $gKey = mb_strtolower($it['type'], 'UTF-8') . '|||' . $it['model'];
            $groups[$gKey][] = $it['serial'];
        }

        // Dùng LIKE trên ten_cauhinh để chỉ lấy rows thuộc đúng cấu hình này
        // (ten_cauhinh có thể là "Cấu hình 1" hoặc "Cấu hình 1, Cấu hình 2" cho shared)
        $cfgLike = '%' . $machine['cfg_name'] . '%';

        foreach ($groups as $gKey => $serials) {
            [$typeNorm, $modelName] = explode('|||', $gKey, 2);
            $keywords  = getTypeKeywordsForImport($typeNorm);
            $kw_likes  = array_map(fn($k) => '%' . $k . '%', $keywords);
            $ph        = implode(' OR ', array_fill(0, count($keywords), 'LOWER(loai_linhkien) LIKE ?'));

            // Ưu tiên 1: đã gán đúng so_may + đúng cấu hình
            $sqlAll = "SELECT id_ct FROM chitiet_donhang
                       WHERE id_donhang = ? AND so_may = ? AND ($ph)
                         AND ten_cauhinh LIKE ?
                       ORDER BY id_ct ASC";
            $stAll = $pdo->prepare($sqlAll);
            $stAll->execute(array_merge([$order_id, $may], $kw_likes, [$cfgLike]));
            $matchedRows = $stAll->fetchAll(PDO::FETCH_COLUMN);

            // Ưu tiên 2: chưa gán so_may, nhưng đúng cấu hình
            if (empty($matchedRows)) {
                $sqlFb = "SELECT id_ct FROM chitiet_donhang
                          WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($ph)
                            AND ten_cauhinh LIKE ?
                          ORDER BY id_ct ASC";
                $stFb = $pdo->prepare($sqlFb);
                $stFb->execute(array_merge([$order_id], $kw_likes, [$cfgLike]));
                $matchedRows = $stFb->fetchAll(PDO::FETCH_COLUMN);
            }

            // Ưu tiên 3: chưa gán so_may, không lọc cấu hình (dự phòng cuối)
            if (empty($matchedRows)) {
                $sqlFb2 = "SELECT id_ct FROM chitiet_donhang
                           WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($ph)
                           ORDER BY id_ct ASC";
                $stFb2 = $pdo->prepare($sqlFb2);
                $stFb2->execute(array_merge([$order_id], $kw_likes));
                $matchedRows = $stFb2->fetchAll(PDO::FETCH_COLUMN);
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

        $results[] = [
            'so_may'       => $may,
            'cfg_name'     => $machine['cfg_name'],
            'imei'         => $imeiExcel,
            'status'       => $serialFail > 0 ? 'partial' : 'ok',
            'serial_done'  => $serialDone,
            'serial_fail'  => $serialFail,
            'note'         => $serialFail > 0 ? "$serialFail linh kiện không tìm thấy slot" : '',
        ];
        $totalImported++;
        if ($serialFail > 0) $totalNotFound += $serialFail;
    }

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    jimport_exit(['success' => false, 'message' => 'Lỗi DB khi nhập: ' . $e->getMessage()]);
}

ob_end_clean();
echo json_encode([
    'success'        => true,
    'total_imported' => $totalImported,
    'total_skipped'  => $totalSkipped,
    'total_not_found'=> $totalNotFound,
    'results'        => $results,
], JSON_UNESCAPED_UNICODE);
