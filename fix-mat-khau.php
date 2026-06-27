<?php
require 'config.php';

$users = [
    ['ketoan',  'Ke7@Abc!2025xZ'],
    ['kythuat', 'Ky8#Xyz$2025mN'],
    ['admin',   'Ad9!Mnp@2025qR'],
];

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
foreach ($users as [$username, $plain]) {
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $rows = $stmt->execute([$hash, $username]);
    echo "✅ Đã cập nhật: <strong>$username</strong><br>";
}
echo "<br>Xong! Hãy xóa file này sau khi dùng.";
