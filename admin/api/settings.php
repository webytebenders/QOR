<?php
/**
 * Settings API
 *
 * POST (admin): ?action=save                 — save settings
 * POST (admin): ?action=test_smtp            — send test email
 * POST (admin): ?action=purge_activity       — purge old activity logs
 * POST (admin): ?action=purge_all            — purge all old data (analytics, chat, logs)
 * POST (admin): ?action=force_password_reset — flag all admins for password reset
 * GET  (admin): ?action=backup_full          — download full DB backup
 * GET  (admin): ?action=backup_table         — download single table backup
 */

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';
require_once '../includes/logger.php';

startSecureSession();
requireRole('super_admin');

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/../includes/schema_settings.sql')); } catch (Exception $e) {}

$action = $_GET['action'] ?? '';
$admin = getCurrentAdmin();

// ===== SAVE SETTINGS =====
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $group = preg_replace('/[^a-z_]/', '', $input['group'] ?? '');
    $settings = $input['settings'] ?? [];

    if (!$group || empty($settings)) {
        jsonResponse(['success' => false, 'error' => 'No settings to save.'], 400);
    }

    // Whitelist of allowed setting keys per group
    $allowed = [
        'general' => ['site_name', 'site_url', 'timezone', 'admin_email', 'site_description', 'footer_copyright', 'google_analytics_id'],
        'email' => ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from_name', 'smtp_from_email'],
        'security' => ['session_lifetime', 'max_login_attempts', 'lockout_duration', 'bcrypt_cost', 'activity_log_retention', 'password_min_length', 'password_require_upper', 'password_require_number', 'password_require_special', 'enforce_2fa', 'ip_whitelist', 'ip_blacklist'],
        'media' => ['max_upload_size', 'allowed_image_types', 'allowed_doc_types', 'image_quality'],
        'maintenance' => ['maintenance_mode', 'maintenance_message'],
        'purge' => ['activity_log_retention', 'analytics_retention', 'chat_retention', 'unanswered_retention'],
    ];

    if (!isset($allowed[$group])) {
        jsonResponse(['success' => false, 'error' => 'Invalid settings group.'], 400);
    }

    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value, setting_group, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');

    $saved = 0;
    foreach ($settings as $key => $value) {
        if (!in_array($key, $allowed[$group])) continue;

        $value = trim($value);

        // Validate numeric fields
        $numericFields = ['smtp_port', 'session_lifetime', 'max_login_attempts', 'lockout_duration', 'bcrypt_cost', 'activity_log_retention', 'max_upload_size', 'image_quality', 'password_min_length', 'analytics_retention', 'chat_retention', 'unanswered_retention'];
        if (in_array($key, $numericFields)) {
            $value = (string)(int)$value;
        }

        $actualGroup = $group === 'purge' ? 'maintenance' : $group;
        $stmt->execute([$key, $value, $actualGroup]);
        $saved++;
    }

    logActivity($admin['id'], 'update_settings', 'settings', null, ['group' => $group, 'count' => $saved]);

    jsonResponse(['success' => true, 'saved' => $saved]);
}

// ===== TEST SMTP =====
if ($action === 'test_smtp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        jsonResponse(['success' => false, 'error' => 'Invalid email address.'], 400);
    }

    require_once '../includes/mailer.php';

    $mailer = new Mailer();
    $body = getEmailWrapper('
        <h2 style="font-family:\'Space Grotesk\',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">SMTP Test Successful</h2>
        <p style="color:#9999aa;">This is a test email from the Core Chain admin panel.</p>
        <p style="color:#9999aa;">If you received this, your SMTP configuration is working correctly.</p>
        <p style="color:#9999aa;">Sent at: ' . date('Y-m-d H:i:s T') . '</p>
    ');

    $result = $mailer->send($email, 'Core Chain — SMTP Test', $body);

    logActivity($admin['id'], 'test_smtp', 'settings');

    if ($result) {
        jsonResponse(['success' => true]);
    } else {
        $errors = $mailer->getErrors();
        jsonResponse(['success' => false, 'error' => $errors ? implode('; ', $errors) : 'Failed to send email.']);
    }
}

// ===== PURGE ACTIVITY LOG =====
if ($action === 'purge_activity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $deleted = purgeOldActivity();
    logActivity($admin['id'], 'purge_activity', 'settings', null, ['deleted' => $deleted]);

    jsonResponse(['success' => true, 'deleted' => $deleted]);
}

// ===== PURGE ALL OLD DATA =====
if ($action === 'purge_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    // Load retention settings
    $retentions = [];
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('activity_log_retention', 'analytics_retention', 'chat_retention', 'unanswered_retention')")->fetchAll();
        foreach ($rows as $r) { $retentions[$r['setting_key']] = (int)$r['setting_value']; }
    } catch (Exception $e) {}

    $activityDays = $retentions['activity_log_retention'] ?? ACTIVITY_LOG_RETENTION;
    $analyticsDays = $retentions['analytics_retention'] ?? 180;
    $chatDays = $retentions['chat_retention'] ?? 90;
    $unansweredDays = $retentions['unanswered_retention'] ?? 30;

    $results = [];

    // Purge activity log
    $deleted = purgeOldActivity();
    $results[] = "activity: {$deleted}";

    // Purge old analytics
    try {
        $stmt = $db->prepare("DELETE FROM page_views WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$analyticsDays]);
        $results[] = "page_views: " . $stmt->rowCount();
    } catch (Exception $e) { $results[] = "page_views: error"; }

    try {
        $stmt = $db->prepare("DELETE FROM analytics_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$analyticsDays]);
        $results[] = "events: " . $stmt->rowCount();
    } catch (Exception $e) {}

    // Purge closed chat sessions
    try {
        $stmt = $db->prepare("DELETE FROM chat_sessions WHERE status = 'closed' AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$chatDays]);
        $results[] = "chat_sessions: " . $stmt->rowCount();
    } catch (Exception $e) {}

    // Purge unanswered
    try {
        $stmt = $db->prepare("DELETE FROM chat_unanswered WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$unansweredDays]);
        $results[] = "unanswered: " . $stmt->rowCount();
    } catch (Exception $e) {}

    $summary = implode(', ', $results);
    logActivity($admin['id'], 'purge_all', 'settings', null, ['summary' => $summary]);

    jsonResponse(['success' => true, 'summary' => $summary]);
}

// ===== FORCE PASSWORD RESET =====
if ($action === 'force_password_reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    // Add force_reset column if not exists, then flag all
    try {
        $db->exec('ALTER TABLE admins ADD COLUMN IF NOT EXISTS force_password_reset TINYINT(1) NOT NULL DEFAULT 0');
    } catch (Exception $e) {}

    $db->query('UPDATE admins SET force_password_reset = 1 WHERE is_active = 1');
    $count = $db->query('SELECT COUNT(*) FROM admins WHERE force_password_reset = 1')->fetchColumn();

    logActivity($admin['id'], 'force_password_reset', 'settings', null, ['count' => $count]);

    jsonResponse(['success' => true, 'count' => (int)$count]);
}

// ===== FORCE LOGOUT ALL =====
if ($action === 'force_logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    // Delete all sessions from DB
    try { $db->exec('DELETE FROM sessions'); } catch (Exception $e) {}

    // Destroy PHP session files
    $sessionPath = session_save_path();
    if ($sessionPath && is_dir($sessionPath)) {
        foreach (glob($sessionPath . '/sess_*') as $file) {
            @unlink($file);
        }
    }

    logActivity($admin['id'], 'force_logout', 'settings');
    jsonResponse(['success' => true]);
}

// ===== CLEAR CACHE =====
if ($action === 'clear_cache' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    // Clear expired sessions from DB
    try { $db->exec("DELETE FROM sessions WHERE expires_at < NOW()"); } catch (Exception $e) {}

    logActivity($admin['id'], 'clear_cache', 'settings');
    jsonResponse(['success' => true]);
}

// ===== OPTIMIZE DB =====
if ($action === 'optimize_db' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $tables = [];
    $stmt = $db->query('SHOW TABLES');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) { $tables[] = $row[0]; }

    $optimized = 0;
    foreach ($tables as $t) {
        try { $db->exec("OPTIMIZE TABLE `{$t}`"); $optimized++; } catch (Exception $e) {}
    }

    logActivity($admin['id'], 'optimize_db', 'settings', null, ['tables' => $optimized]);
    jsonResponse(['success' => true, 'message' => "Optimized {$optimized} tables."]);
}

// ===== RESTORE FROM BACKUP =====
if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../settings?tab=backup'); }

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'No file uploaded or upload error.');
        redirect('../settings?tab=backup');
    }

    $file = $_FILES['backup_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'sql') {
        setFlash('error', 'Only .sql files are allowed.');
        redirect('../settings?tab=backup');
    }

    $sql = file_get_contents($file['tmp_name']);
    if (!$sql) {
        setFlash('error', 'File is empty.');
        redirect('../settings?tab=backup');
    }

    try {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        // Split by semicolons (basic SQL splitting)
        $statements = array_filter(array_map('trim', explode(";\n", $sql)));
        $executed = 0;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt, " \t\n\r;");
            if (empty($stmt) || strpos($stmt, '--') === 0) continue;
            try { $db->exec($stmt); $executed++; } catch (Exception $e) { /* skip failed statements */ }
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');

        logActivity($admin['id'], 'restore_backup', 'settings', null, ['statements' => $executed]);
        setFlash('success', "Database restored. {$executed} statements executed.");
    } catch (Exception $e) {
        setFlash('error', 'Restore failed: ' . $e->getMessage());
    }

    redirect('../settings?tab=backup');
}

// ===== BACKUP: Full Database =====
if ($action === 'backup_full') {
    $tables = [];
    $stmt = $db->query('SHOW TABLES');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sql = "-- Core Chain Admin Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database: " . DB_NAME . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $createStmt['Create Table'] . ";\n\n";

        $rows = $db->query("SELECT * FROM `{$table}`");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $values = array_map(function($v) use ($db) {
                return $v === null ? 'NULL' : $db->quote($v);
            }, array_values($row));
            $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    logActivity($admin['id'], 'backup_full', 'settings');

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="corechain_backup_' . date('Y-m-d_His') . '.sql"');
    echo $sql;
    exit;
}

// ===== BACKUP: Single Table =====
if ($action === 'backup_table') {
    $table = preg_replace('/[^a-z_]/', '', $_GET['table'] ?? '');
    if (!$table) { jsonResponse(['error' => 'Invalid table name.'], 400); }

    // Verify table exists
    try {
        $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
    } catch (Exception $e) {
        jsonResponse(['error' => 'Table not found.'], 404);
    }

    $sql = "-- Core Chain Admin — Table Export: {$table}\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

    $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch();
    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
    $sql .= $createStmt['Create Table'] . ";\n\n";

    $rows = $db->query("SELECT * FROM `{$table}`");
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $values = array_map(function($v) use ($db) {
            return $v === null ? 'NULL' : $db->quote($v);
        }, array_values($row));
        $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
    }

    logActivity($admin['id'], 'backup_table', 'settings', null, ['table' => $table]);

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $table . '_' . date('Y-m-d') . '.sql"');
    echo $sql;
    exit;
}

// ===== UPLOAD LOGO =====
if ($action === 'upload_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'No file uploaded.'], 400);
    }

    $file = $_FILES['file'];
    if ($file['size'] > 2 * 1024 * 1024) {
        jsonResponse(['success' => false, 'error' => 'File too large. Max 2 MB.'], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedLogo = ['image/png', 'image/svg+xml', 'image/webp', 'image/jpeg'];

    if (!in_array($mime, $allowedLogo)) {
        jsonResponse(['success' => false, 'error' => 'Invalid file type. Allowed: PNG, SVG, WebP, JPEG.'], 400);
    }

    $dest = realpath(__DIR__ . '/../../assets/images') . '/qor-logo.png';
    if (!$dest) {
        jsonResponse(['success' => false, 'error' => 'Upload directory not found.'], 500);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        jsonResponse(['success' => false, 'error' => 'Failed to save file.'], 500);
    }

    logActivity($admin['id'], 'upload_logo', 'settings', null, ['original' => $file['name'], 'size' => $file['size']]);
    jsonResponse(['success' => true]);
}

// ===== UPLOAD FAVICON =====
if ($action === 'upload_favicon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'No file uploaded.'], 400);
    }

    $file = $_FILES['file'];
    if ($file['size'] > 1 * 1024 * 1024) {
        jsonResponse(['success' => false, 'error' => 'File too large. Max 1 MB.'], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedFavicon = ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml', 'image/webp'];

    if (!in_array($mime, $allowedFavicon)) {
        jsonResponse(['success' => false, 'error' => 'Invalid file type. Allowed: PNG, ICO, SVG, WebP.'], 400);
    }

    $dest = realpath(__DIR__ . '/../../assets/images') . '/favicon.png';
    if (!$dest) {
        jsonResponse(['success' => false, 'error' => 'Upload directory not found.'], 500);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        jsonResponse(['success' => false, 'error' => 'Failed to save file.'], 500);
    }

    logActivity($admin['id'], 'upload_favicon', 'settings', null, ['original' => $file['name'], 'size' => $file['size']]);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Invalid action.'], 400);
