<?php
require "config.php";
$stmt = $pdo->query("SELECT * FROM trang_thai_lap_may");
echo "LOCKS:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query("SELECT id_ct, id_donhang, so_may, linhkien_chon, user_id, user_id_save FROM chitiet_donhang WHERE user_id IS NOT NULL AND user_id_save IS NULL");
echo "\nIN-PROGRESS COMPONENTS:\n";
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
