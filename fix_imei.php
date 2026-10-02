<?php
require 'config.php';

try {
   // Get current IMEI array
   $stmt = $pdo->prepare("SELECT imei FROM donhang WHERE id_donhang = 77");
   $stmt->execute();
   $current_json = $stmt->fetchColumn();
   $imei_array = json_decode($current_json, true);

   echo "Current IMEI array:\n";
   print_r($imei_array);

   // Fix Machine 2 (index 1) in Configuration 1 from 3 to 2
   if (isset($imei_array[1])) {
      echo "\nChanging IMEI at index 1 from {$imei_array[1]} to 2\n";
      $imei_array[1] = '2';
   }

   // Update the database
   $new_json = json_encode($imei_array);
   $update_stmt = $pdo->prepare("UPDATE donhang SET imei = ? WHERE id_donhang = 77");
   $update_stmt->execute([$new_json]);

   echo "\nUpdated successfully!\n";
   echo "New IMEI array:\n";
   print_r($imei_array);
} catch (PDOException $e) {
   echo "Error: " . $e->getMessage();
}
