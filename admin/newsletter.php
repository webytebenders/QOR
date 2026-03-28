<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';
require_once 'includes/logger.php';

requireLogin();

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_subscribers.sql')); } catch (Exception $e) {}

$tab = $_GET['tab'] ?? 'subscribers';

// ===== TAG ACTIONS (form handlers) =====
if (isPost() && ($_GET['action'] ?? '') === 'create_tag') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $name = trim(sanitize($_POST['name'] ?? ''));
        $color = sanitize($_POST['color'] ?? '#4FC3F7');
        if ($name) {
            try {
                $db->prepare('INSERT INTO tags (name, color) VALUES (?, ?)')->execute([$name, $color]);
                logActivity($_SESSION['admin_id'], 'create_tag', 'newsletter');
                setFlash('success', "Tag '{$name}' created.");
            } catch (Exception $e) {
                setFlash('error', 'Tag name already exists.');
            }
        }
    }
    redirect('newsletter.php?tab=tags');
}

if (isPost() && ($_GET['action'] ?? '') === 'update_tag') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim(sanitize($_POST['name'] ?? ''));
        $color = sanitize($_POST['color'] ?? '#4FC3F7');
        if ($id && $name) {
            $db->prepare('UPDATE tags SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $id]);
            logActivity($_SESSION['admin_id'], 'update_tag', 'newsletter', $id);
            setFlash('success', "Tag updated.");
        }
    }
    redirect('newsletter.php?tab=tags');
}

if (($_GET['action'] ?? '') === 'delete_tag' && ($id = (int)($_GET['id'] ?? 0))) {
    $db->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
    logActivity($_SESSION['admin_id'], 'delete_tag', 'newsletter', $id);
    setFlash('success', 'Tag deleted.');
    redirect('newsletter.php?tab=tags');
}

// ===== SEGMENT ACTIONS =====
if (isPost() && ($_GET['action'] ?? '') === 'save_segment') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim(sanitize($_POST['name'] ?? ''));
        $description = sanitize($_POST['description'] ?? '');
        $rules = $_POST['rules'] ?? '{}';

        // Validate rules JSON
        $decoded = json_decode($rules, true);
        if (!$decoded) { setFlash('error', 'Invalid rules JSON.'); redirect('newsletter.php?tab=segments'); }

        if ($name) {
            if ($id) {
                $db->prepare('UPDATE segments SET name = ?, description = ?, rules = ? WHERE id = ?')->execute([$name, $description, $rules, $id]);
            } else {
                $db->prepare('INSERT INTO segments (name, description, rules) VALUES (?, ?, ?)')->execute([$name, $description, $rules]);
            }
            logActivity($_SESSION['admin_id'], $id ? 'update_segment' : 'create_segment', 'newsletter');
            setFlash('success', 'Segment saved.');
        }
    }
    redirect('newsletter.php?tab=segments');
}

if (($_GET['action'] ?? '') === 'delete_segment' && ($id = (int)($_GET['id'] ?? 0))) {
    $db->prepare('DELETE FROM segments WHERE id = ?')->execute([$id]);
    logActivity($_SESSION['admin_id'], 'delete_segment', 'newsletter', $id);
    setFlash('success', 'Segment deleted.');
    redirect('newsletter.php?tab=segments');
}

// ===== INLINE TAG ASSIGN/REMOVE =====
if (isPost() && ($_GET['action'] ?? '') === 'assign_tag') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $subId = (int)($_POST['subscriber_id'] ?? 0);
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($subId && $tagId) {
            try {
                $db->prepare('INSERT INTO subscriber_tags (subscriber_id, tag_id) VALUES (?, ?)')->execute([$subId, $tagId]);
                // Trigger automations (on_tag)
                require_once 'api/newsletter.php';
                if (function_exists('triggerAutomations')) {
                    try { triggerAutomations($db, $subId, 'on_tag', (string)$tagId); } catch (Exception $e) {}
                }
            } catch (Exception $e) {} // already tagged
        }
    }
    redirect('newsletter.php?tab=subscribers&page=' . ($_GET['page'] ?? 1));
}

if (isPost() && ($_GET['action'] ?? '') === 'remove_sub_tag') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $subId = (int)($_POST['subscriber_id'] ?? 0);
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($subId && $tagId) {
            $db->prepare('DELETE FROM subscriber_tags WHERE subscriber_id = ? AND tag_id = ?')->execute([$subId, $tagId]);
        }
    }
    redirect('newsletter.php?tab=subscribers&page=' . ($_GET['page'] ?? 1));
}

// ===== SUBSCRIBERS TAB DATA =====
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$filterStatus = $_GET['status'] ?? '';
$filterTag = (int)($_GET['tag'] ?? 0);
$search = $_GET['search'] ?? '';

$where = [];
$params = [];
$joinSQL = '';
if ($filterStatus) { $where[] = 's.status = ?'; $params[] = $filterStatus; }
if ($search) { $where[] = '(s.email LIKE ? OR s.name LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
if ($filterTag) { $joinSQL = 'JOIN subscriber_tags st_filter ON s.id = st_filter.subscriber_id AND st_filter.tag_id = ' . $filterTag; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmtCount = $db->prepare("SELECT COUNT(DISTINCT s.id) FROM subscribers s {$joinSQL} {$whereSQL}");
$stmtCount->execute($params);
$total = $stmtCount->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT DISTINCT s.* FROM subscribers s {$joinSQL} {$whereSQL} ORDER BY s.subscribed_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$subscribers = $stmt->fetchAll();

// Get tags for each subscriber
$subTags = [];
if (!empty($subscribers)) {
    $subIds = array_column($subscribers, 'id');
    $placeholders = implode(',', array_fill(0, count($subIds), '?'));
    $tagStmt = $db->prepare("SELECT st.subscriber_id, t.id as tag_id, t.name, t.color FROM subscriber_tags st JOIN tags t ON st.tag_id = t.id WHERE st.subscriber_id IN ({$placeholders})");
    $tagStmt->execute($subIds);
    foreach ($tagStmt->fetchAll() as $row) {
        $subTags[$row['subscriber_id']][] = $row;
    }
}

// Stats
$totalActive = $db->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();
$totalUnsub = $db->query("SELECT COUNT(*) FROM subscribers WHERE status = 'unsubscribed'")->fetchColumn();
$thisWeekSubs = $db->query("SELECT COUNT(*) FROM subscribers WHERE subscribed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'active'")->fetchColumn();
$totalUnsub = $db->query("SELECT COUNT(*) FROM subscribers WHERE status = 'unsubscribed'")->fetchColumn();

// List health
$inactiveCount = 0;
try { $inactiveCount = $db->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND (last_opened_at IS NULL AND subscribed_at < DATE_SUB(NOW(), INTERVAL 90 DAY)) OR (last_opened_at IS NOT NULL AND last_opened_at < DATE_SUB(NOW(), INTERVAL 90 DAY))")->fetchColumn(); } catch (Exception $e) {}
$unsubReasons = [];
try { $unsubReasons = $db->query("SELECT unsubscribe_reason, COUNT(*) as cnt FROM subscribers WHERE status = 'unsubscribed' AND unsubscribe_reason IS NOT NULL AND unsubscribe_reason != '' GROUP BY unsubscribe_reason ORDER BY cnt DESC LIMIT 5")->fetchAll(); } catch (Exception $e) {}
$freqBreakdown = [];
try { $freqBreakdown = $db->query("SELECT frequency, COUNT(*) as cnt FROM subscribers WHERE status = 'active' GROUP BY frequency ORDER BY cnt DESC")->fetchAll(); } catch (Exception $e) {}

// All tags (for dropdown + tags tab)
$allTags = $db->query('SELECT t.*, (SELECT COUNT(*) FROM subscriber_tags WHERE tag_id = t.id) as sub_count FROM tags t ORDER BY t.name')->fetchAll();

// All segments
$allSegments = [];
try { $allSegments = $db->query('SELECT * FROM segments ORDER BY name')->fetchAll(); } catch (Exception $e) {}

// Campaigns
$campaigns = $db->query('SELECT c.*, a.name as author_name FROM campaigns c JOIN admins a ON c.author_id = a.id ORDER BY c.created_at DESC')->fetchAll();
$campaignCount = count($campaigns);

// Edit segment data
$editSegment = null;
if (($editSegId = (int)($_GET['edit_segment'] ?? 0))) {
    $s = $db->prepare('SELECT * FROM segments WHERE id = ?');
    $s->execute([$editSegId]);
    $editSegment = $s->fetch();
}

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
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M5.5 2a3.5 3.5 0 101.665 6.58L8.585 10l-1.42 1.42a3.5 3.5 0 101.414 1.414L10 11.414l1.42 1.42a3.5 3.5 0 101.414-1.414L11.414 10l1.42-1.42A3.5 3.5 0 1011.42 7.16L10 8.586 8.58 7.16A3.491 3.491 0 005.5 2z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= count($allTags) ?></span>
            <span class="stat-widget-label">Tags</span>
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
    <a href="newsletter.php?tab=import" class="tab <?= $tab === 'import' ? 'tab-active' : '' ?>">Import</a>
    <a href="newsletter.php?tab=tags" class="tab <?= $tab === 'tags' ? 'tab-active' : '' ?>">Tags</a>
    <a href="newsletter.php?tab=segments" class="tab <?= $tab === 'segments' ? 'tab-active' : '' ?>">Segments</a>
    <a href="newsletter.php?tab=campaigns" class="tab <?= $tab === 'campaigns' ? 'tab-active' : '' ?>">Campaigns</a>
    <a href="newsletter.php?tab=health" class="tab <?= $tab === 'health' ? 'tab-active' : '' ?>">List Health</a>
</div>

<?php if ($tab === 'subscribers'): ?>
<!-- ==================== SUBSCRIBERS ==================== -->
<div class="filters-bar">
    <form method="GET" class="filters-form">
        <input type="hidden" name="tab" value="subscribers">
        <input type="text" name="search" placeholder="Search email or name..." value="<?= sanitize($search) ?>" class="filter-input">
        <select name="status" class="filter-select">
            <option value="">All Status</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="unsubscribed" <?= $filterStatus === 'unsubscribed' ? 'selected' : '' ?>>Unsubscribed</option>
        </select>
        <select name="tag" class="filter-select">
            <option value="">All Tags</option>
            <?php foreach ($allTags as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filterTag === (int)$t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?> (<?= $t['sub_count'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($search || $filterStatus || $filterTag): ?><a href="newsletter.php?tab=subscribers" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
    </form>
    <div class="filters-actions">
        <form method="POST" action="api/newsletter.php?action=import_waitlist" style="display:inline" onsubmit="return confirm('Import all waitlist members who are not yet newsletter subscribers?')">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-secondary btn-sm">Import Waitlist</button>
        </form>
        <a href="api/newsletter.php?action=export&status=<?= urlencode($filterStatus) ?>" class="btn btn-secondary btn-sm">Export CSV</a>
        <span class="filters-count"><?= number_format($total) ?> subscribers</span>
    </div>
</div>

<!-- Bulk tag bar -->
<div class="card" style="margin-bottom:12px;">
    <div class="card-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 20px;">
        <span style="font-size:0.8rem;font-weight:600;">Bulk:</span>
        <select id="bulkTagId" style="min-width:140px;">
            <option value="">Select tag...</option>
            <?php foreach ($allTags as $t): ?>
            <option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary btn-sm" onclick="bulkTag('add')">Tag Selected</button>
        <button class="btn btn-ghost btn-sm" onclick="bulkTag('remove')">Untag Selected</button>
        <span id="bulkResult" style="font-size:0.8rem;"></span>
    </div>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onchange="document.querySelectorAll('.sub-check').forEach(c=>c.checked=this.checked)"></th>
                        <th>Email</th>
                        <th>Tags</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Subscribed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                    <tr><td colspan="7" class="empty-state">No subscribers found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($subscribers as $sub): ?>
                    <tr>
                        <td><input type="checkbox" class="sub-check" value="<?= $sub['id'] ?>"></td>
                        <td><strong><?= sanitize($sub['email']) ?></strong><?= $sub['name'] ? '<br><span style="font-size:0.75rem;color:var(--text-muted);">' . sanitize($sub['name']) . '</span>' : '' ?></td>
                        <td>
                            <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
                                <?php foreach ($subTags[$sub['id']] ?? [] as $st): ?>
                                <span style="display:inline-flex;align-items:center;gap:3px;background:<?= $st['color'] ?>20;color:<?= $st['color'] ?>;padding:2px 8px;border-radius:10px;font-size:0.7rem;font-weight:600;">
                                    <?= sanitize($st['name']) ?>
                                    <form method="POST" action="newsletter.php?action=remove_sub_tag&page=<?= $page ?>" style="display:inline;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="subscriber_id" value="<?= $sub['id'] ?>">
                                        <input type="hidden" name="tag_id" value="<?= $st['tag_id'] ?>">
                                        <button type="submit" style="background:none;border:none;color:<?= $st['color'] ?>;cursor:pointer;font-size:0.7rem;padding:0;line-height:1;" title="Remove tag">&times;</button>
                                    </form>
                                </span>
                                <?php endforeach; ?>
                                <div class="tag-add-dropdown" style="position:relative;display:inline-block;">
                                    <button class="btn-icon" style="width:20px;height:20px;font-size:0.7rem;" onclick="this.nextElementSibling.classList.toggle('show')" title="Add tag">+</button>
                                    <div class="action-dropdown" style="display:none;position:absolute;top:100%;left:0;z-index:10;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;padding:4px;min-width:120px;">
                                        <?php foreach ($allTags as $t):
                                            $alreadyHas = false;
                                            foreach ($subTags[$sub['id']] ?? [] as $st) { if ($st['tag_id'] == $t['id']) { $alreadyHas = true; break; } }
                                            if ($alreadyHas) continue;
                                        ?>
                                        <form method="POST" action="newsletter.php?action=assign_tag&page=<?= $page ?>">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="subscriber_id" value="<?= $sub['id'] ?>">
                                            <input type="hidden" name="tag_id" value="<?= $t['id'] ?>">
                                            <button type="submit" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 8px;font-size:0.8rem;cursor:pointer;border-radius:4px;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='none'">
                                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $t['color'] ?>;margin-right:6px;"></span><?= sanitize($t['name']) ?>
                                            </button>
                                        </form>
                                        <?php endforeach; ?>
                                        <?php if (empty($allTags)): ?>
                                        <span style="font-size:0.75rem;color:var(--text-muted);padding:6px 8px;display:block;">No tags yet</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><?= $sub['status'] === 'active' ? '<span class="badge-green">Active</span>' : '<span class="badge-red">Unsub</span>' ?></td>
                        <td><span class="source-badge"><?= sanitize($sub['source']) ?></span></td>
                        <td title="<?= $sub['subscribed_at'] ?>"><?= timeAgo($sub['subscribed_at']) ?></td>
                        <td>
                            <form method="POST" action="api/newsletter.php?action=delete_sub" style="display:inline" onsubmit="return confirm('Remove this subscriber?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= $sub['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Del</button>
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
    <?php if ($page > 1): ?><a href="?tab=subscribers&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>&tag=<?= $filterTag ?>" class="btn btn-secondary btn-sm">Previous</a><?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="?tab=subscribers&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>&tag=<?= $filterTag ?>" class="btn btn-secondary btn-sm">Next</a><?php endif; ?>
</div>
<?php endif; ?>

<style>.action-dropdown.show { display:block !important; }</style>
<script>
document.addEventListener('click', function(e) {
    if (!e.target.closest('.tag-add-dropdown')) {
        document.querySelectorAll('.tag-add-dropdown .action-dropdown').forEach(d => d.classList.remove('show'));
    }
});

function bulkTag(mode) {
    const tagId = document.getElementById('bulkTagId').value;
    const checked = [...document.querySelectorAll('.sub-check:checked')].map(c => c.value);
    if (!tagId) { alert('Select a tag first.'); return; }
    if (!checked.length) { alert('Select at least one subscriber.'); return; }

    const result = document.getElementById('bulkResult');
    result.textContent = 'Processing...';
    result.style.color = 'var(--text-muted)';

    fetch('api/newsletter.php?action=bulk_tag', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ mode: mode, tag_id: parseInt(tagId), subscriber_ids: checked.map(Number), csrf_token: '<?= generateCSRFToken() ?>' })
    }).then(r => r.json()).then(d => {
        result.textContent = d.success ? d.message : (d.error || 'Failed');
        result.style.color = d.success ? 'var(--green)' : 'var(--red)';
        if (d.success) setTimeout(() => location.reload(), 800);
    });
}
</script>

<?php elseif ($tab === 'import'): ?>
<!-- ==================== CSV IMPORT ==================== -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Import Subscribers from CSV</h2></div>
    <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:16px;">Upload a CSV file with subscriber emails. The first row should be column headers. Supported columns: <code>email</code>, <code>name</code>, <code>source</code>.</p>

        <form id="csvUploadForm" style="margin-bottom:20px;">
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <div class="form-group" style="flex:1;min-width:200px;margin:0;">
                    <label>CSV File</label>
                    <input type="file" id="csvFile" accept=".csv,.txt" required>
                </div>
                <button type="submit" class="btn btn-secondary">Preview</button>
            </div>
        </form>

        <!-- Preview area (populated by JS) -->
        <div id="csvPreview" style="display:none;">
            <div style="border-top:1px solid var(--border);padding-top:20px;">
                <h3 style="font-size:0.9rem;font-weight:600;margin-bottom:16px;">Preview — <span id="csvRowCount">0</span> rows detected</h3>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div class="form-group" style="margin:0;">
                        <label>Email Column</label>
                        <select id="mapEmail" class="filter-select"></select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Name Column (optional)</label>
                        <select id="mapName" class="filter-select"><option value="">— None —</option></select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Source Column (optional)</label>
                        <select id="mapSource" class="filter-select"><option value="">— None —</option></select>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div class="form-group" style="margin:0;">
                        <label>Auto-assign Tag</label>
                        <select id="importTagId">
                            <option value="">— No tag —</option>
                            <?php foreach ($allTags as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Duplicate Handling</label>
                        <select id="dupMode">
                            <option value="skip">Skip existing emails</option>
                            <option value="update">Update name/source if exists</option>
                        </select>
                    </div>
                </div>

                <!-- Sample rows -->
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-body no-pad">
                        <div class="table-wrap">
                            <table class="data-table" id="csvSampleTable">
                                <thead><tr></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:12px;align-items:center;">
                    <button class="btn btn-primary" onclick="runImport()">Import <span id="csvImportCount">0</span> Subscribers</button>
                    <span id="importResult" style="font-size:0.85rem;"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Import from Waitlist -->
<div class="card">
    <div class="card-header"><h2>Other Import Sources</h2></div>
    <div class="card-body">
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <form method="POST" action="api/newsletter.php?action=import_waitlist" style="display:inline" onsubmit="return confirm('Import all waitlist members not yet subscribed?')">
                <?= csrfField() ?>
                <button type="submit" class="btn btn-secondary">Import from Waitlist</button>
            </form>
        </div>
        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">Imports waitlist emails that aren't already newsletter subscribers.</p>
    </div>
</div>

<script>
let csvData = [];
let csvHeaders = [];

document.getElementById('csvUploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const file = document.getElementById('csvFile').files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        const text = e.target.result;
        const lines = text.split(/\r?\n/).filter(l => l.trim());
        if (lines.length < 2) { alert('CSV must have a header row and at least one data row.'); return; }

        csvHeaders = parseCSVLine(lines[0]);
        csvData = lines.slice(1).map(l => parseCSVLine(l)).filter(r => r.length === csvHeaders.length);

        // Populate column mapping dropdowns
        ['mapEmail', 'mapName', 'mapSource'].forEach(id => {
            const sel = document.getElementById(id);
            const keepFirst = id !== 'mapEmail';
            sel.innerHTML = keepFirst ? '<option value="">— None —</option>' : '';
            csvHeaders.forEach((h, i) => {
                const opt = document.createElement('option');
                opt.value = i;
                opt.textContent = h;
                // Auto-detect
                const hl = h.toLowerCase().trim();
                if (id === 'mapEmail' && (hl === 'email' || hl === 'e-mail' || hl === 'email address')) opt.selected = true;
                if (id === 'mapName' && (hl === 'name' || hl === 'full name' || hl === 'first name')) opt.selected = true;
                if (id === 'mapSource' && (hl === 'source' || hl === 'origin')) opt.selected = true;
                sel.appendChild(opt);
            });
        });

        // Show sample rows
        const thead = document.querySelector('#csvSampleTable thead tr');
        thead.innerHTML = csvHeaders.map(h => '<th>' + h + '</th>').join('');
        const tbody = document.querySelector('#csvSampleTable tbody');
        tbody.innerHTML = csvData.slice(0, 5).map(row =>
            '<tr>' + row.map(cell => '<td style="font-size:0.8rem;">' + escHtml(cell) + '</td>').join('') + '</tr>'
        ).join('');
        if (csvData.length > 5) {
            tbody.innerHTML += '<tr><td colspan="' + csvHeaders.length + '" style="color:var(--text-muted);font-size:0.8rem;text-align:center;">...and ' + (csvData.length - 5) + ' more rows</td></tr>';
        }

        document.getElementById('csvRowCount').textContent = csvData.length;
        document.getElementById('csvImportCount').textContent = csvData.length;
        document.getElementById('csvPreview').style.display = 'block';
    };
    reader.readAsText(file);
});

function parseCSVLine(line) {
    const result = [];
    let current = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
        const ch = line[i];
        if (ch === '"') { inQuotes = !inQuotes; }
        else if (ch === ',' && !inQuotes) { result.push(current.trim()); current = ''; }
        else { current += ch; }
    }
    result.push(current.trim());
    return result;
}

function escHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function runImport() {
    const emailCol = parseInt(document.getElementById('mapEmail').value);
    const nameCol = document.getElementById('mapName').value;
    const sourceCol = document.getElementById('mapSource').value;
    const tagId = document.getElementById('importTagId').value;
    const dupMode = document.getElementById('dupMode').value;

    if (isNaN(emailCol)) { alert('Select the email column.'); return; }

    const rows = csvData.map(row => ({
        email: row[emailCol] || '',
        name: nameCol !== '' ? (row[parseInt(nameCol)] || '') : '',
        source: sourceCol !== '' ? (row[parseInt(sourceCol)] || '') : 'csv_import',
    })).filter(r => r.email && r.email.includes('@'));

    if (!rows.length) { alert('No valid emails found.'); return; }

    const result = document.getElementById('importResult');
    result.textContent = 'Importing ' + rows.length + ' rows...';
    result.style.color = 'var(--text-muted)';

    fetch('api/newsletter.php?action=import_csv', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            rows: rows,
            tag_id: tagId ? parseInt(tagId) : null,
            duplicate_mode: dupMode,
            csrf_token: '<?= generateCSRFToken() ?>'
        })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            result.innerHTML = '<span style="color:var(--green);">Done! ' + d.imported + ' imported, ' + d.skipped + ' skipped, ' + d.updated + ' updated.</span>';
        } else {
            result.innerHTML = '<span style="color:var(--red);">' + (d.error || 'Import failed.') + '</span>';
        }
    });
}
</script>

<?php elseif ($tab === 'tags'): ?>
<!-- ==================== TAGS ==================== -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Create Tag</h2></div>
    <div class="card-body">
        <form method="POST" action="newsletter.php?action=create_tag" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <?= csrfField() ?>
            <div class="form-group" style="flex:1;min-width:180px;margin:0;">
                <label>Tag Name</label>
                <input type="text" name="name" placeholder="e.g. early-adopter, investor, vip" required>
            </div>
            <div class="form-group" style="width:80px;margin:0;">
                <label>Color</label>
                <input type="color" name="color" value="#4FC3F7" style="width:100%;height:38px;padding:2px;cursor:pointer;">
            </div>
            <button type="submit" class="btn btn-primary">Create Tag</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>All Tags (<?= count($allTags) ?>)</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Tag</th><th>Subscribers</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($allTags)): ?>
                    <tr><td colspan="4" class="empty-state">No tags yet. Create one above.</td></tr>
                    <?php else: ?>
                    <?php foreach ($allTags as $t): ?>
                    <tr>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:6px;background:<?= $t['color'] ?>20;color:<?= $t['color'] ?>;padding:4px 12px;border-radius:12px;font-size:0.85rem;font-weight:600;">
                                <span style="width:10px;height:10px;border-radius:50%;background:<?= $t['color'] ?>;"></span>
                                <?= sanitize($t['name']) ?>
                            </span>
                        </td>
                        <td><strong><?= number_format($t['sub_count']) ?></strong></td>
                        <td><?= timeAgo($t['created_at']) ?></td>
                        <td style="white-space:nowrap;">
                            <button class="btn btn-secondary btn-sm" onclick="editTag(<?= $t['id'] ?>, '<?= sanitize($t['name']) ?>', '<?= $t['color'] ?>')">Edit</button>
                            <a href="newsletter.php?tab=subscribers&tag=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">View</a>
                            <a href="newsletter.php?action=delete_tag&id=<?= $t['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this tag? It will be removed from all subscribers.')">Del</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Tag Modal -->
<div class="modal" id="editTagModal">
    <div class="modal-overlay" onclick="this.parentElement.classList.remove('show')"></div>
    <div class="modal-content">
        <div class="modal-header"><h3>Edit Tag</h3><button class="modal-close" onclick="this.closest('.modal').classList.remove('show')">&times;</button></div>
        <form method="POST" action="newsletter.php?action=update_tag">
            <?= csrfField() ?>
            <input type="hidden" name="id" id="editTagId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Tag Name</label>
                    <input type="text" name="name" id="editTagName" required>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <input type="color" name="color" id="editTagColor" style="width:60px;height:38px;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function editTag(id, name, color) {
    document.getElementById('editTagId').value = id;
    document.getElementById('editTagName').value = name;
    document.getElementById('editTagColor').value = color;
    document.getElementById('editTagModal').classList.add('show');
}
</script>

<?php elseif ($tab === 'segments'): ?>
<!-- ==================== SEGMENTS ==================== -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2><?= $editSegment ? 'Edit' : 'Create' ?> Segment</h2></div>
    <div class="card-body">
        <form method="POST" action="newsletter.php?action=save_segment" id="segmentForm">
            <?= csrfField() ?>
            <?php if ($editSegment): ?><input type="hidden" name="id" value="<?= $editSegment['id'] ?>"><?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                <div class="form-group" style="margin:0;">
                    <label>Segment Name</label>
                    <input type="text" name="name" value="<?= sanitize($editSegment['name'] ?? '') ?>" placeholder="e.g. Active investors" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Description</label>
                    <input type="text" name="description" value="<?= sanitize($editSegment['description'] ?? '') ?>" placeholder="Optional description">
                </div>
            </div>

            <div class="form-group">
                <label>Match</label>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <label class="checkbox-label"><input type="radio" name="match_type" value="all" <?= ($editSegment ? (json_decode($editSegment['rules'], true)['match'] ?? 'all') : 'all') === 'all' ? 'checked' : '' ?>> ALL conditions (AND)</label>
                    <label class="checkbox-label"><input type="radio" name="match_type" value="any" <?= ($editSegment ? (json_decode($editSegment['rules'], true)['match'] ?? 'all') : 'all') === 'any' ? 'checked' : '' ?>> ANY condition (OR)</label>
                </div>
            </div>

            <div id="conditionsContainer">
                <!-- Conditions added by JS -->
            </div>

            <button type="button" class="btn btn-secondary btn-sm" onclick="addCondition()" style="margin-bottom:16px;">+ Add Condition</button>

            <input type="hidden" name="rules" id="rulesInput">

            <div style="display:flex;gap:8px;align-items:center;">
                <button type="submit" class="btn btn-primary"><?= $editSegment ? 'Update' : 'Create' ?> Segment</button>
                <?php if ($editSegment): ?><a href="newsletter.php?tab=segments" class="btn btn-ghost">Cancel</a><?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="previewSegment()">Preview Count</button>
                <span id="previewCount" style="font-size:0.85rem;font-weight:600;"></span>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($allSegments)): ?>
<div class="card">
    <div class="card-header"><h2>Saved Segments (<?= count($allSegments) ?>)</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Segment</th><th>Rules</th><th>Subscribers</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($allSegments as $seg): ?>
                    <?php $rules = json_decode($seg['rules'], true); ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($seg['name']) ?></strong>
                            <?= $seg['description'] ? '<br><span style="font-size:0.75rem;color:var(--text-muted);">' . sanitize($seg['description']) . '</span>' : '' ?>
                        </td>
                        <td style="font-size:0.8rem;">
                            <span class="badge-blue"><?= ucfirst($rules['match'] ?? 'all') ?></span>
                            <?= count($rules['conditions'] ?? []) ?> condition(s)
                        </td>
                        <td><strong><?= number_format($seg['subscriber_count']) ?></strong></td>
                        <td style="white-space:nowrap;">
                            <a href="newsletter.php?tab=segments&edit_segment=<?= $seg['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                            <a href="newsletter.php?action=delete_segment&id=<?= $seg['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this segment?')">Del</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const allTags = <?= json_encode(array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name']], $allTags)) ?>;
let conditionIndex = 0;

function addCondition(field, operator, value) {
    const container = document.getElementById('conditionsContainer');
    const idx = conditionIndex++;
    const tagOptions = allTags.map(t => '<option value="' + t.id + '"' + (value == t.id ? ' selected' : '') + '>' + t.name + '</option>').join('');

    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap;';
    row.innerHTML = `
        <select class="cond-field" data-idx="${idx}" onchange="updateOperators(this)" style="min-width:120px;">
            <option value="status" ${field === 'status' ? 'selected' : ''}>Status</option>
            <option value="tag" ${field === 'tag' ? 'selected' : ''}>Tag</option>
            <option value="source" ${field === 'source' ? 'selected' : ''}>Source</option>
            <option value="subscribed_at" ${field === 'subscribed_at' ? 'selected' : ''}>Subscribed Date</option>
            <option value="email" ${field === 'email' ? 'selected' : ''}>Email</option>
        </select>
        <select class="cond-op" data-idx="${idx}" style="min-width:120px;">
        </select>
        <div class="cond-value-wrap" data-idx="${idx}" style="flex:1;min-width:140px;"></div>
        <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:1.2rem;">&times;</button>
    `;
    container.appendChild(row);

    // Set operator and value after DOM insertion
    updateOperators(row.querySelector('.cond-field'), operator, value);
}

function updateOperators(fieldSelect, presetOp, presetVal) {
    const idx = fieldSelect.dataset.idx;
    const field = fieldSelect.value;
    const opSelect = document.querySelector(`.cond-op[data-idx="${idx}"]`);
    const valWrap = document.querySelector(`.cond-value-wrap[data-idx="${idx}"]`);

    let ops = [];
    let valHtml = '';

    if (field === 'status') {
        ops = [['equals', 'is'], ['not_equals', 'is not']];
        valHtml = '<select class="cond-val"><option value="active"' + (presetVal === 'active' ? ' selected' : '') + '>Active</option><option value="unsubscribed"' + (presetVal === 'unsubscribed' ? ' selected' : '') + '>Unsubscribed</option></select>';
    } else if (field === 'tag') {
        ops = [['has', 'has tag'], ['not_has', 'does not have tag']];
        const tagOpts = allTags.map(t => '<option value="' + t.id + '"' + (presetVal == t.id ? ' selected' : '') + '>' + t.name + '</option>').join('');
        valHtml = '<select class="cond-val">' + tagOpts + '</select>';
    } else if (field === 'source') {
        ops = [['equals', 'is'], ['not_equals', 'is not'], ['contains', 'contains']];
        valHtml = '<input type="text" class="cond-val" value="' + (presetVal || '') + '" placeholder="homepage, blog, waitlist">';
    } else if (field === 'subscribed_at') {
        ops = [['after', 'after'], ['before', 'before']];
        valHtml = '<input type="date" class="cond-val" value="' + (presetVal || '') + '">';
    } else if (field === 'email') {
        ops = [['contains', 'contains'], ['not_contains', 'does not contain']];
        valHtml = '<input type="text" class="cond-val" value="' + (presetVal || '') + '" placeholder="@gmail.com">';
    }

    opSelect.innerHTML = ops.map(o => '<option value="' + o[0] + '"' + (presetOp === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('');
    valWrap.innerHTML = valHtml;
}

function buildRules() {
    const match = document.querySelector('input[name="match_type"]:checked').value;
    const conditions = [];
    document.querySelectorAll('.cond-field').forEach(f => {
        const idx = f.dataset.idx;
        const op = document.querySelector(`.cond-op[data-idx="${idx}"]`);
        const val = document.querySelector(`.cond-value-wrap[data-idx="${idx}"] .cond-val`);
        if (f && op && val) {
            conditions.push({ field: f.value, operator: op.value, value: val.value });
        }
    });
    return JSON.stringify({ match, conditions });
}

document.getElementById('segmentForm').addEventListener('submit', function() {
    document.getElementById('rulesInput').value = buildRules();
});

function previewSegment() {
    const rules = buildRules();
    const result = document.getElementById('previewCount');
    result.textContent = 'Counting...';
    result.style.color = 'var(--text-muted)';
    fetch('api/newsletter.php?action=preview_segment', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ rules: JSON.parse(rules), csrf_token: '<?= generateCSRFToken() ?>' })
    }).then(r => r.json()).then(d => {
        result.textContent = d.success ? d.count + ' subscribers match' : 'Error';
        result.style.color = d.success ? 'var(--green)' : 'var(--red)';
    });
}

// Load existing conditions if editing
<?php if ($editSegment):
    $editRules = json_decode($editSegment['rules'], true);
    foreach ($editRules['conditions'] ?? [] as $c):
?>
addCondition('<?= $c['field'] ?>', '<?= $c['operator'] ?>', '<?= $c['value'] ?>');
<?php endforeach; else: ?>
addCondition('status', 'equals', 'active');
<?php endif; ?>
</script>

<?php elseif ($tab === 'campaigns'): ?>
<!-- ==================== CAMPAIGNS ==================== -->
<div class="page-actions" style="gap:8px;">
    <a href="campaign-templates.php" class="btn btn-secondary">Templates</a>
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
                        <th>Audience</th>
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
                    <tr><td colspan="8" class="empty-state">No campaigns yet. Create your first one!</td></tr>
                    <?php else: ?>
                    <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td><a href="campaign-edit.php?id=<?= $c['id'] ?>" class="post-link"><?= sanitize($c['subject']) ?></a></td>
                        <td>
                            <?php if (($c['audience_type'] ?? 'all') === 'all'): ?>
                            <span class="badge-blue">All</span>
                            <?php elseif ($c['audience_type'] === 'segment'): ?>
                            <span class="badge-purple">Segment</span>
                            <?php elseif ($c['audience_type'] === 'tag'): ?>
                            <span class="badge-orange">Tag</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['status'] === 'sending'): ?>
                            <span class="badge-orange" id="sendStatus_<?= $c['id'] ?>">Sending...</span>
                            <div style="width:80px;height:4px;background:var(--border);border-radius:2px;margin-top:4px;"><div id="sendProgress_<?= $c['id'] ?>" style="width:0%;height:100%;background:var(--orange);border-radius:2px;transition:width 0.3s;"></div></div>
                            <?php else: ?>
                            <span class="status-badge post-status-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($c['sent_count']) ?></td>
                        <td><?= number_format($c['open_count']) ?></td>
                        <td><?= sanitize($c['author_name']) ?></td>
                        <td><?= timeAgo($c['created_at']) ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="campaign-edit.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <?php if ($c['status'] === 'sent'): ?>
                                <a href="campaign-stats.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Stats</a>
                                <?php endif; ?>
                                <?php if ($c['status'] !== 'sent'): ?>
                                <form method="POST" action="api/email.php?action=send_campaign" style="display:inline" onsubmit="return confirm('Send this campaign?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Send</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="api/newsletter.php?action=delete_campaign" style="display:inline" onsubmit="return confirm('Delete this campaign?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Del</button>
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
<?php elseif ($tab === 'health'): ?>
<!-- ==================== LIST HEALTH ==================== -->
<?php
$healthScore = 100;
$totalAll = $totalActive + $totalUnsub;
$unsubPct = $totalAll > 0 ? round(($totalUnsub / $totalAll) * 100, 1) : 0;
$inactivePct = $totalActive > 0 ? round(($inactiveCount / $totalActive) * 100, 1) : 0;
if ($unsubPct > 20) $healthScore -= 30; elseif ($unsubPct > 10) $healthScore -= 15;
if ($inactivePct > 40) $healthScore -= 30; elseif ($inactivePct > 20) $healthScore -= 15;
if ($totalActive < 10) $healthScore -= 20;
$healthScore = max(0, $healthScore);
$healthColor = $healthScore >= 70 ? 'var(--green)' : ($healthScore >= 40 ? 'var(--orange)' : 'var(--red)');
?>

<div class="stats-row" style="margin-bottom:0;">
    <div class="stat-widget">
        <div class="stat-widget-icon <?= $healthScore >= 70 ? 'green' : ($healthScore >= 40 ? 'orange' : 'red') ?>">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value" style="color:<?= $healthColor ?>;"><?= $healthScore ?>%</span>
            <span class="stat-widget-label">Health Score</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($inactiveCount) ?></span>
            <span class="stat-widget-label">Inactive (90d)</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon red">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $unsubPct ?>%</span>
            <span class="stat-widget-label">Unsub Rate</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalAll) ?></span>
            <span class="stat-widget-label">Total All Time</span>
        </div>
    </div>
</div>

<div class="dashboard-grid" style="margin-top:20px;">
    <!-- Frequency Breakdown -->
    <div class="card">
        <div class="card-header"><h2>Frequency Preferences</h2></div>
        <div class="card-body">
            <?php if (empty($freqBreakdown)): ?>
            <p class="empty-state">No preference data yet.</p>
            <?php else: ?>
            <?php $maxFreq = max(array_column($freqBreakdown, 'cnt')) ?: 1;
            $freqLabels = ['all' => 'All Emails', 'weekly' => 'Weekly Digest', 'monthly' => 'Monthly Digest', 'important' => 'Important Only'];
            foreach ($freqBreakdown as $fb): $pct = round(($fb['cnt'] / $maxFreq) * 100); ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:4px;">
                    <span><?= $freqLabels[$fb['frequency']] ?? $fb['frequency'] ?></span>
                    <strong><?= number_format($fb['cnt']) ?></strong>
                </div>
                <div style="width:100%;height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
                    <div style="width:<?= $pct ?>%;height:100%;background:var(--blue);border-radius:3px;"></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Unsubscribe Reasons -->
    <div class="card">
        <div class="card-header"><h2>Unsubscribe Reasons</h2></div>
        <div class="card-body">
            <?php if (empty($unsubReasons)): ?>
            <p class="empty-state">No unsubscribe reason data yet.</p>
            <?php else: ?>
            <?php $maxR = max(array_column($unsubReasons, 'cnt')) ?: 1;
            $reasonLabels = ['too_many' => 'Too many emails', 'not_relevant' => 'Not relevant', 'never_signed_up' => 'Never signed up', 'other' => 'Other'];
            foreach ($unsubReasons as $ur): $pct = round(($ur['cnt'] / $maxR) * 100); ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:4px;">
                    <span><?= $reasonLabels[$ur['unsubscribe_reason']] ?? sanitize($ur['unsubscribe_reason']) ?></span>
                    <strong><?= number_format($ur['cnt']) ?></strong>
                </div>
                <div style="width:100%;height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
                    <div style="width:<?= $pct ?>%;height:100%;background:var(--red);border-radius:3px;"></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Recommendations -->
<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>Recommendations</h2></div>
    <div class="card-body">
        <div style="display:flex;flex-direction:column;gap:12px;font-size:0.85rem;">
            <?php if ($inactivePct > 20): ?>
            <div style="display:flex;align-items:start;gap:8px;">
                <span style="color:var(--orange);font-size:1.2rem;">&#9888;</span>
                <div><strong><?= $inactivePct ?>% of subscribers are inactive</strong> (no opens in 90 days). Consider running a re-engagement campaign or cleaning your list.</div>
            </div>
            <?php endif; ?>
            <?php if ($unsubPct > 10): ?>
            <div style="display:flex;align-items:start;gap:8px;">
                <span style="color:var(--red);font-size:1.2rem;">&#9888;</span>
                <div><strong>Unsubscribe rate is <?= $unsubPct ?>%</strong>. Review your email frequency and content relevance.</div>
            </div>
            <?php endif; ?>
            <?php if ($healthScore >= 70): ?>
            <div style="display:flex;align-items:start;gap:8px;">
                <span style="color:var(--green);font-size:1.2rem;">&#10003;</span>
                <div><strong>Your list looks healthy!</strong> Keep sending valuable content and monitoring engagement.</div>
            </div>
            <?php endif; ?>
            <div style="display:flex;align-items:start;gap:8px;">
                <span style="color:var(--blue);font-size:1.2rem;">&#9432;</span>
                <div>Preference center link is included in all emails. Subscribers can manage their frequency and topics at: <code style="font-size:0.8rem;"><?= APP_URL ?>/admin/api/preferences.php?token=...</code></div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php
// Progress polling for sending campaigns
$sendingCampaigns = array_filter($campaigns, fn($c) => $c['status'] === 'sending');
if (!empty($sendingCampaigns) && $tab === 'campaigns'):
?>
<script>
(function pollProgress() {
    const ids = [<?= implode(',', array_column($sendingCampaigns, 'id')) ?>];
    ids.forEach(id => {
        fetch('api/email.php?action=send_progress&campaign_id=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            const pct = d.total > 0 ? Math.round((d.sent / d.total) * 100) : 0;
            const bar = document.getElementById('sendProgress_' + id);
            const label = document.getElementById('sendStatus_' + id);
            if (bar) bar.style.width = pct + '%';
            if (label) label.textContent = 'Sending ' + d.sent + '/' + d.total;
            if (d.status === 'sent') { location.reload(); }
        });
    });
    if (ids.length) setTimeout(pollProgress, 3000);
})();
</script>
<?php endif; ?>

<?php renderFooter(); ?>
