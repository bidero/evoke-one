<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — metaboks „SEO” w edycji wpisu (1.271.0, decyzja zgłaszającego).
 *
 * Te same dane co zakładka SEO: tytuł, opis i słowa kluczowe po polsku
 * (`_evoke_seo_*`) i — przy włączonych Tłumaczeniach — ich wersje językowe
 * (`_evk_tl_{język}__seo_*` ze źródłem). Przy wersji językowej ✦ „Przetłumacz”
 * i znak „AI — do sprawdzenia” (evk_seo_narzedzia_pola(), tl-seo-ai.js).
 * Wartość z ustawień strony Bricksa ma na stronie pierwszeństwo — metaboks ją
 * pokazuje pod polem polskim, jak zakładka. Robots zostają w zakładce SEO.
 *
 * Zapis z formularzem wpisu (`save_post`), nonce i prawo edycji wpisu.
 */

/* Polskie pola SEO wpisu — te same metadane co zakładka SEO. */
const EVK_SEO_MB_META = ['title' => '_evoke_seo_title', 'desc' => '_evoke_seo_desc', 'keywords' => '_evoke_seo_keywords'];

/** Typy treści z metaboksem: jak w zakładce SEO — publiczne, bez załączników. @return list<string> */
function evk_seo_mb_typy(): array {
    return array_values(array_diff(array_keys(get_post_types(['public' => true])), ['attachment']));
}

add_action('add_meta_boxes', function () {
    foreach (evk_seo_mb_typy() as $typ) {
        add_meta_box('evk_seo_mb', 'SEO', 'evk_seo_mb_render', $typ, 'normal', 'low');
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
    $s = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$s || !in_array((string) $s->post_type, evk_seo_mb_typy(), true)) return;
    evk_seo_ai_zasoby();
});

/** Treść metaboksu. */
function evk_seo_mb_render(WP_Post $post): void {
    $pid = (int) $post->ID;
    $wartosci = evk_seo_wartosci_panelu($pid);
    $jezyki = evk_seo_jezyki();
    $tytul = trim(wp_strip_all_tags(html_entity_decode(get_the_title($pid), ENT_QUOTES, 'UTF-8')));
    $pola = ['title' => ['Tytuł SEO', 'input'], 'desc' => ['Opis SEO', 'textarea'], 'keywords' => ['Słowa kluczowe', 'input']];
    wp_nonce_field('evk_seo_mb', 'evk_seo_mb_nonce');
    echo '<div class="evk-seo-mb" data-evk-seo-post="' . esc_attr((string) $pid) . '" data-tytul="' . esc_attr($tytul) . '">';
    if ($jezyki) {
        echo '<div class="evk-seo-mb-przelacznik" role="group" aria-label="Wersja językowa pól SEO">';
        foreach (array_merge(['pl' => 'Polski'], $jezyki) as $kod => $nazwa) {
            $pl = $kod === 'pl';
            echo '<button type="button" class="button' . ($pl ? ' button-primary' : '') . ' evk-tl-jezyk" data-lang="' . esc_attr($kod) . '" aria-pressed="'
                . ($pl ? 'true' : 'false') . '"><span aria-hidden="true">' . esc_html(strtoupper($kod)) . '</span><span class="screen-reader-text">' . esc_html($nazwa) . '</span></button>';
        }
        echo '</div>';
    }
    /* Polska wersja: pola zakładki SEO. */
    echo '<div class="evk-seo-mb-wersja" data-lang="pl">';
    foreach ($pola as $klucz => [$etykieta, $typ]) {
        $id = 'evk-seo-mb-pl-' . $klucz;
        $w = (string) get_post_meta($pid, EVK_SEO_MB_META[$klucz], true);
        echo '<label class="evk-seo-mb-etykieta" for="' . esc_attr($id) . '">' . esc_html($etykieta) . '</label>';
        echo $typ === 'textarea'
            ? '<textarea id="' . esc_attr($id) . '" name="evk_seo_mb[pl][' . esc_attr($klucz) . ']" rows="2">' . esc_textarea($w) . '</textarea>'
            : '<input type="text" id="' . esc_attr($id) . '" name="evk_seo_mb[pl][' . esc_attr($klucz) . ']" value="' . esc_attr($w) . '">';
        $b = (string) ($wartosci[$klucz]['bricks'] ?? '');
        if ($b !== '') echo '<p class="evk-seo-bricks"><span class="evk-seo-oryginal-jezyk">Bricks (ma pierwszeństwo):</span> ' . esc_html($b) . '</p>';
    }
    echo '<p class="description">Robots i lista wszystkich stron — w zakładce SEO Evoke ONE.</p>';
    echo '</div>';
    /* Wersje językowe: pola, polski tekst pod nimi i pasek ✦. */
    foreach ($jezyki as $kod => $nazwa) {
        $K = strtoupper((string) $kod);
        echo '<div class="evk-seo-mb-wersja" data-lang="' . esc_attr((string) $kod) . '" hidden>';
        foreach ($pola as $klucz => [$etykieta, $typ]) {
            $id = 'evk-seo-mb-' . $kod . '-' . $klucz;
            $orig = (string) ($wartosci[$klucz]['bricks'] ?? '');
            $z_bricks = $orig !== '';
            if (!$z_bricks) $orig = (string) ($wartosci[$klucz]['zakladka'] ?? '');
            $w = evk_seo_meta_jezyka($pid, (string) $kod, 'seo_' . $klucz);
            $n = 'evk_seo_mb[' . $kod . '][' . $klucz . ']';
            echo '<label class="evk-seo-mb-etykieta" for="' . esc_attr($id) . '">' . esc_html($etykieta . ' ' . $K) . '</label>';
            echo $typ === 'textarea'
                ? '<textarea id="' . esc_attr($id) . '" class="evk-seo-pole" data-pole="' . esc_attr($klucz) . '" name="' . esc_attr($n) . '" rows="2">' . esc_textarea($w) . '</textarea>'
                : '<input type="text" id="' . esc_attr($id) . '" class="evk-seo-pole" data-pole="' . esc_attr($klucz) . '" name="' . esc_attr($n) . '" value="' . esc_attr($w) . '">';
            echo '<p class="evk-seo-oryginal"><span class="evk-seo-oryginal-jezyk">PL' . ($z_bricks ? ' (Bricks)' : '') . ':</span> ' . ($orig !== '' ? esc_html($orig) : '—') . '</p>';
            evk_seo_narzedzia_pola((string) $kod, $klucz, $orig, $w, evk_seo_zrodlo_jezyka($pid, (string) $kod, 'seo_' . $klucz), $etykieta . ' ' . $K,
                'evk_seo_mb[' . $kod . '][' . $klucz . '__zrodlo]');
        }
        echo '</div>';
    }
    echo '</div>';
}

/* Zapis: polskie pola jak zakładka SEO, wersje językowe przez evk_seo_zapisz_jezyki() (źródło, znak AI). */
add_action('save_post', function ($pid, $post) {
    if (!($post instanceof WP_Post) || wp_is_post_revision($pid) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    if (!isset($_POST['evk_seo_mb_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['evk_seo_mb_nonce'])), 'evk_seo_mb')) return;
    if (!current_user_can('edit_post', $pid) || !in_array($post->post_type, evk_seo_mb_typy(), true)) return;
    $dane = isset($_POST['evk_seo_mb']) && is_array($_POST['evk_seo_mb']) ? wp_unslash($_POST['evk_seo_mb']) : [];
    $pl = isset($dane['pl']) && is_array($dane['pl']) ? $dane['pl'] : [];
    foreach (array_keys(EVK_SEO_MB_META) as $klucz) {
        if (!isset($pl[$klucz]) || !is_string($pl[$klucz])) continue;
        $v = $klucz === 'desc' ? sanitize_textarea_field($pl[$klucz]) : sanitize_text_field($pl[$klucz]);
        /* Puste — bez wiersza w bazie (każdy zapis wpisu zostawiałby trzy puste metadane). */
        if ($v === '') { delete_post_meta($pid, EVK_SEO_MB_META[$klucz]); continue; }
        update_post_meta($pid, EVK_SEO_MB_META[$klucz], wp_slash($v));
    }
    unset($dane['pl']);
    if ($dane) evk_seo_zapisz_jezyki((int) $pid, $dane);
}, 10, 2);
