/**
 * Deaktywacja i odinstalowanie (1.232.0) na PRAWDZIWYM WordPressie —
 * trzecim, jednorazowym (usun.test z tools/testowy-wp.sh).
 *
 * Kolejność ma znaczenie: najpierw odinstalowanie BEZ „Usuń dane" (nic nie
 * może zniknąć), potem Z NIM (znika wszystko nasze, nic cudzego) — po nim
 * strona jest czysta na następny przebieg. Na końcu aktywacja z powrotem.
 *
 * „Cudze" dane mają kształt danych Evoke Fields, które używa tego samego
 * przedrostka `evk_`: kasowanie po przedrostku zabrałoby mu typy treści,
 * taksonomie i sejf konfiguracji.
 *
 * Sonda: tests/php/zapis-wp-odinstalowanie.php.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const sonda = (krok) => {
    const surowe = phpOutput('zapis-wp-odinstalowanie.php', krok, { dopuscBlad: true });
    try { return JSON.parse(surowe); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  };
  const puste = (o) => o && typeof o === 'object' && Object.keys(o).length === 0;

  t.section('środowisko: trzeci, jednorazowy WordPress (usun.test)');
  const p0 = sonda('przygotuj 0');
  t.check('trzeci testowy WordPress jest (tools/testowy-wp.sh)', !p0.brak, p0.brak || 'jest');
  if (p0.brak) return;

  // ── Deaktywacja ─────────────────────────────────────────────────────────
  t.section('deaktywacja: bez zadań w cronie i bez reguł adresów');
  t.check('warunek testu: przed deaktywacją trzy zadania w cronie', p0.cron_przed === true, JSON.stringify(p0.cron_przed));
  const c = p0.cron_po_deaktywacji || {};
  t.check('po deaktywacji w cronie nie ma kroków kopii, kopii nocnej ani wysyłki',
    p0.aktywna_po_deaktywacji === false && c.tick === false && c.nocna === false && c.wysylka === false, JSON.stringify(p0.cron_po_deaktywacji));
  t.check('reguły adresów do przebudowania (bez naszych)', p0.reguly_po_deaktywacji === true, JSON.stringify(p0.reguly_po_deaktywacji));

  // ── Bez „Usuń dane" ─────────────────────────────────────────────────────
  t.section('odinstalowanie BEZ „Usuń dane" (domyślnie): nic nie znika');
  const w0 = sonda('wykonaj');
  const n0 = w0.nasze || {};
  t.check('warunek testu: kod wtyczki przy odinstalowaniu nie jest załadowany', w0.wtyczka_zaladowana === false, JSON.stringify(w0.wtyczka_zaladowana));
  t.check('ustawienia, transienty, tabele i wpisy zostają',
    Object.keys(n0.opcje || {}).length === 10 && Object.keys(n0.transienty || {}).length === 3
      && Object.keys(n0.tabele || {}).length === 2 && Object.keys(n0.wpisy || {}).length === 4,
    JSON.stringify({ opcje: Object.keys(n0.opcje || {}).length, transienty: Object.keys(n0.transienty || {}).length,
      tabele: Object.keys(n0.tabele || {}).length, wpisy: Object.keys(n0.wpisy || {}).length }));
  t.check('meta, rola z Role Managera, uprawnienia i katalogi (kopie, import, OG) zostają',
    Object.keys(n0.meta || {}).length === 3 && n0.rola === true && n0.uprawnienie === true && Object.keys(n0.katalogi || {}).length === 3,
    JSON.stringify({ meta: n0.meta, rola: n0.rola, uprawnienie: n0.uprawnienie, katalogi: n0.katalogi }));
  sonda('przywroc');

  // ── Z „Usuń dane", ale na stronie zostaje druga kopia ───────────────────
  /* 1.233.1: na stronie bywają dwa katalogi z Evoke ONE (ręcznie wgrana kopia
     z gałęzi obok „evoke-one-main"). Usunięcie jednej to sprzątanie plików —
     dane należą dalej do kopii, która zostaje, także przy „Usuń dane". */
  t.section('odinstalowanie Z „Usuń dane", gdy zostaje druga kopia: nic nie znika');
  sonda('przygotuj 1 druga');
  const wd = sonda('wykonaj');
  const nd = wd.nasze || {};
  t.check('warunek testu: w katalogu wtyczek jest druga kopia Evoke ONE',
    JSON.stringify(wd.inne_kopie) === JSON.stringify(['evk-t-druga-kopia/evoke-one.php']), JSON.stringify(wd.inne_kopie));
  t.check('ustawienia, tabele, wpisy, katalogi i rola zostają mimo „Usuń dane"',
    Object.keys(nd.opcje || {}).length === 10 && Object.keys(nd.tabele || {}).length === 2 && Object.keys(nd.wpisy || {}).length === 4
      && Object.keys(nd.katalogi || {}).length === 3 && nd.rola === true,
    JSON.stringify({ opcje: Object.keys(nd.opcje || {}).length, tabele: nd.tabele, wpisy: Object.keys(nd.wpisy || {}).length,
      katalogi: nd.katalogi, rola: nd.rola }));
  sonda('przywroc');

  // ── Z „Usuń dane" ───────────────────────────────────────────────────────
  t.section('odinstalowanie Z „Usuń dane": znika wszystko nasze');
  sonda('przygotuj 1');
  const w1 = sonda('wykonaj');
  const n1 = w1.nasze || {};
  t.check('ustawienia i logi (w tym dynamiczne nazwy), transienty — nic nie zostaje',
    puste(n1.opcje) && puste(n1.transienty), JSON.stringify({ opcje: n1.opcje, transienty: n1.transienty }));
  t.check('tabele newslettera i kopii, snippety, przekierowania, logi — nic nie zostaje',
    puste(n1.tabele) && puste(n1.wpisy), JSON.stringify({ tabele: n1.tabele, wpisy: n1.wpisy }));
  t.check('meta stron (OG, SEO) i użytkowników — nic nie zostaje', puste(n1.meta), JSON.stringify(n1.meta));
  t.check('kopie z serwera, katalog importu, obrazki OG — usunięte', puste(n1.katalogi), JSON.stringify(n1.katalogi));
  t.check('rola z Role Managera usunięta, jej użytkownik z rolą domyślną strony',
    n1.rola === false && JSON.stringify(w1.uzytkownik_role) === JSON.stringify([w1.domyslna_rola]),
    JSON.stringify({ rola: n1.rola, uzytkownik: w1.uzytkownik_role, domyslna: w1.domyslna_rola }));
  t.check('nasze uprawnienia zdjęte z ról', n1.uprawnienie === false, JSON.stringify(n1.uprawnienie));

  t.section('odinstalowanie Z „Usuń dane": cudze dane nietknięte');
  const cz = w1.cudze || {};
  t.check('Evoke Fields: typy treści, katalog kopii i jego opcja, meta _evk_access_key, uprawnienie evk_access_fields',
    cz.evk_custom_post_types === true && cz.evk_backups_dir === true && cz.katalog_evoke_fields === true
      && cz._evk_access_key === true && cz.evk_access_fields === true, JSON.stringify(cz));
  t.check('cudza rola, cudza opcja, zwykła strona i ustawienia WordPressa zostają',
    cz.obca_rola === true && cz.obca_opcja === true && cz.strona === true && cz.blogname === true, JSON.stringify(cz));

  const pr = sonda('przywroc');
  t.check('po odinstalowaniu wtyczka aktywuje się z powrotem', pr.aktywna === true, JSON.stringify(pr));

  // ── Spis danych kompletny ───────────────────────────────────────────────
  /* Nowa opcja w kodzie bez wpisu w includes/dane-wtyczki.php zostałaby po
     odinstalowaniu na zawsze — a wpis bez kodu to literówka, która niczego
     nie kasuje. Oba kierunki. Poza spisem wolno tylko opcje WordPressa,
     Bricksa i `ustawienia` (stara opcja Tłumaczeń, czytana przy migracji). */
  t.section('spis danych: każda opcja z kodu jest w includes/dane-wtyczki.php');
  const korzen = path.join(__dirname, '..');
  let dane = null;
  try {
    dane = JSON.parse(execFileSync('php', ['-r', 'define("ABSPATH", "/"); echo json_encode(require $argv[1]);',
      path.join(korzen, 'includes/dane-wtyczki.php')], { encoding: 'utf8' }));
  } catch (e) { dane = null; }
  const NIE_NASZE = ['active_plugins', 'admin_email', 'blog_public', 'bricks_custom_fonts', 'comment_registration',
    'home', 'page_on_front', 'rewrite_rules', 'siteurl', 'ustawienia'];
  const pliki = [path.join(korzen, 'evoke-one.php')];
  (function chodz(d) {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) chodz(p); else if (p.endsWith('.php')) pliki.push(p);
    }
  })(path.join(korzen, 'includes'));
  const teksty = pliki.filter((p) => !p.endsWith('dane-wtyczki.php')).map((p) => fs.readFileSync(p, 'utf8'));
  const stale = {};
  for (const tx of teksty) {
    for (const m of tx.matchAll(/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']+)'/g)) stale[m[1]] = m[2];
    for (const m of tx.matchAll(/define\(\s*'([A-Z][A-Z0-9_]*)'\s*,\s*'([^']+)'/g)) stale[m[1]] = m[2];
  }
  const nazwy = new Set();
  const przedrostki = new Set();
  for (const tx of teksty) {
    for (const m of tx.matchAll(/\b(?:get|update|add|delete)_option\(\s*'([A-Za-z0-9_]+)'\s*([.,)])/g)) (m[2] === '.' ? przedrostki : nazwy).add(m[1]);
    for (const m of tx.matchAll(/\b(?:get|update|add|delete)_option\(\s*([A-Z][A-Z0-9_]*)\s*[,)]/g)) if (stale[m[1]]) nazwy.add(stale[m[1]]);
  }
  t.check('spis wczytany', !!dane && Array.isArray(dane.opcje) && dane.opcje.length > 50, dane ? dane.opcje.length + ' opcji' : 'brak');
  if (!dane) return;
  const bezSpisu = [...nazwy].filter((n) => !dane.opcje.includes(n) && !NIE_NASZE.includes(n)).sort();
  const bezPrzedrostka = [...przedrostki].filter((n) => !dane.opcje_przedrostki.includes(n)).sort();
  t.check('każda opcja używana w kodzie jest w spisie (albo nie jest nasza)',
    !bezSpisu.length && !bezPrzedrostka.length, [...bezSpisu, ...bezPrzedrostka.map((p) => p + '…')].join(', ') || nazwy.size + ' nazw');
  const caly = teksty.join('\n');
  const martwe = dane.opcje.filter((n) => !caly.includes("'" + n + "'") && !Object.values(stale).includes(n));
  t.check('każda opcja ze spisu występuje w kodzie (bez literówek)', !martwe.length, martwe.join(', ') || 'komplet');
};
