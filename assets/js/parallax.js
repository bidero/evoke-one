// assets/js/parallax.js
/*
 * START ODPORNY NA PÓŹNE WCZYTANIE.
 *
 * Stało tu samo `addEventListener('DOMContentLoaded', ...)`, bez pytania, czy
 * to zdarzenie już nie minęło. Skrypt wczytany PO nim — a tak robią wtyczki
 * optymalizujące, które dokładają `async`, i tak wygląda wstawienie znacznika
 * z JS — nie robił wtedy NIC i parallax po prostu nie działał, bez śladu
 * w konsoli. Wyszło to przy pisaniu fixture'a, który celowo opóźnia skrypt,
 * żeby odwzorować żądanie ze stopki.
 */
const evkParallaxStart = () => {
    const elements = document.querySelectorAll('[data-parallax]');

    // Pobierz domyślne wartości z ustawień WordPress
    const defaults = window.evkParallaxSettings || {
        defaultValue: 0.3,
        defaultScale: 1.2
    };

    const setupParallax = (element) => {
        if (element.dataset.parallaxActive) return;

        const style = window.getComputedStyle(element);
        const isImg = element.tagName.toLowerCase() === 'img';

        // Użyj wartości z atrybutu lub domyślnej z ustawień
        let parallaxValue = element.dataset.parallax;
        if (parallaxValue === '' || parallaxValue === '{evk_parallax}') {
            parallaxValue = defaults.defaultValue;
        }
        parallaxValue = parseFloat(parallaxValue) || defaults.defaultValue;

        let customScale = element.dataset.skala;
        if (customScale === '' || customScale === '{evk_parallax_scale}') {
            customScale = defaults.defaultScale;
        }
        customScale = parseFloat(customScale) || defaults.defaultScale;

        let targetElement;

        /* WARSTWA Z SERWERA — skrypt nie tworzy tu NICZEGO.
         *
         * Element z `data-parallax-css` ma już warstwę: `::before` opisany
         * regułą wydrukowaną w `<head>` (patrz `EVK_Parallax::print_layer_css()`).
         * Ta gałąź istnieje po to, żeby NIE powtórzyć tego, co robiła stara:
         * wstawienia drugiej warstwy i zdjęcia tła z sekcji. To właśnie tamto
         * dawało „widać → pusto → wraca" przy każdym wejściu na stronę.
         *
         * Zostaje jedno zadanie: przesuwać warstwę przy przewijaniu, czyli
         * ustawiać `--evk-par-y`. Skalę reguła bierze domyślną z ustawień;
         * własną, jeśli element ją ma, dokładamy tutaj — o klatkę później,
         * ale to zmiana skali, nie zniknięcie obrazu. */
        const zSerwera = !isImg && element.hasAttribute('data-parallax-css');

        if (zSerwera) {
            /* TŁO BEZ OBRAZU NIE JEST RUSZANE. Gradient CSS to `background-image`
               tak samo jak `url(...)`, więc warstwa dziedziczyła go i przesuwała.
               Zdjęcie na ruchu zyskuje głębię, gradient tylko rozjeżdża się
               z projektem — a przy okazji warstwa rozciągałaby go o piątą część
               wysokości, bo jej pudełko sięga od -10% do 110%.
               Znacznik wyłącza warstwę regułą z arkusza. Ten sam test robi
               wczesny ustawiacz w nagłówku; tutaj jest drugi raz, bo skrypt
               dochodzi też do elementów wstawionych po starcie, których tamten
               nie widział. */
            if (style.backgroundImage.indexOf('url(') < 0) {
                element.setAttribute('data-evk-par-bez-obrazu', '1');
                element.dataset.parallaxActive = 'true';
                return;
            }
            if (Math.abs(customScale - (defaults.defaultScale || 1.2)) > 0.001) {
                element.style.setProperty('--evk-par-scale', String(customScale));
            }
            element.dataset.parallaxActive = 'true';

            const reducedCss = (window.evkMotion && typeof window.evkMotion.reduced === 'function')
                ? window.evkMotion.reduced()
                : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            // Reguła sama zatrzymuje warstwę przy „ogranicz ruch" — nie ma tu
            // czego nakładać ani czego pilnować.
            if (reducedCss) return;

            /* KOSZT TEJ PĘTLI, ZMIERZONY. `--evk-par-y` to własność
             * NIESTANDARDOWA, a te dziedziczą się na całe poddrzewo — zapis na
             * sekcji unieważnia styl każdego jej potomka. Sześć sekcji,
             * dławienie CPU 4×, 120 klatek przewijania:
             *
             *     potomków w sekcji │   0 │  20 │ 100 │  400
             *     czas przeliczania │  97 │ 228 │ 557 │ 2059 ms
             *
             * Z parallaksem wyłączonym: 0 ms w każdym przypadku, więc to nie
             * jest koszt „większej strony". UKŁAD nie kosztuje nic — `transform`
             * go nie brudzi, więc `getBoundingClientRect()` czyta czysty stan.
             * Pierwsza hipoteza mówiła o wymuszonym układzie i była fałszywa.
             *
             * Reguła w `EVK_Parallax::print_layer_css()` zatrzymuje dziedziczenie
             * na dzieciach sekcji i to zdejmuje jedną trzecią kosztu (1898 →
             * 1235 ms przy układzie płaskim, 2582 → 883 ms przy zagnieżdżonym).
             *
             * Próba przeniesienia ruchu na `animation-timeline` została
             * WYCOFANA — patrz komentarz przy regule. */

            let widoczny = false;
            let czeka = false;
            const przesun = () => {
                if (!widoczny) { czeka = false; return; }
                const rect = element.getBoundingClientRect();
                const srodek = rect.top + rect.height / 2;
                const procent = (srodek - window.innerHeight / 2) / (window.innerHeight / 2);
                element.style.setProperty('--evk-par-y', (procent * (parallaxValue * 100)) + 'px');
                czeka = false;
            };
            new IntersectionObserver((entries) => {
                entries.forEach((e) => { widoczny = e.isIntersecting; if (widoczny) przesun(); });
            }, { rootMargin: '20% 0px', threshold: 0 }).observe(element);
            window.addEventListener('scroll', () => {
                if (widoczny && !czeka) { czeka = true; requestAnimationFrame(przesun); }
            }, { passive: true });
            return;
        }

        if (isImg) {
            const wrapper = document.createElement('div');
            wrapper.style.cssText = `
                position: relative;
                overflow: hidden;
                height: 100%;
                width: 100%;
                z-index: 1;
                transform: translateZ(0);
            `;

            element.style.cssText = `
                position: absolute;
                height: 100%;
                width: auto;
                min-width: 100%;
                top: 50%;
                left: 50%;
                will-change: transform;
                backface-visibility: hidden;
                -webkit-backface-visibility: hidden;
                opacity: 0;
                transition: opacity 0.1s ease-in-out;
                transform: translate3d(-50%, -50%, 0) scale(${customScale});
            `;

            element.parentElement.insertBefore(wrapper, element);
            wrapper.appendChild(element);
            targetElement = element;
        } else {
            const bgElement = document.createElement('div');
            bgElement.style.cssText = `
                position: absolute;
                top: -10%;
                bottom: -10%;
                left: 0;
                right: 0;
                background-image: ${style.backgroundImage};
                background-color: ${style.backgroundColor};
                background-position: ${style.backgroundPosition};
                background-size: ${style.backgroundSize !== 'auto' ? style.backgroundSize : 'cover'};
                background-repeat: ${style.backgroundRepeat};
                z-index: -1;
                will-change: transform;
                pointer-events: none;
                backface-visibility: hidden;
                -webkit-backface-visibility: hidden;
                transform: translate3d(0, 0, 0) scale(${customScale});
            `;

            element.style.position = 'relative';
            element.style.overflow = 'hidden';
            element.style.isolation = 'isolate';

            element.insertAdjacentElement('afterbegin', bgElement);
            targetElement = bgElement;

            /* TŁO SEKCJI ZOSTAJE — i warstwa nie wyłania się z pustki.
             *
             * Do 1.140.1 było tu `opacity: 0` z przejściem oraz
             * `element.style.backgroundImage = 'none'`. Razem dawały dziurę:
             * sekcja była już namalowana ze swoim tłem, skrypt to tło zdejmował,
             * a warstwa wjeżdżała dopiero dwie klatki później. „Widać → pusto
             * → wraca" — zgłoszone jako migotanie przy każdym wejściu na stronę.
             *
             * Warstwa i tak zakrywa sekcję w całości (jest wsunięta o 10% w górę
             * i w dół, na pełną szerokość), więc tło pod spodem nie ma jak się
             * pokazać. Nie trzeba go zdejmować, a skoro nie trzeba — nie ma
             * czego maskować przejściem. Ta sama zasada, na której stoi warstwa
             * drukowana z serwera (`EVK_Parallax::print_layer_css()`). */
        }

        element.dataset.parallaxActive = "true";

        /* Wyłanianie zostaje WYŁĄCZNIE dla obrazu.
         *
         * Przy `<img>` skrypt naprawdę przestawia element: wkłada go w nową
         * owijkę i przesuwa na środek transformacją. Tego skoku nie da się
         * uniknąć i to jego maskuje przejście krycia — dlatego tam zostaje.
         *
         * Przy tle nie ma czego maskować: warstwa dostaje tę samą grafikę
         * w tym samym miejscu, a sekcja zachowuje swoje tło. Wyłanianie było
         * tam nie efektem, tylko przykrywką na dziurę, którą skrypt sam
         * robił. */
        if (isImg) {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    targetElement.style.opacity = '1';
                });
            });
        }

        // Silnik ruchu
        let isVisible = false;
        let ticking = false;

        const updateTransform = () => {
            if (!isVisible) {
                ticking = false;
                return;
            }

            const rect = element.getBoundingClientRect();
            const viewportHeight = window.innerHeight;
            const elementCenter = rect.top + rect.height / 2;
            const scrollPercent = (elementCenter - viewportHeight / 2) / (viewportHeight / 2);
            const moveY = scrollPercent * (parallaxValue * 100);

            targetElement.style.transform = isImg
                ? `translate3d(-50%, calc(-50% + ${moveY}px), 0) scale(${customScale})`
                : `translate3d(0, ${moveY}px, 0) scale(${customScale})`;

            ticking = false;
        };

        // Redukcja ruchu: transform nakładamy RAZ, z zerowym przesunięciem, i tu
        // kończymy. Wyjście MUSI być przed IntersectionObserverem — jego callback
        // też woła updateTransform(), więc postawione niżej nic by nie dało.
        // Skala zostaje: element bywa przeskalowany właśnie po to, żeby ruch nie
        // odsłaniał krawędzi, a jej zdjęcie zmieniłoby kadrowanie.
        // Wspólna polityka: includes/anim/motion.php.
        const reduced = (window.evkMotion && typeof window.evkMotion.reduced === 'function')
            ? window.evkMotion.reduced()
            : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

        if (reduced) {
            targetElement.style.transform = isImg
                ? `translate3d(-50%, -50%, 0) scale(${customScale})`
                : `translate3d(0, 0, 0) scale(${customScale})`;
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                isVisible = entry.isIntersecting;
                if (isVisible) updateTransform();
            });
        }, { rootMargin: '20% 0px', threshold: 0 });

        observer.observe(element);

        const onScroll = () => {
            if (isVisible && !ticking) {
                ticking = true;
                requestAnimationFrame(updateTransform);
            }
        };

        window.addEventListener('scroll', onScroll, { passive: true });
    };

    // Inicjalizacja z MutationObserver dla dynamicznych teł
    const initElement = (el) => {
        const style = window.getComputedStyle(el);

        if (el.tagName.toLowerCase() === 'img') {
            if (el.complete) {
                setupParallax(el);
            } else {
                el.addEventListener('load', () => setupParallax(el), { once: true });
            }
        } else if (el.hasAttribute('data-parallax-css')) {
            /* Warstwa jest w CSS i dziedziczy tło z sekcji — nie ma na co
               czekać, nawet gdy tło dokłada dopiero arkusz Bricksa. */
            setupParallax(el);
        } else if (style.backgroundImage && style.backgroundImage !== 'none') {
            setupParallax(el);
        } else {
            const obs = new MutationObserver(() => {
                if (window.getComputedStyle(el).backgroundImage !== 'none') {
                    setupParallax(el);
                    obs.disconnect();
                }
            });
            obs.observe(el, { attributes: true, attributeFilter: ['style', 'class'] });
        }
    };

    elements.forEach(initElement);

    // Obserwuj nowe elementy dodawane dynamicznie (np. przez Bricks)
    const bodyObserver = new MutationObserver((mutations) => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) {
                    if (node.hasAttribute('data-parallax')) {
                        initElement(node);
                    }
                    node.querySelectorAll?.('[data-parallax]').forEach(initElement);
                }
            });
        });
    });

    bodyObserver.observe(document.body, { childList: true, subtree: true });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', evkParallaxStart);
} else {
    evkParallaxStart();
}
