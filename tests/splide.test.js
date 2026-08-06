/**
 * Animator na korzeniu slidera (Splide, tryb fade).
 *
 * Dwie usterki o wspólnym źródle — animator ruszał element, którego układem
 * steruje slider:
 *
 * 1. GSAP zostawia po animacji `transform: translate(0px, 0px)` i `filter:
 *    blur(0px)` w stylu inline. To NIE jest `none`, więc element zostaje BLOKIEM
 *    ZAWIERAJĄCYM dla potomków pozycjonowanych absolutnie — a w trybie fade
 *    Splide układa tak wszystkie slajdy. Efekt: slajdy przestają leżeć na sobie.
 *    Psuł to każdy preset ruszający transform albo filter, czyli prawie każdy.
 *
 * 2. `TextPlugin` wpisuje tekst przez innerHTML. Na korzeniu slidera
 *    `textContent` skleja treść WSZYSTKICH slajdów w jeden ciąg, a wpisanie go
 *    z powrotem kasuje znaczniki i style. Stąd „wszystkie teksty obok siebie".
 *
 * Fixture używa PRAWDZIWEGO Splide, nie atrapy: cała rzecz dzieje się
 * w regułach układu, których atrapa by nie odtworzyła.
 */

module.exports = async function (t) {
  const V = { width: 1000, height: 700 };

  // ── Ślad po animacji na korzeniu slidera ───────────────────────────────
  t.section('slider fade — ślad po animacji wejścia');

  const page = await t.open('splide.html', { viewport: V, query: 'preset=blur-up', settle: 1800 });
  const s = await page.evaluate(() => window.__slider());

  t.check('bez błędów JS', !page.errors.length, page.errors.join(' | ') || 'brak');
  t.check('brak inline transform po animacji', s.inlineTransform === '',
    JSON.stringify(s.inlineTransform));
  t.check('brak inline filter po animacji', s.inlineFilter === '',
    JSON.stringify(s.inlineFilter));

  // Sedno objawu: w trybie fade slajdy leżą JEDEN NA DRUGIM, więc mają tę samą
  // pozycję pionową. Rozjechane w pionie = nakładające się/rozsypane slajdy.
  const stacked = s.tops.every((v) => Math.abs(v - s.tops[0]) <= 1);
  t.check('slajdy nadal leżą jeden na drugim', stacked, s.tops.join(' / '));
  t.check('widoczny dokładnie jeden slajd', s.visible === 1, s.visible + ' widocznych');

  await page.close();

  // KONTROLA NEGATYWNA: preset bez filtra, ale z transformem. Gdyby sprzątanie
  // obejmowało tylko filter, ten przypadek nadal by się sypał.
  const p2 = await t.open('splide.html', { viewport: V, query: 'preset=fade-up', settle: 1600 });
  const s2 = await p2.evaluate(() => window.__slider());
  t.check('to samo dla presetu z samym transformem',
    s2.inlineTransform === '' && s2.tops.every((v) => Math.abs(v - s2.tops[0]) <= 1),
    JSON.stringify(s2.inlineTransform) + ' | ' + s2.tops.join(' / '));
  await p2.close();

  // ── Efekt tekstowy na kontenerze ───────────────────────────────────────
  t.section('efekt tekstowy na kontenerze');

  const p3 = await t.open('splide.html', { viewport: V, query: 'preset=typewriter', settle: 1800 });
  const s3 = await p3.evaluate(() => window.__slider());

  t.check('znaczniki slajdów przeżyły', s3.childTags.join(',') === 'DIV',
    s3.childTags.join(',') || 'brak dzieci');
  t.check('nagłówki w slajdach na miejscu',
    s3.headings.join('|') === 'Pierwszy|Drugi|Trzeci', s3.headings.join('|'));
  // Spłaszczenie kontenera zeruje liczbę potomków — zostaje jeden węzeł tekstowy.
  t.check('struktura slidera nietknięta', s3.descendants >= 10, s3.descendants + ' potomków');
  t.check('ostrzeżenie w konsoli o kontenerze',
    p3.warnings.some((w) => /Evoke|EVK/i.test(w) && /tekst/i.test(w)),
    p3.warnings.join(' | ') || 'brak ostrzeżeń');

  // KONTROLA NEGATYWNA: na zwykłym nagłówku bez dzieci efekt ma nadal działać.
  // Bez tego „treść nietknięta" świeci na zielono także wtedy, gdy zablokowaliśmy
  // efekt wszędzie albo silnik w ogóle nie ruszył.
  t.check('na zwykłym nagłówku nadal pisze', (await p3.evaluate(() => window.__plain())) === 'Napis',
    await p3.evaluate(() => window.__plain()));

  await p3.close();
};
