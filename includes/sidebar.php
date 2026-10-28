<?php
// includes/sidebar.php - Navigation Sidebar
require_once __DIR__ . '/auth.php';

$currentUser = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$pdo = getDB();

// Count pending requests for Admin badge
$pendingCount = 0;
if ($currentUser['role'] === 'Administrator') {
    $pStmt = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Pending'");
    $pendingCount = (int)$pStmt->fetchColumn();
}
?>
<aside class="app-sidebar">
    <div class="sidebar-header">
        <div class="brand-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
        </div>
        <div class="brand-text">
            <h2>RoomReserve</h2>
            <span>Enterprise Suite</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Core</div>
        <a href="dashboard.php" class="nav-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            Dashboard
        </a>

        <div class="nav-section-title">Booking Operations</div>
        <a href="reserve.php" class="nav-item <?= $currentPage === 'reserve.php' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
            Reserve a Room
        </a>

        <a href="reservations.php" class="nav-item <?= $currentPage === 'reservations.php' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            <?= $currentUser['role'] === 'Requester' ? 'My Bookings' : 'All Reservations' ?>
            <?php if ($pendingCount > 0 && $currentUser['role'] === 'Administrator'): ?>
                <span class="badge-count" title="<?= $pendingCount ?> pending approval"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>

        <a href="schedule.php" class="nav-item <?= $currentPage === 'schedule.php' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            Room Schedules
        </a>

        <div class="nav-section-title">Facilities & Rooms</div>
        <a href="rooms.php" class="nav-item <?= $currentPage === 'rooms.php' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            <?= in_array($currentUser['role'], ['Administrator', 'Room Manager']) ? 'Manage Rooms' : 'Browse Rooms' ?>
        </a>

        <?php if (in_array($currentUser['role'], ['Administrator', 'Room Manager'])): ?>
            <div class="nav-section-title">Analytics & Administration</div>
            <a href="reports.php" class="nav-item <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                Reports & Utilization
            </a>
        <?php endif; ?>

        <?php if ($currentUser['role'] === 'Administrator'): ?>
            <a href="users.php" class="nav-item <?= $currentPage === 'users.php' ? 'active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                Manage Users
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-profile-badge">
            <div class="user-avatar">
                <?= strtoupper(substr($currentUser['full_name'], 0, 1)) ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($currentUser['full_name']) ?></div>
                <span class="user-role-badge role-<?= str_replace(' ', '', $currentUser['role']) ?>">
                    <?= htmlspecialchars($currentUser['role']) ?>
                </span>
            </div>
            <a href="logout.php" class="btn btn-secondary btn-icon btn-sm" title="Sign Out">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </a>
        </div>
    </div>
</aside>
