/**
 * OpenGraph — warstwy: przeciąganie, dodawanie, regeneracja.
 *
 * Zgłoszone z użycia: „nie działa drag/drop". Przyczyna była ta sama co
 * w 1.37.0, tylko w innym miejscu. Kod inicjujący przeciąganie stał
 * w `<script>` W TREŚCI zakładki, a biblioteka `sortablejs` jedzie ze
 * STOPKI (`wp_enqueue_script(..., $in_footer = true)`). Skrypt zakładki
 * uruchamiał się więc, zanim biblioteka w ogóle trafiła na stronę,
 * a cichy warunek `if (typeof Sortable !== 'undefined')` połykał to bez
 * śladu w konsoli — wyglądało to jak element, który po prostu nie reaguje.
 *
 * Poprawką nie jest odroczenie inicjalizacji do `load` (to leczy objaw
 * i wraca przy każdym kolejnym skrypcie w treści zakładki), tylko
 * przeniesienie kodu do `assets/admin/admin.js`, który MA `sortablejs`
 * zadeklarowane jako zależność. Dlatego jedno ze sprawdzeń pilnuje, żeby
 * zakładka nie niosła własnego skryptu.
 *
 * Czego tu NIE MA: symulacji samego przeciągania. Playwright nie napędza
 * wewnętrznej mechaniki SortableJS (sprawdzone w 1.37.0 — ani natywnie, ani
 * przez forceFallback), a poprawność biblioteki nie jest naszą
 * odpowiedzialnością. Sprawdzamy to, co posiadamy: że biblioteka jest, że
 * jest podpięta do właściwego kontenera i że po upuszczeniu wychodzą
 * właściwe nazwy pól.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const tab = phpOutput('tab.php', 'og');
  // Ładunek dla admin.js bierzemy z PRAWDZIWEGO wp_localize_script, a nie
  // z kopii w fixture: lista typów warstw ma być jedna dla PHP i dla
  // przeglądarki.
  const ogData = JSON.parse(phpOutput('admin-enqueue.php')).ogData;
  const head = 'window.__tab = ' + JSON.stringify(tab) + ';' +
               'window.__ogData = ' + JSON.stringify(ogData) + ';' +
               'window.__layerCount = 3;';
  const V = { width: 1200, height: 900 };

  // ── Podpięcie ──────────────────────────────────────────────────────────
  t.section('warstwy OG — przeciąganie podpięte');

  const page = await t.open('og-tab.html', { viewport: V, head, settle: 300 });

  t.check('bez błędów JS', !page.errors.length, page.errors.join(' | ') || 'brak');

  const calls = await page.evaluate(() => window.__sortableCalls.map((c) => ({
    id: c.id, handle: c.opts.handle,
  })));
  const og = calls.filter((c) => c.id === 'evk-og-layers-container');

  t.check('przeciąganie podpięte do listy warstw', og.length === 1,
    JSON.stringify(calls.map((c) => c.id)));
  t.check('uchwytem jest ikona przeciągania', og[0] && og[0].handle === '.drag-handle',
    og[0] && og[0].handle);

  // Sedno usterki: skrypt w treści zakładki startuje przed stopką, więc nie
  // ma prawa zależeć od niczego, co ze stopki przychodzi.
  t.check('zakładka nie niesie własnego skryptu',
    (await page.evaluate(() => window.__inlineScripts)) === 0,
    (await page.evaluate(() => window.__inlineScripts)) + ' bloków <script>');

  // ── Kolejność po upuszczeniu ───────────────────────────────────────────
  t.section('warstwy OG — kolejność po upuszczeniu');

  t.check('kolejność startowa z PHP',
    (await page.evaluate(() => window.__layerTypes())).join(',') === 'rect,photo,text',
    (await page.evaluate(() => window.__layerTypes())).join(','));

  await page.evaluate(() => window.__moveAndDrop(0, 3));
  await page.waitForTimeout(50);

  t.check('warstwa zmieniła miejsce',
    (await page.evaluate(() => window.__layerTypes())).join(',') === 'photo,text,rect',
    (await page.evaluate(() => window.__layerTypes())).join(','));

  // Nazwy pól to jedyne, co dojeżdża na serwer. Bez przenumerowania warstwa
  // przestawiona na koniec dalej niosłaby indeks 0.
  t.check('indeksy pól przenumerowane po kolei',
    (await page.evaluate(() => window.__layerNames())).join(',') === '0,1,2',
    (await page.evaluate(() => window.__layerNames())).join(','));

  // ── Dodawanie warstwy ──────────────────────────────────────────────────
  t.section('warstwy OG — dodawanie');

  t.check('„Dodaj warstwę" jest dostępne z atrybutu onclick',
    (await page.evaluate(() => typeof window.evkOgAddLayer)) === 'function',
    await page.evaluate(() => typeof window.evkOgAddLayer));

  const added = await page.evaluate(() => {
    document.getElementById('evk-og-new-layer-type').value = 'qr';
    window.evkOgAddLayer();
    const rows = document.querySelectorAll('#evk-og-layers-container > .evo-og-layer');
    const last = rows[rows.length - 1];
    return {
      count: rows.length,
      type:  last.querySelector('input[name*="[type]"]').value,
      title: last.querySelector('.evo-og-layer-title').textContent,
      // Nowa warstwa nie może wejść pod cudzy indeks — nadpisałaby ją przy zapisie.
      index: /\[layers\]\[(\d+)\]/.exec(last.querySelector('input[name*="[layers]["]').name)[1],
    };
  });

  t.check('warstwa dochodzi na koniec listy', added.count === 4, added.count + ' warstw');
  t.check('warstwa ma wybrany typ', added.type === 'qr', added.type);
  t.check('warstwa ma czytelną nazwę typu', added.title === 'Kod QR', added.title);
  t.check('warstwa dostaje wolny indeks', added.index === '3', added.index);

  // ── Regeneracja masowa ─────────────────────────────────────────────────
  t.section('warstwy OG — regeneracja masowa');

  await page.evaluate(() => { window.__posts = []; document.getElementById('evk-og-regen-all').click(); });
  await page.waitForTimeout(80);

  const posts = await page.evaluate(() => window.__posts);
  t.check('klik wysyła dokładnie jedno żądanie', posts.length === 1, posts.length + ' wywołań');
  t.check('żądanie idzie pod właściwą akcję',
    posts[0] && posts[0].data.action === 'evk_og_regenerate_all', posts[0] && posts[0].data.action);
  // Nonce z atrapy w admin-enqueue.php niesie nazwę akcji — dzięki temu
  // widać nie tylko to, ŻE nonce jedzie, ale i pod jakie uprawnienie.
  t.check('żądanie niesie nonce właściwej akcji',
    posts[0] && posts[0].data.nonce === 'nonce-evk_og_regen',
    posts[0] && posts[0].data.nonce);

  // ── Kontrola negatywna ─────────────────────────────────────────────────
  t.section('warstwy OG — bez biblioteki nie podpina się nic');

  const bad = await t.open('og-tab.html', { viewport: V, head, query: 'nosortable=1', settle: 300 });
  t.check('nic się nie podpina', (await bad.evaluate(() => window.__sortableCalls)).length === 0, '0');
  t.check('brak biblioteki nie wysypuje panelu', !bad.errors.length,
    bad.errors.join(' | ') || 'brak');
};
