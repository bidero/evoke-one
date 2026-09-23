/**
 * Backup — Dysk Google w PRAWDZIWYM panelu, w przeglądarce: testowy
 * WordPress przez php -S (jak backup-panel), Google zastępuje atrapa
 * (tests/php/_google-atrapa.php) — razem z PRAWDZIWĄ stroną przekierowującą
 * z tools/oauth-relay/index.html.
 *
 * Cała droga „jednym kliknięciem": Połącz → zgoda (atrapa) → strona
 * przekierowująca → powrót do panelu → konto widoczne. Dalej wysyłka kopii
 * z listy, plakietka, lista z Dysku, pobranie z przywracaniem, telefon,
 * odmowa w Google i rozłączenie.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');
const atrapaGoogle = require('./lib/google-atrapa');

const sonda = (a) => JSON.parse(phpOutput('backup-panel.php', a));
const widac = (page, sel) => page.locator(sel).first().isVisible();

module.exports = async function (t) {
  t.section('łączenie z Dyskiem jednym kliknięciem');
  const g = await atrapaGoogle.start();
  let serwer = null;
  let browser;
  try {
    const prep = sonda('przygotuj-dysk ' + g.adres + ' ' + g.posrednik);
    t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak, prep.brak || prep.wp);
    if (prep.brak) return;

    serwer = await serwerWp.start(prep.wp);
    const baza = serwer.baza;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    const dialogi = [];
    let odrzucaj = false;
    p.on('dialog', (d) => { dialogi.push(d.message()); return odrzucaj ? d.dismiss() : d.accept(); });
    await serwerWp.zaloguj(p, baza);
    const zakladka = baza + '/wp-admin/options-general.php?page=evoke-one&tab=backup';
    await p.goto(zakladka);

    t.check('niepołączony: przycisk „Połącz z Dyskiem Google", bez wysyłki na liście',
      await widac(p, '[data-evk-gdrive-connect]') && (await p.locator('[data-evk-backup-drive]').count()) === 0);

    await Promise.all([p.waitForURL(/tab=backup/, { timeout: 30000 }), p.click('[data-evk-gdrive-connect]')]);
    await p.waitForLoadState('load');
    const drogi = (await g.stan()).log.map((l) => l.p);
    t.check('droga: zgoda w Google → strona przekierowująca → wymiana kodu (przez pośrednika)',
      drogi.includes('/o/oauth2/v2/auth') && drogi.includes('/evk-oauth/') && drogi.includes('/token'), JSON.stringify(drogi));
    const msg = await p.locator('[data-evk-gdrive-msg]').textContent().catch(() => '');
    t.check('powrót do zakładki z komunikatem o połączeniu i kontem',
      /Dysk Google połączony: wlasciciel@example\.com/.test(msg || '') && (await p.locator('[data-evk-gdrive-email]').textContent()) === 'wlasciciel@example.com',
      msg);
    await p.waitForSelector('[data-evk-gdrive-empty]', { timeout: 15000 }).catch(() => {});
    t.check('lista z Dysku wczytana (pusta) i zajęte miejsce widoczne',
      await widac(p, '[data-evk-gdrive-empty]') && /Zajęte na Dysku/.test(await p.locator('[data-evk-gdrive-quota]').textContent()));
    const czasy = await p.evaluate(() => {
      const d = document.querySelector('[data-evk-gdrive-czasy]');
      return d ? { sum: d.querySelector('summary').textContent, n: d.querySelectorAll('li').length, otwarte: d.open } : null;
    });
    t.check('pod listą zwinięte „Czasy połączenia z Google" z każdym żądaniem',
      czasy && /Czasy połączenia z Google \(lista: [\d,]+ s\)/.test(czasy.sum) && czasy.n >= 2 && !czasy.otwarte, JSON.stringify(czasy));
    const f0 = sonda('fakty-dysku');
    t.check('połączenie zapisane na serwerze', f0.polaczone && f0.email === 'wlasciciel@example.com', JSON.stringify(f0));

    t.section('wysyłka z listy, plakietka, lista z Dysku');
    t.check('przy kopii przycisk „Wyślij na Dysk Google" (ikona z opisem)',
      (await p.getAttribute('tr[data-archive="panel-dysk.zip"] [data-evk-backup-drive]', 'aria-label')) === 'Wyślij na Dysk Google');
    await p.click('tr[data-archive="panel-dysk.zip"] [data-evk-backup-drive]');
    await p.waitForSelector('[data-evk-backup-msg]', { timeout: 60000 });
    const msgW = await p.locator('[data-evk-backup-msg]').textContent();
    t.check('komunikat po wysyłce', /Kopia na Dysku Google: panel-dysk\.zip/.test(msgW), msgW);
    await p.waitForSelector('tr[data-archive="panel-dysk.zip"] [data-evk-backup-on-drive]', { timeout: 10000 }).catch(() => {});
    t.check('plakietka „na Dysku" przy kopii, przycisku wysyłki już nie ma',
      await widac(p, 'tr[data-archive="panel-dysk.zip"] [data-evk-backup-on-drive]')
        && (await p.locator('tr[data-archive="panel-dysk.zip"] [data-evk-backup-drive]').count()) === 0);
    await p.waitForSelector('[data-evk-gdrive-list] tr[data-drive-id]', { timeout: 10000 }).catch(() => {});
    const wiersz = p.locator('[data-evk-gdrive-list] tr[data-drive-id]');
    t.check('lista z Dysku odświeżona: kopia tej strony, jest też na serwerze',
      (await wiersz.count()) === 1 && /ta strona/.test(await wiersz.textContent()) && await widac(p, '[data-evk-gdrive-local]'));
    const plikNaDysku = Object.values((await g.stan()).pliki).find((x) => x.name === 'panel-dysk.zip');
    t.check('na Dysku (atrapie) co do bajtu', plikNaDysku && plikNaDysku.md5Checksum === prep.md5, JSON.stringify(plikNaDysku || null));

    t.section('telefon: rząd akcji mieści się z przyciskiem Dysku');
    await p.setViewportSize({ width: 360, height: 800 });
    await p.goto(zakladka);
    await p.waitForSelector('[data-evk-gdrive-list] tr[data-drive-id]', { timeout: 15000 }).catch(() => {});
    const geo = await p.evaluate(() => {
      const r = (s) => { const e = document.querySelector(s); return e ? e.getBoundingClientRect() : null; };
      const wiersze = [...document.querySelectorAll('.evk-backup-lista tr[data-archive], .evk-gdrive-lista tr[data-drive-id]')];
      return {
        strona: document.documentElement.scrollWidth,
        wystaje: wiersze.flatMap((tr) => [...tr.querySelectorAll('.button')].map((b) => {
          const a = b.getBoundingClientRect();
          const w = tr.getBoundingClientRect();
          return a.right > w.right + 0.5 || a.left < w.left - 0.5;
        })).filter(Boolean).length,
        przyciski: wiersze.map((tr) => tr.querySelectorAll('.button').length),
        konto: r('.evk-gdrive-konto') && r('.evk-gdrive-konto').width,
      };
    });
    t.check('na 360 px bez przewijania w bok, żaden przycisk nie wystaje z wiersza',
      geo.strona <= 360 && geo.wystaje === 0, JSON.stringify(geo));
    await p.setViewportSize({ width: 1280, height: 1000 });

    t.section('pobranie z Dysku i przywracanie');
    await p.click('tr[data-archive="panel-dysk.zip"] [data-evk-backup-delete]');
    await p.waitForSelector('[data-evk-backup-empty]', { timeout: 10000 });
    await p.click('[data-evk-gdrive-list] [data-evk-gdrive-restore]');
    await p.waitForSelector('[data-evk-restore-dialog][open]', { timeout: 60000 }).catch(() => {});
    await p.waitForFunction(() => /zrodlo-dysku\.test/.test((document.querySelector('[data-evk-restore-info]') || {}).textContent || ''),
      null, { timeout: 15000 }).catch(() => {});
    const info = await p.locator('[data-evk-restore-info]').textContent();
    t.check('„Pobierz i przywróć": po pobraniu otwiera się okno przywracania z danymi tej kopii',
      await p.locator('[data-evk-restore-dialog]').evaluate((d) => d.open) && /zrodlo-dysku\.test/.test(info), info);
    await p.click('[data-evk-restore-close]');
    const msgP = await p.locator('[data-evk-backup-msg]').textContent();
    t.check('komunikat z przyciskiem „Przywróć teraz"',
      /Kopia pobrana z Dysku Google: panel-dysk\.zip/.test(msgP) && await widac(p, '[data-evk-backup-msg-restore]'), msgP);
    const f1 = sonda('fakty-dysku');
    const pobrana = f1.kopie.find((k) => k.archive === 'panel-dysk.zip');
    t.check('pobrana kopia na serwerze co do bajtu, jako „z Dysku", część sprzątnięta',
      pobrana && pobrana.md5 === prep.md5 && pobrana.source === 'gdrive' && pobrana.drive_id !== '' && f1.czesci.length === 0,
      JSON.stringify(f1.kopie));
    await p.click('[data-evk-gdrive-list] [data-evk-gdrive-restore]');
    await p.waitForSelector('[data-evk-restore-dialog][open]', { timeout: 10000 }).catch(() => {});
    const zadania = sonda('fakty-dysku').zadania.filter((z) => z.type === 'download').length;
    t.check('kopia już na serwerze: okno od razu, bez drugiego pobierania',
      await p.locator('[data-evk-restore-dialog]').evaluate((d) => d.open) && zadania === 1, 'pobrań: ' + zadania);
    await p.click('[data-evk-restore-close]');

    t.section('pobieranie bez pracy w tle: pasek żyje');
    /* ZGŁOSZONE (evoke.pl, 1.229.3): „idzie szybko, ale pasek się nie
       odświeża" — stoi, potem od razu gotowe. Bez żądań zwrotnych i WP-Cron
       kroki robi karta panelu; pobranie ~1,3 MB po 200 KB/s trwa ~6,5 s. */
    sonda('bez-loopbacku 1');
    await g.ster({ czekaj: 0.5, wolno: 200000 });
    await p.goto(zakladka);
    await p.waitForSelector('[data-evk-gdrive-list] tr[data-drive-id]', { timeout: 15000 }).catch(() => {});
    await p.click('tr[data-archive="panel-dysk.zip"] [data-evk-backup-delete]');
    await p.waitForSelector('[data-evk-backup-empty]', { timeout: 10000 });
    await p.click('[data-evk-gdrive-list] [data-evk-gdrive-restore]');
    const probki = [];
    const tp = Date.now();
    while (Date.now() - tp < 90000) {
      const txt = await p.locator('[data-evk-backup-percent]').innerText().catch(() => '');
      const m = txt.match(/^(\d+)%/);
      if (m && probki[probki.length - 1] !== +m[1]) probki.push(+m[1]);
      if (await widac(p, '[data-evk-backup-msg]')) break;
      await p.waitForTimeout(250);
    }
    const czasPobrania = (Date.now() - tp) / 1000;
    await p.waitForSelector('[data-evk-restore-dialog][open]', { timeout: 10000 }).catch(() => {});
    if (await p.locator('[data-evk-restore-dialog]').evaluate((d) => d.open)) await p.click('[data-evk-restore-close]');
    const f3 = sonda('fakty-dysku');
    const pob = f3.zadania.filter((z) => z.type === 'download').pop() || {};
    const kroki = (pob.log || '').split('\n').filter((l) => / Krok \d+/.test(l)).map((l) => l.replace(/^\S+ /, ''));
    /* Zmierzone przed poprawką: 0 → 100 w 8,0 s (krok robiło pytanie o stan,
       odpowiedź dopiero po kroku). Po niej: 0 → 23 → 39 → 54 → 69 → 85 → 100.
       Próg: co najmniej 5 różnych wartości. */
    t.check('pasek rusza się w trakcie pobierania, gdy krok robi karta panelu (≥ 5 wartości)',
      new Set(probki).size >= 5, probki.join(' → ') + ' (' + czasPobrania.toFixed(1) + ' s)');
    t.check('dziennik: kto zrobił krok („z panelu")', kroki.length > 0 && kroki.every((k) => /^Krok \d+ \(z panelu\): /.test(k)),
      kroki.join(' | ') || 'brak linii kroku');
    const f3k = f3.kopie.find((k) => k.archive === 'panel-dysk.zip');
    t.check('pobrana bez pracy w tle co do bajtu', f3k && f3k.md5 === prep.md5 && pob.status === 'done', JSON.stringify(f3k || null));
    sonda('bez-loopbacku 0');

    t.section('czekanie na Dysk: pasek w ruchu i licznik sekund');
    /* Zmierzone na evoke.pl (1.229.4): 28,6 s do pierwszego bajtu, potem cały
       plik w sekundę — pasek stał na 0% i od razu był pełny. Atrapa: 3 s
       czekania, potem plik bez dławienia; praca w tle jak na evoke.pl. */
    await g.ster({ czekaj: 3, wolno: 0 });
    await p.goto(zakladka);
    await p.waitForSelector('[data-evk-gdrive-list] tr[data-drive-id]', { timeout: 15000 }).catch(() => {});
    await p.click('tr[data-archive="panel-dysk.zip"] [data-evk-backup-delete]');
    await p.waitForSelector('[data-evk-backup-empty]', { timeout: 10000 });
    await p.click('[data-evk-gdrive-list] [data-evk-gdrive-restore]');
    const czek = [];
    const tc = Date.now();
    while (Date.now() - tc < 60000) {
      const s1 = await p.evaluate(() => {
        const b = document.querySelector('[data-evk-backup-bar]');
        return { k: b.classList.contains('is-czeka'), a: getComputedStyle(b, '::after').animationName, v: b.getAttribute('aria-valuenow'),
          t: document.querySelector('[data-evk-backup-percent]').textContent.split(' · ')[1] || '' };
      });
      if (s1.k) czek.push(s1);
      if (await widac(p, '[data-evk-backup-msg]')) break;
      await p.waitForTimeout(250);
    }
    await p.waitForSelector('[data-evk-restore-dialog][open]', { timeout: 10000 }).catch(() => {});
    if (await p.locator('[data-evk-restore-dialog]').evaluate((d) => d.open)) await p.click('[data-evk-restore-close]');
    const liczby = [...new Set(czek.map((x) => (x.t.match(/przygotowuje plik… (\d+) s/) || [])[1]).filter(Boolean))];
    const koniecKlasa = await p.evaluate(() => document.querySelector('[data-evk-backup-bar]').className);
    /* Zmierzone: 11 próbek (co 250 ms) z paskiem w ruchu, licznik 0 → 1 → 2 s,
       animacja „evk-backup-czeka", bez aria-valuenow; po końcu zwykły pasek. */
    t.check('przez czekanie pasek w ruchu (animacja), bez udawanego procentu (bez aria-valuenow)',
      czek.length >= 4 && czek.every((x) => x.a === 'evk-backup-czeka' && x.v === null), JSON.stringify(czek.slice(0, 2)) + ' próbek: ' + czek.length);
    t.check('licznik sekund czekania rośnie („Dysk Google przygotowuje plik… N s")', liczby.length >= 2, liczby.join(' → ') || 'brak');
    t.check('po pobraniu pasek znów zwykły', !/is-czeka/.test(koniecKlasa), koniecKlasa);
    await g.ster({ czekaj: 0, wolno: 0 });

    t.section('usuwanie kopii z Dysku');
    await p.goto(zakladka);
    await p.waitForSelector('[data-evk-gdrive-list] tr[data-drive-id]', { timeout: 15000 }).catch(() => {});
    const usunDysk = '[data-evk-gdrive-list] tr[data-drive-id] [data-evk-gdrive-delete]';
    t.check('przy kopii z Dysku przycisk „Usuń z Dysku Google"', (await p.getAttribute(usunDysk, 'aria-label')) === 'Usuń z Dysku Google');
    odrzucaj = true;
    dialogi.length = 0;
    await p.click(usunDysk);
    await p.waitForTimeout(1500);
    odrzucaj = false;
    const plikiPoOdmowie = Object.values((await g.stan()).pliki).filter((x) => x.name === 'panel-dysk.zip').length;
    t.check('„Anuluj" w pytaniu: nic nie znika', plikiPoOdmowie === 1 && (await p.locator('[data-evk-gdrive-list] tr[data-drive-id]').count()) === 1,
      'na Dysku: ' + plikiPoOdmowie);
    t.check('pytanie z datą kopii i informacją, że kopia na serwerze zostaje',
      dialogi.length === 1 && /^Usunąć z Dysku Google kopię z \d{4}-\d\d-\d\d \d\d:\d\d\? Tego nie da się cofnąć\.\nKopia na serwerze zostaje\.$/.test(dialogi[0]),
      JSON.stringify(dialogi));
    await p.click(usunDysk);
    await p.waitForSelector('[data-evk-gdrive-empty]', { timeout: 15000 }).catch(() => {});
    const msgU = await p.locator('[data-evk-backup-msg]').textContent().catch(() => '');
    t.check('po zgodzie: kopia znika z Dysku i z listy, komunikat',
      Object.values((await g.stan()).pliki).every((x) => x.name !== 'panel-dysk.zip') && await widac(p, '[data-evk-gdrive-empty]')
        && /Usunięto z Dysku Google: panel-dysk\.zip\. Kopia na serwerze zostaje\./.test(msgU || ''), msgU);
    t.check('kopia na serwerze zostaje: bez plakietki „na Dysku", wraca „Wyślij na Dysk Google"',
      (await p.locator('tr[data-archive="panel-dysk.zip"]').count()) === 1
        && (await p.locator('tr[data-archive="panel-dysk.zip"] [data-evk-backup-on-drive]').count()) === 0
        && await widac(p, 'tr[data-archive="panel-dysk.zip"] [data-evk-backup-drive]'));
    const nonceU = await p.request.post(baza + '/wp-admin/admin-ajax.php', { form: { action: 'evk_backup_gdrive_delete', nonce: 'zly', file: 'F000000000000' } });
    t.check('usuwanie z Dysku bez ważnego nonce: 403', nonceU.status() === 403, String(nonceU.status()));
    // Z powrotem na Dysk — dalsze sprawdzenia (rozłączenie) liczą na tę kopię.
    await p.click('tr[data-archive="panel-dysk.zip"] [data-evk-backup-drive]');
    await p.locator('[data-evk-backup-msg]').filter({ hasText: /Kopia na Dysku Google: panel-dysk\.zip/ }).waitFor({ timeout: 60000 }).catch(() => {});
    t.check('i można ją wysłać jeszcze raz', Object.values((await g.stan()).pliki).some((x) => x.name === 'panel-dysk.zip'));

    t.section('odmowa w Google, rozłączenie');
    await p.click('[data-evk-gdrive-disconnect]');
    await p.waitForSelector('[data-evk-gdrive-connect]', { timeout: 15000 });
    const f2 = sonda('fakty-dysku');
    t.check('„Rozłącz": token cofnięty w Google, połączenie zapomniane, znów „Połącz"',
      (await g.stan()).cofniete === 1 && f2.polaczone === false, JSON.stringify({ cofniete: (await g.stan()).cofniete, pol: f2.polaczone }));
    t.check('kopie na Dysku zostają po rozłączeniu', Object.values((await g.stan()).pliki).some((x) => x.name === 'panel-dysk.zip'));
    await g.ster({ odmowa: 1 });
    await Promise.all([p.waitForURL(/tab=backup/, { timeout: 30000 }), p.click('[data-evk-gdrive-connect]')]);
    await p.waitForLoadState('load');
    const msgO = await p.locator('[data-evk-gdrive-msg]').textContent().catch(() => '');
    t.check('odmowa w oknie Google: komunikat, nadal niepołączony',
      /anulowane w oknie Google/.test(msgO || '') && await widac(p, '[data-evk-gdrive-connect]'), msgO);
    await p.reload();
    t.check('komunikat po powrocie jednorazowy (po przeładowaniu go nie ma)', (await p.locator('[data-evk-gdrive-msg]').count()) === 0);

    t.check('bez błędów JavaScriptu na stronie', bledy.length === 0, bledy.join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    await g.zatrzymaj();
    sonda('sprzataj');
  }
};
