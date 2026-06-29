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
// KIỂM TRA: serial trong file Excel phải khớp với DB
// -------------------------------------------------------

// Tải toàn bộ serial đã nhập cho đơn hàng này (từ nhap-serial.php)
$existingSerials = []; // [serial_lower => true]
try {
    $stAll = $pdo->prepare(
        "SELECT LOWER(TRIM(so_serial)) FROM chitiet_donhang
         WHERE id_donhang = ? AND so_serial IS NOT NULL AND so_serial <> ''"
    );
    $stAll->execute([$order_id]);
    foreach ($stAll->fetchAll(PDO::FETCH_COLUMN) as $sn) {
        $existingSerials[$sn] = true;
    }
} catch (PDOException $e) {
    jimport_exit(['success' => false, 'message' => 'Lỗi tải dữ liệu: ' . $e->getMessage()]);
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
        if ($sn === '' || $sn === '-' || $sn === '—') {
            $emptyCount++;
            continue;
        }
        $filledCount++;
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

        // 2. Kiểm tra tồn tại trong DB của đơn hàng
        if (!isset($existingSerials[$snLower])) {
            jimport_exit(['success' => false,
                'message' => "Lỗi Máy $may - $cfg ({$it['type']}): Serial \"$sn\" không khớp với dữ liệu đã nhập trong đơn hàng #$order_id. Vui lòng kiểm tra lại!"
            ]);
        }
    }

    // Ít nhất phải có 1 linh kiện được điền serial trong máy
    if ($filledCount === 0) {
        jimport_exit(['success' => false,
            'message' => "Lỗi: Máy $may - $cfg trong file Excel chưa điền serial nào! Vui lòng cập nhật ít nhất 1 serial cho máy này trước khi import."
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
            'message' => "Lỗi Máy $may - $cfg: Chưa điền số IMEI/IMER! Bắt buộc phải có IMEI/IMER để kiểm tra."
        ]);
    }
    $imeiLower = mb_strtolower($imeiExcel, 'UTF-8');

    // 1. Kiểm tra IMEI trùng với linh kiện khác trong file Excel
    if (isset($seenSerialsInExcel['imei'][$imeiLower])) {
        $prev = $seenSerialsInExcel['imei'][$imeiLower];
        jimport_exit(['success' => false,
            'message' => "Lỗi trùng lặp Serial: Số IMEI/IMER \"$imeiExcel\" của Máy $may - $cfg bị trùng với một Serial đã nhập ở Máy {$prev['may']} - {$prev['cfg']}. Mỗi IMEI/IMER phải là duy nhất!"
        ]);
    }
    $seenSerialsInExcel['imei'][$imeiLower] = ['may' => $may, 'cfg' => $cfg, 'type' => 'IMEI'];

    // 2. Kiểm tra IMEI tồn tại trong đơn hàng
    if (!isset($existingSerials[$imeiLower])) {
        jimport_exit(['success' => false,
            'message' => "Lỗi Máy $may - $cfg: IMEI/IMER \"$imeiExcel\" không khớp với dữ liệu đã nhập trong đơn hàng #$order_id. Vui lòng kiểm tra lại!"
        ]);
    }

    // Bỏ qua kiểm tra IMEI gán đúng số máy vì so_may là cục bộ, dễ gây lỗi trùng lặp khi có nhiều cấu hình
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
        'message' => "Lỗi: File Excel có $excelMachineCount máy nhưng đơn hàng #$order_id có $dbMachineCount máy trong hệ thống. Số máy phải khớp nhau!"
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
