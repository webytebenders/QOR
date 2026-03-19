<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();

// Ensure table exists
try {
    $db->exec(file_get_contents(__DIR__ . '/includes/schema_waitlist.sql'));
} catch (Exception $e) {}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Filters
$search = $_GET['search'] ?? '';
$source = $_GET['source'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

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
if ($dateFrom) {
    $where[] = 'created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = 'created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$stmt = $db->prepare("SELECT COUNT(*) FROM waitlist {$whereSQL}");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Fetch entries
$stmt = $db->prepare("SELECT * FROM waitlist {$whereSQL} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Get unique sources for filter
$sources = $db->query('SELECT DISTINCT source_page FROM waitlist ORDER BY source_page')->fetchAll(PDO::FETCH_COLUMN);

// Stats
$totalAll = $db->query('SELECT COUNT(*) FROM waitlist')->fetchColumn();
$today = $db->query("SELECT COUNT(*) FROM waitlist WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$thisWeek = $db->query("SELECT COUNT(*) FROM waitlist WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$thisMonth = $db->query("SELECT COUNT(*) FROM waitlist WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

renderHeader('Waitlist', 'waitlist');
?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalAll) ?></span>
            <span class="stat-widget-label">Total Signups</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($today) ?></span>
            <span class="stat-widget-label">Today</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($thisWeek) ?></span>
            <span class="stat-widget-label">This Week</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon purple">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zm6-4a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zm6-3a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($thisMonth) ?></span>
            <span class="stat-widget-label">This Month</span>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filters-bar">
    <form method="GET" class="filters-form">
        <input type="text" name="search" placeholder="Search email..." value="<?= sanitize($search) ?>" class="filter-input">
        <select name="source" class="filter-select">
            <option value="">All Sources</option>
            <?php foreach ($sources as $s): ?>
            <option value="<?= sanitize($s) ?>" <?= $source === $s ? 'selected' : '' ?>><?= sanitize($s) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= sanitize($dateFrom) ?>" class="filter-input filter-date" placeholder="From">
        <input type="date" name="to" value="<?= sanitize($dateTo) ?>" class="filter-input filter-date" placeholder="To">
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($search || $source || $dateFrom || $dateTo): ?>
        <a href="waitlist.php" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filters-actions">
        <a href="api/waitlist.php?action=export&search=<?= urlencode($search) ?>&source=<?= urlencode($source) ?>" class="btn btn-secondary btn-sm">
            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            Export CSV
        </a>
        <span class="filters-count"><?= number_format($total) ?> results</span>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Email</th>
                        <th>Source Page</th>
                        <th>IP Address</th>
                        <th>Signed Up</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                    <tr><td colspan="6" class="empty-state">No waitlist signups yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($entries as $i => $entry): ?>
                    <tr>
                        <td class="text-muted"><?= $offset + $i + 1 ?></td>
                        <td><strong><?= sanitize($entry['email']) ?></strong></td>
                        <td><span class="source-badge"><?= sanitize($entry['source_page']) ?></span></td>
                        <td><code><?= sanitize($entry['ip_address']) ?></code></td>
                        <td title="<?= $entry['created_at'] ?>"><?= timeAgo($entry['created_at']) ?></td>
                        <td>
                            <form method="POST" action="api/waitlist.php?action=delete" style="display:inline" onsubmit="return confirm('Delete this entry?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= $entry['id'] ?>">
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
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&source=<?= urlencode($source) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn btn-secondary btn-sm">Previous</a>
    <?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&source=<?= urlencode($source) ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn btn-secondary btn-sm">Next</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
