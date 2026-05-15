<?php
session_start();
session_destroy();
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
header('Location: ' . $base . '/login.php');
exit;
