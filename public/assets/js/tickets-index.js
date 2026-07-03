    (function () {
        var trigger = document.querySelector('[data-confirm-modal-trigger="bulk-approve-confirm"]');
        var countEl = document.querySelector('[data-bulk-count]');
        var summaryEl = document.querySelector('[data-bulk-approve-count]');
        if (!trigger || !countEl || !summaryEl) return;
        trigger.__beforeConfirmOpen = function () {
            summaryEl.textContent = (countEl.textContent || '0').trim();
        };
    })();
