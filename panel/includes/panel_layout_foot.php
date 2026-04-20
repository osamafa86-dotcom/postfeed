</main>

<script>
/* ===== Command Palette (⌘K) ===== */
(function(){
  var backdrop = document.getElementById('cmdBackdrop');
  var input    = document.getElementById('cmdInput');
  var results  = document.getElementById('cmdResults');
  if (!backdrop || !input || !results) return;

  var PAGES = [
    {t:'لوحة التحكم',    s:'index.php',            i:'📊', k:'dashboard home index الرئيسية'},
    {t:'التحليلات',       s:'analytics.php',        i:'📈', k:'analytics stats statistics احصاءات'},
    {t:'الأخبار',         s:'articles.php',         i:'✍️', k:'articles news مقالات'},
    {t:'إضافة خبر جديد',  s:'articles.php?action=add', i:'➕', k:'new add article create انشاء'},
    {t:'الأقسام',         s:'categories.php',       i:'📂', k:'categories sections تصنيفات'},
    {t:'القصص المتطوّرة',  s:'evolving_stories.php', i:'📅', k:'stories timeline'},
    {t:'المصادر',         s:'sources.php',          i:'🌐', k:'sources rss feeds خلاصات'},
    {t:'الشريط الإخباري', s:'ticker.php',           i:'📢', k:'ticker breaking خبر عاجل'},
    {t:'إضافة خبر عاجل',  s:'ticker.php?action=add', i:'🔥', k:'breaking urgent عاجل'},
    {t:'الريلز',          s:'reels.php',            i:'🎬', k:'reels videos فيديو'},
    {t:'تيليغرام',        s:'telegram.php',         i:'📢', k:'telegram channels'},
    {t:'الذكاء الاصطناعي', s:'ai.php',              i:'🤖', k:'ai artificial gpt gemini claude'},
    {t:'الصوت والقراءة',   s:'tts.php',             i:'🎙', k:'tts voice audio صوت'},
    {t:'النشرة البريدية', s:'newsletter.php',       i:'📬', k:'newsletter email بريد'},
    {t:'الإعدادات',       s:'settings.php',         i:'⚙️', k:'settings config إعدادات'},
    {t:'المصادقة الثنائية',s:'twofa.php',           i:'🔐', k:'2fa two factor مصادقة'},
    {t:'سجل التدقيق',     s:'audit.php',            i:'📋', k:'audit logs سجل'},
    {t:'معاينة فرع',       s:'preview.php',         i:'🧪', k:'preview branch فرع'},
    {t:'تسجيل الخروج',    s:'logout.php',           i:'🚪', k:'logout signout خروج'}
  ];

  var ACTIONS = [
    {t:'فتح الموقع العام',  s:'../',                   i:'🌐', k:'site public homepage الموقع', _ext:true},
    {t:'تحديث الصفحة',      i:'🔄', k:'reload refresh تحديث', _cmd:function(){ location.reload(); }},
  ];

  var idx = 0;
  var lastQuery = '';
  var searchTimer = null;

  window.openCommandPalette = function() {
    backdrop.classList.add('open');
    input.value = '';
    lastQuery = '';
    renderResults([]);
    setTimeout(function(){ input.focus(); }, 30);
  };
  window.closeCommandPalette = function() {
    backdrop.classList.remove('open');
  };

  function fuzzy(needle, hay) {
    needle = (needle || '').trim().toLowerCase();
    hay    = (hay || '').toLowerCase();
    if (!needle) return true;
    return hay.indexOf(needle) !== -1;
  }

  function filterStatic(q) {
    var all = [];
    PAGES.forEach(function(p){
      if (!q || fuzzy(q, p.t + ' ' + p.k)) all.push({type:'page', ...p});
    });
    ACTIONS.forEach(function(a){
      if (!q || fuzzy(q, a.t + ' ' + a.k)) all.push({type:'action', ...a});
    });
    return all;
  }

  function render(items, articleSection) {
    if (!items.length && !articleSection) {
      results.innerHTML = '<div class="cmd-empty">لا توجد نتائج</div>';
      return;
    }
    idx = 0;
    var html = '';
    var pages   = items.filter(function(i){return i.type==='page'});
    var actions = items.filter(function(i){return i.type==='action'});
    if (pages.length) {
      html += '<div class="cmd-section-label">صفحات</div>';
      pages.forEach(function(p){
        html += '<a class="cmd-item" href="' + p.s + '" data-idx="'+(idx++)+'">'
              + '<div class="cmd-ico">' + p.i + '</div>'
              + '<div class="cmd-item-body"><div class="cmd-item-title">' + p.t + '</div><div class="cmd-item-sub">'+p.s+'</div></div>'
              + '<span class="cmd-enter">↵</span></a>';
      });
    }
    if (actions.length) {
      html += '<div class="cmd-section-label">إجراءات</div>';
      actions.forEach(function(a){
        var href = a._ext ? a.s : 'javascript:void(0)';
        html += '<a class="cmd-item" href="' + href + '" data-action="'+ (a._cmd?'reload':'') +'" data-idx="'+(idx++)+'">'
              + '<div class="cmd-ico">' + a.i + '</div>'
              + '<div class="cmd-item-body"><div class="cmd-item-title">' + a.t + '</div></div>'
              + '<span class="cmd-enter">↵</span></a>';
      });
    }
    if (articleSection && articleSection.length) {
      html += '<div class="cmd-section-label">مقالات</div>';
      articleSection.forEach(function(a){
        html += '<a class="cmd-item" href="articles.php?action=edit&id=' + a.id + '" data-idx="'+(idx++)+'">'
              + '<div class="cmd-ico">📝</div>'
              + '<div class="cmd-item-body"><div class="cmd-item-title">' + escapeHtml(a.title) + '</div><div class="cmd-item-sub">#' + a.id + '</div></div>'
              + '<span class="cmd-enter">↵</span></a>';
      });
    }
    results.innerHTML = html;
    updateActive(0);
  }

  function renderResults(items) { render(items, null); }

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

  function updateActive(newIdx) {
    var items = results.querySelectorAll('.cmd-item');
    items.forEach(function(it){ it.classList.remove('active'); });
    var target = results.querySelector('[data-idx="'+newIdx+'"]');
    if (target) { target.classList.add('active'); target.scrollIntoView({block:'nearest'}); }
  }

  input.addEventListener('input', function(){
    var q = input.value.trim();
    lastQuery = q;
    var staticItems = filterStatic(q);
    renderResults(staticItems);
    if (q.length >= 2) {
      if (searchTimer) clearTimeout(searchTimer);
      searchTimer = setTimeout(function(){
        fetch('../api/search.php?q=' + encodeURIComponent(q))
          .then(function(r){ return r.ok ? r.json() : null; })
          .then(function(data){
            if (lastQuery !== q) return;
            var arts = (data && data.results) ? data.results.slice(0,6) : [];
            render(staticItems, arts);
          }).catch(function(){});
      }, 180);
    }
  });

  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (backdrop.classList.contains('open')) closeCommandPalette();
      else openCommandPalette();
      return;
    }
    if (!backdrop.classList.contains('open')) return;
    if (e.key === 'Escape') { closeCommandPalette(); return; }
    var items = results.querySelectorAll('.cmd-item');
    if (!items.length) return;
    var current = Array.from(items).findIndex(function(i){ return i.classList.contains('active'); });
    if (current < 0) current = 0;
    if (e.key === 'ArrowDown') { e.preventDefault(); updateActive((current+1) % items.length); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); updateActive((current-1+items.length) % items.length); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      var active = items[current];
      if (!active) return;
      if (active.getAttribute('data-action') === 'reload') { location.reload(); return; }
      window.location.href = active.getAttribute('href');
    }
  });

  results.addEventListener('mouseover', function(e){
    var it = e.target.closest('.cmd-item');
    if (!it) return;
    var newIdx = parseInt(it.getAttribute('data-idx'), 10);
    if (!isNaN(newIdx)) updateActive(newIdx);
  });

  // Initial render (empty query)
  renderResults(filterStatic(''));
})();

/* ===== Toast Notifications ===== */
window.nfToast = function(message, type) {
  var stack = document.getElementById('toastStack');
  if (!stack) return;
  var t = document.createElement('div');
  t.className = 'toast ' + (type || 'info');
  var icon = type === 'success' ? '✅' : (type === 'error' ? '❌' : (type === 'warn' ? '⚠️' : 'ℹ️'));
  t.innerHTML = '<span>'+icon+'</span><span>'+message+'</span>';
  stack.appendChild(t);
  setTimeout(function(){
    t.classList.add('exit');
    setTimeout(function(){ t.remove(); }, 200);
  }, 3500);
};
</script>

</body>
</html>
