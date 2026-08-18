/**
 * Wave Background — palety kolorów.
 *
 * Najważniejsze sprawdzenie jest tu regresyjne: element bez ustawień musi
 * dawać DOKŁADNIE te kolory co przed dodaniem palet. Gotowa paleta jako
 * domyślna przemalowałaby wszystkie tła już wstawione na strony, a zauważyłby
 * to dopiero ktoś, kto wejdzie na gotową podstronę.
 *
 * Kolory czytamy z wyjścia prawdziwego render() (tests/php/wave-bg-colors.php),
 * nie z regexa po źródle — regex sprawdzałby naszą interpretację pliku.
 */

const { phpOutput } = require('./lib/harness');

const HEX = /^#[0-9a-fA-F]{6}$/;
const run = (settings) => JSON.parse(phpOutput('wave-bg-colors.php', JSON.stringify(JSON.stringify(settings))));

module.exports = async function (t) {
  t.section('palety Wave Background');

  // Zestaw sprzed dodania palet — wpisany wprost, bo właśnie o niezmienność
  // wobec niego chodzi. Odczyt z tego samego źródła co kod nie dowiódłby niczego.
  const BEFORE = ['#F2E6DB', '#71D9E9', '#8c3dd0', '#D03F83', '#F43FF9', '#8c3dd0'];

  const bare = run({});
  t.check('element bez ustawień ma kolory jak dotąd',
    JSON.stringify(bare.colors) === JSON.stringify(BEFORE), bare.colors && bare.colors.join(' '));

  t.check('lista palet niepusta', bare.palettes.length >= 5, bare.palettes.join(', '));
  t.check('„własne" jest pierwsze na liście', bare.palettes[0] === 'custom', bare.palettes[0]);

  // Każda paleta musi dać sześć poprawnych kolorów — shader czyta uColor[6]
  // i brakująca pozycja zostawiłaby w gradiencie czerń.
  const broken = bare.palettes.filter((key) => {
    const c = run({ palette: key }).colors;
    return !c || c.length !== 6 || !c.every((v) => HEX.test(v));
  });
  t.check('każda paleta daje sześć poprawnych kolorów', !broken.length,
    broken.join(', ') || bare.palettes.length + ' palet');

  // Gotowa paleta wygrywa z pickerami — te są wtedy w panelu ukryte.
  const named = run({ palette: 'ocean', color_1: { hex: '#ff0000' } });
  t.check('gotowa paleta wygrywa z pickerem', named.colors[0] !== '#ff0000', named.colors[0]);

  const custom = run({ palette: 'custom', color_1: { hex: '#ff0000' } });
  t.check('„własne" czyta pickery', custom.colors[0] === '#ff0000', custom.colors[0]);

  // Paleta zapisana kiedyś, a dziś nieistniejąca (zmiana nazwy klucza) nie może
  // zostawić gradientu bez kolorów — wracamy do pickerów.
  const ghost = run({ palette: 'nie-ma-takiej', color_1: { hex: '#00ff00' } });
  t.check('nieznana paleta wraca do pickerów', ghost.colors[0] === '#00ff00', ghost.colors[0]);

  /* ── Maska: rampa po krzywej, nie po prostej ──────────────────────────
   *
   * Zgłoszone z użycia: maska górna ma mieć łagodniejsze przejście. Dwa
   * przystanki — `transparent 0%` i `#000 X%` — dają alfę rosnącą LINIOWO,
   * a wtedy przyrost jest najszybszy dokładnie tam, gdzie zanikanie się
   * zaczyna i kończy: w obu tych miejscach widać szew.
   *
   * Krzywa `t²(3−2t)` startuje i kończy ze zboczem zerowym, więc wchodzi
   * w sąsiedztwo bez załamania. W POŁOWIE drogi jest identyczna z prostą,
   * co jest tu istotne: zanikanie nie robi się ani krótsze, ani dłuższe —
   * zmienia się wyłącznie jego kształt. Dlatego mierzymy ĆWIARTKĘ, bo tylko
   * tam prosta i krzywa się rozjeżdżają.
   */
  t.section('maska zanika po krzywej, a nie po prostej');

  const alfy = (maska) => (maska.match(/rgba\(0,0,0,([\d.]+)\)/g) || [])
    .map((x) => parseFloat(x.replace(/rgba\(0,0,0,|\)/g, '')));

  const gora = run({ mask_top_enabled: true, mask_top_end: 10 }).maska;
  const a = alfy(gora);

  t.check('rampa ma więcej niż dwa przystanki', a.length >= 5, a.length + ' przystanków');
  t.check('zaczyna od zera i dochodzi do pełnej',
    a[0] === 0 && a[a.length - 1] === 1, a[0] + ' → ' + a[a.length - 1]);
  t.check('rośnie monotonicznie',
    a.every((v, i) => i === 0 || v >= a[i - 1]), a.join(' '));
  /* Właściwość, która krzywą S ODRÓŻNIA od prostej: w pierwszej połowie leży
     PONIŻEJ prostej, w drugiej POWYŻEJ, a w środku się z nią spotyka. Sama
     „mniejsza wartość w jakimś punkcie" niczego by nie dowiodła — porównujemy
     każdy przystanek z prostą w tym samym miejscu. */
  const prosta = a.map((_, i) => i / (a.length - 1));
  const pierwsza = a.slice(1, (a.length - 1) / 2);
  const druga    = a.slice((a.length - 1) / 2 + 1, -1);
  t.check('w pierwszej połowie rampa leży PONIŻEJ prostej',
    pierwsza.every((v, i) => v < prosta[i + 1] - 0.01),
    pierwsza.join(' ') + ' vs ' + prosta.slice(1, (a.length - 1) / 2).join(' '));
  t.check('a w drugiej POWYŻEJ — to jest krzywa S, nie odcinek',
    druga.every((v, i) => v > prosta[i + 1 + (a.length - 1) / 2] + 0.01),
    druga.join(' '));
  // W połowie pokrywa się z prostą — dowód, że długość zanikania została ta sama.
  t.check('a w połowie pokrywa się z prostą — długość bez zmian',
    Math.abs(a[(a.length - 1) / 2] - 0.5) < 0.001, String(a[(a.length - 1) / 2]));

  // KONTROLA NEGATYWNA: bez maski górnej nie ma czego wygładzać.
  t.check('bez maski górnej rampy nie ma wcale',
    alfy(run({ mask_enabled: false, mask_top_enabled: false }).maska || '').length === 0,
    String(run({ mask_enabled: false, mask_top_enabled: false }).maska));

  // Dolna maska jedzie tą samą rampą — inaczej góra byłaby miękka, a dół
  // twardy i wyglądałoby to jak usterka.
  const dol = alfy(run({ mask_enabled: true, mask_start: 90 }).maska);
  t.check('dolna maska zanika tą samą krzywą',
    dol.length >= 5 && dol[0] === 1 && dol[dol.length - 1] === 0,
    dol.join(' '));
};
