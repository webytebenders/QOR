<?php
require_once 'includes/auth.php';
require_once 'includes/layout.php';

requireLogin();

$admin = getCurrentAdmin();
$db = getDB();

// Dashboard stats
$stats = [
    'waitlist' => 0,
    'messages' => 0,
    'posts' => 0,
    'subscribers' => 0,
];

// Try to get counts from tables (they may not exist yet)
$tables = ['waitlist', 'contacts', 'posts', 'subscribers'];
foreach ($tables as $table) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM `{$table}`");
        $key = $table === 'contacts' ? 'messages' : $table;
        $stats[$key] = $stmt->fetchColumn();
    } catch (Exception $e) {
        // Table doesn't exist yet
    }
}

// Get recent activity
$activities = getRecentActivity(15);

// Admin count
$adminCount = $db->query('SELECT COUNT(*) FROM admins WHERE is_active = 1')->fetchColumn();

renderHeader('Dashboard', 'dashboard');
?>

<div class="stats-row">
    <div class="stat-widget">
        <div class="stat-widget-icon blue">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($stats['waitlist']) ?></span>
            <span class="stat-widget-label">Waitlist Signups</span>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-widget-icon orange">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zm-4 0H9v2h2V9z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($stats['messages']) ?></span>
            <span class="stat-widget-label">Messages</span>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-widget-icon green">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($stats['posts']) ?></span>
            <span class="stat-widget-label">Blog Posts</span>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-widget-icon purple">
            <svg viewBox="0 0 20 20" fill="currentColor" width="22" height="22"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
        </div>
        <div class="stat-widget-data">
            <span class="stat-widget-value"><?= number_format($stats['subscribers']) ?></span>
            <span class="stat-widget-label">Subscribers</span>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header">
            <h2>Quick Actions</h2>
        </div>
        <div class="card-body">
            <div class="quick-actions">
                <a href="users.php" class="quick-action">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>
                    <span>Manage Admins</span>
                </a>
                <a href="activity.php" class="quick-action">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                    <span>Activity Log</span>
                </a>
                <a href="../index.html" target="_blank" class="quick-action">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                    <span>View Website</span>
                </a>
            </div>
        </div>
    </div>

    <!-- System Info -->
    <div class="card">
        <div class="card-header">
            <h2>System Info</h2>
        </div>
        <div class="card-body">
            <div class="info-list">
                <div class="info-item">
                    <span class="info-label">Admin Users</span>
                    <span class="info-value"><?= $adminCount ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">PHP Version</span>
                    <span class="info-value"><?= phpversion() ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Server Time</span>
                    <span class="info-value"><?= date('Y-m-d H:i T') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Active Phases</span>
                    <span class="info-value badge-blue">Phase 1</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Activity Feed -->
<div class="card">
    <div class="card-header">
        <h2>Recent Activity</h2>
        <a href="activity.php" class="card-link">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($activities)): ?>
        <p class="empty-state">No activity recorded yet.</p>
        <?php else: ?>
        <div class="activity-feed">
            <?php foreach ($activities as $log): ?>
            <div class="activity-item">
                <div class="activity-avatar"><?= strtoupper(substr($log['admin_name'], 0, 1)) ?></div>
                <div class="activity-content">
                    <span class="activity-text">
                        <strong><?= sanitize($log['admin_name']) ?></strong>
                        <?= formatAction($log) ?>
                    </span>
                    <span class="activity-time"><?= timeAgo($log['created_at']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderFooter(); ?>
