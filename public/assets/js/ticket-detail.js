window.__toggleCommentEdit = function (trigger) {
    var item = trigger.closest('[data-comment-item]');
    var thread = trigger.closest('[data-comment-thread]');
    if (!item) { return false; }
    if (thread) {
        thread.querySelectorAll('[data-comment-item]').forEach(function (cur) {
            var v = cur.querySelector('[data-comment-view]');
            var p = cur.querySelector('[data-comment-edit-panel]');
            var e = cur.querySelector('[data-comment-edit-error]');
            if (v) { v.hidden = false; }
            if (p) { p.hidden = true; }
            if (e) { e.textContent = ''; e.hidden = true; }
        });
    }
    var view = item.querySelector('[data-comment-view]');
    var panel = item.querySelector('[data-comment-edit-panel]');
    var textarea = item.querySelector('[data-comment-edit-textarea]');
    if (view) { view.hidden = true; }
    if (panel) { panel.hidden = false; }
    if (textarea) { textarea.focus(); var len = textarea.value.length; textarea.setSelectionRange(len, len); }
    return false;
};

window.__cancelCommentEdit = function (trigger) {
    var item = trigger.closest('[data-comment-item]');
    if (!item) { return false; }
    var view = item.querySelector('[data-comment-view]');
    var panel = item.querySelector('[data-comment-edit-panel]');
    var error = item.querySelector('[data-comment-edit-error]');
    if (error) { error.textContent = ''; error.hidden = true; }
    if (panel) { panel.hidden = true; }
    if (view) { view.hidden = false; }
    return false;
};

if (typeof window.__handleInlineCommentSave !== 'function') {
    window.__handleInlineCommentSave = function (form, event) {
        if (event) { event.preventDefault(); }
        if (!(form instanceof HTMLFormElement)) { return true; }
        const item = form.closest('[data-comment-item]');
        if (!item) { return true; }
        const error = item.querySelector('[data-comment-edit-error]');
        const submitButton = form.querySelector('button[type="submit"]');
        const view = item.querySelector('[data-comment-view]');
        const panel = item.querySelector('[data-comment-edit-panel]');
        const body = item.querySelector('[data-comment-body]');
        const badgeRoot = item.querySelector('[data-comment-badge]');
        const textarea = form.querySelector('[data-comment-edit-textarea]');
        if (error) { error.textContent = ''; error.hidden = true; }
        const restoreLabel = submitButton instanceof HTMLButtonElement ? submitButton.innerHTML : '';
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span>กำลังบันทึก...</span>';
        }
        fetch(form.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: new FormData(form),
        })
            .then(async function (response) {
                let data = {};
                try { data = await response.json(); } catch (e) { data = {}; }
                if (!response.ok || !data.success) {
                    throw new Error(String(data.message || 'ไม่สามารถบันทึกการแก้ไขได้'));
                }
                if (body) { body.textContent = String((data.comment || {}).body || ''); }
                if (textarea instanceof HTMLTextAreaElement) { textarea.value = String((data.comment || {}).body || ''); }
                if (badgeRoot) {
                    let badge = badgeRoot.querySelector('.badge');
                    if (!badge) { badge = document.createElement('span'); badgeRoot.appendChild(badge); }
                    badge.className = 'badge badge-' + String((data.comment || {}).visibility_tone || 'default');
                    badge.textContent = String((data.comment || {}).visibility_label || 'Public');
                }
                if (panel) { panel.hidden = true; }
                if (view) { view.hidden = false; }
            })
            .catch(function (err) {
                if (error) {
                    error.textContent = err instanceof Error ? err.message : 'ไม่สามารถบันทึกการแก้ไขได้';
                    error.hidden = false;
                }
            })
            .finally(function () {
                if (submitButton instanceof HTMLButtonElement) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = restoreLabel;
                }
            });
        return false;
    };
}

// ── แก้ไข comment แบบ inline (ใช้ event delegation) ──
// แทน inline onclick/onsubmit ใน comment-item.php ด้วย delegation บน data-attribute เดิม
// (รอด comment ที่ live-append เข้ามาด้วย).
(function () {
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) { return; }
        var toggle = e.target.closest('[data-comment-edit-toggle]');
        if (toggle) { e.preventDefault(); if (window.__toggleCommentEdit) { window.__toggleCommentEdit(toggle); } return; }
        var cancel = e.target.closest('[data-comment-edit-cancel]');
        if (cancel) { e.preventDefault(); if (window.__cancelCommentEdit) { window.__cancelCommentEdit(cancel); } return; }
    });
    document.addEventListener('submit', function (e) {
        if (e.target && e.target.matches && e.target.matches('[data-comment-edit-form]') && window.__handleInlineCommentSave) {
            window.__handleInlineCommentSave(e.target, e);   // ฟังก์ชันนี้ preventDefault เอง
        }
    });
})();

// ── polling สถานะ ticket แบบ live ──
// ตรวจว่า status/comment ของ ticket ถูกเปลี่ยนโดยคนอื่นระหว่างเปิดหน้าอยู่ → โชว์ banner ให้โหลดใหม่
// (ไม่ auto-reload กันขัดจังหวะพิมพ์ comment). ใช้ polling ให้สอดคล้อง notification bell — ไม่ใช้ WebSocket.
(function () {
  function init() {
    var root = document.querySelector('[data-ticket-live]');
    if (!root) { return; }
    var url = root.getAttribute('data-ticket-state-url');
    if (!url) { return; }
    var banner = root.querySelector('[data-ticket-live-banner]');
    var reloadBtn = root.querySelector('[data-ticket-live-reload]');
    var baseStatus = root.getAttribute('data-ticket-status') || '';
    var baseComments = parseInt(root.getAttribute('data-ticket-comment-count'), 10) || 0;
    var notified = false;

    if (reloadBtn) {
      reloadBtn.addEventListener('click', function () { window.location.reload(); });
    }

    var check = function () {
      if (notified || document.hidden) { return; }
      fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) { return; }
          // เฉพาะ status change — comment ใหม่ live-append เอง (ไม่ต้องโชว์ banner)
          var changed = (String(data.status) !== baseStatus);
          if (changed && banner) {
            banner.hidden = false;
            notified = true;
          }
        })
        .catch(function () {});
    };

    window.setInterval(check, 25000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { check(); }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

// ── append comment แบบ live (คล้ายแชท) ──
// poll comment ใหม่ (id > latest) → server render มาแล้ว (partial ร่วมกับ show.php) → append เข้า thread
// โดยไม่ต้อง reload. pause เมื่อ tab ซ่อน; ลบ empty-state ตอน append แรก; กันซ้ำด้วย element id.
(function () {
  function init() {
    var thread = document.querySelector('[data-comment-thread]');
    if (!thread) { return; }
    var feedUrl = thread.getAttribute('data-comment-feed-url');
    if (!feedUrl) { return; }
    var latestId = parseInt(thread.getAttribute('data-latest-comment-id'), 10) || 0;
    var badge = document.querySelector('[data-comment-count-badge]');

    var check = function () {
      if (document.hidden) { return; }
      fetch(feedUrl + '?after=' + latestId, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !data.count || !data.html) { return; }
          var empty = thread.querySelector('[data-comment-empty]');
          if (empty) { empty.remove(); }
          var tmp = document.createElement('div');
          tmp.innerHTML = data.html;
          var appended = 0;
          Array.prototype.slice.call(tmp.children).forEach(function (el) {
            if (el.id && document.getElementById(el.id)) { return; } // มีแล้ว กันซ้ำ
            el.classList.add('comment-item-new');
            thread.appendChild(el);
            appended++;
          });
          latestId = parseInt(data.latest_id, 10) || latestId;
          thread.setAttribute('data-latest-comment-id', String(latestId));
          if (badge && appended > 0) {
            var n = (parseInt(badge.getAttribute('data-count'), 10) || 0) + appended;
            badge.setAttribute('data-count', String(n));
            badge.textContent = n + ' รายการ';
          }
        })
        .catch(function () {});
    };

    window.setInterval(check, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { check(); } });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

// ── confirm modal สำหรับลบ comment (ใช้ event delegation) ──
// แทน native confirm() ด้วย confirm-modal ของระบบ. ใช้ event delegation → รองรับ comment
// ที่ live-append เข้ามาด้วย. track form ที่จะลบตอนคลิก (แก้ปัญหา shared handler ที่ผูก form แรก).
(function () {
  function init() {
    var modal = document.getElementById('comment-delete-modal');
    if (!modal) { return; }
    // Portal ออกไปไว้ที่ body: .confirm-modal ใช้ position:fixed แต่ถูก render อยู่ใน
    // .panel-card ที่มี backdrop-filter → สร้าง containing block ทำให้ modal ถูกกักในการ์ด
    // (ไม่เต็มจอ, ถูกตัดบน, เลื่อนตามการ์ด). ย้ายไป body ให้ fixed อิงกับ viewport จริง.
    if (modal.parentNode !== document.body) { document.body.appendChild(modal); }
    var submitBtn = modal.querySelector('[data-modal-submit]');
    var pendingForm = null;

    var close = function () { modal.hidden = true; pendingForm = null; };

    document.addEventListener('click', function (e) {
      var trigger = e.target && e.target.closest ? e.target.closest('[data-comment-delete-trigger]') : null;
      if (!trigger) { return; }
      e.preventDefault();
      pendingForm = trigger.closest('form');
      if (!pendingForm) { return; }
      modal.hidden = false;
      if (submitBtn) { submitBtn.focus(); }
    });

    modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
      el.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) { close(); }
    });
    if (submitBtn) {
      submitBtn.addEventListener('click', function () {
        var f = pendingForm;
        close();
        if (!f) { return; }
        if (typeof f.requestSubmit === 'function') { f.requestSubmit(); } else { f.submit(); }
      });
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
