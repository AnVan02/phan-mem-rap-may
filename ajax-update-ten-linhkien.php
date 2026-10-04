<?php
// Đảm bảo session.save_path đúng TRƯỚC khi start
$_session_path = __DIR__ . '/sessions';
if (!is_dir($_session_path)) mkdir($_session_path, 0755, true);
ini_set('session.save_path', $_session_path);
session_start();
require "config.php";
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit;
}

$data     = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($data['order_id'] ?? 0);
$loai     = trim($data['loai']      ?? '');
$old_name = trim($data['old_name']  ?? '');
$new_name = trim($data['new_name']  ?? '');

if (!$order_id || !$loai || $new_name === '') {
    echo json_encode(['success' => false, 'message' => 'Thiếu dữ liệu']);
    exit;
}

try {
    // Đổi tên linh kiện = coi như linh kiện vật lý đã bị thay (VD: RAM lỗi đổi sang RAM khác).
    // Phải xoá luôn so_serial/linhkien_chon/so_may/user_id/user_id_save của các dòng bị đổi tên,
    // nếu không dữ liệu serial + gán máy CŨ vẫn còn trong SQL dưới tên mới, khiến kỹ thuật không
    // quét được serial mới (kiemtra.php chỉ đối chiếu serial đã có sẵn trong DB) và ô nhập vẫn
    // hiện sai serial hỏng cũ là "đã hợp lệ" (xem kho-import-serial.php::prefilled).
    $stmt = $pdo->prepare(
        "UPDATE chitiet_donhang
         SET ten_linhkien = ?, so_serial = NULL, linhkien_chon = NULL, so_may = NULL, user_id = NULL, user_id_save = NULL
         WHERE id_donhang = ? AND loai_linhkien = ? AND ten_linhkien = ?"
    );
    $stmt->execute([$new_name, $order_id, $loai, $old_name]);
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
