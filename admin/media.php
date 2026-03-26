<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_media.sql')); } catch (Exception $e) {}

// Filters
$filterFolder = $_GET['folder'] ?? '';
$filterType = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($filterFolder) { $where[] = 'm.folder = ?'; $params[] = $filterFolder; }
if ($filterType === 'image') { $where[] = 'm.mime_type LIKE ?'; $params[] = 'image/%'; }
if ($filterType === 'document') { $where[] = '(m.mime_type LIKE ? OR m.mime_type LIKE ?)'; $params[] = 'application/pdf'; $params[] = 'application/%'; }
if ($search) { $where[] = '(m.original_name LIKE ? OR m.alt_text LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM media m {$whereSQL}");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT m.*, a.name as uploader_name FROM media m JOIN admins a ON m.uploaded_by = a.id {$whereSQL} ORDER BY m.created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$files = $stmt->fetchAll();

// Stats
$countAll = $db->query('SELECT COUNT(*) FROM media')->fetchColumn();
$countImages = $db->query("SELECT COUNT(*) FROM media WHERE mime_type LIKE 'image/%'")->fetchColumn();
$countDocs = $db->query("SELECT COUNT(*) FROM media WHERE mime_type NOT LIKE 'image/%'")->fetchColumn();
$totalSize = $db->query('SELECT COALESCE(SUM(file_size), 0) FROM media')->fetchColumn();

// Folders
$folders = $db->query("SELECT folder, COUNT(*) as cnt FROM media GROUP BY folder ORDER BY cnt DESC")->fetchAll();

renderHeader('Media Library', 'media');
?>

<div class="stats-row">
    <a href="media.php" class="stat-widget stat-clickable <?= !$filterType && !$filterFolder ? 'stat-active' : '' ?>">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countAll ?></span>
            <span class="stat-widget-label">Total Files</span>
        </div>
    </a>
    <a href="media.php?type=image" class="stat-widget stat-clickable <?= $filterType === 'image' ? 'stat-active' : '' ?>">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countImages ?></span>
            <span class="stat-widget-label">Images</span>
        </div>
    </a>
    <a href="media.php?type=document" class="stat-widget stat-clickable <?= $filterType === 'document' ? 'stat-active' : '' ?>">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $countDocs ?></span>
            <span class="stat-widget-label">Documents</span>
        </div>
    </a>
    <div class="stat-widget">
        <div class="stat-widget-icon purple">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= formatFileSize($totalSize) ?></span>
            <span class="stat-widget-label">Total Size</span>
        </div>
    </div>
</div>

<div class="filters-bar">
    <form method="GET" class="filters-form">
        <input type="text" name="search" placeholder="Search files..." value="<?= sanitize($search) ?>" class="filter-input">
        <select name="folder" class="filter-select">
            <option value="">All Folders</option>
            <?php foreach ($folders as $f): ?>
            <option value="<?= sanitize($f['folder']) ?>" <?= $filterFolder === $f['folder'] ? 'selected' : '' ?>><?= sanitize(ucfirst($f['folder'])) ?> (<?= $f['cnt'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterType): ?><input type="hidden" name="type" value="<?= sanitize($filterType) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($search || $filterFolder): ?>
        <a href="media.php<?= $filterType ? '?type=' . urlencode($filterType) : '' ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <button type="button" class="btn btn-primary" id="uploadBtn">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
        Upload Files
    </button>
</div>

<!-- Upload Zone (hidden by default) -->
<div class="card" id="uploadZone" style="display:none">
    <div class="card-header">
        <h2>Upload Files</h2>
        <button type="button" class="btn-icon" id="uploadZoneClose">&times;</button>
    </div>
    <div class="card-body">
        <form id="uploadForm" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div class="upload-dropzone" id="dropzone">
                <svg viewBox="0 0 20 20" fill="currentColor" width="40" height="40" style="color:var(--text-muted);margin-bottom:12px"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                <p style="font-size:0.9rem;color:var(--text-secondary);margin-bottom:8px">Drag & drop files here, or click to browse</p>
                <p style="font-size:0.75rem;color:var(--text-muted)">Images (JPG, PNG, GIF, SVG, WebP) &bull; Documents (PDF) &bull; Max 10MB each</p>
                <input type="file" id="fileInput" name="files[]" multiple accept="image/*,.pdf" style="display:none">
            </div>
            <div class="form-group" style="margin-top:12px">
                <label>Folder</label>
                <select name="folder" id="uploadFolder" class="filter-select" style="width:200px">
                    <option value="general">General</option>
                    <option value="blog">Blog</option>
                    <option value="pages">Pages</option>
                    <option value="branding">Branding</option>
                </select>
            </div>
            <div id="uploadProgress" style="display:none;margin-top:12px"></div>
        </form>
    </div>
</div>

<!-- Media Grid -->
<?php if (empty($files)): ?>
<div class="card">
    <div class="card-body">
        <p class="empty-state">No files uploaded yet. Click "Upload Files" to get started!</p>
    </div>
</div>
<?php else: ?>
<div class="media-grid" id="mediaGrid">
    <?php foreach ($files as $file): ?>
    <div class="media-card" data-id="<?= $file['id'] ?>">
        <div class="media-card-preview">
            <?php if (str_starts_with($file['mime_type'], 'image/')): ?>
            <img src="<?= '../uploads/' . sanitize($file['filename']) ?>" alt="<?= sanitize($file['alt_text'] ?: $file['original_name']) ?>" loading="lazy">
            <?php else: ?>
            <div class="media-card-icon">
                <svg viewBox="0 0 20 20" fill="currentColor" width="32" height="32"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                <span><?= strtoupper(pathinfo($file['original_name'], PATHINFO_EXTENSION)) ?></span>
            </div>
            <?php endif; ?>
            <div class="media-card-overlay">
                <button type="button" class="btn btn-sm btn-secondary media-detail-btn" data-id="<?= $file['id'] ?>">Details</button>
            </div>
        </div>
        <div class="media-card-info">
            <span class="media-card-name" title="<?= sanitize($file['original_name']) ?>"><?= sanitize($file['original_name']) ?></span>
            <span class="media-card-meta"><?= formatFileSize($file['file_size']) ?><?= $file['width'] ? " &bull; {$file['width']}&times;{$file['height']}" : '' ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>&folder=<?= urlencode($filterFolder) ?>&type=<?= urlencode($filterType) ?>&search=<?= urlencode($search) ?>" class="btn btn-secondary btn-sm">Previous</a><?php endif; ?>
    <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?>&folder=<?= urlencode($filterFolder) ?>&type=<?= urlencode($filterType) ?>&search=<?= urlencode($search) ?>" class="btn btn-secondary btn-sm">Next</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- Detail Modal -->
<div class="modal" id="mediaModal">
    <div class="modal-overlay" id="modalOverlay"></div>
    <div class="modal-content" style="max-width:600px">
        <div class="modal-header">
            <h3 id="modalTitle">File Details</h3>
            <button class="modal-close" id="modalClose">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <p class="empty-state">Loading...</p>
        </div>
        <div class="modal-footer" id="modalFooter"></div>
    </div>
</div>

<script>
const API = 'api/media.php';
const csrf = document.querySelector('[name="csrf_token"]').value;

// Upload Zone Toggle
document.getElementById('uploadBtn').addEventListener('click', () => {
    const zone = document.getElementById('uploadZone');
    zone.style.display = zone.style.display === 'none' ? 'block' : 'none';
});
document.getElementById('uploadZoneClose').addEventListener('click', () => {
    document.getElementById('uploadZone').style.display = 'none';
});

// Dropzone
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');

dropzone.addEventListener('click', () => fileInput.click());
dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', () => handleFiles(fileInput.files));

function handleFiles(fileList) {
    if (!fileList.length) return;
    const progress = document.getElementById('uploadProgress');
    progress.style.display = 'block';
    progress.innerHTML = '';

    const folder = document.getElementById('uploadFolder').value;
    let completed = 0;
    const total = fileList.length;

    Array.from(fileList).forEach(file => {
        const item = document.createElement('div');
        item.className = 'upload-item';
        item.innerHTML = `<span class="upload-item-name">${file.name}</span><span class="upload-item-status">Uploading...</span>`;
        progress.appendChild(item);

        const fd = new FormData();
        fd.append('file', file);
        fd.append('folder', folder);
        fd.append('csrf_token', csrf);

        fetch(API + '?action=upload', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                const status = item.querySelector('.upload-item-status');
                if (d.success) {
                    status.textContent = 'Done';
                    status.style.color = 'var(--green)';
                } else {
                    status.textContent = d.error || 'Failed';
                    status.style.color = 'var(--red)';
                }
                completed++;
                if (completed === total) {
                    setTimeout(() => location.reload(), 800);
                }
            })
            .catch(() => {
                item.querySelector('.upload-item-status').textContent = 'Error';
                item.querySelector('.upload-item-status').style.color = 'var(--red)';
                completed++;
            });
    });
}

// Detail Modal
document.querySelectorAll('.media-detail-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        openDetail(btn.dataset.id);
    });
});

document.getElementById('modalClose').addEventListener('click', closeModal);
document.getElementById('modalOverlay').addEventListener('click', closeModal);

function closeModal() {
    document.getElementById('mediaModal').classList.remove('show');
}

function openDetail(id) {
    const modal = document.getElementById('mediaModal');
    const body = document.getElementById('modalBody');
    const footer = document.getElementById('modalFooter');

    body.innerHTML = '<p class="empty-state">Loading...</p>';
    footer.innerHTML = '';
    modal.classList.add('show');

    fetch(API + '?action=detail&id=' + id).then(r => r.json()).then(d => {
        if (!d.success) { body.innerHTML = '<p class="empty-state">File not found.</p>'; return; }
        const f = d.file;
        const isImage = f.mime_type.startsWith('image/');
        const url = '../uploads/' + f.filename;

        body.innerHTML = `
            ${isImage ? `<div class="media-detail-preview"><img src="${url}" alt="${f.alt_text || f.original_name}"></div>` : ''}
            <div class="form-group">
                <label>File Name</label>
                <input type="text" value="${f.original_name}" readonly style="background:var(--bg);cursor:default">
            </div>
            <div class="media-detail-info-grid">
                <div><span class="info-label">Type</span><span class="info-value">${f.mime_type}</span></div>
                <div><span class="info-label">Size</span><span class="info-value">${f.file_size_formatted}</span></div>
                ${f.width ? `<div><span class="info-label">Dimensions</span><span class="info-value">${f.width} &times; ${f.height}</span></div>` : ''}
                <div><span class="info-label">Folder</span><span class="info-value">${f.folder}</span></div>
                <div><span class="info-label">Uploaded by</span><span class="info-value">${f.uploader_name}</span></div>
                <div><span class="info-label">Date</span><span class="info-value">${f.created_at}</span></div>
            </div>
            <div class="form-group">
                <label>Alt Text</label>
                <input type="text" id="altTextInput" value="${f.alt_text || ''}" placeholder="Describe this image for accessibility...">
            </div>
            <div class="form-group">
                <label>File URL</label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="fileUrlInput" value="${url}" readonly style="flex:1;background:var(--bg);cursor:default">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyUrl()">Copy</button>
                </div>
            </div>
        `;

        footer.innerHTML = `
            <button type="button" class="btn btn-secondary btn-sm" onclick="updateAlt(${f.id})">Save Alt Text</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="deleteMedia(${f.id})">Delete</button>
        `;
    });
}

function copyUrl() {
    const input = document.getElementById('fileUrlInput');
    navigator.clipboard.writeText(window.location.origin + '/' + input.value.replace('../', ''));
    const btn = input.nextElementSibling;
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 1500);
}

function updateAlt(id) {
    const alt = document.getElementById('altTextInput').value;
    fetch(API + '?action=update_alt', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, alt_text: alt, csrf_token: csrf })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            closeModal();
            location.reload();
        }
    });
}

function deleteMedia(id) {
    if (!confirm('Delete this file permanently?')) return;
    fetch(API + '?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, csrf_token: csrf })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            closeModal();
            location.reload();
        }
    });
}
</script>

<?php

function formatFileSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

renderFooter();
?>
