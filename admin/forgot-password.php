<?php
require_once 'includes/config.php';
require_once 'includes/helpers.php';

session_start();

$error = '';
$success = '';

if (isPost()) {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRF($token)) {
        $error = 'Invalid request.';
    } else {
        $email = sanitize($_POST['email'] ?? '');
        // Always show success to prevent email enumeration
        $success = 'If an account exists with that email, a password reset link has been sent.';

        // TODO: In Phase 6, integrate with Hostinger SMTP to send actual reset emails
        // For now, just show the success message
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — Core Chain Admin</title>
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
                <h1>Reset Password</h1>
                <p>Enter your email to receive a reset link</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success"><?= sanitize($success) ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="admin@corechain.io" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Send Reset Link</button>
                <a href="index.php" class="login-link">Back to Login</a>
            </form>
        </div>
    </div>
</body>
</html>
