/**
 * Backup — zakładka „Kopie zapasowe" w PRAWDZIWYM panelu, w przeglądarce.
 *
 * Testowy WordPress (tools/testowy-wp.sh) podany przez wbudowany serwer PHP
 * z kilkoma procesami (PHP_CLI_SERVER_WORKERS) — bez nich żądanie serwera do
 * samego siebie czekałoby na samo siebie. Router (tests/php/_router-wp.php)
 * ustawia adres strony z portu, bez zmian w bazie.
 *
 * Przechodzimy ścieżkę osoby przy panelu: kopia → postęp → lista → pobranie →
 * przypięcie → usunięcie; anulowanie w trakcie; kopia napędzana WYŁĄCZNIE
 * odpytywaniem z otwartej karty (żądania zwrotne i WP-Cron wyłączone — tak
 * jest na serwerze, który ubija żądania w tle); test napędu w tle.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => JSON.parse(phpOutput('backup-panel.php', a));

/** Czy element naprawdę widać — nie „czy ma atrybut hidden". */
const widac = (page, sel) => page.locator(sel).isVisible();

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');

  const prep = sonda('przygotuj');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak, prep.brak || prep.wp);
  if (prep.brak) return;

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
    await p.goto(zakladka);

    // ── Stan wyjściowy ─────────────────────────────────────────────────────
    t.section('zakładka przed pierwszą kopią');
    t.check('przycisk „Utwórz kopię teraz" widoczny', await widac(p, '[data-evk-backup-start]'));
    /* Zmierzone przy pierwszym podejściu: `hidden` przegrywał z CSS
       WordPressa i „Anuluj" oraz pusta ramka wisiały na ekranie. */
    t.check('„Anuluj", pasek i ramka komunikatu NIEWIDOCZNE',
      !(await widac(p, '[data-evk-backup-cancel]')) && !(await widac(p, '[data-evk-backup-progress]'))
      && !(await widac(p, '[data-evk-backup-msg]')));
    t.check('lista pusta z komunikatem', await widac(p, '[data-evk-backup-empty]'));

    // ── Kopia ──────────────────────────────────────────────────────────────
    t.section('kopia z panelu: postęp, lista, pobranie');
    await p.click('[data-evk-backup-start]');
    t.check('w trakcie: „Anuluj" widoczny, start zablokowany',
      (await widac(p, '[data-evk-backup-cancel]')) && (await p.locator('[data-evk-backup-start]').isDisabled()));
    await p.locator('[data-evk-backup-msg]').filter({ hasText: /Kopia gotowa|nie powiodła/ }).waitFor({ timeout: 180000 });
    const msg1 = await p.locator('[data-evk-backup-msg]').innerText();
    t.check('komunikat „Kopia gotowa"', /Kopia gotowa: .+\.zip/.test(msg1), msg1.trim());
    t.check('pasek na 100%, „Anuluj" znów niewidoczny',
      /^100%/.test(await p.locator('[data-evk-backup-percent]').innerText()) && !(await widac(p, '[data-evk-backup-cancel]')));
    t.check('kopia na liście bez przeładowania strony', (await p.locator('tr[data-archive]').count()) === 1);

    const fakty1 = sonda('fakty');
    const href = await p.locator('tr[data-archive] [data-evk-backup-download]').getAttribute('href');
    const odp = await p.request.get(href);
    const cialo = await odp.body();
    t.check('pobranie: ZIP o rozmiarze kopii z dysku',
      odp.status() === 200 && cialo.slice(0, 2).toString() === 'PK' && cialo.length === fakty1.kopie[0].size,
      odp.status() + ', ' + cialo.length + ' B vs ' + fakty1.kopie[0].size + ' B');
    const zlyNonce = await p.request.get(href.replace(/_wpnonce=[^&]+/, '_wpnonce=zly'));
    t.check('pobranie ze złym nonce: odmowa', zlyNonce.status() === 403, String(zlyNonce.status()));

    /* Żądanie zwrotne kroku przychodzi BEZ zalogowania — jedynym
       poświadczeniem jest podpis HMAC. Zły podpis: odmowa, zanim cokolwiek
       ruszy zadanie. */
    const ajax = baza + '/wp-admin/admin-ajax.php';
    const zlyPodpis = await p.request.post(ajax, { form: { action: 'evk_backup_loopback', id: '1', sig: 'zly' } });
    t.check('żądanie zwrotne kroku ze złym podpisem: 403', zlyPodpis.status() === 403, String(zlyPodpis.status()));
    const bezNonce = await p.request.post(ajax, { form: { action: 'evk_backup_delete', nonce: 'zly', archive: fakty1.kopie[0].archive } });
    t.check('usuwanie kopii bez ważnego nonce: 403, kopia zostaje',
      bezNonce.status() === 403 && sonda('fakty').kopie.length === 1, String(bezNonce.status()));

    // ── Telefon ────────────────────────────────────────────────────────────
    t.section('lista na telefonie (390 px): karta, akcje rzędem pod opisem');
    /* ZGŁOSZONE Z UŻYCIA: „przyciski usuń, przypnij, pobierz w wersji mobilnej
       mają różne wysokości, chyba powinny się zwijać pod opis". Zmierzone
       przed poprawką: 40 px wobec 44 px, wiersz 536 px przy ekranie 390 px. */
    await p.setViewportSize({ width: 390, height: 844 });
    const tel = await p.evaluate(() => {
      const w = document.querySelector('tr[data-archive]');
      const opis = w.querySelector('td[data-label="Zawartość"]').getBoundingClientRect();
      const btn = [...w.querySelectorAll('.evk-backup-akcje > *')].map((e) => e.getBoundingClientRect());
      return {
        wysokosci: btn.map((r) => Math.round(r.height)),
        gory: btn.map((r) => Math.round(r.top)),
        prawa: Math.max(...btn.map((r) => r.right)),
        podOpisem: Math.min(...btn.map((r) => r.top)) >= opis.bottom - 1,
        dokument: document.documentElement.scrollWidth,
      };
    });
    t.check('cztery przyciski (pobierz, przypnij, przywróć, usuń) tej samej wysokości, w jednym rzędzie',
      new Set(tel.wysokosci).size === 1 && new Set(tel.gory).size === 1, JSON.stringify(tel));
    t.check('akcje pod opisem, w granicach ekranu, bez przewijania w bok',
      tel.podOpisem && tel.prawa <= 390 && tel.dokument <= 390, JSON.stringify(tel));
    await p.setViewportSize({ width: 1280, height: 1000 });
    const desk = await p.$$eval('tr[data-archive] .evk-backup-akcje > *', (els) => els.map((e) => Math.round(e.getBoundingClientRect().height)));
    t.check('na szerokim ekranie też równa wysokość', new Set(desk).size === 1, JSON.stringify(desk));

    // ── Przypinanie i usuwanie ─────────────────────────────────────────────
    t.section('przypinanie i usuwanie');
    await p.click('tr[data-archive] [data-evk-backup-pin]');
    await p.locator('tr[data-archive] .evo-badge').waitFor();
    t.check('przypięta: plakietka w wierszu i zapis na dysku', sonda('fakty').kopie[0].pinned === true);
    await p.click('tr[data-archive] [data-evk-backup-delete]');
    await p.locator('[data-evk-backup-empty]').waitFor();
    const fakty2 = sonda('fakty');
    t.check('usunięta: wiersz znika, plik z dysku też', fakty2.kopie.length === 0, JSON.stringify(fakty2.kopie));

    // ── Pasek postępu żyje ─────────────────────────────────────────────────
    t.section('pasek postępu odświeża się co sekundę');
    sonda('duzy');   // 150 MB nieściśliwych danych — kopia trwa kilka sekund
    await p.goto(zakladka);
    /* ZGŁOSZONE Z UŻYCIA: „pasek postępu musi się odświeżać znacznie
       częściej". Zmierzone na tej samej kopii (próbki co 250 ms): przed
       poprawką 3 zmiany w 10,7 s — pakowanie stało na 0% do końca; po niej
       8 zmian w 7 s (0 → 16 → 32 → 48 → 63 → 80 → 100%). Próg: co najmniej
       5 różnych wartości procentu. */
    await p.click('[data-evk-backup-start]');
    const procenty = new Set();
    const szczegoly = [];
    const t0 = Date.now();
    while (Date.now() - t0 < 180000) {
      const txt = await p.locator('[data-evk-backup-percent]').innerText().catch(() => '');
      const m = txt.match(/^(\d+)%/);
      if (m) procenty.add(+m[1]);
      if (/MB z .*MB/.test(txt)) szczegoly.push(txt.split(' · ')[1]);
      if (await widac(p, '[data-evk-backup-msg]')) break;
      await p.waitForTimeout(250);
    }
    t.check('co najmniej 5 różnych wartości paska w trakcie kopii', procenty.size >= 5,
      [...procenty].join(' → ') + ' (' + ((Date.now() - t0) / 1000).toFixed(1) + ' s)');
    t.check('w trakcie pakowania szczegół w liczbach („MB z MB")', szczegoly.length > 0,
      szczegoly.slice(0, 3).join(' | ') || 'ani razu');

    // ── Anulowanie ─────────────────────────────────────────────────────────
    t.section('anulowanie w trakcie');
    await p.goto(zakladka);
    await p.click('[data-evk-backup-start]');
    await p.locator('[data-evk-backup-step]').filter({ hasText: /etap 3/ }).waitFor({ timeout: 60000 });
    await p.click('[data-evk-backup-cancel]');
    await p.locator('[data-evk-backup-msg]').filter({ hasText: /anulowana/ }).waitFor({ timeout: 60000 });
    /* Krok, który akurat pakował, sprząta po sobie między porcjami — chwila. */
    let fakty3;
    for (let i = 0; i < 30; i++) { fakty3 = sonda('fakty'); if (!fakty3.part.length && !fakty3.praca.length) break; await p.waitForTimeout(500); }
    t.check('po anulowaniu: bez nowej kopii, bez .part, bez katalogu roboczego',
      fakty3.kopie.length === 1 && !fakty3.part.length && !fakty3.praca.length, JSON.stringify(fakty3));

    // ── Napęd wyłącznie z panelu ───────────────────────────────────────────
    t.section('kopia napędzana wyłącznie otwartą kartą');
    sonda('bez-loopbacku 1');
    await p.goto(zakladka);
    await p.click('[data-evk-backup-start]');
    await p.locator('[data-evk-backup-msg]').filter({ hasText: /Kopia gotowa|nie powiodła/ }).waitFor({ timeout: 240000 });
    const msg4 = await p.locator('[data-evk-backup-msg]').innerText();
    const fakty4 = sonda('fakty');
    const ost = fakty4.zadania[fakty4.zadania.length - 1];
    /* Bez żądań zwrotnych i bez WP-Cron jedynym, kto może zrobić krok, jest
       odpytywanie z tej karty (ajax.php, evk_backup_status). Mutacja
       usuwająca popychanie: zadanie stoi w „queued" do końca czasu testu. */
    t.check('bez żądań zwrotnych i WP-Cron kopia i tak się kończy', /Kopia gotowa/.test(msg4) && ost.status === 'done',
      msg4.trim() + ' / kroków: ' + ost.ticks);
    sonda('bez-loopbacku 0');

    // ── Środowisko i praca w tle ───────────────────────────────────────────
    t.section('środowisko w akordeonie, test pracy w tle');
    /* ZGŁOSZONE Z UŻYCIA: środowisko zwinąć w akordeon, „napęd" brzmi
       nienaturalnie, opis przy przycisku dziwnie się układał. */
    await p.goto(zakladka);
    const acc = p.locator('details.evk-backup-srodowisko');
    t.check('środowisko zwinięte na starcie (nic nie blokuje)', !(await acc.evaluate((e) => e.open)));
    t.check('przycisk testu schowany w zwiniętym akordeonie', !(await widac(p, '[data-evk-backup-probe-start]')));
    /* textContent, nie innerText: innerText pomija treść zwiniętego
       akordeonu — mutacja z „napędem" w schowanym przycisku przechodziła. */
    t.check('słowa „napęd" nie ma nigdzie na stronie (także w zwiniętym akordeonie)',
      !/napęd/i.test(await p.locator('#wpbody-content').evaluate((e) => e.textContent)));
    await acc.locator('summary').click();
    /* Opis NAD przyciskiem, wynik POD nim — nic nie opływa przycisku. */
    const opisNad = await p.evaluate(() => {
      const box = document.querySelector('[data-evk-backup-probe]');
      return box.querySelector('p.evo-muted').getBoundingClientRect().bottom
        <= box.querySelector('[data-evk-backup-probe-start]').getBoundingClientRect().top;
    });
    t.check('opis nad przyciskiem, nie obok', opisNad === true);
    await p.click('[data-evk-backup-probe-start]');
    await p.waitForFunction(() => !document.querySelector('[data-evk-backup-probe-start]').disabled, null, { timeout: 60000 });
    const probe = await p.locator('[data-evk-backup-probe-result]').innerText();
    const wynikPod = await p.evaluate(() => {
      const box = document.querySelector('[data-evk-backup-probe]');
      return box.querySelector('[data-evk-backup-probe-result]').getBoundingClientRect().top
        >= box.querySelector('[data-evk-backup-probe-start]').getBoundingClientRect().bottom;
    });
    t.check('praca w tle przetrwała pełne 30 s (serwer testowy nie przerywa)', /przetrwał pełne 30 s/.test(probe), probe);
    t.check('wynik pod przyciskiem', wynikPod === true);

    t.check('bez błędów JS przez cały przebieg', !bledy.length, bledy.join(' | ') || 'czysto');
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
