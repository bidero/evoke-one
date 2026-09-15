/**
 * Ziarno a animacje przewijane — KOSZT KLATKI.
 *
 * ZGŁOSZONE Z UŻYCIA: „Kiedy jest szum na całej stronie animacje animatora się
 * tną. Tzn często nie widać ich przy scrollu — tak jakby szum blokował
 * scrolltrigger".
 *
 * ScrollTrigger NIE BYŁ BLOKOWANY i to jest pierwsza rzecz, którą ten plik
 * ustala: we wszystkich wariantach, także w tym najgorszym, wszystkie osiem
 * wyzwalaczy zapala się i dochodzi do pełnego krycia. Brakowało KLATEK, w
 * których miałyby to pokazać — przy 20 klatkach na sekundę animacja trwająca
 * 0,6 s dostaje ich dwanaście i wygląda jak przeskok.
 *
 * OSOBNY PLIK, NIE SEKCJA W grain.test.js. Każdy scenariusz stawia
 * przeglądarkę z dławieniem procesora i przewija dziesięcioma skokami, więc
 * chodzi minutami. Dokładanie tego do grain.test.js zabrałoby możliwość szybkiego
 * iterowania po zachowaniu elementu — a filtr dopasowuje nazwę PLIKU, nie
 * sekcji (patrz CLAUDE.md).
 *
 * DŁAWIENIE JEST TU WARUNKIEM POMIARU, nie ozdobą. Bez niego maszyna testowa
 * rysuje ziarno za darmo i wszystkie warianty wychodzą identycznie — pierwsza
 * wersja tego pomiaru pokazała trzy razy 16,7 ms i nie mówiła nic.
 *
 * Zmierzone, okno 900x600, mediana odstępu klatek przeglądarki:
 *
 *                          │ dławienie 4x │ 6x
 *     bez ziarna           │ 16,7 ms      │ 16,7
 *     ziarno, sufit DPR 2  │ 50,0 ms      │ 66,7
 *     ziarno, sufit DPR 1  │ 16,7 ms      │ 16,7
 *
 * MIERZYMY MEDIANĘ, NIE NAJGORSZĄ KLATKĘ. Najgorsza wychodziła w kolejnych
 * przebiegach raz 33, raz 17 ms przy identycznych ustawieniach — to rozrzut,
 * a próg wokół rozrzutu zapalałby się losowo.
 */

const OKNO = { width: 900, height: 600 };

/** Jeden przebieg: otwórz, przewiń skokami, oddaj liczby. */
async function zmierz(t, query, opcje) {
  const p = await t.open('grain-animator.html',
    Object.assign({ viewport: OKNO, settle: 600, query }, opcje || {}));

  await p.evaluate(() => window.__wyczysc());
  for (let i = 1; i <= 10; i++) {
    await p.evaluate((v) => window.__przewin(v), i * 700);
    await p.waitForTimeout(220);
  }
  await p.waitForTimeout(600);

  const w = await p.evaluate(() => {
    const ile = document.querySelectorAll('.pudlo').length;
    const kryc = [];
    for (let i = 0; i < ile; i++) kryc.push(window.__krycie(i));
    const c = document.querySelector('.evk-grain__plotno');
    return {
      mediana:   window.__mediana(),
      najgorsza: Math.max.apply(null, window.__odstepy),
      pudel:     ile,
      wystrzelilo: Object.keys(window.__wystrzelilo).length,
      pelne:     kryc.filter((k) => k > 0.99).length,
      kanwa:     c ? c.width + 'x' + c.height : null,
    };
  });
  await p.close();
  return w;
}

module.exports = async function (t) {

  const CIEZKO = { dpr: 2, dlawienieCPU: 4 };

  // ── Punkt odniesienia ────────────────────────────────────────────────────
  /* Bez tego „z ziarnem jest 16,7 ms" nie znaczy nic: równie dobrze mogłoby to
     być tempo, którego ta maszyna i tak nie przekracza. */
  t.section('strona bez ziarna — punkt odniesienia');

  const bez = await zmierz(t, 'ziarno=nie', CIEZKO);
  t.check('mediana klatki mieści się w budżecie', bez.mediana <= 25,
    bez.mediana.toFixed(1) + ' ms');
  t.check('bez ziarna kanwy nie ma', bez.kanwa === null, String(bez.kanwa));
  t.check('wszystkie osie zapalają się i dochodzą do końca',
    bez.wystrzelilo === bez.pudel && bez.pelne === bez.pudel,
    bez.wystrzelilo + '/' + bez.pudel + ' zapaliło, ' + bez.pelne + '/' + bez.pudel + ' pełnych');

  // ── Ziarno przesiewane ───────────────────────────────────────────────────
  /* SEDNO ZGŁOSZENIA. Próg 25 ms leży pomiędzy zmierzonymi 16,7 (po poprawce)
     a 50,0 (przed nią) — z zapasem w obie strony, bo mediana skacze po
     szczeblach odświeżania: 16,7 · 33,3 · 50,0. */
  t.section('ziarno co klatkę nie zabiera stronie klatek');

  const zZiarnem = await zmierz(t, 'ziarno=tak&przesiew=klatka', CIEZKO);
  t.check('mediana klatki jak bez ziarna', zZiarnem.mediana <= 25,
    zZiarnem.mediana.toFixed(1) + ' ms');

  /* PRZYCZYNA, NIE OBJAW. Powyższe dwa progi spełniłoby też ziarno, które się
     nie uruchomiło — a to jest sposób, w jaki ten pomiar mógłby skłamać
     najłatwiej. Kanwa ma być gęstości jeden, nie dwa, mimo DPR 2 w kontekście. */
  t.check('kanwa jest w gęstości jeden, nie dwa', zZiarnem.kanwa === '900x600',
    String(zZiarnem.kanwa));

  /* I to, o co poszło zgłoszenie: animacje MAJĄ się pokazać. */
  t.check('osie przewijane dochodzą do pełnego krycia',
    zZiarnem.wystrzelilo === zZiarnem.pudel && zZiarnem.pelne === zZiarnem.pudel,
    zZiarnem.wystrzelilo + '/' + zZiarnem.pudel + ' zapaliło, '
      + zZiarnem.pelne + '/' + zZiarnem.pudel + ' pełnych');

  // ── Ziarno nieruchome ────────────────────────────────────────────────────
  /* Kontrola pozytywna z drugiej strony: tryb, który rysuje JEDEN kadr, był
     tani także przed poprawką. Gdyby i on zapalał, znaczyłoby to, że pomiar
     mierzy coś innego niż koszt rysowania. */
  t.section('nieruchome było tanie i zostaje tanie');

  const stoi = await zmierz(t, 'ziarno=tak&przesiew=stop', CIEZKO);
  t.check('mediana klatki w budżecie', stoi.mediana <= 25, stoi.mediana.toFixed(1) + ' ms');
  t.check('kanwa jednak powstała', stoi.kanwa === '900x600', String(stoi.kanwa));
};
