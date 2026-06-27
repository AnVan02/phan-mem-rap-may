<?php
require dirname(__DIR__) . '/config.php';
$stmt = $pdo->prepare("SELECT id_ct,id_donhang,loai_linhkien,ten_linhkien,so_may,so_serial FROM chitiet_donhang WHERE UPPER(loai_linhkien) IN ('IMEI','IMER') ORDER BY id_donhang DESC LIMIT 20");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
   echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
