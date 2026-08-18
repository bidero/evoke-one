/**
 * Evoke Circular Title v1.1.4
 */
class EvkArcTitle {
    static instances = new Set();

    constructor(element) {
        this.element = element;
        this.inner = element.querySelector('.evk-arc__inner');
        this.options = {
            spacing: parseFloat(element.getAttribute('data-spacing')) || 1,
            size: 1,
        };
        this.isBuilder = typeof bricksIsFrontend !== 'undefined' && !bricksIsFrontend;
        this.canTrig = CSS.supports('(top: calc(sin(1) * 1px))');
        this.reverse      = element.getAttribute('data-reverse') === '1';
        this.scroll       = element.getAttribute('data-scroll') === 'true';
        this.scrollStart  = element.getAttribute('data-scroll-start');
        this.scrollEnd    = element.getAttribute('data-scroll-end');
        this.scrub        = element.getAttribute('data-scrub');
        this.scrollRotation  = parseFloat(element.getAttribute('data-scroll-rotation'));
        this.scrollEasing = element.getAttribute('data-scroll-easing');
        this.velocity     = element.getAttribute('data-scroll-velocity') === 'true';
        this.velocityMult = parseFloat(element.getAttribute('data-velocity-multiplier')) || 3;
        this.initialize();
    }

    initialize() {
        if (!this.inner) return;
        this.updateContent();
        this.element.removeAttribute('data-flickering');
        this.createArcText();
        this.updateStyles();
        // W builderze: tylko renderuj tekst kołowy, bez animacji
        if (this.isBuilder) {
            this.inner.style.animation = 'none';
            return;
        }
        // Redukcja ruchu: tekst zostaje ułożony po okręgu, ale się nie obraca —
        // ani od prędkości scrolla, ani przez ScrollTrigger. Zatrzymujemy też
        // animację CSS, bo bez tego okrąg kręciłby się dalej sam.
        // Wspólna polityka: includes/anim/motion.php.
        if (EvkArcTitle.reduced()) {
            this.inner.style.animation = 'none';
            return;
        }
        if (this.velocity) this.initVelocity();
        else if (this.scroll) this.initScrollTrigger();
    }

    static reduced() {
        if (window.evkMotion && typeof window.evkMotion.reduced === 'function') {
            return window.evkMotion.reduced();
        }
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    updateContent() {
        const content = this.inner.getAttribute('data-content');
        if (content) {
            this.options.text = content;
        } else {
            this.options.text = this.inner.textContent || 'Circular · Title · ';
        }
    }

    createArcText() {
        this.inner.innerHTML = '';
        const chars = this.options.text.split('');
        this.inner.style.setProperty('--evk-char-count', chars.length);
        chars.forEach((char, index) => {
            const span = document.createElement('span');
            span.className = 'evk-arc__char';
            span.setAttribute('aria-hidden', 'true');
            span.style.setProperty('--evk-char-index', index);
            span.textContent = char;
            this.inner.appendChild(span);
        });
        const sr = document.createElement('span');
        sr.className = 'evk-arc__sr-only';
        sr.textContent = this.options.text;
        this.inner.appendChild(sr);
    }

    updateStyles() {
        const { spacing, size } = this.options;
        const charCount = this.inner.children.length - 1;
        this.inner.style.setProperty('--evk-font-size', size);
        this.inner.style.setProperty('--evk-character-width', spacing);
        const sinAngle = Math.sin((360 / charCount) / (180 / Math.PI));
        const radius = this.canTrig
            ? 'calc((var(--evk-character-width) / sin(var(--evk-inner-angle))) * -1ch)'
            : `calc((${spacing} / ${sinAngle}) * -1ch)`;
        this.inner.style.setProperty('--evk-radius', radius);
        if (charCount > 3) {
            const buffer = this.canTrig
                ? `calc((${spacing} / sin(${360 / charCount}deg)) * ${size}rem)`
                : `calc((${spacing} / ${sinAngle}) * ${size}rem)`;
            document.documentElement.style.setProperty('--evk-buffer', buffer);
        }
    }

    // Tryb velocity: obrót napędzany czystym JS — brak CSS animation, brak efektu cofania
    initVelocity() {
        // Wyłącz CSS animation — całkowita kontrola przez JS
        this.inner.style.animation = 'none';

        const style = getComputedStyle(this.element);
        const baseDuration = parseFloat(style.getPropertyValue('--evk-duration')) || 9;
        // stopnie na ms przy prędkości bazowej
        const baseDegsPerMs = 360 / (baseDuration * 1000);
        const mult = this.velocityMult;
        const reverse = this.reverse;

        let currentDeg = 0;
        let speedFactor = 1;
        let currentVelocity = 0;
        let lastTime = performance.now();
        let rafId;

        /* Lenis dostarcza velocity bezpośrednio. Nazwa jest JEDNA i ustawia ją
           moduł płynnego przewijania (includes/96-lenis.php). Do 1.94.0 stało
           tu `window.lenis || window.__lenis` — dwie nazwy, których nikt nigdy
           nie ustawiał, więc element zawsze schodził do ścieżki zapasowej
           i liczył prędkość sam, mimo działającego Lenisa. */
        const lenis = window.evkLenis || null;

        if (lenis && typeof lenis.on === 'function') {
            const onLenisScroll = ({ velocity }) => {
                currentVelocity = Math.abs(velocity) * 100;
            };
            lenis.on('scroll', onLenisScroll);
            this._destroyVelocity = () => {
                lenis.off('scroll', onLenisScroll);
                cancelAnimationFrame(rafId);
                this.inner.style.animation = '';
                this.inner.style.rotate = '';
            };
        } else {
            // Fallback: natywny scroll event + dy/dt
            let lastY = window.scrollY;
            let lastScrollTime = performance.now();
            const onNativeScroll = () => {
                const now = performance.now();
                const dt = (now - lastScrollTime) / 1000;
                const dy = Math.abs(window.scrollY - lastY);
                lastY = window.scrollY;
                lastScrollTime = now;
                currentVelocity = dt > 0 ? dy / dt : 0;
            };
            window.addEventListener('scroll', onNativeScroll, { passive: true });
            this._destroyVelocity = () => {
                window.removeEventListener('scroll', onNativeScroll);
                cancelAnimationFrame(rafId);
                this.inner.style.animation = '';
                this.inner.style.rotate = '';
            };
        }

        // rAF: akumuluj kąt — bez resetu, bez cofania
        const tick = () => {
            const now = performance.now();
            const dt = now - lastTime;
            lastTime = now;

            const target = 1 + Math.min(mult - 1, currentVelocity / 500 * (mult - 1));
            speedFactor += (target - speedFactor) * 0.08;
            currentVelocity *= 0.92;

            const step = baseDegsPerMs * dt * speedFactor;
            currentDeg += reverse ? -step : step;

            this.inner.style.rotate = currentDeg.toFixed(3) + 'deg';
            rafId = requestAnimationFrame(tick);
        };

        rafId = requestAnimationFrame(tick);
    }

    refresh() {
        this.updateContent();
        this.createArcText();
        this.updateStyles();
    }

    destroy() {
        if (this._destroyVelocity) this._destroyVelocity();
        if (this.inner) {
            const content = this.inner.getAttribute('data-content');
            if (content) this.inner.textContent = content;
            this.inner.removeAttribute('style');
        }
    }

    static destroyAll() {
        EvkArcTitle.instances.forEach(instance => {
            instance.destroy();
            EvkArcTitle.instances.delete(instance);
        });
    }

    initScrollTrigger() {
        const run = () => {
            gsap.registerPlugin(ScrollTrigger);
            gsap.to(this.inner, {
                scrollTrigger: {
                    trigger: this.element,
                    start: this.scrollStart,
                    end: this.scrollEnd === 'none' ? '+=100%' : this.scrollEnd,
                    scrub: this.scrub === 'true' ? 1 : (parseFloat(this.scrub) || false),
                },
                rotation: this.scrollRotation,
                ease: this.scrollEasing,
                immediateRender: false,
            });
        };

        if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
            run();
            return;
        }

        let attempts = 0;
        const interval = setInterval(() => {
            if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
                clearInterval(interval);
                run();
            } else if (++attempts > 50) {
                clearInterval(interval);
                console.warn('Evoke Circular Title: GSAP + ScrollTrigger not found after 5s.');
            }
        }, 100);
    }
}

function evk_circular_title_init() {
    EvkArcTitle.destroyAll();
    document.querySelectorAll('.evk-arc-title').forEach(el => {
        const instance = new EvkArcTitle(el);
        EvkArcTitle.instances.add(instance);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    evk_circular_title_init();
});
