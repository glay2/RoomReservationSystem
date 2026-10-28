<?php
// process_reservation.php - Handles approval, rejection, and cancellation workflows with conflict prevention

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

if (!validateCsrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = "Security validation token expired. Please try again.";
    header("Location: dashboard.php");
    exit;
}

$user = currentUser();
$pdo = getDB();

$action = $_POST['action'] ?? '';
$reservationId = (int)($_POST['reservation_id'] ?? 0);
$remarks = trim($_POST['remarks'] ?? '');

if (!$reservationId) {
    $_SESSION['flash_error'] = "Invalid reservation reference.";
    header("Location: dashboard.php");
    exit;
}

// -------------------------------------------------------------
// ACTION: APPROVE (Administrator Only)
// -------------------------------------------------------------
if ($action === 'approve') {
    if (!hasRole(['Administrator'])) {
        $_SESSION['flash_error'] = "Permission Error: Only administrators can approve reservation requests.";
        header("Location: dashboard.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Lock the reservation row for update
        $resStmt = $pdo->prepare("SELECT * FROM reservations WHERE reservation_id = ? FOR UPDATE");
        $resStmt->execute([$reservationId]);
        $res = $resStmt->fetch();

        if (!$res) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Reservation not found.";
            header("Location: dashboard.php");
            exit;
        }

        if ($res['status'] === 'Approved') {
            $pdo->rollBack();
            $_SESSION['flash_success'] = "Reservation is already approved.";
            header("Location: dashboard.php");
            exit;
        }

        // 2. Fetch room info
        $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = ? FOR UPDATE");
        $roomStmt->execute([$res['room_id']]);
        $room = $roomStmt->fetch();

        if (!$room || $room['room_status'] !== 'Operational') {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Cannot approve: Room '{$room['room_number']}' is currently marked as '{$room['room_status']}'.";
            header("Location: dashboard.php");
            exit;
        }

        // 3. CRITICAL: Recheck scheduling conflict right before approval
        // Formula: NewStart < ExistingEnd AND NewEnd > ExistingStart
        $conflict = checkRoomConflict(
            $pdo,
            $res['room_id'],
            $res['reservation_date'],
            $res['start_time'],
            $res['end_time'],
            $res['reservation_id']
        );

        if ($conflict) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Approval Blocked! Conflict detected: Room '{$room['room_number']}' already has an approved booking (#{$conflict['reservation_id']}: '{$conflict['purpose']}') from " . formatTime($conflict['start_time']) . " to " . formatTime($conflict['end_time']) . ".";
            header("Location: reservations.php");
            exit;
        }

        // 4. Update status to Approved
        $updateStmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'Approved', approved_by = ?, updated_at = NOW() 
            WHERE reservation_id = ?
        ");
        $updateStmt->execute([$user['user_id'], $reservationId]);

        // 5. Write to reservation_history
        $logRemarks = !empty($remarks) ? $remarks : "Approved by administrator {$user['full_name']}. Schedule verified.";
        addReservationHistory($pdo, $reservationId, $user['user_id'], $res['status'], 'Approved', $logRemarks);

        // 6. Notify requester
        $notifMessage = "Your reservation request for {$room['room_number']} on " . formatDate($res['reservation_date']) . " (" . formatTime($res['start_time']) . " - " . formatTime($res['end_time']) . ") has been approved.";
        if (!empty($remarks)) {
            $notifMessage .= " Note: " . $remarks;
        }
        sendNotification($pdo, $res['user_id'], $reservationId, 'Reservation Approved', $notifMessage);

        $pdo->commit();
        $_SESSION['flash_success'] = "Reservation #{$reservationId} for {$room['room_number']} has been successfully approved!";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = "Database error during approval: " . $e->getMessage();
    }

    header("Location: reservations.php");
    exit;
}

// -------------------------------------------------------------
// ACTION: REJECT (Administrator Only)
// -------------------------------------------------------------
if ($action === 'reject') {
    if (!hasRole(['Administrator'])) {
        $_SESSION['flash_error'] = "Permission Error: Only administrators can reject reservation requests.";
        header("Location: dashboard.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        $resStmt = $pdo->prepare("SELECT * FROM reservations WHERE reservation_id = ? FOR UPDATE");
        $resStmt->execute([$reservationId]);
        $res = $resStmt->fetch();

        if (!$res) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Reservation not found.";
            header("Location: dashboard.php");
            exit;
        }

        // Fetch room info
        $roomStmt = $pdo->prepare("SELECT room_number FROM rooms WHERE room_id = ?");
        $roomStmt->execute([$res['room_id']]);
        $roomNumber = $roomStmt->fetchColumn() ?: "Room #{$res['room_id']}";

        // Update status to Rejected
        $updateStmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'Rejected', approved_by = ?, updated_at = NOW() 
            WHERE reservation_id = ?
        ");
        $updateStmt->execute([$user['user_id'], $reservationId]);

        // Audit log in reservation_history
        $rejectRemarks = !empty($remarks) ? $remarks : "Reservation rejected by administrator.";
        addReservationHistory($pdo, $reservationId, $user['user_id'], $res['status'], 'Rejected', $rejectRemarks);

        // Notify Requester
        $notifMessage = "Your reservation request for {$roomNumber} on " . formatDate($res['reservation_date']) . " was rejected. Reason: " . $rejectRemarks;
        sendNotification($pdo, $res['user_id'], $reservationId, 'Reservation Request Rejected', $notifMessage);

        $pdo->commit();
        $_SESSION['flash_success'] = "Reservation #{$reservationId} has been rejected, and the requester was notified.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = "Database error during rejection: " . $e->getMessage();
    }

    header("Location: reservations.php");
    exit;
}

// -------------------------------------------------------------
// ACTION: CANCEL (Authorized User or Administrator)
// Flowchart: Authorized to Cancel? No -> Display Permission Error
// -------------------------------------------------------------
if ($action === 'cancel') {
    try {
        $pdo->beginTransaction();

        $resStmt = $pdo->prepare("SELECT * FROM reservations WHERE reservation_id = ? FOR UPDATE");
        $resStmt->execute([$reservationId]);
        $res = $resStmt->fetch();

        if (!$res) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Reservation not found.";
            header("Location: dashboard.php");
            exit;
        }

        // Check Authorization
        $isOwner = ((int)$res['user_id'] === (int)$user['user_id']);
        $isAdmin = ($user['role'] === 'Administrator');

        if (!$isOwner && !$isAdmin) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Permission Error: You are not authorized to cancel this reservation.";
            header("Location: reservations.php");
            exit;
        }

        // Status check - only Pending or Approved can be cancelled
        if (!in_array($res['status'], ['Pending', 'Approved'])) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Cannot cancel reservation that is already '{$res['status']}'.";
            header("Location: reservations.php");
            exit;
        }

        // Room details for notification
        $roomStmt = $pdo->prepare("SELECT room_number FROM rooms WHERE room_id = ?");
        $roomStmt->execute([$res['room_id']]);
        $roomNumber = $roomStmt->fetchColumn() ?: "Room #{$res['room_id']}";

        // Update status to Cancelled
        $updateStmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'Cancelled', updated_at = NOW() 
            WHERE reservation_id = ?
        ");
        $updateStmt->execute([$reservationId]);

        // Audit log in reservation_history
        $cancelRemarks = !empty($remarks) ? $remarks : "Cancelled by {$user['full_name']} ({$user['role']}).";
        addReservationHistory($pdo, $reservationId, $user['user_id'], $res['status'], 'Cancelled', $cancelRemarks);

        // Notify owner if cancelled by Admin
        if ($isAdmin && !$isOwner) {
            $notifMessage = "Your reservation for {$roomNumber} on " . formatDate($res['reservation_date']) . " was cancelled by the administrator. Remarks: " . $cancelRemarks;
            sendNotification($pdo, $res['user_id'], $reservationId, 'Reservation Cancelled by Administrator', $notifMessage);
        }

        $pdo->commit();
        $_SESSION['flash_success'] = "Reservation #{$reservationId} has been successfully cancelled. The room schedule has been refreshed and freed.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = "Database error during cancellation: " . $e->getMessage();
    }

    header("Location: reservations.php");
    exit;
}

// Fallback
header("Location: dashboard.php");
exit;
