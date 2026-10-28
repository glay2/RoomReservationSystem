<?php
// reservations.php - Comprehensive Reservation Management & Audit History
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$user = currentUser();
$pdo = getDB();

$pageTitle = ($user['role'] === 'Requester') ? 'My Reservations & History' : 'All Reservation Records';
$pageSubtitle = 'Monitor reservation lifecycle, examine audit records, and resolve booking conflicts';

$filterStatus = $_GET['status'] ?? 'all';
$searchQuery = trim($_GET['q'] ?? '');

// Base query construction
$sql = "
    SELECT r.*, rm.room_number, rm.capacity, rm.location, u.full_name AS requester_name, u.email AS requester_email,
           appr.full_name AS approver_name
    FROM reservations r
    JOIN rooms rm ON r.room_id = rm.room_id
    JOIN users u ON r.user_id = u.user_id
    LEFT JOIN users appr ON r.approved_by = appr.user_id
    WHERE 1=1
";
$params = [];

// Requester constraint
if ($user['role'] === 'Requester') {
    $sql .= " AND r.user_id = ?";
    $params[] = $user['user_id'];
}

// Status filter
if (in_array($filterStatus, ['Pending', 'Approved', 'Rejected', 'Cancelled', 'Completed'])) {
    $sql .= " AND r.status = ?";
    $params[] = $filterStatus;
}

// Search filter
if (!empty($searchQuery)) {
    $sql .= " AND (rm.room_number LIKE ? OR r.purpose LIKE ? OR u.full_name LIKE ?)";
    $term = "%{$searchQuery}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY r.created_at DESC, r.reservation_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Get counts for filter tabs
$countSql = "SELECT status, COUNT(*) as cnt FROM reservations r WHERE 1=1";
$countParams = [];
if ($user['role'] === 'Requester') {
    $countSql .= " AND r.user_id = ?";
    $countParams[] = $user['user_id'];
}
$countSql .= " GROUP BY status";
$cStmt = $pdo->prepare($countSql);
$cStmt->execute($countParams);
$statusCounts = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$totalCount = array_sum($statusCounts);

include __DIR__ . '/includes/header.php';
?>

<div class="card" style="margin-bottom: 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <!-- Status Filter Tabs -->
        <div class="filter-tabs" style="margin-bottom:0;">
            <a href="reservations.php?status=all<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="filter-tab <?= $filterStatus === 'all' ? 'active' : '' ?>">
                All (<?= $totalCount ?>)
            </a>
            <a href="reservations.php?status=Pending<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="filter-tab <?= $filterStatus === 'Pending' ? 'active' : '' ?>">
                Pending (<?= $statusCounts['Pending'] ?? 0 ?>)
            </a>
            <a href="reservations.php?status=Approved<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="filter-tab <?= $filterStatus === 'Approved' ? 'active' : '' ?>">
                Approved (<?= $statusCounts['Approved'] ?? 0 ?>)
            </a>
            <a href="reservations.php?status=Completed<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="filter-tab <?= $filterStatus === 'Completed' ? 'active' : '' ?>">
                Completed (<?= $statusCounts['Completed'] ?? 0 ?>)
            </a>
            <a href="reservations.php?status=Cancelled<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="filter-tab <?= $filterStatus === 'Cancelled' ? 'active' : '' ?>">
                Cancelled (<?= $statusCounts['Cancelled'] ?? 0 ?>)
            </a>
            <a href="reservations.php?status=Rejected<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="filter-tab <?= $filterStatus === 'Rejected' ? 'active' : '' ?>">
                Rejected (<?= $statusCounts['Rejected'] ?? 0 ?>)
            </a>
        </div>

        <!-- Search Bar -->
        <form method="GET" action="reservations.php" style="display:flex; gap:8px;">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <input type="text" name="q" class="form-control" placeholder="Search room, requester, purpose..." value="<?= htmlspecialchars($searchQuery) ?>" style="padding:7px 12px; font-size:0.85rem; width:220px;">
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if (!empty($searchQuery)): ?>
                <a href="reservations.php?status=<?= htmlspecialchars($filterStatus) ?>" class="btn btn-secondary btn-sm" title="Clear Search">✕</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Reservations Data Table -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
            Reservation Entries (<?= count($reservations) ?> found)
        </div>
        <a href="reserve.php" class="btn btn-primary btn-sm">+ Create New Booking</a>
    </div>

    <?php if (empty($reservations)): ?>
        <div style="text-align:center; padding:50px 20px; color:var(--text-muted);">
            <div style="font-size:2.2rem; margin-bottom:10px;">📋</div>
            <div style="font-size:1.05rem; font-weight:600; color:var(--text-primary);">No reservation records matching your criteria.</div>
            <p style="font-size:0.85rem; margin-top:4px;">Try selecting a different filter tab or search term.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Room & Facility</th>
                        <?php if ($user['role'] !== 'Requester'): ?>
                            <th>Requester</th>
                        <?php endif; ?>
                        <th>Date & Time</th>
                        <th>Purpose & Attendance</th>
                        <th>Status</th>
                        <th>Approver / Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reservations as $r): 
                        $hasConflict = false;
                        if ($r['status'] === 'Pending') {
                            $hasConflict = (bool)checkRoomConflict($pdo, $r['room_id'], $r['reservation_date'], $r['start_time'], $r['end_time']);
                        }
                    ?>
                        <tr>
                            <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-muted);">#<?= $r['reservation_id'] ?></td>
                            <td>
                                <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($r['room_number']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($r['location']) ?></div>
                            </td>
                            <?php if ($user['role'] !== 'Requester'): ?>
                                <td>
                                    <div style="font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($r['requester_name']) ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($r['requester_email']) ?></div>
                                </td>
                            <?php endif; ?>
                            <td>
                                <div style="font-weight:600; color:var(--text-primary);"><?= formatDate($r['reservation_date']) ?></div>
                                <div style="font-size:0.75rem; font-family:var(--font-mono); color:var(--primary);">
                                    <?= formatTime($r['start_time']) ?> - <?= formatTime($r['end_time']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight:500; color:var(--text-primary); max-width:240px;"><?= htmlspecialchars($r['purpose']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted);">
                                    Attendance: <?= $r['attendance'] ?> / <?= $r['capacity'] ?> max
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= getStatusBadgeClass($r['status']) ?>">
                                    <span class="badge-dot"></span> <?= $r['status'] ?>
                                </span>
                                <?php if ($r['status'] === 'Pending' && $hasConflict): ?>
                                    <div style="font-size:0.68rem; color:var(--danger); font-weight:700; margin-top:4px;">⚠️ Overlap!</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['approver_name'])): ?>
                                    <div style="font-size:0.8rem; color:var(--text-primary); font-weight:600;"><?= htmlspecialchars($r['approver_name']) ?></div>
                                    <div style="font-size:0.72rem; color:var(--text-muted);">Updated: <?= date('M d, H:i', strtotime($r['updated_at'])) ?></div>
                                <?php else: ?>
                                    <span style="font-size:0.8rem; color:var(--text-muted);">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <!-- Audit History Button -->
                                    <button class="btn btn-secondary btn-sm" onclick="showHistory(<?= $r['reservation_id'] ?>)" title="View Audit Trail History">
                                        📜 Audit
                                    </button>

                                    <!-- Admin Approval & Rejection for Pending -->
                                    <?php if ($user['role'] === 'Administrator' && $r['status'] === 'Pending'): ?>
                                        <button class="btn btn-success btn-sm" onclick="openApprovalModal(<?= $r['reservation_id'] ?>, '<?= htmlspecialchars(addslashes($r['room_number'])) ?>', '<?= formatDate($r['reservation_date']) ?>', '<?= formatTime($r['start_time']) ?> - <?= formatTime($r['end_time']) ?>', <?= $hasConflict ? 'true' : 'false' ?>)">
                                            Approve
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?= $r['reservation_id'] ?>, '<?= htmlspecialchars(addslashes($r['room_number'])) ?>')">
                                            Reject
                                        </button>
                                    <?php endif; ?>

                                    <!-- Cancellation Button for Authorized Users -->
                                    <?php 
                                        $canCancel = in_array($r['status'], ['Pending', 'Approved']) && 
                                                     ($user['role'] === 'Administrator' || (int)$r['user_id'] === (int)$user['user_id']);
                                        if ($canCancel):
                                    ?>
                                        <button class="btn btn-secondary btn-sm" style="color:#f87171;" onclick="openCancelModal(<?= $r['reservation_id'] ?>, '<?= htmlspecialchars(addslashes($r['room_number'])) ?>')">
                                            Cancel
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ==============================================
     AUDIT HISTORY TIMELINE MODAL
================================================== -->
<div id="auditHistoryModal" class="modal-overlay">
    <div class="modal-window" style="max-width: 680px;">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="historyModalTitle">Audit Trail & Status History</div>
                <div style="font-size:0.75rem; color:var(--text-muted);" id="historyModalSubtitle"></div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('auditHistoryModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="historyLoading" style="text-align:center; padding:30px; color:var(--text-muted);">
                Loading audit trail...
            </div>
            <div id="historyContent" class="history-timeline" style="display:none;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('auditHistoryModal')">Close</button>
        </div>
    </div>
</div>

<!-- Include Shared Modals (Approve, Reject, Cancel) -->
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
                    Confirming reservation for: <strong id="approve_room_label" style="color:var(--text-primary);"></strong>
                    on <strong id="approve_schedule_label" style="color:var(--primary);"></strong>.
                </p>

                <div id="approve_conflict_warning" style="display:none; margin-bottom:14px;">
                    <div class="conflict-callout">
                        <div class="conflict-icon">⚠️</div>
                        <div class="conflict-content">
                            <h4>Conflict Warning!</h4>
                            <p>An overlapping booking exists. The conflict prevention engine will block approval if another booking is confirmed.</p>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="approve_remarks">Approval Remarks / Instructions</label>
                    <textarea id="approve_remarks" name="remarks" class="form-control" rows="3" placeholder="e.g. Approved. Smart podium key provided at reception."></textarea>
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
                    Rejecting reservation for <strong id="reject_room_label" style="color:var(--text-primary);"></strong>.
                </p>
                <div class="form-group">
                    <label class="form-label" for="reject_remarks">Reason for Rejection *</label>
                    <textarea id="reject_remarks" name="remarks" class="form-control" rows="3" required placeholder="State reason to notify requester..."></textarea>
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
                    This will immediately release the room schedule.
                </p>
                <div class="form-group">
                    <label class="form-label" for="cancel_remarks">Cancellation Reason (Optional)</label>
                    <textarea id="cancel_remarks" name="remarks" class="form-control" rows="2" placeholder="e.g. User requested schedule postponement."></textarea>
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
function showHistory(resId) {
    const loading = document.getElementById('historyLoading');
    const content = document.getElementById('historyContent');
    const title = document.getElementById('historyModalTitle');
    const subtitle = document.getElementById('historyModalSubtitle');

    loading.style.display = 'block';
    content.style.display = 'none';
    openModal('auditHistoryModal');

    fetch(`api/get_history.php?reservation_id=${resId}`)
        .then(res => res.json())
        .then(data => {
            loading.style.display = 'none';
            content.style.display = 'block';

            if (data.success) {
                title.textContent = `Reservation #${data.reservation.reservation_id} Audit Trail`;
                subtitle.textContent = `${data.reservation.room_number} | ${data.reservation.reservation_date} (${data.reservation.time_window}) | Purpose: ${data.reservation.purpose}`;

                if (data.history.length === 0) {
                    content.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem;">No historical status changes logged yet.</p>';
                } else {
                    let html = '';
                    data.history.forEach(item => {
                        html += `
                            <div class="timeline-step">
                                <div class="timeline-dot"></div>
                                <div class="timeline-header">
                                    <span class="timeline-action">
                                        Status: <span class="badge ${getStatusBadge(item.new_status)}">${item.new_status}</span>
                                        ${item.previous_status ? `<span style="font-size:0.75rem; color:var(--text-muted); margin-left:6px;">(from ${item.previous_status})</span>` : ''}
                                    </span>
                                    <span class="timeline-time">${formatDateStr(item.changed_at)}</span>
                                </div>
                                <div class="timeline-meta">Action by: <strong>${escapeHtml(item.actor_name)}</strong> (${item.actor_role})</div>
                                ${item.remarks ? `<div class="timeline-remarks">${escapeHtml(item.remarks)}</div>` : ''}
                            </div>
                        `;
                    });
                    content.innerHTML = html;
                }
            } else {
                content.innerHTML = `<div style="color:var(--danger);">${data.error || 'Failed to fetch history.'}</div>`;
            }
        })
        .catch(err => {
            loading.style.display = 'none';
            content.style.display = 'block';
            content.innerHTML = '<div style="color:var(--danger);">Network error fetching history trail.</div>';
        });
}

function getStatusBadge(status) {
    switch (status) {
        case 'Approved': return 'badge-success';
        case 'Pending': return 'badge-warning';
        case 'Rejected': return 'badge-danger';
        case 'Cancelled': return 'badge-secondary';
        case 'Completed': return 'badge-info';
        default: return 'badge-secondary';
    }
}

function formatDateStr(str) {
    if (!str) return '';
    const d = new Date(str);
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

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
