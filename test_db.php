<?php
require 'config.php';
$stmt = $pdo->query('SELECT id_donhang, id_ct, so_may, ten_cauhinh, loai_linhkien, ten_linhkien, so_serial, linhkien_chon FROM chitiet_donhang ORDER BY id_donhang DESC, id_ct ASC LIMIT 30');
$data = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $data[] = $row;
}
file_put_contents('db_dump.json', json_encode($data, JSON_PRETTY_PRINT));
echo "Done";