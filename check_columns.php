<?php
require 'config.php';
try {
   $stmt = $pdo->query('DESCRIBE donhang');
   echo "Columns in donhang table:\n";
   foreach ($stmt->fetchAll() as $row) {
      echo $row['Field'] . ' - ' . $row['Type'] . "\n";
   }
} catch (Exception $e) {
   echo 'Error: ' . $e->getMessage();
}
