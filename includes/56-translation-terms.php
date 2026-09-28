<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — kategorie, tagi i taksonomie: nazwa, adres i opis
 * w każdym języku (1.253.0).
 *
 * PANEL. Ten sam przełącznik i te same pola co przy wpisach
 * (assets/admin/tl-wpisy.js): na ekranie edycji termu i w formularzu
 * dodawania. Dodawanie idzie AJAX-em — skrypt czyści potem pola języków, bo
 * WordPress czyści tylko widoczne (ta sama pułapka co w Evoke FIELDS).
 *
 * DANE. Metadane termu `_evk_tl_{język}__name`, `…__description` i `…__zrodlo`
 * („Do sprawdzenia" jak przy wpisach). Adres idzie do wspólnej mapy adresów.
 *
 * STRONA. Nazwa i opis wszędzie, gdzie WordPress bierze term przez get_term():
 * listy kategorii i tagów, tytuł i opis archiwum, menu, SEO archiwum (opis
 * meta, og:title). Brak tłumaczenia albo pusty polski tekst — polski; panel
 * i builder — polski.
 */

// =========================================================================
// POLA I DANE
// =========================================================================

/**
 * Taksonomie z tłumaczeniami: publiczne z ekranem w panelu, bez menu,
 * formatów wpisu i kategorii linków.
 *
 * @return list<string>
 */
function evk_tlt_taksonomie(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $t = array_diff(array_values(get_taxonomies(['public' => true, 'show_ui' => true])), ['nav_menu', 'link_category', 'post_format']);
    return $cache = array_values(array_unique(array_map('strval', (array) apply_filters('evk_tl_termy_taksonomie', array_values($t)))));
}

/** Zapisane tłumaczenie pola termu (surowo, do panelu). */
function evk_tlt_meta(int $termId, string $lang, string $pole): string {
    return (string) get_term_meta($termId, '_evk_tl_' . $lang . '__' . $pole, true);
}

/** Tłumaczenie do pokazania: '' gdy go nie ma albo polski tekst jest pusty. */
function evk_tlt_tlumaczenie(WP_Term $term, string $lang, string $pole): string {
    $t = evk_tlt_meta((int) $term->term_id, $lang, $pole);
    if (evk_tlw_pusty($t)) return '';
    return evk_tlw_pusty((string) $term->{$pole}) ? '' : $t;
}

/**
 * Stan tłumaczenia termu w języku (nazwa i opis z polskim tekstem).
 *
 * @return array{n: int, m: int, sprawdz: bool}
 */
function evk_tlt_stan(WP_Term $term, string $lang): array {
    $stan = ['n' => 0, 'm' => 0, 'sprawdz' => false];
    foreach (['name', 'description'] as $pole) {
        $pl = (string) $term->{$pole};
        if (evk_tlw_pusty($pl)) continue;
        $stan['m']++;
        $t = evk_tlt_meta((int) $term->term_id, $lang, $pole);
        if (evk_tlw_pusty($t)) continue;
        $stan['n']++;
        $zrodlo = (string) get_term_meta((int) $term->term_id, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo', true);
        if (evk_tlw_nieaktualne($t, $zrodlo, $pl)) $stan['sprawdz'] = true;
    }
    return $stan;
}

// =========================================================================
// STRONA
// =========================================================================

/* Kopia termu z nazwą i opisem w języku — obiekt z pamięci podręcznej
   (albo przekazany przez wołającego) zostaje nietknięty. */
add_filter('get_term', function ($term) {
    if (!($term instanceof WP_Term)) return $term;
    $lang = evk_tlw_jezyk();
    if ($lang === '' || !in_array($term->taxonomy, evk_tlt_taksonomie(), true)) return $term;
    $nazwa = evk_tlt_tlumaczenie($term, $lang, 'name');
    $opis  = evk_tlt_tlumaczenie($term, $lang, 'description');
    if ($nazwa === '' && $opis === '') return $term;
    $kopia = clone $term;
    if ($nazwa !== '') $kopia->name = $nazwa;
    if ($opis !== '')  $kopia->description = $opis;
    return $kopia;
});

// =========================================================================
// PANEL
// =========================================================================

/** Czy bieżący ekran to edycja albo lista termów obsługiwanej taksonomii. */
function evk_tlt_ekran(): bool {
    $s = function_exists('get_current_screen') ? get_current_screen() : null;
    return $s && in_array($s->base, ['edit-tags', 'term'], true) && in_array((string) $s->taxonomy, evk_tlt_taksonomie(), true);
}

add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['edit-tags.php', 'term.php'], true) || !evk_tlt_ekran() || !evk_tlw_jezyki()) return;
    wp_enqueue_style('evk-tl-wpisy', EVOKE_ONE_URL . 'assets/admin/tl-wpisy.css', [], EVOKE_ONE_VERSION);
    wp_enqueue_script('evk-tl-wpisy', EVOKE_ONE_URL . 'assets/admin/tl-wpisy.js', ['jquery'], EVOKE_ONE_VERSION, true);
    wp_add_inline_style('evk-tl-wpisy', evk_tlw_css_jezykow());
});

/** Przełącznik języka (nad formularzem, którego selektor dostaje skrypt). */
function evk_tlt_przelacznik(string $forma): void {
    echo '<div class="evk-tl-przelacznik evk-tlw-przelacznik" role="group" aria-label="Wersja językowa" data-forma="' . esc_attr($forma) . '">';
    foreach (array_merge(['pl' => 'Polski'], evk_tlw_jezyki()) as $kod => $nazwa) {
        $pl = $kod === 'pl';
        echo '<button type="button" class="button' . ($pl ? ' button-primary' : '') . ' evk-tl-jezyk" data-lang="' . esc_attr($kod) . '"'
            . ' aria-pressed="' . ($pl ? 'true' : 'false') . '" title="' . esc_attr($nazwa) . '">' . esc_html(strtoupper($kod))
            . ($pl ? '' : ' <span class="evk-tl-licznik" aria-hidden="true"></span><span class="screen-reader-text evk-tl-licznik-sr"></span>')
            . '</button>';
    }
    echo '</div>';
}

/**
 * Pole języka dla termu: wiersz tabeli (edycja) albo blok formularza
 * (dodawanie). $pole: name, slug albo description.
 *
 * @param array{oryginal: string, wiersz: string} $gdzie Selektory pola polskiego i jego wiersza.
 */
function evk_tlt_pole(bool $wiersz, string $kod, string $pole, ?WP_Term $term, array $gdzie): void {
    $K = strtoupper($kod);
    $nazwy = ['name' => 'Nazwa', 'slug' => 'Adres (slug)', 'description' => 'Opis'];
    $id = 'evk-tlw-' . $kod . '-' . $pole;
    $n = 'evk_tlw[' . $kod . '][' . $pole . ']';
    $atrybuty = ' data-lang="' . esc_attr($kod) . '" data-pole="' . esc_attr($pole) . '" data-po="' . esc_attr($gdzie['wiersz']) . '"'
        . ' data-ukryj="' . esc_attr($gdzie['wiersz']) . '"' . ($pole !== 'slug' ? ' data-oryginal="' . esc_attr($gdzie['oryginal']) . '"' : '');
    $pl = $term ? (string) $term->{$pole} : '';
    if ($pole === 'slug') {
        $wartosc = $term && $pl !== '' ? evk_tlw_slug($pl, $kod) : '';
    } else {
        $wartosc = $term ? evk_tlt_meta((int) $term->term_id, $kod, $pole) : '';
    }
    $zrodlo = ($term && $pole !== 'slug') ? (string) get_term_meta((int) $term->term_id, '_evk_tl_' . $kod . '__' . $pole . '__zrodlo', true) : '';

    if ($pole === 'slug') {
        $input = '<input type="text" id="' . esc_attr($id) . '" class="evk-tlw-slug" name="' . esc_attr($n . '[wartosc]') . '" value="' . esc_attr($wartosc) . '"'
            . ' placeholder="' . esc_attr($pl !== '' ? $pl : 'jak polski') . '" autocomplete="off" spellcheck="false">';
        $pod = '<p class="description evk-tlw-uwaga">Pusty = ten sam człon co po polsku' . ($pl !== '' ? ' („' . esc_html($pl) . '”)' : '') . '.';
        $inne = $term && $pl !== '' ? evk_tlw_inne_z_czlonem($pl, 0, (int) $term->term_id) : [];
        if ($inne) $pod .= ' Ten sam polski człon ma też: ' . esc_html(implode(', ', $inne)) . ' — tłumaczenie członu dotyczy wszystkich (mapa adresów jest wspólna).';
        $pod .= '</p>';
    } else {
        $pusty = evk_tlw_pusty($pl) ? '0' : '1';
        $input = $pole === 'description'
            ? '<textarea id="' . esc_attr($id) . '" class="evk-tlw-wejscie" rows="5" name="' . esc_attr($n . '[wartosc]') . '" data-pl="' . $pusty . '">' . esc_textarea($wartosc) . '</textarea>'
            : '<input type="text" id="' . esc_attr($id) . '" class="evk-tlw-wejscie" name="' . esc_attr($n . '[wartosc]') . '" value="' . esc_attr($wartosc) . '"'
              . ' autocomplete="off" data-pl="' . $pusty . '">';
        ob_start();
        evk_tlw_ukryte($kod, $pole, $wartosc, $zrodlo);
        evk_tlw_narzedzia($kod, $pole, $pl, $wartosc, $zrodlo, true, $pole === 'name');
        $pod = (string) ob_get_clean();
    }
    $etykieta = '<label for="' . esc_attr($id) . '">' . esc_html($nazwy[$pole] . ' ' . $K) . '</label>';
    if ($wiersz) {
        echo '<tr class="form-field evk-tlw-pole evk-tlw-term-' . esc_attr($pole) . '"' . $atrybuty . '><th scope="row">' . $etykieta . '</th><td>' . $input . $pod . '</td></tr>';
    } else {
        echo '<div class="form-field evk-tlw-pole evk-tlw-term-' . esc_attr($pole) . '"' . $atrybuty . '>' . $etykieta . $input . $pod . '</div>';
    }
}

add_action('admin_init', function () {
    if (!evk_tlw_jezyki()) return;
    foreach (evk_tlt_taksonomie() as $tax) {
        // Edycja termu: przełącznik nad tabelą, pola języków jako wiersze (skrypt stawia je pod oryginałami).
        add_action($tax . '_term_edit_form_top', function ($term) {
            if (!($term instanceof WP_Term)) return;
            wp_nonce_field('evk_tlw_zapis', 'evk_tlw_nonce', false);
            evk_tlt_przelacznik('#edittag');
        });
        add_action($tax . '_edit_form_fields', function ($term) {
            if (!($term instanceof WP_Term)) return;
            foreach (array_keys(evk_tlw_jezyki()) as $kod) {
                evk_tlt_pole(true, $kod, 'name', $term, ['oryginal' => '#name', 'wiersz' => '.term-name-wrap']);
                evk_tlt_pole(true, $kod, 'slug', $term, ['oryginal' => '#slug', 'wiersz' => '.term-slug-wrap']);
                evk_tlt_pole(true, $kod, 'description', $term, ['oryginal' => '#description', 'wiersz' => '.term-description-wrap']);
            }
        });
        // Dodawanie termu: przełącznik nad formularzem, pola w formularzu.
        add_action($tax . '_pre_add_form', function () { evk_tlt_przelacznik('#addtag'); });
        add_action($tax . '_add_form_fields', function () {
            wp_nonce_field('evk_tlw_zapis', 'evk_tlw_nonce', false);
            foreach (array_keys(evk_tlw_jezyki()) as $kod) {
                evk_tlt_pole(false, $kod, 'name', null, ['oryginal' => '#tag-name', 'wiersz' => '.term-name-wrap']);
                evk_tlt_pole(false, $kod, 'slug', null, ['oryginal' => '#tag-slug', 'wiersz' => '.term-slug-wrap']);
                evk_tlt_pole(false, $kod, 'description', null, ['oryginal' => '#tag-description', 'wiersz' => '.term-description-wrap']);
            }
        });
        // Kolumna „Języki" na liście termów.
        add_filter('manage_edit-' . $tax . '_columns', function ($kolumny) {
            $nowe = [];
            foreach ((array) $kolumny as $k => $v) {
                $nowe[$k] = $v;
                if ($k === 'name') $nowe['evk_tlw'] = 'Języki';
            }
            if (!isset($nowe['evk_tlw'])) $nowe['evk_tlw'] = 'Języki';
            return $nowe;
        });
        add_filter('manage_' . $tax . '_custom_column', function ($tresc, $kolumna, $termId) {
            if ($kolumna !== 'evk_tlw') return $tresc;
            $term = get_term((int) $termId);
            if (!($term instanceof WP_Term)) return $tresc;
            $stany = [];
            foreach (array_keys(evk_tlw_jezyki()) as $kod) $stany[$kod] = evk_tlt_stan($term, $kod);
            return $tresc . evk_tlw_kolumna_html($stany);
        }, 10, 3);
    }
});

add_action('admin_head-edit-tags.php', 'evk_tlw_styl_kolumny');

// =========================================================================
// ZAPIS
// =========================================================================

/* Stary polski adres termu — przed zapisem (edit_terms), żeby po nim pozycja
   mapy mogła pójść za termem. */
add_action('edit_terms', function ($termId, $taxonomy) {
    if (!in_array((string) $taxonomy, evk_tlt_taksonomie(), true)) return;
    $term = get_term((int) $termId, (string) $taxonomy);
    if ($term instanceof WP_Term) $GLOBALS['evk_tlt_stary_slug'][(int) $termId] = $term->slug;
}, 10, 2);

add_action('edited_term', function ($termId, $ttId, $taxonomy) {
    $stary = (string) ($GLOBALS['evk_tlt_stary_slug'][(int) $termId] ?? '');
    if ($stary === '' || !in_array((string) $taxonomy, evk_tlt_taksonomie(), true)) return;
    $term = get_term((int) $termId, (string) $taxonomy);
    if ($term instanceof WP_Term && $term->slug !== $stary && $term->slug !== '') {
        evk_tlw_mapa_za_obiektem($stary, $term->slug, 0, (int) $termId);
    }
}, 9, 3);

/**
 * Tłumaczenia z formularza termu — te same zasady co przy wpisach (źródło,
 * „Do sprawdzenia", sanityzacja jak rdzeń dla pola, adres do mapy z kontrolą
 * konfliktów). Dodawanie (created_term) przychodzi AJAX-em z tym samym
 * formularzem.
 */
function evk_tlt_zapisz($termId, $ttId, $taxonomy): void {
    $termId = (int) $termId;
    $taxonomy = (string) $taxonomy;
    if (empty($_POST['evk_tlw']) || !is_array($_POST['evk_tlw'])) return;
    if (!isset($_POST['evk_tlw_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['evk_tlw_nonce'])), 'evk_tlw_zapis')) return;
    if (!in_array($taxonomy, evk_tlt_taksonomie(), true) || !current_user_can('edit_term', $termId)) return;
    $term = get_term($termId, $taxonomy);
    if (!($term instanceof WP_Term)) return;
    $jezyki = evk_tlw_jezyki();
    $bledy = [];
    foreach ((array) $_POST['evk_tlw'] as $kod => $dane) {
        $kod = sanitize_key((string) $kod);
        if (!isset($jezyki[$kod]) || !is_array($dane)) continue;
        foreach (['name', 'description'] as $pole) {
            if (!isset($dane[$pole]['wartosc']) || !is_string($dane[$pole]['wartosc'])) continue;
            $tekst = trim((string) wp_unslash(sanitize_term_field($pole, $dane[$pole]['wartosc'], $termId, $taxonomy, 'db')));
            if (evk_tlw_pusty($tekst)) {
                delete_term_meta($termId, '_evk_tl_' . $kod . '__' . $pole);
                delete_term_meta($termId, '_evk_tl_' . $kod . '__' . $pole . '__zrodlo');
                continue;
            }
            $przed  = sanitize_key((string) ($dane[$pole]['przed'] ?? ''));
            $zrodlo = sanitize_key((string) ($dane[$pole]['zrodlo'] ?? ''));
            if ($zrodlo === '' || $zrodlo === 'teraz' || evk_tlw_skrot($tekst) !== $przed) {
                $zrodlo = evk_tlw_zrodlo((string) $term->{$pole});
            }
            update_term_meta($termId, '_evk_tl_' . $kod . '__' . $pole, wp_slash($tekst));
            update_term_meta($termId, '_evk_tl_' . $kod . '__' . $pole . '__zrodlo', $zrodlo);
        }
        if (isset($dane['slug']['wartosc']) && is_string($dane['slug']['wartosc']) && $term->slug !== '') {
            $czlon = sanitize_title(wp_unslash($dane['slug']['wartosc']));
            if ($czlon !== '' && $czlon !== $term->slug) {
                $blad = evk_tlw_slug_konflikt($term->slug, $kod, $czlon, 0, (string) ($GLOBALS['evk_tlt_stary_slug'][$termId] ?? ''), $termId);
                if ($blad !== '') {
                    $bledy[] = 'Adres ' . strtoupper($kod) . ' nie został zapisany. ' . $blad;
                    continue;
                }
            }
            evk_tlw_zapisz_slug($term->slug, $kod, $czlon);
        }
    }
    if ($bledy) set_transient('evk_tlw_bledy_' . get_current_user_id(), $bledy, 120);
}
add_action('created_term', 'evk_tlt_zapisz', 10, 3);
add_action('edited_term', 'evk_tlt_zapisz', 10, 3);

/* Błędy adresów po zapisie termu. */
add_action('admin_notices', function () {
    if (!evk_tlt_ekran()) return;
    $klucz = 'evk_tlw_bledy_' . get_current_user_id();
    $bledy = get_transient($klucz);
    if (!is_array($bledy) || !$bledy) return;
    delete_transient($klucz);
    echo '<div class="notice notice-error"><p>' . implode('</p><p>', array_map('esc_html', $bledy)) . '</p></div>';
});
