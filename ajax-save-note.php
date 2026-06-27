<?php
session_start();
error_reporting(0);
ini_set('display_errors', 0);

require "config.php";
header('Content-Type: application/json');

function respond($success, $message = '')
{
   echo json_encode(['success' => $success, 'message' => $message]);
   exit;
}

if (!$pdo) {
   respond(false, 'Không có kết nối Database.');
}

$id_donhang = isset($_POST['id_donhang']) ? (int) $_POST['id_donhang'] : 0;
$note = trim($_POST['note'] ?? '');

if ($id_donhang <= 0) {
   respond(false, 'ID đơn hàng không hợp lệ.');
}

try {
   $columnCheck = $pdo->query("SHOW COLUMNS FROM donhang LIKE 'ghi_chu'")->fetch(PDO::FETCH_ASSOC);
   if (!$columnCheck) {
      $pdo->exec("ALTER TABLE donhang ADD COLUMN ghi_chu TEXT NULL AFTER ten_khach_hang");
   }

   $stmt = $pdo->prepare("UPDATE donhang SET ghi_chu = ? WHERE id_donhang = ?");
   $stmt->execute([$note, $id_donhang]);

   respond(true);
} catch (Exception $e) {
   respond(false, 'Lỗi lưu ghi chú: ' . $e->getMessage());
}
