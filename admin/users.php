<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';
require_once 'includes/logger.php';

requireRole('super_admin');

$db = getDB();
$admin = getCurrentAdmin();
$tab = $_GET['tab'] ?? 'users';

// Handle actions
if (isPost()) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('users');
    }

    $action = $_POST['action'] ?? '';

    // Load password policy
    $pwMinLen = 8;
    $pwRequireUpper = true;
    $pwRequireNumber = true;
    $pwRequireSpecial = false;
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'password_%'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['setting_key'] === 'password_min_length') $pwMinLen = max(6, (int)$r['setting_value']);
            if ($r['setting_key'] === 'password_require_upper') $pwRequireUpper = $r['setting_value'] === '1';
            if ($r['setting_key'] === 'password_require_number') $pwRequireNumber = $r['setting_value'] === '1';
            if ($r['setting_key'] === 'password_require_special') $pwRequireSpecial = $r['setting_value'] === '1';
        }
    } catch (Exception $e) {}

    // Validate password against policy
    function validatePassword(string $pw, int $minLen, bool $upper, bool $number, bool $special): ?string {
        if (strlen($pw) < $minLen) return "Password must be at least {$minLen} characters.";
        if ($upper && !preg_match('/[A-Z]/', $pw)) return 'Password must contain an uppercase letter.';
        if ($number && !preg_match('/[0-9]/', $pw)) return 'Password must contain a number.';
        if ($special && !preg_match('/[^a-zA-Z0-9]/', $pw)) return 'Password must contain a special character.';
        return null;
    }

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
        } elseif ($pwErr = validatePassword($password, $pwMinLen, $pwRequireUpper, $pwRequireNumber, $pwRequireSpecial)) {
            setFlash('error', $pwErr);
        } else {
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
        redirect('users');
    }

    if ($action === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $role = sanitize($_POST['role'] ?? 'viewer');
        $validRoles = ['super_admin', 'editor', 'support', 'viewer'];

        if (strlen($name) < 2) {
            setFlash('error', 'Name must be at least 2 characters.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Invalid email address.');
        } elseif (!in_array($role, $validRoles)) {
            setFlash('error', 'Invalid role.');
        } else {
            // Check email uniqueness (exclude self)
            $stmt = $db->prepare('SELECT id FROM admins WHERE email = ? AND id != ?');
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                setFlash('error', 'Another admin with this email already exists.');
            } else {
                $stmt = $db->prepare('UPDATE admins SET name = ?, email = ?, role = ? WHERE id = ?');
                $stmt->execute([$name, $email, $role, $userId]);
                logActivity($admin['id'], 'update_admin', 'admin', $userId, ['name' => $name, 'role' => $role]);
                setFlash('success', "Admin '{$name}' updated.");
            }
        }
        redirect('users');
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
        redirect('users');
    }

    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if ($pwErr = validatePassword($newPass, $pwMinLen, $pwRequireUpper, $pwRequireNumber, $pwRequireSpecial)) {
            setFlash('error', $pwErr);
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $stmt = $db->prepare('UPDATE admins SET password = ? WHERE id = ?');
            $stmt->execute([$hash, $userId]);
            logActivity($admin['id'], 'reset_password', 'admin', $userId);
            setFlash('success', 'Password has been reset.');
        }
        redirect('users');
    }

    if ($action === 'delete_admin') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $admin['id']) {
            setFlash('error', 'You cannot delete your own account.');
        } else {
            $stmt = $db->prepare('SELECT name FROM admins WHERE id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if ($target) {
                $db->prepare('DELETE FROM admins WHERE id = ?')->execute([$userId]);
                logActivity($admin['id'], 'delete_admin', 'admin', $userId, ['name' => $target['name']]);
                setFlash('success', "Admin '{$target['name']}' deleted permanently.");
            }
        }
        redirect('users');
    }

    if ($action === 'force_reset_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        try { $db->exec('ALTER TABLE admins ADD COLUMN IF NOT EXISTS force_password_reset TINYINT(1) NOT NULL DEFAULT 0'); } catch (Exception $e) {}
        $db->prepare('UPDATE admins SET force_password_reset = 1 WHERE id = ?')->execute([$userId]);
        logActivity($admin['id'], 'force_reset_user', 'admin', $userId);
        setFlash('success', 'User will be required to change password on next login.');
        redirect('users');
    }

    if ($action === 'reset_2fa') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $db->prepare('UPDATE admins SET totp_secret = NULL, totp_enabled = 0, recovery_codes = NULL WHERE id = ?')->execute([$userId]);
        logActivity($admin['id'], 'reset_2fa', 'admin', $userId);
        setFlash('success', '2FA has been reset for this admin.');
        redirect('users');
    }
}

// Fetch all admins with last activity
$admins = $db->query("
    SELECT a.id, a.name, a.email, a.role, a.is_active, a.totp_enabled, a.last_login, a.created_at,
    (SELECT COUNT(*) FROM activity_log WHERE admin_id = a.id) as total_actions,
    (SELECT action FROM activity_log WHERE admin_id = a.id ORDER BY created_at DESC LIMIT 1) as last_action
    FROM admins a ORDER BY a.created_at ASC
")->fetchAll();

// Role counts
$roleCounts = ['super_admin' => 0, 'editor' => 0, 'support' => 0, 'viewer' => 0];
$activeCount = 0;
$inactiveCount = 0;
$twoFaCount = 0;
foreach ($admins as $u) {
    $roleCounts[$u['role']] = ($roleCounts[$u['role']] ?? 0) + 1;
    if ($u['is_active']) $activeCount++; else $inactiveCount++;
    if ($u['totp_enabled']) $twoFaCount++;
}

renderHeader('Admin Users', 'users');
?>

<div class="stats-row">
    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= count($admins) ?></span>
            <span class="stat-widget-label">Total Admins</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $activeCount ?></span>
            <span class="stat-widget-label">Active</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon <?= $inactiveCount > 0 ? 'orange' : 'green' ?>">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $inactiveCount ?></span>
            <span class="stat-widget-label">Inactive</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon <?= $twoFaCount < count($admins) ? 'orange' : 'green' ?>">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $twoFaCount ?>/<?= count($admins) ?></span>
            <span class="stat-widget-label">2FA Enabled</span>
        </div>
    </div>
</div>

<div class="tabs">
    <a href="users?tab=users" class="tab <?= $tab === 'users' ? 'tab-active' : '' ?>">Users</a>
    <a href="users?tab=roles" class="tab <?= $tab === 'roles' ? 'tab-active' : '' ?>">Roles & Permissions</a>
</div>

<?php if ($tab === 'roles'): ?>
<!-- ==================== ROLES & PERMISSIONS ==================== -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Role Descriptions</h2></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
            <div>
                <span class="role-badge role-super_admin">Super Admin</span>
                <p style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">Full access to everything. Can manage other admins, settings, backups, and all content.</p>
                <p style="font-size:0.75rem;margin-top:4px;"><strong><?= $roleCounts['super_admin'] ?></strong> user(s)</p>
            </div>
            <div>
                <span class="role-badge role-editor">Editor</span>
                <p style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">Can manage blog posts, newsletter, SEO, email templates. No access to settings or users.</p>
                <p style="font-size:0.75rem;margin-top:4px;"><strong><?= $roleCounts['editor'] ?></strong> user(s)</p>
            </div>
            <div>
                <span class="role-badge role-support">Support</span>
                <p style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">Can view and respond to messages, waitlist, chatbot sessions. Read-only for other areas.</p>
                <p style="font-size:0.75rem;margin-top:4px;"><strong><?= $roleCounts['support'] ?></strong> user(s)</p>
            </div>
            <div>
                <span class="role-badge role-viewer">Viewer</span>
                <p style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">Read-only access to dashboard and analytics. Cannot modify any content.</p>
                <p style="font-size:0.75rem;margin-top:4px;"><strong><?= $roleCounts['viewer'] ?></strong> user(s)</p>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Permissions Matrix</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th style="text-align:center">Super Admin</th>
                        <th style="text-align:center">Editor</th>
                        <th style="text-align:center">Support</th>
                        <th style="text-align:center">Viewer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $permissions = [
                        'Dashboard' => [true, true, true, true],
                        'Waitlist — View' => [true, true, true, true],
                        'Waitlist — Manage' => [true, true, true, false],
                        'Messages — View' => [true, true, true, true],
                        'Messages — Reply' => [true, true, true, false],
                        'Blog — View' => [true, true, true, true],
                        'Blog — Create/Edit' => [true, true, false, false],
                        'Blog — Delete' => [true, true, false, false],
                        'Newsletter — Manage' => [true, true, false, false],
                        'Email Templates' => [true, true, false, false],
                        'SEO Manager' => [true, true, false, false],
                        'Chatbot — View Sessions' => [true, true, true, true],
                        'Chatbot — Reply' => [true, true, true, false],
                        'Chatbot — Knowledge Base' => [true, true, false, false],
                        'Chatbot — Settings' => [true, false, false, false],
                        'Analytics — View' => [true, true, true, true],
                        'Analytics — Goals' => [true, true, false, false],
                        'Media — View' => [true, true, true, true],
                        'Media — Upload/Delete' => [true, true, false, false],
                        'Settings' => [true, false, false, false],
                        'Admin Users' => [true, false, false, false],
                        'Activity Log' => [true, false, false, true],
                        'Backups' => [true, false, false, false],
                    ];
                    foreach ($permissions as $feature => $access):
                    ?>
                    <tr>
                        <td><?= $feature ?></td>
                        <?php foreach ($access as $can): ?>
                        <td style="text-align:center;"><?= $can ? '<span style="color:var(--green);">&#10003;</span>' : '<span style="color:var(--text-muted);">&#10007;</span>' ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ==================== USERS LIST ==================== -->
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
                        <th>Activity</th>
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
                                <div>
                                    <span><?= sanitize($user['name']) ?></span>
                                    <?php if ($user['id'] === $admin['id']): ?>
                                    <span style="font-size:0.7rem;color:var(--blue);">(you)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= sanitize($user['email']) ?></td>
                        <td><span class="role-badge role-<?= $user['role'] ?>"><?= ucwords(str_replace('_', ' ', $user['role'])) ?></span></td>
                        <td><?= $user['totp_enabled'] ? '<span class="badge-green">On</span>' : '<span class="badge-gray">Off</span>' ?></td>
                        <td><?= $user['is_active'] ? '<span class="badge-green">Active</span>' : '<span class="badge-red">Inactive</span>' ?></td>
                        <td>
                            <span style="font-size:0.8rem;"><?= number_format($user['total_actions']) ?> actions</span>
                            <?php if ($user['last_action']): ?>
                            <br><span style="font-size:0.7rem;color:var(--text-muted);">Last: <?= sanitize($user['last_action']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $user['last_login'] ? timeAgo($user['last_login']) : '<span class="text-muted">Never</span>' ?></td>
                        <td>
                            <?php if ($user['id'] !== $admin['id']): ?>
                            <div class="action-dropdown">
                                <button class="btn btn-secondary btn-sm" onclick="this.nextElementSibling.classList.toggle('show')">Actions &#9662;</button>
                                <div class="dropdown-menu">
                                    <button class="dropdown-item" onclick="this.closest('.dropdown-menu').classList.remove('show');openEditModal(<?= htmlspecialchars(json_encode($user)) ?>)">Edit Details</button>
                                    <button class="dropdown-item" onclick="this.closest('.dropdown-menu').classList.remove('show');openResetModal(<?= $user['id'] ?>, '<?= sanitize($user['name']) ?>')">Reset Password</button>
                                    <a href="activity?admin=<?= $user['id'] ?>" class="dropdown-item">View Activity</a>
                                    <div class="dropdown-divider"></div>
                                    <form method="POST"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?= $user['id'] ?>"><button type="submit" class="dropdown-item"><?= $user['is_active'] ? 'Deactivate' : 'Activate' ?> Account</button></form>
                                    <form method="POST" onsubmit="return confirm('Force password change on next login?')"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="force_reset_user"><input type="hidden" name="user_id" value="<?= $user['id'] ?>"><button type="submit" class="dropdown-item">Force Password Reset</button></form>
                                    <?php if ($user['totp_enabled']): ?>
                                    <form method="POST" onsubmit="return confirm('Reset 2FA for <?= sanitize($user['name']) ?>?')"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="reset_2fa"><input type="hidden" name="user_id" value="<?= $user['id'] ?>"><button type="submit" class="dropdown-item">Reset 2FA</button></form>
                                    <?php endif; ?>
                                    <div class="dropdown-divider"></div>
                                    <form method="POST" onsubmit="return confirm('Permanently delete <?= sanitize($user['name']) ?>?')"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCSRFToken() ?>"><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="user_id" value="<?= $user['id'] ?>"><button type="submit" class="dropdown-item dropdown-item-danger">Delete User</button></form>
                                </div>
                            </div>
                            <?php else: ?>
                            <span style="font-size:0.75rem;color:var(--text-muted);">Current user</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

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
                        <option value="viewer">Viewer — Read-only access</option>
                        <option value="support">Support — Messages, waitlist, chatbot</option>
                        <option value="editor">Editor — Content management</option>
                        <option value="super_admin">Super Admin — Full access</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="<?= $pwMinLen ?? 8 ?>">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Min <?= $pwMinLen ?? 8 ?> chars<?= ($pwRequireUpper ?? true) ? ', uppercase required' : '' ?><?= ($pwRequireNumber ?? true) ? ', number required' : '' ?><?= ($pwRequireSpecial ?? false) ? ', special char required' : '' ?></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Admin</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Admin Modal -->
<div class="modal" id="editModal">
    <div class="modal-overlay" onclick="this.parentElement.classList.remove('show')"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Admin User</h3>
            <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" id="editName" required minlength="2">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="editEmail" required>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="editRole" required>
                        <option value="viewer">Viewer</option>
                        <option value="support">Support</option>
                        <option value="editor">Editor</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </div>
                <div class="info-list" style="margin-top:12px;">
                    <div class="info-item">
                        <span class="info-label">Created</span>
                        <span class="info-value" id="editCreated"></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Last Login</span>
                        <span class="info-value" id="editLastLogin"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
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
                    <input type="password" name="new_password" required minlength="<?= $pwMinLen ?? 8 ?>">
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
function openEditModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editName').value = user.name;
    document.getElementById('editEmail').value = user.email;
    document.getElementById('editRole').value = user.role;
    document.getElementById('editCreated').textContent = user.created_at;
    document.getElementById('editLastLogin').textContent = user.last_login || 'Never';
    document.getElementById('editModal').classList.add('show');
}

function openResetModal(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').textContent = 'Reset password for: ' + userName;
    document.getElementById('resetModal').classList.add('show');
}
</script>

<?php renderFooter(); ?>
