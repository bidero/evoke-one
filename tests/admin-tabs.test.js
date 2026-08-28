/**
 * Zakładki panelu noszą skórę Evoke Fields — a nie własne style.
 *
 * Skóra z 1.43.0 dosięga zakładki tylko wtedy, gdy zakładka nie broni się
 * własnym `style=`. Atrybut inline wygrywa z każdym arkuszem, więc pole
 * z `border-radius:5px;padding:5px 8px` zostaje w starym kształcie, choć
 * reguła dla niego istnieje i jest poprawna. Dokładnie na to natrafiłem
 * w Animatorze (1.43.0) — tam winowajcą był blok `<style>` w zakładce,
 * tu jest nim atrybut przy kontrolce.
 *
 * Dlatego test NIE czyta źródła PHP w poszukiwaniu tekstu `style="`. Mierzy
 * wyrenderowaną zakładkę w przeglądarce: wysokość, promień, ramkę. Gdyby
 * kiedyś padło postanowienie, że jakieś pole ma być niższe, test to zobaczy
 * i trzeba będzie świadomie dopisać wyjątek — a nie odkryć rozjazd na zrzucie
 * ekranu od użytkownika.
 *
 * Zakładki renderuje `tests/php/tab.php` PRAWDZIWYM plikiem zakładki.
 */

const { phpOutput, rgb, near } = require('./lib/harness');

/** Wzorzec kontrolki z Evoke Fields — te same wartości, co w admin-style.test.js. */
const FIELD = {
  h:      38,                  // --evo-field-h
  radius: '6px',               // --evo-radius-sm
  border: [209, 213, 219],     // --evo-border-field #d1d5db
  size:   '13px',
};
const BTN_RADIUS = '7px';      // --evo-radius-btn
const ACCENT     = [37, 99, 235];

/**
 * Boks sekcji — wzorzec z zakładki OpenGraph, przeniesiony do arkusza
 * w 1.45.0. To on niesie „podział na boksy", o który chodziło.
 */
const BOX = {
  bg:     'rgb(255, 255, 255)',     // --evo-bg
  border: 'rgb(215, 221, 231) 1px', // --evo-border
  radius: '8px',                    // --evo-radius-md
};
/* Nagłówek boksu jest ETYKIETĄ, nie tytułem: 11 px, 700, wersaliki.
   Oryginał w tab-og.php miał `font-size` dwa razy (13 px, potem 11 px) —
   wygrywała druga i taki kształt zostaje. */
const BOX_TITLE = { size: '11px', weight: '700', transform: 'uppercase' };

/** Zakładki objęte przemieceniem. Kolejne dopisujemy tu, gdy przejdą sweep. */
const TABS = ['forminbox', 'a11y', 'darkmode', 'og', 'whitelabel',
              'schema', 'sitemap', 'seo-meta',
              'nl-lists', 'nl-campaigns', 'nl-templates', 'nl-reports', 'nl-settings',
              'sec-login', 'sec-rest', 'sec-hardening', 'sec-cleanup',
              'tools-smtp', 'tools-redirect', 'tools-logs404', 'tools-io', 'tools-maintenance',
              'adm-interface', 'adm-dashboard', 'adm-avatar', 'adm-content',
              'adm-roles', 'adm-tlumaczenia',
              'fe-cursor', 'fe-lenis', 'fe-bgshift', 'fe-fonts',
              'fe-themecolor', 'fe-parallax', 'fe-elementy', 'fe-newsletter', 'fe-newsletter-on',
              /* Tłumaczenia to osobny ekran, ale ładuje ten sam `admin.css`
                 (patrz `tl/bootstrap.php`), więc obowiązuje go ta sama skóra. */
              'tl-translations', 'tl-images', 'tl-slugs', 'tl-dd',
              'tl-languages', 'tl-sitemap', 'tl-io'];

/** Zakładki mierzone też na wąskim ekranie. */
const MOBILE = ['schema', 'sitemap', 'seo-meta',
                'nl-lists', 'nl-campaigns', 'nl-templates', 'nl-reports', 'nl-settings',
                /* Cztery podstrony z tabelami — ten sam kształt, który w 1.48.0
                   rozpychał Raporty do 682 px przy oknie 390 px. */
                'sec-login', 'tools-smtp', 'tools-redirect', 'tools-logs404',
                /* Tłumaczenia: trzy zakładki z tabelami. */
                'tl-translations', 'tl-slugs', 'tl-languages',
                /* Role mają tabelę uprawnień — ten sam kształt. */
                'adm-roles'];

/** Zakładki, które mają już treść w boksach. */
const BOXED = ['forminbox', 'a11y', 'darkmode', 'og', 'whitelabel',
               'sec-hardening', 'tools-smtp', 'tools-logs404',
               'adm-interface', 'adm-dashboard', 'adm-content',
               /* Tłumaczenia NIE używają `.evo-box` — mają własny
                  `.tl-menu-settings`, więc do BOXED nie należą. */
               'fe-lenis', 'fe-bgshift', 'fe-fonts', 'fe-themecolor'];

module.exports = async function (t) {
  // ── Zdublowany atrybut class ──────────────────────────────────────────
  // Przeglądarka bierze PIERWSZY `class` i po cichu ignoruje resztę. Klasa
  // dopisana jako drugi atrybut nie działa, a w źródle wygląda, jakby działała.
  // Przemiatanie zakładek wprowadziło tak 37 takich miejsc — w tym `is-ok`
  // i `is-err` na ramkach informacyjnych, przez co renderowały się neutralnie.
  // Wydane w 1.44.0 i 1.45.0; żaden pomiar wyglądu tego nie zobaczył, bo
  // klasa po prostu nie istniała, a nie miała złą wartość.
  t.section('znaczniki bez zdublowanego class');

  const dup = JSON.parse(phpOutput('dup-class.php'));
  t.check('żaden znacznik nie ma dwóch atrybutów class', dup.count === 0,
    dup.count ? dup.count + ' miejsc, np. ' +
      dup.items.slice(0, 3).map((x) => x.file + ': ' + x.tag).join(' | ')
    : 'czysto');

  // ── Zagnieżdżenie znaczników w WYRENDEROWANEJ zakładce ────────────────
  /*
   * ZGŁOSZONE Z UŻYCIA, dwa razy: „Info ze stopki jest w połowie strony…
   * To w white label", a po wydaniu 1.122.0 — „Nadal jest problem z tekstem
   * stopki w zakładce White Label. Nigdzie indziej nie zauważyłem".
   *
   * Nadmiarowy `</div>` domyka cudzy znacznik: parser zjada nim `<form>`
   * i najbliższy `<div>` NAD nim, więc panel kończy się przed czasem, a razem
   * z nim `#wpbody-content` i `#wpwrap`. `#wpfooter` (w rdzeniu WordPressa
   * `position: absolute; bottom: 0` względem `#wpwrap`) traci wtedy przodka
   * pozycjonującego i siada na dolnej krawędzi OKNA — czyli w połowie treści.
   *
   * W 1.122.0 stało tu LICZENIE znaczników i złapało `nl-lists` (28 otwarć,
   * 29 zamknięć). White Label przepuściło: brakowało tam jednego `</div>`
   * ORAZ stał jeden nadmiarowy — bilans wychodził na zero. RÓWNA LICZBA
   * OTWARĆ I ZAMKNIĘĆ NIE DOWODZI POPRAWNEGO ZAGNIEŻDŻENIA, więc liczenie
   * ustąpiło miejsca stosowi. Stos łapie też stary przypadek: znacznik bez
   * zamknięcia zostaje na stosie do końca.
   *
   * Czytamy SUROWY tekst, nie DOM: `innerHTML` — a więc i atrapa
   * `admin-tabs.html` — po cichu NAPRAWIA złe zagnieżdżenie, przez co żaden
   * pomiar w przeglądarce tej usterki nie zobaczy. Na wyjściu PHP, a nie
   * w źródle, bo gałęzie `if/else` sprawiają, że statyczna suma w pliku prawie
   * nigdy się nie zgadza, choć wyjście jest poprawne. Bloki `<script>`,
   * `<style>` i komentarze maskujemy — znaczniki w łańcuchach JS to dane,
   * nie struktura.
   */
  t.section('wyrenderowany markup ma poprawne zagnieżdżenie');

  /* Tylko znaczniki, które naprawdę trzymają strukturę. `p` i `li` parser
     domyka sam, więc ich „brak zamknięcia" nie byłby usterką, a szumem. */
  const STRUKTURA = ['div', 'form', 'table', 'thead', 'tbody', 'tr', 'td', 'th',
                     'details', 'summary', 'label', 'fieldset', 'section',
                     'ul', 'ol', 'select'];

  const zleZagniezdzone = [];
  for (const slug of TABS) {
    const html = phpOutput('tab.php', slug)
      .replace(/<script\b[\s\S]*?<\/script>/gi, '')
      .replace(/<style\b[\s\S]*?<\/style>/gi, '')
      .replace(/<!--[\s\S]*?-->/g, '');
    const stos = [], bledy = [];
    const znacznik = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(\/?)>/g;
    let m;
    while ((m = znacznik.exec(html))) {
      const tag = m[2].toLowerCase();
      if (!STRUKTURA.includes(tag)) continue;
      if (!m[1]) {                       // otwarcie (pomijamy <x />)
        if (!m[3]) stos.push({ tag, poz: m.index });
        continue;
      }
      const gdzie = stos.map((x) => x.tag).lastIndexOf(tag);
      if (gdzie === -1) {
        bledy.push('</' + tag + '> bez otwarcia (poz. ' + m.index + ')');
        continue;
      }
      if (gdzie === stos.length - 1) { stos.pop(); continue; }
      bledy.push('</' + tag + '> zamyka <' + stos[stos.length - 1].tag +
                 '> otwarty wcześniej (poz. ' + stos[stos.length - 1].poz + ')');
      stos.length = gdzie;               // parser domknąłby wszystko powyżej
    }
    for (const otwarty of stos) {
      bledy.push('<' + otwarty.tag + '> nigdy nie zamknięty (poz. ' + otwarty.poz + ')');
    }
    if (bledy.length) zleZagniezdzone.push(slug + ': ' + bledy.join(' | '));
  }
  t.check('każda zakładka zamyka to, co otworzyła, i w tej kolejności',
    !zleZagniezdzone.length,
    zleZagniezdzone.join(' | ') || TABS.length + ' zakładek poprawnych');

  // ── Objaw: stopka wp-admin nad treścią ────────────────────────────────
  /*
   * Walidator wyżej wskazuje PRZYCZYNĘ. Ten blok pokazuje OBJAW, o którym
   * pisał użytkownik — „info ze stopki jest w połowie strony" — i pilnuje, że
   * naprawa faktycznie go zdejmuje, a nie tylko uspokaja licznik.
   *
   * Atrapa `admin-footer.html` wkłada zakładkę przez `document.write`, czyli
   * w strumień parsowania. To jedyny sposób, żeby PRAWDZIWY parser zobaczył
   * markup taki, jaki wychodzi z PHP: `innerHTML` z `admin-tabs.html` naprawia
   * złe zagnieżdżenie po cichu i usterka znika przed pomiarem.
   *
   * Trzy zakładki, nie wszystkie: strażnikiem dla całego zestawu jest walidator
   * (tani, bez przeglądarki), a tu chodzi o dowód na dwóch zgłoszonych
   * przypadkach i o kontrolę, która musi wyjść zielono.
   */
  t.section('stopka wp-admin nie wchodzi na treść');

  for (const slug of ['whitelabel', 'nl-lists', 'darkmode']) {
    /* Skrypty zakładki zdejmujemy jak w atrapie pomiarowej — mierzymy
       strukturę, a jQuery tu nie ma. */
    const surowy = phpOutput('tab.php', slug).replace(/<script\b[\s\S]*?<\/script>/gi, '');
    const f = await t.open('admin-footer.html', {
      viewport: { width: 1400, height: 900 },
      head: 'window.__tab = ' + JSON.stringify(surowy) + ';',
      settle: 60,
    });
    const s = await f.evaluate(() => ({
      rodzic: document.getElementById('wpfooter').parentElement.id,
      ...window.__stopka(),
    }));
    await f.close();

    /* Ostrzejsze z dwóch pytań: przy nadmiarowym `</div>` stopka wypada poza
       `#wpwrap` i traci przodka pozycjonującego. To widać niezależnie od tego,
       jak wysoka jest treść zakładki. */
    t.check('„' + slug + '" — stopka zostaje w #wpwrap', s.rodzic === 'wpwrap',
      s.rodzic ? 'rodzic: #' + s.rodzic : 'stopka wypadła poza #wpwrap');

    /* I to, co użytkownik naprawdę widzi. */
    t.check('„' + slug + '" — stopka pod treścią, nie na niej', s.stopka >= s.tresc,
      s.stopka >= s.tresc ? 'stopka ' + s.stopka + ' px, treść do ' + s.tresc + ' px'
                          : 'stopka ' + (s.tresc - s.stopka) + ' px NAD dolną krawędzią treści');
  }

  /* Liczniki przez wszystkie zakładki. Sprawdzenia per zakładka są warunkowe
     („żadna ramka nie jest rozwinięta" przechodzi też przy zerze ramek), więc
     na koniec pytamy jeszcze, czy w ogóle było co mierzyć. */
  let seenNotes = 0, seenStates = 0, seenTips = 0;

  for (const slug of TABS) {
    t.section('zakładka „' + slug + '"');

    const head = 'window.__tab = ' + JSON.stringify(phpOutput('tab.php', slug)) + ';';
    const p = await t.open('admin-tabs.html', {
      viewport: { width: 1400, height: 900 }, head, settle: 120,
    });

    // ── Pola i listy ────────────────────────────────────────────────────
    const ctrl = await p.evaluate(() => window.__controls());

    const badH = ctrl.filter((c) => c.h !== FIELD.h);
    t.check('jednolita wysokość ' + FIELD.h + ' px', !badH.length,
      badH.slice(0, 4).map((c) => c.where + ': ' + c.h + 'px').join(' | ') ||
      ctrl.length + ' kontrolek równo');

    const badR = ctrl.filter((c) => c.radius !== FIELD.radius);
    t.check('promień ' + FIELD.radius, !badR.length,
      badR.slice(0, 4).map((c) => c.where + ': ' + c.radius).join(' | ') || 'zgodne');

    const badB = ctrl.filter((c) => !near(rgb(c.border), FIELD.border, 2));
    t.check('ramka w kolorze pola', !badB.length,
      badB.slice(0, 4).map((c) => c.where + ': ' + c.border).join(' | ') || 'zgodne');

    const badS = ctrl.filter((c) => c.size !== FIELD.size);
    t.check('rozmiar tekstu ' + FIELD.size, !badS.length,
      badS.slice(0, 4).map((c) => c.where + ': ' + c.size).join(' | ') || 'zgodne');

    // ── Pola wieloliniowe ───────────────────────────────────────────────
    const ta = await p.evaluate(() => window.__textareas());
    const badTa = ta.filter((c) => c.radius !== FIELD.radius || !near(rgb(c.border), FIELD.border, 2));
    t.check('pola wieloliniowe w skórze', !badTa.length,
      badTa.map((c) => c.where + ': ' + c.radius + ' / ' + c.border).join(' | ') ||
      ta.length + ' szt. zgodnych');

    // ── Przyciski ───────────────────────────────────────────────────────
    const btn = await p.evaluate(() => window.__buttons());

    // Zakładka musiała się w ogóle wyrenderować — inaczej cała reszta tego
    // bloku przechodzi na pustej liście i test nie sprawdza niczego.
    // Liczymy wszystko, co ma kształt: „Mapa strony" to same checkboxy
    // i przyciski, bez ani jednego pola tekstowego.
    const tog = await p.evaluate(() => window.__toggles());
    const crd = await p.evaluate(() => window.__cards());
    t.check('jest co mierzyć', ctrl.length + ta.length + btn.length + tog.length + crd.length > 0,
      ctrl.length + ' kontrolek, ' + ta.length + ' pól wieloliniowych, ' +
      btn.length + ' przycisków, ' + tog.length + ' przełączników, ' +
      crd.length + ' kart');

    const badBtn = btn.filter((b) => b.radius !== BTN_RADIUS);
    t.check('przyciski o promieniu ' + BTN_RADIUS, !badBtn.length,
      badBtn.slice(0, 4).map((b) => b.where + ': ' + b.radius).join(' | ') ||
      btn.length + ' szt. zgodnych');

    const prim = btn.filter((b) => b.primary);
    const badPrim = prim.filter((b) => !near(rgb(b.bg), ACCENT, 2));
    t.check('przycisk główny w akcencie', !prim.length || !badPrim.length,
      badPrim.map((b) => b.bg).join(', ') || prim.length + ' szt.');

    // ── Boksy sekcji ────────────────────────────────────────────────────
    if (BOXED.includes(slug)) {
      const boxes = await p.evaluate(() => window.__boxes());
      t.check('są boksy sekcji', boxes.length > 0, boxes.length + ' szt.');

      const badBox = boxes.filter((b) =>
        b.bg !== BOX.bg || b.border !== BOX.border || b.radius !== BOX.radius);
      t.check('boksy w jednym kształcie', !badBox.length,
        badBox.slice(0, 3).map((b) => b.text + ': ' + b.bg + ' / ' + b.border + ' / ' + b.radius)
          .join(' | ') || boxes.length + ' szt. zgodnych');

      // Nagłówek jest częścią wzorca, nie ozdobą — bez wersalikowej etykiety
      // boks jest tylko ramką wokół treści.
      const titled = boxes.filter((b) => b.title);
      t.check('każdy boks ma nagłówek', titled.length === boxes.length,
        titled.length + ' z ' + boxes.length);
      const badTitle = titled.filter((b) =>
        b.title.size !== BOX_TITLE.size || b.title.weight !== BOX_TITLE.weight ||
        b.title.transform !== BOX_TITLE.transform);
      t.check('nagłówki jako wersalikowa etykieta', !badTitle.length,
        badTitle.map((b) => b.text + ': ' + b.title.size + '/' + b.title.weight +
          '/' + b.title.transform).join(' | ') || 'zgodne');
    }

    // ── Opisy w akordeonach, ostrzeżenia na wierzchu ────────────────────
    const notes  = await p.evaluate(() => window.__notes());
    const states = await p.evaluate(() => window.__stateBoxes());
    seenNotes  += notes.length;
    seenStates += states.length;

    const openNotes = notes.filter((n) => n.visible);
    t.check('opisy zwinięte przy wejściu', !openNotes.length,
      openNotes.length ? openNotes.length + ' rozwiniętych, np. „' + openNotes[0].label + '"'
                       : notes.length + ' szt. zwiniętych');

    // Druga połowa sprawdzenia — bez niej „nic nie jest widoczne" przechodzi
    // także dla zakładki, w której schowano WSZYSTKO, łącznie z ostrzeżeniami.
    const hiddenStates = states.filter((s) => !s.visible);
    t.check('ramki ze stanem zostają widoczne', !hiddenStates.length,
      hiddenStates.map((s) => s.cls).join(' | ') || states.length + ' szt. widocznych');

    const tips = await p.evaluate(() => window.__tips());
    seenTips += tips.length;
    const badTips = tips.filter((x) => !x.tip || x.aria !== x.tip || !x.tabbable || !x.hidden);
    t.check('dymki mają nazwę, fokus i są schowane', !badTips.length,
      badTips.map((x) => (x.tip ? x.tip.slice(0, 24) : '(bez treści)')
        + (x.aria !== x.tip ? ' — aria≠tip' : '')
        + (!x.tabbable ? ' — poza tabulatorem' : '')
        + (!x.hidden ? ' — widoczny w spoczynku' : '')).join(' | ')
      || tips.length + ' szt. zgodnych');

    // ── Kolory z palety, nie z atrybutu ─────────────────────────────────
    // Inline'owy `color:#6b7280` wygląda dziś tak samo jak token, ale przestaje
    // za nim nadążać. To jedyna rzecz, którą przejście na tokeny miało załatwić
    // — a której sam pomiar wyglądu nie wychwyci, dopóki wartości są zgodne.
    const hard = await p.evaluate(() => window.__hardColors());
    t.check('kolory z palety, nie z atrybutu style', !hard.length,
      hard.length
        ? hard.length + ' miejsc, np. ' + hard.slice(0, 3)
            .map((h) => h.where + ' → ' + h.decls.join('; ')).join(' | ')
        : 'brak literałów');

    t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
    await p.close();

    // ── Wąski ekran ─────────────────────────────────────────────────────
    // Zakładki newslettera to w większości ekrany listowe: sidebar o stałej
    // szerokości i tabele. Zmierzone przed poprawką: raporty rozpychały stronę
    // do 682 px przy oknie 390 px, szablony do 449 px.
    // 360 px to nie kaprys: pasek „Logów zdarzeń" MIEŚCIŁ się na 390 px co do
    // piksela, a w prawdziwym panelu wp-admin dokłada własne wcięcia, więc
    // realna szerokość treści jest mniejsza niż okno. Usterkę widać dopiero
    // przy węższym oknie — i to ona przyszła ze zgłoszenia.
    if (MOBILE.includes(slug)) {
      for (const vw of [390, 360]) {
        const m = await t.open('admin-tabs.html', {
          viewport: { width: vw, height: 900 }, head, settle: 150,
        });
        const o = await m.evaluate(() => window.__overflow());

        t.check(vw + ' px — nic nie wystaje poza ekran', o.count === 0,
          o.items.map((x) => x.tag + '.' + x.cls + ' +' + x.over + 'px').join(' | ')
          || 'czysto');
        t.check(vw + ' px — strona nie przewija się w poziomie', o.doc <= o.win + 1,
          o.doc + ' vs ' + o.win);

        // Wystawanie poza okno to tylko połowa. Druga połowa jest gorsza, bo
        // niewidoczna: karta z `overflow-x: hidden` chowa to, co się w niej
        // nie mieści, i przycisk po prostu przestaje istnieć dla klikającego.
        const c = await m.evaluate(() => window.__clipped());
        t.check(vw + ' px — nic nie jest ucięte przez kontener', c.count === 0,
          c.items.map((x) => '.' + x.cls + ' ucina ' + x.cut + 'px („' + x.what + '")')
            .join(' | ') || 'czysto');

        // Kafelki statystyk mają zostać czytelne, a nie tylko zmieścić się.
        // Sztywne `repeat(5, 1fr)` MIEŚCIŁO się na 390 px — po prostu ściskało
        // kafelek do ~70 px i etykieta łamała się na trzy linie.
        const st = await m.evaluate(() => window.__stats());
        if (st.length) {
          const tight = st.filter((w) => w < 110);
          t.check(vw + ' px — kafelki statystyk czytelne', !tight.length,
            tight.length ? tight.join(', ') + 'px' : st.length + ' szt. po ' + st[0] + 'px');
        }

        await m.close();
      }
    }
  }

  // Kontrola sensowności całego bloku wyżej.
  t.section('było co mierzyć');
  t.check('akordeony w ogóle istnieją', seenNotes > 0, seenNotes + ' szt. w ' + TABS.length + ' zakładkach');
  /* LICZBA, nie „więcej niż zero". Sprawdzenie wyżej łapie ramkę ze stanem,
     którą ktoś UKRYŁ — ale nie taką, którą zamienił w akordeon: wtedy ramka
     znika z pomiaru i „wszystkie widoczne" jest prawdą przy zerze ramek.
     Potwierdzone celowym zepsuciem: podmiana `is-ok` na `evo-note`
     w zakładce dostępności nie zapaliła niczego. Próg rośnie tylko wtedy,
     gdy dołoży się zakładkę — nigdy sam nie spada. */
  t.check('ramki ze stanem nie zostały schowane do akordeonów', seenStates >= 3,
    seenStates + ' szt. (oczekiwane co najmniej 3)');
  t.check('dymki w ogóle istnieją', seenTips > 0, seenTips + ' szt.');
};
