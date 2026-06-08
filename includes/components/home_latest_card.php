<?php
/**
 * "آخر الأخبار" card — image-on-top layout used by the homepage 3×2 grid.
 * Matches the Figma latest-news cards (image header, excerpt, title,
 * category pill, source + timestamp footer).
 * Expects $article (array row).
 */
$__imgUrl = $article['image_url'] ?? placeholderImage(600, 400);
$__excerpt = '';
if (!empty($article['excerpt'])) {
    $__excerpt = mb_substr(strip_tags($article['excerpt']), 0, 90);
} elseif (!empty($article['content'])) {
    $__excerpt = mb_substr(strip_tags($article['content']), 0, 90);
}
?>
<a class="nf-latest-card" href="<?php echo articleUrl($article); ?>">
  <div class="nf-latest-card-img">
    <?php echo responsiveImg($__imgUrl, $article['title'] ?? '', '(max-width:700px) 100vw, 360px', [320, 480, 720], '', 'lazy'); ?>
  </div>
  <div class="nf-latest-card-body">
    <?php if ($__excerpt): ?>
      <div class="nf-latest-card-excerpt"><?php echo e($__excerpt); ?>.</div>
    <?php endif; ?>
    <h3 class="nf-latest-card-title"><?php echo e($article['title'] ?? ''); ?></h3>
    <div class="nf-latest-card-meta">
      <?php if (!empty($article['cat_name'])): ?>
        <span class="nf-latest-card-cat"><?php echo e($article['cat_name']); ?></span>
      <?php endif; ?>
    </div>
    <div class="nf-latest-card-foot">
      <?php if (!empty($article['source_name'])): ?>
        <span class="nf-latest-card-source">
          <span class="src-dot" style="background:<?php echo e($article['logo_color'] ?? '#3D5A28'); ?>"></span>
          <?php echo e($article['source_name']); ?>
        </span>
      <?php endif; ?>
      <span class="nf-latest-card-time"><?php echo timeAgo($article['published_at'] ?? 'now'); ?></span>
    </div>
  </div>
</a>
