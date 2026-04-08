<?php
$pageTitle = 'الرئيسية';
$pageSlug  = 'overview';
require __DIR__ . '/_layout.php';

$userId = (int)$me['id'];
$stats = user_stats($userId);
$achievements = user_achievements($stats);
$unlocked = array_filter($achievements, fn($a) => $a['cond']);
$recent = user_reading_history($userId, 4);
$recentBookmarks = user_bookmarks($userId, 4);
$personal = user_personal_feed($userId, 6);
?>
<div class="dash-topbar">
  <div>
    <h1>أهلاً، <?= e(mb_strtok($me['name'] ?? '', ' ')) ?> 👋</h1>
    <p style="color:var(--muted); font-size:13px; margin-top:4px;">
      <?= $stats['reads_today'] ? 'قرأت ' . $stats['reads_today'] . ' خبر اليوم — تابع!' : 'ابدأ يومك بقراءة خبر' ?>
    </p>
  </div>
  <div class="tb-actions">
    <a href="feed.php" class="btn primary">⚡ خلاصتي</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-ico">📖</div>
    <div>
      <div class="stat-val"><?= number_format($stats['reads_total']) ?></div>
      <div class="stat-lbl">خبر مقروء</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-ico">🔖</div>
    <div>
      <div class="stat-val"><?= number_format($stats['bookmarks']) ?></div>
      <div class="stat-lbl">محفوظ</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-ico">🎯</div>
    <div>
      <div class="stat-val"><?= (int)$stats['followed_categories'] ?></div>
      <div class="stat-lbl">قسم متابع</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-ico">🔥</div>
    <div>
      <div class="stat-val"><?= (int)$stats['streak'] ?> <span style="font-size:13px;color:var(--muted)">يوم</span></div>
      <div class="stat-lbl">سلسلة القراءة</div>
    </div>
  </div>
</div>

<?php if ($stats['top_category']): ?>
<div class="panel-card" style="background:linear-gradient(135deg, color-mix(in srgb, var(--accent) 8%, var(--surface)), var(--surface));">
  <div class="panel-head"><h2>📊 تحليل قراءتك</h2></div>
  <p style="color:var(--text-2); font-size:14px;">
    قسمك المفضل هذا الأسبوع هو <strong style="color:var(--accent)"><?= e($stats['top_category']) ?></strong>
    — قرأت <strong><?= (int)$stats['reads_week'] ?></strong> خبر خلال آخر 7 أيام.
  </p>
</div>
<?php endif; ?>

<div class="panel-card">
  <div class="panel-head">
    <h2>⚡ خلاصتك الشخصية</h2>
    <a href="feed.php" class="muted">عرض الكل ←</a>
  </div>
  <?php if (!$personal): ?>
    <div class="u-empty">
      <div class="u-empty-ico">🎯</div>
      <p>ابدأ بمتابعة الأقسام والمصادر اللي تهمك</p>
      <a href="following.php" class="btn primary" style="margin-top:8px;">اختر اهتماماتك</a>
    </div>
  <?php else: ?>
    <div class="u-grid">
      <?php foreach ($personal as $a): ?>
        <?php include __DIR__ . '/_partial_article_card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:18px;" class="two-col">
  <div class="panel-card">
    <div class="panel-head"><h2>🔖 آخر محفوظاتك</h2><a href="saved.php" class="muted">الكل ←</a></div>
    <?php if (!$recentBookmarks): ?>
      <p style="color:var(--muted); font-size:13px;">لا يوجد محفوظات بعد — استخدم زر الحفظ على أي خبر.</p>
    <?php else: ?>
      <?php foreach ($recentBookmarks as $a): ?>
        <div style="padding:10px 0; border-bottom:1px solid var(--border);">
          <a href="../<?= e(articleUrl($a)) ?>" style="font-weight:600; color:var(--text); font-size:14px; display:block;"><?= e($a['title']) ?></a>
          <div style="font-size:11px; color:var(--muted); margin-top:4px;"><?= e($a['source_name'] ?? '') ?> • <?= timeAgo($a['saved_at'] ?? $a['published_at']) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div class="panel-card">
    <div class="panel-head"><h2>🕒 آخر ما قرأت</h2><a href="history.php" class="muted">الكل ←</a></div>
    <?php if (!$recent): ?>
      <p style="color:var(--muted); font-size:13px;">لم تقرأ أي خبر بعد.</p>
    <?php else: ?>
      <?php foreach ($recent as $a): ?>
        <div style="padding:10px 0; border-bottom:1px solid var(--border);">
          <a href="../<?= e(articleUrl($a)) ?>" style="font-weight:600; color:var(--text); font-size:14px; display:block;"><?= e($a['title']) ?></a>
          <div style="font-size:11px; color:var(--muted); margin-top:4px;"><?= e($a['source_name'] ?? '') ?> • <?= timeAgo($a['read_at']) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="panel-card">
  <div class="panel-head">
    <h2>🏆 الإنجازات</h2>
    <span class="muted"><?= count($unlocked) ?>/<?= count($achievements) ?> مفتوحة</span>
  </div>
  <div class="ach-grid">
    <?php foreach ($achievements as $a): ?>
      <div class="ach-card <?= $a['cond'] ? 'unlocked' : '' ?>">
        <div class="ach-ico"><?= $a['icon'] ?></div>
        <div class="ach-title"><?= e($a['title']) ?></div>
        <div class="ach-desc"><?= e($a['desc']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
