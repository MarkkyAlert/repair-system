(() => {
    const tabs = document.querySelectorAll('.admin-tab');
    const panels = document.querySelectorAll('.admin-tab-panel');
    if (!tabs.length) return;

    const nav = document.querySelector('.admin-tabs');
    const scroller = document.querySelector('.admin-tabs-scroller');

    // F2: show fade edges only when there is hidden content to scroll toward.
    const updateFades = () => {
        if (!nav || !scroller) return;
        const max = nav.scrollWidth - nav.clientWidth;
        scroller.classList.toggle('can-scroll-left', nav.scrollLeft > 4);
        scroller.classList.toggle('can-scroll-right', nav.scrollLeft < max - 4);
    };

    // F1: bring the active tab into view horizontally without moving page scroll.
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
            if (active) activeTab = t;
        });
        panels.forEach((p) => {
            p.classList.toggle('is-active', '#' + p.id === hash);
        });
        revealActive(activeTab);
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const hash = tab.getAttribute('href');
            activate(hash);
            history.replaceState(null, '', hash);
        });
    });

    if (nav) {
        nav.addEventListener('scroll', updateFades, { passive: true });
        window.addEventListener('resize', updateFades);
    }

    // F5: stat cards jump to their matching tab (smooth, no reload). The href
    // fallback (/admin#tab-...) still works if JS is unavailable.
    document.querySelectorAll('.stat-grid a.metric-card[href*="#tab-"]').forEach((card) => {
        card.addEventListener('click', (e) => {
            const hash = '#' + card.getAttribute('href').split('#').pop();
            if (!document.querySelector('.admin-tab[href="' + hash + '"]')) return;
            e.preventDefault();
            activate(hash);
            history.replaceState(null, '', hash);
            // Offset the scroll by the sticky topbar so the tab bar lands below it, not hidden under.
            if (scroller) {
                const topbar = document.querySelector('.topbar') || document.querySelector('header');
                const offset = (topbar ? topbar.getBoundingClientRect().height : 0) + 12;
                const y = scroller.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
            }
        });
    });

    activate(location.hash || '#tab-users');
    updateFades();
})();
