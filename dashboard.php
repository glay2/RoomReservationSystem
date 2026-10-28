<?php
// dashboard.php - Role-Tailored Operational Dashboard
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$user = currentUser();
$pdo = getDB();
$pageTitle = $user['role'] . ' Dashboard';
$pageSubtitle = "Operational center for " . htmlspecialchars($user['full_name']);

// General statistics
$totalRooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$operationalRooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE room_status = 'Operational'")->fetchColumn();
$maintenanceRooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE room_status = 'Maintenance'")->fetchColumn();

$today = date('Y-m-d');
$todayBookingsStmt = $pdo->prepare("
    SELECT COUNT(*) FROM reservations 
    WHERE reservation_date = ? AND status = 'Approved'
");
$todayBookingsStmt->execute([$today]);
$todayBookings = $todayBookingsStmt->fetchColumn();

// Role-specific metrics & query logic
if ($user['role'] === 'Administrator') {
    // Admin needs pending review queue
    $pendingStmt = $pdo->query("
        SELECT r.*, rm.room_number, rm.capacity, rm.location, u.full_name AS requester_name, u.email AS requester_email
        FROM reservations r
        JOIN rooms rm ON r.room_id = rm.room_id
        JOIN users u ON r.user_id = u.user_id
        WHERE r.status = 'Pending'
        ORDER BY r.created_at DESC, r.reservation_id DESC
    ");
    $pendingRequests = $pendingStmt->fetchAll();

    $pendingCount = count($pendingRequests);

    // Recent system activity / history
    $recentHistStmt = $pdo->query("
        SELECT rh.*, r.reservation_date, r.start_time, r.end_time, rm.room_number, u.full_name AS actor_name
        FROM reservation_history rh
        JOIN reservations r ON rh.reservation_id = r.reservation_id
        JOIN rooms rm ON r.room_id = rm.room_id
        JOIN users u ON rh.changed_by = u.user_id
        ORDER BY rh.changed_at DESC
        LIMIT 6
    ");
    $recentHistory = $recentHistStmt->fetchAll();

} elseif ($user['role'] === 'Room Manager') {
    // Manager needs room operational overview
    $allRoomsStmt = $pdo->query("
        SELECT r.*, 
            (SELECT COUNT(*) FROM reservations WHERE room_id = r.room_id AND reservation_date = CURDATE() AND status = 'Approved') AS today_bookings_count
        FROM rooms r
        ORDER BY r.room_status ASC, r.room_number ASC
    ");
    $roomsList = $allRoomsStmt->fetchAll();

} else { // Requester
    // Requester needs their bookings summary
    $myPendingStmt = $pdo->prepare("
        SELECT r.*, rm.room_number, rm.location, rm.capacity
        FROM reservations r
        JOIN rooms rm ON r.room_id = rm.room_id
        WHERE r.user_id = ? AND r.status = 'Pending'
        ORDER BY r.created_at DESC, r.reservation_id DESC
    ");
    $myPendingStmt->execute([$user['user_id']]);
    $myPendingRequests = $myPendingStmt->fetchAll();

    $myUpcomingStmt = $pdo->prepare("
        SELECT r.*, rm.room_number, rm.location, rm.capacity
        FROM reservations r
        JOIN rooms rm ON r.room_id = rm.room_id
        WHERE r.user_id = ? AND r.status = 'Approved' AND r.reservation_date >= CURDATE()
        ORDER BY r.reservation_date ASC, r.start_time ASC
    ");
    $myUpcomingStmt->execute([$user['user_id']]);
    $myUpcomingBookings = $myUpcomingStmt->fetchAll();

    $myPastStmt = $pdo->prepare("
        SELECT r.*, rm.room_number, rm.location 
        FROM reservations r
        JOIN rooms rm ON r.room_id = rm.room_id
        WHERE r.user_id = ? AND (r.status IN ('Completed', 'Rejected', 'Cancelled') OR (r.status = 'Approved' AND r.reservation_date < CURDATE()))
        ORDER BY r.reservation_date DESC, r.start_time DESC
        LIMIT 5
    ");
    $myPastStmt->execute([$user['user_id']]);
    $myPastBookings = $myPastStmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<!-- ==============================================
     1. ADMINISTRATOR DASHBOARD VIEW
================================================== -->
<?php if ($user['role'] === 'Administrator'): ?>
    <!-- Admin Top Stats -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Pending Approval</div>
                <div class="stat-value" style="color:var(--warning);"><?= $pendingCount ?></div>
                <div class="stat-desc">Requests awaiting decision</div>
            </div>
            <div class="stat-icon amber">⏳</div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Today's Bookings</div>
                <div class="stat-value" style="color:var(--primary);"><?= $todayBookings ?></div>
                <div class="stat-desc">Confirmed for <?= date('M d') ?></div>
            </div>
            <div class="stat-icon indigo">📅</div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Operational Rooms</div>
                <div class="stat-value" style="color:var(--success);"><?= $operationalRooms ?> / <?= $totalRooms ?></div>
                <div class="stat-desc"><?= $maintenanceRooms ?> rooms in maintenance</div>
            </div>
            <div class="stat-icon emerald">🏢</div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">System Health</div>
                <div class="stat-value" style="color:#38bdf8;">100%</div>
                <div class="stat-desc">Conflict engine active</div>
            </div>
            <div class="stat-icon emerald">🛡️</div>
        </div>
    </div>

    <!-- Review Pending Requests Section -->
    <div class="card" style="margin-bottom: 28px;">
        <div class="card-header">
            <div class="card-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                Pending Reservation Review Queue
            </div>
            <span class="badge badge-warning"><?= $pendingCount ?> Action Required</span>
        </div>

        <?php if (empty($pendingRequests)): ?>
            <div style="text-align:center; padding: 40px 20px; color:var(--text-muted);">
                <div style="font-size:2rem; margin-bottom:8px;">🎉</div>
                <div style="font-size:1rem; font-weight:600; color:var(--text-primary);">All caught up!</div>
                <p style="font-size:0.85rem; margin-top:4px;">There are no pending room reservations awaiting review at this moment.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Room & Location</th>
                            <th>Requester</th>
                            <th>Schedule & Date</th>
                            <th>Purpose & Attendance</th>
                            <th>Conflict Check</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRequests as $req): 
                            // Re-check conflict rule: NewStart < ExistingEnd AND NewEnd > ExistingStart
                            $conflict = checkRoomConflict($pdo, $req['room_id'], $req['reservation_date'], $req['start_time'], $req['end_time']);
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($req['room_number']) ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($req['location']) ?> (Cap: <?= $req['capacity'] ?>)</div>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($req['requester_name']) ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($req['requester_email']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:var(--text-primary);"><?= formatDate($req['reservation_date']) ?></div>
                                    <div style="font-size:0.75rem; font-family:var(--font-mono); color:var(--primary);">
                                        <?= formatTime($req['start_time']) ?> - <?= formatTime($req['end_time']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:500; color:var(--text-primary); max-width:260px;"><?= htmlspecialchars($req['purpose']) ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);">
                                        Attendees: <?= $req['attendance'] ?>
                                        <?php if ($req['attendance'] > $req['capacity']): ?>
                                            <span style="color:var(--warning); font-weight:600;">(Exceeds capacity!)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($conflict): ?>
                                        <span class="badge badge-danger" title="Overlaps with #<?= $conflict['reservation_id'] ?>: <?= htmlspecialchars($conflict['purpose']) ?>">
                                            <span class="badge-dot"></span> Conflict Detected!
                                        </span>
                                        <div style="font-size:0.7rem; color:#fca5a5; margin-top:4px;">
                                            Overlaps: <?= formatTime($conflict['start_time']) ?> - <?= formatTime($conflict['end_time']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-success">
                                            <span class="badge-dot"></span> No Conflict
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px;">
                                        <button class="btn btn-success btn-sm" onclick="openApprovalModal(<?= $req['reservation_id'] ?>, '<?= htmlspecialchars(addslashes($req['room_number'])) ?>', '<?= formatDate($req['reservation_date']) ?>', '<?= formatTime($req['start_time']) ?> - <?= formatTime($req['end_time']) ?>', <?= $conflict ? 'true' : 'false' ?>)">
                                            Approve
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?= $req['reservation_id'] ?>, '<?= htmlspecialchars(addslashes($req['room_number'])) ?>')">
                                            Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Management & Audit Activity -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap:24px;">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Recent System Audit Trail</div>
                <a href="reservations.php" class="btn btn-secondary btn-sm">View All Logs</a>
            </div>
            <div class="history-timeline">
                <?php if (empty($recentHistory)): ?>
                    <p style="color:var(--text-muted); font-size:0.85rem;">No history records available.</p>
                <?php else: ?>
                    <?php foreach ($recentHistory as $h): ?>
                        <div class="timeline-step">
                            <div class="timeline-dot"></div>
                            <div class="timeline-header">
                                <span class="timeline-action">
                                    Reservation #<?= $h['reservation_id'] ?> (<?= htmlspecialchars($h['room_number']) ?>)
                                    <span class="badge <?= getStatusBadgeClass($h['new_status']) ?>" style="font-size:0.65rem; margin-left:6px;"><?= $h['new_status'] ?></span>
                                </span>
                                <span class="timeline-time"><?= date('M d, H:i', strtotime($h['changed_at'])) ?></span>
                            </div>
                            <div class="timeline-meta">Action by <?= htmlspecialchars($h['actor_name']) ?></div>
                            <?php if (!empty($h['remarks'])): ?>
                                <div class="timeline-remarks"><?= htmlspecialchars($h['remarks']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">Quick Administrative Controls</div>
            </div>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <a href="rooms.php" class="btn btn-secondary" style="justify-content:flex-start; padding:14px;">
                    <span style="font-size:1.2rem; margin-right:10px;">🏢</span>
                    <div style="text-align:left;">
                        <div style="font-weight:700;">Room Management Directory</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">Add rooms, update capacity, modify maintenance status</div>
                    </div>
                </a>
                <a href="users.php" class="btn btn-secondary" style="justify-content:flex-start; padding:14px;">
                    <span style="font-size:1.2rem; margin-right:10px;">👥</span>
                    <div style="text-align:left;">
                        <div style="font-weight:700;">User Account Management</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">Manage roles, permissions, and active/inactive status</div>
                    </div>
                </a>
                <a href="reports.php" class="btn btn-secondary" style="justify-content:flex-start; padding:14px;">
                    <span style="font-size:1.2rem; margin-right:10px;">📊</span>
                    <div style="text-align:left;">
                        <div style="font-weight:700;">Room Utilization & Booking Reports</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">Analyze peak hours, room occupancy rates, and cancellation metrics</div>
                    </div>
                </a>
                <a href="schedule.php" class="btn btn-secondary" style="justify-content:flex-start; padding:14px;">
                    <span style="font-size:1.2rem; margin-right:10px;">🗓️</span>
                    <div style="text-align:left;">
                        <div style="font-weight:700;">Interactive Master Schedule</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">View complete visual timeline of all rooms and time slots</div>
                    </div>
                </a>
            </div>
        </div>
    </div>


<!-- ==============================================
     2. ROOM MANAGER DASHBOARD VIEW
================================================== -->
<?php elseif ($user['role'] === 'Room Manager'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Operational Rooms</div>
                <div class="stat-value" style="color:var(--success);"><?= $operationalRooms ?></div>
                <div class="stat-desc">Available for reservation</div>
            </div>
            <div class="stat-icon emerald">✅</div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Under Maintenance</div>
                <div class="stat-value" style="color:var(--warning);"><?= $maintenanceRooms ?></div>
                <div class="stat-desc">Currently servicing</div>
            </div>
            <div class="stat-icon amber">🛠️</div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Total Facilities</div>
                <div class="stat-value" style="color:var(--primary);"><?= $totalRooms ?></div>
                <div class="stat-desc">Managed across campuses</div>
            </div>
            <div class="stat-icon indigo">🏢</div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Today's Bookings</div>
                <div class="stat-value" style="color:#38bdf8;"><?= $todayBookings ?></div>
                <div class="stat-desc">Active approved sessions</div>
            </div>
            <div class="stat-icon indigo">📅</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title">Room Fleet Overview & Operational Controls</div>
            <div style="display:flex; gap:10px;">
                <a href="rooms.php" class="btn btn-primary btn-sm">+ Add New Room</a>
                <a href="schedule.php" class="btn btn-secondary btn-sm">Interactive Schedule</a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Room Name / Number</th>
                        <th>Capacity</th>
                        <th>Location</th>
                        <th>Current Status</th>
                        <th>Today's Bookings</th>
                        <th>Amenities</th>
                        <th>Quick Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roomsList as $rm): ?>
                        <tr>
                            <td>
                                <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($rm['room_number']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars(substr($rm['description'] ?? '', 0, 50)) ?>...</div>
                            </td>
                            <td><span style="font-weight:600;"><?= $rm['capacity'] ?></span> persons</td>
                            <td><?= htmlspecialchars($rm['location']) ?></td>
                            <td>
                                <span class="badge <?= getRoomStatusBadgeClass($rm['room_status']) ?>">
                                    <span class="badge-dot"></span> <?= $rm['room_status'] ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight:600; color:<?= $rm['today_bookings_count'] > 0 ? 'var(--primary)' : 'var(--text-muted)' ?>;">
                                    <?= $rm['today_bookings_count'] ?> sessions
                                </span>
                            </td>
                            <td style="font-size:0.8rem; color:var(--text-muted);"><?= htmlspecialchars($rm['amenities'] ?? 'Standard') ?></td>
                            <td>
                                <a href="rooms.php?edit=<?= $rm['room_id'] ?>" class="btn btn-secondary btn-sm">Edit Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>


<!-- ==============================================
     3. REQUESTER DASHBOARD VIEW
================================================== -->
<?php else: ?>
    <!-- Requester Dashboard -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Upcoming Bookings</div>
                <div class="stat-value" style="color:var(--success);"><?= count($myUpcomingBookings) ?></div>
                <div class="stat-desc">Confirmed & active</div>
            </div>
            <div class="stat-icon emerald">📅</div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Pending Review</div>
                <div class="stat-value" style="color:var(--warning);"><?= count($myPendingRequests) ?></div>
                <div class="stat-desc">Awaiting administrator review</div>
            </div>
            <div class="stat-icon amber">⏳</div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Available Rooms</div>
                <div class="stat-value" style="color:var(--primary);"><?= $operationalRooms ?></div>
                <div class="stat-desc">Ready for reservation</div>
            </div>
            <div class="stat-icon indigo">🏢</div>
        </div>
    </div>

    <!-- Quick Action Hero -->
    <div class="card" style="margin-bottom:28px; background:linear-gradient(135deg, rgba(30,41,59,0.9), rgba(15,23,42,0.95)); border:1px solid var(--border-highlight);">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
            <div>
                <h2 style="font-size:1.3rem; margin-bottom:4px;">Need a Space for Your Next Meeting or Event?</h2>
                <p style="font-size:0.85rem; color:var(--text-secondary);">Browse room specifications, check live time slot availability, and submit requests with instant conflict detection.</p>
            </div>
            <div style="display:flex; gap:10px;">
                <a href="rooms.php" class="btn btn-secondary">Browse Rooms</a>
                <a href="reserve.php" class="btn btn-primary">Book a Room Now</a>
            </div>
        </div>
    </div>

    <!-- My Pending Requests Table -->
    <div class="card" style="margin-bottom:28px;">
        <div class="card-header">
            <div class="card-title">My Pending Requests</div>
            <span class="badge badge-warning"><?= count($myPendingRequests) ?> Pending</span>
        </div>

        <?php if (empty($myPendingRequests)): ?>
            <div style="text-align:center; padding:30px 20px; color:var(--text-muted); font-size:0.88rem;">
                You do not have any pending reservation requests right now.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Date & Time</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Submitted On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myPendingRequests as $req): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($req['room_number']) ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($req['location']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:var(--text-primary);"><?= formatDate($req['reservation_date']) ?></div>
                                    <div style="font-size:0.75rem; font-family:var(--font-mono); color:var(--primary);">
                                        <?= formatTime($req['start_time']) ?> - <?= formatTime($req['end_time']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($req['purpose']) ?></td>
                                <td>
                                    <span class="badge badge-warning"><span class="badge-dot"></span> Pending Review</span>
                                </td>
                                <td style="font-size:0.75rem; color:var(--text-muted);"><?= formatDate($req['created_at']) ?></td>
                                <td>
                                    <button class="btn btn-danger btn-sm" onclick="openCancelModal(<?= $req['reservation_id'] ?>, '<?= htmlspecialchars(addslashes($req['room_number'])) ?>')">
                                        Cancel Request
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- My Upcoming Confirmed Bookings -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">Confirmed Upcoming Bookings</div>
            <a href="reservations.php" class="btn btn-secondary btn-sm">Full History</a>
        </div>

        <?php if (empty($myUpcomingBookings)): ?>
            <div style="text-align:center; padding:30px 20px; color:var(--text-muted); font-size:0.88rem;">
                No upcoming confirmed reservations. Click <strong>Book a Room Now</strong> to get started.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Date & Time</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myUpcomingBookings as $b): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($b['room_number']) ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($b['location']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:var(--text-primary);"><?= formatDate($b['reservation_date']) ?></div>
                                    <div style="font-size:0.75rem; font-family:var(--font-mono); color:var(--success);">
                                        <?= formatTime($b['start_time']) ?> - <?= formatTime($b['end_time']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($b['purpose']) ?></td>
                                <td>
                                    <span class="badge badge-success"><span class="badge-dot"></span> Approved</span>
                                </td>
                                <td>
                                    <button class="btn btn-secondary btn-sm" onclick="openCancelModal(<?= $b['reservation_id'] ?>, '<?= htmlspecialchars(addslashes($b['room_number'])) ?>')">
                                        Cancel Booking
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ==============================================
     MODAL DIALOGS: APPROVAL, REJECTION, CANCELLATION
================================================== -->
<!-- Approval Modal -->
<div id="approvalModal" class="modal-overlay">
    <div class="modal-window">
        <form method="POST" action="process_reservation.php">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" id="approve_reservation_id" name="reservation_id" value="">

            <div class="modal-header">
                <div class="modal-title">Approve Reservation Request</div>
                <button type="button" class="modal-close" onclick="closeModal('approvalModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.88rem; color:var(--text-secondary); margin-bottom:14px;">
                    You are confirming reservation for: <strong id="approve_room_label" style="color:var(--text-primary);"></strong>
                    on <strong id="approve_schedule_label" style="color:var(--primary);"></strong>.
                </p>

                <div id="approve_conflict_warning" style="display:none; margin-bottom:14px;">
                    <div class="conflict-callout">
                        <div class="conflict-icon">⚠️</div>
                        <div class="conflict-content">
                            <h4>Conflict Warning!</h4>
                            <p>An overlapping approved booking was detected for this room and time slot. System will automatically re-verify availability during submission to ensure zero duplicate bookings.</p>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="approve_remarks">Approval Remarks / Notes (Optional)</label>
                    <textarea id="approve_remarks" name="remarks" class="form-control" rows="3" placeholder="e.g. Approved. Room access code will be emailed."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('approvalModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Confirm Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- Rejection Modal -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-window">
        <form method="POST" action="process_reservation.php">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" id="reject_reservation_id" name="reservation_id" value="">

            <div class="modal-header">
                <div class="modal-title">Reject Reservation Request</div>
                <button type="button" class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.88rem; color:var(--text-secondary); margin-bottom:14px;">
                    You are rejecting reservation request for <strong id="reject_room_label" style="color:var(--text-primary);"></strong>.
                </p>
                <div class="form-group">
                    <label class="form-label" for="reject_remarks">Reason for Rejection (Required for Requester Notification)</label>
                    <textarea id="reject_remarks" name="remarks" class="form-control" rows="3" required placeholder="e.g. Schedule conflict with high-priority campus symposium. Please choose an alternative slot or room."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Back</button>
                <button type="submit" class="btn btn-danger">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancellation Modal -->
<div id="cancelModal" class="modal-overlay">
    <div class="modal-window">
        <form method="POST" action="process_reservation.php">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" id="cancel_reservation_id" name="reservation_id" value="">

            <div class="modal-header">
                <div class="modal-title">Cancel Reservation</div>
                <button type="button" class="modal-close" onclick="closeModal('cancelModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.88rem; color:var(--text-secondary); margin-bottom:14px;">
                    Are you sure you want to cancel the reservation for <strong id="cancel_room_label" style="color:var(--text-primary);"></strong>?
                    This will immediately release the room schedule for other users.
                </p>
                <div class="form-group">
                    <label class="form-label" for="cancel_remarks">Cancellation Reason (Optional)</label>
                    <textarea id="cancel_remarks" name="remarks" class="form-control" rows="2" placeholder="e.g. Meeting rescheduled to next week."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancelModal')">Keep Reservation</button>
                <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApprovalModal(id, room, date, times, hasConflict) {
    document.getElementById('approve_reservation_id').value = id;
    document.getElementById('approve_room_label').textContent = room;
    document.getElementById('approve_schedule_label').textContent = `${date} (${times})`;
    document.getElementById('approve_conflict_warning').style.display = hasConflict ? 'block' : 'none';
    openModal('approvalModal');
}

function openRejectModal(id, room) {
    document.getElementById('reject_reservation_id').value = id;
    document.getElementById('reject_room_label').textContent = room;
    openModal('rejectModal');
}

function openCancelModal(id, room) {
    document.getElementById('cancel_reservation_id').value = id;
    document.getElementById('cancel_room_label').textContent = room;
    openModal('cancelModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
