CREATE TABLE IF NOT EXISTS subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(150) NULL,
    status ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
    source VARCHAR(100) NOT NULL DEFAULT 'blog',
    frequency ENUM('all','weekly','monthly','important') NOT NULL DEFAULT 'all',
    topics VARCHAR(500) NULL,
    unsubscribe_token VARCHAR(64) NOT NULL,
    unsubscribe_reason VARCHAR(255) NULL,
    last_opened_at DATETIME NULL,
    ip_address VARCHAR(45),
    subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at DATETIME NULL,
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_token (unsubscribe_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    status ENUM('draft','scheduled','sending','sent') NOT NULL DEFAULT 'draft',
    audience_type ENUM('all','segment','tag') NOT NULL DEFAULT 'all',
    audience_id INT NULL,
    scheduled_at DATETIME NULL,
    sent_at DATETIME NULL,
    sent_count INT NOT NULL DEFAULT 0,
    open_count INT NOT NULL DEFAULT 0,
    click_count INT NOT NULL DEFAULT 0,
    author_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES admins(id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    subscriber_id INT NOT NULL,
    status ENUM('sent','opened','clicked','bounced') NOT NULL DEFAULT 'sent',
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at DATETIME NULL,
    clicked_at DATETIME NULL,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
    INDEX idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_clicks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    subscriber_id INT NOT NULL,
    url VARCHAR(1000) NOT NULL,
    clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
    INDEX idx_campaign (campaign_id),
    INDEX idx_url (url(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    color VARCHAR(7) NOT NULL DEFAULT '#4FC3F7',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriber_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscriber_id INT NOT NULL,
    tag_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_sub_tag (subscriber_id, tag_id),
    FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    rules JSON NOT NULL,
    subscriber_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description VARCHAR(500) NULL,
    trigger_type ENUM('on_subscribe','on_tag','on_date') NOT NULL DEFAULT 'on_subscribe',
    trigger_value VARCHAR(200) NULL,
    status ENUM('active','paused','draft') NOT NULL DEFAULT 'draft',
    total_entered INT NOT NULL DEFAULT 0,
    total_completed INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_trigger (trigger_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automation_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    automation_id INT NOT NULL,
    step_order INT NOT NULL DEFAULT 1,
    delay_value INT NOT NULL DEFAULT 0,
    delay_unit ENUM('minutes','hours','days') NOT NULL DEFAULT 'days',
    subject VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    sent_count INT NOT NULL DEFAULT 0,
    FOREIGN KEY (automation_id) REFERENCES automations(id) ON DELETE CASCADE,
    INDEX idx_automation (automation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automation_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    automation_id INT NOT NULL,
    step_id INT NOT NULL,
    subscriber_id INT NOT NULL,
    status ENUM('waiting','sent','cancelled') NOT NULL DEFAULT 'waiting',
    scheduled_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (automation_id) REFERENCES automations(id) ON DELETE CASCADE,
    FOREIGN KEY (step_id) REFERENCES automation_steps(id) ON DELETE CASCADE,
    FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
    INDEX idx_status_schedule (status, scheduled_at),
    INDEX idx_automation (automation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_send_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    subscriber_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    unsubscribe_token VARCHAR(64) NOT NULL,
    status ENUM('pending','sent','failed','retry') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ab_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    variant_a_subject VARCHAR(255) NOT NULL,
    variant_b_subject VARCHAR(255) NOT NULL,
    variant_a_content LONGTEXT NULL,
    variant_b_content LONGTEXT NULL,
    test_size INT NOT NULL DEFAULT 20,
    test_duration_hours INT NOT NULL DEFAULT 4,
    status ENUM('testing','completed','cancelled') NOT NULL DEFAULT 'testing',
    winner ENUM('a','b') NULL,
    variant_a_sent INT NOT NULL DEFAULT 0,
    variant_a_opens INT NOT NULL DEFAULT 0,
    variant_b_sent INT NOT NULL DEFAULT 0,
    variant_b_opens INT NOT NULL DEFAULT 0,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign (campaign_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ab_test_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ab_test_id INT NOT NULL,
    subscriber_id INT NOT NULL,
    variant ENUM('a','b') NOT NULL,
    status ENUM('sent','opened','clicked') NOT NULL DEFAULT 'sent',
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at DATETIME NULL,
    FOREIGN KEY (ab_test_id) REFERENCES ab_tests(id) ON DELETE CASCADE,
    INDEX idx_test (ab_test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    description VARCHAR(500) NULL,
    subject VARCHAR(255) NULL,
    content LONGTEXT NOT NULL,
    thumbnail VARCHAR(500) NULL,
    usage_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO campaign_templates (id, name, category, description, subject, content) VALUES
(1, 'Product Announcement', 'Announcement', 'Announce a new feature or product update', 'Exciting Update: {{feature_name}}', '[{"id":1,"type":"heading","content":"Big News!","bgColor":"#ffffff","padding":"24px 20px","fontSize":"26px","color":"#333333"},{"id":2,"type":"text","content":"<p>We''re thrilled to announce our latest update. Here''s what''s new and why it matters to you.</p>","bgColor":"#ffffff","padding":"16px 20px"},{"id":3,"type":"image","src":"","alt":"Feature screenshot","link":"","width":"100%","bgColor":"#ffffff","padding":"16px 20px"},{"id":4,"type":"text","content":"<p>This update includes:</p><ul><li>Feature one</li><li>Feature two</li><li>Feature three</li></ul>","bgColor":"#ffffff","padding":"16px 20px"},{"id":5,"type":"button","text":"Learn More","url":"https://qornetwork.com","bgColor":"#4FC3F7","textColor":"#ffffff","borderRadius":"6px","padding":"16px 20px","align":"center"},{"id":6,"type":"spacer","height":"20px"}]'),
(2, 'Weekly Newsletter', 'Newsletter', 'Weekly digest with multiple content sections', 'This Week at Core Chain', '[{"id":1,"type":"heading","content":"This Week at Core Chain","bgColor":"#ffffff","padding":"24px 20px","fontSize":"24px","color":"#333333"},{"id":2,"type":"divider","color":"#4FC3F7","thickness":"2px","padding":"0 20px"},{"id":3,"type":"text","content":"<p>Here''s your weekly roundup of everything happening in the Core Chain ecosystem.</p>","bgColor":"#ffffff","padding":"16px 20px"},{"id":4,"type":"columns","left":"<p><strong>Development</strong></p><p>Progress update on the latest sprint...</p>","right":"<p><strong>Community</strong></p><p>Growing fast! New milestones reached...</p>","bgColor":"#ffffff","padding":"16px 20px"},{"id":5,"type":"divider","color":"#dddddd","thickness":"1px","padding":"8px 20px"},{"id":6,"type":"text","content":"<p><strong>Coming Up</strong></p><p>What to look forward to next week...</p>","bgColor":"#ffffff","padding":"16px 20px"},{"id":7,"type":"button","text":"Read Full Update","url":"https://qornetwork.com/blog.html","bgColor":"#F97316","textColor":"#ffffff","borderRadius":"6px","padding":"16px 20px","align":"center"}]'),
(3, 'Promotional Offer', 'Promotion', 'Time-limited offer or special promotion', 'Limited Time: Special Offer Inside', '[{"id":1,"type":"heading","content":"Special Offer","bgColor":"#4FC3F7","padding":"28px 20px","fontSize":"28px","color":"#ffffff"},{"id":2,"type":"text","content":"<p style=\\"text-align:center;\\"><strong>For a limited time only</strong></p><p style=\\"text-align:center;\\">Don''t miss this exclusive opportunity.</p>","bgColor":"#ffffff","padding":"20px"},{"id":3,"type":"button","text":"Claim Now","url":"#","bgColor":"#F97316","textColor":"#ffffff","borderRadius":"30px","padding":"20px","align":"center"},{"id":4,"type":"spacer","height":"10px"},{"id":5,"type":"text","content":"<p style=\\"text-align:center;font-size:12px;color:#999;\\">Offer expires in 48 hours. Terms apply.</p>","bgColor":"#ffffff","padding":"8px 20px"}]'),
(4, 'Event Invitation', 'Event', 'Invite subscribers to an event or webinar', 'You''re Invited: {{event_name}}', '[{"id":1,"type":"heading","content":"You''re Invited","bgColor":"#ffffff","padding":"24px 20px","fontSize":"26px","color":"#333333"},{"id":2,"type":"text","content":"<p>We''d love for you to join us at our upcoming event.</p>","bgColor":"#ffffff","padding":"16px 20px"},{"id":3,"type":"columns","left":"<p><strong>Date</strong><br>TBD</p><p><strong>Time</strong><br>TBD</p>","right":"<p><strong>Location</strong><br>Online</p><p><strong>Duration</strong><br>1 hour</p>","bgColor":"#f8f9fa","padding":"16px 20px"},{"id":4,"type":"text","content":"<p>Space is limited — register now to secure your spot.</p>","bgColor":"#ffffff","padding":"16px 20px"},{"id":5,"type":"button","text":"Register Now","url":"#","bgColor":"#22c55e","textColor":"#ffffff","borderRadius":"6px","padding":"16px 20px","align":"center"}]'),
(5, 'Plain Text', 'Simple', 'Clean, minimal text-only email', 'A quick note from Core Chain', '[{"id":1,"type":"text","content":"<p>Hi there,</p><p>Just a quick note from the Core Chain team.</p><p>Write your message here. Sometimes simple is best — no images, no buttons, just a genuine message.</p><p>Best,<br>The Core Chain Team</p>","bgColor":"#ffffff","padding":"24px 20px"}]');
