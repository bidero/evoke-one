<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — podmiana adresów i ścieżek bezpieczna dla serializacji.
 *
 * ZERO ZALEŻNOŚCI OD WORDPRESSA. Plik ma się ładować w gołym PHP-ie z CLI —
 * na tym stoi tests/php/backup-serialize.php.
 *
 * PO CO TO WSZYSTKO. PHP zapisuje w serializacji DŁUGOŚĆ łańcucha w bajtach:
 * `s:15:"https://stara.pl";`. Zwykły str_replace zmienia treść, ale nie
 * nagłówek — unserialize() odrzuca wtedy całą wartość, a z nią np. cały
 * układ strony Bricksa albo opcje motywu. Strona po restore wygląda na pustą.
 *
 * DLACZEGO NIE unserialize() → podmiana → serialize(). Bo unserialize() na
 * danych z cudzej bazy instancjonuje klasy (ryzyko łańcuchów gadżetów),
 * a klasy wtyczki, której tu nie ma, zamienia w __PHP_Incomplete_Class.
 * Zamiast tego parser TEKSTOWY, wzorowany na Search-Replace-DB
 * (Interconnect/it): idzie po zapisie token po tokenie, podmienia wyłącznie
 * wnętrza wartości `s:`, przelicza ich długości i składa całość z powrotem.
 * Żadna klasa nie powstaje, bo żaden obiekt nie powstaje.
 *
 * Czego świadomie NIE rusza:
 *   - kluczy tablic i nazw właściwości (zmiana klucza potrafi zderzyć dwa
 *     wpisy w jeden — tak samo robi Search-Replace-DB),
 *   - `C:` (klasy z własnym Serializable — ładunek w formacie klasy),
 *   - `S:` (łańcuch z sekwencjami \xx — przestarzały zapis),
 *   - `E:` (enum), liczb, referencji.
 *
 * Wartość, która WYGLĄDA na serializację, ale nie daje się sparsować (np. zła
 * długość już w bazie źródłowej), zostaje BEZ ZMIAN i jest liczona w
 * statystyce `broken`. Podmiana na zepsutym zapisie mogłaby go tylko popsuć
 * bardziej, a import ma iść dalej.
 */

/** Głębokość zagnieżdżenia, przy której parser rezygnuje (wartość bez zmian). */
const EVK_SR_MAX_DEPTH = 512;

// =========================================================================
// PARY PODMIAN
// =========================================================================

/**
 * Buduje mapę „szukaj => zamień" dla przenosin strony.
 *
 * $urls  lista [stary, nowy] — zwykle home i siteurl z manifestu,
 * $paths lista [stara, nowa] — ABSPATH, WP_CONTENT_DIR,
 * $emails czy podmieniać adresy e-mail w domenie (`@stara.pl`).
 *
 * Każdy adres idzie w wariantach:
 *   - http:// i https:// starego adresu → nowy adres (z jego schematem),
 *   - z „www." i bez,
 *   - `//host` bez schematu → `//nowy-host`,
 *   - każdy powyższy także w zapisie JSON (`https:\/\/…`) i zakodowany jak
 *     w adresie URL (`https%3A%2F%2F…`), bo tak trzymają go inne wtyczki.
 *
 * Adresy e-mail podmieniamy tylko, gdy żadna z domen nie jest poddomeną
 * drugiej. Przeniesienie stara.pl → test.stara.pl to zwykle kopia robocza;
 * zamiana biuro@stara.pl na biuro@test.stara.pl wysłałaby powiadomienia
 * do skrzynki, której nie ma.
 */
function evk_sr_build_pairs(array $urls, array $paths = [], bool $emails = false): array {
    $mapa = [];
    $dodaj = static function (string $z, string $na) use (&$mapa): void {
        if ($z === '' || $z === $na || isset($mapa[$z])) return;
        $mapa[$z] = $na;
    };
    $json = static function (string $s): string {
        return substr((string) json_encode($s, JSON_UNESCAPED_UNICODE), 1, -1);
    };

    foreach ($urls as $para) {
        if (!is_array($para) || count($para) !== 2) continue;
        $stary = evk_sr_parse_url((string) $para[0]);
        $nowy  = rtrim((string) $para[1], '/');
        if (!$stary || $nowy === '') continue;
        $nowyBezSchematu = preg_replace('~^[a-z][a-z0-9+.\-]*:~i', '', $nowy);

        $hosty = [$stary['host']];
        $hosty[] = strpos($stary['host'], 'www.') === 0 ? substr($stary['host'], 4) : 'www.' . $stary['host'];

        foreach ($hosty as $host) {
            $reszta = $host . $stary['port'] . $stary['path'];
            foreach (['https://', 'http://'] as $schemat) {
                $z = $schemat . $reszta;
                $dodaj($z, $nowy);
                $dodaj($json($z), $json($nowy));
                $dodaj(rawurlencode($z), rawurlencode($nowy));
            }
            $dodaj('//' . $reszta, $nowyBezSchematu);
            $dodaj($json('//' . $reszta), $json($nowyBezSchematu));
        }

        if ($emails) {
            $h1 = preg_replace('/^www\./', '', $stary['host']);
            $n  = evk_sr_parse_url($nowy);
            $h2 = $n ? preg_replace('/^www\./', '', $n['host']) : '';
            $poddomena = static function (string $a, string $b): bool {
                return substr($a, -strlen('.' . $b)) === '.' . $b;
            };
            if ($h2 !== '' && $h1 !== $h2 && !$poddomena($h1, $h2) && !$poddomena($h2, $h1)) {
                $dodaj('@' . $h1, '@' . $h2);
            }
        }
    }

    foreach ($paths as $para) {
        if (!is_array($para) || count($para) !== 2) continue;
        $z  = rtrim((string) $para[0], '/\\');
        $na = rtrim((string) $para[1], '/\\');
        /* Ścieżka „/" albo pusta podmieniłaby każdy ukośnik w bazie. Krótsze
           niż cztery znaki nie są realną ścieżką instalacji. */
        if (strlen($z) < 4) continue;
        $dodaj($z, $na);
        $dodaj($json($z), $json($na));
    }

    return $mapa;
}

/**
 * Rozbiór adresu na host, port i ścieżkę (bez końcowego ukośnika).
 * Własny, a nie wp_parse_url(), bo plik nie zależy od WordPressa.
 */
function evk_sr_parse_url(string $url): ?array {
    $url = trim($url);
    if (!preg_match('~^[a-z][a-z0-9+.\-]*://([^/:?#]+)(:\d+)?([^?#]*)~i', $url, $m)) return null;
    return [
        'host' => strtolower($m[1]),
        'port' => $m[2] ?? '',
        'path' => rtrim($m[3] ?? '', '/'),
    ];
}

/**
 * Kompiluje mapę do jednego wyrażenia. Podmiana idzie JEDNYM przejściem:
 * tekst już podmieniony nie jest skanowany drugi raz. Bez tego przenosiny
 * https://stara.pl → https://stara.pl/nowa dałyby przy wariancie `//stara.pl`
 * adres …/nowa/nowa.
 *
 * Kolejność alternatyw: najdłuższe najpierw — `https://stara.pl/blog` ma
 * wygrać z `https://stara.pl` w tym samym miejscu tekstu.
 *
 * GRANICA: za dopasowaniem nie może stać znak ciągnący nazwę dalej (litera,
 * cyfra, `-`, `_`) ani kropka z takim znakiem. Dzięki temu `stara.pl` nie
 * rusza `stara.pl.eu`, `stara.plus` ani `/public_html2`, a kropka kończąca
 * zdanie („…na https://stara.pl.") nie blokuje podmiany.
 */
function evk_sr_compile(array $mapa): array {
    $klucze = array_keys($mapa);
    usort($klucze, static function ($a, $b) { return strlen((string) $b) <=> strlen((string) $a); });
    $alt = implode('|', array_map(static function ($k) { return preg_quote((string) $k, '~'); }, $klucze));
    return [
        're'  => $alt === '' ? null : '~(?:' . $alt . ')(?![A-Za-z0-9_\-]|\.[A-Za-z0-9_\-])~',
        'map' => $mapa,
    ];
}

// =========================================================================
// PODMIANA
// =========================================================================

/**
 * Główne wejście: wartość jednej kolumny → wartość po podmianie.
 *
 * $stats zlicza: replaced (liczba podmian), serialized (wartości przepuszczone
 * przez parser), broken (wyglądały na serializację, nie dały się sparsować),
 * opaque (tokeny C:/S: pominięte w środku).
 */
function evk_sr_replace(string $value, array $compiled, array &$stats = []): string {
    foreach (['replaced', 'serialized', 'broken', 'opaque'] as $k) {
        if (!isset($stats[$k])) $stats[$k] = 0;
    }
    if ($compiled['re'] === null || $value === '') return $value;
    return evk_sr_value($value, $compiled, $stats, 0);
}

/** Podmiana na zwykłym tekście — bez żadnej wiedzy o serializacji. */
function evk_sr_plain(string $s, array $compiled, array &$stats): string {
    $mapa = $compiled['map'];
    $n = 0;
    $out = preg_replace_callback($compiled['re'], static function ($m) use ($mapa) {
        return $mapa[$m[0]];
    }, $s, -1, $n);
    if ($out === null) return $s;   // błąd PCRE (np. limit) — wartość bez zmian
    $stats['replaced'] += $n;
    return $out;
}

/**
 * Czy łańcuch WYGLĄDA na zapis serialize(). Tylko kształt — o tym, czy jest
 * poprawny, rozstrzyga parser.
 */
function evk_sr_looks_serialized(string $s): bool {
    $s = rtrim($s);
    $ostatni = substr($s, -1);
    if ($ostatni !== ';' && $ostatni !== '}') return false;
    return (bool) preg_match('/^(?:N;|b:[01];|[id]:[^;]+;|[sSE]:\d+:"|[aC]:\d+:|O:\d+:"|[rR]:\d+;)/', $s);
}

/**
 * Wartość na dowolnym poziomie: serializacja → parser, reszta → zwykła podmiana.
 * Wnętrze `s:` wraca tutaj, więc serializacja zagnieżdżona w łańcuchu
 * (częste w opcjach wtyczek) też jest parsowana, a nie łatana tekstowo.
 */
function evk_sr_value(string $v, array $compiled, array &$stats, int $depth): string {
    if (!evk_sr_looks_serialized($v)) return evk_sr_plain($v, $compiled, $stats);

    /* Zmiany liczników wprowadzamy dopiero po udanym parsowaniu — przy
       porzuconej wartości nie podmieniono niczego i statystyka ma to mówić. */
    $lokalne = ['replaced' => 0, 'serialized' => 0, 'broken' => 0, 'opaque' => 0];
    try {
        $p   = 0;
        $out = evk_sr_walk($v, $p, $compiled, $lokalne, $depth, true);
        if (trim(substr($v, $p)) !== '') throw new \UnexpectedValueException('dane za końcem zapisu');
        $out .= substr($v, $p);   // ewentualne białe znaki na końcu — jak były
    } catch (\UnexpectedValueException $e) {
        $stats['broken']++;
        return $v;
    }
    foreach ($lokalne as $k => $n) $stats[$k] += $n;
    $stats['serialized']++;
    return $out;
}

/**
 * Wyjątek parsera — pozycja w komunikacie ułatwia diagnozę z logu.
 *
 * @return never
 */
function evk_sr_fail(string $co, int $p): void {
    throw new \UnexpectedValueException($co . ' @' . $p);
}

/** Liczba całkowita od $p do znaku $koniec; przesuwa $p ZA ten znak. */
function evk_sr_read_int(string $s, int &$p, string $koniec): int {
    $k = strpos($s, $koniec, $p);
    if ($k === false) evk_sr_fail('brak „' . $koniec . '"', $p);
    $liczba = substr($s, $p, $k - $p);
    if (!preg_match('/^\d+$/', $liczba)) evk_sr_fail('zła liczba', $p);
    $p = $k + 1;
    return (int) $liczba;
}

/** Oczekuje dosłownie $co na pozycji $p i przesuwa za nie. */
function evk_sr_expect(string $s, int &$p, string $co): void {
    if (substr($s, $p, strlen($co)) !== $co) evk_sr_fail('oczekiwano „' . $co . '"', $p);
    $p += strlen($co);
}

/**
 * Jeden token od pozycji $p. Oddaje jego nowy zapis, $p ustawia za nim.
 * $replace = false dla kluczy i nazw właściwości.
 */
function evk_sr_walk(string $s, int &$p, array $compiled, array &$stats, int $depth, bool $replace): string {
    if ($depth > EVK_SR_MAX_DEPTH) evk_sr_fail('za głęboko', $p);
    $typ = $s[$p] ?? '';

    switch ($typ) {
        case 'N':
            evk_sr_expect($s, $p, 'N;');
            return 'N;';

        case 'b': case 'i': case 'd': case 'r': case 'R':
            $start = $p;
            evk_sr_expect($s, $p, $typ . ':');
            $k = strpos($s, ';', $p);
            if ($k === false) evk_sr_fail('brak „;"', $p);
            $tresc = substr($s, $p, $k - $p);
            $wzor = [
                'b' => '/^[01]$/',
                'i' => '/^[+-]?\d+$/',
                'd' => '/^(?:[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?|-?INF|NAN)$/',
                'r' => '/^\d+$/',
                'R' => '/^\d+$/',
            ][$typ];
            if (!preg_match($wzor, $tresc)) evk_sr_fail('zła wartość ' . $typ, $p);
            $p = $k + 1;
            return substr($s, $start, $p - $start);

        case 's':
            evk_sr_expect($s, $p, 's:');
            $dl = evk_sr_read_int($s, $p, ':');
            evk_sr_expect($s, $p, '"');
            if ($p + $dl + 2 > strlen($s)) evk_sr_fail('łańcuch krótszy niż nagłówek', $p);
            $tresc = substr($s, $p, $dl);
            $p += $dl;
            evk_sr_expect($s, $p, '";');
            if ($replace) $tresc = evk_sr_value($tresc, $compiled, $stats, $depth + 1);
            return 's:' . strlen($tresc) . ':"' . $tresc . '";';

        case 'S':
            // Łańcuch z sekwencjami \xx: długość liczy bajty PO rozkodowaniu.
            $start = $p;
            evk_sr_expect($s, $p, 'S:');
            $dl = evk_sr_read_int($s, $p, ':');
            evk_sr_expect($s, $p, '"');
            for ($i = 0; $i < $dl; $i++) {
                if (!isset($s[$p])) evk_sr_fail('S: urwany', $p);
                if ($s[$p] === '\\') {
                    if (!ctype_xdigit(substr($s, $p + 1, 2)) || strlen(substr($s, $p + 1, 2)) !== 2) evk_sr_fail('S: zła sekwencja', $p);
                    $p += 3;
                } else {
                    $p++;
                }
            }
            evk_sr_expect($s, $p, '";');
            $stats['opaque']++;
            return substr($s, $start, $p - $start);

        case 'E':
            $start = $p;
            evk_sr_expect($s, $p, 'E:');
            $dl = evk_sr_read_int($s, $p, ':');
            evk_sr_expect($s, $p, '"');
            $p += $dl;
            evk_sr_expect($s, $p, '";');
            return substr($s, $start, $p - $start);

        case 'a':
            evk_sr_expect($s, $p, 'a:');
            $n = evk_sr_read_int($s, $p, ':');
            evk_sr_expect($s, $p, '{');
            $out = 'a:' . $n . ':{' . evk_sr_members($s, $p, $n, $compiled, $stats, $depth) . '}';
            evk_sr_expect($s, $p, '}');
            return $out;

        case 'O':
            evk_sr_expect($s, $p, 'O:');
            $dl = evk_sr_read_int($s, $p, ':');
            evk_sr_expect($s, $p, '"');
            $klasa = substr($s, $p, $dl);
            if (strlen($klasa) !== $dl) evk_sr_fail('O: urwana nazwa klasy', $p);
            $p += $dl;
            evk_sr_expect($s, $p, '":');
            $n = evk_sr_read_int($s, $p, ':');
            evk_sr_expect($s, $p, '{');
            $out = 'O:' . $dl . ':"' . $klasa . '":' . $n . ':{' . evk_sr_members($s, $p, $n, $compiled, $stats, $depth) . '}';
            evk_sr_expect($s, $p, '}');
            return $out;

        case 'C':
            // Serializable: ładunek w formacie klasy — przepuszczamy bajt w bajt.
            $start = $p;
            evk_sr_expect($s, $p, 'C:');
            $dl = evk_sr_read_int($s, $p, ':');
            evk_sr_expect($s, $p, '"');
            $p += $dl;
            evk_sr_expect($s, $p, '":');
            $dl = evk_sr_read_int($s, $p, ':');
            evk_sr_expect($s, $p, '{');
            if ($p + $dl + 1 > strlen($s)) evk_sr_fail('C: urwany ładunek', $p);
            $p += $dl;
            evk_sr_expect($s, $p, '}');
            $stats['opaque']++;
            return substr($s, $start, $p - $start);
    }

    evk_sr_fail('nieznany token „' . $typ . '"', $p);
    return '';   // nieosiągalne — dla analizy statycznej
}

/** $n par klucz–wartość wewnątrz a:{…} albo O:{…}. */
function evk_sr_members(string $s, int &$p, int $n, array $compiled, array &$stats, int $depth): string {
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $klucz = $s[$p] ?? '';
        if ($klucz !== 'i' && $klucz !== 's') evk_sr_fail('klucz musi być i: albo s:', $p);
        $out .= evk_sr_walk($s, $p, $compiled, $stats, $depth + 1, false);
        $out .= evk_sr_walk($s, $p, $compiled, $stats, $depth + 1, true);
    }
    return $out;
}
