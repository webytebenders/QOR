<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';
require_once 'includes/logger.php';

requireRole('super_admin', 'editor');

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_subscribers.sql')); } catch (Exception $e) {}

$campaignId = (int)($_GET['campaign_id'] ?? 0);
if (!$campaignId) { redirect('newsletter.php?tab=campaigns'); }

$campaign = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
$campaign->execute([$campaignId]);
$campaign = $campaign->fetch();
if (!$campaign) { setFlash('error', 'Campaign not found.'); redirect('newsletter.php?tab=campaigns'); }

// Handle create A/B test
if (isPost() && ($_GET['action'] ?? '') === 'create_test') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $variantBSubject = sanitize($_POST['variant_b_subject'] ?? '');
        $testSize = max(5, min(50, (int)($_POST['test_size'] ?? 20)));
        $duration = max(1, min(48, (int)($_POST['test_duration'] ?? 4)));

        if ($variantBSubject) {
            $db->prepare('INSERT INTO ab_tests (campaign_id, variant_a_subject, variant_b_subject, test_size, test_duration_hours) VALUES (?, ?, ?, ?, ?)')
                ->execute([$campaignId, $campaign['subject'], $variantBSubject, $testSize, $duration]);
            logActivity($_SESSION['admin_id'], 'create_ab_test', 'campaign', $campaignId);
            setFlash('success', 'A/B test created. Click "Start Test" to begin.');
        }
    }
    redirect('ab-test.php?campaign_id=' . $campaignId);
}

// Start test
if (($_GET['action'] ?? '') === 'start_test') {
    $testId = (int)($_GET['test_id'] ?? 0);
    $test = $db->prepare('SELECT * FROM ab_tests WHERE id = ? AND campaign_id = ?');
    $test->execute([$testId, $campaignId]);
    $test = $test->fetch();

    if ($test && $test['status'] === 'testing' && !$test['started_at']) {
        // Get subscribers
        $emailContent = $campaign['content'];
        $decoded = json_decode($emailContent, true);
        if (is_array($decoded)) {
            require_once 'api/email.php';
            if (function_exists('blocksToEmailHtml')) $emailContent = blocksToEmailHtml($decoded);
        }

        require_once 'includes/mailer.php';

        $subs = $db->query("SELECT id, email, unsubscribe_token FROM subscribers WHERE status = 'active' ORDER BY RAND()")->fetchAll();
        $testCount = max(2, round(count($subs) * ($test['test_size'] / 100)));
        $testSubs = array_slice($subs, 0, $testCount);

        // Split into A and B
        $halfPoint = ceil(count($testSubs) / 2);
        $groupA = array_slice($testSubs, 0, $halfPoint);
        $groupB = array_slice($testSubs, $halfPoint);

        $mailer = new Mailer();
        $trackBase = rtrim(APP_URL, '/') . '/admin/api/track.php';
        $logStmt = $db->prepare('INSERT INTO ab_test_logs (ab_test_id, subscriber_id, variant) VALUES (?, ?, ?)');
        $sentA = 0; $sentB = 0;

        // Send variant A
        foreach ($groupA as $sub) {
            $html = getEmailWrapper($emailContent, '');
            $unsubUrl = rtrim(APP_URL, '/') . '/admin/api/newsletter.php?action=unsubscribe&token=' . $sub['unsubscribe_token'];
            $html = str_replace('{{unsubscribe_url}}', $unsubUrl, $html);
            // Open tracking for A/B
            $html .= '<img src="' . $trackBase . '?action=open&cid=' . $campaignId . '&sid=' . $sub['id'] . '" width="1" height="1" style="display:none;" alt="">';
            if ($mailer->send($sub['email'], $test['variant_a_subject'], $html)) {
                $logStmt->execute([$test['id'], $sub['id'], 'a']);
                $sentA++;
            }
        }

        // Send variant B
        foreach ($groupB as $sub) {
            $html = getEmailWrapper($emailContent, '');
            $unsubUrl = rtrim(APP_URL, '/') . '/admin/api/newsletter.php?action=unsubscribe&token=' . $sub['unsubscribe_token'];
            $html = str_replace('{{unsubscribe_url}}', $unsubUrl, $html);
            $html .= '<img src="' . $trackBase . '?action=open&cid=' . $campaignId . '&sid=' . $sub['id'] . '" width="1" height="1" style="display:none;" alt="">';
            if ($mailer->send($sub['email'], $test['variant_b_subject'], $html)) {
                $logStmt->execute([$test['id'], $sub['id'], 'b']);
                $sentB++;
            }
        }

        $db->prepare('UPDATE ab_tests SET started_at = NOW(), variant_a_sent = ?, variant_b_sent = ? WHERE id = ?')
            ->execute([$sentA, $sentB, $test['id']]);

        logActivity($_SESSION['admin_id'], 'start_ab_test', 'campaign', $campaignId, ['a_sent' => $sentA, 'b_sent' => $sentB]);
        setFlash('success', "Test started! Sent {$sentA} variant A and {$sentB} variant B. Winner determined in {$test['test_duration_hours']}h.");
    }
    redirect('ab-test.php?campaign_id=' . $campaignId);
}

// Pick winner manually
if (($_GET['action'] ?? '') === 'pick_winner') {
    $testId = (int)($_GET['test_id'] ?? 0);
    $winner = in_array($_GET['winner'] ?? '', ['a', 'b']) ? $_GET['winner'] : null;
    if ($testId && $winner) {
        $test = $db->prepare('SELECT * FROM ab_tests WHERE id = ?');
        $test->execute([$testId]);
        $t = $test->fetch();
        if ($t) {
            $winnerSubject = $winner === 'a' ? $t['variant_a_subject'] : $t['variant_b_subject'];
            $db->prepare('UPDATE ab_tests SET status = ?, winner = ?, completed_at = NOW() WHERE id = ?')
                ->execute(['completed', $winner, $testId]);
            $db->prepare('UPDATE campaigns SET subject = ? WHERE id = ?')
                ->execute([$winnerSubject, $campaignId]);
            logActivity($_SESSION['admin_id'], 'pick_ab_winner', 'campaign', $campaignId, ['winner' => $winner]);
            setFlash('success', "Winner: Variant " . strtoupper($winner) . ". Campaign subject updated. You can now send the campaign.");
        }
    }
    redirect('ab-test.php?campaign_id=' . $campaignId);
}

// Load existing tests
$tests = $db->prepare('SELECT * FROM ab_tests WHERE campaign_id = ? ORDER BY created_at DESC');
$tests->execute([$campaignId]);
$tests = $tests->fetchAll();

// Update open counts from campaign_logs (open tracking fires for the campaign)
foreach ($tests as &$t) {
    if ($t['status'] === 'testing' && $t['started_at']) {
        $aOpens = $db->prepare("SELECT COUNT(*) FROM ab_test_logs l JOIN campaign_logs cl ON l.subscriber_id = cl.subscriber_id AND cl.campaign_id = ? WHERE l.ab_test_id = ? AND l.variant = 'a' AND cl.opened_at IS NOT NULL");
        $aOpens->execute([$campaignId, $t['id']]);
        $t['variant_a_opens'] = $aOpens->fetchColumn();

        $bOpens = $db->prepare("SELECT COUNT(*) FROM ab_test_logs l JOIN campaign_logs cl ON l.subscriber_id = cl.subscriber_id AND cl.campaign_id = ? WHERE l.ab_test_id = ? AND l.variant = 'b' AND cl.opened_at IS NOT NULL");
        $bOpens->execute([$campaignId, $t['id']]);
        $t['variant_b_opens'] = $bOpens->fetchColumn();

        $db->prepare('UPDATE ab_tests SET variant_a_opens = ?, variant_b_opens = ? WHERE id = ?')
            ->execute([$t['variant_a_opens'], $t['variant_b_opens'], $t['id']]);

        // Auto-complete if duration passed
        if ($t['started_at'] && strtotime($t['started_at']) + ($t['test_duration_hours'] * 3600) <= time()) {
            $winner = $t['variant_a_opens'] >= $t['variant_b_opens'] ? 'a' : 'b';
            $winnerSubject = $winner === 'a' ? $t['variant_a_subject'] : $t['variant_b_subject'];
            $db->prepare('UPDATE ab_tests SET status = ?, winner = ?, completed_at = NOW() WHERE id = ?')
                ->execute(['completed', $winner, $t['id']]);
            $db->prepare('UPDATE campaigns SET subject = ? WHERE id = ?')
                ->execute([$winnerSubject, $campaignId]);
            $t['status'] = 'completed';
            $t['winner'] = $winner;
        }
    }
}
unset($t);

renderHeader('A/B Test — ' . sanitize($campaign['subject']), 'newsletter');
?>

<div class="msg-back">
    <a href="campaign-edit.php?id=<?= $campaignId ?>" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Campaign
    </a>
</div>

<!-- Create Test -->
<?php if (empty($tests) || end($tests)['status'] === 'completed'): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>Create A/B Test</h2></div>
    <div class="card-body">
        <form method="POST" action="ab-test.php?action=create_test&campaign_id=<?= $campaignId ?>">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Variant A (current subject)</label>
                <input type="text" value="<?= sanitize($campaign['subject']) ?>" disabled style="opacity:0.7;">
            </div>
            <div class="form-group">
                <label>Variant B (test subject)</label>
                <input type="text" name="variant_b_subject" placeholder="Alternative subject line to test..." required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group" style="margin:0;">
                    <label>Test Audience Size (%)</label>
                    <input type="number" name="test_size" value="20" min="5" max="50">
                    <span style="font-size:0.7rem;color:var(--text-muted);">% of subscribers that receive the test. Rest get the winner.</span>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Test Duration (hours)</label>
                    <input type="number" name="test_duration" value="4" min="1" max="48">
                    <span style="font-size:0.7rem;color:var(--text-muted);">Winner auto-picked after this time.</span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:12px;">Create Test</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Tests -->
<?php foreach ($tests as $t): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>
            <?php if ($t['status'] === 'completed'): ?>
                <span class="badge-green">Completed</span>
            <?php elseif ($t['started_at']): ?>
                <span class="badge-orange">Testing</span>
            <?php else: ?>
                <span class="badge-blue">Ready</span>
            <?php endif; ?>
            A/B Test
        </h2>
        <?php if ($t['winner']): ?>
        <span style="font-size:0.85rem;font-weight:600;color:var(--green);">Winner: Variant <?= strtoupper($t['winner']) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- Variants comparison -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <div style="background:var(--bg-input);border:2px solid <?= $t['winner'] === 'a' ? 'var(--green)' : 'var(--border)' ?>;border-radius:8px;padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <strong style="color:var(--blue);">Variant A</strong>
                    <?php if ($t['winner'] === 'a'): ?><span class="badge-green">Winner</span><?php endif; ?>
                </div>
                <p style="font-size:0.9rem;margin-bottom:12px;">"<?= sanitize($t['variant_a_subject']) ?>"</p>
                <div style="font-size:0.8rem;color:var(--text-muted);">
                    Sent: <strong style="color:var(--text);"><?= $t['variant_a_sent'] ?></strong> &middot;
                    Opens: <strong style="color:var(--text);"><?= $t['variant_a_opens'] ?></strong>
                    <?php if ($t['variant_a_sent'] > 0): ?>
                    &middot; Rate: <strong style="color:<?= ($t['variant_a_opens'] / max(1, $t['variant_a_sent'])) > ($t['variant_b_opens'] / max(1, $t['variant_b_sent'])) ? 'var(--green)' : 'var(--text)' ?>;"><?= round(($t['variant_a_opens'] / $t['variant_a_sent']) * 100, 1) ?>%</strong>
                    <?php endif; ?>
                </div>
                <?php if ($t['variant_a_sent'] > 0): ?>
                <div style="width:100%;height:6px;background:var(--border);border-radius:3px;margin-top:8px;">
                    <div style="width:<?= round(($t['variant_a_opens'] / max(1, $t['variant_a_sent'])) * 100) ?>%;height:100%;background:var(--blue);border-radius:3px;"></div>
                </div>
                <?php endif; ?>
            </div>

            <div style="background:var(--bg-input);border:2px solid <?= $t['winner'] === 'b' ? 'var(--green)' : 'var(--border)' ?>;border-radius:8px;padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <strong style="color:var(--orange);">Variant B</strong>
                    <?php if ($t['winner'] === 'b'): ?><span class="badge-green">Winner</span><?php endif; ?>
                </div>
                <p style="font-size:0.9rem;margin-bottom:12px;">"<?= sanitize($t['variant_b_subject']) ?>"</p>
                <div style="font-size:0.8rem;color:var(--text-muted);">
                    Sent: <strong style="color:var(--text);"><?= $t['variant_b_sent'] ?></strong> &middot;
                    Opens: <strong style="color:var(--text);"><?= $t['variant_b_opens'] ?></strong>
                    <?php if ($t['variant_b_sent'] > 0): ?>
                    &middot; Rate: <strong style="color:<?= ($t['variant_b_opens'] / max(1, $t['variant_b_sent'])) > ($t['variant_a_opens'] / max(1, $t['variant_a_sent'])) ? 'var(--green)' : 'var(--text)' ?>;"><?= round(($t['variant_b_opens'] / $t['variant_b_sent']) * 100, 1) ?>%</strong>
                    <?php endif; ?>
                </div>
                <?php if ($t['variant_b_sent'] > 0): ?>
                <div style="width:100%;height:6px;background:var(--border);border-radius:3px;margin-top:8px;">
                    <div style="width:<?= round(($t['variant_b_opens'] / max(1, $t['variant_b_sent'])) * 100) ?>%;height:100%;background:var(--orange);border-radius:3px;"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if (!$t['started_at'] && $t['status'] === 'testing'): ?>
            <a href="ab-test.php?action=start_test&test_id=<?= $t['id'] ?>&campaign_id=<?= $campaignId ?>" class="btn btn-primary" onclick="return confirm('Start sending test emails?')">Start Test</a>
            <?php endif; ?>
            <?php if ($t['started_at'] && $t['status'] === 'testing'): ?>
            <a href="ab-test.php?action=pick_winner&test_id=<?= $t['id'] ?>&campaign_id=<?= $campaignId ?>&winner=a" class="btn btn-secondary btn-sm">Pick A as Winner</a>
            <a href="ab-test.php?action=pick_winner&test_id=<?= $t['id'] ?>&campaign_id=<?= $campaignId ?>&winner=b" class="btn btn-secondary btn-sm">Pick B as Winner</a>
            <span style="font-size:0.8rem;color:var(--text-muted);align-self:center;">
                Auto-picks in <?= max(0, round((strtotime($t['started_at']) + ($t['test_duration_hours'] * 3600) - time()) / 3600, 1)) ?>h
            </span>
            <?php endif; ?>
            <?php if ($t['status'] === 'completed'): ?>
            <span style="font-size:0.85rem;color:var(--green);align-self:center;">Campaign subject updated to winning variant. <a href="newsletter.php?tab=campaigns" style="color:var(--blue);">Send the campaign now.</a></span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($tests)): ?>
<p style="font-size:0.85rem;color:var(--text-muted);text-align:center;">Create a test above to compare two subject lines. The winner is auto-selected based on open rates.</p>
<?php endif; ?>

<?php renderFooter(); ?>
