<?php
// rooms.php - Facility Directory & Room Fleet Management
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$user = currentUser();
$pdo = getDB();

$isManagerOrAdmin = in_array($user['role'], ['Administrator', 'Room Manager']);

$pageTitle = $isManagerOrAdmin ? 'Manage Facilities & Rooms' : 'Available Rooms Directory';
$pageSubtitle = $isManagerOrAdmin 
    ? 'Configure room capacity, location, maintenance states, and amenities' 
    : 'Discover rooms suited to your meeting requirements and attendee capacity';

// Handle Add / Edit / Status Toggle operations (Admin & Room Manager only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isManagerOrAdmin) {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = "Security validation token expired.";
        header("Location: rooms.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_room' || $action === 'edit_room') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $roomNumber = trim($_POST['room_number'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $roomStatus = trim($_POST['room_status'] ?? 'Operational');
        $amenities = trim($_POST['amenities'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($roomNumber) || $capacity <= 0 || empty($location)) {
            $_SESSION['flash_error'] = "Please provide valid room number, positive capacity, and location.";
        } elseif (!in_array($roomStatus, ['Operational', 'Maintenance', 'Unavailable'])) {
            $_SESSION['flash_error'] = "Invalid room status chosen.";
        } else {
            if ($action === 'add_room') {
                // Check unique room_number
                $check = $pdo->prepare("SELECT room_id FROM rooms WHERE room_number = ?");
                $check->execute([$roomNumber]);
                if ($check->fetch()) {
                    $_SESSION['flash_error'] = "A room with number/name '{$roomNumber}' already exists.";
                } else {
                    $insert = $pdo->prepare("
                        INSERT INTO rooms (room_number, capacity, location, room_status, amenities, description)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $insert->execute([$roomNumber, $capacity, $location, $roomStatus, $amenities, $description]);
                    $_SESSION['flash_success'] = "New room '{$roomNumber}' successfully added to inventory.";
                }
            } else {
                // Edit room
                $check = $pdo->prepare("SELECT room_id FROM rooms WHERE room_number = ? AND room_id != ?");
                $check->execute([$roomNumber, $roomId]);
                if ($check->fetch()) {
                    $_SESSION['flash_error'] = "Another room already uses the number '{$roomNumber}'.";
                } else {
                    $update = $pdo->prepare("
                        UPDATE rooms 
                        SET room_number = ?, capacity = ?, location = ?, room_status = ?, amenities = ?, description = ?
                        WHERE room_id = ?
                    ");
                    $update->execute([$roomNumber, $capacity, $location, $roomStatus, $amenities, $description, $roomId]);
                    $_SESSION['flash_success'] = "Room '{$roomNumber}' specifications updated successfully.";
                }
            }
        }
    } elseif ($action === 'toggle_maintenance') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? 'Operational');
        if (in_array($newStatus, ['Operational', 'Maintenance', 'Unavailable'])) {
            $stmt = $pdo->prepare("UPDATE rooms SET room_status = ? WHERE room_id = ?");
            $stmt->execute([$newStatus, $roomId]);
            $_SESSION['flash_success'] = "Room status changed to '{$newStatus}'.";
        }
    }

    header("Location: rooms.php");
    exit;
}

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$minCapacity = (int)($_GET['min_capacity'] ?? 0);
$search = trim($_GET['search'] ?? '');

$sql = "SELECT r.* FROM rooms r WHERE 1=1";
$params = [];

if ($statusFilter !== 'all' && in_array($statusFilter, ['Operational', 'Maintenance', 'Unavailable'])) {
    $sql .= " AND r.room_status = ?";
    $params[] = $statusFilter;
}

if ($minCapacity > 0) {
    $sql .= " AND r.capacity >= ?";
    $params[] = $minCapacity;
}

if (!empty($search)) {
    $sql .= " AND (r.room_number LIKE ? OR r.location LIKE ? OR r.amenities LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$sql .= " ORDER BY r.room_status ASC, r.capacity DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rooms = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Filter & Actions Bar -->
<div class="card" style="margin-bottom: 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <form method="GET" action="rooms.php" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">STATUS:</label>
                <select name="status" class="form-control" style="padding:6px 10px; font-size:0.82rem; width:140px;">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Operational" <?= $statusFilter === 'Operational' ? 'selected' : '' ?>>Operational</option>
                    <option value="Maintenance" <?= $statusFilter === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    <option value="Unavailable" <?= $statusFilter === 'Unavailable' ? 'selected' : '' ?>>Unavailable</option>
                </select>
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">MIN CAPACITY:</label>
                <input type="number" name="min_capacity" class="form-control" placeholder="e.g. 20" value="<?= $minCapacity > 0 ? $minCapacity : '' ?>" style="padding:6px 10px; font-size:0.82rem; width:100px;">
            </div>

            <input type="text" name="search" class="form-control" placeholder="Search room, location, tech..." value="<?= htmlspecialchars($search) ?>" style="padding:6px 12px; font-size:0.82rem; width:200px;">

            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if ($statusFilter !== 'all' || $minCapacity > 0 || !empty($search)): ?>
                <a href="rooms.php" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>

        <?php if ($isManagerOrAdmin): ?>
            <button class="btn btn-primary btn-sm" onclick="openAddRoomModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Add New Room
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Room Cards Grid -->
<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:24px;">
    <?php if (empty($rooms)): ?>
        <div class="card" style="grid-column: 1 / -1; text-align:center; padding:50px;">
            <div style="font-size:2rem; margin-bottom:8px;">🏢</div>
            <div style="font-size:1.1rem; font-weight:600;">No rooms match your filter criteria.</div>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">Try lowering capacity requirements or clearing search terms.</p>
        </div>
    <?php else: ?>
        <?php foreach ($rooms as $rm): ?>
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                        <div>
                            <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:2px;">
                                <?= htmlspecialchars($rm['room_number']) ?>
                            </h3>
                            <div style="font-size:0.8rem; color:var(--text-muted); display:flex; align-items:center; gap:4px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <?= htmlspecialchars($rm['location']) ?>
                            </div>
                        </div>
                        <span class="badge <?= getRoomStatusBadgeClass($rm['room_status']) ?>">
                            <span class="badge-dot"></span> <?= $rm['room_status'] ?>
                        </span>
                    </div>

                    <p style="font-size:0.83rem; color:var(--text-secondary); line-height:1.4; margin-bottom:16px;">
                        <?= htmlspecialchars($rm['description'] ?? 'Modern space equipped for group discussions, lectures, and interactive sessions.') ?>
                    </p>

                    <!-- Specifications List -->
                    <div style="background:rgba(255,255,255,0.03); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:12px; margin-bottom:18px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:0.82rem;">
                            <span style="color:var(--text-muted);">Max Capacity:</span>
                            <strong style="color:var(--text-primary);"><?= $rm['capacity'] ?> Attendees</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:0.82rem;">
                            <span style="color:var(--text-muted);">Equipped With:</span>
                            <span style="color:#a5b4fc; text-align:right; max-width:180px; font-size:0.78rem;">
                                <?= htmlspecialchars($rm['amenities'] ?? 'Standard Media') ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div style="display:flex; gap:8px; align-items:center; border-top:1px solid var(--border-color); padding-top:14px; margin-top:8px;">
                    <?php if ($rm['room_status'] === 'Operational'): ?>
                        <a href="reserve.php?room_id=<?= $rm['room_id'] ?>" class="btn btn-primary btn-sm" style="flex:1;">
                            Reserve This Room
                        </a>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-sm" disabled style="flex:1; opacity:0.6; cursor:not-allowed;">
                            <?= $rm['room_status'] ?> (Unavailable)
                        </button>
                    <?php endif; ?>

                    <a href="schedule.php?room_id=<?= $rm['room_id'] ?>" class="btn btn-secondary btn-sm btn-icon" title="View Schedule Grid">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </a>

                    <?php if ($isManagerOrAdmin): ?>
                        <button class="btn btn-secondary btn-sm" onclick='openEditRoomModal(<?= json_encode($rm) ?>)' title="Edit Room Info">
                            ✏️
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ==============================================
     MODAL: ADD / EDIT ROOM (Admin & Manager)
================================================== -->
<?php if ($isManagerOrAdmin): ?>
<div id="roomModal" class="modal-overlay">
    <div class="modal-window" style="max-width: 580px;">
        <form method="POST" action="rooms.php" id="roomForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" id="room_form_action" value="add_room">
            <input type="hidden" name="room_id" id="edit_room_id" value="">

            <div class="modal-header">
                <div class="modal-title" id="roomModalTitle">Add New Facility Room</div>
                <button type="button" class="modal-close" onclick="closeModal('roomModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="form_room_number">Room Number / Title *</label>
                    <input type="text" name="room_number" id="form_room_number" class="form-control" required placeholder="e.g. CR-105 (Design Studio)">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="form_capacity">Seating Capacity *</label>
                        <input type="number" name="capacity" id="form_capacity" class="form-control" required min="1" max="1000" placeholder="e.g. 25">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="form_room_status">Operational Status *</label>
                        <select name="room_status" id="form_room_status" class="form-control" required>
                            <option value="Operational">Operational (Open for Booking)</option>
                            <option value="Maintenance">Maintenance (Under Repair)</option>
                            <option value="Unavailable">Unavailable (Closed)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="form_location">Building & Floor Location *</label>
                    <input type="text" name="location" id="form_location" class="form-control" required placeholder="e.g. Building B, 2nd Floor">
                </div>

                <div class="form-group">
                    <label class="form-label" for="form_amenities">Amenities & Audio/Visual Equipment</label>
                    <input type="text" name="amenities" id="form_amenities" class="form-control" placeholder="e.g. 4K Projector, Smart Board, Video Conference, High-speed Wi-Fi">
                </div>

                <div class="form-group">
                    <label class="form-label" for="form_description">Room Description & Features</label>
                    <textarea name="description" id="form_description" class="form-control" rows="3" placeholder="Brief summary of room layout, suitability, and accessibility..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('roomModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="roomSubmitBtn">Save Room</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddRoomModal() {
    document.getElementById('room_form_action').value = 'add_room';
    document.getElementById('edit_room_id').value = '';
    document.getElementById('roomModalTitle').textContent = 'Add New Facility Room';
    document.getElementById('roomSubmitBtn').textContent = 'Create Room';

    document.getElementById('form_room_number').value = '';
    document.getElementById('form_capacity').value = '20';
    document.getElementById('form_room_status').value = 'Operational';
    document.getElementById('form_location').value = '';
    document.getElementById('form_amenities').value = 'High-Speed Wi-Fi, Whiteboard';
    document.getElementById('form_description').value = '';

    openModal('roomModal');
}

function openEditRoomModal(room) {
    document.getElementById('room_form_action').value = 'edit_room';
    document.getElementById('edit_room_id').value = room.room_id;
    document.getElementById('roomModalTitle').textContent = `Edit Room ${room.room_number}`;
    document.getElementById('roomSubmitBtn').textContent = 'Save Changes';

    document.getElementById('form_room_number').value = room.room_number;
    document.getElementById('form_capacity').value = room.capacity;
    document.getElementById('form_room_status').value = room.room_status;
    document.getElementById('form_location').value = room.location;
    document.getElementById('form_amenities').value = room.amenities || '';
    document.getElementById('form_description').value = room.description || '';

    openModal('roomModal');
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
