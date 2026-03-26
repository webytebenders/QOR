<?php
require_once __DIR__ . '/db.php';

function logActivity(int $adminId, string $action, string $targetType = '', ?int $targetId = null, ?array $details = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO activity_log (admin_id, action, target_type, target_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $adminId,
            $action,
            $targetType,
            $targetId,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // Silently fail — don't break the app if logging fails
    }
}

function getRecentActivity(int $limit = 20, ?int $adminId = null): array {
    $db = getDB();
    $sql = 'SELECT al.*, a.name as admin_name FROM activity_log al JOIN admins a ON al.admin_id = a.id';
    $params = [];

    if ($adminId) {
        $sql .= ' WHERE al.admin_id = ?';
        $params[] = $adminId;
    }

    $sql .= ' ORDER BY al.created_at DESC LIMIT ?';
    $params[] = $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function purgeOldActivity(): int {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([ACTIVITY_LOG_RETENTION]);
    return $stmt->rowCount();
}

function formatAction(array $log): string {
    $actions = [
        'login'         => 'logged in',
        'logout'        => 'logged out',
        'create_admin'  => 'created admin user',
        'update_admin'  => 'updated admin user',
        'delete_admin'  => 'deactivated admin user',
        'update_profile'=> 'updated their profile',
        'enable_2fa'    => 'enabled 2FA',
        'disable_2fa'   => 'disabled 2FA',
        'reset_password'  => 'reset password',
        'export_waitlist'       => 'exported waitlist to CSV',
        'delete_waitlist'       => 'deleted waitlist entry',
        'read_contact'          => 'read a message',
        'reply_contact'         => 'replied to a message',
        'update_contact_status' => 'updated message status',
        'delete_contact'        => 'deleted a message',
        'export_contacts'       => 'exported messages to CSV',
        'create_post'           => 'created a blog post',
        'update_post'           => 'updated a blog post',
        'delete_post'           => 'deleted a blog post',
        'feature_post'          => 'set a post as featured',
        'export_subscribers'    => 'exported subscribers to CSV',
        'delete_subscriber'     => 'removed a subscriber',
        'create_campaign'       => 'created a campaign',
        'update_campaign'       => 'updated a campaign',
        'delete_campaign'       => 'deleted a campaign',
        'send_campaign'         => 'sent a campaign',
        'test_smtp'             => 'tested SMTP connection',
        'update_seo'            => 'updated SEO settings',
        'generate_sitemap'      => 'generated sitemap.xml',
        'update_robots'         => 'updated robots.txt',
        'update_chatbot'        => 'updated chatbot settings',
        'upload_media'          => 'uploaded a file',
        'update_media'          => 'updated media details',
        'delete_media'          => 'deleted a file',
        'update_settings'       => 'updated settings',
        'purge_activity'        => 'purged old activity logs',
    ];
    return $actions[$log['action']] ?? $log['action'];
}
