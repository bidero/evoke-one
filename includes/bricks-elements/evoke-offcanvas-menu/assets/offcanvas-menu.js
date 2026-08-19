/**
 * Evoke ONE — Offcanvas Menu
 *
 * Dwa NIEZALEŻNE ruchy, każdy z własnym czasem i krzywą:
 *
 *   KADR  (.evk-oc-frame) — wysuwa się z krawędzi ekranu; to otwieranie menu.
 *   TAŚMA (.evk-oc-track) — przesuwa się w poziomie w kadrze; to przechodzenie
 *                           między panelami.
 *
 * Panele leżą OBOK SIEBIE, nie jeden na drugim: wejście w podmenu WYPYCHA panel
 * główny, zamiast go zasłaniać.
 *
 * Przy poszerzaniu (domyślnym) kolumny są DWIE i tylko dwie: panel główny
 * i jeden podrzędny. Wejście w kolejne podmenu PODMIENIA podrzędny — poprzedni
 * wyjeżdża tam, skąd przyjechał — a „wstecz" i Esc wracają wprost do głównego.
 * Stos poziomów dawał stany, z których cofało się po jednym kroku zamiast tam,
 * skąd widać całe menu.
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

/**
 * Przodek, który zamyka `position: fixed` w swoim pudełku — albo `null`.
 *
 * `fixed` liczy się od OKNA tylko wtedy, gdy nikt po drodze nie tworzy dla
 * niego bloku zawierającego. Tworzy go `transform`, `perspective`, `filter`,
 * `backdrop-filter`, `contain` i `will-change` zapowiadające którąkolwiek
 * z tych rzeczy. W nagłówku to nie jest egzotyka: przyklejony nagłówek,
 * animacja wejścia z Animatora, cudzy efekt na sekcji.
 */
function evk_oc_przodek_blokujacy(el) {
    for (var a = el.parentElement; a && a !== document.documentElement; a = a.parentElement) {
        var cs = getComputedStyle(a);
        if (cs.transform !== 'none') return a;
        if (cs.perspective && cs.perspective !== 'none') return a;
        if (cs.filter && cs.filter !== 'none') return a;
        if (cs.backdropFilter && cs.backdropFilter !== 'none') return a;
        if (cs.contain && /paint|layout|strict|content/.test(cs.contain)) return a;
        if (cs.willChange && /transform|perspective|filter|contain/.test(cs.willChange)) return a;
    }
    return null;
}

/**
 * Mówi wprost, gdy menu nie ma jak rozciągnąć się na całe okno.
 *
 * Bez tego objaw jest nie do rozszyfrowania z zewnątrz: menu otwiera się
 * w prostokącie nagłówka i nic w ustawieniach elementu tego nie tłumaczy.
 * Przyczyna leży u KOGOŚ INNEGO w drzewie, więc wskazujemy winowajcę
 * z nazwiska i podpowiadamy wyjście.
 */
function evk_oc_ostrzez_o_blokadzie(shell, usePortal) {
    var winowajca = evk_oc_przodek_blokujacy(shell);
    if (!winowajca) return;

    var kto = winowajca.tagName.toLowerCase()
        + (winowajca.id ? '#' + winowajca.id : '')
        + (winowajca.className && typeof winowajca.className === 'string'
            ? '.' + winowajca.className.trim().split(/\s+/).join('.') : '');

    console.warn('[EVK Offcanvas] Menu nie rozciągnie się na całe okno — przodek '
        + kto + ' tworzy blok zawierający dla `position: fixed` '
        + '(transform, filter, perspective, contain albo will-change). '
        + (usePortal
            ? 'Powłoka jest już w <body>, więc blokada siedzi wyżej — na <body> albo na jego opakowaniu.'
            : 'Włącz „Przenieś do <body>" w ustawieniach elementu.'),
        winowajca);
}

/**
 * Usuwa powłoki po korzeniach, których już nie ma w dokumencie.
 *
 * Bricks PRZERYSOWUJE element przy każdej zmianie ustawienia — podmienia węzeł
 * korzenia na świeży. Póki powłoka siedziała w korzeniu, znikała razem z nim;
 * powłoka w <body> korzenia nad sobą nie ma i zostawałaby po każdej edycji.
 * Po kilkunastu kliknięciach w kanwie leżałby stos martwych menu z nieaktualną
 * treścią, a każde z nich na `z-index: 99990`.
 *
 * Wiązanie idzie WŁASNYM identyfikatorem, nie `id` Bricksa: nasz ginie razem
 * z przerysowanym korzeniem i właśnie po tym poznajemy, że powłoka osierociała.
 */
function evk_oc_sprzatnij_osierocone() {
    document.querySelectorAll('.evk-oc-shell[data-evk-oc-owner]').forEach(function (s) {
        var uid = s.getAttribute('data-evk-oc-owner');
        if (!document.querySelector('[data-evk-oc-uid="' + uid + '"]')) s.remove();
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
    var animExit    = root.getAttribute('data-anim-exit') === '1';

    /**
     * Klasa, którą Bricks zakłada po otwarciu — I PRZYCISKOWI, I OTWIERANEMU
     * ELEMENTOWI.
     *
     * Cała animacja burgera zbudowanego w Bricksie — kreski składające się
     * w krzyżyk — wisi w arkuszu na tej klasie. Do 1.73.0 nakładaliśmy ją
     * WYŁĄCZNIE na przycisk, i to była połowa roboty: Bricks trzyma stan na
     * elemencie, który otwiera, a wygląd przełącznika bywa z niego wyprowadzony
     * — regułą typu `.brx-open .brxe-toggle` albo własną logiką przełącznika,
     * która pyta o stan CELU. Nasze menu nie zgłaszało się tam w ogóle:
     * `is-open` to nazwa Evoke, dla Bricksa nic nie znacząca.
     *
     * Deklaracja stoi TU, przy reszcie konfiguracji, a nie przy triggerach —
     * czyta ją i `open()`, i `setTrigAria()`, więc wisiała na kolejności
     * wykonania w pliku.
     */
    var BRICKS_OPEN = 'brx-open';

    /**
     * Klasy stanu nakładane PRZEŁĄCZNIKOWI. Nie jedna — bo konwencji jest kilka
     * i żadna nie jest „tą właściwą".
     *
     * `brx-open` zakłada Bricks swoim otwartym elementom. `is-active` to
     * konwencja samych burgerów i na niej stoi większość gotowych animacji
     * kresek. Nakładamy OBIE, zamiast zgadywać, która obowiązuje: klasa stanu
     * na przycisku, którego rolą jest otwieranie menu, nie ma jak zaszkodzić.
     *
     * Trzecia konwencja — `<pierwsza-klasa>--opened` — jest wyliczana z klas
     * przycisku. Czwartą i dalsze dopisuje się w kontrolce, bez ruszania kodu.
     */
    var TOGGLE_OPEN = [BRICKS_OPEN, 'is-active'];

    var toggleOpenClasses = TOGGLE_OPEN.concat(
        (root.getAttribute('data-toggle-class') || '')
            .split(/[\s,]+/)
            .filter(function (c) { return c && TOGGLE_OPEN.indexOf(c) < 0; })
    );
    /* Ile czekać z wyjazdem kadru na wyjście treści. `null` znaczy „cały czas
       animacji" (ruchy jeden PO drugim), liczba — tyle sekund, ile podano.
       Zero to nie brak ustawienia, tylko wybór: kadr wyjeżdża RAZEM
       z wychodzącą treścią. Stąd null zamiast domyślki — `|| 0` zjadałoby
       jawne zero. */
    var exitWaitRaw = root.getAttribute('data-exit-wait');
    var exitWait    = (exitWaitRaw === null || exitWaitRaw === '')
        ? null : parseFloat(exitWaitRaw);
    if (isNaN(exitWait)) exitWait = null;
    var usePortal   = root.getAttribute('data-portal') === '1';
    var startId     = root.getAttribute('data-start') || '';
    var side        = root.getAttribute('data-side') || 'right';

    /**
     * Jak menu wchodzi na ekran.
     *
     *   'slide'  — kadr wysuwa się zza krawędzi i WWOZI TREŚĆ ZE SOBĄ.
     *              Zachowanie od 1.56.0 i domyślne, żeby nikomu nic nie
     *              podmieniać pod ręką.
     *   'reveal' — treść stoi w miejscu, a przesuwa się sama płaszczyzna
     *              panelu: jego krawędź przejeżdża po treści i ją ODSŁANIA.
     *              Robi to przeciw-transformacja na `.evk-oc-hold` — cała
     *              w arkuszu, patrz offcanvas-menu.css.
     */
    var reveal      = root.getAttribute('data-effect') === 'reveal';

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

    /**
     * Odstęp między poszerzeniem kadru a wjazdem panelu podrzędnego.
     *
     * Bez niego oba ruchy zaczynają i kończą się równo, więc nie widać, co po
     * czym następuje — całość wygląda sztywno. Z opóźnieniem najpierw robi się
     * miejsce, a potem dopiero panel dojeżdża. Zgłoszone z użycia.
     *
     * Puste pole znaczy „45% czasu przejścia", a nie stałą liczbę sekund:
     * przy czasie 0,9 s stałe 0,15 s byłoby ledwie zauważalne, a przy 0,15 s
     * zjadałoby całe przejście. Ułamek trzyma proporcję niezależnie od tempa.
     */
    var subDelay = reduced ? 0 : num('data-sub-delay', trackTime * 0.45);

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

    // ── Powłoka ────────────────────────────────────────────────────────────
    var shell = document.createElement('div');
    shell.className = 'evk-oc-shell is-side-' + side + (reveal ? ' is-reveal' : '');
    shell.setAttribute('role', 'dialog');
    shell.setAttribute('aria-modal', 'true');

    var scrim = document.createElement('div');
    scrim.className = 'evk-oc-scrim';

    var frame = document.createElement('div');
    frame.className = 'evk-oc-frame';

    /* Opakowanie treści — nosiciel PRZECIW-TRANSFORMACJI przy odsłanianiu.
     *
     * Osobny węzeł, bo transform na taśmie ma już właściciela: applyState()
     * wpisuje jej `style.transform` przy przechodzeniu między panelami, a styl
     * w atrybucie wygrywa z arkuszem. Dwie rzeczy na jednym transformie to
     * dokładnie ta klasa usterek, którą ten element zbierał przez cztery rundy
     * w 1.62.0–1.65.1.
     *
     * Powstaje ZAWSZE, w obu efektach — jedna ścieżka zamiast gałęzi. Przy
     * wysuwaniu nie ma transformacji i jest dla układu przezroczysty. */
    var hold = document.createElement('div');
    hold.className = 'evk-oc-hold';

    var track = document.createElement('div');
    track.className = 'evk-oc-track';

    /* Panel główny siedzi WPROST na taśmie, wszystkie podrzędne w slocie —
       i to jest podział stały, nie zależny od stanu: ścieżka zawsze zaczyna
       się od panelu startowego, więc żaden panel nie zmienia roli w trakcie.
       Slot przycina, dzięki czemu cofający się poziom nie ma czym wyjść na
       panel główny.
       Tylko przy poszerzaniu — w trybie „rodzic wyjeżdża całkiem" panele leżą
       obok siebie na taśmie i drugie pudełko nie ma co robić. */
    var slot = null;
    if (expand) {
        slot = document.createElement('div');
        slot.className = 'evk-oc-slot';
    }

    panels.forEach(function (p, i) {
        if (slot && i !== startIdx) { slot.appendChild(p); return; }
        track.appendChild(p);
    });
    if (slot) track.appendChild(slot);
    hold.appendChild(track);
    frame.appendChild(hold);
    shell.appendChild(scrim);
    shell.appendChild(frame);

    /* Zmienne NA POWŁOCE, nie na korzeniu. Powłoka jedzie do <body>, więc
       przestaje być potomkiem korzenia i nic z niego nie dziedziczy —
       ustawione tylko na korzeniu nie docierały do kadru ani taśmy i oba
       brały wartości zapasowe z arkusza. */
    shell.style.setProperty('--evk-oc-time', frameTime + 's');
    shell.style.setProperty('--evk-oc-panel-time', trackTime + 's');
    shell.style.setProperty('--evk-oc-sub-delay', subDelay + 's');
    if (frameEase) shell.style.setProperty('--evk-oc-ease', frameEase);
    if (trackEase) shell.style.setProperty('--evk-oc-panel-ease', trackEase);
    // Wartości z kontrolek `css` builder zapisuje NA KORZENIU, a powłoka jedzie
    // do <body> i przestaje po nim dziedziczyć — trzeba je przepisać.
    ['--evk-oc-size', '--evk-oc-bg', '--evk-oc-scrim'].forEach(function (v) {
        var val = getComputedStyle(root).getPropertyValue(v);
        if (val && val.trim()) shell.style.setProperty(v, val.trim());
    });

    /* Portal działa TAKŻE W BUILDERZE — do 1.97.1 warunek brzmiał
       `usePortal && !isBuilder` i kontrolka „Przenieś do <body>" była na kanwie
       martwa. Zgłoszone z użycia: menu wstawione w nagłówku otwierało się
       „w świetle tego bloku", a nie na całym ekranie, i przełączanie kontrolki
       nic nie dawało — bo nic nie czytało.

       Powód jest w bloku zawierającym: `position: fixed` liczy się od okna
       tylko wtedy, gdy żaden przodek nie tworzy dla niego bloku zawierającego.
       Robi to `transform`, `filter`, `perspective`, `contain` i `will-change`
       — czyli rzeczy, które w nagłówku zdarzają się stale: przyklejony
       nagłówek, animacja wejścia z Animatora, cudzy efekt. Na froncie problem
       nie występował, bo tam powłoka i tak jechała do <body> i zostawiała
       takiego przodka za sobą.

       Cena jest jedna i trzeba ją znać: panel przeniesiony do <body> przestaje
       być potomkiem węzła elementu, więc Bricks szuka go po `id`, a nie po
       pokrewieństwie. Za to menu wygląda na kanwie tak, jak będzie wyglądać
       na stronie — a to jest sens edycji na żywo. */
    /* Własny identyfikator korzenia i jego odcisk na powłoce. Po nich poznajemy
       powłokę osierociałą przez przerysowanie — patrz komentarz przy
       evk_oc_sprzatnij_osierocone(). Losowy, bo `id` Bricksa ginie razem
       z węzłem i nie odróżniłby korzenia sprzed edycji od tego po niej. */
    var uid = root.dataset.evkOcUid;
    if (uid) {
        // Ten sam korzeń drugi raz — zabieramy jego poprzednią powłokę,
        // zanim zbudujemy nową. `data-evk-oc-ready` zwykle do tego nie
        // dopuszcza, ale zależeć od tego nie musimy.
        var stara = document.querySelector('.evk-oc-shell[data-evk-oc-owner="' + uid + '"]');
        if (stara) stara.remove();
    } else {
        uid = 'oc' + Math.random().toString(36).slice(2, 10);
        root.dataset.evkOcUid = uid;
    }
    shell.setAttribute('data-evk-oc-owner', uid);
    evk_oc_sprzatnij_osierocone();

    if (usePortal) document.body.appendChild(shell);
    else           root.appendChild(shell);

    evk_oc_ostrzez_o_blokadzie(shell, usePortal);


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

    /**
     * Tło kadru dopasowane do panelu, na którym się właśnie jest.
     *
     * Kadr poszerza się od razu, a panel podrzędny dojeżdża z opóźnieniem —
     * przez ten moment widać pasek gołego kadru. Domyślna biel rzucała się
     * w oczy przy ciemnym menu (zgłoszone przy zamykaniu drugiego panelu),
     * a odgadywać tego użytkownik nie ma po co: bierzemy kolor wprost z panelu.
     *
     * Kontrolka „Tło menu" wygrywa, gdy jest ustawiona — dlatego nie ma
     * domyślnej wartości. Ustawiona z automatu wygrałaby zawsze i to
     * dopasowanie nigdy by się nie odpaliło.
     */
    var bgFromBuilder = !!(getComputedStyle(root).getPropertyValue('--evk-oc-bg') || '').trim();

    function syncFrameBg() {
        if (bgFromBuilder) return;
        var p  = panels[stack[stack.length - 1]];
        var bg = p && getComputedStyle(p).backgroundColor;
        // Panel bez własnego tła nie ma czym się podzielić — zostaje domyślne
        // z arkusza. Podstawienie przezroczystości odsłaniałoby stronę.
        if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
            frame.style.background = bg;
        }
    }

    /* Panele w trakcie WYJAZDU. Nie są już w ścieżce, ale nadal się rysują —
       gdyby znikały od razu, kadr zwężałby się nad pustym miejscem i widać
       byłoby jego gołe tło. To był zgłoszony „biały kolor przy zamykaniu". */
    var leavingSet = [];

    /**
     * @param {boolean} slideOut Czy panel ma odjechać SAM.
     *
     * Odjeżdża sam tylko wtedy, gdy pod nim został inny podrzędny: kadr się
     * wtedy nie zmienia, więc bez własnego ruchu zostałby na wierzchu.
     * Przy powrocie do panelu głównego jest odwrotnie — wynosi go zwężający
     * się kadr, a własny transform tylko odsłoniłby jego tło.
     */
    function startLeaving(p, slideOut) {
        if (leavingSet.indexOf(p) < 0) leavingSet.push(p);
        p.classList.remove('is-current', 'is-away');
        p.classList.add(slideOut ? 'is-leaving' : 'is-carried');
        p.setAttribute('inert', '');

        /* Sprzątamy po CZASIE, nie po `transitionend`. Przy wyłączonym ruchu
           przejścia nie ma wcale, więc zdarzenie nigdy by nie przyszło i panel
           zostałby narysowany na zawsze. */
        setTimeout(function () {
            var k = leavingSet.indexOf(p);
            if (k >= 0) leavingSet.splice(k, 1);
            // Mógł w międzyczasie wrócić do ścieżki — wtedy nic mu nie robimy.
            if (stack.indexOf(panels.indexOf(p)) >= 0) return;
            p.style.display = 'none';
            p.classList.remove('is-leaving', 'is-carried', 'is-sub');
        }, trackTime * 1000 + subDelay * 1000 + 80);
    }

    /* Wjazd panelu podrzędnego: postawić go poza slotem BEZ animowania tego
       kroku, a dopiero potem puścić do środka. Bez `is-instant` panel najpierw
       przejechałby do punktu wyjścia, a stamtąd z powrotem. */
    function startEntering(p) {
        p.classList.add('is-sub', 'is-away', 'is-instant');
        p.style.display = '';
        void p.offsetWidth;
        p.classList.remove('is-instant');
        void p.offsetWidth;
        p.classList.remove('is-away');
    }

    /**
     * @param {number} [entering] Indeks panelu, który WŁAŚNIE wszedł do ścieżki.
     *                            Tylko on dostaje animację wjazdu.
     */
    function applyState(entering) {
        var cur = stack[stack.length - 1];

        if (expand) {
            if (!panelPx) measurePanel();

            // Ile paneli mieści się w oknie. Na wąskim ekranie wychodzi jeden
            // i całość wraca do zachowania „rodzic wyjeżdża całkiem" — inaczej
            // menu byłoby szersze niż ekran.
            var fit = Math.max(1, Math.floor(window.innerWidth / (panelPx || 1)));
            /* Kolumny są dwie i tylko dwie, bo ścieżka ma najwyżej dwa kroki:
               panel główny i JEDEN podrzędny. Wejście w kolejne podmenu
               PODMIENIA podrzędny, a nie dokłada poziom — patrz go(). */
            var used = stack.length;
            var cols = Math.min(used, fit);

            frame.style.width = (cols * panelPx) + 'px';
            syncFrameBg();
            /* Gdy druga kolumna nie mieści się w oknie, pokazujemy tę, na
               której się właśnie jest — czyli podrzędną. Kolumny leżą w tej
               samej kolejności przy obu stronach, więc i przesunięcie jest to
               samo; menu z lewej różni się WYŁĄCZNIE kierunkiem wjazdu panelu
               (patrz arkusz). */
            track.style.transform = 'translateX(' + (-(used - cols) * panelPx) + 'px)';

            panels.forEach(function (p, i) {
                var at = stack.indexOf(i);

                if (at < 0) {
                    // Panel w trakcie wyjazdu ma dojechać — nie dotykamy go.
                    if (leavingSet.indexOf(p) >= 0) return;
                    p.style.display = 'none';
                    p.classList.remove('is-current', 'is-away', 'is-leaving', 'is-carried');
                    p.setAttribute('inert', '');
                    return;
                }

                p.classList.remove('is-leaving', 'is-carried');
                p.classList.toggle('is-current', i === cur);

                if (at === 0) {
                    // Panel główny zostaje zwykłym elementem taśmy.
                    p.style.display = '';
                    p.classList.remove('is-sub', 'is-away');
                } else if (i === entering) {
                    startEntering(p);
                } else {
                    p.classList.add('is-sub');
                    p.style.display = '';
                }

                /* Dostępne zostaje to, co widać. Przy dwóch kolumnach są to
                   OBA panele — o to w tym trybie chodzi, główny jest widoczny
                   i klikalny. Przy jednej kolumnie główny wyjechał poza okno
                   i tabulator nie ma czego w nim szukać. */
                if (at < used - cols) p.setAttribute('inert', '');
                else                  p.removeAttribute('inert');
            });
            return;
        }

        /* Oś zależy od krawędzi, z której wychodzi menu. Menu z góry i z dołu
           układa panele W PIONIE (patrz `flex-direction: column` w arkuszu),
           więc przesuwanie ich w poziomie wysyłało podmenu bokiem — wyjeżdżało
           z zupełnie innej strony niż samo menu. Zgłoszone z użycia. */
        var axis = (side === 'top' || side === 'bottom') ? 'Y' : 'X';
        track.style.transform = 'translate' + axis + '(' + (-cur * 100) + '%)';
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

    /* `preventScroll` jest tu OBOWIĄZKOWE, nie kosmetyczne. Domyślne
       `focus()` przewija przodków tak, żeby pokazać element — a panel, do
       którego właśnie przechodzimy, stoi w tej chwili poza kadrem. Bez tego
       przeglądarka przewijała kadr i cały układ paneli się rozjeżdżał.
       Arkusz zamyka tę drogę drugi raz (`overflow: clip`); jedno i drugie,
       bo `clip` nie działa w każdej przeglądarce. */
    function focusFirst(i) {
        var f = focusables(panels[i]);
        if (f.length) f[0].focus({ preventScroll: true });
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

    /* Blokadę przewijania trzyma WSPÓLNY zamek (includes/96-scroll-lock.php),
       a nie ten element. Tutejsza wersja blokowała naprawdę (klasa plus
       kompensata paska), ale nie zatrzymywała płynnego przewijania: dokument
       stał, a Lenis przewijał dalej swoją wirtualną pozycję i po zamknięciu
       obie się nie zgadzały. Zgłoszone z użycia jako „strona się blokuje
       czasami przy przewijaniu".

       Kompensata paska i klasa nie zniknęły — przeniosły się do zamka, razem
       z komentarzem, który je tłumaczy. Zysk jest w tym, że jest jedno miejsce,
       które wie, ILU trzymających jeszcze zostało: dwa panele otwarte naraz to
       realny przypadek, a każdy z osobna nie ma jak tego wiedzieć. */
    function lock() {
        if (!lockScroll || !window.evkScroll) return;
        window.evkScroll.lock('offcanvas');
    }

    function unlock() {
        if (!window.evkScroll) return;
        window.evkScroll.unlock('offcanvas');
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

    /**
     * Górna granica czekania na wyjście treści, w sekundach — dla ścieżki
     * automatycznej. Animacja ustawiona na osiem sekund zostawiłaby menu
     * otwarte przez osiem sekund po kliknięciu ✕, a to już nie jest efekt,
     * tylko zawieszenie. Resztę treść dokańcza pod wyjeżdżającym kadrem.
     */
    var EXIT_MAX = 1;

    /**
     * Wyprowadza treść i zwraca, ile sekund czekać z wyjazdem KADRU.
     *
     * Animacja rusza tutaj, niezależnie od czekania — to są dwie różne rzeczy
     * i dopiero ich rozdzielenie pozwala puścić oba ruchy RAZEM. Zwrócone zero
     * nie znaczy „nic nie wychodzi", tylko „nie czekaj".
     */
    function exitAnimations() {
        if (!animExit || typeof window.evkAnimatorExit !== 'function') return 0;

        var trwa = window.evkAnimatorExit(frame);

        /* Nic nie wyszło — nie ma na co czekać, choćby ustawiono jawną wartość.
           Tędy idzie redukcja ruchu: silnik nie buduje wtedy żadnej osi. */
        if (trwa <= 0) return 0;

        // Jawna wartość wygrywa z czasem animacji — także zero i wartości
        // dłuższe od samej animacji.
        if (exitWait !== null) return exitWait;

        return Math.min(trwa, EXIT_MAX);
    }

    /* Uchwyt odłożonego zamknięcia. Bez niego drugi Esc (albo klik w tło
       w trakcie wychodzenia treści) startuje drugie zamknięcie, a otwarcie
       w tym oknie zostaje po chwili cofnięte przez zaległy zegar. */
    var closeTimer = null;

    function open(trigger) {
        if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        lastTrigger = trigger || null;
        stack = [startIdx];
        applyState();
        shell.classList.add('is-open');
        // Stan po naszemu na powłoce, po Bricksowemu na korzeniu. Korzeń, nie
        // powłoka: powłoka jedzie do <body> i przestaje być czymkolwiek
        // w okolicy przełącznika, a reguły Bricksa czytają stan przez
        // pokrewieństwo w drzewie.
        root.classList.add(BRICKS_OPEN);
        lock();
        setTrigAria(true);
        replayAnimations();
        focusFirst(stack[stack.length - 1]);
    }

    function close() {
        if (closeTimer) return;
        var wait = exitAnimations();
        // Kadr zostaje na miejscu przez czas wychodzenia treści — inaczej menu
        // wyjeżdżałoby razem z animacją i nie byłoby jej widać.
        if (wait > 0) {
            closeTimer = setTimeout(function () { closeTimer = null; finishClose(); }, wait * 1000);
            return;
        }
        finishClose();
    }

    function finishClose() {
        shell.classList.remove('is-open');
        root.classList.remove(BRICKS_OPEN);
        unlock();
        setTrigAria(false);
        if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
    }

    /**
     * Wejście w podmenu.
     *
     * Podrzędny jest ZAWSZE JEDEN. Wejście w kolejne podmenu przy otwartym
     * podrzędnym PODMIENIA go: poprzedni wyjeżdża w tę stronę, z której
     * przyjechał, a nowy wjeżdża na jego miejsce. Stos rósłby inaczej
     * w nieskończoność, a „wstecz" cofałoby po jednym poziomie zamiast
     * wracać tam, skąd widać całe menu.
     */
    function go(id, from) {
        var i = indexOfId(id);
        if (i < 0 || i === stack[stack.length - 1]) return;
        // Wejście na panel główny to po prostu powrót.
        if (expand && i === startIdx) { back(); return; }
        // Skąd przyszliśmy — zapamiętane NA PANELU DOCELOWYM, bo przy powrocie
        // pytamy panel, z którego wychodzimy.
        if (from) panels[i]._evkOcFrom = from;

        if (expand) {
            // Poprzedni podrzędny wyjeżdża SAM: kadr się nie zmienia, więc
            // nikt by go stąd nie wyniósł.
            if (stack.length > 1) startLeaving(panels[stack[1]], true);
            stack = [startIdx, i];
        } else {
            stack.push(i);
        }
        applyState(i);
        // Podmenu też POKAZUJE treść — z tego samego powodu, co otwarcie.
        if (typeof window.evkAnimatorReplay === 'function') window.evkAnimatorReplay(panels[i]);
        focusFirst(i);
    }

    /**
     * Powrót. Przy poszerzaniu wraca ZAWSZE do panelu głównego, bo podrzędny
     * jest zawsze jeden — nie ma poziomu pośredniego, do którego można by
     * cofnąć. To samo robi Esc.
     */
    function back() {
        if (stack.length < 2) { close(); return; }
        var leaving = panels[stack.pop()];
        /* Panel ma ODJECHAĆ, a nie zniknąć — inaczej kadr zwęża się nad pustym
           miejscem i po drodze widać jego własne tło. Własnego przesunięcia
           NIE dostaje: wynosi go zwężający się kadr, a szybszy własny ruch
           znów odsłoniłby tło. */
        if (expand) startLeaving(leaving, false);
        applyState();
        // Fokus wraca NA POZYCJĘ, z której się weszło — nie na początek listy.
        // Bez tego „wstecz" gubi miejsce w menu przy każdym użyciu.
        var origin = leaving._evkOcFrom;
        if (origin && document.contains(origin)) origin.focus({ preventScroll: true });
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

    /**
     * Element, któremu nakładamy stan otwarcia.
     *
     * Trigger bywa opakowaniem przycisku (tak go tworzy get_nestable_children)
     * ALBO samym przyciskiem (selektor zewnętrzny zwykle celuje wprost w burger).
     * `aria-expanded` należy do sterującego, a nie do pudełka wokół niego —
     * div z tym atrybutem nie jest dla czytnika ekranu żadnym przyciskiem.
     */
    /* `.brxe-toggle` obok znaczników HTML-owych i to nie jest ozdoba: burger
       Bricksa nie zawsze jest przyciskiem — bywa zwykłym divem bez roli,
       a to na nim wisi arkusz z animacją kresek. Bez tej pozycji klasa
       lądowała na opakowaniu, czyli o jeden poziom za wysoko. */
    var TOGGLE_SEL = 'button, a, [role="button"], .brxe-toggle';

    function toggleTarget(el) {
        if (el.matches(TOGGLE_SEL)) return el;
        return el.querySelector(TOGGLE_SEL) || el;
    }

    function setTrigAria(isOpen) {
        triggers.forEach(function (t) {
            var btn = toggleTarget(t);
            // Pierwsza WŁASNA klasa — nasze znaczniki stanu wypadają, inaczej
            // przełącznik bez klasy dorobiłby się „brx-open--opened".
            var firstClass = Array.prototype.filter.call(btn.classList, function (c) {
                return toggleOpenClasses.indexOf(c) < 0 && c.slice(-8) !== '--opened';
            })[0];
            if (firstClass) btn.classList.toggle(firstClass + '--opened', isOpen);
            toggleOpenClasses.forEach(function (c) { btn.classList.toggle(c, isOpen); });
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
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
