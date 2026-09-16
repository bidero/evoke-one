/**
 * Evoke ONE — Grain.
 *
 * Ziarno filmowe z shadera — na całe okno albo w jednej sekcji, przewijane
 * z treścią.
 *
 * KANWA JEST WIELKOŚCI OKNA, NIE DOKUMENTU — I NIE SEKCJI. Kanwa wysoka na całą stronę
 * wyglądałaby prościej i jest nie do przyjęcia: strona 10 000 px przy DPR 2 to
 * ~115 megapikseli zaplecza, czyli setki megabajtów pamięci karty. Wrażenie
 * przewijania robi PRZESUNIĘCIE WSPÓŁRZĘDNYCH w shaderze — obraz jest ten sam,
 * a koszt stały i niezależny od długości strony.
 *
 * FORMUŁA JEST TA SAMA CO W PRZEBIEGU POST-PROCESS FALI i to warunek, nie
 * wygoda: inaczej na jednej stronie byłyby dwa różne ziarna. Barwa wychodzi
 * PRZEMNOŻONA PRZEZ ALPHĘ, bo kontekst WebGL domyślnie tak ją czyta — pełna
 * biel przy alfie 0,08 rozjaśniłaby drobinę ośmiokrotnie i zamiast ziarna
 * wyszłyby białe placki.
 *
 * Zasięg „jedna sekcja" NIE ZMIENIA TEGO NIC: sekcja jest MASKĄ w shaderze,
 * a nie rozmiarem kanwy. Kanwa wielkości sekcji musiałaby jechać razem z nią
 * przy każdym przewinięciu, a przy sekcji wysokiej na kilka ekranów wracałby
 * dokładnie ten sam problem z pamięcią karty. Przy masce koszt zostaje stały
 * i niezależny od tego, jak wysoka jest sekcja.
 */
(function () {
    'use strict';

    var WIERZCHOLKI = [
        'attribute vec2 aPoz;',
        'void main() { gl_Position = vec4(aPoz, 0.0, 1.0); }',
    ].join('\n');

    var FRAGMENTY = [
        'precision mediump float;',
        'uniform vec2  uRozmiar;',
        'uniform float uIntensywnosc;',
        'uniform float uSeed;',
        'uniform float uPrzesuniecie;',
        /* Maska sekcji. `uDol` i `uGora` są we WSPÓŁRZĘDNYCH KANWY, liczonych
           od jej dołu — tak jak `gl_FragCoord.y`. Przeliczenie z prostokąta
           sekcji siedzi w `rysujRaz()`, bo tylko tam wiadomo, ile wynosi DPR. */
        'uniform float uOgranicz;',
        'uniform float uDol;',
        'uniform float uGora;',
        'uniform float uWtopienie;',
        'void main() {',
        /* Te same współrzędne 0..1 co `newUv` w shaderze fali. Sąsiednie piksele
           różnią się o ułamek, ale sinus pomnożony przez 43758 zamienia tę
           różnicę w niezależną losową wartość — stąd ziarno, a nie gradient. */
        '    vec2 uv = gl_FragCoord.xy / uRozmiar;',
        '    uv.y += uPrzesuniecie / uRozmiar.y;',
        '    float ziarno = fract(sin(dot(uv + uSeed, vec2(12.9898, 78.233))) * 43758.5453123);',
        '    float w = (ziarno - 0.5) * uIntensywnosc;',
        /* Jasne drobiny rozjaśniają, ciemne przyciemniają — symetrycznie, tak
           jak ziarno fali po 1.193.0. Barwa premnożona przez alphę. */
        '    float a = abs(w) * 2.0;',
        /* WTOPIENIE ROBI `smoothstep`, NIE GRADIENT W CSS-ie — bo maska ma
           działać na przezroczystości ziarna, a nie przyciemniać to, co pod
           spodem. Dwa progi: narastanie od dolnej krawędzi w górę i opadanie
           przy górnej.

           `max(…, 1.0)` NIE JEST OSTROŻNOŚCIĄ NA ZAPAS: przy wtopieniu zero
           oba progi `smoothstep` byłyby równe, a to w GLSL-u jest dzielenie
           przez zero i wynik zależny od sterownika. Jeden piksel przejścia
           wygląda jak twarda krawędź i jest policzalny. */
        '    float wt = max(uWtopienie, 1.0);',
        '    float m = smoothstep(uDol, uDol + wt, gl_FragCoord.y)',
        '            * (1.0 - smoothstep(uGora - wt, uGora, gl_FragCoord.y));',
        /* `mix` zamiast `if` — przy zasięgu „całe okno" maska ma nie istnieć,
           a rozgałęzienie w shaderze fragmentów kosztuje więcej niż mnożenie. */
        '    a *= mix(1.0, m, uOgranicz);',
        '    gl_FragColor = vec4(vec3(step(0.0, w)) * a, a);',
        '}',
    ].join('\n');

    /* JEDNA LICZBA, KTÓRA DECYDUJE O KOSZCIE CAŁEGO ELEMENTU.
     *
     * ZGŁOSZONE Z UŻYCIA: „Kiedy jest szum na całej stronie animacje animatora
     * się tną. Tzn często nie widać ich przy scrollu — tak jakby szum blokował
     * scrolltrigger". ScrollTrigger nie był blokowany: w pomiarze WSZYSTKIE
     * osiem wyzwalaczy zapalało się i dochodziło do pełnego krycia w każdym
     * wariancie. Zabrakło KLATEK, w których miałyby to pokazać.
     *
     * Zmierzone (okno 900x600, osiem osi czasu z `scrollTrigger`, mediana
     * odstępu klatek przeglądarki — patrz tests/grain-koszt.test.js):
     *
     *     sufit DPR │ klatek/s │ dławienie 4x │ 6x     │ najgorsza
     *     2         │ bez      │ 50,0 ms      │ 66,7   │ 67 ms
     *     2         │ 24       │ 50,0 ms      │ 66,7   │ 83 ms
     *     1         │ bez      │ 16,7 ms      │ 16,7   │ 33 ms  ← wybrane
     *     1         │ 24       │ 16,7 ms      │ 16,7   │ 17 ms
     *     1         │ 12       │ 16,7 ms      │ 16,7   │ 17 ms
     *     (bez ziarna w ogóle: 16,7 ms / 16,7 / 17 ms)
     *
     * Kolumna „najgorsza" JEST ROZRZUTEM, nie sygnałem — te same ustawienia
     * dawały w kolejnych przebiegach 33 i 17 ms. Decyduje mediana.
     *
     * ROZSTRZYGA SUFIT, NIE CZĘSTOTLIWOŚĆ. Zejście z dwójki na jedynkę zdejmuje
     * trzy czwarte pikseli i wraca do kosztu strony bez ziarna; samo
     * przesiewanie rzadziej nie daje nic, dopóki każda rysowana klatka ma
     * czterokrotnie za dużo pikseli.
     *
     * DLACZEGO WOLNO ZEJŚĆ DO JEDYNKI. Ten sam argument, który wcześniej
     * uzasadniał sufit na dwójce: ziarno jest szumem poniżej progu
     * rozdzielczości oka, a liczba pikseli rośnie z kwadratem. Na ekranie
     * gęstym drobina jest po tej zmianie wielkości dwóch pikseli fizycznych
     * zamiast jednego — czyli odrobinę grubsza. To jest widoczna różnica
     * i trzeba ją obejrzeć, nie tylko zmierzyć.
     *
     * PRZESIEWU RZADZIEJ NIŻ CO KLATKĘ TU NIE MA — i to też jest wynik pomiaru,
     * a nie przeoczenie. Przez chwilę stała tu druga liczba, 24 klatki na
     * sekundę, uzasadniona najgorszą klatką 33 ms wobec 17 ms. Mutacja
     * zdejmująca to ograniczenie NIE ZAPALIŁA ŻADNEGO SPRAWDZENIA: te 33 ms
     * było rozrzutem pomiaru, nie skutkiem. Kod, którego działania nie da się
     * pokazać, to optymalizacja bez pomiaru — więc poszedł. */
    var SUFIT_DPR = 1;

    /** Redukcja ruchu — wspólna polityka wtyczki, patrz includes/anim/motion.php. */
    function ograniczonyRuch() {
        if (window.evkMotion && typeof window.evkMotion.reduced === 'function') {
            return window.evkMotion.reduced();
        }
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    /**
     * Czy przeglądarka rasteryzuje programowo.
     *
     * WŁASNA, KRÓTSZA PRÓBKA — element fali ma swoją, ale siedzi ona wewnątrz
     * jego modułu i niesie jego własny problem: nie wolno pobrać 287 KB
     * biblioteki, zanim się wie. Ziarno nie ma czego odraczać, więc pyta
     * kontekstu, którego i tak używa. To jest duplikat i lepiej nazwać go
     * duplikatem, niż udawać ponowne użycie.
     */
    function bezAkceleracji(gl) {
        try {
            var ext = gl.getExtension('WEBGL_debug_renderer_info');
            if (!ext) return false;   // nazwa ukryta to nie jest „nie ma GPU"
            var nazwa = String(gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) || '');
            return /swiftshader|llvmpipe|software|basic render/i.test(nazwa);
        } catch (e) {
            return false;
        }
    }

    function kompiluj(gl, rodzaj, zrodlo) {
        var sh = gl.createShader(rodzaj);
        gl.shaderSource(sh, zrodlo);
        gl.compileShader(sh);
        if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) {
            console.warn('[EVK Grain] shader się nie skompilował: ' + gl.getShaderInfoLog(sh));
            return null;
        }
        return sh;
    }

    /* NAZWA KONSTRUKTORA ZOSTAJE POLSKA, choć element nazywa się w builderze
       „Grain". Cały kod tej wtyczki mówi po polsku — `rysujRaz`, `przesiew`,
       `intensywnosc`, `zmierz` — więc jeden angielski identyfikator byłby tu
       wyjątkiem, a nie porządkiem. Po angielsku jest to, co widzi użytkownik:
       etykieta w builderze i przedrostek w konsoli. */
    function Ziarno(root) {
        this.root = root;
        this.intensywnosc = parseFloat(root.getAttribute('data-intensywnosc'));
        if (!isFinite(this.intensywnosc)) this.intensywnosc = 0.08;
        this.mnoznik = parseFloat(root.getAttribute('data-mnoznik'));
        if (!isFinite(this.mnoznik)) this.mnoznik = 1;
        this.stoi = root.getAttribute('data-przesiew') === 'stop';
        this.autoJakosc = root.getAttribute('data-auto-jakosc') !== '0';

        /* ZASIĘG. Kanwa ZOSTAJE WIELKOŚCI OKNA także w trybie sekcji — to nie
           jest przeoczenie. Kanwa wielkości sekcji musiałaby jechać razem
           z nią przy każdym przewinięciu (czyli układ przeliczany co klatkę),
           a przy sekcji wysokiej na kilka ekranów wracałby problem, dla
           którego kanwa nie ma wysokości dokumentu: pamięć karty.

           Zamiast tego sekcja jest MASKĄ w shaderze. Koszt zostaje stały
           i niezależny od tego, jak wysoka jest sekcja. */
        this.ogranicz = root.getAttribute('data-zakres') === 'sekcja';
        this.wtopienie = parseFloat(root.getAttribute('data-wtopienie'));
        if (!isFinite(this.wtopienie) || this.wtopienie < 0) this.wtopienie = 120;
        this.sekcja = null;
        if (this.ogranicz) {
            var sel = root.getAttribute('data-sekcja') || '';
            /* PUSTY SELEKTOR = RODZIC. Korzeń elementu jest pustym znacznikiem
               wstawionym w sekcji, więc jego rodzic to ta sekcja albo kontener
               w niej — czyli dokładnie to, co widać w drzewie buildera. Żadnego
               zgadywania po nazwach klas Bricksa: te zmieniają się między
               wersjami, a rodzic nie.

               Z SELEKTOREM szukamy najpierw PRZODKA, tak jak „Selektor
               przodka" w Horizontal Scrollu — dzięki temu dwa ziarna na jednej
               stronie nie wskazują sobie nawzajem tej samej sekcji. Dopiero gdy
               przodek nie pasuje, bierzemy pierwszy pasujący element strony. */
            if (sel) {
                try {
                    this.sekcja = root.closest(sel) || document.querySelector(sel);
                } catch (e) {
                    console.warn('[EVK Grain] niepoprawny selektor sekcji: ' + sel);
                }
            } else {
                this.sekcja = root.parentElement;
            }
            /* NIETRAFIONY SELEKTOR NIE MOŻE ZNIKNĄĆ PO CICHU. Ziarno rozlane na
               całe okno zamiast jednej sekcji wygląda jak usterka układu,
               a nie jak literówka w selektorze. */
            if (!this.sekcja) {
                console.warn('[EVK Grain] nie znalazłem sekcji („' + sel
                    + '") — ziarno zostaje na całym oknie');
                this.ogranicz = false;
            }
        }

        this.uchwyt = 0;
        this.probki = [];
        this.ostatnia = 0;
        this.zeszloNaStop = false;

        this.kanwa = document.createElement('canvas');
        this.kanwa.className = 'evk-grain__plotno';
        this.kanwa.setAttribute('aria-hidden', 'true');
        var w = parseInt(root.getAttribute('data-warstwa'), 10);
        this.kanwa.style.zIndex = isFinite(w) ? String(w) : '9990';

        var opcje = { alpha: true, antialias: false, depth: false, stencil: false };
        this.gl = this.kanwa.getContext('webgl', opcje) || this.kanwa.getContext('experimental-webgl', opcje);
        if (!this.gl) {
            /* Bez WebGL-a nie ma czym rysować. Element ma wtedy PO PROSTU NIE
               BYĆ — jest dekoracją, więc jego brak niczego nie psuje, a pusta
               kanwa nad całą stroną potrafiłaby przykryć treść. */
            console.warn('[EVK Grain] brak kontekstu WebGL — element się nie uruchamia');
            return;
        }
        if (bezAkceleracji(this.gl)) {
            console.warn('[EVK Grain] rasteryzacja programowa — element się nie uruchamia');
            this.gl = null;
            return;
        }

        if (!this.zbuduj()) { this.gl = null; return; }

        document.body.appendChild(this.kanwa);
        this.przelicz();

        this.naRozmiar = this.przelicz.bind(this);
        window.addEventListener('resize', this.naRozmiar);

        /* NASŁUCH PRZEWIJANIA — tylko dla ziarna NIERUCHOMEGO.
         *
         * Ziarno przesiewane rysuje się co klatkę, więc za przewinięciem nadąża
         * samo. Nieruchome rysuje raz i bez tego nasłuchu STAŁOBY W MIEJSCU —
         * czyli cała obietnica „przewijane z treścią" znikałaby dokładnie w tym
         * trybie, który jest wyjściem dla słabszych maszyn. Znalezione sondą:
         * przy przesiewie „stop" przewinięcie o 500 px zmieniało ZERO pikseli.
         *
         * Przy ograniczonym ruchu nasłuchu NIE MA: ziarno wędrujące za
         * przewijaniem to ruch jak każdy inny, a użytkownik prosił, żeby go nie
         * było. Zostaje wtedy jeden nieruchomy kadr.
         *
         * Rysowanie schodzi do jednej klatki — bez tego szybkie przewijanie
         * zamawiałoby rysowanie kilkadziesiąt razy na klatkę. */
        /* W TRYBIE SEKCJI NASŁUCH JEST NAWET PRZY OGRANICZONYM RUCHU — i to
           nie łamie obietnicy „bez ruchu". Maska musi nadążać za sekcją, bo
           inaczej ziarno zostaje tam, gdzie sekcja była przy wczytaniu strony,
           czyli w złym miejscu. To nie jest animacja, tylko trzymanie się
           swojego miejsca; ruchu ziarna (przesiewu) i tak wtedy nie ma. */
        if (!ograniczonyRuch() || this.ogranicz) {
            var ja = this;
            this.czekaNaScroll = false;
            this.naScroll = function () {
                /* WARUNKIEM JEST BRAK PĘTLI, nie „przesiew stop". Element bywa
                   jednoklatkowy na trzy sposoby: przez przesiew, przez
                   ograniczony ruch i przez automat jakości, który schodzi
                   w trakcie. Pytanie o `uchwyt` obejmuje wszystkie trzy —
                   pytanie o `stoi` obejmowało jeden i przy ograniczonym ruchu
                   maska stałaby w miejscu. */
                if (ja.uchwyt || ja.czekaNaScroll) return;
                ja.czekaNaScroll = true;
                requestAnimationFrame(function () {
                    ja.czekaNaScroll = false;
                    ja.rysujRaz();
                });
            };
            window.addEventListener('scroll', this.naScroll, { passive: true });
        }

        /* REDUKCJA RUCHU: jeden kadr i koniec. Ziarno ZOSTAJE na ekranie —
           jest dekoracyjne, więc jego zniknięcie zmieniłoby wygląd strony.
           Ta sama polityka co w fali. */
        if (this.stoi || ograniczonyRuch()) this.rysujRaz();
        else this.ruszaj();
    }

    Ziarno.prototype.zbuduj = function () {
        var gl = this.gl;
        var vs = kompiluj(gl, gl.VERTEX_SHADER, WIERZCHOLKI);
        var fs = kompiluj(gl, gl.FRAGMENT_SHADER, FRAGMENTY);
        if (!vs || !fs) return false;

        var pr = gl.createProgram();
        gl.attachShader(pr, vs);
        gl.attachShader(pr, fs);
        gl.linkProgram(pr);
        if (!gl.getProgramParameter(pr, gl.LINK_STATUS)) {
            console.warn('[EVK Grain] program się nie zlinkował: ' + gl.getProgramInfoLog(pr));
            return false;
        }
        gl.useProgram(pr);
        this.program = pr;

        /* JEDEN TRÓJKĄT, nie dwa. Trójkąt (-1,-1), (3,-1), (-1,3) wychodzi poza
           kadr i pokrywa go w całości — bez szwu na przekątnej, który przy
           dwóch trójkątach potrafi zostawić linię przy niektórych sterownikach. */
        var buf = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buf);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
        var aPoz = gl.getAttribLocation(pr, 'aPoz');
        gl.enableVertexAttribArray(aPoz);
        gl.vertexAttribPointer(aPoz, 2, gl.FLOAT, false, 0, 0);

        this.uRozmiar      = gl.getUniformLocation(pr, 'uRozmiar');
        this.uIntensywnosc = gl.getUniformLocation(pr, 'uIntensywnosc');
        this.uSeed         = gl.getUniformLocation(pr, 'uSeed');
        this.uPrzesuniecie = gl.getUniformLocation(pr, 'uPrzesuniecie');
        this.uOgranicz     = gl.getUniformLocation(pr, 'uOgranicz');
        this.uDol          = gl.getUniformLocation(pr, 'uDol');
        this.uGora         = gl.getUniformLocation(pr, 'uGora');
        this.uWtopienie    = gl.getUniformLocation(pr, 'uWtopienie');
        return true;
    };

    Ziarno.prototype.przelicz = function () {
        if (!this.gl) return;
        /* Sufit gęstości pikseli — SUFIT_DPR wyżej, razem z pomiarem, który
           go ustawił. Tu tylko go stosujemy. */
        var dpr = Math.min(window.devicePixelRatio || 1, SUFIT_DPR);
        var sz = Math.round(window.innerWidth * dpr);
        var wy = Math.round(window.innerHeight * dpr);
        if (this.kanwa.width === sz && this.kanwa.height === wy) return;
        this.kanwa.width = sz;
        this.kanwa.height = wy;
        this.gl.viewport(0, 0, sz, wy);
        this.rysujRaz();
    };

    Ziarno.prototype.rysujRaz = function () {
        var gl = this.gl;
        if (!gl) return;

        gl.clearColor(0, 0, 0, 0);
        gl.clear(gl.COLOR_BUFFER_BIT);

        /* MASKA SEKCJI — przeliczenie z prostokąta na współrzędne kanwy.
         *
         * `getBoundingClientRect()` UWZGLĘDNIA TRANSFORMACJE, a zapamiętane
         * `offsetTop` nie. To nie jest drobiazg akurat w tej wtyczce: sekcje
         * bywają przypięte przez ScrollTrigger (Horizontal Scroll, Stacking
         * Cards), a przypięcie przesuwa je właśnie transformacją. Sekcja
         * zapamiętana przy wczytaniu strony rozjechałaby się z tym, co widać.
         *
         * `rect` liczy od GÓRY okna, a `gl_FragCoord.y` od DOŁU kanwy — stąd
         * odjęcie od `innerHeight` i zamiana miejscami: dolna krawędź sekcji
         * ma MNIEJSZY `y` w kanwie niż górna.
         */
        if (this.ogranicz) {
            var r = this.sekcja.getBoundingClientRect();
            var wys = window.innerHeight || document.documentElement.clientHeight;
            var wtop = this.wtopienie;

            /* POMIJANIA RYSOWANIA, GDY SEKCJA JEST POZA KADREM, TU NIE MA —
               i to jest wynik pomiaru, a nie przeoczenie. Przez chwilę stało
               tu wyjście `if (r.bottom < -wtop || r.top > wys + wtop) return;`.
               Zmierzone (okno 900x600, DPR 2, dławienie CPU 4x, mediana
               odstępu klatek):

                   bez ziarna                 16,7 ms
                   całe okno                  16,7 ms
                   sekcja widoczna            16,5 ms
                   sekcja daleko poza kadrem  16,8 ms

               Cztery wartości nie do odróżnienia — po zejściu sufitu DPR na
               jedynkę (patrz SUFIT_DPR wyżej) ten shader nie kosztuje już tyle,
               żeby dało się cokolwiek zaoszczędzić. `getBoundingClientRect()`
               i tak trzeba zawołać, więc wyjście oszczędzało wyłącznie
               `drawArrays` po masce z samych zer.

               Ta sama decyzja co przy `KLATEK_NA_SEK`: kod, którego działania
               nie da się pokazać, to optymalizacja bez pomiaru. */

            var dpr = this.kanwa.height / wys;
            gl.uniform1f(this.uOgranicz, 1.0);
            gl.uniform1f(this.uDol,  (wys - r.bottom) * dpr);
            gl.uniform1f(this.uGora, (wys - r.top) * dpr);
            gl.uniform1f(this.uWtopienie, wtop * dpr);
        } else {
            gl.uniform1f(this.uOgranicz, 0.0);
        }

        gl.uniform2f(this.uRozmiar, this.kanwa.width, this.kanwa.height);
        gl.uniform1f(this.uIntensywnosc, this.intensywnosc);
        gl.uniform1f(this.uSeed, this.stoi ? 0.0 : Math.random());
        gl.uniform1f(this.uPrzesuniecie,
            (window.pageYOffset || document.documentElement.scrollTop || 0) * this.mnoznik);
        gl.drawArrays(gl.TRIANGLES, 0, 3);
    };

    Ziarno.prototype.ruszaj = function () {
        var ja = this;
        (function klatka() {
            ja.rysujRaz();
            ja.zmierz();
            ja.uchwyt = requestAnimationFrame(klatka);
        })();
    };

    Ziarno.prototype.stop = function () {
        if (this.uchwyt) cancelAnimationFrame(this.uchwyt);
        this.uchwyt = 0;
    };

    /**
     * Automat jakości — jeden szczebel, nie drabina.
     *
     * Fala ma cztery poziomy, bo ma co zdejmować: post-process, rozdzielczość,
     * ruch. Ziarno jest jednym przebiegiem i albo się miesta, albo nie — więc
     * jedyne sensowne zejście to przestać przesiewać. Kadr ZOSTAJE, bo element
     * jest dekoracją i jego zniknięcie zmieniłoby wygląd strony.
     *
     * SCHODZIMY TYLKO W DÓŁ, tak jak w fali: powrót w górę po chwilowym
     * zwolnieniu dawałby migotanie jakości przy każdym cięższym momencie.
     */
    Ziarno.prototype.zmierz = function () {
        if (!this.autoJakosc || this.zeszloNaStop) return;
        var teraz = (window.performance && performance.now) ? performance.now() : Date.now();
        if (this.ostatnia) this.probki.push(teraz - this.ostatnia);
        this.ostatnia = teraz;
        if (this.probki.length < 30) return;

        var p = this.probki.slice().sort(function (a, b) { return a - b; });
        var mediana = p[Math.floor(p.length / 2)];
        this.probki = [];
        this.ostatnia = 0;
        if (mediana <= 40) return;

        this.zeszloNaStop = true;
        this.stoi = true;
        this.stop();
        this.rysujRaz();
        console.warn('[EVK Grain] ' + Math.round(mediana)
            + ' ms na klatkę — przechodzę na nieruchome ziarno');
    };

    var wszystkie = [];

    function uruchom(root) {
        if (root.__evkZiarno) return;
        var z = new Ziarno(root);
        root.__evkZiarno = z;
        wszystkie.push(z);
    }

    function skanuj(gdzie) {
        var lista = (gdzie || document).querySelectorAll('[data-evk-grain]');
        Array.prototype.forEach.call(lista, uruchom);
    }

    /* Bricks woła to po wyrenderowaniu elementu w canvasie buildera. Ta sama
       nazwa musi stać w `$this->scripts` w element.php. */
    window.evk_grain_init = function () {
        /* W builderze element bywa przerysowywany — stare kanwy zostają
           w <body>, bo nie są dziećmi korzenia. Sprzątamy po osieroconych. */
        wszystkie = wszystkie.filter(function (z) {
            if (z.root && z.root.isConnected) return true;
            z.stop();
            if (z.naRozmiar) window.removeEventListener('resize', z.naRozmiar);
            if (z.naScroll) window.removeEventListener('scroll', z.naScroll);
            if (z.kanwa && z.kanwa.parentNode) z.kanwa.parentNode.removeChild(z.kanwa);
            return false;
        });
        skanuj();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { skanuj(); });
    } else {
        skanuj();
    }
})();
