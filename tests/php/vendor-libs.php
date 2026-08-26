<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Biblioteki obce jadą Z WŁASNEGO SERWERA — zrzut PRAWDZIWEJ rejestracji.
 *
 * Do 1.72.0 GSAP jechał z cdnjs, a Lenis z unpkg: dwa obce hosty, każdy
 * z własnym DNS + TCP + TLS przed pierwszym bajtem. Zmierzone na żywej stronie
 * 900–1650 ms na plik przy 53 KiB razem — czyli koszt POŁĄCZEŃ, nie bajtów.
 *
 * Dwie rzeczy trzeba tu sprawdzać razem, bo osobno nic nie znaczą:
 *
 *  1. **Żaden adres nie wychodzi na obcy host.** Sam zapis adresu wystarczy,
 *     żeby wrócić do CDN-u — i wrócił: `marquee.js` miał adres cdnjs wpisany
 *     na sztywno w loaderze awaryjnym.
 *  2. **Wskazany plik NAPRAWDĘ leży na dysku.** Rejestracja adresu, pod którym
 *     nic nie ma, jest cicha: WordPress wydrukuje `<script src>`, przeglądarka
 *     dostanie 404, a strona po prostu przestanie animować.
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL', 'https://example.test/wp-content/plugins/evoke-one/');

/* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. Plik jest liściem: potrzebuje tylko `is_admin()`
   z atrap i niczego więcej. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

require EVK_TEST_ROOT . '/includes/89-gsap.php';

evk_register_gsap_libs();

$scripts = [];
foreach ($GLOBALS['registered'] as $handle => $row) {
    // Adres → ścieżka na dysku. Rejestracja stoi na EVOKE_ONE_URL, więc odcięcie
    // tego przedrostka daje ścieżkę względem korzenia wtyczki.
    $rel  = str_replace(EVOKE_ONE_URL, '', $row['src']);
    $host = parse_url($row['src'], PHP_URL_HOST);
    $scripts[$handle] = [
        'src'    => $row['src'],
        'deps'   => $row['deps'],
        'host'   => $host,
        'onDisk' => is_file(EVK_TEST_ROOT . '/' . $rel),
        'bytes'  => is_file(EVK_TEST_ROOT . '/' . $rel) ? filesize(EVK_TEST_ROOT . '/' . $rel) : 0,
    ];
}

// Lenis rejestruje się dopiero przy enqueue, więc jego adres składamy tak samo,
// jak robi to includes/96-lenis.php — i sprawdzamy ten sam plik.
$lenisRel = 'assets/vendor/lenis/lenis.min.js';

/*
 * Adresy CDN-ów wpisane na sztywno w JS wtyczki. To NIE jest to samo pytanie,
 * co rejestracja handle'i: `marquee.js` i `hscroll.js` mają własny loader
 * awaryjny i to w nim siedziały adresy cdnjs, poza zasięgiem PHP.
 * Panel administratora jest wyłączony ze skanu świadomie — Sortable i Chart.js
 * jadą tam z jsDelivr i dotyczą wyłącznie zaplecza, nie stron odwiedzających.
 */
$cdnHits = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(EVK_TEST_ROOT . '/includes'));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'js') continue;
    $path = str_replace(EVK_TEST_ROOT . '/', '', $file->getPathname());
    if (strpos($path, 'includes/admin/') === 0) continue;
    if (preg_match('#https?://[^\s\'"]*(cdnjs|unpkg|jsdelivr)[^\s\'"]*#i', file_get_contents($file->getPathname()), $m)) {
        $cdnHits[] = ['file' => $path, 'url' => $m[0]];
    }
}
foreach (glob(EVK_TEST_ROOT . '/assets/js/*.js') as $file) {
    if (preg_match('#https?://[^\s\'"]*(cdnjs|unpkg|jsdelivr)[^\s\'"]*#i', file_get_contents($file), $m)) {
        $cdnHits[] = ['file' => str_replace(EVK_TEST_ROOT . '/', '', $file), 'url' => $m[0]];
    }
}

/*
 * Mapy źródeł, o które proszą zapakowane pliki.
 *
 * Zminifikowany plik potrafi kończyć się komentarzem `sourceMappingURL=...`.
 * Przeglądarka pyta o ten plik ZA KAŻDYM RAZEM, gdy ktoś otworzy narzędzia
 * deweloperskie — i dostaje 404, jeśli mapy nie ma obok. Odwiedzającemu to nie
 * szkodzi (mapy nie pobiera nikt z zamkniętą konsolą), ale w konsoli właściciela
 * strony wisi czerwony błąd bez związku z niczym. Zgłoszone z Safari po
 * przeniesieniu Lenisa na własny serwer.
 *
 * Mapy NIE wycinamy z pliku: to znaczyłoby modyfikowanie cudzej dystrybucji,
 * a wtedy przy każdym podbiciu wersji trzeba by o tym pamiętać. Taniej dołożyć
 * plik obok.
 */
$maps = [];
foreach (array_merge(glob(EVK_TEST_ROOT . '/assets/vendor/gsap/*.js'),
                     glob(EVK_TEST_ROOT . '/assets/vendor/lenis/*.js')) as $file) {
    if (!preg_match('#sourceMappingURL=([^\s*]+)#', file_get_contents($file), $m)) continue;
    $maps[] = [
        'plik'   => basename($file),
        'mapa'   => $m[1],
        'obok'   => is_file(dirname($file) . '/' . $m[1]),
    ];
}

// Zależności marquee. Element używa I Observera (prędkość przewijania),
// I ScrollTriggera (zatrzymanie poza kadrem) — brak tego drugiego wpuszczał
// loader awaryjny do gry na KAŻDEJ stronie z marquee.
// Czytamy WPIS 'script', nie pierwsze lepsze wystąpienie sluga: obok stoi
// tablica `consts` z tym samym słowem i naiwne dopasowanie łapało właśnie ją.
$loader = file_get_contents(EVK_TEST_ROOT . '/includes/bricks-elements/loader.php');
preg_match("#'evk-marquee',\s*\\\$url[^,]*,\s*\[([^\]]*)\]#s", $loader, $mq);

echo json_encode([
    'scripts'     => $scripts,
    'lenisOnDisk' => is_file(EVK_TEST_ROOT . '/' . $lenisRel),
    'lenisBytes'  => is_file(EVK_TEST_ROOT . '/' . $lenisRel) ? filesize(EVK_TEST_ROOT . '/' . $lenisRel) : 0,
    'lenisSrc'    => EVOKE_ONE_URL . $lenisRel,
    // Adres katalogu wystawiony na stronie dla loaderów awaryjnych.
    'inline'      => $GLOBALS['inline'],
    'cdnHits'     => $cdnHits,
    'maps'        => $maps,
    'marqueeDeps' => isset($mq[1]) ? $mq[1] : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
