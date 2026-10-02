/**
 * Claude Desktop przez MCP (1.278.0) — prawdziwy WordPress, prawdziwy
 * MCP Adapter z paczki wydania (instalowany przyciskiem panelu przez sondę
 * tests/php/tl-mcp.php) i prawdziwe wywołania MCP przez HTTP (php -S):
 * `initialize` → `Mcp-Session-Id` → `tools/call`, z hasłem aplikacji.
 *
 * Decyzje zgłaszającego (02.10): tłumaczy Claude z subskrypcji (bez klucza
 * API); zakres jak hurt AI; zapis z „Do sprawdzenia”; prawo jak zakładka
 * Tłumaczenia; „Sprawdzone” klika tylko człowiek; trzy prompty; własna
 * instrukcja w panelu z przyciskiem „Zainstaluj” i hasłem aplikacji.
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-mcp.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);

/** Klient MCP: sesja z hasłem aplikacji jednego użytkownika. */
function klient(baza, login, haslo) {
  const url = baza + '/?rest_route=/mcp/mcp-adapter-default-server';
  const auth = 'Basic ' + Buffer.from(login + ':' + haslo).toString('base64');
  let sesja = '';
  let id = 0;
  const wyslij = async (metoda, params, powiadomienie) => {
    const h = { 'Content-Type': 'application/json', Authorization: auth };
    if (sesja) h['Mcp-Session-Id'] = sesja;
    const cialo = { jsonrpc: '2.0', method: metoda };
    if (!powiadomienie) cialo.id = ++id;
    if (params) cialo.params = params;
    const r = await fetch(url, { method: 'POST', headers: h, body: JSON.stringify(cialo) });
    if (!sesja && r.headers.get('mcp-session-id')) sesja = r.headers.get('mcp-session-id');
    const t = await r.text();
    try { return Object.assign({ http: r.status }, JSON.parse(t)); } catch (e) { return { http: r.status, tekst: t.slice(0, 300) }; }
  };
  return {
    async start() {
      const r = await wyslij('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'evoke-test', version: '1' } });
      await wyslij('notifications/initialized', null, true);
      return r;
    },
    wyslij,
    /** tools/call → wynik strukturalny albo { blad }. */
    async narzedzie(nazwa, args) {
      const r = await wyslij('tools/call', { name: nazwa, arguments: args || {} });
      if (r.error) return { blad: r.error.message || J(r.error) };
      const w = r.result || {};
      if (w.isError) return { blad: ((w.content || [])[0] || {}).text || 'isError' };
      return w.structuredContent || JSON.parse(((w.content || [])[0] || {}).text || '{}');
    },
  };
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress, paczka MCP Adaptera, Abilities API');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  t.check('zip MCP Adaptera jest (tools/testowy-wp.sh pobiera go do ~/.cache)', !!wp.zip, J(wp));
  t.check('WordPress ma Abilities API', wp.api === true, J(wp));
  if (wp.brak || !wp.wp || !wp.zip || !wp.api) return;
  sonda('sprzataj');
  let serwer = null;
  let browser = null;
  try {
    const p = sonda('przygotuj');
    t.check('języki, ustawienia, słownik, strony A i B, cztery hasła aplikacji', p.gotowe === true && Object.keys(p.hasla || {}).length === 4, J(p.gotowe));
    if (p.gotowe !== true) return;
    serwer = await serwerWp.start(wp.wp, { env: { EVK_WP_SRODOWISKO: 'local' } });

    t.section('panel: „Zainstaluj MCP Adapter” (prawdziwy AJAX, paczka wydania)');
    const bezAdaptera = await klient(serwer.baza, 'admin', p.hasla.admin).start();
    t.check('bez adaptera nie ma serwera MCP (404 trasy)', bezAdaptera.http === 404, J(bezAdaptera).slice(0, 200));
    const nieAdmin = sonda('ajax-adapter', 'tlumacz');
    t.check('tłumacz nie instaluje wtyczek', nieAdmin.odp && nieAdmin.odp.success === false && nieAdmin.po.plik === '', J(nieAdmin.odp));
    const zly = sonda('ajax-adapter', 'zly');
    t.check('zepsuta paczka: komunikat błędu, nic nie zainstalowane (bez błędu krytycznego)',
      zly.odp && zly.odp.success === false && typeof zly.odp.data === 'string' && zly.odp.data.length > 5 && zly.po && zly.po.plik === '', J(zly).slice(0, 300));
    const inst = sonda('ajax-adapter');
    t.check('administrator: zainstalowany i włączony, wersja w komunikacie',
      inst.przed && inst.przed.plik === '' && inst.odp && inst.odp.success === true && inst.po.aktywny === true
      && /mcp-adapter\.php$/.test(inst.po.plik) && /MCP Adapter \d+\.\d+/.test(inst.odp.data.komunikat), J([inst.przed, inst.odp, inst.po]));
    const znow = sonda('ajax-adapter');
    t.check('drugi raz: nic nie instaluje, tylko potwierdza', znow.przed.aktywny === true && znow.odp.success === true, J(znow.odp));

    t.section('MCP: narzędzia i prompty Evoke na domyślnym serwerze');
    const adm = klient(serwer.baza, 'admin', p.hasla.admin);
    const init = await adm.start();
    t.check('initialize z hasłem aplikacji: sesja', init.http === 200 && !!(init.result || {}).protocolVersion, J(init).slice(0, 200));
    const narz = ((await adm.wyslij('tools/list')).result || {}).tools || [];
    const nazwy = narz.map((x) => x.name);
    t.check('tools/list: trzy narzędzia Evoke wprost (bez „discover → execute”)',
      ['evoke-tlumaczenia-braki', 'evoke-tlumaczenia-pobierz', 'evoke-tlumaczenia-zapisz'].every((n) => nazwy.includes(n)), J(nazwy));
    const zap = narz.find((x) => x.name === 'evoke-tlumaczenia-zapisz') || {};
    t.check('zapis: schemat wymaga części, języka i listy {klucz, h, tekst}; nie tylko do odczytu',
      J((zap.inputSchema || {}).required) === J(['czesc', 'jezyk', 'tlumaczenia']) && (zap.annotations || {}).readOnlyHint === false, J([zap.inputSchema && zap.inputSchema.required, zap.annotations]));
    const prompty = (((await adm.wyslij('prompts/list')).result || {}).prompts || []).map((x) => [x.name, (x.arguments || []).map((a) => a.name + (a.required ? '*' : ''))]);
    t.check('prompts/list: strona (strona*, jezyk*), braki (jezyk*), sprawdzanie (jezyk*, strona)',
      J(prompty.filter((x) => /^evoke-/.test(x[0]))) === J([['evoke-przetlumacz-strone', ['strona*', 'jezyk*']], ['evoke-przetlumacz-braki', ['jezyk*']],
        ['evoke-sprawdz-tlumaczenia', ['jezyk*', 'strona']]]), J(prompty));
    const pg = await adm.wyslij('prompts/get', { name: 'evoke-przetlumacz-strone', arguments: { strona: 'Strona MCP A', jezyk: 'en' } });
    const tekstPromptu = ((((pg.result || {}).messages || [])[0] || {}).content || {}).text || '';
    t.check('prompts/get: polecenie z nazwą strony, językiem i kolejnością narzędzi',
      /„Strona MCP A”/.test(tekstPromptu) && /język: en/.test(tekstPromptu) && /evoke-tlumaczenia-pobierz/.test(tekstPromptu) && /Do sprawdzenia/.test(tekstPromptu), tekstPromptu.slice(0, 200));

    t.section('braki → pobierz → zapisz (strona A, EN)');
    const br = await adm.narzedzie('evoke-tlumaczenia-braki', { jezyk: 'en', szukaj: 'Strona MCP' });
    const czA = ((br.czesci || []).find((c) => c.tytul === 'Strona MCP A' && /_bricks_page_content_2/.test(c.czesc)) || {}).czesc;
    t.check('lista: języki z nazwami, część Bricksa strony A z trzema brakami EN',
      J((br.jezyki || []).map((j) => j.kod)) === J(['en', 'de']) && !!czA && ((br.czesci || []).find((c) => c.czesc === czA).braki || {}).en === 3, J(br).slice(0, 400));
    const p1 = await adm.narzedzie('evoke-tlumaczenia-pobierz', { czesc: czA, jezyk: 'en' });
    console.log('      teksty: ' + J((p1.teksty || []).map((x) => [x.klucz, x.h, x.n, x.pl])));
    t.check('„Kontakt” ze słownika fraz zapisany od razu (pamięć tłumaczeń), do tłumaczenia dwa teksty',
      p1.z_pamieci === 1 && (p1.teksty || []).length === 2 && !(p1.teksty || []).some((x) => x.pl === 'Kontakt'), J([p1.z_pamieci, p1.teksty]));
    t.check('każdy tekst: klucz miejsca, h = 8 znaków skrótu, numer, miejsce, oryginał',
      (p1.teksty || []).every((x) => /\|text\|en$/.test(x.klucz) && /^[0-9a-f]{8}$/.test(x.h) && x.n > 0 && /·/.test(x.miejsce) && x.pl), J(p1.teksty));
    t.check('instrukcje: język z wariantem, opis strony, wskazówki, słowniczek, nazwy bez tłumaczenia, zapis tym narzędziem',
      /into English \(en-GB\)/.test(p1.instrukcje) && /Studio projektowe Evoke/.test(p1.instrukcje) && /British spelling/.test(p1.instrukcje)
      && /usługi → services/.test(p1.instrukcje) && /- Evoke/.test(p1.instrukcje) && /evoke-tlumaczenia-zapisz/.test(p1.instrukcje) && !/Return JSON/.test(p1.instrukcje), p1.instrukcje);
    t.check('kontekst: wszystkie teksty części po kolei, „Kontakt” już z tłumaczeniem',
      /^1\. \[heading/.test(p1.kontekst) && /"Kontakt" → "Contact"/.test(p1.kontekst), p1.kontekst);
    const tH = (p1.teksty || []).find((x) => /^ah1/.test(x.klucz)) || {};
    const tT = (p1.teksty || []).find((x) => /^at1/.test(x.klucz)) || {};
    const z1 = await adm.narzedzie('evoke-tlumaczenia-zapisz', { czesc: czA, jezyk: 'en', model: 'Claude-Test', tlumaczenia: [
      { klucz: tH.klucz, h: tH.h, tekst: 'Our services at Evoke' },
      { klucz: tT.klucz, h: tT.h, tekst: '<p>Welcome to {post_title}</p>' },
      { klucz: 'nie|ma|en', h: '00000000', tekst: 'x' },
    ] });
    t.check('zapis: nagłówek zapisany; tekst bez <strong> odrzucony (szkielet); zły klucz — nieznany; zostaje 1',
      z1.zapisane === 1 && J((z1.odrzucone || []).map((o) => [o.klucz, /Znaczniki/.test(o.powod) ? 'szkielet' : /Nie ma takiego/.test(o.powod) ? 'nieznany' : o.powod]))
        === J([[tT.klucz, 'szkielet'], ['nie|ma|en', 'nieznany']]) && z1.zostalo === 1 && z1.gotowe === false, J(z1));
    const s1 = sonda('stan', 'en');
    t.check('na stronie: nagłówek i „Contact”; tekst pusty; stan „Do sprawdzenia” z podpisem mcp/claude-test',
      s1.pola && s1.pola.ah1 === 'Our services at Evoke' && s1.pola.ab1 === 'Contact' && !s1.pola.at1
      && (s1.stan[tH.klucz] || {}).src === 'ai' && (s1.stan[tH.klucz] || {}).model === 'mcp/claude-test', J(s1));

    t.section('zmieniony oryginał, porcja dalej, gotowe');
    sonda('zmien');
    const z2 = await adm.narzedzie('evoke-tlumaczenia-zapisz', { czesc: czA, jezyk: 'en', tlumaczenia: [
      { klucz: tT.klucz, h: tT.h, tekst: '<p>Welcome to <strong>{post_title}</strong></p>' }] });
    t.check('tekst zmieniony od pobrania (inne h) — odrzucony, nic nie zapisane', z2.zapisane === 0
      && /zmienił się od pobrania/.test(((z2.odrzucone || [])[0] || {}).powod || ''), J(z2));
    const p2 = await adm.narzedzie('evoke-tlumaczenia-pobierz', { czesc: czA, jezyk: 'en' });
    const tT2 = (p2.teksty || [])[0] || {};
    t.check('ponowne pobranie: tylko ten tekst, nowy oryginał i nowe h', (p2.teksty || []).length === 1 && /serdecznie/.test(tT2.pl) && tT2.h !== tT.h, J(p2.teksty));
    const z3 = await adm.narzedzie('evoke-tlumaczenia-zapisz', { czesc: czA, jezyk: 'en', tlumaczenia: [
      { klucz: tT2.klucz, h: tT2.h, tekst: '<p>A warm welcome to <strong>{post_title}</strong></p>' }] });
    const p3 = await adm.narzedzie('evoke-tlumaczenia-pobierz', { czesc: czA, jezyk: 'en' });
    t.check('zapis z tagami: gotowe; pobranie: gotowe, bez tekstów', z3.zapisane === 1 && z3.gotowe === true && p3.gotowe === true && (p3.teksty || []).length === 0, J([z3, p3]));

    t.section('sprawdzanie tłumaczeń AI (ponownie): poprawka zostaje „Do sprawdzenia”, sprawdzonych nie rusza');
    const r1 = await adm.narzedzie('evoke-tlumaczenia-pobierz', { czesc: czA, jezyk: 'en', ponownie: true });
    const rH = (r1.teksty || []).find((x) => /^ah1/.test(x.klucz)) || {};
    t.check('ponownie: teksty AI z „obecne”, „Contact” ze słownika poza listą, wskazówka o sprawdzaniu',
      (r1.teksty || []).length === 2 && rH.obecne === 'Our services at Evoke' && /„Sprawdzone” zaznacza człowiek/.test(r1.wskazowka || ''), J(r1.teksty));
    const k1 = await adm.narzedzie('evoke-tlumaczenia-zapisz', { czesc: czA, jezyk: 'en', model: 'claude-test', ponownie: true, tlumaczenia: [
      { klucz: rH.klucz, h: rH.h, tekst: 'Our services at Evoke Studio' }] });
    const s2 = sonda('stan', 'en');
    t.check('poprawka zapisana i dalej „Do sprawdzenia”; „zostalo” (ponownie) liczy drugi tekst AI', k1.zapisane === 1 && k1.zostalo === 1
      && s2.pola.ah1 === 'Our services at Evoke Studio' && (s2.stan[rH.klucz] || {}).src === 'ai', J([k1, s2.stan]));
    sonda('sprawdzone', rH.klucz);
    const r2 = await adm.narzedzie('evoke-tlumaczenia-pobierz', { czesc: czA, jezyk: 'en', ponownie: true });
    const k2 = await adm.narzedzie('evoke-tlumaczenia-zapisz', { czesc: czA, jezyk: 'en', tlumaczenia: [{ klucz: rH.klucz, h: rH.h, tekst: 'Nadpisane' }] });
    t.check('po „Sprawdzone”: znika z listy, a zapis go nie nadpisuje', !(r2.teksty || []).some((x) => x.klucz === rH.klucz) && k2.zapisane === 0
      && sonda('stan', 'en').pola.ah1 === 'Our services at Evoke Studio', J([r2.teksty, k2]));

    const rT = (r2.teksty || []).find((x) => /^at1/.test(x.klucz)) || {};
    const k3 = await adm.narzedzie('evoke-tlumaczenia-zapisz', { czesc: czA, jezyk: 'en', tlumaczenia: [
      { klucz: rT.klucz, h: rT.h, tekst: '<p>A very warm welcome to <strong>{post_title}</strong></p>' }] });
    t.check('poprawka bez „ponownie”: przyjęta, a „zostalo” liczy tylko puste — 0, gotowe', k3.zapisane === 1 && k3.zostalo === 0 && k3.gotowe === true, J(k3));

    t.section('porcja: 60 tekstów naraz, dalej od numeru (strona B)');
    const czB = (((await adm.narzedzie('evoke-tlumaczenia-braki', { jezyk: 'de', szukaj: 'MCP B' })).czesci || [])[0] || {}).czesc;
    const b1 = await adm.narzedzie('evoke-tlumaczenia-pobierz', { czesc: czB, jezyk: 'de' });
    const b2 = await adm.narzedzie('evoke-tlumaczenia-pobierz', { czesc: czB, jezyk: 'de', od: b1.nastepne_od });
    t.check('pierwsza porcja 60 (nastepne_od 60), druga 5 od 61; zostało 65', (b1.teksty || []).length === 60 && b1.nastepne_od === 60
      && (b2.teksty || []).length === 5 && b2.teksty[0].n === 61 && b1.zostalo === 65, J([(b1.teksty || []).length, b1.nastepne_od, (b2.teksty || []).length, b1.zostalo]));

    t.section('uprawnienia: jak zakładka Tłumaczenia');
    const tl = klient(serwer.baza, 'tlumacz-mcp', p.hasla['tlumacz-mcp']);
    await tl.start();
    const tlB = await tl.narzedzie('evoke-tlumaczenia-braki', { jezyk: 'de', szukaj: 'MCP A' });
    t.check('tłumacz (redaktor z prawem Tłumaczeń): lista działa', !tlB.blad && (tlB.czesci || []).length > 0, J(tlB).slice(0, 200));
    const red = klient(serwer.baza, 'redaktor-mcp', p.hasla['redaktor-mcp']);
    await red.start();
    const redB = await red.narzedzie('evoke-tlumaczenia-braki', {});
    const redZ = await red.narzedzie('evoke-tlumaczenia-zapisz', { czesc: czA, jezyk: 'de', tlumaczenia: [{ klucz: 'x', h: 'x', tekst: 'x' }] });
    t.check('redaktor bez prawa Tłumaczeń: lista i zapis odmówione', !!redB.blad && !!redZ.blad, J([redB, redZ]));
    const sub = klient(serwer.baza, 'subskrybent-mcp', p.hasla['subskrybent-mcp']);
    await sub.start();
    const subL = (((await sub.wyslij('tools/list')).result || {}).tools || []).map((x) => x.name);
    const subB = await sub.narzedzie('evoke-tlumaczenia-braki', {});
    t.check('subskrybent: wywołanie odmówione', !!subB.blad, J([subL, subB]));

    t.section('panel w Chromium: hasło aplikacji w gotowej konfiguracji, która naprawdę łączy');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const kont = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const strona = await kont.newPage();
    const bledy = [];
    strona.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(strona, serwer.baza);
    await strona.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=mcp');
    const stanAd = await strona.textContent('#tl-mcp-adapter-stan');
    t.check('zakładka: adapter działa (bez przycisku instalacji), wymagania spełnione',
      /✓ MCP Adapter \d/.test(stanAd) && !(await strona.$('#tl-mcp-adapter')) && (await strona.$$('.tl-mcp-wym[data-ok="0"]')).length === 0, stanAd);
    await strona.click('#tl-mcp-haslo');
    await strona.waitForFunction(() => /"WP_API_PASSWORD": "(?!HASŁO)/.test(document.getElementById('tl-mcp-konfiguracja').value), null, { timeout: 15000 }).catch(() => {});
    const konf = JSON.parse(await strona.inputValue('#tl-mcp-konfiguracja'));
    const wpis = Object.values(konf.mcpServers || {})[0] || {};
    t.check('konfiguracja: npx @automattic/mcp-wordpress-remote, adres serwera tej strony, login',
      wpis.command === 'npx' && J(wpis.args) === J(['-y', '@automattic/mcp-wordpress-remote@latest'])
      && wpis.env.WP_API_URL === serwer.baza + '/index.php?rest_route=/mcp/mcp-adapter-default-server' && wpis.env.WP_API_USERNAME === 'admin', J(wpis));
    const zKonf = klient(serwer.baza, wpis.env.WP_API_USERNAME, wpis.env.WP_API_PASSWORD);
    const zKonfInit = await zKonf.start();
    const zKonfB = await zKonf.narzedzie('evoke-tlumaczenia-braki', { jezyk: 'de', szukaj: 'MCP A' });
    t.check('hasło z konfiguracji otwiera sesję MCP i woła narzędzie Evoke', zKonfInit.http === 200 && !zKonfB.blad && (zKonfB.czesci || []).length > 0, J(zKonfB).slice(0, 200));
    await strona.fill('#tl-mcp-npx', '/usr/local/bin/npx');
    const zSciezka = Object.values(JSON.parse(await strona.inputValue('#tl-mcp-konfiguracja')).mcpServers)[0];
    await strona.fill('#tl-mcp-npx', '');
    const bezSciezki = Object.values(JSON.parse(await strona.inputValue('#tl-mcp-konfiguracja')).mcpServers)[0];
    t.check('pełna ścieżka do npx: command i katalog Node w PATH, hasło zostaje; puste pole — z powrotem samo npx',
      zSciezka.command === '/usr/local/bin/npx' && zSciezka.env.PATH === '/usr/local/bin:/usr/bin:/bin' && zSciezka.env.WP_API_PASSWORD === wpis.env.WP_API_PASSWORD
      && bezSciezki.command === 'npx' && !('PATH' in bezSciezki.env), J([zSciezka, bezSciezki]));
    t.check('bez błędów JS na stronie zakładki', bledy.length === 0, J(bledy));
    const bezHttps = sonda('ajax-haslo', 'http');
    t.check('strona bez HTTPS: hasło odmówione z wyjaśnieniem', bezHttps.odp && bezHttps.odp.success === false && /HTTPS/.test(bezHttps.odp.data), J(bezHttps.odp));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
