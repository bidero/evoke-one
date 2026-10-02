<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Obrazy WebP/AVIF (pozycja 5 kolejki, decyzje z 02.10.2026)
 *
 * PRZY WGRYWANIU obok każdego pliku JPEG i PNG z biblioteki (plik główny
 * i rozmiary pośrednie, czyli wszystko, co trafia do `srcset`) powstaje
 * `zdjecie-300x200.jpg.webp` i `zdjecie-300x200.jpg.avif`. ORYGINAŁ ZOSTAJE —
 * nie podmieniamy formatu przez `image_editor_output_format`, bo wtedy
 * WordPress zapisuje rozmiary pośrednie WYŁĄCZNIE w nowym formacie, a przeglądarka
 * bez AVIF nie miałaby czego pokazać.
 *
 * DOKLEJONE ROZSZERZENIE, a nie podmienione. `zdjecie.jpg` i `zdjecie.png`
 * w tym samym katalogu dałyby oba `zdjecie.webp`. Z doklejonym nazwy się nie
 * zderzają, a plik WebP da się znaleźć z samego adresu obrazu — bez
 * identyfikatora załącznika, którego Bricks w HTML-u często nie zostawia.
 *
 * AVIF TYLKO, GDY SERWER UMIE: `wp_image_editor_supports()` pyta edytory
 * WordPressa (Imagick z AVIF albo GD z `imageavif`). Bez bibliotek zewnętrznych —
 * cel projektu to mniej wtyczek, nie inna wtyczka w środku.
 *
 * PODAWANIE przez `<picture>`: `<source type="image/avif">` → `<source
 * type="image/webp">` → oryginalny `<img>` bez zmian (klasy, alt — także
 * tłumaczony, srcset, sizes, loading). Źródło dostaje się tylko wtedy, gdy
 * istnieje plik dla KAŻDEGO kandydata ze `srcset`: brak jednego rozmiaru
 * dałby przeglądarce wybór między samymi mniejszymi plikami i rozmazany
 * obraz na dużym ekranie.
 *
 * ZMNIEJSZANIE ZBYT DUŻYCH to `big_image_size_threshold` WordPressa (od 5.3:
 * „-scaled”, oryginał zostaje jako `original_image`) — ustawiamy próg, nie
 * dublujemy mechanizmu. EXIF zostaje taki, jak go zostawia WordPress.
 *
 * Tła CSS (`background-image`) — świadomie nie teraz (decyzja zgłaszającego).
 */

const EVK_OBRAZY_TYPY     = ['image/jpeg', 'image/png'];
const EVK_OBRAZY_META     = '_evk_obrazy';
const EVK_OBRAZY_PRZEBIEG = 'evk_obrazy_przebieg';
const EVK_OBRAZY_BLOKADA  = 'evk_obrazy_blokada';
const EVK_OBRAZY_HAK      = 'evk_obrazy_tick';

/* Porcje przerabiania biblioteki: krok z karty i krok crona. Liczba obrazów
   i czas — co pierwsze. Karta czeka na odpowiedź, więc krok jest krótki;
   cron nie ma na co czekać, ale hosting tnie żądania po 30 s. */
const EVK_OBRAZY_KROK_ILE   = 5;
const EVK_OBRAZY_KROK_S     = 10.0;
const EVK_OBRAZY_CRON_ILE   = 20;
const EVK_OBRAZY_CRON_S     = 20.0;
/* Karta, która nie odezwała się tyle sekund, jest uznana za zamkniętą —
   dalej pracuje cron. Krok karty trwa najwyżej EVK_OBRAZY_KROK_S. */
const EVK_OBRAZY_KARTA_S    = 60;

/** Ustawienia z domyślnymi. Jakość: WebP 80, AVIF 60; próg jak w WordPressie. */
function evk_obrazy_ustawienia(): array {
    $d = ['enabled' => 0, 'webp' => 1, 'avif' => 1, 'jakosc_webp' => 80, 'jakosc_avif' => 60, 'max_bok' => 2560];
    $u = get_option('evk_obrazy', []);
    return is_array($u) ? array_merge($d, array_intersect_key($u, $d)) : $d;
}

function evk_obrazy_wlaczone(): bool {
    return !empty(evk_obrazy_ustawienia()['enabled']);
}

/** Czy któryś edytor obrazów WordPressa ZAPISZE ten format. Raz na żądanie. */
function evk_obrazy_obslugiwany(string $fmt): bool {
    static $wynik = [];
    if (!isset($wynik[$fmt])) {
        if (!function_exists('wp_image_editor_supports')) require_once ABSPATH . WPINC . '/media.php';
        $wynik[$fmt] = (bool) wp_image_editor_supports(['mime_type' => 'image/' . $fmt]);
    }
    return $wynik[$fmt];
}

/**
 * Kolejność edytorów na czas zapisu formatu: WebP najpierw GD.
 *
 * ImageMagick 6 (6.9.12 z Ubuntu 24.04 — zmierzone; ta gałąź siedzi na wielu
 * hostingach) zapisuje WebP ze STAŁĄ jakością: q30 i q90 dały plik co do bajtu
 * ten sam, także `convert -quality` z wiersza poleceń. GD daje q30 → 26 KB,
 * q90 → 163 KB z tego samego pliku. AVIF zostaje przy kolejności WordPressa
 * (Imagick pierwszy) — tam jakość działa (q30 → 6 KB, q90 → 174 KB).
 */
function evk_obrazy_kolejnosc(string $fmt): callable {
    return static function ($edytory) use ($fmt) {
        if ($fmt !== 'webp' || !is_array($edytory) || !in_array('WP_Image_Editor_GD', $edytory, true)) return $edytory;
        return array_values(array_unique(array_merge(['WP_Image_Editor_GD'], $edytory)));
    };
}

/**
 * Edytor, którym WordPress zapisze ten format („Imagick”, „GD”) albo ''.
 * Panel mówi, CZYM serwer to robi — przy braku AVIF to pierwsze pytanie.
 */
function evk_obrazy_edytor(string $fmt): string {
    if (!function_exists('_wp_image_editor_choose')) return '';
    $kolejnosc = evk_obrazy_kolejnosc($fmt);
    add_filter('wp_image_editors', $kolejnosc, PHP_INT_MAX);
    $klasa = _wp_image_editor_choose(['mime_type' => 'image/' . $fmt, 'methods' => ['save']]);
    remove_filter('wp_image_editors', $kolejnosc, PHP_INT_MAX);
    if (!$klasa) return '';
    return str_replace('WP_Image_Editor_', '', (string) $klasa);
}

/**
 * Formaty do TWORZENIA: włączone w panelu i obsługiwane przez serwer, z jakością.
 * Kolejność ma znaczenie przy podawaniu: AVIF przed WebP.
 *
 * @return array<string,int>
 */
function evk_obrazy_formaty(): array {
    $u = evk_obrazy_ustawienia();
    $out = [];
    if (!empty($u['avif']) && evk_obrazy_obslugiwany('avif')) $out['avif'] = (int) $u['jakosc_avif'];
    if (!empty($u['webp']) && evk_obrazy_obslugiwany('webp')) $out['webp'] = (int) $u['jakosc_webp'];
    return $out;
}

/**
 * Formaty do PODAWANIA: włączone w panelu. Bez pytania serwer o zapis —
 * pliki już leżą, a przeglądarka ich nie potrzebuje serwera do odczytu.
 *
 * @return string[]
 */
function evk_obrazy_formaty_podawane(): array {
    $u = evk_obrazy_ustawienia();
    return array_values(array_filter(['avif', 'webp'], static function ($f) use ($u) { return !empty($u[$f]); }));
}

/**
 * Pliki JPEG/PNG załącznika, które trafiają na stronę: główny (po „-scaled”
 * to ten zmniejszony) i rozmiary pośrednie. `original_image` (plik sprzed
 * zmniejszenia) — nie: WordPress go nie podaje w `srcset`.
 *
 * @return string[] ścieżki bezwzględne, bez powtórzeń
 */
function evk_obrazy_zrodla(int $id, ?array $meta = null): array {
    $glowny = (string) get_attached_file($id);
    if ($glowny === '' || !is_file($glowny)) return [];
    $meta = $meta ?? wp_get_attachment_metadata($id);
    $katalog = trailingslashit(dirname($glowny));
    $out = [$glowny];
    foreach ((array) ($meta['sizes'] ?? []) as $r) {
        if (empty($r['file'])) continue;
        $p = $katalog . wp_basename((string) $r['file']);
        if (isset($r['mime-type']) && !in_array($r['mime-type'], EVK_OBRAZY_TYPY, true)) continue;
        $out[] = $p;
    }
    return array_values(array_unique(array_filter($out, static function ($p) {
        return is_file($p) && preg_match('/\.(jpe?g|png)$/i', $p);
    })));
}

/**
 * Jeden plik w jednym formacie, edytorem WordPressa.
 *
 * JAKOŚĆ PRZEZ FILTR NA CZAS ZAPISU, nie samo `set_quality()`. Przy zmianie
 * typu `get_output_format()` woła `set_quality()` bez argumentu, a to wraca
 * do domyślnej (WebP 86) — jakość z panelu ginęła po cichu. Format wyjścia
 * strony (`image_editor_output_format`) też na ten czas wyłączony: ktoś, kto
 * przestawił JPEG na WebP, dostałby z `.jpg.avif` plik w innym formacie.
 *
 * @return true|WP_Error
 */
function evk_obrazy_konwertuj(string $src, string $fmt, int $jakosc) {
    $mime = 'image/' . $fmt;
    $cel  = $src . '.' . $fmt;
    $q = static function () use ($jakosc) { return $jakosc; };
    $bez_mapy = static function () { return []; };
    $kolejnosc = evk_obrazy_kolejnosc($fmt);
    add_filter('wp_editor_set_quality', $q, PHP_INT_MAX);
    add_filter('image_editor_output_format', $bez_mapy, PHP_INT_MAX);
    add_filter('wp_image_editors', $kolejnosc, PHP_INT_MAX);
    try {
        $ed = wp_get_image_editor($src, ['mime_type' => $mime]);
        if (is_wp_error($ed)) return $ed;
        $ed->set_quality($jakosc);
        $zapis = $ed->save($cel, $mime);
    } catch (\Throwable $e) {
        return new WP_Error('evk_obrazy', $e->getMessage());
    } finally {
        remove_filter('wp_editor_set_quality', $q, PHP_INT_MAX);
        remove_filter('image_editor_output_format', $bez_mapy, PHP_INT_MAX);
        remove_filter('wp_image_editors', $kolejnosc, PHP_INT_MAX);
    }
    if (is_wp_error($zapis)) return $zapis;
    if ($zapis['path'] !== $cel || $zapis['mime-type'] !== $mime) {
        /* Edytor zapisał coś innego, niż chcieliśmy (inny typ, inna nazwa) —
           nie zostawiamy tego obok oryginału. */
        if ($zapis['path'] !== '' && $zapis['path'] !== $src && is_file($zapis['path'])) wp_delete_file($zapis['path']);
        return new WP_Error('evk_obrazy', 'edytor zapisał ' . $zapis['mime-type'] . ' zamiast ' . $mime);
    }
    return true;
}

/**
 * Wersje WebP/AVIF jednego załącznika. `$wymus` — także te, które już są
 * (przerabianie od nowa, np. po zmianie jakości).
 *
 * Meta `_evk_obrazy` = format => jakość, z którą powstał. Odpowiada na pytanie
 * „czy ten obraz ma już swoje wersje” bez sprawdzania plików na dysku —
 * przerabianie biblioteki pomija po niej gotowe, a panel liczy postęp.
 *
 * @return array{id:int,pliki:int,bledy:string[],formaty:array<string,int>}
 */
function evk_obrazy_przerob(int $id, bool $wymus = false, ?array $meta = null): array {
    $wynik = ['id' => $id, 'pliki' => 0, 'bledy' => [], 'formaty' => []];
    if (!in_array((string) get_post_mime_type($id), EVK_OBRAZY_TYPY, true)) return $wynik;
    $zrodla = evk_obrazy_zrodla($id, $meta);
    if (!$zrodla) {
        $wynik['bledy'][] = 'brak pliku obrazu na dysku';
        return $wynik;
    }
    $bylo = get_post_meta($id, EVK_OBRAZY_META, true);
    $bylo = is_array($bylo) ? $bylo : [];
    $stan = array_intersect_key($bylo, ['avif' => 1, 'webp' => 1]);
    foreach (evk_obrazy_formaty() as $fmt => $q) {
        $ok = true;
        foreach ($zrodla as $src) {
            $cel = $src . '.' . $fmt;
            if (!$wymus && is_file($cel) && filemtime($cel) >= filemtime($src)) continue;
            $r = evk_obrazy_konwertuj($src, $fmt, $q);
            if ($r === true) {
                $wynik['pliki']++;
            } else {
                $ok = false;
                $wynik['bledy'][] = wp_basename($src) . ' → ' . strtoupper($fmt) . ': ' . $r->get_error_message();
            }
        }
        if ($ok) $stan[$fmt] = ($wymus || !isset($bylo[$fmt])) ? $q : (int) $bylo[$fmt];
        else unset($stan[$fmt]);
    }
    $wynik['formaty'] = $stan;
    update_post_meta($id, EVK_OBRAZY_META, $stan);
    return $wynik;
}

/** Czy załącznikowi brakuje któregoś z formatów do tworzenia. */
function evk_obrazy_brakuje(int $id): bool {
    $m = get_post_meta($id, EVK_OBRAZY_META, true);
    $m = is_array($m) ? $m : [];
    foreach (array_keys(evk_obrazy_formaty()) as $fmt) {
        if (!isset($m[$fmt])) return true;
    }
    return false;
}

// =========================================================================
// WGRYWANIE
// =========================================================================

/* Na końcu `wp_generate_attachment_metadata()` — rozmiary pośrednie już są
   na dysku, `_wp_attached_file` wskazuje plik „-scaled”, a metadane jeszcze
   nie zapisane, więc rozmiary bierzemy z tego, co dostał filtr. */
add_filter('wp_generate_attachment_metadata', function ($meta, $id) {
    if (!evk_obrazy_wlaczone() || !is_array($meta)) return $meta;
    if (function_exists('set_time_limit')) @set_time_limit(120);
    evk_obrazy_przerob((int) $id, true, $meta);
    return $meta;
}, 20, 2);

/* Próg WordPressa zamiast własnego zmniejszania. 0 w panelu = bez
   zmniejszania (`false` wyłącza je w rdzeniu). */
add_filter('big_image_size_threshold', function ($prog) {
    if (!evk_obrazy_wlaczone()) return $prog;
    $bok = (int) evk_obrazy_ustawienia()['max_bok'];
    return $bok > 0 ? $bok : false;
}, 20);

// =========================================================================
// USUWANIE — razem z plikiem, z którego powstały
// =========================================================================

/* Każdy plik załącznika (główny, rozmiary, „-scaled”, kopie z edytora) idzie
   przez `wp_delete_file()`. Działa zawsze, także przy wyłączonym module —
   wersje z czasu, gdy był włączony, nie mogą zostać sierotami. */
add_filter('wp_delete_file', function ($plik) {
    if (!is_string($plik) || !preg_match('/\.(jpe?g|png)$/i', $plik)) return $plik;
    foreach (['webp', 'avif'] as $fmt) {
        if (is_file($plik . '.' . $fmt)) @unlink($plik . '.' . $fmt);
    }
    return $plik;
});

// =========================================================================
// PODAWANIE: <picture>
// =========================================================================

/**
 * Ścieżka pliku w katalogu wgrywania dla adresu obrazu albo ''.
 * Tylko adresy spod `baseurl`, bez zapytania i bez „..”.
 */
function evk_obrazy_sciezka(string $url): string {
    static $baza = null;
    if ($baza === null) {
        $u = wp_get_upload_dir();
        $baza = empty($u['error']) ? [preg_replace('#^https?:#i', '', (string) $u['baseurl']), (string) $u['basedir']] : ['', ''];
    }
    if ($baza[0] === '') return '';
    $url = html_entity_decode(trim($url), ENT_QUOTES);
    $bez = preg_replace('#^https?:#i', '', $url);
    if (strpos($bez, $baza[0] . '/') !== 0 || strpbrk($bez, '?#') !== false) return '';
    $wzgledna = rawurldecode(substr($bez, strlen($baza[0])));
    if (strpos($wzgledna, '..') !== false || !preg_match('/\.(jpe?g|png)$/i', $wzgledna)) return '';
    return $baza[1] . $wzgledna;
}

/** Atrybuty znacznika jako nazwa => surowa wartość (bez dekodowania encji). */
function evk_obrazy_atrybuty(string $tag): array {
    $out = [];
    if (preg_match_all('/\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $tag, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
        foreach ($m as $a) $out[strtolower((string) $a[1])] = (string) ($a[2] ?? $a[3] ?? $a[4] ?? '');
    }
    return $out;
}

/**
 * Kandydaci `srcset` jako [adres, deskryptor] — według reguł HTML: adres to
 * ciąg bez białych znaków, przecinek na jego końcu kończy kandydata,
 * a deskryptor sięga do najbliższego przecinka. Samo dzielenie po „, ”
 * rozsypałoby się na `a.jpg 300w,b.jpg 600w`.
 *
 * @return array<int,array{0:string,1:string}>
 */
function evk_obrazy_kandydaci(string $srcset): array {
    $out = [];
    $i = 0;
    $n = strlen($srcset);
    while ($i < $n) {
        $i += strspn($srcset, " \t\n\r\f,", $i);
        if ($i >= $n) break;
        $dl = strcspn($srcset, " \t\n\r\f", $i);
        $url = substr($srcset, $i, $dl);
        $i += $dl;
        $opis = '';
        if (substr($url, -1) === ',') {
            $url = rtrim($url, ',');
        } else {
            $dl = strcspn($srcset, ',', $i);
            $opis = trim(substr($srcset, $i, $dl));
            $i += $dl + 1;
        }
        if ($url !== '') $out[] = [$url, $opis];
    }
    return $out;
}

/**
 * `srcset` w danym formacie albo '' — gdy któregoś kandydata brakuje.
 * Adres dostaje doklejone rozszerzenie, deskryptor („300w”, „2x”) zostaje.
 */
function evk_obrazy_srcset(string $srcset, string $fmt): string {
    $out = [];
    foreach (evk_obrazy_kandydaci($srcset) as [$url, $opis]) {
        $sciezka = evk_obrazy_sciezka($url);
        if ($sciezka === '' || !is_file($sciezka . '.' . $fmt)) return '';
        $out[] = $url . '.' . $fmt . ($opis !== '' ? ' ' . $opis : '');
    }
    return implode(', ', $out);
}

/**
 * Jeden `<img>` → `<picture>` ze źródłami albo bez zmian.
 *
 * LENIWE ŁADOWANIE BRICKSA. Bricks podmienia `src` na zastępnik SVG, a prawdziwe
 * adresy trzyma w `data-src`/`data-srcset`/`data-sizes`; jego skrypt obserwuje
 * sam `<img>` i nie zna `<source>`. Źródło z prawdziwym `srcset` przeglądarka
 * pobrałaby od razu (koniec leniwego ładowania), a źródło z `data-srcset` nie
 * zostałoby nigdy uzupełnione (`<source>` nie ma pudełka, więc obserwator
 * przecięć go nie zobaczy). Taki obraz dostaje więc z powrotem prawdziwe
 * adresy i `loading="lazy"` przeglądarki — ono obejmuje cały `<picture>`.
 * Obraz bez wersji WebP/AVIF zostaje z leniwym ładowaniem Bricksa, nietknięty.
 */
function evk_obrazy_img(string $img): string {
    $a = evk_obrazy_atrybuty($img);
    $bricks = isset($a['data-src']) && preg_match('/(^|\s)bricks-lazy-hidden(\s|$)/', (string) ($a['class'] ?? ''));
    $src    = $bricks ? $a['data-src'] : (string) ($a['src'] ?? '');
    $srcset = $bricks ? (string) ($a['data-srcset'] ?? '') : (string) ($a['srcset'] ?? '');
    $sizes  = $bricks ? (string) ($a['data-sizes'] ?? '') : (string) ($a['sizes'] ?? '');
    if ($src === '' || strpos($src, 'data:') === 0) return $img;
    $lista = $srcset !== '' ? $srcset : $src;

    $zrodla = '';
    foreach (evk_obrazy_formaty_podawane() as $fmt) {
        $s = evk_obrazy_srcset($lista, $fmt);
        if ($s === '') continue;
        $zrodla .= '<source type="image/' . $fmt . '" srcset="' . esc_attr(html_entity_decode($s, ENT_QUOTES)) . '"'
            . ($sizes !== '' && $srcset !== '' ? ' sizes="' . esc_attr(html_entity_decode($sizes, ENT_QUOTES)) . '"' : '') . '>';
    }
    if ($zrodla === '') return $img;

    if ($bricks) {
        $img = preg_replace('/\s(?:src|srcset|sizes|data-type)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i', '', $img);
        $img = preg_replace('/\sdata-(src|srcset|sizes)(\s*=)/i', ' $1$2', $img);
        $img = preg_replace_callback('/\sclass\s*=\s*(["\'])(.*?)\1/i', static function ($m) {
            $k = trim(preg_replace('/\s+/', ' ', preg_replace('/(^|\s)bricks-lazy-hidden(?=\s|$)/', ' ', $m[2])));
            return ' class=' . $m[1] . $k . $m[1];
        }, $img, 1);
        /* Pierwszy duży obraz strony WordPress oznacza `fetchpriority="high"`
           (kandydat na LCP) i nigdy nie łączy tego z `loading="lazy"`. Bricks
           i tak go ukrywał do przewinięcia; my zostawiamy go bez leniwości. */
        if (!isset($a['loading']) && strtolower((string) ($a['fetchpriority'] ?? '')) !== 'high') {
            $img = preg_replace('/^<img\b/i', '<img loading="lazy"', $img);
        }
    }
    return '<picture class="evk-obraz">' . $zrodla . $img . '</picture>';
}

/**
 * Wszystkie `<img>` w kawałku HTML-u poza tymi, których ruszać nie wolno:
 * już w `<picture>` (Bricks z własnymi źródłami, treść wpisu przetworzona
 * wcześniej przez `the_content` — render Bricksa dostaje ją drugi raz),
 * w `<noscript>`, `<template>`, `<script>`, `<textarea>`.
 */
function evk_obrazy_html($html) {
    if (!is_string($html) || stripos($html, '<img') === false) return $html;
    if (!evk_obrazy_podawac()) return $html;
    return preg_replace_callback('#<(picture|noscript|template|script|textarea)\b.*?</\1\s*>|<img\b[^>]*>#is', static function ($m) {
        /* Grupa 1 istnieje tylko przy znaczniku z pierwszej gałęzi (PCRE nie
           oddaje niedopasowanych grup z końca). */
        return isset($m[1]) ? $m[0] : evk_obrazy_img($m[0]);
    }, $html) ?? $html;
}

/** Czy w tym żądaniu podajemy `<picture>`: strona, nie panel, kanał ani builder. */
function evk_obrazy_podawac(): bool {
    if (!evk_obrazy_wlaczone() || !evk_obrazy_formaty_podawane()) return false;
    if (is_admin() && !wp_doing_ajax()) return false;
    if (function_exists('is_feed') && is_feed()) return false;
    if (function_exists('bricks_is_builder') && bricks_is_builder()) return false;
    if (function_exists('bricks_is_builder_call') && bricks_is_builder_call()) return false;
    return true;
}

/* Po `wp_filter_content_tags` (12) — `<img>` ma już srcset, sizes i loading.
   Render Bricksa po tłumaczeniach (1) i sierotkach (20): adresy obrazów
   w języku strony są już podmienione. */
add_filter('the_content', 'evk_obrazy_html', 30);
add_filter('post_thumbnail_html', 'evk_obrazy_html', 30);
add_filter('bricks/frontend/render_data', 'evk_obrazy_html', 30);

/* `<picture>` wstawiony wokół `<img>` nie może zmienić układu: obraz bywa
   dzieckiem flexa albo siatki Bricksa (Image bez podpisu i odnośnika — `<img>`
   to korzeń elementu z szerokością w %). `display: contents` zostawia pudełko
   samemu `<img>`, jakby `<picture>` nie było. Ale wtedy `<source>` też
   wchodzą do flexa: puste, a jednak każdy dostawał `gap` — zmierzone w Chromium,
   obraz przesunięty o 2 × 10 px. Stąd `display: none` na źródłach (wyboru
   źródła to nie dotyczy — `<source>` i tak się nie rysuje). */
add_action('wp_head', function () {
    if (!evk_obrazy_podawac()) return;
    echo '<style id="evk-obrazy">picture.evk-obraz{display:contents}picture.evk-obraz>source{display:none}</style>' . "\n";
}, 20);

// =========================================================================
// PRZERABIANIE BIBLIOTEKI — porcjami z karty, dokończenie cronem
// =========================================================================

/** Stan przebiegu z domyślnymi. Zawsze świeży z bazy — pisze go też cron. */
function evk_obrazy_przebieg(): array {
    wp_cache_delete(EVK_OBRAZY_PRZEBIEG, 'options');
    wp_cache_delete('notoptions', 'options');
    $p = get_option(EVK_OBRAZY_PRZEBIEG, []);
    return array_merge(['stan' => 'brak', 'tryb' => 'brakujace', 'od' => 0, 'przejrzane' => 0, 'zrobione' => 0,
        'pominiete' => 0, 'bledy' => 0, 'wszystkie' => 0, 'ostatnie_bledy' => [], 'start' => 0, 'koniec' => 0,
        'krok' => 0, 'cron' => 0, 'kroki_cron' => 0], is_array($p) ? $p : []);
}

/** Identyfikatory JPEG/PNG z biblioteki po `$od`, rosnąco. */
function evk_obrazy_kolejne(int $od, int $ile): array {
    global $wpdb;
    $typy = "'" . implode("','", EVK_OBRAZY_TYPY) . "'";
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($typy) AND ID > %d ORDER BY ID ASC LIMIT %d", $od, $ile)));
}

/** Liczby do panelu: obrazy JPEG/PNG w bibliotece i ile z nich ma wersje. */
function evk_obrazy_liczby(): array {
    global $wpdb;
    $typy = "'" . implode("','", EVK_OBRAZY_TYPY) . "'";
    $wszystkie = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($typy)");
    $z_wersjami = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
         WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($typy) AND m.meta_value <> %s", EVK_OBRAZY_META, serialize([])));
    return ['wszystkie' => $wszystkie, 'z_wersjami' => $z_wersjami];
}

/**
 * Jeden przebieg naraz. `add_option` jest atomowy (klucz unikalny w bazie),
 * więc krok karty i cron nie przerobią tego samego obrazu jednocześnie.
 * Blokada starsza niż dwie minuty to proces, który padł w trakcie.
 */
function evk_obrazy_zablokuj(): bool {
    if (add_option(EVK_OBRAZY_BLOKADA, time(), '', false)) return true;
    wp_cache_delete(EVK_OBRAZY_BLOKADA, 'options');
    wp_cache_delete('notoptions', 'options');
    $kiedy = (int) get_option(EVK_OBRAZY_BLOKADA, 0);
    if ($kiedy > time() - 120) return false;
    delete_option(EVK_OBRAZY_BLOKADA);
    return (bool) add_option(EVK_OBRAZY_BLOKADA, time(), '', false);
}

function evk_obrazy_odblokuj(): void {
    delete_option(EVK_OBRAZY_BLOKADA);
}

/** Następny krok crona za `$za` sekund — jeden, bez duplikatów. */
function evk_obrazy_planuj(int $za): void {
    wp_clear_scheduled_hook(EVK_OBRAZY_HAK);
    wp_schedule_single_event(time() + max(1, $za), EVK_OBRAZY_HAK);
}

/** Start przebiegu: od początku biblioteki. Cron czeka w odwodzie od razu. */
function evk_obrazy_start(string $tryb): array {
    $p = evk_obrazy_przebieg();
    $p = array_merge($p, ['stan' => 'trwa', 'tryb' => $tryb === 'wszystkie' ? 'wszystkie' : 'brakujace', 'od' => 0,
        'przejrzane' => 0, 'zrobione' => 0, 'pominiete' => 0, 'bledy' => 0, 'ostatnie_bledy' => [],
        'wszystkie' => evk_obrazy_liczby()['wszystkie'], 'start' => time(), 'koniec' => 0, 'krok' => time(), 'cron' => 0, 'kroki_cron' => 0]);
    update_option(EVK_OBRAZY_PRZEBIEG, $p, false);
    evk_obrazy_planuj(EVK_OBRAZY_KARTA_S + 30);
    return $p;
}

function evk_obrazy_stop(): array {
    $p = evk_obrazy_przebieg();
    if ($p['stan'] === 'trwa') {
        $p['stan'] = 'zatrzymany';
        $p['koniec'] = time();
        update_option(EVK_OBRAZY_PRZEBIEG, $p, false);
    }
    wp_clear_scheduled_hook(EVK_OBRAZY_HAK);
    return $p;
}

/**
 * Porcja przebiegu: do `$ile` obrazów albo `$sekundy`, co pierwsze.
 * `$kto` — 'karta' albo 'cron'; karta odświeża znacznik „żyję”, po którym
 * cron poznaje, że ma nie przeszkadzać.
 */
function evk_obrazy_porcja(int $ile, float $sekundy, string $kto): array {
    $p = evk_obrazy_przebieg();
    if ($p['stan'] !== 'trwa') return $p;
    if (!evk_obrazy_zablokuj()) return $p + ['zajete' => true];
    $start = microtime(true);
    $n = 0;
    try {
        while ($n < $ile && microtime(true) - $start < $sekundy) {
            $ids = evk_obrazy_kolejne((int) $p['od'], min(10, $ile - $n));
            if (!$ids) {
                $p['stan'] = 'gotowe';
                $p['koniec'] = time();
                break;
            }
            foreach ($ids as $id) {
                $wymus = $p['tryb'] === 'wszystkie';
                if ($wymus || evk_obrazy_brakuje($id)) {
                    $r = evk_obrazy_przerob($id, $wymus);
                    if ($r['bledy']) {
                        $p['bledy']++;
                        $p['ostatnie_bledy'] = array_slice(array_merge($p['ostatnie_bledy'], ['#' . $id . ': ' . implode('; ', $r['bledy'])]), -10);
                    } else {
                        $p['zrobione']++;
                    }
                } else {
                    $p['pominiete']++;
                }
                $p['od'] = $id;
                $p['przejrzane']++;
                $n++;
                if ($n >= $ile || microtime(true) - $start >= $sekundy) break;
            }
        }
        /* Ostatni obraz biblioteki przerobiony na końcu porcji: następne
           zapytanie i tak oddałoby pustą listę, więc nie każemy karcie
           robić po to jeszcze jednego kroku. */
        if ($p['stan'] === 'trwa' && !evk_obrazy_kolejne((int) $p['od'], 1)) {
            $p['stan'] = 'gotowe';
            $p['koniec'] = time();
        }
    } finally {
        evk_obrazy_odblokuj();
    }
    /* „Zatrzymaj” kliknięte w trakcie porcji wygrywa z jej zapisem. */
    if (evk_obrazy_przebieg()['stan'] === 'zatrzymany') $p['stan'] = 'zatrzymany';
    if ($kto === 'karta') {
        $p['krok'] = time();
    } else {
        $p['cron'] = time();
        $p['kroki_cron']++;
    }
    update_option(EVK_OBRAZY_PRZEBIEG, $p, false);
    if ($p['stan'] === 'trwa') evk_obrazy_planuj($kto === 'karta' ? EVK_OBRAZY_KARTA_S + 30 : 5);
    else wp_clear_scheduled_hook(EVK_OBRAZY_HAK);
    return $p;
}

/* Cron: rusza tylko wtedy, gdy karta zamilkła. Karta aktywna — przesuwa się
   za nią i czeka. */
add_action(EVK_OBRAZY_HAK, function (): void {
    $p = evk_obrazy_przebieg();
    if ($p['stan'] !== 'trwa') return;
    if (!evk_obrazy_wlaczone()) {
        evk_obrazy_stop();
        return;
    }
    if (time() - (int) $p['krok'] < EVK_OBRAZY_KARTA_S) {
        evk_obrazy_planuj(EVK_OBRAZY_KARTA_S - (time() - (int) $p['krok']) + 5);
        return;
    }
    if (function_exists('set_time_limit')) @set_time_limit(120);
    evk_obrazy_porcja(EVK_OBRAZY_CRON_ILE, EVK_OBRAZY_CRON_S, 'cron');
});

/** Stan dla panelu: przebieg, liczby, obsługa formatów. */
function evk_obrazy_dla_panelu(): array {
    $p = evk_obrazy_przebieg();
    unset($p['krok'], $p['cron']);
    return ['przebieg' => $p, 'liczby' => evk_obrazy_liczby()];
}

add_action('wp_ajax_evk_obrazy', function (): void {
    check_ajax_referer('evk_obrazy', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.', 403);
    $co = sanitize_key((string) ($_POST['co'] ?? ''));
    if ($co === 'start' || $co === 'krok') {
        if (!evk_obrazy_wlaczone()) wp_send_json_error('Moduł jest wyłączony — włącz go przełącznikiem u góry.');
        if (!evk_obrazy_formaty()) wp_send_json_error('Serwer nie zapisze ani WebP, ani AVIF (albo oba wyłączone w ustawieniach).');
    }
    if ($co === 'start') {
        evk_obrazy_start(sanitize_key((string) ($_POST['tryb'] ?? '')));
    } elseif ($co === 'krok') {
        if (function_exists('set_time_limit')) @set_time_limit(120);
        evk_obrazy_porcja(EVK_OBRAZY_KROK_ILE, EVK_OBRAZY_KROK_S, 'karta');
    } elseif ($co === 'stop') {
        evk_obrazy_stop();
    } elseif ($co !== 'stan') {
        wp_send_json_error('Nieznane polecenie.');
    }
    wp_send_json_success(evk_obrazy_dla_panelu());
});

// =========================================================================
// USTAWIENIA
// =========================================================================

add_action('admin_init', function (): void {
    register_setting('evoke_one_obrazy', 'evk_obrazy', [
        'type'              => 'array',
        'sanitize_callback' => 'evk_obrazy_sanituj',
    ]);
});

function evk_obrazy_sanituj($input): array {
    $input = is_array($input) ? $input : [];
    $q = static function ($v, int $d): int { $v = (int) $v; return $v >= 1 && $v <= 100 ? $v : $d; };
    $bok = max(0, (int) ($input['max_bok'] ?? 2560));
    return [
        // Przełącznik jedzie AJAX-em — zachowujemy go, gdy nie ma go w POST.
        'enabled'     => evk_preserve_toggle($input, 'evk_obrazy'),
        'webp'        => !empty($input['webp']) ? 1 : 0,
        'avif'        => !empty($input['avif']) ? 1 : 0,
        'jakosc_webp' => $q($input['jakosc_webp'] ?? 80, 80),
        'jakosc_avif' => $q($input['jakosc_avif'] ?? 60, 60),
        /* Poniżej 320 px zmniejszanie zjadałoby zwykłe zdjęcia do miniatur —
           taka wartość to raczej literówka niż decyzja. */
        'max_bok'     => $bok === 0 ? 0 : max(320, $bok),
    ];
}
