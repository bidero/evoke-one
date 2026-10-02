<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: tabele (1.283.0).
 *
 * `evk_stat_odslony` — surowe odsłony, po jednej na wczytanie strony. Żyją
 * tyle, ile ustawiono w panelu (domyślnie 90 dni). `wizyta` to skrót z soli
 * dnia, adresu IP i przeglądarki — sól ginie po dobie, więc skrótu nie da się
 * odwrócić ani połączyć z innym dniem. Pełnego adresu IP nie zapisujemy nigdzie.
 *
 * `evk_stat_dni` — zbiorcze dzienne, na zawsze: dla każdego dnia i wymiaru
 * (strona, źródło, urządzenie…) liczby odsłon i unikalnych, suma czasu
 * i przewinięcia. Raport za dni sprzed zbiórki czyta tylko tę tabelę.
 */

/* 2 (1.285.0): tabela zdarzeń. dbDelta dokłada ją do istniejących. */
const EVK_STAT_DB_WERSJA = 2;

function evk_stat_tabela(string $nazwa): string {
    global $wpdb;
    return $wpdb->prefix . 'evk_stat_' . $nazwa;
}

function evk_stat_utworz_tabele(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE " . evk_stat_tabela('odslony') . " (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        czas datetime NOT NULL,
        dzien date NOT NULL,
        klucz char(16) NOT NULL,
        wizyta char(16) NOT NULL,
        sciezka varchar(255) NOT NULL DEFAULT '',
        post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        zrodlo varchar(191) NOT NULL DEFAULT '',
        utm_source varchar(100) NOT NULL DEFAULT '',
        utm_medium varchar(100) NOT NULL DEFAULT '',
        utm_campaign varchar(100) NOT NULL DEFAULT '',
        urzadzenie varchar(10) NOT NULL DEFAULT '',
        przegladarka varchar(20) NOT NULL DEFAULT '',
        system_op varchar(20) NOT NULL DEFAULT '',
        jezyk varchar(10) NOT NULL DEFAULT '',
        czas_s int(10) UNSIGNED NOT NULL DEFAULT 0,
        przewiniecie tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY klucz (klucz),
        KEY dzien_wizyta (dzien,wizyta),
        KEY czas (czas)
    ) $c;");
    dbDelta("CREATE TABLE " . evk_stat_tabela('dni') . " (
        dzien date NOT NULL,
        wymiar varchar(20) NOT NULL,
        wartosc varchar(191) NOT NULL DEFAULT '',
        odslony int(10) UNSIGNED NOT NULL DEFAULT 0,
        unikalni int(10) UNSIGNED NOT NULL DEFAULT 0,
        czas_suma bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        czas_ile int(10) UNSIGNED NOT NULL DEFAULT 0,
        przewiniecie_suma bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (dzien,wymiar,wartosc)
    ) $c;");
    /* Zdarzenia (1.285.0): telefon, e-mail, pobranie, link wychodzący, formularz,
       własne. `klucz` — odsłona, na której zaszło; źródło i kampania wizyty
       liczą się z jej odsłon (cele). */
    dbDelta("CREATE TABLE " . evk_stat_tabela('zdarzenia') . " (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        czas datetime NOT NULL,
        dzien date NOT NULL,
        klucz char(16) NOT NULL,
        wizyta char(16) NOT NULL,
        rodzaj varchar(20) NOT NULL,
        etykieta varchar(191) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY dzien_wizyta (dzien,wizyta),
        KEY czas (czas)
    ) $c;");
    update_option('evk_stat_db_version', EVK_STAT_DB_WERSJA, false);
}

/* Włączenie przełącznikiem (AJAX) tworzy tabele od razu — zanim przyjdzie
   pierwszy beacon. */
add_action('update_option_' . EVK_STAT_OPCJA, function ($stare, $nowe): void {
    if (is_array($nowe) && !empty($nowe['enabled']) && (int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) evk_stat_utworz_tabele();
}, 10, 2);
add_action('add_option_' . EVK_STAT_OPCJA, function ($nazwa, $wartosc): void {
    if (is_array($wartosc) && !empty($wartosc['enabled']) && (int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) evk_stat_utworz_tabele();
}, 10, 2);

/* Tabele powstają przy pierwszym włączeniu modułu (i po zmianie schematu).
   Wyłączony moduł nie tworzy niczego w bazie. */
add_action('admin_init', function (): void {
    if (!evk_stat_wlaczone()) return;
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) evk_stat_utworz_tabele();
});
