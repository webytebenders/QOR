<?php
/**
 * SEO API
 *
 * POST (admin): ?action=save             — save page SEO settings
 * GET  (admin): ?action=generate_sitemap — generate sitemap.xml
 * POST (admin): ?action=save_robots      — save robots.txt
 * POST (admin): ?action=save_redirect    — create/update redirect
 * GET  (admin): ?action=toggle_redirect  — enable/disable redirect
 * GET  (admin): ?action=delete_redirect  — delete redirect
 * GET  (admin): ?action=export_htaccess  — export redirects as .htaccess
 * GET  (admin): ?action=inject_meta      — inject SEO meta into HTML files
 */

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';
require_once '../includes/logger.php';

startSecureSession();
requireRole('super_admin', 'editor');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();
try { $db->exec(file_get_contents(__DIR__ . '/../includes/schema_seo.sql')); } catch (Exception $e) {}

// ===== Save Page SEO =====
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../seo'); }

    $pageFile = sanitize($_POST['page_file'] ?? '');
    $pageName = sanitize($_POST['page_name'] ?? '');
    $metaTitle = sanitize($_POST['meta_title'] ?? '');
    $metaDesc = sanitize($_POST['meta_description'] ?? '');
    $focusKw = sanitize($_POST['focus_keyword'] ?? '');
    $ogTitle = sanitize($_POST['og_title'] ?? '');
    $ogDesc = sanitize($_POST['og_description'] ?? '');
    $ogImage = sanitize($_POST['og_image'] ?? '');
    $canonical = sanitize($_POST['canonical_url'] ?? '');
    $noIndex = isset($_POST['no_index']) ? 1 : 0;
    $structuredData = trim($_POST['structured_data'] ?? '');
    $customHead = $_POST['custom_head'] ?? '';

    if (!$pageFile) { setFlash('error', 'Page file required.'); redirect('../seo?tab=pages'); }

    // Validate JSON-LD if provided
    if ($structuredData && json_decode($structuredData) === null) {
        setFlash('error', 'Structured data must be valid JSON.');
        redirect('../seo?tab=pages&edit=' . urlencode($pageFile));
    }

    // Calculate SEO score
    $seoScore = 0;
    $checks = 0;
    $total = 8;
    if ($metaTitle) $checks++;
    if (strlen($metaTitle) >= 30 && strlen($metaTitle) <= 60) $checks++;
    if ($metaDesc) $checks++;
    if (strlen($metaDesc) >= 120 && strlen($metaDesc) <= 160) $checks++;
    if ($ogTitle) $checks++;
    if ($ogDesc) $checks++;
    if ($ogImage) $checks++;
    if ($focusKw) {
        $checks++;
        $total += 2;
        if (stripos($metaTitle, $focusKw) !== false) $checks++;
        if (stripos($metaDesc, $focusKw) !== false) $checks++;
    }
    $seoScore = $total > 0 ? round(($checks / $total) * 100) : 0;

    // Upsert
    $stmt = $db->prepare('SELECT id FROM seo_pages WHERE page_file = ?');
    $stmt->execute([$pageFile]);

    if ($stmt->fetch()) {
        $stmt = $db->prepare('UPDATE seo_pages SET page_name=?, meta_title=?, meta_description=?, focus_keyword=?, seo_score=?, og_title=?, og_description=?, og_image=?, canonical_url=?, no_index=?, structured_data=?, custom_head=? WHERE page_file=?');
        $stmt->execute([$pageName, $metaTitle, $metaDesc, $focusKw, $seoScore, $ogTitle, $ogDesc, $ogImage, $canonical, $noIndex, $structuredData, $customHead, $pageFile]);
    } else {
        $stmt = $db->prepare('INSERT INTO seo_pages (page_file, page_name, meta_title, meta_description, focus_keyword, seo_score, og_title, og_description, og_image, canonical_url, no_index, structured_data, custom_head) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$pageFile, $pageName, $metaTitle, $metaDesc, $focusKw, $seoScore, $ogTitle, $ogDesc, $ogImage, $canonical, $noIndex, $structuredData, $customHead]);
    }

    logActivity($_SESSION['admin_id'], 'update_seo', 'seo', null, ['page' => $pageFile, 'score' => $seoScore]);
    setFlash('success', "SEO for '{$pageName}' updated (score: {$seoScore}%).");
    redirect('../seo?tab=pages&edit=' . urlencode($pageFile));
}

// ===== Generate Sitemap =====
if ($action === 'generate_sitemap') {
    $baseUrl = rtrim(APP_URL, '/');
    $siteRoot = realpath(__DIR__ . '/../../');

    // Auto-discover pages
    $pages = [];
    foreach (glob($siteRoot . '/*.html') as $file) {
        $fname = basename($file);
        if (strpos($fname, 'blog-post') === 0) continue;
        $priority = '0.7';
        $freq = 'monthly';
        if ($fname === 'index.html') { $priority = '1.0'; $freq = 'weekly'; }
        elseif ($fname === 'blog.html') { $priority = '0.9'; $freq = 'daily'; }
        elseif (in_array($fname, ['privacy.html', 'terms.html'])) { $priority = '0.3'; $freq = 'yearly'; }
        elseif (in_array($fname, ['about.html', 'ecosystem.html', 'tokenomics.html', 'security.html'])) { $priority = '0.8'; }

        $loc = $fname === 'index.html' ? '/' : '/' . str_replace('.html', '', $fname);
        $pages[] = ['loc' => $loc, 'priority' => $priority, 'changefreq' => $freq];
    }

    // Check noindex pages
    $noIndexPages = [];
    try {
        $noIndexPages = $db->query("SELECT page_file FROM seo_pages WHERE no_index = 1")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    // Add published blog posts
    try {
        $posts = $db->query("SELECT slug, updated_at FROM posts WHERE status = 'published' ORDER BY published_at DESC")->fetchAll();
        foreach ($posts as $post) {
            $pages[] = [
                'loc' => '/blog-post?slug=' . $post['slug'],
                'priority' => '0.6',
                'changefreq' => 'weekly',
                'lastmod' => date('Y-m-d', strtotime($post['updated_at']))
            ];
        }
    } catch (Exception $e) {}

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $urlCount = 0;
    foreach ($pages as $page) {
        $file = ltrim($page['loc'], '/');
        if ($file === '') $file = 'index.html';
        if (in_array($file, $noIndexPages)) continue;

        $xml .= "  <url>\n";
        $xml .= "    <loc>{$baseUrl}{$page['loc']}</loc>\n";
        $xml .= "    <lastmod>" . ($page['lastmod'] ?? date('Y-m-d')) . "</lastmod>\n";
        $xml .= "    <changefreq>{$page['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$page['priority']}</priority>\n";
        $xml .= "  </url>\n";
        $urlCount++;
    }

    $xml .= '</urlset>';

    file_put_contents($siteRoot . '/sitemap.xml', $xml);

    logActivity($_SESSION['admin_id'], 'generate_sitemap', 'seo');
    setFlash('success', "sitemap.xml generated with {$urlCount} URLs.");
    redirect('../seo?tab=sitemap');
}

// ===== Save robots.txt =====
if ($action === 'save_robots' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../seo?tab=robots'); }

    $content = $_POST['robots_content'] ?? '';
    file_put_contents(realpath(__DIR__ . '/../../') . '/robots.txt', $content);

    logActivity($_SESSION['admin_id'], 'update_robots', 'seo');
    setFlash('success', 'robots.txt saved.');
    redirect('../seo?tab=robots');
}

// ===== Save Redirect =====
if ($action === 'save_redirect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../seo?tab=redirects'); }

    $source = trim($_POST['source_url'] ?? '');
    $target = trim($_POST['target_url'] ?? '');
    $statusCode = in_array((int)($_POST['status_code'] ?? 301), [301, 302]) ? (int)$_POST['status_code'] : 301;

    if (!$source || !$target) {
        setFlash('error', 'Both source and target URLs are required.');
        redirect('../seo?tab=redirects');
    }

    // Ensure source starts with /
    if ($source[0] !== '/') $source = '/' . $source;

    // Check duplicate
    $stmt = $db->prepare('SELECT id FROM seo_redirects WHERE source_url = ?');
    $stmt->execute([$source]);
    if ($stmt->fetch()) {
        $stmt = $db->prepare('UPDATE seo_redirects SET target_url = ?, status_code = ?, is_active = 1 WHERE source_url = ?');
        $stmt->execute([$target, $statusCode, $source]);
    } else {
        $stmt = $db->prepare('INSERT INTO seo_redirects (source_url, target_url, status_code) VALUES (?, ?, ?)');
        $stmt->execute([$source, $target, $statusCode]);
    }

    logActivity($_SESSION['admin_id'], 'add_redirect', 'seo', null, ['from' => $source, 'to' => $target]);
    setFlash('success', "Redirect added: {$source} -> {$target}");
    redirect('../seo?tab=redirects');
}

// ===== Toggle Redirect =====
if ($action === 'toggle_redirect') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db->prepare('UPDATE seo_redirects SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'toggle_redirect', 'seo', $id);
        setFlash('success', 'Redirect status updated.');
    }
    redirect('../seo?tab=redirects');
}

// ===== Delete Redirect =====
if ($action === 'delete_redirect') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db->prepare('DELETE FROM seo_redirects WHERE id = ?')->execute([$id]);
        logActivity($_SESSION['admin_id'], 'delete_redirect', 'seo', $id);
        setFlash('success', 'Redirect deleted.');
    }
    redirect('../seo?tab=redirects');
}

// ===== Export .htaccess =====
if ($action === 'export_htaccess') {
    $redirects = $db->query('SELECT * FROM seo_redirects WHERE is_active = 1 ORDER BY created_at')->fetchAll();

    $htaccess = "# Redirects generated by Core Chain SEO Manager\n";
    $htaccess .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $htaccess .= "RewriteEngine On\n\n";

    foreach ($redirects as $r) {
        $from = preg_quote($r['source_url'], '#');
        $htaccess .= "RewriteRule ^" . ltrim($r['source_url'], '/') . "$ " . $r['target_url'] . " [R=" . $r['status_code'] . ",L]\n";
    }

    $siteRoot = realpath(__DIR__ . '/../../');
    file_put_contents($siteRoot . '/.htaccess-redirects', $htaccess);

    logActivity($_SESSION['admin_id'], 'export_htaccess', 'seo');
    setFlash('success', '.htaccess-redirects file exported to site root. Merge into your .htaccess manually.');
    redirect('../seo?tab=redirects');
}

// ===== Inject Meta Tags into HTML files =====
if ($action === 'inject_meta') {
    $siteRoot = realpath(__DIR__ . '/../../');
    $rows = $db->query('SELECT * FROM seo_pages')->fetchAll();

    $injected = 0;
    foreach ($rows as $seo) {
        $filePath = $siteRoot . '/' . $seo['page_file'];
        if (!file_exists($filePath)) continue;

        $html = file_get_contents($filePath);
        $original = $html;

        // Build meta tags block
        $metaBlock = "\n    <!-- SEO Meta (auto-injected by Core Chain Admin) -->\n";
        if ($seo['meta_title']) {
            $metaBlock .= '    <title>' . htmlspecialchars($seo['meta_title']) . "</title>\n";
        }
        if ($seo['meta_description']) {
            $metaBlock .= '    <meta name="description" content="' . htmlspecialchars($seo['meta_description']) . "\">\n";
        }
        if ($seo['focus_keyword']) {
            $metaBlock .= '    <meta name="keywords" content="' . htmlspecialchars($seo['focus_keyword']) . "\">\n";
        }
        if ($seo['canonical_url']) {
            $metaBlock .= '    <link rel="canonical" href="' . htmlspecialchars($seo['canonical_url']) . "\">\n";
        }
        if ($seo['no_index']) {
            $metaBlock .= "    <meta name=\"robots\" content=\"noindex, nofollow\">\n";
        }

        // OG tags
        if ($seo['og_title'] || $seo['meta_title']) {
            $metaBlock .= '    <meta property="og:title" content="' . htmlspecialchars($seo['og_title'] ?: $seo['meta_title']) . "\">\n";
        }
        if ($seo['og_description'] || $seo['meta_description']) {
            $metaBlock .= '    <meta property="og:description" content="' . htmlspecialchars($seo['og_description'] ?: $seo['meta_description']) . "\">\n";
        }
        if ($seo['og_image']) {
            $metaBlock .= '    <meta property="og:image" content="' . htmlspecialchars($seo['og_image']) . "\">\n";
        }
        $metaBlock .= '    <meta property="og:url" content="' . htmlspecialchars(rtrim(APP_URL, '/') . '/' . $seo['page_file']) . "\">\n";
        $metaBlock .= "    <meta property=\"og:type\" content=\"website\">\n";

        // Twitter card
        $metaBlock .= "    <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        if ($seo['og_title'] || $seo['meta_title']) {
            $metaBlock .= '    <meta name="twitter:title" content="' . htmlspecialchars($seo['og_title'] ?: $seo['meta_title']) . "\">\n";
        }
        if ($seo['og_description'] || $seo['meta_description']) {
            $metaBlock .= '    <meta name="twitter:description" content="' . htmlspecialchars($seo['og_description'] ?: $seo['meta_description']) . "\">\n";
        }

        // JSON-LD
        if (!empty($seo['structured_data'])) {
            $metaBlock .= "    <script type=\"application/ld+json\">\n    " . $seo['structured_data'] . "\n    </script>\n";
        }

        // Custom head
        if (!empty($seo['custom_head'])) {
            $metaBlock .= "    " . $seo['custom_head'] . "\n";
        }

        $metaBlock .= "    <!-- /SEO Meta -->\n";

        // Remove old injected block if present
        $html = preg_replace('/\n?\s*<!-- SEO Meta \(auto-injected by Core Chain Admin\) -->.*?<!-- \/SEO Meta -->\n?/s', '', $html);

        // Also remove old <title> if we have a new one (avoid duplicate)
        if ($seo['meta_title']) {
            $html = preg_replace('/<title>[^<]*<\/title>\n?\s*/i', '', $html, 1);
        }

        // Inject before </head>
        $html = str_replace('</head>', $metaBlock . '</head>', $html);

        if ($html !== $original) {
            file_put_contents($filePath, $html);
            $injected++;
        }
    }

    logActivity($_SESSION['admin_id'], 'inject_meta', 'seo', null, ['pages' => $injected]);
    setFlash('success', "Meta tags injected into {$injected} HTML file(s).");
    redirect('../seo?tab=audit');
}

// ===== Reset Page SEO to Defaults =====
if ($action === 'reset_page') {
    $page = sanitize($_GET['page'] ?? '');
    if ($page) {
        $db->prepare('DELETE FROM seo_pages WHERE page_file = ?')->execute([$page]);
        logActivity($_SESSION['admin_id'], 'reset_seo', 'seo', null, ['page' => $page]);
        setFlash('success', "SEO reset to defaults for {$page}.");
    }
    redirect('../seo?tab=pages');
}

// ===== Bulk Actions =====
if ($action === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!validateCSRF($input['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $bulkAction = $input['bulk_action'] ?? '';
    $pages = $input['pages'] ?? [];

    if (empty($pages)) {
        jsonResponse(['success' => false, 'error' => 'No pages selected.'], 400);
    }

    $affected = 0;
    foreach ($pages as $pageFile) {
        $pageFile = sanitize($pageFile);
        if ($bulkAction === 'reset') {
            $db->prepare('DELETE FROM seo_pages WHERE page_file = ?')->execute([$pageFile]);
            $affected++;
        } elseif ($bulkAction === 'noindex') {
            $stmt = $db->prepare('SELECT id FROM seo_pages WHERE page_file = ?');
            $stmt->execute([$pageFile]);
            if ($stmt->fetch()) {
                $db->prepare('UPDATE seo_pages SET no_index = 1 WHERE page_file = ?')->execute([$pageFile]);
            } else {
                $db->prepare('INSERT INTO seo_pages (page_file, page_name, no_index) VALUES (?, ?, 1)')->execute([$pageFile, $pageFile]);
            }
            $affected++;
        } elseif ($bulkAction === 'index') {
            $db->prepare('UPDATE seo_pages SET no_index = 0 WHERE page_file = ?')->execute([$pageFile]);
            $affected++;
        }
    }

    logActivity($_SESSION['admin_id'], 'bulk_seo', 'seo', null, ['action' => $bulkAction, 'count' => $affected]);
    jsonResponse(['success' => true, 'message' => "Applied '{$bulkAction}' to {$affected} page(s)."]);
}

jsonResponse(['error' => 'Invalid action.'], 400);
