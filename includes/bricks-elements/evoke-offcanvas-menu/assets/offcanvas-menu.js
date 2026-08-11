/**
 * Evoke ONE — Offcanvas Menu
 *
 * `evk_offcanvas_menu_init()` wołane jest z dwóch stron: przez Bricks (patrz
 * $scripts w element.php) i przez własny DOMContentLoaded niżej. Flaga
 * `data-evk-oc-ready` pilnuje, żeby jeden korzeń zainicjalizował się raz —
 * bez niej nasłuchy stackowałyby się, a przy włączonym portalu drugi przebieg
 * nie znalazłby paneli (są już przeniesione do <body>).
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

    // Wspólna polityka ruchu wtyczki — patrz includes/anim/motion.php.
    var reduced = (window.evkMotion && typeof window.evkMotion.reduced === 'function')
        ? window.evkMotion.reduced()
        : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    var duration = reduced ? 0 : (parseFloat(root.getAttribute('data-duration')) || 0.35);
    var easing   = root.getAttribute('data-easing') || '';

    // ── Konstrukcja powłoki ────────────────────────────────────────────────
    var shell = document.createElement('div');
    shell.className = 'evk-oc-shell';
    shell.setAttribute('role', 'dialog');
    shell.setAttribute('aria-modal', 'true');
    shell.hidden = false;

    var scrim = document.createElement('div');
    scrim.className = 'evk-oc-scrim';

    var wrap = document.createElement('div');
    wrap.className = 'evk-oc-panels';

    panels.forEach(function (p) { wrap.appendChild(p); });
    shell.appendChild(scrim);
    shell.appendChild(wrap);

    root.style.setProperty('--evk-oc-time', duration + 's');
    if (easing) root.style.setProperty('--evk-oc-ease', easing);

    /* Portal do <body> przenosi POWŁOKĘ, ale styl zależy od `data-side`
       na korzeniu — dlatego powłoka dostaje własną kopię tego atrybutu
       i klasę `.evk-oc`, żeby selektory `.evk-oc[data-side]` nadal trafiały. */
    if (usePortal && !isBuilder) {
        shell.classList.add('evk-oc');
        shell.setAttribute('data-side', root.getAttribute('data-side') || 'right');
        document.body.appendChild(shell);
    } else {
        root.appendChild(shell);
    }

    // ── Identyfikacja paneli ───────────────────────────────────────────────
    // ID z atrybutu `data-panel`, a gdy go nie ma — kolejność. Numer jest
    // gorszym identyfikatorem (przestawienie paneli zrywa odnośniki), ale
    // pozwala zacząć bez konfiguracji.
    function panelId(p, i) { return p.getAttribute('data-panel') || String(i); }

    function findPanel(id) {
        for (var i = 0; i < panels.length; i++) {
            if (panelId(panels[i], i) === String(id)) return panels[i];
        }
        return null;
    }

    var start = (startId && findPanel(startId)) || panels[0];
    var stack = [start];

    // ── Dostępność: co jest osiągalne tabulatorem ──────────────────────────
    /**
     * Panel wysunięty poza ekran przez `transform` NADAL łapie fokus.
     * `translateX(-100%)` nie usuwa niczego z kolejności tabulacji, więc bez
     * `inert` odnośniki z panelu, którego nie widać, są wciąż osiągalne —
     * wizualnie wszystko wygląda poprawnie i widać to dopiero tabulatorem.
     */
    function applyState() {
        var current = stack[stack.length - 1];
        panels.forEach(function (p) {
            var isCurrent = p === current;
            var isOut     = stack.indexOf(p) !== -1 && !isCurrent;
            p.classList.toggle('is-current', isCurrent);
            p.classList.toggle('is-out', isOut);
            if (isCurrent) p.removeAttribute('inert');
            else           p.setAttribute('inert', '');
        });
    }

    function focusables(el) {
        return Array.prototype.filter.call(
            el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (n) { return n.offsetParent !== null || n === document.activeElement; }
        );
    }

    // ── Otwieranie i zamykanie ─────────────────────────────────────────────
    var lastTrigger = null;
    var scrollLocked = false;

    function lock() {
        if (!lockScroll || scrollLocked) return;
        // Kompensata szerokości paska przewijania. Bez niej `overflow: hidden`
        // przesuwa całą stronę o kilkanaście pikseli i widać to jako skok
        // w chwili otwarcia — na desktopie z widocznym paskiem.
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

    function open(trigger) {
        lastTrigger = trigger || null;
        stack = [start];
        applyState();
        shell.classList.add('is-open');
        lock();
        setTrigAria(true);
        var f = focusables(stack[stack.length - 1]);
        if (f.length) f[0].focus();
    }

    function close() {
        shell.classList.remove('is-open');
        unlock();
        setTrigAria(false);
        if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
    }

    function go(id) {
        var next = findPanel(id);
        if (!next || next === stack[stack.length - 1]) return;
        stack.push(next);
        applyState();
        var f = focusables(next);
        if (f.length) f[0].focus();
    }

    function back() {
        if (stack.length < 2) { close(); return; }
        var leaving = stack.pop();
        applyState();
        // Fokus wraca NA POZYCJĘ, z której się weszło — nie na początek listy.
        // Bez tego „wstecz" gubi miejsce w menu przy każdym użyciu.
        var origin = leaving._evkOcFrom;
        if (origin && document.contains(origin)) origin.focus();
        else {
            var f = focusables(stack[stack.length - 1]);
            if (f.length) f[0].focus();
        }
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

    function setTrigAria(open) {
        triggers.forEach(function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    }
    setTrigAria(false);

    triggers.forEach(function (t) {
        t.addEventListener('click', function (e) { e.preventDefault(); open(t); });
    });

    scrim.addEventListener('click', close);

    shell.addEventListener('click', function (e) {
        var goEl = e.target.closest('[data-evk-oc-go]');
        if (goEl) {
            e.preventDefault();
            // Zapamiętane NA PANELU DOCELOWYM, nie na źródłowym: przy powrocie
            // pytamy panel, z którego wychodzimy, skąd się wzięliśmy.
            var target = findPanel(goEl.getAttribute('data-evk-oc-go'));
            if (target) target._evkOcFrom = goEl;
            go(goEl.getAttribute('data-evk-oc-go'));
            return;
        }
        if (e.target.closest('[data-evk-oc-back]')) { e.preventDefault(); back(); return; }
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

        // Pułapka fokusu w bieżącym panelu. Panele niebieżące mają `inert`,
        // więc wystarczy zawinąć listę tego jednego.
        var f = focusables(stack[stack.length - 1]);
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    applyState();
    if (isBuilder && openBuilder) open(null);

    return true;
}

document.addEventListener('DOMContentLoaded', evk_offcanvas_menu_init);
