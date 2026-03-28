<?php
/**
 * Subscriber Preference Center (public, no auth)
 *
 * GET  ?token=X              — show preference form
 * POST ?action=save&token=X  — save preferences
 * POST ?action=unsubscribe&token=X — unsubscribe with reason
 */

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!$token) { echo 'Invalid link.'; exit; }

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/../includes/schema_subscribers.sql')); } catch (Exception $e) {}

$stmt = $db->prepare('SELECT * FROM subscribers WHERE unsubscribe_token = ?');
$stmt->execute([$token]);
$subscriber = $stmt->fetch();

if (!$subscriber) { echo 'Subscriber not found or link expired.'; exit; }

// Save preferences
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $frequency = in_array($_POST['frequency'] ?? '', ['all', 'weekly', 'monthly', 'important']) ? $_POST['frequency'] : 'all';
    $topics = isset($_POST['topics']) && is_array($_POST['topics']) ? implode(',', array_map('sanitize', $_POST['topics'])) : '';

    $db->prepare('UPDATE subscribers SET name = ?, frequency = ?, topics = ? WHERE id = ?')
        ->execute([$name, $frequency, $topics, $subscriber['id']]);

    $saved = true;
}

// Unsubscribe with reason
if ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = sanitize($_POST['reason'] ?? '');
    $otherReason = sanitize($_POST['other_reason'] ?? '');
    $finalReason = $reason === 'other' ? $otherReason : $reason;

    $db->prepare('UPDATE subscribers SET status = ?, unsubscribed_at = NOW(), unsubscribe_reason = ? WHERE id = ?')
        ->execute(['unsubscribed', $finalReason, $subscriber['id']]);

    // Cancel any pending automations
    try {
        $db->prepare("UPDATE automation_queue SET status = 'cancelled' WHERE subscriber_id = ? AND status = 'waiting'")
            ->execute([$subscriber['id']]);
    } catch (Exception $e) {}

    $unsubscribed = true;
}

$currentTopics = $subscriber['topics'] ? explode(',', $subscriber['topics']) : [];
$availableTopics = ['product_updates', 'security_news', 'tokenomics', 'ecosystem', 'events', 'developer'];
$topicLabels = [
    'product_updates' => 'Product Updates',
    'security_news' => 'Security News',
    'tokenomics' => 'Tokenomics & Token Updates',
    'ecosystem' => 'Ecosystem & Partnerships',
    'events' => 'Events & Announcements',
    'developer' => 'Developer & Technical',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Preferences — Core Chain</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#0a0a0f; color:#f0f0f5; min-height:100vh; padding:40px 20px; }
        .container { max-width:500px; margin:0 auto; }
        .logo { text-align:center; margin-bottom:32px; }
        .logo h1 { font-family:'Space Grotesk',sans-serif; font-size:1.4rem; color:#4FC3F7; }
        .card { background:#111118; border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:28px; margin-bottom:20px; }
        .card h2 { font-family:'Space Grotesk',sans-serif; font-size:1.1rem; margin-bottom:16px; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-size:0.8rem; font-weight:600; color:#9999aa; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
        input[type="text"], input[type="email"], select, textarea { width:100%; padding:12px 14px; background:#16161f; border:1px solid rgba(255,255,255,0.06); border-radius:6px; color:#f0f0f5; font-size:0.9rem; outline:none; font-family:inherit; }
        input:focus, select:focus, textarea:focus { border-color:#4FC3F7; }
        .radio-group { display:flex; flex-direction:column; gap:10px; }
        .radio-option { display:flex; align-items:center; gap:10px; padding:12px; background:#16161f; border:1px solid rgba(255,255,255,0.06); border-radius:8px; cursor:pointer; transition:border-color 0.2s; }
        .radio-option:hover { border-color:rgba(79,195,247,0.3); }
        .radio-option input[type="radio"] { accent-color:#4FC3F7; width:16px; height:16px; }
        .radio-option .ro-label { font-weight:500; }
        .radio-option .ro-desc { font-size:0.8rem; color:#666; }
        .checkbox-row { display:flex; align-items:center; gap:8px; padding:10px 12px; background:#16161f; border:1px solid rgba(255,255,255,0.06); border-radius:6px; margin-bottom:6px; cursor:pointer; }
        .checkbox-row input { accent-color:#4FC3F7; width:16px; height:16px; }
        .btn { display:inline-block; padding:12px 24px; font-weight:600; font-size:0.9rem; border:none; border-radius:6px; cursor:pointer; text-align:center; transition:opacity 0.2s; font-family:inherit; }
        .btn:hover { opacity:0.9; }
        .btn-primary { background:linear-gradient(135deg,#4FC3F7,#F97316); color:#000; width:100%; }
        .btn-danger { background:rgba(239,68,68,0.15); color:#ef4444; width:100%; margin-top:12px; }
        .success { background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.2); color:#22c55e; padding:16px; border-radius:8px; text-align:center; margin-bottom:16px; }
        .info { font-size:0.8rem; color:#666; margin-top:4px; }
        .divider { border:none; border-top:1px solid rgba(255,255,255,0.06); margin:20px 0; }
        .unsub-reasons { display:flex; flex-direction:column; gap:8px; }
        .unsub-reasons label { display:flex; align-items:center; gap:8px; padding:10px; background:#16161f; border-radius:6px; cursor:pointer; font-size:0.9rem; }
        .unsub-reasons input[type="radio"] { accent-color:#ef4444; }
        a { color:#4FC3F7; text-decoration:none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h1>Core Chain</h1>
            <p style="font-size:0.85rem;color:#666;margin-top:4px;">Email Preferences</p>
        </div>

        <?php if (isset($unsubscribed)): ?>
        <div class="card" style="text-align:center;">
            <h2 style="color:#ef4444;">Unsubscribed</h2>
            <p style="color:#9999aa;margin-top:8px;">You've been removed from our mailing list. Sorry to see you go!</p>
            <p style="margin-top:16px;"><a href="<?= APP_URL ?>">Back to Core Chain</a></p>
        </div>

        <?php elseif ($subscriber['status'] === 'unsubscribed'): ?>
        <div class="card" style="text-align:center;">
            <h2>You're Unsubscribed</h2>
            <p style="color:#9999aa;margin-top:8px;">This email is currently unsubscribed.</p>
            <p style="margin-top:16px;"><a href="<?= APP_URL ?>">Back to Core Chain</a></p>
        </div>

        <?php else: ?>

        <?php if (isset($saved)): ?>
        <div class="success">Preferences saved!</div>
        <?php endif; ?>

        <form method="POST" action="preferences.php?action=save&token=<?= urlencode($token) ?>">
            <div class="card">
                <h2>Your Details</h2>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" value="<?= sanitize($subscriber['email']) ?>" disabled style="opacity:0.6;">
                </div>
                <div class="form-group">
                    <label>Name (optional)</label>
                    <input type="text" name="name" value="<?= sanitize($subscriber['name'] ?? '') ?>" placeholder="Your name">
                </div>
            </div>

            <div class="card">
                <h2>Email Frequency</h2>
                <p class="info" style="margin-bottom:12px;">How often would you like to hear from us?</p>
                <div class="radio-group">
                    <label class="radio-option">
                        <input type="radio" name="frequency" value="all" <?= ($subscriber['frequency'] ?? 'all') === 'all' ? 'checked' : '' ?>>
                        <div><div class="ro-label">All emails</div><div class="ro-desc">Get every update as they're sent</div></div>
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="frequency" value="weekly" <?= ($subscriber['frequency'] ?? '') === 'weekly' ? 'checked' : '' ?>>
                        <div><div class="ro-label">Weekly digest</div><div class="ro-desc">One summary email per week</div></div>
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="frequency" value="monthly" <?= ($subscriber['frequency'] ?? '') === 'monthly' ? 'checked' : '' ?>>
                        <div><div class="ro-label">Monthly digest</div><div class="ro-desc">One summary email per month</div></div>
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="frequency" value="important" <?= ($subscriber['frequency'] ?? '') === 'important' ? 'checked' : '' ?>>
                        <div><div class="ro-label">Important only</div><div class="ro-desc">Major announcements and security alerts only</div></div>
                    </label>
                </div>
            </div>

            <div class="card">
                <h2>Topics</h2>
                <p class="info" style="margin-bottom:12px;">Select the topics you're interested in:</p>
                <?php foreach ($availableTopics as $topic): ?>
                <label class="checkbox-row">
                    <input type="checkbox" name="topics[]" value="<?= $topic ?>" <?= in_array($topic, $currentTopics) ? 'checked' : '' ?>>
                    <span><?= $topicLabels[$topic] ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary">Save Preferences</button>
        </form>

        <!-- Unsubscribe Section -->
        <hr class="divider">
        <div class="card">
            <h2 style="color:#ef4444;">Unsubscribe</h2>
            <p class="info" style="margin-bottom:16px;">We'd hate to see you go. If you unsubscribe, you'll stop receiving all emails from us.</p>
            <form method="POST" action="preferences.php?action=unsubscribe&token=<?= urlencode($token) ?>" onsubmit="return confirm('Are you sure you want to unsubscribe?')">
                <div class="unsub-reasons">
                    <label><input type="radio" name="reason" value="too_many" checked> Too many emails</label>
                    <label><input type="radio" name="reason" value="not_relevant"> Content not relevant to me</label>
                    <label><input type="radio" name="reason" value="never_signed_up"> I never signed up</label>
                    <label><input type="radio" name="reason" value="other"> Other</label>
                </div>
                <div id="otherReasonField" style="display:none;margin-top:8px;">
                    <input type="text" name="other_reason" placeholder="Tell us why...">
                </div>
                <button type="submit" class="btn btn-danger">Unsubscribe</button>
            </form>
        </div>

        <script>
        document.querySelectorAll('input[name="reason"]').forEach(r => {
            r.addEventListener('change', () => {
                document.getElementById('otherReasonField').style.display = r.value === 'other' && r.checked ? 'block' : 'none';
            });
        });
        </script>

        <?php endif; ?>

        <p style="text-align:center;font-size:0.75rem;color:#444;margin-top:24px;">&copy; <?= date('Y') ?> Core Chain. The Biometric Standard.</p>
    </div>
</body>
</html>
<?php exit; ?>
