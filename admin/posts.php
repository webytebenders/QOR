<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireRole('super_admin', 'editor');

$db = getDB();

try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_posts.sql')); } catch (Exception $e) {}

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

$categoryLabels = [
    'technology' => 'Technology',
    'security' => 'Security',
    'ecosystem' => 'Ecosystem',
    'announcements' => 'Announcements',
];

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
            <?php foreach ($categoryLabels as $key => $label): ?>
            <option value="<?= $key ?>" <?= $filterCategory === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= sanitize($filterStatus) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($search || $filterCategory): ?>
        <a href="posts.php<?= $filterStatus ? '?status=' . $filterStatus : '' ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <a href="post-edit.php" class="btn btn-primary">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        New Post
    </a>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
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
                    <tr><td colspan="6" class="empty-state">No posts yet. Create your first post!</td></tr>
                    <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                    <tr>
                        <td>
                            <div class="post-title-cell">
                                <?php if ($post['is_featured']): ?>
                                <span class="featured-star" title="Featured">&#9733;</span>
                                <?php endif; ?>
                                <a href="post-edit.php?id=<?= $post['id'] ?>" class="post-link"><?= sanitize($post['title']) ?></a>
                            </div>
                        </td>
                        <td><span class="cat-badge cat-<?= $post['category'] ?>"><?= $categoryLabels[$post['category']] ?? $post['category'] ?></span></td>
                        <td><span class="status-badge post-status-<?= $post['status'] ?>"><?= ucfirst($post['status']) ?></span></td>
                        <td><?= sanitize($post['author_name']) ?></td>
                        <td title="<?= $post['created_at'] ?>"><?= $post['published_at'] ? date('M j, Y', strtotime($post['published_at'])) : timeAgo($post['created_at']) ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="post-edit.php?id=<?= $post['id'] ?>" class="btn-icon" title="Edit">
                                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                                </a>
                                <form method="POST" action="api/posts.php?action=toggle_featured" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $post['id'] ?>">
                                    <button type="submit" class="btn-icon" title="<?= $post['is_featured'] ? 'Unfeature' : 'Set as Featured' ?>">
                                        <svg viewBox="0 0 20 20" fill="<?= $post['is_featured'] ? '#F97316' : 'currentColor' ?>" width="16" height="16"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    </button>
                                </form>
                                <form method="POST" action="api/posts.php?action=delete" style="display:inline" onsubmit="return confirm('Delete this post?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $post['id'] ?>">
                                    <button type="submit" class="btn-icon" title="Delete">
                                        <svg viewBox="0 0 20 20" fill="#ef4444" width="16" height="16"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                    </button>
                                </form>
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
    <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>&status=<?= urlencode($filterStatus) ?>&category=<?= urlencode($filterCategory) ?>&search=<?= urlencode($search) ?>" class="btn btn-secondary btn-sm">Previous</a><?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?>&status=<?= urlencode($filterStatus) ?>&category=<?= urlencode($filterCategory) ?>&search=<?= urlencode($search) ?>" class="btn btn-secondary btn-sm">Next</a><?php endif; ?>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
