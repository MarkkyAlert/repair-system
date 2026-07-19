    (function () {
        const inputs = document.querySelectorAll('.broadcast-page [data-char-counter]');
        inputs.forEach((input) => {
            const counterId = input.getAttribute('data-char-counter');
            const max = parseInt(input.getAttribute('data-char-max') || '0', 10);
            const counter = document.getElementById(counterId);
            if (!counter || !max) return;
            const nowEl = counter.querySelector('.char-counter-now');
            const update = () => {
                const len = (input.value || '').length;
                if (nowEl) nowEl.textContent = len.toLocaleString();
                const ratio = len / max;
                counter.classList.toggle('is-near-limit', ratio >= 0.9 && ratio < 1);
                counter.classList.toggle('is-at-limit', ratio >= 1);
            };
            input.addEventListener('input', update);
            update();
        });

        // I1: นับจำนวนผู้รับแบบสด (live)
        const roleSelect = document.querySelector('.broadcast-page [data-recipient-target]');
        if (roleSelect) {
            const chipId = roleSelect.getAttribute('data-recipient-target');
            const chip = document.getElementById(chipId);
            const numberEl = chip ? chip.querySelector('[data-recipient-number]') : null;
            const update = () => {
                const opt = roleSelect.options[roleSelect.selectedIndex];
                const count = parseInt(opt?.getAttribute('data-count') || '0', 10);
                if (numberEl) numberEl.textContent = count.toLocaleString();
                if (chip) chip.classList.toggle('is-empty', count === 0);
            };
            roleSelect.addEventListener('change', update);
            update();
        }

        // เติมข้อมูลสรุปใน confirm modal ก่อนเปิด (ตัว global handler เป็นคนเปิด modal เอง)
        const trigger = document.querySelector('[data-confirm-modal-trigger="broadcast-confirm-modal"]');
        const modal = document.getElementById('broadcast-confirm-modal');
        if (trigger && modal) {
            const titleInput = document.getElementById('broadcast_title');
            const messageInput = document.getElementById('broadcast_message');
            const summaryTitle = modal.querySelector('[data-summary-title]');
            const summaryMessage = modal.querySelector('[data-summary-message]');
            const summaryRole = modal.querySelector('[data-summary-role]');
            const summaryCount = modal.querySelector('[data-summary-count]');
            trigger.__beforeConfirmOpen = () => {
                const titleVal = (titleInput?.value || '').trim();
                const messageVal = (messageInput?.value || '').trim();
                const opt = roleSelect?.options[roleSelect.selectedIndex];
                if (summaryTitle) summaryTitle.textContent = titleVal || '(ไม่ระบุ)';
                if (summaryMessage) summaryMessage.textContent = messageVal || '(ไม่ระบุ)';
                if (summaryRole) summaryRole.textContent = opt?.textContent?.trim() || '—';
                if (summaryCount) summaryCount.textContent = parseInt(opt?.getAttribute('data-count') || '0', 10).toLocaleString();
            };
        }
    })();
