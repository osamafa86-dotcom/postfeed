-- =============================================
-- نيوزفلو - لوحة تحكم المستخدم
-- User dashboard schema additions
-- =============================================

-- Extend users table for reader features
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `username` VARCHAR(60) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `bio` TEXT NULL AFTER `avatar_letter`,
  ADD COLUMN IF NOT EXISTS `theme` ENUM('light','dark','auto') DEFAULT 'auto' AFTER `plan`,
  ADD COLUMN IF NOT EXISTS `reading_streak` INT DEFAULT 0 AFTER `theme`,
  ADD COLUMN IF NOT EXISTS `last_read_date` DATE DEFAULT NULL AFTER `reading_streak`,
  ADD COLUMN IF NOT EXISTS `notify_breaking` TINYINT(1) DEFAULT 1 AFTER `last_read_date`,
  ADD COLUMN IF NOT EXISTS `notify_followed` TINYINT(1) DEFAULT 1 AFTER `notify_breaking`,
  ADD COLUMN IF NOT EXISTS `notify_digest` TINYINT(1) DEFAULT 0 AFTER `notify_followed`;

-- Ensure role allows reader role
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','editor','viewer','reader') DEFAULT 'reader';

-- Saved articles (bookmarks)
CREATE TABLE IF NOT EXISTS `user_bookmarks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `article_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_article` (`user_id`, `article_id`),
  KEY `idx_user_bookmarks` (`user_id`, `created_at`),
  CONSTRAINT `fk_bm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bm_article` FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Followed categories with user-defined priority
CREATE TABLE IF NOT EXISTS `user_category_follows` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `category_id` INT(11) NOT NULL,
  `priority` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_cat` (`user_id`, `category_id`),
  KEY `idx_user_cat_priority` (`user_id`, `priority`),
  CONSTRAINT `fk_ucf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ucf_category` FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Followed sources
CREATE TABLE IF NOT EXISTS `user_source_follows` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `source_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_src` (`user_id`, `source_id`),
  KEY `idx_user_src` (`user_id`),
  CONSTRAINT `fk_usf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_usf_source` FOREIGN KEY (`source_id`) REFERENCES `sources`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reading history
CREATE TABLE IF NOT EXISTS `user_reading_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `article_id` INT(11) NOT NULL,
  `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `seconds_spent` INT(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `read_at`),
  KEY `idx_article_read` (`article_id`),
  CONSTRAINT `fk_urh_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_urh_article` FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user notifications
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `type` VARCHAR(50) DEFAULT 'info',
  `title` VARCHAR(500) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `link` VARCHAR(500) DEFAULT NULL,
  `article_id` INT(11) DEFAULT NULL,
  `icon` VARCHAR(10) DEFAULT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_un_user` (`user_id`, `is_read`, `created_at`),
  CONSTRAINT `fk_un_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Article comments
CREATE TABLE IF NOT EXISTS `article_comments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `article_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `parent_id` INT(11) DEFAULT NULL,
  `body` TEXT NOT NULL,
  `likes` INT(11) DEFAULT 0,
  `is_hidden` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ac_article` (`article_id`, `created_at`),
  KEY `idx_ac_user` (`user_id`),
  CONSTRAINT `fk_ac_article` FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ac_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comment likes (one per user per comment)
CREATE TABLE IF NOT EXISTS `comment_likes` (
  `user_id` INT(11) NOT NULL,
  `comment_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `comment_id`),
  CONSTRAINT `fk_cl_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cl_comment` FOREIGN KEY (`comment_id`) REFERENCES `article_comments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
