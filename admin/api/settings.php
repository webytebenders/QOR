<?php
/**
 * Settings API
 *
 * POST (admin): ?action=save           — save settings
 * POST (admin): ?action=test_smtp      — send test email
 * POST (admin): ?action=purge_activity — purge old activity logs
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
        'security' => ['session_lifetime', 'max_login_attempts', 'lockout_duration', 'bcrypt_cost', 'activity_log_retention'],
        'media' => ['max_upload_size', 'allowed_image_types', 'allowed_doc_types', 'image_quality'],
        'maintenance' => ['maintenance_mode', 'maintenance_message'],
    ];

    if (!isset($allowed[$group])) {
        jsonResponse(['success' => false, 'error' => 'Invalid settings group.'], 400);
    }

    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value, setting_group, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');

    $saved = 0;
    foreach ($settings as $key => $value) {
        if (!in_array($key, $allowed[$group])) continue;

        // Sanitize value
        $value = trim($value);

        // Validate numeric fields
        $numericFields = ['smtp_port', 'session_lifetime', 'max_login_attempts', 'lockout_duration', 'bcrypt_cost', 'activity_log_retention', 'max_upload_size', 'image_quality'];
        if (in_array($key, $numericFields)) {
            $value = (string)(int)$value;
        }

        $stmt->execute([$key, $value, $group]);
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

jsonResponse(['error' => 'Invalid action.'], 400);
