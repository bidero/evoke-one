/**
 * View Transitions na liście wpisów — dopasowanie klas CSS.
 *
 * ZNALEZIONE ANALIZĄ STATYCZNĄ, nie z użycia: `is_string()` stało po rzutowaniu
 * `(array)`, więc warunek był martwy. Sam martwy warunek jest niegroźny —
 * groźne jest to, przed czym miał chronić. Bricks przechowuje `_cssClasses` raz
 * tablicą, raz JEDNYM ŁAŃCUCHEM („hero-title big"), a łańcuch po rzutowaniu
 * zostawał w tablicy jako pojedynczy element z całą treścią w środku. Wtedy
 * `array_intersect(['hero-title'], ['hero-title big'])` nie trafiał NIGDY:
 * u kogo Bricks zapisał klasy łańcuchem, przejścia nie działały i nic o tym
 * nie mówiło.
 *
 * Sprawdzenie pyta o WYNIK prawdziwej funkcji, a nie o kształt kodu. Warunek
 * przeżył kilkanaście wydań dokładnie dlatego, że nikt jej o wynik nie pytał.
 */

const { phpOutput } = require('./lib/harness');

const dopasuj = (klasy, szukane) =>
  JSON.parse(phpOutput('darkmode-klasy.php',
    JSON.stringify(JSON.stringify({ klasy, szukane: szukane || 'hero-title' }))));

module.exports = async function (t) {

  t.section('klasy Bricksa rozpoznane w obu kształtach, w jakich je zapisuje');

  const tablica = dopasuj(['hero-title', 'big']);
  t.check('klasy podane TABLICĄ dopasowują się', tablica.dopasowane,
    JSON.stringify(tablica.style));

  /* TO JEST TA POPRAWKA. Przed nią wychodziło `false` i nic tego nie mówiło. */
  const lancuch = dopasuj('hero-title big');
  t.check('klasy podane ŁAŃCUCHEM też', lancuch.dopasowane,
    JSON.stringify(lancuch.style));

  t.check('i dają ten sam wynik, co tablica',
    JSON.stringify(lancuch.style) === JSON.stringify(tablica.style),
    JSON.stringify(lancuch.style) + ' wobec ' + JSON.stringify(tablica.style));

  /* Bricks bywa też rozdziela klasy przecinkiem — to samo rozbicie, którego
     moduł używa dla klas wpisanych w panelu. */
  const przecinek = dopasuj('hero-title, big');
  t.check('rozdzielone przecinkiem tak samo', przecinek.dopasowane,
    JSON.stringify(przecinek.style));

  /* KONTROLA NEGATYWNA, i nie jest kosmetyczna: bez niej „dopasowuje się"
     byłoby prawdą także dla funkcji, która dopasowuje WSZYSTKO — a wtedy każdy
     element listy dostawałby `view-transition-name`, czyli nazwę powtórzoną
     wielokrotnie na jednej stronie. */
  const obca = dopasuj('cos-zupelnie-innego');
  t.check('a klasa spoza ustawień nie dopasowuje się', !obca.dopasowane,
    obca.dopasowane ? 'dopasowana mimo wszystko' : 'pominięta');

  /* Nazwa niesie identyfikator wpisu — dwa wpisy na liście nie mogą dostać tej
     samej nazwy przejścia. */
  t.check('nazwa przejścia niesie identyfikator wpisu',
    /view-transition-name: post-title-42;/.test(String(lancuch.style)),
    String(lancuch.style));
};
