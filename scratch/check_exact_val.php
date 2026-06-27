<?php
require "config.php";
$stmt = $pdo->query("SELECT config_name, LENGTH(config_name) as len, OCTET_LENGTH(config_name) as oct_len FROM trang_thai_lap_may WHERE id = 407");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_PRETTY_PRINT);
