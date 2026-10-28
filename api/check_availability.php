<?php
// api/check_availability.php - Real-time Room Availability & Conflict Verification API

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = getDB();

$roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$date = isset($_GET['date']) ? trim($_GET['date']) : '';
$startTime = isset($_GET['start_time']) ? trim($_GET['start_time']) : '';
$endTime = isset($_GET['end_time']) ? trim($_GET['end_time']) : '';
$attendance = isset($_GET['attendance']) ? (int)$_GET['attendance'] : 0;
$excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;

if (!$roomId || !$date || !$startTime || !$endTime) {
    echo json_encode(['error' => 'Missing parameters for availability check.']);
    exit;
}

// 1. Fetch Room Info & Status
$roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = ?");
$roomStmt->execute([$roomId]);
$room = $roomStmt->fetch();

if (!$room) {
    echo json_encode(['available' => false, 'message' => 'Room not found.']);
    exit;
}

if ($room['room_status'] !== 'Operational') {
    echo json_encode([
        'available' => false,
        'message' => "This room is currently marked as '{$room['room_status']}' and cannot be reserved.",
        'room_status' => $room['room_status']
    ]);
    exit;
}

// 2. Check Overlap with Existing Approved Reservations
// Overlap formula: NewStart < ExistingEnd AND NewEnd > ExistingStart
$conflict = checkRoomConflict($pdo, $roomId, $date, $startTime, $endTime, $excludeId);

if ($conflict) {
    echo json_encode([
        'available' => false,
        'message' => "Conflict detected: Room '{$room['room_number']}' already has an approved reservation during this time interval.",
        'conflict' => [
            'reservation_id' => $conflict['reservation_id'],
            'purpose' => $conflict['purpose'],
            'start_time' => $conflict['start_time'],
            'end_time' => $conflict['end_time'],
            'requester' => $conflict['requester_name']
        ]
    ]);
    exit;
}

// 3. Attendance Capacity Check
$capacityWarning = false;
if ($attendance > 0 && $attendance > $room['capacity']) {
    $capacityWarning = true;
}

echo json_encode([
    'available' => true,
    'room_capacity' => $room['capacity'],
    'capacity_warning' => $capacityWarning,
    'message' => 'Room is operational and available for this time slot.'
]);
exit;
