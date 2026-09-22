<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — tabela zadań.
 *
 * Zadanie (kopia albo restore) to rekord w tabeli, nie opcja WP, z dwóch
 * powodów:
 *
 * 1. LOCK MUSI BYĆ ATOMOWY. Tick zdobywa go warunkowym UPDATE-em
 *    (`… WHERE id = ? AND lock_until < ?`) i sprawdza liczbę zmienionych
 *    wierszy. Transient to osobny odczyt i zapis — dwa równoległe żądania
 *    wp-cron zdobyłyby go oba.
 *
 * 2. RESTORE PODMIENIA TABELĘ OPCJI. Stan zadania trzymany w `wp_options`
 *    zniknąłby razem z nią w chwili przełączenia tabel. Ta tabela jest
 *    wykluczona i z kopii, i z importu, więc przeżywa restore nietknięta.
 *
 * Czasy lock/heartbeat to znaczniki UNIX (int), nie DATETIME: porównujemy je
 * z time() z PHP-a, a NOW() serwera bazy potrafi stać w innej strefie.
 */

const EVK_BACKUP_DB_VERSION = '1.0.0';

function evk_backup_jobs_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'evk_backup_jobs';
}

function evk_backup_create_tables(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $t       = evk_backup_jobs_table();

    /* dbDelta jest wybredny: dwie spacje po PRIMARY KEY, każde pole w osobnej
       linii, KEY zamiast INDEX. Odstępstwo nie daje błędu, tylko ciche
       ALTER-y przy każdym porównaniu. */
    dbDelta("CREATE TABLE $t (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        type varchar(20) NOT NULL DEFAULT 'backup',
        source varchar(20) NOT NULL DEFAULT 'manual',
        status varchar(20) NOT NULL DEFAULT 'queued',
        phase varchar(30) NOT NULL DEFAULT '',
        archive varchar(255) NOT NULL DEFAULT '',
        state longtext DEFAULT NULL,
        next_job_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        lock_until int(10) UNSIGNED NOT NULL DEFAULT 0,
        heartbeat int(10) UNSIGNED NOT NULL DEFAULT 0,
        budget_ms int(10) UNSIGNED NOT NULL DEFAULT 0,
        ticks int(10) UNSIGNED NOT NULL DEFAULT 0,
        kills int(10) UNSIGNED NOT NULL DEFAULT 0,
        progress_done bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        progress_total bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        log longtext DEFAULT NULL,
        error text DEFAULT NULL,
        created_at int(10) UNSIGNED NOT NULL DEFAULT 0,
        started_at int(10) UNSIGNED NOT NULL DEFAULT 0,
        finished_at int(10) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY status (status)
    ) $charset;");

    update_option('evk_backup_db_version', EVK_BACKUP_DB_VERSION, false);
}

/**
 * Tabela powstaje dopiero przy włączonym module — wyłączony moduł nie zostawia
 * w bazie śladu. Na czystej instalacji, na której ktoś chce odtworzyć kopię,
 * włączenie modułu jest i tak pierwszym krokiem.
 */
function evk_backup_maybe_create_tables(): void {
    if (!evk_backup_enabled()) return;
    if (get_option('evk_backup_db_version', '') === EVK_BACKUP_DB_VERSION) return;
    evk_backup_create_tables();
}

add_action('plugins_loaded', 'evk_backup_maybe_create_tables');

/* Włącznik w panelu zapisuje opcję przez AJAX — już PO plugins_loaded, więc
   bez tego tabela powstałaby dopiero przy następnym wczytaniu dowolnej strony,
   a zakładka tuż po włączeniu mówiłaby „brak tabeli". Pierwszy zapis opcji
   idzie przez add_option, każdy kolejny przez update_option. */
add_action('add_option_' . EVK_BACKUP_OPTION, 'evk_backup_maybe_create_tables');
add_action('update_option_' . EVK_BACKUP_OPTION, 'evk_backup_maybe_create_tables');
