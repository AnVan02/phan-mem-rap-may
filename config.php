<?php
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
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Lỗi DB: " . $e->getMessage());
}