import './passkeys.js';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');

        // A signed-out visitor must not keep the previous user's cached
        // dashboard; the login page doubles as the logout landing spot.
        if (document.body.dataset.guest !== undefined) {
            navigator.serviceWorker.controller?.postMessage({ type: 'clear-snapshot' });
        }
    });
}

// Surface connectivity in the UI: body.offline shows the banner styled in
// app.css, covering both an offline launch from the cached dashboard
// snapshot and a live tab losing its connection.
const reflectConnectivity = () => {
    document.body.classList.toggle('offline', !navigator.onLine);
};

window.addEventListener('online', reflectConnectivity);
window.addEventListener('offline', reflectConnectivity);
window.addEventListener('load', reflectConnectivity);

// Dim the outgoing page the moment a wire:navigate hop starts so every
// tap gets an instant visual acknowledgement, even before the network
// answers (styles in app.css under body.navigating).
document.addEventListener('livewire:navigate', () => {
    document.body.classList.add('navigating');
});

// Fade the main content in after every SPA navigation so page changes
// glide instead of popping. The animation lives behind a
// prefers-reduced-motion media query in app.css.
document.addEventListener('livewire:navigated', () => {
    document.body.classList.remove('navigating');

    const main = document.querySelector('main');

    if (!main) {
        return;
    }

    main.classList.remove('page-enter');
    void main.offsetWidth;
    main.classList.add('page-enter');
});

// Welcome-page effects: gauge sweeps, count-up numbers, and scroll reveals,
// all driven by plain IntersectionObservers (this page has no Livewire
// component, so Alpine's x-intersect is not guaranteed). Runs on the first
// load and again after every wire:navigate hop, since a navigate swap does
// not refire window load; the data-*-done flags keep revisits idempotent.
const initWelcomeEffects = () => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Gauge rings and sparklines: sweep the stroke to its target offset.
    // WebKit never fires IntersectionObserver for elements inside an SVG,
    // so the observer watches the nearest HTML ancestor and then sweeps
    // the strokes within it. Reduced motion still reaches the final
    // state; the CSS transition just isn't there.
    const drawables = document.querySelectorAll('[data-draw-offset]:not([data-draw-done])');

    if (drawables.length) {
        const strokesByAnchor = new Map();

        drawables.forEach((element) => {
            element.dataset.drawDone = 'true';
            const anchor = element.closest('div, section') ?? element;

            if (!strokesByAnchor.has(anchor)) {
                strokesByAnchor.set(anchor, []);
            }

            strokesByAnchor.get(anchor).push(element);
        });

        const drawObserver = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (!entry.isIntersecting) {
                        continue;
                    }

                    for (const element of strokesByAnchor.get(entry.target) ?? []) {
                        element.style.strokeDashoffset = element.dataset.drawOffset;
                    }

                    drawObserver.unobserve(entry.target);
                }
            },
            { threshold: 0.25 },
        );

        strokesByAnchor.forEach((strokes, anchor) => drawObserver.observe(anchor));

        // Belt and braces: if any observer quirk keeps a stroke from
        // sweeping, fill it after a beat. A pre-filled gauge below the
        // fold beats an empty one.
        setTimeout(() => {
            drawables.forEach((element) => {
                element.style.strokeDashoffset = element.dataset.drawOffset;
            });
        }, 2500);
    }

    // Count-up numbers: the real value is server-rendered, so without JS
    // or with reduced motion it is simply already there.
    const counters = document.querySelectorAll('[data-count-to]:not([data-count-done])');

    if (counters.length && !reduceMotion) {
        const countObserver = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) {
                    continue;
                }

                countObserver.unobserve(entry.target);

                const target = Number(entry.target.dataset.countTo);
                const begin = performance.now();

                const tick = (now) => {
                    const progress = Math.min((now - begin) / 900, 1);
                    entry.target.textContent = Math.round(target * (1 - Math.pow(1 - progress, 3)));

                    if (progress < 1) {
                        requestAnimationFrame(tick);
                    }
                };

                requestAnimationFrame(tick);
            }
        });

        counters.forEach((element) => {
            element.dataset.countDone = 'true';
            countObserver.observe(element);
        });
    }

    const revealables = document.querySelectorAll('.welcome-reveal:not([data-reveal-done])');

    if (!revealables.length || reduceMotion) {
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('welcome-in');
                    observer.unobserve(entry.target);
                }
            }
        },
        { threshold: 0.15 },
    );

    revealables.forEach((element) => {
        element.dataset.revealDone = 'true';
        observer.observe(element);
    });
};

window.addEventListener('load', initWelcomeEffects);
document.addEventListener('livewire:navigated', initWelcomeEffects);

// Demo forms: reveal the building overlay the moment the form submits and
// cycle the step text while the server provisions the account. Step labels
// come from the form's data-steps JSON so they stay server-translated.
// Deliberately NOT an Alpine component: the hero button is clickable before
// Alpine (loaded via livewire.js on DOMContentLoaded) has walked the page,
// so an early click would submit natively with no overlay. This delegated
// listener is live as soon as the bundle executes.
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-demo-form]');

    if (!form) {
        return;
    }

    const overlay = form.querySelector('[data-demo-overlay]');
    const stepText = form.querySelector('[data-demo-step]');
    const steps = JSON.parse(form.dataset.steps ?? '[]');
    let step = 0;

    overlay.style.display = '';
    form.querySelector('button[type="submit"]')?.setAttribute('disabled', '');

    setInterval(() => {
        step = Math.min(step + 1, steps.length - 1);
        stepText.textContent = steps[step] ?? stepText.textContent;
    }, 2500);
});

document.addEventListener('alpine:init', () => {
    // Welcome-page hero: drives the CSS vars behind .welcome-parallax so
    // the floating fragments drift gently against the pointer.
    window.Alpine.data('pointerParallax', () => ({
        init() {
            if (!window.matchMedia('(hover: hover)').matches) {
                return;
            }

            this.$el.addEventListener(
                'pointermove',
                (event) => {
                    const bounds = this.$el.getBoundingClientRect();
                    this.$el.style.setProperty('--px', ((event.clientX - bounds.left) / bounds.width - 0.5).toFixed(3));
                    this.$el.style.setProperty('--py', ((event.clientY - bounds.top) / bounds.height - 0.5).toFixed(3));
                },
                { passive: true },
            );
        },
    }));
    // Shows a gradient + chevron on horizontally scrollable strips (tabs,
    // chip rows) while more content hides past the trailing edge.
    window.Alpine.data('scrollHint', () => ({
        more: false,

        init() {
            const area =
                this.$el.querySelector('ui-tabs-scroll-area, [data-scroll-area]') ?? this.$el.firstElementChild;

            const update = () => {
                this.more = area.scrollWidth - area.clientWidth - Math.abs(area.scrollLeft) > 8;
            };

            area.addEventListener('scroll', update, { passive: true });
            new ResizeObserver(update).observe(area);
            requestAnimationFrame(update);
        },
    }));

    window.Alpine.data('countUp', (target, format = false, decimals = 0) => ({
        // toFixed mirrors PHP number_format so the server-rendered value and
        // the animated one are glyph-identical in both locales.
        shown: format ? new Intl.NumberFormat(document.documentElement.lang).format(target) : target.toFixed(decimals),
        start() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }

            const formatter = format ? new Intl.NumberFormat(document.documentElement.lang) : null;
            const duration = 900;
            const begin = performance.now();

            const render = (value) => {
                this.shown = formatter ? formatter.format(Math.round(value)) : value.toFixed(decimals);
            };

            const tick = (now) => {
                const progress = Math.min((now - begin) / duration, 1);
                render(target * (1 - Math.pow(1 - progress, 3)));

                if (progress < 1) {
                    requestAnimationFrame(tick);
                }
            };

            render(0);
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
