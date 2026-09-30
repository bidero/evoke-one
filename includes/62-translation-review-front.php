<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — sprawdzanie tłumaczeń wprost na stronie (1.261.0).
 *
 * ZGŁOSZONE (29.09): „Czy jest możliwość edycji tekstów bezpośrednio na
 * stronie do sprawdzenia? Bardzo by to ułatwiło systemowe tłumaczenie
 * hurtowe." Lista „Do sprawdzenia" w panelu nie pokazuje kontekstu: czy
 * tekst mieści się w układzie, czy pasuje do sąsiednich.
 *
 * DECYZJE ZGŁASZAJĄCEGO: okienko przy klikniętym elemencie (nie pisanie
 * w tekście); obrys „Do sprawdzenia" i „Brak tłumaczenia", a klikać da się
 * każdy tekst; zakres — elementy Bricksa (treść, nagłówek, stopka).
 *
 * CZYM RÓŻNI SIĘ OD EDYTORA 🌐 (usuniętego w 1.243.0): tamten rozpoznawał
 * tekst po frazach słownika na gotowym HTML-u, więc nie widział pól
 * w elementach. Ten zapisuje przy renderze identyfikatory elementów
 * (`bricks/element/settings`) i sam ustala, do którego wpisu należy element:
 *   1. szablon treści albo sama strona;
 *   2. szablon nagłówka i stopki (`\Bricks\Database::$active_templates`,
 *      jak 85-seo.php);
 *   3. jednoznaczne trafienie wśród pozostałych wpisów z danymi Bricksa
 *      (szablon sekcji, popup). Skopiowana strona ma te same identyfikatory
 *      elementów — przy dwóch trafieniach element jest tylko do podglądu.
 * Klucze pól — te same co w stanie „Do sprawdzenia" (52).
 *
 * WEJŚCIE: `?evk_tl_sprawdz=1` na adresie w języku, dla zalogowanych
 * z dostępem do Tłumaczeń. Przycisk na pasku admina i odnośnik „Na stronie"
 * przy liście „Do sprawdzenia". Goście, builder, kanwa, podgląd szablonu
 * i polska wersja — bez zmian.
 *
 * ZAPIS drogą hurtu AI (`evk_tl_el_zapisz_pola()` w 53): nowe pole do wykazu
 * dopisanych, zapis bez haka. Po zapisie teksty elementu są sprawdzone
 * (źródło = skrót bieżącego oryginału). Poprawiać może ten, kto może edytować
 * wpis — także szablon nagłówka czy stopki. Bez `unfiltered_html` wartość
 * idzie przez wp_kses_post().
 *
 * PONOWNE TŁUMACZENIE (1.262.0): przy tekście przycisk „Przetłumacz
 * ponownie” (dostawca i model do wyboru, bez zapisu — wynik trafia do pola),
 * znaczek modelu przy tłumaczeniu AI i „Poprzednio” z przyciskiem
 * „Przywróć”. Zapis zmienionego pola odkłada stary tekst do `poprz`.
 *
 * CZEGO TU NIE SPRAWDZIMY (Bricksa na tej maszynie nie ma — CLAUDE.md):
 * identyfikatory elementów w HTML-u (`brxe-…`, własne CSS ID, pętle),
 * `$active_templates` nagłówka i stopki, obiekt elementu w filtrze ustawień
 * (`->id`, `->name`). Test stawia atrapę renderu na prawdziwym filtrze.
 */

const EVK_TL_SPRAWDZ_PARAM = 'evk_tl_sprawdz';

/** Dostęp do Tłumaczeń — jak panel i ich AJAX (evk_tl_ajax_check()). */
function evk_tl_sprawdz_dostep(): bool {
    return current_user_can('manage_options') || current_user_can('evk_access_translations');
}

/**
 * Tryb sprawdzania w tym żądaniu. Liczony raz, po `wp_loaded`: język strony
 * ustala się na `init` (10-language-system.php), a `did_action('init')` jest
 * prawdziwe już w trakcie tego haka.
 */
function evk_tl_sprawdz_aktywny(): bool {
    static $wynik = null;
    if ($wynik !== null) return $wynik;
    if (!did_action('wp_loaded')) return false;
    return $wynik = isset($_GET[EVK_TL_SPRAWDZ_PARAM]) && !is_admin() && !wp_doing_ajax()
        && !(defined('REST_REQUEST') && REST_REQUEST) && !evk_w_builderze() && !tl_is_bricks_preview()
        && get_current_lang() !== 'pl' && is_user_logged_in() && evk_tl_sprawdz_dostep();
}

// =========================================================================
// ELEMENTY STRONY I ICH WPISY
// =========================================================================

/* Każdy wyrenderowany element: identyfikator, nazwa, własne CSS ID. Teksty
   bierze się potem z zapisanych danych wpisu, nie stąd — podmiana tekstu na
   język strony (51) nie ma więc znaczenia dla kolejności. */
add_filter('bricks/element/settings', function ($ustawienia, $element) {
    if (!is_array($ustawienia) || !is_object($element) || !evk_tl_sprawdz_aktywny()) return $ustawienia;
    $id = isset($element->id) && is_scalar($element->id) ? (string) $element->id : '';
    if ($id !== '' && !isset($GLOBALS['evk_tl_sprawdz_elementy'][$id])) {
        $GLOBALS['evk_tl_sprawdz_elementy'][$id] = [
            'nazwa' => isset($element->name) && is_scalar($element->name) ? (string) $element->name : '',
            'css'   => is_scalar($ustawienia['_cssId'] ?? null) ? trim((string) $ustawienia['_cssId']) : '',
        ];
    }
    return $ustawienia;
}, 10, 2);

/** Elementy jednej części wpisu: id → element. */
function evk_tl_sprawdz_elementy_wpisu(int $post_id, string $meta_key): array {
    $dane = get_post_meta($post_id, $meta_key, true);
    $out = [];
    foreach (is_array($dane) ? $dane : [] as $el) {
        if (is_array($el) && isset($el['id']) && is_scalar($el['id']) && (string) $el['id'] !== '') $out[(string) $el['id']] = $el;
    }
    return $out;
}

/**
 * Wpisy, do których najpewniej należą elementy tej strony: szablon treści
 * (albo sama strona), szablon nagłówka, szablon stopki.
 *
 * @return list<array{0:int,1:string}>
 */
function evk_tl_sprawdz_kandydaci(): array {
    [$tresc, $naglowek, $stopka] = evk_tl_el_klucze_meta();
    $szablony = class_exists('\Bricks\Database') && isset(\Bricks\Database::$active_templates) && is_array(\Bricks\Database::$active_templates)
        ? \Bricks\Database::$active_templates : [];
    $out = [];
    if (!empty($szablony['content'])) $out[] = [(int) $szablony['content'], $tresc];
    if (is_singular()) $out[] = [(int) get_queried_object_id(), $tresc];
    if (!empty($szablony['header'])) $out[] = [(int) $szablony['header'], $naglowek];
    if (!empty($szablony['footer'])) $out[] = [(int) $szablony['footer'], $stopka];
    return $out;
}

/**
 * Wpis każdego elementu: najpierw kandydaci po kolei, potem pozostałe wpisy
 * z danymi Bricksa — po identyfikatorze w zapisanej tablicy (jedno zapytanie
 * LIKE zamiast rozpakowania wszystkich stron). Dwa trafienia poza kandydatami
 * (skopiowana strona) → `wiele`.
 *
 * @param list<string> $ids
 * @return array<string,array<string,mixed>> id → {post, meta, el} albo {wiele: n}
 */
function evk_tl_sprawdz_wlasciciele(array $ids): array {
    $out = [];
    $szukane = array_flip($ids);
    $zajrzane = [];
    foreach (evk_tl_sprawdz_kandydaci() as [$post_id, $meta_key]) {
        if ($post_id <= 0 || isset($zajrzane[$post_id . '|' . $meta_key])) continue;
        $zajrzane[$post_id . '|' . $meta_key] = true;
        foreach (evk_tl_sprawdz_elementy_wpisu($post_id, $meta_key) as $id => $el) {
            if (isset($szukane[$id]) && !isset($out[$id])) $out[$id] = ['post' => $post_id, 'meta' => $meta_key, 'el' => $el];
        }
    }
    $reszta = array_slice(array_keys(array_diff_key($szukane, $out)), 0, 60);
    if (!$reszta) return $out;

    global $wpdb;
    $klucze = evk_tl_el_klucze_meta();
    $lubi = [];
    $arg = [];
    foreach ($reszta as $id) {
        $lubi[] = 'pm.meta_value LIKE %s';
        $arg[] = '%' . $wpdb->esc_like('s:2:"id";s:' . strlen((string) $id) . ':"' . $id . '";') . '%';
    }
    $sql = "SELECT pm.post_id, pm.meta_key FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN (%s, %s, %s) AND p.post_type <> 'revision' AND p.post_status NOT IN ('trash', 'auto-draft')
            AND (" . implode(' OR ', $lubi) . ')';
    $wiersze = (array) $wpdb->get_results($wpdb->prepare($sql, ...array_merge($klucze, $arg)), ARRAY_A);   // phpcs:ignore
    $trafienia = [];
    foreach ($wiersze as $w) {
        $post_id = (int) ($w['post_id'] ?? 0);
        $meta_key = (string) ($w['meta_key'] ?? '');
        if (isset($zajrzane[$post_id . '|' . $meta_key])) continue;
        $zajrzane[$post_id . '|' . $meta_key] = true;
        foreach (evk_tl_sprawdz_elementy_wpisu($post_id, $meta_key) as $id => $el) {
            if (in_array((string) $id, $reszta, true)) $trafienia[$id][] = ['post' => $post_id, 'meta' => $meta_key, 'el' => $el];
        }
    }
    foreach ($trafienia as $id => $t) $out[(string) $id] = count($t) === 1 ? $t[0] : ['wiele' => count($t)];
    return $out;
}

// =========================================================================
// POLA ELEMENTU I ICH STAN
// =========================================================================

/** Polska nazwa typu elementu w okienku; nieznany typ — nazwa Bricksa. */
function evk_tl_sprawdz_nazwa(string $nazwa): string {
    $n = ['heading' => 'Nagłówek', 'text' => 'Tekst', 'text-basic' => 'Tekst prosty', 'text-link' => 'Odnośnik tekstowy',
        'button' => 'Przycisk', 'icon-box' => 'Pole z ikoną', 'list' => 'Lista', 'accordion' => 'Akordeon',
        'accordion-nested' => 'Akordeon', 'tabs' => 'Zakładki', 'tabs-nested' => 'Zakładki', 'form' => 'Formularz',
        'testimonials' => 'Opinie', 'pricing-tables' => 'Cennik', 'counter' => 'Licznik', 'alert' => 'Komunikat',
        'post-title' => 'Tytuł wpisu', 'slider' => 'Slider', 'slider-nested' => 'Slider', 'progress-bar' => 'Pasek postępu',
        'team-members' => 'Zespół', 'image' => 'Obraz', 'nav-menu' => 'Menu'];
    return $n[$nazwa] ?? $nazwa;
}

/**
 * Elementy z własnym skryptem Bricksa (rozwijanie, przewijanie, liczenie):
 * podmieniony węzeł straciłby obsługę, więc po zapisie strona przeładowuje się.
 */
function evk_tl_sprawdz_przeladuj(string $nazwa): bool {
    return in_array($nazwa, ['accordion', 'accordion-nested', 'tabs', 'tabs-nested', 'slider', 'slider-nested', 'carousel',
        'testimonials', 'countdown', 'counter', 'form', 'nav-menu', 'nav-nested', 'offcanvas', 'toggle', 'progress-bar',
        'pie-chart', 'team-members', 'pricing-tables'], true);
}

/** Czy tekst ma co tłumaczyć — litery poza znacznikami, tagami `{…}` i shortcodami (jak hurt AI). */
function evk_tl_sprawdz_tekst(string $pl): bool {
    if (function_exists('evk_tl_ai_do_tlumaczenia')) return evk_tl_ai_do_tlumaczenia($pl);
    $t = (string) preg_replace('/\{[^{}]*\}|\[[^\[\]]*\]/u', ' ', html_entity_decode(wp_strip_all_tags($pl), ENT_QUOTES, 'UTF-8'));
    return (bool) preg_match('/\p{L}/u', $t);
}

/**
 * Pola tekstowe elementu (z mapy pól, jak hurt AI) z tłumaczeniem i stanem:
 *   ai      — tłumaczenie AI, niesprawdzone;
 *   zmiana  — polski tekst zmienił się po przetłumaczeniu;
 *   ok      — przetłumaczone i aktualne;
 *   slownik — pole puste, cały tekst tłumaczy słownik;
 *   czesc   — pole puste, słownik tłumaczy tylko część;
 *   brak    — na stronie idzie po polsku.
 *
 * @param array<string,mixed>                $el     Element z danych Bricksa.
 * @param array<string,array<string,mixed>>  $stan   Stan części wpisu (52) — klucz miejsca → {src, tl, model?, poprz?}.
 * @param array<string,array<string,mixed>>  $indeks Z tl_get_match_index().
 * @return list<array<string,mixed>>
 */
function evk_tl_sprawdz_pola(array $el, string $lang, array $stan, array $indeks): array {
    $def = evk_tl_el_mapa()[(string) ($el['name'] ?? '')] ?? null;
    if (!is_array($def) || !is_array($el['settings'] ?? null)) return [];
    $id = (string) ($el['id'] ?? '');
    $kod = (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($lang));
    $out = [];
    $dodaj = static function (array $ust, string $pole, string $sciezka, string $etykieta) use ($id, $lang, $kod, $stan, $indeks, &$out): void {
        $pl = $ust[$pole] ?? null;
        if (!is_string($pl) || !evk_tl_sprawdz_tekst($pl)) return;
        $tl = $ust[evk_tl_el_klucz($lang, $pole)] ?? null;
        $klucz = $id . '|' . $sciezka . '|' . $kod;
        $slownik = '';
        if (evk_tl_el_niepuste($tl)) {
            $src = (string) ($stan[$klucz]['src'] ?? '');
            $s = $src === 'ai' ? 'ai' : ($src !== '' && $src !== evk_tl_el_skrot($pl) ? 'zmiana' : 'ok');
        } else {
            $tl = '';
            [$slownik, $zrodlo] = $indeks ? evk_tl_el_ze_slownika($pl, $indeks) : ['', 'brak'];
            $s = $zrodlo === 'slownik' ? 'slownik' : ($zrodlo === 'czesc' ? 'czesc' : 'brak');
        }
        /* 1.262.0: model tłumaczenia AI i poprzednia wersja (hurt ponowny albo
           zapis z okienka) — do znaczka i „Przywróć”. */
        $wpis = is_array($stan[$klucz] ?? null) ? $stan[$klucz] : [];
        $poprz = is_array($wpis['poprz'] ?? null) && is_string($wpis['poprz']['t'] ?? null) && $wpis['poprz']['t'] !== ''
            ? ['t' => $wpis['poprz']['t'], 'm' => (string) ($wpis['poprz']['m'] ?? '')] : null;
        $out[] = ['klucz' => $klucz, 'sciezka' => $sciezka, 'etykieta' => $etykieta, 'pl' => $pl, 'tl' => (string) $tl,
            'stan' => $s, 'slownik' => (string) $slownik, 'model' => $s === 'ai' ? (string) ($wpis['model'] ?? '') : '', 'poprz' => $poprz];
    };
    foreach ((array) ($def['pola'] ?? []) as $pole) {
        $dodaj($el['settings'], (string) $pole, (string) $pole, evk_tl_el_nazwa_pola((string) $pole));
    }
    foreach ((array) ($def['listy'] ?? []) as $lista => $pola) {
        $pozycje = $el['settings'][$lista] ?? null;
        if (!is_array($pozycje) || !$pozycje || array_keys($pozycje) !== range(0, count($pozycje) - 1)) continue;
        foreach ($pozycje as $i => $poz) {
            if (!is_array($poz)) continue;
            $pid = isset($poz['id']) && is_scalar($poz['id']) && (string) $poz['id'] !== '' ? (string) $poz['id'] : (string) $i;
            foreach ((array) $pola as $pole) {
                $dodaj($poz, (string) $pole, $lista . '.' . $pid . '.' . $pole, 'pozycja ' . ($i + 1) . ' · ' . evk_tl_el_nazwa_pola((string) $pole));
            }
        }
    }
    return $out;
}

/** Czy typ elementu ma pola tekstowe (mapa pól) — elementy bez tekstu tryb pomija. */
function evk_tl_sprawdz_ma_teksty(string $nazwa): bool {
    $def = evk_tl_el_mapa()[$nazwa] ?? null;
    return is_array($def) && (!empty($def['pola']) || !empty($def['listy']));
}

/** Stan „Do sprawdzenia" części wpisu (52). */
function evk_tl_sprawdz_stan(int $post_id, string $meta_key): array {
    $stan = get_post_meta($post_id, EVK_TL_EL_STAN, true);
    return is_array($stan) && is_array($stan[$meta_key] ?? null) ? $stan[$meta_key] : [];
}

/**
 * Adres strony w języku z włączonym trybem — do „Następna strona" i do listy
 * „Do sprawdzenia" w panelu.
 */
function evk_tl_sprawdz_adres(int $post_id, string $lang): string {
    $link = (string) get_permalink($post_id);
    if ($link === '') return '';
    return add_query_arg(EVK_TL_SPRAWDZ_PARAM, '1', function_exists('tl_url_jezyka') ? tl_url_jezyka($link, $lang) : $link);
}

/**
 * Następna strona (nie szablon) z tłumaczeniami „Do sprawdzenia" w tym języku —
 * po identyfikatorze, w kółko. Pusty, gdy innej nie ma.
 */
function evk_tl_sprawdz_nastepna(int $biezacy, string $lang): string {
    $kod = (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($lang));
    $wpisy = [];
    foreach (evk_tl_el_do_sprawdzenia(1000) as $m) {
        $pid = (int) $m['post_id'];
        if (($m['jezyk'] ?? '') !== $kod || $pid === $biezacy || isset($wpisy[$pid])) continue;
        if (get_post_type($pid) === 'bricks_template' || get_post_status($pid) !== 'publish') continue;
        $wpisy[$pid] = true;
    }
    if (!$wpisy) return '';
    ksort($wpisy);
    $ids = array_keys($wpisy);
    $dalej = array_values(array_filter($ids, static function ($i) use ($biezacy) { return $i > $biezacy; }));
    return evk_tl_sprawdz_adres($dalej ? $dalej[0] : $ids[0], $lang);
}

/**
 * Dane dla skryptu: elementy tej strony z polami i stanem, a przy każdym wpis,
 * do którego należy, i czy wolno go poprawiać.
 */
function evk_tl_sprawdz_dane(): array {
    $lang = get_current_lang();
    $zapisane = (array) ($GLOBALS['evk_tl_sprawdz_elementy'] ?? []);
    $zTekstem = array_filter($zapisane, static function ($z) { return evk_tl_sprawdz_ma_teksty((string) ($z['nazwa'] ?? '')); });
    $wlasciciele = evk_tl_sprawdz_wlasciciele(array_map('strval', array_keys($zTekstem)));
    $indeks = function_exists('tl_get_match_index') ? (array) tl_get_match_index($lang) : [];
    $stany = [];
    $elementy = [];
    foreach ($zTekstem as $id => $z) {
        $id = (string) $id;
        $nazwa = (string) $z['nazwa'];
        $e = ['id' => $id, 'dom' => $z['css'] !== '' ? $z['css'] : 'brxe-' . $id, 'nazwa' => $nazwa,
            'etykieta' => evk_tl_sprawdz_nazwa($nazwa), 'przeladuj' => evk_tl_sprawdz_przeladuj($nazwa), 'uwaga' => '', 'pola' => []];
        $w = $wlasciciele[$id] ?? null;
        if ($w === null || isset($w['wiele'])) {
            $e['uwaga'] = $w === null ? 'poza' : 'wiele';
            $elementy[$id] = $e;
            continue;
        }
        $klucz = $w['post'] . '|' . $w['meta'];
        if (!isset($stany[$klucz])) $stany[$klucz] = evk_tl_sprawdz_stan((int) $w['post'], (string) $w['meta']);
        $e['pola'] = evk_tl_sprawdz_pola((array) $w['el'], $lang, $stany[$klucz], $indeks);
        if (!$e['pola']) continue;
        $e += ['post' => (int) $w['post'], 'meta' => (string) $w['meta'], 'czesc' => evk_tl_el_czesc((string) $w['meta']),
            'tytul' => get_the_title((int) $w['post']) ?: ('#' . (int) $w['post']),
            'edycja' => current_user_can('edit_post', (int) $w['post'])];
        $elementy[$id] = $e;
    }
    return [
        'jezyk'    => $lang,
        'ajax'     => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('evk_tl_sprawdz'),
        'koniec'   => remove_query_arg(EVK_TL_SPRAWDZ_PARAM),
        'nastepna' => evk_tl_sprawdz_nastepna(is_singular() ? (int) get_queried_object_id() : 0, $lang),
        'unfiltered' => current_user_can('unfiltered_html'),
        'ai'       => evk_tl_sprawdz_ai_dane(),
        'elementy' => (object) $elementy,
    ];
}

/**
 * „Przetłumacz ponownie” w okienku (1.262.0): dostawcy z zapisanym kluczem
 * i dostawca z ustawień. Bez klucza w ustawieniach — null (przycisków nie ma).
 */
function evk_tl_sprawdz_ai_dane(): ?array {
    if (!function_exists('evk_tl_ai_dostepni')) return null;
    $u = evk_tl_ai_ustawienia();
    $d = evk_tl_ai_dostepni($u);
    return $d ? ['dostawcy' => $d, 'domyslny' => isset($d[$u['dostawca']]) ? $u['dostawca'] : (string) array_key_first($d)] : null;
}

// =========================================================================
// STRONA: pasek admina, skrypt, dane
// =========================================================================

add_action('admin_bar_menu', function ($bar) {
    if (!($bar instanceof WP_Admin_Bar) || is_admin() || evk_w_builderze() || !is_user_logged_in() || !evk_tl_sprawdz_dostep()) return;
    $jezyki = array_keys(tl_get_languages());
    if (!$jezyki) return;
    if (evk_tl_sprawdz_aktywny()) {
        $bar->add_node(['id' => 'evk-tl-sprawdz', 'title' => 'Zakończ sprawdzanie tłumaczeń', 'href' => remove_query_arg(EVK_TL_SPRAWDZ_PARAM)]);
        return;
    }
    $lang = get_current_lang();
    $cel = in_array($lang, $jezyki, true) ? $lang : (string) $jezyki[0];
    $adres = static function (string $j) use ($lang): string {
        $url = $j === $lang ? (string) add_query_arg([]) : (function_exists('lang_switch_url_with_translated_slug') ? lang_switch_url_with_translated_slug($j) : '');
        return $url !== '' ? add_query_arg(EVK_TL_SPRAWDZ_PARAM, '1', $url) : '';
    };
    $bar->add_node(['id' => 'evk-tl-sprawdz', 'title' => 'Sprawdź tłumaczenia (' . strtoupper($cel) . ')', 'href' => $adres($cel)]);
    foreach ($jezyki as $j) {
        if ($j === $cel) continue;
        $bar->add_node(['id' => 'evk-tl-sprawdz-' . sanitize_key($j), 'parent' => 'evk-tl-sprawdz', 'title' => 'Sprawdź ' . strtoupper($j), 'href' => $adres((string) $j)]);
    }
}, 90);

add_action('wp_enqueue_scripts', function () {
    if (!evk_tl_sprawdz_aktywny()) return;
    wp_enqueue_style('evk-tl-sprawdz', EVOKE_ONE_URL . 'assets/admin/tl-sprawdz.css', [], EVOKE_ONE_VERSION);
    wp_enqueue_script('evk-tl-sprawdz', EVOKE_ONE_URL . 'assets/admin/tl-sprawdz.js', [], EVOKE_ONE_VERSION, true);
}, 20);

/* Dane przed skryptami stopki (`wp_print_footer_scripts` ma priorytet 20) —
   jak podgląd w builderze (58). JSON_HEX_TAG nie wypuści `</script>` z tekstu. */
add_action('wp_footer', function () {
    if (!evk_tl_sprawdz_aktywny()) return;
    echo '<script type="application/json" id="evk-tl-sprawdz-dane">'
        . wp_json_encode(evk_tl_sprawdz_dane(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>' . "\n";
}, 5);

// =========================================================================
// ZAPIS
// =========================================================================

/**
 * Poprawki z okienka: pola elementu (ścieżka → tekst; pusty usuwa
 * tłumaczenie). Po zapisie wszystkie teksty elementu z tłumaczeniem są
 * sprawdzone — także gdy nic się nie zmieniło („Sprawdzone").
 *
 * `sciezki[]` (1.263.0, lista „Teksty w elementach"): sprawdzone są tylko
 * te pola — wiersz listy to jeden tekst w jednym języku (decyzja
 * zgłaszającego z 30.09). Okienko na stronie tego nie wysyła.
 */
add_action('wp_ajax_evk_tl_sprawdz_zapisz', function (): void {
    check_ajax_referer('evk_tl_sprawdz', 'nonce');
    if (!evk_tl_sprawdz_dostep()) wp_send_json_error('Brak uprawnień.', 403);
    $post_id = absint($_POST['post_id'] ?? 0);
    $meta_key = sanitize_text_field(wp_unslash((string) ($_POST['meta_key'] ?? '')));
    $lang = sanitize_key((string) ($_POST['lang'] ?? ''));
    $id = sanitize_text_field(wp_unslash((string) ($_POST['element'] ?? '')));
    if (!$post_id || $id === '' || !in_array($meta_key, evk_tl_el_klucze_meta(), true) || !isset(tl_get_languages()[$lang])) {
        wp_send_json_error('Nieznany element albo język.');
    }
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('Brak uprawnień do tej strony.', 403);
    $el = evk_tl_sprawdz_elementy_wpisu($post_id, $meta_key)[$id] ?? null;
    if (!$el) wp_send_json_error('Nie ma już tego elementu — odśwież stronę.');

    $przed = evk_tl_sprawdz_stan($post_id, $meta_key);
    $pola = evk_tl_sprawdz_pola($el, $lang, $przed, []);
    $wgSciezki = [];
    foreach ($pola as $p) $wgSciezki[$p['sciezka']] = $p['klucz'];
    $zmiany = [];
    $wejscie = isset($_POST['pola']) && is_array($_POST['pola']) ? wp_unslash($_POST['pola']) : [];
    foreach ($wejscie as $sciezka => $tekst) {
        if (!is_string($tekst) || !isset($wgSciezki[(string) $sciezka])) continue;
        if (!current_user_can('unfiltered_html')) $tekst = wp_kses_post($tekst);
        $zmiany[$wgSciezki[(string) $sciezka]] = evk_tl_el_niepuste($tekst) ? $tekst : '';
    }
    $zmienione = $zmiany ? evk_tl_el_zapisz_pola($post_id, $meta_key, $lang, $zmiany, false) : [];

    $tylko = isset($_POST['sciezki']) && is_array($_POST['sciezki'])
        ? array_flip(array_filter((array) wp_unslash($_POST['sciezki']), 'is_string')) : null;
    foreach ($pola as $p) {
        if ($tylko === null || isset($tylko[$p['sciezka']])) evk_tl_el_oznacz_sprawdzone($post_id, $meta_key, $p['klucz']);
    }
    /* 1.262.0: zmienione tłumaczenie odkłada poprzednie (z jego modelem) —
       „Przywróć” w okienku wraca do niego. Stan (52) policzył wpis od nowa
       przy zapisie, więc `poprz` dopisuje się po nim. */
    $stare = [];
    foreach ($pola as $p) $stare[$p['klucz']] = $p;
    $stan = get_post_meta($post_id, EVK_TL_EL_STAN, true);
    if ($zmienione && is_array($stan)) {
        foreach ($zmienione as $k) {
            $bylo = (string) ($stare[$k]['tl'] ?? '');
            if ($bylo === '' || !isset($stan[$meta_key][$k])) continue;
            $stan[$meta_key][$k]['poprz'] = ['t' => $bylo, 'm' => (string) ($przed[$k]['model'] ?? '')];
        }
        update_post_meta($post_id, EVK_TL_EL_STAN, $stan);
    }
    $el = evk_tl_sprawdz_elementy_wpisu($post_id, $meta_key)[$id] ?? $el;
    wp_send_json_success([
        'zmienione' => count($zmienione),
        'pola' => evk_tl_sprawdz_pola($el, $lang, evk_tl_sprawdz_stan($post_id, $meta_key),
            function_exists('tl_get_match_index') ? (array) tl_get_match_index($lang) : []),
    ]);
});

/**
 * „Przetłumacz ponownie” (1.262.0): jeden tekst elementu od nowa, wybranym
 * dostawcą i modelem, BEZ zapisu — wynik wraca do pola w okienku, a zapisuje
 * go „Zapisz”. Warunki jak przy zapisie (to ta sama poprawka, tylko jej
 * pierwszy krok): nonce, dostęp do Tłumaczeń, `edit_post` wpisu. Zapytanie
 * kosztuje — kto nie może poprawić strony, nie może go wysłać.
 */
add_action('wp_ajax_evk_tl_sprawdz_ai', function (): void {
    check_ajax_referer('evk_tl_sprawdz', 'nonce');
    if (!evk_tl_sprawdz_dostep() || !function_exists('evk_tl_ai_jeden')) wp_send_json_error('Brak uprawnień.', 403);
    $post_id = absint($_POST['post_id'] ?? 0);
    $meta_key = sanitize_text_field(wp_unslash((string) ($_POST['meta_key'] ?? '')));
    $lang = sanitize_key((string) ($_POST['lang'] ?? ''));
    $id = sanitize_text_field(wp_unslash((string) ($_POST['element'] ?? '')));
    $sciezka = sanitize_text_field(wp_unslash((string) ($_POST['sciezka'] ?? '')));
    if (!$post_id || $id === '' || !in_array($meta_key, evk_tl_el_klucze_meta(), true) || !isset(tl_get_languages()[$lang])) {
        wp_send_json_error('Nieznany element albo język.');
    }
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('Brak uprawnień do tej strony.', 403);
    $el = evk_tl_sprawdz_elementy_wpisu($post_id, $meta_key)[$id] ?? null;
    if (!$el) wp_send_json_error('Nie ma już tego elementu — odśwież stronę.');
    $klucz = '';
    foreach (evk_tl_sprawdz_pola($el, $lang, [], []) as $p) {
        if ($p['sciezka'] === $sciezka) $klucz = $p['klucz'];
    }
    if ($klucz === '') wp_send_json_error('Nieznane pole elementu.');
    $u = evk_tl_ai_na_przebieg(evk_tl_ai_ustawienia(), sanitize_key((string) ($_POST['dostawca'] ?? '')), (string) wp_unslash($_POST['model'] ?? ''));
    if (function_exists('set_time_limit')) @set_time_limit(180);
    $r = evk_tl_ai_jeden($post_id, $meta_key, $lang, $klucz, $u);
    $r['ok'] ? wp_send_json_success($r) : wp_send_json_error((string) ($r['blad'] ?? 'Błąd tłumaczenia.'));
});
