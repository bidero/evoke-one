<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — strony techniczne: Kokpit i strona zasłony konserwacji (1.235.0).
 *
 * Obie to zwykłe opublikowane strony Bricksa. Kokpit wyświetla się w iframe
 * na pulpicie, zasłona — pod każdym adresem w czasie konserwacji. Do 1.234.1
 * pod WŁASNYM adresem widział je każdy: Google je indeksował, były w mapie
 * strony, a zasłona pokazywała „Przerwa techniczna" także przy wyłączonej
 * konserwacji.
 *
 * Teraz dla niezalogowanego to zwykłe 404, takie samo jak pod adresem, którego
 * nie ma — i to na każdej drodze, którą WordPress wystawia treść strony:
 *   — główne zapytanie (ładny adres), wyszukiwarka, pętle zapytań (także
 *     AJAX-owe z frontu) i lista stron w REST — `pre_get_posts`;
 *   — zapytania po identyfikatorze (?page_id=, ?p=, post__in): WP_Query bierze
 *     tylko jedno z `p`, `post__in` i `post__not_in`, więc `?page_id=` szło
 *     obok `pre_get_posts` z 200 i pełną treścią — bezpiecznik na wynikach
 *     (`the_posts`);
 *   — przekierowania kanoniczne: przy 404 WordPress zgaduje adres, więc
 *     `/kokpi` szło 301 na `/kokpit/` i zdradzało, że strona jest;
 *   — pojedyncza strona w REST (z pełną treścią) — ten sam błąd co dla
 *     nieistniejącego identyfikatora;
 *   — oEmbed także dla adresu `?page_id=N`: url_to_postid() oddaje wtedy
 *     numer od ręki, bez WP_Query.
 * get_pages() (wp_list_pages(), lista stron w nagłówku motywu, zapasowe menu)
 * idzie od WordPressa 6.3 przez WP_Query, więc obejmuje ją to samo.
 * Mapa strony i meta robots (85-seo.php) pomijają je zawsze, także dla
 * zalogowanych.
 *
 * Zalogowani widzą obie strony jak dotąd — iframe Kokpitu idzie z ich
 * ciasteczkami. Zasłona w czasie konserwacji nie idzie przez WP_Query
 * (95-maintenance.php podmienia zapytanie sam), więc działa bez zmian.
 */

/** Identyfikatory stron technicznych. Nigdy strona główna ani strona wpisów. */
function evk_strony_techniczne(): array {
    $ids = array_filter([
        (int) get_option('evoke_dashboard_page_id', 0),
        (int) get_option('maintenance_page_id', 0),
    ]);
    /* Bezpiecznik: strona główna wybrana omyłkowo jako Kokpit albo zasłona
       zniknęłaby dla wszystkich odwiedzających. */
    $ids = array_diff($ids, [(int) get_option('page_on_front', 0), (int) get_option('page_for_posts', 0)]);
    return array_values(array_unique($ids));
}

function evk_strona_techniczna(int $id): bool {
    return $id > 0 && in_array($id, evk_strony_techniczne(), true);
}

/**
 * Czy chować przed tym żądaniem: niezalogowany odwiedzający. WP-CLI, cron
 * i sondy testów (PHP_SAPI „cli") widzą wszystko — to nie odwiedzający,
 * a `wp post list` bez tych stron byłby pułapką.
 */
function evk_strony_techniczne_ukryj(): bool {
    return !is_user_logged_in() && PHP_SAPI !== 'cli' && !wp_doing_cron();
}

/** Ścieżka adresu bez końcowego ukośnika — do porównań z celem przekierowania. */
function evk_strony_techniczne_sciezka(string $url): string {
    return untrailingslashit((string) wp_parse_url($url, PHP_URL_PATH));
}

add_action('pre_get_posts', function ($q) {
    if (!($q instanceof WP_Query) || !evk_strony_techniczne_ukryj()) return;
    $ids = evk_strony_techniczne();
    if (!$ids) return;
    $juz = array_map('absint', (array) $q->get('post__not_in'));
    $q->set('post__not_in', array_values(array_unique(array_merge($juz, $ids))));
});

// Zapytania po identyfikatorze (?page_id=, ?p=, post__in) — patrz nagłówek pliku.
add_filter('the_posts', function ($posty) {
    if (!is_array($posty) || !$posty || !evk_strony_techniczne_ukryj()) return $posty;
    $ids = evk_strony_techniczne();
    return array_values(array_filter($posty, static function ($p) use ($ids) {
        return !($p instanceof WP_Post) || !in_array((int) $p->ID, $ids, true);
    }));
});

add_filter('redirect_canonical', function ($cel) {
    if (!is_string($cel) || $cel === '' || !evk_strony_techniczne_ukryj()) return $cel;
    $sciezka = evk_strony_techniczne_sciezka($cel);
    foreach (evk_strony_techniczne() as $id) {
        // Po ścieżce, nie przez url_to_postid(): ta pyta WP_Query, a z niej strony już zniknęły.
        if (evk_strony_techniczne_sciezka((string) get_permalink($id)) === $sciezka) return false;
    }
    return $cel;
}, 20);

/* Pojedyncza strona w REST, zanim kontroler ją przygotuje. Błąd z filtra
   `rest_prepare_page` wywracał kontroler (woła potem `link_header()` na
   odpowiedzi) i kończył się 500 — czyli też zdradzał, że pod tym numerem coś
   jest. Kod i treść jak dla identyfikatora, którego nie ma. */
add_filter('rest_request_before_callbacks', function ($odpowiedz, $obsluga, $zadanie) {
    if ($odpowiedz !== null || !($zadanie instanceof WP_REST_Request) || !evk_strony_techniczne_ukryj()) return $odpowiedz;
    if (preg_match('#^/wp/v2/pages/(\d+)#', (string) $zadanie->get_route(), $m) && evk_strona_techniczna((int) $m[1])) {
        return new WP_Error('rest_post_invalid_id', __('Invalid post ID.'), ['status' => 404]);
    }
    return $odpowiedz;
}, 10, 3);

add_filter('oembed_request_post_id', function ($id) {
    return (evk_strony_techniczne_ukryj() && evk_strona_techniczna((int) $id)) ? 0 : $id;
});

add_filter('wp_sitemaps_posts_query_args', function ($args) {
    $ids = evk_strony_techniczne();
    if (!$ids) return $args;
    $juz = isset($args['post__not_in']) ? array_map('absint', (array) $args['post__not_in']) : [];
    $args['post__not_in'] = array_values(array_unique(array_merge($juz, $ids)));
    return $args;
});
