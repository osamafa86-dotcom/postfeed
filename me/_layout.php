<?php
/**
 * Shared layout for /me/ dashboard pages.
 * Expects before include: $pageTitle, $pageSlug (for active nav)
 * Then body content until _layout_foot.php
 */
require_once __DIR__ . '/../includes/user_auth.php';
require_once __DIR__ . '/../includes/user_functions.php';

require_user_login('me/');
$me = current_user();
$theme = current_theme();
$unread = user_unread_notifications_count((int)$me['id']);
$siteName = defined('SITE_NAME') ? SITE_NAME : 'نيوزفلو';

$active = $pageSlug ?? '';
$navItems = [
    ['slug' => 'overview',     'href' => 'index.php',         'ico' => '🏠', 'label' => 'الرئيسية'],
    ['slug' => 'feed',         'href' => 'feed.php',          'ico' => '⚡', 'label' => 'خلاصتي'],
    ['slug' => 'saved',        'href' => 'saved.php',         'ico' => '🔖', 'label' => 'المحفوظات'],
    ['slug' => 'following',    'href' => 'following.php',     'ico' => '🎯', 'label' => 'متابعاتي'],
    ['slug' => 'history',      'href' => 'history.php',       'ico' => '🕒', 'label' => 'سجل القراءة'],
    ['slug' => 'notifications','href' => 'notifications.php', 'ico' => '🔔', 'label' => 'الإشعارات', 'badge' => $unread],
    ['slug' => 'settings',     'href' => 'settings.php',      'ico' => '⚙️', 'label' => 'الإعدادات'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'لوحة التحكم') ?> - <?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/user.css?v=5">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body>
<div class="dash-shell">
  <aside class="dash-side">
    <div class="dash-brand">
      <div class="logo-icon">N</div>
      <div>
        <div class="brand-txt"><?= e($siteName) ?></div>
        <div class="brand-sub">لوحة التحكم</div>
      </div>
    </div>
    <div class="side-user">
      <div class="avatar"><?= e(mb_substr($me['name'] ?? '?', 0, 1)) ?></div>
      <div class="meta">
        <div><?= e($me['name'] ?? '') ?></div>
        <small><?= e($me['email'] ?? '') ?></small>
      </div>
    </div>
    <nav class="dash-nav">
      <?php foreach ($navItems as $item): ?>
        <a href="<?= e($item['href']) ?>" class="<?= $active === $item['slug'] ? 'active' : '' ?>">
          <span class="nav-ico"><?= $item['ico'] ?></span>
          <span><?= e($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?>
            <span class="nav-badge"><?= (int)$item['badge'] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <a href="../index.php" style="margin-top:12px; border-top:1px solid var(--border); padding-top:14px;">
        <span class="nav-ico">🌐</span><span>عرض الموقع</span>
      </a>
      <a href="../account/logout.php" class="danger">
        <span class="nav-ico">🚪</span><span>تسجيل الخروج</span>
      </a>
    </nav>
  </aside>
  <main class="dash-main">
