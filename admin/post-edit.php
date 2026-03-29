<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireRole('super_admin', 'editor');

$db = getDB();
$schema = file_get_contents(__DIR__ . '/includes/schema_posts.sql');
foreach (explode(';', $schema) as $sql) {
    $sql = trim($sql);
    if ($sql) { try { $db->exec($sql); } catch (Exception $e) {} }
}

$id = (int)($_GET['id'] ?? 0);
$post = null;

if ($id) {
    $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) {
        setFlash('error', 'Post not found.');
        redirect('posts');
    }
}

// Load categories from DB
$categories = $db->query('SELECT * FROM post_categories ORDER BY sort_order, name')->fetchAll();

$pageTitle = $post ? 'Edit Post' : 'New Post';

renderHeader($pageTitle, 'blog');
?>

<div class="msg-back">
    <a href="posts" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Posts
    </a>
    <?php if ($post && $post['status'] === 'published'): ?>
    <a href="/blog-post?slug=<?= urlencode($post['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>
        View on Site
    </a>
    <?php endif; ?>
</div>

<form method="POST" action="api/posts?action=save" class="post-editor">
    <?= csrfField() ?>
    <?php if ($post): ?><input type="hidden" name="id" value="<?= $post['id'] ?>"><?php endif; ?>

    <div class="editor-grid">
        <!-- Main Content -->
        <div class="editor-main">
            <div class="card">
                <div class="card-body" style="display:flex;flex-direction:column;gap:20px">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" value="<?= sanitize($post['title'] ?? '') ?>" placeholder="Post title..." required class="editor-title-input" id="titleInput">
                    </div>

                    <div class="form-group">
                        <label>Slug</label>
                        <div class="slug-input-wrap">
                            <span class="slug-prefix">/blog/</span>
                            <input type="text" name="slug" value="<?= sanitize($post['slug'] ?? '') ?>" placeholder="auto-generated-from-title" id="slugInput">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Excerpt</label>
                        <textarea name="excerpt" rows="2" placeholder="Short summary for cards and previews..."><?= sanitize($post['excerpt'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Content</label>
                        <div class="editor-toolbar">
                            <button type="button" class="toolbar-btn" onclick="execCmd('bold')" title="Bold"><strong>B</strong></button>
                            <button type="button" class="toolbar-btn" onclick="execCmd('italic')" title="Italic"><em>I</em></button>
                            <button type="button" class="toolbar-btn" onclick="execCmd('underline')" title="Underline"><u>U</u></button>
                            <span class="toolbar-sep"></span>
                            <button type="button" class="toolbar-btn" onclick="execCmd('formatBlock', 'h2')" title="Heading 2">H2</button>
                            <button type="button" class="toolbar-btn" onclick="execCmd('formatBlock', 'h3')" title="Heading 3">H3</button>
                            <button type="button" class="toolbar-btn" onclick="execCmd('formatBlock', 'p')" title="Paragraph">P</button>
                            <span class="toolbar-sep"></span>
                            <button type="button" class="toolbar-btn" onclick="execCmd('insertUnorderedList')" title="Bullet List">&bull;</button>
                            <button type="button" class="toolbar-btn" onclick="execCmd('insertOrderedList')" title="Numbered List">1.</button>
                            <span class="toolbar-sep"></span>
                            <button type="button" class="toolbar-btn" onclick="insertLink()" title="Link">&#128279;</button>
                            <button type="button" class="toolbar-btn" onclick="execCmd('formatBlock', 'blockquote')" title="Quote">&ldquo;</button>
                            <span class="toolbar-sep"></span>
                            <button type="button" class="toolbar-btn" onclick="insertImage()" title="Insert Image">&#128247;</button>
                            <button type="button" class="toolbar-btn" onclick="toggleSource()" title="View Source" id="sourceToggle">&lt;/&gt;</button>
                        </div>
                        <div class="editor-content" id="editorContent" contenteditable="true"><?= $post['content'] ?? '<p>Start writing your post...</p>' ?></div>
                        <textarea name="content" id="contentHidden" style="display:none"><?= htmlspecialchars($post['content'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="editor-sidebar">
            <!-- Publish Card -->
            <div class="card">
                <div class="card-header"><h2>Publish</h2></div>
                <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="statusSelect">
                            <option value="draft" <?= ($post['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($post['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="scheduled" <?= ($post['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        </select>
                    </div>

                    <div class="form-group" id="scheduleGroup" style="display:<?= ($post['status'] ?? '') === 'scheduled' ? 'flex' : 'none' ?>">
                        <label>Schedule Date</label>
                        <input type="datetime-local" name="scheduled_at" value="<?= $post && $post['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($post['scheduled_at'])) : '' ?>">
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category">
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= sanitize($cat['slug']) ?>" <?= ($post['category'] ?? 'technology') === $cat['slug'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_featured" value="1" <?= ($post['is_featured'] ?? 0) ? 'checked' : '' ?>>
                            <span>Featured Post</span>
                        </label>
                    </div>

                    <div class="editor-actions">
                        <button type="submit" class="btn btn-primary btn-full" onclick="syncContent()">
                            <?= $post ? 'Update Post' : 'Create Post' ?>
                        </button>
                    </div>

                    <?php if ($post): ?>
                    <div class="editor-meta">
                        <span>Created: <?= date('M j, Y', strtotime($post['created_at'])) ?></span>
                        <?php if ($post['published_at']): ?>
                        <span>Published: <?= date('M j, Y', strtotime($post['published_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Thumbnail Card -->
            <div class="card" style="margin-top:16px">
                <div class="card-header"><h2>Thumbnail</h2></div>
                <div class="card-body">
                    <input type="hidden" name="thumbnail" id="thumbnailInput" value="<?= sanitize($post['thumbnail'] ?? '') ?>">
                    <div id="thumbnailPreview" style="margin-bottom:12px">
                        <?php if (!empty($post['thumbnail'])): ?>
                        <img src="../<?= sanitize($post['thumbnail']) ?>" style="width:100%;border-radius:var(--radius-sm);border:1px solid var(--border)">
                        <?php else: ?>
                        <div style="padding:24px;text-align:center;border:2px dashed var(--border);border-radius:var(--radius-sm);color:var(--text-muted);font-size:0.8rem">No thumbnail selected</div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:8px">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="openMediaPicker()" style="flex:1">
                            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
                            Choose Image
                        </button>
                        <?php if (!empty($post['thumbnail'])): ?>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="clearThumbnail()">Remove</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Media Picker Modal -->
<div class="modal" id="mediaPickerModal">
    <div class="modal-overlay" onclick="closeMediaPicker()"></div>
    <div class="modal-content" style="max-width:680px">
        <div class="modal-header">
            <h3>Select Image</h3>
            <button class="modal-close" onclick="closeMediaPicker()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="mediaPickerGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;max-height:400px;overflow-y:auto">
                <div style="padding:20px;text-align:center;color:var(--text-muted);grid-column:1/-1">Loading images...</div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-generate slug
const titleInput = document.getElementById('titleInput');
const slugInput = document.getElementById('slugInput');
let slugEdited = <?= $post ? 'true' : 'false' ?>;

slugInput.addEventListener('input', () => { slugEdited = true; });
titleInput.addEventListener('input', () => {
    if (!slugEdited) {
        slugInput.value = titleInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }
});

// Schedule toggle
document.getElementById('statusSelect').addEventListener('change', function() {
    document.getElementById('scheduleGroup').style.display = this.value === 'scheduled' ? 'flex' : 'none';
});

// Rich text editor
function execCmd(cmd, value) {
    document.execCommand(cmd, false, value || null);
    document.getElementById('editorContent').focus();
}

function insertLink() {
    const url = prompt('Enter URL:');
    if (url) document.execCommand('createLink', false, url);
}

function insertImage() {
    const url = prompt('Enter image URL:');
    if (url) document.execCommand('insertImage', false, url);
}

let sourceMode = false;
function toggleSource() {
    const editor = document.getElementById('editorContent');
    const btn = document.getElementById('sourceToggle');
    if (sourceMode) {
        editor.innerHTML = editor.innerText;
        btn.style.color = '';
        sourceMode = false;
    } else {
        editor.innerText = editor.innerHTML;
        btn.style.color = 'var(--blue)';
        sourceMode = true;
    }
}

function syncContent() {
    const editor = document.getElementById('editorContent');
    const hidden = document.getElementById('contentHidden');
    hidden.value = sourceMode ? editor.innerText : editor.innerHTML;
}

document.querySelector('.post-editor').addEventListener('submit', syncContent);

// Media Picker
function openMediaPicker() {
    document.getElementById('mediaPickerModal').classList.add('show');
    fetch('api/posts?action=media_list')
        .then(r => r.json())
        .then(data => {
            const grid = document.getElementById('mediaPickerGrid');
            if (!data.images || data.images.length === 0) {
                grid.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);grid-column:1/-1">No images uploaded yet. Upload images in Media Library first.</div>';
                return;
            }
            grid.innerHTML = '';
            data.images.forEach(img => {
                const url = 'uploads/' + img.filename;
                const div = document.createElement('div');
                div.style.cssText = 'cursor:pointer;border-radius:6px;overflow:hidden;border:2px solid transparent;transition:border-color 0.2s';
                div.innerHTML = '<img src="../' + url + '" style="width:100%;height:90px;object-fit:cover" alt="' + (img.alt_text || img.original_name) + '">';
                div.addEventListener('mouseenter', () => div.style.borderColor = '#4FC3F7');
                div.addEventListener('mouseleave', () => div.style.borderColor = 'transparent');
                div.addEventListener('click', () => selectThumbnail(url));
                grid.appendChild(div);
            });
        });
}

function closeMediaPicker() {
    document.getElementById('mediaPickerModal').classList.remove('show');
}

function selectThumbnail(url) {
    document.getElementById('thumbnailInput').value = url;
    document.getElementById('thumbnailPreview').innerHTML = '<img src="../' + url + '" style="width:100%;border-radius:var(--radius-sm);border:1px solid var(--border)">';
    closeMediaPicker();
}

function clearThumbnail() {
    document.getElementById('thumbnailInput').value = '';
    document.getElementById('thumbnailPreview').innerHTML = '<div style="padding:24px;text-align:center;border:2px dashed var(--border);border-radius:var(--radius-sm);color:var(--text-muted);font-size:0.8rem">No thumbnail selected</div>';
}
</script>

<?php renderFooter(); ?>
