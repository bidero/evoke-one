/**
 * Tryb konserwacji — czym się wchodzi za zasłonę.
 *
 * Naprawiane w 1.128.0. Do 1.127.0 ciasteczko wpuszczające za zasłonę miało
 * WARTOŚĆ RÓWNĄ KLUCZOWI dostępu, leciało bez `secure` i bez `SameSite`,
 * a porównania szły przez `===`. Kto ciasteczko odczytał — wspólny komputer,
 * kopia profilu, XSS na stronie — znał klucz, a nie tylko miał wejście.
 *
 * Osobno: „Czas trwania sesji bypass" był wyłącznie datą wygaśnięcia
 * ciasteczka, czyli ustawieniem po stronie przeglądarki. Kto ją zignorował,
 * wchodził bezterminowo. Termin siedzi teraz w podpisanej treści i sprawdza
 * go serwer — dlatego przeterminowane i podrobione ciasteczko mają tu własne
 * sprawdzenia.
 *
 * Test wywołuje prawdziwy hook `parse_request` i patrzy na to, czego czytaniem
 * kodu się nie zobaczy: co przechodzi, co nie, i KTÓRĄ funkcją leci
 * przekierowanie (`wp_redirect` i `wp_safe_redirect` są osobnymi atrapami).
 *
 * Czego nie sprawdza: stałego czasu porównania — `hash_equals` kontra `===`
 * jest z zewnątrz nie do odróżnienia.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('konserwacja.php'));

  // ── Klucz z adresu ────────────────────────────────────────────────────
  t.section('klucz z adresu');

  t.check('poprawny klucz przekierowuje na czysty adres',
    php.dobry_klucz.co === 'przekierowanie' && php.dobry_klucz.cel === '/home-alt/',
    JSON.stringify(php.dobry_klucz));
  t.check('błędny klucz nie wpuszcza',
    php.zly_klucz.co === 'przekierowanie' && php.zly_klucz.cel.endsWith('example.test/'),
    JSON.stringify(php.zly_klucz));

  // `wp_redirect` puszcza adres protokołowo-względny poza serwis — dlatego
  // obie atrapy są osobne i test pyta, która została użyta.
  t.check('przekierowanie idzie bezpiecznym wariantem', php.dobry_klucz.bezpieczne === true,
    php.dobry_klucz.bezpieczne ? 'wp_safe_redirect' : 'wp_redirect');
  t.check('adres //obcy-adres nie wyprowadza poza serwis',
    php.obcy_adres.cel.startsWith('https://example.test'), php.obcy_adres.cel);

  // ── Ciasteczko ────────────────────────────────────────────────────────
  t.section('ciasteczko wpuszczające za zasłonę');

  t.check('ważne ciasteczko wpuszcza', php.ciastko_dobre.co === 'wpuszczony',
    JSON.stringify(php.ciastko_dobre));

  // To jest ten warunek, dla którego wydanie powstało: stary format ciasteczka
  // to sam klucz dostępu i ma przestać działać.
  t.check('ciasteczko w starym formacie (sam klucz) odrzucone',
    php.ciastko_stare.co === 'przekierowanie', JSON.stringify(php.ciastko_stare));
  t.check('podpis nie zawiera klucza', php.podpis.zawiera_klucz === false, php.podpis.wartosc);

  t.check('ciasteczko po terminie odrzucone',
    php.ciastko_po_czasie.co === 'przekierowanie', JSON.stringify(php.ciastko_po_czasie));
  t.check('ciasteczko podpisane innym kluczem odrzucone',
    php.ciastko_obcy_klucz.co === 'przekierowanie', JSON.stringify(php.ciastko_obcy_klucz));
  // Termin jest częścią podpisywanej treści — przesunięcie go unieważnia podpis.
  t.check('przesunięty termin unieważnia podpis',
    php.ciastko_podrobiony_termin.co === 'przekierowanie',
    JSON.stringify(php.ciastko_podrobiony_termin));

  // Atrybutów samego wywołania `setcookie()` test nie widzi (funkcja wbudowana,
  // w CLI bez śladu w headers_list) — sprawdzamy wartości, które moduł jej daje.
  const https = php.ciastko_atrybuty_https;
  const http  = php.ciastko_atrybuty_http;
  t.check('ciasteczko httponly i SameSite=Lax',
    https.httponly === true && https.samesite === 'Lax', JSON.stringify(https));
  t.check('secure idzie za is_ssl()', https.secure === true && http.secure === false,
    'https: ' + https.secure + ' / http: ' + http.secure);

  // ── Wykluczone ścieżki ────────────────────────────────────────────────
  t.section('wykluczone ścieżki');

  const s = php.sciezki;
  t.check('/wp-admin i jego podścieżki wykluczone',
    s['/wp-admin'] === 'wykluczona' && s['/wp-admin/edit.php'] === 'wykluczona'
    && s['/wp-login.php'] === 'wykluczona',
    JSON.stringify(s));
  t.check('wpis użytkownika łapie siebie i swoje podścieżki',
    s['/podglad'] === 'wykluczona' && s['/podglad/cokolwiek'] === 'wykluczona',
    s['/podglad'] + ' / ' + s['/podglad/cokolwiek']);

  // Sedno naprawy: do 1.127.0 porównanie szło podciągiem, więc KAŻDY adres
  // zawierający gdziekolwiek „/wp-admin" omijał zasłonę.
  t.check('adres z /wp-admin w środku NIE jest wykluczony',
    s['/blog/wp-admin-po-polsku'] === 'objeta', s['/blog/wp-admin-po-polsku']);
  t.check('adres z /podglad w środku NIE jest wykluczony',
    s['/kategoria/podglad-x'] === 'objeta', s['/kategoria/podglad-x']);

  // Samo „od początku" też nie wystarczy: strona /wp-administracja zaczyna się
  // od /wp-admin i wychodziłaby spod zasłony bez niczyjej wiedzy.
  t.check('/wp-administracja NIE jest wykluczone', s['/wp-administracja'] === 'objeta',
    s['/wp-administracja']);
  t.check('/podglad-produktu NIE jest wykluczone', s['/podglad-produktu'] === 'objeta',
    s['/podglad-produktu']);

  t.check('wpis bez ukośnika jest normalizowany, nie ignorowany',
    php.bez_ukosnika === 'wykluczona', php.bez_ukosnika);

  // ── Reszta przebiegu ──────────────────────────────────────────────────
  t.section('kiedy zasłony nie ma wcale');

  t.check('zalogowany przechodzi bez klucza', php.zalogowany.co === 'wpuszczony',
    JSON.stringify(php.zalogowany));
  t.check('wyłączony tryb nie zasłania', php.tryb_wylaczony.co === 'wpuszczony',
    JSON.stringify(php.tryb_wylaczony));

  // ── Sanityzacja ustawień ──────────────────────────────────────────────
  t.section('sanityzacja ustawień konserwacji');

  // Cztery ustawienia szły do 1.127.0 do bazy takie, jakie przyszły z formularza.
  t.check('wszystkie cztery ustawienia mają sanityzator', php.sanityzatory.length === 4,
    php.sanityzatory.join(', '));
  t.check('godziny trzymają się zakresu 1–8760',
    JSON.stringify(php.godziny_z_zakresu) === JSON.stringify([1, 12, 8760]),
    php.godziny_z_zakresu.join(', '));
  t.check('klucz bez znaczników', !php.klucz_sanityzowany.includes('<'), php.klucz_sanityzowany);
};
