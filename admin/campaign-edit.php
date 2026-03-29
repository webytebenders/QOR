<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireRole('super_admin', 'editor');

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_subscribers.sql')); } catch (Exception $e) {}

$id = (int)($_GET['id'] ?? 0);
$campaign = null;

if ($id) {
    $stmt = $db->prepare('SELECT * FROM campaigns WHERE id = ?');
    $stmt->execute([$id]);
    $campaign = $stmt->fetch();
    if (!$campaign) { setFlash('error', 'Campaign not found.'); redirect('newsletter?tab=campaigns'); }
}

$activeCount = $db->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();

$allTags = $db->query('SELECT t.*, (SELECT COUNT(*) FROM subscriber_tags WHERE tag_id = t.id) as sub_count FROM tags t ORDER BY t.name')->fetchAll();
$allSegments = [];
try { $allSegments = $db->query('SELECT * FROM segments ORDER BY name')->fetchAll(); } catch (Exception $e) {}

$pageTitle = $campaign ? 'Edit Campaign' : 'New Campaign';
renderHeader($pageTitle, 'newsletter');
?>

<div class="msg-back">
    <a href="newsletter?tab=campaigns" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Campaigns
    </a>
</div>

<form method="POST" action="api/newsletter?action=save_campaign" id="campaignForm">
    <?= csrfField() ?>
    <?php if ($campaign): ?><input type="hidden" name="id" value="<?= $campaign['id'] ?>"><?php endif; ?>
    <textarea name="content" id="contentHidden" style="display:none"><?= htmlspecialchars($campaign['content'] ?? '') ?></textarea>

    <div style="display:grid;grid-template-columns:200px 1fr 280px;gap:16px;align-items:start;">

        <!-- LEFT: Block Palette + AI -->
        <div style="position:sticky;top:80px;">
            <div class="card">
                <div class="card-header"><h2>Blocks</h2></div>
                <div class="card-body" style="padding:12px;">
                    <div class="block-palette">
                        <div class="palette-block" draggable="true" data-type="text">
                            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                            <span>Text</span>
                        </div>
                        <div class="palette-block" draggable="true" data-type="image">
                            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
                            <span>Image</span>
                        </div>
                        <div class="palette-block" draggable="true" data-type="button">
                            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V4a2 2 0 00-2-2H6zm4 14a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                            <span>Button</span>
                        </div>
                        <div class="palette-block" draggable="true" data-type="divider">
                            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
                            <span>Divider</span>
                        </div>
                        <div class="palette-block" draggable="true" data-type="spacer">
                            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M5 8a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1zm0 4a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z"/></svg>
                            <span>Spacer</span>
                        </div>
                        <div class="palette-block" draggable="true" data-type="columns">
                            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M2 4a1 1 0 011-1h5v14H3a1 1 0 01-1-1V4zm9-1h6a1 1 0 011 1v12a1 1 0 01-1 1h-6V3z"/></svg>
                            <span>2 Columns</span>
                        </div>
                        <div class="palette-block" draggable="true" data-type="heading">
                            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h4a1 1 0 010 2H7v4h6V5h-1a1 1 0 110-2h4a1 1 0 110 2h-1v10h1a1 1 0 110 2h-4a1 1 0 110-2h1v-4H7v4h1a1 1 0 110 2H4a1 1 0 110-2h1V5H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
                            <span>Heading</span>
                        </div>
                    </div>

                    <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--border);">
                        <div class="form-group" style="margin:0;">
                            <label>Subject Line</label>
                            <input type="text" name="subject" id="subjectInput" value="<?= sanitize($campaign['subject'] ?? '') ?>" placeholder="Email subject..." required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Content Writer -->
            <div class="card">
                <div class="card-header" style="cursor:pointer;" onclick="document.getElementById('aiPanel').style.display = document.getElementById('aiPanel').style.display === 'none' ? '' : 'none'">
                    <h2 style="display:flex;align-items:center;gap:6px;">
                        <svg viewBox="0 0 20 20" fill="var(--blue)" width="16" height="16"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm1 11a1 1 0 11-2 0V9a1 1 0 112 0v4zm-1-6a1 1 0 110-2 1 1 0 010 2z"/></svg>
                        AI Writer
                    </h2>
                </div>
                <div class="card-body" id="aiPanel" style="padding:12px;">
                    <div class="form-group" style="margin:0 0 8px;">
                        <label style="font-size:0.7rem;">Mode</label>
                        <select id="aiMode" style="width:100%;padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;font-size:0.8rem;">
                            <option value="subject">Generate Subject Lines</option>
                            <option value="body">Write Email Body</option>
                            <option value="rewrite">Rewrite / Improve</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0 0 8px;">
                        <label style="font-size:0.7rem;">Tone</label>
                        <select id="aiTone" style="width:100%;padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;font-size:0.8rem;">
                            <option value="professional">Professional</option>
                            <option value="casual">Casual</option>
                            <option value="urgent">Urgent</option>
                            <option value="friendly">Friendly</option>
                            <option value="minimal">Minimal</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0 0 8px;">
                        <label style="font-size:0.7rem;">Prompt</label>
                        <textarea id="aiPrompt" rows="3" placeholder="e.g. Weekly newsletter about our new staking feature launch" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;font-size:0.8rem;resize:vertical;"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary btn-full" onclick="runAiGenerate()" id="aiGenerateBtn" style="font-size:0.8rem;">
                        Generate
                    </button>
                    <div id="aiResult" style="display:none;margin-top:10px;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;padding:10px;max-height:250px;overflow-y:auto;">
                        <div id="aiResultContent" style="font-size:0.8rem;line-height:1.6;"></div>
                        <div id="aiResultActions" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CENTER: Canvas -->
        <div>
            <div class="card">
                <div class="card-header" style="justify-content:space-between;">
                    <h2>Email Canvas</h2>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="togglePreview()">Preview</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleHtmlSource()">HTML</button>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <!-- Canvas -->
                    <div id="emailCanvas" style="min-height:400px;background:#1a1a24;padding:20px;">
                        <div id="canvasDropZone" style="max-width:600px;margin:0 auto;min-height:360px;background:var(--bg-card);border:2px dashed var(--border);border-radius:8px;padding:16px;transition:border-color 0.2s;">
                            <div id="emptyState" style="text-align:center;padding:60px 20px;color:var(--text-muted);">
                                <p style="font-size:1rem;margin-bottom:8px;">Drag blocks here to build your email</p>
                                <p style="font-size:0.8rem;">Or click a block on the left to add it</p>
                            </div>
                        </div>
                    </div>
                    <!-- Preview (hidden) -->
                    <div id="emailPreview" style="display:none;padding:20px;background:#1a1a24;">
                        <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;">
                            <iframe id="previewFrame" style="width:100%;min-height:500px;border:none;"></iframe>
                        </div>
                    </div>
                    <!-- HTML Source (hidden) -->
                    <div id="htmlSourcePanel" style="display:none;padding:20px;">
                        <textarea id="htmlSourceCode" style="width:100%;min-height:400px;font-family:monospace;font-size:0.8rem;background:var(--bg-input);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:12px;" readonly></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Properties + Send -->
        <div>
            <!-- Block Properties -->
            <div class="card" id="propsPanel" style="display:none;">
                <div class="card-header"><h2 id="propsTitle">Block Properties</h2></div>
                <div class="card-body" id="propsBody"></div>
            </div>

            <!-- Audience -->
            <div class="card">
                <div class="card-header"><h2>Audience</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Send To</label>
                        <select name="audience_type" id="audienceType" onchange="updateAudienceOptions()">
                            <option value="all" <?= ($campaign['audience_type'] ?? 'all') === 'all' ? 'selected' : '' ?>>All (<?= number_format($activeCount) ?>)</option>
                            <?php if (!empty($allSegments)): ?><option value="segment" <?= ($campaign['audience_type'] ?? '') === 'segment' ? 'selected' : '' ?>>Segment</option><?php endif; ?>
                            <?php if (!empty($allTags)): ?><option value="tag" <?= ($campaign['audience_type'] ?? '') === 'tag' ? 'selected' : '' ?>>Tag</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group" id="segmentSelect" style="display:<?= ($campaign['audience_type'] ?? '') === 'segment' ? 'flex' : 'none' ?>;">
                        <label>Segment</label>
                        <select name="audience_segment_id">
                            <?php foreach ($allSegments as $seg): ?><option value="<?= $seg['id'] ?>" <?= ($campaign['audience_id'] ?? 0) == $seg['id'] ? 'selected' : '' ?>><?= sanitize($seg['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="tagSelect" style="display:<?= ($campaign['audience_type'] ?? '') === 'tag' ? 'flex' : 'none' ?>;">
                        <label>Tag</label>
                        <select name="audience_tag_id">
                            <?php foreach ($allTags as $t): ?><option value="<?= $t['id'] ?>" <?= ($campaign['audience_id'] ?? 0) == $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?> (<?= $t['sub_count'] ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Send Settings -->
            <div class="card">
                <div class="card-header"><h2>Settings</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="statusSelect">
                            <option value="draft" <?= ($campaign['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="scheduled" <?= ($campaign['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        </select>
                    </div>
                    <div class="form-group" id="scheduleGroup" style="display:<?= ($campaign['status'] ?? '') === 'scheduled' ? 'flex' : 'none' ?>">
                        <label>Schedule Date</label>
                        <input type="datetime-local" name="scheduled_at" value="<?= ($campaign['scheduled_at'] ?? '') ? date('Y-m-d\TH:i', strtotime($campaign['scheduled_at'])) : '' ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-full" onclick="syncContent()"><?= $campaign ? 'Update' : 'Save' ?> Campaign</button>

                    <?php if ($campaign && $campaign['status'] !== 'sent'): ?>
                    <a href="ab-test?campaign_id=<?= $campaign['id'] ?>" class="btn btn-secondary btn-full" style="margin-top:8px;">A/B Test Subject</a>
                    <?php endif; ?>

                    <?php if ($campaign): ?>
                    <form method="POST" action="campaign-templates?action=save_as_template" style="margin-top:8px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="name" value="<?= sanitize($campaign['subject']) ?>">
                        <input type="hidden" name="subject" value="<?= sanitize($campaign['subject']) ?>">
                        <input type="hidden" name="content" value="<?= htmlspecialchars($campaign['content']) ?>">
                        <input type="hidden" name="category" value="Custom">
                        <button type="submit" class="btn btn-ghost btn-full" onclick="syncContent();this.form.querySelector('[name=content]').value=document.getElementById('contentHidden').value;">Save as Template</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($campaign && ($campaign['sent_at'] ?? null)): ?>
                    <div class="editor-meta" style="margin-top:12px;">
                        <span>Sent: <?= date('M j g:i A', strtotime($campaign['sent_at'])) ?></span>
                        <span>To: <?= number_format($campaign['sent_count']) ?> | Opens: <?= number_format($campaign['open_count']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<style>
.block-palette { display:flex; flex-direction:column; gap:6px; }
.palette-block { display:flex; align-items:center; gap:8px; padding:10px 12px; background:var(--bg-input); border:1px solid var(--border); border-radius:6px; cursor:grab; font-size:0.8rem; font-weight:500; color:var(--text-secondary); transition:all 0.2s; }
.palette-block:hover { border-color:var(--blue); color:var(--text); background:var(--bg-hover); }
.palette-block:active { cursor:grabbing; }
.palette-block svg { flex-shrink:0; color:var(--blue); }
.canvas-block { position:relative; margin-bottom:8px; border:2px solid transparent; border-radius:6px; transition:border-color 0.15s; cursor:pointer; }
.canvas-block:hover { border-color:rgba(79,195,247,0.3); }
.canvas-block.selected { border-color:var(--blue); }
.canvas-block .block-actions { position:absolute; top:-10px; right:4px; display:none; gap:2px; z-index:5; }
.canvas-block:hover .block-actions, .canvas-block.selected .block-actions { display:flex; }
.block-action-btn { width:22px; height:22px; border-radius:4px; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:0.7rem; }
.block-action-btn.move-up, .block-action-btn.move-down { background:var(--bg-input); color:var(--text-muted); }
.block-action-btn.delete { background:var(--red-dim); color:var(--red); }
.block-action-btn:hover { opacity:0.8; }
.canvas-block.drag-over { border-color:var(--blue); border-style:dashed; }
.cb-text, .cb-heading { padding:16px 20px; }
.cb-text [contenteditable] { outline:none; min-height:24px; font-size:14px; line-height:1.6; color:#333; }
.cb-heading [contenteditable] { outline:none; font-size:22px; font-weight:700; color:#333; }
.cb-image { padding:16px 20px; text-align:center; }
.cb-image img { max-width:100%; border-radius:4px; }
.cb-button { padding:16px 20px; text-align:center; }
.cb-button a { display:inline-block; padding:12px 28px; border-radius:6px; font-weight:600; font-size:14px; text-decoration:none; }
.cb-divider { padding:8px 20px; }
.cb-divider hr { border:none; border-top:1px solid #ddd; }
.cb-spacer { }
.cb-columns { padding:16px 20px; display:flex; gap:16px; }
.cb-columns .col { flex:1; min-height:40px; background:rgba(0,0,0,0.02); border:1px dashed #ddd; border-radius:4px; padding:12px; }
.cb-columns .col [contenteditable] { outline:none; font-size:13px; line-height:1.6; color:#333; min-height:20px; }
</style>

<script>
let blocks = [];
let selectedBlockId = null;
let blockIdCounter = 0;

// Default block data
const blockDefaults = {
    text: { content: '<p>Edit this text block. Use it for paragraphs, lists, or any content.</p>', padding: '16px 20px', bgColor: '#ffffff' },
    heading: { content: 'Your Heading Here', padding: '16px 20px', bgColor: '#ffffff', fontSize: '22px', color: '#333333' },
    image: { src: '', alt: 'Image', link: '', width: '100%', padding: '16px 20px', bgColor: '#ffffff' },
    button: { text: 'Click Here', url: '#', bgColor: '#4FC3F7', textColor: '#ffffff', borderRadius: '6px', padding: '16px 20px', align: 'center' },
    divider: { color: '#dddddd', thickness: '1px', padding: '8px 20px' },
    spacer: { height: '30px' },
    columns: { left: '<p>Left column content</p>', right: '<p>Right column content</p>', padding: '16px 20px', bgColor: '#ffffff' },
};

// Init: load existing content into blocks
<?php if ($campaign && $campaign['content']): ?>
// Try to parse as JSON blocks first, fallback to single text block
try {
    const saved = <?= json_encode($campaign['content']) ?>;
    const parsed = JSON.parse(saved);
    if (Array.isArray(parsed)) {
        parsed.forEach(b => { b.id = ++blockIdCounter; blocks.push(b); });
    } else { throw 'not array'; }
} catch(e) {
    blocks.push({ id: ++blockIdCounter, type: 'text', ...blockDefaults.text, content: <?= json_encode($campaign['content']) ?> });
}
<?php endif; ?>

renderCanvas();

// ===== DRAG & DROP =====
document.querySelectorAll('.palette-block').forEach(el => {
    el.addEventListener('dragstart', e => {
        e.dataTransfer.setData('block-type', el.dataset.type);
        e.dataTransfer.effectAllowed = 'copy';
    });
    el.addEventListener('click', () => addBlock(el.dataset.type));
});

const dropZone = document.getElementById('canvasDropZone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; dropZone.style.borderColor = 'var(--blue)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = '';
    const type = e.dataTransfer.getData('block-type');
    if (type) addBlock(type);
});

function addBlock(type, index) {
    const block = { id: ++blockIdCounter, type, ...JSON.parse(JSON.stringify(blockDefaults[type])) };
    if (index !== undefined) blocks.splice(index, 0, block);
    else blocks.push(block);
    renderCanvas();
    selectBlock(block.id);
}

function removeBlock(id) {
    blocks = blocks.filter(b => b.id !== id);
    if (selectedBlockId === id) { selectedBlockId = null; document.getElementById('propsPanel').style.display = 'none'; }
    renderCanvas();
}

function moveBlock(id, dir) {
    const idx = blocks.findIndex(b => b.id === id);
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= blocks.length) return;
    [blocks[idx], blocks[newIdx]] = [blocks[newIdx], blocks[idx]];
    renderCanvas();
}

function selectBlock(id) {
    saveInlineEdits();
    selectedBlockId = id;
    document.querySelectorAll('.canvas-block').forEach(el => el.classList.toggle('selected', el.dataset.id == id));
    showProperties(id);
}

function saveInlineEdits() {
    blocks.forEach(b => {
        const el = document.querySelector(`.canvas-block[data-id="${b.id}"]`);
        if (!el) return;
        if (b.type === 'text') { const ce = el.querySelector('[contenteditable]'); if (ce) b.content = ce.innerHTML; }
        if (b.type === 'heading') { const ce = el.querySelector('[contenteditable]'); if (ce) b.content = ce.textContent; }
        if (b.type === 'columns') {
            const cols = el.querySelectorAll('.col [contenteditable]');
            if (cols[0]) b.left = cols[0].innerHTML;
            if (cols[1]) b.right = cols[1].innerHTML;
        }
    });
}

// ===== RENDER =====
function renderCanvas() {
    const zone = document.getElementById('canvasDropZone');
    const empty = document.getElementById('emptyState');

    if (!blocks.length) {
        zone.innerHTML = '';
        zone.appendChild(empty);
        empty.style.display = '';
        zone.style.borderStyle = 'dashed';
        return;
    }

    empty.style.display = 'none';
    zone.style.borderStyle = 'solid';
    zone.style.borderColor = 'transparent';

    // Keep empty state element but hide
    let html = '';
    blocks.forEach(b => {
        html += '<div class="canvas-block' + (b.id === selectedBlockId ? ' selected' : '') + '" data-id="' + b.id + '" draggable="true" onclick="selectBlock(' + b.id + ')">';
        html += '<div class="block-actions">';
        html += '<button type="button" class="block-action-btn move-up" onclick="event.stopPropagation();moveBlock(' + b.id + ',-1)" title="Move up">&#9650;</button>';
        html += '<button type="button" class="block-action-btn move-down" onclick="event.stopPropagation();moveBlock(' + b.id + ',1)" title="Move down">&#9660;</button>';
        html += '<button type="button" class="block-action-btn delete" onclick="event.stopPropagation();removeBlock(' + b.id + ')" title="Delete">&#10005;</button>';
        html += '</div>';
        html += renderBlockContent(b);
        html += '</div>';
    });

    zone.innerHTML = html;
    zone.appendChild(empty);

    // Enable drag reorder between blocks
    zone.querySelectorAll('.canvas-block').forEach(el => {
        el.addEventListener('dragstart', e => {
            e.dataTransfer.setData('reorder-id', el.dataset.id);
            e.dataTransfer.effectAllowed = 'move';
        });
        el.addEventListener('dragover', e => {
            e.preventDefault();
            el.classList.add('drag-over');
        });
        el.addEventListener('dragleave', () => el.classList.remove('drag-over'));
        el.addEventListener('drop', e => {
            e.preventDefault(); e.stopPropagation();
            el.classList.remove('drag-over');
            const fromId = parseInt(e.dataTransfer.getData('reorder-id'));
            const toId = parseInt(el.dataset.id);
            const newType = e.dataTransfer.getData('block-type');

            if (newType) { // Dropping new block from palette
                const toIdx = blocks.findIndex(b => b.id === toId);
                addBlock(newType, toIdx);
            } else if (fromId && fromId !== toId) {
                const fromIdx = blocks.findIndex(b => b.id === fromId);
                const toIdx = blocks.findIndex(b => b.id === toId);
                const [moved] = blocks.splice(fromIdx, 1);
                blocks.splice(toIdx, 0, moved);
                renderCanvas();
            }
        });
    });
}

function renderBlockContent(b) {
    const bg = b.bgColor || '#ffffff';
    const pad = b.padding || '16px 20px';
    switch (b.type) {
        case 'text': return '<div class="cb-text" style="background:' + bg + ';padding:' + pad + '"><div contenteditable="true" oninput="saveInlineEdits()">' + b.content + '</div></div>';
        case 'heading': return '<div class="cb-heading" style="background:' + bg + ';padding:' + pad + '"><div contenteditable="true" style="font-size:' + (b.fontSize||'22px') + ';color:' + (b.color||'#333') + '" oninput="saveInlineEdits()">' + b.content + '</div></div>';
        case 'image': return '<div class="cb-image" style="background:' + bg + ';padding:' + pad + '">' + (b.src ? '<img src="' + b.src + '" alt="' + b.alt + '" style="width:' + (b.width||'100%') + '">' : '<div style="padding:30px;background:#f0f0f0;color:#999;border-radius:4px;">Click to set image URL</div>') + '</div>';
        case 'button': return '<div class="cb-button" style="padding:' + pad + ';text-align:' + (b.align||'center') + '"><a href="#" style="background:' + (b.bgColor||'#4FC3F7') + ';color:' + (b.textColor||'#fff') + ';border-radius:' + (b.borderRadius||'6px') + ';padding:12px 28px;display:inline-block;font-weight:600;font-size:14px;text-decoration:none;">' + b.text + '</a></div>';
        case 'divider': return '<div class="cb-divider" style="padding:' + pad + '"><hr style="border:none;border-top:' + (b.thickness||'1px') + ' solid ' + (b.color||'#ddd') + '"></div>';
        case 'spacer': return '<div class="cb-spacer" style="height:' + (b.height||'30px') + ';"></div>';
        case 'columns': return '<div class="cb-columns" style="background:' + bg + ';padding:' + pad + '"><div class="col"><div contenteditable="true" oninput="saveInlineEdits()">' + b.left + '</div></div><div class="col"><div contenteditable="true" oninput="saveInlineEdits()">' + b.right + '</div></div></div>';
        default: return '<div style="padding:16px;color:#999;">Unknown block</div>';
    }
}

// ===== PROPERTIES PANEL =====
function showProperties(id) {
    const b = blocks.find(x => x.id === id);
    if (!b) return;
    const panel = document.getElementById('propsPanel');
    const title = document.getElementById('propsTitle');
    const body = document.getElementById('propsBody');
    panel.style.display = '';
    title.textContent = b.type.charAt(0).toUpperCase() + b.type.slice(1) + ' Properties';

    let html = '';
    if (b.type === 'text' || b.type === 'heading' || b.type === 'columns') {
        html += field('Background', 'color', 'bgColor', b.bgColor || '#ffffff');
        html += field('Padding', 'text', 'padding', b.padding || '16px 20px');
    }
    if (b.type === 'heading') {
        html += field('Font Size', 'text', 'fontSize', b.fontSize || '22px');
        html += field('Color', 'color', 'color', b.color || '#333333');
    }
    if (b.type === 'image') {
        html += field('Image URL', 'text', 'src', b.src || '');
        html += field('Alt Text', 'text', 'alt', b.alt || '');
        html += field('Link URL', 'text', 'link', b.link || '');
        html += field('Width', 'text', 'width', b.width || '100%');
        html += field('Background', 'color', 'bgColor', b.bgColor || '#ffffff');
    }
    if (b.type === 'button') {
        html += field('Button Text', 'text', 'text', b.text || 'Click Here');
        html += field('URL', 'text', 'url', b.url || '#');
        html += field('Button Color', 'color', 'bgColor', b.bgColor || '#4FC3F7');
        html += field('Text Color', 'color', 'textColor', b.textColor || '#ffffff');
        html += field('Border Radius', 'text', 'borderRadius', b.borderRadius || '6px');
        html += '<div class="form-group" style="margin:0 0 8px;"><label style="font-size:0.7rem;">Align</label><select onchange="updateProp(\'' + id + '\',\'align\',this.value)" style="width:100%;padding:6px;background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;"><option value="left"' + (b.align === 'left' ? ' selected' : '') + '>Left</option><option value="center"' + (b.align !== 'left' && b.align !== 'right' ? ' selected' : '') + '>Center</option><option value="right"' + (b.align === 'right' ? ' selected' : '') + '>Right</option></select></div>';
    }
    if (b.type === 'divider') {
        html += field('Color', 'color', 'color', b.color || '#dddddd');
        html += field('Thickness', 'text', 'thickness', b.thickness || '1px');
    }
    if (b.type === 'spacer') {
        html += field('Height', 'text', 'height', b.height || '30px');
    }
    body.innerHTML = html;
}

function field(label, type, prop, value) {
    const inputType = type === 'color' ? 'color' : 'text';
    const style = type === 'color' ? 'width:100%;height:32px;padding:2px;cursor:pointer;' : 'width:100%;padding:6px;font-size:0.8rem;';
    return '<div class="form-group" style="margin:0 0 8px;"><label style="font-size:0.7rem;">' + label + '</label><input type="' + inputType + '" value="' + value + '" style="' + style + 'background:var(--bg-input);border:1px solid var(--border);color:var(--text);border-radius:4px;" onchange="updateProp(' + selectedBlockId + ',\'' + prop + '\',this.value)"></div>';
}

function updateProp(id, prop, value) {
    const b = blocks.find(x => x.id === id);
    if (b) { b[prop] = value; renderCanvas(); }
}

// ===== OUTPUT =====
function blocksToHtml() {
    let html = '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;font-family:Inter,Arial,sans-serif;">';
    blocks.forEach(b => {
        html += '<tr><td>';
        const bg = b.bgColor || '#ffffff';
        const pad = b.padding || '16px 20px';
        switch (b.type) {
            case 'text': html += '<div style="background:' + bg + ';padding:' + pad + ';font-size:14px;line-height:1.6;color:#333;">' + b.content + '</div>'; break;
            case 'heading': html += '<div style="background:' + bg + ';padding:' + pad + '"><h2 style="margin:0;font-size:' + (b.fontSize||'22px') + ';color:' + (b.color||'#333') + ';font-weight:700;">' + b.content + '</h2></div>'; break;
            case 'image':
                let img = '<img src="' + b.src + '" alt="' + (b.alt||'') + '" style="width:' + (b.width||'100%') + ';display:block;border-radius:4px;">';
                if (b.link) img = '<a href="' + b.link + '">' + img + '</a>';
                html += '<div style="background:' + bg + ';padding:' + pad + ';text-align:center;">' + img + '</div>';
                break;
            case 'button': html += '<div style="padding:' + pad + ';text-align:' + (b.align||'center') + ';"><a href="' + (b.url||'#') + '" style="display:inline-block;padding:12px 28px;background:' + (b.bgColor||'#4FC3F7') + ';color:' + (b.textColor||'#fff') + ';border-radius:' + (b.borderRadius||'6px') + ';font-weight:600;font-size:14px;text-decoration:none;">' + (b.text||'Click') + '</a></div>'; break;
            case 'divider': html += '<div style="padding:' + pad + '"><hr style="border:none;border-top:' + (b.thickness||'1px') + ' solid ' + (b.color||'#ddd') + ';"></div>'; break;
            case 'spacer': html += '<div style="height:' + (b.height||'30px') + ';"></div>'; break;
            case 'columns': html += '<div style="background:' + bg + ';padding:' + pad + ';"><table width="100%" cellpadding="0" cellspacing="0"><tr><td width="50%" style="vertical-align:top;padding-right:8px;font-size:14px;line-height:1.6;color:#333;">' + b.left + '</td><td width="50%" style="vertical-align:top;padding-left:8px;font-size:14px;line-height:1.6;color:#333;">' + b.right + '</td></tr></table></div>'; break;
        }
        html += '</td></tr>';
    });
    html += '</table>';
    return html;
}

function syncContent() {
    saveInlineEdits();
    // Store blocks JSON (for re-editing) and also generate HTML
    const blocksJson = JSON.stringify(blocks);
    document.getElementById('contentHidden').value = blocksJson;
}

// ===== PREVIEW / HTML SOURCE =====
function togglePreview() {
    saveInlineEdits();
    const preview = document.getElementById('emailPreview');
    const canvas = document.getElementById('emailCanvas');
    const source = document.getElementById('htmlSourcePanel');
    source.style.display = 'none';

    if (preview.style.display === 'none') {
        const html = blocksToHtml();
        const frame = document.getElementById('previewFrame');
        frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{margin:0;padding:20px;background:#f5f5f5;font-family:Inter,Arial,sans-serif;}img{max-width:100%;}a{color:#4FC3F7;}</style></head><body>' + html + '</body></html>';
        preview.style.display = '';
        canvas.style.display = 'none';
    } else {
        preview.style.display = 'none';
        canvas.style.display = '';
    }
}

function toggleHtmlSource() {
    saveInlineEdits();
    const source = document.getElementById('htmlSourcePanel');
    const canvas = document.getElementById('emailCanvas');
    const preview = document.getElementById('emailPreview');
    preview.style.display = 'none';

    if (source.style.display === 'none') {
        document.getElementById('htmlSourceCode').value = blocksToHtml();
        source.style.display = '';
        canvas.style.display = 'none';
    } else {
        source.style.display = 'none';
        canvas.style.display = '';
    }
}

// ===== OTHER =====
document.getElementById('statusSelect').addEventListener('change', function() {
    document.getElementById('scheduleGroup').style.display = this.value === 'scheduled' ? 'flex' : 'none';
});

function updateAudienceOptions() {
    const type = document.getElementById('audienceType').value;
    document.getElementById('segmentSelect').style.display = type === 'segment' ? 'flex' : 'none';
    document.getElementById('tagSelect').style.display = type === 'tag' ? 'flex' : 'none';
}

// ===== AI CONTENT WRITER =====
function runAiGenerate() {
    const mode = document.getElementById('aiMode').value;
    const tone = document.getElementById('aiTone').value;
    const prompt = document.getElementById('aiPrompt').value.trim();
    const btn = document.getElementById('aiGenerateBtn');

    // For rewrite mode, grab current content
    let existingText = '';
    if (mode === 'rewrite') {
        saveInlineEdits();
        if (selectedBlockId) {
            const b = blocks.find(x => x.id === selectedBlockId);
            if (b && b.content) existingText = b.content;
            else if (b && b.left) existingText = b.left + '\n' + b.right;
        }
        if (!existingText) {
            existingText = blocks.filter(b => b.type === 'text' || b.type === 'heading').map(b => b.content).join('\n');
        }
        if (!existingText) { alert('No text content to rewrite. Add text blocks first.'); return; }
    }

    if (!prompt && mode !== 'rewrite') { alert('Enter a prompt describing what to generate.'); return; }

    btn.textContent = 'Generating...';
    btn.disabled = true;

    fetch('api/email?action=ai_generate', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            mode, tone, prompt, existing_text: existingText,
            csrf_token: '<?= generateCSRFToken() ?>'
        })
    })
    .then(r => r.json())
    .then(d => {
        btn.textContent = 'Generate';
        btn.disabled = false;
        const resultDiv = document.getElementById('aiResult');
        const contentDiv = document.getElementById('aiResultContent');
        const actionsDiv = document.getElementById('aiResultActions');

        if (!d.success) {
            contentDiv.innerHTML = '<span style="color:var(--red);">' + (d.error || 'Failed') + '</span>';
            actionsDiv.innerHTML = '';
            resultDiv.style.display = '';
            return;
        }

        resultDiv.style.display = '';

        if (mode === 'subject') {
            // Show subject lines as clickable options
            const lines = d.content.split('\n').filter(l => l.trim());
            contentDiv.innerHTML = '<strong style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;">Click to use:</strong>';
            actionsDiv.innerHTML = lines.map(line => {
                const clean = line.replace(/^\d+[\.\)]\s*/, '').trim();
                return '<button type="button" class="btn btn-secondary btn-sm" style="text-align:left;font-size:0.75rem;" onclick="document.getElementById(\'subjectInput\').value=this.textContent;document.getElementById(\'aiResult\').style.display=\'none\';">' + clean + '</button>';
            }).join('');
        } else {
            // Show generated body content
            contentDiv.innerHTML = '<div style="color:var(--text);">' + d.content.replace(/\n/g, '<br>') + '</div>';
            actionsDiv.innerHTML = '<button type="button" class="btn btn-primary btn-sm" onclick="insertAiContent()">Insert as Text Block</button>' +
                '<button type="button" class="btn btn-secondary btn-sm" onclick="replaceSelectedBlock()">Replace Selected Block</button>' +
                '<button type="button" class="btn btn-ghost btn-sm" onclick="navigator.clipboard.writeText(document.getElementById(\'aiResultContent\').innerText)">Copy</button>';
        }
    })
    .catch(() => {
        btn.textContent = 'Generate';
        btn.disabled = false;
    });
}

function insertAiContent() {
    const content = document.getElementById('aiResultContent').querySelector('div')?.innerHTML || document.getElementById('aiResultContent').innerText;
    addBlock('text');
    const newBlock = blocks[blocks.length - 1];
    newBlock.content = content;
    renderCanvas();
    document.getElementById('aiResult').style.display = 'none';
}

function replaceSelectedBlock() {
    if (!selectedBlockId) { alert('Select a block first.'); return; }
    const b = blocks.find(x => x.id === selectedBlockId);
    if (!b) return;
    const content = document.getElementById('aiResultContent').querySelector('div')?.innerHTML || document.getElementById('aiResultContent').innerText;
    if (b.type === 'text' || b.type === 'heading') {
        b.content = content;
    } else {
        b.type = 'text';
        b.content = content;
    }
    renderCanvas();
    document.getElementById('aiResult').style.display = 'none';
}
</script>

<?php renderFooter(); ?>
