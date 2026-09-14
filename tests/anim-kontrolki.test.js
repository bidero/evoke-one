/**
 * Kształt kontrolek dokładanych filtrem do KAŻDEGO elementu Bricks.
 *
 * ZGŁOSZONE Z UŻYCIA: „gdy dodam nowy element w builderze, zawsze pojawia się
 * żółta kropka obok atrybutów, tak jakby było coś ustawione. A ani parallax,
 * ani animacje nie są".
 *
 * Kropka w Bricks znaczy „ta grupa ma zapisane ustawienia" i kliknięta cofa je
 * do domyślnych. Kontrolka z kluczem `default` raportuje wartość także wtedy,
 * gdy w ustawieniach elementu nie ma nic — a kontrolki Evoke wchodzą do grupy
 * Atrybuty KAŻDEGO elementu, więc zapalały ją wszędzie.
 *
 * Mierzone po kształcie tablicy, bo w przeglądarce tego nie widać: panel jest
 * aplikacją Vue w drugim oknie buildera, do którego test nie ma wstępu.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const d = JSON.parse(phpOutput('anim-kontrolki.php'));

  // ── Pokrycie ─────────────────────────────────────────────────────────────
  /* Bez tego „żadna nie ma default" byłoby prawdą także wtedy, gdyby filtr nie
     dołożył ani jednej kontrolki — czyli z najgorszego możliwego powodu. */
  t.section('filtr naprawdę dokłada kontrolki do grupy Atrybuty');

  t.check('kontrolek jest kilkadziesiąt', d.kontrolek >= 20, d.kontrolek + ' kontrolek');
  t.check('i wszystkie idą do grupy Atrybuty',
    d.grupy.length === 1 && d.grupy[0] === '_attributes', d.grupy.join(', '));

  // ── Kropka „grupa ma ustawienia" ─────────────────────────────────────────
  t.section('żadna kontrolka nie deklaruje wartości domyślnej');

  t.check('zero kluczy `default` w całym bloku', d.zDefault.length === 0,
    d.zDefault.join(', ') || 'żadna kontrolka nie ma default');

  /* Usunięcie `default` nie może zmienić tego, co widać w panelu: select bez
     niego staje na PIERWSZEJ pozycji listy, więc pierwsza musi znaczyć „nic nie
     wybrano". Gdyby lista zaczynała się od prawdziwej animacji, każdy nowy
     element dostałby ją po cichu. */
  t.check('pierwsza pozycja listy animacji to pusty wybór',
    d.pierwszaAnimacja === '', JSON.stringify(d.pierwszaAnimacja));

  // ── Filtrowanie długich list ─────────────────────────────────────────────
  /* Zgłoszone z użycia: „możliwość filtrowania (pisząc) listy animacji
     w kontrolce w builderze". `searchable` jest natywnym kluczem kontrolki
     `select` w Bricks, więc nie ma tu ani linijki własnego JS-u w panelu. */
  t.section('długie listy da się filtrować pisząc');

  t.check('lista animacji jest szukalna',
    d.szukalne.includes('evkAnimList/animation'), d.szukalne.join(', ') || 'żadna');
  t.check('lista krzywych easing też',
    d.szukalne.includes('evkAnimList/easing'), d.szukalne.join(', ') || 'żadna');

  /* KONTROLA NEGATYWNA: `searchable` na liście o trzech pozycjach dokłada pole
     wyszukiwania tam, gdzie nie ma czego szukać. Sprawdzenie wyżej przeszłoby
     także wtedy, gdyby klucz wylądował na wszystkim jak leci. */
  t.check('ale nie na krótkich listach trójstanowych',
    ['evkAnimList/repeat', 'evkAnimList/loop', 'evkAnimList/loopYoyo', 'evkAnimList/pin']
      .every((k) => !d.szukalne.includes(k)),
    d.szukalne.join(', '));
  t.check('a selectów jest więcej niż szukalnych',
    d.selecty.length > d.szukalne.length,
    d.selecty.length + ' selectów, ' + d.szukalne.length + ' szukalnych');
};
