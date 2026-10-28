<?php
// includes/header.php - Global Header & Navigation Topbar
require_once __DIR__ . '/auth.php';

$user = currentUser();
$pdo = getDB();

$unreadCount = 0;
$recentNotifications = [];
if ($user) {
    $notifStmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 6
    ");
    $notifStmt->execute([$user['user_id']]);
    $recentNotifications = $notifStmt->fetchAll();

    foreach ($recentNotifications as $n) {
        if (!$n['is_read']) $unreadCount++;
    }
}

// Flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' | Room Reservation System' : 'Room Reservation System' ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%236366f1'><path d='M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10z'/></svg>">
</head>
<body>

<div class="app-container">
    <?php if ($user): ?>
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="app-main">
            <header class="app-header">
                <div style="display:flex; align-items:center; gap:16px;">
                    <button id="sidebarToggle" class="btn btn-secondary btn-icon menu-toggle" style="display:none;" aria-label="Toggle Navigation">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                    </button>
                    <div class="header-title-block">
                        <h1><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
                        <p><?= htmlspecialchars($pageSubtitle ?? 'Manage reservations, room allocations, and schedules effortlessly') ?></p>
                    </div>
                </div>

                <div class="header-actions">
                    <!-- Quick Role Switcher for Testing / Demonstration -->
                    <div style="display:flex; align-items:center; gap:6px; background:rgba(255,255,255,0.04); border:1px solid var(--border-color); padding:4px 8px; border-radius:var(--radius-md);">
                        <span style="font-size:0.72rem; color:var(--text-muted); font-weight:600;">SWITCH ROLE:</span>
                        <a href="switch_role.php?role=Administrator" class="btn btn-sm <?= $user['role'] === 'Administrator' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:0.7rem; padding:3px 8px;">Admin</a>
                        <a href="switch_role.php?role=Room Manager" class="btn btn-sm <?= $user['role'] === 'Room Manager' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:0.7rem; padding:3px 8px;">Manager</a>
                        <a href="switch_role.php?role=Requester" class="btn btn-sm <?= $user['role'] === 'Requester' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:0.7rem; padding:3px 8px;">Requester</a>
                    </div>

                    <!-- In-App Notification Bell -->
                    <div style="position:relative;">
                        <button id="notifToggleBtn" class="btn btn-secondary btn-icon" title="Notifications" style="position:relative;">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            <?php if ($unreadCount > 0): ?>
                                <span id="notifBadge" style="position:absolute; top:-4px; right:-4px; background:var(--danger); color:white; font-size:0.65rem; font-weight:800; padding:2px 6px; border-radius:var(--radius-full); border:2px solid var(--bg-surface);">
                                    <?= $unreadCount ?>
                                </span>
                            <?php endif; ?>
                        </button>

                        <div id="notifDropdown" class="notif-dropdown">
                            <div class="notif-header">
                                <span style="font-size:0.85rem; font-weight:700;">Notifications</span>
                                <button id="markAllReadBtn" class="btn btn-sm btn-secondary" style="font-size:0.68rem; padding:2px 8px;">Mark all read</button>
                            </div>
                            <div style="max-height:300px; overflow-y:auto;">
                                <?php if (empty($recentNotifications)): ?>
                                    <div style="padding:20px; text-align:center; color:var(--text-muted); font-size:0.8rem;">
                                        No notifications yet.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentNotifications as $notif): ?>
                                        <div class="notif-item <?= !$notif['is_read'] ? 'unread' : '' ?>">
                                            <div class="notif-title"><?= htmlspecialchars($notif['title']) ?></div>
                                            <div class="notif-text"><?= htmlspecialchars($notif['message']) ?></div>
                                            <div class="notif-time"><?= date('M d, H:i', strtotime($notif['created_at'])) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <a href="reserve.php" class="btn btn-primary btn-sm">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        New Reservation
                    </a>
                </div>
            </header>

            <main class="content-body">
                <?php if ($flashSuccess): ?>
                    <div class="flash-message flash-success">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span><?= htmlspecialchars($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashError): ?>
                    <div class="flash-message flash-error">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <span><?= htmlspecialchars($flashError) ?></span>
                    </div>
                <?php endif; ?>
    <?php endif; ?>
