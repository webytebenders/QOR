<?php
/**
 * Waitlist API — handles frontend form submissions + admin actions
 *
 * POST (public):  ?action=subscribe      — add email to waitlist
 * GET  (admin):   ?action=export          — export CSV
 * POST (admin):   ?action=delete          — delete entry
 * POST (admin):   ?action=bulk_delete     — delete multiple entries
 * POST (admin):   ?action=update_status   — change status of entry
 * POST (admin):   ?action=bulk_status     — change status of multiple entries
 * POST (admin):   ?action=save_notes      — save notes for entry
 * POST (admin):   ?action=resend_email    — resend welcome email
 */

// Allow CORS for frontend forms
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ===== PUBLIC: Subscribe =====
if ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $source = sanitize($input['source'] ?? 'unknown');

    if (!$email) {
        jsonResponse(['success' => false, 'message' => 'Invalid email address.'], 400);
    }

    try {
        $db = getDB();

        // Ensure table exists
        $schema = file_get_contents(__DIR__ . '/../includes/schema_waitlist.sql');
        foreach (explode(';', $schema) as $sql) {
            $sql = trim($sql);
            if ($sql) {
                try { $db->exec($sql); } catch (Exception $e) {}
            }
        }

        // Check duplicate
        $stmt = $db->prepare('SELECT id FROM waitlist WHERE email = ?');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            jsonResponse(['success' => true, 'message' => 'You\'re already on the list!']);
        }

        // Insert
        $stmt = $db->prepare('INSERT INTO waitlist (email, source_page, status, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $email,
            $source,
            'new',
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // Send welcome email (non-blocking — don't fail if email fails)
        try {
            require_once '../includes/mailer.php';
            $mailer = new Mailer();
            $mailer->send($email, 'Welcome to Core Chain', getWaitlistWelcomeEmail());
        } catch (Exception $e) { /* silently fail */ }

        jsonResponse(['success' => true, 'message' => 'You\'re in! We\'ll be in touch.']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}

// ===== ADMIN: Export CSV =====
if ($action === 'export') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $db = getDB();

    $search = $_GET['search'] ?? '';
    $source = $_GET['source'] ?? '';
    $status = $_GET['status'] ?? '';

    $where = [];
    $params = [];

    if ($search) {
        $where[] = 'email LIKE ?';
        $params[] = "%{$search}%";
    }
    if ($source) {
        $where[] = 'source_page = ?';
        $params[] = $source;
    }
    if ($status) {
        $where[] = 'status = ?';
        $params[] = $status;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare("SELECT email, source_page, status, notes, ip_address, created_at FROM waitlist {$whereSQL} ORDER BY created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="waitlist_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8 compatibility
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Email', 'Source Page', 'Status', 'Notes', 'IP Address', 'Signed Up']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['email'],
            $row['source_page'],
            ucfirst($row['status'] ?? 'new'),
            $row['notes'] ?? '',
            $row['ip_address'],
            date('Y-m-d H:i:s', strtotime($row['created_at']))
        ]);
    }
    fclose($out);

    // Log export
    require_once '../includes/logger.php';
    logActivity($_SESSION['admin_id'], 'export_waitlist', 'waitlist', null, ['count' => count($rows)]);
    exit;
}

// ===== ADMIN: Delete =====
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../waitlist');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM waitlist WHERE id = ?');
        $stmt->execute([$id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'delete_waitlist', 'waitlist', $id);
        setFlash('success', 'Entry deleted.');
    }
    redirect('../waitlist');
}

// ===== ADMIN: Bulk Delete =====
if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../waitlist');
    }

    $ids = $_POST['ids'] ?? [];
    if (!empty($ids) && is_array($ids)) {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $intIds = array_map('intval', $ids);
        $stmt = $db->prepare("DELETE FROM waitlist WHERE id IN ({$placeholders})");
        $stmt->execute($intIds);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'bulk_delete_waitlist', 'waitlist', null, ['count' => count($intIds)]);
        setFlash('success', count($intIds) . ' entries deleted.');
    }
    redirect('../waitlist');
}

// ===== ADMIN: Update Status =====
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../waitlist');
    }

    $id = (int)($_POST['id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $valid = ['new', 'contacted', 'qualified', 'ready', 'converted'];

    if ($id && in_array($newStatus, $valid)) {
        $db = getDB();
        $stmt = $db->prepare('UPDATE waitlist SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'update_waitlist_status', 'waitlist', $id, ['status' => $newStatus]);
        setFlash('success', 'Status updated to ' . ucfirst($newStatus) . '.');
    }
    redirect('../waitlist');
}

// ===== ADMIN: Bulk Status Update =====
if ($action === 'bulk_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../waitlist');
    }

    $ids = $_POST['ids'] ?? [];
    $newStatus = $_POST['status'] ?? '';
    $valid = ['new', 'contacted', 'qualified', 'ready', 'converted'];

    if (!empty($ids) && is_array($ids) && in_array($newStatus, $valid)) {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $intIds = array_map('intval', $ids);
        $params = array_merge([$newStatus], $intIds);
        $stmt = $db->prepare("UPDATE waitlist SET status = ? WHERE id IN ({$placeholders})");
        $stmt->execute($params);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'bulk_update_waitlist_status', 'waitlist', null, ['count' => count($intIds), 'status' => $newStatus]);
        setFlash('success', count($intIds) . ' entries updated to ' . ucfirst($newStatus) . '.');
    }
    redirect('../waitlist');
}

// ===== ADMIN: Save Notes =====
if ($action === 'save_notes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($id) {
        $db = getDB();
        $stmt = $db->prepare('UPDATE waitlist SET notes = ? WHERE id = ?');
        $stmt->execute([$notes, $id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'update_waitlist_notes', 'waitlist', $id);
        jsonResponse(['success' => true, 'message' => 'Notes saved.']);
    }
    jsonResponse(['success' => false, 'message' => 'Invalid entry.'], 400);
}

// ===== ADMIN: Resend Welcome Email =====
if ($action === 'resend_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../waitlist');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $stmt = $db->prepare('SELECT email FROM waitlist WHERE id = ?');
        $stmt->execute([$id]);
        $entry = $stmt->fetch();

        if ($entry) {
            try {
                require_once '../includes/mailer.php';
                $mailer = new Mailer();
                $sent = $mailer->send($entry['email'], 'Welcome to Core Chain', getWaitlistWelcomeEmail());
                if ($sent) {
                    require_once '../includes/logger.php';
                    logActivity($_SESSION['admin_id'], 'resend_waitlist_email', 'waitlist', $id);
                    setFlash('success', 'Welcome email resent to ' . $entry['email']);
                } else {
                    setFlash('error', 'Failed to send email. Check SMTP settings.');
                }
            } catch (Exception $e) {
                setFlash('error', 'Email error: ' . $e->getMessage());
            }
        }
    }
    redirect('../waitlist');
}

// ===== ADMIN: Get single entry (AJAX) =====
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM waitlist WHERE id = ?');
        $stmt->execute([$id]);
        $entry = $stmt->fetch();
        if ($entry) {
            jsonResponse(['success' => true, 'entry' => $entry]);
        }
    }
    jsonResponse(['success' => false, 'message' => 'Not found.'], 404);
}

jsonResponse(['error' => 'Invalid action.'], 400);
