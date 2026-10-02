<?php
require_once 'config.php';
session_start();

header('Content-Type: application/json');

// Chỉ admin mới có quyền xóa tài khoản
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Không có quyền thực hiện thao tác này.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID không hợp lệ.']);
    exit;
}

// Không cho phép tự xóa chính mình
if ($id === (int)$_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Bạn không thể tự xóa tài khoản của chính mình.']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy tài khoản.']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi DB: ' . $e->getMessage()]);
}
