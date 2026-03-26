CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body LONGTEXT NOT NULL,
    trigger_event VARCHAR(100) NOT NULL DEFAULT '',
    variables TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_trigger (trigger_event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO email_templates (slug, name, subject, trigger_event, variables, body) VALUES
('waitlist_welcome', 'Waitlist Welcome', 'Welcome to Core Chain', 'waitlist_signup', '{{email}}',
'<h2 style="font-family:''Space Grotesk'',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">You''re on the list!</h2>
<p style="color:#9999aa;">Thanks for joining the Core Chain waitlist. You''re now among the first to experience sovereign biometric banking.</p>
<p style="color:#9999aa;">Here''s what to expect:</p>
<ul style="color:#9999aa;padding-left:20px;">
    <li>Early access to the biometric wallet</li>
    <li>Development updates and milestones</li>
    <li>Priority access to the token launch</li>
</ul>
<p style="color:#9999aa;">— The Core Chain Team</p>'),

('contact_autoreply', 'Contact Auto-Reply', 'We received your message — Core Chain', 'contact_submit', '{{name}}, {{email}}',
'<h2 style="font-family:''Space Grotesk'',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">Message Received</h2>
<p style="color:#9999aa;">Hi {{name}},</p>
<p style="color:#9999aa;">Thanks for reaching out. We''ve received your message and our team will get back to you within 48 hours.</p>
<p style="color:#9999aa;">— The Core Chain Team</p>'),

('contact_admin_notify', 'Admin: New Contact Message', 'New Contact: {{subject}} — {{name}}', 'contact_submit', '{{name}}, {{email}}, {{subject}}, {{message}}',
'<h2 style="font-family:''Space Grotesk'',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">New Contact Message</h2>
<p style="color:#9999aa;">A new message has been submitted through the contact form.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
    <tr><td style="padding:8px 12px;color:#555566;font-size:13px;border-bottom:1px solid #222;">From</td><td style="padding:8px 12px;color:#f0f0f5;font-size:14px;border-bottom:1px solid #222;"><strong>{{name}}</strong> &lt;{{email}}&gt;</td></tr>
    <tr><td style="padding:8px 12px;color:#555566;font-size:13px;border-bottom:1px solid #222;">Subject</td><td style="padding:8px 12px;color:#f0f0f5;font-size:14px;border-bottom:1px solid #222;">{{subject}}</td></tr>
</table>
<div style="padding:16px 20px;background:rgba(255,255,255,0.03);border-left:3px solid #4FC3F7;border-radius:0 8px 8px 0;margin:16px 0;color:#f0f0f5;font-size:14px;line-height:1.7;">{{message}}</div>'),

('contact_reply', 'Contact Reply', 'Reply from Core Chain', 'admin_reply', '{{name}}, {{reply_text}}',
'<h2 style="font-family:''Space Grotesk'',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">Reply from Core Chain</h2>
<p style="color:#9999aa;">Hi {{name}},</p>
<div style="padding:16px 20px;background:rgba(79,195,247,0.04);border-left:3px solid #4FC3F7;border-radius:0 8px 8px 0;margin:16px 0;color:#f0f0f5;font-size:15px;line-height:1.7;">{{reply_text}}</div>
<p style="color:#9999aa;">— The Core Chain Team</p>'),

('newsletter_welcome', 'Newsletter Welcome', 'You''re subscribed! — Core Chain', 'newsletter_subscribe', '{{email}}, {{unsubscribe_url}}',
'<h2 style="font-family:''Space Grotesk'',sans-serif;font-size:24px;font-weight:700;color:#f0f0f5;margin:0 0 16px;">You''re subscribed!</h2>
<p style="color:#9999aa;">Welcome to the Core Chain newsletter. You''ll receive the latest updates on biometric finance, security research, and ecosystem development.</p>
<p style="color:#9999aa;">We only send when we have something worth reading. No spam, ever.</p>
<p style="color:#9999aa;">— The Core Chain Team</p>');
