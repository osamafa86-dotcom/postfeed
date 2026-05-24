<?php
/**
 * News Mirror card — مرايا الأخبار.
 *
 * Renders the AI framing-contrast analysis for a cluster: a neutral
 * summary, "same concept / different words" term comparison, and each
 * source's angle. Renders nothing if $mirror is empty.
 *
 * Variables consumed:
 *   $mirror  array|null  output of news_mirror_for_cluster():
 *                        neutral_summary (string), divergent_terms (array),
 *                        framings (array).
 *
 * Relies on e() (escaping) from includes/functions.php.
 * Styles live in the host page (see cluster.php .mirror-* rules).
 */

$mirror = $mirror ?? null;
if (!$mirror) return;
if (empty($mirror['neutral_summary']) && empty($mirror['divergent_terms']) && empty($mirror['framings'])) return;

if (!function_exists('news_mirror_tone_class')) {
    function news_mirror_tone_class(string $tone): string {
        if (mb_strpos($tone, 'سلب') !== false) return 'tone-neg';
        if (mb_strpos($tone, 'إيجاب') !== false || mb_strpos($tone, 'ايجاب') !== false) return 'tone-pos';
        return 'tone-neutral';
    }
}
?>
<div class="mirror-card">
  <div class="mirror-header" onclick="var b=this.nextElementSibling;b.classList.toggle('hidden');this.classList.toggle('collapsed')">
    <h3>🪞 مرايا الأخبار — كيف اختلفت صياغة المصادر</h3>
    <span class="toggle">▾</span>
  </div>
  <div class="mirror-body">

    <?php if (!empty($mirror['neutral_summary'])): ?>
    <div class="mirror-block mirror-neutral">
      <h4>⚖️ الخلاصة المحايدة</h4>
      <p><?php echo e($mirror['neutral_summary']); ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($mirror['divergent_terms'])): ?>
    <div class="mirror-block">
      <h4>🔤 نفس المعنى... كلمات مختلفة</h4>
      <?php foreach ($mirror['divergent_terms'] as $term): ?>
        <?php if (empty($term['concept']) || empty($term['variants'])) continue; ?>
        <div class="mirror-term">
          <div class="mirror-concept"><?php echo e($term['concept']); ?></div>
          <div class="mirror-variants">
            <?php foreach ($term['variants'] as $v): ?>
              <span class="mirror-chip <?php echo news_mirror_tone_class((string)($v['tone'] ?? '')); ?>">
                <span class="term">«<?php echo e($v['term'] ?? ''); ?>»</span>
                <?php if (!empty($v['sources'])): ?>
                  <span class="src"><?php echo e(implode('، ', (array)$v['sources'])); ?></span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($mirror['framings'])): ?>
    <div class="mirror-block">
      <h4>🎚 زاوية كل مصدر</h4>
      <div class="mirror-framings-list">
        <?php foreach ($mirror['framings'] as $f): ?>
          <?php if (empty($f['angle'])) continue; ?>
          <div class="mirror-framing">
            <?php if (!empty($f['sources'])): ?>
              <div class="fr-src"><?php echo e(implode('، ', (array)$f['sources'])); ?></div>
            <?php endif; ?>
            <div class="fr-angle"><?php echo e($f['angle']); ?></div>
            <?php if (!empty($f['emphasis'])): ?>
              <div class="fr-emph"><?php echo e($f['emphasis']); ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="mirror-disclaimer">
      🤖 تحليل مُولّد بالذكاء الاصطناعي لمقارنة صياغة المصادر العربية المتاحة لهذا الخبر — قد لا يكون دقيقاً بالكامل، والهدف إبراز اختلاف التأطير لا الحكم على المصادر.
    </div>

  </div>
</div>
