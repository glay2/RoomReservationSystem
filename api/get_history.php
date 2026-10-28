<?php
// api/get_history.php - Fetches audit history timeline for a reservation
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = currentUser();
$pdo = getDB();
$resId = (int)($_GET['reservation_id'] ?? 0);

if (!$resId) {
    echo json_encode(['error' => 'Invalid reservation ID']);
    exit;
}

// Check permission: requester can only view their own reservation history, admin/manager can view all
$resStmt = $pdo->prepare("
    SELECT r.*, rm.room_number, u.full_name AS requester_name 
    FROM reservations r
    JOIN rooms rm ON r.room_id = rm.room_id
    JOIN users u ON r.user_id = u.user_id
    WHERE r.reservation_id = ?
");
$resStmt->execute([$resId]);
$res = $resStmt->fetch();

if (!$res) {
    echo json_encode(['error' => 'Reservation not found']);
    exit;
}

if ($user['role'] === 'Requester' && (int)$res['user_id'] !== (int)$user['user_id']) {
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Fetch history
$histStmt = $pdo->prepare("
    SELECT rh.*, u.full_name AS actor_name, u.role AS actor_role
    FROM reservation_history rh
    JOIN users u ON rh.changed_by = u.user_id
    WHERE rh.reservation_id = ?
    ORDER BY rh.changed_at ASC
");
$histStmt->execute([$resId]);
$history = $histStmt->fetchAll();

echo json_encode([
    'success' => true,
    'reservation' => [
        'reservation_id' => $res['reservation_id'],
        'room_number' => $res['room_number'],
        'requester_name' => $res['requester_name'],
        'purpose' => $res['purpose'],
        'reservation_date' => formatDate($res['reservation_date']),
        'time_window' => formatTime($res['start_time']) . ' - ' . formatTime($res['end_time']),
        'current_status' => $res['status']
    ],
    'history' => $history
]);
exit;
