<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h3>Đang sửa quyền truy cập file (Permissions)...</h3>";

function fixPermissions($path) {
    if (!file_exists($path)) {
        echo "Thư mục không tồn tại: $path<br>";
        return;
    }

    $dirCount = 0;
    $fileCount = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $pathname = $item->getPathname();
        if ($item->isDir()) {
            if (chmod($pathname, 0755)) {
                $dirCount++;
            }
        } else {
            if (chmod($pathname, 0644)) {
                $fileCount++;
            }
        }
    }
    
    // Set permission cho chính thư mục gốc
    chmod($path, 0755);

    echo "Đã sửa thành công: $dirCount thư mục (0755) và $fileCount tệp tin (0644).<br>";
}

fixPermissions(__DIR__ . '/vendor');
echo "<strong>Đã hoàn thành! Bạn hãy tải lại trang xuất Excel nhé.</strong>";
