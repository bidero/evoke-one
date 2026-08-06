/**
 * Biblioteka presetów Animatora.
 *
 * Tanie zabezpieczenie przed literówką w tablicy: preset z błędną nazwą
 * właściwości albo z krzywą easingu spoza listy nie wywala niczego głośno —
 * po prostu cicho nie działa. Dlatego sprawdzamy i kształt danych, i to,
 * że silnik faktycznie buduje z każdego presetu oś czasu bez wyjątku,
 * a element po jej zakończeniu jest WIDOCZNY.
 *
 * Lista presetów idzie z PHP (tests/php/presets.php), nie z kopii w teście —
 * nowy preset jest sprawdzany od razu, bez dopisywania czegokolwiek tutaj.
 */

const { phpOutput } = require('./lib/harness');

/** clip-path, który cokolwiek obcina — 'inset(0%)' i 'none' są w porządku. */
const clips = (v) => v && v !== 'none' && /[1-9]/.test(v);

module.exports = async function (t) {
  const data    = JSON.parse(phpOutput('presets.php'));
  const presets = data.presets;
  const keys    = Object.keys(presets);
  const head    = 'window.__presets = ' + JSON.stringify(data) + ';';

  // ── Kształt tablicy ────────────────────────────────────────────────────
  t.section('kształt tablicy presetów');

  t.check('lista niepusta', keys.length >= 20, keys.length + ' presetów');

  const noLabel = keys.filter((k) => !presets[k].label);
  t.check('każdy ma etykietę', !noLabel.length, noLabel.join(', ') || 'brak braków');

  // 'custom' jest jedynym wyjątkiem z definicji — bierze from/to z pól w panelu.
  const incomplete = keys.filter((k) => k !== 'custom' && (!presets[k].from || !presets[k].to));
  t.check('każdy ma from i to', !incomplete.length, incomplete.join(', ') || 'brak braków');

  // Krzywa spoza listy dopuszczalnych wartości to najczęściej literówka
  // w nawiasach — GSAP przyjmuje ją, ale traktuje jak brak easingu.
  const badEase = keys.filter((k) => presets[k].easing && !data.easings.includes(presets[k].easing));
  t.check('easingi presetów z listy', !badEase.length, badEase.join(', ') || 'brak odstępstw');

  // ── Silnik buduje każdy preset ─────────────────────────────────────────
  t.section('budowa osi czasu — wyzwalacz wejścia w kadr');

  const page = await t.open('presets.html', {
    viewport: { width: 1200, height: 1000 }, head, query: 'mode=viewport', settle: 1800,
  });
  t.check('bez błędów JS', !page.errors.length, page.errors.join(' | ') || 'brak');

  const st = await page.evaluate(() => window.__state());
  const built = Object.keys(st);
  t.check('zbudowane wszystkie presety', built.length === keys.length - 1,
    built.length + ' z ' + (keys.length - 1));

  const invisible = built.filter((k) => st[k].opacity < 0.99);
  t.check('po animacji pełna widoczność', !invisible.length,
    invisible.map((k) => k + '=' + st[k].opacity).join(', ') || 'brak przezroczystych');

  const clipped = built.filter((k) => clips(st[k].clipPath));
  t.check('po animacji nic nie obcina maską', !clipped.length,
    clipped.map((k) => k + '=' + st[k].clipPath).join(', ') || 'brak obciętych');

  const hidden = built.filter((k) => st[k].visibility === 'hidden');
  t.check('zasłona zdjęta', !hidden.length, hidden.join(', ') || 'brak ukrytych');

  // Podkład podany TYLKO we 'from' ma zostać nałożony — na tym stoi
  // underline-sweep (gradient pod animowanym background-size).
  t.check('podkład z from nałożony (underline-sweep)',
    st['underline-sweep'] && st['underline-sweep'].bgSize === '100% 2px',
    st['underline-sweep'] ? st['underline-sweep'].bgSize : 'brak presetu');

  await page.close();

  // ── Ten sam zestaw na wyzwalaczu najechania ────────────────────────────
  t.section('budowa osi czasu — wyzwalacz najechania');

  const hov = await t.open('presets.html', {
    viewport: { width: 1200, height: 1000 }, head, query: 'mode=hover', settle: 300,
  });
  t.check('bez błędów JS', !hov.errors.length, hov.errors.join(' | ') || 'brak');

  // Stan spoczynku: oś czasu jest wstrzymana, więc widać stan 'from'.
  const rest = await hov.evaluate(() => window.__state());
  t.check('w spoczynku nic nie zostaje niewidoczne',
    !Object.keys(rest).filter((k) => rest[k].visibility === 'hidden').length, 'ok');

  await hov.evaluate(() => window.__hoverAll());
  await hov.waitForTimeout(900);
  const after = await hov.evaluate(() => window.__state());

  t.check('uniesienie zmienia cień (lift)',
    after.lift && after.lift.boxShadow !== rest.lift.boxShadow,
    after.lift ? after.lift.boxShadow : 'brak presetu');
  t.check('podkreślenie dojeżdża do pełnej szerokości',
    after['underline-sweep'] && after['underline-sweep'].bgSize === '100% 2px',
    after['underline-sweep'] ? after['underline-sweep'].bgSize : 'brak presetu');
  t.check('ramka dorysowana (border-draw)',
    after['border-draw'] && /2px/.test(after['border-draw'].boxShadow),
    after['border-draw'] ? after['border-draw'].boxShadow : 'brak presetu');
  t.check('po najechaniu nadal wszystko widoczne',
    !Object.keys(after).filter((k) => after[k].opacity < 0.99).length, 'ok');

  await hov.close();

  // ── Easing dziedziczony z presetu ──────────────────────────────────────
  // Bez tego „odbicie" nie odbija: twarda wartość domyślna w wierszu
  // biblioteki przykrywała krzywą presetu (do 1.30.0 easing nie był dziedziczony).
  t.section('easing z presetu');

  const e1 = await t.open('presets.html', {
    viewport: { width: 900, height: 700 }, head, query: 'mode=ease', settle: 1600,
  });
  const overshoot = await e1.evaluate(() => window.__maxScale);
  t.check('krzywa z presetu przeregulowuje', overshoot > 1.02, 'max skala ' + overshoot.toFixed(3));
  await e1.close();

  // KONTROLA NEGATYWNA: ta sama animacja z krzywą narzuconą w wierszu.
  // Bez niej „skala rośnie do 1" wygląda tak samo przy działającym
  // i przy w ogóle nieuruchomionym silniku.
  const e2 = await t.open('presets.html', {
    viewport: { width: 900, height: 700 }, head, query: 'mode=ease-off', settle: 1600,
  });
  const flat = await e2.evaluate(() => window.__maxScale);
  t.check('krzywa z wiersza wygrywa z presetem', flat > 0.9 && flat <= 1.005,
    'max skala ' + flat.toFixed(3));
  await e2.close();

  // ── Redukcja ruchu na wyzwalaczu najechania ────────────────────────────
  // Stanem spoczynku jest tam 'from', nie 'to'. Nałożenie 'to' zostawiłoby
  // przycisk trwale uniesionym i podświetlonym — dlatego silnik nie rusza
  // wtedy elementu wcale.
  t.section('redukcja ruchu — stany najechania');

  const rm = await t.open('presets.html', {
    viewport: { width: 1200, height: 1000 }, head, query: 'mode=hover', reduce: true, settle: 400,
  });
  const rmState = await rm.evaluate(() => { window.__hoverAll(); return window.__state(); });
  await rm.waitForTimeout(600);
  const rmAfter = await rm.evaluate(() => window.__state());

  t.check('brak uniesienia po najechaniu',
    rmAfter.lift && (rmAfter.lift.transform === 'none' || /matrix\(1, 0, 0, 1, 0, 0\)/.test(rmAfter.lift.transform)),
    rmAfter.lift ? rmAfter.lift.transform : 'brak presetu');
  t.check('brak podkreślenia po najechaniu',
    rmAfter['underline-sweep'] && rmAfter['underline-sweep'].bgSize === 'auto',
    rmAfter['underline-sweep'] ? rmAfter['underline-sweep'].bgSize : 'brak presetu');
  t.check('treść mimo to widoczna',
    !Object.keys(rmAfter).filter((k) => rmAfter[k].opacity < 0.99).length,
    Object.keys(rmState).length + ' elementów');
  await rm.close();
};
