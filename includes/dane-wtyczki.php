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
        'evk_interface', 'evk_lenis', 'evk_motion', 'evk_og', 'evk_parallax', 'evk_parallax_scale',
        'evk_parallax_value', 'evk_rewizje', 'evk_schema', 'evk_security', 'evk_sierotki',
        'evk_sitemap_rules_cleaned', 'evk_theme_color', 'evk_white_label', 'evk_wl_bar_items',
        'evoke_dashboard_active', 'evoke_dashboard_fit_content', 'evoke_dashboard_height', 'evoke_dashboard_mode',
        'evoke_dashboard_page_id', 'evoke_dashboard_remove_help', 'evoke_dashboard_remove_native',
        'evoke_dashboard_scrolling', 'evoke_dashboard_shadow', 'evoke_dashboard_width',
        'evoke_disable_global_comments', 'evoke_move_bricks_bottom', 'evoke_require_reg_to_comment', 'favicon_url',
        // Przekierowania, 404, SMTP, bezpieczeństwo
        'evk_301_enabled', 'evk_404_bot_list', 'evk_404_enabled', 'evk_404_max_logs', 'evk_404_skip_bots',
        'evk_smtp', 'evk_smtp_log', 'evk_blocked_ips', 'evk_failed_logins',
        // Konserwacja
        'maintenance_bypass_hours', 'maintenance_bypass_password', 'maintenance_excluded_paths',
        'maintenance_mode', 'maintenance_page_id',
        // Tłumaczenia
        'evk_tl_fab_enabled', 'evk_tl_module_enabled', 'tl_dd_keys', 'tl_images', 'tl_languages',
        'tl_menu_location', 'tl_pl_flag', 'tl_sitemap_settings', 'tl_translations', 'tl_url_slugs',
        // Snippety
        'evk_snippets_advanced_content', 'evk_snippets_advanced_enabled', 'evk_snippets_enabled',
        'evk_snippets_error_log', 'evk_snippets_fatal_notice', 'evk_snippets_kopia_przed_migracja',
        'evk_snippets_migracja_1139',
        // Newsletter
        'evk_newsletter', 'evk_nl_db_version', 'evk_nl_rewrite_version', 'evk_nl_zablokowane', 'evk_nl_skrot_klucz',
        'evk_nl_wykluczenia',
        // Kopie zapasowe
        'evk_backup', 'evk_backup_alert', 'evk_backup_db_version', 'evk_backup_dir', 'evk_backup_gdrive',
        'evk_backup_key', 'evk_backup_probe', 'evk_backup_sched', 'evk_gdrive_czekanie',
        // Role
        'evk_role_restrictions', 'evk_role_restrictions_notice_1230', 'evk_role_utworzone',
        // Aktualizator i instalacja
        'evk_one_gh_release', 'evk_one_github_token', 'evk_one_install_state', 'evk_usun_dane',
    ],
    'opcje_przedrostki' => ['evk_nl_backoff_'],
    'transienty' => ['evk_301_cache', 'evk_backup_exposed', 'evk_inbox_unread',
                     'tl_compiled_config', 'tl_compiled_slugs', 'tl_inline_phrases'],
    /* evk_nl_rl_: limit zapisów na adres IP; evk_nl_pt_: limit maili
       z potwierdzeniem na adres e-mail (1.233.0). */
    'transienty_przedrostki' => ['evk_gdrive_msg_', 'evk_gdrive_pkce_', 'evk_wl_font_b64_', 'tl_compiled_tokens_',
                                 'evk_nl_rl_', 'evk_nl_pt_'],
    'typy_wpisow' => ['evk_code_snippet', 'evk_301_redirect', 'evk_301_log', 'evk_404_log'],
    'meta_wpisow' => ['_evk_og_disable', '_evk_og_url', '_evk_original_post_id',
                      '_evoke_seo_desc', '_evoke_seo_keywords', '_evoke_seo_robots', '_evoke_seo_title',
                      '_evk_snippet_rodzaj', '_evk_snippet_miejsce', '_evk_snippet_grupa', '_evk_snippet_wlaczony',
                      '_evk_snippet_awaria', '_evk_snippet_ukosniki_ok'],
    'meta_uzytkownikow' => ['evk_avatar_id'],
    'tabele' => ['evk_nl_lists', 'evk_nl_subscribers', 'evk_nl_templates', 'evk_nl_campaigns',
                 'evk_nl_queue', 'evk_nl_logs', 'evk_backup_jobs'],
    /* `evk_access_fields` NIE — to wejście do Evoke Fields, które nadaje się
       w Role Managerze, ale sprawdza je tamta wtyczka. */
    'uprawnienia' => ['manage_evk_roles', 'evk_access_translations', 'evk_access_newsletter',
                      'evk_access_maintenance', 'evk_access_messages'],
    'haki_crona' => ['evk_backup_tick', 'evk_backup_nightly', 'evk_nl_process_batch'],
];
