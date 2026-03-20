<?php
/**
 * Posts API
 *
 * GET  (public):  ?action=list          — get published posts (JSON)
 * GET  (public):  ?action=get&slug=xxx  — get single post by slug
 * POST (admin):   ?action=save          — create/update post
 * POST (admin):   ?action=delete        — delete post
 * POST (admin):   ?action=toggle_featured — toggle featured status
 * POST (admin):   ?action=upload_image  — upload thumbnail
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

// ===== PUBLIC: List published posts =====
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $db = getDB();
        $db->exec(file_get_contents(__DIR__ . '/../includes/schema_posts.sql'));

        $category = $_GET['category'] ?? '';
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where = ["status = 'published'", "(published_at IS NULL OR published_at <= NOW())"];
        $params = [];

        if ($category) {
            $where[] = 'category = ?';
            $params[] = $category;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Get featured post
        $featured = null;
        $stmtFeat = $db->prepare("SELECT p.id, p.title, p.slug, p.excerpt, p.category, p.thumbnail, p.published_at, a.name as author FROM posts p JOIN admins a ON p.author_id = a.id WHERE p.is_featured = 1 AND p.status = 'published' ORDER BY p.published_at DESC LIMIT 1");
        $stmtFeat->execute();
        $featured = $stmtFeat->fetch();

        // Count
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM posts {$whereSQL}");
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();

        // Posts
        $stmt = $db->prepare("SELECT p.id, p.title, p.slug, p.excerpt, p.category, p.thumbnail, p.published_at, a.name as author FROM posts p JOIN admins a ON p.author_id = a.id {$whereSQL} ORDER BY p.published_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $posts = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'featured' => $featured,
            'posts' => $posts,
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
        redirect('../posts.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $title = sanitize($_POST['title'] ?? '');
    $slug = sanitize($_POST['slug'] ?? '');
    $excerpt = sanitize($_POST['excerpt'] ?? '');
    $content = $_POST['content'] ?? ''; // Allow HTML
    $category = sanitize($_POST['category'] ?? 'technology');
    $status = sanitize($_POST['status'] ?? 'draft');
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $scheduledAt = $_POST['scheduled_at'] ?? null;

    // Validate
    if (strlen($title) < 3) {
        setFlash('error', 'Title must be at least 3 characters.');
        redirect('../post-edit.php' . ($id ? '?id=' . $id : ''));
    }

    // Generate slug if empty
    if (!$slug) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        $slug = trim($slug, '-');
    }

    $validCategories = ['technology', 'security', 'ecosystem', 'announcements'];
    if (!in_array($category, $validCategories)) $category = 'technology';

    $validStatuses = ['draft', 'published', 'scheduled'];
    if (!in_array($status, $validStatuses)) $status = 'draft';

    $publishedAt = null;
    if ($status === 'published') {
        $publishedAt = date('Y-m-d H:i:s');
    }
    if ($status === 'scheduled' && $scheduledAt) {
        $publishedAt = $scheduledAt;
    }

    // If setting as featured, unfeatured others
    if ($isFeatured) {
        $db = getDB();
        $db->exec('UPDATE posts SET is_featured = 0 WHERE is_featured = 1');
    }

    $db = getDB();
    $admin = getCurrentAdmin();

    try {
        if ($id) {
            // Update
            $stmt = $db->prepare('UPDATE posts SET title=?, slug=?, excerpt=?, content=?, category=?, status=?, is_featured=?, published_at=COALESCE(?, published_at), scheduled_at=? WHERE id=?');
            $stmt->execute([$title, $slug, $excerpt, $content, $category, $status, $isFeatured, $publishedAt, $scheduledAt, $id]);

            require_once '../includes/logger.php';
            logActivity($admin['id'], 'update_post', 'post', $id, ['title' => $title]);
            setFlash('success', "Post '{$title}' updated.");
        } else {
            // Create
            $stmt = $db->prepare('INSERT INTO posts (title, slug, excerpt, content, category, status, is_featured, author_id, published_at, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$title, $slug, $excerpt, $content, $category, $status, $isFeatured, $admin['id'], $publishedAt, $scheduledAt]);

            $newId = $db->lastInsertId();
            require_once '../includes/logger.php';
            logActivity($admin['id'], 'create_post', 'post', $newId, ['title' => $title]);
            setFlash('success', "Post '{$title}' created.");
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            setFlash('error', 'A post with this slug already exists. Please use a different slug.');
        } else {
            setFlash('error', 'Error saving post: ' . $e->getMessage());
        }
        redirect('../post-edit.php' . ($id ? '?id=' . $id : ''));
    }

    redirect('../posts.php');
}

// ===== ADMIN: Delete =====
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        setFlash('error', 'Invalid request.');
        redirect('../posts.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $stmt = $db->prepare('SELECT title FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        $post = $stmt->fetch();

        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'delete_post', 'post', $id, ['title' => $post['title'] ?? '']);
        setFlash('success', 'Post deleted.');
    }
    redirect('../posts.php');
}

// ===== ADMIN: Toggle Featured =====
if ($action === 'toggle_featured' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin', 'editor');

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        // Unfeatured all, then toggle this one
        $db->exec('UPDATE posts SET is_featured = 0');
        $db->prepare('UPDATE posts SET is_featured = 1 WHERE id = ?')->execute([$id]);

        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'feature_post', 'post', $id);
    }
    redirect('../posts.php');
}

jsonResponse(['error' => 'Invalid action.'], 400);
