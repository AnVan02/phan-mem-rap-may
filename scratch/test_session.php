<?php
session_start();
echo "Session ID: " . session_id() . "\n";
echo "User ID: " . ($_SESSION['user_id'] ?? 'NULL') . "\n";
echo "Full Session: " . json_encode($_SESSION) . "\n";
?>
