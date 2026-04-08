<?php
$pageTitle = 'خلاصتي';
$pageSlug  = 'feed';
require __DIR__ . '/_layout.php';

$userId = (int)$me['id'];
$articles = user_personal_feed($userId, 30);
$bookmarkedIds = array_flip(user_bookmark_ids_for($userId, array_column($articles, 'id')));
?>
<div class="dash-topbar">
  <h1>⚡ خلاصتي</h1>
  <a href="following.php" class="btn">⚙️ إدارة المتابعات</a>
</div>

<?php if (!$articles): ?>
  <div class="panel-card">
    <div class="u-empty">
      <div class="u-empty-ico">🎯</div>
      <p>ابدأ بمتابعة الأقسام والمصادر اللي تهمك</p>
      <a href="following.php" class="btn primary" style="margin-top:8px;">اختر اهتماماتك</a>
    </div>
  </div>
<?php else: ?>
  <div class="u-grid">
    <?php foreach ($articles as $a):
      $isSaved = isset($bookmarkedIds[(int)$a['id']]);
      $imgUrl = $a['image_url'] ?? placeholderImage(400, 300);
    ?>
      <a class="u-card news-card" href="../<?= e(articleUrl($a)) ?>" data-article-id="<?= (int)$a['id'] ?>">
        <button type="button" class="nf-bookmark-btn <?= $isSaved ? 'saved' : '' ?>" title="<?= $isSaved ? 'إزالة من المحفوظات' : 'حفظ' ?>" data-save-id="<?= (int)$a['id'] ?>" onclick="event.preventDefault(); event.stopPropagation(); NF.toggleSave(this)">🔖</button>
        <div class="u-img"><img src="<?= e($imgUrl) ?>" alt="<?= e($a['title']) ?>" loading="lazy" decoding="async"></div>
        <div class="u-body">
          <span class="u-cat"><?= e($a['cat_name'] ?? '') ?></span>
          <div class="u-title"><?= e($a['title']) ?></div>
          <div class="u-meta">
            <span><?= e($a['source_name'] ?? '') ?></span>
            <span><?= timeAgo($a['published_at']) ?></span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_foot.php'; ?>
