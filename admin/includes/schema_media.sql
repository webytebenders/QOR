CREATE TABLE IF NOT EXISTS media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    width INT UNSIGNED DEFAULT NULL,
    height INT UNSIGNED DEFAULT NULL,
    alt_text VARCHAR(500) DEFAULT '',
    folder VARCHAR(100) DEFAULT 'general',
    uploaded_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_folder (folder),
    INDEX idx_mime (mime_type),
    INDEX idx_created (created_at DESC),
    FOREIGN KEY (uploaded_by) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
