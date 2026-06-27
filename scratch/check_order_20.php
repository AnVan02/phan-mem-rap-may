<?php
require "config.php";
$id = 20;
$stmt = $pdo->prepare("SELECT id_donhang, ten_linhkien, loai_linhkien, so_may, so_serial FROM chitiet_donhang WHERE id_donhang = ?");
$stmt->execute([$id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($rows);
echo "</pre>";
?>
