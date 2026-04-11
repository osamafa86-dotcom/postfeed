-- =============================================
-- نيوزفلو — القصص المتطوّرة المُعرَّفة من الإدارة
-- Evolving Stories: admin-defined persistent topics
-- =============================================
--
-- A story is a long-lived topic (e.g. "أخبار الأقصى") that
-- the admin defines once, provides a list of keywords, and
-- the system automatically matches every incoming article
-- against those keywords and links them in.

CREATE TABLE IF NOT EXISTS `evolving_stories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `icon` VARCHAR(20) NOT NULL DEFAULT '',
  `cover_image` VARCHAR(500) NULL,
  `accent_color` VARCHAR(20) NOT NULL DEFAULT '#0d9488',
  `keywords` TEXT NOT NULL,
  `exclude_keywords` TEXT NULL,
  `min_match_score` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `article_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_matched_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_active_order` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `evolving_story_articles` (
  `story_id` INT(11) NOT NULL,
  `article_id` INT(11) NOT NULL,
  `match_score` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `matched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`story_id`, `article_id`),
  KEY `idx_article` (`article_id`),
  KEY `idx_story_matched` (`story_id`, `matched_at`),
  CONSTRAINT `fk_esa_story`   FOREIGN KEY (`story_id`)   REFERENCES `evolving_stories`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_esa_article` FOREIGN KEY (`article_id`) REFERENCES `articles`(`id`)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Seed data — 5 initial stories
-- Keywords are JSON arrays of Arabic match terms.
-- =============================================

INSERT IGNORE INTO `evolving_stories`
  (`name`, `slug`, `description`, `icon`, `accent_color`, `keywords`, `min_match_score`, `sort_order`)
VALUES
(
  'أخبار الأقصى',
  'al-aqsa',
  'كل ما يتعلق بالمسجد الأقصى المبارك وقبة الصخرة وساحات الحرم القدسي — اقتحامات، صلوات، قرارات، وردود الأفعال.',
  '🕌',
  '#d97706',
  '["الأقصى","المسجد الأقصى","قبة الصخرة","الحرم القدسي","باب العمود","باب الأسباط","اقتحام الأقصى","اقتحام المسجد","ساحات الأقصى","المصلى القبلي","المصلى المرواني"]',
  1,
  1
),
(
  'أخبار الأسرى',
  'prisoners',
  'آخر المستجدات المتعلقة بالأسرى الفلسطينيين في سجون الاحتلال — اعتقالات، إضرابات، صفقات تبادل، وشهادات.',
  '⛓️',
  '#475569',
  '["الأسرى","الأسير","الأسيرات","أسير فلسطيني","المعتقلين","معتقل إداري","الاعتقال الإداري","صفقة تبادل","صفقة التبادل","إضراب عن الطعام","نادي الأسير","هيئة الأسرى","سجون الاحتلال","سجن عوفر","سجن النقب","سجن مجدو"]',
  1,
  2
),
(
  'أخبار غزة',
  'gaza',
  'تغطية شاملة لقطاع غزة — العدوان، المساعدات، الهدنة، والمواقف الإقليمية والدولية.',
  '🇵🇸',
  '#dc2626',
  '["غزة","قطاع غزة","رفح","خان يونس","بيت حانون","بيت لاهيا","جباليا","الشجاعية","دير البلح","النصيرات","المغازي","البريج","مخيم الشاطئ","شمال غزة","جنوب غزة","معبر رفح","معبر كرم أبو سالم"]',
  1,
  3
),
(
  'أخبار الضفة',
  'west-bank',
  'مستجدات الضفة الغربية — اقتحامات، اعتقالات، عمليات، وحياة المدن والقرى تحت الاحتلال.',
  '🏔️',
  '#059669',
  '["الضفة","الضفة الغربية","نابلس","جنين","مخيم جنين","رام الله","الخليل","طولكرم","قلقيلية","بيت لحم","أريحا","سلفيت","طوباس","بيتا","حوارة","مخيم بلاطة","مخيم نور شمس"]',
  1,
  4
),
(
  'الاستيطان',
  'settlements',
  'تتبّع المشروع الاستيطاني الإسرائيلي في الضفة والقدس — بناء، مصادرة، بؤر، واعتداءات المستوطنين.',
  '🏗️',
  '#7c2d12',
  '["مستوطن","مستوطنين","المستوطنين","الاستيطان","استيطان","مستوطنة","مستوطنات","بؤرة استيطانية","بؤر استيطانية","قافلة استيطانية","اعتداءات المستوطنين","إرهاب المستوطنين","وحدات استيطانية","معاليه أدوميم","كريات أربع","يتسهار"]',
  1,
  5
);
