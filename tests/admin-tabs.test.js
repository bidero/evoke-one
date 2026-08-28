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
              'fe-themecolor', 'fe-parallax', 'fe-elementy', 'fe-newsletter', 'fe-newsletter-on'];

/** Zakładki mierzone też na wąskim ekranie. */
const MOBILE = ['schema', 'sitemap', 'seo-meta',
                'nl-lists', 'nl-campaigns', 'nl-templates', 'nl-reports', 'nl-settings',
                /* Cztery podstrony z tabelami — ten sam kształt, który w 1.48.0
                   rozpychał Raporty do 682 px przy oknie 390 px. */
                'sec-login', 'tools-smtp', 'tools-redirect', 'tools-logs404',
                /* Role mają tabelę uprawnień — ten sam kształt. */
                'adm-roles'];

/** Zakładki, które mają już treść w boksach. */
const BOXED = ['forminbox', 'a11y', 'darkmode', 'og', 'whitelabel',
               'sec-hardening', 'tools-smtp', 'tools-logs404',
               'adm-interface', 'adm-dashboard', 'adm-content',
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
