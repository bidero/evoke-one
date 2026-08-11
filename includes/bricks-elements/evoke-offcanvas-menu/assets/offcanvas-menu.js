/**
 * Evoke ONE — Offcanvas Menu
 *
 * Dwa NIEZALEŻNE ruchy, każdy z własnym czasem i krzywą:
 *
 *   KADR  (.evk-oc-frame) — wysuwa się z krawędzi ekranu; to otwieranie menu.
 *   TAŚMA (.evk-oc-track) — przesuwa się w poziomie w kadrze; to przechodzenie
 *                           między panelami.
 *
 * Panele leżą OBOK SIEBIE na taśmie, więc wejście w podmenu WYPYCHA rodzica
 * zamiast go zasłaniać. Wcześniejsza wersja kładła panele jeden na drugim
 * i drugi po prostu przykrywał pierwszy.
 *
 * `evk_offcanvas_menu_init()` wołane jest z dwóch stron: przez Bricks (patrz
 * $scripts w element.php) i przez własny DOMContentLoaded niżej. Flaga
 * `data-evk-oc-ready` pilnuje, żeby jeden korzeń zainicjalizował się raz.
 */

function evk_offcanvas_menu_init() {
    document.querySelectorAll('.evk-oc').forEach(function (root) {
        if (root.dataset.evkOcReady === '1') return;
        if (evk_offcanvas_menu_init_one(root)) root.dataset.evkOcReady = '1';
    });
}

function evk_offcanvas_menu_init_one(root) {

    var panels = Array.prototype.slice.call(root.querySelectorAll('.evk-oc-panel'));
    // Bez paneli nie ma czego pokazywać — świeżo wstawiony element w builderze
    // trafia tu, zanim powstaną dzieci, i ma dostać drugą szansę.
    if (!panels.length) return false;

    var isBuilder   = (typeof bricksIsFrontend !== 'undefined') && !bricksIsFrontend;
    var openBuilder = root.getAttribute('data-open-builder') === '1';
    var mode        = root.getAttribute('data-mode') || 'single';
    var escBack     = root.getAttribute('data-esc-back') === '1' && mode === 'levels';
    var closeOnLink = root.getAttribute('data-close-link') === '1';
    var lockScroll  = root.getAttribute('data-lock') === '1';
    var usePortal   = root.getAttribute('data-portal') === '1';
    var startId     = root.getAttribute('data-start') || '';
    var side        = root.getAttribute('data-side') || 'right';

    // Wspólna polityka ruchu wtyczki — patrz includes/anim/motion.php.
    var reduced = (window.evkMotion && typeof window.evkMotion.reduced === 'function')
        ? window.evkMotion.reduced()
        : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function num(attr, fallback) {
        var v = parseFloat(root.getAttribute(attr));
        return isNaN(v) ? fallback : v;
    }

    /**
     * Krzywa, którą przeglądarka NA PEWNO przyjmie.
     *
     * Wartość przelicza już PHP (evk_anim_easing_css), ale ostatnie słowo ma
     * przeglądarka: nieznana funkcja czasu unieważnia CAŁĄ deklarację
     * `transition` — razem z czasem trwania — więc jedna zła wartość nie
     * psuje krzywej, tylko GASI ruch do zera. Odrzucona wartość znaczy „nie
     * ustawiaj zmiennej": arkusz ma własną domyślną i menu dalej się rusza.
     *
     * Dwa realne przypadki: `linear()` na starszej przeglądarce (odbicie
     * i sprężyna nie dają się zapisać jako `cubic-bezier`) oraz strona
     * z pamięci podręcznej, która niesie jeszcze surową nazwę GSAP-a.
     */
    function cssEase(attr) {
        var v = (root.getAttribute(attr) || '').trim();
        if (!v) return '';
        if (window.CSS && CSS.supports && !CSS.supports('transition-timing-function', v)) {
            return '';
        }
        return v;
    }

    var frameTime = reduced ? 0 : num('data-duration', 0.35);
    var frameEase = cssEase('data-easing');
    // Osobny czas taśmy jest sednem efektu: wspólny daje ruch liniowy, bo menu
    // wjeżdża i panele przesuwają się dokładnie tak samo. Puste = ten sam co kadr.
    // Domyślka to czas KADRU, nie `frameTime || 0.35`: przy jawnie ustawionym
    // zerze `0 || 0.35` dawało 0,35 i taśma jechała mimo wyłączonego ruchu.
    var trackTime = reduced ? 0 : num('data-panel-duration', frameTime);
    var trackEase = cssEase('data-panel-easing') || frameEase;

    /**
     * Jak wygląda wejście w podmenu.
     *
     *   'expand' — KADR SIĘ POSZERZA. Rodzic przesuwa się w lewo, ale zostaje
     *              widoczny obok podmenu, a menu rośnie o szerokość jednego
     *              panelu na poziom. Tak działa wzór (nextbricks) i to jest
     *              domyślne.
     *   'slide'  — rodzic wyjeżdża CAŁKIEM poza kadr, podmenu zajmuje jego
     *              miejsce. Kadr ma stałą szerokość.
     *
     * Poszerzanie ma sens tylko w poziomie: przy menu z góry lub z dołu
     * „szerokością" jest wysokość, a panele i tak leżą obok siebie — dlatego
     * te dwie strony zawsze jadą trybem 'slide'.
     */
    var expand = mode === 'levels'
        && (root.getAttribute('data-level-style') || 'expand') !== 'slide'
        && (side === 'left' || side === 'right');

    // ── Powłoka ────────────────────────────────────────────────────────────
    var shell = document.createElement('div');
    shell.className = 'evk-oc-shell is-side-' + side;
    shell.setAttribute('role', 'dialog');
    shell.setAttribute('aria-modal', 'true');

    var scrim = document.createElement('div');
    scrim.className = 'evk-oc-scrim';

    var frame = document.createElement('div');
    frame.className = 'evk-oc-frame';

    var track = document.createElement('div');
    track.className = 'evk-oc-track';

    panels.forEach(function (p) { track.appendChild(p); });
    frame.appendChild(track);
    shell.appendChild(scrim);
    shell.appendChild(frame);

    /* Zmienne NA POWŁOCE, nie na korzeniu. Powłoka jedzie do <body>, więc
       przestaje być potomkiem korzenia i nic z niego nie dziedziczy —
       ustawione tylko na korzeniu nie docierały do kadru ani taśmy i oba
       brały wartości zapasowe z arkusza. */
    shell.style.setProperty('--evk-oc-time', frameTime + 's');
    shell.style.setProperty('--evk-oc-panel-time', trackTime + 's');
    if (frameEase) shell.style.setProperty('--evk-oc-ease', frameEase);
    if (trackEase) shell.style.setProperty('--evk-oc-panel-ease', trackEase);
    // Szerokość panelu ustawia builder na korzeniu (kontrolka z `css`), więc
    // trzeba ją przepisać razem z resztą.
    var size = getComputedStyle(root).getPropertyValue('--evk-oc-size');
    if (size && size.trim()) shell.style.setProperty('--evk-oc-size', size.trim());

    if (usePortal && !isBuilder) document.body.appendChild(shell);
    else                        root.appendChild(shell);

    // ── Identyfikacja paneli ───────────────────────────────────────────────
    // ID z atrybutu `data-panel`, a gdy go nie ma — kolejność. Numer jest
    // gorszym identyfikatorem (przestawienie paneli zrywa odnośniki), ale
    // pozwala zacząć bez żadnej konfiguracji.
    function panelId(p, i) { return p.getAttribute('data-panel') || String(i); }

    function indexOfId(id) {
        for (var i = 0; i < panels.length; i++) {
            if (panelId(panels[i], i) === String(id)) return i;
        }
        return -1;
    }

    var startIdx = startId ? indexOfId(startId) : 0;
    if (startIdx < 0) startIdx = 0;
    var stack = [startIdx];

    // ── Stan ───────────────────────────────────────────────────────────────
    /**
     * Panel poza kadrem NADAL łapie fokus tabulatorem — przesunięcie taśmy
     * niczego nie usuwa z kolejności tabulacji. Bez `inert` odnośniki
     * z niewidocznego panelu są osiągalne, a wizualnie wszystko wygląda
     * poprawnie: widać to dopiero tabulatorem albo testem.
     */
    /**
     * Szerokość JEDNEGO panelu w pikselach.
     *
     * Przy poszerzaniu kadr zmienia szerokość, więc „100% kadru" przestaje być
     * stałą i panele nie mogą się już od niej liczyć — inaczej rosłyby razem
     * z kadrem i nigdy by się nie ułożyły obok siebie.
     *
     * Mierzymy sondą wstawioną do POWŁOKI, nie do kadru: powłoka ma rozmiar
     * okna, więc `100%` w `min(420px, 100%)` znaczy to, co miało znaczyć.
     * Sonda w kadrze liczyłaby procent od szerokości, której właśnie szukamy.
     * Kadru przy tym nie dotykamy — zmiana jego szerokości uruchomiłaby
     * przejście i przy każdym przeliczeniu widać byłoby drgnięcie.
     */
    var panelPx = 0;

    function measurePanel() {
        var probe = document.createElement('div');
        probe.style.cssText = 'position:absolute;top:0;left:0;height:0;visibility:hidden;'
            + 'pointer-events:none;width:var(--evk-oc-size, min(420px, 100%))';
        shell.appendChild(probe);
        panelPx = Math.round(probe.getBoundingClientRect().width);
        shell.removeChild(probe);
        if (panelPx > 0) shell.style.setProperty('--evk-oc-panel-basis', panelPx + 'px');
        return panelPx;
    }

    function applyState() {
        var cur = stack[stack.length - 1];

        if (expand) {
            if (!panelPx) measurePanel();

            // Ile paneli mieści się w oknie. Na wąskim ekranie wychodzi jeden
            // i całość wraca do zachowania „rodzic wyjeżdża całkiem" — inaczej
            // menu byłoby szersze niż ekran.
            var fit = Math.max(1, Math.floor(window.innerWidth / (panelPx || 1)));
            var vis = Math.min(stack.length, fit);

            frame.style.width = (vis * panelPx) + 'px';
            // Gdy ścieżka nie mieści się w oknie, pokazujemy jej OGON —
            // najgłębsze panele, bo to na nich się właśnie jest.
            track.style.transform = 'translateX(' + (-(stack.length - vis) * panelPx) + 'px)';

            /* Kolejność na taśmie to kolejność ŚCIEŻKI, nie kolejność w DOM.
               Ścieżka potrafi przeskakiwać (0 → 2 → 1), a wtedy układ z DOM
               pokazałby panele w złej kolejności albo z dziurą pośrodku.
               Panele spoza ścieżki chowamy — nie mają czego zajmować miejsca. */
            panels.forEach(function (p, i) {
                var at = stack.indexOf(i);
                p.style.order   = at < 0 ? '' : String(at);
                p.style.display = at < 0 ? 'none' : '';
                p.classList.toggle('is-current', i === cur);
                // Panele ścieżki ZOSTAJĄ dostępne — o to w tym trybie chodzi,
                // rodzic jest widoczny i klikalny. Odcinamy tylko te spoza niej.
                if (at < 0) p.setAttribute('inert', '');
                else        p.removeAttribute('inert');
            });
            return;
        }

        track.style.transform = 'translateX(' + (-cur * 100) + '%)';
        panels.forEach(function (p, i) {
            var isCur = i === cur;
            p.classList.toggle('is-current', isCur);
            if (isCur) p.removeAttribute('inert');
            else       p.setAttribute('inert', '');
        });
    }

    function focusables(el) {
        return Array.prototype.filter.call(
            el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (n) { return n.offsetParent !== null || n === document.activeElement; }
        );
    }

    function focusFirst(i) {
        var f = focusables(panels[i]);
        if (f.length) f[0].focus();
    }

    /* Zasięg pułapki fokusu. Przy poszerzaniu rodzic ZOSTAJE widoczny
       i klikalny, więc pułapka obejmuje cały kadr — zamknięcie jej
       w bieżącym panelu odcinałoby tabulatorem to, co widać na ekranie.
       W trybie „rodzic wyjeżdża" widać dokładnie jeden panel i tylko on. */
    function trapScope() {
        return expand ? frame : panels[stack[stack.length - 1]];
    }

    // ── Otwieranie i zamykanie ─────────────────────────────────────────────
    var lastTrigger = null;
    var scrollLocked = false;

    function lock() {
        if (!lockScroll || scrollLocked) return;
        // Kompensata szerokości paska przewijania. Bez niej `overflow: hidden`
        // przesuwa całą stronę o kilkanaście pikseli i widać to jako skok
        // w chwili otwarcia — na desktopie z paskiem zajmującym miejsce.
        var gap = window.innerWidth - document.documentElement.clientWidth;
        if (gap > 0) document.body.style.paddingRight = gap + 'px';
        document.documentElement.classList.add('evk-oc-locked');
        scrollLocked = true;
    }

    function unlock() {
        if (!scrollLocked) return;
        document.documentElement.classList.remove('evk-oc-locked');
        document.body.style.paddingRight = '';
        scrollLocked = false;
    }

    /**
     * Animacje wejściowe w treści menu odgrywamy PRZY KAŻDYM otwarciu.
     *
     * Wyzwalacz „wejście w kadr" jest z definicji jednorazowy — strona
     * przewija się w jedną stronę, więc ScrollTrigger po pierwszym wejściu
     * kończy pracę. W panelu, który się otwiera i zamyka, to założenie nie
     * obowiązuje: animacja grała raz na całe życie strony i przy drugim
     * otwarciu treść po prostu była. Zgłoszone z użycia.
     */
    function replayAnimations() {
        if (typeof window.evkAnimatorReplay === 'function') {
            window.evkAnimatorReplay(frame);
        }
    }

    function open(trigger) {
        lastTrigger = trigger || null;
        stack = [startIdx];
        applyState();
        shell.classList.add('is-open');
        lock();
        setTrigAria(true);
        replayAnimations();
        focusFirst(stack[stack.length - 1]);
    }

    function close() {
        shell.classList.remove('is-open');
        unlock();
        setTrigAria(false);
        if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
    }

    function go(id, from) {
        var i = indexOfId(id);
        if (i < 0 || i === stack[stack.length - 1]) return;
        // Skąd przyszliśmy — zapamiętane NA PANELU DOCELOWYM, bo przy powrocie
        // pytamy panel, z którego wychodzimy.
        if (from) panels[i]._evkOcFrom = from;
        stack.push(i);
        applyState();
        // Podmenu też POKAZUJE treść — z tego samego powodu, co otwarcie.
        if (typeof window.evkAnimatorReplay === 'function') window.evkAnimatorReplay(panels[i]);
        focusFirst(i);
    }

    function back() {
        if (stack.length < 2) { close(); return; }
        var leaving = panels[stack.pop()];
        applyState();
        // Fokus wraca NA POZYCJĘ, z której się weszło — nie na początek listy.
        // Bez tego „wstecz" gubi miejsce w menu przy każdym użyciu.
        var origin = leaving._evkOcFrom;
        if (origin && document.contains(origin)) origin.focus();
        else focusFirst(stack[stack.length - 1]);
    }

    // ── Triggery ───────────────────────────────────────────────────────────
    var triggers = Array.prototype.slice.call(root.querySelectorAll('.evk-oc-trigger'));
    var extraSel = root.getAttribute('data-trigger') || '';
    if (extraSel) {
        try {
            triggers = triggers.concat(Array.prototype.slice.call(document.querySelectorAll(extraSel)));
        } catch (e) {
            console.warn('[EVK Offcanvas] Nieprawidłowy selektor triggera:', extraSel, root);
        }
    }

    function setTrigAria(isOpen) {
        triggers.forEach(function (t) { t.setAttribute('aria-expanded', isOpen ? 'true' : 'false'); });
    }
    setTrigAria(false);

    triggers.forEach(function (t) {
        t.addEventListener('click', function (e) { e.preventDefault(); open(t); });
    });

    scrim.addEventListener('click', close);

    shell.addEventListener('click', function (e) {
        var goEl = e.target.closest('[data-evk-oc-go]');
        if (goEl) { e.preventDefault(); go(goEl.getAttribute('data-evk-oc-go'), goEl); return; }
        if (e.target.closest('[data-evk-oc-back]'))  { e.preventDefault(); back();  return; }
        if (e.target.closest('[data-evk-oc-close]')) { e.preventDefault(); close(); return; }
        if (closeOnLink && e.target.closest('a[href]')) close();
    });

    document.addEventListener('keydown', function (e) {
        if (!shell.classList.contains('is-open')) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            // Esc cofa o JEDEN poziom; zamykanie z trzeciego panelu od razu
            // gubi kontekst. Na panelu startowym cofać nie ma dokąd — zamyka.
            if (escBack && stack.length > 1) back();
            else close();
            return;
        }

        if (e.key !== 'Tab') return;

        // Panele spoza ścieżki mają `inert`, więc wystarczy zawinąć listę
        // tego, co widać — patrz trapScope().
        var f = focusables(trapScope());
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    /* Zmiana rozmiaru okna zmienia i szerokość panelu (`min(420px, 100%)`),
       i to, ile paneli się mieści. Bez przeliczenia obrót telefonu zostawiał
       kadr w szerokości sprzed obrotu. */
    if (expand) {
        window.addEventListener('resize', function () {
            measurePanel();
            applyState();
        });
    }

    applyState();
    if (isBuilder && openBuilder) open(null);

    return true;
}

document.addEventListener('DOMContentLoaded', evk_offcanvas_menu_init);
