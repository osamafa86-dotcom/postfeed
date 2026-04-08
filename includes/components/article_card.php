<?php
/**
 * Reusable article card.
 * Expects: $article (array), $catLabel (optional override label),
 *          $catClass (optional override CSS class), $seed (image fallback prefix)
 */
$catLabel = $catLabel ?? ($article['cat_name'] ?? '');
$catClass = $catClass ?? ($article['css_class'] ?? 'cat-political');
$seed     = $seed     ?? 'card';
$imgUrl   = $article['image_url'] ?? ('https://picsum.photos/seed/' . $seed . rand(1,10) . '/400/300');
?>
<a class="news-card" href="<?php echo articleUrl($article); ?>">
  <div class="card-img"><img src="<?php echo e($imgUrl); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
  <div class="card-body">
    <span class="card-cat <?php echo e($catClass); ?>"><?php echo e($catLabel); ?></span>
    <div class="card-title"><?php echo e($article['title'] ?? ''); ?></div>
    <div class="card-excerpt"><?php echo e(mb_substr($article['excerpt'] ?? '', 0, 150)); ?></div>
    <div class="card-meta">
      <div class="card-source">
        <span class="source-dot" style="background:<?php echo e($article['logo_color'] ?? '#6b9fd4'); ?>"></span>
        <a href="source/<?php echo (int)($article['source_id'] ?? 0); ?>" onclick="event.stopPropagation()" style="color:inherit"><?php echo e($article['source_name'] ?? ''); ?></a>
      </div>
      <span class="card-time"><?php echo timeAgo($article['published_at']); ?></span>
    </div>
  </div>
</a>
