<?php
// Đổi session save path về thư mục có quyền ghi (tránh lỗi Permission denied trên C:/laragon/tmp)
$_session_path = __DIR__ . '/sessions';
if (!is_dir($_session_path)) mkdir($_session_path, 0755, true);
ini_set('session.save_path', $_session_path);

// Fallback mbstring nếu server chưa bật extension
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($str, $encoding = 'UTF-8') {
        return strtolower($str);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($str, $encoding = 'UTF-8') {
        return strtoupper($str);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $encoding = 'UTF-8') {
        return strlen($str);
    }
}
if (!function_exists('mb_convert_encoding')) {
    function mb_convert_encoding($str, $to, $from = 'UTF-8') {
        return $str;
    }
}

$host = "localhost";
$dbname = "phan-mem-rap-may";
$username = "root";
$password = "";
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,   // Dùng server-side prepared statement thật
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Mặc định trả mảng key-value
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci",
    ]);
} catch (PDOException $e) {
    die("Lỗi DB: " . $e->getMessage());
}