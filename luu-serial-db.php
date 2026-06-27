<?php
session_start();
// --- NHẬT KÝ KIỂM TRA NGUỒN GỌI ---
$log_entry = date('[Y-m-d H:i:s] ') . "LUU-SERIAL-DB CALLED | Caller: " . ($_SERVER['HTTP_REFERER'] ?? 'Unknown') . PHP_EOL;
$input_raw = file_get_contents('php://input');
file_put_contents('debug_log.txt', $log_entry . "INPUT: " . $input_raw . PHP_EOL, FILE_APPEND);

require "config.php";
header('Content-Type: application/json');

function respondError($message)
{
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Hàm trích xuất số máy từ chuỗi "Máy X"
function extract_so_may($choice)
{
    if (preg_match('/M[áàảãạ]y\s*(\d+)/ui', $choice, $matches)) {
        return (int) $matches[1];
    }
    return 0;
}

// Hàm lấy cấu hình chủ sở hữu dựa trên "Space Hack"
function get_owner_config($ten_cauhinh)
{
    $trimmed = rtrim($ten_cauhinh, ' ');
    $spaces = strlen($ten_cauhinh) - strlen($trimmed);
    $parts = array_map('trim', explode(',', $trimmed));
    if (isset($parts[$spaces])) {
        return $parts[$spaces];
    }
    return $parts[0] ?? $trimmed;
}

// Hàm lấy số lượng máy cho từng cấu hình trong đơn hàng
function get_config_machine_counts($pdo, $order_id)
{
    // Lấy tất cả linh kiện trong đơn hàng để xác định số máy thực tế của mỗi cấu hình
    $stmt = $pdo->prepare("SELECT ten_cauhinh, loai_linhkien FROM chitiet_donhang WHERE id_donhang = ?");
    $stmt->execute([$order_id]);
    $all_order_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $owner_counts = [];
    $defining_types = ['CPU', 'MAIN', 'MAINBOARD', 'VGA', 'SSD', 'PSU', 'FAN'];
    foreach ($all_order_rows as $r) {
        $owner = get_owner_config($r['ten_cauhinh']);
        $type = strtoupper($r['loai_linhkien']);
        if (in_array($type, $defining_types)) {
            $owner_counts[$owner][$type] = ($owner_counts[$owner][$type] ?? 0) + 1;
        }
    }
    $machine_counts_per_owner = [];
    foreach ($owner_counts as $owner => $types) {
        $qty = 0;
        foreach ($defining_types as $t) {
            if (isset($types[$t])) {
                $qty = $types[$t];
                break;
            }
        }
        $machine_counts_per_owner[$owner] = $qty > 0 ? $qty : 1;
    }
    return $machine_counts_per_owner;
}

if (!$pdo)
    respondError('Lỗi kết nối dữ liệu hệ thống.');

$input = $input_raw;
$data = json_decode($input, true);

if (!$data || !isset($data['order_id']) || !isset($data['serials_data'])) {
    respondError('Dữ liệu không hợp lệ.');
}

$order_id = (int) $data['order_id'];
$serials_data = $data['serials_data'];
$imei_data = $data['imei_data'] ?? [];

try {
    $pdo->beginTransaction();
    file_put_contents('debug_log.txt', "[INFO] Transaction started" . PHP_EOL, FILE_APPEND);

    // ===== LƯU IMEI VÀO BẢNG DONHANG =====
    // Kiểm tra xem bảng donhang đã có cột imei hay chưa
    $columnCheck = $pdo->query("SHOW COLUMNS FROM donhang LIKE 'imei'")->fetch(PDO::FETCH_ASSOC);
    if (!$columnCheck) {
        $pdo->exec("ALTER TABLE donhang ADD COLUMN imei LONGTEXT NULL");
        file_put_contents('debug_log.txt', "[INFO] Added imei column to donhang table" . PHP_EOL, FILE_APPEND);
    }
    // Lưu IMEI dưới dạng JSON
    if (!empty($imei_data)) {
        $imei_json = json_encode($imei_data, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("UPDATE donhang SET imei = ? WHERE id_donhang = ?");
        $stmt->execute([$imei_json, $order_id]);
        file_put_contents('debug_log.txt', "[INFO] Saved " . count($imei_data) . " IMEIs for order " . $order_id . PHP_EOL, FILE_APPEND);
    }

    // ===== TIẾP TỤC XỬ LÝ SERIAL =====

    // Lấy bảng thông tin số máy từng cấu hình một lần duy nhất
    $config_machine_counts = get_config_machine_counts($pdo, $order_id);

    foreach ($serials_data as $group) {
        $type = $group['type'];
        $name = $group['name'];
        $config = isset($group['config']) ? trim((string) $group['config']) : ''; // Tên cấu hình từ JS
        $choice = isset($group['linhkien_chon']) ? trim((string) $group['linhkien_chon']) : '';

        // Nhận diện mảng serials có thể là chuỗi hoặc object {serial, manual_m, manual_choice}
        $serials_input = $group['serials'] ?? [];
        $processed_serials = [];
        foreach ($serials_input as $s) {
            if (is_array($s)) {
                $processed_serials[] = [
                    'serial' => strtoupper(trim((string) ($s['serial'] ?? ''))),
                    'manual_m' => (int) ($s['manual_m'] ?? 0),
                    'manual_choice' => $s['manual_choice'] ?? null
                ];
            } else {
                $processed_serials[] = [
                    'serial' => strtoupper(trim((string) $s)),
                    'manual_m' => 0,
                    'manual_choice' => null
                ];
            }
        }
        $processed_serials = array_values(array_filter($processed_serials, function ($ps) {
            return $ps['serial'] !== '';
        }));

        // Phân tích choice để lấy so_may từ context (nếu gọi từ kho-import-serial)
        $context_so_may = extract_so_may($choice);
        $context_lk_chon = $choice;
        if (strpos($choice, '|') !== false) {
            $parts = explode('|', $choice);
            $context_lk_chon = trim($parts[0]);
        }

        // 1. Tìm các slot trong DB
        if ($context_so_may > 0) {
            // Trường hợp nhập cho 1 máy cụ thể (Kho-import-serial)
            $sql_query = "SELECT id_ct, so_serial, so_may, linhkien_chon, ten_cauhinh, user_id, user_id_save FROM chitiet_donhang 
                          WHERE id_donhang = ? AND loai_linhkien = ? AND ten_linhkien = ? AND so_may = ?
                          ORDER BY id_ct ASC";
            $stmt_current = $pdo->prepare($sql_query);
            $stmt_current->execute([$order_id, $type, $name, $context_so_may]);
            $all_slots = $stmt_current->fetchAll(PDO::FETCH_ASSOC);

            // Nếu máy này chưa được định danh hàng, lấy hàng chưa gán
            if (empty($all_slots)) {
                $sql_query = "SELECT id_ct, so_serial, so_may, linhkien_chon, ten_cauhinh, user_id, user_id_save FROM chitiet_donhang 
                              WHERE id_donhang = ? AND loai_linhkien = ? AND ten_linhkien = ? AND (so_may = 0 OR so_may IS NULL)
                              ORDER BY id_ct ASC LIMIT " . count($processed_serials);
                $stmt_current = $pdo->prepare($sql_query);
                $stmt_current->execute([$order_id, $type, $name]);
                $all_slots = $stmt_current->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            // Trường hợp nhập hàng loạt (Nhap-serial.php)
            $type_upper = strtoupper($type);
            if (($type_upper === 'IMEI' || $type_upper === 'IMER') && $config !== '') {
                // IMEI/IMER: lọc theo ten_cauhinh để chỉ gán đúng slot của cấu hình đó
                $sql_query = "SELECT id_ct, so_serial, so_may, linhkien_chon, ten_cauhinh, user_id, user_id_save FROM chitiet_donhang 
                              WHERE id_donhang = ? AND loai_linhkien = ? AND ten_linhkien = ? AND ten_cauhinh = ?
                              ORDER BY id_ct ASC";
                $stmt_current = $pdo->prepare($sql_query);
                $stmt_current->execute([$order_id, $type, $name, $config]);
                $all_slots = $stmt_current->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Các linh kiện thông thường: lấy toàn bộ slot
                $sql_query = "SELECT id_ct, so_serial, so_may, linhkien_chon, ten_cauhinh, user_id, user_id_save FROM chitiet_donhang 
                              WHERE id_donhang = ? AND loai_linhkien = ? AND ten_linhkien = ? 
                              ORDER BY id_ct ASC";
                $stmt_current = $pdo->prepare($sql_query);
                $stmt_current->execute([$order_id, $type, $name]);
                $all_slots = $stmt_current->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Lấy ID người dùng từ Session
        $user_id = $_SESSION['user_id'] ?? null;

        // Lấy danh sách serial hiện tại của nhóm linh kiện này để kiểm tra (đổi chỗ hay nhập mới)
        $existing_serials = [];
        foreach ($all_slots as $s) {
            if (!empty($s['so_serial'])) {
                $existing_serials[] = strtoupper(trim((string) $s['so_serial']));
            }
        }

        // KIỂM TRA PHÂN LOẠI: ĐỔI CHỖ (Permutation) hay NHẬP MỚI
        $input_serials = [];
        $has_manual = false;
        foreach ($processed_serials as $ps) {
            $input_serials[] = $ps['serial'];
            if ($ps['manual_m'] > 0 || $ps['manual_choice'] !== null)
                $has_manual = true;
        }

        $old_sorted = $existing_serials;
        sort($old_sorted);
        $new_sorted = $input_serials;
        sort($new_sorted);

        // Nếu nội dung y hệt nhau (chỉ khác thứ tự) và không gán thủ công thì giữ nguyên không cập nhật
        // RIÊNG IMER: Cho phép cập nhật để đổi chỗ cho nhau
        if ($old_sorted == $new_sorted && !$has_manual && $context_so_may <= 0 && $type !== 'IMER') {
            continue;
        }

        // (Đã loại bỏ continue để luôn cho phép cập nhật user_id/user_id_save khi nhấn Lưu)

        foreach ($all_slots as $index => $slot) {
            if (isset($processed_serials[$index])) {
                $ps = $processed_serials[$index];
                $sn = $ps['serial'];

                // XÁC ĐỊNH GIÁ TRỊ LƯU:
                $final_m = $slot['so_may'];
                $final_lk = $slot['linhkien_chon'];

                // 1. Kiểm tra nếu serial thay đổi so với DB
                if ($sn !== $slot['so_serial']) {
                    // Nếu là SERIAL HOÀN TOÀN MỚI (không có trong danh sách cũ của nhóm này)
                    if (!in_array($sn, $existing_serials)) {
                        $final_m = null;
                        $final_lk = null;
                    }
                    // Nếu là SERIAL CŨ (đổi chỗ), giữ nguyên $final_m và $final_lk của slot để tránh mất gán máy
                }

                // 2. Ưu tiên CHỈ ĐỊNH THỦ CÔNG (nếu có)
                if ($ps['manual_m'] > 0) {
                    $final_m = $ps['manual_m'];
                    $final_lk = $ps['manual_choice'] ?: $slot['ten_cauhinh'];
                } else if ($context_so_may > 0) {
                    $final_m = $context_so_may;
                    $final_lk = $context_lk_chon;
                }

                // 3. Thực hiện UPDATE nếu có sự thay đổi
                $current_user_id = (int) ($slot['user_id'] ?? 0);
                $current_user_save = (int) ($slot['user_id_save'] ?? 0);

                if (
                    $sn !== $slot['so_serial'] ||
                    $final_m != $slot['so_may'] ||
                    $final_lk !== $slot['linhkien_chon'] ||
                    $slot['user_id'] !== null
                ) {
                    $stmt = $pdo->prepare("UPDATE chitiet_donhang SET so_serial = ?, so_may = ?, linhkien_chon = ?, user_id = NULL, user_id_save = NULL WHERE id_ct = ?");
                    $stmt->execute([$sn, $final_m, $final_lk, $slot['id_ct']]);
                }
            } else {
                // Nếu slot dư (người dùng xóa bớt dòng trong textarea)
                if (!empty($slot['so_serial'])) {
                    // CHỈ xóa serial, giữ nguyên so_may và linhkien_chon để tránh mất mapping máy
                    $pdo->prepare("UPDATE chitiet_donhang SET so_serial = '', user_id = NULL, user_id_save = ? WHERE id_ct = ?")
                        ->execute([$user_id, $slot['id_ct']]);
                }
            }
        }
    }
    $pdo->commit();
    file_put_contents('debug_log.txt', "[INFO] Transaction committed" . PHP_EOL, FILE_APPEND);
    echo json_encode(['success' => true, 'message' => 'Lưu so serial thành công.']);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
        file_put_contents('debug_log.txt', "[ERROR] Transaction rolled back: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
    respondError('Lỗi database: ' . $e->getMessage());
}
