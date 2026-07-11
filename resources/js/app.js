if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

// Fade the main content in after every SPA navigation so page changes
// glide instead of popping. The animation lives behind a
// prefers-reduced-motion media query in app.css.
document.addEventListener('livewire:navigated', () => {
    const main = document.querySelector('main');

    if (!main) {
        return;
    }

    main.classList.remove('page-enter');
    void main.offsetWidth;
    main.classList.add('page-enter');
});

const navigateTo = (url) => {
    if (window.Livewire?.navigate) {
        window.Livewire.navigate(url);
    } else {
        window.location.assign(url);
    }
};

// Swipe left/right on the content area to move between the bottom-nav tabs,
// like a native stocks app. Skips gestures that belong to something else:
// horizontally scrollable tables, charts (svg), form fields, and modals.
(() => {
    let start = null;

    const owsGesture = (el) =>
        el.closest('.overflow-x-auto, svg, input, textarea, select, dialog, [role="dialog"]') !== null;

    document.addEventListener(
        'touchstart',
        (event) => {
            const touch = event.touches[0];
            start = owsGesture(event.target)
                ? null
                : { x: touch.clientX, y: touch.clientY, time: Date.now() };
        },
        { passive: true },
    );

    document.addEventListener(
        'touchend',
        (event) => {
            if (start === null) {
                return;
            }

            const touch = event.changedTouches[0];
            const dx = touch.clientX - start.x;
            const dy = touch.clientY - start.y;
            const elapsed = Date.now() - start.time;
            start = null;

            if (Math.abs(dx) < 60 || Math.abs(dx) < 2 * Math.abs(dy) || elapsed > 600) {
                return;
            }

            const nav = document.querySelector('[data-swipe-tabs]');

            if (!nav || getComputedStyle(nav).display === 'none') {
                return;
            }

            const tabs = [...nav.querySelectorAll(':scope > div > a[href]')];
            const current = tabs.findIndex(
                (tab) => new URL(tab.href).pathname === window.location.pathname,
            );

            if (current === -1) {
                return;
            }

            // Swiping the content left reveals the visually-next tab, which in
            // RTL is the previous one in DOM order.
            const rtl = document.documentElement.dir === 'rtl';
            const step = (dx < 0 ? 1 : -1) * (rtl ? -1 : 1);
            const target = tabs[current + step];

            if (target) {
                navigateTo(target.href);
            }
        },
        { passive: true },
    );
})();

// Pull-to-refresh, only when installed to the home screen: browser tabs
// already have the native gesture, and standalone mode is where we disable
// overscroll so this one can take over. Releasing past the threshold
// re-renders the current page fresh from the server.
(() => {
    if (!window.matchMedia('(display-mode: standalone)').matches) {
        return;
    }

    const THRESHOLD = 70;
    const RESISTANCE = 0.4;

    const indicator = document.createElement('div');
    indicator.className = 'ptr-indicator';
    indicator.innerHTML =
        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>';
    document.body.appendChild(indicator);

    let startY = null;
    let pulled = 0;

    const reset = () => {
        startY = null;
        pulled = 0;
        indicator.classList.remove('ptr-visible');
        indicator.style.transform = '';
    };

    document.addEventListener(
        'touchstart',
        (event) => {
            const scrollable = event.target.closest('.overflow-y-auto');

            startY =
                window.scrollY === 0 &&
                (!scrollable || scrollable.scrollTop === 0) &&
                !document.querySelector('dialog[open]') &&
                !indicator.classList.contains('ptr-loading')
                    ? event.touches[0].clientY
                    : null;
        },
        { passive: true },
    );

    document.addEventListener(
        'touchmove',
        (event) => {
            if (startY === null) {
                return;
            }

            pulled = Math.min((event.touches[0].clientY - startY) * RESISTANCE, 100);

            if (pulled <= 0) {
                indicator.classList.remove('ptr-visible');

                return;
            }

            indicator.classList.add('ptr-visible');
            indicator.style.transform = `translateY(${pulled}px) rotate(${pulled * 3}deg)`;
        },
        { passive: true },
    );

    document.addEventListener(
        'touchend',
        () => {
            if (startY === null) {
                return;
            }

            if (pulled < THRESHOLD) {
                reset();

                return;
            }

            indicator.classList.add('ptr-loading');
            indicator.style.transform = `translateY(${THRESHOLD}px)`;

            document.addEventListener(
                'livewire:navigated',
                () => {
                    indicator.classList.remove('ptr-loading');
                    reset();
                },
                { once: true },
            );

            navigateTo(window.location.pathname + window.location.search);
        },
        { passive: true },
    );
})();
