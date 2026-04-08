/* ============================================================
   نيوزفلو — User Dashboard client logic
   Exposes window.NF with bookmark / follow / theme / comment helpers.
   ============================================================ */

(function () {
  const NF = {};

  NF.csrf = () => {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  };

  // Use absolute /api/ so this works on URL-rewritten pages like
  // /article/123/slug and /category/political as well as /me/*.
  NF.apiBase = '/api/';

  NF.toast = (msg) => {
    const t = document.getElementById('nfToast');
    if (!t) { console.log(msg); return; }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(NF._toastT);
    NF._toastT = setTimeout(() => t.classList.remove('show'), 2000);
  };

  NF.post = async (path, data) => {
    const body = new URLSearchParams(data);
    body.set('_csrf', NF.csrf());
    try {
      const r = await fetch(NF.apiBase + path, {
        method: 'POST',
        headers: { 'X-CSRF-Token': NF.csrf() },
        body,
        credentials: 'same-origin',
      });
      return await r.json();
    } catch (e) {
      return { ok: false, error: 'network' };
    }
  };

  NF.get = async (path) => {
    try {
      const r = await fetch(NF.apiBase + path, { credentials: 'same-origin' });
      return await r.json();
    } catch (e) {
      return { ok: false, error: 'network' };
    }
  };

  // --------------- Bookmarks ---------------
  NF.toggleSave = async (btn) => {
    const id = parseInt(btn.dataset.saveId, 10);
    if (!id) return;
    // Optimistic UI
    const wasSaved = btn.classList.contains('saved');
    btn.classList.toggle('saved');
    const res = await NF.post('bookmark.php', { article_id: id });
    if (!res.ok) {
      // Rollback
      btn.classList.toggle('saved', wasSaved);
      if (res.error === 'auth_required') {
        NF.toast('سجّل دخول لحفظ الأخبار');
        setTimeout(() => {
          location.href = '/account/login.php?return=' + encodeURIComponent(location.pathname);
        }, 800);
        return;
      }
      NF.toast('حدث خطأ');
      return;
    }
    NF.toast(res.saved ? '✓ تم الحفظ' : 'أزيل من المحفوظات');
    // If we're on the saved page, remove the card on unsave
    if (!res.saved && /\/me\/saved\.php/.test(location.pathname)) {
      const card = btn.closest('.u-card');
      if (card) card.remove();
    }
  };

  // --------------- Follow ---------------
  NF.toggleFollow = async (btn, kind) => {
    const attr = kind === 'cat' ? 'followCat' : 'followSrc';
    const id = parseInt(btn.dataset[attr], 10);
    if (!id) return;
    const was = btn.classList.contains('on');
    btn.classList.toggle('on');
    const res = await NF.post('follow.php', { kind, id });
    if (!res.ok) {
      btn.classList.toggle('on', was);
      if (res.error === 'auth_required') {
        NF.toast('سجّل دخول للمتابعة');
        setTimeout(() => {
          location.href = '/account/login.php?return=' + encodeURIComponent(location.pathname);
        }, 800);
        return;
      }
      NF.toast('حدث خطأ');
      return;
    }
    NF.toast(res.following ? '✓ تمت المتابعة' : 'تم الإلغاء');

    // Live-update the reorder list on /me/following.php
    if (kind === 'cat') {
      NF.syncReorderRow(id, btn, !!res.following);
    }
  };

  // Insert or remove a reorder row matching the follow/unfollow.
  // Only runs on /me/following.php where the reorder card exists.
  NF.syncReorderRow = (id, chipBtn, following) => {
    let list = document.getElementById('reorderList');

    if (following) {
      // Extract category label + icon from the chip button
      const chipText = (chipBtn.textContent || '').trim();
      // chip text is like "📚 سياسة" — first whitespace-separated token may be icon
      let icon = '📂', name = chipText;
      const parts = chipText.split(/\s+/);
      if (parts.length > 1 && parts[0].length <= 4) {
        icon = parts[0];
        name = parts.slice(1).join(' ');
      }

      if (!list) {
        // Replace empty-state paragraph with a fresh <ul>
        const card = [...document.querySelectorAll('.panel-card')].find(p => {
          const h = p.querySelector('.panel-head h2');
          return h && h.textContent.includes('ترتيب');
        });
        if (card) {
          const emptyP = card.querySelector('p');
          if (emptyP) emptyP.remove();
          list = document.createElement('ul');
          list.className = 'reorder-list';
          list.id = 'reorderList';
          card.appendChild(list);
          NF.initReorder();
        }
      }
      if (!list) return;
      // Avoid duplicate
      if (list.querySelector('li[data-cat-id="' + id + '"]')) return;

      const li = document.createElement('li');
      li.setAttribute('draggable', 'true');
      li.dataset.catId = String(id);
      li.innerHTML =
        '<span class="drag-handle">⋮⋮</span>' +
        '<span class="cat-icon">' + NF.escape(icon) + '</span>' +
        '<span style="flex:1; font-weight:600;">' + NF.escape(name) + '</span>' +
        '<button type="button" class="btn sm danger" onclick="NF.unfollowCatRow(this, ' + id + ')">إلغاء</button>';
      list.appendChild(li);
    } else {
      if (!list) return;
      const row = list.querySelector('li[data-cat-id="' + id + '"]');
      if (row) row.remove();
    }
  };

  NF.unfollowCatRow = async (btn, id) => {
    const res = await NF.post('follow.php', { kind: 'cat', id });
    if (res.ok) {
      btn.closest('li').remove();
      // Also toggle the chip if visible
      const chip = document.querySelector('[data-follow-cat="' + id + '"]');
      if (chip) chip.classList.remove('on');
      NF.toast('تم الإلغاء');
    }
  };

  // --------------- Reorder (drag/drop) ---------------
  NF.initReorder = () => {
    const list = document.getElementById('reorderList');
    if (!list) return;
    let dragged = null;
    list.addEventListener('dragstart', e => {
      const li = e.target.closest('li');
      if (!li) return;
      dragged = li; li.classList.add('dragging');
    });
    list.addEventListener('dragend', () => {
      if (dragged) dragged.classList.remove('dragging');
      dragged = null;
      NF.saveOrder();
    });
    list.addEventListener('dragover', e => {
      e.preventDefault();
      const li = e.target.closest('li');
      if (!li || !dragged || li === dragged) return;
      const rect = li.getBoundingClientRect();
      const after = (e.clientY - rect.top) / rect.height > 0.5;
      li.parentNode.insertBefore(dragged, after ? li.nextSibling : li);
    });
  };

  NF.saveOrder = async () => {
    const list = document.getElementById('reorderList');
    if (!list) return;
    const ids = [...list.querySelectorAll('li')].map(li => li.dataset.catId).join(',');
    const res = await NF.post('reorder_categories.php', { order: ids });
    if (res.ok) NF.toast('✓ تم حفظ الترتيب');
  };

  // --------------- Theme toggle ---------------
  NF.setTheme = async (theme) => {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('nf_theme', theme); } catch (e) {}
    await NF.post('theme.php', { theme });
  };

  NF.initThemeSelect = () => {
    const sel = document.getElementById('themeSelect');
    if (sel) sel.addEventListener('change', () => NF.setTheme(sel.value));
  };

  NF.cycleTheme = () => {
    const cur = document.documentElement.getAttribute('data-theme') || 'auto';
    const order = { auto: 'light', light: 'dark', dark: 'auto' };
    NF.setTheme(order[cur] || 'auto');
  };

  // --------------- Comments ---------------
  NF.submitComment = async (form) => {
    const ta = form.querySelector('textarea');
    const body = ta.value.trim();
    if (body.length < 2) return;
    const articleId = form.dataset.articleId;
    const res = await NF.post('comment.php', { action: 'add', article_id: articleId, body });
    if (!res.ok) {
      NF.toast(res.error === 'auth_required' ? 'سجّل دخول للتعليق' : 'حدث خطأ');
      return;
    }
    ta.value = '';
    const list = document.getElementById('commentsList');
    if (list) {
      const c = res.comment;
      const el = document.createElement('div');
      el.className = 'comment';
      el.innerHTML = `
        <div class="avatar">${(c.avatar_letter || (c.user_name || '?').charAt(0))}</div>
        <div class="c-body">
          <div><span class="c-name">${NF.escape(c.user_name || '')}</span><span class="c-time">الآن</span></div>
          <div class="c-text">${NF.escape(c.body || '')}</div>
          <div class="c-actions"><button onclick="NF.likeComment(this, ${c.id})">♡ 0</button></div>
        </div>`;
      list.prepend(el);
    }
    NF.toast('✓ تم إرسال التعليق');
  };

  NF.likeComment = async (btn, id) => {
    const res = await NF.post('comment.php', { action: 'like', comment_id: id });
    if (!res.ok) { NF.toast('حدث خطأ'); return; }
    btn.classList.toggle('liked', res.liked);
    btn.textContent = (res.liked ? '♥ ' : '♡ ') + res.likes;
  };

  NF.escape = (s) => String(s || '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[c]);

  // --------------- Notifications dropdown ---------------
  NF.toggleNotifDropdown = async (btn) => {
    let dd = document.getElementById('nfNotifDropdown');
    if (dd) { dd.remove(); return; }
    dd = document.createElement('div');
    dd.id = 'nfNotifDropdown';
    dd.innerHTML = '<div style="padding:14px; text-align:center; color:#888;">جاري التحميل...</div>';
    Object.assign(dd.style, {
      position: 'fixed', top: '62px', insetInlineEnd: '24px',
      width: '320px', maxHeight: '70vh', overflowY: 'auto',
      background: 'var(--surface, #fff)', color: 'var(--text, #222)',
      border: '1px solid var(--border, #ddd)', borderRadius: '12px',
      boxShadow: '0 10px 40px rgba(0,0,0,.2)', zIndex: 99999, fontSize: '13px',
    });
    document.body.appendChild(dd);
    document.addEventListener('click', NF._closeNotifOutside, true);
    const res = await NF.get('notifications.php?action=list&limit=10');
    if (!res.ok) { dd.innerHTML = '<div style="padding:14px;text-align:center;color:#888;">تعذّر التحميل</div>'; return; }
    const items = res.items || [];
    if (!items.length) {
      dd.innerHTML = '<div style="padding:20px;text-align:center;color:#888;">لا توجد إشعارات</div>';
      return;
    }
    let html = '<div style="padding:10px 14px; border-bottom:1px solid var(--border, #eee); font-weight:700;">الإشعارات</div>';
    items.forEach(n => {
      html += `<a href="${NF.escape(n.link || '#')}" style="display:flex;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border, #eee);color:inherit;${n.is_read == 0 ? 'background:rgba(26,115,232,.04);' : ''}">
        <div style="width:34px;height:34px;border-radius:8px;background:var(--surface-2,#f3f4f6);display:flex;align-items:center;justify-content:center;">${NF.escape(n.icon || '🔔')}</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:600;">${NF.escape(n.title || '')}</div>
          <div style="color:#888;font-size:11px;margin-top:2px;">${NF.escape(n.body || '')}</div>
        </div>
      </a>`;
    });
    html += '<div style="padding:10px;text-align:center;"><button onclick="NF.markAllRead(this)" style="background:none;border:0;color:var(--accent,#1a73e8);cursor:pointer;font-family:inherit;font-size:12px;">تعليم الكل كمقروء</button></div>';
    dd.innerHTML = html;
  };

  NF._closeNotifOutside = (e) => {
    const dd = document.getElementById('nfNotifDropdown');
    if (!dd) return;
    if (dd.contains(e.target)) return;
    if (e.target.closest('[data-nf-notif-btn]')) return;
    dd.remove();
    document.removeEventListener('click', NF._closeNotifOutside, true);
  };

  NF.markAllRead = async () => {
    const res = await NF.post('notifications.php', { action: 'read_all' });
    if (res.ok) {
      const dd = document.getElementById('nfNotifDropdown');
      if (dd) dd.remove();
      const badge = document.querySelector('[data-notif-badge]');
      if (badge) badge.textContent = '0';
      NF.toast('✓ تم');
    }
  };

  // --------------- Log reading history (on article pages) ---------------
  NF.logRead = (articleId) => {
    if (!articleId) return;
    NF.post('log_read.php', { article_id: articleId }).catch(() => {});
  };

  // --------------- Reactions (like / dislike) ---------------
  NF.toggleReaction = async (btn) => {
    const id = parseInt(btn.dataset.reactId, 10);
    const type = btn.dataset.reactType; // 'like' | 'dislike'
    if (!id || !type) return;
    const group = btn.closest('.nf-action-bar');
    if (!group) return;
    const likeBtn = group.querySelector('.nf-act.like');
    const dislikeBtn = group.querySelector('.nf-act.dislike');
    const res = await NF.post('reaction.php', { article_id: id, reaction: type });
    if (!res.ok) {
      if (res.error === 'auth_required') {
        NF.toast('سجّل دخول للتفاعل');
        setTimeout(() => {
          location.href = '/account/login.php?return=' + encodeURIComponent(location.pathname);
        }, 800);
        return;
      }
      NF.toast('حدث خطأ');
      return;
    }
    likeBtn.classList.toggle('active', res.reaction === 'like');
    dislikeBtn.classList.toggle('active', res.reaction === 'dislike');
    likeBtn.querySelector('.nf-act-count').textContent = res.like;
    dislikeBtn.querySelector('.nf-act-count').textContent = res.dislike;
  };

  // --------------- Share ---------------
  NF.shareArticle = async (btn) => {
    const id = parseInt(btn.dataset.shareId, 10);
    const url = btn.dataset.shareUrl || location.href;
    const title = btn.dataset.shareTitle || document.title;
    try {
      if (navigator.share) {
        await navigator.share({ title, url });
      } else if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(url);
        NF.toast('✓ تم نسخ الرابط');
      } else {
        prompt('انسخ الرابط:', url);
      }
    } catch (e) {
      // user canceled share — don't count
      return;
    }
    if (id) {
      const res = await NF.post('share.php', { article_id: id });
      if (res && res.ok) {
        let countEl = btn.querySelector('.nf-act-count');
        if (!countEl) {
          countEl = document.createElement('span');
          countEl.className = 'nf-act-count';
          btn.appendChild(countEl);
        }
        countEl.textContent = res.count;
      }
    }
  };

  // --------------- Boot ---------------
  window.NF = NF;
  document.addEventListener('DOMContentLoaded', () => {
    NF.initReorder();
    NF.initThemeSelect();
  });
})();
