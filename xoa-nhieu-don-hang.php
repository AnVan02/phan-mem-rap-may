<?php
header('Content-Type: application/json');
require "config.php";
$data = json_decode(file_get_contents('php://input'), true);
$ids = $data['ids'] ?? [];
if (empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false, 'message' => 'Danh sách xóa trống.']);
    exit;
}
try {
    $pdo->beginTransaction();
    $inQuery = implode(',', array_fill(0, count($ids), '?'));

    // 1. Xóa trạng thái lắp máy trước
    $stmt0 = $pdo->prepare("DELETE FROM trang_thai_lap_may WHERE id_donhang IN ($inQuery)");
    $stmt0->execute($ids);

    // 2. Xóa chi tiết đơn hàng
    $stmt1 = $pdo->prepare("DELETE FROM chitiet_donhang WHERE id_donhang IN ($inQuery)");
    $stmt1->execute($ids);

    // 3. Xóa đơn hàng chính
    $stmt2 = $pdo->prepare("DELETE FROM donhang WHERE id_donhang IN ($inQuery)");
    $stmt2->execute($ids);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Lỗi DB: ' . $e->getMessage()]);
}
