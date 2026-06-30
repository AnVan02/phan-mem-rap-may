<?php
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
    $stmt = $pdo->prepare(
        "UPDATE chitiet_donhang SET ten_linhkien = ?
         WHERE id_donhang = ? AND loai_linhkien = ? AND ten_linhkien = ?"
    );
    $stmt->execute([$new_name, $order_id, $loai, $old_name]);
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
