-- =============================================
-- نيوز فيد — القصص المتطوّرة المُعرَّفة من الإدارة
-- Evolving Stories: admin-defined persistent topics
-- =============================================
--
-- A story is a long-lived topic (e.g. "أخبار القدس") that
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
  (`name`, `slug`, `description`, `icon`, `accent_color`, `keywords`, `exclude_keywords`, `min_match_score`, `sort_order`)
VALUES
(
  'أخبار القدس',
  'al-aqsa',
  'كل ما يتعلق بالقدس الشريف والمسجد الأقصى المبارك — اقتحامات، اعتداءات، أوقاف، وقرارات الاحتلال في المدينة المقدسة.',
  '🕌',
  '#d97706',
  '["القدس","الأقصى","المسجد الأقصى","قبة الصخرة","الحرم القدسي","باب العمود","باب الأسباط","اقتحام الأقصى","اقتحام المسجد","ساحات الأقصى","المصلى القبلي","المصلى المرواني","البلدة القديمة","حي الشيخ جراح","سلوان","حائط البراق","كنيسة القيامة","أوقاف القدس","شرقي القدس","شرق القدس","القدس الشرقية","بلدية الاحتلال في القدس"]',
  '["طوفان الأقصى","جامعة الأقصى","مستشفى الأقصى","كتائب الأقصى","كتيبة الأقصى","سرايا الأقصى","شهداء الأقصى","قناة الأقصى","إذاعة الأقصى","حركة الأقصى","ملاعب الأقصى","فضائية الأقصى"]',
  1,
  1
),
(
  'أخبار الأسرى',
  'prisoners',
  'آخر المستجدات المتعلقة بالأسرى الفلسطينيين في سجون الاحتلال — اعتقالات، إضرابات، صفقات تبادل، وشهادات.',
  '⛓️',
  '#475569',
  '["الأسرى الفلسطينيين","أسير فلسطيني","المعتقلين الفلسطينيين","معتقل إداري","الاعتقال الإداري","صفقة تبادل","صفقة التبادل","إضراب عن الطعام","نادي الأسير","هيئة الأسرى","نادي الأسرى","سجون الاحتلال","سجن عوفر","سجن النقب","سجن مجدو","سجن ريمون","سجن نفحة","سجن جلبوع","الأسرى في سجون","أسرى الحرية"]',
  '["أسرى الاحتلال","أسرى إسرائيليين","أسرى أوكرانيين","أسرى روس","أسرى أوكرانيا","أسرى الحرب الأوكرانية","الأسرى الأمريكيين","الأسرى البريطانيين"]',
  1,
  2
),
(
  'أخبار غزة',
  'gaza',
  'تغطية شاملة لقطاع غزة — العدوان، المساعدات، الهدنة، والمواقف الإقليمية والدولية.',
  '🇵🇸',
  '#dc2626',
  '["غزة","قطاع غزة","رفح","خان يونس","بيت حانون","بيت لاهيا","جباليا","الشجاعية","دير البلح","النصيرات","المغازي","البريج","مخيم الشاطئ","شمال غزة","جنوب غزة","معبر رفح","معبر كرم أبو سالم","العدوان على غزة","الحرب على غزة","قصف غزة"]',
  '["غزة هاشم","سرايا غزة","موسم في غزة"]',
  1,
  3
),
(
  'أخبار الضفة',
  'west-bank',
  'مستجدات الضفة الغربية — اقتحامات، اعتقالات، عمليات، وحياة المدن والقرى تحت الاحتلال.',
  '🏔️',
  '#059669',
  '["الضفة الغربية","نابلس","جنين","مخيم جنين","رام الله","الخليل","طولكرم","قلقيلية","بيت لحم","أريحا","سلفيت","طوباس","بيتا","حوارة","مخيم بلاطة","مخيم نور شمس","اقتحام نابلس","اقتحام جنين","اقتحام طولكرم"]',
  '["الضفة الأخرى","الضفة المقابلة","الضفة اليسرى","الضفة اليمنى","ضفة النيل","ضفة النهر"]',
  1,
  4
),
(
  'الاستيطان',
  'settlements',
  'تتبّع المشروع الاستيطاني الإسرائيلي في الضفة والقدس — بناء، مصادرة، بؤر، واعتداءات المستوطنين.',
  '🏗️',
  '#7c2d12',
  '["مستوطن إسرائيلي","المستوطنين الإسرائيليين","الاستيطان الإسرائيلي","استيطان في الضفة","مستوطنة إسرائيلية","مستوطنات الضفة","بؤرة استيطانية","بؤر استيطانية","قافلة استيطانية","اعتداءات المستوطنين","إرهاب المستوطنين","وحدات استيطانية","معاليه أدوميم","كريات أربع","يتسهار","مستوطني الضفة","المشروع الاستيطاني"]',
  '["استيطان نفسي","استيطان غذائي","استيطان رقمي","استيطان ذهني"]',
  1,
  5
);
