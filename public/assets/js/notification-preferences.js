    (function () {
        const form = document.getElementById('prefs-form');
        if (!form) return;

        const submitBtn = form.querySelector('.prefs-actions button[type="submit"]');
        const statusEl = form.querySelector('[data-prefs-status]');
        const switches = form.querySelectorAll('input.switch[type="checkbox"]');

        const setDirty = (dirty) => {
            if (dirty) form.setAttribute('data-dirty', '1');
            else form.removeAttribute('data-dirty');
            if (submitBtn) submitBtn.disabled = !dirty;
            if (statusEl) statusEl.hidden = !dirty;
        };
        setDirty(false);

        form.addEventListener('change', () => setDirty(true));

        form.querySelectorAll('[data-prefs-preset]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const preset = btn.getAttribute('data-prefs-preset');
                switches.forEach((cb) => {
                    const channel = cb.getAttribute('data-channel');
                    if (preset === 'all') cb.checked = true;
                    else if (preset === 'none') cb.checked = false;
                    else if (preset === 'in-app-only') cb.checked = (channel === 'in_app');
                });
                setDirty(true);
            });
        });

        form.querySelectorAll('.prefs-info-icon[aria-controls]').forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                const targetId = btn.getAttribute('aria-controls');
                const target = document.getElementById(targetId);
                if (!target) return;
                const expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                target.hidden = expanded;
            });
        });
    })();
