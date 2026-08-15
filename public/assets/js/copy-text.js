(function () {
    // ปุ่มคัดลอกข้อความสั้น ๆ (เลขที่อ้างอิง) — ผูกกับ [data-copy-source] ซึ่งเก็บ selector ของกล่องที่มีข้อความจริง
    // ปุ่มถูกซ่อนไว้ใน HTML แล้วเปิดที่นี่ ถ้าเบราว์เซอร์ปิด JS ผู้ใช้จะไม่เห็นปุ่มที่กดแล้วไม่เกิดอะไรขึ้น
    var buttons = document.querySelectorAll('[data-copy-source]');
    if (!buttons.length) return;

    var copyText = function (text) {
        // clipboard API ใช้ได้เฉพาะหน้าที่เป็น https หรือ localhost — ติดตั้งในองค์กรหลายที่ยังเป็น http ล้วน
        // เลยต้องมีทางสำรองด้วย execCommand เสมอ ไม่งั้นปุ่มจะตายเงียบบนเครื่องลูกค้า
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var proxy = document.createElement('textarea');
            proxy.value = text;
            proxy.setAttribute('readonly', 'readonly');
            proxy.setAttribute('aria-hidden', 'true');
            proxy.style.position = 'fixed';
            proxy.style.top = '-1000px';
            proxy.style.opacity = '0';
            document.body.appendChild(proxy);
            proxy.select();
            proxy.setSelectionRange(0, proxy.value.length); // iOS ไม่สนใจ select() ต้องระบุช่วงเอง
            var copied = false;
            try {
                copied = document.execCommand('copy');
            } catch (error) {
                copied = false;
            }
            document.body.removeChild(proxy);
            copied ? resolve() : reject(new Error('copy-unsupported'));
        });
    };

    var selectSource = function (source) {
        // ทางสำรองสุดท้าย: ไฮไลต์ข้อความไว้ให้ ผู้ใช้กด Ctrl+C / กดค้างแล้วคัดลอกเองได้
        var selection = window.getSelection();
        if (!selection) return;
        var range = document.createRange();
        range.selectNodeContents(source);
        selection.removeAllRanges();
        selection.addRange(range);
    };

    Array.prototype.forEach.call(buttons, function (button) {
        var source = document.querySelector(button.getAttribute('data-copy-source') || '');
        if (!source) return;

        var labelBox = button.querySelector('span');
        var status = document.querySelector(button.getAttribute('data-copy-status') || '');
        var idleLabel = labelBox ? labelBox.textContent : '';
        var doneLabel = button.getAttribute('data-copy-done') || 'คัดลอกแล้ว';
        var failLabel = button.getAttribute('data-copy-failed') || 'คัดลอกไม่ได้ กรุณาคัดลอกเอง';
        var resetTimer = null;

        button.hidden = false;
        button.style.display = '';

        var announce = function (message) {
            if (labelBox) labelBox.textContent = message;
            if (status) status.textContent = message;
            if (resetTimer) window.clearTimeout(resetTimer);
            resetTimer = window.setTimeout(function () {
                if (labelBox) labelBox.textContent = idleLabel;
                if (status) status.textContent = '';
            }, 2500);
        };

        button.addEventListener('click', function () {
            copyText((source.textContent || '').trim()).then(function () {
                announce(doneLabel);
            }).catch(function () {
                selectSource(source);
                announce(failLabel);
            });
        });
    });
})();
