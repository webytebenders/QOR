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
