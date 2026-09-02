<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Eksport listy adresów do CSV.
 *
 * Handler kończy się `exit`, więc CAŁE WYJŚCIE TEGO PROCESU jest plikiem CSV —
 * tak samo jak przy `tl-eksport.php`. Test w przeglądarce parsuje je wprost,
 * dzięki czemu sprawdza dane, które naprawdę wychodzą z serwera, a nie to, co
 * deklaruje funkcja pomocnicza.
 *
 * Argument 1 wybiera scenariusz:
 *   (brak)   — trzej subskrybenci: aktywny, wypisany, oczekujący na potwierdzenie
 *   partie   — 1200 aktywnych, czyli więcej niż jedna partia odczytu
 *   formula  — adres zaczynający się od „=", który w Excelu byłby formułą
 */
require __DIR__ . '/_wp-stubs.php';

function current_time($type = 'mysql') {
    return $type === 'Y-m-d' ? '2027-03-01' : '2027-03-01 10:00:00';
}
function wp_die($msg = '', $t = '', $a = []) { echo 'WP_DIE: ' . $msg; exit; }
function home_url($p = '') { return 'https://example.test' . $p; }

/* Lista i subskrybenci — atrapy oddające przygotowany zestaw.
   `evk_nl_get_subscribers()` jest tu ODWZOROWANE, nie prawdziwe: prawdziwe
   siedzi na $wpdb, a ten test jest o tym, co eksport robi z wynikami, nie
   o samym zapytaniu. Odwzorowanie honoruje `status`, `limit` i `offset`,
   bo właśnie na tych trzech stoi cała pętla partii. */
$GLOBALS['lista'] = ['id' => 4, 'name' => 'Newsletter Główny', 'fields_config' => '[]'];
$GLOBALS['subskrybenci'] = [];

function evk_nl_get_list($id) { return $id === 4 ? $GLOBALS['lista'] : null; }
function evk_nl_get_subscribers(int $list_id, array $args = []): array {
    $status = $args['status'] ?? null;
    $limit  = (int) ($args['limit'] ?? 50);
    $offset = (int) ($args['offset'] ?? 0);
    $pasuje = array_values(array_filter($GLOBALS['subskrybenci'], function ($s) use ($status) {
        return $status === null || (int) $s['status'] === (int) $status;
    }));
    return array_slice($pasuje, $offset, $limit);
}

require_once EVK_TEST_ROOT . '/includes/newsletter/ajax.php';

/** Buduje jednego subskrybenta. */
function sub(string $email, int $status, array $pola = [], string $data = '2027-01-01 09:00:00'): array {
    return [
        'id' => crc32($email), 'email' => $email, 'status' => $status,
        'token' => 'tajny-token-' . crc32($email),
        'subscribed_at' => $data, 'unsubscribed_at' => null,
        'fields_json' => wp_json_encode($pola),
    ];
}

$scenariusz = $argv[1] ?? '';

if ($scenariusz === 'partie') {
    for ($i = 0; $i < 1200; $i++) {
        $GLOBALS['subskrybenci'][] = sub('adres' . $i . '@example.test', 1);
    }
} elseif ($scenariusz === 'formula') {
    $GLOBALS['subskrybenci'] = [
        sub('=HYPERLINK("http://zly-adres.test","klik")@example.test', 1),
        sub('zwykly@example.test', 1, ['imie' => '=1+1']),
    ];
} else {
    $GLOBALS['lista']['fields_config'] = wp_json_encode([
        ['key' => 'imie', 'label' => 'Imię'],
        ['key' => 'firma', 'label' => 'Firma'],
    ]);
    $GLOBALS['subskrybenci'] = [
        sub('aktywny@example.test', 1, [
            'imie' => 'Jan', 'firma' => 'ACME',
            '_consent_text' => 'Zgoda na newsletter', '_consent_at' => '2027-01-01 09:00:00',
            '_consent_ip' => '198.51.100.7', '_confirmed_at' => '2027-01-01 09:05:00',
        ]),
        sub('wypisany@example.test', 0, ['imie' => 'Anna']),
        sub('oczekujacy@example.test', 2, ['imie' => 'Piotr']),
        // Pole własne, którego NIE MA w konfiguracji listy — kolumna ma powstać
        // z tego, co naprawdę siedzi u subskrybentów.
        sub('drugi-aktywny@example.test', 1, ['imie' => 'Ewa', 'miasto' => 'Gdańsk']),
    ];
}

$handler = null;
foreach ($GLOBALS['hooks']['wp_ajax_evk_nl_export_subscribers'] ?? [] as $cb) { $handler = $cb; }
$_POST = ['nonce' => 'testnonce', 'list_id' => 4];
$handler();   // kończy się exit — wyjście procesu to plik CSV
