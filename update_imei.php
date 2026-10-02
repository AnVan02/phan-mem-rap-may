<?php
require 'config.php';

// First check current value
$stmt = $pdo->prepare("SELECT imei FROM donhang WHERE id_donhang = 77");
$stmt->execute();
$current = $stmt->fetchColumn();
$imei_arr = json_decode($current, true) ?? [];

echo "Current IMEI array: " . json_encode($imei_arr) . "\n";

// For Configuration 1 with 2 machines:
// Machine 1 (index 0) should be 1
// Machine 2 (index 1) should be 2
// Update index 1 from 3 to 2
if (isset($imei_arr[1])) {
   $imei_arr[1] = '2';
}

$new_imei = json_encode($imei_arr);
echo "New IMEI array: " . json_encode($imei_arr) . "\n";

// Update database
$stmt = $pdo->prepare("UPDATE donhang SET imei = ? WHERE id_donhang = 77");
$stmt->execute([$new_imei]);

echo "Updated successfully!\n";
