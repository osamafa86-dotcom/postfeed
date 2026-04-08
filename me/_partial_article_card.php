<?php
/** Expects $a (article row from ArticleRepository/functions) */
$imgUrl = $a['image_url'] ?? placeholderImage(400, 300);
$catName = $a['cat_name'] ?? '';
?>
<a class="u-card news-card" href="../<?= e(articleUrl($a)) ?>" data-article-id="<?= (int)$a['id'] ?>">
  <button type="button" class="nf-bookmark-btn saved" title="إزالة من المحفوظات" data-save-id="<?= (int)$a['id'] ?>" onclick="event.preventDefault(); event.stopPropagation(); NF.toggleSave(this)">🔖</button>
  <div class="u-img"><img src="<?= e($imgUrl) ?>" alt="<?= e($a['title']) ?>" loading="lazy" decoding="async"></div>
  <div class="u-body">
    <span class="u-cat"><?= e($catName) ?></span>
    <div class="u-title"><?= e($a['title']) ?></div>
    <div class="u-meta">
      <span><?= e($a['source_name'] ?? '') ?></span>
      <span><?= timeAgo($a['published_at']) ?></span>
    </div>
  </div>
</a>
