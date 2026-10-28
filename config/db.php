<?php
// config/db.php - Database connection & Core Utility Helpers

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'room_reservation_system');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

/**
 * Automatically transitions past Approved reservations into Completed status
 * and creates an audit entry in reservation_history.
 */
function syncCompletedReservations($pdo) {
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            SELECT reservation_id, user_id 
            FROM reservations 
            WHERE status = 'Approved' 
              AND TIMESTAMP(CONCAT(reservation_date, ' ', end_time)) <= ?
        ");
        $stmt->execute([$now]);
        $expired = $stmt->fetchAll();

        if (!empty($expired)) {
            $updateStmt = $pdo->prepare("UPDATE reservations SET status = 'Completed', updated_at = NOW() WHERE reservation_id = ?");
            $histStmt = $pdo->prepare("
                INSERT INTO reservation_history (reservation_id, changed_by, previous_status, new_status, remarks, changed_at) 
                VALUES (?, ?, 'Approved', 'Completed', 'Reservation time window has ended. System automatically marked as Completed.', NOW())
            ");

            foreach ($expired as $row) {
                $updateStmt->execute([$row['reservation_id']]);
                // Changed by system (or reservation user)
                $histStmt->execute([$row['reservation_id'], $row['user_id']]);
            }
        }
    } catch (Exception $e) {
        // Silently skip if query fails during setup
    }
}

/**
 * Checks for overlapping approved reservations for the same room.
 * Rule: NewStart < ExistingEnd AND NewEnd > ExistingStart
 * Returns the conflicting reservation record or null if none.
 */
function checkRoomConflict($pdo, $roomId, $reservationDate, $startTime, $endTime, $excludeReservationId = null) {
    $sql = "
        SELECT r.*, rm.room_number, u.full_name AS requester_name
        FROM reservations r
        JOIN rooms rm ON r.room_id = rm.room_id
        JOIN users u ON r.user_id = u.user_id
        WHERE r.room_id = ?
          AND r.reservation_date = ?
          AND r.status = 'Approved'
          AND (? < r.end_time AND ? > r.start_time)
    ";
    $params = [$roomId, $reservationDate, $startTime, $endTime];

    if ($excludeReservationId !== null) {
        $sql .= " AND r.reservation_id != ?";
        $params[] = $excludeReservationId;
    }

    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflict = $stmt->fetch();

    return $conflict ?: null;
}

/**
 * Records an entry in reservation_history for auditing.
 */
function addReservationHistory($pdo, $reservationId, $changedBy, $prevStatus, $newStatus, $remarks) {
    $stmt = $pdo->prepare("
        INSERT INTO reservation_history (reservation_id, changed_by, previous_status, new_status, remarks, changed_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$reservationId, $changedBy, $prevStatus, $newStatus, $remarks]);
}

/**
 * Sends in-system notification to a user.
 */
function sendNotification($pdo, $userId, $reservationId, $title, $message) {
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, reservation_id, title, message, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    return $stmt->execute([$userId, $reservationId, $title, $message]);
}

/**
 * Format helpers
 */
function sanitize($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatDate($dateStr) {
    if (!$dateStr) return 'N/A';
    return date('M d, Y', strtotime($dateStr));
}

function formatTime($timeStr) {
    if (!$timeStr) return 'N/A';
    return date('h:i A', strtotime($timeStr));
}

function formatDateTime($dtStr) {
    if (!$dtStr) return 'N/A';
    return date('M d, Y h:i A', strtotime($dtStr));
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Approved':
            return 'badge-success';
        case 'Pending':
            return 'badge-warning';
        case 'Rejected':
            return 'badge-danger';
        case 'Cancelled':
            return 'badge-secondary';
        case 'Completed':
            return 'badge-info';
        default:
            return 'badge-light';
    }
}

function getRoomStatusBadgeClass($status) {
    switch ($status) {
        case 'Operational':
            return 'badge-success';
        case 'Maintenance':
            return 'badge-warning';
        case 'Unavailable':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}
