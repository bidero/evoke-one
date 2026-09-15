<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Etykiety elementów — rejestr kontra sam element.
 *
 * Nazwa elementu stoi w DWÓCH miejscach: `evk_elements_registry()` w loaderze
 * (widoczna w panelu wtyczki, przy włączniku) i `get_label()` w samym elemencie
 * (widoczna w builderze Bricksa). Loader mówi o tym wprost — „zmieniając jedną,
 * zmień drugą" — i do 1.202.0 pilnował tego wyłącznie komentarz.
 *
 * ROZJAZD JEST NIEWIDOCZNY Z KAŻDEGO POJEDYNCZEGO EKRANU. Panel pokazuje jedną
 * nazwę, builder drugą, obie wyglądają normalnie, a szuka się elementu, którego
 * „nie ma". Wyszło przy zmianie „Ziarno" na „Grain": trzeba było poprawić dwa
 * pliki i nic by nie zapaliło, gdyby poprawiło się jeden.
 *
 * CZEGO TA SONDA NIE SPRAWDZA. Nazwy elementów są po angielsku — to jedyne
 * miejsce we wtyczce, gdzie tak jest. Stało tu sprawdzenie „żadna etykieta nie
 * ma znaków spoza ASCII", ale wyszło przy mutacji, że jest bezwartościowe:
 * „Ziarno" przechodzi przez nie bez zająknięcia. Wybór nazwy jest decyzją
 * człowieka, a jedyna reguła, jaką dałoby się napisać, łapałaby ogonki zamiast
 * polskich słów — czyli dawałaby fałszywą pewność.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require __DIR__ . '/_bricks-stubs.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');

/* Rejestr czytamy ze ŹRÓDŁA loadera, bez jego wykonywania: loader wiesza filtry
   i akcje WordPressa, a atrapy nie udają całego cyklu życia wtyczki. Interesuje
   nas jedna tablica, więc bierzemy ją regexem z tego samego pliku, który jedzie
   na stronę. */
$loader = (string) file_get_contents(EVK_TEST_ROOT . '/includes/bricks-elements/loader.php');

/* Każdy wpis rejestru: klucz, etykieta i nazwa klasy — z jednego bloku, żeby
   przypadkowe „label" spod innego klucza nie podszyło się pod właściwe. */
$rejestr = [];
if (preg_match_all("/'(\w+)'\s*=>\s*\[(.*?)\n        \],/s", $loader, $m, PREG_SET_ORDER)) {
    foreach ($m as $wpis) {
        if (!preg_match("/'label'\s*=>\s*'([^']*)'/", $wpis[2], $l))  continue;
        if (!preg_match("/'class'\s*=>\s*'([^']*)'/", $wpis[2], $k))  continue;
        $rejestr[$wpis[1]] = [ 'label' => $l[1], 'class' => $k[1] ];
    }
}

/** Ładuje element i oddaje nazwę klasy, która przez to powstała. */
function zaladuj(string $plik): ?string {
    $przed = get_declared_classes();
    require_once $plik;
    foreach (array_values(array_diff(get_declared_classes(), $przed)) as $k) {
        if (is_subclass_of($k, 'Bricks\\Element')) { return $k; }
    }
    return null;
}

/* KLUCZUJEMY PO NAZWIE BEZ PRZESTRZENI. Trzy elementy — Burger, Circular Menu
   i Offcanvas Menu — deklarują klasę wewnątrz `namespace Bricks`, więc naprawdę
   nazywają się `Bricks\Evk_Burger`, a rejestr trzyma `Evk_Burger`. Produkcja
   radzi sobie z tym listą `guard` w rejestrze; tutaj wystarczy porównywać
   końcówkę. Pierwsza wersja tej sondy tego nie robiła i po cichu pomijała
   właśnie te trzy elementy — czyli strażnik pilnowałby siedmiu z dziesięciu
   i wyglądałby przy tym na zielono. */
function bezPrzestrzeni(string $klasa): string {
    $p = strrpos($klasa, '\\');
    return $p === false ? $klasa : substr($klasa, $p + 1);
}

$zElementow = [];
foreach (glob(EVK_TEST_ROOT . '/includes/bricks-elements/*/element.php') as $plik) {
    $klasa = zaladuj($plik);
    if (!$klasa) { continue; }
    $el = new $klasa();
    $zElementow[bezPrzestrzeni($klasa)] = method_exists($el, 'get_label')
        ? (string) $el->get_label() : null;
}

/* Rozjazdy: klasa z rejestru, której etykieta nie zgadza się z get_label(). */
$rozjazdy = [];
$dopasowanych = 0;
foreach ($rejestr as $klucz => $w) {
    $k = bezPrzestrzeni($w['class']);
    if (!array_key_exists($k, $zElementow)) {
        $rozjazdy[] = $klucz . ': rejestr wskazuje klasę „' . $w['class'] . '", której nie ma';
        continue;
    }
    $dopasowanych++;
    if ($zElementow[$k] !== $w['label']) {
        $rozjazdy[] = $klucz . ': rejestr „' . $w['label']
            . '" ≠ element „' . $zElementow[$k] . '"';
    }
}

echo json_encode([
    'wRejestrze'    => count($rejestr),
    'zElementow'    => count($zElementow),
    'dopasowanych'  => $dopasowanych,
    'rozjazdy'      => $rozjazdy,
    'etykiety'      => array_values($zElementow),
    'klasy'         => array_keys($zElementow),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
