<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireRole('super_admin', 'editor');

$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/includes/schema_seo.sql')); } catch (Exception $e) {}

$tab = $_GET['tab'] ?? 'audit';
$editPage = $_GET['edit'] ?? '';

// Auto-discover HTML pages from site root
$siteRoot = realpath(__DIR__ . '/../');
$sitePages = [];
$defaultPages = [
    'index.html' => 'Home',
    'about.html' => 'About',
    'ecosystem.html' => 'Ecosystem',
    'tokenomics.html' => 'Tokenomics',
    'security.html' => 'Security',
    'blog.html' => 'Blog',
    'contact.html' => 'Contact + FAQ',
    'compliance.html' => 'ISO 20022 Compliance',
    'zk-compression.html' => 'ZK Compression',
    'cross-chain.html' => 'Cross-Chain',
    'privacy.html' => 'Privacy Policy',
    'terms.html' => 'Terms of Service',
];
foreach (glob($siteRoot . '/*.html') as $file) {
    $fname = basename($file);
    if (strpos($fname, 'blog-post') === 0) continue; // skip template
    $name = $defaultPages[$fname] ?? ucwords(str_replace(['-', '.html'], [' ', ''], $fname));
    $sitePages[] = ['file' => $fname, 'name' => $name];
}
// Sort: index first, then alphabetical
usort($sitePages, function($a, $b) {
    if ($a['file'] === 'index.html') return -1;
    if ($b['file'] === 'index.html') return 1;
    return strcmp($a['name'], $b['name']);
});

// Fetch saved SEO data
$seoData = [];
try {
    $rows = $db->query('SELECT * FROM seo_pages')->fetchAll();
    foreach ($rows as $row) { $seoData[$row['page_file']] = $row; }
} catch (Exception $e) {}

// Fetch redirects
$redirects = [];
try {
    $redirects = $db->query('SELECT * FROM seo_redirects ORDER BY created_at DESC')->fetchAll();
} catch (Exception $e) {}

// Calculate SEO score for a page
function calcSeoScore(array $seo): int {
    $score = 0;
    $checks = 0;
    $total = 8;

    // Has meta title
    if (!empty($seo['meta_title'])) { $checks++; }
    // Title length 30-60
    $tLen = strlen($seo['meta_title'] ?? '');
    if ($tLen >= 30 && $tLen <= 60) { $checks++; }
    // Has meta description
    if (!empty($seo['meta_description'])) { $checks++; }
    // Description length 120-160
    $dLen = strlen($seo['meta_description'] ?? '');
    if ($dLen >= 120 && $dLen <= 160) { $checks++; }
    // Has OG title
    if (!empty($seo['og_title'])) { $checks++; }
    // Has OG description
    if (!empty($seo['og_description'])) { $checks++; }
    // Has OG image
    if (!empty($seo['og_image'])) { $checks++; }
    // Has focus keyword
    if (!empty($seo['focus_keyword'])) {
        $checks++;
        $total += 2;
        // Keyword in title
        if (!empty($seo['meta_title']) && stripos($seo['meta_title'], $seo['focus_keyword']) !== false) { $checks++; }
        // Keyword in description
        if (!empty($seo['meta_description']) && stripos($seo['meta_description'], $seo['focus_keyword']) !== false) { $checks++; }
    }

    return $total > 0 ? round(($checks / $total) * 100) : 0;
}

// Compute scores for all pages
$auditData = [];
$totalScore = 0;
$issueCount = 0;
foreach ($sitePages as $p) {
    $seo = $seoData[$p['file']] ?? [];
    $score = empty($seo) ? 0 : calcSeoScore($seo);
    $issues = [];

    if (empty($seo['meta_title'])) $issues[] = 'Missing meta title';
    elseif (strlen($seo['meta_title']) < 30) $issues[] = 'Title too short (< 30 chars)';
    elseif (strlen($seo['meta_title']) > 60) $issues[] = 'Title too long (> 60 chars)';

    if (empty($seo['meta_description'])) $issues[] = 'Missing meta description';
    elseif (strlen($seo['meta_description']) < 120) $issues[] = 'Description too short (< 120 chars)';
    elseif (strlen($seo['meta_description']) > 160) $issues[] = 'Description too long (> 160 chars)';

    if (empty($seo['og_title'])) $issues[] = 'Missing OG title';
    if (empty($seo['og_description'])) $issues[] = 'Missing OG description';
    if (empty($seo['og_image'])) $issues[] = 'Missing OG image';
    if (empty($seo['focus_keyword'])) $issues[] = 'No focus keyword set';

    $auditData[] = ['page' => $p, 'score' => $score, 'issues' => $issues, 'seo' => $seo];
    $totalScore += $score;
    $issueCount += count($issues);
}
$avgScore = count($sitePages) > 0 ? round($totalScore / count($sitePages)) : 0;
$completedPages = count(array_filter($auditData, fn($a) => $a['score'] >= 80));

// Editing a specific page
$editing = null;
if ($editPage) {
    $editing = $seoData[$editPage] ?? null;
    foreach ($sitePages as $p) {
        if ($p['file'] === $editPage) {
            if (!$editing) {
                $editing = ['page_file' => $editPage, 'page_name' => $p['name'], 'meta_title' => '', 'meta_description' => '', 'focus_keyword' => '', 'seo_score' => 0, 'og_title' => '', 'og_description' => '', 'og_image' => '', 'canonical_url' => '', 'no_index' => 0, 'structured_data' => '', 'custom_head' => ''];
            }
            break;
        }
    }
}

// Read robots.txt
$robotsPath = $siteRoot . '/robots.txt';
$robotsContent = file_exists($robotsPath) ? file_get_contents($robotsPath) : "User-agent: *\nAllow: /\n\nSitemap: " . APP_URL . "/sitemap.xml";

// Check sitemap
$sitemapExists = file_exists($siteRoot . '/sitemap.xml');

renderHeader('SEO Manager', 'seo');
?>

<!-- Tabs -->
<div class="tabs">
    <a href="seo?tab=audit" class="tab <?= $tab === 'audit' ? 'tab-active' : '' ?>">Audit</a>
    <a href="seo?tab=pages" class="tab <?= $tab === 'pages' ? 'tab-active' : '' ?>">Pages</a>
    <a href="seo?tab=structured" class="tab <?= $tab === 'structured' ? 'tab-active' : '' ?>">Structured Data</a>
    <a href="seo?tab=redirects" class="tab <?= $tab === 'redirects' ? 'tab-active' : '' ?>">Redirects</a>
    <a href="seo?tab=robots" class="tab <?= $tab === 'robots' ? 'tab-active' : '' ?>">robots.txt</a>
    <a href="seo?tab=sitemap" class="tab <?= $tab === 'sitemap' ? 'tab-active' : '' ?>">Sitemap</a>
</div>

<?php if ($tab === 'audit'): ?>
<!-- ==================== SEO AUDIT DASHBOARD ==================== -->
<div class="stats-row">
    <div class="stat-widget">
        <div class="stat-widget-icon <?= $avgScore >= 70 ? 'green' : ($avgScore >= 40 ? 'orange' : 'red') ?>">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $avgScore ?>%</span>
            <span class="stat-widget-label">Average SEO Score</span>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= count($sitePages) ?></span>
            <span class="stat-widget-label">Total Pages</span>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $completedPages ?></span>
            <span class="stat-widget-label">Optimized (80%+)</span>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-widget-icon <?= $issueCount > 0 ? 'orange' : 'green' ?>">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= $issueCount ?></span>
            <span class="stat-widget-label">Total Issues</span>
        </div>
    </div>
</div>

<!-- Inject Meta Tags -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Inject Meta Tags</strong>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:4px 0 0;">Write saved SEO data (meta, OG, JSON-LD) into your static HTML files.</p>
        </div>
        <a href="api/seo?action=inject_meta" class="btn btn-primary" onclick="return confirm('This will modify your HTML files. Continue?')">
            <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 01-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            Inject All Pages
        </a>
    </div>
</div>

<!-- Page-by-page audit -->
<div class="card">
    <div class="card-header"><h2>Page Audit</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Score</th>
                        <th>Issues</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditData as $a): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($a['page']['name']) ?></strong><br>
                            <code style="font-size:0.7rem;color:var(--text-muted)"><?= $a['page']['file'] ?></code>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:60px;height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
                                    <div style="width:<?= $a['score'] ?>%;height:100%;background:<?= $a['score'] >= 80 ? 'var(--green)' : ($a['score'] >= 50 ? 'var(--orange)' : 'var(--red)') ?>;border-radius:3px;"></div>
                                </div>
                                <span style="font-size:0.8rem;font-weight:600;color:<?= $a['score'] >= 80 ? 'var(--green)' : ($a['score'] >= 50 ? 'var(--orange)' : 'var(--red)') ?>"><?= $a['score'] ?>%</span>
                            </div>
                        </td>
                        <td>
                            <?php if (empty($a['issues'])): ?>
                                <span class="badge-green">All good</span>
                            <?php else: ?>
                                <div style="font-size:0.75rem;color:var(--text-muted);line-height:1.6;">
                                    <?php foreach (array_slice($a['issues'], 0, 3) as $issue): ?>
                                        <div style="display:flex;align-items:center;gap:4px;">
                                            <span style="color:var(--orange);">&#9679;</span> <?= $issue ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($a['issues']) > 3): ?>
                                        <div style="color:var(--text-muted);">+<?= count($a['issues']) - 3 ?> more</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="seo?tab=pages&edit=<?= urlencode($a['page']['file']) ?>" class="btn btn-secondary btn-sm">Fix</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'pages' && $editing): ?>
<!-- ==================== EDIT PAGE SEO ==================== -->
<div class="msg-back">
    <a href="seo?tab=pages" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back to Pages
    </a>
</div>

<form method="POST" action="api/seo?action=save">
    <?= csrfField() ?>
    <input type="hidden" name="page_file" value="<?= sanitize($editing['page_file']) ?>">
    <input type="hidden" name="page_name" value="<?= sanitize($editing['page_name'] ?? '') ?>">

    <div class="editor-grid">
        <div class="editor-main">
            <div class="card">
                <div class="card-header"><h2>SEO — <?= sanitize($editing['page_name'] ?? $editing['page_file']) ?></h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Focus Keyword</label>
                        <input type="text" name="focus_keyword" id="focusKeyword" value="<?= sanitize($editing['focus_keyword'] ?? '') ?>" placeholder="Primary keyword for this page" oninput="updateSeoChecklist()">
                        <small style="color:var(--text-muted);">The main keyword you want this page to rank for.</small>
                    </div>

                    <div class="form-group">
                        <label>Meta Title <span class="char-count" id="titleCount">0/60</span></label>
                        <input type="text" name="meta_title" id="metaTitle" value="<?= sanitize($editing['meta_title']) ?>" placeholder="Page title for search engines" maxlength="70" oninput="updateCount(this,'titleCount',60); updateSeoChecklist(); updatePreviews();">
                    </div>
                    <div class="form-group">
                        <label>Meta Description <span class="char-count" id="descCount">0/160</span></label>
                        <textarea name="meta_description" id="metaDesc" rows="3" placeholder="Page description for search results" maxlength="200" oninput="updateCount(this,'descCount',160); updateSeoChecklist(); updatePreviews();"><?= sanitize($editing['meta_description']) ?></textarea>
                    </div>

                    <h3 class="seo-section-title">Open Graph (Social Sharing)</h3>
                    <div class="form-group">
                        <label>OG Title</label>
                        <input type="text" name="og_title" id="ogTitle" value="<?= sanitize($editing['og_title']) ?>" placeholder="Title shown on Twitter/Facebook" oninput="updateSeoChecklist(); updatePreviews();">
                    </div>
                    <div class="form-group">
                        <label>OG Description</label>
                        <textarea name="og_description" id="ogDesc" rows="2" placeholder="Description shown on social cards" oninput="updateSeoChecklist(); updatePreviews();"><?= sanitize($editing['og_description']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>OG Image URL</label>
                        <input type="text" name="og_image" id="ogImage" value="<?= sanitize($editing['og_image']) ?>" placeholder="assets/images/qor-logo.png" oninput="updateSeoChecklist()">
                    </div>

                    <h3 class="seo-section-title">Advanced</h3>
                    <div class="form-group">
                        <label>Canonical URL</label>
                        <input type="text" name="canonical_url" value="<?= sanitize($editing['canonical_url']) ?>" placeholder="https://corechain.io/page">
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="no_index" value="1" <?= ($editing['no_index'] ?? 0) ? 'checked' : '' ?>>
                            <span>noindex — Hide from search engines</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Structured Data (JSON-LD)</label>
                        <textarea name="structured_data" rows="6" placeholder='{"@context":"https://schema.org","@type":"WebPage",...}' style="font-family:monospace;font-size:0.8rem;"><?= htmlspecialchars($editing['structured_data'] ?? '') ?></textarea>
                        <small style="color:var(--text-muted);">Paste valid JSON-LD or use the Structured Data tab for templates.</small>
                    </div>
                    <div class="form-group">
                        <label>Custom Head Code</label>
                        <textarea name="custom_head" rows="3" placeholder="Additional HTML for <head> section" style="font-family:monospace;font-size:0.8rem;"><?= htmlspecialchars($editing['custom_head'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="editor-sidebar">
            <!-- SEO Score Checklist -->
            <div class="card">
                <div class="card-header"><h2>SEO Score</h2></div>
                <div class="card-body">
                    <div style="text-align:center;margin-bottom:12px;">
                        <span id="seoScoreValue" style="font-size:2rem;font-weight:700;">0%</span>
                    </div>
                    <div id="seoChecklist" style="font-size:0.8rem;line-height:2;">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>

            <!-- Google Preview -->
            <div class="card">
                <div class="card-header"><h2>Google Preview</h2></div>
                <div class="card-body">
                    <div class="seo-preview-google">
                        <div class="seo-goog-title" id="previewTitle"><?= sanitize($editing['meta_title'] ?: ($editing['page_name'] ?? '') . ' — Core Chain') ?></div>
                        <div class="seo-goog-url"><?= APP_URL ?>/<?= $editing['page_file'] ?></div>
                        <div class="seo-goog-desc" id="previewDesc"><?= sanitize($editing['meta_description'] ?: 'No description set.') ?></div>
                    </div>
                </div>
            </div>

            <!-- Twitter Preview -->
            <div class="card">
                <div class="card-header"><h2>Twitter Preview</h2></div>
                <div class="card-body">
                    <div class="seo-preview-twitter">
                        <div class="seo-tw-image"></div>
                        <div class="seo-tw-body">
                            <div class="seo-tw-domain"><?= parse_url(APP_URL, PHP_URL_HOST) ?></div>
                            <div class="seo-tw-title" id="previewOgTitle"><?= sanitize($editing['og_title'] ?: $editing['meta_title'] ?: ($editing['page_name'] ?? '')) ?></div>
                            <div class="seo-tw-desc" id="previewOgDesc"><?= sanitize($editing['og_description'] ?: $editing['meta_description'] ?: '') ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="editor-actions">
                <button type="submit" class="btn btn-primary btn-full">Save SEO Settings</button>
            </div>
        </div>
    </div>
</form>

<script>
function updateCount(el, countId, max) {
    const len = el.value.length;
    const counter = document.getElementById(countId);
    counter.textContent = len + '/' + max;
    counter.style.color = len > max ? 'var(--red)' : 'var(--text-muted)';
}

function updatePreviews() {
    const t = document.getElementById('metaTitle');
    const d = document.getElementById('metaDesc');
    const ot = document.getElementById('ogTitle');
    const od = document.getElementById('ogDesc');
    document.getElementById('previewTitle').textContent = t.value || '<?= sanitize($editing['page_name'] ?? '') ?> — Core Chain';
    document.getElementById('previewDesc').textContent = d.value || 'No description set.';
    document.getElementById('previewOgTitle').textContent = ot.value || t.value || '<?= sanitize($editing['page_name'] ?? '') ?>';
    document.getElementById('previewOgDesc').textContent = od.value || d.value || '';
}

function updateSeoChecklist() {
    const kw = document.getElementById('focusKeyword').value.trim().toLowerCase();
    const title = document.getElementById('metaTitle').value;
    const desc = document.getElementById('metaDesc').value;
    const ogT = document.getElementById('ogTitle').value;
    const ogD = document.getElementById('ogDesc').value;
    const ogI = document.getElementById('ogImage').value;

    const checks = [
        { label: 'Meta title set', pass: title.length > 0 },
        { label: 'Title length (30-60)', pass: title.length >= 30 && title.length <= 60 },
        { label: 'Meta description set', pass: desc.length > 0 },
        { label: 'Description length (120-160)', pass: desc.length >= 120 && desc.length <= 160 },
        { label: 'OG title set', pass: ogT.length > 0 },
        { label: 'OG description set', pass: ogD.length > 0 },
        { label: 'OG image set', pass: ogI.length > 0 },
        { label: 'Focus keyword set', pass: kw.length > 0 },
    ];

    if (kw.length > 0) {
        checks.push({ label: 'Keyword in title', pass: title.toLowerCase().includes(kw) });
        checks.push({ label: 'Keyword in description', pass: desc.toLowerCase().includes(kw) });
    }

    const passed = checks.filter(c => c.pass).length;
    const score = Math.round((passed / checks.length) * 100);

    const scoreEl = document.getElementById('seoScoreValue');
    scoreEl.textContent = score + '%';
    scoreEl.style.color = score >= 80 ? 'var(--green)' : (score >= 50 ? 'var(--orange)' : 'var(--red)');

    const list = document.getElementById('seoChecklist');
    list.innerHTML = checks.map(c =>
        '<div style="display:flex;align-items:center;gap:6px;">' +
        (c.pass ? '<span style="color:var(--green);">&#10003;</span>' : '<span style="color:var(--red);">&#10007;</span>') +
        '<span style="' + (c.pass ? '' : 'color:var(--text-muted)') + '">' + c.label + '</span></div>'
    ).join('');
}

// Init
document.querySelectorAll('[oninput]').forEach(el => { const e = new Event('input'); el.dispatchEvent(e); });
updateSeoChecklist();
</script>

<?php elseif ($tab === 'pages'): ?>
<!-- ==================== PAGES LIST ==================== -->

<!-- Bulk Actions Bar -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:0.85rem;font-weight:600;">Bulk Actions:</span>
        <select id="bulkAction" style="min-width:180px;">
            <option value="">Select action...</option>
            <option value="reset">Reset selected to defaults</option>
            <option value="noindex">Set selected as noindex</option>
            <option value="index">Set selected as indexed</option>
        </select>
        <button class="btn btn-secondary btn-sm" onclick="runBulkAction()">Apply</button>
        <span style="font-size:0.8rem;color:var(--text-muted);" id="bulkStatus"></span>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <a href="api/seo?action=inject_meta" class="btn btn-primary btn-sm" onclick="return confirm('Inject meta into all HTML files?')">Inject All</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                        <th>Page</th>
                        <th>Meta Title</th>
                        <th>Keyword</th>
                        <th>Score</th>
                        <th>Indexed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sitePages as $p): ?>
                    <?php $seo = $seoData[$p['file']] ?? null; ?>
                    <?php $score = $seo ? calcSeoScore($seo) : 0; ?>
                    <tr>
                        <td><input type="checkbox" class="page-check" value="<?= sanitize($p['file']) ?>"></td>
                        <td><strong><?= $p['name'] ?></strong><br><code style="font-size:0.7rem;color:var(--text-muted)"><?= $p['file'] ?></code></td>
                        <td class="msg-preview"><?= $seo && $seo['meta_title'] ? sanitize(substr($seo['meta_title'], 0, 40)) . (strlen($seo['meta_title']) > 40 ? '...' : '') : '<span class="text-muted">Not set</span>' ?></td>
                        <td><?= $seo && !empty($seo['focus_keyword']) ? '<code style="font-size:0.75rem;">' . sanitize($seo['focus_keyword']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <span style="font-weight:600;color:<?= $score >= 80 ? 'var(--green)' : ($score >= 50 ? 'var(--orange)' : 'var(--red)') ?>"><?= $score ?>%</span>
                        </td>
                        <td><?= ($seo && $seo['no_index']) ? '<span class="badge-red">No</span>' : '<span class="badge-green">Yes</span>' ?></td>
                        <td style="white-space:nowrap;">
                            <a href="seo?tab=pages&edit=<?= urlencode($p['file']) ?>" class="btn btn-secondary btn-sm">Edit</a>
                            <a href="../<?= urlencode($p['file']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="View live page">
                                <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                            </a>
                            <?php if ($seo): ?>
                            <a href="api/seo?action=duplicate&page=<?= urlencode($p['file']) ?>" class="btn btn-ghost btn-sm" title="Duplicate SEO to clipboard" onclick="event.preventDefault(); copySeoData('<?= sanitize($p['file']) ?>');">
                                <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"/><path d="M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h8a2 2 0 00-2-2H5z"/></svg>
                            </a>
                            <a href="api/seo?action=reset_page&page=<?= urlencode($p['file']) ?>" class="btn btn-ghost btn-sm" title="Reset to defaults" onclick="return confirm('Reset SEO for <?= sanitize($p['name']) ?> to defaults?')">
                                <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const seoDataJson = <?= json_encode($seoData) ?>;
function toggleAll(el) { document.querySelectorAll('.page-check').forEach(c => c.checked = el.checked); }
function copySeoData(pageFile) {
    const d = seoDataJson[pageFile];
    if (d) {
        const text = JSON.stringify({meta_title: d.meta_title, meta_description: d.meta_description, og_title: d.og_title, og_description: d.og_description, og_image: d.og_image, focus_keyword: d.focus_keyword}, null, 2);
        navigator.clipboard.writeText(text).then(() => alert('SEO data copied to clipboard! Paste into another page\'s editor.'));
    }
}
function runBulkAction() {
    const action = document.getElementById('bulkAction').value;
    const checked = [...document.querySelectorAll('.page-check:checked')].map(c => c.value);
    if (!action) { alert('Select an action.'); return; }
    if (!checked.length) { alert('Select at least one page.'); return; }
    if (!confirm('Apply "' + action + '" to ' + checked.length + ' page(s)?')) return;
    fetch('api/seo?action=bulk', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({bulk_action: action, pages: checked, csrf_token: '<?= generateCSRFToken() ?>'})
    }).then(r => r.json()).then(d => {
        document.getElementById('bulkStatus').textContent = d.success ? d.message : (d.error || 'Failed');
        document.getElementById('bulkStatus').style.color = d.success ? 'var(--green)' : 'var(--red)';
        if (d.success) setTimeout(() => location.reload(), 1000);
    });
}
</script>

<?php elseif ($tab === 'structured'): ?>
<!-- ==================== STRUCTURED DATA ==================== -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h2>JSON-LD Templates</h2></div>
    <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:16px;">Click a template to copy it, then paste into a page's Structured Data field.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">

            <div class="card" style="cursor:pointer;" onclick="copyJsonLd('org')">
                <div class="card-body">
                    <strong>Organization</strong>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">Company name, logo, social links</p>
                </div>
            </div>

            <div class="card" style="cursor:pointer;" onclick="copyJsonLd('website')">
                <div class="card-body">
                    <strong>WebSite</strong>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">Site name, URL, search action</p>
                </div>
            </div>

            <div class="card" style="cursor:pointer;" onclick="copyJsonLd('article')">
                <div class="card-body">
                    <strong>Article</strong>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">Blog posts, news articles</p>
                </div>
            </div>

            <div class="card" style="cursor:pointer;" onclick="copyJsonLd('faq')">
                <div class="card-body">
                    <strong>FAQ Page</strong>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">Frequently asked questions</p>
                </div>
            </div>

            <div class="card" style="cursor:pointer;" onclick="copyJsonLd('breadcrumb')">
                <div class="card-body">
                    <strong>Breadcrumb</strong>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">Navigation breadcrumbs</p>
                </div>
            </div>

            <div class="card" style="cursor:pointer;" onclick="copyJsonLd('product')">
                <div class="card-body">
                    <strong>Software Application</strong>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">App/software product info</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Per-page structured data status -->
<div class="card">
    <div class="card-header"><h2>Pages with Structured Data</h2></div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Has JSON-LD</th>
                        <th>Schema Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sitePages as $p): ?>
                    <?php $seo = $seoData[$p['file']] ?? null; ?>
                    <?php
                        $hasLd = $seo && !empty($seo['structured_data']);
                        $schemaType = '—';
                        if ($hasLd) {
                            $ld = json_decode($seo['structured_data'], true);
                            $schemaType = $ld['@type'] ?? '—';
                        }
                    ?>
                    <tr>
                        <td><strong><?= $p['name'] ?></strong></td>
                        <td><?= $hasLd ? '<span class="badge-green">Yes</span>' : '<span class="badge-gray">No</span>' ?></td>
                        <td><code style="font-size:0.8rem;"><?= sanitize($schemaType) ?></code></td>
                        <td><a href="seo?tab=pages&edit=<?= urlencode($p['file']) ?>" class="btn btn-secondary btn-sm">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<textarea id="jsonLdTemplates" style="display:none;"><?= htmlspecialchars(json_encode([
    'org' => [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'Core Chain',
        'url' => APP_URL,
        'logo' => APP_URL . '/assets/images/qor-logo.png',
        'sameAs' => ['https://twitter.com/corechain', 'https://github.com/corechain'],
        'description' => 'Next-generation blockchain infrastructure.'
    ],
    'website' => [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Core Chain',
        'url' => APP_URL,
        'potentialAction' => ['@type' => 'SearchAction', 'target' => APP_URL . '/blog?q={search_term_string}', 'query-input' => 'required name=search_term_string']
    ],
    'article' => [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => 'Article Title Here',
        'author' => ['@type' => 'Organization', 'name' => 'Core Chain'],
        'datePublished' => date('Y-m-d'),
        'image' => APP_URL . '/assets/images/qor-logo.png',
        'description' => 'Article description here.'
    ],
    'faq' => [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            ['@type' => 'Question', 'name' => 'What is Core Chain?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Core Chain is a next-generation blockchain platform.']],
            ['@type' => 'Question', 'name' => 'How do I get started?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Visit our website and join the waitlist.']]
        ]
    ],
    'breadcrumb' => [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => APP_URL],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Page Name', 'item' => APP_URL . '/page']
        ]
    ],
    'product' => [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Core Chain Wallet',
        'operatingSystem' => 'iOS, Android',
        'applicationCategory' => 'FinanceApplication',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD']
    ]
])) ?></textarea>

<script>
function copyJsonLd(type) {
    const templates = JSON.parse(document.getElementById('jsonLdTemplates').textContent);
    const json = JSON.stringify(templates[type], null, 2);
    navigator.clipboard.writeText(json).then(() => {
        alert('JSON-LD template copied! Paste it into a page\'s Structured Data field.');
    });
}
</script>

<?php elseif ($tab === 'redirects'): ?>
<!-- ==================== REDIRECTS ==================== -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>Add Redirect</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="api/seo?action=save_redirect" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <?= csrfField() ?>
            <div class="form-group" style="flex:1;min-width:200px;margin:0;">
                <label>From URL</label>
                <input type="text" name="source_url" placeholder="/old-page" required>
            </div>
            <div class="form-group" style="flex:1;min-width:200px;margin:0;">
                <label>To URL</label>
                <input type="text" name="target_url" placeholder="/new-page" required>
            </div>
            <div class="form-group" style="width:120px;margin:0;">
                <label>Status</label>
                <select name="status_code">
                    <option value="301">301 Permanent</option>
                    <option value="302">302 Temporary</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Add</button>
        </form>
    </div>
</div>

<?php if (!empty($redirects)): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>Active Redirects</h2>
        <a href="api/seo?action=export_htaccess" class="btn btn-secondary btn-sm">Export .htaccess</a>
    </div>
    <div class="card-body no-pad">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Type</th>
                        <th>Hits</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($redirects as $r): ?>
                    <tr>
                        <td><code style="font-size:0.8rem;"><?= sanitize($r['source_url']) ?></code></td>
                        <td><code style="font-size:0.8rem;"><?= sanitize($r['target_url']) ?></code></td>
                        <td><span class="badge-<?= $r['status_code'] == 301 ? 'blue' : 'orange' ?>"><?= $r['status_code'] ?></span></td>
                        <td><?= number_format($r['hits']) ?></td>
                        <td><?= $r['is_active'] ? '<span class="badge-green">Active</span>' : '<span class="badge-gray">Disabled</span>' ?></td>
                        <td>
                            <div class="action-dropdown">
                                <button class="btn btn-secondary btn-sm" onclick="this.nextElementSibling.classList.toggle('show')">Actions &#9662;</button>
                                <div class="dropdown-menu">
                                    <a href="api/seo?action=toggle_redirect&id=<?= $r['id'] ?>" class="dropdown-item"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></a>
                                    <div class="dropdown-divider"></div>
                                    <a href="api/seo?action=delete_redirect&id=<?= $r['id'] ?>" class="dropdown-item dropdown-item-danger" onclick="return confirm('Delete this redirect?')">Delete</a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body">
        <p class="empty-state">No redirects configured yet.</p>
    </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'robots'): ?>
<!-- ==================== ROBOTS.TXT ==================== -->
<div class="card">
    <div class="card-header"><h2>robots.txt</h2></div>
    <div class="card-body">
        <form method="POST" action="api/seo?action=save_robots">
            <?= csrfField() ?>
            <div class="form-group">
                <textarea name="robots_content" rows="12" style="font-family:monospace;font-size:0.85rem;"><?= htmlspecialchars($robotsContent) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:12px">Save robots.txt</button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'sitemap'): ?>
<!-- ==================== SITEMAP ==================== -->
<div class="card">
    <div class="card-header"><h2>Sitemap</h2></div>
    <div class="card-body">
        <div class="info-list">
            <div class="info-item">
                <span class="info-label">sitemap.xml</span>
                <span class="info-value"><?= $sitemapExists ? '<span class="badge-green">Exists</span>' : '<span class="badge-red">Not generated</span>' ?></span>
            </div>
            <?php if ($sitemapExists): ?>
            <div class="info-item">
                <span class="info-label">Last Modified</span>
                <span class="info-value"><?= date('M j, Y g:i A', filemtime($siteRoot . '/sitemap.xml')) ?></span>
            </div>
            <div class="info-item" style="border:none">
                <span class="info-label">View</span>
                <span class="info-value"><a href="../sitemap.xml" target="_blank" style="color:var(--blue)">Open sitemap.xml</a></span>
            </div>
            <?php endif; ?>
        </div>

        <div style="margin-top:20px; display:flex; gap:8px;">
            <a href="api/seo?action=generate_sitemap" class="btn btn-primary">
                <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>
                <?= $sitemapExists ? 'Regenerate' : 'Generate' ?> Sitemap
            </a>
        </div>

        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:16px;">Includes all site pages + published blog posts. Excludes noindex pages.</p>
    </div>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
