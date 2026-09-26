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
const EVK_TEST_TEL_OPCJE = ['evk_tl_module_enabled', 'evk_newsletter', 'evk_forminbox', 'evk_tl_el_pola'];
const EVK_TEST_TEL_STRONA = 'Test telefon — teksty w elementach';

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
        /* Tłumaczenia → Teksty w elementach (1.245.0): strona z treścią Bricksa
           i mapa pól, żeby tabela miała wiersz — długi tekst w każdej kolumnie. */
        update_option('evk_tl_el_pola', ['heading' => ['pola' => ['text'], 'listy' => []]], false);
        global $wpdb;
        if (!$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TEST_TEL_STRONA))) {
            $id = (int) wp_insert_post(['post_title' => EVK_TEST_TEL_STRONA, 'post_type' => 'page', 'post_status' => 'publish']);
            update_post_meta($id, '_bricks_page_content_2', [['id' => 'tel1ab', 'name' => 'heading', 'settings' => [
                'text' => 'Bardzo długi polski nagłówek, który na telefonie musi się zawinąć albo przewinąć razem z tabelą',
                'evk_tl_en__text' => 'A very long English heading that has to wrap or scroll together with the table on a phone',
            ]]]);
        }
        echo json_encode(['ok' => true, 'wp' => rtrim(ABSPATH, '/')]);
        break;

    case 'przywroc':
        global $wpdb;
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TEST_TEL_STRONA)) as $id) {
            wp_delete_post((int) $id, true);
        }
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
