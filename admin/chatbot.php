<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';
require_once 'includes/logger.php';

requireLogin();

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_chat.sql')); } catch (Exception $e) {}

$tab = $_GET['tab'] ?? 'sessions';

// Handle config update
if (isPost() && ($_GET['action'] ?? '') === 'save_config') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $fields = ['enabled', 'greeting', 'bot_name', 'suggested_questions', 'fallback_message', 'primary_color', 'widget_logo', 'ai_provider', 'ai_api_key', 'ai_model', 'ai_system_prompt', 'ai_max_tokens'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                if ($f === 'ai_api_key' && $_POST[$f] === '••••••••') continue;
                $val = $f === 'enabled' ? '1' : sanitize($_POST[$f]);
                $db->prepare('INSERT INTO chat_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?')
                    ->execute([$f, $val, $val]);
            }
        }
        if (!isset($_POST['enabled'])) {
            $db->prepare('UPDATE chat_config SET config_value = ? WHERE config_key = ?')->execute(['0', 'enabled']);
        }
        logActivity($_SESSION['admin_id'], 'update_chatbot', 'chatbot');
        setFlash('success', 'Chatbot settings saved.');
    }
    redirect('chatbot?tab=settings');
}

// Handle admin reply
if (isPost() && ($_GET['action'] ?? '') === 'admin_reply') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if ($sessionId && $message) {
            $db->prepare('INSERT INTO chat_messages (session_id, role, message, created_at) VALUES (?, ?, ?, NOW())')
                ->execute([$sessionId, 'admin', $message]);
            $db->prepare('UPDATE chat_sessions SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute(['active', $sessionId]);
            logActivity($_SESSION['admin_id'], 'admin_reply', 'chatbot', $sessionId);
            setFlash('success', 'Reply sent.');
        }
    }
    redirect('chatbot?view=' . ($sessionId ?? 0));
}

// Handle session status change
if (($_GET['action'] ?? '') === 'close_session') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db->prepare('UPDATE chat_sessions SET status = ? WHERE id = ?')->execute(['closed', $id]);
        logActivity($_SESSION['admin_id'], 'close_chat', 'chatbot', $id);
        setFlash('success', 'Session closed.');
    }
    redirect('chatbot');
}
if (($_GET['action'] ?? '') === 'escalate_session') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db->prepare('UPDATE chat_sessions SET status = ? WHERE id = ?')->execute(['escalated', $id]);
        logActivity($_SESSION['admin_id'], 'escalate_chat', 'chatbot', $id);
        setFlash('success', 'Session escalated.');
    }
    redirect('chatbot?view=' . $id);
}
if (($_GET['action'] ?? '') === 'delete_session') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db->prepare('DELETE FROM chat_sessions WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'delete_chat', 'chatbot', $id);
        setFlash('success', 'Session deleted.');
    }
    redirect('chatbot');
}

// Handle knowledge seed
if (($_GET['action'] ?? '') === 'seed_knowledge') {
    require_once 'includes/chatbot_knowledge.php';
    $seeded = seedKnowledge($db);
    logActivity($_SESSION['admin_id'], 'seed_knowledge', 'chatbot', null, ['count' => $seeded]);
    setFlash('success', $seeded > 0 ? "Seeded {$seeded} knowledge entries from defaults." : 'Knowledge base already has entries.');
    redirect('chatbot?tab=knowledge');
}

// Handle knowledge save
if (isPost() && ($_GET['action'] ?? '') === 'save_knowledge') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $id = (int)($_POST['id'] ?? 0);
        $category = sanitize($_POST['category'] ?? 'General');
        $keywords = sanitize($_POST['keywords'] ?? '');
        $response = trim($_POST['response'] ?? '');
        $quickReplies = trim($_POST['quick_replies'] ?? '[]');

        if ($keywords && $response) {
            if ($id) {
                $db->prepare('UPDATE chat_knowledge SET category=?, keywords=?, response=?, quick_replies=? WHERE id=?')
                    ->execute([$category, $keywords, $response, $quickReplies, $id]);
            } else {
                $db->prepare('INSERT INTO chat_knowledge (category, keywords, response, quick_replies) VALUES (?, ?, ?, ?)')
                    ->execute([$category, $keywords, $response, $quickReplies]);
            }
            logActivity($_SESSION['admin_id'], $id ? 'update_knowledge' : 'add_knowledge', 'chatbot');
            setFlash('success', 'Knowledge entry saved.');
        } else {
            setFlash('error', 'Keywords and response are required.');
        }
    }
    redirect('chatbot?tab=knowledge');
}

// Handle knowledge toggle/delete
if (($_GET['action'] ?? '') === 'toggle_knowledge') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) { $db->prepare('UPDATE chat_knowledge SET is_active = NOT is_active WHERE id = ?')->execute([$id]); }
    redirect('chatbot?tab=knowledge');
}
if (($_GET['action'] ?? '') === 'delete_knowledge') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) { $db->prepare('DELETE FROM chat_knowledge WHERE id = ?')->execute([$id]); }
    redirect('chatbot?tab=knowledge');
}

// Handle dismiss unanswered
if (($_GET['action'] ?? '') === 'dismiss_unanswered') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) { $db->prepare('DELETE FROM chat_unanswered WHERE id = ?')->execute([$id]); }
    redirect('chatbot?tab=analytics');
}

// Get config
$config = [];
try {
    $rows = $db->query('SELECT config_key, config_value FROM chat_config')->fetchAll();
    foreach ($rows as $r) { $config[$r['config_key']] = $r['config_value']; }
} catch (Exception $e) {}

// Stats
$totalSessions = $db->query('SELECT COUNT(*) FROM chat_sessions')->fetchColumn();
$todaySessions = $db->query("SELECT COUNT(*) FROM chat_sessions WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$totalMessages = $db->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
$emailsCaptured = $db->query("SELECT COUNT(*) FROM chat_sessions WHERE visitor_email IS NOT NULL AND visitor_email != ''")->fetchColumn();

// Sessions list
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$whereSQL = '';
$filterParams = [];
if ($statusFilter && in_array($statusFilter, ['active', 'closed', 'escalated'])) {
    $whereSQL = 'WHERE cs.status = ?';
    $filterParams[] = $statusFilter;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM chat_sessions cs {$whereSQL}");
$countStmt->execute($filterParams);
$filteredTotal = $countStmt->fetchColumn();
$totalPages = max(1, ceil($filteredTotal / $perPage));

$sessions = $db->prepare("SELECT cs.*, (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.id) as msg_count, (SELECT message FROM chat_messages WHERE session_id = cs.id AND role = 'user' ORDER BY created_at ASC LIMIT 1) as first_message FROM chat_sessions cs {$whereSQL} ORDER BY cs.updated_at DESC LIMIT {$perPage} OFFSET {$offset}");
$sessions->execute($filterParams);
$sessionList = $sessions->fetchAll();

// View single session
$viewId = (int)($_GET['view'] ?? 0);
$viewSession = null;
$viewMessages = [];
if ($viewId) {
    $stmt = $db->prepare('SELECT * FROM chat_sessions WHERE id = ?');
    $stmt->execute([$viewId]);
    $viewSession = $stmt->fetch();
    if ($viewSession) {
        $stmt = $db->prepare('SELECT * FROM chat_messages WHERE session_id = ? ORDER BY created_at ASC');
        $stmt->execute([$viewId]);
        $viewMessages = $stmt->fetchAll();
    }
}

// Knowledge base
$knowledgeEntries = [];
try {
    $knowledgeEntries = $db->query('SELECT * FROM chat_knowledge ORDER BY category, id')->fetchAll();
} catch (Exception $e) {}

$editKnowledge = null;
$editId = (int)($_GET['edit_knowledge'] ?? 0);
if ($editId) {
    $stmt = $db->prepare('SELECT * FROM chat_knowledge WHERE id = ?');
    $stmt->execute([$editId]);
    $editKnowledge = $stmt->fetch();
}

// Analytics
$unanswered = [];
try {
    $unanswered = $db->query('SELECT * FROM chat_unanswered ORDER BY created_at DESC LIMIT 30')->fetchAll();
} catch (Exception $e) {}

$topKnowledge = [];
try {
    $topKnowledge = $db->query('SELECT * FROM chat_knowledge WHERE hit_count > 0 ORDER BY hit_count DESC LIMIT 10')->fetchAll();
} catch (Exception $e) {}

// Sessions per day (last 14 days)
$dailySessions = [];
try {
    $dailySessions = $db->query("SELECT DATE(created_at) as day, COUNT(*) as cnt FROM chat_sessions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY day")->fetchAll();
} catch (Exception $e) {}

// Escalated count
$escalatedCount = $db->query("SELECT COUNT(*) FROM chat_sessions WHERE status = 'escalated'")->fetchColumn();

renderHeader('Chatbot', 'chatbot');
?>

<div class="stats-row">
    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalSessions) ?></span>
            <span class="stat-widget-label">Total Sessions</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($todaySessions) ?></span>
            <span class="stat-widget-label">Today</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M2 5a2 2 0 012-2h7a2 2 0 012 2v4a2 2 0 01-2 2H9l-3 3v-3H4a2 2 0 01-2-2V5z"/><path d="M15 7v2a4 4 0 01-4 4H9.828l-1.766 1.767c.28.149.599.233.938.233h2l3 3v-3h2a2 2 0 002-2V9a2 2 0 00-2-2h-1z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalMessages) ?></span>
            <span class="stat-widget-label">Messages</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon <?= $escalatedCount > 0 ? 'red' : 'purple' ?>">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($escalatedCount) ?></span>
            <span class="stat-widget-label">Escalated</span>
        </div>
    </div>
</div>

<div class="tabs">
    <a href="chatbot?tab=sessions" class="tab <?= $tab === 'sessions' && !$viewId ? 'tab-active' : '' ?>">Sessions</a>
    <a href="chatbot?tab=knowledge" class="tab <?= $tab === 'knowledge' ? 'tab-active' : '' ?>">Knowledge Base</a>
    <a href="chatbot?tab=analytics" class="tab <?= $tab === 'analytics' ? 'tab-active' : '' ?>">Analytics</a>
    <a href="chatbot?tab=settings" class="tab <?= $tab === 'settings' ? 'tab-active' : '' ?>">Settings</a>
</div>

<?php if ($viewSession): ?>
<!-- ==================== CHAT SESSION VIEW ==================== -->
<div class="msg-back" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <a href="chatbot" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Sessions
    </a>
    <div style="display:flex;gap:6px;">
        <?php if ($viewSession['status'] !== 'escalated'): ?>
        <a href="chatbot?action=escalate_session&id=<?= $viewSession['id'] ?>" class="btn btn-secondary btn-sm" onclick="return confirm('Escalate this session?')">Escalate</a>
        <?php endif; ?>
        <?php if ($viewSession['status'] !== 'closed'): ?>
        <a href="chatbot?action=close_session&id=<?= $viewSession['id'] ?>" class="btn btn-secondary btn-sm">Close</a>
        <?php endif; ?>
        <a href="chatbot?action=delete_session&id=<?= $viewSession['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this session and all messages?')">Delete</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Chat #<?= $viewSession['id'] ?></h2>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="badge-<?= $viewSession['status'] === 'active' ? 'green' : ($viewSession['status'] === 'escalated' ? 'red' : 'gray') ?>"><?= ucfirst($viewSession['status']) ?></span>
            <?php if ($viewSession['visitor_email']): ?>
            <span class="badge-blue"><?= sanitize($viewSession['visitor_email']) ?></span>
            <?php endif; ?>
            <span class="msg-date"><?= date('M j, Y g:i A', strtotime($viewSession['created_at'])) ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="chat-history">
            <?php foreach ($viewMessages as $msg): ?>
            <div class="chat-msg chat-msg-<?= $msg['role'] ?>">
                <div class="chat-msg-bubble">
                    <div class="chat-msg-role"><?= $msg['role'] === 'user' ? 'Visitor' : ($msg['role'] === 'bot' ? ($config['bot_name'] ?? 'Bot') : 'Admin') ?></div>
                    <div class="chat-msg-text"><?= nl2br(sanitize($msg['message'])) ?></div>
                    <div class="chat-msg-time"><?= date('g:i A', strtotime($msg['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Admin Reply -->
        <?php if ($viewSession['status'] !== 'closed'): ?>
        <form method="POST" action="chatbot?action=admin_reply" style="margin-top:20px;border-top:1px solid var(--border);padding-top:16px;">
            <?= csrfField() ?>
            <input type="hidden" name="session_id" value="<?= $viewSession['id'] ?>">
            <div class="form-group" style="margin-bottom:12px;">
                <label>Reply as Admin</label>
                <textarea name="message" rows="3" placeholder="Type your reply to the visitor..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
                Send Reply
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'knowledge'): ?>
<!-- ==================== KNOWLEDGE BASE ==================== -->

<!-- Add/Edit Form -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2><?= $editKnowledge ? 'Edit' : 'Add' ?> Knowledge Entry</h2></div>
    <div class="card-body">
        <form method="POST" action="chatbot?action=save_knowledge">
            <?= csrfField() ?>
            <?php if ($editKnowledge): ?>
            <input type="hidden" name="id" value="<?= $editKnowledge['id'] ?>">
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;">
                <div class="form-group" style="margin:0;">
                    <label>Category</label>
                    <input type="text" name="category" value="<?= sanitize($editKnowledge['category'] ?? 'General') ?>" placeholder="General">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Keywords (comma-separated)</label>
                    <input type="text" name="keywords" value="<?= sanitize($editKnowledge['keywords'] ?? '') ?>" placeholder="biometric, face id, fingerprint" required>
                </div>
            </div>
            <div class="form-group">
                <label>Response</label>
                <textarea name="response" rows="4" required placeholder="Bot response when keywords match..."><?= htmlspecialchars($editKnowledge['response'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Quick Replies (JSON array)</label>
                <input type="text" name="quick_replies" value="<?= sanitize($editKnowledge['quick_replies'] ?? '[]') ?>" placeholder='["Follow up question?","Another option"]'>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><?= $editKnowledge ? 'Update' : 'Add' ?> Entry</button>
                <?php if ($editKnowledge): ?>
                <a href="chatbot?tab=knowledge" class="btn btn-ghost">Cancel</a>
                <?php endif; ?>
                <a href="chatbot?action=seed_knowledge" class="btn btn-secondary" style="margin-left:auto;" onclick="return confirm('Seed knowledge base from default entries? (Only works if DB is empty)')">Seed from Defaults</a>
            </div>
        </form>
    </div>
</div>

<!-- Knowledge Entries -->
<div class="card">
    <div class="card-header">
        <h2>Knowledge Entries (<?= count($knowledgeEntries) ?>)</h2>
    </div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Keywords</th>
                        <th>Response</th>
                        <th>Hits</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($knowledgeEntries)): ?>
                    <tr><td colspan="6" class="empty-state">No knowledge entries. Click "Seed from Defaults" to import, or add manually.</td></tr>
                    <?php else: ?>
                    <?php foreach ($knowledgeEntries as $k): ?>
                    <tr>
                        <td><span class="badge-blue"><?= sanitize($k['category']) ?></span></td>
                        <td><code style="font-size:0.75rem;"><?= sanitize(substr($k['keywords'], 0, 50)) ?><?= strlen($k['keywords']) > 50 ? '...' : '' ?></code></td>
                        <td class="msg-preview" style="max-width:250px;"><?= sanitize(substr($k['response'], 0, 80)) ?>...</td>
                        <td><?= number_format($k['hit_count']) ?></td>
                        <td><?= $k['is_active'] ? '<span class="badge-green">Active</span>' : '<span class="badge-gray">Disabled</span>' ?></td>
                        <td>
                            <div class="action-dropdown">
                                <button class="btn btn-secondary btn-sm" onclick="this.nextElementSibling.classList.toggle('show')">Actions &#9662;</button>
                                <div class="dropdown-menu">
                                    <a href="chatbot?tab=knowledge&edit_knowledge=<?= $k['id'] ?>" class="dropdown-item">Edit</a>
                                    <a href="chatbot?action=toggle_knowledge&id=<?= $k['id'] ?>" class="dropdown-item"><?= $k['is_active'] ? 'Disable' : 'Enable' ?></a>
                                    <div class="dropdown-divider"></div>
                                    <a href="chatbot?action=delete_knowledge&id=<?= $k['id'] ?>" class="dropdown-item dropdown-item-danger" onclick="return confirm('Delete this entry?')">Delete</a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'analytics'): ?>
<!-- ==================== ANALYTICS ==================== -->

<!-- Session Trend -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Sessions (Last 14 Days)</h2></div>
    <div class="card-body">
        <?php if (empty($dailySessions)): ?>
        <p class="empty-state">No session data yet.</p>
        <?php else: ?>
        <?php $maxCnt = max(array_column($dailySessions, 'cnt')) ?: 1; ?>
        <div style="display:flex;align-items:flex-end;gap:4px;height:120px;">
            <?php foreach ($dailySessions as $d): ?>
            <?php $pct = ($d['cnt'] / $maxCnt) * 100; ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;">
                <span style="font-size:0.7rem;font-weight:600;"><?= $d['cnt'] ?></span>
                <div style="width:100%;background:var(--blue);border-radius:3px 3px 0 0;min-height:4px;height:<?= max(4, $pct) ?>%;" title="<?= $d['day'] ?>: <?= $d['cnt'] ?> sessions"></div>
                <span style="font-size:0.6rem;color:var(--text-muted);writing-mode:vertical-lr;transform:rotate(180deg);height:40px;overflow:hidden;"><?= date('M j', strtotime($d['day'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <!-- Top Questions -->
    <div class="card">
        <div class="card-header"><h2>Top Matched Topics</h2></div>
        <div class="card-body no-pad">
            <?php if (empty($topKnowledge)): ?>
            <p class="empty-state" style="padding:20px;">No data yet.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Keywords</th><th>Hits</th></tr></thead>
                    <tbody>
                        <?php foreach ($topKnowledge as $tk): ?>
                        <tr>
                            <td><code style="font-size:0.75rem;"><?= sanitize(substr($tk['keywords'], 0, 40)) ?></code></td>
                            <td><strong><?= number_format($tk['hit_count']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Unanswered Questions -->
    <div class="card">
        <div class="card-header"><h2>Unanswered Questions (<?= count($unanswered) ?>)</h2></div>
        <div class="card-body no-pad">
            <?php if (empty($unanswered)): ?>
            <p class="empty-state" style="padding:20px;">All questions answered!</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Message</th><th>When</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($unanswered as $u): ?>
                        <tr>
                            <td style="font-size:0.85rem;"><?= sanitize(substr($u['message'], 0, 60)) ?></td>
                            <td class="msg-date"><?= timeAgo($u['created_at']) ?></td>
                            <td><a href="chatbot?action=dismiss_unanswered&id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm" title="Dismiss">&times;</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Key Metrics -->
<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>Key Metrics</h2></div>
    <div class="card-body">
        <div class="info-list">
            <div class="info-item">
                <span class="info-label">Total Sessions</span>
                <span class="info-value"><?= number_format($totalSessions) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Messages</span>
                <span class="info-value"><?= number_format($totalMessages) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Avg Messages / Session</span>
                <span class="info-value"><?= $totalSessions > 0 ? round($totalMessages / $totalSessions, 1) : '0' ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Emails Captured</span>
                <span class="info-value"><?= number_format($emailsCaptured) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Email Capture Rate</span>
                <span class="info-value"><?= $totalSessions > 0 ? round(($emailsCaptured / $totalSessions) * 100, 1) : '0' ?>%</span>
            </div>
            <div class="info-item">
                <span class="info-label">Knowledge Base Entries</span>
                <span class="info-value"><?= count($knowledgeEntries) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Unanswered Questions</span>
                <span class="info-value"><?= count($unanswered) > 0 ? '<span class="badge-orange">' . count($unanswered) . '</span>' : '<span class="badge-green">0</span>' ?></span>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'settings'): ?>
<!-- ==================== SETTINGS ==================== -->
<form method="POST" action="chatbot?action=save_config">
    <?= csrfField() ?>
    <div class="editor-grid">
        <div class="editor-main">
            <div class="card">
                <div class="card-header"><h2>Chatbot Settings</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="enabled" value="1" <?= ($config['enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span>Enable chatbot widget on website</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Bot Name</label>
                        <input type="text" name="bot_name" value="<?= sanitize($config['bot_name'] ?? 'Core Chain Bot') ?>">
                    </div>
                    <div class="form-group">
                        <label>Greeting Message</label>
                        <textarea name="greeting" rows="3"><?= sanitize($config['greeting'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Fallback Message (when bot can't answer)</label>
                        <textarea name="fallback_message" rows="2"><?= sanitize($config['fallback_message'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Suggested Questions (JSON array)</label>
                        <textarea name="suggested_questions" rows="3" style="font-family:monospace;font-size:0.8rem"><?= sanitize($config['suggested_questions'] ?? '[]') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Primary Color</label>
                        <input type="color" name="primary_color" value="<?= $config['primary_color'] ?? '#4FC3F7' ?>" style="width:60px;height:36px;padding:2px;cursor:pointer">
                    </div>
                    <div class="form-group">
                        <label>Chat Widget Logo URL</label>
                        <input type="text" name="widget_logo" value="<?= sanitize($config['widget_logo'] ?? '') ?>" placeholder="assets/images/qor-logo.png">
                        <span style="font-size:0.7rem;color:var(--text-muted)">Displayed in the chat widget header. Leave empty for default icon.</span>
                    </div>
                </div>
            </div>

            <!-- AI API Configuration -->
            <div class="card">
                <div class="card-header"><h2>AI API Configuration</h2></div>
                <div class="card-body">
                    <p class="settings-note">Connect an AI provider for intelligent responses. The bot will use AI when the knowledge base has no match. Leave API key empty to use knowledge base only.</p>
                    <div class="form-group">
                        <label>AI Provider</label>
                        <select name="ai_provider">
                            <option value="none" <?= ($config['ai_provider'] ?? 'none') === 'none' ? 'selected' : '' ?>>None (Knowledge Base Only)</option>
                            <option value="openai" <?= ($config['ai_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                            <option value="anthropic" <?= ($config['ai_provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic (Claude)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>API Key</label>
                        <input type="password" name="ai_api_key" value="<?= ($config['ai_api_key'] ?? '') ? '••••••••' : '' ?>" placeholder="sk-... or anthropic key">
                        <span style="font-size:0.7rem;color:var(--text-muted)">Leave blank to keep current key. Stored encrypted in DB.</span>
                    </div>
                    <div class="form-group">
                        <label>Model</label>
                        <input type="text" name="ai_model" value="<?= sanitize($config['ai_model'] ?? 'gpt-4o-mini') ?>" placeholder="gpt-4o-mini, claude-haiku-4-5-20251001">
                        <span style="font-size:0.7rem;color:var(--text-muted)">OpenAI: gpt-4o-mini, gpt-4o | Anthropic: claude-haiku-4-5-20251001, claude-sonnet-4-6</span>
                    </div>
                    <div class="form-group">
                        <label>System Prompt</label>
                        <textarea name="ai_system_prompt" rows="4" style="font-family:monospace;font-size:0.8rem;"><?= sanitize($config['ai_system_prompt'] ?? 'You are the Core Chain assistant. You help visitors learn about Core Chain — a biometric-powered blockchain wallet. Be concise, friendly, and helpful. Only answer questions related to Core Chain, crypto, and blockchain. If unsure, direct them to hello@corechain.io.') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Max Tokens</label>
                        <input type="number" name="ai_max_tokens" value="<?= sanitize($config['ai_max_tokens'] ?? '300') ?>" min="50" max="2000">
                    </div>
                </div>
            </div>
        </div>
        <div class="editor-sidebar">
            <div class="card">
                <div class="card-header"><h2>Info</h2></div>
                <div class="card-body">
                    <p style="font-size:0.85rem;color:var(--text-secondary);line-height:1.6;">The chatbot first checks the knowledge base. If no match is found and an AI provider is configured, it queries the AI API for a response.</p>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:12px;">Admin can reply directly to active sessions from the session view.</p>
                    <button type="submit" class="btn btn-primary btn-full" style="margin-top:16px">Save Settings</button>
                </div>
            </div>

            <!-- Deploy Widget -->
            <div class="card">
                <div class="card-header"><h2>Deploy Widget</h2></div>
                <div class="card-body">
                    <p style="font-size:0.85rem;color:var(--text-secondary);line-height:1.6;margin-bottom:12px;">Add the chat widget to your website pages. Click the button below to auto-inject the widget script into all HTML pages.</p>
                    <a href="api/chat?action=deploy_widget" class="btn btn-primary btn-full" onclick="return confirm('This will add the chat widget script to all HTML pages. Continue?')">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 01-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        Deploy to All Pages
                    </a>
                    <p style="font-size:0.75rem;color:var(--text-muted);margin-top:8px;">Or add manually:<br><code style="font-size:0.7rem;">&lt;script src="admin/assets/js/chatwidget.js"&gt;&lt;/script&gt;</code></p>
                </div>
            </div>
        </div>
    </div>
</form>

<?php else: ?>
<!-- ==================== SESSIONS LIST ==================== -->

<!-- Status filters -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
    <a href="chatbot" class="btn <?= !$statusFilter ? 'btn-primary' : 'btn-secondary' ?> btn-sm">All (<?= $totalSessions ?>)</a>
    <a href="chatbot?status=active" class="btn <?= $statusFilter === 'active' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Active</a>
    <a href="chatbot?status=escalated" class="btn <?= $statusFilter === 'escalated' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Escalated<?= $escalatedCount > 0 ? " ({$escalatedCount})" : '' ?></a>
    <a href="chatbot?status=closed" class="btn <?= $statusFilter === 'closed' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Closed</a>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Visitor</th>
                        <th>First Message</th>
                        <th>Messages</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessionList)): ?>
                    <tr><td colspan="7" class="empty-state">No chat sessions<?= $statusFilter ? " with status '{$statusFilter}'" : '' ?>.</td></tr>
                    <?php else: ?>
                    <?php foreach ($sessionList as $s): ?>
                    <tr>
                        <td class="text-muted"><?= $s['id'] ?></td>
                        <td><?= $s['visitor_email'] ? '<span class="badge-blue">' . sanitize($s['visitor_email']) . '</span>' : ($s['visitor_name'] ? sanitize($s['visitor_name']) : '<span class="text-muted">Anonymous</span>') ?></td>
                        <td class="msg-preview"><?= $s['first_message'] ? sanitize(substr($s['first_message'], 0, 50)) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $s['msg_count'] ?></td>
                        <td>
                            <?php if ($s['status'] === 'escalated'): ?>
                            <span class="badge-red">Escalated</span>
                            <?php elseif ($s['status'] === 'closed'): ?>
                            <span class="badge-gray">Closed</span>
                            <?php else: ?>
                            <span class="badge-green">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="msg-date"><?= timeAgo($s['created_at']) ?></td>
                        <td style="white-space:nowrap;">
                            <a href="chatbot?view=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">View</a>
                            <?php if ($s['status'] !== 'closed'): ?>
                            <a href="chatbot?action=close_session&id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm" title="Close">&#10005;</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?><a href="?tab=sessions&<?= $statusFilter ? "status={$statusFilter}&" : '' ?>page=<?= $page - 1 ?>" class="btn btn-secondary btn-sm">Previous</a><?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="?tab=sessions&<?= $statusFilter ? "status={$statusFilter}&" : '' ?>page=<?= $page + 1 ?>" class="btn btn-secondary btn-sm">Next</a><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php renderFooter(); ?>
