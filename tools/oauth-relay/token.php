<?php
/**
 * Evoke ONE — pośrednik tokenów Google Drive.
 *
 * GDZIE: https://evoke.pl/evk-oauth/token.php (wgrać przez FTP obok
 * index.html). Serwer podaje go przed WordPressem, jak stronę
 * przekierowującą.
 *
 * PO CO. Klient OAuth typu „web" wymaga sekretu przy wymianie kodu na tokeny
 * i przy każdym odświeżeniu tokenu dostępu. Wtyczka i jej repozytorium są
 * publiczne — sekret wpisany we wtyczkę Google wykrył i kazał wymienić
 * (1.229.0). Więc sekret zna WYŁĄCZNIE ten plik: strona z wtyczką wysyła tu
 * żądanie tokenu bez sekretu, pośrednik dokłada identyfikator i sekret
 * klienta, przekazuje do Google i oddaje odpowiedź Google bez zmian.
 * Niczego nie zapisuje (ani kodów, ani tokenów, ani dziennika).
 *
 * SEKRET — w pliku konfiguracyjnym, NIGDY w tym pliku ani w repozytorium:
 *
 *   <katalog nad public_html>/evk-oauth-config.php
 *
 *   <?php return [
 *       'client_id'     => '763290511291-….apps.googleusercontent.com',
 *       'client_secret' => 'GOCSPX-…',
 *       'redirect_uri'  => 'https://evoke.pl/evk-oauth/',
 *   ];
 *
 * Szukany kolejno: zmienna środowiskowa EVK_OAUTH_CONFIG, dwa katalogi wyżej
 * niż ten plik (public_html/evk-oauth/ → katalog domowy konta), obok tego
 * pliku jako config.php. Bez konfiguracji pośrednik odpowiada 503 i nic nie
 * robi — tak jest w każdej kopii wtyczki na stronach klientów, bo ten plik
 * jedzie razem z nią w katalogu tools/.
 *
 * CO PRZEPUSZCZA — tylko to, czego używa wtyczka:
 *   - POST,
 *   - grant_type=authorization_code z kodem, weryfikatorem PKCE
 *     i redirect_uri równym temu z konfiguracji,
 *   - grant_type=refresh_token z tokenem odświeżania.
 * Adres Google jest stały (z konfiguracji, domyślnie oauth2.googleapis.com) —
 * to nie jest otwarte proxy. Identyfikator i sekret z żądania są pomijane.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function evk_posrednik_odpowiedz(int $kod, array $dane): void {
    http_response_code($kod);
    echo json_encode($dane, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$konfig = null;
foreach ([getenv('EVK_OAUTH_CONFIG') ?: '', dirname(__DIR__, 2) . '/evk-oauth-config.php', __DIR__ . '/config.php'] as $plik) {
    if ($plik !== '' && is_file($plik)) { $konfig = include $plik; break; }
}
if (!is_array($konfig) || empty($konfig['client_id']) || empty($konfig['client_secret']) || empty($konfig['redirect_uri'])) {
    evk_posrednik_odpowiedz(503, ['error' => 'temporarily_unavailable', 'error_description' => 'Pośrednik nie jest skonfigurowany.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    evk_posrednik_odpowiedz(405, ['error' => 'invalid_request', 'error_description' => 'Tylko POST.']);
}

$typ = (string) ($_POST['grant_type'] ?? '');
if ($typ === 'authorization_code') {
    $kod = (string) ($_POST['code'] ?? '');
    $weryfikator = (string) ($_POST['code_verifier'] ?? '');
    if ($kod === '' || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $weryfikator)) {
        evk_posrednik_odpowiedz(400, ['error' => 'invalid_request', 'error_description' => 'Brak kodu albo weryfikatora PKCE.']);
    }
    if ((string) ($_POST['redirect_uri'] ?? '') !== (string) $konfig['redirect_uri']) {
        evk_posrednik_odpowiedz(400, ['error' => 'invalid_request', 'error_description' => 'Nieznany adres powrotu.']);
    }
    $cialo = ['grant_type' => $typ, 'code' => $kod, 'code_verifier' => $weryfikator, 'redirect_uri' => (string) $konfig['redirect_uri']];
} elseif ($typ === 'refresh_token') {
    $odswiez = (string) ($_POST['refresh_token'] ?? '');
    if ($odswiez === '' || strlen($odswiez) > 512) {
        evk_posrednik_odpowiedz(400, ['error' => 'invalid_request', 'error_description' => 'Brak tokenu odświeżania.']);
    }
    $cialo = ['grant_type' => $typ, 'refresh_token' => $odswiez];
} else {
    evk_posrednik_odpowiedz(400, ['error' => 'unsupported_grant_type']);
}
$cialo['client_id'] = (string) $konfig['client_id'];
$cialo['client_secret'] = (string) $konfig['client_secret'];

$adres = (string) ($konfig['token_url'] ?? 'https://oauth2.googleapis.com/token');
$zapytanie = http_build_query($cialo, '', '&');
$status = 0;
$tresc = false;
if (function_exists('curl_init')) {
    $c = curl_init($adres);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $zapytanie, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
    $tresc = curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
} else {
    $tresc = @file_get_contents($adres, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 25,
        'ignore_errors' => true, 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $zapytanie]]));
    // Nagłówki ostatniej odpowiedzi strumienia: funkcja od PHP 8.4, wcześniej zmienna lokalna.
    $naglowki = function_exists('http_get_last_response_headers') ? (array) http_get_last_response_headers()
        : (array) (get_defined_vars()['http_response_header'] ?? []);
    foreach ($naglowki as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) $status = (int) $m[1];
}
if ($tresc === false || $status === 0) {
    evk_posrednik_odpowiedz(502, ['error' => 'temporarily_unavailable', 'error_description' => 'Google nie odpowiada.']);
}
http_response_code($status);
echo $tresc;
