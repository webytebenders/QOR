<?php
require_once 'includes/auth.php';

startSecureSession();

// Redirect to setup if first run
if (isFirstRun()) {
    redirect('setup.php');
}

// Already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';
$requires2fa = false;

if (isPost()) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        $error = 'Invalid request. Please try again.';
    } else {
        // Handle 2FA verification
        if (isset($_POST['totp_code']) && isset($_SESSION['pending_2fa_admin_id'])) {
            $code = sanitize($_POST['totp_code']);
            $adminId = $_SESSION['pending_2fa_admin_id'];

            if (verify2FA($adminId, $code)) {
                $db = getDB();
                $stmt = $db->prepare('SELECT * FROM admins WHERE id = ?');
                $stmt->execute([$adminId]);
                $admin = $stmt->fetch();
                unset($_SESSION['pending_2fa_admin_id']);
                completeLogin($admin);
                redirect('dashboard.php');
            } else {
                $error = 'Invalid 2FA code. Please try again.';
                $requires2fa = true;
            }
        } else {
            // Normal login
            $email = sanitize($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            $result = attemptLogin($email, $password);

            if ($result['success']) {
                redirect('dashboard.php');
            } elseif (!empty($result['requires_2fa'])) {
                $requires2fa = true;
            } else {
                $error = $result['message'];
            }
        }
    }
}

// Check if pending 2FA
if (isset($_SESSION['pending_2fa_admin_id'])) {
    $requires2fa = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Core Chain Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="../assets/images/qor-logo.png" alt="Core Chain" class="login-logo">
                <h1>Core Chain</h1>
                <p>Admin Panel</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <?php if ($requires2fa): ?>
            <form method="POST" class="login-form">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="totp_code">2FA Code</label>
                    <input type="text" id="totp_code" name="totp_code" placeholder="Enter 6-digit code" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="one-time-code">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Verify</button>
                <a href="index.php" class="login-link">Back to Login</a>
            </form>
            <?php else: ?>
            <form method="POST" class="login-form">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="admin@corechain.io" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Sign In</button>
                <a href="forgot-password.php" class="login-link">Forgot password?</a>
            </form>
            <?php endif; ?>
        </div>
        <p class="login-footer-text">&copy; <?= date('Y') ?> Core Chain. The Biometric Standard.</p>
    </div>
</body>
</html>
