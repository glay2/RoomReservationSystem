<?php
// schedule.php - Interactive Room Schedule & Hourly Availability Timeline Matrix
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$user = currentUser();
$pdo = getDB();

$pageTitle = 'Facility Schedules & Timeline';
$pageSubtitle = 'Inspect real-time hourly room occupancy and detect scheduling conflicts';

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedRoomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

// Fetch rooms
$roomSql = "SELECT * FROM rooms WHERE 1=1";
$roomParams = [];
if ($selectedRoomId > 0) {
    $roomSql .= " AND room_id = ?";
    $roomParams[] = $selectedRoomId;
}
$roomSql .= " ORDER BY room_status ASC, room_number ASC";
$rStmt = $pdo->prepare($roomSql);
$rStmt->execute($roomParams);
$rooms = $rStmt->fetchAll();

// All rooms list for dropdown
$allRooms = $pdo->query("SELECT room_id, room_number FROM rooms ORDER BY room_number ASC")->fetchAll();

// Fetch all reservations for the selected date
$resStmt = $pdo->prepare("
    SELECT r.*, rm.room_number, u.full_name AS requester_name
    FROM reservations r
    JOIN rooms rm ON r.room_id = rm.room_id
    JOIN users u ON r.user_id = u.user_id
    WHERE r.reservation_date = ? AND r.status IN ('Approved', 'Pending')
    ORDER BY r.start_time ASC
");
$resStmt->execute([$selectedDate]);
$dateReservations = $resStmt->fetchAll();

// Time slot columns from 08:00 to 19:00
$timeSlots = [
    '08:00:00' => '08:00 - 09:00',
    '09:00:00' => '09:00 - 10:00',
    '10:00:00' => '10:00 - 11:00',
    '11:00:00' => '11:00 - 12:00',
    '12:00:00' => '12:00 - 13:00',
    '13:00:00' => '13:00 - 14:00',
    '14:00:00' => '14:00 - 15:00',
    '15:00:00' => '15:00 - 16:00',
    '16:00:00' => '16:00 - 17:00',
    '17:00:00' => '17:00 - 18:00',
    '18:00:00' => '18:00 - 19:00',
    '19:00:00' => '19:00 - 20:00',
];

// Helper to determine booking overlap for an hour slot
function getSlotBooking($reservations, $roomId, $slotStart) {
    $slotEnd = date('H:i:s', strtotime($slotStart) + 3600);
    // Overlap: NewStart < ExistingEnd AND NewEnd > ExistingStart
    foreach ($reservations as $res) {
        if ((int)$res['room_id'] === (int)$roomId) {
            if ($slotStart < $res['end_time'] && $slotEnd > $res['start_time']) {
                return $res;
            }
        }
    }
    return null;
}

include __DIR__ . '/includes/header.php';
?>

<!-- Schedule Controller Header -->
<div class="card" style="margin-bottom: 24px;">
    <div class="schedule-header-controls">
        <form method="GET" action="schedule.php" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="font-size:0.8rem; font-weight:700; color:var(--text-muted);">DATE:</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($selectedDate) ?>" style="padding:6px 12px; font-size:0.85rem; width:160px;" onchange="this.form.submit()">
            </div>

            <div style="display:flex; align-items:center; gap:8px;">
                <label style="font-size:0.8rem; font-weight:700; color:var(--text-muted);">ROOM FILTER:</label>
                <select name="room_id" class="form-control" style="padding:6px 12px; font-size:0.85rem; width:220px;" onchange="this.form.submit()">
                    <option value="0">-- All Facilities (Fleet View) --</option>
                    <?php foreach ($allRooms as $arm): ?>
                        <option value="<?= $arm['room_id'] ?>" <?= $arm['room_id'] == $selectedRoomId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($arm['room_number']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary btn-sm">Refresh Schedule</button>

            <!-- Quick Day Navigators -->
            <div style="display:flex; gap:4px; margin-left:8px;">
                <a href="schedule.php?date=<?= date('Y-m-d', strtotime($selectedDate . ' -1 day')) ?><?= $selectedRoomId ? '&room_id=' . $selectedRoomId : '' ?>" class="btn btn-secondary btn-sm btn-icon" title="Previous Day">◀</a>
                <a href="schedule.php?date=<?= date('Y-m-d') ?><?= $selectedRoomId ? '&room_id=' . $selectedRoomId : '' ?>" class="btn btn-secondary btn-sm" style="font-size:0.75rem;">Today</a>
                <a href="schedule.php?date=<?= date('Y-m-d', strtotime($selectedDate . ' +1 day')) ?><?= $selectedRoomId ? '&room_id=' . $selectedRoomId : '' ?>" class="btn btn-secondary btn-sm btn-icon" title="Next Day">▶</a>
            </div>
        </form>

        <!-- Legend -->
        <div style="display:flex; gap:14px; align-items:center; font-size:0.75rem;">
            <div style="display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:12px; height:12px; background:rgba(99, 102, 241, 0.4); border:1px solid #6366f1; border-radius:3px;"></span>
                <span>Approved Booking</span>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:12px; height:12px; background:rgba(245, 158, 11, 0.2); border:1px dashed #f59e0b; border-radius:3px;"></span>
                <span>Pending Review</span>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:12px; height:12px; background:rgba(16, 185, 129, 0.15); border:1px solid #10b981; border-radius:3px;"></span>
                <span>Available</span>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:12px; height:12px; background:rgba(239, 68, 68, 0.2); border:1px solid #ef4444; border-radius:3px;"></span>
                <span>Unavailable / Maintenance</span>
            </div>
        </div>
    </div>
</div>

<!-- Interactive Schedule Grid -->
<div class="schedule-container">
    <div style="margin-bottom:14px; font-weight:700; font-size:0.95rem; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center;">
        <span>Schedule for <?= formatDate($selectedDate) ?></span>
        <span style="font-size:0.78rem; color:var(--text-muted);">Click on any free slot to instantly pre-fill a reservation request</span>
    </div>

    <div class="table-responsive">
        <table class="schedule-table">
            <thead>
                <tr>
                    <th style="min-width:180px; text-align:left;">Facility / Room</th>
                    <?php foreach ($timeSlots as $slotTime => $slotLabel): ?>
                        <th style="min-width:105px;"><?= $slotLabel ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $rm): ?>
                    <tr>
                        <td class="schedule-room-cell">
                            <div style="font-weight:700;"><?= htmlspecialchars($rm['room_number']) ?></div>
                            <div style="font-size:0.72rem; color:var(--text-muted);">
                                Cap: <?= $rm['capacity'] ?> &bull; <?= htmlspecialchars($rm['location']) ?>
                            </div>
                            <div style="margin-top:2px;">
                                <span class="badge <?= getRoomStatusBadgeClass($rm['room_status']) ?>" style="font-size:0.65rem; padding:1px 6px;">
                                    <?= $rm['room_status'] ?>
                                </span>
                            </div>
                        </td>

                        <?php foreach ($timeSlots as $slotTime => $slotLabel): 
                            $slotStart = $slotTime;
                            $slotEnd = date('H:i:s', strtotime($slotTime) + 3600);
                        ?>
                            <?php if ($rm['room_status'] === 'Maintenance'): ?>
                                <td class="slot-maintenance">
                                    🛠️ Maint.
                                </td>
                            <?php elseif ($rm['room_status'] === 'Unavailable'): ?>
                                <td class="slot-unavailable">
                                    🚫 Closed
                                </td>
                            <?php else: 
                                $booking = getSlotBooking($dateReservations, $rm['room_id'], $slotStart);
                            ?>
                                <?php if ($booking): ?>
                                    <?php if ($booking['status'] === 'Approved'): ?>
                                        <td class="slot-booked" title="Reserved by <?= htmlspecialchars($booking['requester_name']) ?>: <?= htmlspecialchars($booking['purpose']) ?>">
                                            <div style="font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                <?= htmlspecialchars($booking['purpose']) ?>
                                            </div>
                                            <div style="font-size:0.65rem; color:#a5b4fc; margin-top:2px;">
                                                <?= htmlspecialchars($booking['requester_name']) ?>
                                            </div>
                                        </td>
                                    <?php else: // Pending ?>
                                        <td style="background:rgba(245, 158, 11, 0.15); border:1px dashed #f59e0b; color:#fbbf24; border-radius:var(--radius-sm); font-size:0.7rem; padding:4px;" 
                                            title="Pending Request: <?= htmlspecialchars($booking['purpose']) ?>">
                                            <div style="font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                ⏳ Pending
                                            </div>
                                            <div style="font-size:0.65rem; color:#fde68a;">
                                                <?= htmlspecialchars($booking['requester_name']) ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <td class="slot-free" 
                                        onclick="location.href='reserve.php?room_id=<?= $rm['room_id'] ?>&date=<?= $selectedDate ?>&start=<?= substr($slotStart, 0, 5) ?>&end=<?= substr($slotEnd, 0, 5) ?>'" 
                                        title="Click to reserve <?= htmlspecialchars($rm['room_number']) ?> for <?= $slotLabel ?>">
                                        + Free
                                    </td>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
