/**
 * Kolor pasków przeglądarki — `theme-color`.
 *
 * Safari koloruje swój górny i dolny pasek pod stronę. Gdy strona NIE MÓWI mu,
 * jakiego koloru mają być, przeglądarka bierze go z tego, co widzi — i wtedy
 * cokolwiek zamaluje kadr, przemalowuje przy okazji paski. Najbardziej widać
 * to po otwarciu menu pełnoekranowego, bo Circular i Offcanvas kładą na cały
 * kadr nieprzezroczysty panel. Ten moduł odbiera tę decyzję próbkowaniu.
 *
 * CZEGO TE SPRAWDZENIA NIE OBEJMUJĄ, i trzeba o tym wiedzieć, zanim się im
 * zaufa: **czy Safari faktycznie pomaluje paski tym kolorem**. To zachowanie
 * przeglądarki, a zestaw jedzie na Chromium — takiego pomiaru nie da się tu
 * zrobić i żadne z poniższych go nie zastępuje. Sprawdzana jest NASZA połowa
 * umowy: że do dokumentu trafia poprawny znacznik, we właściwej kolejności
 * i tylko wtedy, gdy ma trafić.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  const php = JSON.parse(phpOutput('theme-color.php'));

  // ── Nic bez zgody ──────────────────────────────────────────────────────
  // Moduł dokłada znacznik do KAŻDEJ strony, więc domyślne włączenie
  // zmieniałoby wygląd pasków każdemu, kto zaktualizuje wtyczkę.
  t.section('wyłączony moduł nie dokłada niczego');

  t.check('domyślnie wyłączony', php.domyslne.enabled === 0,
    'enabled: ' + php.domyslne.enabled);
  t.check('i wyłączony nie wypisuje ANI ZNAKU', php.wylaczony === '',
    JSON.stringify(php.wylaczony));

  // ── Znacznik ───────────────────────────────────────────────────────────
  t.section('włączony wystawia theme-color');

  t.check('jeden kolor to jeden znacznik BEZ media',
    php.jedenKolor === '<meta name="theme-color" content="#ff0000">',
    php.jedenKolor);
  // Pusty ciemny znaczy „ten sam co jasny" — ta sama konwencja co przy
  // kolorach burgera i kadru menu.
  t.check('pusty ciemny NIE dokłada drugiego znacznika',
    !/prefers-color-scheme/.test(php.jedenKolor), php.jedenKolor);

  const linie = php.dwaKolory.split('\n');
  t.check('dwa kolory to dwa znaczniki', linie.length === 2,
    linie.length + ' znaczników');
  /* Kolejność jest częścią umowy, nie kosmetyką: przeglądarka bierze PIERWSZY
     znacznik, którego zapytanie `media` pasuje. Odwrócona para nie rzuca
     błędu — po prostu w trybie jasnym wychodzi kolor ciemny, czyli dokładnie
     to, przed czym ten moduł ma chronić. */
  t.check('jasny stoi PRZED ciemnym, bo wygrywa pierwszy pasujący',
    /content="#ffffff" media="\(prefers-color-scheme: light\)"/.test(linie[0])
    && /content="#111111" media="\(prefers-color-scheme: dark\)"/.test(linie[1]),
    linie.join(' | '));

  // Ten sam kolor w obu polach to nadal jeden znacznik — para z identyczną
  // treścią byłaby tylko szumem w kodzie strony.
  t.check('identyczne kolory schodzą do jednego znacznika',
    php.takiSam === '<meta name="theme-color" content="#abcdef">', php.takiSam);

  // ── Nie zgadujemy ──────────────────────────────────────────────────────
  // Pomyłka w kolorze przemalowuje paski na CAŁEJ stronie, a milczenie
  // zostawia zachowanie sprzed włączenia modułu. Dlatego przy braku wartości
  // moduł ma zamilknąć, a nie podstawić cokolwiek.
  t.section('bez poprawnego koloru moduł milczy');

  t.check('brak koloru jasnego = brak znacznika', php.bezJasnego === '',
    JSON.stringify(php.bezJasnego));
  /* Sanityzacja także PRZY RENDEROWANIU, nie tylko przy zapisie: opcja bywa
     zapisana inaczej niż przez formularz — kodem, importem ustawień, migracją
     ze starszej wersji. `esc_attr` broni przed wyjściem z atrybutu, ale nie
     robi z treści koloru. */
  t.check('śmieć w opcji też nie wychodzi do strony', php.smiec === '',
    JSON.stringify(php.smiec));
  /* `sanitize_hex_color()` zwraca `null` dla wartości NIEPUSTEJ, ale
     nieprawidłowej — a `''` dla pustej. Bez rzutowania na łańcuch `null`
     przechodzi przez porównanie z `''` i do <head> idzie DRUGI znacznik
     z pustą treścią, który w trybie ciemnym wygrywa z jasnym. Pusty ciemny
     tej ścieżki nie dotyka, bo tam `null` w ogóle nie powstaje. */
  t.check('nieprawidłowy ciemny nie dokłada pustego znacznika',
    php.ciemnySmiec === '<meta name="theme-color" content="#ffffff">',
    php.ciemnySmiec);

  t.check('sanityzacja przepuszcza skrócony zapis szesnastkowy',
    php.sanityzacja.light === '#FFF', php.sanityzacja.light);
  t.check('a odsiewa zapis, którego atrybut nie przyjmie',
    !php.sanityzacja.dark, JSON.stringify(php.sanityzacja.dark));
};
