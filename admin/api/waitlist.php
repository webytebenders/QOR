<?php
/**
 * Waitlist API — handles frontend form submissions + admin actions
 *
 * POST (public):  ?action=subscribe   — add email to waitlist
 * GET  (admin):   ?action=export      — export CSV
 * POST (admin):   ?action=delete      — delete entry
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
        $db->exec(file_get_contents(__DIR__ . '/../includes/schema_waitlist.sql'));

        // Check duplicate
        $stmt = $db->prepare('SELECT id FROM waitlist WHERE email = ?');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            jsonResponse(['success' => true, 'message' => 'You\'re already on the list!']);
        }

        // Insert
        $stmt = $db->prepare('INSERT INTO waitlist (email, source_page, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([
            $email,
            $source,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

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

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare("SELECT email, source_page, ip_address, created_at FROM waitlist {$whereSQL} ORDER BY created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="waitlist_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email', 'Source Page', 'IP Address', 'Signed Up']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['email'], $row['source_page'], $row['ip_address'], $row['created_at']]);
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
        redirect('../waitlist.php');
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
    redirect('../waitlist.php');
}

jsonResponse(['error' => 'Invalid action.'], 400);
