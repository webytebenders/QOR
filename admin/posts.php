<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireRole('super_admin', 'editor');

$db = getDB();

// Run schema
$schema = file_get_contents(__DIR__ . '/includes/schema_posts.sql');
foreach (explode(';', $schema) as $sql) {
    $sql = trim($sql);
    if ($sql) { try { $db->exec($sql); } catch (Exception $e) {} }
}

// Load categories from DB
$categories = $db->query('SELECT * FROM post_categories ORDER BY sort_order, name')->fetchAll();
$categoryMap = [];
foreach ($categories as $cat) { $categoryMap[$cat['slug']] = $cat; }

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterCategory = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($filterStatus) { $where[] = 'p.status = ?'; $params[] = $filterStatus; }
if ($filterCategory) { $where[] = 'p.category = ?'; $params[] = $filterCategory; }
if ($search) { $where[] = '(p.title LIKE ? OR p.excerpt LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM posts p {$whereSQL}");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT p.*, a.name as author_name FROM posts p JOIN admins a ON p.author_id = a.id {$whereSQL} ORDER BY p.created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Stats
$countAll = $db->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$countPublished = $db->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
$countDraft = $db->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
$countScheduled = $db->query("SELECT COUNT(*) FROM posts WHERE status = 'scheduled'")->fetchColumn();

$qs = http_build_query(array_filter(['search' => $search, 'category' => $filterCategory, 'status' => $filterStatus]));

renderHeader('Blog Posts', 'blog');
?>

<div class="stats-row">
    <a href="posts.php" class="stat-widget stat-clickable <?= !$filterStatus ? 'stat-active' : '' ?>">
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countAll ?></span>
            <span class="stat-widget-label">All Posts</span>
        </div>
    </a>
    <a href="posts.php?status=published" class="stat-widget stat-clickable <?= $filterStatus === 'published' ? 'stat-active' : '' ?>">
        <div class="stat-widget-icon green"><svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countPublished ?></span>
            <span class="stat-widget-label">Published</span>
        </div>
    </a>
    <a href="posts.php?status=draft" class="stat-widget stat-clickable <?= $filterStatus === 'draft' ? 'stat-active' : '' ?>">
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countDraft ?></span>
            <span class="stat-widget-label">Drafts</span>
        </div>
    </a>
    <a href="posts.php?status=scheduled" class="stat-widget stat-clickable <?= $filterStatus === 'scheduled' ? 'stat-active' : '' ?>">
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countScheduled ?></span>
            <span class="stat-widget-label">Scheduled</span>
        </div>
    </a>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <input type="text" name="search" placeholder="Search posts..." value="<?= sanitize($search) ?>" class="filter-input">
        <select name="category" class="filter-select">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= sanitize($cat['slug']) ?>" <?= $filterCategory === $cat['slug'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= sanitize($filterStatus) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($search || $filterCategory): ?>
        <a href="posts.php<?= $filterStatus ? '?status=' . $filterStatus : '' ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('catModal').classList.add('show')">
            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
            Categories
        </button>
        <a href="post-edit.php" class="btn btn-primary">
            <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
            New Post
        </a>
    </div>
</div>

<!-- Bulk Actions Bar -->
<div class="bulk-bar" id="bulkBar" style="display:none;">
    <span class="bulk-bar-count"><span id="bulkCount">0</span> selected</span>
    <div class="bulk-bar-actions">
        <select id="bulkStatusSelect" class="filter-select">
            <option value="">Change Status...</option>
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="scheduled">Scheduled</option>
        </select>
        <button type="button" class="btn btn-secondary btn-sm" onclick="bulkChangeStatus()">Apply</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="bulkDelete()">Delete Selected</button>
    </div>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Author</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($posts)): ?>
                    <tr><td colspan="7" class="empty-state">No posts yet. Create your first post!</td></tr>
                    <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                    <?php $catInfo = $categoryMap[$post['category']] ?? null; ?>
                    <tr>
                        <td><input type="checkbox" class="row-check" value="<?= $post['id'] ?>" onchange="updateBulkBar()"></td>
                        <td>
                            <div class="post-title-cell">
                                <?php if ($post['is_featured']): ?><span class="featured-star" title="Featured">&#9733;</span><?php endif; ?>
                                <a href="post-edit.php?id=<?= $post['id'] ?>" class="post-link"><?= sanitize($post['title']) ?></a>
                            </div>
                        </td>
                        <td>
                            <?php if ($catInfo): ?>
                            <span class="cat-badge" style="background:<?= $catInfo['color'] ?>15;color:<?= $catInfo['color'] ?>"><?= sanitize($catInfo['name']) ?></span>
                            <?php else: ?>
                            <span class="cat-badge" style="background:rgba(255,255,255,0.05);color:#999"><?= sanitize($post['category']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status-badge post-status-<?= $post['status'] ?>"><?= ucfirst($post['status']) ?></span></td>
                        <td><?= sanitize($post['author_name']) ?></td>
                        <td title="<?= $post['created_at'] ?>"><?= $post['published_at'] ? date('M j, Y', strtotime($post['published_at'])) : timeAgo($post['created_at']) ?></td>
                        <td>
                            <div class="action-dropdown">
                                <button class="btn-icon" onclick="toggleDropdown(this)" title="Actions">
                                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                                </button>
                                <div class="dropdown-menu">
                                    <a href="post-edit.php?id=<?= $post['id'] ?>" class="dropdown-item">
                                        <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                                        Edit
                                    </a>
                                    <?php if ($post['status'] === 'published'): ?>
                                    <a href="../blog-post.html?slug=<?= urlencode($post['slug']) ?>" target="_blank" class="dropdown-item">
                                        <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/></svg>
                                        View on Site
                                    </a>
                                    <?php endif; ?>
                                    <form method="POST" action="api/posts.php?action=duplicate" style="display:contents">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="id" value="<?= $post['id'] ?>">
                                        <button type="submit" class="dropdown-item">
                                            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"/><path d="M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h8a2 2 0 00-2-2H5z"/></svg>
                                            Duplicate
                                        </button>
                                    </form>
                                    <form method="POST" action="api/posts.php?action=toggle_featured" style="display:contents">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="id" value="<?= $post['id'] ?>">
                                        <button type="submit" class="dropdown-item">
                                            <svg viewBox="0 0 20 20" fill="<?= $post['is_featured'] ? '#F97316' : 'currentColor' ?>" width="14" height="14"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                            <?= $post['is_featured'] ? 'Unfeature' : 'Set as Featured' ?>
                                        </button>
                                    </form>
                                    <div class="dropdown-divider"></div>
                                    <span class="dropdown-label">Change Status</span>
                                    <?php foreach (['draft','published','scheduled'] as $s): ?>
                                    <form method="POST" action="api/posts.php?action=bulk_status" style="display:contents">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="ids[]" value="<?= $post['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $s ?>">
                                        <button type="submit" class="dropdown-item <?= $post['status'] === $s ? 'dropdown-item-active' : '' ?>">
                                            <span class="status-dot status-dot-<?= $s === 'published' ? 'replied' : ($s === 'draft' ? 'archived' : 'new') ?>"></span>
                                            <?= ucfirst($s) ?>
                                        </button>
                                    </form>
                                    <?php endforeach; ?>
                                    <div class="dropdown-divider"></div>
                                    <form method="POST" action="api/posts.php?action=delete" style="display:contents" onsubmit="return confirm('Delete this post?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="id" value="<?= $post['id'] ?>">
                                        <button type="submit" class="dropdown-item dropdown-item-danger">
                                            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>&<?= $qs ?>" class="btn btn-secondary btn-sm">Previous</a><?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?>&<?= $qs ?>" class="btn btn-secondary btn-sm">Next</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- Category Management Modal -->
<div class="modal" id="catModal">
    <div class="modal-overlay" onclick="this.parentElement.classList.remove('show')"></div>
    <div class="modal-content" style="max-width:520px">
        <div class="modal-header">
            <h3>Manage Categories</h3>
            <button class="modal-close" onclick="document.getElementById('catModal').classList.remove('show')">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Existing categories -->
            <?php foreach ($categories as $cat): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.06)">
                <span style="width:14px;height:14px;border-radius:50%;background:<?= $cat['color'] ?>;flex-shrink:0"></span>
                <span style="flex:1;font-size:0.85rem"><?= sanitize($cat['name']) ?></span>
                <span style="font-size:0.75rem;color:var(--text-muted)"><?= $db->query("SELECT COUNT(*) FROM posts WHERE category = '" . $cat['slug'] . "'")->fetchColumn() ?> posts</span>
                <button class="btn-icon" title="Edit" onclick="editCategory(<?= $cat['id'] ?>, <?= htmlspecialchars(json_encode($cat['name']), ENT_QUOTES) ?>, '<?= $cat['color'] ?>')">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                </button>
                <form method="POST" action="api/posts.php?action=delete_category" style="display:inline" onsubmit="return confirm('Delete category? Posts will be moved.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                    <button type="submit" class="btn-icon" title="Delete">
                        <svg viewBox="0 0 20 20" fill="#ef4444" width="14" height="14"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>

            <!-- Add / Edit form -->
            <form method="POST" action="api/posts.php?action=save_category" style="margin-top:16px">
                <?= csrfField() ?>
                <input type="hidden" name="cat_id" id="catEditId" value="0">
                <div style="display:flex;gap:8px;align-items:flex-end">
                    <div class="form-group" style="flex:1">
                        <label id="catFormLabel">Add Category</label>
                        <input type="text" name="cat_name" id="catEditName" placeholder="Category name" required>
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <input type="color" name="cat_color" id="catEditColor" value="#4FC3F7" style="width:46px;height:38px;padding:2px;cursor:pointer;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm)">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="height:38px">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk action forms -->
<form method="POST" action="api/posts.php?action=bulk_delete" id="bulkDeleteForm" style="display:none">
    <?= csrfField() ?>
    <div id="bulkDeleteIds"></div>
</form>
<form method="POST" action="api/posts.php?action=bulk_status" id="bulkStatusForm" style="display:none">
    <?= csrfField() ?>
    <div id="bulkStatusIds"></div>
    <input type="hidden" name="status" id="bulkStatusValue">
</form>

<script>
function toggleDropdown(btn) {
    document.querySelectorAll('.dropdown-menu.show').forEach(m => {
        if (m !== btn.nextElementSibling) m.classList.remove('show');
    });
    btn.nextElementSibling.classList.toggle('show');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.action-dropdown')) {
        document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    }
});

function toggleSelectAll(el) {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = el.checked);
    updateBulkBar();
}
function updateBulkBar() {
    const checked = document.querySelectorAll('.row-check:checked');
    document.getElementById('bulkCount').textContent = checked.length;
    document.getElementById('bulkBar').style.display = checked.length > 0 ? 'flex' : 'none';
    const all = document.querySelectorAll('.row-check');
    document.getElementById('selectAll').checked = all.length > 0 && checked.length === all.length;
}
function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
}
function bulkDelete() {
    const ids = getSelectedIds();
    if (!ids.length || !confirm('Delete ' + ids.length + ' posts?')) return;
    const c = document.getElementById('bulkDeleteIds');
    c.innerHTML = '';
    ids.forEach(id => { const i = document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=id; c.appendChild(i); });
    document.getElementById('bulkDeleteForm').submit();
}
function bulkChangeStatus() {
    const ids = getSelectedIds();
    const status = document.getElementById('bulkStatusSelect').value;
    if (!ids.length || !status) { alert('Select posts and a status.'); return; }
    const c = document.getElementById('bulkStatusIds');
    c.innerHTML = '';
    ids.forEach(id => { const i = document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=id; c.appendChild(i); });
    document.getElementById('bulkStatusValue').value = status;
    document.getElementById('bulkStatusForm').submit();
}

function editCategory(id, name, color) {
    document.getElementById('catEditId').value = id;
    document.getElementById('catEditName').value = name;
    document.getElementById('catEditColor').value = color;
    document.getElementById('catFormLabel').textContent = 'Edit Category';
    document.getElementById('catModal').classList.add('show');
}
</script>

<?php renderFooter(); ?>
