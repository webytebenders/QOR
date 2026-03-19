<?php
// ===== DATABASE =====
define('DB_HOST', 'localhost');
define('DB_NAME', 'corechain_admin');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ===== SMTP (Hostinger) =====
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USER', 'admin@yourdomain.com');
define('SMTP_PASS', '');
define('SMTP_FROM_NAME', 'Core Chain Admin');
define('SMTP_FROM_EMAIL', 'admin@yourdomain.com');

// ===== APP =====
define('APP_NAME', 'Core Chain Admin');
define('APP_URL', 'https://yourdomain.com');
define('ADMIN_URL', APP_URL . '/admin');
define('SESSION_LIFETIME', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes
define('ACTIVITY_LOG_RETENTION', 90); // days

// ===== SECURITY =====
define('CSRF_TOKEN_NAME', 'csrf_token');
define('BCRYPT_COST', 12);

// ===== TIMEZONE =====
date_default_timezone_set('UTC');
