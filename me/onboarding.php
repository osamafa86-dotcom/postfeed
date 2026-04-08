<?php
$pageTitle = 'اختر اهتماماتك';
$pageSlug  = 'following';
require __DIR__ . '/_layout.php';

$userId = (int)$me['id'];
$allCats = getCategories();
$allSources = getActiveSources();
$followedCatIds = user_followed_category_ids($userId);
$followedSrcIds = user_followed_source_ids($userId);
?>
<div class="dash-topbar">
  <div>
    <h1>👋 أهلاً بك في نيوزفلو</h1>
    <p style="color:var(--muted); font-size:13px; margin-top:4px;">اختر اهتماماتك لنبني لك خلاصة أخبار مخصّصة</p>
  </div>
</div>

<div class="panel-card">
  <div class="panel-head"><h2>📚 الأقسام اللي تهمك</h2></div>
  <div class="chip-grid">
    <?php foreach ($allCats as $c):
      $on = in_array((int)$c['id'], $followedCatIds, true);
    ?>
      <button type="button" class="chip <?= $on ? 'on' : '' ?>" data-follow-cat="<?= (int)$c['id'] ?>" onclick="NF.toggleFollow(this,'cat')">
        <?= e($c['icon'] ?? '') ?> <?= e($c['name']) ?>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel-card">
  <div class="panel-head"><h2>🌐 المصادر المفضّلة</h2></div>
  <div class="chip-grid">
    <?php foreach ($allSources as $s):
      $on = in_array((int)$s['id'], $followedSrcIds, true);
    ?>
      <button type="button" class="chip <?= $on ? 'on' : '' ?>" data-follow-src="<?= (int)$s['id'] ?>" onclick="NF.toggleFollow(this,'src')">
        <span style="width:8px;height:8px;border-radius:50%;background:<?= e($s['logo_color'] ?? '#5a85b0') ?>;display:inline-block;margin-inline-end:4px"></span>
        <?= e($s['name']) ?>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<div style="display:flex; gap:10px; margin-top:14px;">
  <a href="index.php" class="btn primary">متابعة إلى لوحتي ←</a>
  <a href="../index.php" class="btn">تخطي</a>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
