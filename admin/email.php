<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';
require_once 'includes/mailer.php';

requireRole('super_admin');

$db = getDB();
ensureEmailTemplatesTable();

// Load templates
$templates = $db->query('SELECT * FROM email_templates ORDER BY id')->fetchAll();

// Editing a template?
$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
if ($editId) {
    $stmt = $db->prepare('SELECT * FROM email_templates WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

$triggerLabels = [
    'waitlist_signup' => 'Waitlist Signup',
    'contact_submit' => 'Contact Form Submit',
    'admin_reply' => 'Admin Replies to Message',
    'newsletter_subscribe' => 'Newsletter Subscribe',
    'campaign_send' => 'Campaign Send',
    '' => 'Manual / Custom',
];

renderHeader('Email System', 'email');
?>

<div class="dashboard-grid">
    <!-- SMTP Config -->
    <div class="card">
        <div class="card-header"><h2>SMTP Configuration</h2></div>
        <div class="card-body">
            <div class="info-list">
                <div class="info-item">
                    <span class="info-label">Host</span>
                    <span class="info-value"><code><?= SMTP_HOST ?></code></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Port</span>
                    <span class="info-value"><code><?= SMTP_PORT ?></code></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Security</span>
                    <span class="info-value"><code><?= SMTP_SECURE ?></code></span>
                </div>
                <div class="info-item">
                    <span class="info-label">From Email</span>
                    <span class="info-value"><code><?= SMTP_FROM_EMAIL ?></code></span>
                </div>
                <div class="info-item" style="border:none">
                    <span class="info-label">Password</span>
                    <span class="info-value"><?= SMTP_PASS ? '<span class="badge-green">Set</span>' : '<span class="badge-red">Not Set</span>' ?></span>
                </div>
            </div>
            <p style="font-size:0.75rem;color:var(--text-muted);margin-top:12px;">Edit <code>admin/includes/config.php</code> to update SMTP settings.</p>
        </div>
    </div>

    <!-- Test Email -->
    <div class="card">
        <div class="card-header"><h2>Test SMTP</h2></div>
        <div class="card-body">
            <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:16px;">Send a test email to verify your SMTP configuration is working.</p>
            <form method="POST" action="api/email?action=test_smtp">
                <?= csrfField() ?>
                <div class="form-group">
                    <label>Send Test To</label>
                    <input type="email" name="test_email" placeholder="your@email.com" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:12px">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
                    Send Test Email
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Email Templates -->
<div class="card">
    <div class="card-header">
        <h2>Email Templates</h2>
        <button class="btn btn-primary btn-sm" onclick="openTemplateEditor(0)">
            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
            New Template
        </button>
    </div>
    <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:24px;">Click a template to edit its subject, body, and variables. Templates use <code style="color:var(--blue)">{{variable}}</code> syntax.</p>

        <div class="email-templates-grid">
            <?php foreach ($templates as $tpl): ?>
            <div class="email-tpl-card" style="cursor:pointer;transition:border-color 0.2s;<?= !$tpl['is_active'] ? 'opacity:0.5' : '' ?>" onclick="openTemplateEditor(<?= $tpl['id'] ?>)">
                <div class="email-tpl-header">
                    <span class="email-tpl-trigger"><?= $triggerLabels[$tpl['trigger_event']] ?? $tpl['trigger_event'] ?></span>
                    <?php if ($tpl['is_active']): ?>
                    <span class="badge-green">Active</span>
                    <?php else: ?>
                    <span class="badge-red">Disabled</span>
                    <?php endif; ?>
                </div>
                <h4><?= sanitize($tpl['name']) ?></h4>
                <p style="font-size:0.75rem;color:var(--text-muted);margin-top:4px">Subject: <?= sanitize($tpl['subject']) ?></p>
                <?php if ($tpl['variables']): ?>
                <p style="font-size:0.7rem;color:var(--text-muted);margin-top:4px">Variables: <code><?= sanitize($tpl['variables']) ?></code></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Automation Flows -->
<div class="card">
    <div class="card-header"><h2>Automation Flows</h2></div>
    <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:16px;">These flows run automatically. Enable/disable them by toggling the template they use.</p>
        <div class="flow-list">
            <div class="flow-item">
                <div class="flow-trigger">Waitlist Signup</div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-action">Welcome Email</div>
            </div>
            <div class="flow-item">
                <div class="flow-trigger">Contact Form</div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-action">Auto-Reply to User</div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-action">Admin Notification</div>
            </div>
            <div class="flow-item">
                <div class="flow-trigger">Admin Replies</div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-action">Reply Email to User</div>
            </div>
            <div class="flow-item">
                <div class="flow-trigger">Newsletter Subscribe</div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-action">Welcome Email</div>
            </div>
            <div class="flow-item">
                <div class="flow-trigger">Campaign Send</div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-action">Bulk Email to Subscribers</div>
            </div>
        </div>
    </div>
</div>

<!-- Template Editor Modal -->
<div class="modal" id="tplModal">
    <div class="modal-overlay" onclick="closeTplModal()"></div>
    <div class="modal-content" style="max-width:700px;max-height:90vh;overflow-y:auto">
        <div class="modal-header">
            <h3 id="tplModalTitle">Edit Template</h3>
            <button class="modal-close" onclick="closeTplModal()">&times;</button>
        </div>
        <form method="POST" action="api/email?action=save_template">
            <?= csrfField() ?>
            <input type="hidden" name="tpl_id" id="tplId" value="0">
            <div class="modal-body" style="gap:14px">
                <div class="form-group">
                    <label>Template Name</label>
                    <input type="text" name="tpl_name" id="tplName" placeholder="e.g. Waitlist Welcome" required>
                </div>
                <div class="form-group">
                    <label>Email Subject</label>
                    <input type="text" name="tpl_subject" id="tplSubject" placeholder="e.g. Welcome to Core Chain" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="form-group">
                        <label>Trigger Event</label>
                        <select name="tpl_trigger" id="tplTrigger">
                            <?php foreach ($triggerLabels as $val => $label): ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Variables <span style="font-weight:400;color:var(--text-muted)">(comma-separated)</span></label>
                        <input type="text" name="tpl_variables" id="tplVariables" placeholder="{{name}}, {{email}}">
                    </div>
                </div>
                <div class="form-group">
                    <label>Email Body <span style="font-weight:400;color:var(--text-muted)">(HTML — use {{variable}} for dynamic content)</span></label>
                    <textarea name="tpl_body" id="tplBody" rows="14" style="font-family:monospace;font-size:0.8rem;line-height:1.6" required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:space-between">
                <div>
                    <span id="tplActions"></span>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="previewTemplate()" id="tplPreviewBtn" style="display:none">Preview</button>
                    <button type="button" class="btn btn-ghost" onclick="closeTplModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Template</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Template data for JS -->
<script>
const templates = <?= json_encode($templates) ?>;

function openTemplateEditor(id) {
    const modal = document.getElementById('tplModal');
    if (id === 0) {
        // New template
        document.getElementById('tplModalTitle').textContent = 'New Template';
        document.getElementById('tplId').value = '0';
        document.getElementById('tplName').value = '';
        document.getElementById('tplSubject').value = '';
        document.getElementById('tplBody').value = '<h2 style="font-family:\'Space Grotesk\',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">Title</h2>\n<p style="color:#9999aa;">Your content here.</p>\n<p style="color:#9999aa;">— The Core Chain Team</p>';
        document.getElementById('tplTrigger').value = '';
        document.getElementById('tplVariables').value = '';
        document.getElementById('tplActions').innerHTML = '';
        document.getElementById('tplPreviewBtn').style.display = 'none';
    } else {
        const tpl = templates.find(t => t.id == id);
        if (!tpl) return;
        document.getElementById('tplModalTitle').textContent = 'Edit: ' + tpl.name;
        document.getElementById('tplId').value = tpl.id;
        document.getElementById('tplName').value = tpl.name;
        document.getElementById('tplSubject').value = tpl.subject;
        document.getElementById('tplBody').value = tpl.body;
        document.getElementById('tplTrigger').value = tpl.trigger_event;
        document.getElementById('tplVariables').value = tpl.variables || '';
        document.getElementById('tplPreviewBtn').style.display = '';

        // Toggle + Delete buttons
        const csrf = document.querySelector('input[name="<?= CSRF_TOKEN_NAME ?>"]').value;
        document.getElementById('tplActions').innerHTML = `
            <form method="POST" action="api/email?action=toggle_template" style="display:inline">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="${csrf}">
                <input type="hidden" name="tpl_id" value="${tpl.id}">
                <button type="submit" class="btn btn-sm ${tpl.is_active == 1 ? 'btn-secondary' : 'btn-primary'}" title="${tpl.is_active == 1 ? 'Disable' : 'Enable'}">
                    ${tpl.is_active == 1 ? 'Disable' : 'Enable'}
                </button>
            </form>
            <form method="POST" action="api/email?action=delete_template" style="display:inline" onsubmit="return confirm('Delete this template?')">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="${csrf}">
                <input type="hidden" name="tpl_id" value="${tpl.id}">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>`;
    }
    modal.classList.add('show');
}

function closeTplModal() {
    document.getElementById('tplModal').classList.remove('show');
}

function previewTemplate() {
    const id = document.getElementById('tplId').value;
    if (id && id !== '0') {
        window.open('api/email?action=preview_template&id=' + id, '_blank', 'width=700,height=600');
    }
}
</script>

<?php renderFooter(); ?>
