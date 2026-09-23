/**
 * Backup — przywracanie, kopia nocna i powiadomienia w PRAWDZIWYM panelu,
 * w przeglądarce (testowy WordPress przez php -S, jak backup-panel).
 *
 * Osobny plik, bo backup-panel trwa już ponad dwie minuty (CLAUDE.md: plik
 * za duży do iterowania — dzielić, nie filtrować).
 *
 * Najważniejsze: przywrócenie bazy WYLOGOWUJE osobę przy panelu (podmienia
 * użytkowników i sesje), a pasek ma i tak dojść do końca — stan idzie
 * tokenem zadania, nie sesją. Test sprawdza to wprost: po końcu zakładka
 * odsyła do logowania, a zaległa zmiana (znacznik) jest cofnięta.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => JSON.parse(phpOutput('backup-panel.php', a));

const widac = (page, sel) => page.locator(sel).isVisible();

module.exports = async function (t) {
  t.section('środowisko: kopia do przywrócenia, plik w katalogu FTP');

  const prep = sonda('przygotuj-przywracanie');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak, prep.brak || prep.wp);
  if (prep.brak) return;
  t.check('kopia źródłowa gotowa', prep.status === 'done', prep.status);

  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(prep.wp);
    const baza = serwer.baza;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    p.on('dialog', (d) => d.accept());
    await serwerWp.zaloguj(p, baza);
    const zakladka = baza + '/wp-admin/options-general.php?page=evoke-one&tab=backup';

    // ── Komunikat o nieudanej kopii ────────────────────────────────────────
    t.section('komunikat w panelu o nieudanej kopii');
    await p.goto(baza + '/wp-admin/index.php');
    t.check('komunikat widoczny w całym panelu (kokpit)', await widac(p, '[data-evk-backup-alert]'));
    await p.click('[data-evk-backup-alert] .notice-dismiss');
    let alertPo;
    for (let i = 0; i < 20; i++) { alertPo = sonda('fakty-przywracania').alert; if (alertPo === false) break; await p.waitForTimeout(250); }
    await p.reload();
    t.check('krzyżyk zamyka go na stałe (po przeładowaniu też nie ma)',
      alertPo === false && !(await widac(p, '[data-evk-backup-alert]')), JSON.stringify(alertPo));

    // ── Katalog FTP ────────────────────────────────────────────────────────
    t.section('kopie wgrane przez FTP');
    await p.goto(zakladka);
    const ftp = sonda('fakty-przywracania');
    t.check('stary plik z katalogu FTP przeniesiony do katalogu kopii jako „wgrana"',
      !ftp.ftp.includes('z ftp (1).zip') && ftp.kopie.some(([n, zr]) => n === 'z-ftp-1.zip' && zr === 'upload'),
      JSON.stringify(ftp.kopie));
    t.check('plik zmieniony przed chwilą (może się jeszcze wgrywać) zostaje na miejscu', ftp.ftp.includes('wgrywa-sie.zip'),
      JSON.stringify(ftp.ftp));
    t.check('zakładka mówi, co przeniosła', /Przeniesione z katalogu FTP: z-ftp-1/.test(await p.locator('#wpbody-content').innerText()));
    t.check('lista: obie kopie, każda z przyciskiem „Przywróć"', (await p.locator('tr[data-archive] [data-evk-backup-restore]').count()) === 2);
    const bezWgranej = () => p.locator('tr[data-archive]:not([data-archive="kopia-z-komputera.zip"])');
    /* Zgłoszone z użycia (1.227.1): sprawdzenie wgranych kopii bez przeładowania zakładki. */
    sonda('ftp-postarz');
    await p.click('[data-evk-backup-ftp]');
    await p.locator('[data-evk-backup-ftp-result]').filter({ hasText: /Przeniesione|Nowych/ }).waitFor({ timeout: 15000 });
    const ftpTxt = await p.locator('[data-evk-backup-ftp-result]').innerText();
    t.check('„Sprawdź katalog FTP": przenosi bez przeładowania, lista od razu z nową kopią',
      /Przeniesione na listę: wgrywa-sie\.zip/.test(ftpTxt) && (await bezWgranej().count()) === 3, ftpTxt);
    await p.click('[data-evk-backup-ftp]');
    await p.locator('[data-evk-backup-ftp-result]').filter({ hasText: /Nowych kopii/ }).waitFor({ timeout: 15000 });
    t.check('drugie sprawdzenie: „nowych kopii nie ma"', true);

    // ── Wgrywanie z komputera ──────────────────────────────────────────────
    t.section('wgrywanie kopii z komputera: kawałki, wznowienie, anulowanie');
    const wgraj = async () => {
      const [wybor] = await Promise.all([p.waitForEvent('filechooser'), p.click('[data-evk-upload-pick]')]);
      await wybor.setFiles(prep.plik);
    };
    /* Odpowiedź zgubiona: drugi kawałek DOCHODZI do serwera (route.fetch),
       a przeglądarka dostaje zerwane połączenie. Klient ponawia, serwer
       odpowiada rzeczywistym offsetem — plik na końcu ma być co do bajtu. */
    let kawalkow = 0;
    let zgubiona = false;
    await p.route('**/admin-ajax.php', async (route) => {
      const cialo = route.request().postDataBuffer();
      if (!zgubiona && cialo && cialo.toString('latin1').includes('evk_backup_upload_chunk') && ++kawalkow === 2) {
        zgubiona = true;
        await route.fetch();
        await route.abort('connectionreset');
        return;
      }
      await route.continue();
    });
    /* Wznowienie: przerwane przeładowaniem strony w połowie, ten sam plik
       wybrany znowu — start od miejsca przerwania, nie od zera. */
    await wgraj();
    await p.waitForFunction(() => +document.querySelector('[data-evk-upload-bar]').getAttribute('aria-valuenow') >= 20, null, { timeout: 60000 });
    await p.unroute('**/admin-ajax.php');
    t.check('odpowiedź na kawałek zgubiona po drodze: wgrywanie poszło dalej (ponowienie)', zgubiona === true);
    await p.reload();
    /* Po wznowieniu klient zaczyna od offsetu ze startu — kawałek wysłany od
       złego miejsca serwer odrzuca („resync"), ale to już megabajt na marne.
       Mutacja „wznawiaj od zera" przechodziła, bo serwer i tak ją poprawiał. */
    const resynce = [];
    const naOdp = async (odp) => {
      // Po treści odpowiedzi, nie żądania: przy blobie w FormData treść żądania bywa tu pusta.
      if (!/admin-ajax\.php/.test(odp.url())) return;
      const j = await odp.json().catch(() => null);
      if (j && j.data && j.data.resync) resynce.push(j.data.offset);
    };
    p.on('response', naOdp);
    await wgraj();
    await p.locator('[data-evk-upload-msg]').filter({ hasText: /Wznawiam od/ }).waitFor({ timeout: 15000 });
    const wzn = await p.locator('[data-evk-upload-msg]').innerText();
    const wznMb = parseFloat(wzn.replace(/.*od ([\d,]+) MB.*/s, '$1').replace(',', '.'));
    t.check('przerwane w połowie i wybrane znowu: wznawia od miejsca przerwania', wznMb >= 8, wzn.trim());
    const procWg = new Set();
    while (true) {
      const v = await p.locator('[data-evk-upload-bar]').getAttribute('aria-valuenow').catch(() => null);
      if (v !== null) procWg.add(+v);
      if (await widac(p, '[data-evk-upload-restore]')) break;
      if (/przerwane/.test(await p.locator('[data-evk-upload-msg]').innerText().catch(() => ''))) break;
      await p.waitForTimeout(150);
    }
    p.off('response', naOdp);
    t.check('wznowienie bez zbędnych kawałków (ani jednego odrzuconego „resync")', !resynce.length, JSON.stringify(resynce));
    const wgMsg = await p.locator('[data-evk-upload-msg]').innerText();
    t.check('wgrane: komunikat z nazwą i „Przywróć teraz"', /Kopia wgrana: kopia-z-komputera\.zip/.test(wgMsg)
      && (await widac(p, '[data-evk-upload-restore]')), wgMsg.trim());
    t.check('pasek wgrywania szedł na bieżąco (kawałki po 1 MB)', procWg.size >= 5, [...procWg].sort((a, b) => a - b).join(' → '));
    const poWg = sonda('fakty-przywracania');
    t.check('wgrana kopia co do bajtu mimo zgubionej odpowiedzi i przerwy', poWg.wgrana_zgodna === true);
    t.check('kopia na liście jako „wgrana", bez resztek części',
      poWg.kopie.some(([n, zr]) => n === 'kopia-z-komputera.zip' && zr === 'upload') && !poWg.czesci.length
        && (await p.locator('tr[data-archive="kopia-z-komputera.zip"]').count()) === 1, JSON.stringify(poWg.czesci));
    await p.click('[data-evk-upload-restore]');
    const oknoWg = p.locator('[data-evk-restore-dialog]');
    await oknoWg.locator('dl').waitFor({ timeout: 20000 });
    t.check('„Przywróć teraz" otwiera okno przywracania tej kopii',
      /stara\.test/.test(await oknoWg.locator('[data-evk-restore-info]').innerText()));
    await oknoWg.locator('[data-evk-restore-close]').click();

    await wgraj();   // drugi raz — nowa część od zera
    await p.waitForFunction(() => +document.querySelector('[data-evk-upload-bar]').getAttribute('aria-valuenow') >= 10, null, { timeout: 60000 });
    await p.click('[data-evk-upload-cancel]');
    await p.locator('[data-evk-upload-msg]').filter({ hasText: /anulowane/ }).waitFor({ timeout: 15000 });
    let czesci = [];
    for (let i = 0; i < 20; i++) { czesci = sonda('fakty-przywracania').czesci; if (!czesci.length) break; await p.waitForTimeout(250); }
    t.check('anulowane w połowie: wgrana część usunięta z serwera', !czesci.length, JSON.stringify(czesci));
    const nonceWg = await p.evaluate(() => window.evkBackup.nonce);
    const zlyNonceWg = await p.request.post(baza + '/wp-admin/admin-ajax.php',
      { form: { action: 'evk_backup_upload_start', nonce: 'zly', name: 'x.zip', size: '1000', mtime: '1' } });
    t.check('wgrywanie bez ważnego nonce: 403', zlyNonceWg.status() === 403 && nonceWg.length > 0, String(zlyNonceWg.status()));
    await p.goto(zakladka);

    t.section('przypomnienie o adresie i domyślne wykluczenia');
    t.check('bez adresu do powiadomień: ramka z odnośnikiem do pola', await widac(p, '[data-evk-backup-bez-maila]'));
    await p.fill('#evk-backup-wykluczenia', '');
    await p.click('[data-evk-backup-domyslne]');
    const wykl = await p.inputValue('#evk-backup-wykluczenia');
    t.check('„Przywróć domyślne wykluczenia" wpisuje domyślną listę', /^cache\/\n/.test(wykl) && /litespeed\//.test(wykl), JSON.stringify(wykl));

    // ── Kopia nocna i powiadomienia: zapis ustawień ────────────────────────
    t.section('ustawienia kopii nocnej i powiadomień');
    await p.check('input[name="evk_backup[schedule_enabled]"]');
    await p.fill('input[name="evk_backup[schedule_time]"]', '02:30');
    await p.fill('input[name="evk_backup[notify_address]"]', 'kopie@example.com, zly');
    // Panel zapisuje formularz AJAX-em (admin.js) — bez przeładowania; czekamy na zapis w bazie.
    await p.click('form[action="options.php"] [type="submit"]');
    let ust;
    for (let i = 0; i < 40; i++) { ust = sonda('fakty-przywracania'); if (ust.ustawienia.schedule_time === '02:30') break; await p.waitForTimeout(250); }
    await p.reload();
    t.check('po wpisaniu adresu ramka przypomnienia znika', !(await widac(p, '[data-evk-backup-bez-maila]')));
    t.check('zapisane: harmonogram 02:30, złe adresy odrzucone',
      ust.ustawienia.schedule_enabled === 1 && ust.ustawienia.schedule_time === '02:30' && ust.ustawienia.notify_address === 'kopie@example.com',
      JSON.stringify(ust.ustawienia));
    const nast = await p.locator('[data-evk-backup-next]').innerText().catch(() => '');
    t.check('zakładka pokazuje następny termin, zdarzenie w WP-Cron zaplanowane',
      / 02:30$/.test(nast) && ust.plan && ust.plan.time === '02:30', nast + ' / ' + JSON.stringify(ust.plan));
    const cron = await p.locator('[data-evk-backup-cron-cmd]').evaluate((e) => e.textContent);
    t.check('instrukcja crona systemowego z adresem wp-cron.php tej strony',
      cron.includes(baza + '/wp-cron.php?doing_wp_cron') && /^\*\/15 \* \* \* \*/.test(cron), cron);

    // ── Okno przywracania ──────────────────────────────────────────────────
    t.section('okno przywracania: podsumowanie i potwierdzenie');
    const wiersz = p.locator('tr[data-archive]', { hasText: 'ręczna' });
    await wiersz.locator('[data-evk-backup-restore]').click();
    const okno = p.locator('[data-evk-restore-dialog]');
    await okno.locator('dl').waitFor({ timeout: 20000 });
    const info = await okno.locator('[data-evk-restore-info]').innerText();
    t.check('podsumowanie z manifestu: skąd, dokąd, podmiana adresów, zawartość',
      /stara\.test/.test(info) && info.includes(baza) && /podmienione/.test(info) && /wierszy bazy/.test(info), info);
    const go = okno.locator('[data-evk-restore-go]');
    t.check('„Przywróć" zablokowane bez wpisanego słowa', await go.isDisabled());
    await okno.locator('[data-evk-restore-confirm]').fill('przywróć');
    t.check('…i przy złej wielkości liter', await go.isDisabled());
    await okno.locator('input[value="db"]').check();
    t.check('„tylko baza": opcja usuwania plików spoza kopii niedostępna',
      await okno.locator('[data-evk-restore-mirror]').isDisabled());
    await okno.locator('input[value="all"]').check();
    await okno.locator('[data-evk-restore-confirm]').fill('PRZYWRÓĆ');
    t.check('po wpisaniu PRZYWRÓĆ — aktywne', await go.isEnabled());

    /* Słowo sprawdza też serwer — nie tylko przycisk w przeglądarce. */
    const ajax = baza + '/wp-admin/admin-ajax.php';
    const nonce = await p.evaluate(() => window.evkBackup.nonce);
    const bezSlowa = await p.request.post(ajax, { form: { action: 'evk_backup_restore_start', nonce, archive: prep.archive, scope: 'all' } });
    const bezSlowaJ = await bezSlowa.json();
    t.check('serwer bez słowa PRZYWRÓĆ odmawia i nie zakłada zadania',
      bezSlowaJ.success === false && /PRZYWRÓĆ/.test(bezSlowaJ.data.msg)
        && !sonda('fakty-przywracania').zadania.some((z) => z.type === 'restore'), JSON.stringify(bezSlowaJ));
    const zlyToken = await p.request.post(ajax, { form: { action: 'evk_backup_restore_status', id: '1', token: 'zly' } });
    t.check('stan przywracania ze złym tokenem: 403', zlyToken.status() === 403, String(zlyToken.status()));

    await p.setViewportSize({ width: 390, height: 844 });
    const oknoTel = await okno.evaluate((e) => { const r = e.getBoundingClientRect(); return { l: r.left, p: r.right, sw: document.documentElement.scrollWidth }; });
    t.check('okno na telefonie (390 px) mieści się w ekranie', oknoTel.l >= 0 && oknoTel.p <= 390, JSON.stringify(oknoTel));
    await p.setViewportSize({ width: 1280, height: 1000 });

    // ── Przywracanie ───────────────────────────────────────────────────────
    t.section('przywracanie z panelu: postęp, wylogowanie, cofnięte zmiany');
    await go.click();
    // Okno zamyka się po odpowiedzi serwera, nie w chwili kliknięcia.
    await okno.waitFor({ state: 'hidden', timeout: 20000 }).catch(() => {});
    t.check('okno zamknięte, pasek postępu widoczny', !(await okno.isVisible()) && (await widac(p, '[data-evk-backup-progress]')));
    const etapy = new Set();
    const t0 = Date.now();
    while (Date.now() - t0 < 240000) {
      const lab = await p.locator('[data-evk-backup-label]').innerText().catch(() => '');
      if (lab) etapy.add(lab.toLowerCase());
      if (await widac(p, '[data-evk-backup-msg]')) break;
      await p.waitForTimeout(300);
    }
    const koniec = await p.locator('[data-evk-backup-msg]').innerText();
    t.check('koniec: „Kopia przywrócona" z odnośnikiem do logowania',
      /Kopia przywrócona/.test(koniec) && (await widac(p, '[data-evk-backup-login]')), koniec.trim());
    /* Zmierzone (próbki co 300 ms): przywracanie testowej strony trwa kilka
       sekund, więc łapie się jeden–dwa etapy — raz „rozpakowywanie", raz
       „wczytywanie bazy". Pewne jest to: jakiś etap przywracania, a na końcu
       „gotowe", które przychodzi już PO podmianie bazy, więc tokenem. */
    t.check('pasek pokazał etap przywracania i doszedł do „gotowe" (po podmianie bazy — bez sesji)',
      [...etapy].some((e) => /sprawdzanie|rozpakow|wczytywanie|podmiana/.test(e)) && [...etapy].some((e) => /gotowe/.test(e)),
      [...etapy].join(' → '));
    const po = sonda('fakty-przywracania');
    t.check('zmiany po kopii cofnięte: znacznik w bazie i plik — ale plik spoza kopii zostaje (bez lustra)',
      po.znacznik === false && po.plik === true, JSON.stringify({ znacznik: po.znacznik, plik: po.plik }));
    t.check('zadanie przywracania skończone, bez tabel roboczych',
      po.zadania.some((z) => z.type === 'restore' && z.status === 'done') && !po.tabele.some((n) => /^evk[ro]\d/.test(n)),
      JSON.stringify(po.zadania.slice(-1)) + ' ' + po.tabele.join(','));

    await p.goto(zakladka);
    t.check('sesja wygasła razem ze starą bazą — zakładka odsyła do logowania', /wp-login\.php/.test(p.url()), p.url());
    await serwerWp.zaloguj(p, baza);
    await p.goto(zakladka);
    t.check('po zalogowaniu kontem z kopii zakładka działa, kopie na liście',
      (await p.locator('tr[data-archive]').count()) >= 2 && (await widac(p, '[data-evk-backup-start]')));

    /* Zgłoszone z użycia (1.227.1): przełączenie włącznika „nic nie robiło",
       treść pojawiała się dopiero po odświeżeniu. */
    t.section('włącznik modułu przeładowuje zakładkę');
    await Promise.all([p.waitForNavigation(), p.locator('.evo-status-card .evo-toggle').click()]);
    t.check('wyłączony: przycisk kopii znika bez ręcznego odświeżania', !(await widac(p, '[data-evk-backup-start]')));
    await Promise.all([p.waitForNavigation(), p.locator('.evo-status-card .evo-toggle').click()]);
    t.check('włączony: przycisk kopii i lista są od razu',
      (await widac(p, '[data-evk-backup-start]')) && (await p.locator('tr[data-archive]').count()) >= 2);

    t.check('bez błędów JS przez cały przebieg', !bledy.length, bledy.join(' | ') || 'czysto');
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
