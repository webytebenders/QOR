<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';
require_once 'includes/logger.php';

requireRole('super_admin', 'editor');

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_subscribers.sql')); } catch (Exception $e) {}

// Save template from campaign
if (isPost() && ($_GET['action'] ?? '') === 'save_as_template') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $name = sanitize($_POST['name'] ?? '');
        $category = sanitize($_POST['category'] ?? 'General');
        $description = sanitize($_POST['description'] ?? '');
        $subject = sanitize($_POST['subject'] ?? '');
        $content = $_POST['content'] ?? '';

        if ($name && $content) {
            $db->prepare('INSERT INTO campaign_templates (name, category, description, subject, content) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $category, $description, $subject, $content]);
            logActivity($_SESSION['admin_id'], 'save_template', 'newsletter');
            setFlash('success', "Template '{$name}' saved.");
        }
    }
    redirect('campaign-templates.php');
}

// Delete template
if (($_GET['action'] ?? '') === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db->prepare('DELETE FROM campaign_templates WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'delete_template', 'newsletter', $id);
        setFlash('success', 'Template deleted.');
    }
    redirect('campaign-templates.php');
}

// Use template (create campaign from template)
if (($_GET['action'] ?? '') === 'use') {
    $id = (int)($_GET['id'] ?? 0);
    $tpl = $db->prepare('SELECT * FROM campaign_templates WHERE id = ?');
    $tpl->execute([$id]);
    $tpl = $tpl->fetch();
    if ($tpl) {
        $admin = getCurrentAdmin();
        $db->prepare('INSERT INTO campaigns (subject, content, status, audience_type, author_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$tpl['subject'] ?: 'New Campaign from Template', $tpl['content'], 'draft', 'all', $admin['id']]);
        $newId = $db->lastInsertId();
        $db->prepare('UPDATE campaign_templates SET usage_count = usage_count + 1 WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'use_template', 'newsletter', $id);
        redirect('campaign-edit.php?id=' . $newId);
    }
    redirect('campaign-templates.php');
}

// Duplicate campaign as template
if (($_GET['action'] ?? '') === 'from_campaign') {
    $id = (int)($_GET['id'] ?? 0);
    $camp = $db->prepare('SELECT subject, content FROM campaigns WHERE id = ?');
    $camp->execute([$id]);
    $camp = $camp->fetch();
    if ($camp) {
        $db->prepare('INSERT INTO campaign_templates (name, category, subject, content) VALUES (?, ?, ?, ?)')
            ->execute(['Campaign #' . $id . ' Template', 'Custom', $camp['subject'], $camp['content']]);
        logActivity($_SESSION['admin_id'], 'duplicate_to_template', 'newsletter', $id);
        setFlash('success', 'Campaign saved as template.');
    }
    redirect('campaign-templates.php');
}

// Load templates
$filterCat = $_GET['category'] ?? '';
$where = $filterCat ? 'WHERE category = ?' : '';
$params = $filterCat ? [$filterCat] : [];
$stmt = $db->prepare("SELECT * FROM campaign_templates {$where} ORDER BY category, name");
$stmt->execute($params);
$templates = $stmt->fetchAll();

$categories = $db->query('SELECT DISTINCT category FROM campaign_templates ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

renderHeader('Campaign Templates', 'newsletter');
?>

<div class="msg-back" style="display:flex;justify-content:space-between;align-items:center;">
    <a href="newsletter.php?tab=campaigns" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Campaigns
    </a>
</div>

<!-- Category Filters -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="campaign-templates.php" class="btn <?= !$filterCat ? 'btn-primary' : 'btn-secondary' ?> btn-sm">All</a>
    <?php foreach ($categories as $cat): ?>
    <a href="campaign-templates.php?category=<?= urlencode($cat) ?>" class="btn <?= $filterCat === $cat ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= sanitize($cat) ?></a>
    <?php endforeach; ?>
</div>

<!-- Template Grid -->
<?php if (empty($templates)): ?>
<div class="card"><div class="card-body"><p class="empty-state">No templates yet. Send a campaign first, then save it as a template.</p></div></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
    <?php foreach ($templates as $tpl): ?>
    <div class="card" style="margin-bottom:0;">
        <div class="card-body">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px;">
                <span class="badge-blue" style="font-size:0.7rem;"><?= sanitize($tpl['category']) ?></span>
                <?php if ($tpl['usage_count'] > 0): ?>
                <span style="font-size:0.7rem;color:var(--text-muted);">Used <?= $tpl['usage_count'] ?>x</span>
                <?php endif; ?>
            </div>
            <h3 style="font-size:0.95rem;font-weight:600;margin-bottom:4px;"><?= sanitize($tpl['name']) ?></h3>
            <?php if ($tpl['description']): ?>
            <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;"><?= sanitize($tpl['description']) ?></p>
            <?php endif; ?>
            <?php if ($tpl['subject']): ?>
            <p style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:12px;"><strong>Subject:</strong> <?= sanitize(substr($tpl['subject'], 0, 50)) ?></p>
            <?php endif; ?>
            <div style="display:flex;gap:6px;">
                <a href="campaign-templates.php?action=use&id=<?= $tpl['id'] ?>" class="btn btn-primary btn-sm">Use Template</a>
                <a href="campaign-templates.php?action=delete&id=<?= $tpl['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this template?')">Del</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
