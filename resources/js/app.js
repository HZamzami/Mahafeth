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

// Count a number up from zero when it scrolls into view; used by the
// health score. Renders the real value server-side, so without JS (or with
// reduced motion) the number is simply already there.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('countUp', (target) => ({
        shown: target,
        start() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }

            const duration = 900;
            const begin = performance.now();

            const tick = (now) => {
                const progress = Math.min((now - begin) / duration, 1);
                this.shown = Math.round(target * (1 - Math.pow(1 - progress, 3)));

                if (progress < 1) {
                    requestAnimationFrame(tick);
                }
            };

            this.shown = 0;
            requestAnimationFrame(tick);
        },
    }));
});

// Short confirmation buzz on key moments (Android; iOS Safari has no
// Vibration API). Kept rare on purpose: feedback, not decoration.
window.haptic = (ms = 8) => {
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        navigator.vibrate?.(ms);
    }
};

const navigateTo = (url) => {
    if (window.Livewire?.navigate) {
        window.Livewire.navigate(url);
    } else {
        window.location.assign(url);
    }
};

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

            window.haptic(15);
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
