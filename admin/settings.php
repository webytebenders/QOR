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

renderHeader('Settings', 'settings');
?>

<div class="tabs">
    <a href="settings.php?tab=general" class="tab <?= $tab === 'general' ? 'tab-active' : '' ?>">General</a>
    <a href="settings.php?tab=email" class="tab <?= $tab === 'email' ? 'tab-active' : '' ?>">Email / SMTP</a>
    <a href="settings.php?tab=security" class="tab <?= $tab === 'security' ? 'tab-active' : '' ?>">Security</a>
    <a href="settings.php?tab=media" class="tab <?= $tab === 'media' ? 'tab-active' : '' ?>">Media</a>
    <a href="settings.php?tab=maintenance" class="tab <?= $tab === 'maintenance' ? 'tab-active' : '' ?>">Maintenance</a>
</div>

<!-- General -->
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

<!-- Email / SMTP -->
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

<!-- Security -->
<?php if ($tab === 'security'): ?>
<form id="settingsForm" data-group="security">
    <div class="card">
        <div class="card-header"><h2>Authentication</h2></div>
        <div class="card-body">
            <div class="settings-grid">
                <div class="form-group">
                    <label>Session Lifetime (seconds)</label>
                    <input type="number" name="session_lifetime" value="<?= sanitize(s('session_lifetime', (string)SESSION_LIFETIME)) ?>" min="300" max="86400">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 1800 (30 minutes). Range: 300–86400</span>
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
        <div class="card-header"><h2>Activity Log</h2></div>
        <div class="card-body">
            <div class="settings-grid">
                <div class="form-group">
                    <label>Retention Period (days)</label>
                    <input type="number" name="activity_log_retention" value="<?= sanitize(s('activity_log_retention', (string)ACTIVITY_LOG_RETENTION)) ?>" min="7" max="365">
                    <span style="font-size:0.7rem;color:var(--text-muted)">Default: 90 days. Older entries are auto-purged</span>
                </div>
                <div class="form-group">
                    <label>Purge Old Entries</label>
                    <button type="button" class="btn btn-secondary btn-sm" id="purgeBtn">Purge Now</button>
                    <span style="font-size:0.7rem;color:var(--text-muted)" id="purgeResult"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="settings-actions">
        <button type="submit" class="btn btn-primary">Save Security Settings</button>
        <span class="settings-saved" id="saveStatus"></span>
    </div>
</form>
<?php endif; ?>

<!-- Media -->
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

<!-- Maintenance -->
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

    <div class="card">
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
                    <span class="info-value"><code><?= $db->getAttribute(PDO::ATTR_SERVER_VERSION) ?></code></span>
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

    <div class="card">
        <div class="card-header"><h2>Database Tables</h2></div>
        <div class="card-body no-pad">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Table</th><th>Rows</th><th>Size</th></tr></thead>
                    <tbody>
                        <?php
                        $tables = ['admins', 'activity_log', 'waitlist', 'contacts', 'posts', 'subscribers', 'campaigns', 'chat_sessions', 'chat_messages', 'chatbot_config', 'page_views', 'seo_pages', 'media', 'settings'];
                        foreach ($tables as $tbl):
                            try {
                                $cnt = $db->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
                            } catch (Exception $e) {
                                $cnt = '—';
                            }
                            try {
                                $sizeRow = $db->query("SELECT ROUND((data_length + index_length), 0) AS size FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "' AND table_name = '{$tbl}'")->fetch();
                                $tblSize = $sizeRow ? formatStorageSize((int)$sizeRow['size']) : '—';
                            } catch (Exception $e) {
                                $tblSize = '—';
                            }
                        ?>
                        <tr>
                            <td><code><?= $tbl ?></code></td>
                            <td><?= $cnt ?></td>
                            <td><?= $tblSize ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="settings-actions">
        <button type="submit" class="btn btn-primary">Save Maintenance Settings</button>
        <span class="settings-saved" id="saveStatus"></span>
    </div>
</form>
<?php endif; ?>

<script>
const API = 'api/settings.php';
const csrf = '<?= generateCSRFToken() ?>';

// Save settings
document.getElementById('settingsForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const form = e.target;
    const group = form.dataset.group;
    const data = {};
    const formData = new FormData(form);

    for (const [key, value] of formData.entries()) {
        // Skip password field if it's the masked placeholder
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
            if (d.success) {
                result.innerHTML = '<span style="color:var(--green)">Test email sent successfully!</span>';
            } else {
                result.innerHTML = '<span style="color:var(--red)">' + (d.error || 'Failed to send.') + '</span>';
            }
        })
        .catch(() => {
            result.innerHTML = '<span style="color:var(--red)">Connection error.</span>';
        });
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
            if (d.success) {
                result.textContent = d.deleted + ' entries purged.';
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
