-- Reels feature schema
CREATE TABLE IF NOT EXISTS reels_sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  display_name VARCHAR(150) NOT NULL,
  avatar_url VARCHAR(500) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_id INT DEFAULT NULL,
  instagram_url VARCHAR(500) NOT NULL,
  shortcode VARCHAR(100) NOT NULL,
  caption TEXT DEFAULT NULL,
  thumbnail_url VARCHAR(500) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_shortcode (shortcode),
  INDEX idx_source (source_id),
  FOREIGN KEY (source_id) REFERENCES reels_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
