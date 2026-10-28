<?php
// logout.php - Session termination
require_once __DIR__ . '/includes/auth.php';

logoutUser();
session_start();
$_SESSION['flash_success'] = "You have been logged out successfully.";
header("Location: index.php");
exit;
