<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_subscribers.sql')); } catch (Exception $e) {}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect('newsletter?tab=campaigns'); }

$stmt = $db->prepare('SELECT c.*, a.name as author_name FROM campaigns c JOIN admins a ON c.author_id = a.id WHERE c.id = ?');
$stmt->execute([$id]);
$campaign = $stmt->fetch();
if (!$campaign) { setFlash('error', 'Campaign not found.'); redirect('newsletter?tab=campaigns'); }

// Core stats
$sent = (int)$campaign['sent_count'];
$opened = (int)$campaign['open_count'];
$clicked = (int)$campaign['click_count'];

// Detailed counts from logs
$logStats = $db->prepare("SELECT status, COUNT(*) as cnt FROM campaign_logs WHERE campaign_id = ? GROUP BY status");
$logStats->execute([$id]);
$logCounts = [];
foreach ($logStats->fetchAll() as $r) { $logCounts[$r['status']] = (int)$r['cnt']; }

$totalSent = $logCounts['sent'] ?? 0;
$totalOpened = ($logCounts['opened'] ?? 0) + ($logCounts['clicked'] ?? 0);
$totalClicked = $logCounts['clicked'] ?? 0;
$totalBounced = $logCounts['bounced'] ?? 0;

// Use campaign-level counts if log counts are lower (race condition fallback)
$totalOpened = max($totalOpened, $opened);
$totalClicked = max($totalClicked, $clicked);
$totalSent = max($totalSent, $sent);

$openRate = $totalSent > 0 ? round(($totalOpened / $totalSent) * 100, 1) : 0;
$clickRate = $totalSent > 0 ? round(($totalClicked / $totalSent) * 100, 1) : 0;
$ctoRate = $totalOpened > 0 ? round(($totalClicked / $totalOpened) * 100, 1) : 0;

// Unsubscribed after this campaign (approximate: unsubs within 48h of send)
$unsubCount = 0;
if ($campaign['sent_at']) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM subscribers WHERE status = 'unsubscribed' AND unsubscribed_at BETWEEN ? AND DATE_ADD(?, INTERVAL 48 HOUR)");
    $stmt->execute([$campaign['sent_at'], $campaign['sent_at']]);
    $unsubCount = (int)$stmt->fetchColumn();
}
$unsubRate = $totalSent > 0 ? round(($unsubCount / $totalSent) * 100, 1) : 0;

// Top clicked links
$topLinks = [];
try {
    $topLinks = $db->prepare("SELECT url, COUNT(*) as clicks, COUNT(DISTINCT subscriber_id) as unique_clicks FROM campaign_clicks WHERE campaign_id = ? GROUP BY url ORDER BY clicks DESC LIMIT 10");
    $topLinks->execute([$id]);
    $topLinks = $topLinks->fetchAll();
} catch (Exception $e) {}

// Opens/clicks over time (hourly for first 72h)
$timeline = [];
if ($campaign['sent_at']) {
    try {
        $timeline = $db->prepare("SELECT DATE_FORMAT(opened_at, '%Y-%m-%d %H:00') as hour, COUNT(*) as opens FROM campaign_logs WHERE campaign_id = ? AND opened_at IS NOT NULL AND opened_at <= DATE_ADD(?, INTERVAL 72 HOUR) GROUP BY hour ORDER BY hour");
        $timeline->execute([$id, $campaign['sent_at']]);
        $timeline = $timeline->fetchAll();
    } catch (Exception $e) {}
}

// Subscriber breakdown
$subscriberLogs = $db->prepare("SELECT cl.*, s.email, s.name as sub_name FROM campaign_logs cl JOIN subscribers s ON cl.subscriber_id = s.id WHERE cl.campaign_id = ? ORDER BY cl.sent_at DESC LIMIT 100");
$subscriberLogs->execute([$id]);
$subLogs = $subscriberLogs->fetchAll();

// Audience info
$audienceLabel = 'All Subscribers';
if (($campaign['audience_type'] ?? 'all') === 'segment' && $campaign['audience_id']) {
    $seg = $db->prepare('SELECT name FROM segments WHERE id = ?'); $seg->execute([$campaign['audience_id']]); $segName = $seg->fetchColumn();
    if ($segName) $audienceLabel = "Segment: {$segName}";
} elseif (($campaign['audience_type'] ?? 'all') === 'tag' && $campaign['audience_id']) {
    $tag = $db->prepare('SELECT name FROM tags WHERE id = ?'); $tag->execute([$campaign['audience_id']]); $tagName = $tag->fetchColumn();
    if ($tagName) $audienceLabel = "Tag: {$tagName}";
}

renderHeader('Campaign Stats', 'newsletter');
?>

<div class="msg-back">
    <a href="newsletter?tab=campaigns" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Campaigns
    </a>
</div>

<!-- Campaign Header -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body">
        <h2 style="font-family:var(--font-heading);font-size:1.2rem;margin-bottom:8px;"><?= sanitize($campaign['subject']) ?></h2>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:0.8rem;color:var(--text-muted);">
            <span>By <?= sanitize($campaign['author_name']) ?></span>
            <span>Sent <?= $campaign['sent_at'] ? date('M j, Y g:i A', strtotime($campaign['sent_at'])) : 'Not sent' ?></span>
            <span>Audience: <strong style="color:var(--text-secondary);"><?= sanitize($audienceLabel) ?></strong></span>
            <span class="status-badge post-status-<?= $campaign['status'] ?>"><?= ucfirst($campaign['status']) ?></span>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-row">
    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalSent) ?></span>
            <span class="stat-widget-label">Sent</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalOpened) ?> <small style="font-size:0.7rem;color:var(--text-muted);">(<?= $openRate ?>%)</small></span>
            <span class="stat-widget-label">Opened</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M12.586 4.586a2 2 0 112.828 2.828l-3 3a2 2 0 01-2.828 0 1 1 0 00-1.414 1.414 4 4 0 005.656 0l3-3a4 4 0 00-5.656-5.656l-1.5 1.5a1 1 0 101.414 1.414l1.5-1.5zm-5 5a2 2 0 012.828 0 1 1 0 101.414-1.414 4 4 0 00-5.656 0l-3 3a4 4 0 105.656 5.656l1.5-1.5a1 1 0 10-1.414-1.414l-1.5 1.5a2 2 0 11-2.828-2.828l3-3z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($totalClicked) ?> <small style="font-size:0.7rem;color:var(--text-muted);">(<?= $clickRate ?>%)</small></span>
            <span class="stat-widget-label">Clicked</span>
        </div>
    </div>
    <div class="stat-widget">
        <div class="stat-widget-icon <?= $unsubCount > 0 ? 'red' : 'purple' ?>">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $unsubCount ?> <small style="font-size:0.7rem;color:var(--text-muted);">(<?= $unsubRate ?>%)</small></span>
            <span class="stat-widget-label">Unsubscribed</span>
        </div>
    </div>
</div>

<!-- Rates Summary -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;text-align:center;">
            <div>
                <div style="font-size:2rem;font-weight:700;color:<?= $openRate >= 20 ? 'var(--green)' : ($openRate >= 10 ? 'var(--orange)' : 'var(--red)') ?>;"><?= $openRate ?>%</div>
                <div style="font-size:0.8rem;color:var(--text-muted);">Open Rate</div>
                <div style="width:100%;height:4px;background:var(--border);border-radius:2px;margin-top:8px;"><div style="width:<?= min($openRate, 100) ?>%;height:100%;background:<?= $openRate >= 20 ? 'var(--green)' : 'var(--orange)' ?>;border-radius:2px;"></div></div>
            </div>
            <div>
                <div style="font-size:2rem;font-weight:700;color:<?= $clickRate >= 3 ? 'var(--green)' : ($clickRate >= 1 ? 'var(--orange)' : 'var(--text-muted)') ?>;"><?= $clickRate ?>%</div>
                <div style="font-size:0.8rem;color:var(--text-muted);">Click Rate</div>
                <div style="width:100%;height:4px;background:var(--border);border-radius:2px;margin-top:8px;"><div style="width:<?= min($clickRate * 10, 100) ?>%;height:100%;background:<?= $clickRate >= 3 ? 'var(--green)' : 'var(--orange)' ?>;border-radius:2px;"></div></div>
            </div>
            <div>
                <div style="font-size:2rem;font-weight:700;color:var(--blue);"><?= $ctoRate ?>%</div>
                <div style="font-size:0.8rem;color:var(--text-muted);">Click-to-Open Rate</div>
                <div style="width:100%;height:4px;background:var(--border);border-radius:2px;margin-top:8px;"><div style="width:<?= min($ctoRate, 100) ?>%;height:100%;background:var(--blue);border-radius:2px;"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Opens Timeline -->
<?php if (!empty($timeline)): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Opens Over Time (First 72 Hours)</h2></div>
    <div class="card-body">
        <?php $maxOpens = max(array_column($timeline, 'opens')) ?: 1; ?>
        <div style="display:flex;align-items:flex-end;gap:2px;height:120px;">
            <?php foreach ($timeline as $t): ?>
            <?php $pct = ($t['opens'] / $maxOpens) * 100; ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;">
                <span style="font-size:0.6rem;font-weight:600;color:var(--text-muted);"><?= $t['opens'] ?></span>
                <div style="width:100%;background:var(--blue);border-radius:2px 2px 0 0;min-height:2px;height:<?= max(2, $pct) ?>%;" title="<?= $t['hour'] ?>: <?= $t['opens'] ?> opens"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:4px;">
            <span style="font-size:0.65rem;color:var(--text-muted);"><?= $timeline[0]['hour'] ?? '' ?></span>
            <span style="font-size:0.65rem;color:var(--text-muted);"><?= end($timeline)['hour'] ?? '' ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="dashboard-grid">
    <!-- Top Clicked Links -->
    <div class="card">
        <div class="card-header"><h2>Top Clicked Links</h2></div>
        <div class="card-body no-pad">
            <?php if (empty($topLinks)): ?>
            <p class="empty-state" style="padding:20px;">No clicks recorded yet.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>URL</th><th>Clicks</th><th>Unique</th></tr></thead>
                    <tbody>
                        <?php foreach ($topLinks as $link): ?>
                        <tr>
                            <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <a href="<?= sanitize($link['url']) ?>" target="_blank" style="color:var(--blue);font-size:0.8rem;"><?= sanitize($link['url']) ?></a>
                            </td>
                            <td><strong><?= number_format($link['clicks']) ?></strong></td>
                            <td><?= number_format($link['unique_clicks']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Engagement Breakdown -->
    <div class="card">
        <div class="card-header"><h2>Engagement Breakdown</h2></div>
        <div class="card-body">
            <?php
            $notOpened = $totalSent - $totalOpened;
            $openedNotClicked = $totalOpened - $totalClicked;
            $segments = [
                ['label' => 'Clicked', 'count' => $totalClicked, 'color' => 'var(--green)'],
                ['label' => 'Opened (no click)', 'count' => $openedNotClicked, 'color' => 'var(--blue)'],
                ['label' => 'Not opened', 'count' => max(0, $notOpened), 'color' => 'var(--text-muted)'],
            ];
            if ($totalBounced > 0) $segments[] = ['label' => 'Bounced', 'count' => $totalBounced, 'color' => 'var(--red)'];
            ?>
            <?php foreach ($segments as $seg): ?>
            <?php $pct = $totalSent > 0 ? round(($seg['count'] / $totalSent) * 100, 1) : 0; ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:4px;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span style="width:10px;height:10px;border-radius:50%;background:<?= $seg['color'] ?>;"></span>
                        <?= $seg['label'] ?>
                    </span>
                    <span><strong><?= number_format($seg['count']) ?></strong> (<?= $pct ?>%)</span>
                </div>
                <div style="width:100%;height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $seg['color'] ?>;border-radius:3px;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Subscriber Detail -->
<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>Subscriber Activity (Last 100)</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Subscriber</th><th>Status</th><th>Sent</th><th>Opened</th><th>Clicked</th></tr></thead>
                <tbody>
                    <?php if (empty($subLogs)): ?>
                    <tr><td colspan="5" class="empty-state">No send logs yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($subLogs as $sl): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($sl['email']) ?></strong>
                            <?= $sl['sub_name'] ? '<br><span style="font-size:0.75rem;color:var(--text-muted);">' . sanitize($sl['sub_name']) . '</span>' : '' ?>
                        </td>
                        <td>
                            <?php if ($sl['status'] === 'clicked'): ?><span class="badge-green">Clicked</span>
                            <?php elseif ($sl['status'] === 'opened'): ?><span class="badge-blue">Opened</span>
                            <?php elseif ($sl['status'] === 'bounced'): ?><span class="badge-red">Bounced</span>
                            <?php else: ?><span class="badge-gray">Sent</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.8rem;"><?= date('M j g:i A', strtotime($sl['sent_at'])) ?></td>
                        <td style="font-size:0.8rem;"><?= $sl['opened_at'] ? date('M j g:i A', strtotime($sl['opened_at'])) : '<span class="text-muted">—</span>' ?></td>
                        <td style="font-size:0.8rem;"><?= $sl['clicked_at'] ? date('M j g:i A', strtotime($sl['clicked_at'])) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
