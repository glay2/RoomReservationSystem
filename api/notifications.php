<?php
// api/notifications.php - User In-App Notification Center API

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDB();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';

if ($action === 'mark_all_read') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'mark_single_read') {
    $notifId = (int)($_POST['notification_id'] ?? 0);
    if ($notifId) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$notifId, $userId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// Fetch list
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 15
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$unreadCount = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) $unreadCount++;
}

echo json_encode([
    'unread_count' => $unreadCount,
    'notifications' => $notifications
]);
exit;
