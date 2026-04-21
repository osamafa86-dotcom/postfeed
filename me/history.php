<?php
$pageTitle = 'سجل القراءة';
$pageSlug  = 'history';
require __DIR__ . '/_layout.php';

$userId = (int)$me['id'];
$history = user_reading_history($userId, 60);
?>
<div class="dash-topbar">
  <h1>🕒 سجل القراءة</h1>
  <span style="color:var(--muted); font-size:13px;"><?= count($history) ?> خبر</span>
</div>

<?php if (!$history): ?>
  <div class="panel-card">
    <div class="u-empty">
      <div class="u-empty-ico">📖</div>
      <p>لم تقرأ أي خبر بعد</p>
      <a href="../index.php" class="btn primary" style="margin-top:8px;">ابدأ القراءة</a>
    </div>
  </div>
<?php else: ?>
  <div class="panel-card">
    <?php foreach ($history as $a): ?>
      <div style="display:flex; gap:12px; padding:12px 0; border-bottom:1px solid var(--border); align-items:center;">
        <?php if (!empty($a['image_url'])): ?>
          <?= responsiveImg($a['image_url'], '', '80px', [80, 160], '', 'lazy', 'style="width:80px; height:60px; border-radius:8px; object-fit:cover; flex-shrink:0;"') ?>
        <?php endif; ?>
        <div style="flex:1; min-width:0;">
          <a href="../<?= e(articleUrl($a)) ?>" style="font-weight:700; color:var(--text); font-size:14px; display:block;"><?= e($a['title']) ?></a>
          <div style="font-size:11px; color:var(--muted); margin-top:4px;">
            <?= e($a['cat_name'] ?? '') ?> • <?= e($a['source_name'] ?? '') ?> • <?= timeAgo($a['read_at']) ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_foot.php'; ?>
