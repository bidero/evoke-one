/**
 * Meta SEO — stronicowanie i zapis.
 *
 * Zakładka ładowała WSZYSTKIE opublikowane wpisy i strony naraz
 * (`posts_per_page => -1`), rysując na każdy trzy pola i sześć checkboksów.
 * Przy pięciuset wpisach to ~4500 kontrolek w DOM.
 *
 * Dwie rzeczy są tu ważniejsze niż samo „działa":
 *
 * 1. **Wyszukiwanie musi sięgać poza bieżącą stronę.** Dawna wyszukiwarka
 *    filtrowała już załadowane wiersze; przy stronicowaniu przeszukiwałaby
 *    jedną stronę wyników i wyglądałaby na zepsutą. Dlatego test szuka wpisu,
 *    który na pierwszej stronie NIE LEŻY — inaczej przechodziłby także dla
 *    filtra po stronie przeglądarki.
 *
 * 2. **Zapis zbiorczy wysyła tylko zmienione wiersze.** Wcześniej szło stąd
 *    wszystko, co było na stronie. Przy dwóch osobach w panelu jedna
 *    nadpisywała drugiej świeże wartości tymi sprzed swojego załadowania —
 *    to nie jest „odświeżenie danych", tylko cofnięcie cudzej pracy.
 */

const { phpOutput } = require('./lib/harness');

const V = { width: 1280, height: 900 };
/* `phpOutput` dokleja argumenty do polecenia powłoki, więc JSON z parametrami
   zapytania idzie w apostrofach — w środku są cudzysłowy. */
const tabHead = (query) =>
  'window.__tab = ' + JSON.stringify(phpOutput('tab.php', "seo-meta '" + query + "'")) + ';';

module.exports = async function (t) {
  // ── Stronicowanie po stronie serwera ──────────────────────────────────
  t.section('stronicowanie');

  const p1 = await t.open('seo-meta.html', { viewport: V, head: tabHead('{}'), settle: 120 });
  const rows1 = await p1.evaluate(() => window.__rows());
  t.check('pierwsza strona ma pełne 20 wierszy', rows1.length === 20, rows1.length + ' szt.');

  // Blok <script> miał odejść do admin.js — inaczej logika zapisu żyje
  // w dwóch miejscach i rozjedzie się przy pierwszej poprawce.
  t.check('zakładka nie niesie własnego <script>',
    (await p1.evaluate(() => window.__inlineScripts())) === 0,
    (await p1.evaluate(() => window.__inlineScripts())) + ' bloków');
  await p1.close();

  const p2 = await t.open('seo-meta.html', {
    viewport: V, head: tabHead('{"seo_paged":"2"}'), settle: 120,
  });
  const rows2 = await p2.evaluate(() => window.__rows());
  t.check('druga strona ma resztę', rows2.length === 6, rows2.length + ' szt.');
  t.check('druga strona to INNE wpisy niż pierwsza',
    !rows2.some((r) => rows1.find((x) => x.id === r.id)),
    rows1[0].title + '… vs ' + rows2[0].title + '…');
  await p2.close();

  // ── Szukanie sięga poza bieżącą stronę ────────────────────────────────
  t.section('szukanie w zapytaniu, nie w wierszach');

  // Kontrola sensowności: szukany wpis NIE MOŻE być na pierwszej stronie,
  // bo wtedy filtr po stronie przeglądarki też by go znalazł.
  t.check('szukany wpis leży poza pierwszą stroną',
    !rows1.find((r) => /Kontakt/.test(r.title)),
    rows1.map((r) => r.title).slice(-2).join(', ') + ' — ostatnie na stronie 1');

  const ps = await t.open('seo-meta.html', {
    viewport: V, head: tabHead('{"seo_s":"Kontakt"}'), settle: 120,
  });
  const found = await ps.evaluate(() => window.__rows());
  t.check('fraza znajduje wpis z dalszej strony',
    found.length === 1 && /Kontakt/.test(found[0].title),
    found.map((r) => r.title).join(', ') || 'nic');
  await ps.close();

  // ── Zapis tylko zmienionych wierszy ───────────────────────────────────
  t.section('zapis zbiorczy wysyła tylko zmienione');

  const p = await t.open('seo-meta.html', { viewport: V, head: tabHead('{}'), settle: 120 });

  // Nic nie ruszone → żadnego żądania, za to komunikat.
  await p.evaluate(() => window.__saveAll());
  await p.waitForTimeout(60);
  t.check('bez zmian nie leci żadne żądanie',
    (await p.evaluate(() => window.__bulkCount())) === 0,
    (await p.evaluate(() => window.__bulkCount())) + ' żądań');
  t.check('bez zmian jest komunikat, a nie cisza',
    /Nie ma czego zapisać/.test((await p.evaluate(() => window.__status())).text),
    (await p.evaluate(() => window.__status())).text || '(pusto)');

  // Dwa wiersze zmienione z dwudziestu.
  await p.evaluate(() => { window.__type(3, 'Tytuł A'); window.__type(7, 'Tytuł B'); });
  await p.waitForTimeout(30);
  const dirty = (await p.evaluate(() => window.__rows())).filter((r) => r.dirty);
  t.check('zmienione wiersze są oznaczone', dirty.length === 2,
    dirty.map((r) => r.title).join(', '));

  await p.evaluate(() => window.__saveAll());
  await p.waitForTimeout(80);
  const bulk = await p.evaluate(() => window.__lastBulk());
  t.check('poszło jedno żądanie zbiorcze',
    (await p.evaluate(() => window.__bulkCount())) === 1,
    (await p.evaluate(() => window.__bulkCount())) + ' żądań');
  t.check('w żądaniu są DWA wiersze, nie dwadzieścia',
    bulk && bulk.rows.length === 2, bulk ? bulk.rows.length + ' wierszy' : 'brak żądania');
  t.check('to te wiersze, które zmieniono',
    bulk && bulk.rows.map((r) => r.seo_title).sort().join('|') === 'Tytuł A|Tytuł B',
    bulk ? bulk.rows.map((r) => r.seo_title).join(', ') : '—');
  t.check('nonce jedzie razem z nimi', bulk && bulk.nonce === 'testnonce',
    bulk ? String(bulk.nonce) : '—');

  // Po udanym zapisie znacznik znika — inaczej drugie kliknięcie wysłałoby
  // te same wiersze jeszcze raz.
  await p.waitForTimeout(60);
  const afterSave = (await p.evaluate(() => window.__rows())).filter((r) => r.dirty);
  t.check('po zapisie nic nie zostaje oznaczone', afterSave.length === 0,
    afterSave.length + ' szt.');

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();
};
