<?php
require 'config.php';
try {
    $stmt = $pdo->query("DESCRIBE trang_thai_lap_may");
    echo "trang_thai_lap_may structure:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Key']} | {$row['Default']}\n";
    }
    
    $stmt = $pdo->query("DESCRIBE chitiet_donhang");
    echo "\nchitiet_donhang structure:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Key']} | {$row['Default']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
