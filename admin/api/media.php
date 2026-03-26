<?php
/**
 * Media API
 *
 * POST (admin): ?action=upload     — upload file(s)
 * GET  (admin): ?action=detail     — get file details
 * POST (admin): ?action=update_alt — update alt text
 * POST (admin): ?action=delete     — delete file
 * GET  (admin): ?action=browse     — list files (for picker)
 */

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';
require_once '../includes/logger.php';

startSecureSession();
requireLogin();

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/../includes/schema_media.sql')); } catch (Exception $e) {}

$action = $_GET['action'] ?? '';
$admin = getCurrentAdmin();

// Upload directory
$uploadDir = realpath(__DIR__ . '/../../uploads');
if (!$uploadDir) {
    @mkdir(__DIR__ . '/../../uploads', 0755, true);
    $uploadDir = realpath(__DIR__ . '/../../uploads');
}

// Allowed types
$allowedTypes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp',
    'application/pdf',
];
$maxSize = 10 * 1024 * 1024; // 10MB

// ===== UPLOAD =====
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    if (!isset($_FILES['file'])) {
        jsonResponse(['success' => false, 'error' => 'No file uploaded.'], 400);
    }

    $file = $_FILES['file'];
    $folder = preg_replace('/[^a-z0-9_-]/', '', strtolower($_POST['folder'] ?? 'general')) ?: 'general';

    // Validate
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        ];
        jsonResponse(['success' => false, 'error' => $errors[$file['error']] ?? 'Upload error.'], 400);
    }

    if ($file['size'] > $maxSize) {
        jsonResponse(['success' => false, 'error' => 'File exceeds 10MB limit.'], 400);
    }

    // Check MIME type from actual file content
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(['success' => false, 'error' => 'File type not allowed: ' . $mimeType], 400);
    }

    // Generate unique filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeExts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'pdf'];
    if (!in_array($ext, $safeExts)) {
        $ext = 'bin';
    }
    $filename = date('Y-m') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

    // Move file
    $destPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonResponse(['success' => false, 'error' => 'Failed to save file.'], 500);
    }

    // Get image dimensions
    $width = null;
    $height = null;
    if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
        $dims = @getimagesize($destPath);
        if ($dims) {
            $width = $dims[0];
            $height = $dims[1];
        }
    }

    // Insert DB record
    $stmt = $db->prepare('INSERT INTO media (filename, original_name, mime_type, file_size, width, height, folder, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $filename,
        substr($file['name'], 0, 255),
        $mimeType,
        $file['size'],
        $width,
        $height,
        $folder,
        $admin['id'],
    ]);

    $mediaId = $db->lastInsertId();
    logActivity($admin['id'], 'upload_media', 'media', $mediaId, ['name' => $file['name']]);

    jsonResponse(['success' => true, 'id' => $mediaId, 'filename' => $filename]);
}

// ===== DETAIL =====
if ($action === 'detail' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'error' => 'Missing ID.'], 400);

    $stmt = $db->prepare('SELECT m.*, a.name as uploader_name FROM media m JOIN admins a ON m.uploaded_by = a.id WHERE m.id = ?');
    $stmt->execute([$id]);
    $file = $stmt->fetch();

    if (!$file) jsonResponse(['success' => false, 'error' => 'File not found.'], 404);

    $file['file_size_formatted'] = formatFileSize($file['file_size']);

    jsonResponse(['success' => true, 'file' => $file]);
}

// ===== UPDATE ALT TEXT =====
if ($action === 'update_alt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $id = (int)($input['id'] ?? 0);
    $altText = substr(trim($input['alt_text'] ?? ''), 0, 500);

    if (!$id) jsonResponse(['success' => false, 'error' => 'Missing ID.'], 400);

    $stmt = $db->prepare('UPDATE media SET alt_text = ? WHERE id = ?');
    $stmt->execute([$altText, $id]);

    logActivity($admin['id'], 'update_media', 'media', $id);

    jsonResponse(['success' => true]);
}

// ===== DELETE =====
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'error' => 'Missing ID.'], 400);

    // Get file info
    $stmt = $db->prepare('SELECT filename, original_name FROM media WHERE id = ?');
    $stmt->execute([$id]);
    $file = $stmt->fetch();

    if (!$file) jsonResponse(['success' => false, 'error' => 'File not found.'], 404);

    // Delete physical file
    $filePath = $uploadDir . DIRECTORY_SEPARATOR . $file['filename'];
    if (file_exists($filePath)) {
        @unlink($filePath);
    }

    // Delete DB record
    $stmt = $db->prepare('DELETE FROM media WHERE id = ?');
    $stmt->execute([$id]);

    logActivity($admin['id'], 'delete_media', 'media', $id, ['name' => $file['original_name']]);

    jsonResponse(['success' => true]);
}

// ===== BROWSE (for media picker) =====
if ($action === 'browse' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_GET['folder'] ?? '';
    $type = $_GET['type'] ?? '';
    $search = $_GET['search'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];
    if ($folder) { $where[] = 'folder = ?'; $params[] = $folder; }
    if ($type === 'image') { $where[] = 'mime_type LIKE ?'; $params[] = 'image/%'; }
    if ($search) { $where[] = '(original_name LIKE ? OR alt_text LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare("SELECT COUNT(*) FROM media {$whereSQL}");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT id, filename, original_name, mime_type, file_size, width, height, alt_text, folder FROM media {$whereSQL} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $files = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'files' => $files,
        'total' => (int)$total,
        'pages' => max(1, ceil($total / $perPage)),
        'page' => $page,
    ]);
}

function formatFileSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

jsonResponse(['error' => 'Invalid action.'], 400);
