-- =============================================
-- نيوزفلو — المرحلة 2 من القصص المتطوّرة
-- Evolving Stories Phase 2: AI-extracted entities + quotes
-- =============================================
--
-- Two new tables power the "Story by Numbers" dashboard and the
-- "Quote Wall". A nightly cron (or the manual "Extract now" button
-- in the admin panel) walks every active story, sends the latest
-- unprocessed articles to Claude Haiku, and persists the structured
-- output here so the public pages can render instantly from cache.
--
-- Nothing here is on the hot insert path — cron_rss.php does NOT
-- touch these tables. Only the dedicated cron_evolving_ai.php job
-- writes to them, so a Claude outage or API quota exhaustion never
-- blocks article ingestion.

CREATE TABLE IF NOT EXISTS `evolving_story_entities` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `story_id` INT(11) NOT NULL,
  -- person | location | organization | term
  `entity_type` VARCHAR(24) NOT NULL,
  `entity_name` VARCHAR(180) NOT NULL,
  `mention_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `first_seen_at` TIMESTAMP NULL DEFAULT NULL,
  `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_story_type_name` (`story_id`, `entity_type`, `entity_name`),
  KEY `idx_story_type_count` (`story_id`, `entity_type`, `mention_count`),
  CONSTRAINT `fk_ese_story` FOREIGN KEY (`story_id`) REFERENCES `evolving_stories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `evolving_story_quotes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `story_id` INT(11) NOT NULL,
  `article_id` INT(11) NOT NULL,
  `quote_text` TEXT NOT NULL,
  `speaker` VARCHAR(180) NOT NULL DEFAULT '',
  `speaker_role` VARCHAR(180) NOT NULL DEFAULT '',
  `context` VARCHAR(500) NOT NULL DEFAULT '',
  -- A cheap sha1 of the quote text so we can deduplicate when the
  -- same quote is picked up from multiple articles/reposts.
  `quote_hash` CHAR(40) NOT NULL,
  `published_at` TIMESTAMP NULL DEFAULT NULL,
  `extracted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_story_hash` (`story_id`, `quote_hash`),
  KEY `idx_story_published` (`story_id`, `published_at`),
  KEY `idx_article` (`article_id`),
  CONSTRAINT `fk_esq_story`   FOREIGN KEY (`story_id`)   REFERENCES `evolving_stories`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_esq_article` FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track which articles have already been fed to the extractor so we
-- never pay for the same Claude call twice and the nightly run stays
-- O(new articles) no matter how big the story grows.
CREATE TABLE IF NOT EXISTS `evolving_story_extractions` (
  `story_id` INT(11) NOT NULL,
  `article_id` INT(11) NOT NULL,
  `extracted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `status` VARCHAR(16) NOT NULL DEFAULT 'ok',
  PRIMARY KEY (`story_id`, `article_id`),
  KEY `idx_extracted_at` (`extracted_at`),
  CONSTRAINT `fk_esx_story`   FOREIGN KEY (`story_id`)   REFERENCES `evolving_stories`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_esx_article` FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
