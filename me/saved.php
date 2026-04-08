<?php
$pageTitle = 'المحفوظات';
$pageSlug  = 'saved';
require __DIR__ . '/_layout.php';

$userId = (int)$me['id'];
$bookmarks = user_bookmarks($userId, 60);
?>
<div class="dash-topbar">
  <h1>🔖 المحفوظات</h1>
  <span style="color:var(--muted); font-size:13px;"><?= count($bookmarks) ?> خبر محفوظ</span>
</div>

<?php if (!$bookmarks): ?>
  <div class="panel-card">
    <div class="u-empty">
      <div class="u-empty-ico">🔖</div>
      <p>ما في أخبار محفوظة بعد</p>
      <p style="font-size:12px">استخدم زر الحفظ على أي بطاقة خبر لتجدها هنا لاحقاً.</p>
      <a href="../index.php" class="btn primary" style="margin-top:10px;">تصفّح الأخبار</a>
    </div>
  </div>
<?php else: ?>
  <div class="u-grid">
    <?php foreach ($bookmarks as $a): ?>
      <?php include __DIR__ . '/_partial_article_card.php'; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_foot.php'; ?>
