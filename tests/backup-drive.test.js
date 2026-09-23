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
    // Rozmiar archiwum waha się o bajt (manifest z datą jest kompresowany) — zakresy liczone od niego.
    const KAW = 262144;
    t.check('pobieranie zakresami po 256 KB (+1 za chwilowy błąd), część sprzątnięta',
      p.zakresow === Math.ceil(p.rozmiar / KAW) + 1 && p.pierwszy === 'bytes=0-' + (KAW - 1)
        && p.ostatni === 'bytes=' + Math.floor(p.rozmiar / KAW) * KAW + '-' + (p.rozmiar - 1) && !p.czesc_zostala,
      JSON.stringify({ n: p.zakresow, rozmiar: p.rozmiar, pierwszy: p.pierwszy, ostatni: p.ostatni }));
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

    // ── Czasy połączeń i kawałek dopasowany do prędkości (1.229.2) ───────
    t.section('pomiar połączeń, kawałek dopasowany do prędkości');
    const MB = 1048576;
    const d = w.dopasowanie;
    t.check('evoke.pl (8 MB w 27 s): następny kawałek 512 KB — żądanie ~2 s zamiast 27 s', d.wolno === 512 * 1024, JSON.stringify(d));
    t.check('szybkie łącze: do sufitu (8 MB); bardzo wolne: najmniej 256 KB', d.szybko === 8 * MB && d.bardzo === 256 * 1024, JSON.stringify(d));
    t.check('duży narzut łączenia (3 s): żądanie co najmniej 3× dłuższe niż łączenie', d.narzut === 9 * 256 * 1024, JSON.stringify(d));
    t.check('sufit spoza siatki 256 KB: w dół do wielokrotności', d.sufit === 512 * 1024, JSON.stringify(d));
    const tp = w.tempo;
    t.check('pobieranie przy 1 MB/s: plik co do bajtu, pierwszy zakres 1 MB (pasek rusza od razu)',
      tp.pobranie === 'done' && tp.md5 && tp.zakresy[0] === MB, JSON.stringify(tp.zakresy));
    t.check('…kolejne zakresy dopasowane do ~2,5 s (2–2,75 MB), nie stały sufit 4 MB',
      tp.zakresy.length >= 3 && tp.zakresy.slice(1, -1).every((z) => z >= 2 * MB && z <= 2.75 * MB), JSON.stringify(tp.zakresy));
    t.check('wysyłka: pierwszy kawałek 1 MB, kolejne wielokrotnością 256 KB (wymóg Google)',
      tp.wysylka === 'done' && tp.puty[0] === MB && tp.puty.slice(0, -1).every((n) => n % 262144 === 0), JSON.stringify(tp.puty));
    const k1 = tp.log_kawalek[0] || '';
    t.check('dziennik: kawałek z prędkością, czasami DNS/połączenie/TLS/pierwszy bajt, adresem IP i wielkością następnego',
      /MB\/s/.test(k1) && /DNS .+ połączenie .+ TLS .+ pierwszy bajt/.test(k1) && /127\.0\.0\.1 \(IPv4\)/.test(k1) && /Następny: 2,3 MB/.test(k1), k1);
    t.check('dziennik: podsumowanie pobierania i wysyłki (średnia prędkość, średnie czasy, adresy)',
      /Pomiar: \d+ kawałków, [\d,]+ MB .+ średnio .+ MB\/s; .+ adresy: 127\.0\.0\.1 \(IPv4\)/.test(tp.log_pomiar[0] || '') && tp.log_wysylka.length === 1,
      (tp.log_pomiar[0] || '') + ' | ' + (tp.log_wysylka[0] || ''));
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
