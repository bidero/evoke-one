/**
 * Fala przy zmianie motywu — na STRONIE, a nie na gołym tle.
 *
 * ZGŁOSZONE Z UŻYCIA: „po usunięciu elementów z list fala zmienia tylko kolor
 * body. Teksty, divy, pola, wszystkie elementy i gradienty zmieniają się od
 * razu."
 *
 * PO CO OSOBNY PLIK, SKORO JEST `darkmode-ripple.test.js`. Tamten mierzy na
 * gołym tle: body z kolorem i jedno pasmo, wszystko w kadrze. Zgłoszony objaw
 * nie odtwarzał się tam ani razu — a odtworzył się dopiero na fixturze, który
 * przypomina prawdziwą stronę: wyższej od kadru, przewiniętej, z `#brx-content`,
 * przyklejonym nagłówkiem, kolorami ze ZMIENNYCH CSS i działającymi animacjami.
 *
 * Droga do tego wniosku prowadziła przez cztery obalone hipotezy, wszystkie
 * zdjęte pomiarem z żywej strony, nie rozumowaniem: własne
 * `view-transition-name` na elementach (jest tylko `html`), nazwa w trakcie
 * (`theme-ripple`, poprawna), animacja maski (działa), wyciszenie przejść (zero
 * żywych) i Lenis (wyłączenie nic nie zmieniło).
 *
 * ZMIERZONE, karta w rogu przeciwległym do przycisku (jasność, start 255):
 *
 *     czas      z przygaszaniem   bez (1.216.0)
 *     180 ms         251              255
 *     360 ms         236              255
 *     600 ms         215              255
 *    1080 ms           0                0   (fala doszła — ma się zmienić)
 *
 * Czterdzieści poziomów przecieku ZANIM fala tam dotarła. To nie było „elementy
 * się nie animują", tylko dwadzieścia procent już przefarbowanej strony
 * prześwitujące przez przygaszoną migawkę.
 */

const { phpOutput } = require('./lib/harness');

const V = { viewport: { width: 800, height: 600 }, settle: 300 };
/* Róg przeciwległy do przycisku — fala dochodzi tam na końcu. Punkt leży na
   TLE strony (`--evk-tlo`, prowadzone przez `data-theme` wtyczki), nie na
   karcie; karta ma osobny punkt niżej. */
const ROG = [770, 570];

/* PUNKT NA KARCIE, nie na tle. ROG powyżej leży na tle `body`, które prowadzi
   `data-theme` wtyczki — a sedno zgłoszenia dotyczy powierzchni prowadzonych
   przez motyw BRICKSA (`data-brx-theme`). Bez tego punktu mutacja zdejmująca
   przejęcie zdarzenia przechodziła tu na zielono.

   Współrzędna ZMIERZONA, nie policzona: przy przewinięciu 400 piąta karta
   (bez własnej animacji) zajmuje w kadrze pas 260–380 i kończy się na x = 770,
   więc 770 trafia już w sekcję pod nią — stąd 760. Fala (łatwość
   `cubic-bezier(0.4, 0, 0.2, 1)`, promień docelowy ~1066 px) dociera tu przy
   ~45 % czasu, więc próbki idą do 30 %. */
const KARTA = [760, 330];

/* Druga karta z własną nazwą — tą nadaną w ATRYBUCIE `style`, jak robi to
   `inject_post_trans_attrs()` dla elementów w pętli wpisów. Zmierzone: pas
   120–380 w kadrze; x = 700, a nie przy krawędzi, bo ta karta ma animację
   `translateX(3px)` i przy brzegu odsłaniałaby tło. */
const KARTA_INLINE = [700, 180];

/** Wstrzykuje PRAWDZIWY moduł z 93-darkmode.php — bez klikania. */
async function wstrzyknij(p, ustawienia) {
  await p.evaluate((html) => {
    const d = document.createElement('div');
    d.innerHTML = html;
    Array.from(d.children).forEach((n) => {
      if (n.tagName === 'SCRIPT') {
        const s = document.createElement('script');
        s.textContent = n.textContent;
        document.body.appendChild(s);
      } else { document.head.appendChild(n); }
    });
    window.dispatchEvent(new Event('DOMContentLoaded'));
  }, phpOutput('darkmode-head.php', JSON.stringify(JSON.stringify(ustawienia || {}))));
  await p.waitForTimeout(300);
}

/** Wstrzykuje moduł i klika w przełącznik. */
async function zapal(p, ustawienia) {
  await wstrzyknij(p, ustawienia);
  /* ZERUJEMY REJESTR TUŻ PRZED KLIKNIĘCIEM. Bez tego pierwszym wpisem byłoby
     `data-theme` ustawiane przy rozruchu modułu — a wtedy sprawdzenie
     kolejności przechodziłoby także w wersji z błędem. */
  await p.evaluate(() => { window.__kolejnosc.length = 0; });
  await p.click('.brxe-toggle-mode');
  await p.waitForTimeout(120);
}

/** Jasność punktu przy zadanym czasie fali. Fala jest ZAMROŻONA, czas z ręki. */
async function wPunkcie(p, ms, xy) {
  await p.evaluate((v) => window.__fala.forEach((a) => { a.currentTime = v; }), ms);
  await p.waitForTimeout(40);
  const buf = await p.screenshot();
  return p.evaluate(async ({ b64, punkt }) => {
    const img = new Image();
    img.src = 'data:image/png;base64,' + b64;
    await img.decode();
    const c = document.createElement('canvas');
    c.width = img.width; c.height = img.height;
    const ctx = c.getContext('2d');
    ctx.drawImage(img, 0, 0);
    const d = ctx.getImageData(0, 0, img.width, img.height).data;
    const i = (punkt[1] * img.width + punkt[0]) * 4;
    return Math.round((d[i] + d[i + 1] + d[i + 2]) / 3);
  }, { b64: buf.toString('base64'), punkt: xy });
}

module.exports = async function (t) {

  // ── Stara migawka w ogóle powstaje ──────────────────────────────────────
  /* STRUKTURALNY WARUNEK WSZYSTKIEGO PONIŻEJ. Bez starej migawki nie ma czym
     przykryć tego, do czego fala nie doszła — widać wtedy żywy, już
     przefarbowany dokument, i cały efekt przestaje istnieć.

     Pytamy o nią, dokładając jej animację, która nic nie zmienia: gdy
     pseudoelementu nie ma, `animate()` nie dorzuci nic do `getAnimations()`.
     To był główny podejrzany w tej sprawie i wart jest własnego sprawdzenia,
     nawet gdy okazał się niewinny. */
  t.section('fala ma z czego odsłaniać: stara migawka istnieje');

  const p = await t.open('darkmode-ripple-strona.html', V);
  await zapal(p, { global_selectors: '', bricks_selectors: '' });

  t.check('::view-transition-old(theme-ripple) jest',
    (await p.evaluate(() => window.__czyJestStara())) === true, 'jest');

  await p.evaluate(() => window.__zamroz());
  const czas = await p.evaluate(() => (window.__fala.length
    ? window.__fala[0].effect.getComputedTiming().duration : 0));
  t.check('a maska ma swoją animację', czas > 0, czas + ' ms');

  // ── Treść czeka na falę, choć nie ma jej na żadnej liście ───────────────
  /* Listy selektorów są tu PUSTE — dokładnie ta konfiguracja, przy której objaw
     był widoczny. Punkt ma kolor ze zmiennej CSS i nie jest wymieniony nigdzie. */
  t.section('tło spoza list czeka, aż fala po nim przejdzie');

  const start = await wPunkcie(p, 0, ROG);
  t.check('na starcie tło jest jasne', start > 250, 'jasność ' + start);

  const dryf = [
    await wPunkcie(p, Math.round(czas * 0.15), ROG),
    await wPunkcie(p, Math.round(czas * 0.3), ROG),
    await wPunkcie(p, Math.round(czas * 0.5), ROG),
  ];
  /* Próg DWA POZIOMY, a nie zero: zrzut ekranu jest ośmiobitowy i kompozytowanie
     potrafi przesunąć wartość o jeden. Przeciek, który tu łapiemy, miał
     czterdzieści. */
  t.check('i trzyma kolor, dopóki fala nie dojdzie',
    dryf.every((j) => Math.abs(j - start) <= 2),
    start + ' → ' + dryf.join(' → '));

  /* KONTROLA POZYTYWNA: bez niej „nic się nie zmienia" przechodziłoby także dla
     fali, która nigdy do rogu nie dociera. */
  const poFali = await wPunkcie(p, Math.round(czas * 0.9), ROG);
  t.check('a gdy fala dojdzie — zmienia się', poFali < 40, 'jasność ' + poFali);

  // ── Motyw Bricksa nie wyprzedza migawki ─────────────────────────────────
  /* PRZYCZYNA CAŁEJ SPRAWY, zmierzona na lustrze żywej strony
     (`tools/lustro/zmierz-fale.js`). Do tego samego przycisku podpina się
     `bricksToggleModeFn` z `bricks.min.js` i przestawia `data-brx-theme`
     SYNCHRONICZNIE — a na tym atrybucie wiszą wszystkie kolory strony. Stara
     migawka powstaje dopiero w kroku renderowania, więc łapie już nowe kolory.

         57 ms  data-brx-theme = light     ← Bricks
         72 ms  startViewTransition
        124 ms  stara migawka zrobiona     ← o 67 ms za późno

     Cały kadr przy promieniu fali równym zeru, wobec stanu sprzed kliknięcia:
     101 z 336 komórek przefarbowanych przed poprawką, 2 po niej.

     Pomiary powyżej łapią to jasnością. TU pytamy o KOLEJNOŚĆ — bo ona mówi
     wprost, kto wygrał wyścig, i nie da się jej zaliczyć przypadkiem. */
  t.section('motyw Bricksa przełącza się wewnątrz fali, nie przed nią');

  const kolejnosc = await p.evaluate(() => window.__kolejnosc);
  t.check('atrybuty motywu zmieniają się razem, z wnętrza przejścia',
    kolejnosc.length >= 2 && kolejnosc[0] === 'data-theme',
    kolejnosc.join(' → ') || 'nic się nie zmieniło');
  t.check('a motyw Bricksa faktycznie doszedł do nowej wartości',
    (await p.evaluate(() => document.documentElement.dataset.brxTheme)) === 'dark',
    await p.evaluate(() => String(document.documentElement.dataset.brxTheme)));

  /* TA SAMA MIARA CO NA LUSTRZE: przy promieniu fali równym zeru powierzchnia ma
     wyglądać dokładnie jak przed kliknięciem. Karta jest jasna (#f2f2f2 → 242)
     i ma pociemnieć (#0d0d0d → 13) dopiero wtedy, gdy fala po niej przejdzie. */
  const naKarcie = [
    await wPunkcie(p, 0, KARTA),
    await wPunkcie(p, Math.round(czas * 0.15), KARTA),
    await wPunkcie(p, Math.round(czas * 0.3), KARTA),
  ];
  t.check('karta prowadzona motywem Bricksa też czeka na falę',
    naKarcie.every((j) => j > 200), naKarcie.join(' → '));
  t.check('a po przejściu fali ciemnieje',
    (await wPunkcie(p, Math.round(czas * 0.9), KARTA)) < 60,
    'jasność ' + (await wPunkcie(p, Math.round(czas * 0.9), KARTA)));

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── „Lista → wpis" nie wyjmuje elementów spod fali ──────────────────────
  /* ZGŁOSZONE Z UŻYCIA (selenit-gastro.pl): „przy zaznaczonym Przejścia
     elementów lista → wpis nagłówek — logo i linki — przechodzi przez fade,
     a nie przez falę". Na tamtej stronie nie ma nawet wpisów.

     MECHANIZM: opcja nadaje elementom własne `view-transition-name` NA STAŁE.
     Element z własną nazwą jest wyjmowany z migawki `theme-ripple` i animuje
     się osobną grupą — domyślnie przez przenikanie.

     ZMIERZONE NA LUSTRZE ŻYWEJ STRONY. Spis nazw w trakcie przejścia motywu:

         theme-ripple     ←  html.lenis.is-theme-toggling.dark
         post-title-264   ←  div#brxe-e43e4d.brxe-container

     a ten `div` to kontener wewnątrz `<header id="brx-header">` — z logo
     i linkami. Kadr przy promieniu fali równym zeru, wobec stanu sprzed
     kliknięcia:

         przed poprawką   19 z 336 komórek, wszystkie w pasie nagłówka
         po poprawce      10 z 336 — a KONTROLA NEGATYWNA (dwa zrzuty BEZ
                          kliknięcia) daje te same 10, bo to obracający się
                          tytuł łukowy. Przecieku z motywu nie ma.

     Tu ten sam układ w atrapie: `?wyjeta=tak` nadaje piątej karcie własną
     nazwę, a ustawienia wskazują ją jako selektor tytułu wpisu. */
  t.section('element z „lista → wpis" i tak czeka na falę');

  const w = await t.open('darkmode-ripple-strona.html', { ...V, query: 'wyjeta=tak' });
  await zapal(w, {
    global_selectors: '', bricks_selectors: '',
    post_trans_enabled: 1,
    post_trans_title_single: '.karta-wyjeta',   // nazwa z arkusza
    post_trans_image_single: '.karta-inline',   // nazwa z atrybutu `style`
  });
  await w.evaluate(() => window.__zamroz());
  const czasW = await w.evaluate(() => (window.__fala.length
    ? window.__fala[0].effect.getComputedTiming().duration : 0));

  /* Nazwa ma być zgaszona NA CZAS PRZEJŚCIA — pytamy w jego trakcie, bo poza
     nim reguła nie obowiązuje i ma nie obowiązywać (przejście lista → wpis
     musi dalej działać). */
  const nazwy = await w.evaluate(() => {
    const out = [];
    document.querySelectorAll('*').forEach((el) => {
      const n = getComputedStyle(el).viewTransitionName;
      if (n && n !== 'none') out.push(n);
    });
    return out;
  });
  t.check('w trakcie fali jedyną nazwą jest theme-ripple',
    nazwy.length === 1 && nazwy[0] === 'theme-ripple', nazwy.join(', ') || 'brak');

  const naWyjetej = [
    await wPunkcie(w, 0, KARTA),
    await wPunkcie(w, Math.round(czasW * 0.15), KARTA),
    await wPunkcie(w, Math.round(czasW * 0.3), KARTA),
  ];
  t.check('i karta z nazwą z arkusza trzyma kolor',
    naWyjetej.every((j) => j > 200), naWyjetej.join(' → '));

  /* NAZWA Z ATRYBUTU `style` — druga droga, którą idą elementy w pętli wpisów.
     Tylko tu potrzebny jest `!important` w regule gaszącej. Bez tego
     sprawdzenia mutacja zdejmująca `!important` przechodziła na zielono. */
  const naInline = [
    await wPunkcie(w, 0, KARTA_INLINE),
    await wPunkcie(w, Math.round(czasW * 0.15), KARTA_INLINE),
    await wPunkcie(w, Math.round(czasW * 0.3), KARTA_INLINE),
  ];
  t.check('i karta z nazwą w atrybucie style również',
    naInline.every((j) => j > 200), naInline.join(' → '));

  t.check('a po przejściu fali ciemnieje',
    (await wPunkcie(w, Math.round(czasW * 0.9), KARTA)) < 60,
    'jasność ' + (await wPunkcie(w, Math.round(czasW * 0.9), KARTA)));
  t.check('bez błędów JS', !w.errors.length, w.errors.join(' | ') || 'brak');
  await w.close();

  /* GASZENIE MA OBOWIĄZYWAĆ TYLKO W TRAKCIE PRZEJŚCIA MOTYWU. Poza nim nazwy
     muszą zostać — inaczej zniknęłoby przejście lista → wpis, czyli to, po co
     ta opcja w ogóle istnieje. Bez tego sprawdzenia mutacja gasząca nazwy
     ZAWSZE przechodziła na zielono. */
  const z = await t.open('darkmode-ripple-strona.html', { ...V, query: 'wyjeta=tak' });
  await wstrzyknij(z, {
    global_selectors: '', bricks_selectors: '',
    post_trans_enabled: 1, post_trans_title_single: '.karta-wyjeta',
    post_trans_image_single: '.karta-inline',
  });
  const pozaPrzejsciem = await z.evaluate(() => [
    getComputedStyle(document.querySelector('.karta-wyjeta')).viewTransitionName,
    getComputedStyle(document.querySelector('.karta-inline')).viewTransitionName,
  ]);
  t.check('poza przejściem motywu nazwy zostają nietknięte',
    pozaPrzejsciem.every((n) => n && n !== 'none'), pozaPrzejsciem.join(', '));
  await z.close();
};
