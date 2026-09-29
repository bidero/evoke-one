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
 * KOSZT LICZYMY W PIKSELACH, CZAS KLATKI JEST TYLKO ZABEZPIECZENIEM (od
 * 1.253.1, bez wydania). Do tego miejsca sprawdzenie brzmiało „mediana klatki
 * z ziarnem ≤ 25 ms" i w pełnych przebiegach zapalało się raz na dwa, trzy razy
 * na tym samym kodzie (CHANGELOG 1.240.0, 1.253.0, 1.253.1). Zmierzone
 * skryptem, pary uruchamiane jedna po drugiej, średni odstęp klatki i odsetek
 * klatek dłuższych niż 25 ms:
 *
 *                                 │ bez ziarna │ ziarno co klatkę  │ mediana
 *     spokojna maszyna            │ 16,7 · 0%  │ 16,9–21,1 · 1–27% │ 16,7
 *     obciążenie: 2 procesy       │ 16,7 · 0%  │ 24,9–27,2 · 46–60%│ 16,7 / 33,3
 *     obciążenie: 4 procesy       │ 16,7 · 0%  │ 24,9–31,8 · 48–75%│ zwykle 33,3
 *     mutacja SUFIT_DPR = 2       │            │ 65–69 · 100%      │ 66,7
 *     mutacja: drugi drawArrays   │            │ 22,1–25,5 · 32–51%│ 16,7 / 33,2
 *
 * Z tej tabeli trzy wnioski:
 *
 *  1. Pod obciążeniem mniej więcej połowa klatek z ziarnem wypada, a mediana
 *     skacze po szczeblach odświeżania (16,7 · 33,3), więc wynik to rzut
 *     monetą. Stąd losowe czerwone.
 *  2. PUNKT ODNIESIENIA BEZ ZIARNA OBCIĄŻENIA NIE WIDZI (0% wolnych klatek
 *     przy czterech procesach). Ziarno rysuje się programowo w procesie GPU,
 *     którego dławienie CPU nie obejmuje i który walczy o rdzenie z resztą
 *     maszyny; strona bez ziarna tej pracy nie ma. Odrzucanie przebiegów, w
 *     których kontrola jest wolna, nie odrzuciłoby więc żadnego.
 *  3. Żaden próg czasowy nie oddzieli ziarna dwa razy droższego (drugi
 *     drawArrays: 22–25 ms) od poprawnego pod obciążeniem (25–32 ms).
 *
 * Dlatego koszt jest LICZONY: fixtura owija drawArrays i sumuje powierzchnię
 * bufora rysowaną w każdej klatce. Budżet to jedno okno w gęstości jeden,
 * 900x600 = 540 000 pikseli, zmierzone dokładnie tyle. Obie mutacje go
 * przekraczają (×4 i ×2), a obciążenie maszyny nie zmienia go wcale. Czego
 * ta liczba NIE widzi: droższej matematyki w samym shaderze przy tej samej
 * powierzchni.
 *
 * Czas klatki zostaje, ale jako ŚREDNIA (bez szczebli) i z progiem na koszt
 * rażący: 45 ms leży między 31,8 (poprawne ziarno, cztery procesy obciążenia)
 * a 65 (sufit DPR 2 na spokojnej maszynie).
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
      srednia:   window.__srednia(),
      klatek:    window.__odstepy.length,
      wolnych:   window.__odstepy.filter((x) => x > 25).length,
      piksele:   window.__pikseliNaKlatke(),
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

/** Jedno okno w gęstości jeden — patrz nagłówek. */
const BUDZET_PIKSELI = OKNO.width * OKNO.height;
/** Koszt rażący, nie koszt — patrz nagłówek. */
const PROG_SREDNIEJ = 45;

const ms = (x) => x.toFixed(1) + ' ms';
const opisKlatek = (w) => 'średnio ' + ms(w.srednia) + ', mediana ' + ms(w.mediana)
  + ', wolnych ' + w.wolnych + '/' + w.klatek;
const opisPikseli = (w) => w.piksele.mediana + ' px w ' + w.piksele.klatek + ' z '
  + w.klatek + ' klatek (budżet ' + BUDZET_PIKSELI + ')';

module.exports = async function (t) {

  const CIEZKO = { dpr: 2, dlawienieCPU: 4 };

  // ── Punkt odniesienia ────────────────────────────────────────────────────
  /* Bez tego „z ziarnem jest 16,7 ms" nie znaczy nic: równie dobrze mogłoby to
     być tempo, którego ta maszyna i tak nie przekracza. Strona bez ziarna nie
     ma pracy w procesie GPU i obciążenia nie czuje (nagłówek), więc tu próg
     zostaje ciasny. */
  t.section('strona bez ziarna — punkt odniesienia');

  const bez = await zmierz(t, 'ziarno=nie', CIEZKO);
  t.check('średnia klatka mieści się w budżecie', bez.srednia <= 25, opisKlatek(bez));
  t.check('bez ziarna kanwy nie ma', bez.kanwa === null, String(bez.kanwa));
  t.check('bez ziarna nic nie rysuje', bez.piksele.klatek === 0, opisPikseli(bez));
  t.check('wszystkie osie zapalają się i dochodzą do końca',
    bez.wystrzelilo === bez.pudel && bez.pelne === bez.pudel,
    bez.wystrzelilo + '/' + bez.pudel + ' zapaliło, ' + bez.pelne + '/' + bez.pudel + ' pełnych');

  // ── Ziarno przesiewane ───────────────────────────────────────────────────
  /* SEDNO ZGŁOSZENIA. Przed poprawką kanwa miała gęstość dwa, czyli cztery
     okna pikseli w każdej klatce. */
  t.section('ziarno co klatkę nie zabiera stronie klatek');

  const zZiarnem = await zmierz(t, 'ziarno=tak&przesiew=klatka', CIEZKO);
  t.check('rysuje najwyżej jedno okno pikseli na klatkę',
    zZiarnem.piksele.mediana > 0 && zZiarnem.piksele.mediana <= BUDZET_PIKSELI,
    opisPikseli(zZiarnem));

  /* Bez tego budżet spełniłoby ziarno, które się nie uruchomiło albo rysuje
     co kilka klatek — a to jest sposób, w jaki ten pomiar mógłby skłamać
     najłatwiej. Zmierzone: rysowało w każdej klatce. */
  t.check('przesiewa w każdej klatce',
    zZiarnem.piksele.klatek >= 0.9 * zZiarnem.klatek, opisPikseli(zZiarnem));

  t.check('średnia klatka bez rażącego kosztu', zZiarnem.srednia <= PROG_SREDNIEJ,
    opisKlatek(zZiarnem) + ' (próg ' + PROG_SREDNIEJ + ' ms)');

  /* PRZYCZYNA, NIE OBJAW: gęstość jeden mimo DPR 2 w kontekście. */
  t.check('kanwa jest w gęstości jeden, nie dwa', zZiarnem.kanwa === '900x600',
    String(zZiarnem.kanwa));

  /* I to, o co poszło zgłoszenie: animacje MAJĄ się pokazać. */
  t.check('osie przewijane dochodzą do pełnego krycia',
    zZiarnem.wystrzelilo === zZiarnem.pudel && zZiarnem.pelne === zZiarnem.pudel,
    zZiarnem.wystrzelilo + '/' + zZiarnem.pudel + ' zapaliło, '
      + zZiarnem.pelne + '/' + zZiarnem.pudel + ' pełnych');

  // ── Ziarno nieruchome ────────────────────────────────────────────────────
  /* Kontrola z drugiej strony: tryb, który rysuje JEDEN kadr i odrysowuje go
     tylko przy przewinięciu. Zmierzone: 7 klatek z rysowaniem na ~170, po
     dziesięciu skokach przewinięcia. */
  t.section('nieruchome było tanie i zostaje tanie');

  const stoi = await zmierz(t, 'ziarno=tak&przesiew=stop', CIEZKO);
  t.check('rysuje tylko przy przewinięciu, nie co klatkę',
    stoi.piksele.klatek > 0 && stoi.piksele.klatek <= 20, opisPikseli(stoi));
  t.check('rysuje najwyżej jedno okno pikseli na klatkę',
    stoi.piksele.mediana <= BUDZET_PIKSELI, opisPikseli(stoi));
  t.check('średnia klatka w budżecie', stoi.srednia <= 25, opisKlatek(stoi));
  t.check('kanwa jednak powstała', stoi.kanwa === '900x600', String(stoi.kanwa));
};
