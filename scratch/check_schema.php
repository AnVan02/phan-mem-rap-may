<?php
require "config.php";
$stmt = $pdo->query("DESCRIBE trang_thai_lap_may");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
