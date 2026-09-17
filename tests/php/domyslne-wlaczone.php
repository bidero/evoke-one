<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Pola zaznaczenia domyślnie WŁĄCZONE — czy domyślna naprawdę obowiązuje.
 *
 * TRZY REGUŁY, WSZYSTKIE OGÓLNE I NIEZNAJĄCE ŻADNEGO ELEMENTU Z NAZWY.
 *
 *   0. ŻADEN CHECKBOX NIE MA PRAWA MIEĆ 'default' => true
 *
 *      Bo Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać jako
 *      „wyłączone", więc takie pole jest NIE DO WYŁĄCZENIA — żadna poprawka
 *      odczytu tego nie obejdzie. Pola, które mają być domyślnie włączone,
 *      idą przez ODWRÓCONY przełącznik i `evk_wlaczone()` (patrz flaga.php).
 *
 *      To jest reguła, której brak kosztował trzy błędne diagnozy i dwa
 *      wydania. Dowód siedział w jednym elemencie, w dwóch linijkach obok
 *      siebie w evoke-wave-bg/element.php: maska dolna czytana z domyślną
 *      włączoną NIE dawała się wyłączyć, górna z domyślną wyłączoną — owszem.
 *      Ten sam render(), ten sam gradient, ta sama kontrolka w panelu.
 *
 *   Dla pozostałych pól zaznaczenia (domyślnie wyłączonych):
 *
 *   1. DOMYŚLNA OBOWIĄZUJE
 *      render([]) === render([klucz => false])
 *
 *   2. DA SIĘ JĄ PRZESTAWIĆ NIEZALEŻNIE OD ZAPISU
 *      render([klucz => null]) === render([klucz => false])
 *
 * Nie trzeba przy tym wiedzieć, w jaki atrybut dana kontrolka pisze — a właśnie
 * ta wiedza robi ze sprawdzeń rzecz pisaną osobno dla każdego elementu
 * i zapominaną przy nowych.
 *
 * CO ŁAPIE REGUŁA 1. Odczyt `! empty( $s['klucz'] )` przy domyślnej WŁĄCZONEJ:
 * Bricks przy nietkniętym elemencie nie ma klucza w ustawieniach, `! empty()`
 * daje wtedy `false` i zadeklarowane `'default' => true` nie obowiązuje.
 * Z panelu buildera wygląda to normalnie — pole jest zaznaczone.
 *
 * CO ŁAPIE REGUŁA 2 — i dlaczego jej brak kosztował zgłoszenie. Do 1.213.0
 * stała tu wyłącznie reguła 1, czyli sprawdzana była POŁOWA UMOWY: że domyślna
 * działa. Nikt nie pytał, czy da się ją zdjąć. Stacking Cards czytał swoje dwa
 * pola tak:
 *
 *     'shadow' => ! isset( $s['shadow'] ) || ! empty( $s['shadow'] ),
 *
 * i przechodził regułę 1 na zielono, będąc NIE DO WYŁĄCZENIA. Zgłoszone
 * z użycia: „nie działa wyłączanie cienia kart. Zawsze się wyświetla".
 *
 * Sedno to `isset()` wobec `array_key_exists()`. `isset()` oddaje `false` dla
 * wartości `null`, więc pierwszy człon zapala się przy ODZNACZONYM polu
 * zapisanym jako `null` i wraca domyślna — włączona. `evk_flaga()` pyta
 * `array_key_exists()`, które dla `null` oddaje `true`, i dopiero wtedy
 * sprawdza `! empty()`. Dlatego reguła 2 porównuje właśnie `null` z `false`:
 * to jedyna para, która te dwa odczyty rozróżnia.
 *
 * Wyjście: listy kontrolek, przy których wyjścia się różnią — osobno dla obu
 * reguł, bo to dwie różne usterki i dwie różne naprawy.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require __DIR__ . '/_bricks-stubs.php';
require EVK_TEST_ROOT . '/includes/bricks-elements/flaga.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');
if (!defined('EVOKE_ONE_URL')) define('EVOKE_ONE_URL', '/');

/** Ładuje element i oddaje nazwę klasy, która przez to powstała. */
function zaladuj(string $plik): ?string {
    $przed = get_declared_classes();
    require_once $plik;
    foreach (array_values(array_diff(get_declared_classes(), $przed)) as $k) {
        if (is_subclass_of($k, 'Bricks\\Element')) { return $k; }
    }
    return null;
}

/** Wyjście render() dla podanych ustawień — świeży obiekt za każdym razem. */
function wyjscie(string $klasa, array $ustawienia): string {
    $el = new $klasa();
    $el->settings = $ustawienia;
    ob_start();
    try { $el->render(); } catch (\Throwable $e) { ob_end_clean(); return 'WYJĄTEK: ' . $e->getMessage(); }
    return (string) ob_get_clean();
}

$rozjazdy = [];
$nieDoWylaczenia = [];
$zDomyslnaWlaczona = [];
$bezEfektu = [];
$bezTresci = [];
$zbadanych = 0;
$elementow = 0;
$pominiete = [];

foreach (glob(EVK_TEST_ROOT . '/includes/bricks-elements/*/element.php') as $plik) {
    $nazwa = basename(dirname($plik));
    $klasa = zaladuj($plik);
    if (!$klasa) { $pominiete[] = $nazwa . ' (klasa się nie załadowała)'; continue; }

    $el = new $klasa();
    $el->set_controls();
    $elementow++;

    /* ELEMENT, KTÓRY PRZY PUSTYCH USTAWIENIACH DRUKUJE PUDEŁKO ZASTĘPCZE, nie
       nadaje się do reguły 3. Marquee bez pozycji wychodzi z render() pierwszą
       linijką („Dodaj elementy w zakładce Treść"), więc żadne przestawienie
       przełącznika nie ma prawa zmienić wyjścia — i nie jest to usterka
       przełącznika, tylko brak treści. Mówimy o tym wprost zamiast cicho
       przepuszczać. */
    $zastepcze = strpos(wyjscie($klasa, []), 'bricks-element-placeholder') !== false;
    if ($zastepcze) { $bezTresci[] = $nazwa; }

    /* Element musi dać STABILNE wyjście dla tych samych ustawień — inaczej
       porównanie dwóch wyjść nie znaczy nic. Losowy identyfikator albo znacznik
       czasu w znaczniku dyskwalifikuje element z tego sprawdzenia, i lepiej
       powiedzieć to wprost, niż cicho go przepuścić. */
    if (wyjscie($klasa, []) !== wyjscie($klasa, [])) {
        $pominiete[] = $nazwa . ' (render nie jest powtarzalny)';
        continue;
    }

    foreach ($el->controls as $klucz => $def) {
        if (!is_array($def)) { continue; }
        if (($def['type'] ?? '') !== 'checkbox') { continue; }

        /* REGUŁA 0 — najważniejsza i najprostsza. Pole zaznaczenia z domyślną
           WŁĄCZONĄ jest w tej wtyczce nie do wyłączenia, bo Bricks przy
           odznaczeniu nie zapisuje nic, co dałoby się odczytać jako
           „wyłączone". Powody i dowód: evk_wlaczone() w flaga.php. */
        if (($def['default'] ?? null) === true) {
            $zDomyslnaWlaczona[] = $nazwa . '/' . $klucz;
            continue;
        }

        $zbadanych++;

        /* Reguła 1: domyślna obowiązuje. Dla pól domyślnie wyłączonych znaczy
           to, że brak klucza ma dać dokładnie to samo co jawne `false`. */
        $puste     = wyjscie($klasa, []);
        $zWlaczona = wyjscie($klasa, [ $klucz => false ]);
        if ($puste !== $zWlaczona) {
            $rozjazdy[] = $nazwa . '/' . $klucz;
        }

        /* Reguła 2: da się ją wyłączyć. `null` to postać, w jakiej odznaczenie
           potrafi trafić do ustawień — i jedyna, którą `isset()` myli z brakiem
           klucza. Porównujemy z `false`, bo „odznaczone" ma znaczyć to samo
           niezależnie od tego, jak zostało zapisane. */
        $jakoNull  = wyjscie($klasa, [ $klucz => null ]);
        $jakoFalse = wyjscie($klasa, [ $klucz => false ]);
        if ($jakoNull !== $jakoFalse) {
            $nieDoWylaczenia[] = $nazwa . '/' . $klucz;
        }

        /* REGUŁA 3 — DLA ODWRÓCONYCH PRZEŁĄCZNIKÓW: zaznaczenie MA COŚ ZMIENIĆ.
           To jest dosłownie treść zgłoszenia („przełączanie działa, ale nie ma
           efektu"), więc warto ją sprawdzać wprost, a nie wnioskować z odczytu.
           Ograniczone do przyrostka `_off`, bo tylko tam wiadomo z nazwy, że
           zaznaczenie ma coś WYŁĄCZAĆ — przy zwykłym polu włączającym funkcję
           dodatkową brak różnicy bywa poprawny. */
        if (substr($klucz, -4) === '_off' && !$zastepcze
            && $puste === wyjscie($klasa, [ $klucz => true ])) {
            $bezEfektu[] = $nazwa . '/' . $klucz;
        }
    }
}

/* NAZWA NOWEGO KLUCZA MUSI BYĆ STARYM KLUCZEM + „_off".
 *
 * Nie jest to pedanteria: przy odwracaniu szesnastu pól w 1.214.0 dwa z nich
 * dostały skróconą nazwę (`noise_off` zamiast `noise_enabled_off`) i skrypt
 * sprawdzający przestał je widzieć — pokazywał „bez zmian" dla kodu, który
 * działał. Dwie konwencje na raz są gorsze niż jedna brzydka.
 *
 * Czytamy ŹRÓDŁO, bo stary klucz nie jest już kontrolką i w tablicy `controls`
 * go nie ma — istnieje wyłącznie jako argument `evk_wlaczone()`. */
$zleNazwane = [];
foreach (glob(EVK_TEST_ROOT . '/includes/bricks-elements/*/element.php') as $plik) {
    $tresc = (string) file_get_contents($plik);
    if (preg_match_all("/evk_wlaczone\(\s*[^,]+,\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/", $tresc, $m, PREG_SET_ORDER)) {
        foreach ($m as $para) {
            if ($para[2] !== $para[1] . '_off') {
                $zleNazwane[] = basename(dirname($plik)) . ': ' . $para[1] . ' → ' . $para[2];
            }
        }
    }
}

echo json_encode([
    'elementow'  => $elementow,
    'zbadanych'  => $zbadanych,
    'zDomyslnaWlaczona' => $zDomyslnaWlaczona,
    'rozjazdy'   => $rozjazdy,
    'nieDoWylaczenia' => $nieDoWylaczenia,
    'bezEfektu'  => $bezEfektu,
    'bezTresci'  => $bezTresci,
    'zleNazwane' => $zleNazwane,
    'pominiete'  => $pominiete,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
