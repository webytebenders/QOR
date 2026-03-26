<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();

// Ensure table + migration
$schema = file_get_contents(__DIR__ . '/includes/schema_waitlist.sql');
foreach (explode(';', $schema) as $sql) {
    $sql = trim($sql);
    if ($sql) {
        try { $db->exec($sql); } catch (Exception $e) {}
    }
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Filters
$search = $_GET['search'] ?? '';
$source = $_GET['source'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

$where = [];
$params = [];

if ($search) {
    $where[] = '(email LIKE ? OR notes LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($source) {
    $where[] = 'source_page = ?';
    $params[] = $source;
}
if ($status) {
    $where[] = 'status = ?';
    $params[] = $status;
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

// Status counts
$statusCounts = [];
$scRows = $db->query("SELECT status, COUNT(*) as cnt FROM waitlist GROUP BY status")->fetchAll();
foreach ($scRows as $r) { $statusCounts[$r['status']] = $r['cnt']; }

// Build query string for links
$qs = http_build_query(array_filter(['search' => $search, 'source' => $source, 'status' => $status, 'from' => $dateFrom, 'to' => $dateTo]));

renderHeader('Waitlist', 'waitlist');
?>

<!-- Stats -->
<div class="stats-row">
    <a href="waitlist.php" class="stat-widget stat-clickable <?= !$status ? 'stat-active' : '' ?>">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalAll) ?></span>
            <span class="stat-widget-label">Total Signups</span>
        </div>
    </a>
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

<!-- Status Filter Pills -->
<div class="status-pills">
    <a href="waitlist.php" class="status-pill <?= !$status ? 'active' : '' ?>">All <span class="pill-count"><?= $totalAll ?></span></a>
    <a href="?status=new" class="status-pill pill-new <?= $status === 'new' ? 'active' : '' ?>">New <span class="pill-count"><?= $statusCounts['new'] ?? 0 ?></span></a>
    <a href="?status=contacted" class="status-pill pill-contacted <?= $status === 'contacted' ? 'active' : '' ?>">Contacted <span class="pill-count"><?= $statusCounts['contacted'] ?? 0 ?></span></a>
    <a href="?status=qualified" class="status-pill pill-qualified <?= $status === 'qualified' ? 'active' : '' ?>">Qualified <span class="pill-count"><?= $statusCounts['qualified'] ?? 0 ?></span></a>
    <a href="?status=ready" class="status-pill pill-ready <?= $status === 'ready' ? 'active' : '' ?>">Ready <span class="pill-count"><?= $statusCounts['ready'] ?? 0 ?></span></a>
    <a href="?status=converted" class="status-pill pill-converted <?= $status === 'converted' ? 'active' : '' ?>">Converted <span class="pill-count"><?= $statusCounts['converted'] ?? 0 ?></span></a>
</div>

<!-- Filters -->
<div class="filters-bar">
    <form method="GET" class="filters-form">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= sanitize($status) ?>"><?php endif; ?>
        <input type="text" name="search" placeholder="Search email or notes..." value="<?= sanitize($search) ?>" class="filter-input">
        <select name="source" class="filter-select">
            <option value="">All Sources</option>
            <?php foreach ($sources as $s): ?>
            <option value="<?= sanitize($s) ?>" <?= $source === $s ? 'selected' : '' ?>><?= sanitize($s) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= sanitize($dateFrom) ?>" class="filter-input filter-date" placeholder="From">
        <input type="date" name="to" value="<?= sanitize($dateTo) ?>" class="filter-input filter-date" placeholder="To">
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($search || $source || $dateFrom || $dateTo || $status): ?>
        <a href="waitlist.php" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <div class="filters-actions">
        <a href="api/waitlist.php?action=export&search=<?= urlencode($search) ?>&source=<?= urlencode($source) ?>&status=<?= urlencode($status) ?>" class="btn btn-secondary btn-sm">
            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            Export CSV
        </a>
        <span class="filters-count"><?= number_format($total) ?> results</span>
    </div>
</div>

<!-- Bulk Actions Bar (hidden until selection) -->
<div class="bulk-bar" id="bulkBar" style="display:none;">
    <span class="bulk-bar-count"><span id="bulkCount">0</span> selected</span>
    <div class="bulk-bar-actions">
        <select id="bulkStatusSelect" class="filter-select">
            <option value="">Change Status...</option>
            <option value="new">New</option>
            <option value="contacted">Contacted</option>
            <option value="qualified">Qualified</option>
            <option value="ready">Ready</option>
            <option value="converted">Converted</option>
        </select>
        <button type="button" class="btn btn-secondary btn-sm" onclick="bulkChangeStatus()">Apply Status</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="bulkDelete()">Delete Selected</button>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                        <th>#</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Source Page</th>
                        <th>Notes</th>
                        <th>Signed Up</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                    <tr><td colspan="8" class="empty-state">No waitlist signups yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($entries as $i => $entry): ?>
                    <tr data-id="<?= $entry['id'] ?>">
                        <td><input type="checkbox" class="row-check" value="<?= $entry['id'] ?>" onchange="updateBulkBar()"></td>
                        <td class="text-muted"><?= $offset + $i + 1 ?></td>
                        <td><strong><?= sanitize($entry['email']) ?></strong></td>
                        <td>
                            <span class="wl-status-badge wl-status-<?= $entry['status'] ?? 'new' ?>"><?= ucfirst($entry['status'] ?? 'new') ?></span>
                        </td>
                        <td><span class="source-badge"><?= sanitize($entry['source_page']) ?></span></td>
                        <td>
                            <?php if (!empty($entry['notes'])): ?>
                            <span class="notes-preview" title="<?= sanitize($entry['notes']) ?>"><?= sanitize(mb_substr($entry['notes'], 0, 30)) ?><?= mb_strlen($entry['notes']) > 30 ? '...' : '' ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td title="<?= $entry['created_at'] ?>"><?= timeAgo($entry['created_at']) ?></td>
                        <td>
                            <div class="action-dropdown">
                                <button class="btn-icon" onclick="toggleDropdown(this)" title="Actions">
                                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                                </button>
                                <div class="dropdown-menu">
                                    <button class="dropdown-item" onclick="openNotesModal(<?= $entry['id'] ?>, <?= htmlspecialchars(json_encode($entry['email']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($entry['notes'] ?? ''), ENT_QUOTES) ?>)">
                                        <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                                        Notes
                                    </button>
                                    <div class="dropdown-divider"></div>
                                    <span class="dropdown-label">Change Status</span>
                                    <?php foreach (['new','contacted','qualified','ready','converted'] as $s): ?>
                                    <form method="POST" action="api/waitlist.php?action=update_status" style="display:contents">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="id" value="<?= $entry['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $s ?>">
                                        <button type="submit" class="dropdown-item <?= ($entry['status'] ?? 'new') === $s ? 'dropdown-item-active' : '' ?>">
                                            <span class="wl-dot wl-dot-<?= $s ?>"></span> <?= ucfirst($s) ?>
                                        </button>
                                    </form>
                                    <?php endforeach; ?>
                                    <div class="dropdown-divider"></div>
                                    <form method="POST" action="api/waitlist.php?action=resend_email" style="display:contents">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="id" value="<?= $entry['id'] ?>">
                                        <button type="submit" class="dropdown-item">
                                            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
                                            Resend Welcome Email
                                        </button>
                                    </form>
                                    <div class="dropdown-divider"></div>
                                    <form method="POST" action="api/waitlist.php?action=delete" style="display:contents" onsubmit="return confirm('Delete this entry?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="id" value="<?= $entry['id'] ?>">
                                        <button type="submit" class="dropdown-item dropdown-item-danger">
                                            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                            Delete
                                        </button>
                                    </form>
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

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page - 1 ?>&<?= $qs ?>" class="btn btn-secondary btn-sm">Previous</a>
    <?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page + 1 ?>&<?= $qs ?>" class="btn btn-secondary btn-sm">Next</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Notes Modal -->
<div class="modal" id="notesModal">
    <div class="modal-overlay" onclick="closeNotesModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Notes — <span id="notesEmail"></span></h3>
            <button class="modal-close" onclick="closeNotesModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Notes</label>
                <textarea id="notesText" rows="5" placeholder="Add notes about this waitlist entry..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeNotesModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveNotes()">Save Notes</button>
        </div>
    </div>
</div>

<!-- Hidden forms for bulk actions -->
<form method="POST" action="api/waitlist.php?action=bulk_delete" id="bulkDeleteForm" style="display:none">
    <?= csrfField() ?>
    <div id="bulkDeleteIds"></div>
</form>
<form method="POST" action="api/waitlist.php?action=bulk_status" id="bulkStatusForm" style="display:none">
    <?= csrfField() ?>
    <div id="bulkStatusIds"></div>
    <input type="hidden" name="status" id="bulkStatusValue">
</form>

<script>
// Dropdown toggle
function toggleDropdown(btn) {
    // Close all others
    document.querySelectorAll('.dropdown-menu.show').forEach(m => {
        if (m !== btn.nextElementSibling) m.classList.remove('show');
    });
    btn.nextElementSibling.classList.toggle('show');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.action-dropdown')) {
        document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    }
});

// Bulk selection
function toggleSelectAll(el) {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = el.checked);
    updateBulkBar();
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.row-check:checked');
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked.length;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';

    // Update "select all" checkbox
    const all = document.querySelectorAll('.row-check');
    document.getElementById('selectAll').checked = all.length > 0 && checked.length === all.length;
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
}

function bulkDelete() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' entries? This cannot be undone.')) return;

    const container = document.getElementById('bulkDeleteIds');
    container.innerHTML = '';
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = 'ids[]'; input.value = id;
        container.appendChild(input);
    });
    document.getElementById('bulkDeleteForm').submit();
}

function bulkChangeStatus() {
    const ids = getSelectedIds();
    const status = document.getElementById('bulkStatusSelect').value;
    if (!ids.length || !status) { alert('Select entries and a status.'); return; }

    const container = document.getElementById('bulkStatusIds');
    container.innerHTML = '';
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = 'ids[]'; input.value = id;
        container.appendChild(input);
    });
    document.getElementById('bulkStatusValue').value = status;
    document.getElementById('bulkStatusForm').submit();
}

// Notes modal
let currentNotesId = null;

function openNotesModal(id, email, notes) {
    currentNotesId = id;
    document.getElementById('notesEmail').textContent = email;
    document.getElementById('notesText').value = notes || '';
    document.getElementById('notesModal').classList.add('show');
}

function closeNotesModal() {
    document.getElementById('notesModal').classList.remove('show');
    currentNotesId = null;
}

function saveNotes() {
    if (!currentNotesId) return;
    const notes = document.getElementById('notesText').value;
    const csrf = document.querySelector('input[name="<?= CSRF_TOKEN_NAME ?>"]').value;

    const body = new FormData();
    body.append('id', currentNotesId);
    body.append('notes', notes);
    body.append('<?= CSRF_TOKEN_NAME ?>', csrf);

    fetch('api/waitlist.php?action=save_notes', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeNotesModal();
                location.reload();
            } else {
                alert(data.message || 'Failed to save notes.');
            }
        })
        .catch(() => alert('Network error. Please try again.'));
}
</script>

<?php renderFooter(); ?>
