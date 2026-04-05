-- =============================================
-- نيوزفلو - قاعدة البيانات
-- NewsFlow Database Schema
-- =============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+03:00";
SET NAMES utf8mb4;

-- =============================================
-- جدول التصنيفات
-- =============================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `icon` varchar(10) DEFAULT NULL,
  `css_class` varchar(50) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`name`, `slug`, `icon`, `css_class`, `sort_order`) VALUES
('عاجل', 'breaking', '🔴', 'cat-breaking', 0),
('سياسة', 'political', '🏛', 'cat-political', 1),
('اقتصاد', 'economy', '💹', 'cat-economic', 2),
('رياضة', 'sports', '⚽', 'cat-sports', 3),
('فنون', 'arts', '🎨', 'cat-arts', 4),
('ميديا', 'media', '🎥', 'cat-media', 5),
('تقارير', 'reports', '📊', 'cat-reports', 6),
('تكنولوجيا', 'tech', '💻', 'cat-political', 7),
('صحة', 'health', '🏥', 'cat-political', 8);

-- =============================================
-- جدول المصادر الإخبارية
-- =============================================
CREATE TABLE IF NOT EXISTS `sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `logo_letter` varchar(5) DEFAULT NULL,
  `logo_color` varchar(20) DEFAULT '#5a85b0',
  `logo_bg` varchar(20) DEFAULT '#e5eef8',
  `url` varchar(500) DEFAULT NULL,
  `rss_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `articles_today` int(11) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sources` (`name`, `slug`, `logo_letter`, `logo_color`, `logo_bg`, `url`) VALUES
('الجزيرة', 'aljazeera', 'ج', '#5a85b0', '#e5eef8', 'https://aljazeera.net'),
('رويترز عربي', 'reuters', 'ر', '#3d6690', '#e5f0f8', 'https://reuters.com/ar'),
('العربية', 'alarabiya', 'ع', '#8f4040', '#fae8e8', 'https://alarabiya.net'),
('BBC عربي', 'bbc', 'B', '#2e7a50', '#e5f3ec', 'https://bbc.com/arabic'),
('CNN عربي', 'cnn', 'C', '#5a3d8a', '#ede8f5', 'https://arabic.cnn.com'),
('سكاي رياضة', 'sky-sports', 'S', '#6b9fd4', '#e5eef8', NULL),
('بلومبرغ', 'bloomberg', 'B', '#85c1a3', '#e5f3ec', NULL),
('فوربس عربية', 'forbes', 'F', '#6b9fd4', '#e5eef8', NULL),
('أرقام', 'argaam', 'أ', '#c9ab6e', '#f5ede0', NULL),
('سينما عرب', 'cinema-arab', 'س', '#a08cc8', '#ede8f5', NULL),
('روتانا', 'rotana', 'ر', '#b48cb4', '#f5e8f5', NULL),
('Goal Arabia', 'goal', 'G', '#85c1a3', '#e5f3ec', NULL);

-- =============================================
-- جدول الأخبار
-- =============================================
CREATE TABLE IF NOT EXISTS `articles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(500) NOT NULL,
  `slug` varchar(500) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `is_featured` tinyint(1) DEFAULT 0,
  `is_breaking` tinyint(1) DEFAULT 0,
  `is_hero` tinyint(1) DEFAULT 0,
  `view_count` int(11) DEFAULT 0,
  `comments` int(11) DEFAULT 0,
  `status` enum('published','draft','archived') DEFAULT 'published',
  `published_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `source_id` (`source_id`),
  KEY `status` (`status`),
  KEY `is_breaking` (`is_breaking`),
  KEY `is_featured` (`is_featured`),
  KEY `published_at` (`published_at`),
  CONSTRAINT `fk_article_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_article_source` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بيانات تجريبية للأخبار
INSERT INTO `articles` (`title`, `slug`, `excerpt`, `image_url`, `category_id`, `source_id`, `is_featured`, `is_breaking`, `is_hero`, `view_count`, `comments`, `published_at`) VALUES
-- أخبار بارزة (Hero)
('قمة عربية طارئة تبحث التطورات الأمنية وتداعياتها على المنطقة في ظل توترات متصاعدة', 'arab-summit-emergency', 'قمة عربية طارئة لبحث التطورات الأمنية الإقليمية', 'https://picsum.photos/seed/news1/800/500', 2, 1, 1, 0, 1, 24580, 342, NOW() - INTERVAL 15 MINUTE),
('أسواق الخليج تسجل مكاسب قياسية مدفوعة بارتفاع أسعار النفط فوق 85 دولاراً', 'gulf-markets-gains', 'مكاسب قياسية في أسواق الخليج', 'https://picsum.photos/seed/news2/400/200', 3, 2, 1, 0, 1, 12000, 0, NOW() - INTERVAL 32 MINUTE),
('البطولة العربية: نتائج مثيرة وأهداف تاريخية في الجولة الأخيرة', 'arab-championship-results', 'نتائج مثيرة في البطولة العربية', 'https://picsum.photos/seed/news3/400/200', 4, 6, 1, 0, 1, 8400, 0, NOW() - INTERVAL 1 HOUR),

-- أخبار عاجلة
('الرئيس يوجّه بعقد اجتماع طارئ لمجلس الوزراء لمناقشة الأوضاع الأمنية على الحدود', 'president-emergency-meeting', NULL, 'https://picsum.photos/seed/brk1/200/150', 1, 2, 0, 1, 0, 41000, 0, NOW() - INTERVAL 5 MINUTE),
('زلزال بقوة 5.8 درجات يضرب منطقة ساحلية ولا أنباء عن خسائر حتى الآن', 'earthquake-58', NULL, 'https://picsum.photos/seed/brk2/200/150', 1, 4, 0, 1, 0, 28000, 0, NOW() - INTERVAL 11 MINUTE),
('وزير الخارجية يغادر إلى جنيف على رأس وفد دبلوماسي لإجراء مباحثات عاجلة', 'foreign-minister-geneva', NULL, 'https://picsum.photos/seed/brk3/200/150', 1, 1, 0, 1, 0, 19000, 0, NOW() - INTERVAL 18 MINUTE),

-- آخر الأخبار
('مفاوضات السلام تستأنف أعمالها بعد توقف لأشهر وسط ترقب دولي واسع', 'peace-negotiations-resume', NULL, 'https://picsum.photos/seed/lat1/400/300', 2, 1, 0, 0, 0, 5000, 0, NOW() - INTERVAL 45 MINUTE),
('صندوق النقد يرفع توقعاته للنمو الاقتصادي في المنطقة العربية لعام 2026', 'imf-growth-forecast', NULL, 'https://picsum.photos/seed/lat2/400/300', 3, 2, 0, 0, 0, 3200, 0, NOW() - INTERVAL 1 HOUR),
('ريال مدريد يعلن ضم نجم مفاجئ بصفقة قياسية قبيل انتهاء سوق الانتقالات', 'real-madrid-signing', NULL, 'https://picsum.photos/seed/lat3/400/300', 4, 6, 0, 0, 0, 8700, 0, NOW() - INTERVAL 2 HOUR),

-- أخبار سياسية
('اجتماع طارئ لوزراء خارجية دول مجلس التعاون لمناقشة التطورات الإقليمية', 'gcc-foreign-ministers', NULL, 'https://picsum.photos/seed/pol1/400/300', 2, 3, 0, 0, 0, 4500, 0, NOW() - INTERVAL 3 HOUR),
('البرلمان يصادق على قانون جديد لتعزيز الشفافية في المؤسسات العامة', 'parliament-transparency-law', NULL, 'https://picsum.photos/seed/pol2/400/300', 2, 3, 0, 0, 0, 3800, 0, NOW() - INTERVAL 4 HOUR),
('الأمم المتحدة تطالب بوقف فوري للتصعيد العسكري وفتح ممرات إنسانية', 'un-ceasefire-demand', NULL, 'https://picsum.photos/seed/pol3/400/300', 2, 4, 0, 0, 0, 6100, 0, NOW() - INTERVAL 5 HOUR),
('الرئيس الأمريكي يتحدث هاتفياً مع قادة المنطقة لبحث ملف التسوية', 'us-president-calls', NULL, 'https://picsum.photos/seed/pol4/400/300', 2, 5, 0, 0, 0, 5200, 0, NOW() - INTERVAL 6 HOUR),

-- أخبار اقتصادية
('البنك المركزي يثبّت أسعار الفائدة للربع الثاني على التوالي وسط ضغوط تضخمية', 'central-bank-interest-rates', NULL, 'https://picsum.photos/seed/eco1/400/300', 3, 7, 0, 0, 0, 4100, 0, NOW() - INTERVAL 2 HOUR),
('شركة أمازون تعلن استثمارات ضخمة في البنية التحتية الرقمية بالشرق الأوسط', 'amazon-investment-me', NULL, 'https://picsum.photos/seed/eco2/400/300', 3, 8, 0, 0, 0, 3600, 0, NOW() - INTERVAL 3 HOUR),
('ارتفاع مؤشرات سوق الأسهم السعودية بنسبة 2.3% في ختام جلسة تداول متوترة', 'saudi-stock-market', NULL, 'https://picsum.photos/seed/eco3/400/300', 3, 9, 0, 0, 0, 2900, 0, NOW() - INTERVAL 4 HOUR),

-- أخبار رياضية
('دوري أبطال أوروبا: نتائج متفجرة في دور الـ16 وإقصاء مفاجئ لأحد الأوفياء', 'ucl-round-16', NULL, 'https://picsum.photos/seed/sp1/400/300', 4, 6, 0, 0, 0, 7200, 0, NOW() - INTERVAL 1 HOUR),
('نجم المنتخب يكشف عن إصابته ويشكك في مشاركته في تصفيات كأس العالم', 'player-injury', NULL, 'https://picsum.photos/seed/sp2/400/300', 4, 12, 0, 0, 0, 5400, 0, NOW() - INTERVAL 2 HOUR),
('الهلال يحسم قمة كأس الملك بثلاثية نظيفة ويمضي نحو اللقب السادس عشر', 'hilal-kings-cup', NULL, 'https://picsum.photos/seed/sp3/400/300', 4, 6, 0, 0, 0, 6800, 0, NOW() - INTERVAL 3 HOUR),

-- فنون
('مهرجان القاهرة السينمائي يكشف قائمة أفلام المسابقة الرسمية لدورته الجديدة', 'cairo-film-festival', NULL, 'https://picsum.photos/seed/art1/400/300', 5, 10, 0, 0, 0, 2100, 0, NOW() - INTERVAL 5 HOUR),
('الفنانة نجوى كرم تطلق ألبومها الجديد بعد غياب أربع سنوات وسط ترقب جماهيري', 'najwa-karam-album', NULL, 'https://picsum.photos/seed/art2/400/300', 5, 11, 0, 0, 0, 3400, 0, NOW() - INTERVAL 6 HOUR),

-- تقارير
('تقرير شامل: كيف غيّر الذكاء الاصطناعي منظومة العمل في المنطقة العربية؟', 'ai-impact-arab-world', NULL, 'https://picsum.photos/seed/rep1/400/300', 7, 8, 0, 0, 0, 8900, 0, NOW() - INTERVAL 1 DAY),
('تحقيق استقصائي: ملف الفساد في قطاع المقاولات يكشف شبكة مالية معقدة', 'corruption-investigation', NULL, 'https://picsum.photos/seed/rep2/400/300', 7, 5, 0, 0, 0, 6700, 0, NOW() - INTERVAL 2 DAY),
('مستقبل المياه: أزمة تهدد دول الشرق الأوسط مع ارتفاع درجات الحرارة', 'water-crisis-me', NULL, 'https://picsum.photos/seed/rep3/400/300', 7, 1, 0, 0, 0, 5500, 0, NOW() - INTERVAL 3 DAY);

-- =============================================
-- جدول الأخبار العاجلة (شريط الأخبار)
-- =============================================
CREATE TABLE IF NOT EXISTS `ticker_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `text` varchar(500) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ticker_items` (`text`, `sort_order`) VALUES
('مجلس الأمن يعقد جلسة طارئة لبحث التطورات الإقليمية', 1),
('انخفاض ملحوظ في أسعار النفط بعد قرارات أوبك+', 2),
('المنتخب الوطني يتأهل لنهائيات بطولة آسيا', 3),
('الحكومة تعلن حزمة دعم اقتصادية جديدة بقيمة مليار دولار', 4),
('افتتاح مهرجان الأفلام العربية الدولي في العاصمة', 5),
('اتفاقية تجارية جديدة تُحدث نقلة نوعية في العلاقات الإقليمية', 6),
('وزير الصحة يؤكد جاهزية المنظومة الصحية لمواجهة الأوبئة', 7);

-- =============================================
-- جدول الترندات
-- =============================================
CREATE TABLE IF NOT EXISTS `trends` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(300) NOT NULL,
  `tweet_count` int(11) DEFAULT 0,
  `search_count` int(11) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `trends` (`title`, `tweet_count`, `search_count`, `sort_order`) VALUES
('قمة مجلس التعاون الخليجي الطارئة', 42800, 0, 1),
('انهيار الليرة التركية مقابل الدولار', 28400, 0, 2),
('بطولة آسيا تحت 23 سنة', 19100, 0, 3),
('فيلم عربي يحصد جوائز أوسكار', 0, 15300, 4),
('أسعار الوقود الجديدة في المنطقة', 0, 12900, 5);

-- =============================================
-- جدول الإشعارات
-- =============================================
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `text` text NOT NULL,
  `icon` varchar(10) DEFAULT NULL,
  `icon_bg` varchar(20) DEFAULT '#e5eef8',
  `source_name` varchar(100) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `notifications` (`text`, `icon`, `icon_bg`, `source_name`, `created_at`) VALUES
('خبر عاجل: الرئيس يعلن حالة الطوارئ في ثلاث محافظات', '🔴', '#fae8e8', 'الجزيرة', NOW() - INTERVAL 3 MINUTE),
('هدف في الدقيقة 89 يقلب نتيجة مباراة الكلاسيكو', '⚽', '#e5eef8', 'سكاي رياضة', NOW() - INTERVAL 12 MINUTE),
('البيتكوين يتجاوز 95,000 دولار للمرة الأولى هذا الشهر', '💹', '#e5f3ec', 'بلومبرغ', NOW() - INTERVAL 28 MINUTE),
('فيلم "أرض النعيم" يفوز بجائزة السعفة الذهبية في كان', '🎬', '#ede8f5', 'روتانا', NOW() - INTERVAL 1 HOUR),
('مجلس الأمن يعقد جلسة طارئة الساعة 6 مساءً بتوقيت نيويورك', '🏛', '#e5eef8', 'رويترز', NOW() - INTERVAL 2 HOUR);

-- =============================================
-- جدول استطلاعات الرأي
-- =============================================
CREATE TABLE IF NOT EXISTS `polls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question` varchar(500) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `total_votes` int(11) DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `polls` (`question`, `total_votes`, `expires_at`) VALUES
('ما أبرز قضية تتابعها هذا الأسبوع؟', 12450, DATE_ADD(NOW(), INTERVAL 2 DAY));

CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `poll_id` int(11) NOT NULL,
  `text` varchar(300) NOT NULL,
  `votes` int(11) DEFAULT 0,
  `percentage` decimal(5,2) DEFAULT 0,
  `color` varchar(20) DEFAULT 'var(--accent)',
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `poll_id` (`poll_id`),
  CONSTRAINT `fk_poll_option` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `poll_options` (`poll_id`, `text`, `votes`, `percentage`, `color`, `sort_order`) VALUES
(1, 'الوضع السياسي الإقليمي', 5976, 48.00, 'var(--accent)', 1),
(1, 'الأزمة الاقتصادية', 3486, 28.00, 'var(--accent2)', 2),
(1, 'البطولات الرياضية', 1743, 14.00, 'var(--accent3)', 3),
(1, 'الفنون والثقافة', 1245, 10.00, 'var(--gold)', 4);

-- =============================================
-- جدول الوسائط (ميديا)
-- =============================================
CREATE TABLE IF NOT EXISTS `media_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(500) NOT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `type` enum('video','image','live') DEFAULT 'video',
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `media_items` (`title`, `image_url`, `sort_order`) VALUES
('مؤتمر صحفي: وزير الدفاع يكشف تفاصيل العملية العسكرية', 'https://picsum.photos/seed/med1/400/225', 1),
('تقرير مصوّر: داخل غرفة عمليات الأزمات', 'https://picsum.photos/seed/med2/400/225', 2),
('حوار خاص مع رئيس الوزراء حول الخطة الاقتصادية', 'https://picsum.photos/seed/med3/400/225', 3),
('تغطية مباشرة: افتتاح المعرض الدولي للتكنولوجيا', 'https://picsum.photos/seed/med4/400/225', 4);

-- =============================================
-- جدول المستخدمين (لوحة التحكم)
-- =============================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','editor','viewer') DEFAULT 'viewer',
  `avatar_letter` varchar(5) DEFAULT NULL,
  `plan` varchar(50) DEFAULT 'free',
  `preferences` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- كلمة المرور الافتراضية: admin123 (مشفرة بـ bcrypt)
INSERT INTO `users` (`name`, `email`, `password`, `role`, `avatar_letter`, `plan`) VALUES
('أسامة المعايضة', 'osama.fa.mayadmeh@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'أ', 'premium');

-- =============================================
-- جدول إعدادات الموقع
-- =============================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'نيوزفلو'),
('site_tagline', 'مجمع المصادر الإخبارية'),
('site_currency_usd', '0.71 JD'),
('site_currency_eur', '0.78 JD'),
('weather_city', 'عمّان، الأردن'),
('weather_temp', '22'),
('weather_desc', 'مشمس جزئياً'),
('weather_icon', '☀️');

-- =============================================
-- جدول الأكثر قراءة
-- =============================================
CREATE TABLE IF NOT EXISTS `most_read` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(500) NOT NULL,
  `view_count` int(11) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `article_id` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `most_read` (`title`, `view_count`, `sort_order`) VALUES
('السيسي يوجّه القوات المسلحة باتخاذ كل الإجراءات اللازمة', 94000, 1),
('البنك الدولي يحذر من ركود اقتصادي عالمي في 2026', 71000, 2),
('كريستيانو رونالدو يعلن اعتزاله الكرة الدولية رسمياً', 58000, 3);

COMMIT;
