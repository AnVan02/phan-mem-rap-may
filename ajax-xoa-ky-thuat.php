<?php
require "config.php";
require "phan-quyen.php"; // Chặn nếu không có quyền

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit;
}

// Quyền tối thiểu: admin hoặc chính kỹ thuật viên tự gỡ mình (hoặc admin gỡ người khác)
// Để đơn giản, cho phép nếu đang đăng nhập hợp lệ. Admin có thể xóa ai cũng được.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_donhang = isset($_POST['id_donhang']) ? (int)$_POST['id_donhang'] : 0;
    $so_may = isset($_POST['so_may']) ? (int)$_POST['so_may'] : 0;
    $user_id_to_remove = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    if ($id_donhang > 0 && $so_may > 0 && $user_id_to_remove > 0) {
        try {
            $pdo->beginTransaction();

            // Reset user_id (người chọn cấu hình/kiểm tra)
            $stmt1 = $pdo->prepare("UPDATE chitiet_donhang SET user_id = NULL WHERE id_donhang = ? AND so_may = ? AND user_id = ?");
            $stmt1->execute([$id_donhang, $so_may, $user_id_to_remove]);

            // Reset user_id_save (người lưu serial/lắp ráp)
            $stmt2 = $pdo->prepare("UPDATE chitiet_donhang SET user_id_save = NULL WHERE id_donhang = ? AND so_may = ? AND user_id_save = ?");
            $stmt2->execute([$id_donhang, $so_may, $user_id_to_remove]);

            // Xóa khóa máy của người này (trạng thái lắp máy)
            $stmt3 = $pdo->prepare("DELETE FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ? AND user_id = ?");
            $stmt3->execute([$id_donhang, $so_may, $user_id_to_remove]);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Đã gỡ kỹ thuật viên khỏi máy thành công.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Lỗi DB: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ.']);
}
