<?php
require "config.php";
require "phan-quyen.php"; // Chặn nếu không có quyền

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit;
}

// Xử lý JSON payload
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['items']) && is_array($data['items']) && count($data['items']) > 0) {
    try {
        $pdo->beginTransaction();

        $stmt1 = $pdo->prepare("UPDATE chitiet_donhang SET user_id = NULL WHERE id_donhang = ? AND so_may = ? AND user_id = ?");
        $stmt2 = $pdo->prepare("UPDATE chitiet_donhang SET user_id_save = NULL WHERE id_donhang = ? AND so_may = ? AND user_id_save = ?");
        $stmt3 = $pdo->prepare("DELETE FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ? AND user_id = ?");

        foreach ($data['items'] as $item) {
            $id_donhang = (int)$item['id_donhang'];
            $so_may = (int)$item['so_may'];
            $user_id_to_remove = (int)$item['user_id'];

            if ($id_donhang > 0 && $so_may > 0 && $user_id_to_remove > 0) {
                $stmt1->execute([$id_donhang, $so_may, $user_id_to_remove]);
                $stmt2->execute([$id_donhang, $so_may, $user_id_to_remove]);
                $stmt3->execute([$id_donhang, $so_may, $user_id_to_remove]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Đã gỡ kỹ thuật viên khỏi các máy đã chọn thành công.']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Lỗi DB: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ hoặc trống.']);
}
