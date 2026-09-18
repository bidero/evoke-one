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
const ROG = [770, 570];   // róg przeciwległy do przycisku — fala dochodzi tam na końcu

/** Wstrzykuje PRAWDZIWY moduł z 93-darkmode.php i klika w przełącznik. */
async function zapal(p, ustawienia) {
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
  /* SEDNO ZGŁOSZENIA. Listy selektorów są tu PUSTE — dokładnie ta konfiguracja,
     przy której objaw był widoczny. Karta w rogu ma kolor ze zmiennej CSS, tak
     jak elementy Bricksa, i nie jest wymieniona nigdzie. */
  t.section('karta spoza list czeka, aż fala po niej przejdzie');

  const start = await wPunkcie(p, 0, ROG);
  t.check('na starcie karta jest jasna', start > 250, 'jasność ' + start);

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
  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();
};
