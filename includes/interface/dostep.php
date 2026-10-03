<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — dostęp do części panelu bez praw administratora (1.292.0).
 * Decyzje zgłaszającego z 03.10 (docs/plan-kolejka.md): rola typu Manager
 * wchodzi przez Ustawienia → Evoke ONE, a panel pokazuje jej WYŁĄCZNIE ekrany
 * z jej dostępów (edycja roli); resztę zamyka także po adresie.
 *
 * Każdy dostęp to uprawnienie roli i lista ekranów panelu („zakładka/ekran”,
 * sama „zakładka” dla zakładek bez podstron). Zapisy tych ekranów (AJAX,
 * options.php) pytają `evk_moze('…')` zamiast samego `manage_options`.
 *
 * Kopie zapasowe: TYLKO „Utwórz kopię teraz” (bez pobierania, usuwania,
 * przywracania i ustawień). Logowanie: TYLKO lista kont z 2FA i „Wyłącz
 * 2FA” innemu kontu, nie administratorowi.
 */

const EVK_DOSTEPY_PANELU = [
    'seo'            => ['cap' => 'evk_access_seo',            'ekrany' => ['strona/meta', 'strona/sitemap', 'strona/schema', 'strona/og']],
    'przekierowania' => ['cap' => 'evk_access_przekierowania', 'ekrany' => ['narzedzia/redirect', 'narzedzia/logs404']],
    'kopie'          => ['cap' => 'evk_access_kopie',          'ekrany' => ['backup']],
    'logowanie'      => ['cap' => 'evk_access_logowanie',      'ekrany' => ['logowanie/2fa']],
];

/** Administrator albo rola z danym dostępem. */
function evk_moze(string $dostep): bool {
    if (current_user_can('manage_options')) return true;
    $cap = EVK_DOSTEPY_PANELU[$dostep]['cap'] ?? '';
    return $cap !== '' && current_user_can($cap);
}

/** Dostępy panelu zalogowanego, który NIE jest administratorem (pusta lista — administrator albo nikt). @return list<string> */
function evk_dostepy_panelu(): array {
    if (!is_user_logged_in() || current_user_can('manage_options')) return [];
    $ma = [];
    foreach (EVK_DOSTEPY_PANELU as $d => $def) if (current_user_can($def['cap'])) $ma[] = $d;
    return $ma;
}

/** Czy panel ma być przycięty: zalogowany bez praw administratora. */
function evk_panel_przyciety(): bool {
    return is_user_logged_in() && !current_user_can('manage_options');
}

/** Ekrany dozwolone przyciętemu panelowi, np. ['strona/meta', 'backup']. @return list<string> */
function evk_panel_ekrany_dozwolone(): array {
    $e = [];
    foreach (evk_dostepy_panelu() as $d) $e = array_merge($e, EVK_DOSTEPY_PANELU[$d]['ekrany']);
    return $e;
}

/** Uprawnienie, z którym rejestruje się strona panelu: administrator — manage_options, inaczej pierwszy posiadany dostęp. */
function evk_panel_uprawnienie(): string {
    if (current_user_can('manage_options')) return 'manage_options';
    foreach (evk_dostepy_panelu() as $d) return EVK_DOSTEPY_PANELU[$d]['cap'];
    return 'manage_options';
}

/* Administrator ma każdy z tych dostępów od razu (jak pozostałe evk_access_*). */
add_filter('user_has_cap', function (array $caps, array $pytanie) {
    $cap = $pytanie[0] ?? '';
    if (!empty($caps['manage_options']) && in_array($cap, array_column(EVK_DOSTEPY_PANELU, 'cap'), true)) $caps[$cap] = true;
    return $caps;
}, 1, 2);

/* Schema i OpenGraph zapisują się przez options.php — grupa opcji z dostępem SEO. */
foreach (['evoke_one_schema', 'evoke_one_og'] as $evk_grupa) {
    add_filter('option_page_capability_' . $evk_grupa, function ($cap) {
        return !current_user_can('manage_options') && evk_moze('seo') ? EVK_DOSTEPY_PANELU['seo']['cap'] : $cap;
    });
}
unset($evk_grupa);

/** Przełączniki z przeglądu i z ekranów (evk_ajax_toggle) dozwolone przy dostępie: opcja => dostęp. */
function evk_dostep_przelacznika(string $opcja): string {
    $mapa = ['evk_schema' => 'seo', 'evk_og' => 'seo', 'evk_301_enabled' => 'przekierowania', 'evk_404_enabled' => 'przekierowania',
        'evk_404_skip_bots' => 'przekierowania'];
    return $mapa[$opcja] ?? '';
}
