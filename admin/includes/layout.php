<?php
function renderHeader(string $pageTitle, string $activePage = ''): void {
    $admin = getCurrentAdmin();
    $flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> — Core Chain Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img src="../assets/images/qor-logo.png" alt="Core Chain" class="sidebar-logo">
                <span class="sidebar-brand">Core Chain</span>
                <button class="sidebar-close" id="sidebarClose">&times;</button>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-group">
                    <a href="dashboard.php" class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
                        <span>Dashboard</span>
                    </a>
                </div>

                <div class="nav-group">
                    <span class="nav-group-label">Manage</span>
                    <a href="waitlist.php" class="nav-item <?= $activePage === 'waitlist' ? 'active' : '' ?>">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
                        <span>Waitlist</span>
                    </a>
                    <a href="messages.php" class="nav-item <?= $activePage === 'messages' ? 'active' : '' ?>">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zm-4 0H9v2h2V9z" clip-rule="evenodd"/></svg>
                        <span>Messages</span>
                    </a>
                    <a href="posts.php" class="nav-item <?= $activePage === 'blog' ? 'active' : '' ?>">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                        <span>Blog</span>
                    </a>
                    <a href="#" class="nav-item disabled" title="Phase 5">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
                        <span>Newsletter</span>
                        <span class="nav-badge soon">P5</span>
                    </a>
                    <a href="#" class="nav-item disabled" title="Phase 6">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
                        <span>Email</span>
                        <span class="nav-badge soon">P6</span>
                    </a>
                </div>

                <div class="nav-group">
                    <span class="nav-group-label">Tools</span>
                    <a href="#" class="nav-item disabled" title="Phase 7">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                        <span>SEO</span>
                        <span class="nav-badge soon">P7</span>
                    </a>
                    <a href="#" class="nav-item disabled" title="Phase 8">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"/></svg>
                        <span>Chatbot</span>
                        <span class="nav-badge soon">P8</span>
                    </a>
                    <a href="#" class="nav-item disabled" title="Phase 9">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zm6-4a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zm6-3a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg>
                        <span>Analytics</span>
                        <span class="nav-badge soon">P9</span>
                    </a>
                    <a href="#" class="nav-item disabled" title="Phase 10">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
                        <span>Media</span>
                        <span class="nav-badge soon">P10</span>
                    </a>
                </div>

                <div class="nav-group">
                    <span class="nav-group-label">System</span>
                    <a href="#" class="nav-item disabled" title="Phase 11">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
                        <span>Settings</span>
                        <span class="nav-badge soon">P11</span>
                    </a>
                    <a href="users.php" class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"/></svg>
                        <span>Admin Users</span>
                    </a>
                    <a href="activity.php" class="nav-item <?= $activePage === 'activity' ? 'active' : '' ?>">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                        <span>Activity Log</span>
                    </a>
                </div>
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?= strtoupper(substr($admin['name'] ?? 'A', 0, 1)) ?></div>
                    <div class="sidebar-user-info">
                        <span class="sidebar-user-name"><?= sanitize($admin['name'] ?? 'Admin') ?></span>
                        <span class="sidebar-user-role"><?= ucwords(str_replace('_', ' ', $admin['role'] ?? 'admin')) ?></span>
                    </div>
                </div>
                <a href="api/logout.php" class="nav-item logout-btn">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <button class="hamburger-admin" id="sidebarToggle">
                    <span></span><span></span><span></span>
                </button>
                <h1 class="topbar-title"><?= sanitize($pageTitle) ?></h1>
                <div class="topbar-actions">
                    <a href="../index.html" target="_blank" class="topbar-btn" title="View Site">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                    </a>
                </div>
            </header>

            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?>">
                <?= sanitize($flash['message']) ?>
                <button class="alert-close">&times;</button>
            </div>
            <?php endif; ?>

            <div class="content-area">
<?php
}

function renderFooter(): void {
?>
            </div>
        </main>
    </div>
    <script src="assets/js/admin.js"></script>
</body>
</html>
<?php
}
