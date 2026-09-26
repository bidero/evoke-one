<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Panel na telefonie (1.240.0) — moduły z własnymi ekranami włączone na czas
 * testu, żeby ich strony w ogóle istniały.
 *
 *   php tests/php/admin-telefon.php ustaw      Tłumaczenia, Newsletter, Skrzynka włączone
 *   php tests/php/admin-telefon.php przywroc   stan sprzed testu
 *
 * Pierwsze „ustaw" odkłada stan do osobnej opcji (tylko raz — przerwany
 * przebieg nie nadpisze kopii wartościami testu).
 */
require __DIR__ . '/_testowy-wp.php';

const EVK_TEST_TEL_KOPIA = 'evk_test_telefon_kopia';
const EVK_TEST_TEL_OPCJE = ['evk_tl_module_enabled', 'evk_newsletter', 'evk_forminbox'];

switch ($argv[1] ?? '') {
    case 'ustaw':
        if (get_option(EVK_TEST_TEL_KOPIA, null) === null) {
            $kopia = [];
            foreach (EVK_TEST_TEL_OPCJE as $o) $kopia[$o] = get_option($o, null);
            update_option(EVK_TEST_TEL_KOPIA, $kopia, false);
        }
        update_option('evk_tl_module_enabled', 1);
        foreach (['evk_newsletter', 'evk_forminbox'] as $o) {
            $v = get_option($o, []);
            update_option($o, array_merge(is_array($v) ? $v : [], ['enabled' => 1]));
        }
        echo json_encode(['ok' => true, 'wp' => rtrim(ABSPATH, '/')]);
        break;

    case 'przywroc':
        $kopia = get_option(EVK_TEST_TEL_KOPIA, null);
        if (is_array($kopia)) {
            foreach (EVK_TEST_TEL_OPCJE as $o) {
                if (($kopia[$o] ?? null) === null) delete_option($o);
                else update_option($o, $kopia[$o]);
            }
            delete_option(EVK_TEST_TEL_KOPIA);
        }
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['brak' => 'nieznane polecenie: ' . ($argv[1] ?? '')]);
}
