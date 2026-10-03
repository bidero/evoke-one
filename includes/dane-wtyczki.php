<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — spis danych, które wtyczka zostawia w WordPressie.
 *
 * Czyta go deaktywacja (haki crona), odinstalowanie z „Usuń dane"
 * (uninstall.php) i test tests/zapis-wp-odinstalowanie.test.js, który
 * pilnuje, żeby każda opcja używana w kodzie była tutaj.
 *
 * WYŁĄCZNIE DOKŁADNE NAZWY, nie przedrostki. Evoke Fields używa tego samego
 * `evk_` — m.in. opcji `evk_custom_post_types`, `evk_taxonomies`,
 * `evk_config_vault`, meta `_evk_access_key` i katalogów `evk-backups-*`.
 * Kasowanie „wszystkiego, co zaczyna się od evk_" zabrałoby własne typy
 * treści, taksonomie i sejf konfiguracji innej wtyczki. Przedrostek wolno
 * tylko tam, gdzie nazwa i tak jest dynamiczna i ściśle nasza.
 *
 * Plik bez efektów ubocznych: zwraca tablicę. Działa też bez reszty wtyczki
 * (odinstalowanie woła go, gdy wtyczka jest już wyłączona).
 */
return [
    'opcje' => [
        // Moduły i ich ustawienia
        'evk_a11y', 'evk_animator', 'evk_bgshift', 'evk_cleanup', 'evk_cursor', 'evk_darkmode',
        'evk_draft_revision_enabled', 'evk_elements', 'evk_fonts', 'evk_forminbox', 'evk_forminbox_read',
        'evk_interface', 'evk_lenis', 'evk_motion', 'evk_obrazy', 'evk_obrazy_blokada', 'evk_obrazy_przebieg', 'evk_og', 'evk_parallax', 'evk_parallax_scale',
        'evk_parallax_value', 'evk_rewizje', 'evk_schema', 'evk_security', 'evk_sierotki',
        'evk_sitemap_rules_cleaned', 'evk_theme_color', 'evk_white_label', 'evk_wl_bar_items',
        'evoke_dashboard_active', 'evoke_dashboard_fit_content', 'evoke_dashboard_height', 'evoke_dashboard_mode',
        'evoke_dashboard_page_id', 'evoke_dashboard_remove_help', 'evoke_dashboard_remove_native',
        'evoke_dashboard_scrolling', 'evoke_dashboard_shadow', 'evoke_dashboard_width',
        'evoke_disable_global_comments', 'evoke_move_bricks_bottom', 'evoke_require_reg_to_comment', 'favicon_url',
        // Przekierowania, 404, SMTP, bezpieczeństwo
        'evk_301_enabled', 'evk_404_bot_list', 'evk_404_db_version', 'evk_404_enabled', 'evk_404_max_logs', 'evk_404_skip_bots',
        'evk_smtp', 'evk_smtp_log', 'evk_blocked_ips', 'evk_failed_logins',
        // Konserwacja
        'maintenance_bypass_hours', 'maintenance_bypass_password', 'maintenance_excluded_paths',
        'maintenance_mode', 'maintenance_page_id',
        // Tłumaczenia
        'evk_tl_ai', 'evk_tl_ai_pamiec', 'evk_tl_deepl_glosariusze', 'evk_tl_google_glosariusze', 'evk_tl_frazy_ai', 'evk_tl_el_pola', 'evk_tl_kp_stan', 'evk_tl_kp_przejscie', 'evk_tl_module_enabled', 'tl_dd_keys', 'tl_images', 'tl_languages',
        'tl_menu_location', 'tl_pl_flag', 'tl_sitemap_settings', 'tl_translations', 'tl_url_slugs',
        // Snippety
        'evk_snippets_advanced_content', 'evk_snippets_advanced_enabled', 'evk_snippets_enabled',
        'evk_snippets_error_log', 'evk_snippets_fatal_notice', 'evk_snippets_kopia_przed_migracja',
        'evk_snippets_migracja_1139',
        // Newsletter
        'evk_newsletter', 'evk_nl_db_version', 'evk_nl_rewrite_version', 'evk_nl_zablokowane', 'evk_nl_skrot_klucz',
        'evk_nl_wykluczenia',
        // Statystyki (1.283.0)
        'evk_statystyki', 'evk_stat_db_version', 'evk_stat_sol', 'evk_stat_zebrane', 'evk_stat_cele', 'evk_stat_dbip', 'evk_stat_hotspoty',
        // Logowanie dwuetapowe (1.287.0)
        'evk_2fa', 'evk_2fa_dziennik',
        // Ukryty adres logowania (1.288.0)
        'evk_ukryty_adres',
        // Kopie zapasowe
        'evk_backup', 'evk_backup_alert', 'evk_backup_db_version', 'evk_backup_dir', 'evk_backup_gdrive',
        'evk_backup_key', 'evk_backup_probe', 'evk_backup_sched', 'evk_gdrive_czekanie',
        // Role
        'evk_role_restrictions', 'evk_role_restrictions_notice_1230', 'evk_role_utworzone',
        // Aktualizator i instalacja
        'evk_one_gh_release', 'evk_one_github_token', 'evk_one_install_state', 'evk_usun_dane',
    ],
    /* Opcje, których kod już nie używa, a które mogły zostać na starszych
       stronach — odinstalowanie kasuje je jak zwykłe. Osobno, bo strażnik
       spisu (zapis-wp-odinstalowanie) wymaga, żeby każda nazwa z `opcje`
       występowała w kodzie. `evk_tl_fab_enabled`: edytor tłumaczeń na
       froncie, usunięty w 1.243.0 (jego transient `tl_inline_phrases` zostaje
       w `transienty` — nazwę trzyma stała TL_TRANSIENT_INLINE). */
    'opcje_dawne' => ['evk_tl_fab_enabled'],
    'opcje_przedrostki' => ['evk_nl_backoff_'],
    'transienty' => ['evk_301_cache', 'evk_backup_exposed', 'evk_inbox_unread',
                     'tl_compiled_config', 'tl_compiled_slugs', 'tl_inline_phrases'],
    /* evk_nl_rl_: limit zapisów na adres IP; evk_nl_pt_: limit maili
       z potwierdzeniem na adres e-mail (1.233.0). */
    'transienty_przedrostki' => ['evk_gdrive_msg_', 'evk_gdrive_pkce_', 'evk_wl_font_b64_', 'tl_compiled_tokens_',
                                 'evk_nl_rl_', 'evk_nl_pt_',
                                 // błędy adresu EN wpisu po zapisie, na użytkownika (1.252.0)
                                 'evk_tlw_bledy_',
                                 // token dostępu Google Cloud Translation, na konto usługi (1.280.0)
                                 'evk_tl_google_token_',
                                 // token drugiego kroku logowania 2FA (1.287.0)
                                 'evk_2fa_t_'],
    'typy_wpisow' => ['evk_code_snippet', 'evk_301_redirect', 'evk_301_log', 'evk_404_log'],
    'meta_wpisow' => ['_evk_og_disable', '_evk_og_url', '_evk_original_post_id',
                      '_evoke_seo_desc', '_evoke_seo_keywords', '_evoke_seo_robots', '_evoke_seo_title',
                      '_evk_snippet_rodzaj', '_evk_snippet_miejsce', '_evk_snippet_grupa', '_evk_snippet_wlaczony',
                      '_evk_snippet_awaria', '_evk_snippet_ukosniki_ok',
                      // Tłumaczenia w elementach: stan „Do sprawdzenia" (1.242.0), wykaz dopisanych (1.246.0)
                      // i kopia „Wyczyść tłumaczenia strony” (1.264.0).
                      '_evk_tl_el_stan', '_evk_tl_el_dopisane', '_evk_tl_ai_wyczyszczone',
                      // Znacznik polskiego altu napisanego przez AI, „do sprawdzenia” (1.271.0).
                      '_evk_alt_ai',
                      // Wersje WebP/AVIF załącznika: format => jakość. Pliki `….jpg.webp`
                      // i `….jpg.avif` kasuje odinstalowanie po tej liście (uninstall.php).
                      '_evk_obrazy'],
    /* Wersje językowe pól wpisu: `_evk_tl_{język}__{pole}` — kod języka jest
       dynamiczny, więc przedrostek (1.251.0: pola SEO). Tylko z podkreślnikiem
       na początku: Evoke Fields trzyma swoje tłumaczenia pod `evk_tl_…`. */
    'meta_wpisow_przedrostki' => ['_evk_tl_'],
    /* Wersje językowe nazw i opisów termów (1.253.0): `_evk_tl_{język}__name`,
       `…__description` — ten sam przedrostek, w metadanych termów. */
    'meta_termow_przedrostki' => ['_evk_tl_'],
    'meta_uzytkownikow' => ['evk_avatar_id', 'evk_2fa'],
    'tabele' => ['evk_nl_lists', 'evk_nl_subscribers', 'evk_nl_templates', 'evk_nl_campaigns',
                 'evk_nl_queue', 'evk_nl_logs', 'evk_backup_jobs', 'evk_404',
                 'evk_stat_odslony', 'evk_stat_dni', 'evk_stat_zdarzenia',
                 'evk_stat_kraje', 'evk_stat_kraje_nowe', 'evk_stat_hot_odslony', 'evk_stat_hot_kliki'],
    /* `evk_access_fields` NIE — to wejście do Evoke Fields, które nadaje się
       w Role Managerze, ale sprawdza je tamta wtyczka. */
    'uprawnienia' => ['manage_evk_roles', 'evk_access_translations', 'evk_access_newsletter',
                      'evk_access_maintenance', 'evk_access_messages', 'evk_access_stats', 'evk_access_hotspoty'],
    'haki_crona' => ['evk_backup_tick', 'evk_backup_nightly', 'evk_nl_process_batch', 'evk_obrazy_tick', 'evk_stat_dobowy', 'evk_stat_dbip'],
];
