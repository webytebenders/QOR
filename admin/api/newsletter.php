<?php
/**
 * Newsletter API
 *
 * POST (public):  ?action=subscribe     — add subscriber
 * GET  (public):  ?action=unsubscribe   — unsubscribe via token
 * GET  (admin):   ?action=export        — export subscribers CSV
 * POST (admin):   ?action=delete_sub    — delete subscriber
 * POST (admin):   ?action=save_campaign — create/update campaign
 * POST (admin):   ?action=delete_campaign — delete campaign
 * POST (admin):   ?action=send_campaign — send campaign (Phase 6 integration)
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ===== PUBLIC: Subscribe =====
if ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $source = sanitize($input['source'] ?? 'blog');

    if (!$email) {
        jsonResponse(['success' => false, 'message' => 'Invalid email address.'], 400);
    }

    try {
        $db = getDB();
        $db->exec(file_get_contents(__DIR__ . '/../includes/schema_subscribers.sql'));

        $stmt = $db->prepare('SELECT id, status FROM subscribers WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'unsubscribed') {
                $token = bin2hex(random_bytes(32));
                $db->prepare('UPDATE subscribers SET status = ?, unsubscribe_token = ?, unsubscribed_at = NULL WHERE id = ?')
                    ->execute(['active', $token, $existing['id']]);
                jsonResponse(['success' => true, 'message' => 'Welcome back! You\'re resubscribed.']);
            }
            jsonResponse(['success' => true, 'message' => 'You\'re already subscribed!']);
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT INTO subscribers (email, source, unsubscribe_token, ip_address, subscribed_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$email, $source, $token, $_SERVER['REMOTE_ADDR'] ?? '']);

        // Send welcome email
        try {
            require_once '../includes/mailer.php';
            $mailer = new Mailer();
            $unsubUrl = ADMIN_URL . '/api/newsletter.php?action=unsubscribe&token=' . $token;
            $mailer->send($email, 'Welcome to Core Chain Newsletter', getSubscriberWelcomeEmail($unsubUrl));
        } catch (Exception $e) { /* silently fail */ }

        jsonResponse(['success' => true, 'message' => 'Subscribed! You\'ll receive our latest updates.']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}

// ===== PUBLIC: Unsubscribe =====
if ($action === 'unsubscribe' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = $_GET['token'] ?? '';
    if (!$token) { echo 'Invalid unsubscribe link.'; exit; }

    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE subscribers SET status = ?, unsubscribed_at = NOW() WHERE unsubscribe_token = ? AND status = ?');
        $stmt->execute(['unsubscribed', $token, 'active']);

        if ($stmt->rowCount() > 0) {
            echo '<!DOCTYPE html><html><head><title>Unsubscribed</title><style>body{font-family:Inter,sans-serif;background:#0a0a0f;color:#f0f0f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{text-align:center;padding:40px;background:#111118;border:1px solid rgba(255,255,255,.06);border-radius:12px;max-width:400px}h2{color:#4FC3F7;margin-bottom:12px}p{color:#9999aa;margin-bottom:20px}a{color:#4FC3F7}</style></head><body><div class="card"><h2>Unsubscribed</h2><p>You\'ve been removed from our mailing list. Sorry to see you go!</p><a href="' . APP_URL . '">Back to Core Chain</a></div></body></html>';
        } else {
            echo '<!DOCTYPE html><html><head><title>Already Unsubscribed</title><style>body{font-family:Inter,sans-serif;background:#0a0a0f;color:#f0f0f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{text-align:center;padding:40px;background:#111118;border:1px solid rgba(255,255,255,.06);border-radius:12px;max-width:400px}h2{margin-bottom:12px}p{color:#9999aa;margin-bottom:20px}a{color:#4FC3F7}</style></head><body><div class="card"><h2>Already Unsubscribed</h2><p>This email was already unsubscribed or the link has expired.</p><a href="' . APP_URL . '">Back to Core Chain</a></div></body></html>';
        }
    } catch (Exception $e) {
        echo 'Error processing request.';
    }
    exit;
}

// ===== ADMIN: Export Subscribers =====
if ($action === 'export') {
    require_once '../includes/auth.php';
    startSecureSession(); requireLogin();

    $db = getDB();
    $status = $_GET['status'] ?? '';
    $where = $status ? 'WHERE status = ?' : '';
    $params = $status ? [$status] : [];

    $stmt = $db->prepare("SELECT id, email, name, status, source, subscribed_at, unsubscribed_at FROM subscribers {$where} ORDER BY subscribed_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Get tags for all subscribers
    $tagMap = [];
    try {
        $tagRows = $db->query('SELECT st.subscriber_id, t.name FROM subscriber_tags st JOIN tags t ON st.tag_id = t.id')->fetchAll();
        foreach ($tagRows as $tr) {
            $tagMap[$tr['subscriber_id']][] = $tr['name'];
        }
    } catch (Exception $e) {}

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subscribers_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email', 'Name', 'Status', 'Source', 'Tags', 'Subscribed', 'Unsubscribed']);
    foreach ($rows as $r) {
        $tags = isset($tagMap[$r['id']]) ? implode(', ', $tagMap[$r['id']]) : '';
        fputcsv($out, [$r['email'], $r['name'] ?? '', $r['status'], $r['source'], $tags, $r['subscribed_at'], $r['unsubscribed_at']]);
    }
    fclose($out);

    require_once '../includes/logger.php';
    logActivity($_SESSION['admin_id'], 'export_subscribers', 'subscriber', null, ['count' => count($rows)]);
    exit;
}

// ===== ADMIN: Delete Subscriber =====
if ($action === 'delete_sub' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession(); requireLogin();
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../newsletter.php'); }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $db->prepare('DELETE FROM subscribers WHERE id = ?')->execute([$id]);
        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'delete_subscriber', 'subscriber', $id);
        setFlash('success', 'Subscriber removed.');
    }
    redirect('../newsletter.php');
}

// ===== ADMIN: Save Campaign =====
if ($action === 'save_campaign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession(); requireRole('super_admin', 'editor');
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../newsletter.php?tab=campaigns'); }

    $id = (int)($_POST['id'] ?? 0);
    $subject = sanitize($_POST['subject'] ?? '');
    $content = $_POST['content'] ?? '';
    $status = sanitize($_POST['status'] ?? 'draft');
    $scheduledAt = $_POST['scheduled_at'] ?? null;
    $audienceType = in_array($_POST['audience_type'] ?? 'all', ['all', 'segment', 'tag']) ? $_POST['audience_type'] : 'all';
    $audienceId = null;
    if ($audienceType === 'segment') $audienceId = (int)($_POST['audience_segment_id'] ?? 0) ?: null;
    if ($audienceType === 'tag') $audienceId = (int)($_POST['audience_tag_id'] ?? 0) ?: null;

    if (strlen($subject) < 3) { setFlash('error', 'Subject must be at least 3 characters.'); redirect('../campaign-edit.php' . ($id ? '?id=' . $id : '')); }

    $validStatuses = ['draft', 'scheduled'];
    if (!in_array($status, $validStatuses)) $status = 'draft';

    $db = getDB();
    $admin = getCurrentAdmin();

    if ($id) {
        $stmt = $db->prepare('UPDATE campaigns SET subject=?, content=?, status=?, scheduled_at=?, audience_type=?, audience_id=? WHERE id=?');
        $stmt->execute([$subject, $content, $status, $scheduledAt, $audienceType, $audienceId, $id]);
        require_once '../includes/logger.php';
        logActivity($admin['id'], 'update_campaign', 'campaign', $id, ['subject' => $subject]);
        setFlash('success', 'Campaign updated.');
    } else {
        $stmt = $db->prepare('INSERT INTO campaigns (subject, content, status, scheduled_at, audience_type, audience_id, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$subject, $content, $status, $scheduledAt, $audienceType, $audienceId, $admin['id']]);
        require_once '../includes/logger.php';
        logActivity($admin['id'], 'create_campaign', 'campaign', $db->lastInsertId(), ['subject' => $subject]);
        setFlash('success', 'Campaign created.');
    }
    redirect('../newsletter.php?tab=campaigns');
}

// ===== ADMIN: Delete Campaign =====
if ($action === 'delete_campaign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession(); requireRole('super_admin', 'editor');
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../newsletter.php?tab=campaigns'); }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db = getDB();
        $db->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$id]);
        require_once '../includes/logger.php';
        logActivity($_SESSION['admin_id'], 'delete_campaign', 'campaign', $id);
        setFlash('success', 'Campaign deleted.');
    }
    redirect('../newsletter.php?tab=campaigns');
}

// ===== ADMIN: Import Waitlist to Newsletter =====
if ($action === 'import_waitlist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession();
    requireRole('super_admin');

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) { setFlash('error', 'Invalid request.'); redirect('../newsletter.php'); }

    $db = getDB();

    // Get all waitlist emails not already in subscribers
    $stmt = $db->query("SELECT w.email FROM waitlist w LEFT JOIN subscribers s ON w.email = s.email WHERE s.id IS NULL");
    $newEmails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($newEmails)) {
        setFlash('info', 'All waitlist members are already subscribed to the newsletter.');
        redirect('../newsletter.php');
    }

    $imported = 0;
    $insertStmt = $db->prepare('INSERT INTO subscribers (email, source, unsubscribe_token, ip_address, subscribed_at) VALUES (?, ?, ?, ?, NOW())');

    foreach ($newEmails as $email) {
        try {
            $unsubToken = bin2hex(random_bytes(32));
            $insertStmt->execute([$email, 'waitlist_import', $unsubToken, '']);
            $imported++;
        } catch (Exception $e) {
            // Skip duplicates or errors
        }
    }

    require_once '../includes/logger.php';
    logActivity($_SESSION['admin_id'], 'import_waitlist_to_newsletter', 'subscriber', null, ['count' => $imported]);
    setFlash('success', "{$imported} waitlist members imported to newsletter subscribers.");
    redirect('../newsletter.php');
}

// ===== ADMIN: Send Campaign =====
if ($action === 'send_campaign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handled by api/email.php?action=send_campaign
    require_once '../includes/auth.php';
    startSecureSession(); requireRole('super_admin');
    redirect('../newsletter.php?tab=campaigns');
}

// ===== ADMIN: Import CSV =====
if ($action === 'import_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    require_once '../includes/logger.php';
    startSecureSession(); requireRole('super_admin', 'editor');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!validateCSRF($input['csrf_token'] ?? '')) { jsonResponse(['success' => false, 'error' => 'Invalid CSRF.'], 403); }

    $rows = $input['rows'] ?? [];
    $tagId = $input['tag_id'] ?? null;
    $dupMode = $input['duplicate_mode'] ?? 'skip';

    if (empty($rows)) { jsonResponse(['success' => false, 'error' => 'No rows to import.'], 400); }

    $db = getDB();
    $imported = 0;
    $skipped = 0;
    $updated = 0;

    $checkStmt = $db->prepare('SELECT id FROM subscribers WHERE email = ?');
    $insertStmt = $db->prepare('INSERT INTO subscribers (email, name, source, unsubscribe_token, ip_address, subscribed_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $updateStmt = $db->prepare('UPDATE subscribers SET name = COALESCE(NULLIF(?, ""), name), source = COALESCE(NULLIF(?, ""), source) WHERE email = ?');
    $tagStmt = $tagId ? $db->prepare('INSERT IGNORE INTO subscriber_tags (subscriber_id, tag_id) VALUES (?, ?)') : null;

    foreach ($rows as $row) {
        $email = filter_var(trim($row['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$email) { $skipped++; continue; }

        $name = sanitize($row['name'] ?? '');
        $source = sanitize($row['source'] ?? 'csv_import');

        $checkStmt->execute([$email]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            if ($dupMode === 'update') {
                $updateStmt->execute([$name, $source, $email]);
                $updated++;
                if ($tagStmt) { $tagStmt->execute([$existing['id'], $tagId]); }
            } else {
                $skipped++;
            }
        } else {
            $token = bin2hex(random_bytes(32));
            $insertStmt->execute([$email, $name ?: null, $source, $token, $_SERVER['REMOTE_ADDR'] ?? '']);
            $newId = $db->lastInsertId();
            $imported++;
            if ($tagStmt) { $tagStmt->execute([$newId, $tagId]); }
        }
    }

    logActivity($_SESSION['admin_id'], 'import_csv', 'subscriber', null, ['imported' => $imported, 'skipped' => $skipped, 'updated' => $updated]);
    jsonResponse(['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'updated' => $updated]);
}

// ===== ADMIN: Bulk Tag =====
if ($action === 'bulk_tag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    require_once '../includes/logger.php';
    startSecureSession(); requireLogin();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!validateCSRF($input['csrf_token'] ?? '')) { jsonResponse(['success' => false, 'error' => 'Invalid CSRF.'], 403); }

    $mode = $input['mode'] ?? 'add';
    $tagId = (int)($input['tag_id'] ?? 0);
    $subIds = $input['subscriber_ids'] ?? [];

    if (!$tagId || empty($subIds)) { jsonResponse(['success' => false, 'error' => 'Missing tag or subscribers.'], 400); }

    $db = getDB();
    $affected = 0;

    foreach ($subIds as $subId) {
        $subId = (int)$subId;
        if ($mode === 'add') {
            try {
                $db->prepare('INSERT INTO subscriber_tags (subscriber_id, tag_id) VALUES (?, ?)')->execute([$subId, $tagId]);
                $affected++;
            } catch (Exception $e) {} // already tagged
        } else {
            $stmt = $db->prepare('DELETE FROM subscriber_tags WHERE subscriber_id = ? AND tag_id = ?');
            $stmt->execute([$subId, $tagId]);
            $affected += $stmt->rowCount();
        }
    }

    $verb = $mode === 'add' ? 'tagged' : 'untagged';
    logActivity($_SESSION['admin_id'], "bulk_{$verb}", 'newsletter', null, ['tag_id' => $tagId, 'count' => $affected]);
    jsonResponse(['success' => true, 'message' => "{$affected} subscriber(s) {$verb}."]);
}

// ===== ADMIN: Preview Segment =====
if ($action === 'preview_segment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/auth.php';
    startSecureSession(); requireLogin();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!validateCSRF($input['csrf_token'] ?? '')) { jsonResponse(['success' => false, 'error' => 'Invalid CSRF.'], 403); }

    $rules = $input['rules'] ?? [];
    $db = getDB();

    $count = countSegmentSubscribers($db, $rules);
    jsonResponse(['success' => true, 'count' => $count]);
}

/**
 * Count subscribers matching segment rules
 */
function countSegmentSubscribers(PDO $db, array $rules): int {
    return count(getSegmentSubscriberIds($db, $rules));
}

/**
 * Get subscriber IDs matching segment rules
 */
function getSegmentSubscriberIds(PDO $db, array $rules): array {
    $match = $rules['match'] ?? 'all';
    $conditions = $rules['conditions'] ?? [];

    if (empty($conditions)) {
        return $db->query("SELECT id FROM subscribers WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    }

    $where = [];
    $params = [];
    $joins = [];
    $tagJoinIdx = 0;

    foreach ($conditions as $c) {
        $field = $c['field'] ?? '';
        $op = $c['operator'] ?? '';
        $val = $c['value'] ?? '';

        if ($field === 'status') {
            if ($op === 'equals') { $where[] = 's.status = ?'; $params[] = $val; }
            elseif ($op === 'not_equals') { $where[] = 's.status != ?'; $params[] = $val; }
        } elseif ($field === 'source') {
            if ($op === 'equals') { $where[] = 's.source = ?'; $params[] = $val; }
            elseif ($op === 'not_equals') { $where[] = 's.source != ?'; $params[] = $val; }
            elseif ($op === 'contains') { $where[] = 's.source LIKE ?'; $params[] = "%{$val}%"; }
        } elseif ($field === 'email') {
            if ($op === 'contains') { $where[] = 's.email LIKE ?'; $params[] = "%{$val}%"; }
            elseif ($op === 'not_contains') { $where[] = 's.email NOT LIKE ?'; $params[] = "%{$val}%"; }
        } elseif ($field === 'subscribed_at') {
            if ($op === 'after') { $where[] = 's.subscribed_at >= ?'; $params[] = $val; }
            elseif ($op === 'before') { $where[] = 's.subscribed_at <= ?'; $params[] = $val . ' 23:59:59'; }
        } elseif ($field === 'tag') {
            $alias = 'stj' . $tagJoinIdx++;
            if ($op === 'has') {
                $joins[] = "JOIN subscriber_tags {$alias} ON s.id = {$alias}.subscriber_id AND {$alias}.tag_id = " . (int)$val;
            } elseif ($op === 'not_has') {
                $joins[] = "LEFT JOIN subscriber_tags {$alias} ON s.id = {$alias}.subscriber_id AND {$alias}.tag_id = " . (int)$val;
                $where[] = "{$alias}.id IS NULL";
            }
        }
    }

    $joiner = $match === 'any' ? ' OR ' : ' AND ';
    $whereSQL = $where ? 'WHERE ' . implode($joiner, $where) : '';
    $joinSQL = implode(' ', $joins);

    $sql = "SELECT DISTINCT s.id FROM subscribers s {$joinSQL} {$whereSQL}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

jsonResponse(['error' => 'Invalid action.'], 400);
