<?php
// users.php - Administrator User Account & Role Management Portal
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireRole(['Administrator']);

$user = currentUser();
$pdo = getDB();

$pageTitle = 'User Account & Permission Management';
$pageSubtitle = 'Oversee system access, configure staff and requester permissions, and audit user states';

// Handle user actions: Add user, Edit user, Toggle Active/Inactive, Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = "Security token validation failed.";
        header("Location: users.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? 'Requester');
        $status = trim($_POST['account_status'] ?? 'Active');

        if (empty($fullName) || empty($email) || empty($password)) {
            $_SESSION['flash_error'] = "All fields are required to register a user.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = "Invalid email format provided.";
        } else {
            $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $_SESSION['flash_error'] = "A user account with email '{$email}' already exists.";
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins = $pdo->prepare("
                    INSERT INTO users (full_name, email, password_hash, role, account_status)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ins->execute([$fullName, $email, $hash, $role, $status]);
                $_SESSION['flash_success'] = "User '{$fullName}' ({$role}) registered successfully.";
            }
        }
    } elseif ($action === 'edit_user') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? 'Requester');
        $status = trim($_POST['account_status'] ?? 'Active');

        if (empty($fullName) || empty($email)) {
            $_SESSION['flash_error'] = "Name and email cannot be empty.";
        } else {
            $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $check->execute([$email, $targetId]);
            if ($check->fetch()) {
                $_SESSION['flash_error'] = "Another account is already using email '{$email}'.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, role = ?, account_status = ?
                    WHERE user_id = ?
                ");
                $upd->execute([$fullName, $email, $role, $status, $targetId]);
                $_SESSION['flash_success'] = "User details for '{$fullName}' updated successfully.";
            }
        }
    } elseif ($action === 'reset_password') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newPass = trim($_POST['new_password'] ?? '');

        if (strlen($newPass) < 6) {
            $_SESSION['flash_error'] = "Password must be at least 6 characters long.";
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $upd->execute([$hash, $targetId]);
            $_SESSION['flash_success'] = "Password for user #{$targetId} has been reset.";
        }
    }

    header("Location: users.php");
    exit;
}

// Fetch all users
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="card" style="margin-bottom: 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <div style="font-size:1.1rem; font-weight:700;">System User Accounts (<?= count($users) ?> total)</div>
            <div style="font-size:0.8rem; color:var(--text-muted);">Manage roles: Administrator, Room Manager, and Requester</div>
        </div>

        <button class="btn btn-primary btn-sm" onclick="openAddUserModal()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add New User
        </button>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Name & Username/Email</th>
                    <th>Role</th>
                    <th>Account Status</th>
                    <th>Registered Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td style="font-family:var(--font-mono); font-size:0.82rem; color:var(--text-muted);">#<?= $u['user_id'] ?></td>
                        <td>
                            <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($u['full_name']) ?></div>
                            <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                        </td>
                        <td>
                            <span class="user-role-badge role-<?= str_replace(' ', '', $u['role']) ?>">
                                <?= $u['role'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $u['account_status'] === 'Active' ? 'badge-success' : 'badge-danger' ?>">
                                <span class="badge-dot"></span> <?= $u['account_status'] ?>
                            </span>
                        </td>
                        <td style="font-size:0.8rem; color:var(--text-muted);"><?= formatDate($u['created_at']) ?></td>
                        <td>
                            <div style="display:flex; gap:6px;">
                                <button class="btn btn-secondary btn-sm" onclick='openEditUserModal(<?= json_encode($u) ?>)'>
                                    Edit Role
                                </button>
                                <button class="btn btn-secondary btn-sm" onclick="openResetPasswordModal(<?= $u['user_id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                                    🔑 Reset Key
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==============================================
     MODAL: ADD USER
================================================== -->
<div id="addUserModal" class="modal-overlay">
    <div class="modal-window">
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add_user">

            <div class="modal-header">
                <div class="modal-title">Create New System User</div>
                <button type="button" class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="add_name">Full Name *</label>
                    <input type="text" name="full_name" id="add_name" class="form-control" required placeholder="e.g. Alice Cooper">
                </div>

                <div class="form-group">
                    <label class="form-label" for="add_email">Email Address *</label>
                    <input type="email" name="email" id="add_email" class="form-control" required placeholder="alice@system.com">
                </div>

                <div class="form-group">
                    <label class="form-label" for="add_password">Temporary Password *</label>
                    <input type="password" name="password" id="add_password" class="form-control" required placeholder="Minimum 6 characters">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_role">User Role *</label>
                        <select name="role" id="add_role" class="form-control" required>
                            <option value="Requester">Requester</option>
                            <option value="Room Manager">Room Manager</option>
                            <option value="Administrator">Administrator</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="add_status">Account Status *</label>
                        <select name="account_status" id="add_status" class="form-control" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================
     MODAL: EDIT USER
================================================== -->
<div id="editUserModal" class="modal-overlay">
    <div class="modal-window">
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id" value="">

            <div class="modal-header">
                <div class="modal-title">Edit User Account & Role</div>
                <button type="button" class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="edit_name">Full Name *</label>
                    <input type="text" name="full_name" id="edit_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="edit_email">Username or Email *</label>
                    <input type="text" name="email" id="edit_email" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="edit_role">User Role *</label>
                        <select name="role" id="edit_role" class="form-control" required>
                            <option value="Requester">Requester</option>
                            <option value="Room Manager">Room Manager</option>
                            <option value="Administrator">Administrator</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit_status">Account Status *</label>
                        <select name="account_status" id="edit_status" class="form-control" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================
     MODAL: RESET PASSWORD
================================================== -->
<div id="resetPasswordModal" class="modal-overlay">
    <div class="modal-window" style="max-width:440px;">
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id" value="">

            <div class="modal-header">
                <div class="modal-title">Reset User Password</div>
                <button type="button" class="modal-close" onclick="closeModal('resetPasswordModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:14px;">
                    Set a new password for: <strong id="reset_user_label" style="color:var(--text-primary);"></strong>
                </p>
                <div class="form-group">
                    <label class="form-label" for="reset_new_password">New Password *</label>
                    <input type="password" name="new_password" id="reset_new_password" class="form-control" required placeholder="Minimum 6 characters">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('resetPasswordModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddUserModal() {
    openModal('addUserModal');
}

function openEditUserModal(u) {
    document.getElementById('edit_user_id').value = u.user_id;
    document.getElementById('edit_name').value = u.full_name;
    document.getElementById('edit_email').value = u.email;
    document.getElementById('edit_role').value = u.role;
    document.getElementById('edit_status').value = u.account_status;
    openModal('editUserModal');
}

function openResetPasswordModal(id, name) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_user_label').textContent = name;
    document.getElementById('reset_new_password').value = '';
    openModal('resetPasswordModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
