/**
 * Backup — Dysk Google (includes/backup/gdrive.php) na prawdziwym
 * WordPressie z tools/testowy-wp.sh i atrapie Google
 * (tests/php/_google-atrapa.php) zamiast prawdziwych serwerów Google.
 *
 * Najważniejsze: łączenie jest bezpieczne mimo strony pośredniej na evoke.pl
 * (podpis `state`, PKCE, jednorazowość, użytkownik), wysyłka wznawia się po
 * ubitym kroku od miejsca, które zna GOOGLE, a nie baza, retencja nie rusza
 * przypiętych ani cudzych kopii, a dostęp cofnięty w Google nie przechodzi
 * po cichu.
 *
 * Strona przekierowująca (tools/oauth-relay/index.html) w Chromium: przepuszcza
 * wyłącznie powrót do admin-post.php z akcją wtyczki.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const atrapaGoogle = require('./lib/google-atrapa');

module.exports = async function (t) {
  const g = await atrapaGoogle.start();
  let browser;
  try {
    process.env.EVK_GOOGLE = g.adres;
    process.env.EVK_GOOGLE_BROKER = g.posrednik;
    const w = JSON.parse(phpOutput('backup-drive.php'));
    delete process.env.EVK_GOOGLE;
    delete process.env.EVK_GOOGLE_BROKER;

    t.section('łączenie: adres zgody, podpis, PKCE, jednorazowość');
    t.check('testowy WordPress jest (tools/testowy-wp.sh)', !w.brak, w.brak || 'jest');
    if (w.brak) return;
    const a = w.adres_zgody;
    t.check('zgoda: PKCE S256, dostęp offline, zgoda wymuszona (token odświeżania), zakres drive.file',
      a.pkce === 'S256' && a.wyzwanie === 43 && a.offline === 'offline' && a.zgoda === 'consent'
        && /auth\/drive\.file/.test(a.zakres) && a.klient === 'test-klient' && a.powrot, JSON.stringify(a));
    t.check('state niesie adres powrotu tej strony (admin-post.php z akcją wtyczki)', a.u_ok, a.u);
    t.check('zmieniony podpis state: odmowa', /Podpis powrotu/.test(w.zly_podpis), w.zly_podpis);
    t.check('podmieniony adres powrotu w state (stary podpis): odmowa', /Podpis powrotu/.test(w.podmieniona_tresc), w.podmieniona_tresc);
    t.check('powrót do innego użytkownika niż ten, który zaczął łączenie: odmowa', /inny użytkownik/.test(w.inny_uzytkownik), w.inny_uzytkownik);
    t.check('ten sam powrót drugi raz: odmowa (weryfikator jednorazowy)', /wygasło/.test(w.drugi_raz), w.drugi_raz);
    t.check('kod bez właściwego weryfikatora PKCE: Google odmawia, nic nie połączone',
      /Invalid code verifier/.test(w.zly_weryfikator) && w.po_zlym === false, w.zly_weryfikator);
    t.check('zgoda bez dostępu do plików Dysku: czytelna odmowa, nic nie połączone',
      /dostępu do plików/.test(w.bez_dysku) && w.bez_dysku_polaczone === false, w.bez_dysku);
    const pol = w.polaczone;
    t.check('połączone: token odświeżania, konto z id_token, folder „Evoke ONE — host" z kluczem strony',
      pol.refresh && pol.email === 'wlasciciel@example.com' && pol.foldery === 1 && pol.folder_nazwa === 'Evoke ONE — ' + pol.klucz
        && pol.folder_strona === pol.klucz, JSON.stringify(pol));
    t.check('ponowne połączenie tego samego konta: ten sam folder, bez drugiego',
      w.ponownie.ten_sam && w.ponownie.foldery === 1, JSON.stringify(w.ponownie));

    t.section('wysyłka w kawałkach, wznowienie od stanu Google');
    const u = w.wysylka;
    t.check('wysyłka skończona, plik na Dysku co do bajtu, w folderze strony',
      u.status === 'done' && u.md5 && u.rozmiar && u.folder, JSON.stringify({ status: u.status, blad: u.blad, md5: u.md5, folder: u.folder }));
    t.check('krok ubity po dwóch kawałkach (pozycja w bazie cofnięta): pozycja od Google, żaden kawałek nie poszedł w złe miejsce',
      u.cofniete === 524288 && u.wznowienie && u.niezgodne_po_ubiciu === 0,
      JSON.stringify({ cofniete: u.cofniete, wznowienie: u.wznowienie, niezgodne: u.niezgodne_po_ubiciu }));
    /* Od pozycji sprzed błędów: brakujące kawałki po 256 KB i +1 za ten
       odrzucony błędem 503. Kawałek przyjęty ze zgubioną odpowiedzią NIE
       idzie drugi raz — pozycję po błędzie mówi Google. */
    const brakowalo = Math.ceil((u.rozmiar_zip - u.pos_przed_bledami) / 262144);
    t.check('chwilowe błędy w środku kroku: kawałek odrzucony wysłany ponownie, przyjęty ze zgubioną odpowiedzią — nie',
      u.ponowienia === 2 && u.pos_przed_bledami > 0 && u.kawalki_po_bledach === brakowalo + 1 && u.niezgodne_po_bledach === 0,
      JSON.stringify({ ponowienia: u.ponowienia, kawalki: u.kawalki_po_bledach, oczekiwane: brakowalo + 1, niezgodne: u.niezgodne_po_bledach }));
    t.check('token dostępu odrzucony (401): odświeżony raz, żądanie powtórzone', u.odswiezenia === 1, String(u.odswiezenia));
    t.check('dane kopii w appProperties (lista z Dysku bez otwierania archiwów)',
      u.wlasciwosci.evk === 'backup' && u.wlasciwosci.evk_site === pol.klucz && /^\d{10}$/.test(u.wlasciwosci.created)
        && u.wlasciwosci.source === 'manual' && u.wlasciwosci.pinned === '0', JSON.stringify(u.wlasciwosci));

    t.section('retencja na Dysku, przypięcie, lista');
    const r = w.retencja;
    t.check('kopia tej strony starsza niż 14 dni usunięta, świeża i właśnie wysłane zostają',
      r.status === 'done' && r.stara === false && r.swieza && r.pierwsza && r.log, JSON.stringify(r));
    t.check('przypięta starsza niż 14 dni zostaje', r.przypieta === true);
    t.check('stara kopia INNEJ strony w innym folderze zostaje', r.cudza === true);
    t.check('przypięcie na liście lokalnej idzie na Dysk', w.przypiecie === '1', String(w.przypiecie));
    const l = w.lista;
    t.check('lista z Dysku: od najnowszej, także kopie innych stron (przenosiny)',
      l.nazwy[0] === 'dysk-test-2.zip' && l.nazwy.includes('cudza.zip') && l.cudza_strona && l.ta_strona === 4,
      JSON.stringify(l.nazwy));
    t.check('lista: plakietka „na serwerze" przy kopiach, które są też w katalogu kopii', l.na_serwerze === 2, String(l.na_serwerze));
    t.check('zajęte miejsce na Dysku po polsku', /^Zajęte na Dysku: 1,0 GB z 15,0 GB/.test(l.miejsce), l.miejsce);

    t.section('pobranie z Dysku');
    const p = w.pobranie;
    t.check('pobrana kopia co do bajtu, na liście jako „z Dysku", z identyfikatorem i przypięciem z Dysku',
      p.status === 'done' && p.md5 && p.zrodlo === 'gdrive' && p.drive_id && p.przypieta && p.archiwum === 'dysk-test-1.zip',
      JSON.stringify({ status: p.status, blad: p.blad, md5: p.md5, zrodlo: p.zrodlo }));
    t.check('pobieranie jednym strumieniem od początku (+1 żądanie za chwilowy błąd 503), część sprzątnięta',
      p.zakresow === 2 && p.pierwszy === 'bytes=0-' && p.ostatni === 'bytes=0-' && !p.czesc_zostala,
      JSON.stringify({ n: p.zakresow, pierwszy: p.pierwszy, ostatni: p.ostatni }));
    t.check('pobrana kopia gotowa do przywrócenia (okno czyta jej manifest)', p.info === 'http://stara.test', p.info);
    t.check('kopia już na serwerze rozpoznana po identyfikatorze z Dysku (bez drugiego pobrania)',
      w.lokalna_po_usunieciu === '' && p.lokalna === 'dysk-test-1.zip', JSON.stringify([w.lokalna_po_usunieciu, p.lokalna]));
    t.check('plik z Dysku, który nie jest kopią wtyczki: odmowa', /nie jest kopia/.test(w.nie_kopia), w.nie_kopia);

    t.section('kopia nocna na Dysk, powiadomienia');
    t.check('po udanej kopii nocnej rusza wysyłka na Dysk', w.auto.nocna.length === 1 && w.auto.nocna[0].type === 'upload'
      && w.auto.nocna[0].source === 'schedule', JSON.stringify(w.auto.nocna));
    t.check('po ręcznej — nie; z wyłączonym ustawieniem — też nie',
      w.auto.reczna.length === 0 && w.auto.wylaczona.length === 0, JSON.stringify(w.auto));
    const f = w.nieudana;
    t.check('nieudana wysyłka kopii nocnej: powiadomienie, kopia na serwerze zostaje',
      f.status === 'failed' && /6 prób/.test(f.blad) && /wysyłka na Dysk Google nie powiodła się/.test(f.alert || '') && f.kopia_zostala,
      JSON.stringify(f));
    const c = w.cofniety;
    t.check('dostęp cofnięty w Google (invalid_grant): rozłączone i powiadomienie',
      /cofnął dostęp/.test(c.blad) && c.polaczone === false && c.alert === 'Dysk Google rozłączony', JSON.stringify(c));

    // ── Pobieranie strumieniem (1.229.3) ─────────────────────────────────
    /* Zmierzone na evoke.pl (1.229.2): każde pobranie czeka ~29 s na pierwszy
       bajt, niezależnie od wielkości — koszt jest na żądanie. Atrapa odtwarza
       to: 1,5 s czekania na każde żądanie, potem 1 MB/s; kroki po 1 s. */
    t.section('pobieranie strumieniem: jedno żądanie na krok');
    const MB = 1048576;
    const sm = w.strumien;
    const dane = sm.zakresy.slice(1);   // pierwsze: token dostępu odrzucony (401) → odświeżony
    t.check('plik 8 MB co do bajtu, w 2 żądaniach z danymi (kawałkami po 256 KB byłoby ich 32)',
      sm.pobranie === 'done' && sm.md5 && dane.length === 2, JSON.stringify({ status: sm.pobranie, blad: sm.blad, zakresy: sm.zakresy }));
    t.check('zakresy otwarte do końca pliku, każdy od rozmiaru części na dysku (wznowienie po kroku)',
      dane[0] === 'bytes=0-' && /^bytes=\d+-$/.test(dane[1]) && parseInt(dane[1].slice(6), 10) >= 4 * MB, JSON.stringify(sm.zakresy));
    t.check('kroki (1 s) krótsze niż czekanie na pierwszy bajt (1,5 s) i tak robią postęp', sm.kroki <= 4, 'kroków: ' + sm.kroki);
    t.check('postęp zapisywany W TRAKCIE żądania (pasek co sekundę), nie tylko po nim',
      sm.postepy >= 2 * dane.length, 'zapisów postępu: ' + sm.postepy + ', żądań: ' + dane.length);
    /* 1.229.4 z evoke.pl: 28,6 s czekania, potem 81,8 MB w sekundę — pasek stał
       i od razu był pełny. Panel pokazuje czekanie osobno. Zmierzone tu (atrapa
       1,5 s czekania): „Dysk Google przygotowuje plik… 1 s", przy drugim żądaniu
       „… (ostatnio ok. 2 s)", zapamiętane 1,6 s; gdy dane idą — „563,2 KB z 8,0 MB". */
    const widok = sm.widok;
    t.check('czekanie na pierwszy bajt: panel dostaje sekundy i opis „Dysk Google przygotowuje plik… N s"',
      widok.some(([c, d]) => c >= 1 && /^Dysk Google przygotowuje plik… \d+ s/.test(d)), JSON.stringify(widok));
    t.check('gdy dane idą: bez czekania, szczegół w MB',
      widok.some(([c, d]) => c === 'brak' && /B z 8,0 MB$/.test(d))
        && widok.every(([c, d]) => (c === 'brak') === /B z 8,0 MB$/.test(d)), JSON.stringify(widok));
    t.check('czas czekania zapamiętany i podany przy następnym żądaniu („ostatnio ok. 2 s")',
      sm.czekanie >= 1.4 && sm.czekanie <= 2.5 && widok.some(([, d]) => /\(ostatnio ok\. 2 s\)$/.test(d)),
      'zapamiętane: ' + sm.czekanie);
    t.check('po końcu pobierania nic nie czeka', sm.po_koncu === null, String(sm.po_koncu));
    t.check('bez Accept-Encoding (ZIP już jest skompresowany)', sm.ae.length === 1 && sm.ae[0] === '', JSON.stringify(sm.ae));
    t.check('token dostępu odrzucony w strumieniu (401): odświeżony, pobieranie idzie dalej', sm.odswiezenia === 1, String(sm.odswiezenia));
    const z1 = sm.log_zadanie[0] || '';
    t.check('dziennik: żądanie z czasem do pierwszego bajtu, prędkością po nim, kompresją, adresem i przerwaniem na końcu kroku',
      /pierwszy bajt 1,[5-9]\d s/.test(z1) && /po pierwszym bajcie 0,9\d MB\/s|po pierwszym bajcie 1,0\d MB\/s/.test(z1)
        && /kompresja: brak/.test(z1) && /127\.0\.0\.1 \(IPv4\)/.test(z1) && /przerwane na końcu kroku/.test(z1), z1);
    t.check('dziennik: samo czekanie na odpowiedź (od wysłania żądania do pierwszego bajtu, atrapa czeka 1,5 s)',
      /\(czekanie na odpowiedź 1,[4-9]\d s\)/.test(z1), z1);
    const pk = w.pomiar_klucze || {};
    t.check('pomiar czyta tylko klucze, które ma prawdziwe curl_getinfo() (TLS z appconnect_time_us)',
      pk.kod === 200 && Array.isArray(pk.brak) && pk.brak.length === 0, JSON.stringify(pk));
    t.check('rozbicie z liczb evoke.pl: TLS 0,45 s zamiast 0,00, czekanie na odpowiedź 28,6 s',
      /TLS 0,45 s, pierwszy bajt 29,1 s \(czekanie na odpowiedź 28,6 s\)/.test(w.pomiar_opis || ''), w.pomiar_opis);
    t.check('dziennik: podsumowanie pobierania i wysyłki',
      /Pomiar: 2 żądań, 8,0 MB .+ średnio .+ MB\/s; .+ adresy: 127\.0\.0\.1 \(IPv4\)/.test(sm.log_pomiar[0] || '') && sm.log_wysylka.length === 1,
      (sm.log_pomiar[0] || '') + ' | ' + (sm.log_wysylka[0] || ''));
    t.check('wysyłka znów stałymi kawałkami (dobór z 1.229.2 wycofany)',
      sm.wysylka === 'done' && sm.puty.length === 3 && sm.puty[0] === 4 * MB && sm.puty[1] === 4 * MB, JSON.stringify(sm.puty));
    const sb = w.strumien_blad;
    t.check('błąd od Google w strumieniu (403): czytelny komunikat, do części nie trafia ani bajt',
      sb.status === 'failed' && /download quota/.test(sb.blad) && sb.czesc === false, JSON.stringify(sb));
    // ── Usuwanie jednej kopii z Dysku (1.229.6) ─────────────────────────
    t.section('usuwanie pojedynczej kopii z Dysku');
    const us = w.usuwanie;
    t.check('wysłana kopia usunięta z Dysku; na serwerze zostaje, bez identyfikatora Dysku',
      us.wysylka === 'done' && us.id && us.wyslana.local === 'dysk-usun.zip' && us.wyslana_na_dysku === false
        && us.lokalna === true && us.lokalna_drive_id === null && us.lokalna_zrodlo === 'manual', JSON.stringify(us));
    t.check('przypiętą i kopię innej strony też można usunąć (decyzja zgłaszającego)',
      us.przypieta.name === 'usun-przypieta.zip' && us.cudza.name === 'usun-cudza.zip'
        && us.przypieta_na_dysku === false && us.cudza_na_dysku === false, JSON.stringify([us.przypieta, us.cudza]));
    /* drive.file widzi też folder „Evoke ONE — …" — jego usunięcie zabrałoby
       wszystkie kopie w nim. */
    t.check('folder kopii i plik bez znacznika kopii: odmowa, zostają (z kopiami w folderze)',
      /nie jest kopia zapasowa/.test(us.folder) && /nie jest kopia zapasowa/.test(us.nie_kopia)
        && us.folder_na_dysku && us.nie_kopia_na_dysku && us.w_folderze >= 5, JSON.stringify([us.folder, us.w_folderze]));
    const li = w.lista;
    t.check('przycisk usuwania przy każdej kopii na liście; niesie stronę (inna), przypięcie i „na serwerze" do pytania',
      li.usun === li.wierszy && li.usun_strona === 1 && li.usun_przypieta === li.przypietych && li.przypietych >= 1 && li.usun_lokalna === li.na_serwerze,
      JSON.stringify({ usun: li.usun, wierszy: li.wierszy, strona: li.usun_strona, przyp: [li.usun_przypieta, li.przypietych], lok: [li.usun_lokalna, li.na_serwerze] }));
    t.check('zły identyfikator: odmowa bez żądania do Google', /Nieprawidłowy identyfikator/.test(us.zly_id) && us.zly_id_zadan === 0,
      us.zly_id + ' / żądań: ' + us.zly_id_zadan);
    t.check('kopia już usunięta: czytelny komunikat', /nie ma już na Dysku/.test(us.juz_nie_ma), us.juz_nie_ma);
    t.check('kopia, która właśnie się pobiera: odmowa, zostaje', /właśnie pobiera/.test(us.w_trakcie) && us.pobierana_na_dysku,
      us.w_trakcie);
    const lc = w.lista_czasy;
    t.check('lista z Dysku oddaje czasy każdego żądania (lista, miejsce) z rozbiciem',
      lc.n >= 2 && lc.co.includes('GET /drive/v3/files') && lc.co.includes('GET /drive/v3/about') && lc.opisy.every((o) => /DNS/.test(o)),
      JSON.stringify(lc.co));

    // ── Pośrednik tokenów (tools/oauth-relay/token.php) ──────────────────
    t.section('pośrednik tokenów: sekret tylko na evoke.pl');
    const dp = w.do_posrednika;
    t.check('strona nie wysyła sekretu klienta — ani przy łączeniu, ani przy odświeżaniu',
      dp.zadan >= 5 && dp.z_sekretem === 0 && dp.klucze.includes('client_id') && !dp.klucze.includes('client_secret'), JSON.stringify(dp));
    /* Odrzucone ma zostać NA POŚREDNIKU: atrapa Google też odpowiada 400
       na zły kod, więc sam status niczego nie dowodzi (mutacje „bez PKCE",
       „obcy powrót" i „dowolny grant" przechodziły). Liczymy żądania, które
       dotarły do Google. */
    const doGoogle = async () => (await g.stan()).log.filter((l) => l.p === '/token').length;
    const doP = async (dane, metoda = 'POST') => {
      const przed = await doGoogle();
      const r = await fetch(g.posrednik, metoda === 'POST'
        ? { method: 'POST', body: new URLSearchParams(dane) } : { method: metoda });
      const tekst = await r.text();
      return { status: r.status, tekst, doGoogle: (await doGoogle()) - przed };
    };
    const zly = {
      'GET': await doP({}, 'GET'),
      'inny grant': await doP({ grant_type: 'client_credentials' }),
      'kod bez PKCE': await doP({ grant_type: 'authorization_code', code: 'x', redirect_uri: g.adres + '/evk-oauth/' }),
      'obcy powrót': await doP({ grant_type: 'authorization_code', code: 'x', code_verifier: 'a'.repeat(64), redirect_uri: 'https://zly.pl/' }),
      'bez tokenu': await doP({ grant_type: 'refresh_token' }),
    };
    const kody = Object.fromEntries(Object.entries(zly).map(([k, v]) => [k, v.status + (v.doGoogle ? ' →Google' : '')]));
    t.check('pośrednik: tylko POST', kody.GET === '405', JSON.stringify(kody));
    t.check('pośrednik: inny rodzaj żądania niż kod i odświeżenie — odmowa, do Google nie idzie',
      kody['inny grant'] === '400' && /unsupported_grant_type/.test(zly['inny grant'].tekst), JSON.stringify(kody));
    t.check('pośrednik: kod bez weryfikatora PKCE — odmowa, do Google nie idzie',
      kody['kod bez PKCE'] === '400' && /weryfikatora PKCE/.test(zly['kod bez PKCE'].tekst), JSON.stringify(kody));
    t.check('pośrednik: adres powrotu inny niż evoke.pl — odmowa, do Google nie idzie',
      kody['obcy powrót'] === '400' && /adres powrotu/.test(zly['obcy powrót'].tekst), JSON.stringify(kody));
    t.check('pośrednik: odświeżenie bez tokenu — odmowa, do Google nie idzie', kody['bez tokenu'] === '400', JSON.stringify(kody));
    const odmowaG = await doP({ grant_type: 'refresh_token', refresh_token: '1//nieznany', client_secret: 'podrzucony' });
    t.check('poprawne żądanie idzie do Google, odpowiedź oddana bez zmian (tu: invalid_grant)',
      odmowaG.status === 400 && /invalid_grant/.test(odmowaG.tekst) && odmowaG.doGoogle === 1, odmowaG.status + ' ' + odmowaG.tekst);
    t.check('sekret nie wraca w żadnej odpowiedzi pośrednika',
      ![...Object.values(zly), odmowaG].some((r) => r.tekst.includes('test-sekret')));

    // ── Strona przekierowująca w Chromium ────────────────────────────────
    t.section('strona przekierowująca (tools/oauth-relay/index.html)');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const strona = await browser.newPage();
    const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const przejdz = async (u, reszta) => {
      const state = b64({ u, n: 'a'.repeat(32) }) + '.podpis';
      await strona.goto(g.adres + '/evk-oauth/?' + new URLSearchParams(Object.assign({ state }, reszta)).toString());
      await strona.waitForTimeout(300);
      return { url: strona.url(), tekst: await strona.evaluate(() => (document.getElementById('stan') || {}).textContent || '') };
    };
    const cel = g.adres + '/wp-admin/admin-post.php?action=evk_backup_gdrive_callback';
    const dobry = await przejdz(cel, { code: '4/kod', scope: 'x' });
    const dobryUrl = new URL(dobry.url);
    t.check('dobry adres powrotu: przekierowanie z kodem i state, akcja zachowana',
      dobryUrl.pathname === '/wp-admin/admin-post.php' && dobryUrl.searchParams.get('code') === '4/kod'
        && dobryUrl.searchParams.get('action') === 'evk_backup_gdrive_callback' && /\.podpis$/.test(dobryUrl.searchParams.get('state')),
      dobry.url);
    const odmowa = await przejdz(cel, { error: 'access_denied' });
    t.check('odmowa w Google: błąd przekazany na stronę', new URL(odmowa.url).searchParams.get('error') === 'access_denied', odmowa.url);
    const zle = {
      'javascript:': 'javascript:alert(1)//wp-admin/admin-post.php?action=evk_backup_gdrive_callback',
      'inna ścieżka': g.adres + '/zly/sciezka?action=evk_backup_gdrive_callback',
      'inna akcja': g.adres + '/wp-admin/admin-post.php?action=cos_innego',
      'kotwica': cel + '#x',
      'login w adresie': cel.replace('http://', 'http://ktos:haslo@'),
    };
    const wyniki = {};
    for (const [nazwa, u2] of Object.entries(zle)) {
      const r2 = await przejdz(u2, { code: '4/kod' });
      wyniki[nazwa] = r2.url.startsWith(g.adres + '/evk-oauth/') && /Nie rozpoznaję/.test(r2.tekst);
    }
    t.check('adresy spoza wzorca odrzucone (javascript:, inna ścieżka, inna akcja, kotwica, login w adresie)',
      Object.values(wyniki).every(Boolean), JSON.stringify(wyniki));
  } finally {
    delete process.env.EVK_GOOGLE;
    delete process.env.EVK_GOOGLE_BROKER;
    if (browser) await browser.close();
    await g.zatrzymaj();
  }
};
