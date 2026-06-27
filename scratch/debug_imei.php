<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=phan-mem-rap-may', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "--- IMEI Records in chitiet_donhang ---\n";
    $stmt = $pdo->query("SELECT id_ct, id_donhang, loai_linhkien, so_serial, so_may, linhkien_chon FROM chitiet_donhang WHERE loai_linhkien LIKE '%imei%' OR loai_linhkien LIKE '%imer%' ORDER BY id_donhang DESC LIMIT 10");
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode($r) . "\n";
    }
    
    echo "\n--- IMEI Data in donhang ---\n";
    $stmt = $pdo->query("SELECT id_donhang, imei FROM donhang WHERE imei IS NOT NULL AND imei != '' ORDER BY id_donhang DESC LIMIT 5");
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode($r) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
