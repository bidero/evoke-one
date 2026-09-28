<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — wpisy i strony: tytuł, adres, treść i zajawka w każdym
 * języku (1.252.0).
 *
 * KLASYCZNY EDYTOR (zgłaszający: „wszędzie klasyczny"). Przełącznik
 * `PL | EN n/m | DE n/m` nad tytułem podmienia w tych samych miejscach tytuł,
 * adres, treść i zajawkę na pola języka; kategorie, obrazek wyróżniający
 * i publikacja są wspólne. Przyciski mają klasę `evk-tl-jezyk` jak w Evoke
 * FIELDS: FIELDS przełącza swoje grupy na to samo kliknięcie, a ten moduł —
 * na kliknięcie w przełącznik FIELDS. Reszta klas jest własna (`evk-tlw-…`),
 * żeby arkusze obu wtyczek się nie mieszały.
 *
 * DANE. Metadane `_evk_tl_{język}__{pole}` (post_title, post_content,
 * post_excerpt) i `…__zrodlo` — skrót polskiego tekstu, z którego powstało
 * tłumaczenie („Do sprawdzenia", ta sama zasada co w FIELDS). Adres idzie do
 * mapy adresów (`tl_url_slugs`, zakładka „Slugi URL"), wspólnej z resztą
 * strony: ten sam polski człon ma jedno tłumaczenie, gdziekolwiek stoi.
 *
 * STRONA. Tytuł (także w menu, <title> i pętlach), treść (z `<!--more-->`
 * i `<!--nextpage-->`), zajawka. Brak tłumaczenia albo pusty polski tekst —
 * polski. Panel i builder Bricksa — zawsze polski.
 */

// =========================================================================
// POLA I DANE
// =========================================================================

/**
 * Typy treści z tłumaczeniami wpisów: publiczne z ekranem edycji, bez
 * załączników (ich tekst alternatywny to osobna sprawa).
 *
 * @return list<string>
 */
function evk_tlw_typy(): array {
    $typy = array_diff(array_values(get_post_types(['public' => true, 'show_ui' => true])), ['attachment']);
    return array_values(array_unique(array_map('strval', (array) apply_filters('evk_tl_wpisy_typy', array_values($typy)))));
}

/**
 * Pola tekstowe wpisu danego typu: pole => nazwa, w kolejności ekranu.
 *
 * @return array<string, string>
 */
function evk_tlw_pola(string $typ): array {
    $pola = ['post_title' => 'Tytuł'];
    if (post_type_supports($typ, 'editor'))  $pola['post_content'] = 'Treść';
    if (post_type_supports($typ, 'excerpt')) $pola['post_excerpt'] = 'Zajawka';
    return $pola;
}

/** Języki tłumaczeń wpisów: kod => nazwa (bez polskiego). */
function evk_tlw_jezyki(): array {
    $out = [];
    foreach (tl_get_languages() as $kod => $l) $out[(string) $kod] = (string) ($l['name'] ?? $kod);
    return $out;
}

/** Czy tekst jest pusty po zdjęciu znaczników (pusty akapit z edytora to też pustka). */
function evk_tlw_pusty(string $tekst): bool {
    return trim(str_replace("\xC2\xA0", ' ', html_entity_decode(wp_strip_all_tags($tekst), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '';
}

/**
 * Skrót tekstu jak w Evoke FIELDS (`evk_rep_tl_hash()`): bez znaczników, encji
 * i ukośników, odstępy scalone — zmiana TREŚCI, a nie przestawione przez
 * edytor znaczniki.
 */
function evk_tlw_skrot(string $tekst): string {
    $t = html_entity_decode(wp_strip_all_tags(str_replace('\\', '', $tekst)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
    return $t === '' ? '' : substr(md5($t), 0, 12);
}

/** Źródło zapisywane z tłumaczeniem: skrót polskiego tekstu, a przy pustym — `pusty`. */
function evk_tlw_zrodlo(string $pl): string {
    $h = evk_tlw_skrot($pl);
    return $h !== '' ? $h : 'pusty';
}

/** Czy tłumaczenie powstało z innego polskiego tekstu niż bieżący („Do sprawdzenia"). */
function evk_tlw_nieaktualne(string $tlumaczenie, string $zrodlo, string $pl): bool {
    if (evk_tlw_pusty($tlumaczenie) || $zrodlo === '') return false;
    $h = evk_tlw_skrot($pl);
    return $h !== '' && $zrodlo !== $h;
}

/** Zapisane tłumaczenie pola (surowo, do panelu). */
function evk_tlw_meta(int $pid, string $lang, string $pole): string {
    return (string) get_post_meta($pid, '_evk_tl_' . $lang . '__' . $pole, true);
}

/** Tłumaczenie do pokazania na stronie: '' gdy go nie ma albo polski tekst jest pusty. */
function evk_tlw_tlumaczenie(int $pid, string $lang, string $pole): string {
    if ($pid <= 0) return '';
    $t = evk_tlw_meta($pid, $lang, $pole);
    if (evk_tlw_pusty($t)) return '';
    return evk_tlw_pusty((string) get_post_field($pole, $pid, 'raw')) ? '' : $t;
}

/** Czy treść strony rysuje Bricks (tryb edytora „bricks"), a nie treść WordPressa. */
function evk_tlw_z_bricksa(int $pid): bool {
    return get_post_meta($pid, '_bricks_editor_mode', true) === 'bricks';
}

/**
 * Stan tłumaczenia wpisu w języku: ile pól z polskim tekstem ma tłumaczenie
 * (n z m) i czy któreś jest „Do sprawdzenia". Adres się nie liczy — ten sam
 * adres w obu językach bywa zamierzony.
 *
 * @return array{n: int, m: int, sprawdz: bool}
 */
function evk_tlw_stan(WP_Post $post, string $lang): array {
    $stan = ['n' => 0, 'm' => 0, 'sprawdz' => false];
    foreach (array_keys(evk_tlw_pola($post->post_type)) as $pole) {
        if ($pole === 'post_content' && evk_tlw_z_bricksa($post->ID)) continue;
        $pl = (string) $post->{$pole};
        if (evk_tlw_pusty($pl)) continue;
        $stan['m']++;
        $t = evk_tlw_meta($post->ID, $lang, $pole);
        if (evk_tlw_pusty($t)) continue;
        $stan['n']++;
        if (evk_tlw_nieaktualne($t, (string) get_post_meta($post->ID, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo', true), $pl)) $stan['sprawdz'] = true;
    }
    return $stan;
}

/**
 * Tłumaczenie polskiego tekstu ze słownika (cała fraza, jak na stronie); ''
 * gdy słownik go nie zna. Podpowiedź przy pustym polu języka.
 */
function evk_tlw_ze_slownika(string $pl, string $lang): string {
    if (!function_exists('tl_get_match_index')) return '';
    $klucz = mb_strtolower(tl_normalize_text_for_match(trim(wp_strip_all_tags($pl))));
    if ($klucz === '') return '';
    return (string) (tl_get_match_index($lang)[$klucz]['tlum'] ?? '');
}

// =========================================================================
// STRONA
// =========================================================================

/** Język, w którym czytamy wpisy: '' = polski (panel, builder, bez Tłumaczeń). */
function evk_tlw_jezyk(): string {
    if (is_admin() && !wp_doing_ajax()) return '';
    if (tl_is_bricks_editor() || tl_is_bricks_preview()) return '';
    $lang = get_current_lang();
    return ($lang !== 'pl' && isset(tl_get_languages()[$lang])) ? $lang : '';
}

/* Tytuł: pętle, menu (wpis bez własnej etykiety pozycji), <title> przez
   single_post_title. Przed wptexturize i Sierotkami. */
add_filter('the_title', function ($tytul, $id = 0) {
    $lang = evk_tlw_jezyk();
    if ($lang === '' || !$id) return $tytul;
    $t = evk_tlw_tlumaczenie((int) $id, $lang, 'post_title');
    return $t !== '' ? $t : $tytul;
}, 1, 2);

add_filter('single_post_title', function ($tytul, $post = null) {
    $lang = evk_tlw_jezyk();
    if ($lang === '' || !($post instanceof WP_Post)) return $tytul;
    $t = evk_tlw_tlumaczenie($post->ID, $lang, 'post_title');
    return $t !== '' ? $t : $tytul;
}, 1, 2);

/**
 * Treść: podmieniamy strony, z których WordPress składa `get_the_content()`.
 * Wszystko dalej (the_content, `<!--more-->`, liczba stron, zajawka z treści)
 * dostaje wersję języka. Podział jak w WP_Query::generate_postdata().
 */
add_filter('content_pagination', function ($strony, $post) {
    $lang = evk_tlw_jezyk();
    if ($lang === '' || !($post instanceof WP_Post)) return $strony;
    $t = evk_tlw_tlumaczenie($post->ID, $lang, 'post_content');
    if ($t === '') return $strony;
    if (strpos($t, '<!--nextpage-->') === false) return [$t];
    $t = str_replace(["\n<!--nextpage-->\n", "\n<!--nextpage-->", "<!--nextpage-->\n"], '<!--nextpage-->', $t);
    $t = str_replace(['<!-- wp:nextpage -->', '<!-- /wp:nextpage -->'], '', $t);
    if (strpos($t, '<!--nextpage-->') === 0) $t = (string) substr($t, 15);
    return explode('<!--nextpage-->', $t);
}, 1, 2);

/**
 * Zajawka: własna zajawka języka; bez niej, a z treścią języka — pusto, więc
 * WordPress zrobi zajawkę z treści języka (wp_trim_excerpt na priorytecie 10).
 * Bez obu — polska, jak wszystko bez tłumaczenia.
 */
add_filter('get_the_excerpt', function ($zajawka, $post = null) {
    $lang = evk_tlw_jezyk();
    if ($lang === '' || !($post instanceof WP_Post)) return $zajawka;
    $t = evk_tlw_tlumaczenie($post->ID, $lang, 'post_excerpt');
    if ($t !== '') return $t;
    return evk_tlw_tlumaczenie($post->ID, $lang, 'post_content') !== '' ? '' : $zajawka;
}, 1, 2);

// =========================================================================
// ADRES: MAPA ADRESÓW
// =========================================================================

/** Mapa adresów wprost z opcji (bez pamięci podręcznej modułu). */
function evk_tlw_mapa(): array {
    $mapa = get_option('tl_url_slugs', []);
    return is_array($mapa) ? array_values(array_filter($mapa, 'is_array')) : [];
}

/** Człon języka dla polskiego członu; '' gdy mapa go nie ma. */
function evk_tlw_slug(string $pl, string $lang): string {
    foreach (evk_tlw_mapa() as $w) {
        if (($w['pl'] ?? '') === $pl && !empty($w[$lang])) return (string) $w[$lang];
    }
    return '';
}

/**
 * Inne miejsca z tym samym polskim członem: wpisy i termy (bez wpisu $pomin
 * i termu $pominTerm). Mapa jest wspólna, więc tłumaczenie członu dotyczy ich
 * wszystkich.
 *
 * @return list<string> Opisy dla ludzi.
 */
function evk_tlw_inne_z_czlonem(string $slug, int $pomin = 0, int $pominTerm = 0): array {
    global $wpdb;
    if ($slug === '') return [];
    $out = [];
    $wpisy = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_name = %s AND ID <> %d
         AND post_type NOT IN ('revision', 'nav_menu_item', 'attachment') AND post_status NOT IN ('trash', 'auto-draft', 'inherit') LIMIT 5",
        $slug, $pomin));
    foreach ($wpisy as $w) {
        $typ = get_post_type_object((string) $w->post_type);
        $out[] = ($typ ? $typ->labels->singular_name : $w->post_type) . ' „' . $w->post_title . '”';
    }
    $terminy = get_terms(['slug' => $slug, 'hide_empty' => false, 'number' => 5]);
    foreach (is_array($terminy) ? $terminy : [] as $term) {
        if ((int) $term->term_id === $pominTerm) continue;
        $tax = get_taxonomy($term->taxonomy);
        $out[] = ($tax ? $tax->labels->singular_name : $term->taxonomy) . ' „' . $term->name . '”';
    }
    return $out;
}

/**
 * Dlaczego człon $czlon nie może być adresem wersji $lang polskiego członu $pl;
 * '' gdy może. Mapa tłumaczy wstecz po samym członie, więc:
 *   — ten sam człon języka przy innym polskim członie = dwie strony pod jednym adresem;
 *   — człon równy polskiemu adresowi innego wpisu albo termu zasłoniłby tamten.
 * $stary: poprzedni polski człon tego wpisu (zmiana adresu w tym zapisie) — jego
 * pozycja w mapie należy do tego wpisu.
 */
function evk_tlw_slug_konflikt(string $pl, string $lang, string $czlon, int $pid, string $stary = '', int $termId = 0): string {
    foreach (evk_tlw_mapa() as $w) {
        $wpl = (string) ($w['pl'] ?? '');
        if ($wpl === $pl || ($stary !== '' && $wpl === $stary)) continue;
        if ((string) ($w[$lang] ?? '') === $czlon) {
            return 'Adres „' . $czlon . '” ma już w mapie adresów inny człon („' . $wpl . '”).';
        }
    }
    $inne = evk_tlw_inne_z_czlonem($czlon, $pid, $termId);
    if ($inne) {
        return '„' . $czlon . '” to polski adres: ' . $inne[0] . ' — wersja językowa zasłoniłaby tamten adres.';
    }
    return '';
}

/**
 * Zapis członu języka do mapy. Pusty albo równy polskiemu — usuwa tłumaczenie
 * tego języka; pozycja bez żadnego tłumaczenia znika.
 */
function evk_tlw_zapisz_slug(string $pl, string $lang, string $czlon): void {
    if ($pl === '') return;
    $mapa = evk_tlw_mapa();
    $i = -1;
    foreach ($mapa as $k => $w) {
        if (($w['pl'] ?? '') === $pl) { $i = $k; break; }
    }
    if ($czlon === '' || $czlon === $pl) {
        if ($i < 0) return;
        $mapa[$i][$lang] = '';
        $zostaje = array_filter(array_diff_key($mapa[$i], ['pl' => 1]), static function ($v) { return (string) $v !== ''; });
        if (!$zostaje) unset($mapa[$i]);
    } elseif ($i < 0) {
        $wpis = ['pl' => $pl];
        foreach (array_keys(evk_tlw_jezyki()) as $kod) $wpis[$kod] = '';
        $wpis[$lang] = $czlon;
        $mapa[] = $wpis;
    } else {
        $mapa[$i][$lang] = $czlon;
    }
    update_option('tl_url_slugs', array_values($mapa));
}

/**
 * Zmiana polskiego adresu wpisu: jego pozycja w mapie idzie za nim, o ile
 * polskiego członu nie używa nic innego (inny wpis, term, człon typu treści
 * albo taksonomii) — wtedy zostaje dla nich, a wpis dostaje nową pozycję
 * dopiero z formularza.
 */
add_action('post_updated', function ($pid, $po, $przed) {
    if (!($po instanceof WP_Post) || !($przed instanceof WP_Post)) return;
    if ($po->post_name === $przed->post_name || $przed->post_name === '' || $po->post_name === '') return;
    if (!in_array($po->post_type, evk_tlw_typy(), true)) return;
    $GLOBALS['evk_tlw_stary_slug'][(int) $pid] = $przed->post_name;
    evk_tlw_mapa_za_obiektem($przed->post_name, $po->post_name, (int) $pid, 0);
}, 10, 3);

/** Pozycja mapy idzie ze starego polskiego członu na nowy (wpis $pid albo term $termId zmienił adres). */
function evk_tlw_mapa_za_obiektem(string $stary, string $nowy, int $pid, int $termId): void {
    if (evk_tlw_inne_z_czlonem($stary, $pid, $termId) || in_array($stary, evk_tlw_czlony_rewrite(), true)) return;
    $mapa = evk_tlw_mapa();
    $iStary = -1;
    $iNowy = -1;
    foreach ($mapa as $k => $w) {
        if (($w['pl'] ?? '') === $stary) $iStary = $k;
        if (($w['pl'] ?? '') === $nowy) $iNowy = $k;
    }
    if ($iStary < 0) return;
    if ($iNowy < 0) {
        $mapa[$iStary]['pl'] = $nowy;
    } else {
        unset($mapa[$iStary]);   // nowy człon ma już swoją pozycję — ta by jej przeszkadzała
    }
    update_option('tl_url_slugs', array_values($mapa));
}

/**
 * Człony z reguł adresów: typy treści, ich archiwa i taksonomie. Taki człon
 * w mapie nie należy do jednego wpisu, nawet gdy wpis ma ten sam adres.
 *
 * @return list<string>
 */
function evk_tlw_czlony_rewrite(): array {
    $out = [];
    foreach (get_post_types([], 'objects') as $t) {
        if (is_array($t->rewrite) && !empty($t->rewrite['slug'])) $out[] = (string) $t->rewrite['slug'];
        if (is_string($t->has_archive) && $t->has_archive !== '') $out[] = $t->has_archive;
    }
    foreach (get_taxonomies([], 'objects') as $t) {
        if (is_array($t->rewrite) && !empty($t->rewrite['slug'])) $out[] = (string) $t->rewrite['slug'];
    }
    foreach (['category_base', 'tag_base'] as $o) {
        $v = trim((string) get_option($o, ''), '/');
        if ($v !== '') $out[] = $v;
    }
    $czlony = [];
    foreach ($out as $s) foreach (explode('/', trim($s, '/')) as $c) if ($c !== '') $czlony[] = $c;
    return array_values(array_unique($czlony));
}

// =========================================================================
// PANEL: KLASYCZNY EDYTOR
// =========================================================================

/** Czy bieżący ekran to klasyczny edytor wpisu obsługiwanego typu. */
function evk_tlw_ekran(): bool {
    $s = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$s || $s->base !== 'post' || !in_array((string) $s->post_type, evk_tlw_typy(), true)) return false;
    return !$s->is_block_editor();
}

add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true) || !evk_tlw_ekran() || !evk_tlw_jezyki()) return;
    wp_enqueue_style('evk-tl-wpisy', EVOKE_ONE_URL . 'assets/admin/tl-wpisy.css', [], EVOKE_ONE_VERSION);
    wp_enqueue_script('evk-tl-wpisy', EVOKE_ONE_URL . 'assets/admin/tl-wpisy.js', ['jquery', 'editor'], EVOKE_ONE_VERSION, true);
    wp_enqueue_editor();
    /* Widok języka: pola języka zamiast oryginałów. Reguła na język — języki
       zna dopiero serwer. */
    wp_add_inline_style('evk-tl-wpisy', evk_tlw_css_jezykow());
});

/** Widok języka: pola tego języka widoczne (wiersz tabeli jako wiersz). Reguła na język — języki zna dopiero serwer. */
function evk_tlw_css_jezykow(): string {
    $css = '';
    foreach (array_keys(evk_tlw_jezyki()) as $kod) {
        $k = esc_attr($kod);
        $css .= '[data-evk-tlw="' . $k . '"] .evk-tlw-pole[data-lang="' . $k . '"]{display:block;}'
              . '[data-evk-tlw="' . $k . '"] tr.evk-tlw-pole[data-lang="' . $k . '"]{display:table-row;}';
    }
    return $css;
}

/** Pola ukryte przy polu języka: skrót przy renderze i źródło („Do sprawdzenia"). */
function evk_tlw_ukryte(string $lang, string $pole, string $wartosc, string $zrodlo): void {
    $n = 'evk_tlw[' . $lang . '][' . $pole . ']';
    echo '<input type="hidden" name="' . esc_attr($n . '[przed]') . '" value="' . esc_attr(evk_tlw_skrot($wartosc)) . '">';
    echo '<input type="hidden" class="evk-tlw-zrodlo" name="' . esc_attr($n . '[zrodlo]') . '" value="' . esc_attr($zrodlo) . '">';
}

/**
 * Oryginał pod polem, narzędzia i podpowiedź słownika ($slownik: null = dla
 * tytułu i zajawki — teksty krótkie, które słownik zna w całości).
 */
function evk_tlw_narzedzia(string $lang, string $pole, string $pl, string $wartosc, string $zrodlo, bool $kopiuj = true, ?bool $slownik = null): void {
    $podglad = html_entity_decode(wp_strip_all_tags($pl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $podglad = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $podglad));
    if (mb_strlen($podglad) > 160) $podglad = mb_substr($podglad, 0, 159) . '…';
    echo '<p class="evk-tlw-oryginal"><span class="evk-tlw-oryginal-jezyk">PL:</span> <span class="evk-tlw-oryginal-tekst">'
        . ($podglad !== '' ? esc_html($podglad) : '—') . '</span></p>';
    echo '<div class="evk-tlw-narzedzia">';
    if ($kopiuj) echo '<button type="button" class="button button-small evk-tlw-kopiuj">Kopiuj z polskiego</button>';
    echo '<span class="evk-tlw-do-sprawdzenia"' . (evk_tlw_nieaktualne($wartosc, $zrodlo, $pl) ? '' : ' hidden') . '>Do sprawdzenia: polski tekst się zmienił</span>';
    echo '<button type="button" class="button button-small evk-tlw-sprawdzone"' . (evk_tlw_nieaktualne($wartosc, $zrodlo, $pl) ? '' : ' hidden') . '>Sprawdzone</button>';
    if ($slownik === null) $slownik = in_array($pole, ['post_title', 'post_excerpt'], true);
    if ($slownik && evk_tlw_pusty($wartosc)) {
        $slownik = evk_tlw_ze_slownika($pl, $lang);
        if ($slownik !== '') {
            echo '<span class="evk-tlw-slownik">Ze słownika: <q class="evk-tlw-slownik-tekst">' . esc_html($slownik) . '</q> '
                . '<button type="button" class="button button-small evk-tlw-wstaw" data-tekst="' . esc_attr($slownik) . '">Wstaw</button></span>';
        }
    }
    echo '</div>';
}

/* Przełącznik nad tytułem. */
add_action('edit_form_top', function ($post) {
    if (!($post instanceof WP_Post) || !evk_tlw_ekran() || !($jezyki = evk_tlw_jezyki())) return;
    wp_nonce_field('evk_tlw_zapis', 'evk_tlw_nonce');
    echo '<div class="evk-tl-przelacznik evk-tlw-przelacznik" role="group" aria-label="Wersja językowa wpisu" data-forma="#post">';
    foreach (array_merge(['pl' => 'Polski'], $jezyki) as $kod => $nazwa) {
        $pl = $kod === 'pl';
        echo '<button type="button" class="button' . ($pl ? ' button-primary' : '') . ' evk-tl-jezyk" data-lang="' . esc_attr($kod) . '"'
            . ' aria-pressed="' . ($pl ? 'true' : 'false') . '" title="' . esc_attr($nazwa) . '">' . esc_html(strtoupper($kod))
            . ($pl ? '' : ' <span class="evk-tl-licznik" aria-hidden="true"></span><span class="screen-reader-text evk-tl-licznik-sr"></span>')
            . '</button>';
    }
    echo '</div>';
});

/* Tytuł i adres języka — w bloku tytułu, między polem tytułu a adresem. */
add_action('edit_form_before_permalink', function ($post) {
    if (!($post instanceof WP_Post) || !evk_tlw_ekran() || !($jezyki = evk_tlw_jezyki())) return;
    foreach ($jezyki as $kod => $nazwa) {
        $K = strtoupper($kod);
        $wartosc = evk_tlw_meta($post->ID, $kod, 'post_title');
        $zrodlo  = (string) get_post_meta($post->ID, '_evk_tl_' . $kod . '__post_title__zrodlo', true);
        $id = 'evk-tlw-' . $kod . '-post_title';
        echo '<div class="evk-tlw-pole evk-tlw-tytul" data-lang="' . esc_attr($kod) . '" data-pole="post_title" data-oryginal="#title" data-ukryj="#titlewrap">';
        echo '<label class="screen-reader-text" for="' . esc_attr($id) . '">Tytuł ' . esc_html($K) . '</label>';
        echo '<input type="text" id="' . esc_attr($id) . '" class="evk-tlw-wejscie" name="evk_tlw[' . esc_attr($kod) . '][post_title][wartosc]"'
            . ' value="' . esc_attr($wartosc) . '" placeholder="' . esc_attr('Tytuł ' . $K) . '" autocomplete="off" spellcheck="true"'
            . ' data-pl="' . (evk_tlw_pusty($post->post_title) ? '0' : '1') . '">';
        evk_tlw_ukryte($kod, 'post_title', $wartosc, $zrodlo);
        evk_tlw_narzedzia($kod, 'post_title', $post->post_title, $wartosc, $zrodlo);
        echo '</div>';
        evk_tlw_pole_adresu($post, $kod);
    }
});

/** Pole adresu języka: człon z mapy adresów, pod nim polski człon i wspólne użycia. */
function evk_tlw_pole_adresu(WP_Post $post, string $kod): void {
    $K = strtoupper($kod);
    $pl = (string) $post->post_name;
    $czlon = $pl !== '' ? evk_tlw_slug($pl, $kod) : (string) get_post_meta($post->ID, '_evk_tl_' . $kod . '__post_name', true);
    $id = 'evk-tlw-' . $kod . '-post_name';
    /* Początek adresu: strona w języku z przetłumaczonymi rodzicami. */
    $rodzice = [];
    foreach (array_reverse(get_post_ancestors($post)) as $a) {
        $s = (string) get_post_field('post_name', $a);
        $rodzice[] = evk_tlw_slug($s, $kod) ?: $s;
    }
    $baza = untrailingslashit((string) get_option('home')) . '/' . $kod . '/' . ($rodzice ? implode('/', $rodzice) . '/' : '');
    echo '<div class="evk-tlw-pole evk-tlw-adres" data-lang="' . esc_attr($kod) . '" data-pole="post_name" data-ukryj="#edit-slug-box">';
    echo '<label for="' . esc_attr($id) . '" class="evk-tlw-adres-etykieta">Adres ' . esc_html($K) . ':</label> ';
    echo '<span class="evk-tlw-adres-baza">' . esc_html($baza) . '</span>';
    echo '<input type="text" id="' . esc_attr($id) . '" class="evk-tlw-slug" name="evk_tlw[' . esc_attr($kod) . '][post_name][wartosc]"'
        . ' value="' . esc_attr($czlon) . '" placeholder="' . esc_attr($pl !== '' ? $pl : 'jak polski') . '" autocomplete="off" spellcheck="false">';
    echo '<p class="evk-tlw-uwaga">Pusty = ten sam człon co po polsku' . ($pl !== '' ? ' („' . esc_html($pl) . '”)' : '') . '.';
    $inne = evk_tlw_inne_z_czlonem($pl, $post->ID);
    if ($inne) {
        echo ' Ten sam polski człon ma też: ' . esc_html(implode(', ', $inne)) . ' — tłumaczenie członu dotyczy wszystkich (mapa adresów jest wspólna).';
    }
    echo '</p></div>';
}

/* Treść i zajawka języka. Zajawki skrypt przenosi do pudełka „Zajawka". */
add_action('edit_form_after_editor', function ($post) {
    if (!($post instanceof WP_Post) || !evk_tlw_ekran() || !($jezyki = evk_tlw_jezyki())) return;
    $pola = evk_tlw_pola($post->post_type);
    foreach ($jezyki as $kod => $nazwa) {
        $K = strtoupper($kod);
        if (isset($pola['post_content'])) {
            echo '<div class="evk-tlw-pole evk-tlw-tresc" data-lang="' . esc_attr($kod) . '" data-pole="post_content" data-oryginal="#content" data-ukryj="#postdivrich">';
            if (evk_tlw_z_bricksa($post->ID)) {
                echo '<p class="evk-tlw-bricks">Treść tej strony jest w Bricksie — teksty tłumaczysz w builderze (pola „Tłumaczenie '
                    . esc_html($K) . '” w elementach) albo w słowniku.</p>';
            } else {
                $wartosc = evk_tlw_meta($post->ID, $kod, 'post_content');
                $zrodlo  = (string) get_post_meta($post->ID, '_evk_tl_' . $kod . '__post_content__zrodlo', true);
                $id = 'evk-tlw-' . $kod . '-post_content';
                echo '<p class="evk-tlw-etykieta-wiersz"><label class="evk-tlw-etykieta" for="' . esc_attr($id) . '">Treść ' . esc_html($K) . '</label></p>';
                echo '<textarea id="' . esc_attr($id) . '" class="evk-tlw-wejscie evk-tlw-edytor" name="evk_tlw[' . esc_attr($kod) . '][post_content][wartosc]"'
                    . ' rows="18" data-pl="' . (evk_tlw_pusty($post->post_content) ? '0' : '1') . '">' . esc_textarea($wartosc) . '</textarea>';
                evk_tlw_ukryte($kod, 'post_content', $wartosc, $zrodlo);
                evk_tlw_narzedzia($kod, 'post_content', $post->post_content, $wartosc, $zrodlo);
            }
            echo '</div>';
        }
        if (isset($pola['post_excerpt'])) {
            $wartosc = evk_tlw_meta($post->ID, $kod, 'post_excerpt');
            $zrodlo  = (string) get_post_meta($post->ID, '_evk_tl_' . $kod . '__post_excerpt__zrodlo', true);
            $id = 'evk-tlw-' . $kod . '-post_excerpt';
            echo '<div class="evk-tlw-pole evk-tlw-zajawka" data-lang="' . esc_attr($kod) . '" data-pole="post_excerpt" data-oryginal="#excerpt"'
                . ' data-do="#postexcerpt .inside" data-ukryj="#excerpt, #postexcerpt .inside > p, #postexcerpt .inside > label[for=excerpt]">';
            echo '<label class="evk-tlw-etykieta" for="' . esc_attr($id) . '">Zajawka ' . esc_html($K) . '</label>';
            echo '<textarea id="' . esc_attr($id) . '" class="evk-tlw-wejscie" rows="3" name="evk_tlw[' . esc_attr($kod) . '][post_excerpt][wartosc]"'
                . ' data-pl="' . (evk_tlw_pusty($post->post_excerpt) ? '0' : '1') . '">' . esc_textarea($wartosc) . '</textarea>';
            evk_tlw_ukryte($kod, 'post_excerpt', $wartosc, $zrodlo);
            evk_tlw_narzedzia($kod, 'post_excerpt', $post->post_excerpt, $wartosc, $zrodlo);
            echo '</div>';
        }
    }
});

// =========================================================================
// ZAPIS
// =========================================================================

/**
 * Tłumaczenia z formularza. Źródło = bieżący polski tekst, gdy tłumaczenie się
 * zmieniło (skrót z renderu nie pasuje), tłumacz kliknął „Sprawdzone"
 * (`teraz`) albo źródła jeszcze nie było; inaczej zostaje stare — zmiana
 * samego polskiego tekstu zostawia tłumaczenie „Do sprawdzenia".
 * Sanityzacja jak rdzeń dla tego pola ({pole}_save_pre: kses bez
 * unfiltered_html). Język bez pól w formularzu zostaje nietknięty.
 */
add_action('save_post', function ($pid, $post) {
    if (!($post instanceof WP_Post) || wp_is_post_revision($pid) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    if (empty($_POST['evk_tlw']) || !is_array($_POST['evk_tlw'])) return;
    if (!isset($_POST['evk_tlw_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['evk_tlw_nonce'])), 'evk_tlw_zapis')) return;
    if (!current_user_can('edit_post', $pid) || !in_array($post->post_type, evk_tlw_typy(), true)) return;

    $jezyki = evk_tlw_jezyki();
    $pola   = evk_tlw_pola($post->post_type);
    $bledy  = [];
    foreach ((array) $_POST['evk_tlw'] as $kod => $dane) {
        $kod = sanitize_key((string) $kod);
        if (!isset($jezyki[$kod]) || !is_array($dane)) continue;
        foreach ($pola as $pole => $nazwa) {
            if (!isset($dane[$pole]['wartosc']) || !is_string($dane[$pole]['wartosc'])) continue;
            if ($pole === 'post_content' && evk_tlw_z_bricksa($pid)) continue;
            $surowa = sanitize_post_field($pole, $dane[$pole]['wartosc'], $pid, 'db');   // ukośniki jak w $_POST
            $tekst  = (string) wp_unslash($surowa);
            if ($pole === 'post_title') $tekst = trim($tekst);
            $klucz  = '_evk_tl_' . $kod . '__' . $pole;
            if (evk_tlw_pusty($tekst)) {
                delete_post_meta($pid, '_evk_tl_' . $kod . '__' . $pole);
                delete_post_meta($pid, '_evk_tl_' . $kod . '__' . $pole . '__zrodlo');
                continue;
            }
            $przed  = sanitize_key((string) ($dane[$pole]['przed'] ?? ''));
            $zrodlo = sanitize_key((string) ($dane[$pole]['zrodlo'] ?? ''));
            if ($zrodlo === '' || $zrodlo === 'teraz' || evk_tlw_skrot($tekst) !== $przed) {
                $zrodlo = evk_tlw_zrodlo((string) $post->{$pole});
            }
            update_post_meta($pid, '_evk_tl_' . $kod . '__' . $pole, wp_slash($tekst));
            update_post_meta($pid, $klucz . '__zrodlo', $zrodlo);
        }
        // Adres: człon do mapy adresów (wpis bez polskiego adresu — szkic — czeka w metadanych).
        if (isset($dane['post_name']['wartosc']) && is_string($dane['post_name']['wartosc'])) {
            $czlon = sanitize_title(wp_unslash($dane['post_name']['wartosc']));
            $pl = (string) $post->post_name;
            if ($pl === '') {
                if ($czlon === '') {
                    delete_post_meta($pid, '_evk_tl_' . $kod . '__post_name');
                } else {
                    update_post_meta($pid, '_evk_tl_' . $kod . '__post_name', $czlon);
                }
                continue;
            }
            delete_post_meta($pid, '_evk_tl_' . $kod . '__post_name');
            if ($czlon !== '' && $czlon !== $pl) {
                $blad = evk_tlw_slug_konflikt($pl, $kod, $czlon, $pid, (string) ($GLOBALS['evk_tlw_stary_slug'][$pid] ?? ''));
                if ($blad !== '') {
                    $bledy[] = 'Adres ' . strtoupper($kod) . ' nie został zapisany. ' . $blad;
                    continue;
                }
            }
            evk_tlw_zapisz_slug($pl, $kod, $czlon);
        }
    }
    // Szkic dostał polski adres (publikacja) — człony czekające w metadanych idą do mapy.
    if ((string) $post->post_name !== '') {
        foreach (array_keys($jezyki) as $kod) {
            $czeka = (string) get_post_meta($pid, '_evk_tl_' . $kod . '__post_name', true);
            if ($czeka === '') continue;
            delete_post_meta($pid, '_evk_tl_' . $kod . '__post_name');
            if (evk_tlw_slug((string) $post->post_name, $kod) !== '') continue;
            $blad = evk_tlw_slug_konflikt((string) $post->post_name, $kod, $czeka, $pid);
            if ($blad === '') {
                evk_tlw_zapisz_slug((string) $post->post_name, $kod, $czeka);
            } else {
                $bledy[] = 'Adres ' . strtoupper($kod) . ' nie został zapisany. ' . $blad;
            }
        }
    }
    if ($bledy) set_transient('evk_tlw_bledy_' . get_current_user_id(), $bledy, 120);
}, 10, 2);

/* Błędy adresów po zapisie — na ekranie, na który wraca edytor. */
add_action('admin_notices', function () {
    if (!evk_tlw_ekran()) return;
    $klucz = 'evk_tlw_bledy_' . get_current_user_id();
    $bledy = get_transient($klucz);
    if (!is_array($bledy) || !$bledy) return;
    delete_transient($klucz);
    echo '<div class="notice notice-error"><p>' . implode('</p><p>', array_map('esc_html', $bledy)) . '</p></div>';
});

// =========================================================================
// LISTA WPISÓW: KOLUMNA „JĘZYKI"
// =========================================================================

add_action('admin_init', function () {
    if (!evk_tlw_jezyki()) return;
    foreach (evk_tlw_typy() as $typ) {
        add_filter('manage_' . $typ . '_posts_columns', function ($kolumny) {
            $nowe = [];
            foreach ((array) $kolumny as $k => $v) {
                $nowe[$k] = $v;
                if ($k === 'title') $nowe['evk_tlw'] = 'Języki';
            }
            if (!isset($nowe['evk_tlw'])) $nowe['evk_tlw'] = 'Języki';
            return $nowe;
        });
        add_action('manage_' . $typ . '_posts_custom_column', function ($kolumna, $pid) {
            if ($kolumna !== 'evk_tlw') return;
            $post = get_post((int) $pid);
            if (!$post) return;
            $stany = [];
            foreach (array_keys(evk_tlw_jezyki()) as $kod) $stany[$kod] = evk_tlw_stan($post, $kod);
            echo evk_tlw_kolumna_html($stany);   // gotowy HTML z esc_* w środku
        }, 10, 2);
    }
});

/**
 * Kolumna „Języki": przy każdym języku stan — gotowe, częściowo, brak, do
 * sprawdzenia, nie ma czego tłumaczyć — z opisem dla czytnika ekranu.
 *
 * @param array<string, array{n: int, m: int, sprawdz: bool}> $stany
 */
function evk_tlw_kolumna_html(array $stany): string {
    $jezyki = evk_tlw_jezyki();
    $out = '<span class="evk-tlw-kolumna">';
    foreach ($stany as $kod => $s) {
        $nazwa = $jezyki[$kod] ?? $kod;
        if ($s['m'] === 0)            { $klasa = 'is-brak-tekstu'; $opis = 'nie ma czego tłumaczyć'; }
        elseif ($s['sprawdz'])        { $klasa = 'is-sprawdz';     $opis = 'do sprawdzenia, przetłumaczone ' . $s['n'] . ' z ' . $s['m']; }
        elseif ($s['n'] === $s['m'])  { $klasa = 'is-gotowe';      $opis = 'przetłumaczone ' . $s['n'] . ' z ' . $s['m']; }
        elseif ($s['n'] > 0)          { $klasa = 'is-czesc';       $opis = 'przetłumaczone ' . $s['n'] . ' z ' . $s['m']; }
        else                          { $klasa = 'is-brak';        $opis = 'brak tłumaczenia'; }
        $out .= '<span class="evk-tlw-stan ' . $klasa . '" title="' . esc_attr($nazwa . ': ' . $opis) . '">'
            . '<span aria-hidden="true">' . esc_html(strtoupper((string) $kod)) . '</span>'
            . '<span class="screen-reader-text">' . esc_html($nazwa . ': ' . $opis) . '</span></span>';
    }
    return $out . '</span>';
}

/** Wygląd kolumny „Języki" — lista wpisów i lista termów. */
function evk_tlw_styl_kolumny(): void {
    if (!evk_tlw_jezyki()) return;
    echo '<style id="evk-tlw-kolumna">'
        . '.column-evk_tlw{width:110px;}'
        . '.evk-tlw-kolumna{display:inline-flex;flex-wrap:wrap;gap:4px;}'
        . '.evk-tlw-stan{display:inline-block;min-width:28px;padding:1px 6px;border-radius:9px;font-size:11px;font-weight:600;line-height:18px;text-align:center;border:1px solid;}'
        . '.evk-tlw-stan.is-gotowe{background:#edfaef;border-color:#68de7c;color:#1a6d2c;}'
        . '.evk-tlw-stan.is-czesc{background:#fcf9e8;border-color:#f0c33c;color:#6e4e00;}'
        . '.evk-tlw-stan.is-sprawdz{background:#fcf0e3;border-color:#dba617;color:#8a4b00;}'
        . '.evk-tlw-stan.is-brak{background:#fff;border-color:#c3c4c7;color:#646970;}'
        . '.evk-tlw-stan.is-brak-tekstu{background:#f6f7f7;border-color:#dcdcde;color:#a7aaad;}'
        . '</style>';
}
add_action('admin_head-edit.php', 'evk_tlw_styl_kolumny');

// =========================================================================
// PRZENIESIENIE ZE SŁOWNIKA (zakładka „Wpisy i kategorie")
// =========================================================================

/**
 * Teksty, które słownik tłumaczy w całości, a pole języka jest puste: tytuły
 * i zajawki wpisów (1.252.0) oraz nazwy termów (1.253.0, gdy moduł termów
 * jest). Słownik tłumaczy je dziś na stronie (menu, nagłówki), więc pole
 * języka dostaje to samo. Wpisy we wszystkich stanach poza koszem.
 *
 * @return list<array{rodzaj: string, id: int, tytul: string, typ: string, pole: string, nazwa: string, jezyk: string, pl: string, tekst: string}>
 */
function evk_tlw_slownik_kandydaci(): array {
    $jezyki = evk_tlw_jezyki();
    if (!$jezyki) return [];
    $out = [];
    $q = new WP_Query([
        'post_type' => evk_tlw_typy(), 'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
        'posts_per_page' => -1, 'no_found_rows' => true, 'orderby' => 'title', 'order' => 'ASC',
        'update_post_term_cache' => false, 'suppress_filters' => true,
    ]);
    foreach ($q->posts as $post) {
        if (!($post instanceof WP_Post)) continue;
        $typ = get_post_type_object($post->post_type);
        foreach (['post_title' => 'Tytuł', 'post_excerpt' => 'Zajawka'] as $pole => $nazwa) {
            if ($pole === 'post_excerpt' && !post_type_supports($post->post_type, 'excerpt')) continue;
            $pl = (string) $post->{$pole};
            if (evk_tlw_pusty($pl)) continue;
            foreach (array_keys($jezyki) as $kod) {
                if (!evk_tlw_pusty(evk_tlw_meta($post->ID, $kod, $pole))) continue;
                $tekst = evk_tlw_ze_slownika($pl, $kod);
                if ($tekst === '') continue;
                $out[] = ['rodzaj' => 'post', 'id' => $post->ID, 'tytul' => $post->post_title !== '' ? $post->post_title : 'ID ' . $post->ID,
                          'typ' => $typ ? (string) $typ->labels->singular_name : $post->post_type,
                          'pole' => $pole, 'nazwa' => $nazwa, 'jezyk' => $kod, 'pl' => $pl, 'tekst' => $tekst];
            }
        }
    }
    if (function_exists('evk_tlt_taksonomie')) {
        $terminy = get_terms(['taxonomy' => evk_tlt_taksonomie(), 'hide_empty' => false, 'orderby' => 'name']);
        foreach (is_array($terminy) ? $terminy : [] as $term) {
            $tax = get_taxonomy($term->taxonomy);
            foreach (array_keys($jezyki) as $kod) {
                if (!evk_tlw_pusty(evk_tlt_meta((int) $term->term_id, $kod, 'name'))) continue;
                $tekst = evk_tlw_ze_slownika($term->name, $kod);
                if ($tekst === '') continue;
                $out[] = ['rodzaj' => 'term', 'id' => (int) $term->term_id, 'tytul' => $term->name,
                          'typ' => $tax ? (string) $tax->labels->singular_name : $term->taxonomy,
                          'pole' => 'name', 'nazwa' => 'Nazwa', 'jezyk' => $kod, 'pl' => $term->name, 'tekst' => $tekst];
            }
        }
    }
    return $out;
}

/**
 * Zapis przeniesienia: pole języka + źródło = bieżący polski tekst. Tylko
 * to, co użytkownik może edytować; drugi raz nie ma już czego przenieść.
 *
 * @return array{zapisane: int, pominiete: int}
 */
function evk_tlw_slownik_przenies(): array {
    $wynik = ['zapisane' => 0, 'pominiete' => 0];
    foreach (evk_tlw_slownik_kandydaci() as $k) {
        $wolno = $k['rodzaj'] === 'term' ? current_user_can('edit_term', $k['id']) : current_user_can('edit_post', $k['id']);
        if (!$wolno) { $wynik['pominiete']++; continue; }
        if ($k['rodzaj'] === 'term') {
            update_term_meta($k['id'], '_evk_tl_' . $k['jezyk'] . '__name', wp_slash($k['tekst']));
            update_term_meta($k['id'], '_evk_tl_' . $k['jezyk'] . '__name__zrodlo', evk_tlw_zrodlo($k['pl']));
        } else {
            update_post_meta($k['id'], '_evk_tl_' . $k['jezyk'] . '__' . $k['pole'], wp_slash($k['tekst']));
            update_post_meta($k['id'], '_evk_tl_' . $k['jezyk'] . '__' . $k['pole'] . '__zrodlo', evk_tlw_zrodlo($k['pl']));
        }
        $wynik['zapisane']++;
    }
    return $wynik;
}

add_action('wp_ajax_evk_tlw_slownik', function (): void {
    evk_tl_ajax_check();
    if (($_POST['tryb'] ?? '') === 'zapisz') {
        wp_send_json_success(evk_tlw_slownik_przenies());
    }
    $wiersze = evk_tlw_slownik_kandydaci();
    foreach ($wiersze as &$w) {
        $w['adres'] = $w['rodzaj'] === 'term' ? (string) get_edit_term_link($w['id']) : (string) get_edit_post_link($w['id'], 'raw');
    }
    unset($w);
    wp_send_json_success(['wiersze' => $wiersze]);
});
