<?php
require "config.php";
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $order_id = isset($data['order_id']) ? (int)$data['order_id'] : 0;
    $so_serial = isset($data['so_serial']) ? trim((string)$data['so_serial']) : '';
    $ten_linhkien = isset($data['ten_linhkien']) ? trim((string)$data['ten_linhkien']) : '';
    $loai_linhkien = isset($data['loai_linhkien']) ? trim((string)$data['loai_linhkien']) : '';
    $config_name = isset($data['config_name']) ? trim((string)$data['config_name']) : '';

    $id_ct = isset($data['id_ct']) ? (int)$data['id_ct'] : 0;

    if ($order_id <= 0 || $so_serial === '' || $ten_linhkien === '') {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không đầy đủ để xóa.']);
        exit;
    }

    try {
        // Ưu tiên dùng id_ct (là khoá chính) để xoá chính xác dòng đó
        if ($id_ct > 0) {
            $stmt = $pdo->prepare(
                "UPDATE chitiet_donhang 
                 SET so_serial = '', linhkien_chon = NULL, so_may = NULL, user_id = NULL, user_id_save = NULL 
                 WHERE id_ct = ? AND id_donhang = ?
                 LIMIT 1"
            );
            $stmt->execute([$id_ct, $order_id]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE chitiet_donhang 
                 SET so_serial = '', linhkien_chon = NULL, so_may = NULL, user_id = NULL, user_id_save = NULL 
                 WHERE id_donhang = ? AND so_serial = ? AND ten_linhkien = ? AND loai_linhkien = ? AND linhkien_chon = ?
                 LIMIT 1"
            );
            $stmt->execute([$order_id, $so_serial, $ten_linhkien, $loai_linhkien, $config_name]);
        }

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Đã xoá serial khỏi hệ thống thành công!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy serial tương ứng để xoá hoặc serial đã được xoá trước đó.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Lỗi SQL: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
}
