<?php
/**
 * Posts API
 *
 * GET  (public):  ?action=list             — get published posts (JSON)
 * GET  (public):  ?action=get&slug=xxx     — get single post by slug
 * POST (admin):   ?action=save             — create/update post
 * POST (admin):   ?action=delete           — delete post
 * POST (admin):   ?action=bulk_delete      — delete multiple posts
 * POST (admin):   ?action=bulk_status      — change status of multiple posts
 * POST (admin):   ?action=toggle_featured  — toggle featured status
 * POST (admin):   ?action=duplicate        — duplicate a post
 * GET  (admin):   ?action=categories       — get all categories
 * POST (admin):   ?action=save_category    — add/edit category
 * POST (admin):   ?action=delete_category  — delete category
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Helper: run schema
function ensurePostSchema() {
    $db = getDB();
    $schema = file_get_contents(__DIR__ . '/../includes/schema_posts.sql');
    foreach (explode(';', $schema) as $sql) {
        $sql = trim($sql);
        if ($sql) { try { $db->exec($sql); } catch (Exception $e) {} }
    }
}

// Helper: get categories
function getCategories(): array {
    $db = getDB();
    try {
        return $db->query('SELECT * FROM post_categories ORDER BY sort_order, name')->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ===== PUBLIC: List published posts =====
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $db = getDB();
        ensurePostSchema();

        $category = $_GET['category'] ?? '';
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where = ["p.status = 'published'", "(p.published_at IS NULL OR p.published_at <= NOW())"];
        $params = [];

        if ($category) {
            $where[] = 'p.category = ?';
            $params[] = $category;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Get featured post
        $stmtFeat = $db->prepare("SELECT p.id, p.title, p.slug, p.excerpt, p.category, p.thumbnail, p.published_at, a.name as author FROM posts p JOIN admins a ON p.author_id = a.id WHERE p.is_featured = 1 AND p.status = 'published' ORDER BY p.published_at DESC LIMIT 1");
        $stmtFeat->execute();
        $featured = $stmtFeat->fetch();

        // Count
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM posts p {$whereSQL}");
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();

        // Posts
        $stmt = $db->prepare("SELECT p.id, p.title, p.slug, p.excerpt, p.category, p.thumbnail, p.published_at, a.name as author FROM posts p JOIN admins a ON p.author_id = a.id {$whereSQL} ORDER BY p.published_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $posts = $stmt->fetchAll();

        // Categories
        $categories = getCategories();

        jsonResponse([
            'success' => true,
            'featured' => $featured,
            'posts' => $posts,
            'categories' => $categories,
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'posts' => [], 'total' => 0]);
    }
}

// ===== PUBLIC: Get single post =====
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = $_GET['slug'] ?? '';
    if (!$slug) jsonResponse(['success' => false, 'message' => 'Slug required.'], 400);

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT p.*, a.name as author FROM posts p JOIN admins a ON p.author_id = a.id WHERE p.slug = ? AND p.status = 'published'");
        $stmt->execute([$slug]);
        $post = $stmt->fetch();

        if (!$post) jsonResponse(['success' => false, 'message' => 'Post not found.'], 404);

        jsonResponse(['success' => true, 'post' => $post]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Error loading post.'], 500);
    }
}

// ===== ADMIN: Save (create/update) =====
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../posts');
    }

    $id = (int)($_POST['id'] ?? 0);
    $title = sanitize($_POST['title'] ?? '');
    $slug = sanitize($_POST['slug'] ?? '');
    $excerpt = sanitize($_POST['excerpt'] ?? '');
    $content = $_POST['content'] ?? '';
    $category = sanitize($_POST['category'] ?? 'technology');
    $status = sanitize($_POST['status'] ?? 'draft');
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $scheduledAt = $_POST['scheduled_at'] ?? null;
    $thumbnail = sanitize($_POST['thumbnail'] ?? '');

    if (strlen($title) < 3) {
        setFlash('error', 'Title must be at least 3 characters.');
        redirect('../post-edit' . ($id ? '?id=' . $id : ''));
    }

    if (!$slug) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        $slug = trim($slug, '-');
    }

    $validStatuses = ['draft', 'published', 'scheduled'];
    if (!in_array($status, $validStatuses)) $status = 'draft';

    $publishedAt = null;
    if ($status === 'published') {
        $publishedAt = date('Y-m-d H:i:s');
    }
    if ($status === 'scheduled' && $scheduledAt) {
        $publishedAt = $scheduledAt;
    }

    if ($isFeatured) {
        $db = getDB();
        $db->exec('UPDATE posts SET is_featured = 0 WHERE is_featured = 1');
    }

    $db = getDB();
    $admin = getCurrentAdmin();

    try {
        if ($id) {
            $stmt = $db->prepare('UPDATE posts SET title=?, slug=?, excerpt=?, content=?, category=?, status=?, is_featured=?, thumbnail=?, published_at=COALESCE(?, published_at), scheduled_at=? WHERE id=?');
            $stmt->execute([$title, $slug, $excerpt, $content, $category, $status, $isFeatured, $thumbnail, $publishedAt, $scheduledAt, $id]);

            require_once '../includes/logger.php';
            logActivity($admin['id'], 'update_post', 'post', $id, ['title' => $title]);
            setFlash('success', "Post '{$title}' updated.");
        } else {
            $stmt = $db->prepare('INSERT INTO posts (title, slug, excerpt, content, category, status, is_featured, thumbnail, author_id, published_at, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$title, $slug, $excerpt, $content, $category, $status, $isFeatured, $thumbnail, $admin['id'], $publishedAt, $scheduledAt]);

            $newId = $db->lastInsertId();
            require_once '../includes/logger.php';
            logActivity($admin['id'], 'create_post', 'post', $newId, ['title' => $title]);
            setFlash('success', "Post '{$title}' created.");
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            setFlash('error', 'A post with this slug already exists.');
        } else {
            setFlash('error', 'Error saving post: ' . $e->getMessage());
        }
        redirect('../post-edit' . ($id ? '?id=' . $id : ''));
    }

    redirect('../posts');
}

// ===== ADMIN: Delete =====
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../posts'); }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $post = $db->prepare('SELECT title FROM posts WHERE id = ?');
        $post->execute([$id]);
        $post = $post->fetch();
        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'delete_post', 'post', $id, ['title' => $post['title'] ?? '']);
        setFlash('success', 'Post deleted.');
    }
    redirect('../posts');
}

// ===== ADMIN: Bulk Delete =====
if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../posts'); }

    $ids = $_POST['ids'] ?? [];
    if (!empty($ids) && is_array($ids)) {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $intIds = array_map('intval', $ids);
        $db->prepare("DELETE FROM posts WHERE id IN ({$placeholders})")->execute($intIds);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'bulk_delete_posts', 'post', null, ['count' => count($intIds)]);
        setFlash('success', count($intIds) . ' posts deleted.');
    }
    redirect('../posts');
}

// ===== ADMIN: Bulk Status =====
if ($action === 'bulk_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../posts'); }

    $ids = $_POST['ids'] ?? [];
    $newStatus = $_POST['status'] ?? '';
    $valid = ['draft', 'published', 'scheduled'];

    if (!empty($ids) && is_array($ids) && in_array($newStatus, $valid)) {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $intIds = array_map('intval', $ids);

        if ($newStatus === 'published') {
            $params = array_merge([$newStatus, date('Y-m-d H:i:s')], $intIds);
            $db->prepare("UPDATE posts SET status = ?, published_at = COALESCE(published_at, ?) WHERE id IN ({$placeholders})")->execute($params);
        } else {
            $params = array_merge([$newStatus], $intIds);
            $db->prepare("UPDATE posts SET status = ? WHERE id IN ({$placeholders})")->execute($params);
        }

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'bulk_update_post_status', 'post', null, ['count' => count($intIds), 'status' => $newStatus]);
        setFlash('success', count($intIds) . ' posts updated to ' . ucfirst($newStatus) . '.');
    }
    redirect('../posts');
}

// ===== ADMIN: Toggle Featured =====
if ($action === 'toggle_featured' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $db->exec('UPDATE posts SET is_featured = 0');
        $db->prepare('UPDATE posts SET is_featured = 1 WHERE id = ?')->execute([$id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'feature_post', 'post', $id);
    }
    redirect('../posts');
}

// ===== ADMIN: Duplicate Post =====
if ($action === 'duplicate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../posts'); }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        $orig = $stmt->fetch();

        if ($orig) {
            $newSlug = $orig['slug'] . '-copy-' . time();
            $newTitle = $orig['title'] . ' (Copy)';
            $admin = getCurrentAdmin();

            $stmt = $db->prepare('INSERT INTO posts (title, slug, excerpt, content, category, status, is_featured, thumbnail, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())');
            $stmt->execute([$newTitle, $newSlug, $orig['excerpt'], $orig['content'], $orig['category'], 'draft', $orig['thumbnail'], $admin['id']]);

            require_once '../includes/logger.php';
            logActivity($_SESSION['admin_id'], 'duplicate_post', 'post', $id, ['new_id' => $db->lastInsertId()]);
            setFlash('success', "Post duplicated as draft: '{$newTitle}'");
        }
    }
    redirect('../posts');
}

// ===== ADMIN: Get Categories =====
if ($action === 'categories' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    ensurePostSchema();
    jsonResponse(['success' => true, 'categories' => getCategories()]);
}

// ===== ADMIN: Save Category =====
if ($action === 'save_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../posts'); }

    $catId = (int)($_POST['cat_id'] ?? 0);
    $name = sanitize($_POST['cat_name'] ?? '');
    $color = sanitize($_POST['cat_color'] ?? '#4FC3F7');

    if (strlen($name) < 2) {
        setFlash('error', 'Category name must be at least 2 characters.');
        redirect('../posts');
    }

    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    $slug = trim($slug, '-');
    $db = getDB();

    try {
        if ($catId) {
            $db->prepare('UPDATE post_categories SET name = ?, slug = ?, color = ? WHERE id = ?')->execute([$name, $slug, $color, $catId]);
            setFlash('success', "Category '{$name}' updated.");
        } else {
            $db->prepare('INSERT INTO post_categories (name, slug, color) VALUES (?, ?, ?)')->execute([$name, $slug, $color]);
            setFlash('success', "Category '{$name}' added.");
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            setFlash('error', 'A category with this name already exists.');
        } else {
            setFlash('error', 'Error saving category.');
        }
    }

    require_once '../includes/logger.php';
    logActivity($_SESSION['admin_id'], $catId ? 'update_category' : 'create_category', 'category', $catId ?: null, ['name' => $name]);
    redirect('../posts');
}

// ===== ADMIN: Delete Category =====
if ($action === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../posts'); }

    $catId = (int)($_POST['cat_id'] ?? 0);
    if ($catId) {
        $db = getDB();
        $cat = $db->prepare('SELECT slug FROM post_categories WHERE id = ?');
        $cat->execute([$catId]);
        $cat = $cat->fetch();

        if ($cat) {
            // Move posts in this category to 'uncategorized' (first available category)
            $first = $db->query("SELECT slug FROM post_categories WHERE id != {$catId} ORDER BY sort_order LIMIT 1")->fetchColumn();
            if ($first) {
                $db->prepare('UPDATE posts SET category = ? WHERE category = ?')->execute([$first, $cat['slug']]);
            }
            $db->prepare('DELETE FROM post_categories WHERE id = ?')->execute([$catId]);

            require_once '../includes/logger.php';
            logActivity($_SESSION['admin_id'], 'delete_category', 'category', $catId);
            setFlash('success', 'Category deleted. Posts moved to another category.');
        }
    }
    redirect('../posts');
}

// ===== ADMIN: Get media list for thumbnail picker =====
if ($action === 'media_list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireLogin();

    $db = getDB();
    try {
        $stmt = $db->query("SELECT id, filename, original_name, mime_type, alt_text FROM media WHERE mime_type LIKE 'image/%' ORDER BY created_at DESC LIMIT 50");
        jsonResponse(['success' => true, 'images' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        jsonResponse(['success' => true, 'images' => []]);
    }
}

jsonResponse(['error' => 'Invalid action.'], 400);
