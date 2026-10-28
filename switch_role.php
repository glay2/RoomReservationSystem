<?php
// switch_role.php - Quick role switcher for demo and testing convenience
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$role = $_GET['role'] ?? 'Administrator';
$pdo = getDB();

$validRoles = ['Administrator', 'Room Manager', 'Requester'];
if (!in_array($role, $validRoles, true)) {
    $role = 'Administrator';
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE role = ? AND account_status = 'Active' LIMIT 1");
$stmt->execute([$role]);
$user = $stmt->fetch();

if ($user) {
    loginUser($user);
    $_SESSION['flash_success'] = "Switched active view to {$user['role']} ({$user['full_name']}).";
}

$redirect = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header("Location: $redirect");
exit;
