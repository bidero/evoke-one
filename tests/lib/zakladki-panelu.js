/**
 * Zakładki panelu renderowane przez tests/php/tab.php — jedna lista dla
 * admin-tabs (wygląd), admin-etykiety (nazwy dostępne kontrolek)
 * i potwierdzenie-zapisu (jednolity komunikat po zapisie).
 *
 * Nową zakładkę dopisuje się TUTAJ: wtedy obejmują ją wszystkie trzy naraz.
 * Osobne kopie listy rozjechałyby się przy pierwszej nowej zakładce, a strażnik
 * etykiet nie zgłosiłby, że czegoś nie widzi. Do 1.236.0 potwierdzenie-zapisu
 * wycinało listę wyrażeniem z admin-tabs.test.js — przeniesienie jej tutaj
 * wysypało ten test wyjątkiem, dopiero w pełnym przebiegu.
 */
const TABS = ['forminbox', 'a11y', 'darkmode', 'og', 'whitelabel',
              'schema', 'sitemap', 'seo-meta',
              'nl-lists', 'nl-campaigns', 'nl-templates', 'nl-reports', 'nl-settings',
              'sec-login', 'sec-rest', 'sec-hardening', 'sec-cleanup',
              'tools-smtp', 'tools-redirect', 'tools-logs404', 'tools-io', 'tools-maintenance',
              'adm-interface', 'adm-dashboard', 'adm-avatar', 'adm-content',
              'adm-roles', 'adm-tlumaczenia',
              'fe-cursor', 'fe-lenis', 'fe-bgshift', 'fe-fonts', 'fe-sierotki',
              'fe-themecolor', 'fe-parallax', 'fe-elementy', 'fe-newsletter', 'fe-newsletter-on',
              'backup', 'backup-on',
              /* Tłumaczenia to osobny ekran, ale ładuje ten sam `admin.css`
                 (patrz `tl/bootstrap.php`), więc obowiązuje go ta sama skóra. */
              'tl-translations', 'tl-images', 'tl-slugs', 'tl-dd',
              'tl-languages', 'tl-sitemap', 'tl-io'];

module.exports = { TABS };
