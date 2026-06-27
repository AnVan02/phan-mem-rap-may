<?php
session_start();
// --- [PHIÊN BẢN MỚI V7 - CHẾ ĐỘ ĐỐI CHIẾU VÀ GÁN MÁY] ---
$log_entry = date('[Y-m-d H:i:s] ') . "AJAX-LUU-SERIAL V7 (LOOKUP MODE) CALLED" . PHP_EOL;
file_put_contents('debug_log.txt', $log_entry . "POST: " . json_encode($_POST) . PHP_EOL, FILE_APPEND);

require "config.php";
header('Content-Type: application/json');
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Lỗi kết nối']);
    exit;
}
$pdo->exec("SET NAMES utf8mb4");

function extract_so_may($choice)
{
    if (preg_match('/M[áàảãạ]y\s*(\d+)/ui', $choice, $matches))
        return (int) $matches[1];
    return 1; // Mặc định máy 1 nếu không bóc tách được
}

$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$config_name = isset($_POST['config_name']) ? trim((string) $_POST['config_name']) : '';
$serials = $_POST['serials'] ?? null;

if ($order_id <= 0 || !is_array($serials) || $config_name === '') {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu thiếu']);
    exit;
}
try {
    $pdo->beginTransaction();
    $so_may_t = isset($_POST['machine_idx']) ? (int) $_POST['machine_idx'] : extract_so_may($config_name);
    $ln_pure = $config_name;
    if (strpos($config_name, '|') !== false) {
        $parts = explode('|', $config_name);
        $ln_pure = mb_strtolower(trim($parts[0]), 'UTF-8');
    }

    if ($order_id <= 0 || empty($ln_pure)) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ (Thiếu ID đơn hàng hoặc Tên cấu hình)']);
        exit;
    }

    // BƯỚC 1: XÁC ĐỊNH LINH KIỆN NÀO THỰC SỰ ỨNG VỚI SERIAL QUÉT ĐƯỢC
    // Câu lệnh cập nhật dựa trên Serial có sẵn trong DB
    // BỎ BƯỚC GIẢI PHÓNG TOÀN BỘ MÁY (Đáp ứng: không phải máy không đổi cũng cập nhập)
    // $stmt_clear = $pdo->prepare("UPDATE chitiet_donhang SET linhkien_chon = NULL, so_may = 0 
    //                             WHERE id_donhang = ? AND linhkien_chon = ? AND so_may = ?");
    // $stmt_clear->execute([$order_id, $ln_pure, $so_may_t]);

    // Lấy ID người dùng từ Session
    $user_id = $_SESSION['user_id'] ?? null;

    // --- TRƯỜNG HỢP 4: KIỂM TRA QUYỀN SỞ HỮU TRƯỚC KHI LƯU ---
    // Dùng cùng cách normalize như ajax-handle-lock.php (preg_replace NFC/NFD safe)
    $clean_req_cfg = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($config_name), 'UTF-8'));
    $stmt_check_lock = $pdo->prepare("SELECT user_id, config_name FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ?");
    $stmt_check_lock->execute([$order_id, $so_may_t]);
    $lock_owner = false;
    foreach ($stmt_check_lock->fetchAll(PDO::FETCH_ASSOC) as $lrow) {
        $clean_db_cfg = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($lrow['config_name']), 'UTF-8'));
        if ($clean_db_cfg === $clean_req_cfg) {
            $lock_owner = $lrow['user_id'];
            break;
        }
    }

    if ($lock_owner === false || (int) $lock_owner !== (int) $user_id) {
        // Kiểm tra xem user này thực sự đang ở máy nào để báo lỗi chi tiết
        $stmt_where = $pdo->prepare("SELECT so_may, config_name FROM trang_thai_lap_may WHERE user_id = ? LIMIT 1");
        $stmt_where->execute([$user_id]);
        $where = $stmt_where->fetch(PDO::FETCH_ASSOC);

        $err_msg = "Phiên làm việc đã hết hạn hoặc bạn không có quyền cập nhật máy này. Vui lòng tải lại trang và thử lại.";

        if ($where) {
            $err_msg = "Hệ thống ghi nhận bạn đang làm việc ở Máy " . $where['so_may'] . " (" . $where['config_name'] . ").";
        }

        echo json_encode([
            'success' => false,
            'error_type' => 'auth_lock',
            'message' => $err_msg
        ]);
        exit;
    }
    // -------------------------------------------------------

    // BƯỚC 2: GÁN CÁC LINH KIỆN MỚI
    $stmt_update_by_id = $pdo->prepare("UPDATE chitiet_donhang 
                                           SET so_serial = ?, linhkien_chon = ?, so_may = ?, user_id = ?, user_id_save = ? 
                                            WHERE id_ct = ? AND id_donhang = ?");



    // Lấy thông tin hiện tại để đối chiếu (tránh cập nhật thừa)
    $stmt_get_current = $pdo->prepare("SELECT id_ct, so_serial, linhkien_chon, so_may, user_id, user_id_save FROM chitiet_donhang WHERE id_ct = ?");

    $updated = 0;
    foreach ($serials as $item) {
        $val = isset($item['val']) ? strtoupper(trim((string) $item['val'])) : '';
        $id_ct = isset($item['id_ct']) ? (int) $item['id_ct'] : 0;
        $type = isset($item['type']) ? strtoupper(trim((string) $item['type'])) : '';

        if (($val === '' && !in_array($type, ['WIN', 'IMEI', 'IMER'])) || $id_ct <= 0)
            continue;

        // BƯỚC 1: Kiểm tra xem serial, cấu hình hoặc số máy có thay đổi không
        $stmt_get_current->execute([$id_ct]);
        $row = $stmt_get_current->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $current_sn = (string) ($row['so_serial'] ?? '');
            $current_cfg = (string) ($row['linhkien_chon'] ?? '');
            $current_m = (int) ($row['so_may'] ?? 0);
            $current_user_id = (int) ($row['user_id'] ?? 0);
            $current_user_save = (int) ($row['user_id_save'] ?? 0);

            // Cập nhật nếu: 
            // 1. Serial, cấu hình hoặc số máy có thay đổi
            // 2. HOẶC nếu user_id đang được gán (đang khóa) -> cần giải phóng (set NULL)
            // 3. HOẶC nếu user_id_save chưa được gán chính xác (để xác nhận ai là người lưu cuối)
            if (
                $current_sn !== $val ||
                $current_cfg !== (string) $ln_pure ||
                $current_m !== (int) $so_may_t ||
                $row['user_id'] !== null ||
                ($row['user_id_save'] === null && $user_id !== null) ||
                ($row['user_id_save'] !== null && (int) $row['user_id_save'] !== (int) $user_id)
            ) {
                $stmt_update_by_id->execute([$val, $ln_pure, $so_may_t, null, $user_id, $id_ct, $order_id]);
                $updated++;
            }
        }
    }
    $pdo->commit();

    // GIẢI PHÓNG KHÓA MÁY - Dùng PHP normalize để tránh lỗi NFC/NFD trên server Linux
    $stmt_get_locks = $pdo->prepare("SELECT config_name FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ?");
    $stmt_get_locks->execute([$order_id, $so_may_t]);
    $stmt_unlock = $pdo->prepare("DELETE FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ? AND config_name = ?");
    foreach ($stmt_get_locks->fetchAll(PDO::FETCH_COLUMN) as $stored_cfg) {
        $clean_stored = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($stored_cfg), 'UTF-8'));
        if ($clean_stored === $clean_req_cfg) {
            $stmt_unlock->execute([$order_id, $so_may_t, $stored_cfg]);
            break;
        }
    }

    // Xóa dấu vết đang ở trong máy (Ngăn chặn F5 sau khi lưu)
    unset($_SESSION['LAST_MACHINE_ENTERED']);

    $msg = $updated > 0 ? "Đã lưu thành công cho $updated linh kiện!" : "Dữ liệu đã được đồng bộ!";
    echo json_encode(['success' => true, 'message' => $msg, 'updated' => $updated]);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
