<?php
/**
 * Contacts API
 *
 * POST (public):  ?action=submit        — submit contact form
 * POST (admin):   ?action=update_status  — change status
 * POST (admin):   ?action=reply          — reply to message
 * POST (admin):   ?action=delete         — delete entry
 * GET  (admin):   ?action=export         — export CSV
 */

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

// ===== PUBLIC: Submit contact form =====
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $name = sanitize($input['name'] ?? '');
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $subject = sanitize($input['subject'] ?? 'general');
    $message = sanitize($input['message'] ?? '');

    if (!$name || strlen($name) < 2) {
        jsonResponse(['success' => false, 'message' => 'Please enter your name.'], 400);
    }
    if (!$email) {
        jsonResponse(['success' => false, 'message' => 'Please enter a valid email.'], 400);
    }
    if (!$message || strlen($message) < 10) {
        jsonResponse(['success' => false, 'message' => 'Message must be at least 10 characters.'], 400);
    }

    $validSubjects = ['general', 'partnership', 'institutional', 'node', 'media', 'careers', 'bug'];
    if (!in_array($subject, $validSubjects)) {
        $subject = 'general';
    }

    try {
        $db = getDB();
        $db->exec(file_get_contents(__DIR__ . '/../includes/schema_contacts.sql'));

        $stmt = $db->prepare('INSERT INTO contacts (name, email, subject, message, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $name,
            $email,
            $subject,
            $message,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // Send auto-reply to user
        try {
            require_once '../includes/mailer.php';
            $mailer = new Mailer();
            $mailer->send($email, 'We received your message — Core Chain', getContactAutoReplyEmail($name));
        } catch (Exception $e) { /* silently fail */ }

        // Notify admin of new message
        try {
            if (!isset($mailer)) {
                require_once '../includes/mailer.php';
                $mailer = new Mailer();
            }
            $mailer->send(SMTP_FROM_EMAIL, 'New Contact: ' . $subject . ' — ' . $name, getContactAdminNotificationEmail($name, $email, $subject, $message));
        } catch (Exception $e) { /* silently fail */ }

        jsonResponse(['success' => true, 'message' => 'Message sent! We\'ll get back to you within 48 hours.']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}

// ===== ADMIN: Update Status =====
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../messages.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $status = sanitize($_POST['status'] ?? '');
    $validStatuses = ['new', 'read', 'replied', 'archived'];

    if ($id && in_array($status, $validStatuses)) {
        $db = getDB();
        $stmt = $db->prepare('UPDATE contacts SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'update_contact_status', 'contact', $id, ['status' => $status]);
    }
    redirect('../messages.php' . (isset($_GET['view']) ? '?view=' . $id : ''));
}

// ===== ADMIN: Reply =====
if ($action === 'reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../messages.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $replyText = sanitize($_POST['reply_text'] ?? '');

    if ($id && strlen($replyText) > 0) {
        $db = getDB();
        $stmt = $db->prepare('UPDATE contacts SET status = ?, reply_text = ?, replied_at = NOW() WHERE id = ?');
        $stmt->execute(['replied', $replyText, $id]);

        // Send reply email
        try {
            $stmtContact = $db->prepare('SELECT name, email FROM contacts WHERE id = ?');
            $stmtContact->execute([$id]);
            $contact = $stmtContact->fetch();
            if ($contact) {
                require_once '../includes/mailer.php';
                $mailer = new Mailer();
                $mailer->send($contact['email'], 'Reply from Core Chain', getContactReplyEmail($contact['name'], $replyText));
            }
        } catch (Exception $e) { /* silently fail */ }

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'reply_contact', 'contact', $id);
        setFlash('success', 'Reply sent successfully.');
    }
    redirect('../messages.php?view=' . $id);
}

// ===== ADMIN: Delete =====
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../messages.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM contacts WHERE id = ?');
        $stmt->execute([$id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'delete_contact', 'contact', $id);
        setFlash('success', 'Message deleted.');
    }
    redirect('../messages.php');
}

// ===== ADMIN: Export =====
if ($action === 'export') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $db = getDB();
    $status = $_GET['status'] ?? '';
    $subject = $_GET['subject'] ?? '';

    $where = [];
    $params = [];
    if ($status) { $where[] = 'status = ?'; $params[] = $status; }
    if ($subject) { $where[] = 'subject = ?'; $params[] = $subject; }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare("SELECT name, email, subject, message, status, created_at FROM contacts {$whereSQL} ORDER BY created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="contacts_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Subject', 'Message', 'Status', 'Date']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['name'], $row['email'], $row['subject'], $row['message'], $row['status'], $row['created_at']]);
    }
    fclose($out);

    require_once '../includes/logger.php';
    logActivity($_SESSION['admin_id'], 'export_contacts', 'contact', null, ['count' => count($rows)]);
    exit;
}

jsonResponse(['error' => 'Invalid action.'], 400);
