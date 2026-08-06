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
};
