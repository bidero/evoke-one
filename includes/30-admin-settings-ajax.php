<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - admin settings, sanitizers and AJAX
 */

// ====================================================================
// 3. ADMIN SETTINGS + AJAX
// ====================================================================
add_action('admin_init', function () {
    register_setting('tl_group_languages', 'tl_menu_location', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'options-general.php']);
    register_setting('tl_group_translations', 'tl_translations', ['sanitize_callback' => 'tl_sanitize_translations_payload']);
    register_setting('tl_group_languages', 'tl_pl_flag', ['sanitize_callback' => 'absint', 'default' => 0]);
    register_setting('tl_group_images', 'tl_images', ['sanitize_callback' => 'tl_sanitize_images_payload']);
    register_setting('tl_group_slugs', 'tl_url_slugs', ['sanitize_callback' => 'tl_sanitize_slugs_payload']);
    register_setting('tl_group_sitemap', 'tl_sitemap_settings', ['sanitize_callback' => 'tl_sanitize_sitemap_settings']);
    if (get_option('ustawienia') && !get_option('tl_translations')) {
        update_option('tl_translations', get_option('ustawienia'));
    }
});

function tl_sanitize_translations_payload($input): array {
    $codes = evk_tl_kody_jezykow();
    $clean = ['groups' => []];
    foreach (($input['groups'] ?? []) as $group_id => $group) {
        $group_key = sanitize_key($group_id) ?: ('group_' . wp_rand(1000, 9999));
        $clean['groups'][$group_key] = ['name' => sanitize_text_field($group['name'] ?? ''), 'rows' => []];
        foreach (($group['rows'] ?? []) as $row_id => $row) {
            $row_key = sanitize_key($row_id) ?: ('row_' . wp_rand(1000, 9999));
            $clean['groups'][$group_key]['rows'][$row_key] = [
                'pl'     => tl_sanitize_phrase($row['pl'] ?? ''),
                'dd_key' => sanitize_key($row['dd_key'] ?? ''),
            ];
            foreach ($codes as $code) {
                $clean['groups'][$group_key]['rows'][$row_key][$code] = tl_sanitize_phrase($row[$code] ?? '');
            }
        }
    }
    return $clean;
}

function tl_sanitize_languages_payload($input): array {
    $clean = [];
    foreach ((array) $input as $lang) {
        $code = strtolower(preg_replace('/[^a-zA-Z]/', '', $lang['code'] ?? ''));
        if ($code && $code !== 'pl') {
            $clean[] = [
                'code' => $code,
                'name' => sanitize_text_field($lang['name'] ?? $code),
                'html' => sanitize_text_field($lang['html'] ?? $code),
                'flag' => absint($lang['flag'] ?? 0),
            ];
        }
    }
    return $clean;
}

function tl_sanitize_images_payload($input): array {
    $codes = evk_tl_kody_jezykow();
    $clean = [];
    foreach ((array) $input as $key => $entry) {
        $image_key = sanitize_key($key);
        if (!$image_key) continue;
        $clean[$image_key] = ['pl' => absint($entry['pl'] ?? 0)];
        foreach ($codes as $code) { $clean[$image_key][$code] = absint($entry[$code] ?? 0); }
    }
    return $clean;
}

function tl_sanitize_slugs_payload($input): array {
    $codes = evk_tl_kody_jezykow();
    $clean = [];
    foreach ((array) $input as $entry) {
        $pl_slug = sanitize_title($entry['pl'] ?? '');
        if (!$pl_slug) continue;
        $row = ['pl' => $pl_slug];
        foreach ($codes as $code) {
            $row[$code] = sanitize_title($entry[$code] ?? '');
        }
        $clean[] = $row;
    }
    return $clean;
}

function tl_get_sitemap_settings(): array {
    $defaults = [
        'enabled'                  => 0,
        'include_home'             => 1,
        'include_pages'            => 1,
        'include_posts'            => 1,
        'include_polish'           => 0,
        'only_translated_slugs'    => 0,
        'auto_exclude_noindex'     => 1,
        'excluded_ids'             => [],
		'include_users'            => 0,
        /* Sterowanie natywną mapą (`wp-sitemap.xml`) — puste tablice znaczą
           „jak WordPress domyślnie", czyli wszystkie publiczne typy treści
           i taksonomie w mapie. Dzięki temu aktualizacja nie zmienia niczego
           na stronie, na której nikt tych ekranów nie dotknął. */
        'excluded_types'           => [],
        'noindex_types'            => [],
        'excluded_taxonomies'      => [],
        'noindex_taxonomies'       => [],
        'anchor_sections'          => [],
        /* Język, na który wskazuje `x-default` — w mapie I w tagach `<head>`.
           Domyślnie polski, czyli to, co panel deklarował od zawsze. */
        'hreflang_default'         => 'pl',
    ];
    $saved = get_option('tl_sitemap_settings', []);
    return array_merge($defaults, is_array($saved) ? $saved : []);
}

/**
 * Czy ta wartość metadanych znaczy „noindex".
 *
 * MIESZKA TUTAJ, NIE W `80-sitemap.php`, i to jest cała treść poprawki z 1.164.0.
 * Tamten plik ładuje się WYŁĄCZNIE przy włączonym module tłumaczeń
 * (`evoke-one.php`, gałąź `$evk_tl_enabled`), a diagnostyka noindex na ekranie
 * SEO → Mapa strony woła tę funkcję bezwarunkowo — więc przy wyłączonych
 * tłumaczeniach ekran kończył się fatalem i nie dawało się go otworzyć.
 * ZGŁOSZONE Z ŻYWEJ STRONY.
 *
 * Sama funkcja nie ma z tłumaczeniami nic wspólnego: to predykat po metadanych
 * SEO, bez jednej zależności od silnika języków. Stała tam z historii.
 *
 * Sąsiaduje z `tl_get_sitemap_settings()` powyżej z tego samego powodu — oba są
 * pomocnikami mapy strony, których panel potrzebuje niezależnie od tego, czy
 * moduł tłumaczeń jest włączony.
 */
function tl_meta_value_means_noindex($value, string $key = ''): bool {
    $key_l = strtolower($key);

    // Deserializacja stringa
    if (is_string($value)) {
        $decoded_json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_json)) {
            return tl_meta_value_means_noindex($decoded_json, $key);
        }
        $decoded = maybe_unserialize($value);
        if ($decoded !== $value && (is_array($decoded) || is_object($decoded))) {
            return tl_meta_value_means_noindex($decoded, $key);
        }
    }

    // Rekurencja po tablicach/obiektach
    if (is_array($value) || is_object($value)) {
        foreach ((array) $value as $child_key => $child_value) {
            if (tl_meta_value_means_noindex($child_value, (string) $child_key)) {
                return true;
            }
        }
        return false;
    }

    // Bricks: metaRobots => ["noindex", "nofollow"]
    // Wartość jest stringiem "noindex" lub "nofollow" pod kluczem numerycznym,
    // ale rodzic ma klucz "metaRobots" — sprawdzamy czy wartość == "noindex"
    if ($key_l === '' || is_numeric($key)) {
        if (is_string($value) && strtolower(trim($value)) === 'noindex') {
            return true;
        }
    }

    // Klucz zawiera "noindex"
    if (strpos($key_l, 'noindex') !== false) {
        if (is_bool($value)) return $value;
        $value_l = strtolower(trim((string) $value));
        return !in_array($value_l, ['', '0', 'false', 'no', 'off', 'none'], true);
    }

    // Klucz zawiera "robots" i wartość zawiera "noindex"
    if (strpos($key_l, 'robots') !== false && is_string($value)) {
        return stripos($value, 'noindex') !== false;
    }

    // Klucz zawiera "metarobots" lub "meta_robots"
    if (preg_match('/meta.?robots/i', $key_l) && is_string($value)) {
        return stripos($value, 'noindex') !== false;
    }

    // Generyczne klucze SEO z wartością zawierającą "noindex"
    $seoish_key = preg_match('/(bricks|seo|robots|rank_math|yoast|aioseo)/i', $key_l);
    return $seoish_key && is_string($value) && stripos($value, 'noindex') !== false;
}
/**
 * Sanityzacja ustawień mapy strony.
 *
 * BRAK KLUCZA ZOSTAWIA WARTOŚĆ Z BAZY, a nie kasuje jej — i to nie jest
 * kosmetyka. Ten sam wpis w opcjach zapisują DWA ekrany: SEO → Mapa strony
 * (komplet pól) i starsza zakładka Tłumaczenia → Mapa strony
 * (`includes/admin/tl/tab-sitemap.php`, same pola sekcji tłumaczeń). Przy
 * budowaniu tablicy od zera zapis z tego drugiego ekranu zerowałby typy
 * treści, taksonomie i kotwice ustawione na pierwszym — po cichu, bo żaden
 * z tych ekranów drugiego nie pokazuje.
 */
function tl_sanitize_sitemap_settings($input): array {
    $input    = is_array($input) ? $input : [];
    $obecne   = get_option('tl_sitemap_settings', []);
    $obecne   = is_array($obecne) ? $obecne : [];

    /** Flaga 0/1: z wejścia, gdy klucz przyszedł; inaczej stan z bazy. */
    $flaga = static function (string $klucz, int $domyslna) use ($input, $obecne): int {
        if (array_key_exists($klucz, $input))  return !empty($input[$klucz]) ? 1 : 0;
        if (array_key_exists($klucz, $obecne)) return !empty($obecne[$klucz]) ? 1 : 0;
        return $domyslna;
    };

    /** Pojedyncza wartość słownikowa (kod języka): wejście, baza, domyślna. */
    $tekst = static function (string $klucz, string $domyslna) use ($input, $obecne): string {
        $zrodlo = array_key_exists($klucz, $input) ? $input[$klucz] : ($obecne[$klucz] ?? $domyslna);
        $czyste = sanitize_key((string) $zrodlo);
        return $czyste !== '' ? $czyste : $domyslna;
    };

    /** Lista slugów: z wejścia, gdy klucz przyszedł; inaczej stan z bazy. */
    $slugi = static function (string $klucz) use ($input, $obecne): array {
        $zrodlo = array_key_exists($klucz, $input) ? $input[$klucz] : ($obecne[$klucz] ?? []);
        $out    = [];
        foreach ((array) $zrodlo as $slug) {
            $slug = sanitize_key((string) $slug);
            if ($slug !== '') $out[] = $slug;
        }
        return array_values(array_unique($out));
    };

    if (array_key_exists('excluded_ids', $input)) {
        $excluded_ids = [];
        foreach ((array) $input['excluded_ids'] as $post_id) {
            $post_id = absint($post_id);
            if ($post_id) $excluded_ids[] = $post_id;
        }
        $excluded_ids = array_values(array_unique($excluded_ids));
    } else {
        $excluded_ids = array_values(array_unique(array_filter(array_map('absint', (array) ($obecne['excluded_ids'] ?? [])))));
    }

    /* Sekcje kotwic: własna pozycja w indeksie mapy z ręcznie wpisanymi
       kotwicami. Sekcja bez nazwy albo bez ani jednej kotwicy nie ma czego
       wystawić i wypada — inaczej indeks mapy dostawałby adres odpowiadający
       zerem wpisów.

       Slug bierzemy z nazwy (`sanitize_title`), bo to on staje się nazwą pliku
       `wp-sitemap-<slug>-1.xml`. Zderzenia z nazwami rdzenia odsiewa dopiero
       `evk_sitemap_sekcje_kotwic()` — tam, gdzie wiadomo, co jest już
       zarejestrowane. */
    $sekcje_zrodlo = array_key_exists('anchor_sections', $input) ? $input['anchor_sections'] : ($obecne['anchor_sections'] ?? []);
    $anchor_sections = [];
    foreach ((array) $sekcje_zrodlo as $sekcja) {
        if (!is_array($sekcja)) continue;

        $nazwa = sanitize_text_field((string) ($sekcja['name'] ?? ''));
        if ($nazwa === '') continue;

        $kotwice = [];
        foreach ((array) ($sekcja['anchors'] ?? []) as $kotwica) {
            $kotwica = sanitize_title(ltrim((string) $kotwica, '#'));
            if ($kotwica !== '' && !in_array($kotwica, $kotwice, true)) $kotwice[] = $kotwica;
        }
        if (empty($kotwice)) continue;

        $url = trim((string) ($sekcja['url'] ?? ''));
        $anchor_sections[] = [
            'name'    => $nazwa,
            'slug'    => sanitize_title((string) ($sekcja['slug'] ?? '') ?: $nazwa),
            'page'    => absint($sekcja['page'] ?? 0),
            'url'     => $url === '' ? '' : esc_url_raw($url),
            'anchors' => $kotwice,
        ];
    }

    return [
        'enabled'              => $flaga('enabled', 0),
        'include_home'         => $flaga('include_home', 1),
        'include_pages'        => $flaga('include_pages', 1),
        'include_posts'        => $flaga('include_posts', 1),
        'include_polish'       => $flaga('include_polish', 0),
        'only_translated_slugs'=> $flaga('only_translated_slugs', 0),
        'auto_exclude_noindex' => $flaga('auto_exclude_noindex', 1),
		'include_users'        => $flaga('include_users', 0),
        'excluded_ids'         => $excluded_ids,
        'excluded_types'       => $slugi('excluded_types'),
        'noindex_types'        => $slugi('noindex_types'),
        'excluded_taxonomies'  => $slugi('excluded_taxonomies'),
        'noindex_taxonomies'   => $slugi('noindex_taxonomies'),
        'anchor_sections'      => $anchor_sections,
        'hreflang_default'     => $tekst('hreflang_default', 'pl'),
    ];
}

function tl_rebuild_dd_keys_from_rows(array $payload, array $previous_keys = []): array {
    $dd_keys = $previous_keys;
    foreach (($payload['groups'] ?? []) as $group) {
        foreach (($group['rows'] ?? []) as $row) {
            $pl     = tl_sanitize_phrase($row['pl'] ?? '');
            $dd_key = sanitize_key($row['dd_key'] ?? '');
            if ($pl && $dd_key) $dd_keys[$dd_key] = $pl;
        }
    }
    return $dd_keys;
}

/**
 * Wspólna bramka dla wszystkich punktów AJAX Tłumaczeń.
 *
 * DLACZEGO ISTNIEJE. Do 1.126.0 handlery `tl_*` sprawdzały samo
 * `manage_options`, a brakujące uprawnienie dokładał im hook w Role Managerze:
 * na `admin_init` doklejał `manage_options` KAŻDEMU, kto ma
 * `evk_access_translations`, jeśli tylko nazwa akcji zaczynała się od `tl_`.
 * O przyznaniu uprawnienia decydowała więc wartość z żądania — a że
 * `admin-ajax.php` też odpala `admin_init`, wystarczyło zawołać `tl_import`,
 * żeby zapisać snippet z dowolnym PHP i doprowadzić do jego wykonania.
 *
 * Handler pyta teraz sam o to, czego naprawdę potrzebuje — dokładnie tak, jak
 * robi to od początku `evk_nl_ajax_check()` w newsletterze.
 *
 * Nonce jest parametrem, bo do 1.242.0 edytor inline na froncie drukował
 * własny (`tl_inline_nonce`). Edytor usunięto w 1.243.0 i dziś wszyscy
 * wołają domyślny.
 */
function evk_tl_ajax_check(string $nonce = 'tl_ajax_nonce'): void {
    check_ajax_referer($nonce, 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('evk_access_translations')) {
        wp_send_json_error('Brak uprawnien.', 403);
    }
}

/**
 * Ograniczenie modułów eksportu/importu dla bieżącego użytkownika.
 * `null` = bez ograniczeń; pusta tablica = użytkownik nie ma tu czego szukać.
 *
 * Rola od tłumaczeń dostaje wyłącznie moduły `tl_*`. Reszta paczki to m.in.
 * hasło SMTP, hasło obejścia konserwacji, źródła snippetów PHP i cała lista
 * adresów newslettera — czyli rzeczy, których nie wolno ani wynieść, ani
 * wgrać komuś, kto ma zarządzać tłumaczeniami.
 *
 * Administrator dostaje `null`, a NIE przefiltrowanego spisu modułów. To nie
 * jest skrót: `evoke_one_get_io_modules()` jest listą DLA INTERFEJSU i nie
 * zawiera wszystkiego, co import obsługuje (`evk_bgshift`, `evk_forminbox`
 * mają gałęzie w imporcie, a w spisie ich nie ma). Filtrowanie przez ten spis
 * po cichu wyłączyłoby import tych dwóch modułów.
 */
/**
 * HASŁA W PACZCE USTAWIEŃ — tylko na wyraźne życzenie („Dołącz hasła",
 * 1.232.0). Paczka wędruje mailem, leży w Pobranych i na dyskach klientów:
 * hasło SMTP otwiera skrzynkę firmy, a hasło obejścia konserwacji wpuszcza na
 * stronę, która ma być zamknięta. Do 1.231.x jechały w każdym eksporcie.
 *
 * Opcja => pola z hasłem (null = cała opcja jest hasłem).
 */
const EVK_IO_HASLA = ['evk_smtp' => ['password'], 'maintenance_bypass_password' => null];

/** Eksport: bez zaznaczonego „Dołącz hasła" paczka nie ma haseł. */
function evk_io_bez_hasel(array $data, bool $z_haslami): array {
    if ($z_haslami) return $data;
    foreach (EVK_IO_HASLA as $opcja => $pola) {
        if (!array_key_exists($opcja, $data)) continue;
        if ($pola === null) { unset($data[$opcja]); continue; }
        if (is_array($data[$opcja])) {
            foreach ($pola as $pole) unset($data[$opcja][$pole]);
        }
    }
    return $data;
}

/**
 * Import: pole hasła, którego w paczce nie ma, zostaje takie, jakie jest na
 * stronie — paczka bez haseł nie może ich skasować. Opcje-hasła zapisywane
 * w całości (maintenance_bypass_password) import pomija i tak, gdy ich brak.
 */
function evk_io_zachowaj_hasla(string $opcja, $wartosc) {
    $pola = EVK_IO_HASLA[$opcja] ?? null;
    if (!$pola || !is_array($wartosc)) return $wartosc;
    $obecna = (array) get_option($opcja, []);
    foreach ($pola as $pole) {
        if (!array_key_exists($pole, $wartosc) && array_key_exists($pole, $obecna)) $wartosc[$pole] = $obecna[$pole];
    }
    return $wartosc;
}

/**
 * Import newslettera z pliku BEZ subskrybentów: listy i szablony dopisywane
 * po NAZWIE. Istniejąca lista o tej nazwie dostaje konfigurację pól i stan
 * z pliku (numer zostaje — przypisani do niej subskrybenci dalej są jej),
 * nowa nazwa to nowa lista. Szablony tak samo. Subskrybentów, kampanii,
 * kolejki i statystyk strony import nie dotyka.
 */
function evk_io_newsletter_dopisz(array $data): void {
    global $wpdb;
    $zestawy = [
        $wpdb->prefix . 'evk_nl_lists'     => [$data['evk_nl_lists'] ?? [], ['fields_config', 'status']],
        $wpdb->prefix . 'evk_nl_templates' => [$data['evk_nl_templates'] ?? [], ['subject', 'body_html', 'attachments_json']],
    ];
    foreach ($zestawy as $tabela => [$wiersze, $kolumny]) {
        foreach ((array) $wiersze as $wiersz) {
            if (!is_array($wiersz)) continue;
            $nazwa = sanitize_text_field((string) ($wiersz['name'] ?? ''));
            if ($nazwa === '') continue;
            $wartosci = array_intersect_key($wiersz, array_flip($kolumny));
            $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tabela WHERE name = %s ORDER BY id LIMIT 1", $nazwa));
            if ($id) {
                if ($wartosci) $wpdb->update($tabela, $wartosci, ['id' => $id]);
            } else {
                $wpdb->insert($tabela, ['name' => $nazwa] + $wartosci);
            }
        }
    }
}

function evk_io_ograniczenie_modulow(): ?array {
    if (current_user_can('manage_options')) return null;
    if (!current_user_can('evk_access_translations')) return [];
    return array_values(array_filter(
        array_keys(evoke_one_get_io_modules()),
        fn($mod) => strpos($mod, 'tl_') === 0
    ));
}

add_action('wp_ajax_tl_save_translations', function () {
    evk_tl_ajax_check();
    $raw  = isset($_POST['tl_translations']) ? wp_unslash($_POST['tl_translations']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    $sanitized = tl_sanitize_translations_payload($data);
    $dd_keys   = tl_rebuild_dd_keys_from_rows($sanitized, get_option('tl_dd_keys', []));
    update_option('tl_translations', $sanitized);
    update_option('tl_dd_keys', $dd_keys);
    tl_invalidate_cache();
    wp_send_json_success('Zapisano.');
});

add_action('wp_ajax_tl_save_images', function () {
    evk_tl_ajax_check();
    $raw  = isset($_POST['tl_images']) ? wp_unslash($_POST['tl_images']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    update_option('tl_images', tl_sanitize_images_payload($data));
    wp_send_json_success('Zapisano.');
});

add_action('wp_ajax_tl_save_settings', function () {
    evk_tl_ajax_check();
    $raw  = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    if (isset($data['tl_languages']))     update_option('tl_languages',     tl_sanitize_languages_payload($data['tl_languages']));
    if (isset($data['tl_menu_location'])) update_option('tl_menu_location', sanitize_text_field($data['tl_menu_location']));
    if (isset($data['tl_pl_flag']))       update_option('tl_pl_flag',       absint($data['tl_pl_flag']));
    tl_invalidate_cache();
    tl_flush_rewrite_rules();
    wp_send_json_success('Zapisano ustawienia.');
});

add_action('wp_ajax_tl_save_dd_keys', function () {
    evk_tl_ajax_check();
    $raw  = isset($_POST['tl_dd_keys']) ? wp_unslash($_POST['tl_dd_keys']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    $clean = [];
    foreach ($data as $key => $phrase) {
        $key = sanitize_key($key);
        if ($key) $clean[$key] = tl_sanitize_phrase($phrase);
    }
    update_option('tl_dd_keys', $clean);
    tl_invalidate_cache();
    wp_send_json_success('Zapisano klucze DD.');
});

add_action('wp_ajax_tl_save_slugs', function () {
    evk_tl_ajax_check();
    $raw  = isset($_POST['tl_url_slugs']) ? wp_unslash($_POST['tl_url_slugs']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    update_option('tl_url_slugs', tl_sanitize_slugs_payload($data));
    tl_invalidate_cache();
    tl_flush_rewrite_rules();
    wp_send_json_success('Zapisano slugi URL.');
});

add_action('wp_ajax_tl_save_sitemap_settings', function () {
    evk_tl_ajax_check();
    $raw  = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    update_option('tl_sitemap_settings', tl_sanitize_sitemap_settings($data));
    wp_send_json_success('Zapisano ustawienia mapy strony.');
});

add_action('wp_ajax_tl_export', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    $limit = evk_io_ograniczenie_modulow();
    if ($limit !== null && !$limit) wp_die();

    /* Które moduły eksportować.
     *
     * BRAK POLA `modules` ≠ PUSTE POLE. Zakładka Import/Eksport wysyła listę
     * zaznaczonych modułów, więc pusta lista znaczy tam „nic nie zaznaczono"
     * i pusta paczka jest poprawną odpowiedzią. Strona Tłumaczeń pola nie
     * wysyła w ogóle (`tlExportGroup()` w admin/tl/js-admin.php posyła tylko
     * `action`, `nonce` i `group_id`) — i przez to jej „Eksportuj wszystko"
     * zwracało DO 1.126.0 plik z samym nagłówkiem, bez ani jednej frazy:
     * `json_decode('[]')` daje tablicę, więc dawny warunek `!is_array()`
     * nigdy nie łapał tego przypadku. Rozróżnienie idzie po `isset()`. */
    $modules = $limit ?? array_keys(evoke_one_get_io_modules());
    if (isset($_POST['modules'])) {
        $wybrane = json_decode(wp_unslash($_POST['modules']), true);
        if (is_array($wybrane)) $modules = $wybrane;
    }
    if ($limit !== null) $modules = array_values(array_intersect($modules, $limit));

    $data = ['_evoke_one_export' => true, 'exported_at' => current_time('c'), 'version' => EVOKE_ONE_VERSION];

    // Mapa: klucz modułu → funkcja zbierająca dane
    $collectors = [
        /* `group_id` przychodzi ze strony Tłumaczeń („Eksportuj grupę" przy każdej
           grupie fraz) i do 1.132.0 NIE BYŁ NIGDZIE CZYTANY — przycisk oddawał
           komplet tłumaczeń zamiast wybranej grupy, a nikt tego nie zauważył, bo
           plik nazywa się tak samo i otwiera się poprawnie. */
        'tl_translations'     => function () {
            $wszystko = get_option('tl_translations', ['groups' => []]);
            $grupa    = sanitize_key(wp_unslash($_POST['group_id'] ?? ''));
            if ($grupa !== '' && isset($wszystko['groups'][$grupa])) {
                $wszystko['groups'] = [$grupa => $wszystko['groups'][$grupa]];
            }
            return ['tl_translations' => $wszystko];
        },
        'tl_languages'        => fn() => [
            'tl_languages'    => get_option('tl_languages', []),
            'tl_menu_location'=> get_option('tl_menu_location', 'options-general.php'),
            'tl_pl_flag'      => get_option('tl_pl_flag', 0),
        ],
        'tl_images'           => fn() => ['tl_images'           => get_option('tl_images', [])],
        'tl_url_slugs'        => fn() => ['tl_url_slugs'        => get_option('tl_url_slugs', [])],
        'tl_sitemap_settings' => fn() => ['tl_sitemap_settings' => get_option('tl_sitemap_settings', [])],
        'tl_dd_keys'          => fn() => ['tl_dd_keys'          => get_option('tl_dd_keys', [])],
        'evk_darkmode'        => fn() => ['evk_darkmode'        => get_option('evk_darkmode', [])],
        'evk_cursor'          => fn() => ['evk_cursor'          => get_option('evk_cursor', [])],
        'evk_lenis'           => fn() => ['evk_lenis'           => get_option('evk_lenis', [])],
        'evk_animator'        => fn() => ['evk_animator'        => get_option('evk_animator', [])],
        'evk_bgshift'         => fn() => ['evk_bgshift'         => get_option('evk_bgshift', [])],
        'evk_forminbox'       => fn() => ['evk_forminbox' => get_option('evk_forminbox', [])],
        'evk_parallax'        => fn() => [
            'evk_parallax'       => get_option('evk_parallax', []),
            'evk_parallax_value' => get_option('evk_parallax_value', 0.3),
            'evk_parallax_scale' => get_option('evk_parallax_scale', 1.2),
        ],
        'evk_a11y'            => fn() => ['evk_a11y'            => get_option('evk_a11y', [])],
        'evk_schema'          => fn() => ['evk_schema'          => get_option('evk_schema', [])],
        'evk_og'              => fn() => ['evk_og'              => get_option('evk_og', [])],
        'evk_white_label'     => fn() => [
            'evk_white_label'    => get_option('evk_white_label', []),
            'evk_wl_bar_items'   => get_option('evk_wl_bar_items', '[]'),
        ],
        'evk_security'        => fn() => ['evk_security'        => get_option('evk_security', [])],
        'evk_smtp'            => fn() => ['evk_smtp'            => get_option('evk_smtp', [])],
        'evk_maintenance'     => fn() => [
            'maintenance_mode'             => get_option('maintenance_mode', ''),
            'maintenance_page_id'          => get_option('maintenance_page_id', 0),
            'maintenance_excluded_paths'   => get_option('maintenance_excluded_paths', ''),
            'maintenance_bypass_hours'     => get_option('maintenance_bypass_hours', 24),
            'maintenance_bypass_password'  => get_option('maintenance_bypass_password', ''),
        ],
        'evk_redirects'       => fn() => ['evk_301_enabled' => get_option('evk_301_enabled', '')],
        'evk_logs404'         => fn() => [
            'evk_404_enabled'   => get_option('evk_404_enabled', ''),
            'evk_404_max_logs'  => get_option('evk_404_max_logs', 200),
            'evk_404_skip_bots' => get_option('evk_404_skip_bots', 1),
            'evk_404_bot_list'  => get_option('evk_404_bot_list', ''),
        ],
        'evk_dashboard'       => fn() => [
            'evoke_dashboard_active'        => get_option('evoke_dashboard_active', ''),
            'evoke_dashboard_page_id'       => get_option('evoke_dashboard_page_id', 0),
            'evoke_dashboard_mode'          => get_option('evoke_dashboard_mode', 'above'),
            'evoke_dashboard_width'         => get_option('evoke_dashboard_width', '100%'),
            'evoke_dashboard_height'        => get_option('evoke_dashboard_height', '600px'),
            'evoke_dashboard_scrolling'     => get_option('evoke_dashboard_scrolling', 'auto'),
            'evoke_dashboard_fit_content'   => get_option('evoke_dashboard_fit_content', ''),
            'evoke_dashboard_shadow'        => get_option('evoke_dashboard_shadow', '1'),
            'evoke_dashboard_remove_native' => get_option('evoke_dashboard_remove_native', ''),
            'evoke_dashboard_remove_help'   => get_option('evoke_dashboard_remove_help', ''),
        ],
        'evk_snippets'        => function () {
            // Snippety są CPT (evk_code_snippet) + 3 opcje WP
            $posts = get_posts([
                'post_type'      => 'evk_code_snippet',
                'posts_per_page' => -1,
                'post_status'    => 'private',
                'suppress_filters' => true,
            ]);
            /* Z METADANYMI. Do 1.229.6 szły sam slug, tytuł i treść, a wpis bez
               rodzaju odczytuje się jako „szablon" w <head>: snippet PHP po
               imporcie WYPISYWAŁ SWÓJ KOD na każdej stronie (razem z kluczami,
               które w nim siedzą), zamiast go wykonać. Audyt 1.229.6,
               tools/audyt/sondy/snippet-io.php. */
            $snippets = [];
            foreach ($posts as $p) {
                $snippets[] = [
                    'slug'      => $p->post_name,
                    'title'     => $p->post_title,
                    'content'   => $p->post_content,
                    'rodzaj'    => (string) get_post_meta($p->ID, EVK_SNIPPET_META_RODZAJ, true),
                    'miejsce'   => (string) get_post_meta($p->ID, EVK_SNIPPET_META_MIEJSCE, true),
                    'grupa'     => (string) get_post_meta($p->ID, EVK_SNIPPET_META_GRUPA, true),
                    'wlaczony'  => (string) get_post_meta($p->ID, EVK_SNIPPET_META_WLACZ, true),
                    'kolejnosc' => (int) $p->menu_order,
                ];
            }
            return [
                'evk_snippets_posts'            => $snippets,
                'evk_snippets_enabled'          => get_option('evk_snippets_enabled', 0),
                'evk_snippets_advanced_enabled' => get_option('evk_snippets_advanced_enabled', 0),
                'evk_snippets_advanced_content' => get_option('evk_snippets_advanced_content', ''),
            ];
        },
        'evk_other'           => fn() => [
            'evoke_disable_global_comments' => get_option('evoke_disable_global_comments', ''),
            'evoke_require_reg_to_comment'  => get_option('evoke_require_reg_to_comment', ''),
            'evoke_move_bricks_bottom'      => get_option('evoke_move_bricks_bottom', ''),
            'evk_draft_revision_enabled'    => get_option('evk_draft_revision_enabled', ''),
            'favicon_url'                   => get_option('favicon_url', ''),
            'evk_elements'                  => get_option('evk_elements', []),
            'evk_cleanup'                   => get_option('evk_cleanup', []),
        ],
        /* LUDZIE TYLKO NA WYRAŹNE ŻYCZENIE („Dołącz subskrybentów", 1.233.0).
           Do 1.232.x każdy eksport z modułem Newsletter wynosił całą listę
           adresów razem z adresami IP zgód i tokenami wypisu — a paczka
           ustawień wędruje mailem i leży w Pobranych. Bez zaznaczenia plik
           niesie ustawienia, listy i szablony; subskrybenci, kampanie, kolejka
           i statystyki (historia wysyłek do konkretnych osób) — tylko z nim. */
        'evk_newsletter'      => function () {
            global $wpdb;
            $dane = [
                'evk_newsletter'      => get_option('evk_newsletter', []),
                'evk_nl_lists'        => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_lists", ARRAY_A) ?: [],
                'evk_nl_templates'    => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_templates", ARRAY_A) ?: [],
            ];
            if (!empty($_POST['subskrybenci'])) {
                $dane += [
                    'evk_nl_subscribers'  => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_subscribers", ARRAY_A) ?: [],
                    'evk_nl_campaigns'    => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_campaigns", ARRAY_A) ?: [],
                    'evk_nl_queue'        => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_queue", ARRAY_A) ?: [],
                    'evk_nl_logs'         => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_logs", ARRAY_A) ?: [],
                ];
            }
            return $dane;
        },
    ];

    foreach ($modules as $mod) {
        if (isset($collectors[$mod])) {
            $data = array_merge($data, ($collectors[$mod])());
        }
    }
    $data = evk_io_bez_hasel($data, !empty($_POST['hasla']));

    $filename = 'evoke-one-export-' . date('Y-m-d') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
});

add_action('wp_ajax_tl_import', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    $limit = evk_io_ograniczenie_modulow();
    if ($limit !== null && !$limit) wp_send_json_error('Brak uprawnień.');

    $raw  = isset($_POST['json'])      ? wp_unslash($_POST['json'])      : '';
    $decs = isset($_POST['decisions']) ? wp_unslash($_POST['decisions']) : '{}';
    $data = json_decode($raw, true);
    $dec  = json_decode($decs, true) ?: [];

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        wp_send_json_error('Nieprawidłowy JSON.');
    }

    // Helper: zapisz jeśli moduł jest dla tego użytkownika dozwolony
    // ORAZ decyzja = overwrite (lub brak decyzji = zawsze nadpisz).
    //
    // Ograniczenie siedzi TUTAJ, a nie przy poszczególnych gałęziach, bo każdy
    // zapis w tym handlerze przechodzi przez `$should()`. Dopisanie kolejnego
    // modułu nie wymaga więc pamiętania o uprawnieniach — i nie da się o nich
    // zapomnieć.
    $should = function(string $mod) use ($dec, $limit): bool {
        if ($limit !== null && !in_array($mod, $limit, true)) return false;
        return !isset($dec[$mod]) || $dec[$mod] === 'overwrite';
    };

    $imported = 0;
    $snippety_wylaczone = 0;

    // TL
    if ($should('tl_translations') && isset($data['tl_translations'])) {
        // Backward compat: stary format 'ustawienia'
        $trans = $data['tl_translations'] ?: ($data['ustawienia'] ?? null);
        if ($trans) { update_option('tl_translations', $trans); $imported++; }
    }
    if ($should('tl_languages')) {
        if (isset($data['tl_languages']))     { update_option('tl_languages',     $data['tl_languages']); $imported++; }
        if (isset($data['tl_menu_location'])) update_option('tl_menu_location',  sanitize_text_field($data['tl_menu_location']));
        if (isset($data['tl_pl_flag']))       update_option('tl_pl_flag',         absint($data['tl_pl_flag']));
    }
    if ($should('tl_images')           && isset($data['tl_images']))           { update_option('tl_images',           $data['tl_images']); $imported++; }
    if ($should('tl_url_slugs')        && isset($data['tl_url_slugs']))        { update_option('tl_url_slugs',        $data['tl_url_slugs']); $imported++; }
    if ($should('tl_sitemap_settings') && isset($data['tl_sitemap_settings'])) { update_option('tl_sitemap_settings', tl_sanitize_sitemap_settings($data['tl_sitemap_settings'])); $imported++; }
    if ($should('tl_dd_keys')          && isset($data['tl_dd_keys']))          { update_option('tl_dd_keys',          $data['tl_dd_keys']); $imported++; }

    // Frontend modules
    foreach (['evk_darkmode','evk_cursor','evk_lenis','evk_animator','evk_bgshift','evk_a11y','evk_schema','evk_og','evk_security','evk_smtp'] as $opt) {
        $mod = str_replace(['evk_','evoke_one_'], ['evk_','evk_'], $opt);
        if ($should($mod) && isset($data[$opt])) { update_option($opt, evk_io_zachowaj_hasla($opt, $data[$opt])); $imported++; }
    }

    // Parallax (dwa klucze → jeden moduł)
    if ($should('evk_parallax')) {
        if (isset($data['evk_parallax']))       { update_option('evk_parallax',       $data['evk_parallax']); $imported++; }
        if (isset($data['evk_parallax_value'])) update_option('evk_parallax_value', $data['evk_parallax_value']);
        if (isset($data['evk_parallax_scale'])) update_option('evk_parallax_scale', $data['evk_parallax_scale']);
    }

    // White Label
    if ($should('evk_white_label')) {
        if (isset($data['evk_white_label']))  { update_option('evk_white_label',  $data['evk_white_label']); $imported++; }
        if (isset($data['evk_wl_bar_items'])) update_option('evk_wl_bar_items', $data['evk_wl_bar_items']);
    }

    // Maintenance
    if ($should('evk_maintenance')) {
        $scalar_maintenance = ['maintenance_mode','maintenance_page_id','maintenance_excluded_paths','maintenance_bypass_hours','maintenance_bypass_password'];
        foreach ($scalar_maintenance as $k) {
            if (isset($data[$k])) update_option($k, $data[$k]);
        }
        $imported++;
    }

    // Redirects, 404, Dashboard, Snippets, Other
    if ($should('evk_redirects')   && isset($data['evk_301_enabled']))        { update_option('evk_301_enabled', $data['evk_301_enabled']); $imported++; }
    if ($should('evk_logs404')) {
        foreach (['evk_404_enabled','evk_404_max_logs','evk_404_skip_bots','evk_404_bot_list'] as $k) {
            if (isset($data[$k])) update_option($k, $data[$k]);
        }
        $imported++;
    }
    if ($should('evk_dashboard')) {
        $dash_keys = ['evoke_dashboard_active','evoke_dashboard_page_id','evoke_dashboard_mode','evoke_dashboard_width','evoke_dashboard_height','evoke_dashboard_scrolling','evoke_dashboard_fit_content','evoke_dashboard_shadow','evoke_dashboard_remove_native','evoke_dashboard_remove_help'];
        foreach ($dash_keys as $k) { if (isset($data[$k])) update_option($k, $data[$k]); }
        $imported++;
    }
    if ($should('evk_snippets')) {
        if (isset($data['evk_snippets_posts']) && is_array($data['evk_snippets_posts']) && function_exists('evk_snippet_save')) {
            foreach ($data['evk_snippets_posts'] as $s) {
                if (!is_array($s)) continue;
                $slug = sanitize_key($s['slug'] ?? '');
                if (!$slug) continue;

                /* Kod idzie SUROWY — ukośniki dokłada evk_snippet_save(). Do
                   1.229.6 slashował tutaj import, a „zaawansowany" niżej szedł
                   przez update_option(), która ukośników nie zdejmuje — więc
                   jego kod po imporcie miał je PODWOJONE. */
                $istnial = evk_snippet_get_id($slug) > 0;
                evk_snippet_save($slug, sanitize_text_field($s['title'] ?? ''), (string) ($s['content'] ?? ''));
                $id = evk_snippet_get_id($slug);
                if (!$id) continue;

                $rodzaj  = (string) ($s['rodzaj'] ?? '');
                $miejsce = (string) ($s['miejsce'] ?? '');
                if (isset(evk_snippet_rodzaje()[$rodzaj]))  update_post_meta($id, EVK_SNIPPET_META_RODZAJ, $rodzaj);
                if (isset(evk_snippet_miejsca()[$miejsce])) update_post_meta($id, EVK_SNIPPET_META_MIEJSCE, $miejsce);
                if (isset($s['grupa']))     update_post_meta($id, EVK_SNIPPET_META_GRUPA, sanitize_text_field((string) $s['grupa']));
                if (isset($s['kolejnosc'])) wp_update_post(['ID' => $id, 'menu_order' => (int) $s['kolejnosc']]);

                /* NOWY WPIS WCHODZI WYŁĄCZONY. Kod z pliku to kod z innej strony
                   (albo ze starego eksportu bez rodzaju) — ma go obejrzeć
                   człowiek, zanim się wykona. Istniejący wpis zachowuje swój
                   włącznik: import nie przełącza tego, co już tu pracuje. */
                if (!$istnial) {
                    update_post_meta($id, EVK_SNIPPET_META_WLACZ, 0);
                    $snippety_wylaczone++;
                }
            }
        }
        if (isset($data['evk_snippets_enabled']))          update_option('evk_snippets_enabled',          absint($data['evk_snippets_enabled']));
        if (isset($data['evk_snippets_advanced_enabled'])) update_option('evk_snippets_advanced_enabled', absint($data['evk_snippets_advanced_enabled']));
        if (isset($data['evk_snippets_advanced_content'])) {
            $adv = (string) $data['evk_snippets_advanced_content'];
            function_exists('evk_snippets_advanced_save') ? evk_snippets_advanced_save($adv) : update_option('evk_snippets_advanced_content', $adv, false);
        }
        $imported++;
    }
    if ($should('evk_other')) {
        foreach (['evoke_disable_global_comments','evoke_require_reg_to_comment','evoke_move_bricks_bottom','evk_draft_revision_enabled','favicon_url','evk_elements','evk_cleanup'] as $k) {
            if (isset($data[$k])) update_option($k, $data[$k]);
        }
        $imported++;
    }

    // Skrzynka wiadomości — pełne ustawienia (w tym header_layout, sidebar_layout, subject_field)
    if ($should('evk_forminbox') && isset($data['evk_forminbox']) && is_array($data['evk_forminbox'])) {
        update_option('evk_forminbox', $data['evk_forminbox']);
        $imported++;
    }

    // Newsletter — ustawienia + tabele DB
    if ($should('evk_newsletter') && isset($data['evk_newsletter'])) {
        global $wpdb;

        update_option('evk_newsletter', $data['evk_newsletter']);
        // Strona, na której moduł nigdy nie był włączony, nie ma jeszcze tabel.
        if (function_exists('evk_nl_create_tables')) evk_nl_create_tables();

        /* Plik BEZ subskrybentów (eksport bez „Dołącz subskrybentów") tylko
           dopisuje listy i szablony. Czyszczenie tabel niżej zostawiłoby
           subskrybentów strony przypisanych do numerów list, które po imporcie
           znaczą co innego albo nie istnieją. */
        if (!array_key_exists('evk_nl_subscribers', $data)) {
            evk_io_newsletter_dopisz($data);
        } else {
            // Plik Z subskrybentami to komplet — zastępuje newsletter na stronie.
            $tables = [
                'evk_nl_lists'       => ['id','name','fields_config','status','created_at'],
                'evk_nl_subscribers' => ['id','list_id','email','fields_json','status','token','subscribed_at','unsubscribed_at'],
                'evk_nl_templates'   => ['id','name','subject','body_html','attachments_json','created_at','updated_at'],
                'evk_nl_campaigns'   => ['id','name','template_id','lists_json','status','scheduled_at','batch_size','batch_interval','tracking_enabled','created_at'],
                'evk_nl_queue'       => ['id','campaign_id','subscriber_id','status','attempts','sent_at','opened_at','error_message'],
                'evk_nl_logs'        => ['id','campaign_id','event','subscriber_id','data_json','created_at'],
            ];

            foreach ($tables as $table_key => $columns) {
                $table = $wpdb->prefix . $table_key;
                $rows  = $data[$table_key] ?? [];
                if (empty($rows) || !is_array($rows)) continue;

                // Wyczyść tabelę przed importem
                $wpdb->query("TRUNCATE TABLE $table");

                foreach ($rows as $row) {
                    // Filtruj tylko znane kolumny
                    $clean = array_intersect_key($row, array_flip($columns));
                    if (!empty($clean)) {
                        $wpdb->insert($table, $clean);
                    }
                }
            }
        }

        $imported++;
    }

    // Przebuduj cache TL
    if (function_exists('tl_invalidate_cache'))    tl_invalidate_cache();
    if (function_exists('tl_flush_rewrite_rules')) tl_flush_rewrite_rules();

    /* Bez „odśwież stronę" — dokleja to panel (admin.js). Do 1.229.6 zdanie
       pojawiało się dwa razy. Liczba po dwukropku, żeby nie odmieniać. */
    $komunikat = 'Zaimportowano moduły: ' . $imported . '.';
    if ($snippety_wylaczone) {
        $komunikat .= ' Nowe snippety (' . $snippety_wylaczone . ') są WYŁĄCZONE — przejrzyj kod i włącz je ręcznie.';
    }
    wp_send_json_success($komunikat);
});

// =========================================================================
// HELPER — zachowanie pól przełączników przy zapisie formularza
// =========================================================================

/**
 * Zwraca wartość pola przełącznika (np. 'enabled') z zachowaniem stanu.
 *
 * Formularze options.php NIE zawierają pól zarządzanych przez AJAX toggle
 * (evk_ajax_toggle) — WordPress przy zapisie grupy ustawień aktualizuje
 * wszystkie zarejestrowane opcje, więc brakujący klucz zerował przełącznik.
 * Zasada: klucz obecny w $input → sanityzuj; klucz nieobecny → zachowaj
 * aktualnie zapisaną wartość opcji. Zapis przez AJAX toggle zawsze przekazuje
 * pełną tablicę z kluczem, więc wyłączanie działa poprawnie.
 */
function evk_preserve_toggle($input, string $option, string $field = 'enabled', int $default = 0): int {
    if (is_array($input) && array_key_exists($field, $input)) {
        return !empty($input[$field]) ? 1 : 0;
    }
    $current = get_option($option, null);
    if (is_array($current) && array_key_exists($field, $current)) {
        return !empty($current[$field]) ? 1 : 0;
    }
    // Opcja nigdy nie zapisana — użyj domyślnej wartości modułu
    return $default;
}

// =========================================================================
// AJAX TOGGLE — uniwersalny handler włączników/wyłączników
// =========================================================================

/**
 * BIAŁA LISTA PRZEŁĄCZNIKÓW — opcja => dozwolone pola.
 *
 * Wyprowadzona z uchwytu do osobnej funkcji, żeby dało się ją PORÓWNAĆ
 * z panelem. Przełącznik, którego tu nie ma, rysuje się poprawnie i odbija
 * dopiero przy kliknięciu, komunikatem `not_allowed` — a to widać wyłącznie
 * z użycia. Zdarzyło się już dwa razy: przy Offcanvas Menu (1.57.0)
 * i przy Sierotkach (1.147.0). Teraz pilnuje tego test, który wyciąga
 * `data-option`/`data-field` ze WSZYSTKICH ekranów panelu i sprawdza,
 * czy każdy z nich ma tu wpis.
 */
function evk_toggle_allowlist(): array {
    return [
        'evk_darkmode'              => ['enabled'],
        'evk_cursor'                => ['enabled'],
        'evk_lenis'                 => ['enabled'],
        'evk_a11y'                  => ['enabled'],
        'evk_smtp'                  => ['enabled'],
        'evk_parallax'              => ['enabled'],
        'evk_schema'                => ['enabled'],
        'evk_og'                    => ['enabled'],
        'evk_fonts'                 => ['enabled'],
        'evk_theme_color'           => ['enabled'],
        'evk_sierotki'              => ['enabled'],
        'evk_rewizje'               => ['limit_on'],
        'evk_animator'              => ['enabled'],
        'evk_bgshift'               => ['enabled'],
        'evk_white_label'           => ['enabled'],
        'evk_newsletter'            => ['enabled'],
        'evk_security'              => ['limit_login_enabled', 'hide_wp_version', 'rest_block_all', 'disable_bundled_themes'],
        // Scalar (flat) options
        'maintenance_mode'          => ['_scalar'],
        'evk_draft_revision_enabled'=> ['_scalar'],
        'evk_301_enabled'           => ['_scalar'],
        'evk_404_enabled'           => ['_scalar'],
        'evk_404_skip_bots'         => ['_scalar'],
        'evoke_disable_global_comments' => ['_scalar'],
        'evoke_require_reg_to_comment'  => ['_scalar'],
        'evoke_move_bricks_bottom'      => ['_scalar'],
        'evoke_dashboard_active'        => ['_scalar'],
        'evoke_dashboard_remove_native' => ['_scalar'],
        'evoke_dashboard_remove_help'   => ['_scalar'],
        'evoke_dashboard_fit_content'   => ['_scalar'],
        'evoke_dashboard_shadow'        => ['_scalar'],
        'evk_tl_module_enabled'         => ['_scalar'],
        'evk_forminbox'                  => ['enabled'],
        'evk_backup'                     => ['enabled'],
        'evk_snippets_enabled'          => ['_scalar'],
        'evk_snippets_advanced_enabled' => ['_scalar'],
        /*
         * Lista elementów PROSTO Z REJESTRU, nie przepisana ręcznie.
         *
         * Przepisana była do 1.57.0 i rozjechała się przy pierwszym nowym
         * elemencie: Offcanvas Menu miał wpis w rejestrze i włącznik w panelu,
         * a przełącznik odbijał się tutaj z `not_allowed`. Panel rysował się
         * poprawnie, więc widać to było dopiero z użycia.
         *
         * `function_exists` na wypadek, gdyby ten plik załadował się przed
         * loaderem elementów — wtedy lista jest pusta i przełącznik nie działa,
         * ale nic się nie wywraca.
         */
        'evk_elements'                  => function_exists('evk_elements_registry')
            ? array_keys(evk_elements_registry()) : [],
        'evk_cleanup'                   => ['disable_xmlrpc', 'remove_rss'],
    ];
}

add_action('wp_ajax_evk_ajax_toggle', function () {
    check_ajax_referer('evk-toggle-nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

    $option = sanitize_key($_POST['option'] ?? '');
    $field  = sanitize_key($_POST['field']  ?? '');
    $value  = absint($_POST['value'] ?? 0) ? 1 : 0;

    $allowed = evk_toggle_allowlist();

    if (!isset($allowed[$option]) || !in_array($field, $allowed[$option])) {
        wp_send_json_error('not_allowed: ' . $option . '/' . $field);
    }

    if ($field === '_scalar') {
        update_option($option, $value ? '1' : '');
    } else {
        $current = (array) get_option($option, []);
        $current[$field] = $value;
        update_option($option, $current);
    }

    wp_send_json_success(['option' => $option, 'field' => $field, 'value' => $value]);
});

// =========================================================================
// AJAX: zapisz pojedynczą opcję (prosty klucz → wartość skalarna)
// Używany m.in. przez toggle modułu tłumaczeń.
// =========================================================================
add_action('wp_ajax_evk_save_option', function () {
    check_ajax_referer('evk_save_option', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    $allowed = [
        'evk_tl_module_enabled',
    ];

    $option = sanitize_key(wp_unslash($_POST['option'] ?? ''));
    if (!in_array($option, $allowed, true)) {
        wp_send_json_error('not_allowed: ' . $option, 403);
    }

    $value = !empty($_POST['value']) ? 1 : 0;
    update_option($option, $value);
    wp_send_json_success(['option' => $option, 'value' => $value]);
});

// =========================================================================
// AJAX — kolejność wierszy biblioteki animacji
// =========================================================================

/**
 * Zapisuje nową kolejność wierszy po przeciągnięciu w panelu Animatora.
 *
 * Kolejność jest WYŁĄCZNIE porządkowa: silnik czyta bibliotekę po slugu,
 * a sekwencją startową steruje pole „Kolejność" w wierszu. To udogodnienie
 * dla oka, nie zmiana zachowania strony.
 *
 * Przyjmujemy same slugi, nie całe wiersze — nie ma powodu przepychać przez
 * sieć konfiguracji, której i tak nie zmieniamy, ani ryzykować, że częściowo
 * wypełniony formularz nadpisze zapisane wartości.
 */
add_action('wp_ajax_evk_anim_reorder', function () {
    check_ajax_referer('evk_anim_reorder', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    $order = array_map('sanitize_title', (array) wp_unslash($_POST['order'] ?? []));

    $opt = get_option('evk_animator', []);
    if (!is_array($opt) || empty($opt['animations']) || !is_array($opt['animations'])) {
        wp_send_json_error('brak biblioteki', 400);
    }

    // Wiersze pod slugiem — po nim, a nie po pozycji, dopasowujemy kolejność.
    $by_slug = [];
    foreach ($opt['animations'] as $row) {
        if (is_array($row) && !empty($row['slug'])) $by_slug[$row['slug']] = $row;
    }

    // W panelu mogą stać wiersze DODANE PRZYCISKIEM I JESZCZE NIEZAPISANE —
    // ich slugów nie znamy, więc po prostu je pomijamy. A wiersze zapisane,
    // których nie było na liście (wyścig dwóch kart), lądują na końcu zamiast
    // wypaść. Dzięki obu regułom przeciągnięcie nie może niczego zgubić.
    $sorted = [];
    foreach ($order as $slug) {
        if (isset($by_slug[$slug])) {
            $sorted[] = $by_slug[$slug];
            unset($by_slug[$slug]);
        }
    }
    foreach ($by_slug as $row) $sorted[] = $row;

    $opt['animations'] = $sorted;
    update_option('evk_animator', $opt);

    wp_send_json_success([
        'order'   => wp_list_pluck($sorted, 'slug'),
        'ignored' => count($order) - count($sorted) + count($by_slug),
    ]);
});

/**
 * Zapis całej biblioteki animacji bez przeładowania strony.
 *
 * Sanityzację REUŻYWAMY, nie przepisujemy — leci ta sama metoda, którą wywołuje
 * options.php przez register_setting(). Drugie miejsce z regułami czyszczenia
 * rozjechałoby się z pierwszym i różnica wyszłaby dopiero na żywej stronie.
 *
 * Klient wysyła zwykłe form.serialize(), więc PHP samo rozkłada nazwy
 * evk_animator[animations][0][slug] w zagnieżdżoną tablicę. Kolejność pól
 * w ciele żądania to kolejność wierszy w panelu, a sanityzacja iteruje po niej
 * foreachem — przestawienie wierszy zapisuje się więc samo, bez osobnego kroku.
 */
add_action('wp_ajax_evk_anim_save', function () {
    check_ajax_referer('evk_anim_save', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    if (!class_exists('EVK_Animator')) wp_send_json_error('brak modułu', 400);

    $input = wp_unslash($_POST['evk_animator'] ?? null);
    if (!is_array($input)) wp_send_json_error('brak danych', 400);

    // Przełącznik „włączony" nie jest częścią formularza — steruje nim osobny
    // AJAX toggle. Wewnątrz sanityzacji chroni go evk_preserve_toggle(), więc
    // nie wolno go tu podstawiać ani zerować.
    $clean = EVK_Animator::get_instance()->sanitize_settings($input);
    update_option('evk_animator', $clean);

    wp_send_json_success([
        'count' => count($clean['animations']),
        'slugs' => wp_list_pluck($clean['animations'], 'slug'),
    ]);
});

/**
 * Zapis DOWOLNEJ grupy ustawień przez AJAX.
 *
 * Zakładek z formularzem jest osiemnaście i każda z nich to ten sam ruch:
 * weź pola, przepuść przez sanityzację modułu, zapisz opcję. Osobny endpoint
 * na zakładkę oznaczałby osiemnaście miejsc, w których można się pomylić —
 * i osiemnaście, o których trzeba pamiętać przy dodaniu dziewiętnastej.
 *
 * Odwzorowuje pętlę z `wp-admin/options.php`, bo to ONA jest drogą zapasową:
 * gdy AJAX padnie, formularz leci normalnie i musi zapisać dokładnie to samo.
 * Trzy rzeczy z tamtej pętli, które łatwo pominąć, a które mają znaczenie:
 *
 * * **Białą listę bierzemy z rejestru WordPressa** (`$new_allowed_options`,
 *   przed 5.5 `$new_whitelist_options`), a nie z własnego spisu grup. Własny
 *   spis rozjechałby się z `register_setting()` przy pierwszym nowym module.
 * * **`update_option()` wołamy także wtedy, gdy pola NIE MA w żądaniu.**
 *   Odznaczony checkbox nie przychodzi wcale; gdyby brak oznaczał „pomiń",
 *   nie dałoby się niczego odznaczyć. Sanityzatory modułów są na to gotowe
 *   (stąd `evk_preserve_toggle`).
 * * **Sanityzacja odpala się sama** — `update_option()` woła `sanitize_option()`,
 *   a `register_setting()` podpina tam `sanitize_callback`. Wywołanie jej tutaj
 *   z ręki zrobiłoby ją dwa razy.
 *
 * Nonce jest ten sam, który drukuje `settings_fields( $group )`.
 */
add_action('wp_ajax_evk_settings_save', function () {
    $page = isset($_POST['option_page']) ? sanitize_key(wp_unslash($_POST['option_page'])) : '';
    if ($page === '') wp_send_json_error('brak grupy ustawień', 400);

    check_ajax_referer($page . '-options', '_wpnonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    $allowed = $GLOBALS['new_allowed_options'] ?? $GLOBALS['new_whitelist_options'] ?? [];
    if (empty($allowed[$page])) wp_send_json_error('nieznana grupa ustawień', 400);

    $saved = [];
    foreach ((array) $allowed[$page] as $option) {
        $option = trim($option);
        $value  = null;
        if (isset($_POST[$option])) {
            $value = $_POST[$option];
            if (!is_array($value)) $value = trim($value);
            $value = wp_unslash($value);
        }
        update_option($option, $value);
        $saved[] = $option;
    }

    wp_send_json_success(['page' => $page, 'options' => $saved]);
});
