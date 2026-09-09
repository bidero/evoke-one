<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Zapis formularza ustawień AJAX-em — PRAWDZIWY uchwyt `evk_save_settings`.
 *
 * PYTANIE, NA KTÓRE TEN PLIK ODPOWIADA: czy wartość wysłana z formularza
 * przechodzi przez `sanitize_callback` modułu, zanim wyląduje w bazie. Uchwyt
 * przyjmuje CAŁĄ TABLICĘ ustawień, nie jak przełącznik pojedyncze 0/1, więc
 * pominięta sanitacja nie jest tu niedogodnością — to dziura.
 *
 * Wołamy uchwyt, nie porównujemy list, i ładujemy PRAWDZIWY moduł sierotek,
 * żeby sitem był jego własny `sanitize_settings()`. Atrapa sita sprawdzałaby
 * wyłącznie, czy moja imitacja usuwa to, co sama uznała za groźne.
 *
 * `apply_filters` MUSI odpalać zarejestrowane filtry — inaczej wartość
 * przechodzi nietknięta i test przechodzi na zielono nie mierząc niczego.
 * Deklaracja stoi poniżej `require`, ale PHP wciąga ją przy kompilacji pliku,
 * czyli PRZED wykonaniem tego `require` — dlatego atrapa w `_wp-stubs.php`
 * jest pod strażą `function_exists`. Ta sama sztuczka, co w svg-sanityzacja.php.
 *
 *   php tests/php/zapis.php '{"option":"evk_sierotki","form":"evk_sierotki[wyjatki]=x"}'
 */
require __DIR__ . '/_wp-stubs.php';

function apply_filters($hook, $value, ...$args) {
    foreach ($GLOBALS['hooks'][$hook] ?? [] as $cb) { $value = $cb($value, ...$args); }
    return $value;
}

/* `evk_preserve_toggle()` NIE jest tu atrapowana — mieszka w
   `30-admin-settings-ajax.php` i to jej prawdziwy kontrakt („gdy pola nie ma
   w POST, weź stan z bazy") rozstrzyga, czy zapis formularza gasi przełącznik
   AJAX. Podstawienie własnej wersji sprawdzałoby moją imitację. */

define('EVOKE_ONE_DIR', EVK_TEST_ROOT . '/');
define('EVOKE_ONE_URL', 'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', '0.0.0-test');

$wejscie = json_decode($argv[1] ?? '{}', true) ?: [];

// Stan początkowy opcji — do sprawdzenia, czego zapis NIE ma ruszyć.
$GLOBALS['options']['evk_sierotki'] = $wejscie['stan'] ?? ['enabled' => 1, 'jednostki' => 0, 'wyjatki' => ''];

/* PRAWDZIWY moduł: jego `register_setting()` podpina sito pod
   `sanitize_option_evk_sierotki`. */
require EVK_TEST_ROOT . '/includes/91-sierotki.php';
require EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';

/* Moduły rejestrują ustawienia na `admin_init`; w harnessie odpalamy ten hak
   z ręki, bo nie ma tu pętli WordPressa. */
foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) $cb();

$uchwyt = null;
foreach ($GLOBALS['hooks']['wp_ajax_evk_save_settings'] ?? [] as $cb) $uchwyt = $cb;

$_POST = [
    'nonce'  => 'x',
    'option' => $wejscie['option'] ?? 'evk_sierotki',
    'form'   => $wejscie['form'] ?? '',
];

$odpowiedz = ['brak odpowiedzi' => true];
try {
    $uchwyt();
} catch (EVK_Test_Json $e) {
    $odpowiedz = $e->payload ?? [];
}

/* Które opcje z listy dozwolonych mają zarejestrowane sito. To jest WARUNEK
   bezpieczeństwa całego uchwytu: wpis bez `sanitize_callback` zapisywałby
   surową tablicę z żądania prosto do bazy, a uchwyt wyglądałby przy tym
   identycznie. */
$sita = [];
foreach (evk_settings_allowlist() as $opcja) {
    $sita[$opcja] = isset($GLOBALS['sanitizers'][$opcja]);
}

echo json_encode([
    'odpowiedz' => $odpowiedz,
    'w_bazie'   => get_option('evk_sierotki', null),
    'sita'      => $sita,
], JSON_UNESCAPED_UNICODE), "\n";
