<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — manifest kopii (`manifest.json` w archiwum).
 *
 * Restore porównuje go z instalacją, na którą przywraca, i z tego liczy:
 * stary → nowy adres (siteurl, home), stara → nowa ścieżka (ABSPATH,
 * WP_CONTENT_DIR), stary → nowy prefiks tabel. Bez manifestu kopia nie
 * nadaje się do przenosin, więc jest pierwszym wpisem archiwum.
 *
 * `wp_config_constants` — WYŁĄCZNIE NAZWY stałych z wp-config.php, bez
 * wartości. wp-config.php nie trafia do kopii (hasło do bazy), ale po
 * przeniesieniu warto wiedzieć, których stałych brakuje na nowym serwerze
 * (limit pamięci, token GitHuba aktualizatora, klucze SMTP).
 */

const EVK_BACKUP_FORMAT = 1;

function evk_backup_manifest(array $dodatki = []): array {
    global $wpdb, $wp_version;
    $uploads = wp_upload_dir(null, false);
    return array_merge([
        'format'              => EVK_BACKUP_FORMAT,
        'plugin_version'      => defined('EVOKE_ONE_VERSION') ? EVOKE_ONE_VERSION : '',
        'created_at'          => gmdate('Y-m-d\TH:i:s\Z'),
        'siteurl'             => (string) get_option('siteurl'),
        'home'                => (string) get_option('home'),
        'abspath'             => ABSPATH,
        'wp_content_dir'      => WP_CONTENT_DIR,
        'wp_content_url'      => content_url(),
        'uploads_dir'         => (string) $uploads['basedir'],
        'uploads_url'         => (string) $uploads['baseurl'],
        'table_prefix'        => $wpdb->prefix,
        'wp_version'          => (string) $wp_version,
        'php_version'         => PHP_VERSION,
        'db_version'          => (string) $wpdb->db_version(),
        'db_charset'          => (string) $wpdb->charset,
        'db_collate'          => (string) $wpdb->collate,
        'wp_config_constants' => evk_backup_wp_config_constants(),
    ], $dodatki);
}

/**
 * Nazwy stałych zdefiniowanych w wp-config.php — bez wykonywania pliku i bez
 * wartości. Przez tokenizer PHP, nie wyrażenie regularne: `define()` w
 * komentarzu (częste: „// define('WP_DEBUG', true);") nie jest stałą, a lista
 * ma potem mówić, czego BRAKUJE na nowym serwerze. wp-config.php bywa
 * w ABSPATH albo katalog wyżej.
 */
function evk_backup_wp_config_constants(?string $plik = null): array {
    if ($plik === null) {
        foreach ([ABSPATH . 'wp-config.php', dirname(ABSPATH) . '/wp-config.php'] as $p) {
            if (is_file($p)) { $plik = $p; break; }
        }
    }
    if ($plik === null || !is_readable($plik)) return [];
    $tokeny = array_values(array_filter(
        token_get_all((string) file_get_contents($plik, false, null, 0, 1048576)),
        static function ($t) { return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); }
    ));
    $nazwy = [];
    foreach ($tokeny as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING || strtolower($t[1]) !== 'define') continue;
        $nawias = $tokeny[$i + 1] ?? null;
        $nazwa  = $tokeny[$i + 2] ?? null;
        if ($nawias === '(' && is_array($nazwa) && $nazwa[0] === T_CONSTANT_ENCAPSED_STRING) {
            $n = substr($nazwa[1], 1, -1);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $n)) $nazwy[] = $n;
        }
    }
    $nazwy = array_values(array_unique($nazwy));
    sort($nazwy);
    return $nazwy;
}

/**
 * Pliki z katalogu głównego do PODGLĄDU (w archiwum pod `_root/`, restore
 * ich nie przywraca, tylko pokazuje): reguły serwera, robots.txt i pliki
 * weryfikacyjne wyszukiwarek. Wzorce są WYLICZONE, bez ogólnych w rodzaju
 * `*.php` czy `*.txt` — dzięki temu wp-config.php (hasło do bazy) nie ma jak
 * się złapać; test pilnuje tego wprost.
 */
function evk_backup_root_preview_files(?string $katalog = null): array {
    /* Bez końcowego „/", bo niżej doklejamy „/nazwa". WordPress w KATALOGU
       GŁÓWNYM serwera (hosting, który zamyka konto we własnym systemie plików:
       ABSPATH = „/" albo „//") dawał tu pusty napis, a scandir('') w PHP 8
       rzuca ValueError, którego @ nie tłumi. Każda kopia kończyła się wtedy
       zaraz po zrzucie bazy komunikatem „scandir(): Argument #1 ($directory)
       must not be empty" (zgłoszone z serwera Apache, 1.231.1). */
    $baza   = rtrim($katalog ?? ABSPATH, '/');
    $wzorce = ['.htaccess', '.user.ini', 'robots.txt', 'ads.txt', 'app-ads.txt', 'google*.html',
               'BingSiteAuth.xml', 'yandex_*.html', 'pinterest-*.html'];
    $wynik = [];
    foreach ((array) @scandir($baza === '' ? '/' : $baza) as $e) {
        $e = (string) $e;
        if ($e === '' || $e === '.' || $e === '..') continue;
        $p = $baza . '/' . $e;
        if (!is_file($p) || filesize($p) > 1048576) continue;
        foreach ($wzorce as $w) {
            if (fnmatch($w, $e)) { $wynik['_root/' . $e] = $p; break; }
        }
    }
    ksort($wynik);
    return $wynik;
}
