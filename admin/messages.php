<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();

// Ensure table exists
try {
    $db->exec(file_get_contents(__DIR__ . '/includes/schema_contacts.sql'));
} catch (Exception $e) {}

// Single message view
$viewId = (int)($_GET['view'] ?? 0);
$viewing = null;

if ($viewId) {
    $stmt = $db->prepare('SELECT * FROM contacts WHERE id = ?');
    $stmt->execute([$viewId]);
    $viewing = $stmt->fetch();

    // Auto-mark as read
    if ($viewing && $viewing['status'] === 'new') {
        $db->prepare('UPDATE contacts SET status = ? WHERE id = ?')->execute(['read', $viewId]);
        $viewing['status'] = 'read';
        require_once 'includes/logger.php';
        logActivity($_SESSION['admin_id'], 'read_contact', 'contact', $viewId);
    }
}

// List view
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$filterStatus = $_GET['status'] ?? '';
$filterSubject = $_GET['subject'] ?? '';
$search = $_GET['search'] ?? '';

$where = [];
$params = [];
if ($filterStatus) { $where[] = 'status = ?'; $params[] = $filterStatus; }
if ($filterSubject) { $where[] = 'subject = ?'; $params[] = $filterSubject; }
if ($search) { $where[] = '(name LIKE ? OR email LIKE ? OR message LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM contacts {$whereSQL}");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT * FROM contacts {$whereSQL} ORDER BY FIELD(status, 'new', 'read', 'replied', 'archived'), created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$messages = $stmt->fetchAll();

// Stats
$countNew = $db->query("SELECT COUNT(*) FROM contacts WHERE status = 'new'")->fetchColumn();
$countRead = $db->query("SELECT COUNT(*) FROM contacts WHERE status = 'read'")->fetchColumn();
$countReplied = $db->query("SELECT COUNT(*) FROM contacts WHERE status = 'replied'")->fetchColumn();
$countArchived = $db->query("SELECT COUNT(*) FROM contacts WHERE status = 'archived'")->fetchColumn();
$countAll = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();

$subjectLabels = [
    'general' => 'General',
    'partnership' => 'Partnership',
    'institutional' => 'Institutional',
    'node' => 'Node Operator',
    'media' => 'Media / Press',
    'careers' => 'Careers',
    'bug' => 'Bug Report',
];

renderHeader('Messages', 'messages');
?>

<?php if ($viewing): ?>
<!-- Single Message View -->
<div class="msg-back">
    <a href="messages" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Inbox
    </a>
    <div class="msg-actions-top">
        <form method="POST" action="api/contacts?action=update_status&view=<?= $viewing['id'] ?>" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
            <?php if ($viewing['status'] !== 'archived'): ?>
            <input type="hidden" name="status" value="archived">
            <button type="submit" class="btn btn-secondary btn-sm">Archive</button>
            <?php else: ?>
            <input type="hidden" name="status" value="read">
            <button type="submit" class="btn btn-secondary btn-sm">Unarchive</button>
            <?php endif; ?>
        </form>
        <form method="POST" action="api/contacts?action=delete" style="display:inline" onsubmit="return confirm('Delete this message permanently?')">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="msg-header-info">
            <div class="msg-sender">
                <div class="msg-avatar"><?= strtoupper(substr($viewing['name'], 0, 1)) ?></div>
                <div>
                    <h3><?= sanitize($viewing['name']) ?></h3>
                    <a href="mailto:<?= sanitize($viewing['email']) ?>" class="msg-email"><?= sanitize($viewing['email']) ?></a>
                </div>
            </div>
            <div class="msg-meta">
                <span class="subject-badge subject-<?= $viewing['subject'] ?>"><?= $subjectLabels[$viewing['subject']] ?? $viewing['subject'] ?></span>
                <span class="status-badge status-<?= $viewing['status'] ?>"><?= ucfirst($viewing['status']) ?></span>
                <span class="msg-date"><?= date('M j, Y \a\t g:i A', strtotime($viewing['created_at'])) ?></span>
            </div>
        </div>

        <div class="msg-body">
            <p><?= nl2br(sanitize($viewing['message'])) ?></p>
        </div>

        <?php if ($viewing['reply_text']): ?>
        <div class="msg-reply-display">
            <h4>Your Reply <span class="msg-reply-date"><?= $viewing['replied_at'] ? date('M j, Y \a\t g:i A', strtotime($viewing['replied_at'])) : '' ?></span></h4>
            <p><?= nl2br(sanitize($viewing['reply_text'])) ?></p>
        </div>
        <?php endif; ?>

        <div class="msg-reply-form">
            <h4><?= $viewing['reply_text'] ? 'Update Reply' : 'Reply' ?></h4>
            <form method="POST" action="api/contacts?action=reply">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
                <div class="form-group">
                    <textarea name="reply_text" rows="4" placeholder="Write your reply..." required><?= sanitize($viewing['reply_text'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
                    Send Reply
                </button>
            </form>
        </div>

        <div class="msg-details">
            <span>IP: <code><?= sanitize($viewing['ip_address']) ?></code></span>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Inbox List View -->

<div class="stats-row">
    <a href="messages" class="stat-widget stat-clickable <?= !$filterStatus ? 'stat-active' : '' ?>">
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countAll ?></span>
            <span class="stat-widget-label">All</span>
        </div>
    </a>
    <a href="messages?status=new" class="stat-widget stat-clickable <?= $filterStatus === 'new' ? 'stat-active' : '' ?>">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countNew ?></span>
            <span class="stat-widget-label">New</span>
        </div>
    </a>
    <a href="messages?status=read" class="stat-widget stat-clickable <?= $filterStatus === 'read' ? 'stat-active' : '' ?>">
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countRead ?></span>
            <span class="stat-widget-label">Read</span>
        </div>
    </a>
    <a href="messages?status=replied" class="stat-widget stat-clickable <?= $filterStatus === 'replied' ? 'stat-active' : '' ?>">
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countReplied ?></span>
            <span class="stat-widget-label">Replied</span>
        </div>
    </a>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <input type="text" name="search" placeholder="Search name, email, message..." value="<?= sanitize($search) ?>" class="filter-input" style="min-width:200px">
        <select name="subject" class="filter-select">
            <option value="">All Subjects</option>
            <?php foreach ($subjectLabels as $key => $label): ?>
            <option value="<?= $key ?>" <?= $filterSubject === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= sanitize($filterStatus) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($search || $filterSubject): ?>
        <a href="messages<?= $filterStatus ? '?status=' . $filterStatus : '' ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filters-actions">
        <a href="api/contacts?action=export&status=<?= urlencode($filterStatus) ?>&subject=<?= urlencode($filterSubject) ?>" class="btn btn-secondary btn-sm">
            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            Export
        </a>
        <span class="filters-count"><?= number_format($total) ?> messages</span>
    </div>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>From</th>
                        <th>Subject</th>
                        <th>Preview</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="5" class="empty-state">No messages yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr class="msg-row <?= $msg['status'] === 'new' ? 'msg-unread' : '' ?>" onclick="window.location='messages?view=<?= $msg['id'] ?>'" style="cursor:pointer">
                        <td><span class="status-dot status-dot-<?= $msg['status'] ?>"></span></td>
                        <td>
                            <div class="msg-from">
                                <strong><?= sanitize($msg['name']) ?></strong>
                                <span><?= sanitize($msg['email']) ?></span>
                            </div>
                        </td>
                        <td><span class="subject-badge subject-<?= $msg['subject'] ?>"><?= $subjectLabels[$msg['subject']] ?? $msg['subject'] ?></span></td>
                        <td class="msg-preview"><?= sanitize(substr($msg['message'], 0, 80)) ?><?= strlen($msg['message']) > 80 ? '...' : '' ?></td>
                        <td title="<?= $msg['created_at'] ?>"><?= timeAgo($msg['created_at']) ?></td>
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
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($filterStatus) ?>&subject=<?= urlencode($filterSubject) ?>&search=<?= urlencode($search) ?>" class="btn btn-secondary btn-sm">Previous</a>
    <?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($filterStatus) ?>&subject=<?= urlencode($filterSubject) ?>&search=<?= urlencode($search) ?>" class="btn btn-secondary btn-sm">Next</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php renderFooter(); ?>
