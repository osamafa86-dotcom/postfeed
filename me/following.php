<?php
$pageTitle = 'متابعاتي';
$pageSlug  = 'following';
require __DIR__ . '/_layout.php';

$userId = (int)$me['id'];
$allCats = getCategories();
$allSources = getActiveSources();
$followedCatIds = user_followed_category_ids($userId);
$followedCats = user_followed_categories($userId);
$followedSrcIds = user_followed_source_ids($userId);
?>
<div class="dash-topbar">
  <h1>🎯 متابعاتي</h1>
  <span style="color:var(--muted); font-size:13px;">اختر الأقسام والمصادر لخلاصة مخصّصة</span>
</div>

<div class="panel-card">
  <div class="panel-head">
    <h2>📚 الأقسام</h2>
    <span class="muted"><?= count($followedCatIds) ?> من <?= count($allCats) ?></span>
  </div>
  <p style="color:var(--muted); font-size:13px; margin-bottom:14px;">اضغط على قسم لمتابعته أو إلغاء متابعته</p>
  <div class="chip-grid" id="catChips">
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
  <div class="panel-head">
    <h2>↕️ ترتيب الأولوية</h2>
    <span class="muted">اسحب لإعادة الترتيب</span>
  </div>
  <?php if (!$followedCats): ?>
    <p style="color:var(--muted); font-size:13px;">تابع قسم أو أكثر لتتمكن من ترتيبهم</p>
  <?php else: ?>
    <ul class="reorder-list" id="reorderList">
      <?php foreach ($followedCats as $c): ?>
        <li draggable="true" data-cat-id="<?= (int)$c['id'] ?>">
          <span class="drag-handle">⋮⋮</span>
          <span class="cat-icon"><?= e($c['icon'] ?? '📂') ?></span>
          <span style="flex:1; font-weight:600;"><?= e($c['name']) ?></span>
          <button type="button" class="btn sm danger" onclick="NF.unfollowCatRow(this, <?= (int)$c['id'] ?>)">إلغاء</button>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="panel-card">
  <div class="panel-head">
    <h2>🌐 المصادر الإخبارية</h2>
    <span class="muted"><?= count($followedSrcIds) ?> من <?= count($allSources) ?></span>
  </div>
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

<?php require __DIR__ . '/_layout_foot.php'; ?>
