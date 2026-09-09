/**
 * Potwierdzenie zapisu — jeden wygląd w całym panelu.
 *
 * ZGŁOSZONE Z UŻYCIA: „czasami jest przesunięty maksymalnie w prawo, a czasami
 * czarny. Musi być jednolicie".
 *
 * Jedno i drugie było prawdą, bo potwierdzeń było PIĘĆ RÓŻNYCH: zielony
 * `.evo-save-msg` obok przycisku (4 ekrany), zielony `.tl-save-status` z własną
 * klasą i stylem wpisanym w PHP (6 ekranów tłumaczeń), CZARNE natywne
 * powiadomienie WordPressa u góry strony (Logi 404, snippety, newsletter),
 * napis wpisywany w sam przycisk (Meta SEO) — a na dwudziestu pięciu paskach
 * zapisu nie było niczego. „Maksymalnie w prawo" brało się z `margin-left: auto`,
 * które odpychało komunikat na drugi koniec paska.
 *
 * SPRAWDZENIE MIERZY JEDNOLITOŚĆ, nie obecność. Pytanie nie brzmi „czy ekran ma
 * potwierdzenie", tylko „czy wszystkie wyglądają tak samo i stoją w tym samym
 * miejscu" — bo to właśnie różnice były usterką.
 */

const fs   = require('fs');
const path = require('path');
const { phpOutput, rgb } = require('./lib/harness');

/* Ekrany brane z listy, którą i tak utrzymuje `admin-tabs` — nie z własnego
   spisu, bo drugi spis rozjeżdża się z pierwszym przy pierwszym nowym ekranie. */
const TABS = require('fs').readFileSync(path.join(__dirname, 'admin-tabs.test.js'), 'utf8')
  .match(/const TABS = \[([\s\S]*?)\];/)[1]
  .match(/'[^']+'/g).map((s) => s.replace(/'/g, ''));

/* OTOCZKA JAK NA ŻYWO, i to nie jest ozdoba pomiaru.
   `.wrap` niesie zmienne kolorów panelu (są zadeklarowane właśnie tam, nie na
   `:root`), a `.evo-panel` jest w selektorze zapisu bez przeładowania
   (`.evo-panel form[action$="options.php"]`). Zakładka wstrzyknięta bez tych
   dwóch przodków renderuje się na czarno i nie łapie zapisu — czyli pomiar
   mówi o czymś, czego użytkownik nigdy nie zobaczy. Zmierzone dwa razy. */
const wPanelu = (html) =>
  '<div class="wrap evo-control-center"><div class="evo-panel">' + html + '</div></div>';

module.exports = async function (t) {

  // ── Jedno źródło znaczników ────────────────────────────────────────────
  t.section('paski zapisu wychodzą z jednej funkcji');

  const zrodla = (function zbierz(kat, akc) {
    for (const wpis of fs.readdirSync(kat, { withFileTypes: true })) {
      const p = path.join(kat, wpis.name);
      if (wpis.isDirectory()) zbierz(p, akc);
      else if (wpis.name.endsWith('.php')) akc.push([p, fs.readFileSync(p, 'utf8')]);
    }
    return akc;
  })(path.join(__dirname, '..', 'includes'), []);

  /* Pasek zbudowany z ręki to pasek, który może wyglądać inaczej niż reszta —
     i dokładnie tak powstało pięć wariantów potwierdzenia. Wyjątki są nazwane:
     przycisk typu `button` z własnym `onclick` i przyciski o własnych nazwach
     pola, których `submit_button()` z funkcji panelu nie umie. */
  const WYJATKI = ['seo/tab-sitemap.php', 'snippets/panel.php',
                   'newsletter/tab-settings.php', 'tools-logs404.php',
                   'tl/tab-'];
  const reczne = [];
  for (const [p, s] of zrodla) {
    if (!/<div class="evo-save-bar"/.test(s)) continue;
    // `helpers.php` to źródło paska, nie jego kopia.
    if (p.endsWith('admin/helpers.php')) continue;
    if (WYJATKI.some((w) => p.includes(w))) continue;
    reczne.push(path.relative(path.join(__dirname, '..', 'includes'), p));
  }
  t.check('żaden zwykły pasek nie jest budowany z ręki', !reczne.length,
    reczne.join(', ') || 'wszystkie z evoke_one_pasek_zapisu()');

  /* Kontrola pozytywna: gdyby funkcji nikt nie wołał, sprawdzenie wyżej
     przechodziłoby na pusto. */
  const wolania = zrodla.filter(([, s]) => /evoke_one_(pasek|komunikat)_zapisu\(/.test(s)).length;
  t.check('i jest ich z czego zbudować', wolania >= 20, wolania + ' plików woła funkcję panelu');

  /* Klasa ma być JEDNA. Własna klasa o tym samym wyglądzie to dwa opisy tej
     samej rzeczy, z których jeden zawsze zostaje w tyle — tak żyło
     `.tl-save-status` przez sześć ekranów tłumaczeń. */
  const wlasneKlasy = zrodla
    .filter(([p, s]) => /tl-save-status|evk-save-status/.test(s) && !p.endsWith('helpers.php'))
    .map(([p]) => path.basename(p));
  t.check('i nikt nie ma własnej klasy komunikatu', !wlasneKlasy.length,
    wlasneKlasy.join(', ') || 'jedna klasa: evo-save-msg');

  // ── Ten sam znacznik na każdym ekranie ─────────────────────────────────
  t.section('każde potwierdzenie ma tę samą treść i rolę');

  const zEkranu = (slug, query) => {
    let html = '';
    try { html = phpOutput('tab.php', slug + (query ? ' ' + JSON.stringify(JSON.stringify(query)) : '')); }
    catch (e) { return { blad: String(e.message).split('\n')[0] }; }
    const m = html.match(/<span class="(evo-save-msg[^"]*)"([^>]*)>([^<]*)</);
    return m ? { klasa: m[1], atrybuty: m[2], tekst: m[3] } : null;
  };

  const zPaskiem = [];
  const rozne = [];
  const bezRoli = [];
  for (const slug of TABS) {
    const w = zEkranu(slug);
    if (w && w.blad) { rozne.push(slug + ': ' + w.blad); continue; }
    if (!w) continue;
    zPaskiem.push(slug);
    if (w.tekst !== '✓ Zapisano' && w.tekst !== '') rozne.push(slug + ': „' + w.tekst + '"');
    if (!/role="status"/.test(w.atrybuty)) bezRoli.push(slug);
  }

  t.check('jest co porównywać — ekranów z potwierdzeniem jest wiele',
    zPaskiem.length >= 20, zPaskiem.length + ' z ' + TABS.length + ' ekranów');
  t.check('wszystkie mówią to samo', !rozne.length,
    rozne.join(' | ') || '„✓ Zapisano" wszędzie');
  /* Bez przeładowania i bez powiadomienia WordPressa niewidzący nie dostaje
     żadnego sygnału, że zapis się wydarzył. */
  t.check('i wszystkie są czytane przez czytnik ekranu', !bezRoli.length,
    bezRoli.join(', ') || 'role="status" wszędzie');

  // ── Widoczność wynika ze stanu, nie z ekranu ───────────────────────────
  t.section('potwierdzenie pokazuje się po zapisie, nie przed');

  const ukryte = [];
  const nieodslonione = [];
  for (const slug of zPaskiem) {
    const przed = zEkranu(slug);
    const po    = zEkranu(slug, { 'settings-updated': 'true' });
    if (przed && /is-widoczny/.test(przed.klasa)) ukryte.push(slug);
    /* Ekrany na Settings API wracają z `?settings-updated` — te mają odsłonić
       komunikat same, bez ani jednej linii u siebie. Ekrany z własnym zapisem
       albo z zapisem AJAX-em odsłaniają go inaczej i tu nie wchodzą. */
    if (po && !/is-widoczny/.test(po.klasa)) nieodslonione.push(slug);
  }
  t.check('przed zapisem żaden nie jest widoczny', !ukryte.length,
    ukryte.join(', ') || zPaskiem.length + ' ekranów');
  t.check('a po powrocie z zapisu odsłania się sam',
    nieodslonione.length < zPaskiem.length,
    (zPaskiem.length - nieodslonione.length) + ' z ' + zPaskiem.length + ' odsłania się');

  // ── Wygląd i miejsce, zmierzone w przeglądarce ─────────────────────────
  t.section('zielony tekst tuż na prawo od przycisku');

  /* Kolor i pozycja MIERZONE, nie odczytane z arkusza: zgłoszenie mówiło
     o obu tych rzeczach naraz, a obie zależą od reguł, które wygrywają
     kaskadę — czego w źródle nie widać. */
  const slug = 'darkmode';
  const strona = await t.open('panel-start.html', {
    viewport: { width: 1400, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(wPanelu(
      phpOutput('tab.php', slug + ' ' + JSON.stringify(JSON.stringify({ 'settings-updated': 'true' }))))) + ';',
  });

  const pomiar = await strona.evaluate(() => {
    const msg = document.querySelector('.evo-save-msg');
    const bar = msg && msg.closest('.evo-save-bar');
    const btn = bar && bar.querySelector('[type=submit], button');
    if (!msg || !btn) return null;
    const m = msg.getBoundingClientRect(), b = btn.getBoundingClientRect(),
          p = bar.getBoundingClientRect();
    return {
      kolor: getComputedStyle(msg).color,
      widoczny: getComputedStyle(msg).display !== 'none',
      odstep: Math.round(m.left - b.right),
      doKonca: Math.round(p.right - m.right),
      szerokoscPaska: Math.round(p.width),
    };
  });

  t.check('komunikat jest w pasku i widoczny', !!pomiar && pomiar.widoczny,
    pomiar ? JSON.stringify(pomiar) : 'brak komunikatu');

  const [r, g, b] = rgb(pomiar.kolor);
  t.check('jest zielony', g === Math.max(r, g, b) && g - r > 60, pomiar.kolor);

  /* TO JEST TA POŁOWA ZGŁOSZENIA O „MAKSYMALNIE W PRAWO". Odstęp od przycisku
     ma być mały; do prawej krawędzi paska ma zostać dużo miejsca. Przy
     `margin-left: auto` było odwrotnie i komunikat wypadał metr od tego,
     czego dotyczył. */
  t.check('stoi tuż przy przycisku, a nie na drugim końcu paska',
    pomiar.odstep <= 16 && pomiar.doKonca > pomiar.szerokoscPaska / 3,
    pomiar.odstep + ' px od przycisku, ' + pomiar.doKonca + ' px do końca paska ('
      + pomiar.szerokoscPaska + ' px)');

  await strona.close();

  // ── To, co widać PO ZAPISIE ────────────────────────────────────────────
  t.section('po zapisie widać jeden komunikat, ten sam co wszędzie');

  /* TU BYŁA DZIURA W POPRZEDNIM WYDANIU. Sprawdzenia pilnowały znaczników
     WYRENDEROWANYCH PRZEZ PHP — a napis, który użytkownik ogląda po zapisie,
     dokłada JavaScript. Znaczniki były jednolite, ekran nie: na jednym stało
     „Zapisano" bez ptaszka, na drugim szare „Zapisano.", a na trzecim OBA
     NARAZ, bo w panelu działały dwa niezależne mechanizmy zapisu bez
     przeładowania. Zgłoszone zrzutami ekranu.

     Dlatego mierzymy stan PO wysłaniu formularza, w przeglądarce. */
  /* NAPIS BIERZEMY Z PRODUKTU, nie wpisujemy go w fixture. Pierwsza wersja
     podstawiała tu „✓ Zapisano" z siebie — i mutacja zmieniająca napis
     w `page.php` PRZECHODZIŁA NA ZIELONO, bo test czytał własną atrapę zamiast
     tego, co panel naprawdę wysyła do przeglądarki. Zmierzone. */
  const zrodloPage = fs.readFileSync(
    path.join(__dirname, '..', 'includes', 'admin', 'page.php'), 'utf8');
  const napisZPanelu = (zrodloPage.match(/'saved'\s*=>\s*__\('([^']*)'/) || [, ''])[1];

  t.check('napis wysyłany do przeglądarki jest ten sam, co w znacznikach',
    napisZPanelu === '✓ Zapisano', '„' + napisZPanelu + '"');

  const poZapisie = await t.open('panel-start.html', {
    viewport: { width: 1400, height: 900 },
    head: 'window.evkSettingsSave = { url: "about:blank", saving: "Zapisuję…",'
        + ' saved: ' + JSON.stringify(napisZPanelu) + ', failed: "Nie udało się zapisać" };'
        + 'window.__panel = ' + JSON.stringify(wPanelu(phpOutput('tab.php', 'darkmode'))) + ';',
  });

  await poZapisie.evaluate(() => {
    window.jQuery.post = function () {
      return window.jQuery.Deferred().resolve({ success: true, data: {} }).promise();
    };
    document.querySelector('form input[name=option_page]').form.requestSubmit();
  });
  await poZapisie.waitForTimeout(250);

  const widoczne = await poZapisie.evaluate(() => {
    const bar = document.querySelector('.evo-save-bar');
    /* Liczymy KAŻDY element potwierdzenia w pasku, nie tylko widoczny w tej
       chwili. Dwa mechanizmy zapisu dokładały dwa osobne elementy; jeden bywał
       chwilowo ukryty, więc filtr po widoczności przepuszczał taką parę —
       zmierzone mutacją. Element potwierdzenia poznajemy po treści. */
    const wszystkie = [...bar.querySelectorAll('span')]
      .filter((el) => /Zapisano/.test(el.textContent))
      .map((el) => ({ tekst: el.textContent.trim(), kolor: getComputedStyle(el).color,
                      klasa: el.className, widoczny: getComputedStyle(el).display !== 'none' }));
    return wszystkie;
  });

  /* JEDEN. To sprawdzenie złapałoby zrzut, na którym „✓ Zapisano" stało obok
     szarego „Zapisano." — dwa mechanizmy potwierdzały ten sam zapis. */
  t.check('w pasku jest dokładnie jeden komunikat', widoczne.length === 1,
    widoczne.map((w) => '„' + w.tekst + '" (' + w.klasa + ')').join(' + ') || 'żadnego');

  t.check('i mówi to samo, co reszta panelu',
    widoczne.length === 1 && widoczne[0].tekst === '✓ Zapisano',
    widoczne.length ? '„' + widoczne[0].tekst + '"' : 'brak');

  t.check('i jest widoczny', widoczne.length === 1 && widoczne[0].widoczny,
    widoczne.length ? (widoczne[0].widoczny ? 'widoczny' : 'ukryty') : 'brak');

  if (widoczne.length === 1) {
    const [r2, g2, b2] = rgb(widoczne[0].kolor);
    t.check('i jest zielony, nie szary', g2 === Math.max(r2, g2, b2) && g2 - r2 > 60,
      widoczne[0].kolor);
  }

  await poZapisie.close();

  // ── Napisy w kodzie ────────────────────────────────────────────────────
  t.section('nikt nie wypisuje własnego wariantu napisu');

  /* Znaczniki i JavaScript to dwa źródła tego samego napisu. Dopóki wolno
     wpisać go z ręki, wraca w wariantach: „Zapisano", „Zapisano.", „Zapisano!".
     Sprawdzenie zbiera je z całego kodu i wymaga jednego brzmienia. */
  /* Liczy się TO, CO TRAFIA NA EKRAN PANELU. Poza zakresem zostają:
     — `wp_send_json_success('Zapisano.')`, czyli ładunek odpowiedzi AJAX-a,
       którego żaden ekran nie wypisuje (JS podaje własny napis);
     — edytor frontowy (`41-frontend-inline-editor.php`) — inna powierzchnia,
       nie panel;
     — komunikaty niosące LICZBĘ zapisanych rzeczy („Zapisano 3 z 5") i te
       z dodatkową instrukcją („— odśwież stronę"): to inna treść, nie inny
       wariant tego samego napisu. Zaczynają się tak samo i o to chodzi. */
  const doEkranu = (p) => (p.includes('/admin/') || p.includes('/snippets/'))
    && !p.includes('41-frontend-inline-editor');

  const warianty = new Set();
  const zbierz = (tresc) => {
    for (const m of tresc.matchAll(/\.text\(\s*'([^']*Zapisano[^']*)'/g)) warianty.add(m[1]);
    for (const m of tresc.matchAll(/\?\s*'([^']*Zapisano[^']*)'\s*:/g)) warianty.add(m[1]);
  };
  for (const [p, s] of zrodla) {
    if (p.endsWith('admin/helpers.php')) continue;          // źródło napisu
    if (!doEkranu(p)) continue;
    zbierz(s);
  }
  zbierz(fs.readFileSync(path.join(__dirname, '..', 'assets', 'admin', 'admin.js'), 'utf8'));

  const obce = [...warianty].filter((w) => !w.startsWith('✓ Zapisano'));
  t.check('każdy napis potwierdzenia zaczyna się tak samo', !obce.length,
    obce.map((w) => '„' + w + '"').join(', ')
      || [...warianty].map((w) => '„' + w + '"').join(', '));
};
