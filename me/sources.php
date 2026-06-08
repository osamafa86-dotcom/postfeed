<?php
$pageTitle = 'مصادري';
$pageSlug  = 'sources';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/user_sources.php';

$userId  = (int)$me['id'];
$sources = user_sources_list($userId);
$count   = count($sources);
$active  = user_sources_count($userId, true);

/** Inline platform glyph (white stroke; coloured by the circle behind it). */
function usrc_icon_svg(string $type): string {
    $p = [
        'rss'      => '<path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.6" fill="#fff" stroke="none"/>',
        'website'  => '<circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18"/>',
        'telegram' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'x'        => '<line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/>',
        'youtube'  => '<rect x="3" y="6" width="18" height="12" rx="3.5"/><polygon points="11 9.5 15 12 11 14.5" fill="#fff" stroke="none"/>',
    ][$type] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}
?>
<style>
.usrc-sub { color:var(--muted); font-size:13px; }
.usrc-add { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:22px; margin-bottom:22px; box-shadow:var(--shadow-sm,0 1px 3px rgba(0,0,0,.05)); }
.usrc-add h2 { font-size:18px; font-weight:800; margin:0 0 4px; color:var(--text); }
.usrc-add p { font-size:13px; color:var(--muted); margin:0 0 14px; line-height:1.6; }
.usrc-add-form { display:flex; gap:10px; }
.usrc-add-form input { flex:1; min-width:0; border:1px solid var(--border); background:var(--bg,#fff); border-radius:11px; padding:13px 15px; font-family:inherit; font-size:14px; color:var(--text); outline:none; transition:border-color .2s, box-shadow .2s; }
.usrc-add-form input:focus { border-color:var(--accent); box-shadow:0 0 0 4px rgba(91,127,59,.14); }
.usrc-add-form button { border:0; border-radius:11px; padding:13px 24px; font-family:inherit; font-weight:800; font-size:14px; color:#fff; cursor:pointer; background:linear-gradient(135deg,var(--accent),var(--accent-2)); white-space:nowrap; transition:transform .15s; }
.usrc-add-form button:hover { transform:translateY(-1px); }
.usrc-add-form button:disabled { opacity:.6; cursor:default; transform:none; }
.usrc-hints { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
.usrc-hints span { font-size:11.5px; font-weight:700; color:var(--muted); background:var(--bg-3,#EFEAE0); border-radius:999px; padding:5px 11px; }
.usrc-msg { font-size:13px; font-weight:700; margin-top:12px; min-height:18px; }
.usrc-msg.err { color:#B11226; }
.usrc-msg.ok  { color:#1B7A3D; }
.usrc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:16px; }
.usrc-card { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:16px; display:flex; flex-direction:column; gap:12px; box-shadow:0 1px 2px rgba(60,40,20,.04),0 10px 24px -16px rgba(60,40,20,.10); transition:box-shadow .2s; }
.usrc-card:hover { box-shadow:0 12px 28px -16px rgba(60,40,20,.18); }
.usrc-card.off { opacity:.62; }
.usrc-top { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.usrc-pb { display:flex; align-items:center; gap:11px; min-width:0; }
.usrc-ico { width:40px; height:40px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
.usrc-ico svg { width:20px; height:20px; }
.usrc-pl { min-width:0; }
.usrc-name { font-size:15px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.usrc-handle { font-size:11.5px; color:var(--muted-2,#968B78); direction:ltr; text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.usrc-toggle { width:40px; height:22px; border-radius:999px; background:#D9CFBD; border:0; position:relative; cursor:pointer; flex-shrink:0; padding:0; transition:background .2s; }
.usrc-toggle.on { background:var(--accent); }
.usrc-knob { position:absolute; top:3px; right:3px; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.3); transition:right .2s; }
.usrc-toggle.on .usrc-knob { right:21px; }
.usrc-ln { height:1px; background:var(--border); }
.usrc-ft { display:flex; align-items:center; justify-content:space-between; }
.usrc-status { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:var(--muted); }
.usrc-status i { width:7px; height:7px; border-radius:50%; background:var(--muted-2,#968B78); display:inline-block; }
.usrc-card.on .usrc-status { color:#1B7A3D; }
.usrc-card.on .usrc-status i { background:#1B7A3D; }
.usrc-actions { display:flex; align-items:center; gap:10px; }
.usrc-platlabel { font-size:11.5px; font-weight:700; color:var(--accent-2); }
.usrc-del { border:0; background:none; cursor:pointer; font-size:15px; opacity:.5; padding:2px; transition:opacity .15s; }
.usrc-del:hover { opacity:1; }
.usrc-empty { text-align:center; padding:46px 20px; color:var(--muted); }
.usrc-empty .ico { font-size:40px; }
.usrc-empty p { margin:10px 0 0; font-size:14px; }
</style>

<div class="dash-topbar">
  <div>
    <h1>📡 مصادري</h1>
    <p class="usrc-sub"><?= $count ? ('صحيفتك تُبنى من ' . $count . ' مصدراً (' . $active . ' نشطاً)') : 'ابدأ ببناء صحيفتك — أضف مصادرك الخاصة' ?></p>
  </div>
</div>

<div class="usrc-add">
  <h2>أضف مصدراً</h2>
  <p>ألصق رابط موقع، خلاصة RSS، قناة تلغرام، حساب إكس، أو قناة يوتيوب — وسنكتشف النوع تلقائياً ونعرض أخباره ضمن «صحيفتي».</p>
  <form class="usrc-add-form" onsubmit="return usrcAdd(event)">
    <input id="usrcInput" type="text" placeholder="https://site.com/feed  ·  @handle  ·  t.me/channel" dir="ltr" autocomplete="off" required>
    <button type="submit" id="usrcAddBtn">إضافة</button>
  </form>
  <div class="usrc-hints"><span>🌐 موقع</span><span>📡 RSS</span><span>✈️ تلغرام</span><span>𝕏 إكس</span><span>▶️ يوتيوب</span></div>
  <div class="usrc-msg" id="usrcMsg" role="status" aria-live="polite"></div>
</div>

<div class="usrc-grid" id="usrcGrid">
  <?php if (!$sources): ?>
    <div class="usrc-empty" style="grid-column:1/-1;">
      <div class="ico">📰</div>
      <p>لا مصادر بعد. أضف أول مصدر بالأعلى لتبدأ صحيفتك الخاصة.</p>
    </div>
  <?php else: foreach ($sources as $s):
    $m = user_source_meta($s['type']);
    $on = (int)$s['is_active'] === 1;
  ?>
    <div class="usrc-card <?= $on ? 'on' : 'off' ?>" data-id="<?= (int)$s['id'] ?>">
      <div class="usrc-top">
        <div class="usrc-pb">
          <span class="usrc-ico" style="background:<?= e($m['color']) ?>"><?= usrc_icon_svg($s['type']) ?></span>
          <div class="usrc-pl">
            <div class="usrc-name"><?= e($s['name']) ?></div>
            <div class="usrc-handle"><?= e($s['handle'] ?: $s['url']) ?></div>
          </div>
        </div>
        <button type="button" class="usrc-toggle <?= $on ? 'on' : '' ?>" aria-label="تشغيل/إيقاف" onclick="usrcToggle(this)"><span class="usrc-knob"></span></button>
      </div>
      <div class="usrc-ln"></div>
      <div class="usrc-ft">
        <span class="usrc-status"><i></i><span class="usrc-status-txt"><?= $on ? 'نشط' : 'موقوف' ?></span><?php if ((int)($s['article_count'] ?? 0) > 0): ?> · <?= (int)$s['article_count'] ?> مقال<?php endif; ?></span>
        <div class="usrc-actions">
          <span class="usrc-platlabel"><?= e($m['label']) ?></span>
          <button type="button" class="usrc-del" title="حذف" onclick="usrcDelete(this)">🗑️</button>
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<script>
(function(){
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  function req(data){ data._csrf = csrf; return fetch('../api/user_source.php', { method:'POST', headers:{'X-CSRF-Token':csrf}, body:new URLSearchParams(data) }).then(function(r){return r.json();}); }
  var ERR = { unrecognized:'تعذّر التعرّف على المصدر — جرّب رابطاً كاملاً.', duplicate:'هذا المصدر مضاف مسبقاً.', limit:'بلغت الحد الأقصى للمصادر.', csrf:'انتهت الجلسة، حدّث الصفحة.', rate_limited:'محاولات كثيرة، انتظر قليلاً.' };
  window.usrcAdd = function(e){
    e.preventDefault();
    var inp = document.getElementById('usrcInput'), btn = document.getElementById('usrcAddBtn'), msg = document.getElementById('usrcMsg');
    var val = inp.value.trim(); if(!val) return false;
    btn.disabled = true; msg.className='usrc-msg'; msg.textContent='…جاري الإضافة';
    req({action:'add', input:val}).then(function(d){
      btn.disabled = false;
      if(d && d.ok){ msg.className='usrc-msg ok'; msg.textContent='تمت إضافة المصدر ✓'; location.reload(); }
      else { msg.className='usrc-msg err'; msg.textContent = ERR[d&&d.error] || 'تعذّرت الإضافة، حاول لاحقاً.'; }
    }).catch(function(){ btn.disabled=false; msg.className='usrc-msg err'; msg.textContent='خطأ في الاتصال.'; });
    return false;
  };
  window.usrcToggle = function(btn){
    var card = btn.closest('.usrc-card'), id = card.getAttribute('data-id');
    var on = !btn.classList.contains('on');
    btn.classList.toggle('on', on); card.classList.toggle('on', on); card.classList.toggle('off', !on);
    card.querySelector('.usrc-status-txt').textContent = on ? 'نشط' : 'موقوف';
    req({action:'toggle', id:id, on: on?'1':'0'}).catch(function(){});
  };
  window.usrcDelete = function(btn){
    if(!confirm('حذف هذا المصدر من صحيفتك؟')) return;
    var card = btn.closest('.usrc-card'), id = card.getAttribute('data-id');
    req({action:'delete', id:id}).then(function(d){ if(d&&d.ok){ card.style.transition='opacity .2s'; card.style.opacity='0'; setTimeout(function(){ card.remove(); }, 200); } });
  };
})();
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
