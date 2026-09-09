/**
 * Przekierowania 301 i Logi 404 — włącznik na AJAX, jak reszta panelu.
 *
 * DWIE HISTORIE, KTÓRE TU PILNUJEMY, obie zapłacone wydaniem:
 *
 * 1. Do 1.14.4 włącznik 301 miał JEDNOCZEŚNIE `data-option` i
 *    `onchange="this.form.submit()"` — dwa niezależne sterowniki na jedno
 *    kliknięcie: uchwyt AJAX zapisywał opcję, a formularz zaraz potem
 *    przeładowywał stronę i zapisywał ją drugi raz. Naprawiono przez zdjęcie
 *    AJAX-a; te dwa moduły zostały jedynymi przełączanymi przeładowaniem.
 *
 * 2. Zapis ustawień Logów 404 ZAWSZE wyłączał moduł, bo formularz nie
 *    przekazywał `evk_404_enabled`, a uchwyt zerował brakujący klucz. Załatano
 *    ukrytym polem z aktualnym stanem.
 *
 * Od 1.166.0 jest odwrotnie i jednoznacznie: został sam AJAX, a formularz
 * włącznika i jego uchwyt POST poszły. Ukryte pole też — przy włączniku
 * AJAX-owym niosłoby WARTOŚĆ SPRZED przełączenia, czyli ta sama klasa błędu
 * co w punkcie 2, tylko odwrócona.
 *
 * Zasada, którą sprawdzenia wyrażają: JEDNA OPCJA — JEDEN STEROWNIK.
 */

const { phpOutput } = require('./lib/harness');

const ekran = (slug, post, opcje) =>
  phpOutput('tab.php', slug + " '' " + JSON.stringify(JSON.stringify(post || {}))
    + ' ' + JSON.stringify(JSON.stringify(opcje || {})));

const stan404 = (html) => (html.match(/Logi 404: (WŁĄCZONE|WYŁĄCZONE)/) || [, '?'])[1];

module.exports = async function (t) {

  // ── Jeden sterownik ────────────────────────────────────────────────────
  t.section('włącznik ma dokładnie jedną drogę zapisu');

  for (const [slug, opcja, nazwa] of [
    ['tools-redirect', 'evk_301_enabled', 'Przekierowania 301'],
    ['tools-logs404',  'evk_404_enabled', 'Logi 404'],
  ]) {
    const html = ekran(slug);

    /* Kontrola pozytywna — bez niej wszystko niżej mierzy nieobecność czegoś,
       czego mogło nigdy nie być na tym ekranie. */
    const pary = [...html.matchAll(/data-option="([^"]+)"[^>]*?data-field="([^"]+)"/g)]
      .map((m) => m[1] + '/' + m[2]);
    t.check('„' + nazwa + '" ma włącznik AJAX', pary.includes(opcja + '/_scalar'),
      pary.join(', ') || 'brak data-option');

    /* SEDNO. Włącznik wewnątrz formularza to druga droga zapisu obok AJAX-a —
       dokładnie układ, który w 1.14.4 strzelał podwójnie. Bierzemy kawałek
       znacznika od karty stanu do jej końca i pytamy, czy nie ma w nim `<form`. */
    const karta = (html.match(/<div class="evo-status-card">[\s\S]*?<\/div>\s*<\/div>/) || [''])[0];
    t.check('i nie siedzi w formularzu', !/<form/.test(karta),
      /<form/.test(karta) ? 'formularz wokół włącznika' : 'sam przełącznik');

    t.check('ani nie wysyła formularza z ręki',
      !/onchange="[^"]*submit\(\)/.test(html),
      /onchange="[^"]*submit\(\)/.test(html) ? 'onchange → submit' : 'brak auto-submitu');
  }

  // ── Zapis ustawień a stan włącznika ────────────────────────────────────
  t.section('zapis ustawień Logów 404 nie dotyka włącznika');

  /* Formularz ustawień renderuje się RAZ, przy wejściu na ekran. Gdyby wiózł
     kopię stanu włącznika, to po przełączeniu go AJAX-em i zapisaniu ustawień
     wracałaby wartość sprzed przełączenia — moduł gasiłby się sam, a ekran po
     zapisie wyglądałby poprawnie. */
  const zapis = { evk_404_save: '1', evk_404_max_logs: '500', evk_404_skip_bots: '1' };

  const wlaczone = ekran('tools-logs404', zapis, { evk_404_enabled: 1 });
  t.check('włączone zostaje włączone', stan404(wlaczone) === 'WŁĄCZONE', stan404(wlaczone));

  /* I odwrotnie — inaczej „nie dotyka" znaczyłoby „zawsze włącza". */
  const wylaczone = ekran('tools-logs404', zapis, { evk_404_enabled: 0 });
  t.check('a wyłączone zostaje wyłączone', stan404(wylaczone) === 'WYŁĄCZONE', stan404(wylaczone));

  /* Kontrola pozytywna dla samego zapisu: gdyby uchwyt POST w ogóle się nie
     wykonał, oba sprawdzenia wyżej przechodziłyby nie mierząc niczego. */
  t.check('a pozostałe ustawienia zapisują się normalnie',
    /name="evk_404_max_logs" value="500"/.test(wlaczone),
    (wlaczone.match(/name="evk_404_max_logs" value="(\d+)"/) || [, 'brak'])[1]);

  /* Ukryte pole ze stanem włącznika ma zniknąć z formularza — dopóki tam stoi,
     powyższe działa tylko dlatego, że uchwyt je ignoruje. Dwie linie obrony
     w miejscu, gdzie już raz było o jedną za mało. */
  t.check('a formularz nie wiezie już kopii stanu włącznika',
    !/name="evk_404_enabled"/.test(wlaczone),
    /name="evk_404_enabled"/.test(wlaczone) ? 'ukryte pole nadal jest' : 'brak kopii');

  // ── Jedno kliknięcie, jedno żądanie ────────────────────────────────────
  t.section('jedno kliknięcie to jedno zapisanie');

  /* TO JEST POMIAR PODWÓJNEGO STRZAŁU, nie jego opis. Znaczniki wyżej mówią,
     że drugiej drogi nie widać w kodzie; tutaj klikamy naprawdę i liczymy.
     Zero nawigacji jest równie ważne jak jedno żądanie: przeładowanie było
     tą drugą drogą. */
  for (const [slug, opcja, nazwa] of [
    ['tools-redirect', 'evk_301_enabled', 'Przekierowania 301'],
    ['tools-logs404',  'evk_404_enabled', 'Logi 404'],
  ]) {
    const strona = await t.open('panel-start.html', {
      viewport: { width: 1200, height: 900 },
      head: 'window.evkToggle = { url: "about:blank", nonce: "x" };'
          + 'window.__zadania = []; window.__nawigacje = 0;'
          + '(function () { var o = XMLHttpRequest.prototype.open;'
          + '  XMLHttpRequest.prototype.open = function (m, u) { window.__zadania.push(m + " " + u); return o.apply(this, arguments); };'
          + '}());'
          + 'window.__panel = ' + JSON.stringify(
              '<div class="wrap evo-control-center">' + ekran(slug) + '</div>') + ';',
    });

    await strona.evaluate(() => {
      window.addEventListener('beforeunload', () => { window.__nawigacje++; });
    });
    await strona.click('label.evo-toggle:has(input[data-option="' + opcja + '"]) .evo-slider');
    await strona.waitForTimeout(250);

    const po = await strona.evaluate(() => ({
      zadania: window.__zadania.length, nawigacje: window.__nawigacje,
    }));

    t.check('„' + nazwa + '": jedno żądanie i zero przeładowań',
      po.zadania === 1 && po.nawigacje === 0,
      po.zadania + ' żądań, ' + po.nawigacje + ' nawigacji');

    await strona.close();
  }
};
