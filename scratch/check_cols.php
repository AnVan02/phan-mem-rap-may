<?php
require "config.php";
$stmt = $pdo->query("DESCRIBE donhang");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
