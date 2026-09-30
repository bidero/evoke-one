/**
 * Widok „Teksty w elementach" (1.245.0) — tylko do odczytu.
 *
 * Decyzja zgłaszającego: w zakładce Tłumaczeń ma być widać teksty z builderа
 * („dodanie nowego tekstu nie dodaje go do zakładek w Tłumaczeniach").
 * Lista: każdy tekst elementu Bricksa (pola z mapy, także pozycje list
 * i szablony nagłówka), dla każdego języka tłumaczenie i pochodzenie —
 * w elemencie, ze słownika, słownikiem tylko część tekstu, brak — oraz
 * „do sprawdzenia". Pochodzenie słownikowe liczone węzeł po węźle, tak jak
 * słownik tłumaczy stronę.
 *
 * Część pierwsza: prawdziwe dane w testowym WordPressie i HTML listy
 * (filtry, stronicowanie). Część druga: zakładka w Chromium.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => JSON.parse(phpOutput('tl-teksty.php', a.join(' ')));

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const s = sonda('scenariusz');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !s.brak, s.brak || 'jest');
  if (s.brak) return;

  t.section('wiersze: każdy tekst z mapy i pochodzenie w każdym języku');
  const w = s.wiersze || [];
  const jest = (x) => w.includes(x);
  t.check('pole w elemencie wygrywa, obok słownik', jest('A|Treść|heading|Tekst|Kontakt|en:pole:Contact us|de:slownik:Kontakt (DE)'), w.join('\n'));
  t.check('pozycja listy z numerem i nazwą pola; język bez tłumaczenia: brak',
    jest('A|Treść|accordion|pozycja 1 · Tytuł|Jeden|en:slownik:One|de:brak:'), w.join('\n'));
  t.check('dwa akapity, słownik zna jeden: „część", tłumaczone węzeł po węźle',
    jest('A|Treść|accordion|pozycja 2 · Treść|<p>Witaj świecie</p><p>Nieznany tekst</p>|en:czesc:<p>Hello world</p><p>Nieznany tekst</p>|de:czesc:<p>Hallo Welt</p><p>Nieznany tekst</p>'),
    w.join('\n'));
  t.check('„do sprawdzenia" przy języku, którego oryginał zmienił się po tłumaczeniu',
    jest('B|Treść|heading|Tekst|Oferta specjalna|en:pole:Offer:S|de:brak:'), w.join('\n'));
  t.check('szablon nagłówka: część „Nagłówek"', jest('C|Nagłówek|heading|Tekst|Kontakt|en:slownik:Contact|de:slownik:Kontakt (DE)'), w.join('\n'));
  t.check('puste pola i element bez pól pominięte; razem pięć wierszy', w.length === 5, String(w.length));
  t.check('typ spoza mapy: wymieniony, nie w wierszach', JSON.stringify(s.nieznane) === '{"nieznany-element":1}' && !w.some((x) => x.includes('nieznany')),
    JSON.stringify(s.nieznane));
  t.check('liczby: wszystko 5, bez tłumaczenia 3 (brak albo część), do sprawdzenia 1',
    JSON.stringify(s.liczby) === '{"wszystko":5,"braki":3,"sprawdz":1}', JSON.stringify(s.liczby));

  t.section('HTML: filtry, tabela, stronicowanie');
  const h = s.html_wszystko || '';
  // 1.263.0: wiersze mają `data-w` (edytor pod wierszem) — liczy się każdy znacznik <tr.
  const wierszeTabeli = (html) => ((html.match(/<tbody>([\s\S]*?)<\/tbody>/) || ['', ''])[1].match(/<tr[\s>]/g) || []).length;
  t.check('filtry z liczbami, bieżący oznaczony aria-current',
    /aria-current="page">\s*Wszystkie \(5\)<\/a>/.test(h) && /Bez tłumaczenia \(3\)/.test(h) && /Do sprawdzenia \(1\)/.test(h)
      && (h.match(/aria-current="page"/g) || []).length === 1, (h.match(/<nav[\s\S]*?<\/nav>/) || [''])[0]);
  t.check('tabela w przewijanym opakowaniu, nagłówki kolumn: Strona, Element, Polski, EN, DE',
    /<div class="evo-tbl-wrap"><table class="evo-table">/.test(h) && (h.match(/<th scope="col">/g) || []).length === 5
      && /<th scope="col">EN<\/th><th scope="col">DE<\/th>/.test(h), (h.match(/<thead>[\s\S]*?<\/thead>/) || [''])[0]);
  t.check('pochodzenie słowem, „do sprawdzenia" przy wierszu', /w elemencie/.test(h) && /ze słownika/.test(h) && /słownik: część tekstu/.test(h)
    && /brak tłumaczenia/.test(h) && /do sprawdzenia<\/span>/.test(h));
  t.check('odnośnik strony prowadzi do buildera', /href="[^"]*bricks=run[^"]*">Test TL — teksty A<\/a>/.test(h), (h.match(/<td><a [^>]*>Test TL[^<]*<\/a>/) || [''])[0]);
  t.check('tekst bez znaczników w komórce (oryginał z <p> pokazany jako tekst)', !/<p>Witaj/.test(h) && /Witaj świecie Nieznany tekst/.test(h));
  t.check('filtr „bez tłumaczenia": 3 wiersze', wierszeTabeli(s.html_braki) === 3, String(wierszeTabeli(s.html_braki)));
  t.check('filtr „do sprawdzenia": 1 wiersz (strona B)', wierszeTabeli(s.html_sprawdz) === 1 && /Test TL — teksty B/.test(s.html_sprawdz),
    String(wierszeTabeli(s.html_sprawdz)));
  t.check('nieznany filtr: wszystko, wartość z adresu nigdzie niewypisana',
    wierszeTabeli(s.html_nieznany_filtr) === 5 && !/<script>/.test(s.html_nieznany_filtr) && /aria-current="page">\s*Wszystkie/.test(s.html_nieznany_filtr));
  t.check('stronicowanie: po 2 wiersze, strona 2 z 3, odnośniki w obie strony',
    wierszeTabeli(s.html_str2) === 2 && /Strona 2 z 3/.test(s.html_str2) && />Poprzednie</.test(s.html_str2) && />Następne</.test(s.html_str2)
      && /str=3/.test(s.html_str2), (s.html_str2.match(/<nav class="tl-teksty-strony"[\s\S]*?<\/nav>/) || [''])[0]);
  t.check('strona spoza zakresu: ostatnia (3 z 3, 1 wiersz, bez „Następne")',
    /Strona 3 z 3/.test(s.html_str99) && wierszeTabeli(s.html_str99) === 1 && !/>Następne</.test(s.html_str99));
  t.check('bez mapy pól: komunikat „otwórz stronę w builderze", bez tabeli',
    /nie zna jeszcze pól/.test(s.html_bez_mapy) && !/<table/.test(s.html_bez_mapy));

  // ── Zakładka w przeglądarce ─────────────────────────────────────────────
  t.section('zakładka w przeglądarce');
  const u = sonda('ui-ustaw');
  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(u.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=elementy');
    const wiersze = p.locator('.tl-teksty tbody tr', { hasText: 'Test TL — teksty' });
    t.check('lista pod przyciskiem przeniesienia, z wierszami testu', await p.locator('.tl-przeniesienie + .tl-teksty, .tl-przeniesienie ~ .tl-teksty').count() === 1
      && await wiersze.count() === 5, String(await wiersze.count()));
    await p.click('.tl-teksty-filtry a:has-text("Do sprawdzenia")');
    await p.waitForLoadState('load');
    t.check('filtr „Do sprawdzenia" z adresu: jeden wiersz i oznaczenie bieżącego',
      /[?&]pokaz=sprawdz/.test(p.url()) && await wiersze.count() === 1
        && /Do sprawdzenia/.test(await p.locator('.tl-teksty-filtry a[aria-current="page"]').textContent() || ''), p.url());
    const link = await wiersze.first().locator('a').getAttribute('href');
    t.check('odnośnik do edycji w Bricksie', /[?&]bricks=run/.test(link || ''), link);
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
