<?php
require 'config.php';
$id = 1000;
$stmt = $pdo->prepare('SELECT so_luong_may FROM donhang WHERE id_donhang = ?');
$stmt->execute([$id]);
print_r($stmt->fetch());

$stmt = $pdo->prepare('SELECT so_may, count(*) as count FROM chitiet_donhang WHERE id_donhang = ? GROUP BY so_may');
$stmt->execute([$id]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
