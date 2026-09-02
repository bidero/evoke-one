/**
 * Ekran startowy panelu — Control Center.
 *
 * Powstał z paczki przygotowanej przez innego agenta i przyszedł BEZ testów.
 * To nie jest drobiazg: pulpit czyta kilkanaście opcji po nazwie i wypisuje
 * z nich liczby, a licznik sięgający po nieistniejącą opcję **kłamie po cichu**
 * — pokazuje „0 aktywnych" i nikt się nie dowie, że pyta o zły klucz.
 * Sprawdzenia niżej są w większości o tym.
 *
 * Znacznik bierzemy z PRAWDZIWEJ `evoke_one_render_settings()`, a nie z kopii
 * w fixturze; zasiew konfiguracji idzie z wiersza poleceń, więc porównujemy
 * liczbę na ekranie z tym, co naprawdę siedzi w opcjach.
 */

const fs   = require('fs');
const path = require('path');
const { phpOutput } = require('./lib/harness');

/** Panel wyrenderowany przez PHP dla podanego zasiewu opcji. */
const panel = (zasiew, tab) =>
  phpOutput('panel-start.php',
    JSON.stringify(JSON.stringify(zasiew || {})) + (tab ? ' ' + tab : ''));

module.exports = async function (t) {

  // ── Liczniki modułów ───────────────────────────────────────────────────
  t.section('liczby na pulpicie odpowiadają opcjom w bazie');

  const pusty = panel({});
  t.check('bez włączonych modułów licznik pokazuje zero',
    /0 aktywnych z 9/.test(pusty),
    (pusty.match(/\d+ aktywnych z \d+/) || ['brak'])[0]);

  const trzy = panel({ evk_animator: 1, evk_parallax: 1, evk_darkmode: 1 });
  t.check('trzy włączone moduły to trzy na kaflu',
    /3 aktywnych z 9/.test(trzy),
    (trzy.match(/\d+ aktywnych z \d+/) || ['brak'])[0]);

  /* Każdy moduł osobno, bo licznik zbiorczy przeszedłby także wtedy, gdyby
     jedna nazwa opcji była błędna, a inna liczyła się podwójnie. */
  const modulyFrontendu = ['evk_animator', 'evk_parallax', 'evk_darkmode', 'evk_cursor',
    'evk_lenis', 'evk_bgshift', 'evk_fonts', 'evk_theme_color', 'evk_a11y'];
  const zle = modulyFrontendu.filter((opcja) => !/1 aktywnych z 9/.test(panel({ [opcja]: 1 })));
  t.check('każda z dziewięciu nazw opcji jest trafiona', !zle.length,
    zle.length ? 'nie liczą się: ' + zle.join(', ') : '9 z 9');

  /* Nazwy z listy muszą istnieć w kodzie wtyczki. Sam licznik przechodziłby
     też wtedy, gdyby panel i ten test uzgodniły wspólną literówkę. */
  const zrodlaWtyczki = (function zbierz(kat, akc) {
    for (const wpis of fs.readdirSync(kat, { withFileTypes: true })) {
      const p = path.join(kat, wpis.name);
      if (wpis.isDirectory()) zbierz(p, akc);
      else if (wpis.name.endsWith('.php')) akc.push(fs.readFileSync(p, 'utf8'));
    }
    return akc;
  })(path.join(__dirname, '..', 'includes'), []).join('\n');

  const nieznane = modulyFrontendu.filter(
    (opcja) => !new RegExp("register_setting\\([^)]*'" + opcja + "'|'" + opcja + "'").test(zrodlaWtyczki));
  t.check('i każda występuje w kodzie modułów', !nieznane.length,
    nieznane.join(', ') || 'wszystkie znane');

  // ── Wynik gotowości ────────────────────────────────────────────────────
  t.section('wynik liczy tylko to, co da się nie zdać');

  const wynik = (html) => {
    const m = html.match(/evo-health-score"><strong>(\d+)<\/strong>/);
    return m ? Number(m[1]) : null;
  };

  /* Do 1.137.0 w mianowniku stały `defined('ABSPATH')` i `PHP >= 7.4`. Obie są
     prawdziwe zawsze, gdy ten kod się wykonuje, więc pusta instalacja
     pokazywała 38/100 mimo że nie było skonfigurowane nic. */
  t.check('pusta konfiguracja to zero, nie „trochę"', wynik(pusty) === 0,
    wynik(pusty) + '/100');

  const polowa = panel({ ssl: 1, evk_smtp: 1, evk_schema: 1 });
  t.check('trzy z sześciu kontrolek to 50', wynik(polowa) === 50, wynik(polowa) + '/100');

  const komplet = panel({
    ssl: 1, evk_smtp: 1, evk_schema: 1,
    evk_cleanup: { disable_xmlrpc: 1 },
    evk_security: { limit_login_enabled: 1 },
    tl_sitemap_settings: 1,
  });
  t.check('komplet kontrolek to sto', wynik(komplet) === 100, wynik(komplet) + '/100');
  t.check('i wtedy panel mówi, że jest dobrze',
    /Wszystko działa dobrze/.test(komplet), 'komunikat nagłówka');

  /* Środowisko jest do zobaczenia, nie do oceniania — ma być na pasku, ale
     poza wynikiem. Bez tego sprawdzenia „pusta konfiguracja to zero" dałoby
     się spełnić także wycinając te kontrolki z ekranu. */
  t.check('wersja PHP i Bricks nadal widoczne, ale poza wynikiem',
    /is-info[^>]*>.*?PHP /s.test(pusty) && /is-info[^>]*>.*?Bricks/s.test(pusty),
    'oznaczone jako informacja');

  // ── Kierowanie do miejsc ───────────────────────────────────────────────
  t.section('linki prowadzą tam, gdzie naprawdę coś jest');

  /* Zakładki czytamy z page.php, podzakładki z plików zakładek — czyli
     z jedynych miejsc, które o nich decydują. */
  const pagePhp = fs.readFileSync(path.join(__dirname, '..', 'includes', 'admin', 'page.php'), 'utf8');
  const zakladki = [...pagePhp.matchAll(/^\s*'([a-z_]+)'\s*=>\s*\['label'/gm)].map((m) => m[1]);
  t.check('zakładek jest osiem', zakladki.length === 8, zakladki.join(', '));

  const podzakladki = {};
  for (const [tabKey, plik] of Object.entries({
    wydajnosc: 'tab-wydajnosc.php', bezpieczenstwo: 'tab-bezpieczenstwo.php',
    narzedzia: 'tab-narzedzia.php', admin_panel: 'tab-admin.php', strona: 'tab-seo.php',
  })) {
    const tresc = fs.readFileSync(path.join(__dirname, '..', 'includes', 'admin', plik), 'utf8');
    podzakladki[tabKey] = [...tresc.matchAll(/'([a-z0-9_]+)'\s*=>\s*\['label'/g)].map((m) => m[1]);
  }

  /* Nazwy wycinamy DO OGRANICZNIKA (`&` albo koniec adresu), a nie klasą
     dozwolonych znaków. Wzorzec `([a-z_]+)` wygląda rozsądnie i jest tu
     błędny: przy adresie `tab=newsletterXX` dopasowuje samo `newsletter`,
     resztę połyka i sprawdzenie przechodzi na zielono. Złapane mutacją. */
  const adresy = [...pusty.matchAll(/href="[^"]*?[?&](?:amp;)?tab=([^&"]+)(?:&(?:amp;)?sub=([^&"]+))?[^"]*"/g)]
    .map((m) => ({ tab: decodeURIComponent(m[1]), sub: m[2] ? decodeURIComponent(m[2]) : '' }));
  t.check('jest co sprawdzać — ekran linkuje w kilkanaście miejsc',
    adresy.length >= 15, adresy.length + ' odsyłaczy');

  const zlyTab = adresy.filter((a) => !zakladki.includes(a.tab));
  t.check('każdy odsyłacz celuje w istniejącą zakładkę', !zlyTab.length,
    zlyTab.map((a) => a.tab).join(', ') || 'komplet');

  const zlySub = adresy.filter((a) => a.sub && podzakladki[a.tab] && !podzakladki[a.tab].includes(a.sub));
  t.check('i w istniejącą podzakładkę', !zlySub.length,
    zlySub.map((a) => a.tab + '/' + a.sub).join(', ') || 'komplet');

  /* Kontrolka, która nie przeszła, musi dać się naprawić jednym kliknięciem —
     inaczej lista „wymaga uwagi" jest samym narzekaniem. */
  const doNaprawy = (pusty.match(/evo-health-issue(?! is-good)/g) || []).length;
  const zLinkiem  = (pusty.match(/evo-health-issue(?! is-good)[\s\S]*?Skonfiguruj/g) || []).length;
  t.check('każda niezdana kontrolka z linkiem ma dokąd prowadzić',
    doNaprawy > 0 && zLinkiem >= doNaprawy - 1,
    zLinkiem + ' z ' + doNaprawy + ' pozycji z odsyłaczem');

  // ── Nawigacja ──────────────────────────────────────────────────────────
  t.section('sidebar zna komplet zakładek i wie, gdzie stoisz');

  const naSeo = panel({}, 'strona');
  const wPasku = [...naSeo.matchAll(/class="evo-sidebar-link[^"]*"[^>]*?>[\s\S]*?<span>([^<]+)<\/span>/g)]
    .map((m) => m[1]);
  t.check('w pasku jest osiem pozycji plus pomoc', wPasku.length === 9,
    wPasku.length + ': ' + wPasku.join(', '));
  t.check('bieżąca zakładka jest zaznaczona',
    /class="evo-sidebar-link is-active"[^>]*>[\s\S]*?<span>SEO<\/span>/.test(naSeo),
    'SEO');
  t.check('a pozostałe nie', (naSeo.match(/is-active/g) || []).length === 1,
    (naSeo.match(/is-active/g) || []).length + ' zaznaczonych');

  // ── Przeglądarka ───────────────────────────────────────────────────────
  t.section('paleta i wersja wąska');

  const otworz = (szer) => t.open('panel-start.html', {
    viewport: { width: szer, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(panel({ evk_animator: 1 })) + ';',
  });

  const p = await otworz(1400);

  const przed = await p.evaluate(() => window.__paleta());
  t.check('paleta startuje zamknięta', !przed.otwarta && !przed.widoczna,
    JSON.stringify(przed));

  await p.evaluate(() => window.__skrot(null));
  const poSkrocie = await p.evaluate(() => window.__paleta());
  t.check('Ctrl+K poza polem otwiera paletę', poSkrocie.otwarta, JSON.stringify(poSkrocie));

  await p.evaluate(() => window.__zamknij());
  const poEsc = await p.evaluate(() => window.__paleta());
  t.check('Escape ją zamyka', !poEsc.otwarta, JSON.stringify(poEsc));

  /* ZGŁOSZENIEM BYŁOBY: „w edytorze skryptów Ctrl+K przestał działać".
     Nasłuch wisi na dokumencie, więc bez warunku po `event.target` skrót
     zjada kombinację także w trakcie pisania. */
  await p.evaluate(() => window.__skrot('#evo-command-input'));
  const wPolu = await p.evaluate(() => window.__paleta());
  t.check('a w polu tekstowym skrót nie przechwytuje pisania', !wPolu.otwarta,
    JSON.stringify(wPolu));

  const szeroki = await p.evaluate(() => window.__uklad());
  t.check('na desktopie sidebar stoi, paska mobilnego nie ma',
    szeroki.pasekWidoczny && !szeroki.mobilnyWidoczny, JSON.stringify(szeroki));
  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  const w = await otworz(390);
  const waski = await w.evaluate(() => window.__uklad());
  t.check('przy 390 px sidebar schodzi z drogi',
    !waski.pasekWidoczny && waski.mobilnyWidoczny, JSON.stringify(waski));
  t.check('a treść dostaje całą szerokość', waski.szerokoscTresci >= 340,
    waski.szerokoscTresci + ' px');

  await w.evaluate(() => window.__burger());
  const poBurgerze = await w.evaluate(() => window.__uklad());
  t.check('burger wysuwa nawigację', poBurgerze.pasekWidoczny,
    JSON.stringify(poBurgerze));
  t.check('bez błędów JS w wersji wąskiej', !w.errors.length, w.errors.join(' | ') || 'brak');
  await w.close();
};
