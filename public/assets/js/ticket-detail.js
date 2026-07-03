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
