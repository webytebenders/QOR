<?php
require_once 'includes/config.php';
require_once 'includes/helpers.php';

session_start();

$step = $_GET['step'] ?? '1';
$error = '';
$success = '';

// Step 1: Database setup
if ($step === '1' && isPost()) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        $error = 'Invalid request.';
    } else {
        $host = sanitize($_POST['db_host'] ?? 'localhost');
        $name = sanitize($_POST['db_name'] ?? '');
        $user = sanitize($_POST['db_user'] ?? '');
        $pass = $_POST['db_pass'] ?? '';

        try {
            $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Create database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$name}`");

            // Create tables
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS admins (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    role ENUM('super_admin','editor','support','viewer') NOT NULL DEFAULT 'viewer',
                    totp_secret VARCHAR(32) NULL,
                    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
                    recovery_codes JSON NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    login_attempts INT NOT NULL DEFAULT 0,
                    locked_until DATETIME NULL,
                    last_login DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    id VARCHAR(128) PRIMARY KEY,
                    admin_id INT NOT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS activity_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    action VARCHAR(100) NOT NULL,
                    target_type VARCHAR(50) DEFAULT '',
                    target_id INT NULL,
                    details JSON NULL,
                    ip_address VARCHAR(45),
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
                    INDEX idx_created (created_at),
                    INDEX idx_admin (admin_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Save config
            $configContent = "<?php\n";
            $configContent .= "// ===== DATABASE =====\n";
            $configContent .= "define('DB_HOST', " . var_export($host, true) . ");\n";
            $configContent .= "define('DB_NAME', " . var_export($name, true) . ");\n";
            $configContent .= "define('DB_USER', " . var_export($user, true) . ");\n";
            $configContent .= "define('DB_PASS', " . var_export($pass, true) . ");\n";
            $configContent .= "define('DB_CHARSET', 'utf8mb4');\n\n";
            $configContent .= "// ===== SMTP (Hostinger) =====\n";
            $configContent .= "define('SMTP_HOST', 'smtp.hostinger.com');\n";
            $configContent .= "define('SMTP_PORT', 465);\n";
            $configContent .= "define('SMTP_SECURE', 'ssl');\n";
            $configContent .= "define('SMTP_USER', 'admin@yourdomain.com');\n";
            $configContent .= "define('SMTP_PASS', '');\n";
            $configContent .= "define('SMTP_FROM_NAME', 'Core Chain Admin');\n";
            $configContent .= "define('SMTP_FROM_EMAIL', 'admin@yourdomain.com');\n\n";
            $configContent .= "// ===== APP =====\n";
            $configContent .= "define('APP_NAME', 'Core Chain Admin');\n";
            $configContent .= "define('APP_URL', 'https://yourdomain.com');\n";
            $configContent .= "define('ADMIN_URL', APP_URL . '/admin');\n";
            $configContent .= "define('SESSION_LIFETIME', 1800);\n";
            $configContent .= "define('MAX_LOGIN_ATTEMPTS', 5);\n";
            $configContent .= "define('LOCKOUT_DURATION', 900);\n";
            $configContent .= "define('ACTIVITY_LOG_RETENTION', 90);\n\n";
            $configContent .= "// ===== SECURITY =====\n";
            $configContent .= "define('CSRF_TOKEN_NAME', 'csrf_token');\n";
            $configContent .= "define('BCRYPT_COST', 12);\n\n";
            $configContent .= "// ===== TIMEZONE =====\n";
            $configContent .= "date_default_timezone_set('UTC');\n";

            file_put_contents(__DIR__ . '/includes/config.php', $configContent);

            redirect('setup?step=2');
        } catch (PDOException $e) {
            $error = 'Database connection failed: ' . $e->getMessage();
        }
    }
}

// Step 2: Create admin account
if ($step === '2' && isPost()) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        $error = 'Invalid request.';
    } else {
        require_once 'includes/db.php';

        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($name) < 2) {
            $error = 'Name must be at least 2 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $db = getDB();
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $stmt = $db->prepare('INSERT INTO admins (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$name, $email, $hash, 'super_admin']);

            redirect('setup?step=3');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — Core Chain Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card setup-card">
            <div class="login-header">
                <img src="../assets/images/qor-logo.png" alt="Core Chain" class="login-logo">
                <h1>Core Chain Setup</h1>
                <p>Step <?= sanitize($step) ?> of 3</p>
            </div>

            <div class="setup-progress">
                <div class="setup-step <?= $step >= 1 ? 'active' : '' ?>">1</div>
                <div class="setup-line <?= $step >= 2 ? 'active' : '' ?>"></div>
                <div class="setup-step <?= $step >= 2 ? 'active' : '' ?>">2</div>
                <div class="setup-line <?= $step >= 3 ? 'active' : '' ?>"></div>
                <div class="setup-step <?= $step >= 3 ? 'active' : '' ?>">3</div>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <?php if ($step === '1'): ?>
            <h2 class="setup-title">Database Connection</h2>
            <form method="POST" class="login-form">
                <?= csrfField() ?>
                <div class="form-group">
                    <label>Host</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>Database Name</label>
                    <input type="text" name="db_name" value="corechain_admin" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="db_user" value="root" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="db_pass">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Connect & Create Tables</button>
            </form>

            <?php elseif ($step === '2'): ?>
            <h2 class="setup-title">Create Admin Account</h2>
            <form method="POST" class="login-form">
                <?= csrfField() ?>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" placeholder="John Doe" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="admin@corechain.io" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Min 8 characters" required minlength="8">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Create Super Admin</button>
            </form>

            <?php elseif ($step === '3'): ?>
            <div class="setup-complete">
                <div class="setup-check">
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#22c55e" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                </div>
                <h2>Setup Complete!</h2>
                <p>Your Core Chain admin panel is ready. You can now log in with your admin credentials.</p>
                <a href="." class="btn btn-primary btn-full">Go to Login</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
