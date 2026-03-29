<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireRole('super_admin');

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_settings.sql')); } catch (Exception $e) {}

// Load all settings
$stmt = $db->query('SELECT setting_key, setting_value FROM settings');
$saved = [];
foreach ($stmt->fetchAll() as $row) {
    $saved[$row['setting_key']] = $row['setting_value'];
}

// Helper: get setting with fallback to config constant
function s(string $key, string $fallback = ''): string {
    global $saved;
    return $saved[$key] ?? $fallback;
}

$tab = $_GET['tab'] ?? 'general';

// --- Environment health check data ---
$healthChecks = [];
if ($tab === 'maintenance') {
    // PHP extensions
    $requiredExts = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'curl', 'fileinfo', 'gd'];
    foreach ($requiredExts as $ext) {
        $healthChecks['ext_' . $ext] = extension_loaded($ext);
    }
    // Writable dirs
    $writableDirs = [
        'uploads/' => realpath(__DIR__ . '/../uploads') ?: __DIR__ . '/../uploads',
        'site root' => realpath(__DIR__ . '/../') ?: __DIR__ . '/../',
    ];
    foreach ($writableDirs as $label => $path) {
        $healthChecks['writable_' . $label] = is_writable($path);
    }
    // SSL
    $healthChecks['ssl'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
    // PHP version
    $healthChecks['php_version'] = version_compare(PHP_VERSION, '8.0.0', '>=');
}

renderHeader('Settings', 'settings');
?>

<div class="tabs">
    <a href="settings?tab=general" class="tab <?= $tab === 'general' ? 'tab-active' : '' ?>">General</a>
    <a href="settings?tab=branding" class="tab <?= $tab === 'branding' ? 'tab-active' : '' ?>">Branding</a>
    <a href="settings?tab=email" class="tab <?= $tab === 'email' ? 'tab-active' : '' ?>">Email / SMTP</a>
    <a href="settings?tab=security" class="tab <?= $tab === 'security' ? 'tab-active' : '' ?>">Security</a>
    <a href="settings?tab=logins" class="tab <?= $tab === 'logins' ? 'tab-active' : '' ?>">Login History</a>
    <a href="settings?tab=media" class="tab <?= $tab === 'media' ? 'tab-active' : '' ?>">Media</a>
    <a href="settings?tab=backup" class="tab <?= $tab === 'backup' ? 'tab-active' : '' ?>">Backup</a>
    <a href="settings?tab=maintenance" class="tab <?= $tab === 'maintenance' ? 'tab-active' : '' ?>">Maintenance</a>
</div>

<!-- ==================== GENERAL ==================== -->
<?php if ($tab === 'general'): ?>
<form id="settingsForm" data-group="general">
    <div class="card">
        <div class="card-header"><h2>General Settings</h2></div>
        <div class="card-body">
            <div class="settings-grid">
                <div class="form-group">
                    <label>Site Name</label>
                    <input type="text" name="site_name" value="<?= sanitize(s('site_name', APP_NAME)) ?>" placeholder="Core Chain Admin">
                </div>
                <div class="form-group">
                    <label>Site URL</label>
                    <input type="url" name="site_url" value="<?= sanitize(s('site_url', APP_URL)) ?>" placeholder="https://yourdomain.com">
                </div>
                <div class="form-group">
                    <label>Timezone</label>
                    <select name="timezone">
                        <?php
                        $currentTz = s('timezone', 'UTC');
                        $timezones = ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Toronto', 'America/Sao_Paulo', 'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Moscow', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Shanghai', 'Australia/Sydney', 'Pacific/Auckland'];
                        foreach ($timezones as $tz): ?>
                        <option value="<?= $tz ?>" <?= $currentTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Admin Email (Notifications)</label>
                    <input type="email" name="admin_email" value="<?= sanitize(s('admin_email', '')) ?>" placeholder="admin@yourdomain.com">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label>Site Description</label>
                    <textarea name="site_description" rows="2" placeholder="Sovereign digital banking powered by biometric authentication."><?= sanitize(s('site_description', '')) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Footer Copyright</label>
                    <input type="text" name="footer_copyright" value="<?= sanitize(s('footer_copyright', '© ' . date('Y') . ' Core Chain. The Biometric Standard.')) ?>">
                </div>
                <div class="form-group">
                    <label>Google Analytics ID</label>
                    <input type="text" name="google_analytics_id" value="<?= sanitize(s('google_analytics_id', '')) ?>" placeholder="G-XXXXXXXXXX">
                </div>
            </div>
        </div>
    </div>
    <div class="settings-actions">
        <button type="submit" class="btn btn-primary">Save General Settings</button>
        <span class="settings-saved" id="saveStatus"></span>
    </div>
</form>
<?php endif; ?>

<!-- ==================== BRANDING ==================== -->
<?php if ($tab === 'branding'):
    $logoPath = realpath(__DIR__ . '/../assets/images/qor-logo.png');
    $faviconPath = realpath(__DIR__ . '/../assets/images/favicon.png');
    $hasFavicon = $faviconPath && file_exists($faviconPath);
?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Site Logo</h2></div>
    <div class="card-body">
        <p class="settings-note">Upload a new logo. This replaces the logo across the entire site — navbar, footer, and admin sidebar.</p>
        <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
            <div style="background:var(--bg-tertiary);border-radius:12px;padding:20px;text-align:center;min-width:160px;">
                <img src="../assets/images/qor-logo.png?v=<?= time() ?>" alt="Current Logo" style="max-height:80px;max-width:200px;" id="logoPreview">
                <p style="font-size:0.75rem;color:var(--text-muted);margin-top:8px;">Current Logo</p>
            </div>
            <div style="flex:1;min-width:250px;">
                <form id="logoForm" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label>Upload New Logo</label>
                        <input type="file" name="file" accept="image/png,image/svg+xml,image/webp,image/jpeg" id="logoFile" required>
                        <span style="font-size:0.7rem;color:var(--text-muted)">Recommended: PNG or SVG with transparent background. Max 2 MB.</span>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Upload Logo</button>
                    <span id="logoStatus" style="margin-left:8px;font-size:0.85rem;"></span>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Favicon</h2></div>
    <div class="card-body">
        <p class="settings-note">Upload a favicon (browser tab icon). Recommended: square PNG, at least 64 &times; 64 px.</p>
        <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
            <div style="background:var(--bg-tertiary);border-radius:12px;padding:20px;text-align:center;min-width:120px;">
                <?php if ($hasFavicon): ?>
                <img src="../assets/images/favicon.png?v=<?= time() ?>" alt="Current Favicon" style="width:48px;height:48px;image-rendering:pixelated;" id="faviconPreview">
                <?php else: ?>
                <div style="width:48px;height:48px;background:var(--bg-secondary);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;" id="faviconPreview">
                    <svg viewBox="0 0 20 20" fill="var(--text-muted)" width="24" height="24"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
                </div>
                <?php endif; ?>
                <p style="font-size:0.75rem;color:var(--text-muted);margin-top:8px;"><?= $hasFavicon ? 'Current Favicon' : 'No favicon set' ?></p>
            </div>
            <div style="flex:1;min-width:250px;">
                <form id="faviconForm" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label>Upload Favicon</label>
                        <input type="file" name="file" accept="image/png,image/x-icon,image/svg+xml,image/webp" id="faviconFile" required>
                        <span style="font-size:0.7rem;color:var(--text-muted)">PNG, ICO, SVG, or WebP. Square image recommended. Max 1 MB.</span>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Upload Favicon</button>
                    <span id="faviconStatus" style="margin-left:8px;font-size:0.85rem;"></span>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Logo upload
document.getElementById('logoForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const status = document.getElementById('logoStatus');
    const file = document.getElementById('logoFile').files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { status.textContent = 'File too large (max 2 MB).'; status.style.color = 'var(--red)'; return; }

    status.textContent = 'Uploading...';
    status.style.color = 'var(--text-muted)';

    const fd = new FormData();
    fd.append('file', file);
    fd.append('csrf_token', '<?= generateCSRFToken() ?>');

    fetch('api/settings?action=upload_logo', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                status.textContent = 'Logo updated!';
                status.style.color = 'var(--green)';
                document.getElementById('logoPreview').src = '../assets/images/qor-logo.png?v=' + Date.now();
                document.querySelector('.sidebar-logo').src = '../assets/images/qor-logo.png?v=' + Date.now();
            } else {
                status.textContent = d.error || 'Upload failed.';
                status.style.color = 'var(--red)';
            }
        })
        .catch(() => { status.textContent = 'Connection error.'; status.style.color = 'var(--red)'; });
});

// Favicon upload
document.getElementById('faviconForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const status = document.getElementById('faviconStatus');
    const file = document.getElementById('faviconFile').files[0];
    if (!file) return;
    if (file.size > 1 * 1024 * 1024) { status.textContent = 'File too large (max 1 MB).'; status.style.color = 'var(--red)'; return; }

    status.textContent = 'Uploading...';
    status.style.color = 'var(--text-muted)';

    const fd = new FormData();
    fd.append('file', file);
    fd.append('csrf_token', '<?= generateCSRFToken() ?>');

    fetch('api/settings?action=upload_favicon', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                status.textContent = 'Favicon updated!';
                status.style.color = 'var(--green)';
                const preview = document.getElementById('faviconPreview');
                if (preview.tagName === 'IMG') {
                    preview.src = '../assets/images/favicon.png?v=' + Date.now();
                } else {
                    preview.outerHTML = '<img src="../assets/images/favicon.png?v=' + Date.now() + '" alt="Favicon" style="width:48px;height:48px;" id="faviconPreview">';
                }
            } else {
                status.textContent = d.error || 'Upload failed.';
                status.style.color = 'var(--red)';
            }
        })
        .catch(() => { status.textContent = 'Connection error.'; status.style.color = 'var(--red)'; });
});
</script>
<?php endif; ?>

<!-- ==================== EMAIL / SMTP ==================== -->
<?php if ($tab === 'email'): ?>
<form id="settingsForm" data-group="email">
    <div class="card">
        <div class="card-header"><h2>SMTP Configuration</h2></div>
        <div class="card-body">
            <p class="settings-note">Configure your outgoing email server. Currently using values from <code>config.php</code> as defaults.</p>
            <div class="settings-grid">
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?= sanitize(s('smtp_host', SMTP_HOST)) ?>" placeholder="smtp.hostinger.com">
                </div>
                <div class="form-group">
                    <label>SMTP Port</label>
                    <input type="number" name="smtp_port" value="<?= sanitize(s('smtp_port', (string)SMTP_PORT)) ?>" placeholder="465">
                </div>
                <div class="form-group">
                    <label>Security</label>
                    <select name="smtp_secure">
                        <?php $smtpSec = s('smtp_secure', SMTP_SECURE); ?>
                        <option value="ssl" <?= $smtpSec === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="tls" <?= $smtpSec === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="none" <?= $smtpSec === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>SMTP Username</label>
                    <input type="text" name="smtp_user" value="<?= sanitize(s('smtp_user', SMTP_USER)) ?>" placeholder="admin@yourdomain.com">
                </div>
                <div class="form-group">
                    <label>SMTP Password</label>
                    <input type="password" name="smtp_pass" value="<?= s('smtp_pass', '') ? '••••••••' : '' ?>" placeholder="<?= SMTP_PASS ? '(set in config.php)' : 'Enter SMTP password' ?>">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Leave blank to keep current password</span>
                </div>
                <div class="form-group">
                    <label>From Name</label>
                    <input type="text" name="smtp_from_name" value="<?= sanitize(s('smtp_from_name', SMTP_FROM_NAME)) ?>">
                </div>
                <div class="form-group">
                    <label>From Email</label>
                    <input type="email" name="smtp_from_email" value="<?= sanitize(s('smtp_from_email', SMTP_FROM_EMAIL)) ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Test SMTP Connection</h2></div>
        <div class="card-body">
            <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:16px;">Send a test email to verify your settings work.</p>
            <div style="display:flex;gap:8px;align-items:flex-end">
                <div class="form-group" style="flex:1">
                    <label>Test Email Address</label>
                    <input type="email" id="testEmail" placeholder="your@email.com">
                </div>
                <button type="button" class="btn btn-secondary" id="testSmtpBtn" style="margin-bottom:0">Send Test</button>
            </div>
            <div id="testResult" style="margin-top:12px"></div>
        </div>
    </div>

    <div class="settings-actions">
        <button type="submit" class="btn btn-primary">Save Email Settings</button>
        <span class="settings-saved" id="saveStatus"></span>
    </div>
</form>
<?php endif; ?>

<!-- ==================== SECURITY ==================== -->
<?php if ($tab === 'security'): ?>
<form id="settingsForm" data-group="security">
    <div class="card">
        <div class="card-header"><h2>Authentication</h2></div>
        <div class="card-body">
            <div class="settings-grid">
                <div class="form-group">
                    <label>Session Lifetime (seconds)</label>
                    <input type="number" name="session_lifetime" value="<?= sanitize(s('session_lifetime', (string)SESSION_LIFETIME)) ?>" min="300" max="86400">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 1800 (30 minutes). Range: 300-86400</span>
                </div>
                <div class="form-group">
                    <label>Max Login Attempts</label>
                    <input type="number" name="max_login_attempts" value="<?= sanitize(s('max_login_attempts', (string)MAX_LOGIN_ATTEMPTS)) ?>" min="3" max="20">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 5. Lock account after this many failed tries</span>
                </div>
                <div class="form-group">
                    <label>Lockout Duration (seconds)</label>
                    <input type="number" name="lockout_duration" value="<?= sanitize(s('lockout_duration', (string)LOCKOUT_DURATION)) ?>" min="60" max="86400">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 900 (15 minutes)</span>
                </div>
                <div class="form-group">
                    <label>Password Hashing Cost</label>
                    <input type="number" name="bcrypt_cost" value="<?= sanitize(s('bcrypt_cost', (string)BCRYPT_COST)) ?>" min="10" max="15">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 12. Higher = slower but more secure</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Password Policy</h2></div>
        <div class="card-body">
            <div class="settings-grid">
                <div class="form-group">
                    <label>Minimum Password Length</label>
                    <input type="number" name="password_min_length" value="<?= sanitize(s('password_min_length', '8')) ?>" min="6" max="32">
                </div>
                <div class="form-group">
                    <label>Require Uppercase Letter</label>
                    <select name="password_require_upper">
                        <option value="1" <?= s('password_require_upper', '1') === '1' ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= s('password_require_upper', '1') === '0' ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Require Number</label>
                    <select name="password_require_number">
                        <option value="1" <?= s('password_require_number', '1') === '1' ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= s('password_require_number', '1') === '0' ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Require Special Character</label>
                    <select name="password_require_special">
                        <option value="0" <?= s('password_require_special', '0') === '0' ? 'selected' : '' ?>>No</option>
                        <option value="1" <?= s('password_require_special', '0') === '1' ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Two-Factor Authentication</h2></div>
        <div class="card-body">
            <div class="settings-grid">
                <div class="form-group">
                    <label>Enforce 2FA for All Admins</label>
                    <select name="enforce_2fa">
                        <option value="0" <?= s('enforce_2fa', '0') === '0' ? 'selected' : '' ?>>Optional</option>
                        <option value="1" <?= s('enforce_2fa', '0') === '1' ? 'selected' : '' ?>>Required for all admins</option>
                    </select>
                    <span style="font-size:0.7rem;color:var(--text-muted)">When required, admins must set up 2FA on next login</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>IP Access Control</h2></div>
        <div class="card-body">
            <div class="settings-grid" style="grid-template-columns:1fr;">
                <div class="form-group">
                    <label>IP Whitelist (admin access only from these IPs)</label>
                    <textarea name="ip_whitelist" rows="3" placeholder="One IP per line. Leave empty to allow all." style="font-family:monospace;font-size:0.8rem;"><?= htmlspecialchars(s('ip_whitelist', '')) ?></textarea>
                    <span style="font-size:0.7rem;color:var(--text-muted)">Your current IP: <code><?= $_SERVER['REMOTE_ADDR'] ?? 'unknown' ?></code>. Leave empty to disable whitelist.</span>
                </div>
                <div class="form-group">
                    <label>IP Blacklist (block these IPs)</label>
                    <textarea name="ip_blacklist" rows="3" placeholder="One IP per line." style="font-family:monospace;font-size:0.8rem;"><?= htmlspecialchars(s('ip_blacklist', '')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Admin Actions</h2></div>
        <div class="card-body">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                <button type="button" class="btn btn-danger btn-sm" id="forceResetBtn" onclick="forcePasswordReset()">Force All Password Reset</button>
                <span style="font-size:0.8rem;color:var(--text-muted)" id="forceResetResult">Expire all admin passwords — everyone must change on next login</span>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:16px;">
                <button type="button" class="btn btn-danger btn-sm" id="forceLogoutBtn" onclick="forceLogoutAll()">Force Logout All Sessions</button>
                <span style="font-size:0.8rem;color:var(--text-muted)" id="forceLogoutResult">Invalidate all active admin sessions (everyone re-logs in)</span>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:16px;">
                <button type="button" class="btn btn-secondary btn-sm" id="purgeBtn">Purge Activity Log</button>
                <span style="font-size:0.8rem;color:var(--text-muted)" id="purgeResult">Remove entries older than retention period</span>
            </div>
        </div>
    </div>

    <div class="settings-actions">
        <button type="submit" class="btn btn-primary">Save Security Settings</button>
        <span class="settings-saved" id="saveStatus"></span>
    </div>
</form>
<?php endif; ?>

<!-- ==================== LOGIN HISTORY ==================== -->
<?php if ($tab === 'logins'):
    $loginLogs = $db->query("SELECT al.*, a.name as admin_name FROM activity_log al JOIN admins a ON al.admin_id = a.id WHERE al.action IN ('login', 'logout', 'login_failed') ORDER BY al.created_at DESC LIMIT 50")->fetchAll();
?>
<div class="card">
    <div class="card-header"><h2>Recent Login Activity</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Admin</th><th>Action</th><th>IP Address</th><th>Time</th></tr></thead>
                <tbody>
                    <?php if (empty($loginLogs)): ?>
                    <tr><td colspan="4" class="empty-state">No login activity recorded.</td></tr>
                    <?php else: ?>
                    <?php foreach ($loginLogs as $ll): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-avatar-sm"><?= strtoupper(substr($ll['admin_name'], 0, 1)) ?></div>
                                <span><?= sanitize($ll['admin_name']) ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($ll['action'] === 'login'): ?>
                            <span class="badge-green">Login</span>
                            <?php elseif ($ll['action'] === 'logout'): ?>
                            <span class="badge-blue">Logout</span>
                            <?php else: ?>
                            <span class="badge-red">Failed</span>
                            <?php endif; ?>
                        </td>
                        <td><code style="font-size:0.8rem;"><?= sanitize($ll['ip_address']) ?></code></td>
                        <td title="<?= $ll['created_at'] ?>"><?= timeAgo($ll['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==================== MEDIA ==================== -->
<?php if ($tab === 'media'): ?>
<form id="settingsForm" data-group="media">
    <div class="card">
        <div class="card-header"><h2>Upload Settings</h2></div>
        <div class="card-body">
            <div class="settings-grid">
                <div class="form-group">
                    <label>Max File Size (MB)</label>
                    <input type="number" name="max_upload_size" value="<?= sanitize(s('max_upload_size', '10')) ?>" min="1" max="50">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 10MB. Also limited by PHP's upload_max_filesize</span>
                </div>
                <div class="form-group">
                    <label>Allowed Image Types</label>
                    <input type="text" name="allowed_image_types" value="<?= sanitize(s('allowed_image_types', 'jpg,jpeg,png,gif,svg,webp')) ?>">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Comma-separated extensions</span>
                </div>
                <div class="form-group">
                    <label>Allowed Document Types</label>
                    <input type="text" name="allowed_doc_types" value="<?= sanitize(s('allowed_doc_types', 'pdf')) ?>">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Comma-separated extensions</span>
                </div>
                <div class="form-group">
                    <label>Image Quality (JPEG)</label>
                    <input type="number" name="image_quality" value="<?= sanitize(s('image_quality', '85')) ?>" min="50" max="100">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 85. Used when compressing uploads</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Storage Info</h2></div>
        <div class="card-body">
            <?php
            $mediaCount = 0;
            $totalSize = 0;
            try {
                $mediaCount = $db->query('SELECT COUNT(*) FROM media')->fetchColumn();
                $totalSize = $db->query('SELECT COALESCE(SUM(file_size), 0) FROM media')->fetchColumn();
            } catch (Exception $e) {}
            ?>
            <div class="info-list">
                <div class="info-item">
                    <span class="info-label">Total Files</span>
                    <span class="info-value"><?= number_format($mediaCount) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Total Storage Used</span>
                    <span class="info-value"><?= formatStorageSize($totalSize) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Upload Directory</span>
                    <span class="info-value"><code>uploads/</code></span>
                </div>
                <div class="info-item">
                    <span class="info-label">PHP upload_max_filesize</span>
                    <span class="info-value"><code><?= ini_get('upload_max_filesize') ?></code></span>
                </div>
                <div class="info-item">
                    <span class="info-label">PHP post_max_size</span>
                    <span class="info-value"><code><?= ini_get('post_max_size') ?></code></span>
                </div>
            </div>
        </div>
    </div>

    <div class="settings-actions">
        <button type="submit" class="btn btn-primary">Save Media Settings</button>
        <span class="settings-saved" id="saveStatus"></span>
    </div>
</form>
<?php endif; ?>

<!-- ==================== BACKUP ==================== -->
<?php if ($tab === 'backup'): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Database Backup</h2></div>
    <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:16px;">Download a full SQL dump of your database. This includes all tables, data, and structure.</p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <a href="api/settings?action=backup_full" class="btn btn-primary">
                <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                Download Full Backup (.sql)
            </a>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Restore from Backup</h2></div>
    <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:16px;">Upload a .sql file to restore your database. <strong style="color:var(--red);">This will overwrite existing data.</strong></p>
        <form method="POST" action="api/settings?action=restore" enctype="multipart/form-data" onsubmit="return confirm('WARNING: This will overwrite your database. Are you absolutely sure?')">
            <?= csrfField() ?>
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <div class="form-group" style="flex:1;margin:0;">
                    <label>SQL Backup File</label>
                    <input type="file" name="backup_file" accept=".sql" required>
                </div>
                <button type="submit" class="btn btn-danger">Restore Database</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Cache Management</h2></div>
    <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:16px;">Clear temporary files and cached data.</p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn-secondary" onclick="clearCache()">Clear Session Cache</button>
            <button type="button" class="btn btn-secondary" onclick="optimizeDb()">Optimize Database Tables</button>
            <span id="cacheResult" style="font-size:0.8rem;"></span>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Table-by-Table Export</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Table</th><th>Rows</th><th>Size</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php
                    $allTables = ['admins', 'activity_log', 'settings', 'waitlist', 'contacts', 'posts', 'subscribers', 'campaigns', 'email_templates', 'chat_sessions', 'chat_messages', 'chat_config', 'chat_knowledge', 'chat_unanswered', 'page_views', 'analytics_events', 'analytics_goals', 'analytics_conversions', 'seo_pages', 'seo_redirects', 'media'];
                    foreach ($allTables as $tbl):
                        try { $cnt = $db->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn(); } catch (Exception $e) { $cnt = '—'; }
                        try {
                            $sizeRow = $db->query("SELECT ROUND((data_length + index_length), 0) AS size FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "' AND table_name = '{$tbl}'")->fetch();
                            $tblSize = $sizeRow ? formatStorageSize((int)$sizeRow['size']) : '—';
                        } catch (Exception $e) { $tblSize = '—'; }
                    ?>
                    <tr>
                        <td><code><?= $tbl ?></code></td>
                        <td><?= is_numeric($cnt) ? number_format($cnt) : $cnt ?></td>
                        <td><?= $tblSize ?></td>
                        <td>
                            <?php if (is_numeric($cnt)): ?>
                            <a href="api/settings?action=backup_table&table=<?= urlencode($tbl) ?>" class="btn btn-secondary btn-sm">Export</a>
                            <?php else: ?>
                            <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Auto-Purge Settings</h2></div>
    <div class="card-body">
        <form id="settingsForm" data-group="purge">
            <p class="settings-note">Automatically clean up old data to keep your database lean.</p>
            <div class="settings-grid">
                <div class="form-group">
                    <label>Activity Log Retention (days)</label>
                    <input type="number" name="activity_log_retention" value="<?= sanitize(s('activity_log_retention', (string)ACTIVITY_LOG_RETENTION)) ?>" min="7" max="365">
                </div>
                <div class="form-group">
                    <label>Analytics Data Retention (days)</label>
                    <input type="number" name="analytics_retention" value="<?= sanitize(s('analytics_retention', '180')) ?>" min="30" max="730">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 180 days. Page views and events older than this are purged.</span>
                </div>
                <div class="form-group">
                    <label>Closed Chat Sessions Retention (days)</label>
                    <input type="number" name="chat_retention" value="<?= sanitize(s('chat_retention', '90')) ?>" min="7" max="365">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 90 days. Only closed sessions are purged.</span>
                </div>
                <div class="form-group">
                    <label>Unanswered Questions Retention (days)</label>
                    <input type="number" name="unanswered_retention" value="<?= sanitize(s('unanswered_retention', '30')) ?>" min="7" max="180">
                </div>
            </div>
            <div style="display:flex;gap:12px;align-items:center;margin-top:12px;">
                <button type="submit" class="btn btn-primary">Save Purge Settings</button>
                <button type="button" class="btn btn-danger btn-sm" id="purgeAllBtn">Run Purge Now</button>
                <span class="settings-saved" id="saveStatus"></span>
                <span id="purgeAllResult" style="font-size:0.8rem;"></span>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ==================== MAINTENANCE ==================== -->
<?php if ($tab === 'maintenance'): ?>
<form id="settingsForm" data-group="maintenance">
    <div class="card">
        <div class="card-header"><h2>Maintenance Mode</h2></div>
        <div class="card-body">
            <p class="settings-note">When enabled, the public site shows a maintenance page. Admin panel remains accessible.</p>
            <div class="settings-grid">
                <div class="form-group">
                    <label>Maintenance Mode</label>
                    <select name="maintenance_mode">
                        <option value="0" <?= s('maintenance_mode', '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                        <option value="1" <?= s('maintenance_mode', '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Maintenance Message</label>
                    <input type="text" name="maintenance_message" value="<?= sanitize(s('maintenance_message', 'We are currently performing scheduled maintenance. Please check back soon.')) ?>">
                </div>
            </div>
        </div>
    </div>
    <div class="settings-actions">
        <button type="submit" class="btn btn-primary">Save Maintenance Settings</button>
        <span class="settings-saved" id="saveStatus"></span>
    </div>
</form>

<!-- Environment Health Check -->
<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>Environment Health Check</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Check</th><th>Status</th></tr></thead>
                <tbody>
                    <tr>
                        <td>PHP Version (>= 8.0)</td>
                        <td><?= $healthChecks['php_version'] ? '<span class="badge-green">PHP ' . PHP_VERSION . '</span>' : '<span class="badge-red">PHP ' . PHP_VERSION . ' (upgrade recommended)</span>' ?></td>
                    </tr>
                    <tr>
                        <td>SSL / HTTPS</td>
                        <td><?= $healthChecks['ssl'] ? '<span class="badge-green">Active</span>' : '<span class="badge-orange">Not detected (OK for local dev)</span>' ?></td>
                    </tr>
                    <?php foreach ($requiredExts as $ext): ?>
                    <tr>
                        <td>PHP Extension: <code><?= $ext ?></code></td>
                        <td><?= $healthChecks['ext_' . $ext] ? '<span class="badge-green">Loaded</span>' : '<span class="badge-red">Missing</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($writableDirs as $label => $path): ?>
                    <tr>
                        <td>Writable: <code><?= $label ?></code></td>
                        <td><?= $healthChecks['writable_' . $label] ? '<span class="badge-green">Writable</span>' : '<span class="badge-red">Not writable</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- System Information -->
<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>System Information</h2></div>
    <div class="card-body">
        <div class="info-list">
            <div class="info-item">
                <span class="info-label">PHP Version</span>
                <span class="info-value"><code><?= phpversion() ?></code></span>
            </div>
            <div class="info-item">
                <span class="info-label">Server Software</span>
                <span class="info-value"><code><?= sanitize($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') ?></code></span>
            </div>
            <div class="info-item">
                <span class="info-label">Database</span>
                <span class="info-value"><code>MySQL <?= $db->getAttribute(PDO::ATTR_SERVER_VERSION) ?></code></span>
            </div>
            <div class="info-item">
                <span class="info-label">Database Name</span>
                <span class="info-value"><code><?= DB_NAME ?></code></span>
            </div>
            <div class="info-item">
                <span class="info-label">Server Time</span>
                <span class="info-value"><?= date('Y-m-d H:i:s T') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">PHP Memory Limit</span>
                <span class="info-value"><code><?= ini_get('memory_limit') ?></code></span>
            </div>
            <div class="info-item">
                <span class="info-label">Max Execution Time</span>
                <span class="info-value"><code><?= ini_get('max_execution_time') ?>s</code></span>
            </div>
            <div class="info-item">
                <span class="info-label">Admin Panel Version</span>
                <span class="info-value badge-blue">Phase 11</span>
            </div>
        </div>
    </div>
</div>

<!-- Database Tables -->
<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>Database Tables</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Table</th><th>Rows</th><th>Size</th></tr></thead>
                <tbody>
                    <?php
                    $allTables = ['admins', 'activity_log', 'settings', 'waitlist', 'contacts', 'posts', 'subscribers', 'campaigns', 'email_templates', 'chat_sessions', 'chat_messages', 'chat_config', 'chat_knowledge', 'chat_unanswered', 'page_views', 'analytics_events', 'analytics_goals', 'analytics_conversions', 'seo_pages', 'seo_redirects', 'media'];
                    foreach ($allTables as $tbl):
                        try { $cnt = $db->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn(); } catch (Exception $e) { $cnt = '—'; }
                        try {
                            $sizeRow = $db->query("SELECT ROUND((data_length + index_length), 0) AS size FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "' AND table_name = '{$tbl}'")->fetch();
                            $tblSize = $sizeRow ? formatStorageSize((int)$sizeRow['size']) : '—';
                        } catch (Exception $e) { $tblSize = '—'; }
                    ?>
                    <tr>
                        <td><code><?= $tbl ?></code></td>
                        <td><?= is_numeric($cnt) ? number_format($cnt) : $cnt ?></td>
                        <td><?= $tblSize ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const API = 'api/settings';
const csrf = '<?= generateCSRFToken() ?>';

// Save settings
document.getElementById('settingsForm')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const form = e.target;
    const group = form.dataset.group;
    const data = {};
    const formData = new FormData(form);

    for (const [key, value] of formData.entries()) {
        if (key === 'smtp_pass' && value === '••••••••') continue;
        data[key] = value;
    }

    const status = document.getElementById('saveStatus');
    status.textContent = 'Saving...';
    status.style.color = 'var(--text-muted)';

    fetch(API + '?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ group, settings: data, csrf_token: csrf })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            status.textContent = 'Settings saved!';
            status.style.color = 'var(--green)';
        } else {
            status.textContent = d.error || 'Failed to save.';
            status.style.color = 'var(--red)';
        }
        setTimeout(() => status.textContent = '', 3000);
    })
    .catch(() => {
        status.textContent = 'Error saving settings.';
        status.style.color = 'var(--red)';
    });
});

// Test SMTP
const testBtn = document.getElementById('testSmtpBtn');
if (testBtn) {
    testBtn.addEventListener('click', () => {
        const email = document.getElementById('testEmail').value;
        if (!email) { alert('Enter a test email address.'); return; }
        const result = document.getElementById('testResult');
        result.innerHTML = '<span style="color:var(--text-muted)">Sending test email...</span>';
        fetch(API + '?action=test_smtp', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, csrf_token: csrf })
        })
        .then(r => r.json())
        .then(d => {
            result.innerHTML = d.success ?
                '<span style="color:var(--green)">Test email sent successfully!</span>' :
                '<span style="color:var(--red)">' + (d.error || 'Failed to send.') + '</span>';
        })
        .catch(() => { result.innerHTML = '<span style="color:var(--red)">Connection error.</span>'; });
    });
}

// Force logout all
function forceLogoutAll() {
    if (!confirm('Force logout all admin sessions? Everyone (including you) will need to log back in.')) return;
    const result = document.getElementById('forceLogoutResult');
    fetch(API + '?action=force_logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: csrf })
    })
    .then(r => r.json())
    .then(d => {
        result.textContent = d.success ? 'All sessions invalidated. Redirecting...' : (d.error || 'Failed.');
        result.style.color = d.success ? 'var(--green)' : 'var(--red)';
        if (d.success) setTimeout(() => window.location = '.', 1500);
    });
}

// Clear cache
function clearCache() {
    const result = document.getElementById('cacheResult');
    result.textContent = 'Clearing...';
    result.style.color = 'var(--text-muted)';
    fetch(API + '?action=clear_cache', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: csrf })
    }).then(r => r.json()).then(d => {
        result.textContent = d.success ? 'Cache cleared.' : (d.error || 'Failed.');
        result.style.color = d.success ? 'var(--green)' : 'var(--red)';
    });
}

function optimizeDb() {
    const result = document.getElementById('cacheResult');
    result.textContent = 'Optimizing...';
    result.style.color = 'var(--text-muted)';
    fetch(API + '?action=optimize_db', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: csrf })
    }).then(r => r.json()).then(d => {
        result.textContent = d.success ? d.message : (d.error || 'Failed.');
        result.style.color = d.success ? 'var(--green)' : 'var(--red)';
    });
}

// Purge activity log
const purgeBtn = document.getElementById('purgeBtn');
if (purgeBtn) {
    purgeBtn.addEventListener('click', () => {
        if (!confirm('Purge activity log entries older than the retention period?')) return;
        const result = document.getElementById('purgeResult');
        fetch(API + '?action=purge_activity', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf })
        })
        .then(r => r.json())
        .then(d => {
            result.textContent = d.success ? d.deleted + ' entries purged.' : (d.error || 'Failed.');
            result.style.color = d.success ? 'var(--green)' : 'var(--red)';
        });
    });
}

// Force password reset
function forcePasswordReset() {
    if (!confirm('This will force ALL admins (including you) to change their password on next login. Continue?')) return;
    const result = document.getElementById('forceResetResult');
    fetch(API + '?action=force_password_reset', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: csrf })
    })
    .then(r => r.json())
    .then(d => {
        result.textContent = d.success ? d.count + ' admin(s) flagged for password reset.' : (d.error || 'Failed.');
        result.style.color = d.success ? 'var(--green)' : 'var(--red)';
    });
}

// Purge all (backup tab)
const purgeAllBtn = document.getElementById('purgeAllBtn');
if (purgeAllBtn) {
    purgeAllBtn.addEventListener('click', () => {
        if (!confirm('Run auto-purge now? This will delete old data based on retention settings.')) return;
        const result = document.getElementById('purgeAllResult');
        result.textContent = 'Purging...';
        result.style.color = 'var(--text-muted)';
        fetch(API + '?action=purge_all', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf })
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                result.textContent = 'Purged: ' + d.summary;
                result.style.color = 'var(--green)';
            } else {
                result.textContent = d.error || 'Failed.';
                result.style.color = 'var(--red)';
            }
        });
    });
}
</script>

<?php

function formatStorageSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

renderFooter();
?>
