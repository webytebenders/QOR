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

// ===== AI Content Generation =====
if ($action === 'ai_generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!validateCSRF($input['csrf_token'] ?? '')) { jsonResponse(['success' => false, 'error' => 'Invalid CSRF.'], 403); }

    $mode = $input['mode'] ?? 'subject'; // subject, body, rewrite
    $prompt = trim($input['prompt'] ?? '');
    $tone = $input['tone'] ?? 'professional';
    $existingText = $input['existing_text'] ?? '';

    if (!$prompt && $mode !== 'rewrite') { jsonResponse(['success' => false, 'error' => 'Prompt is required.'], 400); }
    if ($mode === 'rewrite' && !$existingText) { jsonResponse(['success' => false, 'error' => 'No text to rewrite.'], 400); }

    // Load AI config from chatbot settings
    $db = getDB();
    $aiConfig = [];
    try {
        $rows = $db->query("SELECT config_key, config_value FROM chat_config WHERE config_key LIKE 'ai_%'")->fetchAll();
        foreach ($rows as $r) { $aiConfig[$r['config_key']] = $r['config_value']; }
    } catch (Exception $e) {}

    $provider = $aiConfig['ai_provider'] ?? 'none';
    $apiKey = $aiConfig['ai_api_key'] ?? '';
    $model = $aiConfig['ai_model'] ?? 'gpt-4o-mini';

    if ($provider === 'none' || !$apiKey) {
        jsonResponse(['success' => false, 'error' => 'AI not configured. Go to Chatbot > Settings to set up an AI provider.'], 400);
    }

    $toneDesc = [
        'professional' => 'professional and authoritative',
        'casual' => 'casual and conversational',
        'urgent' => 'urgent and action-driven with FOMO',
        'friendly' => 'warm, friendly and approachable',
        'minimal' => 'short, minimal and clean',
    ];
    $toneText = $toneDesc[$tone] ?? 'professional';

    // Build system prompt based on mode
    if ($mode === 'subject') {
        $systemPrompt = "You are an email marketing expert for Core Chain (a biometric blockchain wallet). Generate 5 compelling email subject lines. The tone should be {$toneText}. Keep each under 60 characters. Return ONLY the 5 subject lines, one per line, numbered 1-5. No explanations.";
        $userPrompt = "Topic/context: {$prompt}";
    } elseif ($mode === 'body') {
        $systemPrompt = "You are an email marketing copywriter for Core Chain (a biometric blockchain wallet). Write email body content in HTML (use <p>, <strong>, <ul>, <li> tags). The tone should be {$toneText}. Keep it concise — 3-5 short paragraphs max. Include a clear call-to-action. Do NOT include subject line, just the body content. Do NOT wrap in full HTML document tags.";
        $userPrompt = "Write an email about: {$prompt}";
    } elseif ($mode === 'rewrite') {
        $systemPrompt = "You are an email marketing copywriter. Rewrite the following email content to be more {$toneText}. Keep the same meaning but improve the copy. Return HTML (use <p>, <strong>, <ul>, <li> tags). Only return the rewritten content, no explanations.";
        $userPrompt = "Rewrite this:\n\n{$existingText}" . ($prompt ? "\n\nAdditional instructions: {$prompt}" : '');
    } else {
        jsonResponse(['success' => false, 'error' => 'Invalid mode.'], 400);
    }

    $maxTokens = (int)($aiConfig['ai_max_tokens'] ?? 500);
    if ($mode === 'subject') $maxTokens = 200;

    // Call AI
    require_once 'chat.php'; // for callAiApi — but it's at the bottom, so we duplicate
    $result = callAiApiDirect($provider, $apiKey, $model, $systemPrompt, $userPrompt, $maxTokens);

    if ($result) {
        logActivity($_SESSION['admin_id'], 'ai_generate', 'email', null, ['mode' => $mode]);
        jsonResponse(['success' => true, 'content' => $result, 'mode' => $mode]);
    } else {
        jsonResponse(['success' => false, 'error' => 'AI request failed. Check your API key and model.'], 500);
    }
}

function callAiApiDirect(string $provider, string $apiKey, string $model, string $systemPrompt, string $userMessage, int $maxTokens): ?string {
    if ($provider === 'openai') {
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
        $body = json_encode(['model' => $model, 'messages' => [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userMessage]], 'max_tokens' => $maxTokens, 'temperature' => 0.8]);
    } elseif ($provider === 'anthropic') {
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'];
        $body = json_encode(['model' => $model, 'max_tokens' => $maxTokens, 'system' => $systemPrompt, 'messages' => [['role' => 'user', 'content' => $userMessage]]]);
    } else { return null; }

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);

    if ($provider === 'openai') return $data['choices'][0]['message']['content'] ?? null;
    if ($provider === 'anthropic') return $data['content'][0]['text'] ?? null;
    return null;
}

// ===== Test SMTP =====
if ($action === 'test_smtp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../email'); }

    $testEmail = sanitize($_POST['test_email'] ?? '');
    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        setFlash('error', 'Enter a valid email address.');
        redirect('../email');
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
    redirect('../email');
}

// ===== Save Template =====
if ($action === 'save_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../email'); }

    $id = (int)($_POST['tpl_id'] ?? 0);
    $name = sanitize($_POST['tpl_name'] ?? '');
    $subject = sanitize($_POST['tpl_subject'] ?? '');
    $body = $_POST['tpl_body'] ?? '';
    $triggerEvent = sanitize($_POST['tpl_trigger'] ?? '');
    $variables = sanitize($_POST['tpl_variables'] ?? '');

    if (strlen($name) < 2 || strlen($subject) < 2) {
        setFlash('error', 'Name and subject are required.');
        redirect('../email');
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
    redirect('../email');
}

// ===== Delete Template =====
if ($action === 'delete_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../email'); }

    $id = (int)($_POST['tpl_id'] ?? 0);
    if ($id) {
        $db = getDB();
        $db->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'delete_email_template', 'email_template', $id);
        setFlash('success', 'Template deleted.');
    }
    redirect('../email');
}

// ===== Toggle Template Active =====
if ($action === 'toggle_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../email'); }

    $id = (int)($_POST['tpl_id'] ?? 0);
    if ($id) {
        $db = getDB();
        $db->prepare('UPDATE email_templates SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'toggle_email_template', 'email_template', $id);
        setFlash('success', 'Template status toggled.');
    }
    redirect('../email');
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
// ===== Send Campaign (queue-based) =====
if ($action === 'send_campaign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../newsletter?tab=campaigns'); }

    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    if (!$campaignId) { setFlash('error', 'Invalid campaign.'); redirect('../newsletter?tab=campaigns'); }

    $db = getDB();
    try { $db->exec(file_get_contents(__DIR__ . '/../includes/schema_subscribers.sql')); } catch (Exception $e) {}

    $stmt = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();

    if (!$campaign) { setFlash('error', 'Campaign not found.'); redirect('../newsletter?tab=campaigns'); }
    if ($campaign['status'] === 'sent' || $campaign['status'] === 'sending') { setFlash('error', 'Campaign already sent/sending.'); redirect('../newsletter?tab=campaigns'); }

    $subscribers = getCampaignSubscribers($db, $campaign);
    if (empty($subscribers)) { setFlash('error', 'No subscribers match this audience.'); redirect('../newsletter?tab=campaigns'); }

    // Queue all subscribers
    $queueStmt = $db->prepare('INSERT INTO campaign_send_queue (campaign_id, subscriber_id, email, unsubscribe_token) VALUES (?, ?, ?, ?)');
    $queued = 0;
    foreach ($subscribers as $sub) {
        try {
            $queueStmt->execute([$campaignId, $sub['id'], $sub['email'], $sub['unsubscribe_token']]);
            $queued++;
        } catch (Exception $e) {}
    }

    // Mark campaign as sending
    $db->prepare('UPDATE campaigns SET status = ?, sent_at = NOW(), sent_count = 0 WHERE id = ?')->execute(['sending', $campaignId]);

    logActivity($_SESSION['admin_id'], 'queue_campaign', 'campaign', $campaignId, ['queued' => $queued]);
    setFlash('success', "{$queued} emails queued for sending. Processing will begin automatically.");
    redirect('../newsletter?tab=campaigns');
}

// ===== Cron: Process Send Queue =====
if ($action === 'process_queue') {
    $db = getDB();
    try { $db->exec(file_get_contents(__DIR__ . '/../includes/schema_subscribers.sql')); } catch (Exception $e) {}

    $batchSize = 25;

    // Also trigger scheduled campaigns
    $scheduled = $db->query("SELECT id FROM campaigns WHERE status = 'scheduled' AND scheduled_at <= NOW()")->fetchAll();
    foreach ($scheduled as $sc) {
        $camp = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
        $camp->execute([$sc['id']]);
        $campData = $camp->fetch();
        if ($campData) {
            $subs = getCampaignSubscribers($db, $campData);
            $qStmt = $db->prepare('INSERT INTO campaign_send_queue (campaign_id, subscriber_id, email, unsubscribe_token) VALUES (?, ?, ?, ?)');
            foreach ($subs as $s) { try { $qStmt->execute([$sc['id'], $s['id'], $s['email'], $s['unsubscribe_token']]); } catch (Exception $e) {} }
            $db->prepare('UPDATE campaigns SET status = ?, sent_at = NOW() WHERE id = ?')->execute(['sending', $sc['id']]);
        }
    }

    // Process pending queue items
    $pending = $db->query("SELECT q.*, c.subject, c.content FROM campaign_send_queue q JOIN campaigns c ON q.campaign_id = c.id WHERE q.status IN ('pending', 'retry') AND q.attempts < 3 ORDER BY q.created_at ASC LIMIT {$batchSize}")->fetchAll();

    if (empty($pending)) {
        // Check if any sending campaigns have finished
        $sending = $db->query("SELECT id FROM campaigns WHERE status = 'sending'")->fetchAll();
        foreach ($sending as $sc) {
            $remaining = $db->prepare("SELECT COUNT(*) FROM campaign_send_queue WHERE campaign_id = ? AND status IN ('pending', 'retry')");
            $remaining->execute([$sc['id']]);
            if ($remaining->fetchColumn() == 0) {
                $sentCount = $db->prepare("SELECT COUNT(*) FROM campaign_send_queue WHERE campaign_id = ? AND status = 'sent'");
                $sentCount->execute([$sc['id']]);
                $db->prepare('UPDATE campaigns SET status = ?, sent_count = ? WHERE id = ?')->execute(['sent', $sentCount->fetchColumn(), $sc['id']]);
            }
        }
        jsonResponse(['success' => true, 'processed' => 0, 'message' => 'Queue empty.']);
    }

    $mailer = new Mailer();
    $sent = 0;
    $failed = 0;
    $trackBase = rtrim(APP_URL, '/') . '/admin/api/track';

    foreach ($pending as $item) {
        // Convert blocks JSON to HTML if needed
        $emailContent = $item['content'];
        $decoded = json_decode($emailContent, true);
        if (is_array($decoded)) $emailContent = blocksToEmailHtml($decoded);

        $personalHtml = getEmailWrapper($emailContent, '{{unsubscribe_url}}');
        $unsubUrl = rtrim(APP_URL, '/') . '/admin/api/newsletter?action=unsubscribe&token=' . $item['unsubscribe_token'];
        $personalHtml = str_replace(['{{unsubscribe_url}}', '{{email}}'], [$unsubUrl, $item['email']], $personalHtml);

        // Open tracking pixel
        $trackPixel = '<img src="' . $trackBase . '?action=open&cid=' . $item['campaign_id'] . '&sid=' . $item['subscriber_id'] . '" width="1" height="1" style="display:none;" alt="">';
        $personalHtml = stripos($personalHtml, '</body>') !== false
            ? str_ireplace('</body>', $trackPixel . '</body>', $personalHtml)
            : $personalHtml . $trackPixel;

        // Click tracking
        $cid = $item['campaign_id'];
        $sid = $item['subscriber_id'];
        $personalHtml = preg_replace_callback('/href="(https?:\/\/[^"]+)"/', function($m) use ($trackBase, $cid, $sid) {
            if (strpos($m[1], 'unsubscribe') !== false) return $m[0];
            return 'href="' . $trackBase . '?action=click&cid=' . $cid . '&sid=' . $sid . '&url=' . urlencode($m[1]) . '"';
        }, $personalHtml);

        $result = $mailer->send($item['email'], $item['subject'], $personalHtml);

        if ($result) {
            $db->prepare('UPDATE campaign_send_queue SET status = ?, sent_at = NOW(), attempts = attempts + 1 WHERE id = ?')->execute(['sent', $item['id']]);
            // Create campaign log
            try {
                $db->prepare('INSERT INTO campaign_logs (campaign_id, subscriber_id, status, sent_at) VALUES (?, ?, ?, NOW())')
                    ->execute([$item['campaign_id'], $item['subscriber_id'], 'sent']);
            } catch (Exception $e) {}
            $sent++;
        } else {
            $errors = $mailer->getErrors();
            $errMsg = $errors ? implode('; ', $errors) : 'Unknown error';
            $newStatus = $item['attempts'] + 1 >= 3 ? 'failed' : 'retry';
            $db->prepare('UPDATE campaign_send_queue SET status = ?, attempts = attempts + 1, error_message = ? WHERE id = ?')
                ->execute([$newStatus, substr($errMsg, 0, 500), $item['id']]);
            $failed++;
        }

        // Update campaign sent_count incrementally
        $db->prepare('UPDATE campaigns SET sent_count = (SELECT COUNT(*) FROM campaign_send_queue WHERE campaign_id = ? AND status = ?) WHERE id = ?')
            ->execute([$item['campaign_id'], 'sent', $item['campaign_id']]);
    }

    // Check if any sending campaigns have finished
    $sending = $db->query("SELECT id FROM campaigns WHERE status = 'sending'")->fetchAll();
    foreach ($sending as $sc) {
        $remaining = $db->prepare("SELECT COUNT(*) FROM campaign_send_queue WHERE campaign_id = ? AND status IN ('pending', 'retry')");
        $remaining->execute([$sc['id']]);
        if ($remaining->fetchColumn() == 0) {
            $sentCount = $db->prepare("SELECT COUNT(*) FROM campaign_send_queue WHERE campaign_id = ? AND status = 'sent'");
            $sentCount->execute([$sc['id']]);
            $db->prepare('UPDATE campaigns SET status = ?, sent_count = ? WHERE id = ?')->execute(['sent', $sentCount->fetchColumn(), $sc['id']]);
        }
    }

    jsonResponse(['success' => true, 'processed' => $sent + $failed, 'sent' => $sent, 'failed' => $failed]);
}

// ===== Campaign Send Progress =====
if ($action === 'send_progress') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    if (!$campaignId) { jsonResponse(['success' => false], 400); }
    $db = getDB();

    $total = $db->prepare("SELECT COUNT(*) FROM campaign_send_queue WHERE campaign_id = ?");
    $total->execute([$campaignId]);
    $totalCount = (int)$total->fetchColumn();

    $sentCount = $db->prepare("SELECT COUNT(*) FROM campaign_send_queue WHERE campaign_id = ? AND status = 'sent'");
    $sentCount->execute([$campaignId]);
    $sent = (int)$sentCount->fetchColumn();

    $failedCount = $db->prepare("SELECT COUNT(*) FROM campaign_send_queue WHERE campaign_id = ? AND status = 'failed'");
    $failedCount->execute([$campaignId]);
    $failed = (int)$failedCount->fetchColumn();

    $pending = $totalCount - $sent - $failed;
    $campaign = $db->prepare('SELECT status FROM campaigns WHERE id = ?');
    $campaign->execute([$campaignId]);

    jsonResponse(['success' => true, 'total' => $totalCount, 'sent' => $sent, 'failed' => $failed, 'pending' => $pending, 'status' => $campaign->fetchColumn()]);
}

/**
 * Get subscribers for a campaign based on audience type
 */
function getCampaignSubscribers(PDO $db, array $campaign): array {
    $audienceType = $campaign['audience_type'] ?? 'all';
    $audienceId = $campaign['audience_id'] ?? null;

    if ($audienceType === 'segment' && $audienceId) {
        $segStmt = $db->prepare('SELECT rules FROM segments WHERE id = ?');
        $segStmt->execute([$audienceId]);
        $segRules = json_decode($segStmt->fetchColumn() ?: '{}', true);
        if (!empty($segRules['conditions'])) {
            $match = $segRules['match'] ?? 'all';
            $w = []; $p = []; $j = []; $ti = 0;
            foreach ($segRules['conditions'] as $c) {
                $f = $c['field']; $o = $c['operator']; $v = $c['value'];
                if ($f === 'status') { $w[] = $o === 'equals' ? 's.status = ?' : 's.status != ?'; $p[] = $v; }
                elseif ($f === 'source') { if ($o === 'contains') { $w[] = 's.source LIKE ?'; $p[] = "%{$v}%"; } else { $w[] = $o === 'equals' ? 's.source = ?' : 's.source != ?'; $p[] = $v; } }
                elseif ($f === 'email') { $w[] = $o === 'contains' ? 's.email LIKE ?' : 's.email NOT LIKE ?'; $p[] = "%{$v}%"; }
                elseif ($f === 'subscribed_at') { $w[] = $o === 'after' ? 's.subscribed_at >= ?' : 's.subscribed_at <= ?'; $p[] = $o === 'before' ? $v . ' 23:59:59' : $v; }
                elseif ($f === 'tag') { $a = 'stj' . $ti++; if ($o === 'has') { $j[] = "JOIN subscriber_tags {$a} ON s.id = {$a}.subscriber_id AND {$a}.tag_id = " . (int)$v; } else { $j[] = "LEFT JOIN subscriber_tags {$a} ON s.id = {$a}.subscriber_id AND {$a}.tag_id = " . (int)$v; $w[] = "{$a}.id IS NULL"; } }
            }
            $joiner = $match === 'any' ? ' OR ' : ' AND ';
            $wSQL = $w ? 'AND (' . implode($joiner, $w) . ')' : '';
            $stmt = $db->prepare("SELECT DISTINCT s.id, s.email, s.unsubscribe_token FROM subscribers s " . implode(' ', $j) . " WHERE s.status = 'active' {$wSQL}");
            $stmt->execute($p);
            return $stmt->fetchAll();
        }
    } elseif ($audienceType === 'tag' && $audienceId) {
        $stmt = $db->prepare("SELECT DISTINCT s.id, s.email, s.unsubscribe_token FROM subscribers s JOIN subscriber_tags st ON s.id = st.subscriber_id WHERE s.status = 'active' AND st.tag_id = ?");
        $stmt->execute([$audienceId]);
        return $stmt->fetchAll();
    }
    return $db->query("SELECT id, email, unsubscribe_token FROM subscribers WHERE status = 'active'")->fetchAll();
}

jsonResponse(['error' => 'Invalid action.'], 400);

function blocksToEmailHtml(array $blocks): string {
    $html = '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;font-family:Inter,Arial,sans-serif;">';
    foreach ($blocks as $b) {
        $bg = $b['bgColor'] ?? '#ffffff';
        $pad = $b['padding'] ?? '16px 20px';
        $html .= '<tr><td>';
        switch ($b['type'] ?? '') {
            case 'text': $html .= '<div style="background:' . $bg . ';padding:' . $pad . ';font-size:14px;line-height:1.6;color:#333;">' . ($b['content'] ?? '') . '</div>'; break;
            case 'heading': $html .= '<div style="background:' . $bg . ';padding:' . $pad . '"><h2 style="margin:0;font-size:' . ($b['fontSize'] ?? '22px') . ';color:' . ($b['color'] ?? '#333') . ';font-weight:700;">' . ($b['content'] ?? '') . '</h2></div>'; break;
            case 'image':
                $img = '<img src="' . ($b['src'] ?? '') . '" alt="' . ($b['alt'] ?? '') . '" style="width:' . ($b['width'] ?? '100%') . ';display:block;border-radius:4px;">';
                if (!empty($b['link'])) $img = '<a href="' . $b['link'] . '">' . $img . '</a>';
                $html .= '<div style="background:' . $bg . ';padding:' . $pad . ';text-align:center;">' . $img . '</div>';
                break;
            case 'button': $html .= '<div style="padding:' . $pad . ';text-align:' . ($b['align'] ?? 'center') . ';"><a href="' . ($b['url'] ?? '#') . '" style="display:inline-block;padding:12px 28px;background:' . ($b['bgColor'] ?? '#4FC3F7') . ';color:' . ($b['textColor'] ?? '#fff') . ';border-radius:' . ($b['borderRadius'] ?? '6px') . ';font-weight:600;font-size:14px;text-decoration:none;">' . ($b['text'] ?? 'Click') . '</a></div>'; break;
            case 'divider': $html .= '<div style="padding:' . $pad . '"><hr style="border:none;border-top:' . ($b['thickness'] ?? '1px') . ' solid ' . ($b['color'] ?? '#ddd') . ';"></div>'; break;
            case 'spacer': $html .= '<div style="height:' . ($b['height'] ?? '30px') . ';"></div>'; break;
            case 'columns': $html .= '<div style="background:' . $bg . ';padding:' . $pad . ';"><table width="100%" cellpadding="0" cellspacing="0"><tr><td width="50%" style="vertical-align:top;padding-right:8px;font-size:14px;line-height:1.6;color:#333;">' . ($b['left'] ?? '') . '</td><td width="50%" style="vertical-align:top;padding-left:8px;font-size:14px;line-height:1.6;color:#333;">' . ($b['right'] ?? '') . '</td></tr></table></div>'; break;
        }
        $html .= '</td></tr>';
    }
    $html .= '</table>';
    return $html;
}
