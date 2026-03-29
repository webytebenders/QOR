<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';
require_once 'includes/logger.php';

requireRole('super_admin', 'editor');

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_subscribers.sql')); } catch (Exception $e) {}

$view = $_GET['view'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// ===== ACTIONS =====
if (isPost() && ($_GET['action'] ?? '') === 'save_automation') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (validateCSRF($token)) {
        $id = (int)($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $triggerType = in_array($_POST['trigger_type'] ?? '', ['on_subscribe', 'on_tag', 'on_date']) ? $_POST['trigger_type'] : 'on_subscribe';
        $triggerValue = sanitize($_POST['trigger_value'] ?? '');

        if ($name) {
            if ($id) {
                $db->prepare('UPDATE automations SET name=?, description=?, trigger_type=?, trigger_value=? WHERE id=?')
                    ->execute([$name, $description, $triggerType, $triggerValue, $id]);
                logActivity($_SESSION['admin_id'], 'update_automation', 'newsletter', $id);
            } else {
                $db->prepare('INSERT INTO automations (name, description, trigger_type, trigger_value) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $description, $triggerType, $triggerValue]);
                $id = $db->lastInsertId();
                logActivity($_SESSION['admin_id'], 'create_automation', 'newsletter', $id);
            }

            // Save steps
            $stepSubjects = $_POST['step_subject'] ?? [];
            $stepContents = $_POST['step_content'] ?? [];
            $stepDelayValues = $_POST['step_delay_value'] ?? [];
            $stepDelayUnits = $_POST['step_delay_unit'] ?? [];

            // Delete old steps and re-insert
            $db->prepare('DELETE FROM automation_steps WHERE automation_id = ?')->execute([$id]);

            for ($i = 0; $i < count($stepSubjects); $i++) {
                $subj = sanitize($stepSubjects[$i] ?? '');
                $cont = $stepContents[$i] ?? '';
                $dVal = max(0, (int)($stepDelayValues[$i] ?? 0));
                $dUnit = in_array($stepDelayUnits[$i] ?? 'days', ['minutes', 'hours', 'days']) ? $stepDelayUnits[$i] : 'days';

                if ($subj) {
                    $db->prepare('INSERT INTO automation_steps (automation_id, step_order, delay_value, delay_unit, subject, content) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$id, $i + 1, $dVal, $dUnit, $subj, $cont]);
                }
            }

            setFlash('success', 'Automation saved.');
        }
    }
    redirect('automations?view=edit&id=' . $id);
}

if (($_GET['action'] ?? '') === 'toggle') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $current = $db->prepare('SELECT status FROM automations WHERE id = ?');
        $current->execute([$id]);
        $status = $current->fetchColumn();
        $newStatus = $status === 'active' ? 'paused' : 'active';
        $db->prepare('UPDATE automations SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        logActivity($_SESSION['admin_id'], 'toggle_automation', 'newsletter', $id, ['status' => $newStatus]);
        setFlash('success', 'Automation ' . ($newStatus === 'active' ? 'activated' : 'paused') . '.');
    }
    redirect('automations');
}

if (($_GET['action'] ?? '') === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db->prepare('DELETE FROM automations WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'delete_automation', 'newsletter', $id);
        setFlash('success', 'Automation deleted.');
    }
    redirect('automations');
}

// ===== DATA =====
$automations = $db->query('SELECT a.*, (SELECT COUNT(*) FROM automation_steps WHERE automation_id = a.id) as step_count FROM automations a ORDER BY a.created_at DESC')->fetchAll();

$allTags = [];
try { $allTags = $db->query('SELECT id, name FROM tags ORDER BY name')->fetchAll(); } catch (Exception $e) {}

// Edit data
$editAuto = null;
$editSteps = [];
if ($editId) {
    $stmt = $db->prepare('SELECT * FROM automations WHERE id = ?');
    $stmt->execute([$editId]);
    $editAuto = $stmt->fetch();
    if ($editAuto) {
        $editSteps = $db->prepare('SELECT * FROM automation_steps WHERE automation_id = ? ORDER BY step_order');
        $editSteps->execute([$editId]);
        $editSteps = $editSteps->fetchAll();
    }
}

// Queue stats for edit view
$queueStats = [];
if ($editAuto) {
    $queueStats = $db->prepare("SELECT aq.step_id, aq.status, COUNT(*) as cnt FROM automation_queue aq WHERE aq.automation_id = ? GROUP BY aq.step_id, aq.status");
    $queueStats->execute([$editId]);
    $queueStatsArr = [];
    foreach ($queueStats->fetchAll() as $qs) {
        $queueStatsArr[$qs['step_id']][$qs['status']] = $qs['cnt'];
    }
    $queueStats = $queueStatsArr;
}

renderHeader('Automations', 'automations');
?>

<div class="msg-back">
    <a href="newsletter?tab=campaigns" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Newsletter
    </a>
</div>

<?php if ($view === 'edit' || $view === 'new'): ?>
<!-- ==================== EDIT/CREATE AUTOMATION ==================== -->
<form method="POST" action="automations?action=save_automation">
    <?= csrfField() ?>
    <?php if ($editAuto): ?><input type="hidden" name="id" value="<?= $editAuto['id'] ?>"><?php endif; ?>

    <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><h2><?= $editAuto ? 'Edit' : 'Create' ?> Automation</h2></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group" style="margin:0;">
                    <label>Automation Name</label>
                    <input type="text" name="name" value="<?= sanitize($editAuto['name'] ?? '') ?>" placeholder="e.g. Welcome Series" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Description</label>
                    <input type="text" name="description" value="<?= sanitize($editAuto['description'] ?? '') ?>" placeholder="Optional description">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
                <div class="form-group" style="margin:0;">
                    <label>Trigger</label>
                    <select name="trigger_type" id="triggerType" onchange="updateTriggerValue()">
                        <option value="on_subscribe" <?= ($editAuto['trigger_type'] ?? '') === 'on_subscribe' ? 'selected' : '' ?>>When someone subscribes</option>
                        <option value="on_tag" <?= ($editAuto['trigger_type'] ?? '') === 'on_tag' ? 'selected' : '' ?>>When tag is added</option>
                        <option value="on_date" <?= ($editAuto['trigger_type'] ?? '') === 'on_date' ? 'selected' : '' ?>>On a specific date</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;" id="triggerValueGroup">
                    <label id="triggerValueLabel">Trigger Value</label>
                    <div id="triggerValueField">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Steps -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
            <h2>Email Steps</h2>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addStep()">+ Add Step</button>
        </div>
        <div class="card-body" id="stepsContainer">
            <?php if (empty($editSteps) && !$editAuto): ?>
            <!-- Default first step -->
            <div class="automation-step" data-index="0">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                    <span style="background:var(--blue);color:#000;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;flex-shrink:0;">1</span>
                    <strong style="font-size:0.9rem;">Immediately after trigger</strong>
                    <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
                        <span style="font-size:0.8rem;color:var(--text-muted);">Delay:</span>
                        <input type="number" name="step_delay_value[]" value="0" min="0" style="width:60px;padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;">
                        <select name="step_delay_unit[]" style="padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;">
                            <option value="minutes">Minutes</option>
                            <option value="hours">Hours</option>
                            <option value="days" selected>Days</option>
                        </select>
                        <button type="button" class="block-action-btn delete" onclick="this.closest('.automation-step').remove();reindexSteps()" title="Remove step" style="width:24px;height:24px;">&times;</button>
                    </div>
                </div>
                <div class="form-group" style="margin:0 0 8px;">
                    <label style="font-size:0.7rem;">Subject</label>
                    <input type="text" name="step_subject[]" placeholder="Welcome to Core Chain!" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.7rem;">Content (HTML)</label>
                    <textarea name="step_content[]" rows="5" placeholder="<p>Hi there! Thanks for joining...</p>" style="font-family:monospace;font-size:0.8rem;"></textarea>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($editSteps as $i => $step): ?>
            <div class="automation-step" data-index="<?= $i ?>">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                    <span style="background:var(--blue);color:#000;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;flex-shrink:0;"><?= $i + 1 ?></span>
                    <strong style="font-size:0.9rem;"><?= $i === 0 ? 'After trigger' : 'Then wait' ?></strong>
                    <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
                        <span style="font-size:0.8rem;color:var(--text-muted);">Delay:</span>
                        <input type="number" name="step_delay_value[]" value="<?= $step['delay_value'] ?>" min="0" style="width:60px;padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;">
                        <select name="step_delay_unit[]" style="padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;">
                            <option value="minutes" <?= $step['delay_unit'] === 'minutes' ? 'selected' : '' ?>>Minutes</option>
                            <option value="hours" <?= $step['delay_unit'] === 'hours' ? 'selected' : '' ?>>Hours</option>
                            <option value="days" <?= $step['delay_unit'] === 'days' ? 'selected' : '' ?>>Days</option>
                        </select>
                        <?php $stepQ = $queueStats[$step['id']] ?? []; ?>
                        <?php if (!empty($stepQ)): ?>
                        <span style="font-size:0.7rem;color:var(--text-muted);" title="Waiting / Sent">
                            <?= $stepQ['waiting'] ?? 0 ?> queued, <?= $stepQ['sent'] ?? 0 ?> sent
                        </span>
                        <?php endif; ?>
                        <button type="button" class="block-action-btn delete" onclick="this.closest('.automation-step').remove();reindexSteps()" title="Remove step" style="width:24px;height:24px;">&times;</button>
                    </div>
                </div>
                <div class="form-group" style="margin:0 0 8px;">
                    <label style="font-size:0.7rem;">Subject</label>
                    <input type="text" name="step_subject[]" value="<?= sanitize($step['subject']) ?>" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.7rem;">Content (HTML)</label>
                    <textarea name="step_content[]" rows="5" style="font-family:monospace;font-size:0.8rem;"><?= htmlspecialchars($step['content']) ?></textarea>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:flex;gap:12px;">
        <button type="submit" class="btn btn-primary"><?= $editAuto ? 'Update' : 'Create' ?> Automation</button>
        <a href="automations" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<style>
.automation-step { background:var(--bg-input); border:1px solid var(--border); border-radius:8px; padding:16px; margin-bottom:12px; }
.automation-step + .automation-step::before { content:''; display:block; width:2px; height:20px; background:var(--blue); margin:-28px auto 16px 13px; }
</style>

<script>
const allTags = <?= json_encode($allTags) ?>;
let stepIndex = document.querySelectorAll('.automation-step').length;

function addStep() {
    const container = document.getElementById('stepsContainer');
    const div = document.createElement('div');
    div.className = 'automation-step';
    div.dataset.index = stepIndex;
    div.innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <span style="background:var(--blue);color:#000;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;flex-shrink:0;">${stepIndex + 1}</span>
            <strong style="font-size:0.9rem;">Then wait</strong>
            <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
                <span style="font-size:0.8rem;color:var(--text-muted);">Delay:</span>
                <input type="number" name="step_delay_value[]" value="1" min="0" style="width:60px;padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;">
                <select name="step_delay_unit[]" style="padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;">
                    <option value="minutes">Minutes</option>
                    <option value="hours">Hours</option>
                    <option value="days" selected>Days</option>
                </select>
                <button type="button" class="block-action-btn delete" onclick="this.closest('.automation-step').remove();reindexSteps()" title="Remove" style="width:24px;height:24px;">&times;</button>
            </div>
        </div>
        <div class="form-group" style="margin:0 0 8px;">
            <label style="font-size:0.7rem;">Subject</label>
            <input type="text" name="step_subject[]" placeholder="Follow-up email subject..." required>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:0.7rem;">Content (HTML)</label>
            <textarea name="step_content[]" rows="5" placeholder="<p>Just checking in...</p>" style="font-family:monospace;font-size:0.8rem;"></textarea>
        </div>
    `;
    container.appendChild(div);
    stepIndex++;
}

function reindexSteps() {
    document.querySelectorAll('.automation-step').forEach((el, i) => {
        el.querySelector('span[style*="border-radius:50%"]').textContent = i + 1;
    });
    stepIndex = document.querySelectorAll('.automation-step').length;
}

function updateTriggerValue() {
    const type = document.getElementById('triggerType').value;
    const label = document.getElementById('triggerValueLabel');
    const field = document.getElementById('triggerValueField');
    const current = '<?= sanitize($editAuto['trigger_value'] ?? '') ?>';

    if (type === 'on_subscribe') {
        label.textContent = 'Source Filter (optional)';
        field.innerHTML = '<input type="text" name="trigger_value" value="' + current + '" placeholder="Leave empty for all sources, or e.g. homepage">';
    } else if (type === 'on_tag') {
        label.textContent = 'Tag';
        let opts = allTags.map(t => '<option value="' + t.id + '"' + (current == t.id ? ' selected' : '') + '>' + t.name + '</option>').join('');
        field.innerHTML = '<select name="trigger_value">' + opts + '</select>';
    } else if (type === 'on_date') {
        label.textContent = 'Date';
        field.innerHTML = '<input type="date" name="trigger_value" value="' + current + '">';
    }
}
updateTriggerValue();
</script>

<?php else: ?>
<!-- ==================== AUTOMATIONS LIST ==================== -->
<div class="page-actions">
    <a href="automations?view=new" class="btn btn-primary">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        New Automation
    </a>
</div>

<?php if (empty($automations)): ?>
<div class="card"><div class="card-body">
    <div style="text-align:center;padding:40px 20px;">
        <p style="font-size:1rem;margin-bottom:8px;">No automations yet</p>
        <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:20px;">Create automated email sequences that trigger when subscribers sign up, get tagged, or on a specific date.</p>
        <a href="automations?view=new" class="btn btn-primary">Create Your First Automation</a>
    </div>
</div></div>
<?php else: ?>
<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Automation</th><th>Trigger</th><th>Steps</th><th>Entered</th><th>Completed</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($automations as $a): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($a['name']) ?></strong>
                            <?= $a['description'] ? '<br><span style="font-size:0.75rem;color:var(--text-muted);">' . sanitize($a['description']) . '</span>' : '' ?>
                        </td>
                        <td>
                            <?php if ($a['trigger_type'] === 'on_subscribe'): ?>
                            <span class="badge-blue">On Subscribe</span>
                            <?php elseif ($a['trigger_type'] === 'on_tag'): ?>
                            <span class="badge-orange">On Tag</span>
                            <?php else: ?>
                            <span class="badge-purple">On Date</span>
                            <?php endif; ?>
                            <?php if ($a['trigger_value']): ?>
                            <br><code style="font-size:0.7rem;"><?= sanitize($a['trigger_value']) ?></code>
                            <?php endif; ?>
                        </td>
                        <td><?= $a['step_count'] ?> email(s)</td>
                        <td><?= number_format($a['total_entered']) ?></td>
                        <td><?= number_format($a['total_completed']) ?></td>
                        <td>
                            <?php if ($a['status'] === 'active'): ?><span class="badge-green">Active</span>
                            <?php elseif ($a['status'] === 'paused'): ?><span class="badge-orange">Paused</span>
                            <?php else: ?><span class="badge-gray">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="automations?view=edit&id=<?= $a['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                            <a href="automations?action=toggle&id=<?= $a['id'] ?>" class="btn btn-<?= $a['status'] === 'active' ? 'secondary' : 'primary' ?> btn-sm"><?= $a['status'] === 'active' ? 'Pause' : 'Activate' ?></a>
                            <a href="automations?action=delete&id=<?= $a['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this automation and all its steps/queue?')">Del</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php renderFooter(); ?>
