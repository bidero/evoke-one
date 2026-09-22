<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — ustawienia modułu.
 *
 * Wszystko, co jest decyzją osoby przy panelu, siedzi w JEDNEJ opcji
 * `evk_backup`. Świeża instalacja nie zapisuje nic: każda flaga ma domyślnie 0,
 * zgodnie z zasadą z `01-install.php`.
 *
 * Czego tu NIE MA, i dlaczego — stan maszyny, a nie decyzje:
 *
 *   `evk_backup_dir`   losowy sufiks katalogu kopii,
 *   `evk_backup_key`   tajny klucz żądań zwrotnych (loopback).
 *
 * Oba są osobnymi opcjami, bo należą do TEJ instalacji, nie do strony.
 * Restore podmienia całą tabelę opcji na tę ze starej strony — gdyby sufiks
 * siedział w `evk_backup`, po przełączeniu tabel wskazywałby katalog, którego
 * na nowym serwerze nie ma, a w nim leży archiwum, z którego właśnie
 * przywracamy. Restore przepisuje je więc po przełączeniu z wartości sprzed
 * niego. Z tego samego powodu nie ma ich w eksporcie ustawień.
 */

const EVK_BACKUP_OPTION = 'evk_backup';

function evk_backup_defaults(): array {
    return [
        'enabled'          => 0,
        // Harmonogram nocny — godzina w czasie strony (wp_timezone()).
        'schedule_enabled' => 0,
        'schedule_time'    => '03:00',
        // Retencja lokalna w SZTUKACH. Przypięte kopie i snapshoty sprzed
        // restore do limitu się nie liczą.
        'retention_count'  => 5,
        // Wykluczenia względem wp-content, jedno na linię. Katalogów kopii tej
        // wtyczki tu nie ma: wykluczane są zawsze, niezależnie od listy
        // (patrz evk_backup_hard_exclusions()).
        'exclusions'       => implode("\n", evk_backup_default_exclusions()),
        // Tryb konserwacji na czas zrzutu bazy — spójna migawka kosztem
        // zasłonięcia strony na kilka–kilkadziesiąt sekund.
        'maintenance_db'   => 0,
        // Powiadomienia o nieudanej kopii.
        'notify_email'     => 0,
        'notify_address'   => '',   // puste = adres administratora
        'notify_notice'    => 0,
    ];
}

/**
 * Domyślna lista wykluczeń. Poza cache i plikami tymczasowymi — katalogi
 * kopii INNYCH wtyczek: stare archiwa leżące w wp-content potrafią być
 * większe niż cała reszta strony, a kopia kopii nikomu nie służy.
 */
function evk_backup_default_exclusions(): array {
    return [
        'cache/',
        'upgrade/',
        'upgrade-temp-backup/',
        '*.log',
        'ai1wm-backups/',
        'updraft/',
        'backwpup-*/',
        'wflogs/',
        'litespeed/',
        'et-cache/',
    ];
}

/**
 * Wykluczenia, których nie da się zdjąć z panelu: katalogi kopii tej wtyczki.
 * Kopia zawierająca poprzednie kopie rośnie wykładniczo.
 */
function evk_backup_hard_exclusions(): array {
    return ['evk-backups-*/'];
}

function evk_backup_get_settings(): array {
    $zapis = get_option(EVK_BACKUP_OPTION, []);
    return array_merge(evk_backup_defaults(), is_array($zapis) ? $zapis : []);
}

function evk_backup_enabled(): bool {
    return !empty(evk_backup_get_settings()['enabled']);
}

/**
 * Lista wykluczeń jako tablica wzorców — z ustawień plus twarde.
 */
function evk_backup_exclusion_patterns(): array {
    $s = evk_backup_get_settings();
    $lista = array_filter(array_map('trim', explode("\n", (string) $s['exclusions'])), 'strlen');
    return array_values(array_unique(array_merge($lista, evk_backup_hard_exclusions())));
}

// =========================================================================
// SANITYZACJA
// =========================================================================

/**
 * Jeden wzorzec wykluczenia. Względny wobec wp-content, bez `..` — wzorzec
 * wychodzący poza wp-content nie ma czego wykluczać, a `..` w ścieżce
 * porównywanej z listą plików to proszenie się o niespodzianki.
 * Pusty łańcuch = wzorzec odrzucony.
 */
function evk_backup_sanitize_pattern(string $p): string {
    $p = trim(str_replace('\\', '/', $p));
    $p = ltrim($p, '/');
    if ($p === '' || strpos($p, '..') !== false) return '';
    if (preg_match('/[\x00-\x1f]/', $p)) return '';
    return $p;
}

function evk_backup_sanitize($input): array {
    $d   = evk_backup_defaults();
    $in  = is_array($input) ? $input : [];
    $cur = evk_backup_get_settings();
    $c   = [];

    /* Włącznik modułu idzie przełącznikiem AJAX (evk_toggle_allowlist), nie
       formularzem — formularz, który go nie zawiera, nie może go wyzerować. */
    $c['enabled'] = array_key_exists('enabled', $in) ? (!empty($in['enabled']) ? 1 : 0) : (int) $cur['enabled'];

    foreach (['schedule_enabled', 'maintenance_db', 'notify_email', 'notify_notice'] as $k) {
        $c[$k] = !empty($in[$k]) ? 1 : 0;
    }

    $czas = (string) ($in['schedule_time'] ?? $d['schedule_time']);
    $c['schedule_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $czas) ? $czas : $d['schedule_time'];

    $c['retention_count'] = max(1, min(100, (int) ($in['retention_count'] ?? $d['retention_count'])));

    $wzorce = [];
    foreach (explode("\n", (string) ($in['exclusions'] ?? '')) as $linia) {
        $w = evk_backup_sanitize_pattern($linia);
        if ($w !== '' && !in_array($w, $wzorce, true)) $wzorce[] = $w;
        if (count($wzorce) >= 200) break;
    }
    $c['exclusions'] = implode("\n", $wzorce);

    $adres = sanitize_email((string) ($in['notify_address'] ?? ''));
    $c['notify_address'] = $adres ?: '';

    return $c;
}

add_action('admin_init', function () {
    register_setting('evoke_one_backup', EVK_BACKUP_OPTION, [
        'type'              => 'array',
        'sanitize_callback' => 'evk_backup_sanitize',
        'default'           => evk_backup_defaults(),
    ]);
});
