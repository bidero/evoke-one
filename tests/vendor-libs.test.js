/**
 * Biblioteki obce hostowane u siebie.
 *
 * Do 1.72.0 GSAP jechał z cdnjs, a Lenis z unpkg. Zmierzone na żywej stronie:
 * **53 KiB w sumie, 900–1650 ms na plik**. To nie jest koszt bajtów, tylko
 * koszt POŁĄCZEŃ — dwa obce hosty znaczą dwa razy DNS + TCP + TLS, zanim
 * przyjdzie pierwszy bajt.
 *
 * Trzy rzeczy trzeba tu pilnować RAZEM, bo osobno każda przechodzi dla
 * zepsutej konfiguracji:
 *
 * 1. **Żaden adres nie wychodzi na obcy host.** Sam zapis adresu wystarczy,
 *    żeby wrócić do CDN-u — i wrócił: `marquee.js` i `hscroll.js` miały adresy
 *    cdnjs wpisane na sztywno w loaderach awaryjnych, poza zasięgiem PHP.
 * 2. **Wskazany plik NAPRAWDĘ leży na dysku.** Rejestracja adresu, pod którym
 *    nic nie ma, jest cicha: WordPress wydrukuje `<script src>`, przeglądarka
 *    dostanie 404, a strona po prostu przestanie animować.
 * 3. **Plik na dysku to DZIAŁAJĄCY GSAP.** Punkty 1 i 2 przechodzą także dla
 *    pustego pliku o właściwej nazwie — dlatego przeglądarka ładuje ten sam
 *    plik, który jedzie na stronę, i mówi, jaką wersję dostała.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  const php = JSON.parse(phpOutput('vendor-libs.php'));

  // ── Nic nie wychodzi na obcy host ──────────────────────────────────────
  t.section('biblioteki jadą z własnego serwera');

  const handles = Object.keys(php.scripts);
  t.check('zarejestrowane są wszystkie handle GSAP-a', handles.length === 6,
    handles.join(', '));

  const obce = handles.filter((h) => php.scripts[h].host !== 'example.test');
  t.check('żaden skrypt GSAP-a nie wskazuje na obcy host', obce.length === 0,
    obce.length ? obce.map((h) => h + ' → ' + php.scripts[h].host).join(', ')
                : 'wszystkie z example.test');
  t.check('Lenis też jedzie z własnego serwera',
    !/unpkg|cdnjs|jsdelivr/.test(php.lenisSrc), php.lenisSrc);

  /* Skan ŹRÓDŁA, nie rejestracji. Loadery awaryjne w marquee.js i hscroll.js
     budują adres same, więc PHP ich nie widzi — a to właśnie tam przetrwały
     adresy cdnjs. Panel administratora jest ze skanu wyłączony świadomie:
     Sortable i Chart.js dotyczą zaplecza, nie stron odwiedzających. */
  t.check('w JS wtyczki nie został ani jeden adres CDN-u', php.cdnHits.length === 0,
    php.cdnHits.length ? php.cdnHits.map((x) => x.file + ': ' + x.url).join(' | ')
                       : 'czysto');

  // ── Pliki są na dysku ──────────────────────────────────────────────────
  // Zarejestrowany adres bez pliku pod nim to cichy 404 — strona przestaje
  // animować, a w PHP wszystko wygląda poprawnie.
  t.section('pliki naprawdę leżą w paczce');

  const brak = handles.filter((h) => !php.scripts[h].onDisk);
  t.check('każdy zarejestrowany plik istnieje', brak.length === 0,
    brak.length ? 'BRAKUJE: ' + brak.join(', ') : handles.length + ' plików');
  // Pusty plik o właściwej nazwie przeszedłby sprawdzenie obecności.
  const puste = handles.filter((h) => php.scripts[h].bytes < 2000);
  t.check('i żaden nie jest pusty', puste.length === 0,
    puste.length ? puste.join(', ')
                 : Math.round(handles.reduce((s, h) => s + php.scripts[h].bytes, 0) / 1024) + ' KB razem');
  t.check('plik Lenisa też jest i ma treść',
    php.lenisOnDisk && php.lenisBytes > 2000,
    php.lenisOnDisk ? Math.round(php.lenisBytes / 1024) + ' KB' : 'BRAK');

  // ── Mapy źródeł jadą razem z plikami ───────────────────────────────────
  // Zminifikowany plik potrafi kończyć się komentarzem `sourceMappingURL`.
  // Przeglądarka pyta o wskazany plik za każdym razem, gdy ktoś otworzy
  // narzędzia deweloperskie — i dostaje 404, jeśli mapy nie ma obok.
  // Odwiedzającemu to nie szkodzi, bo mapy nie pobiera nikt z zamkniętą
  // konsolą, ale właścicielowi strony wisi w niej czerwony błąd bez związku
  // z niczym. Zgłoszone z Safari po przeniesieniu Lenisa na własny serwer.
  //
  // Sprawdzamy REGUŁĘ, nie konkretny plik: dzisiaj o mapę prosi tylko Lenis,
  // ale kolejne wydanie GSAP-a może dołożyć ten komentarz i nikt by tego nie
  // zauważył aż do czyjejś konsoli.
  t.section('mapy źródeł nie zostawiają 404 w konsoli');

  const sierotki = php.maps.filter((m) => !m.obok);
  t.check('każdy plik proszący o mapę ma ją obok siebie', sierotki.length === 0,
    sierotki.length ? sierotki.map((m) => m.plik + ' → ' + m.mapa).join(', ')
                    : php.maps.map((m) => m.plik).join(', ') || 'żaden nie prosi');

  // ── Adres katalogu dla loaderów awaryjnych ─────────────────────────────
  // Marquee i Horizontal Scroll dociągają GSAP-a same, gdy go nie zastaną.
  // Bez tej globalnej nie miałyby skąd wziąć adresu po odcięciu CDN-u.
  t.section('loadery awaryjne mają skąd wziąć adres');

  const base = php.inline.find((x) => /evkGsapBase/.test(x.data));
  t.check('adres katalogu jest wystawiany na stronie', !!base,
    base ? base.data : 'BRAK window.evkGsapBase');
  // Pozycja `before` nie jest kosmetyką: globalna musi istnieć, ZANIM wykona
  // się którykolwiek ze skryptów, które ją czytają.
  t.check('i to PRZED samym GSAP-em',
    !!base && base.handle === 'evk-gsap' && base.position === 'before',
    base ? base.handle + ' / ' + base.position : '—');

  // ── Marquee ma komplet zależności ──────────────────────────────────────
  // Element używa I Observera (prędkość przewijania), I ScrollTriggera
  // (zatrzymanie poza kadrem). Brakowało tego drugiego, więc loader awaryjny
  // wchodził do gry na KAŻDEJ stronie z marquee i dociągał ScrollTriggera
  // z cdnjs — osobne DNS + TCP + TLS, i to dopiero po wykonaniu skryptu.
  t.section('marquee deklaruje wszystko, czego używa');

  t.check('marquee zależy i od Observera, i od ScrollTriggera',
    /evk-observer/.test(php.marqueeDeps) && /evk-scrolltrigger/.test(php.marqueeDeps),
    php.marqueeDeps);

  // ── Plik na dysku to DZIAŁAJĄCY GSAP ───────────────────────────────────
  // Wszystko powyżej przeszłoby też dla pliku o właściwej nazwie i długości,
  // ale niedziałającego. Ładujemy więc dokładnie ten plik, który jedzie na
  // stronę — fixture'y wskazują na assets/vendor, nie na node_modules.
  t.section('zapakowany GSAP naprawdę działa');

  const p = await t.open('circular-menu.html', { viewport: { width: 900, height: 700 }, settle: 300 });

  const v = await p.evaluate(() => (window.gsap && gsap.version) || null);
  t.check('GSAP wstał i zna swoją wersję', !!v, String(v));
  // Numer musi się zgadzać z tym, którym WordPress odcina pamięć podręczną —
  // rozjazd znaczy, że ktoś podmienił pliki i zapomniał o stałej (albo odwrotnie),
  // a wtedy przeglądarki zostają przy starym pliku.
  const phpVer = phpOutput('vendor-libs.php') && require('fs')
    .readFileSync(require('path').join(__dirname, '..', 'includes', '89-gsap.php'), 'utf8')
    .match(/EVK_GSAP_VERSION\s*=\s*'([^']+)'/);
  t.check('wersja pliku zgadza się ze stałą EVK_GSAP_VERSION',
    !!phpVer && v === phpVer[1], 'plik ' + v + ', stała ' + (phpVer ? phpVer[1] : '—'));

  t.check('ScrollTrigger z tej samej paczki też wstał',
    await p.evaluate(() => typeof ScrollTrigger !== 'undefined'), 'jest');
  t.check('bez błędów JS przy zapakowanych bibliotekach', !p.errors.length,
    p.errors.join(' | ') || 'brak');
  await p.close();
};
