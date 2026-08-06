/**
 * Przestawianie kolejności wierszy biblioteki animacji (zapis AJAX).
 *
 * Kolejność jest wyłącznie porządkowa — silnik czyta bibliotekę po slugu —
 * więc najważniejsze nie jest to, czy handler dobrze sortuje, tylko czy
 * NIGDY NIE GUBI WIERSZA. W panelu mogą stać wiersze dodane przyciskiem
 * i jeszcze niezapisane (nie mają sluga), a dwie otwarte karty mogą przysłać
 * listę bez wiersza, który w opcji już jest. Żaden z tych przypadków nie może
 * skończyć się utratą konfiguracji.
 */

const { phpOutput } = require('./lib/harness');

const run = (saved, order) =>
  JSON.parse(phpOutput('anim-reorder.php',
    JSON.stringify(JSON.stringify(saved)) + ' ' + JSON.stringify(JSON.stringify(order))));

/** Same slugi, w kolejności zapisanej w opcji. */
const slugs = (res) => res.stored.map((s) => s.split('/')[0]);

module.exports = async function (t) {
  t.section('kolejność wierszy animacji');

  const plain = run(['a', 'b', 'c'], ['c', 'a', 'b']);
  t.check('przestawia zgodnie z listą z panelu',
    slugs(plain).join(',') === 'c,a,b', slugs(plain).join(','));

  // Etykieta niesie tożsamość wiersza — gdyby handler budował wiersze od nowa
  // zamiast je przestawiać, konfiguracja by wyparowała, a slugi i tak by się zgadzały.
  t.check('wiersze przestawione, nie zbudowane od nowa',
    plain.stored.every((s) => s.split('/')[1] === 'etykieta-' + s.split('/')[0]),
    plain.stored.join(' '));

  // Wiersz dodany przyciskiem nie ma jeszcze sluga, więc nie ma go na liście.
  // Zapisane wiersze spoza listy muszą przetrwać — na końcu, ale przetrwać.
  const partial = run(['a', 'b', 'c'], ['c', 'a']);
  t.check('wiersz spoza listy nie ginie', slugs(partial).join(',') === 'c,a,b',
    slugs(partial).join(','));

  const ghost = run(['a', 'b'], ['b', 'duch', 'a']);
  t.check('nieznany slug jest pomijany', slugs(ghost).join(',') === 'b,a', slugs(ghost).join(','));
  t.check('odpowiedź zgłasza pominięty wpis', ghost.response.data.ignored === 1,
    JSON.stringify(ghost.response.data));

  // Pusta lista z panelu (wszystkie wiersze świeżo dodane) nie może wyczyścić opcji.
  const empty = run(['a', 'b'], []);
  t.check('pusta lista nie czyści biblioteki', slugs(empty).join(',') === 'a,b',
    slugs(empty).join(','));

  const none = run([], ['a']);
  t.check('brak biblioteki kończy się błędem, nie zapisem',
    none.response && none.response.success === false, JSON.stringify(none.response));
};
