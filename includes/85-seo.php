<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł SEO
 *
 * Evoke ONE renderuje komplet meta tagów (title, description, keywords,
 * robots, og:*). Dane per strona wg łańcucha priorytetów:
 *   1. Bricks — Ustawienia strony → SEO / Media społecznościowe
 *      (documentTitle, metaDescription, metaKeywords, metaRobots,
 *       sharingTitle, sharingDescription, sharingImage; fallback na
 *       ustawienia aktywnego szablonu treści Bricks),
 *   2. zakładka SEO Evoke ONE (_evoke_seo_*),
 *   3. fallback automatyczny (tytuł strony, generator OG, miniatura).
 * Z tego samego resolvera korzysta moduł Schema (evk_seo_get_meta()).
 * Natywne meta tagi SEO/OG Bricksa są wyłączane, żeby nie dublować wpisów.
 */

// =========================================================================
// RESOLVER META DANYCH (wspólny dla SEO, OG i Schema)
// =========================================================================

/**
 * Ustawienia strony Bricks (_bricks_page_settings) z fallbackiem
 * na ustawienia aktywnego szablonu treści.
 */
function evk_seo_bricks_settings(int $pid): array {
    static $cache = [];
    if (isset($cache[$pid])) return $cache[$pid];

    $settings = get_post_meta($pid, '_bricks_page_settings', true);
    $settings = is_array($settings) ? $settings : [];

    if (class_exists('\Bricks\Database') && !empty(\Bricks\Database::$active_templates['content'])) {
        $tpl_id = (int) \Bricks\Database::$active_templates['content'];
        if ($tpl_id && $tpl_id !== $pid) {
            $tpl = get_post_meta($tpl_id, '_bricks_page_settings', true);
            if (is_array($tpl)) $settings += $tpl; // strona ma pierwszeństwo przed szablonem
        }
    }

    return $cache[$pid] = $settings;
}

/**
 * Renderuje wartość z Bricksa (dynamic data {tags}) do czystego tekstu.
 */
function evk_seo_render_bricks_value($value, int $pid): string {
    if (!is_string($value) || trim($value) === '') return '';
    if (function_exists('bricks_render_dynamic_data')) {
        $value = bricks_render_dynamic_data($value, $pid);
    }
    return trim(wp_strip_all_tags((string) $value));
}

/**
 * URL obrazka z pola sharingImage Bricksa (id / url / dynamic data).
 */
function evk_seo_bricks_sharing_image($img, int $pid): string {
    if (empty($img) || !is_array($img)) return '';

    if (!empty($img['useDynamicData']) && class_exists('\Bricks\Integrations\Dynamic_Data\Providers')) {
        $images = \Bricks\Integrations\Dynamic_Data\Providers::render_tag($img['useDynamicData'], $pid, 'image');
        if (!empty($images[0])) {
            return is_numeric($images[0])
                ? (string) wp_get_attachment_image_url((int) $images[0], 'full')
                : (string) $images[0];
        }
        return '';
    }

    if (!empty($img['url'])) return (string) $img['url'];
    if (!empty($img['id']))  return (string) wp_get_attachment_image_url((int) $img['id'], 'full');
    return '';
}

/**
 * Komplet meta danych strony.
 *
 * Zwraca: title, title_custom (puste = brak nadpisania, WP dokleja nazwę
 * witryny), desc, keywords, robots[], og_title, og_desc, og_image.
 */
function evk_seo_get_meta(int $pid): array {
    static $cache = [];
    if (isset($cache[$pid])) return $cache[$pid];

    $b = evk_seo_bricks_settings($pid);

    // Tytuł — title_custom tylko gdy jawnie ustawiony (Bricks lub zakładka SEO)
    $title_custom = evk_seo_render_bricks_value($b['documentTitle'] ?? '', $pid);
    if ($title_custom === '') $title_custom = trim((string) get_post_meta($pid, '_evoke_seo_title', true));
    $title = $title_custom !== '' ? $title_custom : get_the_title($pid);

    // Opis
    $desc = evk_seo_render_bricks_value($b['metaDescription'] ?? '', $pid);
    if ($desc === '') $desc = trim((string) get_post_meta($pid, '_evoke_seo_desc', true));

    // Słowa kluczowe (Bricks: metaKeywords, inaczej zakładka SEO)
    $keywords = evk_seo_render_bricks_value($b['metaKeywords'] ?? '', $pid);
    if ($keywords === '') $keywords = trim((string) get_post_meta($pid, '_evoke_seo_keywords', true));

    // Robots
    $valid_robots = ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet'];
    $robots = array_values(array_intersect((array) ($b['metaRobots'] ?? []), $valid_robots));
    if (empty($robots)) {
        $robots = array_values(array_intersect(
            (array) (get_post_meta($pid, '_evoke_seo_robots', true) ?: []),
            $valid_robots
        ));
    }

    /* Typ treści „poza indeksem" (slajdy, pozycje menu, wiersze cennika).
       Wyrzucenie z mapy strony samo z siebie NIE WYPROWADZA z indeksu tego,
       co już się tam znalazło — mapa jest podpowiedzią, a nie zakazem. Dlatego
       flaga dokłada `noindex` do meta tagu. `follow` zostaje, żeby linki z tej
       podstrony dalej przekazywały wartość stronie, do której prowadzą. */
    if (function_exists('evk_sitemap_typ_poza_indeksem') && evk_sitemap_typ_poza_indeksem((string) get_post_type($pid))) {
        $robots = array_values(array_unique(array_merge(
            array_diff($robots, ['index']),
            ['noindex', 'follow']
        )));
    }

    /* Strona techniczna (Kokpit, zasłona konserwacji): niezalogowany dostaje
       404 (strony-techniczne.php), więc meta widzi tylko zalogowany — i nic
       z tej strony nie ma trafić do indeksu. */
    if (function_exists('evk_strona_techniczna') && evk_strona_techniczna((int) $pid)) {
        $robots = ['noindex', 'nofollow'];
    }

    // OG — najpierw Media społecznościowe Bricksa, potem łańcuch meta
    $og_title = evk_seo_render_bricks_value($b['sharingTitle'] ?? '', $pid);
    if ($og_title === '') $og_title = $title;

    $og_desc = evk_seo_render_bricks_value($b['sharingDescription'] ?? '', $pid);
    if ($og_desc === '') $og_desc = $desc;

    // Obrazek OG: Bricks sharingImage → generator OG Evoke → miniatura
    $og_image = evk_seo_bricks_sharing_image($b['sharingImage'] ?? null, $pid);
    if ($og_image === '' && function_exists('evk_og_get_url') && !empty(evk_og_get_settings()['enabled'])) {
        $og_image = (string) evk_og_get_url($pid);
    }
    if ($og_image === '' && has_post_thumbnail($pid)) {
        $og_image = (string) get_the_post_thumbnail_url($pid, 'full');
    }

    return $cache[$pid] = compact('title', 'title_custom', 'desc', 'keywords', 'robots', 'og_title', 'og_desc', 'og_image');
}

// =========================================================================
// WYŁĄCZENIE NATYWNYCH META TAGÓW BRICKSA (Evoke przejmuje renderowanie)
// =========================================================================

add_filter('bricks/frontend/disable_seo', '__return_true');
add_filter('bricks/frontend/disable_opengraph', '__return_true');

// =========================================================================
// ZAPIS PÓL ZAKŁADKI SEO (AJAX)
// =========================================================================

/* Nazwa nonce'a MUSI zgadzać się z `wp_localize_script('…', 'evoSeoAjax', …)`
   w `includes/admin/page.php` — panel wysyła go od dawna, brakowało wyłącznie
   sprawdzenia po tej stronie. Do 1.129.0 zalogowany administrator odwiedzający
   cudzą stronę mógł w tle dostać nadpisane tytuły, opisy i `robots`, masowo,
   bo zapis zbiorczy przyjmuje tablicę wierszy z dowolnymi `post_id`. */
add_action('wp_ajax_evoke_save_seo_ajax', function () {
    check_ajax_referer('evoke_seo_nonce', 'nonce');
    if (!current_user_can('manage_options') || empty($_POST['post_id'])) wp_send_json_error();
    $pid = absint($_POST['post_id']);
    update_post_meta($pid, '_evoke_seo_title',    sanitize_text_field(wp_unslash($_POST['seo_title']    ?? '')));
    update_post_meta($pid, '_evoke_seo_desc',     sanitize_textarea_field(wp_unslash($_POST['seo_desc'] ?? '')));
    update_post_meta($pid, '_evoke_seo_keywords', sanitize_text_field(wp_unslash($_POST['seo_keywords'] ?? '')));
    $robots = json_decode(wp_unslash($_POST['seo_robots'] ?? '[]'), true);
    $valid  = array_values(array_intersect((array)$robots, ['index','noindex','follow','nofollow','noarchive','nosnippet']));
    update_post_meta($pid, '_evoke_seo_robots', $valid);
    wp_send_json_success();
});

add_action('wp_ajax_evoke_save_seo_bulk', function () {
    check_ajax_referer('evoke_seo_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    $rows = json_decode(wp_unslash($_POST['rows'] ?? '[]'), true);
    $count = 0;
    foreach ((array)$rows as $row) {
        $pid = absint($row['post_id'] ?? 0);
        if (!$pid) continue;
        update_post_meta($pid, '_evoke_seo_title',    sanitize_text_field($row['seo_title']    ?? ''));
        update_post_meta($pid, '_evoke_seo_desc',     sanitize_textarea_field($row['seo_desc'] ?? ''));
        update_post_meta($pid, '_evoke_seo_keywords', sanitize_text_field($row['seo_keywords'] ?? ''));
        $robots = (array)($row['seo_robots'] ?? []);
        $valid  = array_values(array_intersect($robots, ['index','noindex','follow','nofollow','noarchive','nosnippet']));
        update_post_meta($pid, '_evoke_seo_robots', $valid);
        $count++;
    }
    wp_send_json_success(['saved' => $count]);
});

// =========================================================================
// FRONTEND — tytuł dokumentu i meta tagi w <head>
// =========================================================================

// 1. Filtr tytułu — nadpisuje tylko, gdy tytuł jest jawnie ustawiony
add_filter('pre_get_document_title', function ($title) {
    if (!is_singular() && !is_home()) return $title;
    $pid = get_queried_object_id();
    if (!$pid) return $title;
    $custom = evk_seo_get_meta($pid)['title_custom'];
    return $custom !== '' ? $custom : $title;
}, 999);

/**
 * Archiwa typów i taksonomii oznaczonych jako „poza indeksem".
 *
 * Resolver `evk_seo_get_meta()` pracuje na ID wpisu, więc nie widzi archiwum
 * typu ani strony termu — a to właśnie one zostają w indeksie najdłużej, bo
 * linkuje do nich nawigacja. Osobny wpis w `<head>`, ten sam warunek co
 * w mapie strony.
 */
add_action('wp_head', function () {
    if (is_singular() || is_home()) return;
    if (!function_exists('evk_sitemap_typ_poza_indeksem')) return;

    $poza = false;

    if (is_post_type_archive()) {
        $typ  = get_query_var('post_type');
        $typ  = is_array($typ) ? (string) reset($typ) : (string) $typ;
        $poza = $typ !== '' && evk_sitemap_typ_poza_indeksem($typ);
    } elseif (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        $poza = $term instanceof WP_Term && evk_sitemap_taksonomia_poza_indeksem($term->taxonomy);
    }

    if ($poza) echo '<meta name="robots" content="noindex, follow">' . "\n";
}, 5);

// =========================================================================
// OG: JĘZYK, OBRAZEK, KARTA X — wspólne dla stron i archiwów (1.236.0)
// =========================================================================

/**
 * Kod w postaci, której chce og:locale: „język_REGION" („pl_PL"). Przyjmuje
 * locale WordPressa („pl_PL", „de_DE_formal") i kody HTML z Tłumaczeń
 * („en-US"). Sam język („pl") daje pusty wynik — lepiej nie wypisać nic, niż
 * zgadywać region; bez og:locale Facebook przyjmuje en_US.
 */
function evk_seo_og_locale(string $kod): string {
    return preg_match('/^([a-z]{2,3})[-_]([A-Za-z]{2})(?![A-Za-z])/', $kod, $m) ? strtolower($m[1]) . '_' . strtoupper($m[2]) : '';
}

/**
 * og:locale strony i og:locale:alternate. Bez Tłumaczeń: język witryny.
 * Z Tłumaczeniami: język bieżącej wersji (polska = język witryny, pozostałe
 * z kodów HTML w ustawieniach Tłumaczeń), a reszta jako alternatywy.
 *
 * @return array{0: string, 1: list<string>}
 */
function evk_seo_og_locale_strony(): array {
    $witryna = evk_seo_og_locale((string) get_locale());
    if (!function_exists('tl_get_languages') || !function_exists('get_current_lang')) return [$witryna, []];
    $wszystkie = ['pl' => $witryna];
    foreach (tl_get_languages() as $kod => $jezyk) $wszystkie[$kod] = evk_seo_og_locale((string) ($jezyk['html'] ?? ''));
    $biezacy = get_current_lang();
    $glowny  = $wszystkie[$biezacy] ?? $witryna;
    $inne    = array_diff(array_unique(array_filter($wszystkie)), [$glowny]);
    return [$glowny, array_values($inne)];
}

/**
 * Wymiary i tekst alternatywny obrazka OG. Bez og:image:width/height Facebook
 * przy pierwszym udostępnieniu często pokazuje link bez obrazka (przetwarza go
 * dopiero w tle); alt czytają czytniki ekranu w serwisach społecznościowych.
 *
 * Najpierw biblioteka mediów (wymiary i alt z załącznika), potem plik
 * w katalogu uploads (wymiary z pliku: obrazki generatora OG z dopiskiem
 * `?v=`, og-fallback.jpg). Obcy adres zostaje bez wymiarów.
 *
 * @return array{w: int, h: int, alt: string}
 */
function evk_seo_obrazek_info(string $url): array {
    static $cache = [];
    if (isset($cache[$url])) return $cache[$url];
    $info = ['w' => 0, 'h' => 0, 'alt' => ''];
    $czysty = (string) strtok($url, '?');
    $id = $czysty !== '' ? attachment_url_to_postid($czysty) : 0;
    if ($id) {
        $meta = wp_get_attachment_metadata($id);
        $info['w']   = (int) ($meta['width'] ?? 0);
        $info['h']   = (int) ($meta['height'] ?? 0);
        $info['alt'] = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
        return $cache[$url] = $info;
    }
    $uploads = wp_upload_dir(null, false);
    if ($czysty !== '' && strpos($czysty, $uploads['baseurl'] . '/') === 0) {
        $plik = $uploads['basedir'] . substr($czysty, strlen($uploads['baseurl']));
        $rozmiar = is_file($plik) ? @getimagesize($plik) : false;
        if ($rozmiar) {
            $info['w'] = (int) $rozmiar[0];
            $info['h'] = (int) $rozmiar[1];
        }
    }
    return $cache[$url] = $info;
}

/**
 * Tagi OG i karta X — jedno miejsce dla stron i archiwów.
 *
 * @param array{title: string, type: string, url: string, desc: string, image: string} $d
 */
function evk_seo_wypisz_og(array $d): void {
    [$locale, $inne] = evk_seo_og_locale_strony();
    echo '<meta property="og:title" content="' . esc_attr($d['title']) . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr($d['type']) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($d['url']) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    if ($locale !== '') {
        echo '<meta property="og:locale" content="' . esc_attr($locale) . '">' . "\n";
        foreach ($inne as $l) echo '<meta property="og:locale:alternate" content="' . esc_attr($l) . '">' . "\n";
    }
    if ($d['desc'] !== '') echo '<meta property="og:description" content="' . esc_attr($d['desc']) . '">' . "\n";
    if ($d['image'] !== '') {
        $info = evk_seo_obrazek_info($d['image']);
        echo '<meta property="og:image" content="' . esc_url($d['image']) . '">' . "\n";
        if ($info['w'] > 0 && $info['h'] > 0) {
            echo '<meta property="og:image:width" content="' . $info['w'] . '">' . "\n";
            echo '<meta property="og:image:height" content="' . $info['h'] . '">' . "\n";
        }
        echo '<meta property="og:image:alt" content="' . esc_attr($info['alt'] !== '' ? $info['alt'] : $d['title']) . '">' . "\n";
    }
    /* Duża karta tylko z obrazkiem — bez niego X rysuje pustą ramkę. Tytuł,
       opis i obrazek X bierze z og:*, więc nic więcej nie trzeba. */
    echo '<meta name="twitter:card" content="' . ($d['image'] !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
}

// 2. Meta tagi w <head> — wpisy, strony i strona wpisów
add_action('wp_head', function () {
    if (!is_singular() && !is_home()) return;

    $pid = get_queried_object_id();
    if (!$pid) return;   // strona główna z ostatnimi wpisami — hak archiwów niżej

    $m = evk_seo_get_meta($pid);

    if ($m['desc'])     echo '<meta name="description" content="' . esc_attr($m['desc']) . '">' . "\n";
    if ($m['keywords']) echo '<meta name="keywords" content="' . esc_attr($m['keywords']) . '">' . "\n";
    if ($m['robots'])   echo '<meta name="robots" content="' . esc_attr(implode(', ', $m['robots'])) . '">' . "\n";

    // OpenGraph
    if (is_home()) {
        $og_type = 'blog';
    } elseif (get_post_type($pid) === 'post') {
        $og_type = 'article';
    } else {
        $og_type = 'website';
    }

    evk_seo_wypisz_og([
        'title' => (string) $m['og_title'],
        'type'  => $og_type,
        'url'   => (string) get_permalink($pid),
        'desc'  => (string) $m['og_desc'],
        'image' => (string) $m['og_image'],
    ]);
}, 5);

/**
 * Archiwa: kategorie, tagi i inne taksonomie, archiwa typów treści oraz strona
 * główna z ostatnimi wpisami (1.236.0). Do 1.235.0 nie dostawały ani opisu,
 * ani OG — strona główna bloga też nie, bo resolver pracuje na ID wpisu,
 * a tam go nie ma. Opis: opis kategorii/tagu, opis typu treści, slogan
 * witryny. Obrazek: domyślny obrazek OG (ustawienia generatora albo
 * og-fallback.jpg). Archiwa dat i autorów zostają bez zmian.
 *
 * @return array{title: string, type: string, url: string, desc: string, image: string}|null
 */
function evk_seo_archiwum(): ?array {
    if (is_home() && is_front_page()) {
        $d = ['title' => (string) get_bloginfo('name'), 'desc' => (string) get_bloginfo('description'), 'url' => home_url('/')];
    } elseif (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if (!($term instanceof WP_Term)) return null;
        $link = get_term_link($term);
        $d = ['title' => $term->name, 'desc' => $term->description, 'url' => is_wp_error($link) ? '' : $link];
    } elseif (is_post_type_archive()) {
        $typ = get_query_var('post_type');
        $typ = is_array($typ) ? (string) reset($typ) : (string) $typ;
        $obiekt = get_post_type_object($typ);
        if (!$obiekt) return null;
        $d = ['title' => (string) post_type_archive_title('', false), 'desc' => (string) $obiekt->description,
              'url' => (string) get_post_type_archive_link($typ)];
    } else {
        return null;
    }
    $strona = (int) get_query_var('paged');
    if ($strona > 1) $d['url'] = get_pagenum_link($strona, false);
    // Opis bywa długim wstępem z HTML-em — do meta idzie tekst, najwyżej 50 słów.
    $d['desc']  = wp_trim_words($d['desc'], 50, '…');
    $d['type']  = 'website';
    $d['image'] = function_exists('evk_og_obrazek_zastepczy') ? evk_og_obrazek_zastepczy(0) : '';
    return $d;
}

add_action('wp_head', function () {
    if (is_singular()) return;
    $d = evk_seo_archiwum();
    if ($d === null) return;
    if ($d['desc'] !== '') echo '<meta name="description" content="' . esc_attr($d['desc']) . '">' . "\n";
    evk_seo_wypisz_og($d);
}, 5);
