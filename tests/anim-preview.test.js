/**
 * Podgląd animacji w panelu.
 *
 * Podgląd, który pokazuje co innego niż strona, jest gorszy niż jego brak —
 * ustawia się wtedy wartości pod obraz, którego odwiedzający nigdy nie zobaczy.
 * Dlatego cała logika siedzi w `assets/js/animator.js`, przy silniku, a panel
 * podaje tylko wartości pól. Ten plik pilnuje, że tak zostało.
 *
 * Co jest sprawdzane i przed czym broni:
 *
 * * **Zgodność z presetem.** Varsy, które podgląd wpuszcza do GSAP, muszą się
 *   zgadzać z tablicą presetów z PHP. To jedyne sprawdzenie, które łapie
 *   ROZJAZD, a nie tylko awarię.
 * * **Dwa parsery `opacity: 0` dają to samo.** Zapis obsługuje PHP, podgląd JS;
 *   dwie implementacje jednego formatu to dwa miejsca do rozejścia się.
 * * **Sprzątanie między odegraniami.** SplitText przebudowuje DOM, a wtyczki
 *   tekstowe nadpisują `innerHTML` — bez przywrócenia treści druga próba
 *   startowałaby z resztek pierwszej.
 * * **Redukcja ruchu** — ta sama polityka co w silniku, Z KONTROLĄ NEGATYWNĄ.
 *   Bez niej nieuruchomiony podgląd jest nie do odróżnienia od wyłączonego.
 */

const { phpOutput } = require('./lib/harness');

/** Teksty do porównania obu parserów — brzegi, nie tylko przypadek typowy. */
const PARSE_CASES = [
  'opacity: 0\ny: 40',
  'scale: 0.8\nfilter: blur(12px)',
  '  x : -60  \n\nrotation: 15',
  'opacity: 0\nZŁA LINIA\n: brak nazwy\npusta:',
  'clipPath: inset(0 100% 0 0)',
];

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('anim-preview.php', JSON.stringify(JSON.stringify(PARSE_CASES))));
  const head = 'window.__presets = ' + JSON.stringify(php.presets) + ';';
  const V = { width: 900, height: 600 };

  // ── Punkt wejścia ─────────────────────────────────────────────────────
  t.section('podgląd jest częścią silnika, nie panelu');

  const p = await t.open('anim-preview.html', { viewport: V, head, settle: 150 });

  t.check('silnik wystawia podgląd', await p.evaluate(() => window.__hasPreview()),
    'evkAnimatorPreview + evkAnimatorParseProps');
  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');

  // ── Parsery ───────────────────────────────────────────────────────────
  t.section('parser „opacity: 0" — PHP i JS zgodne');

  const jsParsed = await p.evaluate((cases) => cases.map((c) => window.__parse(c)), PARSE_CASES);
  const mismatch = PARSE_CASES.map((src, i) => ({
    src,
    php: JSON.stringify(php.parsed[i]),
    js:  JSON.stringify(jsParsed[i]),
  })).filter((r) => r.php !== r.js);

  t.check('oba parsery dają ten sam wynik', !mismatch.length,
    mismatch.map((r) => JSON.stringify(r.src) + ': PHP ' + r.php + ' ≠ JS ' + r.js).join(' | ')
    || PARSE_CASES.length + ' przypadków zgodnych');
  t.check('parser w ogóle coś parsuje',
    jsParsed[0] && jsParsed[0].opacity === 0 && jsParsed[0].y === 40,
    JSON.stringify(jsParsed[0]));

  // ── Zgodność z presetem ───────────────────────────────────────────────
  t.section('podgląd gra to, co niesie preset');

  // Slugi wpisane wprost i SPRAWDZANE, że istnieją. Cicha pętla „jeśli nie ma,
  // pomiń" przepuszczała literówkę — `zoom-in` nie istnieje (jest `scale-in`)
  // i cztery sprawdzenia po prostu nie wykonywały się wcale.
  const SLUGS = ['fade', 'fade-up', 'scale-in', 'blur-in'];
  const missing = SLUGS.filter((s) => !php.presets[s]);
  t.check('wszystkie badane presety istnieją', !missing.length,
    missing.join(', ') || SLUGS.join(', '));

  for (const slug of SLUGS) {
    if (!php.presets[slug]) continue;
    const got  = await p.evaluate((s) => window.__engineVars({ preset: s }), slug);
    const want = php.presets[slug];

    t.check('„' + slug + '" ma co odegrać', !!got, got ? '' : 'podgląd nic nie zbudował');
    if (!got) continue;

    const badProps = Object.keys(want.to || {}).filter((k) => String(got.vars[k]) !== String(want.to[k]));
    t.check('„' + slug + '" — wartości docelowe z presetu', !badProps.length,
      badProps.map((k) => k + ': ' + got.vars[k] + ' ≠ ' + want.to[k]).join(', ') || 'zgodne');

    t.check('„' + slug + '" — czas trwania z presetu',
      Math.abs(got.duration - want.duration) < 0.01,
      got.duration + ' vs ' + want.duration);
  }

  // Wartości z pól wiersza mają wygrywać z presetem — inaczej podgląd
  // pokazywałby preset, a nie to, co użytkownik właśnie ustawił.
  const overridden = await p.evaluate(() =>
    window.__engineVars({ preset: 'fade-up', duration: 2.5, easing: 'bounce.out' }));
  t.check('pola wiersza wygrywają z presetem',
    overridden && Math.abs(overridden.duration - 2.5) < 0.01
    && overridden.vars.ease === 'bounce.out',
    overridden ? overridden.duration + ' / ' + overridden.vars.ease : 'brak');

  const custom = await p.evaluate(() =>
    window.__engineVars({ preset: 'fade', to: { opacity: 0.5, x: 20 } }));
  t.check('własne „to" zastępuje presetowe',
    custom && String(custom.vars.opacity) === '0.5' && String(custom.vars.x) === '20',
    custom ? JSON.stringify({ opacity: custom.vars.opacity, x: custom.vars.x }) : 'brak');

  // ── Odegranie i sprzątanie ────────────────────────────────────────────
  t.section('odegranie wraca do punktu wyjścia');

  const before = await p.evaluate(() => { window.__play({ preset: 'fade-up' }); return window.__state(); });
  await p.waitForTimeout(60);
  const during = await p.evaluate(() => window.__state());
  t.check('animacja realnie rusza',
    during.opacity !== '1' || during.transform !== before.transform,
    'opacity ' + before.opacity + ' → ' + during.opacity);

  await p.waitForTimeout(1100);
  const after = await p.evaluate(() => window.__state());
  t.check('kończy w stanie docelowym', after.opacity === '1', after.opacity);

  // Drugi przebieg musi wyglądać jak pierwszy — inaczej podgląd „zużywa się".
  const second = await p.evaluate(() => { window.__play({ preset: 'fade-up' }); return window.__state(); });
  t.check('drugie odegranie startuje tak jak pierwsze',
    second.opacity === before.opacity && second.text === before.text,
    second.opacity + ' / „' + second.text + '"');

  // ── Presety dzielące tekst ────────────────────────────────────────────
  t.section('presety dzielące tekst i piszące');

  const splitSlug = Object.keys(php.presets).find((k) => php.presets[k].split === 'chars');
  if (splitSlug) {
    await p.evaluate((s) => window.__play({ preset: s }), splitSlug);
    await p.waitForTimeout(80);
    const st = await p.evaluate(() => window.__state());
    t.check('„' + splitSlug + '" dzieli tekst na węzły', st.kids > 1, st.kids + ' węzłów');

    // A po ponownym odegraniu innego presetu scena wraca do czystego tekstu —
    // inaczej podział zostawałby na stałe i kolejne presety grałyby na resztkach.
    await p.evaluate(() => window.__play({ preset: 'fade' }));
    const clean = await p.evaluate(() => window.__state());
    t.check('podział jest sprzątany przed kolejnym odegraniem', clean.kids === 0,
      clean.kids + ' węzłów, „' + clean.html + '"');
  }

  const typeSlug = Object.keys(php.presets).find((k) => php.presets[k].textFx === 'type');
  if (typeSlug) {
    await p.evaluate((s) => window.__play({ preset: s }), typeSlug);
    await p.waitForTimeout(60);
    const typing = await p.evaluate(() => window.__state());
    t.check('„' + typeSlug + '" zaczyna od pustego pola',
      typing.text.length < 'Evoke ONE — próbka tekstu'.length,
      '„' + typing.text + '"');

    await p.waitForTimeout(1400);
    const typed = await p.evaluate(() => window.__state());
    t.check('„' + typeSlug + '" dopisuje tekst do końca',
      typed.text.indexOf('Evoke ONE') === 0, '„' + typed.text + '"');
  }

  await p.close();

  // ── Redukcja ruchu ────────────────────────────────────────────────────
  t.section('redukcja ruchu — stan końcowy bez ruchu');

  const r = await t.open('anim-preview.html', { viewport: V, head, reduce: true, settle: 150 });

  const played = await r.evaluate(() => window.__play({ preset: 'fade-up' }));
  t.check('podgląd nie buduje osi czasu', played === false, 'zwrócone: ' + played);

  await r.waitForTimeout(120);
  const rs = await r.evaluate(() => window.__state());
  t.check('stan końcowy jest widoczny', rs.opacity === '1', rs.opacity);
  t.check('nic nie zostaje przesunięte',
    rs.transform === 'none' || /matrix\(1, 0, 0, 1, 0, 0\)/.test(rs.transform), rs.transform);
  await r.close();

  // KONTROLA NEGATYWNA. Bez niej „nic się nie porusza" byłoby nie do odróżnienia
  // od podglądu, który w ogóle się nie uruchomił.
  const n = await t.open('anim-preview.html', { viewport: V, head, settle: 150 });
  const playedNormal = await n.evaluate(() => window.__play({ preset: 'fade-up' }));
  t.check('bez redukcji ruchu oś czasu POWSTAJE', playedNormal === true,
    'zwrócone: ' + playedNormal);
  await n.close();

  // ── Szew panel ↔ silnik ───────────────────────────────────────────────
  // Testy wyżej wołają silnik wprost. Tu chodzi o coś innego: czy interfejs,
  // który admin.js dokłada do wiersza, trafia w markup zakładki i czy kliknięcie
  // realnie uruchamia animację. To ten szew rozjedzie się najszybciej — obie
  // strony żyją w osobnych plikach i nic ich ze sobą nie wiąże poza nazwami klas.
  t.section('panel: kliknięcie ▶ odgrywa animację');

  const tab = phpOutput('anim-tab.php', JSON.stringify(JSON.stringify(['alfa', 'beta'])));
  const panelHead = head + ' window.__tab = ' + JSON.stringify(tab) + ';';

  const pan = await t.open('anim-preview-panel.html', {
    viewport: { width: 1200, height: 900 }, head: panelHead, settle: 300,
  });

  t.check('bez błędów JS w panelu', !pan.errors.length, pan.errors.join(' | ') || 'brak');

  const rows = await pan.evaluate(() => window.__rows());
  const ui   = await pan.evaluate(() => window.__ui());
  t.check('każdy wiersz dostał podgląd',
    rows > 0 && ui.length === rows && ui.every((u) => u.play && u.stage),
    ui.length + ' z ' + rows);
  t.check('scena ma próbkę tekstu', ui[0] && ui[0].text.length > 3, '„' + (ui[0] || {}).text + '"');

  // Przycisk ma SĄSIADOWAĆ z „Usuń". Sprawdzanie „czy jest za połową paska"
  // nie ma zębów: bez auto-marginesu space-between stawia go na 659 przy
  // połowie 574, więc warunek przechodził mimo rozjechanego układu.
  const gap = ui[0] ? ui[0].removeLeft - (ui[0].playLeft + ui[0].playWidth) : 999;
  t.check('▶ sąsiaduje z „Usuń"', gap >= 0 && gap <= 12,
    gap + ' px odstępu (▶ ' + (ui[0] || {}).playLeft + ', Usuń ' + (ui[0] || {}).removeLeft + ')');

  // Nagłówek jest też przełącznikiem zwijania — ▶ nie może przy okazji zwijać.
  const wasCollapsed = await pan.evaluate(() => window.__collapsed(0));
  await pan.locator('.evo-anim-row').first().locator('.evo-anim-play').click();
  await pan.waitForTimeout(60);
  t.check('kliknięcie ▶ nie zwija wiersza',
    (await pan.evaluate(() => window.__collapsed(0))) === wasCollapsed, 'stan bez zmian');

  const mid = await pan.evaluate(() => window.__stageState(0));
  t.check('animacja rusza po kliknięciu', mid.opacity !== '1',
    'opacity ' + mid.opacity);

  await pan.waitForTimeout(1200);
  const end = await pan.evaluate(() => window.__stageState(0));
  t.check('kończy w stanie docelowym', end.opacity === '1', end.opacity);

  // Podgląd ma czytać to, co JEST W POLACH, a nie to, co zapisano w bibliotece.
  await pan.evaluate(() => {
    window.__setField(0, 'preset', 'scale-in');
    window.__setField(0, 'duration', '3');
  });
  await pan.locator('.evo-anim-row').first().locator('.evo-anim-play').click();
  await pan.waitForTimeout(80);
  const dur = await pan.evaluate(() => window.__lastDuration());
  // Czas ODCZYTANY z osi czasu, nie zgadnięty z opacity: preset „scale-in" trwa
  // 0,8 s, więc próbka po 0,7 s wygląda tak samo przy jednym i drugim.
  t.check('podgląd czyta żywe wartości pól, nie zapisane',
    dur !== null && Math.abs(dur - 3) < 0.01,
    'czas trwania osi: ' + dur + ' (preset sam z siebie ma 0,8)');

  // ── Podgląd nie wyjeżdża poza wiersz ──────────────────────────────────
  // Domyślnym celem animacji jest „sam element", więc GSAP przesuwa SCENĘ —
  // a `overflow: hidden` na niej obcina wyłącznie jej dzieci, nie ją samą.
  // Zmierzone przed poprawką: „fade z lewej" wypychał ją 13 px poza wiersz.
  // Dlatego scena siedzi w nieruchomym KADRZE, który ją obcina.
  t.section('podgląd zostaje w swoim kadrze');

  for (const width of [1200, 390]) {
    const o = await t.open('anim-preview-panel.html', {
      viewport: { width, height: 900 }, head: panelHead, settle: 250,
    });

    // Preset przesuwający w bok — statyczne „fade" niczego by nie pokazało.
    await o.evaluate(() => window.__setField(0, 'preset', 'fade-left'));
    await o.locator('.evo-anim-play').first().click();
    await o.waitForTimeout(50);

    const ov = await o.evaluate(() => window.__overflowOut(0));

    // Kontrola sensowności: gdyby scena w ogóle się nie ruszała, „nic nie
    // wystaje" byłoby prawdą bez żadnej zasługi kadru.
    t.check(width + ' px — scena realnie się przesuwa', ov.raw > 0,
      'geometria wychodzi ' + ov.raw + ' px poza wiersz');
    t.check(width + ' px — ale nic z niej nie widać poza wierszem', ov.visible === 0,
      'widoczne wyjście: ' + ov.visible + ' px');
    t.check(width + ' px — strona nie przewija się w poziomie',
      ov.docScroll <= ov.win + 1, ov.docScroll + ' vs ' + ov.win);

    await o.close();
  }

  await pan.close();
};
