<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — katalog kopii i jego ochrona.
 *
 * `wp-content/evk-backups-<losowe 20 znaków>/`. Kopia niesie zrzut bazy:
 * hashe haseł, adresy subskrybentów, wiadomości z formularzy — pod
 * przewidywalnym adresem byłaby do pobrania przez każdego. Dlatego trzy
 * warstwy, z których żadna nie wystarcza sama:
 *
 *   1. losowa nazwa katalogu (i plików kopii),
 *   2. `.htaccess` blokujący dostęp — działa na Apache i LiteSpeed,
 *      nie działa na nginx,
 *   3. `index.php` i `web.config` (IIS) — na wypadek listowania katalogu.
 *
 * Czy warstwa 2 działa NA TYM serwerze, nie zakładamy — sprawdza to
 * evk_backup_exposure_check(): kanarek w katalogu i żądanie HTTP z serwera
 * do samego siebie. Wynik trafia do tabeli „Środowisko serwera".
 *
 * Sufiks siedzi w osobnej opcji `evk_backup_dir`, nie w ustawieniach —
 * należy do tej instalacji, nie do strony (patrz settings.php). Restore
 * przepisuje go po przełączeniu tabel.
 */

const EVK_BACKUP_DIR_OPTION = 'evk_backup_dir';

function evk_backup_dir_suffix(): string {
    $s = (string) get_option(EVK_BACKUP_DIR_OPTION, '');
    if (!preg_match('/^[a-f0-9]{20}$/', $s)) {
        $s = bin2hex(random_bytes(10));
        update_option(EVK_BACKUP_DIR_OPTION, $s, false);
    }
    return $s;
}

/** Katalog kopii (bez tworzenia). */
function evk_backup_dir(): string {
    return WP_CONTENT_DIR . '/evk-backups-' . evk_backup_dir_suffix();
}

function evk_backup_dir_url(): string {
    return content_url('evk-backups-' . evk_backup_dir_suffix());
}

/**
 * Katalog na kopie wgrywane przez FTP. Stała nazwa, bo trzeba ją znać bez
 * panelu — więc pliki są stąd przenoszone do katalogu z losową nazwą przy
 * każdym otwarciu zakładki (etap F). Pasuje do twardego wykluczenia
 * `evk-backups-*`, więc nie trafia do kopii.
 */
function evk_backup_import_dir(): string {
    return WP_CONTENT_DIR . '/evk-backups-import';
}

/** Treść plików ochronnych. */
function evk_backup_protection_files(): array {
    return [
        '.htaccess' => "# Evoke ONE — katalog kopii zapasowych: bez dostępu z sieci.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
        'index.php' => "<?php\n// Silence is golden.\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
            . "    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
    ];
}

/**
 * Tworzy katalog i dokłada brakujące pliki ochronne. Istniejących nie
 * nadpisuje — ktoś mógł dopisać do .htaccess coś pod swój serwer.
 */
function evk_backup_ensure_dir(string $dir): bool {
    if (!is_dir($dir) && !wp_mkdir_p($dir)) return false;
    foreach (evk_backup_protection_files() as $nazwa => $tresc) {
        if (!file_exists($dir . '/' . $nazwa)) @file_put_contents($dir . '/' . $nazwa, $tresc);
    }
    return is_dir($dir) && is_writable($dir);
}

/**
 * Czy plik w katalogu kopii da się pobrać z sieci.
 *
 * Kanarek o losowej nazwie i treści, żądanie HTTP z serwera do samego siebie,
 * kanarek usunięty. true = TREŚĆ KANARKA WRÓCIŁA (katalog odsłonięty),
 * false = serwer odmówił albo nie znalazł, null = nie da się stwierdzić
 * (żądanie do samego siebie nie przeszło — firewall, Basic Auth, DNS).
 *
 * $url_base i $dir podaje test; w panelu idą z ustawień.
 */
function evk_backup_exposure_check(?string $dir = null, ?string $url_base = null): ?bool {
    $dir      = $dir ?? evk_backup_dir();
    $url_base = rtrim($url_base ?? evk_backup_dir_url(), '/');
    if (!evk_backup_ensure_dir($dir)) return null;

    $nazwa = 'evk-kanarek-' . bin2hex(random_bytes(6)) . '.txt';
    $token = bin2hex(random_bytes(16));
    if (@file_put_contents($dir . '/' . $nazwa, $token) === false) return null;

    $odp = wp_remote_get($url_base . '/' . $nazwa, ['timeout' => 8, 'redirection' => 0, 'sslverify' => false]);
    @unlink($dir . '/' . $nazwa);

    if (is_wp_error($odp)) return null;
    $kod = (int) wp_remote_retrieve_response_code($odp);
    if ($kod === 200) return trim((string) wp_remote_retrieve_body($odp)) === $token;
    return false;
}

/** Wynik sprawdzenia z pamięcią na 12 h — zakładka nie pyta serwera przy każdym otwarciu. */
function evk_backup_exposure_cached(bool $odswiez = false): ?bool {
    $zapis = get_transient('evk_backup_exposed');
    if (!$odswiez && $zapis !== false) return $zapis === 'tak' ? true : ($zapis === 'nie' ? false : null);
    $wynik = evk_backup_exposure_check();
    set_transient('evk_backup_exposed', $wynik === true ? 'tak' : ($wynik === false ? 'nie' : 'nieznane'), 12 * HOUR_IN_SECONDS);
    return $wynik;
}
