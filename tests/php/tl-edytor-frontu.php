<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Edytor tłumaczeń na froncie (1.239.0) — stan testowego WordPressa.
 *
 *   php tests/php/tl-edytor-frontu.php ustaw      moduł i edytor włączone, jedna fraza
 *   php tests/php/tl-edytor-frontu.php stan       wiersze słownika (co zapisał serwer)
 *   php tests/php/tl-edytor-frontu.php przywroc   stan sprzed testu
 *
 * Fraza to tytuł wpisu „Hello world!", który testowy WordPress ma od
 * instalacji, więc widać ją na stronie głównej bez Bricksa. Pierwsze „ustaw"
 * odkłada stan sprzed testu do osobnej opcji (tylko raz — przerwany przebieg
 * nie nadpisze kopii wartościami testu).
 */
require __DIR__ . '/_testowy-wp.php';

const EVK_TEST_TL_KOPIA = 'evk_test_tl_edytor_kopia';
const EVK_TEST_TL_OPCJE = ['evk_tl_module_enabled', 'evk_tl_fab_enabled', 'tl_translations', 'tl_dd_keys', 'tl_languages'];

/** Pamięć podręczna słownika — te same klucze co tl_invalidate_cache(). */
function evk_test_tl_czysc_cache(): void {
    foreach (['tl_compiled_config', 'tl_inline_phrases', 'tl_compiled_slugs', 'tl_compiled_tokens_en', 'tl_compiled_tokens_de'] as $t) {
        delete_transient($t);
    }
}

switch ($argv[1] ?? '') {
    case 'ustaw':
        if (get_option(EVK_TEST_TL_KOPIA, null) === null) {
            $kopia = [];
            foreach (EVK_TEST_TL_OPCJE as $o) $kopia[$o] = get_option($o, null);
            update_option(EVK_TEST_TL_KOPIA, $kopia, false);
        }
        update_option('evk_tl_module_enabled', 1);
        update_option('evk_tl_fab_enabled', 1);
        update_option('tl_languages', [
            ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
            ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
        ]);
        update_option('tl_translations', ['groups' => ['g_test' => ['name' => 'Test', 'rows' => [
            'r_hello' => ['pl' => 'Hello world!', 'dd_key' => '', 'en' => 'Hello EN', 'de' => 'Hallo DE'],
        ]]]]);
        update_option('tl_dd_keys', []);
        evk_test_tl_czysc_cache();
        echo json_encode(['ok' => true, 'wp' => rtrim(ABSPATH, '/')]);
        break;

    case 'stan':
        $wiersze = [];
        foreach ((get_option('tl_translations', [])['groups'] ?? []) as $g) {
            foreach (($g['rows'] ?? []) as $r) $wiersze[] = $r;
        }
        echo json_encode(['wiersze' => $wiersze], JSON_UNESCAPED_UNICODE);
        break;

    case 'przywroc':
        $kopia = get_option(EVK_TEST_TL_KOPIA, null);
        if (is_array($kopia)) {
            foreach (EVK_TEST_TL_OPCJE as $o) {
                if (($kopia[$o] ?? null) === null) delete_option($o);
                else update_option($o, $kopia[$o]);
            }
            delete_option(EVK_TEST_TL_KOPIA);
        }
        evk_test_tl_czysc_cache();
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['brak' => 'nieznane polecenie: ' . ($argv[1] ?? '')]);
}
