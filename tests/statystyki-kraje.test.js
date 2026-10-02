/**
 * Statystyki: kraj z DB-IP Lite (1.285.0) — prawdziwy import porcjami
 * z pliku podanego przez serwer testowy (php -S), wyszukiwanie IPv4 i IPv6,
 * kraj przy odsłonie z Chromium, lista „Kraje” z podpisem CC BY i przycisk
 * w zakładce.
 *
 * Plik testowy jest MAŁY i zmyślony (127.0.0.0/8 → PL, żeby odsłona
 * z przeglądarki testu miała kraj), ale ma kształt prawdziwego:
 * „od,do,KRAJ”, IPv4 i IPv6, „ZZ”, oraz złe wiersze, które import pomija.
 * Prawdziwy plik (710 834 zakresy) przeszedł import w 6 s przy pomiarze
 * w trakcie prac — to nie jest część testu (sieć).
 */

const fs = require('fs');
const zlib = require('zlib');
const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (args, env) => {
  /* phpOutput dziedziczy środowisko procesu — zmienne tylko na czas jednego wywołania. */
  Object.assign(process.env, env || {});
  let s;
  try { s = phpOutput('statystyki.php', args.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true }); }
  finally { for (const k of Object.keys(env || {})) delete process.env[k]; }
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + args[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

function plikBazy() {
  const w = ['0.0.0.0,0.255.255.255,ZZ', '1.0.0.0,1.0.0.255,AU', 'zle,linie', '1.0.1.0,1.0.3.255,usa', '127.0.0.0,127.255.255.255,PL',
    /* 32.0.0.0/8 bajtowo leży tuż przed „2001:…” — bez rozdziału IPv4/IPv6 adres 2001:db7::1 trafiłby tutaj. */
    '32.0.0.0,32.255.255.255,GB'];
  for (let i = 0; i < 30000; i++) w.push('10.' + (i >> 8) + '.' + (i & 255) + '.0,10.' + (i >> 8) + '.' + (i & 255) + '.255,US');
  w.push('::,1fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff,ZZ', '2001:db8::,2001:db8:ffff:ffff:ffff:ffff:ffff:ffff,DE', '2001:db9::,1.2.3.4,FR');
  return { gz: zlib.gzipSync(w.join('\n') + '\n'), dobre: 30000 + 6 };
}

module.exports = async function (t) {
  t.section('środowisko');
  const wp = sonda(['wp']);
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  let serwer = null;
  let browser = null;
  try {
    const [A] = sonda(['przygotuj']).strony || [];
    const { gz, dobre } = plikBazy();
    fs.writeFileSync(wp.wp + '/wp-content/uploads/evk-test-dbip.csv.gz', gz);
    serwer = await serwerWp.start(wp.wp);
    const adres = serwer.baza + '/wp-content/uploads/evk-test-dbip.csv.gz';
    /* Przycisk w zakładce idzie przez serwer — adres pliku podaje mu jednorazowy mu-plugin testu (usuwany w finally). */
    fs.mkdirSync(wp.wp + '/wp-content/mu-plugins', { recursive: true });
    fs.writeFileSync(wp.wp + '/wp-content/mu-plugins/evk-test-dbip.php', "<?php\nadd_filter('evk_stat_dbip_adres', static function () { return " + JSON.stringify(adres) + "; });\nadd_filter('evk_stat_dbip_minimum', static function () { return 1000; });\n");

    t.section('import porcjami');
    const przed = sonda(['kraj', '127.0.0.1']).kraje;
    t.check('bez bazy: kraj nieznany', przed['127.0.0.1'] === '', J(przed));
    const polBez = sonda(['polityka']).akapit || '';
    const kroki = [];
    let k = null;
    for (let i = 0; i < 40; i++) {
      /* 0,02 s na krok i porcje po 500 wierszy — import musi przejść przez kilka kroków. */
      k = sonda(['dbip', '0.02', adres], { EVK_TEST_DBIP_PORCJA: '500' });
      kroki.push((k.wynik || {}).stan + ':' + (k.wynik || {}).procent);
      if (i === 1) {
        const wTrakcie = sonda(['kraj', '127.0.0.1']).kraje;
        t.check('w trakcie importu zbieranie korzysta ze starej (tu: żadnej) bazy — bez błędu', wTrakcie['127.0.0.1'] === '', J(wTrakcie));
      }
      if ((k.wynik || {}).stan !== 'import') break;
    }
    console.log('      kroki: ' + kroki.join(' '));
    const procenty = kroki.filter((x) => x.startsWith('import:')).map((x) => +x.split(':')[1]);
    t.check('kilka kroków „import” z rosnącym postępem, potem „gotowe”', kroki.length >= 3 && kroki[kroki.length - 1] === 'gotowe:100'
      && procenty.every((p, i) => i === 0 || p >= procenty[i - 1]), kroki.join(' '));
    t.check('zakresy: tylko poprawne wiersze (złe IP, „usa”, mieszane IPv6/IPv4 pominięte)', k.stan.zakresy === dobre && /^\d{4}-\d{2}$/.test(k.stan.wersja) && k.stan.import === null, J(k.stan));
    const kr = sonda(['kraj', '127.0.0.1', '1.0.0.7', '10.100.7.9', '2001:db8::42', '2001:db7::1', '32.1.2.3', '8.8.8.8', '0.0.0.5', 'nie-ip']).kraje;
    t.check('wyszukiwanie: IPv4 w zakresach, IPv6 osobno od IPv4 (zakres „::” ZZ nie łapie IPv4), poza bazą i ZZ — pusto',
      J(kr) === J({ '127.0.0.1': 'PL', '1.0.0.7': 'AU', '10.100.7.9': 'US', '2001:db8::42': 'DE', '2001:db7::1': '', '32.1.2.3': 'GB', '8.8.8.8': '', '0.0.0.5': '', 'nie-ip': '' }), J(kr));
    /* Tekst do polityki prywatności (moduł RODO) opisuje to, co naprawdę zbierane: kraj dopiero z bazą, zdarzenia i DNT według ustawień. */
    const polZ = sonda(['polityka']).akapit || '';
    const polMin = sonda(['polityka', J({ zd_tel: 0, zd_pobrania: 0, zd_wychodzace: 0, zd_formularze: 0, dnt: 0, retencja: 30 })]).akapit || '';
    console.log('      polityka: ' + J(polZ));
    t.check('polityka prywatności: akapit „Statystyki odwiedzin”; kraj z IP dopiero z wczytaną bazą; zdarzenia, DNT i czas trzymania według ustawień',
      /bez plików cookies/.test(polBez) && !/kraj/.test(polBez) && /kraj ustalony z adresu IP \(baza DB-IP Lite/.test(polZ)
      && /kliknięcia w numery telefonu i adresy e-mail, pobrania plików, przejścia do innych serwisów i wysłanie formularza/.test(polZ) && /„nie śledź”/.test(polZ) && /przechowujemy 90 dni/.test(polZ)
      && !/kliknięcia|pobrania|formularz|nie śledź/.test(polMin) && /przechowujemy 30 dni/.test(polMin), J({ polBez, polZ, polMin }));
    const k2 = sonda(['dbip', '5', adres]);
    t.check('ten sam miesiąc drugi raz: „gotowe” bez ponownego importu', k2.wynik.stan === 'gotowe' && k2.stan.zakresy === dobre, J(k2.wynik));
    /* Nowy miesiąc, a pod adresem strona HTML z kodem 200 (WordPress, CDN) albo za mały plik — stara baza zostaje. */
    sonda(['dbip-wersja', '2000-01']);
    const k3 = sonda(['dbip', '5', serwer.baza + '/nie-ma-pliku.csv.gz']);
    t.check('strona HTML zamiast pliku: błąd „brak nagłówka gzip”, stara baza dalej działa', k3.wynik.stan === 'blad' && /nagłówka gzip/.test(k3.stan.blad)
      && k3.stan.zakresy === dobre && sonda(['kraj', '127.0.0.1']).kraje['127.0.0.1'] === 'PL', J(k3));
    fs.writeFileSync(wp.wp + '/wp-content/uploads/evk-test-dbip-maly.csv.gz', zlib.gzipSync('1.0.0.0,1.0.0.255,AU\n'));
    let k4 = null;
    for (let i = 0; i < 10; i++) { k4 = sonda(['dbip', '5', serwer.baza + '/wp-content/uploads/evk-test-dbip-maly.csv.gz']); if (k4.wynik.stan !== 'import') break; }
    fs.unlinkSync(wp.wp + '/wp-content/uploads/evk-test-dbip-maly.csv.gz');
    t.check('plik z 1 zakresem (próg testu 1000): odrzucony, stara baza (30 006) dalej działa', k4.wynik.stan === 'blad' && /tylko 1 zakresów/.test(k4.stan.blad)
      && k4.stan.zakresy === dobre && sonda(['kraj', '127.0.0.1']).kraje['127.0.0.1'] === 'PL', J(k4));

    t.section('kraj przy odsłonie, raport i zakładka');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const ctx = await browser.newContext({ userAgent: UA });
    await ctx.addInitScript(() => { Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }); });
    await (await ctx.newPage()).goto(serwer.baza + '/?page_id=' + A);
    let rows = [];
    for (let i = 0; i < 40 && !rows.length; i++) { rows = sonda(['odslony']).wiersze || []; if (!rows.length) await new Promise((r) => setTimeout(r, 150)); }
    t.check('odsłona z 127.0.0.1 ma kraj PL', (rows[0] || {}).kraj === 'PL', J(rows[0]));
    const s = await (await browser.newContext()).newPage();
    await serwerWp.zaloguj(s, serwer.baza);
    await s.goto(serwer.baza + '/wp-admin/admin.php?page=evoke-statystyki&okres=7');
    const lista = await s.evaluate(() => {
      const b = [...document.querySelectorAll('.evk-stat-lista')].find((x) => x.querySelector('h3').textContent === 'Kraje');
      return b && { kraje: [...b.querySelectorAll('tbody td:first-child')].map((x) => x.textContent), podpis: (b.querySelector('.evk-stat-dbip a') || {}).textContent };
    });
    t.check('raport: lista „Kraje” z nazwą po polsku (albo kodem bez intl) i podpisem „IP Geolocation by DB-IP”',
      !!lista && ['Polska', 'PL'].includes(lista.kraje[0]) && lista.podpis === 'IP Geolocation by DB-IP', J(lista));
    await s.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-one&tab=statystyki');
    const stan0 = await s.textContent('#evk-stat-dbip .evk-stat-dbip-stan');
    t.check('zakładka: stan bazy (miesiąc i liczba zakresów)', /^Baza z \d{4}-\d{2}: 30[ ,.\u00a0]?006 zakresów adresów\./.test(stan0.trim()), stan0);
    /* Przycisk: nowy miesiąc w pliku → pełny import przez AJAX z paskiem postępu. Zmieniamy wersję w stanie, żeby był powód. */
    sonda(['dbip-wersja', '2000-01']);
    await s.click('#evk-stat-dbip-pobierz');
    await s.waitForFunction(() => /^Baza z|^Błąd/.test(document.querySelector('#evk-stat-dbip .evk-stat-dbip-stan').textContent), null, { timeout: 30000 }).catch(() => {});
    const stan1 = await s.textContent('#evk-stat-dbip .evk-stat-dbip-stan');
    t.check('„Pobierz bazę krajów teraz”: import przez AJAX do końca', /^Baza z \d{4}-\d{2}: 30[ ,.\u00a0]?006 zakresów adresów\.$/.test(stan1.trim()), stan1);
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    try { fs.unlinkSync(wp.wp + '/wp-content/mu-plugins/evk-test-dbip.php'); } catch (e) { /* nie było */ }
    sonda(['sprzataj']);
  }
};
