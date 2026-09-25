<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Eksport CSV skrzynki formularzy (1.234.0) na PRAWDZIWYM WordPressie.
 *
 *   php tests/php/inbox-csv.php
 *
 * Wiersze w kształcie tabeli zgłoszeń Bricksa (`form_data` to JSON pól
 * z typem, wartością i nazwą — tak zapisuje Bricks), plik z tej samej
 * funkcji co pobieranie w panelu: evk_inbox_csv_zapisz(). Samo pobieranie
 * kończy się `exit`, więc sonda pisze do strumienia w pamięci.
 */

require __DIR__ . '/_testowy-wp.php';

$wiersz = static function (int $id, array $pola, array $reszta = []): object {
    return (object) array_merge([
        'id' => $id, 'form_id' => 'kontakt', 'created_at' => '2026-09-25 10:00:00',
        'form_data' => wp_json_encode($pola), 'ip' => '203.0.113.7', 'browser' => 'Firefox',
        'os' => 'Linux', 'referrer' => 'https://example.com/', 'user_id' => 0,
    ], $reszta);
};

$wiersze = [
    $wiersz(1, [
        'temat'   => ['type' => 'select',   'value' => 'Rezerwacja noclegu', 'name' => 'Temat'],
        'opcje'   => ['type' => 'checkbox', 'value' => ['Śniadanie', 'Parking'], 'name' => 'Opcje'],
        'imie'    => ['type' => 'text',     'value' => '=HYPERLINK("http://zly.example","klik")', 'name' => 'Imię'],
        'telefon' => ['type' => 'tel',      'value' => '+48 600 100 200', 'name' => 'Telefon'],
        'liczba'  => ['type' => 'number',   'value' => '-5', 'name' => 'Liczba'],
        'uwagi'   => ['type' => 'textarea', 'value' => '@SUM(1+1)', 'name' => 'Uwagi'],
    ], ['browser' => "=cmd|' /C calc'!A0", 'referrer' => '-2+3+cmd|x']),
    // Starszy Bricks: płaski łańcuch „typ, wartość".
    $wiersz(2, ['temat' => 'select, Zapytanie ofertowe', 'imie' => 'Anna', 'telefon' => '+48600100200']),
];

$f = fopen('php://memory', 'w+');
evk_inbox_csv_zapisz($f, $wiersze, evk_inbox_get_settings());
rewind($f);
$tresc = (string) stream_get_contents($f);
fclose($f);

$bom = strncmp($tresc, "\xEF\xBB\xBF", 3) === 0;
$linie = array_values(array_filter(preg_split('/\r?\n(?=(?:[^"]*"[^"]*")*[^"]*$)/', $bom ? substr($tresc, 3) : $tresc)));
$tabela = array_map(static function ($l) { return str_getcsv($l, ';', '"', ''); }, $linie);
$naglowek = $tabela[0] ?? [];
$rekordy = [];
foreach (array_slice($tabela, 1) as $r) {
    $rekordy[] = $naglowek ? array_combine(array_slice($naglowek, 0, count($r)), array_slice($r, 0, count($naglowek))) : $r;
}

echo wp_json_encode([
    'bom'      => $bom,
    'naglowek' => $naglowek,
    'rekordy'  => $rekordy,
    'komorki'  => array_map('evk_inbox_csv_komorka', ['=1+1', '+48 600', '-5', '+48', '3,14', '@x', "\tx", 'zwykły', '', 'a=b']),
]);
