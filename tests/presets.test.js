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

  // Wyjątki z definicji: 'custom' bierze from/to z pól w panelu, presety
  // tekstowe składają varsy w silniku, a wskaźnikowe śledzą kursor i niosą
  // samo 'to' jako stan spoczynku.
  const marked = (k) => presets[k].textFx || presets[k].pointer;
  const incomplete = keys.filter(
    (k) => k !== 'custom' && !marked(k) && (!presets[k].from || !presets[k].to));
  t.check('każdy ma from i to albo znacznik', !incomplete.length,
    incomplete.join(', ') || 'brak braków');

  const badPtr = keys.filter(
    (k) => presets[k].pointer && !['magnetic', 'tilt'].includes(presets[k].pointer));
  t.check('znaczniki wskaźnika znane silnikowi', !badPtr.length, badPtr.join(', ') || 'brak odstępstw');

  // Preset wskaźnikowy bez stanu spoczynku zostawiłby element przekrzywiony
  // na stałe u kogoś z włączoną redukcją ruchu.
  const noRest = keys.filter((k) => presets[k].pointer && !presets[k].to);
  t.check('preset wskaźnika ma stan spoczynku', !noRest.length, noRest.join(', ') || 'brak braków');

  const badFx = keys.filter(
    (k) => presets[k].textFx && !['type', 'scramble', 'words'].includes(presets[k].textFx));
  t.check('znaczniki tekstowe znane silnikowi', !badFx.length, badFx.join(', ') || 'brak odstępstw');

  // Krzywa spoza listy dopuszczalnych wartości to najczęściej literówka
  // w nawiasach — GSAP przyjmuje ją, ale traktuje jak brak easingu.
  const badEase = keys.filter((k) => presets[k].easing && !data.easings.includes(presets[k].easing));
  t.check('easingi presetów z listy', !badEase.length, badEase.join(', ') || 'brak odstępstw');

  // ── Co PHP wystawia na stronę ──────────────────────────────────────────
  // Literówka w nazwie handle'a niczego nie wywala: skrypt po prostu nie
  // trafia na stronę, a efekt cicho nie działa. Z przeglądarki tego nie widać,
  // bo fixture ładuje wtyczki GSAP sam.
  t.section('wtyczki GSAP i lista słów po stronie PHP');

  const enq = (rows) => JSON.parse(phpOutput('animator-enqueue.php', JSON.stringify(JSON.stringify(rows))));

  const plain = enq([{ slug: 'a', preset: 'fade-up' }]);
  t.check('bez presetu tekstowego — bez wtyczek tekstowych',
    !plain.deps.includes('evk-textplugin') && !plain.deps.includes('evk-scrambletext'),
    plain.deps.join(', '));

  const typed = enq([{ slug: 'a', preset: 'typewriter' }]);
  t.check('maszyna do pisania dociąga TextPlugin',
    typed.deps.includes('evk-textplugin') && !typed.deps.includes('evk-scrambletext'),
    typed.deps.join(', '));

  const scr = enq([{ slug: 'a', preset: 'scramble' }]);
  t.check('losowe znaki dociągają ScrambleTextPlugin',
    scr.deps.includes('evk-scrambletext'), scr.deps.join(', '));

  // Pole w panelu → opcja → payload. Pusta linia ma wypaść, bo pusty krok
  // wyglądałby jak zawieszenie animacji.
  const words = enq([{ slug: 'a', preset: 'rotating-words', words: 'szybciej\n\n  prościej  \ntaniej' }]);
  t.check('lista słów przeżywa zapis w panelu',
    JSON.stringify(words.library.a.words) === JSON.stringify(['szybciej', 'prościej', 'taniej']),
    JSON.stringify(words.library.a.words));

  const many = enq([{ slug: 'a', preset: 'rotating-words',
    words: Array.from({ length: 25 }, (_, i) => 'w' + i).join('\n') }]);
  t.check('lista słów przycięta do 20', many.library.a.words.length === 20,
    many.library.a.words.length + ' pozycji');

  // Wiersz bez listy nie może wystawić pustej tablicy — w JS jest prawdziwa
  // i przesłoniłaby brak konfiguracji.
  t.check('brak listy = brak klucza', !('words' in typed.library.a),
    Object.keys(typed.library.a).join(', '));

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

  // ── Efekty tekstowe ────────────────────────────────────────────────────
  // Maszyna do pisania i losowe znaki nadpisują treść elementu w trakcie
  // animacji, więc jedyne, co naprawdę trzeba udowodnić, to że NA KOŃCU
  // wracają do tekstu wyjściowego. Element, który zostaje z „przepisz" albo
  // z losowym ciągiem, jest gorszy niż brak efektu.
  t.check('maszyna do pisania dopisuje do końca',
    st.typewriter && st.typewriter.text === 'typewriter', st.typewriter && st.typewriter.text);
  t.check('losowe znaki wracają do treści',
    st.scramble && st.scramble.text === 'scramble', st.scramble && st.scramble.text);

  const WORDS = ['szybciej', 'prościej', 'taniej'];
  t.check('zmieniające się słowa pokazują słowo z listy',
    st['rotating-words'] && WORDS.some((w) => w.startsWith(st['rotating-words'].text)),
    st['rotating-words'] && JSON.stringify(st['rotating-words'].text));

  await page.close();

  // KONTROLA NEGATYWNA dla maszyny do pisania: w połowie animacji tekstu ma
  // być MNIEJ niż docelowo. Bez tego „na końcu zgadza się treść" świeci
  // na zielono także wtedy, gdy efekt nie ruszył wcale.
  const mid = await t.open('presets.html', {
    viewport: { width: 1200, height: 1000 }, head, query: 'mode=viewport', settle: 500,
  });
  const half = await mid.evaluate(() => window.__state());
  t.check('w połowie tekst niepełny (kontrola)',
    half.typewriter && half.typewriter.text.length < 'typewriter'.length,
    JSON.stringify(half.typewriter && half.typewriter.text));
  await mid.close();

  // ── Efekty wskaźnika ───────────────────────────────────────────────────
  // Ruch myszy przez page.mouse, nie przez dispatchEvent: silnik słucha
  // pointermove, a syntetyczne zdarzenie nie niesie wiarygodnych współrzędnych.
  t.section('efekty wskaźnika');

  const ptr = await t.open('presets.html', {
    viewport: { width: 1200, height: 1000 }, head, query: 'mode=viewport', settle: 1600,
  });

  // Bez tego cała sekcja byłaby fałszywie zielona: silnik wyłącza śledzenie
  // tam, gdzie przeglądarka raportuje dotyk zamiast wskaźnika.
  t.check('przeglądarka raportuje wskaźnik', await ptr.evaluate(() => window.__hoverCapable), '');

  /** Liczby z WNĘTRZA nawiasu — inaczej „3" z nazwy matrix3d wchodzi do wyniku. */
  const matrix = (s) => {
    const m = /\(([^)]*)\)/.exec(s || '');
    return m ? m[1].split(',').map((v) => Number(v.trim())) : [];
  };
  /** Przesunięcie w poziomie: matrix ma je na 4, matrix3d na 12. */
  const shiftX = (m) => (m.length === 16 ? m[12] : m[4]) || 0;

  async function nudge(id, fx) {
    const b = await ptr.locator('#p-' + id).boundingBox();
    await ptr.mouse.move(b.x + b.width * fx, b.y + b.height / 2);
    await ptr.waitForTimeout(700);
    return matrix((await ptr.evaluate(() => window.__state()))[id].transform);
  }

  // Przyciąganie: kursor przy prawej krawędzi ma przesunąć element w prawo.
  const magRight = await nudge('magnetic', 0.95);
  t.check('przyciąganie idzie za kursorem w prawo', shiftX(magRight) > 2,
    'x = ' + shiftX(magRight).toFixed(1));

  const magLeft = await nudge('magnetic', 0.05);
  t.check('i wraca w lewo za kursorem', shiftX(magLeft) < -2,
    'x = ' + shiftX(magLeft).toFixed(1));

  // Przechył: macierz 3D ma 16 pól, płaska 6. Sam fakt przejścia na 3D dowodzi
  // obrotu — porównywanie kątów wprost byłoby przepisywaniem matematyki GSAP-a.
  const tilt = await nudge('tilt', 0.95);
  t.check('przechył obraca w 3D', tilt.length === 16 && Math.abs(tilt[0] - 1) > 0.001,
    tilt.length + ' pól macierzy');

  // Zjazd z elementu wraca do spoczynku.
  await ptr.mouse.move(5, 5);
  await ptr.waitForTimeout(800);
  const off = matrix((await ptr.evaluate(() => window.__state()))['magnetic'].transform);
  t.check('po zjeździe wraca do spoczynku', Math.abs(shiftX(off)) < 1,
    'x = ' + shiftX(off).toFixed(2));

  t.check('bez błędów JS', !ptr.errors.length, ptr.errors.join(' | ') || 'brak');
  await ptr.close();

  // KONTROLA NEGATYWNA: przy redukcji ruchu ten sam ruch myszy nie ma nic zmienić.
  const ptrRm = await t.open('presets.html', {
    viewport: { width: 1200, height: 1000 }, head, query: 'mode=viewport', reduce: true, settle: 900,
  });
  const rb = await ptrRm.locator('#p-magnetic').boundingBox();
  await ptrRm.mouse.move(rb.x + rb.width * 0.95, rb.y + rb.height / 2);
  await ptrRm.waitForTimeout(700);
  const rmPtr = matrix((await ptrRm.evaluate(() => window.__state()))['magnetic'].transform);
  t.check('redukcja ruchu — kursor nie rusza elementem',
    Math.abs(shiftX(rmPtr)) < 1, 'x = ' + shiftX(rmPtr).toFixed(2));
  await ptrRm.close();

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

  // Czekamy na WARUNEK, nie na zegar. Sztywne 900 ms wystarczało w izolacji,
  // ale przy pełnym zestawie (kilkanaście równoległych przeglądarek) animacja
  // najechania bywała jeszcze w drodze i sprawdzenie „nadal wszystko widoczne"
  // migotało. Usterka, której to sprawdzenie broni — element zostający
  // niewidoczny po najechaniu — nie kończy się NIGDY, więc czekanie na warunek
  // niczego nie osłabia: przy realnym błędzie i tak upłynie termin.
  await hov.waitForFunction(() => {
    var s = window.__state();
    return Object.keys(s).every(function (k) { return s[k].opacity >= 0.99; });
  }, null, { timeout: 5000 }).catch(function () {});

  const after = await hov.evaluate(() => window.__state());

  t.check('uniesienie zmienia cień (lift)',
    after.lift && after.lift.boxShadow !== rest.lift.boxShadow,
    after.lift ? after.lift.boxShadow : 'brak presetu');

  // Stan najechania MUSI zostać, dopóki kursor jest na elemencie. Sprzątanie
  // transformacji po animacji (1.35.0) obowiązuje wyłącznie wejścia — puszczone
  // tutaj zdejmowałoby uniesienie w chwili, gdy dojedzie do końca.
  t.check('uniesienie utrzymuje się po najechaniu',
    after.lift && after.lift.transform !== 'none' && after.lift.transform !== rest.lift.transform,
    after.lift ? after.lift.transform : 'brak presetu');
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

  // ── Redukcja ruchu — efekty tekstowe ───────────────────────────────────
  // Tu nie ma „stanu końcowego" do nałożenia: docelowym tekstem jest treść,
  // którą element już ma. Silnik ma go więc zostawić w spokoju — także
  // pętli po słowach, która inaczej kręciłaby się w nieskończoność.
  t.section('redukcja ruchu — efekty tekstowe');

  const rt = await t.open('presets.html', {
    viewport: { width: 1200, height: 1000 }, head, query: 'mode=viewport', reduce: true, settle: 900,
  });
  const rtA = await rt.evaluate(() => window.__state());
  await rt.waitForTimeout(700);
  const rtB = await rt.evaluate(() => window.__state());

  t.check('maszyna do pisania nie rusza treści',
    rtA.typewriter && rtA.typewriter.text === 'typewriter', rtA.typewriter && rtA.typewriter.text);
  t.check('losowe znaki nie ruszają treści',
    rtA.scramble && rtA.scramble.text === 'scramble', rtA.scramble && rtA.scramble.text);
  t.check('pętla po słowach stoi',
    rtA['rotating-words'] && rtB['rotating-words']
      && rtA['rotating-words'].text === rtB['rotating-words'].text
      && rtB['rotating-words'].text === 'rotating-words',
    rtB['rotating-words'] && rtB['rotating-words'].text);
  await rt.close();
};
