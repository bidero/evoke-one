<?php
/**
 * Atrapa Google (OAuth 2.0 + Drive API v3) dla testów modułu kopii —
 * router wbudowanego serwera PHP:
 *
 *   EVK_GOOGLE_DIR=/tmp/katalog php -S 127.0.0.1:PORT tests/php/_google-atrapa.php
 *
 * Działa WYŁĄCZNIE pod SAPI `cli-server` (jak _router-wp.php) — na
 * prawdziwym serwerze odpowiada 403 i kończy.
 *
 * Odpowiada tym, o co pyta includes/backup/gdrive.php, i tak, jak robi to
 * Google według dokumentacji:
 *   /o/oauth2/v2/auth         zgoda — od razu 302 na redirect_uri z kodem,
 *   /token                    authorization_code (PKCE S256 sprawdzane!)
 *                             i refresh_token,
 *   /revoke                   cofnięcie tokenu odświeżania,
 *   /evk-oauth/               PRAWDZIWA strona przekierowująca z
 *                             tools/oauth-relay/index.html,
 *   /drive/v3/about           miejsce na Dysku,
 *   /drive/v3/files           lista (q: parents, trashed, mimeType, name,
 *                             appProperties has), tworzenie folderu,
 *   /drive/v3/files/{id}      metadane, alt=media z Range (206), PATCH, DELETE,
 *   /upload/drive/v3/files    resumable upload: sesja, PUT kawałków
 *                             (308 + Range), `bytes * /rozmiar` o stan.
 *
 * Sterowanie awariami i podgląd dla testów: POST /_atrapa/ster (JSON do
 * scalenia z `ster`), GET /_atrapa/stan, POST /_atrapa/plik (plik na
 * Dysku z zadanym wiekiem). Stan w pliku JSON w EVK_GOOGLE_DIR.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }

const ATRAPA_KLIENT = 'test-klient';
const ATRAPA_SEKRET = 'test-sekret';

$katalog = getenv('EVK_GOOGLE_DIR') ?: sys_get_temp_dir() . '/evk-google-atrapa';
@mkdir($katalog . '/pliki', 0777, true);
@mkdir($katalog . '/sesje', 0777, true);
$plikStanu = $katalog . '/stan.json';

$blokada = fopen($katalog . '/.blokada', 'c');
flock($blokada, LOCK_EX);
$stan = is_file($plikStanu) ? (json_decode((string) file_get_contents($plikStanu), true) ?: []) : [];
$stan += ['kody' => [], 'odswiezajace' => [], 'dostepowe' => [], 'pliki' => [], 'sesje' => [], 'ster' => [], 'log' => [], 'licz' => 0];

function zapisz(): void {
    global $stan, $plikStanu;
    $stan['log'] = array_slice($stan['log'], -400);
    file_put_contents($plikStanu, json_encode($stan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function json_out(int $kod, $dane, array $naglowki = []): void {
    zapisz();
    http_response_code($kod);
    header('Content-Type: application/json; charset=UTF-8');
    // Kod podany jawnie: sam `Location` PHP zamieniłby na 302, a Google oddaje go przy 200.
    foreach ($naglowki as $k => $v) header($k . ': ' . $v, true, $kod);
    echo json_encode($dane, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function blad(int $kod, string $msg, string $powod = ''): void {
    json_out($kod, ['error' => ['code' => $kod, 'message' => $msg, 'errors' => [['reason' => $powod ?: 'error']]]]);
}

function nowe_id(string $pref): string {
    global $stan;
    $stan['licz']++;
    return $pref . str_pad((string) $stan['licz'], 6, '0', STR_PAD_LEFT) . bin2hex(random_bytes(6));
}

function b64url(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }

/** Jedno użycie awarii z `ster[$klucz]` (licznik). */
function awaria(string $klucz): bool {
    global $stan;
    if (empty($stan['ster'][$klucz])) return false;
    $stan['ster'][$klucz]--;
    return true;
}

$naglowki = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
$metoda = $_SERVER['REQUEST_METHOD'];
$sciezka = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cialo = (string) file_get_contents('php://input');
$stan['log'][] = ['m' => $metoda, 'p' => $sciezka, 'q' => $_SERVER['QUERY_STRING'] ?? '', 'cr' => $naglowki['content-range'] ?? '',
                  'range' => $naglowki['range'] ?? '', 'n' => strlen($cialo)];

// ── sterowanie testami ──────────────────────────────────────────────────
if ($sciezka === '/_atrapa/stan') json_out(200, $stan);
if ($sciezka === '/_atrapa/ster') {
    $stan['ster'] = array_merge($stan['ster'], (array) json_decode($cialo, true));
    json_out(200, $stan['ster']);
}
if ($sciezka === '/_atrapa/wyczysc') {
    foreach (glob($katalog . '/pliki/*') ?: [] as $p) unlink($p);
    foreach (glob($katalog . '/sesje/*') ?: [] as $p) unlink($p);
    $stan = ['kody' => [], 'odswiezajace' => [], 'dostepowe' => [], 'pliki' => [], 'sesje' => [], 'ster' => [], 'log' => [], 'licz' => 0];
    json_out(200, ['ok' => true]);
}
if ($sciezka === '/_atrapa/plik') {
    $d = (array) json_decode($cialo, true);
    $id = nowe_id('F');
    file_put_contents($katalog . '/pliki/' . $id, (string) ($d['body'] ?? ''));
    $stan['pliki'][$id] = ['id' => $id, 'name' => (string) $d['name'], 'mimeType' => 'application/zip', 'parents' => (array) ($d['parents'] ?? []),
        'appProperties' => (array) ($d['appProperties'] ?? []), 'trashed' => false, 'size' => (string) strlen((string) ($d['body'] ?? '')),
        'createdTime' => gmdate('Y-m-d\TH:i:s.000\Z', (int) ($d['created'] ?? time()))];
    json_out(200, $stan['pliki'][$id]);
}

// ── strona przekierowująca (prawdziwy plik z repozytorium) ─────────────────
if ($sciezka === '/evk-oauth/' || $sciezka === '/evk-oauth/index.html') {
    zapisz();
    header('Content-Type: text/html; charset=UTF-8');
    readfile(dirname(__DIR__, 2) . '/tools/oauth-relay/index.html');
    exit;
}

// ── OAuth ────────────────────────────────────────────────────────────────
if ($sciezka === '/o/oauth2/v2/auth') {
    $q = $_GET;
    $ok = ($q['client_id'] ?? '') === ATRAPA_KLIENT && ($q['response_type'] ?? '') === 'code'
        && ($q['code_challenge_method'] ?? '') === 'S256' && strlen((string) ($q['code_challenge'] ?? '')) >= 43
        && ($q['access_type'] ?? '') === 'offline' && strpos((string) ($q['scope'] ?? ''), 'auth/drive.file') !== false;
    if (!$ok) { zapisz(); http_response_code(400); echo 'Zle zadanie zgody: ' . htmlspecialchars(json_encode($q)); exit; }
    $cel = (string) $q['redirect_uri'];
    $stan['ostatnia_zgoda'] = $q;
    if (awaria('odmowa')) {
        $cel .= (strpos($cel, '?') === false ? '?' : '&') . http_build_query(['error' => 'access_denied', 'state' => $q['state']]);
    } else {
        $kod = '4/' . bin2hex(random_bytes(12));
        $stan['kody'][$kod] = ['challenge' => $q['code_challenge'], 'redirect' => $cel, 'uzyty' => false];
        $cel .= (strpos($cel, '?') === false ? '?' : '&') . http_build_query(['state' => $q['state'], 'code' => $kod,
            'scope' => 'email openid https://www.googleapis.com/auth/drive.file']);
    }
    zapisz();
    header('Location: ' . $cel, true, 302);
    exit;
}

if ($sciezka === '/token' && $metoda === 'POST') {
    $p = $_POST;
    if (($p['client_id'] ?? '') !== ATRAPA_KLIENT || ($p['client_secret'] ?? '') !== ATRAPA_SEKRET) {
        json_out(401, ['error' => 'invalid_client', 'error_description' => 'Unauthorized']);
    }
    $wydaj = static function (string $odsw) use (&$stan): array {
        $dost = 'ya29.' . bin2hex(random_bytes(10));
        $stan['dostepowe'][$dost] = time() + 3600;
        return ['access_token' => $dost, 'expires_in' => 3599, 'token_type' => 'Bearer',
                'scope' => !empty($stan['ster']['bez_drive_file']) ? 'openid https://www.googleapis.com/auth/userinfo.email' : 'openid https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email'];
    };
    if (($p['grant_type'] ?? '') === 'authorization_code') {
        $k = $stan['kody'][$p['code'] ?? ''] ?? null;
        if (!$k || $k['uzyty'] || $k['redirect'] !== ($p['redirect_uri'] ?? '')) json_out(400, ['error' => 'invalid_grant', 'error_description' => 'Bad code']);
        if (b64url(hash('sha256', (string) ($p['code_verifier'] ?? ''), true)) !== $k['challenge']) {
            $stan['kody'][$p['code']]['uzyty'] = true;
            json_out(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid code verifier.']);
        }
        $stan['kody'][$p['code']]['uzyty'] = true;
        $odsw = '1//' . bin2hex(random_bytes(12));
        $stan['odswiezajace'][$odsw] = true;
        $email = (string) ($stan['ster']['email'] ?? 'wlasciciel@example.com');
        $id = b64url('{"alg":"RS256"}') . '.' . b64url(json_encode(['email' => $email, 'email_verified' => true, 'sub' => '1'])) . '.podpis';
        json_out(200, $wydaj($odsw) + ['refresh_token' => $odsw, 'id_token' => $id]);
    }
    if (($p['grant_type'] ?? '') === 'refresh_token') {
        $odsw = (string) ($p['refresh_token'] ?? '');
        if (empty($stan['odswiezajace'][$odsw]) || !empty($stan['ster']['invalid_grant'])) {
            json_out(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']);
        }
        $stan['odswiezen'] = ($stan['odswiezen'] ?? 0) + 1;
        json_out(200, $wydaj($odsw));
    }
    json_out(400, ['error' => 'unsupported_grant_type']);
}

if ($sciezka === '/revoke' && $metoda === 'POST') {
    unset($stan['odswiezajace'][$_POST['token'] ?? '']);
    $stan['cofniete'] = ($stan['cofniete'] ?? 0) + 1;
    json_out(200, []);
}

// ── Drive: autoryzacja ───────────────────────────────────────────────────
$tok = preg_match('/^Bearer (.+)$/', (string) ($naglowki['authorization'] ?? ''), $m) ? $m[1] : '';
if (strpos($sciezka, '/drive/') === 0 || strpos($sciezka, '/upload/') === 0) {
    if ($tok === '' || ($stan['dostepowe'][$tok] ?? 0) < time()) blad(401, 'Request had invalid authentication credentials.', 'authError');
}

function meta_out(array $f): array { return $f; }

/** Czy plik pasuje do zapytania `q` — tylko te klauzule, których używa wtyczka. */
function pasuje(array $f, string $q): bool {
    if (preg_match_all("/'([^']*)' in parents/", $q, $m)) foreach ($m[1] as $p) if (!in_array($p, $f['parents'], true)) return false;
    if (strpos($q, 'trashed=false') !== false && !empty($f['trashed'])) return false;
    if (preg_match("/mimeType='([^']*)'/", $q, $m) && $f['mimeType'] !== $m[1]) return false;
    if (preg_match("/name='([^']*)'/", $q, $m) && $f['name'] !== $m[1]) return false;
    if (preg_match_all("/appProperties has \{ key='([^']*)' and value='([^']*)' \}/", $q, $m, PREG_SET_ORDER)) {
        foreach ($m as $w) if (($f['appProperties'][$w[1]] ?? null) !== $w[2]) return false;
    }
    return true;
}

if ($sciezka === '/drive/v3/about') {
    json_out(200, ['storageQuota' => ['limit' => '16106127360', 'usage' => (string) (1073741824 + array_sum(array_map(static function ($f) { return (int) ($f['size'] ?? 0); }, $stan['pliki'])))]]);
}

if ($sciezka === '/drive/v3/files' && $metoda === 'GET') {
    $wynik = array_values(array_filter($stan['pliki'], static function ($f) { return pasuje($f, (string) ($_GET['q'] ?? '')); }));
    json_out(200, ['files' => $wynik]);
}

if ($sciezka === '/drive/v3/files' && $metoda === 'POST') {
    $d = (array) json_decode($cialo, true);
    $id = nowe_id('D');
    $stan['pliki'][$id] = ['id' => $id, 'name' => (string) ($d['name'] ?? ''), 'mimeType' => (string) ($d['mimeType'] ?? 'application/octet-stream'),
        'parents' => (array) ($d['parents'] ?? []), 'appProperties' => (array) ($d['appProperties'] ?? []), 'trashed' => false,
        'createdTime' => gmdate('Y-m-d\TH:i:s.000\Z')];
    json_out(200, ['id' => $id]);
}

if (preg_match('#^/drive/v3/files/([A-Za-z0-9_-]+)$#', $sciezka, $m)) {
    $id = $m[1];
    if (!isset($stan['pliki'][$id])) blad(404, 'File not found: ' . $id . '.', 'notFound');
    $f = $stan['pliki'][$id];
    if ($metoda === 'DELETE') {
        unset($stan['pliki'][$id]);
        @unlink($katalog . '/pliki/' . $id);
        zapisz();
        http_response_code(204);
        exit;
    }
    if ($metoda === 'PATCH') {
        $d = (array) json_decode($cialo, true);
        $stan['pliki'][$id]['appProperties'] = array_merge($f['appProperties'], (array) ($d['appProperties'] ?? []));
        json_out(200, ['id' => $id]);
    }
    if (($_GET['alt'] ?? '') === 'media') {
        if (awaria('get_503')) blad(503, 'Backend Error', 'backendError');
        $dane = (string) file_get_contents($katalog . '/pliki/' . $id);
        $r = (string) ($naglowki['range'] ?? '');
        zapisz();
        if (preg_match('/^bytes=(\d+)-(\d+)$/', $r, $z)) {
            $od = (int) $z[1];
            $do = min((int) $z[2], strlen($dane) - 1);
            // Wolne łącze na żądanie testu: `wolno` = bajtów na sekundę.
            $wolno = (int) ($stan['ster']['wolno'] ?? 0);
            if ($wolno > 0) usleep((int) (($do - $od + 1) / $wolno * 1e6));
            http_response_code(206);
            header('Content-Range: bytes ' . $od . '-' . $do . '/' . strlen($dane));
            echo substr($dane, $od, $do - $od + 1);
            exit;
        }
        http_response_code(200);
        echo $dane;
        exit;
    }
    json_out(200, $f);
}

// ── resumable upload ─────────────────────────────────────────────────────
if ($sciezka === '/upload/drive/v3/files' && $metoda === 'POST' && ($_GET['uploadType'] ?? '') === 'resumable') {
    if (awaria('sesja_503')) blad(503, 'Backend Error', 'backendError');
    $d = (array) json_decode($cialo, true);
    $sid = nowe_id('S');
    $stan['sesje'][$sid] = ['meta' => $d, 'total' => (int) ($naglowki['x-upload-content-length'] ?? -1)];
    file_put_contents($katalog . '/sesje/' . $sid, '');
    $adres = 'http://' . $_SERVER['HTTP_HOST'] . '/upload/drive/v3/files?uploadType=resumable&upload_id=' . $sid;
    json_out(200, [], ['Location' => $adres]);
}

if ($sciezka === '/upload/drive/v3/files' && $metoda === 'PUT') {
    $sid = (string) ($_GET['upload_id'] ?? '');
    if (!isset($stan['sesje'][$sid]) || !empty($stan['ster']['sesja_wygasla'])) {
        if (!empty($stan['ster']['sesja_wygasla'])) $stan['ster']['sesja_wygasla']--;
        blad(404, 'Upload session not found.', 'notFound');
    }
    $s = $stan['sesje'][$sid];
    $plikSesji = $katalog . '/sesje/' . $sid;
    clearstatcache();
    $ma = (int) filesize($plikSesji);
    $cr = (string) ($naglowki['content-range'] ?? '');
    $range308 = static function (int $ma): array { return $ma > 0 ? ['Range' => 'bytes=0-' . ($ma - 1)] : []; };
    $zakoncz = static function () use ($sid, $s, $plikSesji, $katalog, &$stan): void {
        $id = nowe_id('F');
        rename($plikSesji, $katalog . '/pliki/' . $id);
        $stan['pliki'][$id] = ['id' => $id, 'name' => (string) ($s['meta']['name'] ?? ''), 'mimeType' => 'application/zip',
            'parents' => (array) ($s['meta']['parents'] ?? []), 'appProperties' => (array) ($s['meta']['appProperties'] ?? []),
            'trashed' => false, 'size' => (string) filesize($katalog . '/pliki/' . $id), 'createdTime' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'md5Checksum' => md5_file($katalog . '/pliki/' . $id)];
        unset($stan['sesje'][$sid]);
        json_out(200, ['id' => $id]);
    };
    if (preg_match('#^bytes \*/(\d+)$#', $cr, $z)) {
        if ($ma === (int) $z[1] && $ma > 0) $zakoncz();
        $stan['zapytania_o_stan'] = ($stan['zapytania_o_stan'] ?? 0) + 1;
        json_out(308, [], $range308($ma));
    }
    if (!preg_match('#^bytes (\d+)-(\d+)/(\d+)$#', $cr, $z)) blad(400, 'Bad Content-Range: ' . $cr, 'badRequest');
    [$od, $do, $cale] = [(int) $z[1], (int) $z[2], (int) $z[3]];
    if (awaria('put_503')) blad(503, 'Backend Error', 'backendError');
    if (strlen($cialo) !== $do - $od + 1) blad(400, 'Body length does not match Content-Range.', 'badRequest');
    if ($do + 1 < $cale && strlen($cialo) % 262144 !== 0) blad(400, 'Chunk size must be a multiple of 256 KiB.', 'badRequest');
    // Kawałek nie od miejsca, w którym stoi sesja: Google odpowiada stanem — nic nie dopisuje.
    if ($od !== $ma) { $stan['niezgodne'] = ($stan['niezgodne'] ?? 0) + 1; json_out(308, [], $range308($ma)); }
    file_put_contents($plikSesji, $cialo, FILE_APPEND);
    $ma += strlen($cialo);
    // Zgubiona odpowiedź: dane przyjęte, a klient dostaje błąd.
    if (awaria('put_zgub')) blad(503, 'Backend Error', 'backendError');
    if ($ma === $cale) $zakoncz();
    json_out(308, [], $range308($ma));
}

zapisz();
http_response_code(404);
echo 'atrapa: nie znam ' . $metoda . ' ' . $sciezka;
