<?php
/**
 * Reusable action bar: share / save / dislike / like with counts.
 * Expects $article (array with id) and optionally pre-populated globals:
 *   $GLOBALS['__nf_saved_ids']          array map [article_id => true]
 *   $GLOBALS['__nf_reaction_counts']    array map [article_id => ['like' => N, 'dislike' => N, 'share' => N]]
 *   $GLOBALS['__nf_user_reactions']     array map [article_id => 'like'|'dislike']
 */
$__aid = (int)($article['id'] ?? 0);
$__saved = !empty($GLOBALS['__nf_saved_ids']) && isset($GLOBALS['__nf_saved_ids'][$__aid]);
$__counts = $GLOBALS['__nf_reaction_counts'][$__aid] ?? ['like' => 0, 'dislike' => 0, 'share' => 0];
$__myReact = $GLOBALS['__nf_user_reactions'][$__aid] ?? null;
$__shareUrl = SITE_URL . '/' . ltrim(articleUrl($article), '/');
$__shareTitle = $article['title'] ?? '';
?>
<div class="nf-action-bar" onclick="event.stopPropagation()">
  <button type="button" class="nf-act like <?php echo $__myReact === 'like' ? 'active' : ''; ?>"
          data-react-id="<?php echo $__aid; ?>" data-react-type="like"
          onclick="event.preventDefault(); event.stopPropagation(); NF.toggleReaction(this)"
          title="إعجاب">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H7V10l4.34-8.66A1.93 1.93 0 0 1 13 .88 2 2 0 0 1 15 3v2.88z"/></svg>
    <span class="nf-act-count"><?php echo (int)$__counts['like']; ?></span>
  </button>
  <button type="button" class="nf-act dislike <?php echo $__myReact === 'dislike' ? 'active' : ''; ?>"
          data-react-id="<?php echo $__aid; ?>" data-react-type="dislike"
          onclick="event.preventDefault(); event.stopPropagation(); NF.toggleReaction(this)"
          title="عدم إعجاب">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 14V2"/><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H17v12l-4.34 8.66a1.93 1.93 0 0 1-1.66 1.12A2 2 0 0 1 9 21.12v-3z"/></svg>
    <span class="nf-act-count"><?php echo (int)$__counts['dislike']; ?></span>
  </button>
  <span class="nf-act-sep"></span>
  <button type="button" class="nf-act share"
          data-share-id="<?php echo $__aid; ?>"
          data-share-url="<?php echo htmlspecialchars($__shareUrl, ENT_QUOTES); ?>"
          data-share-title="<?php echo htmlspecialchars($__shareTitle, ENT_QUOTES); ?>"
          onclick="event.preventDefault(); event.stopPropagation(); NF.shareArticle(this)"
          title="مشاركة">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
    <?php if ((int)$__counts['share'] > 0): ?><span class="nf-act-count"><?php echo (int)$__counts['share']; ?></span><?php endif; ?>
  </button>
  <button type="button" class="nf-act save <?php echo $__saved ? 'saved' : ''; ?>"
          data-save-id="<?php echo $__aid; ?>"
          onclick="event.preventDefault(); event.stopPropagation(); NF.toggleSave(this)"
          title="<?php echo $__saved ? 'إزالة من المحفوظات' : 'حفظ'; ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
  </button>
</div>
