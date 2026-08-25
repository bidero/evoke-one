/**
 * Warunki widoczności kontrolek — WSZYSTKIE elementy Bricksa naraz.
 *
 * Bricks nie obsługuje ŁAŃCUCHÓW w `required`: warunek złożony z dwóch członów
 * sprawia, że kontrolka nie pokazuje się wcale. Kosztowało to już dwa razy —
 * najpierw repeater marquee (1.103.1), potem czternaście pól wskaźnika
 * w Horizontal Scroll (1.107.0). Za drugim razem dlatego, że pierwszy wniosek
 * był za wąski i sprawdzenie pilnowało jednego repeatera zamiast całej wtyczki.
 *
 * Tego NIE DA SIĘ zobaczyć ani w znaczniku, ani w przeglądarce: pole po prostu
 * nie pojawia się w panelu buildera, a strona wygląda normalnie. Stąd pomiar
 * na kształcie tablicy, z PRAWDZIWYCH plików elementów.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  const d = JSON.parse(phpOutput('bricks-required.php'));

  // ── Pokrycie ─────────────────────────────────────────────────────────────
  /* Bez tego „zero łańcuchów" byłoby prawdą także wtedy, gdyby żaden element
     się nie załadował. Liczba plików jest pierwszym, co trzeba sprawdzić. */
  t.section('strażnik obejmuje wszystkie elementy');

  t.check('weszło dziewięć plików elementów', d.plikow === 9, d.plikow + ' plików');
  t.check('żaden nie odpadł po drodze', d.niezaladowane.length === 0,
    d.niezaladowane.join(', ') || 'brak');
  /* I warunki w ogóle SĄ — reguła nie może być spełniona przez pustkę. */
  t.check('a warunków jest kilkadziesiąt', d.warunkow > 60, d.warunkow + ' warunków');

  // ── Łańcuchy ─────────────────────────────────────────────────────────────
  t.section('żadna kontrolka nie ma łańcucha warunków');

  t.check('zero łańcuchów w całej wtyczce', d.lancuchy.length === 0,
    d.lancuchy.join(', ') || 'wszystkie po jednym warunku');

  /* Alternatywę zapisuje się TABLICĄ w trzecim członie — to jedyna forma, jaką
     dokumentacja Bricksa opisuje, i to nią zastąpiono łańcuchy `!=`. */
  t.check('a alternatywy zapisane tablicą', d.tablicowe >= 4, d.tablicowe + ' warunków tablicowych');

  // ── Wiszące odwołania ────────────────────────────────────────────────────
  /* Druga klasa cichej usterki: warunek wskazujący pole, którego w tej samej
     przestrzeni nie ma. Kontrolka też się wtedy nie pokaże, a w źródle wygląda
     poprawnie. */
  t.section('każdy warunek wskazuje istniejące pole');

  t.check('brak wiszących odwołań', d.wiszace.length === 0,
    d.wiszace.join(', ') || 'wszystkie trafiają');

  // ── Wskaźnik Horizontal Scroll ───────────────────────────────────────────
  /* Zgłoszone z użycia: „brak możliwości wybrania koloru aktywnego". Pole było
     w kodzie, ale z łańcuchem — czyli nie do dosięgnięcia w panelu. */
  t.section('kontrolki wskaźnika są dosięgalne');

  const wsk = d.wskaznik;
  const znajdz = (p) => wsk.find((w) => w.startsWith(p + ':'));

  t.check('jest ich dziewiętnaście', wsk.length === 19, wsk.length + ' kontrolek');
  t.check('„Kolor bieżącego" ma jeden warunek, po stylu',
    znajdz('seg_on') === 'seg_on: ["progressbar_style","=",["segments","current"]]',
    znajdz('seg_on'));
  t.check('„Kolor nieaktywnych" tak samo',
    znajdz('seg_off') === 'seg_off: ["progressbar_style","=",["segments","current"]]',
    znajdz('seg_off'));
  /* Kontrola pozytywna: warunki dalej ROZRÓŻNIAJĄ style — zejście do jednego
     członu miało odblokować pola, a nie pokazać wszystkie naraz. */
  t.check('a pola tylko dla kreski dalej pytają o kreskę',
    znajdz('progressbar_color') === 'progressbar_color: ["progressbar_style","=","bar"]',
    znajdz('progressbar_color'));
  t.check('i pola tylko dla numerów — o numery',
    znajdz('num_size') === 'num_size: ["progressbar_style","=","current"]',
    znajdz('num_size'));
};
