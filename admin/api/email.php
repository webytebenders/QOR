<?php
/**
 * Email API
 *
 * POST: ?action=test_smtp        — test SMTP connection
 * POST: ?action=send_campaign    — send campaign to subscribers
 * POST: ?action=save_template    — create/update email template
 * POST: ?action=delete_template  — delete email template
 * POST: ?action=toggle_template  — enable/disable template
 * GET:  ?action=preview_template — preview template HTML
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';
require_once '../includes/mailer.php';
require_once '../includes/logger.php';

startSecureSession();
requireRole('super_admin');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ===== Test SMTP =====
if ($action === 'test_smtp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../email.php'); }

    $testEmail = sanitize($_POST['test_email'] ?? '');
    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        setFlash('error', 'Enter a valid email address.');
        redirect('../email.php');
    }

    $mailer = new Mailer();
    $html = getEmailWrapper('
        <h2 style="font-family:\'Space Grotesk\',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">SMTP Test Successful!</h2>
        <p style="color:#9999aa;">If you\'re reading this, your Core Chain admin email system is working correctly.</p>
        <p style="color:#9999aa;">Server: ' . SMTP_HOST . '<br>Port: ' . SMTP_PORT . '<br>From: ' . SMTP_FROM_EMAIL . '</p>
        <p style="color:#9999aa;">— Core Chain Admin</p>
    ');

    if ($mailer->send($testEmail, 'Core Chain — SMTP Test', $html)) {
        logActivity($_SESSION['admin_id'], 'test_smtp', 'email', null, ['to' => $testEmail]);
        setFlash('success', "Test email sent to {$testEmail}!");
    } else {
        $errors = implode('; ', $mailer->getErrors());
        setFlash('error', "SMTP test failed: {$errors}");
    }
    redirect('../email.php');
}

// ===== Save Template =====
if ($action === 'save_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../email.php'); }

    $id = (int)($_POST['tpl_id'] ?? 0);
    $name = sanitize($_POST['tpl_name'] ?? '');
    $subject = sanitize($_POST['tpl_subject'] ?? '');
    $body = $_POST['tpl_body'] ?? '';
    $triggerEvent = sanitize($_POST['tpl_trigger'] ?? '');
    $variables = sanitize($_POST['tpl_variables'] ?? '');

    if (strlen($name) < 2 || strlen($subject) < 2) {
        setFlash('error', 'Name and subject are required.');
        redirect('../email.php');
    }

    $db = getDB();
    ensureEmailTemplatesTable();

    if ($id) {
        $stmt = $db->prepare('UPDATE email_templates SET name=?, subject=?, body=?, trigger_event=?, variables=? WHERE id=?');
        $stmt->execute([$name, $subject, $body, $triggerEvent, $variables, $id]);
        logActivity($_SESSION['admin_id'], 'update_email_template', 'email_template', $id, ['name' => $name]);
        setFlash('success', "Template '{$name}' updated.");
    } else {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        try {
            $stmt = $db->prepare('INSERT INTO email_templates (slug, name, subject, body, trigger_event, variables) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$slug, $name, $subject, $body, $triggerEvent, $variables]);
            logActivity($_SESSION['admin_id'], 'create_email_template', 'email_template', $db->lastInsertId(), ['name' => $name]);
            setFlash('success', "Template '{$name}' created.");
        } catch (\PDOException $e) {
            setFlash('error', 'A template with this name already exists.');
        }
    }
    redirect('../email.php');
}

// ===== Delete Template =====
if ($action === 'delete_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../email.php'); }

    $id = (int)($_POST['tpl_id'] ?? 0);
    if ($id) {
        $db = getDB();
        $db->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'delete_email_template', 'email_template', $id);
        setFlash('success', 'Template deleted.');
    }
    redirect('../email.php');
}

// ===== Toggle Template Active =====
if ($action === 'toggle_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../email.php'); }

    $id = (int)($_POST['tpl_id'] ?? 0);
    if ($id) {
        $db = getDB();
        $db->prepare('UPDATE email_templates SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'toggle_email_template', 'email_template', $id);
        setFlash('success', 'Template status toggled.');
    }
    redirect('../email.php');
}

// ===== Preview Template =====
if ($action === 'preview_template' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db = getDB();
        ensureEmailTemplatesTable();
        $stmt = $db->prepare('SELECT * FROM email_templates WHERE id = ?');
        $stmt->execute([$id]);
        $tpl = $stmt->fetch();
        if ($tpl) {
            // Replace variables with sample data
            $body = $tpl['body'];
            $sampleVars = ['name' => 'John Doe', 'email' => 'john@example.com', 'subject' => 'General', 'message' => 'This is a sample message.', 'reply_text' => 'Thank you for reaching out!', 'unsubscribe_url' => '#'];
            foreach ($sampleVars as $k => $v) {
                $body = str_replace('{{' . $k . '}}', $v, $body);
            }
            echo getEmailWrapper($body);
            exit;
        }
    }
    echo 'Template not found.';
    exit;
}

// ===== Send Campaign =====
if ($action === 'send_campaign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../newsletter.php?tab=campaigns'); }

    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    if (!$campaignId) { setFlash('error', 'Invalid campaign.'); redirect('../newsletter.php?tab=campaigns'); }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();

    if (!$campaign) { setFlash('error', 'Campaign not found.'); redirect('../newsletter.php?tab=campaigns'); }
    if ($campaign['status'] === 'sent') { setFlash('error', 'Campaign already sent.'); redirect('../newsletter.php?tab=campaigns'); }

    $subscribers = $db->query("SELECT id, email, unsubscribe_token FROM subscribers WHERE status = 'active'")->fetchAll();
    if (empty($subscribers)) { setFlash('error', 'No active subscribers.'); redirect('../newsletter.php?tab=campaigns'); }

    $template = getEmailWrapper($campaign['content'], '{{unsubscribe_url}}');
    $mailer = new Mailer();
    $results = $mailer->sendBulk($subscribers, $campaign['subject'], $template);

    $stmtLog = $db->prepare('INSERT INTO campaign_logs (campaign_id, subscriber_id, status, sent_at) VALUES (?, ?, ?, NOW())');
    foreach ($subscribers as $sub) { $stmtLog->execute([$campaignId, $sub['id'], 'sent']); }

    $stmt = $db->prepare('UPDATE campaigns SET status = ?, sent_at = NOW(), sent_count = ? WHERE id = ?');
    $stmt->execute(['sent', $results['sent'], $campaignId]);

    logActivity($_SESSION['admin_id'], 'send_campaign', 'campaign', $campaignId, ['sent' => $results['sent'], 'failed' => $results['failed']]);

    $msg = $results['failed'] > 0 ? "Sent to {$results['sent']}. {$results['failed']} failed." : "Sent to {$results['sent']} subscribers!";
    setFlash('success', $msg);
    redirect('../newsletter.php?tab=campaigns');
}

jsonResponse(['error' => 'Invalid action.'], 400);
