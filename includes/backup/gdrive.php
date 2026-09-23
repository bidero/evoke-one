<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — Dysk Google: łączenie jednym kliknięciem, wysyłka kopii
 * w kawałkach, retencja w dniach, pobieranie kopii z powrotem na serwer.
 *
 * ŁĄCZENIE (OAuth 2.0 z PKCE). Google odsyła po zgodzie wyłącznie na adres
 * wpisany w konsoli aplikacji, a stron z wtyczką jest wiele — więc odsyła na
 * stronę przekierowującą na evoke.pl (tools/oauth-relay/index.html), która
 * przekazuje kod dalej, na adres zapisany w `state`:
 *
 *   panel → admin-post.php?action=evk_backup_gdrive_connect
 *         → accounts.google.com (zgoda)
 *         → https://evoke.pl/evk-oauth/?code=…&state=…
 *         → admin-post.php?action=evk_backup_gdrive_callback (ta strona)
 *         → zakładka Kopie zapasowe
 *
 * Kod przechodzący przez evoke.pl jest bez weryfikatora PKCE bezużyteczny,
 * a weryfikator nie opuszcza tej strony (transient na 15 min, jednorazowy,
 * przypisany do użytkownika). `state` jest podpisany kluczem instalacji.
 *
 * SEKRETU KLIENTA WE WTYCZCE NIE MA (1.229.1). W 1.229.0 był jawny, jak
 * w rclone — Google wykrył go w publicznym repozytorium w kilka minut po
 * wypchnięciu i nakazał wymianę. Wymianę kodu na tokeny i odświeżanie tokenu
 * (tylko te dwie operacje wymagają sekretu) robi pośrednik na evoke.pl
 * (tools/oauth-relay/token.php), który jako jedyny zna sekret. Tokeny
 * przechodzą przez niego, ale niczego nie zapisuje; reszta rozmowy z Google
 * (Dysk, cofnięcie tokenu) idzie ze strony wprost. Własna aplikacja Google:
 * stałe EVK_GDRIVE_CLIENT_ID i EVK_GDRIVE_CLIENT_SECRET w wp-config.php —
 * wtedy strona rozmawia z Google bez pośrednika.
 *
 * ZAKRES `drive.file`: wtyczka widzi WYŁĄCZNIE pliki, które sama utworzyła —
 * żadnych innych plików z Dysku. To zakres niewrażliwy (bez audytu Google).
 *
 * STAN POŁĄCZENIA w opcji `evk_backup_gdrive` — jak `evk_backup_key`,
 * należy do instalacji, nie do strony: przywracanie zachowuje wartość
 * sprzed siebie (restore.php, r_swap), więc przywrócenie kopii z innej
 * strony nie podpina tu cudzego Dysku.
 *
 * WYSYŁKA I POBIERANIE to zadania silnika (engine.php) typów `upload`
 * i `download` — te same kroki, lock, samokalibracja i odpytywanie panelu co
 * kopia. Wysyłka: „resumable upload" Google w kawałkach po wielokrotności
 * 256 KB; krok ubity w połowie pyta Google, ile już dotarło
 * (`Content-Range: bytes * /rozmiar`). Pobieranie: zakresami (Range)
 * do części w katalogu kopii — jej rozmiar na dysku jest pozycją wznowienia.
 *
 * Dane kopii (strona, data, źródło, przypięcie) jadą w `appProperties`
 * pliku na Dysku — lista kopii z Dysku nie otwiera archiwów.
 */

// Identyfikator klienta nie jest tajny (widać go w każdym adresie zgody Google).
if (!defined('EVK_GDRIVE_CLIENT_ID'))     define('EVK_GDRIVE_CLIENT_ID', '763290511291-49qjq33hftns0jbvup1canppi5gk1qv1.apps.googleusercontent.com');
if (!defined('EVK_GDRIVE_REDIRECT'))      define('EVK_GDRIVE_REDIRECT', 'https://evoke.pl/evk-oauth/');
/** Pośrednik tokenów — zna sekret klienta, którego we wtyczce nie ma. */
if (!defined('EVK_GDRIVE_TOKEN_BROKER'))  define('EVK_GDRIVE_TOKEN_BROKER', 'https://evoke.pl/evk-oauth/token.php');

const EVK_GDRIVE_OPTION = 'evk_backup_gdrive';
const EVK_GDRIVE_SCOPE  = 'https://www.googleapis.com/auth/drive.file openid email';
const EVK_GDRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';
/** Kawałek wysyłki i pobierania: wielokrotność 256 KB (wymóg Google). */
const EVK_GDRIVE_CHUNK  = 8388608;
/** Tyle chwilowych błędów Google (5xx, 429) z rzędu i zadanie się poddaje. */
const EVK_GDRIVE_MAX_RETRIES = 6;

/** Wyjątek z kodem błędu OAuth (`invalid_grant` = dostęp cofnięty). */
class EVK_Gdrive_Exception extends \RuntimeException {
    /** @var string */
    public $kod = '';
    public function __construct(string $msg, string $kod = '') {
        parent::__construct($msg);
        $this->kod = $kod;
    }
}

/**
 * Adresy Google i dane klienta — przez filtr, który testy kierują na atrapę.
 * Bez sekretu w wp-config.php żądania tokenów idą do pośrednika, który
 * dokłada sekret sam (`client_secret` pusty = nie wysyłamy go wcale).
 */
function evk_gdrive_config(): array {
    $sekret = defined('EVK_GDRIVE_CLIENT_SECRET') ? (string) constant('EVK_GDRIVE_CLIENT_SECRET') : '';
    return (array) apply_filters('evk_backup_gdrive_endpoints', [
        'auth'          => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token'         => $sekret !== '' ? 'https://oauth2.googleapis.com/token' : EVK_GDRIVE_TOKEN_BROKER,
        'revoke'        => 'https://oauth2.googleapis.com/revoke',
        'api'           => 'https://www.googleapis.com/drive/v3',
        'upload'        => 'https://www.googleapis.com/upload/drive/v3',
        'redirect'      => EVK_GDRIVE_REDIRECT,
        'client_id'     => EVK_GDRIVE_CLIENT_ID,
        'client_secret' => $sekret,
    ]);
}

function evk_gdrive_chunk(): int {
    $n = (int) apply_filters('evk_backup_gdrive_chunk', EVK_GDRIVE_CHUNK);
    return max(262144, $n - $n % 262144);
}

// =========================================================================
// STAN POŁĄCZENIA
// =========================================================================

function evk_gdrive_state(): array {
    $s = get_option(EVK_GDRIVE_OPTION, []);
    return is_array($s) ? $s : [];
}

function evk_gdrive_save(array $s): void {
    update_option(EVK_GDRIVE_OPTION, $s, false);
}

function evk_gdrive_connected(): bool {
    return !empty(evk_gdrive_state()['refresh']);
}

/** Rozłączenie po stronie wtyczki (bez pytania Google). */
function evk_gdrive_forget(): void {
    delete_option(EVK_GDRIVE_OPTION);
}

/** Klucz strony w appProperties: host i ścieżka, małymi literami. */
function evk_gdrive_site_key(): string {
    $u = wp_parse_url(home_url());
    return substr(strtolower((string) ($u['host'] ?? 'strona') . rtrim((string) ($u['path'] ?? ''), '/')), 0, 100);
}

// =========================================================================
// ŁĄCZENIE
// =========================================================================

function evk_gdrive_b64url(string $b): string {
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

function evk_gdrive_callback_url(): string {
    return add_query_arg('action', 'evk_backup_gdrive_callback', admin_url('admin-post.php'));
}

function evk_gdrive_state_sig(string $payload): string {
    return hash_hmac('sha256', 'evk-gdrive-state|' . $payload, evk_backup_loopback_key());
}

/**
 * Adres zgody Google dla użytkownika $user. Weryfikator PKCE zostaje tu,
 * w transiencie pod losowym `n` ze `state`.
 */
function evk_gdrive_auth_url(int $user): string {
    $n = bin2hex(random_bytes(16));
    $weryfikator = evk_gdrive_b64url(random_bytes(48));
    set_transient('evk_gdrive_pkce_' . $n, ['v' => $weryfikator, 'user' => $user], 15 * MINUTE_IN_SECONDS);
    $dane = evk_gdrive_b64url((string) wp_json_encode(['u' => evk_gdrive_callback_url(), 'n' => $n], JSON_UNESCAPED_SLASHES));
    $c = evk_gdrive_config();
    return $c['auth'] . '?' . http_build_query([
        'client_id'             => $c['client_id'],
        'redirect_uri'          => $c['redirect'],
        'response_type'         => 'code',
        'scope'                 => EVK_GDRIVE_SCOPE,
        'access_type'           => 'offline',
        // Zgoda za każdym razem: tylko wtedy Google wydaje token odświeżania.
        'prompt'                => 'consent',
        'state'                 => $dane . '.' . evk_gdrive_state_sig($dane),
        'code_challenge'        => evk_gdrive_b64url(hash('sha256', $weryfikator, true)),
        'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * `state` z powrotu → weryfikator PKCE. Podpis, ważność (15 min), jednorazowość
 * i użytkownik. Rzuca z powodem.
 */
function evk_gdrive_verify_state(string $state, int $user): string {
    $cz = explode('.', $state);
    if (count($cz) !== 2 || !hash_equals(evk_gdrive_state_sig($cz[0]), $cz[1])) {
        throw new \RuntimeException('Podpis powrotu z Google się nie zgadza. Połącz jeszcze raz.');
    }
    $d = json_decode((string) base64_decode(strtr($cz[0], '-_', '+/')), true);
    $n = is_array($d) ? (string) ($d['n'] ?? '') : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $n)) throw new \RuntimeException('Nieczytelny powrót z Google. Połącz jeszcze raz.');
    $p = get_transient('evk_gdrive_pkce_' . $n);
    delete_transient('evk_gdrive_pkce_' . $n);
    if (!is_array($p) || (int) ($p['user'] ?? 0) !== $user || empty($p['v'])) {
        throw new \RuntimeException('Łączenie wygasło (15 minut) albo zaczął je inny użytkownik. Kliknij „Połącz” jeszcze raz.');
    }
    return (string) $p['v'];
}

/** Żądanie do punktu tokenów Google. Rzuca EVK_Gdrive_Exception z kodem błędu OAuth. */
function evk_gdrive_token_request(array $body): array {
    $c = evk_gdrive_config();
    $body['client_id'] = $c['client_id'];
    if ((string) $c['client_secret'] !== '') $body['client_secret'] = $c['client_secret'];
    $t0 = microtime(true);
    $r = wp_remote_post($c['token'], ['timeout' => 30, 'body' => $body]);
    evk_gdrive_notuj('token (' . wp_parse_url((string) $c['token'], PHP_URL_HOST) . ')', evk_gdrive_pomiar($t0));
    $kto = (string) $c['client_secret'] !== '' ? 'Google' : 'pośrednikiem logowania (' . wp_parse_url((string) $c['token'], PHP_URL_HOST) . ')';
    if (is_wp_error($r)) throw new EVK_Gdrive_Exception('Brak połączenia z ' . $kto . ': ' . $r->get_error_message());
    // Pośrednik bez konfiguracji albo niedostępny — to nie jest odmowa Google i nie rozłącza Dysku.
    if ((int) wp_remote_retrieve_response_code($r) >= 500) {
        throw new EVK_Gdrive_Exception('Chwilowy błąd połączenia z ' . $kto . ' (HTTP ' . wp_remote_retrieve_response_code($r) . '). Spróbuj za chwilę.');
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    if ((int) wp_remote_retrieve_response_code($r) !== 200 || !is_array($j) || empty($j['access_token'])) {
        $kod = is_array($j) ? (string) ($j['error'] ?? '') : '';
        $opis = is_array($j) ? (string) ($j['error_description'] ?? '') : '';
        throw new EVK_Gdrive_Exception(trim('Google odrzucił żądanie tokenu: ' . ($kod ?: 'HTTP ' . wp_remote_retrieve_response_code($r)) . ($opis ? ' (' . $opis . ')' : '')), $kod);
    }
    return $j;
}

/** Adres e-mail konta z id_token (prosto z punktu tokenów przez TLS — bez sprawdzania podpisu). */
function evk_gdrive_id_token_email(string $jwt): string {
    $cz = explode('.', $jwt);
    if (count($cz) < 2) return '';
    $d = json_decode((string) base64_decode(strtr($cz[1], '-_', '+/')), true);
    return is_array($d) ? sanitize_email((string) ($d['email'] ?? '')) : '';
}

/** Wymiana kodu na tokeny, zapis połączenia, folder na Dysku. */
function evk_gdrive_connect_finish(string $kod, string $weryfikator): array {
    $c = evk_gdrive_config();
    $j = evk_gdrive_token_request(['grant_type' => 'authorization_code', 'code' => $kod,
        'redirect_uri' => $c['redirect'], 'code_verifier' => $weryfikator]);
    /* Zgoda w Google jest „ziarnista": można odznaczyć dostęp do Dysku
       i zostawić sam e-mail. Wtedy połączenie niczego by nie wysłało. */
    if (isset($j['scope']) && strpos((string) $j['scope'], 'auth/drive.file') === false) {
        throw new \RuntimeException('W oknie zgody Google nie zaznaczono dostępu do plików na Dysku. Połącz jeszcze raz i zostaw to pole zaznaczone.');
    }
    if (empty($j['refresh_token'])) throw new \RuntimeException('Google nie wydał tokenu odświeżania. Połącz jeszcze raz.');
    evk_gdrive_save([
        'refresh'      => (string) $j['refresh_token'],
        'access'       => (string) $j['access_token'],
        'access_exp'   => time() + (int) ($j['expires_in'] ?? 3600) - 120,
        'email'        => evk_gdrive_id_token_email((string) ($j['id_token'] ?? '')),
        'connected_at' => time(),
        'folder'       => '',
    ]);
    evk_gdrive_folder();
    return evk_gdrive_state();
}

/**
 * Ważny token dostępu — z opcji albo odświeżony. `invalid_grant` (dostęp
 * cofnięty w koncie Google, zmienione hasło, token nieużywany pół roku)
 * rozłącza Dysk i idzie powiadomieniem: kopie przestają się wysyłać, a nikt
 * nie patrzy w panel codziennie.
 */
function evk_gdrive_access_token(bool $odswiez = false): string {
    $s = evk_gdrive_state();
    if (empty($s['refresh'])) throw new \RuntimeException('Dysk Google nie jest połączony.');
    if (!$odswiez && !empty($s['access']) && (int) ($s['access_exp'] ?? 0) > time()) return (string) $s['access'];
    try {
        $j = evk_gdrive_token_request(['grant_type' => 'refresh_token', 'refresh_token' => (string) $s['refresh']]);
    } catch (EVK_Gdrive_Exception $e) {
        if ($e->kod !== 'invalid_grant') throw $e;
        evk_gdrive_forget();
        evk_backup_alert('Dysk Google rozłączony', 'Google cofnął tej stronie dostęp do Dysku (dostęp odebrany w koncie Google, zmienione hasło albo połączenie nieużywane przez pół roku). Kopie nie są wysyłane na Dysk — połącz go jeszcze raz w zakładce Kopie zapasowe.');
        throw new \RuntimeException('Google cofnął dostęp do Dysku — połącz go jeszcze raz.');
    }
    $s['access'] = (string) $j['access_token'];
    $s['access_exp'] = time() + (int) ($j['expires_in'] ?? 3600) - 120;
    evk_gdrive_save($s);
    return $s['access'];
}

// =========================================================================
// POMIAR POŁĄCZEŃ
// =========================================================================

/*
 * Czasy każdego żądania do Google z curl_getinfo (1.229.2). Zgłoszone
 * z evoke.pl: 81,8 MB z Dysku w 4 min 56 s (~0,28 MB/s), lista z Dysku
 * kilkanaście sekund — a z dziennika nie dało się powiedzieć, czy czas idzie
 * na szukanie adresu, łączenie (np. IPv6, które nie odpowiada), TLS, czy na
 * sam transfer. WordPress oddaje curl_getinfo akcją
 * `requests-curl.after_request` (WP_HTTP_Requests_Hooks) — żądania są
 * synchroniczne, więc ostatni zapis należy do żądania, które właśnie wróciło.
 * Czasy curl liczone od startu żądania, narastająco.
 */
add_action('requests-curl.after_request', static function ($naglowki, $info = null): void {
    if (is_array($info)) $GLOBALS['evk_gdrive_curl'] = $info;
}, 10, 2);

/** Pomiar żądania, które trwało od $t0: czas całkowity i — z curl — rozbicie. */
function evk_gdrive_pomiar(float $t0): array {
    $m = ['czas' => microtime(true) - $t0];
    $i = $GLOBALS['evk_gdrive_curl'] ?? null;
    $GLOBALS['evk_gdrive_curl'] = null;
    if (!is_array($i)) return $m;
    return $m + [
        'dns' => (float) ($i['namelookup_time'] ?? 0), 'tcp' => (float) ($i['connect_time'] ?? 0),
        'tls' => (float) ($i['appconnect_time'] ?? 0), 'pierwszy' => (float) ($i['starttransfer_time'] ?? 0),
        'curl' => (float) ($i['total_time'] ?? 0), 'pobrane' => (int) ($i['size_download'] ?? 0),
        'wyslane' => (int) ($i['size_upload'] ?? 0), 'przekierowania' => (int) ($i['redirect_count'] ?? 0),
        'ip' => (string) ($i['primary_ip'] ?? ''),
    ];
}

/** Sekundy po polsku: 0,42 s. */
function evk_gdrive_s(float $s): string {
    return str_replace('.', ',', sprintf($s < 10 ? '%.2f' : '%.1f', $s)) . ' s';
}

/** Jedna linia o żądaniu: ile, w jakim czasie, z jaką prędkością, gdzie szedł czas, dokąd. */
function evk_gdrive_pomiar_opis(array $m): string {
    $bajty = max((int) ($m['pobrane'] ?? 0), (int) ($m['wyslane'] ?? 0));
    $t = (float) $m['czas'];
    $opis = ($bajty >= 65536 ? evk_backup_bytes_label((float) $bajty) . ' w ' : '') . evk_gdrive_s($t);
    if ($bajty >= 65536 && $t > 0) {
        $opis .= ' (' . str_replace('.', ',', sprintf('%.2f', $bajty / 1048576 / $t)) . ' MB/s)';
    }
    if (!isset($m['dns'])) return $opis . ' · bez rozbicia (transport HTTP inny niż curl)';
    $opis .= ' · od startu: DNS ' . evk_gdrive_s($m['dns']) . ', połączenie ' . evk_gdrive_s($m['tcp'])
        . ', TLS ' . evk_gdrive_s($m['tls']) . ', pierwszy bajt ' . evk_gdrive_s($m['pierwszy']);
    if ($m['przekierowania'] > 0) $opis .= ' · przekierowań: ' . $m['przekierowania'];
    if ($m['ip'] !== '') $opis .= ' · ' . $m['ip'] . (strpos($m['ip'], ':') !== false ? ' (IPv6)' : ' (IPv4)');
    return $opis;
}

/** Żądania ostatniej operacji (lista z Dysku) — do pokazania w panelu. */
function evk_gdrive_notuj(string $co, array $m): void {
    $GLOBALS['evk_gdrive_czasy'][] = ['co' => $co, 'czas' => (float) $m['czas'], 'opis' => evk_gdrive_pomiar_opis($m)];
}

/**
 * Zapis pomiaru żądania z danymi (kawałek wysyłki, żądanie pobierania) do
 * sumy w stanie zadania i — dla pierwszych pięciu i co dwudziestego — do
 * dziennika (trzyma 300 linii).
 *
 * W 1.229.2 wielkość kawałka dobierała się tu do prędkości (~2,5 s na
 * żądanie). Pomiar z evoke.pl to obalił: każde pobranie z Dysku czeka ~29 s
 * na pierwszy bajt NIEZALEŻNIE od wielkości (1 MB, 256 KB i 8 MB — tyle
 * samo), więc mniejsze kawałki mnożyły tylko czekanie (256 KB × ~330 żądań).
 * Koszt jest na żądanie — pobieranie idzie teraz strumieniem, jedno żądanie
 * na krok (evk_gdrive_stream), a wysyłka stałymi 8 MB jak w 1.229.1.
 */
function evk_gdrive_zapisz_pomiar(array $job, int $bajty, array $m, string $dopisek = ''): array {
    $p = $job['state']['pomiar'] ?? ['n' => 0, 'bajty' => 0, 'czas' => 0.0, 'dns' => 0.0, 'tcp' => 0.0, 'tls' => 0.0, 'pierwszy' => 0.0, 'z_curl' => 0, 'ip' => []];
    $p['n']++;
    $p['bajty'] += $bajty;
    $p['czas'] += (float) $m['czas'];
    if (isset($m['dns'])) {
        $p['z_curl']++;
        foreach (['dns', 'tcp', 'tls', 'pierwszy'] as $k) $p[$k] += (float) $m[$k];
        if ($m['ip'] !== '' && !in_array($m['ip'], $p['ip'], true) && count($p['ip']) < 5) $p['ip'][] = $m['ip'];
    }
    $job['state']['pomiar'] = $p;
    if ($p['n'] <= 5 || $p['n'] % 20 === 0) {
        evk_backup_job_log($job['id'], sprintf('Żądanie %d: %s%s.', $p['n'], evk_gdrive_pomiar_opis($m), $dopisek));
    }
    return $job;
}

/** Podsumowanie pomiaru na koniec wysyłki albo pobierania. */
function evk_gdrive_pomiar_podsumowanie(array $job): void {
    $p = $job['state']['pomiar'] ?? null;
    if (!$p || !$p['n']) return;
    $opis = sprintf('Pomiar: %d żądań, %s w %s, średnio %s MB/s', $p['n'], evk_backup_bytes_label((float) $p['bajty']),
        evk_gdrive_s((float) $p['czas']), str_replace('.', ',', sprintf('%.2f', $p['czas'] > 0 ? $p['bajty'] / 1048576 / $p['czas'] : 0)));
    if ($p['z_curl']) {
        $sr = static function ($k) use ($p) { return evk_gdrive_s($p[$k] / $p['z_curl']); };
        $opis .= '; na żądanie średnio od startu: DNS ' . $sr('dns') . ', połączenie ' . $sr('tcp') . ', TLS ' . $sr('tls')
            . ', pierwszy bajt ' . $sr('pierwszy') . '; adresy: ' . implode(', ', array_map(static function ($ip) {
                return $ip . (strpos($ip, ':') !== false ? ' (IPv6)' : ' (IPv4)');
            }, $p['ip']));
    }
    evk_backup_job_log($job['id'], $opis . '.');
}

// =========================================================================
// API DYSKU
// =========================================================================

/**
 * Żądanie do API z tokenem. 401 → token odświeżony i jedna powtórka.
 * Oddaje {code, body, json, pomiar, range, location}; błąd sieci rzuca.
 */
function evk_gdrive_api(string $metoda, string $url, array $args = [], bool $powtorka = true): array {
    $args += ['timeout' => 60, 'headers' => []];
    $args['method'] = $metoda;
    $args['headers']['Authorization'] = 'Bearer ' . evk_gdrive_access_token();
    $t0 = microtime(true);
    $r = wp_remote_request($url, $args);
    $pomiar = evk_gdrive_pomiar($t0);
    if (is_wp_error($r)) throw new \RuntimeException('Brak połączenia z Dyskiem Google: ' . $r->get_error_message()
        . ' (po ' . evk_gdrive_s($pomiar['czas']) . ')');
    $kod = (int) wp_remote_retrieve_response_code($r);
    if ($kod === 401 && $powtorka) {
        evk_gdrive_access_token(true);
        return evk_gdrive_api($metoda, $url, $args, false);
    }
    $body = (string) wp_remote_retrieve_body($r);
    evk_gdrive_notuj($metoda . ' ' . (string) wp_parse_url($url, PHP_URL_PATH), $pomiar);
    return ['code' => $kod, 'body' => $body, 'json' => json_decode($body, true), 'pomiar' => $pomiar,
            'range' => (string) wp_remote_retrieve_header($r, 'range'), 'location' => (string) wp_remote_retrieve_header($r, 'location')];
}

/** Opis błędu API po polsku, z treścią od Google. */
function evk_gdrive_error(array $r, string $co): string {
    $msg = is_array($r['json']) ? (string) ($r['json']['error']['message'] ?? '') : '';
    return 'Dysk Google, ' . $co . ': ' . ($msg !== '' ? $msg . ' (HTTP ' . $r['code'] . ')' : 'HTTP ' . $r['code']);
}

/** Jak evk_gdrive_api, ale wszystko poza 2xx rzuca. */
function evk_gdrive_api_ok(string $metoda, string $url, array $args, string $co): array {
    $r = evk_gdrive_api($metoda, $url, $args);
    if ($r['code'] < 200 || $r['code'] > 299) throw new \RuntimeException(evk_gdrive_error($r, $co));
    return $r;
}

/** Chwilowy błąd Google, po którym warto spróbować jeszcze raz. */
function evk_gdrive_transient_code(int $kod): bool {
    return $kod === 429 || $kod >= 500;
}

function evk_gdrive_json_args(array $dane): array {
    return ['headers' => ['Content-Type' => 'application/json; charset=UTF-8'], 'body' => (string) wp_json_encode($dane)];
}

/** Wartość do zapytania `q` — w apostrofach, z ucieczkami. */
function evk_gdrive_q(string $v): string {
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
}

/**
 * Folder tej strony na Dysku — zapisany, odnaleziony (ponowne połączenie tego
 * samego konta: `drive.file` widzi pliki utworzone wcześniej przez tę
 * aplikację) albo nowy.
 */
function evk_gdrive_folder(): string {
    $c = evk_gdrive_config();
    $s = evk_gdrive_state();
    if (!empty($s['folder'])) {
        $r = evk_gdrive_api('GET', $c['api'] . '/files/' . rawurlencode((string) $s['folder']) . '?fields=id,trashed');
        if ($r['code'] === 200 && empty($r['json']['trashed'])) return (string) $s['folder'];
        if ($r['code'] !== 200 && $r['code'] !== 404) throw new \RuntimeException(evk_gdrive_error($r, 'sprawdzenie folderu'));
    }
    $klucz = evk_gdrive_site_key();
    $q = 'mimeType=' . evk_gdrive_q(EVK_GDRIVE_FOLDER_MIME) . ' and trashed=false and appProperties has { key=\'evk_site\' and value='
        . evk_gdrive_q($klucz) . ' }';
    $r = evk_gdrive_api_ok('GET', $c['api'] . '/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 10, 'spaces' => 'drive']),
        [], 'szukanie folderu');
    $id = (string) ($r['json']['files'][0]['id'] ?? '');
    if ($id === '') {
        $r = evk_gdrive_api_ok('POST', $c['api'] . '/files?fields=id', evk_gdrive_json_args([
            'name' => 'Evoke ONE — ' . $klucz, 'mimeType' => EVK_GDRIVE_FOLDER_MIME,
            'appProperties' => ['evk' => 'folder', 'evk_site' => $klucz],
        ]), 'tworzenie folderu');
        $id = (string) ($r['json']['id'] ?? '');
        if ($id === '') throw new \RuntimeException('Dysk Google nie oddał identyfikatora nowego folderu.');
    }
    $s = evk_gdrive_state();
    $s['folder'] = $id;
    evk_gdrive_save($s);
    return $id;
}

/**
 * Kopie na Dysku — wszystkie, które ta aplikacja wysłała z tego konta, także
 * z innych stron (przenosiny: nowy serwer łączy ten sam Dysk i widzi kopie
 * starego). Od najnowszej.
 */
function evk_gdrive_list_backups(): array {
    $c = evk_gdrive_config();
    $pliki = [];
    $strona = '';
    for ($i = 0; $i < 20; $i++) {
        $zap = ['q' => "appProperties has { key='evk' and value='backup' } and trashed=false", 'pageSize' => 100, 'spaces' => 'drive',
                'fields' => 'nextPageToken,files(id,name,size,createdTime,appProperties,parents)'];
        if ($strona !== '') $zap['pageToken'] = $strona;
        $r = evk_gdrive_api_ok('GET', $c['api'] . '/files?' . http_build_query($zap), [], 'lista kopii');
        foreach ((array) ($r['json']['files'] ?? []) as $f) {
            $a = (array) ($f['appProperties'] ?? []);
            $pliki[] = [
                'id' => (string) $f['id'], 'name' => (string) $f['name'], 'size' => (int) ($f['size'] ?? 0),
                'created' => (int) ($a['created'] ?? 0) ?: (int) strtotime((string) ($f['createdTime'] ?? '')),
                'site' => (string) ($a['evk_site'] ?? ''), 'source' => (string) ($a['source'] ?? ''),
                'pinned' => ($a['pinned'] ?? '') === '1', 'db_rows' => (int) ($a['db_rows'] ?? 0), 'files' => (int) ($a['files'] ?? 0),
                'parents' => (array) ($f['parents'] ?? []),
            ];
        }
        $strona = (string) ($r['json']['nextPageToken'] ?? '');
        if ($strona === '') break;
    }
    usort($pliki, static function ($a, $b) { return $b['created'] <=> $a['created']; });
    return $pliki;
}

/** Zajęte i dostępne miejsce na Dysku (bajty; limit 0 = bez limitu). null, gdy Google nie odpowie. */
function evk_gdrive_quota(): ?array {
    $c = evk_gdrive_config();
    $r = evk_gdrive_api('GET', $c['api'] . '/about?fields=storageQuota(limit,usage)');
    if ($r['code'] !== 200) return null;
    return ['usage' => (float) ($r['json']['storageQuota']['usage'] ?? 0), 'limit' => (float) ($r['json']['storageQuota']['limit'] ?? 0)];
}

/**
 * Retencja na Dysku: kopie z folderu TEJ strony starsze niż N dni znikają
 * (na stałe, nie do kosza — kosz dalej zajmuje miejsce). Przypięte zostają,
 * $zostaw (właśnie wysłana) też. Oddaje nazwy usuniętych.
 */
function evk_gdrive_retention(string $zostaw = ''): array {
    $dni = (int) evk_backup_get_settings()['gdrive_retention_days'];
    $granica = time() - $dni * DAY_IN_SECONDS;
    $folder = (string) (evk_gdrive_state()['folder'] ?? '');
    $c = evk_gdrive_config();
    $usuniete = [];
    foreach (evk_gdrive_list_backups() as $f) {
        if ($f['id'] === $zostaw || $f['pinned'] || !in_array($folder, $f['parents'], true)) continue;
        if (!$f['created'] || $f['created'] >= $granica) continue;
        $r = evk_gdrive_api('DELETE', $c['api'] . '/files/' . rawurlencode($f['id']));
        if ($r['code'] === 204 || $r['code'] === 200 || $r['code'] === 404) $usuniete[] = $f['name'];
    }
    return $usuniete;
}

/** Przypięcie kopii lokalnej idzie też na Dysk — retencja Dysku go szanuje. */
add_action('evk_backup_pinned', static function (string $nazwa, bool $przypieta, array $meta): void {
    if (empty($meta['drive_id']) || !evk_gdrive_connected()) return;
    try {
        $c = evk_gdrive_config();
        evk_gdrive_api('PATCH', $c['api'] . '/files/' . rawurlencode((string) $meta['drive_id']) . '?fields=id',
            evk_gdrive_json_args(['appProperties' => ['pinned' => $przypieta ? '1' : '0']]));
    } catch (\RuntimeException $e) {
        // Bez połączenia przypięcie zostaje lokalne — lista i tak działa.
    }
}, 10, 3);

// =========================================================================
// WYSYŁKA — zadanie `upload`
// =========================================================================

/** Nowa wysyłka kopii $archiwum na Dysk. Zwraca id zadania albo WP_Error. */
function evk_gdrive_upload_start(string $archiwum, string $zrodlo = 'manual') {
    if (!evk_gdrive_connected()) return new WP_Error('evk_gdrive_off', 'Dysk Google nie jest połączony.');
    if (!evk_backup_archive_path($archiwum)) return new WP_Error('evk_backup_archive', 'Nie ma takiej kopii.');
    if (evk_backup_job_active()) return new WP_Error('evk_backup_busy', 'Inna kopia, wysyłka albo przywracanie już trwa.');
    $id = evk_backup_job_create('upload', $zrodlo, 'queued', 'u_session', ['archive' => $archiwum]);
    if (is_wp_error($id)) return $id;
    evk_backup_job_update($id, ['archive' => $archiwum]);
    evk_backup_job_log($id, 'Wysyłka na Dysk Google: ' . $archiwum . '.');
    evk_backup_schedule($id, 0);
    evk_backup_kick($id);
    return $id;
}

function evk_gdrive_phase(array $job, float $deadline): array {
    switch ($job['phase']) {
        case 'u_session': return evk_gdrive_phase_session($job);
        case 'u_send':    return evk_gdrive_phase_send($job, $deadline);
        case 'u_finish':  return evk_gdrive_phase_finish($job);
        case 'd_fetch':   return evk_gdrive_phase_fetch($job, $deadline);
    }
    throw new \RuntimeException('Nieznana faza zadania Dysku: ' . $job['phase']);
}

/**
 * Chwilowy błąd Google: odczekanie i ta sama porcja jeszcze raz — do
 * EVK_GDRIVE_MAX_RETRIES razy z rzędu.
 */
function evk_gdrive_retry(array $job, array $r, string $co, float $deadline): array {
    $n = (int) ($job['state']['retries'] ?? 0) + 1;
    if ($n > EVK_GDRIVE_MAX_RETRIES) throw new \RuntimeException(evk_gdrive_error($r, $co) . ' — ' . EVK_GDRIVE_MAX_RETRIES . ' prób z rzędu.');
    $job['state']['retries'] = $n;
    evk_backup_job_log($job['id'], evk_gdrive_error($r, $co) . ' — ponawiam (' . $n . ').');
    $czekaj = min((float) $n, max(0.0, $deadline - microtime(true)));
    if ($czekaj > 0) usleep((int) ($czekaj * 1e6));
    return $job;
}

function evk_gdrive_phase_session(array $job): array {
    $archiwum = (string) $job['state']['archive'];
    $zip = evk_backup_archive_path($archiwum);
    if (!$zip) throw new \RuntimeException('Kopia zniknęła z katalogu kopii: ' . $archiwum);
    $meta = is_file($zip . '.json') ? (json_decode((string) file_get_contents($zip . '.json'), true) ?: []) : [];
    $rozmiar = (int) filesize($zip);
    $folder = evk_gdrive_folder();
    $c = evk_gdrive_config();
    // appProperties: najwyżej 124 bajty na parę klucz+wartość (limit Google).
    $wlasciwosci = [
        'evk' => 'backup', 'evk_site' => evk_gdrive_site_key(),
        'created' => (string) (int) ($meta['created_at'] ?? filemtime($zip)),
        'source' => (string) ($meta['source'] ?? 'upload'), 'pinned' => !empty($meta['pinned']) ? '1' : '0',
        'db_rows' => (string) (int) ($meta['db_rows'] ?? 0), 'files' => (string) (int) ($meta['files'] ?? 0),
    ];
    $r = evk_gdrive_api('POST', $c['upload'] . '/files?uploadType=resumable&fields=id', [
        'headers' => ['Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => 'application/zip',
                      'X-Upload-Content-Length' => (string) $rozmiar],
        'body' => (string) wp_json_encode(['name' => $archiwum, 'parents' => [$folder], 'appProperties' => $wlasciwosci]),
    ]);
    if (evk_gdrive_transient_code($r['code'])) return evk_gdrive_retry($job, $r, 'otwarcie wysyłki', microtime(true) + 1);
    if ($r['code'] !== 200 || $r['location'] === '') throw new \RuntimeException(evk_gdrive_error($r, 'otwarcie wysyłki'));
    $job['state']['retries'] = 0;
    $job['state']['u'] = ['session' => $r['location'], 'pos' => 0, 'size' => $rozmiar, 'tick' => $job['ticks']];
    $job['progress_total'] = max(1, $rozmiar);
    $job['progress_done'] = 0;
    $job['phase'] = 'u_send';
    evk_backup_job_log($job['id'], sprintf('Sesja wysyłki otwarta: %s do folderu %s.', evk_backup_bytes_label((float) $rozmiar), 'Evoke ONE — ' . evk_gdrive_site_key()));
    return $job;
}

/** Odpowiedź 308 → pozycja, od której Google czeka na dane (nagłówek `Range: bytes=0-N`). */
function evk_gdrive_range_next(string $range): int {
    return preg_match('/bytes=0-(\d+)/', $range, $m) ? (int) $m[1] + 1 : 0;
}

/**
 * Jeden kawałek. W PIERWSZEJ porcji każdego kroku najpierw pytanie o stan
 * (`bytes * /rozmiar`): poprzedni krok mógł zginąć po wysłaniu kawałka, a przed
 * zapisaniem pozycji — Google wie lepiej, ile ma.
 */
function evk_gdrive_phase_send(array $job, float $deadline): array {
    $u = $job['state']['u'];
    $zip = evk_backup_archive_path((string) $job['state']['archive']);
    if (!$zip) throw new \RuntimeException('Kopia zniknęła z katalogu kopii w trakcie wysyłki.');
    $naglowki = static function (array $h = []) { return ['timeout' => 120, 'headers' => $h]; };

    if ((int) ($u['tick'] ?? -1) !== $job['ticks']) {
        $r = evk_gdrive_api('PUT', $u['session'], $naglowki(['Content-Range' => 'bytes */' . $u['size'], 'Content-Length' => '0']) + ['body' => '']);
        if ($r['code'] === 404 || $r['code'] === 410) return evk_gdrive_restart($job, 'Sesja wysyłki wygasła — zaczynam od nowa.');
        if ($r['code'] === 200 || $r['code'] === 201) return evk_gdrive_sent($job, $r);
        if (evk_gdrive_transient_code($r['code'])) return evk_gdrive_retry($job, $r, 'stan wysyłki', $deadline);
        if ($r['code'] !== 308) throw new \RuntimeException(evk_gdrive_error($r, 'stan wysyłki'));
        $juz = evk_gdrive_range_next($r['range']);
        if ($juz !== (int) $u['pos']) evk_backup_job_log($job['id'], sprintf('Wznowienie wysyłki: Google ma %s, zapisane było %s.',
            evk_backup_bytes_label((float) $juz), evk_backup_bytes_label((float) $u['pos'])));
        $u['pos'] = $juz;
        $u['tick'] = $job['ticks'];
    }

    $dl = min(evk_gdrive_chunk(), (int) $u['size'] - (int) $u['pos']);
    $fh = fopen($zip, 'rb');
    if (!$fh) throw new \RuntimeException('Nie można odczytać kopii do wysyłki.');
    fseek($fh, (int) $u['pos']);
    $dane = $dl > 0 ? (string) fread($fh, $dl) : '';
    fclose($fh);
    if (strlen($dane) !== $dl) throw new \RuntimeException('Kopia zmieniła się w trakcie wysyłki (krótszy odczyt).');

    $r = evk_gdrive_api('PUT', $u['session'], $naglowki([
        'Content-Range' => 'bytes ' . $u['pos'] . '-' . ((int) $u['pos'] + $dl - 1) . '/' . $u['size'],
        'Content-Type' => 'application/zip',
    ]) + ['body' => $dane]);
    unset($dane);
    if ($r['code'] === 200 || $r['code'] === 201) {
        $job['state']['u'] = $u;
        return evk_gdrive_sent(evk_gdrive_zapisz_pomiar($job, $dl, $r['pomiar']), $r);
    }
    if ($r['code'] === 404 || $r['code'] === 410) return evk_gdrive_restart($job, 'Sesja wysyłki wygasła — zaczynam od nowa.');
    if (evk_gdrive_transient_code($r['code'])) {
        // Po chwilowym błędzie pozycję trzeba odczytać od Google — kawałek mógł dojść.
        $u['tick'] = -1;
        $job['state']['u'] = $u;
        return evk_gdrive_retry($job, $r, 'kawałek kopii', $deadline);
    }
    if ($r['code'] !== 308) throw new \RuntimeException(evk_gdrive_error($r, 'kawałek kopii'));
    $u['pos'] = evk_gdrive_range_next($r['range']);
    $job['state']['retries'] = 0;
    $job['state']['u'] = $u;
    $job['progress_done'] = (int) $u['pos'];
    return evk_gdrive_zapisz_pomiar($job, $dl, $r['pomiar']);
}

function evk_gdrive_restart(array $job, string $powod): array {
    evk_backup_job_log($job['id'], $powod);
    $n = (int) ($job['state']['restarts'] ?? 0) + 1;
    if ($n > 3) throw new \RuntimeException('Dysk Google trzy razy z rzędu unieważnił sesję wysyłki.');
    $job['state']['restarts'] = $n;
    unset($job['state']['u']);
    $job['phase'] = 'u_session';
    return $job;
}

function evk_gdrive_sent(array $job, array $r): array {
    $id = (string) ($r['json']['id'] ?? '');
    if ($id === '') throw new \RuntimeException('Dysk Google przyjął plik, ale nie oddał jego identyfikatora.');
    $job['state']['file_id'] = $id;
    $job['state']['retries'] = 0;
    $job['progress_done'] = $job['progress_total'];
    $job['phase'] = 'u_finish';
    return $job;
}

function evk_gdrive_phase_finish(array $job): array {
    $archiwum = (string) $job['state']['archive'];
    $zip = evk_backup_archive_path($archiwum);
    if ($zip) {
        $meta = is_file($zip . '.json') ? (json_decode((string) file_get_contents($zip . '.json'), true) ?: []) : [];
        $meta = $meta + ['archive' => $archiwum, 'created_at' => (int) filemtime($zip), 'source' => 'upload', 'pinned' => false];
        $meta['drive_id'] = (string) $job['state']['file_id'];
        $meta['drive_at'] = time();
        evk_backup_meta_write($zip, $meta);
    }
    evk_backup_job_log($job['id'], 'Kopia na Dysku Google: ' . $archiwum . ' (' . evk_backup_bytes_label((float) $job['progress_total']) . ').');
    evk_gdrive_pomiar_podsumowanie($job);
    try {
        $usuniete = evk_gdrive_retention((string) $job['state']['file_id']);
        if ($usuniete) evk_backup_job_log($job['id'], 'Retencja na Dysku: usunięte kopie starsze niż '
            . (int) evk_backup_get_settings()['gdrive_retention_days'] . ' dni: ' . implode(', ', $usuniete) . '.');
    } catch (\RuntimeException $e) {
        // Kopia już jest na Dysku — nieudane porządki nie czynią wysyłki nieudaną.
        evk_backup_job_log($job['id'], 'Retencja na Dysku nie powiodła się: ' . $e->getMessage());
    }
    return evk_gdrive_job_done($job);
}

function evk_gdrive_job_done(array $job): array {
    $job['status'] = 'done';
    $job['phase'] = 'done';
    $job['progress_done'] = $job['progress_total'];
    evk_backup_job_update($job['id'], [
        'status' => 'done', 'phase' => 'done', 'finished_at' => time(), 'state' => $job['state'],
        'progress_done' => $job['progress_done'], 'lock_until' => 0, 'archive' => $job['archive'] ?? '',
    ]);
    do_action('evk_backup_gdrive_done', $job);
    return $job;
}

// =========================================================================
// POBIERANIE — zadanie `download`
// =========================================================================

/** Kopia lokalna pobrana wcześniej z pliku Dysku $id (albo z niego wysłana). */
function evk_gdrive_local_for(string $id): string {
    foreach (evk_backup_list_archives() as $k) {
        if (($k['drive_id'] ?? '') === $id) return (string) $k['archive'];
    }
    return '';
}

function evk_gdrive_download_part(string $id): string {
    return evk_backup_dir() . '/.pobieranie-' . sha1($id) . '.part';
}

/** Nowe pobieranie pliku $id z Dysku do katalogu kopii. Zwraca id zadania albo WP_Error. */
function evk_gdrive_download_start(string $plik) {
    if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $plik)) return new WP_Error('evk_gdrive_id', 'Zły identyfikator pliku.');
    if (!evk_gdrive_connected()) return new WP_Error('evk_gdrive_off', 'Dysk Google nie jest połączony.');
    if (evk_backup_job_active()) return new WP_Error('evk_backup_busy', 'Inna kopia, wysyłka albo przywracanie już trwa.');
    try {
        $c = evk_gdrive_config();
        $r = evk_gdrive_api_ok('GET', $c['api'] . '/files/' . rawurlencode($plik) . '?fields=id,name,size,appProperties,trashed', [], 'odczyt pliku');
    } catch (\RuntimeException $e) {
        return new WP_Error('evk_gdrive', $e->getMessage());
    }
    $f = (array) $r['json'];
    if (($f['appProperties']['evk'] ?? '') !== 'backup' || !empty($f['trashed'])) return new WP_Error('evk_gdrive_nie', 'To nie jest kopia wysłana przez Evoke ONE.');
    if (!evk_backup_ensure_dir(evk_backup_dir())) return new WP_Error('evk_backup_dir', 'Nie można zapisać w katalogu kopii.');
    $rozmiar = (int) ($f['size'] ?? 0);
    $wolne = apply_filters('evk_backup_upload_free_space', function_exists('disk_free_space') ? @disk_free_space(evk_backup_dir()) : false);
    if ($wolne !== false && (float) $wolne < $rozmiar + 16 * 1048576) {
        return new WP_Error('evk_backup_space', sprintf('Za mało miejsca na serwerze: kopia ma %s, wolne %s.',
            evk_backup_bytes_label((float) $rozmiar), evk_backup_bytes_label((float) $wolne)));
    }
    $id = evk_backup_job_create('download', 'gdrive', 'queued', 'd_fetch',
        ['file' => $plik, 'name' => (string) $f['name'], 'size' => $rozmiar, 'app' => (array) ($f['appProperties'] ?? [])]);
    if (is_wp_error($id)) return $id;
    evk_backup_job_update($id, ['progress_total' => max(1, $rozmiar)]);
    evk_backup_job_log($id, sprintf('Pobieranie z Dysku Google: %s (%s).', (string) $f['name'], evk_backup_bytes_label((float) $rozmiar)));
    evk_backup_schedule($id, 0);
    evk_backup_kick($id);
    return $id;
}

/**
 * Pobieranie z Dysku STRUMIENIEM (1.229.3): jedno żądanie `Range: bytes=N-`
 * na krok, dane dopisywane do części w trakcie, postęp zapisywany co sekundę.
 * Zmierzone na evoke.pl: każde żądanie pobrania czeka ~29 s na pierwszy bajt,
 * niezależnie od wielkości — płacimy to raz na krok, a nie raz na kawałek.
 *
 * Pozycja wznowienia to rozmiar części na dysku: dopisek od jej końca jest
 * zawsze poprawnym przedłużeniem, więc krok ubity w połowie nic nie psuje.
 * Bez curl w PHP — dawna droga: zakresy po 8 MB przez WordPress.
 */
function evk_gdrive_phase_fetch(array $job, float $deadline): array {
    $s = $job['state'];
    $part = evk_gdrive_download_part((string) $s['file']);
    $rozmiar = (int) $s['size'];
    clearstatcache(true, $part);
    $jest = is_file($part) ? (int) filesize($part) : 0;
    if ($jest > $rozmiar) { @unlink($part); $jest = 0; }

    if ($jest < $rozmiar) {
        $c = evk_gdrive_config();
        $url = $c['api'] . '/files/' . rawurlencode((string) $s['file']) . '?alt=media';
        if (function_exists('curl_init') && apply_filters('evk_backup_gdrive_stream', true)) {
            $id = (int) $job['id'];
            $termin = (float) ($GLOBALS['evk_backup_termin_kroku'] ?? $deadline);
            $w = evk_gdrive_stream($url, $part, $jest, $termin, static function (int $pozycja) use ($id): bool {
                $t = time();
                evk_backup_job_update($id, ['progress_done' => $pozycja, 'heartbeat' => $t,
                    'lock_until' => $t + (int) ceil(EVK_BACKUP_BUDGET_MAX / 1000) + EVK_BACKUP_LOCK_MARGIN]);
                do_action('evk_backup_gdrive_postep', $id, $pozycja);
                return evk_backup_job_status($id) !== 'cancelled';
            });
            if ($w['kod'] === 401) { evk_gdrive_access_token(true); return $job; }
            if ($w['dopisane'] === 0 && ($w['kod'] === 0 || evk_gdrive_transient_code($w['kod']))) {
                return evk_gdrive_retry($job, ['code' => $w['kod'], 'json' => json_decode($w['tresc'], true)],
                    'pobieranie' . ($w['curl'] !== '' ? ' (' . $w['curl'] . ')' : ''), $deadline);
            }
            if ($w['kod'] !== 206 && !($w['kod'] === 200 && $jest === 0)) {
                throw new \RuntimeException(evk_gdrive_error(['code' => $w['kod'], 'json' => json_decode($w['tresc'], true)], 'pobieranie')
                    . ($w['kod'] === 200 ? ' — serwer pominął zakres (Range).' : ''));
            }
            $jest += $w['dopisane'];
            $job['state']['retries'] = 0;
            $job = evk_gdrive_zapisz_pomiar($job, $w['dopisane'], $w['pomiar'], sprintf(' · od %s%s · kompresja: %s%s',
                evk_backup_bytes_label((float) ($jest - $w['dopisane'])), evk_gdrive_po_pierwszym($w), $w['kodowanie'] ?: 'brak',
                $w['przerwane'] ? ' · przerwane na końcu kroku' : ($w['curl'] !== '' ? ' · zerwane: ' . $w['curl'] : '')));
        } else {
            $dl = min(evk_gdrive_chunk(), $rozmiar - $jest);
            $r = evk_gdrive_api('GET', $url, ['timeout' => 120, 'headers' => ['Range' => 'bytes=' . $jest . '-' . ($jest + $dl - 1)]]);
            if (evk_gdrive_transient_code($r['code'])) return evk_gdrive_retry($job, $r, 'pobieranie', $deadline);
            $dobre = $r['code'] === 206 || ($r['code'] === 200 && $jest === 0 && strlen($r['body']) === $rozmiar);
            if (!$dobre) throw new \RuntimeException(evk_gdrive_error($r, 'pobieranie'));
            if (strlen($r['body']) !== $dl && !($r['code'] === 200 && strlen($r['body']) === $rozmiar)) {
                throw new \RuntimeException(sprintf('Dysk Google oddał %d bajtów zamiast %d.', strlen($r['body']), $dl));
            }
            if (file_put_contents($part, $r['body'], FILE_APPEND | LOCK_EX) !== strlen($r['body'])) {
                throw new \RuntimeException('Zapis pobieranej kopii nie powiódł się (brak miejsca na dysku?).');
            }
            $jest += strlen($r['body']);
            $job['state']['retries'] = 0;
            $job = evk_gdrive_zapisz_pomiar($job, strlen($r['body']), $r['pomiar']);
        }
    }
    $job['progress_done'] = $jest;
    if ($jest < $rozmiar) return $job;

    try {
        evk_restore_read_manifest($part);
    } catch (\RuntimeException $e) {
        @unlink($part);
        throw new \RuntimeException('Plik z Dysku nie jest kopią tej wtyczki: ' . $e->getMessage());
    }
    $nazwa = evk_backup_register_upload($part, (string) $s['name'], 'gdrive', ['drive_id' => (string) $s['file'],
        'pinned' => ($s['app']['pinned'] ?? '') === '1']);
    if ($nazwa === '') throw new \RuntimeException('Nie udało się przenieść pobranej kopii do katalogu kopii.');
    $job['archive'] = $nazwa;
    evk_backup_job_log($job['id'], 'Kopia pobrana z Dysku: ' . $nazwa . '.');
    evk_gdrive_pomiar_podsumowanie($job);
    return evk_gdrive_job_done($job);
}

/** „ · po pierwszym bajcie X MB/s" — prędkość samego transferu, bez czekania na odpowiedź. */
function evk_gdrive_po_pierwszym(array $w): string {
    $m = $w['pomiar'];
    if (!isset($m['pierwszy']) || $w['dopisane'] < 65536) return '';
    $t = max(0.001, (float) $m['curl'] - (float) $m['pierwszy']);
    return ' · po pierwszym bajcie ' . str_replace('.', ',', sprintf('%.2f', $w['dopisane'] / 1048576 / $t)) . ' MB/s';
}

/**
 * Jedno żądanie GET `Range: bytes=$od-` z curl wprost (nie przez WordPress:
 * potrzebny zapis i postęp W TRAKCIE żądania oraz przerwanie w trakcie —
 * Requests tego nie daje, a przy błędzie zapisu powtarza całe żądanie).
 *
 *   - dane idą do części tylko przy 206 (albo 200 od początku pliku);
 *     każda inna odpowiedź (błąd JSON) zostaje w pamięci na komunikat,
 *   - $postep(pozycja) co ~1 s; false = przerwij (anulowanie z panelu),
 *   - przerwanie po terminie kroku, ale NIE przed pierwszym bajtem i nie
 *     wcześniej niż po transferze tak długim jak czekanie na odpowiedź
 *     (min. 5 s) — czekanie ma się zwrócić; twardy limit 300 s,
 *   - bez Accept-Encoding: ZIP jest już skompresowany (i sprawdzamy, czy
 *     serwer mimo to coś kompresuje — kodowanie idzie do dziennika).
 *
 * Oddaje {kod, dopisane, tresc, pomiar, przerwane, kodowanie, curl}.
 */
function evk_gdrive_stream(string $url, string $part, int $od, float $termin, callable $postep): array {
    $w = ['kod' => 0, 'dopisane' => 0, 'tresc' => '', 'pomiar' => [], 'przerwane' => false, 'kodowanie' => '', 'curl' => ''];
    $fh = null;
    $t0 = microtime(true);
    $pierwszy = 0.0;
    $ostatni = $t0;
    $ch = curl_init($url);
    $opcje = [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . evk_gdrive_access_token(), 'Range: bytes=' . $od . '-'],
        CURLOPT_USERAGENT => 'WordPress/' . get_bloginfo('version') . '; ' . home_url() . '; Evoke ONE',
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 330,
        CURLOPT_SSL_VERIFYPEER => (bool) apply_filters('https_ssl_verify', true, $url),
        CURLOPT_SSL_VERIFYHOST => apply_filters('https_ssl_verify', true, $url) ? 2 : 0,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linia) use (&$w): int {
            if (stripos($linia, 'content-encoding:') === 0) $w['kodowanie'] = trim(substr($linia, 17));
            return strlen($linia);
        },
        CURLOPT_WRITEFUNCTION => static function ($c, string $dane) use (&$w, &$fh, &$pierwszy, $part, $od): int {
            $kod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            if ($kod === 206 || ($kod === 200 && $od === 0)) {
                if (!$fh) $fh = fopen($part, 'ab');
                if (!$fh || fwrite($fh, $dane) !== strlen($dane)) return 0;   // brak miejsca — przerwij
                if (!$pierwszy) $pierwszy = microtime(true);
                $w['dopisane'] += strlen($dane);
            } elseif (strlen($w['tresc']) < 65536) {
                $w['tresc'] .= $dane;
            }
            return strlen($dane);
        },
        CURLOPT_XFERINFOFUNCTION => static function ($c) use (&$w, &$ostatni, &$pierwszy, $postep, $termin, $t0, $od): int {
            $teraz = microtime(true);
            if ($teraz - $ostatni >= 1.0) {
                $ostatni = $teraz;
                if (!$postep($od + $w['dopisane'])) { $w['przerwane'] = true; return 1; }
            }
            $koniec = $pierwszy ? max($termin, $pierwszy + max(5.0, $pierwszy - $t0)) : 0.0;
            if (($pierwszy && $teraz > $koniec) || $teraz - $t0 > 300) { $w['przerwane'] = true; return 1; }
            return 0;
        },
    ];
    $ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
    if (is_file($ca)) $opcje[CURLOPT_CAINFO] = $ca;
    if (class_exists('WP_HTTP_Proxy')) {
        $proxy = new WP_HTTP_Proxy();
        if ($proxy->is_enabled() && $proxy->send_through_proxy($url)) {
            $opcje[CURLOPT_PROXY] = $proxy->host() . ':' . $proxy->port();
            if ($proxy->use_authentication()) $opcje[CURLOPT_PROXYUSERPWD] = $proxy->authentication();
        }
    }
    curl_setopt_array($ch, $opcje);
    curl_exec($ch);
    $w['kod'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $blad = curl_errno($ch);
    if ($blad && !$w['przerwane']) $w['curl'] = curl_error($ch);
    $GLOBALS['evk_gdrive_curl'] = curl_getinfo($ch);
    curl_close($ch);
    if ($fh) { fflush($fh); fclose($fh); }
    $w['pomiar'] = evk_gdrive_pomiar($t0);
    evk_gdrive_notuj('GET (strumień) ' . (string) wp_parse_url($url, PHP_URL_PATH), $w['pomiar']);
    return $w;
}

/** Sprzątanie po przerwanym zadaniu Dysku: część pobierania. */
function evk_gdrive_cleanup(array $job): void {
    if ($job['type'] === 'download' && !empty($job['state']['file'])) @unlink(evk_gdrive_download_part((string) $job['state']['file']));
}

// =========================================================================
// AUTOMATYCZNIE PO KOPII NOCNEJ; POWIADOMIENIA
// =========================================================================

add_action('evk_backup_done', static function (array $job, array $meta): void {
    if (($job['source'] ?? '') !== 'schedule' || !evk_gdrive_connected()) return;
    if (empty(evk_backup_get_settings()['gdrive_auto_schedule'])) return;
    $id = evk_gdrive_upload_start((string) $meta['archive'], 'schedule');
    if (is_wp_error($id)) {
        evk_backup_alert('Kopia nocna zrobiona, wysyłka na Dysk Google nie ruszyła',
            'Kopia ' . $meta['archive'] . ' jest na serwerze, ale nie trafiła na Dysk: ' . $id->get_error_message());
    }
}, 20, 2);

add_action('evk_backup_failed', static function (array $job, string $powod): void {
    if (($job['type'] ?? '') !== 'upload' || ($job['source'] ?? '') !== 'schedule') return;
    evk_backup_alert('Kopia nocna zrobiona, wysyłka na Dysk Google nie powiodła się',
        'Kopia ' . ($job['state']['archive'] ?? '') . ' jest na serwerze, ale nie trafiła na Dysk: ' . $powod
        . ' Można ją wysłać ręcznie z listy kopii.');
}, 10, 2);

// =========================================================================
// ŻĄDANIA Z PANELU
// =========================================================================

function evk_gdrive_tab_url(array $arg = []): string {
    return add_query_arg(['page' => 'evoke-one', 'tab' => 'backup'] + $arg, admin_url('options-general.php'));
}

/** Komunikat po powrocie z Google — jednorazowy, dla tego użytkownika. */
function evk_gdrive_flash(string $tekst, string $rodzaj): void {
    set_transient('evk_gdrive_msg_' . get_current_user_id(), ['text' => $tekst, 'kind' => $rodzaj], 10 * MINUTE_IN_SECONDS);
}

function evk_gdrive_flash_take(): ?array {
    $k = 'evk_gdrive_msg_' . get_current_user_id();
    $m = get_transient($k);
    if ($m) delete_transient($k);
    return is_array($m) ? $m : null;
}

add_action('admin_post_evk_backup_gdrive_connect', static function (): void {
    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response' => 403]);
    check_admin_referer('evk_backup_gdrive_connect');
    // Adres Google — wp_redirect, nie wp_safe_redirect (ten wpuszcza tylko własny host).
    wp_redirect(evk_gdrive_auth_url(get_current_user_id()));
    exit;
});

add_action('admin_post_evk_backup_gdrive_callback', static function (): void {
    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.', '', ['response' => 403]);
    $blad = sanitize_key(wp_unslash($_GET['error'] ?? ''));
    try {
        if ($blad !== '') {
            throw new \RuntimeException($blad === 'access_denied' ? 'Połączenie anulowane w oknie Google — Dysk nie został podłączony.'
                : 'Google odmówił połączenia: ' . $blad . '.');
        }
        $weryfikator = evk_gdrive_verify_state((string) wp_unslash($_GET['state'] ?? ''), get_current_user_id());
        $kod = (string) wp_unslash($_GET['code'] ?? '');
        if ($kod === '') throw new \RuntimeException('Google nie przekazał kodu połączenia. Połącz jeszcze raz.');
        $s = evk_gdrive_connect_finish($kod, $weryfikator);
        evk_gdrive_flash('Dysk Google połączony' . (!empty($s['email']) ? ': ' . $s['email'] : '') . '. Kopie trafią do folderu „Evoke ONE — ' . evk_gdrive_site_key() . '”.', 'ok');
    } catch (\RuntimeException $e) {
        evk_gdrive_flash($e->getMessage(), 'err');
    }
    wp_safe_redirect(evk_gdrive_tab_url() . '#evk-gdrive');
    exit;
});

/* Powrót z Google do wylogowanej przeglądarki (sesja wygasła w trakcie
   zgody): logowanie i z powrotem tutaj — kod i weryfikator są ważne 15 min. */
add_action('admin_post_nopriv_evk_backup_gdrive_callback', static function (): void {
    $tu = add_query_arg(array_map('rawurlencode', array_map('strval', wp_unslash($_GET))), admin_url('admin-post.php'));
    wp_safe_redirect(wp_login_url($tu));
    exit;
});

add_action('wp_ajax_evk_backup_gdrive_disconnect', static function (): void {
    evk_backup_ajax_guard();
    $s = evk_gdrive_state();
    if (!empty($s['refresh'])) {
        // Cofnięcie w Google — bez czekania na wynik; lokalnie i tak zapominamy.
        wp_remote_post(evk_gdrive_config()['revoke'], ['timeout' => 10, 'body' => ['token' => (string) $s['refresh']]]);
    }
    evk_gdrive_forget();
    wp_send_json_success();
});

/**
 * Lista z Dysku dla panelu, z czasami każdego żądania (zgłoszone: lista
 * wczytywała się kilkanaście sekund). Rdzeń bez wp_send_json — sonda testów
 * woła go wprost.
 */
function evk_gdrive_list_core(): array {
    $GLOBALS['evk_gdrive_czasy'] = [];
    $t0 = microtime(true);
    try {
        $pliki = evk_gdrive_list_backups();
        $q = evk_gdrive_quota();
    } catch (\RuntimeException $e) {
        return ['blad' => $e->getMessage(), 'czasy' => $GLOBALS['evk_gdrive_czasy'], 'razem' => microtime(true) - $t0];
    }
    return ['html' => evk_gdrive_render_list($pliki), 'count' => count($pliki), 'quota' => $q ? evk_gdrive_quota_label($q) : '',
            'czasy' => $GLOBALS['evk_gdrive_czasy'], 'razem' => microtime(true) - $t0,
            'razem_opis' => 'Razem ' . evk_gdrive_s(microtime(true) - $t0)];
}

add_action('wp_ajax_evk_backup_gdrive_list', static function (): void {
    evk_backup_ajax_guard();
    $w = evk_gdrive_list_core();
    if (isset($w['blad'])) wp_send_json_error(['msg' => $w['blad'], 'czasy' => $w['czasy']]);
    wp_send_json_success($w);
});

add_action('wp_ajax_evk_backup_gdrive_upload', static function (): void {
    evk_backup_ajax_guard();
    $id = evk_gdrive_upload_start(sanitize_file_name(wp_unslash($_POST['archive'] ?? '')), 'manual');
    if (is_wp_error($id)) wp_send_json_error(['msg' => $id->get_error_message()]);
    wp_send_json_success(['job' => evk_backup_job_public(evk_backup_job_get($id))]);
});

add_action('wp_ajax_evk_backup_gdrive_download', static function (): void {
    evk_backup_ajax_guard();
    $plik = (string) wp_unslash($_POST['file'] ?? '');
    // Ta kopia już jest na serwerze — od razu okno przywracania, bez pobierania.
    $lokalna = preg_match('/^[A-Za-z0-9_-]{10,200}$/', $plik) ? evk_gdrive_local_for($plik) : '';
    if ($lokalna !== '') wp_send_json_success(['local' => $lokalna]);
    $id = evk_gdrive_download_start($plik);
    if (is_wp_error($id)) wp_send_json_error(['msg' => $id->get_error_message()]);
    wp_send_json_success(['job' => evk_backup_job_public(evk_backup_job_get($id))]);
});

// =========================================================================
// HTML
// =========================================================================

function evk_gdrive_quota_label(array $q): string {
    if ($q['limit'] <= 0) return 'Zajęte na Dysku: ' . evk_backup_bytes_label($q['usage']) . ' (bez limitu).';
    return sprintf('Zajęte na Dysku: %s z %s (wolne %s).', evk_backup_bytes_label($q['usage']), evk_backup_bytes_label($q['limit']),
        evk_backup_bytes_label(max(0.0, $q['limit'] - $q['usage'])));
}

function evk_gdrive_render_list(array $pliki): string {
    if (!$pliki) return '<p class="evo-empty evo-muted" data-evk-gdrive-empty>Na Dysku nie ma jeszcze żadnej kopii.</p>';
    $tu = evk_gdrive_site_key();
    $lokalne = [];
    foreach (evk_backup_list_archives() as $k) if (!empty($k['drive_id'])) $lokalne[(string) $k['drive_id']] = true;
    ob_start();
    ?>
    <div class="evo-tbl-wrap"><table class="evo-table evk-backup-lista evk-gdrive-lista">
        <thead><tr><th>Data</th><th>Strona</th><th>Rozmiar</th><th>Zawartość</th><th class="is-right">Akcje</th></tr></thead>
        <tbody>
        <?php foreach ($pliki as $f): ?>
            <tr data-drive-id="<?php echo esc_attr($f['id']); ?>">
                <td class="evk-backup-data"><?php echo esc_html($f['created'] ? wp_date('Y-m-d H:i', $f['created']) : '—'); ?>
                    <?php if ($f['pinned']): ?><span class="evo-badge" title="Przypięta — retencja Dysku jej nie usuwa">przypięta</span><?php endif; ?>
                    <?php if (isset($lokalne[$f['id']])): ?><span class="evo-badge" data-evk-gdrive-local title="Ta kopia jest też w katalogu kopii tej strony">na serwerze</span><?php endif; ?></td>
                <td data-label="Strona"><?php echo esc_html($f['site'] === $tu ? 'ta strona' : $f['site']); ?>
                    <?php if ($f['source'] !== ''): ?><span class="evo-muted">· <?php echo esc_html(evk_backup_source_label($f['source'])); ?></span><?php endif; ?></td>
                <td data-label="Rozmiar"><?php echo esc_html(evk_backup_bytes_label((float) $f['size'])); ?></td>
                <td data-label="Zawartość" class="evo-muted"><?php echo esc_html(sprintf('%d plików, %d wierszy bazy', $f['files'], $f['db_rows'])); ?></td>
                <td class="is-right evk-backup-akcje">
                    <button type="button" class="button button-small" data-evk-gdrive-restore>Pobierz i przywróć</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
    return (string) ob_get_clean();
}
