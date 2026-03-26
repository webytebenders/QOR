<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_subscribers.sql')); } catch (Exception $e) {}

$tab = $_GET['tab'] ?? 'subscribers';

// ===== SUBSCRIBERS TAB =====
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$filterStatus = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$where = [];
$params = [];
if ($filterStatus) { $where[] = 'status = ?'; $params[] = $filterStatus; }
if ($search) { $where[] = 'email LIKE ?'; $params[] = "%{$search}%"; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmtCount = $db->prepare("SELECT COUNT(*) FROM subscribers {$whereSQL}");
$stmtCount->execute($params);
$total = $stmtCount->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT * FROM subscribers {$whereSQL} ORDER BY subscribed_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$subscribers = $stmt->fetchAll();

// Stats
$totalActive = $db->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();
$totalUnsub = $db->query("SELECT COUNT(*) FROM subscribers WHERE status = 'unsubscribed'")->fetchColumn();
$totalAll = $db->query("SELECT COUNT(*) FROM subscribers")->fetchColumn();
$thisWeekSubs = $db->query("SELECT COUNT(*) FROM subscribers WHERE subscribed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'active'")->fetchColumn();

// ===== CAMPAIGNS TAB =====
$campaigns = $db->query('SELECT c.*, a.name as author_name FROM campaigns c JOIN admins a ON c.author_id = a.id ORDER BY c.created_at DESC')->fetchAll();
$campaignCount = count($campaigns);

renderHeader('Newsletter', 'newsletter');
?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalActive) ?></span>
            <span class="stat-widget-label">Active Subscribers</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($thisWeekSubs) ?></span>
            <span class="stat-widget-label">This Week</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalUnsub) ?></span>
            <span class="stat-widget-label">Unsubscribed</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon purple">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $campaignCount ?></span>
            <span class="stat-widget-label">Campaigns</span>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <a href="newsletter.php?tab=subscribers" class="tab <?= $tab === 'subscribers' ? 'tab-active' : '' ?>">Subscribers</a>
    <a href="newsletter.php?tab=campaigns" class="tab <?= $tab === 'campaigns' ? 'tab-active' : '' ?>">Campaigns</a>
</div>

<?php if ($tab === 'subscribers'): ?>
<!-- Subscribers Tab -->
<div class="filters-bar">
    <form method="GET" class="filters-form">
        <input type="hidden" name="tab" value="subscribers">
        <input type="text" name="search" placeholder="Search email..." value="<?= sanitize($search) ?>" class="filter-input">
        <select name="status" class="filter-select">
            <option value="">All</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="unsubscribed" <?= $filterStatus === 'unsubscribed' ? 'selected' : '' ?>>Unsubscribed</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($search || $filterStatus): ?><a href="newsletter.php?tab=subscribers" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
    <div class="filters-actions">
        <form method="POST" action="api/newsletter.php?action=import_waitlist" style="display:inline" onsubmit="return confirm('Import all waitlist members who are not yet newsletter subscribers?')">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-secondary btn-sm">
                <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
                Import from Waitlist
            </button>
        </form>
        <a href="api/newsletter.php?action=export&status=<?= urlencode($filterStatus) ?>" class="btn btn-secondary btn-sm">
            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            Export CSV
        </a>
        <span class="filters-count"><?= number_format($total) ?> subscribers</span>
    </div>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Subscribed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                    <tr><td colspan="6" class="empty-state">No subscribers yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($subscribers as $i => $sub): ?>
                    <tr>
                        <td class="text-muted"><?= $offset + $i + 1 ?></td>
                        <td><strong><?= sanitize($sub['email']) ?></strong></td>
                        <td>
                            <?php if ($sub['status'] === 'active'): ?>
                            <span class="badge-green">Active</span>
                            <?php else: ?>
                            <span class="badge-red">Unsubscribed</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="source-badge"><?= sanitize($sub['source']) ?></span></td>
                        <td title="<?= $sub['subscribed_at'] ?>"><?= timeAgo($sub['subscribed_at']) ?></td>
                        <td>
                            <form method="POST" action="api/newsletter.php?action=delete_sub" style="display:inline" onsubmit="return confirm('Remove this subscriber?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= $sub['id'] ?>">
                                <button type="submit" class="btn-icon" title="Delete">
                                    <svg viewBox="0 0 20 20" fill="#ef4444" width="16" height="16"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                </button>
                            </form>
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
    <?php if ($page > 1): ?><a href="?tab=subscribers&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>" class="btn btn-secondary btn-sm">Previous</a><?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="?tab=subscribers&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>" class="btn btn-secondary btn-sm">Next</a><?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- Campaigns Tab -->
<div class="page-actions">
    <a href="campaign-edit.php" class="btn btn-primary">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        New Campaign
    </a>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Sent</th>
                        <th>Opens</th>
                        <th>Author</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($campaigns)): ?>
                    <tr><td colspan="7" class="empty-state">No campaigns yet. Create your first one!</td></tr>
                    <?php else: ?>
                    <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td><a href="campaign-edit.php?id=<?= $c['id'] ?>" class="post-link"><?= sanitize($c['subject']) ?></a></td>
                        <td><span class="status-badge post-status-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
                        <td><?= number_format($c['sent_count']) ?></td>
                        <td><?= number_format($c['open_count']) ?></td>
                        <td><?= sanitize($c['author_name']) ?></td>
                        <td><?= timeAgo($c['created_at']) ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="campaign-edit.php?id=<?= $c['id'] ?>" class="btn-icon" title="Edit">
                                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                                </a>
                                <?php if ($c['status'] !== 'sent'): ?>
                                <form method="POST" action="api/email.php?action=send_campaign" style="display:inline" onsubmit="return confirm('Send this campaign to all active subscribers?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn-icon" title="Send Now">
                                        <svg viewBox="0 0 20 20" fill="#22c55e" width="16" height="16"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="api/newsletter.php?action=delete_campaign" style="display:inline" onsubmit="return confirm('Delete this campaign?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn-icon" title="Delete">
                                        <svg viewBox="0 0 20 20" fill="#ef4444" width="16" height="16"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                    </button>
                                </form>
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
<?php endif; ?>

<?php renderFooter(); ?>
