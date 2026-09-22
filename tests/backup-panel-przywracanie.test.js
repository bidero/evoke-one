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

    t.check('bez błędów JS przez cały przebieg', !bledy.length, bledy.join(' | ') || 'czysto');
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
