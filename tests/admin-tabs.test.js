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
const TABS = ['forminbox', 'a11y', 'darkmode', 'og', 'whitelabel'];

/** Zakładki, które mają już treść w boksach. */
const BOXED = ['forminbox', 'a11y', 'darkmode', 'og', 'whitelabel'];

module.exports = async function (t) {
  for (const slug of TABS) {
    t.section('zakładka „' + slug + '"');

    const head = 'window.__tab = ' + JSON.stringify(phpOutput('tab.php', slug)) + ';';
    const p = await t.open('admin-tabs.html', {
      viewport: { width: 1400, height: 900 }, head, settle: 120,
    });

    // ── Pola i listy ────────────────────────────────────────────────────
    const ctrl = await p.evaluate(() => window.__controls());
    t.check('są kontrolki do zmierzenia', ctrl.length > 0, ctrl.length + ' szt.');

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
  }
};
