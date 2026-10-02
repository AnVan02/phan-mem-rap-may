<?php
require 'config.php';
$stmt = $pdo->prepare("SELECT id_donhang, imei FROM donhang WHERE id_donhang = 77");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
   echo "Current IMEI value:\n";
   echo json_encode(json_decode($row['imei'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
   echo "Order not found";
}
