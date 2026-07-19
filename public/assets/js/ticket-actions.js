// ปุ่ม Action CTA (ปุ่มเรียกให้ทำงาน เช่น อนุมัติ/ปฏิเสธ, มอบหมาย ฯลฯ) กระโดดไปยัง section #action-*. หักลบระยะ scroll ด้วย
// ความสูงของ sticky stack จริง (topbar + .action-bar ที่ตรึงไว้) เพื่อให้กล่องเป้าหมายมาอยู่ใต้พวกมัน ไม่ถูกบังอยู่ข้างใต้
(function () {
    var pad = 16;
    function stickyOffset() {
        var topbar = document.querySelector('.topbar') || document.querySelector('header');
        var topH = topbar ? topbar.getBoundingClientRect().height : 0;
        var bar = document.querySelector('.action-bar');
        var barBottom = 0;
        if (bar && getComputedStyle(bar).position === 'sticky') {
            barBottom = (parseInt(getComputedStyle(bar).top, 10) || 0) + bar.offsetHeight;
        }
        return Math.max(topH, barBottom) + pad;
    }
    function goTo(hash, smooth) {
        var target = document.querySelector(hash);
        if (!target) return;
        var y = target.getBoundingClientRect().top + window.scrollY - stickyOffset();
        window.scrollTo({ top: Math.max(0, y), behavior: smooth ? 'smooth' : 'auto' });
    }
    document.querySelectorAll('a[href^="#action-"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            var hash = a.getAttribute('href');
            if (!document.querySelector(hash)) return;
            e.preventDefault();
            goTo(hash, true);
            history.replaceState(null, '', hash);
        });
    });
    // เปิดหน้าตรง ๆ พร้อม hash #action-* (ลิงก์ที่ bookmark ไว้/ที่แชร์กันมา)
    if (/^#action-/.test(location.hash) && document.querySelector(location.hash)) {
        setTimeout(function () { goTo(location.hash, false); }, 60);
    }
})();
