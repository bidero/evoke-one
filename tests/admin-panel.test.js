/**
 * Panel Animatora — przeciąganie wierszy i zapis kolejności.
 *
 * Usterka 1.37.0: `evoke-one-admin` deklarował zależności `['jquery']`,
 * a biblioteka przeciągania szła osobnym enqueue NIŻEJ w tej samej funkcji.
 * WordPress drukuje skrypty w kolejności zgłoszeń, więc admin.js lądował przed
 * biblioteką i w chwili jego uruchomienia jej po prostu nie było. Cichy warunek
 * `if (… && $.fn.sortable)` połykał to bez śladu w konsoli.
 *
 * Wtedy pokryty był WYŁĄCZNIE handler PHP — poprawny — a nie to, co dzieje się
 * w panelu. Stąd ten plik pilnuje obu stron naraz: deklaracji zależności po
 * stronie PHP i realnego podpięcia po stronie przeglądarki.
 *
 * Czego tu NIE MA: symulacji samego przeciągania. Playwright nie napędza
 * wewnętrznej mechaniki SortableJS (sprawdzone — ani natywnie, ani przez
 * forceFallback), a poprawność biblioteki i tak nie jest naszą odpowiedzialnością.
 * Sprawdzamy to, co posiadamy: że biblioteka jest, że jest podpięta do właściwego
 * elementu i że po upuszczeniu wychodzi właściwy zapis.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  // ── Strona PHP: deklaracja zależności ──────────────────────────────────
  t.section('panel — co trafia na stronę');

  const enq = JSON.parse(phpOutput('admin-enqueue.php'));

  t.check('skrypt panelu deklaruje bibliotekę przeciągania',
    (enq.deps || []).includes('sortablejs'), JSON.stringify(enq.deps));

  // Zależność, a nie osobne enqueue — tylko ona wymusza kolejność drukowania.
  t.check('biblioteka nie polega na kolejności enqueue',
    enq.handles.indexOf('sortablejs') < enq.handles.indexOf('evoke-one-admin'),
    enq.handles.join(' → '));

  // Bez adresu i nonce'a zapis nie ruszy, choćby przeciąganie działało.
  ['url', 'nonce'].forEach((k) => {
    t.check('panel dostaje „' + k + '" do zapisu kolejności', (enq.anim || []).includes(k),
      (enq.anim || []).join(', '));
  });

  // ── Przeglądarka: realne podpięcie ─────────────────────────────────────
  t.section('panel — podpięcie i zapis kolejności');

  const tab  = phpOutput('anim-tab.php', JSON.stringify(JSON.stringify(['alfa', 'beta', 'gamma'])));
  const head = 'window.__tab = ' + JSON.stringify(tab) + ';';
  const V    = { width: 1100, height: 900 };

  const page = await t.open('anim-tab.html', { viewport: V, head, settle: 300 });

  t.check('bez błędów JS', !page.errors.length, page.errors.join(' | ') || 'brak');

  const calls = await page.evaluate(() => window.__sortableCalls.map((c) => ({
    id: c.id, handle: c.opts.handle, draggable: c.opts.draggable, filter: c.opts.filter,
  })));

  t.check('przeciąganie podpięte do listy wierszy',
    calls.length === 1 && calls[0].id === 'evo-anim-repeater-container',
    JSON.stringify(calls.map((c) => c.id)));
  t.check('uchwytem jest nagłówek wiersza',
    calls[0] && calls[0].handle === '.evo-anim-row-header', calls[0] && calls[0].handle);
  t.check('wiersz jest jednostką przeciągania',
    calls[0] && calls[0].draggable === '.evo-anim-row', calls[0] && calls[0].draggable);
  // Bez tego złapanie za „Usuń" w nagłówku zaczynałoby przeciąganie.
  t.check('przyciski i pola wyłączone z przeciągania',
    calls[0] && /button/.test(calls[0].filter || ''), calls[0] && calls[0].filter);

  t.check('kolejność startowa z PHP',
    (await page.evaluate(() => window.__order())).join(',') === 'alfa,beta,gamma',
    (await page.evaluate(() => window.__order())).join(','));

  // Przestawiamy wiersz i uruchamiamy ścieżkę, którą biblioteka woła po upuszczeniu.
  await page.evaluate(() => window.__moveAndDrop(0, 3));
  await page.waitForTimeout(100);

  const posts = await page.evaluate(() => window.__posts);
  t.check('po upuszczeniu wychodzi dokładnie jeden zapis', posts.length === 1,
    posts.length + ' wywołań');
  t.check('zapis idzie pod właściwą akcję',
    posts[0] && posts[0].data.action === 'evk_anim_reorder', posts[0] && posts[0].data.action);
  t.check('zapis niesie nonce', posts[0] && posts[0].data.nonce === 'testnonce',
    posts[0] && posts[0].data.nonce);
  t.check('zapis niesie NOWĄ kolejność slugów',
    posts[0] && posts[0].data.order.join(',') === 'beta,gamma,alfa',
    posts[0] && posts[0].data.order.join(','));

  t.check('panel potwierdza zapis',
    /zapisana/i.test(await page.evaluate(() => document.getElementById('evo-anim-order-note').textContent)),
    await page.evaluate(() => document.getElementById('evo-anim-order-note').textContent));

  await page.close();

  // ── Kontrola negatywna: brak biblioteki ────────────────────────────────
  // Odtworzenie usterki 1.37.0. Ma być GŁOŚNA — cisza sprawiła, że pojechała
  // na produkcję. Bez tego bloku sekcja wyżej świeciłaby na zielono także wtedy,
  // gdyby nic realnie nie sprawdzała.
  t.section('brak biblioteki przeciągania');

  const bad = await t.open('anim-tab.html', {
    viewport: V, head, query: 'nosortable=1', settle: 300,
  });

  t.check('nic się nie podpina', (await bad.evaluate(() => window.__sortableCalls)).length === 0, '0');
  t.check('błąd trafia do konsoli',
    bad.errors.some((e) => /Sortable/i.test(e) && /Evoke/i.test(e)),
    bad.errors.join(' | ') || 'CISZA — usterka 1.37.0');
  // Reszta panelu ma działać dalej: brak przeciągania nie może wywalić skryptu.
  t.check('dodawanie wierszy nadal działa',
    await bad.evaluate(() => typeof window.evkAddAnimRow === 'function'), '');

  await bad.close();
};
