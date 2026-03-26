CREATE TABLE IF NOT EXISTS waitlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    source_page VARCHAR(100) NOT NULL DEFAULT 'unknown',
    status ENUM('new','contacted','qualified','ready','converted') NOT NULL DEFAULT 'new',
    notes TEXT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_source (source_page),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration: add columns if table already exists
ALTER TABLE waitlist ADD COLUMN IF NOT EXISTS status ENUM('new','contacted','qualified','ready','converted') NOT NULL DEFAULT 'new' AFTER source_page;
ALTER TABLE waitlist ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER status;
ALTER TABLE waitlist ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE waitlist ADD INDEX IF NOT EXISTS idx_status (status);
