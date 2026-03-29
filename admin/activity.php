<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPageRaw = (int)($_GET['per_page'] ?? 0);
$perPage = in_array($perPageRaw, [10, 25, 50, 100]) ? $perPageRaw : 25;
$offset = ($page - 1) * $perPage;

// Filters
$filterAdmin = $_GET['admin'] ?? '';
$filterAction = $_GET['action_filter'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');
$filterRange = $_GET['range'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

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
if ($filterSearch) {
    $where[] = '(al.action LIKE ? OR al.target_type LIKE ? OR al.details LIKE ? OR a.name LIKE ?)';
    $searchLike = "%{$filterSearch}%";
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
}
if ($filterRange === '1') {
    $where[] = 'DATE(al.created_at) = CURDATE()';
} elseif ($filterRange === '7') {
    $where[] = 'al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($filterRange === '30') {
    $where[] = 'al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
} elseif ($filterRange === 'custom' && $filterDateFrom) {
    $where[] = 'al.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
    if ($filterDateTo) {
        $where[] = 'al.created_at <= ?';
        $params[] = $filterDateTo . ' 23:59:59';
    }
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$stmt = $db->prepare("SELECT COUNT(*) FROM activity_log al JOIN admins a ON al.admin_id = a.id {$whereSQL}");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Fetch logs
$stmt = $db->prepare("SELECT al.*, a.name as admin_name FROM activity_log al JOIN admins a ON al.admin_id = a.id {$whereSQL} ORDER BY al.created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get admin list for filter
$adminList = $db->query('SELECT id, name FROM admins ORDER BY name')->fetchAll();

// Get unique actions
$actions = $db->query('SELECT DISTINCT action FROM activity_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

// Stats
$todayCount = $db->query("SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$totalAll = $db->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();

$mostActiveAdmin = $db->query("SELECT a.name, COUNT(*) as cnt FROM activity_log al JOIN admins a ON al.admin_id = a.id WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY al.admin_id ORDER BY cnt DESC LIMIT 1")->fetch();

$mostCommonAction = $db->query("SELECT action, COUNT(*) as cnt FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY action ORDER BY cnt DESC LIMIT 1")->fetch();

// Action color map
function getActionColor(string $action): string {
    if (str_starts_with($action, 'create') || str_starts_with($action, 'add') || str_starts_with($action, 'upload')) return 'green';
    if (str_starts_with($action, 'update') || str_starts_with($action, 'edit') || str_starts_with($action, 'toggle')) return 'blue';
    if (str_starts_with($action, 'delete') || str_starts_with($action, 'purge') || str_starts_with($action, 'remove')) return 'red';
    if ($action === 'login' || $action === 'logout') return 'purple';
    if (str_starts_with($action, 'export') || str_starts_with($action, 'backup')) return 'orange';
    if (str_starts_with($action, 'send') || str_starts_with($action, 'test')) return 'blue';
    if (str_starts_with($action, 'reset') || str_starts_with($action, 'force')) return 'orange';
    return 'gray';
}

// Build query string for pagination
function buildQS(array $overrides = []): string {
    $params = array_merge([
        'admin' => $_GET['admin'] ?? '',
        'action_filter' => $_GET['action_filter'] ?? '',
        'search' => $_GET['search'] ?? '',
        'range' => $_GET['range'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'per_page' => $_GET['per_page'] ?? '',
    ], $overrides);
    return http_build_query(array_filter($params, fn($v) => $v !== ''));
}

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $db->prepare("SELECT al.*, a.name as admin_name FROM activity_log al JOIN admins a ON al.admin_id = a.id {$whereSQL} ORDER BY al.created_at DESC");
    $stmt->execute($params);
    $allLogs = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Admin', 'Action', 'Target Type', 'Target ID', 'Details', 'IP Address', 'Timestamp']);
    foreach ($allLogs as $l) {
        fputcsv($out, [$l['id'], $l['admin_name'], $l['action'], $l['target_type'], $l['target_id'], $l['details'], $l['ip_address'], $l['created_at']]);
    }
    fclose($out);
    exit;
}

renderHeader('Activity Log', 'activity');
?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalAll) ?></span>
            <span class="stat-widget-label">Total Entries</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($todayCount) ?></span>
            <span class="stat-widget-label">Today</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $mostActiveAdmin ? sanitize($mostActiveAdmin['name']) : '—' ?></span>
            <span class="stat-widget-label">Most Active (7d)</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon purple">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $mostCommonAction ? sanitize($mostCommonAction['action']) : '—' ?></span>
            <span class="stat-widget-label">Top Action (7d)</span>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body">
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:0.75rem;">Admin</label>
                <select name="admin" class="filter-select">
                    <option value="">All Admins</option>
                    <?php foreach ($adminList as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $filterAdmin == $a['id'] ? 'selected' : '' ?>><?= sanitize($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:0.75rem;">Action</label>
                <select name="action_filter" class="filter-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $act): ?>
                    <option value="<?= sanitize($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>><?= sanitize($act) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:0.75rem;">Date Range</label>
                <select name="range" class="filter-select" onchange="toggleCustomDate(this.value)">
                    <option value="">All Time</option>
                    <option value="1" <?= $filterRange === '1' ? 'selected' : '' ?>>Today</option>
                    <option value="7" <?= $filterRange === '7' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $filterRange === '30' ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="custom" <?= $filterRange === 'custom' ? 'selected' : '' ?>>Custom</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:120px;<?= $filterRange !== 'custom' ? 'display:none;' : '' ?>" id="customDateFrom">
                <label style="font-size:0.75rem;">From</label>
                <input type="date" name="date_from" value="<?= sanitize($filterDateFrom) ?>">
            </div>
            <div class="form-group" style="margin:0;min-width:120px;<?= $filterRange !== 'custom' ? 'display:none;' : '' ?>" id="customDateTo">
                <label style="font-size:0.75rem;">To</label>
                <input type="date" name="date_to" value="<?= sanitize($filterDateTo) ?>">
            </div>
            <div class="form-group" style="margin:0;min-width:80px;">
                <label style="font-size:0.75rem;">Per Page</label>
                <select name="per_page" class="filter-select">
                    <?php foreach ([10, 25, 50, 100] as $pp): ?>
                    <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:160px;">
                <label style="font-size:0.75rem;">Search</label>
                <input type="text" name="search" value="<?= sanitize($filterSearch) ?>" placeholder="Search actions, targets, details...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($filterAdmin || $filterAction || $filterSearch || $filterRange): ?>
            <a href="activity" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
            <a href="activity?<?= buildQS(['export' => 'csv']) ?>" class="btn btn-secondary btn-sm" title="Export filtered results">
                <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                CSV
            </a>
        </form>
    </div>
</div>

<div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:8px;"><?= number_format($total) ?> entries<?= ($filterAdmin || $filterAction || $filterSearch || $filterRange) ? ' (filtered)' : '' ?></div>

<!-- Activity Table -->
<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>IP Address</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="6" class="empty-state">No activity found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <?php
                        $color = getActionColor($log['action']);
                        $details = $log['details'] ? json_decode($log['details'], true) : null;
                        $hasDetails = !empty($details);
                    ?>
                    <tr class="<?= $hasDetails ? 'expandable-row' : '' ?>" <?= $hasDetails ? 'onclick="toggleDetails(this)" style="cursor:pointer;"' : '' ?>>
                        <td>
                            <div class="user-cell">
                                <div class="user-avatar-sm"><?= strtoupper(substr($log['admin_name'], 0, 1)) ?></div>
                                <span><?= sanitize($log['admin_name']) ?></span>
                            </div>
                        </td>
                        <td><span class="badge-<?= $color ?>"><?= sanitize($log['action']) ?></span></td>
                        <td><?= $log['target_type'] ? sanitize($log['target_type']) . ($log['target_id'] ? ' <code>#' . $log['target_id'] . '</code>' : '') : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if ($hasDetails): ?>
                            <span style="font-size:0.75rem;color:var(--blue);cursor:pointer;" title="Click row to expand">
                                <?php
                                // Show brief summary
                                $brief = [];
                                foreach (array_slice($details, 0, 2) as $k => $v) {
                                    $brief[] = $k . ': ' . (is_string($v) ? substr($v, 0, 20) : $v);
                                }
                                echo sanitize(implode(', ', $brief));
                                if (count($details) > 2) echo '...';
                                ?>
                                <svg viewBox="0 0 20 20" fill="currentColor" width="12" height="12" style="vertical-align:middle;"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><code style="font-size:0.75rem;"><?= sanitize($log['ip_address']) ?></code></td>
                        <td title="<?= $log['created_at'] ?>" style="white-space:nowrap;"><?= timeAgo($log['created_at']) ?></td>
                    </tr>
                    <?php if ($hasDetails): ?>
                    <tr class="detail-row" style="display:none;">
                        <td colspan="6" style="padding:0 16px 16px;">
                            <div style="background:var(--bg-secondary);border-radius:6px;padding:12px;font-size:0.8rem;">
                                <strong style="color:var(--text-secondary);">Metadata</strong>
                                <div style="margin-top:8px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;">
                                    <?php foreach ($details as $k => $v): ?>
                                    <div>
                                        <span style="color:var(--text-muted);"><?= sanitize($k) ?>:</span>
                                        <span style="color:var(--text-primary);"><?= sanitize(is_array($v) ? json_encode($v) : (string)$v) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Numbered Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination" style="display:flex;align-items:center;gap:4px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
    <?php if ($page > 1): ?>
    <a href="?<?= buildQS(['page' => $page - 1]) ?>" class="btn btn-secondary btn-sm">Prev</a>
    <?php endif; ?>

    <?php
    // Show page numbers with ellipsis
    $start = max(1, $page - 3);
    $end = min($totalPages, $page + 3);

    if ($start > 1): ?>
    <a href="?<?= buildQS(['page' => 1]) ?>" class="btn btn-secondary btn-sm">1</a>
    <?php if ($start > 2): ?><span style="color:var(--text-muted);padding:0 4px;">...</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="?<?= buildQS(['page' => $i]) ?>" class="btn <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
    <?php if ($end < $totalPages - 1): ?><span style="color:var(--text-muted);padding:0 4px;">...</span><?php endif; ?>
    <a href="?<?= buildQS(['page' => $totalPages]) ?>" class="btn btn-secondary btn-sm"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
    <a href="?<?= buildQS(['page' => $page + 1]) ?>" class="btn btn-secondary btn-sm">Next</a>
    <?php endif; ?>

    <span style="font-size:0.8rem;color:var(--text-muted);margin-left:8px;">Page <?= $page ?> of <?= $totalPages ?></span>
</div>
<?php endif; ?>

<script>
function toggleCustomDate(value) {
    document.getElementById('customDateFrom').style.display = value === 'custom' ? '' : 'none';
    document.getElementById('customDateTo').style.display = value === 'custom' ? '' : 'none';
}

function toggleDetails(row) {
    const detailRow = row.nextElementSibling;
    if (detailRow && detailRow.classList.contains('detail-row')) {
        detailRow.style.display = detailRow.style.display === 'none' ? '' : 'none';
    }
}
</script>

<?php renderFooter(); ?>
