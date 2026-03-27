CREATE TABLE IF NOT EXISTS page_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_path VARCHAR(255) NOT NULL,
    page_title VARCHAR(255) NULL,
    referrer VARCHAR(500) NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    device_type ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop',
    browser VARCHAR(50) NULL,
    os VARCHAR(50) NULL,
    country VARCHAR(100) NULL,
    session_id VARCHAR(64) NULL,
    utm_source VARCHAR(100) NULL,
    utm_medium VARCHAR(100) NULL,
    utm_campaign VARCHAR(100) NULL,
    duration INT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page (page_path),
    INDEX idx_created (created_at),
    INDEX idx_session (session_id),
    INDEX idx_device (device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(100) NOT NULL,
    event_category VARCHAR(100) NOT NULL DEFAULT 'general',
    event_data TEXT NULL,
    page_path VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    session_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event_name),
    INDEX idx_category (event_category),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    goal_type ENUM('pageview','event','duration') NOT NULL DEFAULT 'pageview',
    goal_target VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_conversions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    goal_id INT NOT NULL,
    ip_address VARCHAR(45) NULL,
    session_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (goal_id) REFERENCES analytics_goals(id) ON DELETE CASCADE,
    INDEX idx_goal (goal_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
