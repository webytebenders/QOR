<?php
/**
 * Email Tracking API (public, no auth)
 *
 * GET ?action=open&cid=X&sid=X   — tracking pixel (1x1 gif), records open
 * GET ?action=click&cid=X&sid=X&url=X — click redirect, records click then redirects
 */

require_once '../includes/config.php';
require_once '../includes/db.php';

$action = $_GET['action'] ?? '';

// ===== OPEN TRACKING (1x1 transparent GIF) =====
if ($action === 'open') {
    $campaignId = (int)($_GET['cid'] ?? 0);
    $subscriberId = (int)($_GET['sid'] ?? 0);

    if ($campaignId && $subscriberId) {
        try {
            $db = getDB();

            // Update campaign_logs: mark as opened (only first open)
            $stmt = $db->prepare('UPDATE campaign_logs SET status = ?, opened_at = COALESCE(opened_at, NOW()) WHERE campaign_id = ? AND subscriber_id = ? AND status = ?');
            $stmt->execute(['opened', $campaignId, $subscriberId, 'sent']);

            // Increment campaign open count (only if we actually updated a row)
            if ($stmt->rowCount() > 0) {
                $db->prepare('UPDATE campaigns SET open_count = open_count + 1 WHERE id = ?')->execute([$campaignId]);
            }
        } catch (Exception $e) {
            // Silently fail — never break the tracking pixel
        }
    }

    // Serve 1x1 transparent GIF
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // Smallest valid GIF (43 bytes)
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

// ===== CLICK TRACKING =====
if ($action === 'click') {
    $campaignId = (int)($_GET['cid'] ?? 0);
    $subscriberId = (int)($_GET['sid'] ?? 0);
    $url = $_GET['url'] ?? '';

    if (!$url) {
        header('Location: ' . APP_URL);
        exit;
    }

    if ($campaignId && $subscriberId) {
        try {
            $db = getDB();
            try { $db->exec(file_get_contents(__DIR__ . '/../includes/schema_subscribers.sql')); } catch (Exception $e) {}

            // Record the click
            $db->prepare('INSERT INTO campaign_clicks (campaign_id, subscriber_id, url) VALUES (?, ?, ?)')
                ->execute([$campaignId, $subscriberId, $url]);

            // Update campaign_logs: mark as clicked (only first click)
            $stmt = $db->prepare('UPDATE campaign_logs SET status = ?, clicked_at = COALESCE(clicked_at, NOW()) WHERE campaign_id = ? AND subscriber_id = ? AND status IN (?, ?)');
            $stmt->execute(['clicked', $campaignId, $subscriberId, 'sent', 'opened']);

            // Also mark as opened if not already
            $db->prepare('UPDATE campaign_logs SET opened_at = COALESCE(opened_at, NOW()) WHERE campaign_id = ? AND subscriber_id = ?')
                ->execute([$campaignId, $subscriberId]);

            // Increment campaign click count (only if log row was updated)
            if ($stmt->rowCount() > 0) {
                $db->prepare('UPDATE campaigns SET click_count = click_count + 1 WHERE id = ?')->execute([$campaignId]);
            }
        } catch (Exception $e) {
            // Silently fail
        }
    }

    // Redirect to actual URL
    header('Location: ' . $url);
    exit;
}

// Invalid request
header('HTTP/1.1 404 Not Found');
echo 'Not found.';
