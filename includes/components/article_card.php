<?php
/**
 * Reusable article card.
 * Expects: $article (array), $catLabel (optional override label),
 *          $catClass (optional override CSS class), $seed (image fallback prefix)
 */
$catLabel = $catLabel ?? ($article['cat_name'] ?? '');
$catClass = $catClass ?? ($article['css_class'] ?? 'cat-political');
$seed     = $seed     ?? 'card';
$imgUrl   = $article['image_url'] ?? placeholderImage(400, 300);
$__aId    = (int)($article['id'] ?? 0);
$__isSaved = !empty($GLOBALS['__nf_saved_ids']) && isset($GLOBALS['__nf_saved_ids'][$__aId]);
?>
<a class="news-card" href="<?php echo articleUrl($article); ?>">
  <button type="button" class="nf-bookmark-btn <?php echo $__isSaved ? 'saved' : ''; ?>" title="<?php echo $__isSaved ? 'إزالة من المحفوظات' : 'حفظ'; ?>" data-save-id="<?php echo $__aId; ?>" onclick="event.preventDefault(); event.stopPropagation(); NF.toggleSave(this)">🔖</button>
  <div class="card-img"><img src="<?php echo e($imgUrl); ?>" alt="<?php echo e($article['title'] ?? ''); ?>" loading="lazy" decoding="async"></div>
  <div class="card-body">
    <span class="card-cat <?php echo e($catClass); ?>"><?php echo e($catLabel); ?></span>
    <?php echo renderClusterBadge($article); ?>
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
