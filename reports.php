<?php
// reports.php - Room Utilization, Booking Analytics & Compliance Reports
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireRole(['Administrator', 'Room Manager']);

$user = currentUser();
$pdo = getDB();

$pageTitle = 'Reports & Room Utilization Analytics';
$pageSubtitle = 'Audit scheduling trends, analyze facility usage patterns, and export operational metrics';

// Date range filter
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=room_reservation_report_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['Reservation ID', 'Room Number', 'Location', 'Requester Name', 'Email', 'Date', 'Start Time', 'End Time', 'Status', 'Purpose', 'Attendance', 'Approved By', 'Created At']);

    $exportStmt = $pdo->prepare("
        SELECT r.reservation_id, rm.room_number, rm.location, u.full_name, u.email,
               r.reservation_date, r.start_time, r.end_time, r.status, r.purpose, r.attendance,
               appr.full_name AS approver_name, r.created_at
        FROM reservations r
        JOIN rooms rm ON r.room_id = rm.room_id
        JOIN users u ON r.user_id = u.user_id
        LEFT JOIN users appr ON r.approved_by = appr.user_id
        WHERE r.reservation_date BETWEEN ? AND ?
        ORDER BY r.reservation_date DESC
    ");
    $exportStmt->execute([$startDate, $endDate]);
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// 1. Overall stats in date range
$totalReqStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE reservation_date BETWEEN ? AND ?");
$totalReqStmt->execute([$startDate, $endDate]);
$totalInPeriod = $totalReqStmt->fetchColumn();

$approvedReqStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE status IN ('Approved', 'Completed') AND reservation_date BETWEEN ? AND ?");
$approvedReqStmt->execute([$startDate, $endDate]);
$approvedInPeriod = $approvedReqStmt->fetchColumn();

$rejectedReqStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'Rejected' AND reservation_date BETWEEN ? AND ?");
$rejectedReqStmt->execute([$startDate, $endDate]);
$rejectedInPeriod = $rejectedReqStmt->fetchColumn();

$cancelledReqStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'Cancelled' AND reservation_date BETWEEN ? AND ?");
$cancelledReqStmt->execute([$startDate, $endDate]);
$cancelledInPeriod = $cancelledReqStmt->fetchColumn();

// 2. Room Utilization: hours booked per room
$utilStmt = $pdo->prepare("
    SELECT rm.room_id, rm.room_number, rm.capacity, rm.location, rm.room_status,
           COUNT(r.reservation_id) AS total_bookings,
           COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(r.end_time, r.start_time)) / 3600), 0) AS total_hours_booked
    FROM rooms rm
    LEFT JOIN reservations r ON rm.room_id = r.room_id 
          AND r.status IN ('Approved', 'Completed')
          AND r.reservation_date BETWEEN ? AND ?
    GROUP BY rm.room_id
    ORDER BY total_hours_booked DESC, total_bookings DESC
");
$utilStmt->execute([$startDate, $endDate]);
$roomUtilization = $utilStmt->fetchAll();

// 3. Status breakdown percentage
$statusDistStmt = $pdo->prepare("
    SELECT status, COUNT(*) as count 
    FROM reservations 
    WHERE reservation_date BETWEEN ? AND ?
    GROUP BY status
");
$statusDistStmt->execute([$startDate, $endDate]);
$statusDistribution = $statusDistStmt->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/includes/header.php';
?>

<!-- Filter & Export Controls -->
<div class="card" style="margin-bottom: 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <form method="GET" action="reports.php" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:0.75rem; color:var(--text-muted); font-weight:700;">FROM:</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>" style="padding:6px 10px; font-size:0.82rem; width:150px;">
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:0.75rem; color:var(--text-muted); font-weight:700;">TO:</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>" style="padding:6px 10px; font-size:0.82rem; width:150px;">
            </div>

            <button type="submit" class="btn btn-secondary btn-sm">Filter Range</button>
        </form>

        <a href="reports.php?export=csv&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" class="btn btn-primary btn-sm">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Export Auditable CSV Report
        </a>
    </div>
</div>

<!-- Key Performance Metrics -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Requests</div>
            <div class="stat-value" style="color:var(--primary);"><?= $totalInPeriod ?></div>
            <div class="stat-desc">Within selected period</div>
        </div>
        <div class="stat-icon indigo">📊</div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Confirmed / Completed</div>
            <div class="stat-value" style="color:var(--success);"><?= $approvedInPeriod ?></div>
            <div class="stat-desc"><?= $totalInPeriod > 0 ? round(($approvedInPeriod / $totalInPeriod) * 100, 1) : 0 ?>% approval rate</div>
        </div>
        <div class="stat-icon emerald">✅</div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Rejected Requests</div>
            <div class="stat-value" style="color:var(--danger);"><?= $rejectedInPeriod ?></div>
            <div class="stat-desc">Prevented conflicts / non-compliant</div>
        </div>
        <div class="stat-icon rose">❌</div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Cancelled Bookings</div>
            <div class="stat-value" style="color:var(--warning);"><?= $cancelledInPeriod ?></div>
            <div class="stat-desc">Released back to pool</div>
        </div>
        <div class="stat-icon amber">🔄</div>
    </div>
</div>

<!-- Room Utilization Table -->
<div class="card" style="margin-bottom:28px;">
    <div class="card-header">
        <div class="card-title">Facility Utilization & Cumulative Booked Hours</div>
        <span style="font-size:0.78rem; color:var(--text-muted);"><?= formatDate($startDate) ?> &mdash; <?= formatDate($endDate) ?></span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Room Name / Number</th>
                    <th>Location</th>
                    <th>Capacity</th>
                    <th>Status</th>
                    <th>Approved Sessions</th>
                    <th>Total Hours Booked</th>
                    <th>Utilization Visualizer</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $maxHours = 0;
                foreach ($roomUtilization as $u) {
                    if ($u['total_hours_booked'] > $maxHours) $maxHours = $u['total_hours_booked'];
                }
                if ($maxHours == 0) $maxHours = 1;

                foreach ($roomUtilization as $rm): 
                    $percent = round(($rm['total_hours_booked'] / $maxHours) * 100);
                ?>
                    <tr>
                        <td><strong style="color:var(--text-primary);"><?= htmlspecialchars($rm['room_number']) ?></strong></td>
                        <td><?= htmlspecialchars($rm['location']) ?></td>
                        <td><?= $rm['capacity'] ?> pax</td>
                        <td>
                            <span class="badge <?= getRoomStatusBadgeClass($rm['room_status']) ?>">
                                <?= $rm['room_status'] ?>
                            </span>
                        </td>
                        <td><strong><?= $rm['total_bookings'] ?></strong> bookings</td>
                        <td>
                            <span style="font-family:var(--font-mono); font-weight:700; color:var(--primary);">
                                <?= number_format($rm['total_hours_booked'], 1) ?> hrs
                            </span>
                        </td>
                        <td style="min-width: 160px;">
                            <div style="background:rgba(255,255,255,0.06); height:8px; border-radius:4px; overflow:hidden;">
                                <div style="background:var(--primary-gradient); height:100%; width:<?= $percent ?>%; border-radius:4px;"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Status Distribution Summary Cards -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:18px;">
    <?php 
    $statusPalette = [
        'Pending' => ['var(--warning)', '⏳'],
        'Approved' => ['var(--success)', '✅'],
        'Completed' => ['#38bdf8', '🎓'],
        'Cancelled' => ['var(--text-muted)', '🛑'],
        'Rejected' => ['var(--danger)', '❌']
    ];
    foreach (['Pending', 'Approved', 'Completed', 'Cancelled', 'Rejected'] as $st): 
        $cnt = $statusDistribution[$st] ?? 0;
        $pct = $totalInPeriod > 0 ? round(($cnt / $totalInPeriod) * 100, 1) : 0;
        $color = $statusPalette[$st][0];
        $icon = $statusPalette[$st][1];
    ?>
        <div class="card" style="padding:18px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;"><?= $st ?></div>
                    <div style="font-size:1.6rem; font-weight:800; color:<?= $color ?>; margin-top:2px;"><?= $cnt ?></div>
                    <div style="font-size:0.75rem; color:var(--text-secondary);"><?= $pct ?>% of total volume</div>
                </div>
                <div style="font-size:1.5rem;"><?= $icon ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
