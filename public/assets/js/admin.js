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
            // Roving tabindex (WAI-ARIA tabs): only the selected tab is in the Tab order; the rest are
            // reached with arrow keys. Without this the 12 tabs are 12 separate Tab stops and there is no
            // arrow navigation, breaking the contract role="tab" advertises to assistive tech.
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

    // Scroll so the tab row lands just below the sticky topbar, not hidden under it (topbar is taller +
    // higher z-index). Used by the stat-card jumps and by an initial deep link. (ux-review-3 F2)
    const revealTabsBelowTopbar = () => {
        if (!scroller) return;
        const topbar = document.querySelector('.topbar') || document.querySelector('header');
        const offset = (topbar ? topbar.getBoundingClientRect().height : 0) + 12;
        const y = scroller.getBoundingClientRect().top + window.scrollY - offset;
        window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    };

    // F5: stat cards jump to their matching tab (smooth, no reload). The href
    // fallback (/admin#tab-...) still works if JS is unavailable.
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
    // A deep link (/admin#tab-priorities) landed the browser at the panel with the tab row hidden behind the
    // topbar; scroll it into view so the user sees which section is active and can switch. (ux-review-3 F2)
    if (initialHash && document.querySelector('.admin-tab[href="' + initialHash + '"]')) {
        requestAnimationFrame(revealTabsBelowTopbar);
    }
    updateFades();
})();
