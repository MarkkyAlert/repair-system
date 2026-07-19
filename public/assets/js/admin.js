(() => {
    const tabs = document.querySelectorAll('.admin-tab');
    const panels = document.querySelectorAll('.admin-tab-panel');
    if (!tabs.length) return;

    const nav = document.querySelector('.admin-tabs');
    const scroller = document.querySelector('.admin-tabs-scroller');

    // F2: แสดงขอบจาง (fade) เฉพาะตอนที่มีเนื้อหาถูกซ่อนอยู่ให้เลื่อนไปหาได้
    const updateFades = () => {
        if (!nav || !scroller) return;
        const max = nav.scrollWidth - nav.clientWidth;
        scroller.classList.toggle('can-scroll-left', nav.scrollLeft > 4);
        scroller.classList.toggle('can-scroll-right', nav.scrollLeft < max - 4);
    };

    // F1: เลื่อน tab ที่กำลังเลือกอยู่ให้เข้ามาในมุมมองตามแนวนอน โดยไม่ขยับ scroll ของทั้งหน้า
    const revealActive = (tab) => {
        if (!nav || !tab) return;
        const navRect = nav.getBoundingClientRect();
        const tabRect = tab.getBoundingClientRect();
        if (tabRect.left < navRect.left) {
            nav.scrollBy({ left: tabRect.left - navRect.left - 12, behavior: 'smooth' });
        } else if (tabRect.right > navRect.right) {
            nav.scrollBy({ left: tabRect.right - navRect.right + 12, behavior: 'smooth' });
        }
    };

    const activate = (hash) => {
        let activeTab = null;
        tabs.forEach((t) => {
            const active = t.getAttribute('href') === hash;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
            // Roving tabindex (เทคนิคของ WAI-ARIA tabs): มีเพียง tab ที่ถูกเลือกเท่านั้นที่อยู่ในลำดับการกด Tab ส่วนที่เหลือ
            // เข้าถึงด้วยปุ่มลูกศร. ถ้าไม่ทำแบบนี้ tab ทั้ง 12 จะกลายเป็นจุดหยุด Tab แยกกัน 12 จุด และไม่มี
            // การนำทางด้วยปุ่มลูกศร ทำให้ผิดสัญญาที่ role="tab" ประกาศไว้กับ assistive tech (เทคโนโลยีช่วยเหลือผู้พิการ)
            t.setAttribute('tabindex', active ? '0' : '-1');
            if (active) activeTab = t;
        });
        panels.forEach((p) => {
            p.classList.toggle('is-active', '#' + p.id === hash);
        });
        revealActive(activeTab);
    };

    const tabList = Array.from(tabs);
    const selectTab = (tab, focus) => {
        const hash = tab.getAttribute('href');
        activate(hash);
        history.replaceState(null, '', hash);
        if (focus) tab.focus();
    };

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            selectTab(tab, false);
        });
        tab.addEventListener('keydown', (e) => {
            let next = null;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = tabList[(i + 1) % tabList.length];
            else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = tabList[(i - 1 + tabList.length) % tabList.length];
            else if (e.key === 'Home') next = tabList[0];
            else if (e.key === 'End') next = tabList[tabList.length - 1];
            if (!next) return;
            e.preventDefault();
            selectTab(next, true);
        });
    });

    if (nav) {
        nav.addEventListener('scroll', updateFades, { passive: true });
        window.addEventListener('resize', updateFades);
    }

    // เลื่อนให้แถว tab มาอยู่ใต้ sticky topbar (แถบบนสุดที่ตรึงไว้) พอดี ไม่ถูกบังอยู่ใต้มัน (topbar สูงกว่า +
    // z-index สูงกว่า). ใช้โดยการกดกระโดดจาก stat-card และตอนเปิดด้วย deep link ครั้งแรก
    const revealTabsBelowTopbar = () => {
        if (!scroller) return;
        const topbar = document.querySelector('.topbar') || document.querySelector('header');
        const offset = (topbar ? topbar.getBoundingClientRect().height : 0) + 12;
        const y = scroller.getBoundingClientRect().top + window.scrollY - offset;
        window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    };

    // F5: การ์ดสถิติ (stat card) กระโดดไปยัง tab ที่ตรงกัน (เลื่อนแบบ smooth ไม่ reload). ตัว href
    // สำรอง (/admin#tab-...) ยังทำงานได้ถ้า JS ใช้ไม่ได้
    document.querySelectorAll('.stat-grid a.metric-card[href*="#tab-"]').forEach((card) => {
        card.addEventListener('click', (e) => {
            const hash = '#' + card.getAttribute('href').split('#').pop();
            if (!document.querySelector('.admin-tab[href="' + hash + '"]')) return;
            e.preventDefault();
            activate(hash);
            history.replaceState(null, '', hash);
            revealTabsBelowTopbar();
        });
    });

    const initialHash = location.hash;
    activate(initialHash || '#tab-users');
    // deep link (ลิงก์ที่ชี้ตรงจุด เช่น /admin#tab-priorities) พาเบราว์เซอร์มาที่ panel โดยที่แถว tab ถูกซ่อนอยู่หลัง
    // topbar; เลื่อนมันเข้ามาในมุมมองเพื่อให้ผู้ใช้เห็นว่า section ไหนกำลังทำงานอยู่ และสลับได้
    if (initialHash && document.querySelector('.admin-tab[href="' + initialHash + '"]')) {
        requestAnimationFrame(revealTabsBelowTopbar);
    }
    updateFades();
})();
