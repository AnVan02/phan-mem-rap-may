<?php
require 'config.php';
try {
    $stmt = $pdo->query("SHOW INDEX FROM trang_thai_lap_may");
    echo "trang_thai_lap_may indexes:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Table']} | {$row['Non_unique']} | {$row['Key_name']} | {$row['Seq_in_index']} | {$row['Column_name']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
