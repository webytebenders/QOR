<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

// Filters
$filterAdmin = $_GET['admin'] ?? '';
$filterAction = $_GET['action'] ?? '';

$where = [];
$params = [];

if ($filterAdmin) {
    $where[] = 'al.admin_id = ?';
    $params[] = (int)$filterAdmin;
}
if ($filterAction) {
    $where[] = 'al.action = ?';
    $params[] = $filterAction;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$countSQL = "SELECT COUNT(*) FROM activity_log al {$whereSQL}";
$stmt = $db->prepare($countSQL);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Fetch logs
$sql = "SELECT al.*, a.name as admin_name FROM activity_log al JOIN admins a ON al.admin_id = a.id {$whereSQL} ORDER BY al.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get admin list for filter
$admins = $db->query('SELECT id, name FROM admins ORDER BY name')->fetchAll();

// Get unique actions
$actions = $db->query('SELECT DISTINCT action FROM activity_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

renderHeader('Activity Log', 'activity');
?>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <select name="admin" class="filter-select">
            <option value="">All Admins</option>
            <?php foreach ($admins as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $filterAdmin == $a['id'] ? 'selected' : '' ?>><?= sanitize($a['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="action" class="filter-select">
            <option value="">All Actions</option>
            <?php foreach ($actions as $act): ?>
            <option value="<?= sanitize($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>><?= sanitize($act) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($filterAdmin || $filterAction): ?>
        <a href="activity.php" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <span class="filters-count"><?= number_format($total) ?> entries</span>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>IP Address</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="5" class="empty-state">No activity found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-avatar-sm"><?= strtoupper(substr($log['admin_name'], 0, 1)) ?></div>
                                <span><?= sanitize($log['admin_name']) ?></span>
                            </div>
                        </td>
                        <td><span class="action-badge"><?= sanitize($log['action']) ?></span></td>
                        <td><?= $log['target_type'] ? sanitize($log['target_type']) . ($log['target_id'] ? " #{$log['target_id']}" : '') : '—' ?></td>
                        <td><code><?= sanitize($log['ip_address']) ?></code></td>
                        <td title="<?= $log['created_at'] ?>"><?= timeAgo($log['created_at']) ?></td>
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
    <a href="?page=<?= $page - 1 ?>&admin=<?= $filterAdmin ?>&action=<?= $filterAction ?>" class="btn btn-secondary btn-sm">Previous</a>
    <?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page + 1 ?>&admin=<?= $filterAdmin ?>&action=<?= $filterAction ?>" class="btn btn-secondary btn-sm">Next</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
