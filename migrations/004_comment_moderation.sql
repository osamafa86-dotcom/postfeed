-- ============================================================
-- Migration 004 — Comment Moderation
-- Required for App Store Guideline 1.2 (User-Generated Content):
-- users must be able to flag offensive content, block abusive
-- users, and delete their own posts.
-- ============================================================

-- Soft-delete flag for user-deleted comments (kept in DB so
-- replies don't break, but hidden from listings).
ALTER TABLE `article_comments`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE `article_comments`
  ADD INDEX IF NOT EXISTS `idx_ac_visible` (`article_id`, `is_hidden`, `is_deleted`, `created_at`);

-- ============================================================
-- comment_reports — user-submitted abuse reports.
-- A report is unique per (reporter, comment) so a user can't
-- spam-report the same comment.
-- ============================================================
CREATE TABLE IF NOT EXISTS `comment_reports` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `comment_id`   INT(11)      NOT NULL,
  `reporter_id`  INT(11)      NOT NULL,
  `reason`       VARCHAR(50)  NOT NULL DEFAULT 'other',
  `note`         VARCHAR(500) DEFAULT NULL,
  `status`       ENUM('pending','reviewed','actioned','dismissed') NOT NULL DEFAULT 'pending',
  `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at`  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reporter_comment` (`reporter_id`, `comment_id`),
  KEY `idx_cr_status` (`status`, `created_at`),
  KEY `idx_cr_comment` (`comment_id`),
  CONSTRAINT `fk_cr_comment`  FOREIGN KEY (`comment_id`)  REFERENCES `article_comments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cr_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users`(`id`)            ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- user_blocks — per-user mute list. Comments by blocked users
-- are hidden from the blocker's view (server-side filter).
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_blocks` (
  `blocker_id` INT(11)   NOT NULL,
  `blocked_id` INT(11)   NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`blocker_id`, `blocked_id`),
  KEY `idx_ub_blocked` (`blocked_id`),
  CONSTRAINT `fk_ub_blocker` FOREIGN KEY (`blocker_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ub_blocked` FOREIGN KEY (`blocked_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CHECK (`blocker_id` <> `blocked_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
