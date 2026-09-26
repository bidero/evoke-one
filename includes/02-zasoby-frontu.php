<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — skrócone pliki frontu (1.248.0).
 *
 * Zgłoszone: „w kodzie strony jest mnóstwo poprawek, opisów w skryptach,
 * które wcale nie są tam potrzebne". Na stronę jadą więc wersje `.min` plików
 * JS i CSS wtyczki, zbudowane przez tools/minifikuj.js (tam lista plików
 * i pomiar: 467 → 156 KiB). Źródła z komentarzami zostają w repozytorium —
 * są dokumentacją i to na nich chodzą testy zachowania.
 *
 * Źródło zamiast `.min`:
 *   · przy `SCRIPT_DEBUG` — zwyczaj WordPressa: ktoś szuka błędu i chce czytać
 *     kod z komentarzami;
 *   · gdy pliku `.min` nie ma — wtyczka skopiowana bez kroku budowania.
 *     Lepiej wolniej niż wcale.
 */

/**
 * Adres pliku JS albo CSS wtyczki → adres jego wersji `.min`, jeśli ma być
 * użyta. Adresy spoza wtyczki i pliki już skrócone wracają bez zmian.
 */
function evk_zasob_url(string $url): string {
    if ((defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) || !defined('EVOKE_ONE_URL')) return $url;
    if (strpos($url, EVOKE_ONE_URL) !== 0) return $url;
    $wzgledna = substr($url, strlen(EVOKE_ONE_URL));
    if (preg_match('/\.min\.(?:js|css)$/', $wzgledna) || !preg_match('/^(.+)\.(js|css)$/', $wzgledna, $m)) return $url;
    $min = $m[1] . '.min.' . $m[2];
    return file_exists(dirname(__DIR__) . '/' . $min) ? EVOKE_ONE_URL . $min : $url;
}
