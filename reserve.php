<?php
// reserve.php - Submit Room Reservation Request
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$user = currentUser();
$pdo = getDB();
$pageTitle = 'Reserve a Room';
$pageSubtitle = 'Select your preferred space, schedule, and submit for review';

$prefillRoomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$prefillDate = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$prefillStart = isset($_GET['start']) ? trim($_GET['start']) : '09:00';
$prefillEnd = isset($_GET['end']) ? trim($_GET['end']) : '10:00';

$error = '';
$conflictAlert = null;

// Fetch all rooms for selector
$roomsStmt = $pdo->query("SELECT * FROM rooms ORDER BY room_status ASC, room_number ASC");
$allRooms = $roomsStmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $error = "Form security token expired. Please try again.";
    } else {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $resDate = trim($_POST['reservation_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $attendance = (int)($_POST['attendance'] ?? 1);

        // Retain values on error
        $prefillRoomId = $roomId;
        $prefillDate = $resDate;
        $prefillStart = $startTime;
        $prefillEnd = $endTime;

        if (!$roomId || empty($resDate) || empty($startTime) || empty($endTime) || empty($purpose)) {
            $error = "Please fill in all required fields.";
        } elseif ($startTime >= $endTime) {
            $error = "Validation Error: Reservation End Time must be later than Start Time.";
        } elseif ($resDate < date('Y-m-d')) {
            $error = "Validation Error: Reservations cannot be scheduled for past dates.";
        } else {
            // Verify Room Status
            $rStmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = ?");
            $rStmt->execute([$roomId]);
            $selectedRoom = $rStmt->fetch();

            if (!$selectedRoom) {
                $error = "Selected room does not exist.";
            } elseif ($selectedRoom['room_status'] !== 'Operational') {
                $error = "Cannot submit request: Room '{$selectedRoom['room_number']}' is currently marked as '{$selectedRoom['room_status']}'.";
            } else {
                // Conflict detection: NewStart < ExistingEnd AND NewEnd > ExistingStart
                $conflict = checkRoomConflict($pdo, $roomId, $resDate, $startTime, $endTime);

                if ($conflict) {
                    $conflictAlert = $conflict;
                    $error = "Scheduling Conflict Detected: Room '{$selectedRoom['room_number']}' is already booked by {$conflict['requester_name']} for '{$conflict['purpose']}' (" . formatTime($conflict['start_time']) . " - " . formatTime($conflict['end_time']) . "). Please select a different time slot or room.";
                } else {
                    // Insert Pending Reservation
                    $insertStmt = $pdo->prepare("
                        INSERT INTO reservations (user_id, room_id, purpose, attendance, reservation_date, start_time, end_time, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
                    ");
                    $insertStmt->execute([
                        $user['user_id'],
                        $roomId,
                        $purpose,
                        $attendance,
                        $resDate,
                        $startTime,
                        $endTime
                    ]);
                    $newResId = $pdo->lastInsertId();

                    // Record initial audit history
                    addReservationHistory($pdo, $newResId, $user['user_id'], null, 'Pending', 'Initial reservation request submitted by requester.');

                    // Send In-App notification
                    sendNotification($pdo, $user['user_id'], $newResId, 'Reservation Request Submitted', "Your request for {$selectedRoom['room_number']} on " . formatDate($resDate) . " has been saved as Pending and forwarded for administrator review.");

                    $_SESSION['flash_success'] = "Reservation request #{$newResId} successfully submitted! It is now Pending administrator review.";
                    header("Location: reservations.php");
                    exit;
                }
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 860px; margin: 0 auto;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                Room Reservation Submission Form
            </div>
            <a href="rooms.php" class="btn btn-secondary btn-sm">Browse Available Rooms</a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="flash-message flash-error">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Real-time AJAX Conflict / Availability Feedback Banner -->
        <div id="conflict_feedback" style="display:none;"></div>

        <form method="POST" action="reserve.php" id="reservationForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <div class="form-group">
                <label class="form-label" for="reserve_room_id">Select Room *</label>
                <select name="room_id" id="reserve_room_id" class="form-control" required>
                    <option value="">-- Choose a Facility / Room --</option>
                    <?php foreach ($allRooms as $rm): ?>
                        <option value="<?= $rm['room_id'] ?>" 
                            <?= $rm['room_id'] == $prefillRoomId ? 'selected' : '' ?>
                            <?= $rm['room_status'] !== 'Operational' ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($rm['room_number']) ?> &mdash; <?= htmlspecialchars($rm['location']) ?> (Cap: <?= $rm['capacity'] ?>) <?= $rm['room_status'] !== 'Operational' ? '[' . $rm['room_status'] . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--text-muted); font-size:0.75rem; margin-top:4px; display:block;">
                    Only operational rooms are selectable. Rooms in maintenance or unavailable cannot be reserved.
                </small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="reserve_date">Reservation Date *</label>
                    <input type="date" name="reservation_date" id="reserve_date" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($prefillDate) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="reserve_attendance">Expected Attendance *</label>
                    <input type="number" name="attendance" id="reserve_attendance" class="form-control" required min="1" max="500" value="10" placeholder="Number of attendees">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="reserve_start">Start Time *</label>
                    <input type="time" name="start_time" id="reserve_start" class="form-control" required value="<?= htmlspecialchars($prefillStart) ?>" step="900">
                </div>

                <div class="form-group">
                    <label class="form-label" for="reserve_end">End Time *</label>
                    <input type="time" name="end_time" id="reserve_end" class="form-control" required value="<?= htmlspecialchars($prefillEnd) ?>" step="900">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="reserve_purpose">Reservation Purpose & Event Details *</label>
                <textarea name="purpose" id="reserve_purpose" class="form-control" rows="3" required placeholder="Describe the goal of the meeting, technology needed, or audience profile..."></textarea>
            </div>

            <div style="background:rgba(255,255,255,0.03); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; margin-bottom:24px;">
                <div style="font-size:0.82rem; font-weight:700; color:var(--text-primary); margin-bottom:6px; display:flex; align-items:center; gap:8px;">
                    <span style="color:var(--primary);">ℹ️</span> Scheduling Conflict Engine Rules
                </div>
                <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.4;">
                    Our conflict detection rule mandates that two reservations overlap when:
                    <code style="font-family:var(--font-mono); color:#a5b4fc; background:rgba(0,0,0,0.3); padding:2px 6px; border-radius:4px;">NewStart &lt; ExistingEnd AND NewEnd &gt; ExistingStart</code>.
                    This allows a new reservation to start at the exact moment a prior approved booking concludes.
                </p>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" id="submitReservationBtn" class="btn btn-primary" style="padding:11px 28px;">
                    Submit Reservation Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Trigger initial live check if room and dates were prefilled
document.addEventListener('DOMContentLoaded', () => {
    const roomSelect = document.getElementById('reserve_room_id');
    if (roomSelect && roomSelect.value) {
        roomSelect.dispatchEvent(new Event('change'));
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
