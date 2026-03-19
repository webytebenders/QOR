<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireRole('super_admin');

$db = getDB();
$admin = getCurrentAdmin();

// Handle actions
if (isPost()) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('users.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $role = sanitize($_POST['role'] ?? 'viewer');
        $password = $_POST['password'] ?? '';

        $validRoles = ['super_admin', 'editor', 'support', 'viewer'];

        if (strlen($name) < 2) {
            setFlash('error', 'Name must be at least 2 characters.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Invalid email address.');
        } elseif (!in_array($role, $validRoles)) {
            setFlash('error', 'Invalid role.');
        } elseif (strlen($password) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
        } else {
            // Check duplicate
            $stmt = $db->prepare('SELECT id FROM admins WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                setFlash('error', 'An admin with this email already exists.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                $stmt = $db->prepare('INSERT INTO admins (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())');
                $stmt->execute([$name, $email, $hash, $role]);
                logActivity($admin['id'], 'create_admin', 'admin', $db->lastInsertId(), ['name' => $name, 'role' => $role]);
                setFlash('success', "Admin '{$name}' created successfully.");
            }
        }
        redirect('users.php');
    }

    if ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $admin['id']) {
            setFlash('error', 'You cannot deactivate your own account.');
        } else {
            $stmt = $db->prepare('SELECT is_active, name FROM admins WHERE id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if ($target) {
                $newStatus = $target['is_active'] ? 0 : 1;
                $stmt = $db->prepare('UPDATE admins SET is_active = ? WHERE id = ?');
                $stmt->execute([$newStatus, $userId]);
                $statusText = $newStatus ? 'activated' : 'deactivated';
                logActivity($admin['id'], 'update_admin', 'admin', $userId, ['status' => $statusText]);
                setFlash('success', "Admin '{$target['name']}' {$statusText}.");
            }
        }
        redirect('users.php');
    }

    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $stmt = $db->prepare('UPDATE admins SET password = ? WHERE id = ?');
            $stmt->execute([$hash, $userId]);
            logActivity($admin['id'], 'reset_password', 'admin', $userId);
            setFlash('success', 'Password has been reset.');
        }
        redirect('users.php');
    }
}

// Fetch all admins
$admins = $db->query('SELECT id, name, email, role, is_active, totp_enabled, last_login, created_at FROM admins ORDER BY created_at ASC')->fetchAll();

renderHeader('Admin Users', 'users');
?>

<div class="page-actions">
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('show')">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        Add Admin
    </button>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>2FA</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $user): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-avatar-sm"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
                                <span><?= sanitize($user['name']) ?></span>
                            </div>
                        </td>
                        <td><?= sanitize($user['email']) ?></td>
                        <td><span class="role-badge role-<?= $user['role'] ?>"><?= ucwords(str_replace('_', ' ', $user['role'])) ?></span></td>
                        <td><?= $user['totp_enabled'] ? '<span class="badge-green">On</span>' : '<span class="badge-gray">Off</span>' ?></td>
                        <td><?= $user['is_active'] ? '<span class="badge-green">Active</span>' : '<span class="badge-red">Inactive</span>' ?></td>
                        <td><?= $user['last_login'] ? timeAgo($user['last_login']) : 'Never' ?></td>
                        <td>
                            <div class="table-actions">
                                <?php if ($user['id'] !== $admin['id']): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn-icon" title="<?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <?php if ($user['is_active']): ?>
                                        <svg viewBox="0 0 20 20" fill="#ef4444" width="16" height="16"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg>
                                        <?php else: ?>
                                        <svg viewBox="0 0 20 20" fill="#22c55e" width="16" height="16"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                        <?php endif; ?>
                                    </button>
                                </form>
                                <button class="btn-icon" title="Reset Password" onclick="openResetModal(<?= $user['id'] ?>, '<?= sanitize($user['name']) ?>')">
                                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Admin Modal -->
<div class="modal" id="addModal">
    <div class="modal-overlay" onclick="this.parentElement.classList.remove('show')"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Admin User</h3>
            <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" required minlength="2">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="viewer">Viewer</option>
                        <option value="support">Support</option>
                        <option value="editor">Editor</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="8">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Admin</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal" id="resetModal">
    <div class="modal-overlay" onclick="this.parentElement.classList.remove('show')"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reset Password</h3>
            <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetUserId">
            <div class="modal-body">
                <p id="resetUserName" style="margin-bottom:16px; color: var(--text-secondary);"></p>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required minlength="8">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-primary">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').textContent = 'Reset password for: ' + userName;
    document.getElementById('resetModal').classList.add('show');
}
</script>

<?php renderFooter(); ?>
