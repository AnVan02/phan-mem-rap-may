<?php
session_start();
require "config.php";

echo "Session User ID: " . ($_SESSION['user_id'] ?? 'NULL') . "\n";

$stmt = $pdo->query("DESCRIBE chitiet_donhang");
echo "Table structure for chitiet_donhang:\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}

$stmt = $pdo->query("SELECT id_ct, so_serial, user_id, user_id_save, so_may, linhkien_chon FROM chitiet_donhang ORDER BY id_ct DESC LIMIT 10");
echo "Latest 10 records in chitiet_donhang:\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: " . $row['id_ct'] . " | SN: " . $row['so_serial'] . " | UserID: " . ($row['user_id'] ?? 'NULL') . " | UserSave: " . $row['user_id_save'] . " | Machine: " . $row['so_may'] . "\n";
}
