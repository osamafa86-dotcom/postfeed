<?php
/**
 * Compact side card for the homepage featured 3-column grid.
 * Expects $article (array row).
 */
$__imgUrl = $article['image_url'] ?? placeholderImage(200, 160);
?>
<div class="nf-side-card">
  <a class="nf-side-card-link" href="<?php echo articleUrl($article); ?>">
    <div class="nf-side-card-row">
      <div class="nf-side-card-img">
        <img src="<?php echo e($__imgUrl); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async">
      </div>
      <div class="nf-side-card-body">
        <div class="nf-side-card-meta">
          <span><?php echo e(timeAgo($article['published_at'] ?? 'now')); ?></span>
          <span class="sep">|</span>
          <span class="cat"><?php echo e($article['cat_name'] ?? ''); ?></span>
        </div>
        <div class="nf-side-card-title"><?php echo e($article['title'] ?? ''); ?></div>
      </div>
    </div>
  </a>
  <?php include __DIR__ . '/action_bar.php'; ?>
</div>
