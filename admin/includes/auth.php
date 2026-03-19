<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logger.php';

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/admin/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly'  => true,
            'samesite'  => 'Strict',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSecureSession();
    if (!isset($_SESSION['admin_id'])) return false;

    // Check session expiry
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
        destroySession();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('index.php');
    }
    secureHeaders();
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array(getCurrentAdmin()['role'], $roles)) {
        setFlash('error', 'You do not have permission to access this page.');
        redirect('dashboard.php');
    }
}

function attemptLogin(string $email, string $password): array {
    $db = getDB();

    // Check lockout
    $stmt = $db->prepare('SELECT * FROM admins WHERE email = ?');
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if (!$admin) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    if (!$admin['is_active']) {
        return ['success' => false, 'message' => 'Account has been deactivated.'];
    }

    // Check lockout
    if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
        $remaining = ceil((strtotime($admin['locked_until']) - time()) / 60);
        return ['success' => false, 'message' => "Account locked. Try again in {$remaining} minutes."];
    }

    if (!password_verify($password, $admin['password'])) {
        // Increment failed attempts
        $attempts = $admin['login_attempts'] + 1;
        $lockedUntil = null;

        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
            $attempts = 0;
        }

        $stmt = $db->prepare('UPDATE admins SET login_attempts = ?, locked_until = ? WHERE id = ?');
        $stmt->execute([$attempts, $lockedUntil, $admin['id']]);

        $remaining = MAX_LOGIN_ATTEMPTS - $attempts;
        if ($remaining > 0) {
            return ['success' => false, 'message' => "Invalid password. {$remaining} attempts remaining."];
        }
        return ['success' => false, 'message' => 'Account locked due to too many failed attempts.'];
    }

    // Check if 2FA is enabled
    if ($admin['totp_enabled']) {
        $_SESSION['pending_2fa_admin_id'] = $admin['id'];
        return ['success' => false, 'requires_2fa' => true];
    }

    // Successful login
    completeLogin($admin);
    return ['success' => true];
}

function completeLogin(array $admin): void {
    $db = getDB();

    // Reset login attempts
    $stmt = $db->prepare('UPDATE admins SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?');
    $stmt->execute([$admin['id']]);

    // Regenerate session
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['last_activity'] = time();

    logActivity($admin['id'], 'login', 'admin', $admin['id']);
}

function logout(): void {
    startSecureSession();
    if (isset($_SESSION['admin_id'])) {
        logActivity($_SESSION['admin_id'], 'logout', 'admin', $_SESSION['admin_id']);
    }
    destroySession();
}

function destroySession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

function getCurrentAdmin(): ?array {
    static $admin = null;
    if ($admin === null && isset($_SESSION['admin_id'])) {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, name, email, role, totp_enabled, last_login, created_at FROM admins WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
    }
    return $admin;
}

function isFirstRun(): bool {
    try {
        $db = getDB();
        $stmt = $db->query('SELECT COUNT(*) FROM admins');
        return $stmt->fetchColumn() == 0;
    } catch (Exception $e) {
        return true;
    }
}

function verify2FA(int $adminId, string $code): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT totp_secret FROM admins WHERE id = ?');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();

    if (!$admin || !$admin['totp_secret']) return false;

    // Simple TOTP verification (30-second window)
    $secret = $admin['totp_secret'];
    $timeSlice = floor(time() / 30);

    for ($i = -1; $i <= 1; $i++) {
        $calcCode = getOTP($secret, $timeSlice + $i);
        if ($calcCode === $code) return true;
    }
    return false;
}

function getOTP(string $secret, int $timeSlice): string {
    $secretKey = base32Decode($secret);
    $time = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $time, $secretKey, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

function base32Decode(string $input): string {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper($input);
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';
    for ($i = 0; $i < strlen($input); $i++) {
        $val = strpos($map, $input[$i]);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $output;
}

function generateTOTPSecret(): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 16; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}
