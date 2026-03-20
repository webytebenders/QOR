<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireRole('super_admin', 'editor');

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_posts.sql')); } catch (Exception $e) {}

$id = (int)($_GET['id'] ?? 0);
$post = null;

if ($id) {
    $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) {
        setFlash('error', 'Post not found.');
        redirect('posts.php');
    }
}

$pageTitle = $post ? 'Edit Post' : 'New Post';
$categoryLabels = [
    'technology' => 'Technology',
    'security' => 'Security',
    'ecosystem' => 'Ecosystem',
    'announcements' => 'Announcements',
];

renderHeader($pageTitle, 'blog');
?>

<div class="msg-back">
    <a href="posts.php" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Posts
    </a>
</div>

<form method="POST" action="api/posts.php?action=save" class="post-editor">
    <?= csrfField() ?>
    <?php if ($post): ?><input type="hidden" name="id" value="<?= $post['id'] ?>"><?php endif; ?>

    <div class="editor-grid">
        <!-- Main Content -->
        <div class="editor-main">
            <div class="card">
                <div class="card-body">
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
            <div class="card">
                <div class="card-header"><h2>Publish</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="statusSelect">
                            <option value="draft" <?= ($post['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($post['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="scheduled" <?= ($post['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        </select>
                    </div>

                    <div class="form-group" id="scheduleGroup" style="display:<?= ($post['status'] ?? '') === 'scheduled' ? 'block' : 'none' ?>">
                        <label>Schedule Date</label>
                        <input type="datetime-local" name="scheduled_at" value="<?= $post['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($post['scheduled_at'])) : '' ?>">
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category">
                            <?php foreach ($categoryLabels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($post['category'] ?? 'technology') === $key ? 'selected' : '' ?>><?= $label ?></option>
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
        </div>
    </div>
</form>

<script>
// Auto-generate slug from title
const titleInput = document.getElementById('titleInput');
const slugInput = document.getElementById('slugInput');
let slugEdited = <?= $post ? 'true' : 'false' ?>;

slugInput.addEventListener('input', () => { slugEdited = true; });
titleInput.addEventListener('input', () => {
    if (!slugEdited) {
        slugInput.value = titleInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }
});

// Status → schedule toggle
document.getElementById('statusSelect').addEventListener('change', function() {
    document.getElementById('scheduleGroup').style.display = this.value === 'scheduled' ? 'block' : 'none';
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

// Sync on form submit
document.querySelector('.post-editor').addEventListener('submit', syncContent);
</script>

<?php renderFooter(); ?>
