<?php
require 'config.php';

$old_truncated = "Máy tính nguyên bộ ROSA-A5516512 Ryzen 5 5500GT/16GB/SSD 512GB/Đen/K/M/Win11 HOME/LCD 21.5, Máy tính nguyên bộ ROSA-A5516512 Ryzen 5 5500GT/16GB/SSD 512GB/Đen/K/M/Win11 HOME/LCD 23.8, Máy tính nguyên bộ ROSA-A328512 Ryzen 3 3200G/8GB/SSD 512GB/Đen/K/M/Win";
$full_base = "Máy tính nguyên bộ ROSA-A5516512 Ryzen 5 5500GT/16GB/SSD 512GB/Đen/K/M/Win11 HOME/LCD 21.5, Máy tính nguyên bộ ROSA-A5516512 Ryzen 5 5500GT/16GB/SSD 512GB/Đen/K/M/Win11 HOME/LCD 23.8, Máy tính nguyên bộ ROSA-A328512 Ryzen 3 3200G/8GB/SSD 512GB/Đen/K/M/Win11 HOME/LCD 21.5";

echo "<pre>=== ĐANG SỬA DỮ LIỆU ĐƠN 331 ===\n";

// Sửa cột trong DB thành TEXT để chắc chắn
try {
    $pdo->exec("ALTER TABLE chitiet_donhang MODIFY COLUMN ten_cauhinh TEXT NULL");
    echo "1. Đã đảm bảo cột ten_cauhinh là TEXT.\n";
} catch (Exception $e) {}

// Lấy tất cả dòng của đơn 331
$stmt = $pdo->prepare("SELECT id_ct, so_may, loai_linhkien, ten_cauhinh FROM chitiet_donhang WHERE id_donhang = 331 ORDER BY id_ct");
$stmt->execute();
$rows = $stmt->fetchAll();

$by_loai = [];
foreach ($rows as $r) {
    if (strpos($r['ten_cauhinh'], $old_truncated) === 0 || strpos(trim($r['ten_cauhinh']), $full_base) === 0) {
        $by_loai[$r['loai_linhkien']][] = $r;
    }
}

$count = 0;
foreach ($by_loai as $loai => $item_rows) {
    if (count($item_rows) == 12) {
        // MAIN, SSD, PSU
        for ($i = 0; $i < count($item_rows); $i++) {
            $owner = ($i < 2) ? 0 : (($i < 5) ? 1 : 2);
            $new_cfg = $full_base . str_repeat(' ', $owner);
            $pdo->prepare("UPDATE chitiet_donhang SET ten_cauhinh = ? WHERE id_ct = ?")->execute([$new_cfg, $item_rows[$i]['id_ct']]);
            $count++;
        }
    } elseif (count($item_rows) == 24) {
        // CASE, WIN
        for ($i = 0; $i < count($item_rows); $i++) {
            $owner = ($i < 4) ? 0 : (($i < 10) ? 1 : 2);
            $new_cfg = $full_base . str_repeat(' ', $owner);
            $pdo->prepare("UPDATE chitiet_donhang SET ten_cauhinh = ? WHERE id_ct = ?")->execute([$new_cfg, $item_rows[$i]['id_ct']]);
            $count++;
        }
    }
}

echo "2. Đã sửa thành công $count dòng linh kiện bị cắt cụt cho đơn 331.\n";
echo "Xong! Bạn có thể vào lại đơn 331 để kiểm tra.</pre>";
