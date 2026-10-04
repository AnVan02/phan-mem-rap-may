<?php
// Đảm bảo session.save_path đúng TRƯỚC khi start
$_session_path = __DIR__ . '/sessions';
if (!is_dir($_session_path)) mkdir($_session_path, 0755, true);
ini_set('session.save_path', $_session_path);
session_start();
session_unset();
session_destroy();
header("Location: dang-nhap.php");
exit();
?>
