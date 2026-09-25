<?php
/**
 * Plugin Name: Evoke ONE
 * Description: Zintegrowany zestaw narzędzi Evoke Design Studio — Tłumaczenia, Parallax, Konserwacja.
 * Version: 1.237.0
 * Author: Evoke Design Studio
 * Text Domain: evoke-one
 */

if (!defined('ABSPATH')) exit;

/* DRUGA KOPIA WTYCZKI (1.233.1). Gdy na stronie aktywne są dwa katalogi
   z Evoke ONE naraz — np. „evoke-one-main" i kopia z gałęzi roboczej obok,
   a przywrócenie kopii zapasowej do 1.233.0 umiało zostawić aktywne obie —
   druga wywracała stronę błędem „Cannot redeclare function". Działa kopia,
   która wczytała się pierwsza; ta kończy tutaj, zanim cokolwiek zdefiniuje,
   i mówi o tym w panelu.

   Dlatego w TYM pliku nie ma żadnej deklaracji funkcji ani klasy: PHP wiąże
   je przy kompilacji pliku, czyli PRZED tym warunkiem, i wczesny `return`
   by ich nie zatrzymał (tests/zapis-wp-dwie-kopie.test.js). */
if (defined('EVOKE_ONE_FILE')) {
    $evoke_one_druga_kopia = __FILE__;
    add_action('admin_notices', static function () use ($evoke_one_druga_kopia) {
        if (!current_user_can('activate_plugins') || !empty($GLOBALS['evoke_one_kopie_zgloszone'])) return;
        $GLOBALS['evoke_one_kopie_zgloszone'] = true;
        printf(
            '<div class="notice notice-error" data-evk-druga-kopia><p><strong>Evoke ONE jest włączony więcej niż raz.</strong> Działa kopia %1$s, a kopia %2$s się nie uruchomiła — wyłącz ją w Wtyczkach.</p></div>',
            '<code>' . esc_html(plugin_basename(EVOKE_ONE_FILE)) . '</code>',
            '<code>' . esc_html(plugin_basename($evoke_one_druga_kopia)) . '</code>'
        );
    });
    return;
}

// =========================================================================
// STAŁE GLOBALNE
// =========================================================================

define('EVOKE_ONE_FILE',    __FILE__);
define('EVOKE_ONE_DIR',     plugin_dir_path(__FILE__));
define('EVOKE_ONE_URL',     plugin_dir_url(__FILE__));
/* UWAGA: ta liczba MUSI być tożsama z `Version:` w nagłówku wyżej.
   Nie jest to kosmetyka — WordPress dokleja ją jako `?ver=` do adresów
   `admin.css` i `admin.js`, więc stała pozostawiona w tyle każe
   przeglądarkom podawać stare pliki z pamięci mimo aktualizacji wtyczki.
   Zgodności trzech miejsc (nagłówek, stała, changelog) pilnuje sekcja
   „numer wersji w trzech miejscach" w tests/drobiazgi.test.js. */
define('EVOKE_ONE_VERSION', '1.237.0');

/* DEAKTYWACJA: bez zadań w cronie i bez naszych reguł adresów. Do 1.231.x
   wyłączona wtyczka zostawiała zaplanowane kroki kopii i wysyłki newslettera,
   które WP-Cron odpalał dalej w próżnię. Rejestrowana PRZED sprawdzeniem
   kolizji niżej — wtyczka zablokowana konfliktem też ma po sobie posprzątać.
   Danych nie kasuje: to robi dopiero odinstalowanie z „Usuń dane"
   (uninstall.php). */
register_deactivation_hook(__FILE__, static function (): void {
    $dane = require __DIR__ . '/includes/dane-wtyczki.php';
    foreach ($dane['haki_crona'] as $hak) wp_unschedule_hook($hak);
    delete_option('rewrite_rules');   // WordPress zbuduje reguły od nowa, już bez naszych
});

// Stałe modułu tłumaczeń (zachowane dla kompatybilności z istniejącymi ustawieniami)
define('TL_MENU_SLUG',        'evoke-tlumaczenia');
define('TL_MENU_TITLE',       'Tłumaczenia');
define('TL_VERSION',          EVOKE_ONE_VERSION . ' Evoke Design Studio');
define('TL_TRANSIENT_CONFIG', 'tl_compiled_config');
define('TL_TRANSIENT_TOKENS', 'tl_compiled_tokens_');
define('TL_TRANSIENT_INLINE', 'tl_inline_phrases');
define('TL_TRANSIENT_SLUGS',  'tl_compiled_slugs');
define('TL_CACHE_TTL',        DAY_IN_SECONDS * 7);

// Aliasy dla kompatybilności wstecznej
define('EVOKE_TL_FILE', __FILE__);
define('EVOKE_TL_DIR',  EVOKE_ONE_DIR);
define('EVOKE_TL_URL',  EVOKE_ONE_URL);

// =========================================================================
// SPRAWDZENIE KOLIZJI
// =========================================================================

// Funkcja w osobnym pliku — patrz strażnik drugiej kopii na górze.
require_once EVOKE_ONE_DIR . 'includes/00-kolizje.php';

if (function_exists('tl_get_languages') || evoke_one_kolizje()) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Evoke One:</strong> Wykryto konflikt z inną wtyczką Evoke. Wyłącz poprzednie wersje (Tłumaczenia, Parallax, Konserwacja) przed uruchomieniem Evoke One.</p></div>';
    });
    return;
}

// =========================================================================
// ŁADOWANIE MODUŁÓW
// =========================================================================

// ── Pliki płaskie (tłumaczenia, SEO, moduły) ─────────────────────────────
$evoke_one_modules = [
    '00-context-safety.php',
    '01-install.php',
    '20-helpers-cache-inline.php',
    '30-admin-settings-ajax.php',
    '31-admin-page.php',
    /* Mapa strony steruje `wp-sitemap.xml`, czyli czymś, co WordPress wystawia
       na każdej stronie — także bez tłumaczeń. Do 1.220.0 plik siedział
       w gałęzi modułu tłumaczeń niżej i na stronie bez tłumaczeń nie było
       czym sterować: ani typami treści, ani taksonomiami, ani użytkownikami. */
    '80-sitemap.php',
    '85-seo.php',
    '86-dashboard.php',
    '87-snippets.php',
    '86-avatar.php',
    '89-gsap.php',
    '90-schema.php',
    '91-fonts.php',
    '91-sierotki.php',
    '91-theme-color.php',
    '92-parallax.php',
    '93-darkmode.php',
    '94-cursor.php',
    '95-maintenance.php',
    '96-scroll-lock.php',
    '96-lenis.php',
    '97-opengraph.php',
    '98-accessibility.php',
    '99-github-updater.php',
];

foreach ($evoke_one_modules as $module) {
    require_once EVOKE_ONE_DIR . 'includes/' . $module;
}

// ── Moduł tłumaczeń (warunkowo) ───────────────────────────────────────────
// Ładowany w całości tylko gdy włączony. Admin page (31-admin-page.php) ładowany
// zawsze — żeby toggle był dostępny nawet gdy moduł wyłączony.
$evk_tl_enabled = !empty(get_option('evk_tl_module_enabled', 0));
if ($evk_tl_enabled) {
    $evoke_tl_modules = [
        '10-language-system.php',
        '11-bricks-conditions.php',
        '12-seo-url-filters.php',
        '40-dynamic-data-shortcode.php',
        '41-frontend-inline-editor.php',
        '50-translation-engine.php',
        '60-image-replacement.php',
        '70-bricks-language-switcher.php',
    ];
    foreach ($evoke_tl_modules as $module) {
        require_once EVOKE_ONE_DIR . 'includes/' . $module;
    }
}

// ── Admin panel (podfolder) ───────────────────────────────────────────────
// Tylko helpers i page.php ładowane globalnie.
// Pliki zakładek (tab-*.php) są ładowane przez require wewnątrz evoke_one_render_settings()
// WYŁĄCZNIE gdy renderujemy stronę admina — nie przy każdym requescie.
require_once EVOKE_ONE_DIR . 'includes/admin/helpers.php';
require_once EVOKE_ONE_DIR . 'includes/admin/page.php';

// ── Security (podfolder) ──────────────────────────────────────────────────
$evoke_security_modules = [
    'security/ip-klienta.php',   // adres odwiedzającego: limit logowań, newsletter, logi 404
    'security/settings.php',
    'security/login-limit.php',
    'security/hide-version.php',
    'security/bundled-themes.php',
    'security/rest-api.php',
];
foreach ($evoke_security_modules as $module) {
    require_once EVOKE_ONE_DIR . 'includes/' . $module;
}

// ── Interface (podfolder) ─────────────────────────────────────────────────
$evoke_interface_modules = [
    'interface/thumbnails.php',
    'interface/white-label.php',
];
foreach ($evoke_interface_modules as $module) {
    require_once EVOKE_ONE_DIR . 'includes/' . $module;
}

// ── Tools (podfolder) ────────────────────────────────────────────────────
$evoke_tools_modules = [
    'tools/redirect-301.php',    // przekierowania 301 — musi być przed 404 (evk_301_has_redirect)
    'tools/logs-404.php',        // logi 404
    'tools/smtp.php',            // SMTP + logi maili
    'tools/draft-revision.php',  // wersje robocze postów
    'tools/rewizje.php',         // przegląd i sprzątanie rewizji w bazie
];
foreach ($evoke_tools_modules as $module) {
    require_once EVOKE_ONE_DIR . 'includes/' . $module;
}

// ── Form Inbox ───────────────────────────────────────────────────────────
require_once EVOKE_ONE_DIR . 'includes/88-form-inbox.php';

// ── Backup (moduł narzędziowy z własną zakładką) ─────────────────────────
require_once EVOKE_ONE_DIR . 'includes/backup/settings.php';
require_once EVOKE_ONE_DIR . 'includes/backup/tables.php';
require_once EVOKE_ONE_DIR . 'includes/backup/storage.php';
require_once EVOKE_ONE_DIR . 'includes/backup/environment.php';
// Silnik kopii — wyłącznie przy włączonym module (jak kolejka newslettera).
if (evk_backup_enabled()) {
    foreach (['zip-writer', 'zip-reader', 'serialize-replace', 'db-dump', 'file-collector', 'manifest', 'engine',
              'restore', 'schedule', 'upload', 'gdrive', 'ajax'] as $evk_backup_plik) {
        require_once EVOKE_ONE_DIR . 'includes/backup/' . $evk_backup_plik . '.php';
    }
}
require_once EVOKE_ONE_DIR . 'includes/bricks-elements/loader.php';
require_once EVOKE_ONE_DIR . 'includes/anim/motion.php';
require_once EVOKE_ONE_DIR . 'includes/anim/animator.php';
require_once EVOKE_ONE_DIR . 'includes/anim/bgshift.php';
require_once EVOKE_ONE_DIR . 'includes/anim/bricks-controls.php';
require_once EVOKE_ONE_DIR . 'includes/97-security.php';

// ── Admin extras (logika bez renderowania) ───────────────────────────────
require_once EVOKE_ONE_DIR . 'includes/admin/role-manager-logic.php';

// ── Newsletter (warunkowo — tylko gdy moduł aktywny) ──────────────────────
require_once EVOKE_ONE_DIR . 'includes/newsletter/tables.php';
require_once EVOKE_ONE_DIR . 'includes/newsletter/mailer.php';
require_once EVOKE_ONE_DIR . 'includes/newsletter/lists.php';
require_once EVOKE_ONE_DIR . 'includes/newsletter/import.php';   // podgląd, mapowanie, wykluczenia (1.233.0)
require_once EVOKE_ONE_DIR . 'includes/newsletter/campaigns.php';
require_once EVOKE_ONE_DIR . 'includes/newsletter/tracking.php';
require_once EVOKE_ONE_DIR . 'includes/newsletter/menu.php';

$evk_nl_opts = get_option('evk_newsletter', []);
if (!empty($evk_nl_opts['enabled'])) {
    require_once EVOKE_ONE_DIR . 'includes/newsletter/queue.php';
}

require_once EVOKE_ONE_DIR . 'includes/newsletter/ajax.php';
require_once EVOKE_ONE_DIR . 'includes/newsletter/public.php';
require_once EVOKE_ONE_DIR . 'includes/newsletter/bricks.php';   // akcja formularza Bricksa (1.233.0)
require_once EVOKE_ONE_DIR . 'includes/newsletter/settings.php';

// ── RODO: eksport i usuwanie danych osoby, tekst polityki (zawsze) ────────
require_once EVOKE_ONE_DIR . 'includes/prywatnosc.php';

// ── Strony techniczne: Kokpit i zasłona konserwacji tylko dla zalogowanych ──
require_once EVOKE_ONE_DIR . 'includes/strony-techniczne.php';

// (Rejestracja ustawień Schema odbywa się w includes/90-schema.php —
//  wcześniejsza duplikacja tutaj nadpisywała argumenty rejestracji.)

// =========================================================================
// USUŃ SEKCJĘ USERS Z WP SITEMAP (warunkowo)
// =========================================================================

add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
    if ($name === 'users') {
        if (!function_exists('tl_get_sitemap_settings')) return false;
        $settings = tl_get_sitemap_settings();
        if (empty($settings['include_users'])) return false;
    }
    return $provider;
}, 10, 2);

// =========================================================================
// FLUSH REWRITE ON ACTIVATION
// =========================================================================

register_activation_hook(__FILE__, function () {
    evk_one_maybe_install();
    evk_nl_create_tables();
    evk_nl_flush_rewrite();
});
