<?php
/**
 * Chat API
 *
 * POST (public): ?action=start    — start chat session
 * POST (public): ?action=message  — send message, get bot response
 * POST (public): ?action=email    — capture visitor email
 * POST (public): ?action=rate     — rate chat session
 * GET  (admin):  ?action=sessions — list chat sessions
 * GET  (admin):  ?action=history  — get session messages
 * GET  (admin):  ?action=export   — export session as text
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';

$action = $_GET['action'] ?? '';

// ===== PUBLIC: Start Session =====
if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDB();
        $db->exec(file_get_contents(__DIR__ . '/../includes/schema_chat.sql'));

        // Check if chatbot is enabled
        $enabledStmt = $db->prepare("SELECT config_value FROM chat_config WHERE config_key = 'enabled'");
        $enabledStmt->execute();
        $enabled = $enabledStmt->fetchColumn();
        if ($enabled === '0') {
            jsonResponse(['success' => false, 'message' => 'Chat is currently offline.'], 503);
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT INTO chat_sessions (session_token, ip_address, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$token, $_SERVER['REMOTE_ADDR'] ?? '']);

        // Get config
        $config = [];
        $rows = $db->query('SELECT config_key, config_value FROM chat_config')->fetchAll();
        foreach ($rows as $r) { $config[$r['config_key']] = $r['config_value']; }

        jsonResponse([
            'success' => true,
            'session_token' => $token,
            'greeting' => $config['greeting'] ?? 'Hi! How can I help?',
            'bot_name' => $config['bot_name'] ?? 'Core Chain Bot',
            'suggested' => json_decode($config['suggested_questions'] ?? '[]', true),
            'color' => $config['primary_color'] ?? '#4FC3F7',
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Chat unavailable.'], 500);
    }
}

// ===== PUBLIC: Send Message =====
if ($action === 'message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['session_token'] ?? '';
    $message = trim($input['message'] ?? '');

    if (!$token || !$message) { jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400); }

    try {
        $db = getDB();

        // Find session
        $stmt = $db->prepare('SELECT id FROM chat_sessions WHERE session_token = ?');
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session) { jsonResponse(['success' => false, 'message' => 'Session expired.'], 404); }

        $sessionId = $session['id'];

        // Save user message
        $db->prepare('INSERT INTO chat_messages (session_id, role, message, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$sessionId, 'user', $message]);

        // Update session timestamp
        $db->prepare('UPDATE chat_sessions SET updated_at = NOW() WHERE id = ?')->execute([$sessionId]);

        // Get bot response (DB-aware)
        require_once '../includes/chatbot_knowledge.php';
        $botResponse = findBotResponse($message, $db);

        // Get quick replies from global
        $quickReplies = $GLOBALS['_chatbot_quick_replies'] ?? [];

        if (!$botResponse) {
            // Try AI provider if configured
            $aiConfig = [];
            try {
                $cfgRows = $db->query("SELECT config_key, config_value FROM chat_config WHERE config_key LIKE 'ai_%'")->fetchAll();
                foreach ($cfgRows as $cr) { $aiConfig[$cr['config_key']] = $cr['config_value']; }
            } catch (Exception $e) {}

            $aiProvider = $aiConfig['ai_provider'] ?? 'none';
            $aiKey = $aiConfig['ai_api_key'] ?? '';
            $aiModel = $aiConfig['ai_model'] ?? 'gpt-4o-mini';
            $aiSystemPrompt = $aiConfig['ai_system_prompt'] ?? 'You are the Core Chain assistant.';
            $aiMaxTokens = (int)($aiConfig['ai_max_tokens'] ?? 300);

            if ($aiProvider !== 'none' && $aiKey) {
                $botResponse = callAiApi($aiProvider, $aiKey, $aiModel, $aiSystemPrompt, $message, $aiMaxTokens);
                $quickReplies = [];
            }
        }

        if (!$botResponse) {
            // Get fallback from config
            $stmt = $db->prepare("SELECT config_value FROM chat_config WHERE config_key = 'fallback_message'");
            $stmt->execute();
            $botResponse = $stmt->fetchColumn() ?: "I'm not sure about that. You can reach our team at hello@corechain.io or check our FAQ at the Contact page.";
            $quickReplies = ['Contact the team', 'Leave my email'];
        }

        // Save bot response
        $db->prepare('INSERT INTO chat_messages (session_id, role, message, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$sessionId, 'bot', $botResponse]);

        jsonResponse([
            'success' => true,
            'response' => $botResponse,
            'quick_replies' => $quickReplies,
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Error processing message.'], 500);
    }
}

// ===== PUBLIC: Capture Email =====
if ($action === 'email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['session_token'] ?? '';
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $name = sanitize($input['name'] ?? '');

    if (!$token || !$email) { jsonResponse(['success' => false], 400); }

    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE chat_sessions SET visitor_email = ?, visitor_name = ? WHERE session_token = ?');
        $stmt->execute([$email, $name, $token]);
        jsonResponse(['success' => true, 'message' => 'Thanks! Our team will follow up.']);
    } catch (Exception $e) {
        jsonResponse(['success' => false], 500);
    }
}

// ===== PUBLIC: Rate Session =====
if ($action === 'rate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['session_token'] ?? '';
    $rating = (int)($input['rating'] ?? 0);

    if (!$token || $rating < 1 || $rating > 5) { jsonResponse(['success' => false], 400); }

    try {
        $db = getDB();
        $db->prepare('UPDATE chat_sessions SET rating = ? WHERE session_token = ?')->execute([$rating, $token]);
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        jsonResponse(['success' => false], 500);
    }
}

// ===== ADMIN: List Sessions =====
if ($action === 'sessions') {
    require_once '../includes/auth.php';
    startSecureSession(); requireLogin();

    $db = getDB();
    try { $db->exec(file_get_contents(__DIR__ . '/../includes/schema_chat.sql')); } catch (Exception $e) {}

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $total = $db->query('SELECT COUNT(*) FROM chat_sessions')->fetchColumn();
    $stmt = $db->prepare("SELECT cs.*, (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.id) as msg_count FROM chat_sessions cs ORDER BY cs.updated_at DESC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute();
    $sessions = $stmt->fetchAll();

    jsonResponse(['success' => true, 'sessions' => $sessions, 'total' => (int)$total]);
}

// ===== ADMIN: Session History =====
if ($action === 'history') {
    require_once '../includes/auth.php';
    startSecureSession(); requireLogin();

    $sessionId = (int)($_GET['session_id'] ?? 0);
    if (!$sessionId) { jsonResponse(['success' => false], 400); }

    $db = getDB();
    $session = $db->prepare('SELECT * FROM chat_sessions WHERE id = ?');
    $session->execute([$sessionId]);
    $sessionData = $session->fetch();

    $messages = $db->prepare('SELECT * FROM chat_messages WHERE session_id = ? ORDER BY created_at ASC');
    $messages->execute([$sessionId]);

    jsonResponse(['success' => true, 'session' => $sessionData, 'messages' => $messages->fetchAll()]);
}

// ===== ADMIN: Export Session =====
if ($action === 'export') {
    require_once '../includes/auth.php';
    startSecureSession(); requireLogin();

    $sessionId = (int)($_GET['session_id'] ?? 0);
    if (!$sessionId) { jsonResponse(['success' => false], 400); }

    $db = getDB();
    $session = $db->prepare('SELECT * FROM chat_sessions WHERE id = ?');
    $session->execute([$sessionId]);
    $sessionData = $session->fetch();

    $messages = $db->prepare('SELECT * FROM chat_messages WHERE session_id = ? ORDER BY created_at ASC');
    $messages->execute([$sessionId]);
    $msgs = $messages->fetchAll();

    $export = "Chat Session #{$sessionId}\n";
    $export .= "Started: {$sessionData['created_at']}\n";
    $export .= "Visitor: " . ($sessionData['visitor_email'] ?: 'Anonymous') . "\n";
    $export .= "Status: {$sessionData['status']}\n";
    $export .= str_repeat('-', 50) . "\n\n";

    foreach ($msgs as $m) {
        $role = strtoupper($m['role']);
        $time = date('g:i A', strtotime($m['created_at']));
        $export .= "[{$time}] {$role}: {$m['message']}\n\n";
    }

    header('Content-Type: text/plain');
    header("Content-Disposition: attachment; filename=chat-session-{$sessionId}.txt");
    echo $export;
    exit;
}

// ===== ADMIN: Deploy Widget to Frontend =====
if ($action === 'deploy_widget') {
    require_once '../includes/auth.php';
    require_once '../includes/logger.php';
    startSecureSession(); requireLogin();

    $siteRoot = realpath(__DIR__ . '/../../');
    $widgetScript = "\n<!-- Core Chain Chat Widget -->\n<script src=\"admin/assets/js/chatwidget.js\"></script>\n<!-- /Chat Widget -->\n";

    $injected = 0;
    foreach (glob($siteRoot . '/*.html') as $file) {
        $html = file_get_contents($file);

        // Remove old widget if present
        $html = preg_replace('/\n?<!-- Core Chain Chat Widget -->.*?<!-- \/Chat Widget -->\n?/s', '', $html);

        // Inject before </body>
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $widgetScript . '</body>', $html);
            file_put_contents($file, $html);
            $injected++;
        }
    }

    logActivity($_SESSION['admin_id'], 'deploy_widget', 'chatbot', null, ['pages' => $injected]);
    setFlash('success', "Chat widget deployed to {$injected} page(s).");
    redirect('../chatbot?tab=settings');
}

jsonResponse(['error' => 'Invalid action.'], 400);

// ===== AI API Helper =====
function callAiApi(string $provider, string $apiKey, string $model, string $systemPrompt, string $userMessage, int $maxTokens): ?string {
    if ($provider === 'openai') {
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
        $body = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.7,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? null;

    } elseif ($provider === 'anthropic') {
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ];
        $body = json_encode([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? null;
    }

    return null;
}
