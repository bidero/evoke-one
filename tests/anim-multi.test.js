/**
 * Wiele animacji na jednym elemencie.
 *
 * Do 1.50.0 element mógł mieć dokładnie jedną. Blokad było sześć i każda
 * wystarczała sama: `attrConfig()` uznawał JSON zaczynający się od `[` za goły
 * slug, `buildConfig()` zwracał jeden obiekt, `initOne()` budował jedną
 * animację, `slugFromClass()` zwracał PIERWSZĄ klasę `evk-anim-*`,
 * `evk_bricks_set_attr()` nadpisywał atrybut, a kontrolki w builderze były
 * płaskimi kluczami bez indeksu.
 *
 * Mierzone są DWIE ROZŁĄCZNE właściwości — przezroczystość i przesunięcie —
 * bo przy dwóch presetach ruszających to samo nie dałoby się odróżnić „obie
 * działają" od „działa tylko druga".
 *
 * Pomiar idzie po STANIE POCZĄTKOWYM, nie końcowym. Stan końcowy fade'a to
 * `opacity: 1`, czyli wartość domyślna — sprawdzenie po nim przechodziło
 * także wtedy, gdy nie powstała żadna animacja (potwierdzone: przed poprawką
 * „pierwsza konfiguracja zagrała" świeciło na zielono na elemencie, dla
 * którego silnik nie zbudował niczego). Stan „from" jest nakładany natychmiast
 * przy budowie osi, więc jest dowodem, że konfiguracja powstała.
 *
 * Wsteczna zgodność jest tu równie ważna jak nowa funkcja: goły slug
 * i pojedynczy obiekt to formaty zapisane na istniejących stronach.
 */

const V = { width: 1000, height: 700 };

module.exports = async function (t) {
  const p = await t.open('anim-multi.html', { viewport: V, settle: 0 });
  await p.waitForFunction(() => window.__stan);

  // ── Stan początkowy: dowód, że konfiguracje POWSTAŁY ───────────────────
  // Okno pomiarowe to 0,5 s opóźnienia z biblioteki.
  const start = await p.evaluate(() => ({
    oba:    window.__stan('oba'),
    slug:   window.__stan('slug'),
    obiekt: window.__stan('obiekt'),
    klasy:  window.__stan('klasy'),
  }));

  t.section('dwie animacje na jednym elemencie');
  t.check('pierwsza konfiguracja powstała (opacity 0)', start.oba.opacity === 0,
    'opacity ' + start.oba.opacity);
  t.check('druga konfiguracja powstała (x -100)', start.oba.x === -100,
    'x ' + start.oba.x + ', transform ' + start.oba.transform);

  t.section('dwie klasy evk-anim-* na jednym elemencie');
  t.check('pierwsza klasa powstała', start.klasy.opacity === 0, 'opacity ' + start.klasy.opacity);
  t.check('druga klasa powstała', start.klasy.x === -100,
    'x ' + start.klasy.x + ', transform ' + start.klasy.transform);

  // ── Wsteczna zgodność ──────────────────────────────────────────────────
  // Goły slug i pojedynczy obiekt mają dawać DOKŁADNIE JEDNĄ animację.
  // Bez tej pary „obsługujemy tablicę" mogłoby znaczyć „rozbijamy wszystko
  // na znaki" i nikt by nie zauważył.
  t.section('stare formaty atrybutu bez zmian');
  t.check('goły slug powstał', start.slug.opacity === 0, 'opacity ' + start.slug.opacity);
  t.check('goły slug NIE dokłada drugiej animacji', start.slug.transform === 'none',
    'transform ' + start.slug.transform);
  t.check('pojedynczy obiekt powstał', start.obiekt.opacity === 0, 'opacity ' + start.obiekt.opacity);
  t.check('pojedynczy obiekt NIE dokłada drugiej', start.obiekt.transform === 'none',
    'transform ' + start.obiekt.transform);

  // ── Stan końcowy: obie realnie dobiegają do celu ───────────────────────
  t.section('obie dobiegają do stanu docelowego');
  await p.waitForTimeout(1400);
  const end = await p.evaluate(() => window.__stan('oba'));
  t.check('przezroczystość dojechała', end.opacity === 1, 'opacity ' + end.opacity);
  t.check('przesunięcie dojechało', end.x === 0, 'x ' + end.x);

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();
};
