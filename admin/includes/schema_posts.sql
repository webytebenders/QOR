CREATE TABLE IF NOT EXISTS post_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    color VARCHAR(7) NOT NULL DEFAULT '#4FC3F7',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    excerpt TEXT,
    content LONGTEXT NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'technology',
    status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    thumbnail VARCHAR(500) NULL,
    author_id INT NOT NULL,
    published_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES admins(id),
    INDEX idx_slug (slug),
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_featured (is_featured),
    INDEX idx_published (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default categories if table is empty
INSERT IGNORE INTO post_categories (name, slug, color, sort_order) VALUES
('Technology', 'technology', '#4FC3F7', 1),
('Security', 'security', '#F97316', 2),
('Ecosystem', 'ecosystem', '#22c55e', 3),
('Announcements', 'announcements', '#a855f7', 4);

-- Migration: change category from ENUM to VARCHAR if needed
ALTER TABLE posts MODIFY COLUMN category VARCHAR(100) NOT NULL DEFAULT 'technology';
