<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_analytics.sql')); } catch (Exception $e) {}

$range = $_GET['range'] ?? '7';
$tab = $_GET['tab'] ?? 'overview';

// Goals management
if (isPost() && ($_GET['action'] ?? '') === 'save_goal') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        require_once 'includes/logger.php';
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $goalType = in_array($_POST['goal_type'] ?? '', ['pageview', 'event', 'duration']) ? $_POST['goal_type'] : 'pageview';
        $goalTarget = sanitize($_POST['goal_target'] ?? '');

        if ($name && $goalTarget) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $db->prepare('UPDATE analytics_goals SET name=?, description=?, goal_type=?, goal_target=? WHERE id=?')
                    ->execute([$name, $description, $goalType, $goalTarget, $id]);
            } else {
                $db->prepare('INSERT INTO analytics_goals (name, description, goal_type, goal_target) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $description, $goalType, $goalTarget]);
            }
            logActivity($_SESSION['admin_id'], $id ? 'update_goal' : 'add_goal', 'analytics');
            setFlash('success', 'Goal saved.');
        }
    }
    redirect('analytics?tab=goals&range=' . $range);
}

if (($_GET['action'] ?? '') === 'delete_goal') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        require_once 'includes/logger.php';
        $db->prepare('DELETE FROM analytics_goals WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'delete_goal', 'analytics', $id);
        setFlash('success', 'Goal deleted.');
    }
    redirect('analytics?tab=goals&range=' . $range);
}

if (($_GET['action'] ?? '') === 'toggle_goal') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) { $db->prepare('UPDATE analytics_goals SET is_active = NOT is_active WHERE id = ?')->execute([$id]); }
    redirect('analytics?tab=goals&range=' . $range);
}

// Fetch goals for the goals tab
$goals = [];
try { $goals = $db->query('SELECT * FROM analytics_goals ORDER BY created_at DESC')->fetchAll(); } catch (Exception $e) {}

$editGoal = null;
$editId = (int)($_GET['edit_goal'] ?? 0);
if ($editId) {
    $stmt = $db->prepare('SELECT * FROM analytics_goals WHERE id = ?');
    $stmt->execute([$editId]);
    $editGoal = $stmt->fetch();
}

// Goal conversion counts
$goalConversions = [];
try {
    $rangeInt = (int)$range;
    foreach ($goals as $g) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM analytics_conversions WHERE goal_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$g['id'], $rangeInt]);
        $goalConversions[$g['id']] = $stmt->fetchColumn();
    }
} catch (Exception $e) {}

// Events summary for events tab
$topEvents = [];
$eventCategories = [];
try {
    $rangeInt = (int)$range;
    $topEvents = $db->query("SELECT event_name, event_category, COUNT(*) as cnt FROM analytics_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$rangeInt} DAY) GROUP BY event_name, event_category ORDER BY cnt DESC LIMIT 20")->fetchAll();
    $eventCategories = $db->query("SELECT event_category, COUNT(*) as cnt FROM analytics_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$rangeInt} DAY) GROUP BY event_category ORDER BY cnt DESC")->fetchAll();
} catch (Exception $e) {}

// UTM data
$utmSources = [];
$utmCampaigns = [];
try {
    $rangeInt = (int)$range;
    $utmSources = $db->query("SELECT utm_source, utm_medium, COUNT(*) as cnt FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$rangeInt} DAY) AND utm_source IS NOT NULL AND utm_source != '' GROUP BY utm_source, utm_medium ORDER BY cnt DESC LIMIT 15")->fetchAll();
    $utmCampaigns = $db->query("SELECT utm_campaign, COUNT(*) as cnt FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$rangeInt} DAY) AND utm_campaign IS NOT NULL AND utm_campaign != '' GROUP BY utm_campaign ORDER BY cnt DESC LIMIT 10")->fetchAll();
} catch (Exception $e) {}

// Real-time (last 5 min)
$realtimeCount = 0;
$realtimePages = [];
try {
    $realtimeCount = $db->query("SELECT COUNT(DISTINCT ip_address) FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
    $realtimePages = $db->query("SELECT page_path, page_title, COUNT(*) as cnt FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) GROUP BY page_path, page_title ORDER BY cnt DESC LIMIT 10")->fetchAll();
} catch (Exception $e) {}

renderHeader('Analytics', 'analytics');
?>

<div class="filters-bar" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div class="filters-form">
        <a href="analytics?tab=<?= $tab ?>&range=1" class="btn <?= $range === '1' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Today</a>
        <a href="analytics?tab=<?= $tab ?>&range=7" class="btn <?= $range === '7' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">7 Days</a>
        <a href="analytics?tab=<?= $tab ?>&range=30" class="btn <?= $range === '30' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">30 Days</a>
        <a href="analytics?tab=<?= $tab ?>&range=90" class="btn <?= $range === '90' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">90 Days</a>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="api/analytics?action=export_csv&range=<?= $range ?>" class="btn btn-secondary btn-sm">
            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            Export CSV
        </a>
        <div style="display:flex;align-items:center;gap:6px;">
            <div style="width:8px;height:8px;border-radius:50%;background:<?= $realtimeCount > 0 ? 'var(--green)' : 'var(--text-muted)' ?>;animation:<?= $realtimeCount > 0 ? 'pulse 2s infinite' : 'none' ?>;"></div>
            <span style="font-size:0.85rem;"><strong><?= $realtimeCount ?></strong> active now</span>
        </div>
    </div>
</div>

<style>@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }</style>

<!-- Stats (loaded via JS) -->
<div class="stats-row" id="statsRow">
    <div class="stat-widget"><div class="stat-widget-icon blue"><svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg></div><div class="stat-widget-data"><span class="stat-widget-value" id="statViews">--</span><span class="stat-widget-label">Page Views</span></div></div>
    <div class="stat-widget"><div class="stat-widget-icon green"><svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg></div><div class="stat-widget-data"><span class="stat-widget-value" id="statVisitors">--</span><span class="stat-widget-label">Unique Visitors</span></div></div>
    <div class="stat-widget"><div class="stat-widget-icon orange"><svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg></div><div class="stat-widget-data"><span class="stat-widget-value" id="statBounce">--</span><span class="stat-widget-label">Bounce Rate</span></div></div>
    <div class="stat-widget"><div class="stat-widget-icon purple"><svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zm6-4a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zm6-3a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg></div><div class="stat-widget-data"><span class="stat-widget-value" id="statAvgDuration">--</span><span class="stat-widget-label">Avg Duration</span></div></div>
</div>

<div class="tabs">
    <a href="analytics?tab=overview&range=<?= $range ?>" class="tab <?= $tab === 'overview' ? 'tab-active' : '' ?>">Overview</a>
    <a href="analytics?tab=realtime&range=<?= $range ?>" class="tab <?= $tab === 'realtime' ? 'tab-active' : '' ?>">Real-Time</a>
    <a href="analytics?tab=events&range=<?= $range ?>" class="tab <?= $tab === 'events' ? 'tab-active' : '' ?>">Events</a>
    <a href="analytics?tab=campaigns&range=<?= $range ?>" class="tab <?= $tab === 'campaigns' ? 'tab-active' : '' ?>">Campaigns</a>
    <a href="analytics?tab=goals&range=<?= $range ?>" class="tab <?= $tab === 'goals' ? 'tab-active' : '' ?>">Goals</a>
    <a href="analytics?tab=conversions&range=<?= $range ?>" class="tab <?= $tab === 'conversions' ? 'tab-active' : '' ?>">Conversions</a>
    <a href="analytics?tab=geo&range=<?= $range ?>" class="tab <?= $tab === 'geo' ? 'tab-active' : '' ?>">Geography</a>
    <a href="analytics?tab=performance&range=<?= $range ?>" class="tab <?= $tab === 'performance' ? 'tab-active' : '' ?>">Performance</a>
    <a href="analytics?tab=comparison&range=<?= $range ?>" class="tab <?= $tab === 'comparison' ? 'tab-active' : '' ?>">Comparison</a>
</div>

<?php if ($tab === 'overview'): ?>
<!-- ==================== OVERVIEW ==================== -->
<!-- Chart -->
<div class="card">
    <div class="card-header"><h2>Traffic Overview</h2></div>
    <div class="card-body">
        <canvas id="trafficChart" height="200"></canvas>
    </div>
</div>

<div class="dashboard-grid">
    <!-- Top Pages -->
    <div class="card">
        <div class="card-header"><h2>Top Pages</h2></div>
        <div class="card-body no-pad">
            <div class="table-wrap">
                <table class="data-table" id="topPagesTable">
                    <thead><tr><th>Page</th><th>Views</th><th>Unique</th></tr></thead>
                    <tbody><tr><td colspan="3" class="empty-state">Loading...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top Referrers -->
    <div class="card">
        <div class="card-header"><h2>Top Referrers</h2></div>
        <div class="card-body no-pad">
            <div class="table-wrap">
                <table class="data-table" id="referrersTable">
                    <thead><tr><th>Source</th><th>Visits</th></tr></thead>
                    <tbody><tr><td colspan="2" class="empty-state">Loading...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <!-- Devices -->
    <div class="card">
        <div class="card-header"><h2>Devices</h2></div>
        <div class="card-body" id="devicesPanel"><p class="empty-state">Loading...</p></div>
    </div>

    <!-- Browsers & OS -->
    <div class="card">
        <div class="card-header"><h2>Browsers & OS</h2></div>
        <div class="card-body" id="browserPanel"><p class="empty-state">Loading...</p></div>
    </div>
</div>

<?php elseif ($tab === 'realtime'): ?>
<!-- ==================== REAL-TIME ==================== -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Active Visitors (Last 5 Minutes)</h2></div>
    <div class="card-body">
        <div style="text-align:center;padding:20px 0;">
            <span style="font-size:3rem;font-weight:700;color:<?= $realtimeCount > 0 ? 'var(--green)' : 'var(--text-muted)' ?>;"><?= $realtimeCount ?></span>
            <p style="font-size:0.9rem;color:var(--text-muted);margin-top:8px;">visitors on site right now</p>
        </div>
    </div>
</div>

<?php if (!empty($realtimePages)): ?>
<div class="card">
    <div class="card-header"><h2>Active Pages</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Page</th><th>Active Viewers</th></tr></thead>
                <tbody>
                    <?php foreach ($realtimePages as $rp): ?>
                    <tr>
                        <td><strong><?= sanitize($rp['page_title'] ?: $rp['page_path']) ?></strong><br><code style="font-size:0.7rem;color:var(--text-muted);"><?= sanitize($rp['page_path']) ?></code></td>
                        <td><span style="font-weight:600;"><?= $rp['cnt'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card"><div class="card-body"><p class="empty-state">No active visitors right now.</p></div></div>
<?php endif; ?>

<p style="font-size:0.8rem;color:var(--text-muted);margin-top:12px;text-align:center;">This page shows a snapshot. Refresh to update.</p>

<?php elseif ($tab === 'events'): ?>
<!-- ==================== EVENTS ==================== -->
<div class="dashboard-grid">
    <div class="card">
        <div class="card-header"><h2>Event Categories</h2></div>
        <div class="card-body">
            <?php if (empty($eventCategories)): ?>
            <p class="empty-state">No events tracked yet. Use the tracking script to send custom events.</p>
            <?php else: ?>
            <?php $maxEvt = max(array_column($eventCategories, 'cnt')) ?: 1; ?>
            <?php foreach ($eventCategories as $ec): ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:4px;">
                    <span><?= sanitize($ec['event_category']) ?></span>
                    <strong><?= number_format($ec['cnt']) ?></strong>
                </div>
                <div style="width:100%;height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
                    <div style="width:<?= round(($ec['cnt'] / $maxEvt) * 100) ?>%;height:100%;background:var(--blue);border-radius:3px;"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Top Events</h2></div>
        <div class="card-body no-pad">
            <?php if (empty($topEvents)): ?>
            <p class="empty-state" style="padding:20px;">No events yet.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Event</th><th>Category</th><th>Count</th></tr></thead>
                    <tbody>
                        <?php foreach ($topEvents as $te): ?>
                        <tr>
                            <td><strong><?= sanitize($te['event_name']) ?></strong></td>
                            <td><span class="badge-blue"><?= sanitize($te['event_category']) ?></span></td>
                            <td><?= number_format($te['cnt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>Tracking Code</h2></div>
    <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:12px;">Use this JavaScript to track custom events:</p>
        <pre style="background:var(--bg-secondary);padding:16px;border-radius:8px;font-size:0.8rem;overflow-x:auto;"><code>// Track a custom event
fetch('<?= APP_URL ?>/admin/api/analytics?action=event', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    event: 'button_click',
    category: 'engagement',
    data: { button: 'waitlist_signup' },
    path: window.location.pathname,
    session_id: localStorage.getItem('_cc_sid')
  })
});</code></pre>
    </div>
</div>

<?php elseif ($tab === 'campaigns'): ?>
<!-- ==================== CAMPAIGNS / UTM ==================== -->
<div class="dashboard-grid">
    <div class="card">
        <div class="card-header"><h2>Traffic Sources (UTM)</h2></div>
        <div class="card-body no-pad">
            <?php if (empty($utmSources)): ?>
            <p class="empty-state" style="padding:20px;">No UTM-tagged traffic yet. Add ?utm_source=...&utm_medium=... to your links.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Source</th><th>Medium</th><th>Visits</th></tr></thead>
                    <tbody>
                        <?php foreach ($utmSources as $u): ?>
                        <tr>
                            <td><strong><?= sanitize($u['utm_source']) ?></strong></td>
                            <td><span class="badge-blue"><?= sanitize($u['utm_medium'] ?: '—') ?></span></td>
                            <td><?= number_format($u['cnt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Campaigns</h2></div>
        <div class="card-body no-pad">
            <?php if (empty($utmCampaigns)): ?>
            <p class="empty-state" style="padding:20px;">No campaign data yet.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Campaign</th><th>Visits</th></tr></thead>
                    <tbody>
                        <?php foreach ($utmCampaigns as $c): ?>
                        <tr>
                            <td><strong><?= sanitize($c['utm_campaign']) ?></strong></td>
                            <td><?= number_format($c['cnt']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>UTM Link Builder</h2></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;" id="utmBuilder">
            <div class="form-group" style="margin:0;">
                <label>Source</label>
                <input type="text" id="utmSrc" placeholder="twitter, newsletter" oninput="buildUtm()">
            </div>
            <div class="form-group" style="margin:0;">
                <label>Medium</label>
                <input type="text" id="utmMed" placeholder="social, email, cpc" oninput="buildUtm()">
            </div>
            <div class="form-group" style="margin:0;">
                <label>Campaign</label>
                <input type="text" id="utmCamp" placeholder="launch_2026" oninput="buildUtm()">
            </div>
        </div>
        <div class="form-group" style="margin-top:12px;">
            <label>Generated URL</label>
            <input type="text" id="utmResult" value="<?= APP_URL ?>/" readonly style="font-family:monospace;font-size:0.8rem;" onclick="this.select()">
        </div>
        <button class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('utmResult').value)">Copy URL</button>
    </div>
</div>

<script>
function buildUtm() {
    const base = '<?= rtrim(APP_URL, '/') ?>/';
    const src = document.getElementById('utmSrc').value.trim();
    const med = document.getElementById('utmMed').value.trim();
    const camp = document.getElementById('utmCamp').value.trim();
    let params = [];
    if (src) params.push('utm_source=' + encodeURIComponent(src));
    if (med) params.push('utm_medium=' + encodeURIComponent(med));
    if (camp) params.push('utm_campaign=' + encodeURIComponent(camp));
    document.getElementById('utmResult').value = base + (params.length ? '?' + params.join('&') : '');
}
</script>

<?php elseif ($tab === 'goals'): ?>
<!-- ==================== GOALS ==================== -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2><?= $editGoal ? 'Edit' : 'Create' ?> Goal</h2></div>
    <div class="card-body">
        <form method="POST" action="analytics?action=save_goal&tab=goals&range=<?= $range ?>">
            <?= csrfField() ?>
            <?php if ($editGoal): ?>
            <input type="hidden" name="id" value="<?= $editGoal['id'] ?>">
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:2fr 1fr 2fr;gap:12px;">
                <div class="form-group" style="margin:0;">
                    <label>Goal Name</label>
                    <input type="text" name="name" value="<?= sanitize($editGoal['name'] ?? '') ?>" placeholder="Waitlist signup" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Type</label>
                    <select name="goal_type">
                        <option value="pageview" <?= ($editGoal['goal_type'] ?? '') === 'pageview' ? 'selected' : '' ?>>Page View</option>
                        <option value="event" <?= ($editGoal['goal_type'] ?? '') === 'event' ? 'selected' : '' ?>>Event</option>
                        <option value="duration" <?= ($editGoal['goal_type'] ?? '') === 'duration' ? 'selected' : '' ?>>Duration (sec)</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Target <small style="color:var(--text-muted);">(path, event name, or seconds)</small></label>
                    <input type="text" name="goal_target" value="<?= sanitize($editGoal['goal_target'] ?? '') ?>" placeholder="/contact or waitlist_signup" required>
                </div>
            </div>
            <div class="form-group">
                <label>Description (optional)</label>
                <input type="text" name="description" value="<?= sanitize($editGoal['description'] ?? '') ?>" placeholder="Visitor submits the waitlist form">
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><?= $editGoal ? 'Update' : 'Create' ?> Goal</button>
                <?php if ($editGoal): ?><a href="analytics?tab=goals&range=<?= $range ?>" class="btn btn-ghost">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($goals)): ?>
<div class="card">
    <div class="card-header"><h2>Conversion Goals</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Goal</th><th>Type</th><th>Target</th><th>Conversions (<?= $range ?>d)</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($goals as $g): ?>
                    <tr>
                        <td><strong><?= sanitize($g['name']) ?></strong><?= $g['description'] ? '<br><span style="font-size:0.75rem;color:var(--text-muted);">' . sanitize($g['description']) . '</span>' : '' ?></td>
                        <td><span class="badge-blue"><?= $g['goal_type'] ?></span></td>
                        <td><code style="font-size:0.8rem;"><?= sanitize($g['goal_target']) ?></code></td>
                        <td><strong style="font-size:1.1rem;"><?= number_format($goalConversions[$g['id']] ?? 0) ?></strong></td>
                        <td><?= $g['is_active'] ? '<span class="badge-green">Active</span>' : '<span class="badge-gray">Disabled</span>' ?></td>
                        <td style="white-space:nowrap;">
                            <a href="analytics?tab=goals&range=<?= $range ?>&edit_goal=<?= $g['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                            <a href="analytics?action=toggle_goal&id=<?= $g['id'] ?>&range=<?= $range ?>" class="btn btn-secondary btn-sm"><?= $g['is_active'] ? 'Disable' : 'Enable' ?></a>
                            <a href="analytics?action=delete_goal&id=<?= $g['id'] ?>&range=<?= $range ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this goal?')">Del</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card"><div class="card-body"><p class="empty-state">No goals created yet. Create one above to start tracking conversions.</p></div></div>
<?php endif; ?>

<?php elseif ($tab === 'conversions'): ?>
<!-- ==================== CONVERSIONS ==================== -->
<div class="dashboard-grid" id="conversionsGrid">
    <div class="card"><div class="card-body" style="text-align:center;padding:30px 20px;">
        <span style="font-size:2.5rem;font-weight:700;" id="convWaitlist">--</span>
        <p style="color:var(--text-muted);margin-top:4px;">Waitlist Signups</p>
        <span style="font-size:0.8rem;" id="convWaitlistDelta"></span>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;padding:30px 20px;">
        <span style="font-size:2.5rem;font-weight:700;" id="convContacts">--</span>
        <p style="color:var(--text-muted);margin-top:4px;">Contact Submissions</p>
        <span style="font-size:0.8rem;" id="convContactsDelta"></span>
    </div></div>
    <div class="card"><div class="card-body" style="text-align:center;padding:30px 20px;">
        <span style="font-size:2.5rem;font-weight:700;" id="convSubs">--</span>
        <p style="color:var(--text-muted);margin-top:4px;">Newsletter Subscribes</p>
        <span style="font-size:0.8rem;" id="convSubsDelta"></span>
    </div></div>
</div>
<p style="font-size:0.8rem;color:var(--text-muted);margin-top:12px;">Compared to previous <?= $range ?> day period.</p>

<?php elseif ($tab === 'geo'): ?>
<!-- ==================== GEOGRAPHY ==================== -->
<div class="card">
    <div class="card-header"><h2>Visitors by Country</h2></div>
    <div class="card-body" id="geoPanel"><p class="empty-state">Loading...</p></div>
</div>
<p style="font-size:0.8rem;color:var(--text-muted);margin-top:12px;">Country data is collected from page views. Set the <code>country</code> field via a GeoIP lookup in your tracking script for accurate data.</p>

<?php elseif ($tab === 'performance'): ?>
<!-- ==================== PERFORMANCE ==================== -->
<div class="dashboard-grid">
    <div class="card">
        <div class="card-header"><h2>Avg Time on Page</h2></div>
        <div class="card-body" id="perfAvgPanel"><p class="empty-state">Loading...</p></div>
    </div>
    <div class="card">
        <div class="card-header"><h2>Slowest Pages (Max Duration)</h2></div>
        <div class="card-body" id="perfSlowestPanel"><p class="empty-state">Loading...</p></div>
    </div>
</div>

<?php elseif ($tab === 'comparison'): ?>
<!-- ==================== COMPARISON ==================== -->
<div class="card">
    <div class="card-header"><h2>This Period vs Previous Period (<?= $range ?> days)</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table" id="comparisonTable">
                <thead><tr><th>Metric</th><th>Current Period</th><th>Previous Period</th><th>Change</th></tr></thead>
                <tbody><tr><td colspan="4" class="empty-state">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const range = <?= (int)$range ?>;
const API = 'api/analytics';

function renderBar(label, value, max, color) {
    const pct = max > 0 ? (value / max * 100) : 0;
    return '<div class="analytics-bar"><div class="analytics-bar-header"><span>' + label + '</span><span style="color:' + color + '">' + value + '</span></div><div class="analytics-bar-track"><div class="analytics-bar-fill" style="width:' + pct + '%;background:' + color + '"></div></div></div>';
}

function drawChart(canvas, data) {
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    const w = rect.width, h = rect.height;
    const padding = { top: 20, right: 20, bottom: 40, left: 50 };
    const chartW = w - padding.left - padding.right;
    const chartH = h - padding.top - padding.bottom;

    if (!data.length) {
        ctx.fillStyle = '#555'; ctx.font = '14px Inter'; ctx.textAlign = 'center';
        ctx.fillText('No data yet', w / 2, h / 2); return;
    }

    const maxVal = Math.max(...data.map(d => d.views), 1);
    const barW = Math.max(4, (chartW / data.length) - 4);

    ctx.strokeStyle = 'rgba(255,255,255,0.04)'; ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = padding.top + (chartH / 4) * i;
        ctx.beginPath(); ctx.moveTo(padding.left, y); ctx.lineTo(w - padding.right, y); ctx.stroke();
        ctx.fillStyle = '#555'; ctx.font = '10px Inter'; ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxVal - (maxVal / 4) * i), padding.left - 8, y + 4);
    }

    data.forEach((d, i) => {
        const x = padding.left + (chartW / data.length) * i + 2;
        const barH = (d.views / maxVal) * chartH;
        const y = padding.top + chartH - barH;
        const gradient = ctx.createLinearGradient(x, y, x, y + barH);
        gradient.addColorStop(0, '#4FC3F7'); gradient.addColorStop(1, 'rgba(79,195,247,0.2)');
        ctx.fillStyle = gradient; ctx.fillRect(x, y, barW, barH);

        const visitorH = (d.visitors / maxVal) * chartH;
        const vy = padding.top + chartH - visitorH;
        ctx.beginPath(); ctx.arc(x + barW / 2, vy, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#F97316'; ctx.fill();

        if (data.length <= 31 || i % Math.ceil(data.length / 10) === 0) {
            ctx.fillStyle = '#555'; ctx.font = '9px Inter'; ctx.textAlign = 'center';
            ctx.fillText(new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }), x + barW / 2, h - 8);
        }
    });

    ctx.fillStyle = '#4FC3F7'; ctx.fillRect(w - 140, 8, 10, 10);
    ctx.fillStyle = '#9999aa'; ctx.font = '10px Inter'; ctx.textAlign = 'left'; ctx.fillText('Views', w - 126, 17);
    ctx.beginPath(); ctx.arc(w - 60, 13, 4, 0, Math.PI * 2); ctx.fillStyle = '#F97316'; ctx.fill();
    ctx.fillStyle = '#9999aa'; ctx.fillText('Visitors', w - 52, 17);
}

function formatDuration(seconds) {
    if (seconds < 60) return seconds + 's';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m + 'm ' + s + 's';
}

// Load dashboard stats
fetch(API + '?action=dashboard&range=' + range).then(r => r.json()).then(d => {
    document.getElementById('statViews').textContent = d.total_views.toLocaleString();
    document.getElementById('statVisitors').textContent = d.unique_visitors.toLocaleString();
    document.getElementById('statBounce').textContent = d.bounce_rate + '%';
    document.getElementById('statAvgDuration').textContent = formatDuration(d.avg_duration);

    const devPanel = document.getElementById('devicesPanel');
    if (devPanel) {
        const maxDev = Math.max(...d.devices.map(x => x.cnt), 1);
        const devColors = { desktop: '#4FC3F7', mobile: '#F97316', tablet: '#a855f7' };
        devPanel.innerHTML = d.devices.length ?
            d.devices.map(x => renderBar(x.device_type, x.cnt, maxDev, devColors[x.device_type] || '#4FC3F7')).join('') :
            '<p class="empty-state">No data yet</p>';
    }

    const brPanel = document.getElementById('browserPanel');
    if (brPanel) {
        const maxBr = Math.max(...d.browsers.map(x => x.cnt), 1);
        const brHtml = d.browsers.map(x => renderBar(x.browser, x.cnt, maxBr, '#4FC3F7')).join('');
        const maxOs = Math.max(...d.oses.map(x => x.cnt), 1);
        const osHtml = d.oses.map(x => renderBar(x.os, x.cnt, maxOs, '#F97316')).join('');
        brPanel.innerHTML = (d.browsers.length || d.oses.length) ?
            '<h4 style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px">BROWSERS</h4>' + (brHtml || '<p class="text-muted">No data</p>') +
            '<h4 style="font-size:0.8rem;color:var(--text-muted);margin:20px 0 12px">OS</h4>' + (osHtml || '<p class="text-muted">No data</p>') :
            '<p class="empty-state">No data yet</p>';
    }
});

fetch(API + '?action=pages&range=' + range).then(r => r.json()).then(d => {
    const tbody = document.querySelector('#topPagesTable tbody');
    if (tbody) tbody.innerHTML = d.pages.length ? d.pages.map(p =>
        '<tr><td><strong>' + (p.page_title || p.page_path) + '</strong><br><code style="font-size:0.7rem;color:var(--text-muted)">' + p.page_path + '</code></td><td>' + p.views + '</td><td>' + p.unique_views + '</td></tr>'
    ).join('') : '<tr><td colspan="3" class="empty-state">No data yet</td></tr>';
});

fetch(API + '?action=referrers&range=' + range).then(r => r.json()).then(d => {
    const tbody = document.querySelector('#referrersTable tbody');
    if (tbody) tbody.innerHTML = d.referrers.length ? d.referrers.map(r => {
        let display = r.referrer;
        try { display = new URL(r.referrer).hostname; } catch(e) {}
        return '<tr><td><a href="' + r.referrer + '" target="_blank" style="color:var(--blue)">' + display + '</a></td><td>' + r.cnt + '</td></tr>';
    }).join('') : '<tr><td colspan="2" class="empty-state">No referrer data yet</td></tr>';
});

const chartCanvas = document.getElementById('trafficChart');
if (chartCanvas) {
    fetch(API + '?action=chart&range=' + range).then(r => r.json()).then(d => drawChart(chartCanvas, d.chart));
    window.addEventListener('resize', () => {
        fetch(API + '?action=chart&range=' + range).then(r => r.json()).then(d => drawChart(chartCanvas, d.chart));
    });
}

// Conversions tab
function delta(current, prev) {
    if (prev === 0) return current > 0 ? '<span style="color:var(--green);">+' + current + ' new</span>' : '<span style="color:var(--text-muted);">No change</span>';
    const pct = Math.round(((current - prev) / prev) * 100);
    return pct >= 0 ? '<span style="color:var(--green);">+' + pct + '% vs prev</span>' : '<span style="color:var(--red);">' + pct + '% vs prev</span>';
}

if (document.getElementById('convWaitlist')) {
    fetch(API + '?action=conversions&range=' + range).then(r => r.json()).then(d => {
        document.getElementById('convWaitlist').textContent = d.waitlist;
        document.getElementById('convContacts').textContent = d.contacts;
        document.getElementById('convSubs').textContent = d.subscribers;
        document.getElementById('convWaitlistDelta').innerHTML = delta(d.waitlist, d.prev_waitlist);
        document.getElementById('convContactsDelta').innerHTML = delta(d.contacts, d.prev_contacts);
        document.getElementById('convSubsDelta').innerHTML = delta(d.subscribers, d.prev_subscribers);
    });
}

// Geography tab
if (document.getElementById('geoPanel')) {
    fetch(API + '?action=geo&range=' + range).then(r => r.json()).then(d => {
        const panel = document.getElementById('geoPanel');
        if (!d.countries.length) { panel.innerHTML = '<p class="empty-state">No geographic data yet.</p>'; return; }
        const max = Math.max(...d.countries.map(c => c.cnt));
        panel.innerHTML = d.countries.map(c => renderBar(c.country, c.cnt, max, '#4FC3F7')).join('');
    });
}

// Performance tab
if (document.getElementById('perfAvgPanel')) {
    fetch(API + '?action=performance&range=' + range).then(r => r.json()).then(d => {
        const avg = document.getElementById('perfAvgPanel');
        const slow = document.getElementById('perfSlowestPanel');
        if (!d.avg_load.length) { avg.innerHTML = '<p class="empty-state">No duration data. Duration tracking requires the frontend script.</p>'; }
        else {
            const max = Math.max(...d.avg_load.map(x => x.avg_duration));
            avg.innerHTML = d.avg_load.map(x => renderBar(x.page_path, formatDuration(x.avg_duration) + ' (' + x.views + ' views)', max, x.avg_duration > 30 ? '#F97316' : '#4FC3F7')).join('');
        }
        if (!d.slowest.length) { slow.innerHTML = '<p class="empty-state">No data.</p>'; }
        else {
            const maxS = Math.max(...d.slowest.map(x => x.max_duration));
            slow.innerHTML = d.slowest.map(x => renderBar(x.page_path, formatDuration(x.max_duration), maxS, x.max_duration > 60 ? 'var(--red)' : '#F97316')).join('');
        }
    });
}

// Comparison tab
if (document.getElementById('comparisonTable')) {
    fetch(API + '?action=comparison&range=' + range).then(r => r.json()).then(d => {
        const tbody = document.querySelector('#comparisonTable tbody');
        function compRow(label, cur, prev, fmt) {
            const c = fmt ? fmt(cur) : cur;
            const p = fmt ? fmt(prev) : prev;
            let change = '—';
            if (prev > 0) {
                const pct = Math.round(((cur - prev) / prev) * 100);
                change = pct >= 0 ? '<span style="color:var(--green);">+' + pct + '%</span>' : '<span style="color:var(--red);">' + pct + '%</span>';
            } else if (cur > 0) {
                change = '<span style="color:var(--green);">New</span>';
            }
            return '<tr><td><strong>' + label + '</strong></td><td>' + c + '</td><td>' + p + '</td><td>' + change + '</td></tr>';
        }
        tbody.innerHTML =
            compRow('Page Views', d.current.views, d.previous.views, v => v.toLocaleString()) +
            compRow('Unique Visitors', d.current.visitors, d.previous.visitors, v => v.toLocaleString()) +
            compRow('Bounce Rate', d.current.bounce_rate, d.previous.bounce_rate, v => v + '%') +
            compRow('Avg Duration', d.current.avg_duration, d.previous.avg_duration, formatDuration);
    });
}
</script>

<?php renderFooter(); ?>
