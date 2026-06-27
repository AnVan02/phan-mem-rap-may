<?php
require "config.php";
$stmt = $pdo->query("SELECT id_donhang, imei FROM donhang ORDER BY id_donhang DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
