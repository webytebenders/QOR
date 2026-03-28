<?php
/**
 * Mailer — Hostinger SMTP email sender
 * Uses raw PHP sockets for SMTP (no Composer dependencies)
 */

require_once __DIR__ . '/config.php';

class Mailer {
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $fromName;
    private string $fromEmail;
    private $socket;
    private array $errors = [];

    public function __construct() {
        $this->host = SMTP_HOST;
        $this->port = SMTP_PORT;
        $this->user = SMTP_USER;
        $this->pass = SMTP_PASS;
        $this->fromName = SMTP_FROM_NAME;
        $this->fromEmail = SMTP_FROM_EMAIL;
    }

    public function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool {
        try {
            $this->connect();
            $this->authenticate();
            $this->sendEnvelope($to);
            $this->sendMessage($to, $subject, $htmlBody, $replyTo);
            $this->disconnect();
            return true;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->disconnect();
            return false;
        }
    }

    public function sendBulk(array $recipients, string $subject, string $htmlTemplate): array {
        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];

        try {
            $this->connect();
            $this->authenticate();

            foreach ($recipients as $recipient) {
                try {
                    $email = $recipient['email'];
                    $html = $this->personalizeTemplate($htmlTemplate, $recipient);

                    $this->command("RSET", 250);
                    $this->sendEnvelope($email);
                    $this->sendMessage($email, $subject, $html);
                    $results['sent']++;
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = $email . ': ' . $e->getMessage();
                }
            }

            $this->disconnect();
        } catch (Exception $e) {
            $results['errors'][] = 'Connection: ' . $e->getMessage();
        }

        return $results;
    }

    private function connect(): void {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ]);

        $protocol = SMTP_SECURE === 'ssl' ? 'ssl://' : '';
        $this->socket = @stream_socket_client(
            $protocol . $this->host . ':' . $this->port,
            $errno, $errstr, 30,
            STREAM_CLIENT_CONNECT, $context
        );

        if (!$this->socket) {
            throw new Exception("SMTP connection failed: {$errstr} ({$errno})");
        }

        $this->getResponse();
        $this->command("EHLO " . gethostname(), 250);
    }

    private function authenticate(): void {
        $this->command("AUTH LOGIN", 334);
        $this->command(base64_encode($this->user), 334);
        $this->command(base64_encode($this->pass), 235);
    }

    private function sendEnvelope(string $to): void {
        $this->command("MAIL FROM:<{$this->fromEmail}>", 250);
        $this->command("RCPT TO:<{$to}>", 250);
    }

    private function sendMessage(string $to, string $subject, string $htmlBody, ?string $replyTo = null): void {
        $this->command("DATA", 354);

        $boundary = md5(uniqid(time()));
        $headers = [];
        $headers[] = "From: {$this->fromName} <{$this->fromEmail}>";
        $headers[] = "To: {$to}";
        $headers[] = "Subject: {$subject}";
        if ($replyTo) $headers[] = "Reply-To: {$replyTo}";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $headers[] = "X-Mailer: CoreChain-Admin/1.0";
        $headers[] = "Date: " . date('r');
        $headers[] = "Message-ID: <" . uniqid() . "@" . parse_url(APP_URL, PHP_URL_HOST) . ">";

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlBody));

        $body = implode("\r\n", $headers) . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= $plainText . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--{$boundary}--\r\n";
        $body .= ".";

        $this->command($body, 250);
    }

    private function command(string $cmd, int $expectedCode): string {
        fwrite($this->socket, $cmd . "\r\n");
        $response = $this->getResponse();
        $code = (int)substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new Exception("SMTP error [{$code}]: {$response}");
        }
        return $response;
    }

    private function getResponse(): string {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return trim($response);
    }

    private function disconnect(): void {
        if ($this->socket) {
            try { fwrite($this->socket, "QUIT\r\n"); } catch (Exception $e) {}
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function personalizeTemplate(string $template, array $data): string {
        $replacements = [
            '{{email}}' => $data['email'] ?? '',
            '{{unsubscribe_url}}' => ADMIN_URL . '/api/newsletter.php?action=unsubscribe&token=' . ($data['unsubscribe_token'] ?? ''),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    public function getErrors(): array {
        return $this->errors;
    }
}

// ===== DB TEMPLATE LOADER =====

function ensureEmailTemplatesTable(): void {
    static $done = false;
    if ($done) return;
    try {
        $db = getDB();
        $schema = file_get_contents(__DIR__ . '/schema_email_templates.sql');
        foreach (explode(';', $schema) as $sql) {
            $sql = trim($sql);
            if ($sql) { try { $db->exec($sql); } catch (\Exception $e) {} }
        }
    } catch (\Exception $e) {}
    $done = true;
}

function getDbTemplate(string $slug): ?array {
    try {
        ensureEmailTemplatesTable();
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM email_templates WHERE slug = ? AND is_active = 1');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    } catch (\Exception $e) {
        return null;
    }
}

function renderTemplate(string $slug, array $vars = [], string $unsubscribeUrl = ''): string {
    $tpl = getDbTemplate($slug);
    if ($tpl) {
        $body = $tpl['body'];
        foreach ($vars as $key => $val) {
            $body = str_replace('{{' . $key . '}}', htmlspecialchars($val), $body);
        }
        return getEmailWrapper($body, $unsubscribeUrl);
    }
    return '';
}

function getTemplateSubject(string $slug, array $vars = []): string {
    $tpl = getDbTemplate($slug);
    if ($tpl) {
        $subject = $tpl['subject'];
        foreach ($vars as $key => $val) {
            $subject = str_replace('{{' . $key . '}}', $val, $subject);
        }
        return $subject;
    }
    return '';
}

// ===== EMAIL TEMPLATES =====

function getEmailWrapper(string $content, string $unsubscribeUrl = ''): string {
    // Build preference link from unsubscribe URL (swap endpoint)
    $prefsUrl = str_replace('newsletter.php?action=unsubscribe&token=', 'preferences.php?token=', $unsubscribeUrl);
    $unsub = $unsubscribeUrl ? '<p style="margin-top:30px;padding-top:20px;border-top:1px solid #222;font-size:12px;color:#666;"><a href="' . $prefsUrl . '" style="color:#4FC3F7;">Manage Preferences</a> &middot; <a href="' . $unsubscribeUrl . '" style="color:#666;">Unsubscribe</a></p>' : '';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:Inter,-apple-system,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#111118;border:1px solid rgba(255,255,255,0.06);border-radius:12px;overflow:hidden;">
<tr><td style="padding:32px 40px;border-bottom:1px solid rgba(255,255,255,0.06);">
<span style="font-family:\'Space Grotesk\',sans-serif;font-size:20px;font-weight:700;color:#4FC3F7;">Core Chain</span>
</td></tr>
<tr><td style="padding:40px;color:#f0f0f5;font-size:15px;line-height:1.7;">
' . $content . $unsub . '
</td></tr>
<tr><td style="padding:24px 40px;border-top:1px solid rgba(255,255,255,0.06);font-size:12px;color:#555566;text-align:center;">
&copy; ' . date('Y') . ' Core Chain. The Biometric Standard.
</td></tr>
</table>
</td></tr></table></body></html>';
}

function getWaitlistWelcomeEmail(): string {
    $db = renderTemplate('waitlist_welcome');
    if ($db) return $db;
    return getEmailWrapper('<h2 style="font-family:\'Space Grotesk\',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">You\'re on the list!</h2><p style="color:#9999aa;">Thanks for joining the Core Chain waitlist.</p><p style="color:#9999aa;">— The Core Chain Team</p>');
}

function getContactAutoReplyEmail(string $name): string {
    $db = renderTemplate('contact_autoreply', ['name' => $name]);
    if ($db) return $db;
    return getEmailWrapper('<h2 style="font-family:\'Space Grotesk\',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">Message Received</h2><p style="color:#9999aa;">Hi ' . htmlspecialchars($name) . ',</p><p style="color:#9999aa;">Thanks for reaching out. We\'ll get back to you within 48 hours.</p><p style="color:#9999aa;">— The Core Chain Team</p>');
}

function getContactReplyEmail(string $name, string $replyText): string {
    $db = renderTemplate('contact_reply', ['name' => $name, 'reply_text' => nl2br($replyText)]);
    if ($db) return $db;
    return getEmailWrapper('<h2 style="font-family:\'Space Grotesk\',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">Reply from Core Chain</h2><p style="color:#9999aa;">Hi ' . htmlspecialchars($name) . ',</p><div style="padding:16px 20px;background:rgba(79,195,247,0.04);border-left:3px solid #4FC3F7;border-radius:0 8px 8px 0;margin:16px 0;color:#f0f0f5;font-size:15px;line-height:1.7;">' . nl2br(htmlspecialchars($replyText)) . '</div><p style="color:#9999aa;">— The Core Chain Team</p>');
}

function getContactAdminNotificationEmail(string $name, string $email, string $subject, string $message): string {
    $db = renderTemplate('contact_admin_notify', ['name' => $name, 'email' => $email, 'subject' => $subject, 'message' => nl2br($message)]);
    if ($db) return $db;
    return getEmailWrapper('<h2 style="font-family:\'Space Grotesk\',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">New Contact Message</h2><p style="color:#9999aa;">From: ' . htmlspecialchars($name) . ' &lt;' . htmlspecialchars($email) . '&gt;</p><p style="color:#9999aa;">' . nl2br(htmlspecialchars($message)) . '</p>');
}

function getSubscriberWelcomeEmail(string $unsubscribeUrl): string {
    $db = renderTemplate('newsletter_welcome', ['unsubscribe_url' => $unsubscribeUrl], $unsubscribeUrl);
    if ($db) return $db;
    return getEmailWrapper('<h2 style="font-family:\'Space Grotesk\',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">You\'re subscribed!</h2><p style="color:#9999aa;">Welcome to the Core Chain newsletter.</p><p style="color:#9999aa;">— The Core Chain Team</p>', $unsubscribeUrl);
}
