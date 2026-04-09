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
        <?php echo renderClusterBadge($article); ?>
        <?php if (!empty($article['source_name'])): ?>
          <div class="nf-side-card-source">
            <span class="src-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"><?php echo e(mb_substr($article['source_name'], 0, 1)); ?></span>
            <span><?php echo e($article['source_name']); ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </a>
  <?php include __DIR__ . '/action_bar.php'; ?>
</div>
