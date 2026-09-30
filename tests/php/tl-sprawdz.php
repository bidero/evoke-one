<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Sprawdzanie tłumaczeń na stronie (1.261.0) — prawdziwy WordPress; render
 * Bricksa udaje mu-plugin (Bricksa tu nie ma).
 *
 *   php tests/php/tl-sprawdz.php wp
 *   php tests/php/tl-sprawdz.php modul          języki i moduł Tłumaczeń (wczyta się w NASTĘPNYM procesie)
 *   php tests/php/tl-sprawdz.php przygotuj      mapa pól, strony, szablony, słownik, użytkownicy
 *   php tests/php/tl-sprawdz.php mu             atrapa renderu Bricksa (mu-plugin, działa tylko pod php -S)
 *   php tests/php/tl-sprawdz.php stan <L> <id>  pola EN elementu, stan „Do sprawdzenia” i wykaz dopisanych
 *   php tests/php/tl-sprawdz.php lista <baza>   sekcja „Do sprawdzenia” z panelu (adresy serwera testowego)
 *   php tests/php/tl-sprawdz.php odbierz <login> odbiera dostęp do Tłumaczeń (prawo edycji stron zostaje)
 *   php tests/php/tl-sprawdz.php sprzataj
 *
 * Wpisy (litery w pliku stanu):
 *   A — strona: h1 (EN od AI), t1 (polski zmieniony po tłumaczeniu), b1 (bez EN),
 *       s1 (tekst ze słownika), ok1 (przetłumaczony), a1 (akordeon: pozycja 1
 *       z EN od AI, pozycja 2 bez EN), d1 (sam {post_title}), lp1 (w pętli —
 *       dwa razy na stronie), cs1 (własne CSS ID, bez EN), x1 → szablon S,
 *       x2 → szablon K1, c1 (kontener, bez tekstu);
 *   B — strona z tłumaczeniem AI (do „Następna strona”);
 *   C — skopiowana strona z elementem h1 strony A (ten sam identyfikator): na A
 *       element należy do A, bo kandydaci idą przed wyszukiwaniem;
 *   H, F — szablony nagłówka i stopki; S — szablon sekcji (element sx1 poza
 *   kandydatami, jedno trafienie); K1, K2 — kopia z tym samym elementem dup1
 *   (dwa trafienia → tylko podgląd) i kontenerem dupc (bez tekstów — poza trybem).
 */

if (($argv[1] ?? '') === 'lista' && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
require __DIR__ . '/_testowy-wp.php';

$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-tl-sprawdz.json';
$tresc = '_bricks_page_content_2';
$mu    = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-tl-sprawdz.php';
$muDir = $mu . '/evk-t-tl-sprawdz';
$opcje = ['tl_languages', 'evk_tl_module_enabled', 'tl_translations', 'evk_tl_el_pola', 'evk_t_tls_h', 'evk_t_tls_f'];
$out   = ['krok' => $krok];

function evk_t_tls_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_t_tls_wpis(string $l): int {
    return (int) ((evk_t_tls_zapis()['wpisy'] ?? [])[$l] ?? 0);
}
/** Wpis z danymi Bricksa — zapis przez update_post_meta, jak builder (hak stanu 52 liczy stan). */
function evk_t_tls_wstaw(string $tytul, string $typ, string $klucz, array $dane): int {
    $id = (int) wp_insert_post(['post_title' => $tytul, 'post_type' => $typ, 'post_status' => 'publish']);
    update_post_meta($id, $klucz, wp_slash($dane));
    return $id;
}
/** Źródło tłumaczenia w stanie — jak hurt AI (`src: ai`). */
function evk_t_tls_ai(int $id, string $meta, array $klucze): void {
    $stan = get_post_meta($id, EVK_TL_EL_STAN, true);
    foreach ($klucze as $k) {
        if (isset($stan[$meta][$k])) $stan[$meta][$k]['src'] = 'ai';
    }
    update_post_meta($id, EVK_TL_EL_STAN, $stan);
}

switch ($krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'modul':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'wpisy' => [], 'uzytkownicy' => []]));
    }
    update_option('tl_languages', [['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE']]);
    update_option('evk_tl_module_enabled', 1);
    $out['gotowe'] = true;
    break;

case 'przygotuj':
    if (!function_exists('evk_tl_sprawdz_dane')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $zapis = evk_t_tls_zapis();
    /* Słownik pusty przy zakładaniu stron — inaczej uzupełnianie przy zapisie
       (53) wpisałoby frazę do pola s1, a ten ma zostać „ze słownika”. */
    update_option('tl_translations', ['groups' => []]);
    tl_invalidate_cache();
    // Mapa tak, jak zapisałby ją builder: prawdziwy filtr kontrolek na kontrolkach w kształcie Bricksa.
    delete_option(EVK_TL_EL_MAPA);
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'text'], 'tag' => ['tab' => 'content', 'type' => 'select']], 'heading');
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'editor']], 'text-basic');
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'text'], 'link' => ['tab' => 'content', 'type' => 'link']], 'button');
    evk_tl_el_kontrolki(['accordions' => ['tab' => 'content', 'type' => 'repeater', 'fields' => [
        'title' => ['label' => 'Tytuł', 'type' => 'text'], 'content' => ['label' => 'Treść', 'type' => 'editor']]]], 'accordion');
    evk_tl_el_kontrolki(['tag' => ['tab' => 'content', 'type' => 'select']], 'container');
    evk_tl_el_zapisz_mape();

    $w = [];
    $w['S'] = evk_t_tls_wstaw('TLS szablon sekcji', 'bricks_template', $tresc,
        [['id' => 'sx1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Sekcja wspólna']]]);
    // Kontener w kopii: bez tekstów, więc poza danymi trybu, choć ma dwa trafienia.
    $kopia = [['id' => 'dup1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Kopia', 'evk_tl_en__text' => 'Copy']],
        ['id' => 'dupc', 'name' => 'container', 'parent' => 0, 'settings' => []]];
    $w['K1'] = evk_t_tls_wstaw('TLS kopia 1', 'bricks_template', $tresc, $kopia);
    $w['K2'] = evk_t_tls_wstaw('TLS kopia 2', 'bricks_template', $tresc, $kopia);
    $w['H'] = evk_t_tls_wstaw('TLS nagłówek', 'bricks_template', '_bricks_page_header_2',
        [['id' => 'hn1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Menu główne']]]);
    $w['F'] = evk_t_tls_wstaw('TLS stopka', 'bricks_template', '_bricks_page_footer_2',
        [['id' => 'fn1', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => 'Stopka firmy', 'evk_tl_en__text' => 'Company footer']]]);
    $a = [
        ['id' => 'h1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Nasze usługi', 'evk_tl_en__text' => 'EN: Our services']],
        ['id' => 't1', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => '<p>Projektujemy strony.</p>', 'evk_tl_en__text' => '<p>We design websites.</p>']],
        ['id' => 'b1', 'name' => 'button', 'parent' => 0, 'settings' => ['text' => 'Napisz do nas']],
        ['id' => 's1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Słownikowy tekst']],
        ['id' => 'ok1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Gotowe', 'evk_tl_en__text' => 'Ready']],
        ['id' => 'a1', 'name' => 'accordion', 'parent' => 0, 'settings' => ['accordions' => [
            ['id' => 'p1', 'title' => 'Pytanie', 'content' => '<p>Odpowiedź</p>', 'evk_tl_en__title' => 'EN Question'],
            ['id' => 'p2', 'title' => 'Drugie pytanie', 'content' => '<p>Druga odpowiedź</p>'],
        ]]],
        ['id' => 'd1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => '{post_title}']],
        ['id' => 'lp1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'W pętli', 'evk_tl_en__text' => 'In a loop', '_evk_t_razy' => 2]],
        ['id' => 'cs1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Własne ID', '_cssId' => 'moj-naglowek']],
        ['id' => 'x1', 'name' => 'template', 'parent' => 0, 'settings' => ['template' => $w['S']]],
        ['id' => 'x2', 'name' => 'template', 'parent' => 0, 'settings' => ['template' => $w['K1']]],
        ['id' => 'c1', 'name' => 'container', 'parent' => 0, 'settings' => []],
    ];
    $w['A'] = evk_t_tls_wstaw('TLS strona A', 'page', $tresc, $a);
    $w['B'] = evk_t_tls_wstaw('TLS strona B', 'page', $tresc,
        [['id' => 'hb', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Strona B', 'evk_tl_en__text' => 'Page B']]]);
    // Kopia strony: ten sam identyfikator elementu, stan bez AI (nie trafia do „Następna strona”).
    $w['C'] = evk_t_tls_wstaw('TLS kopia strony A', 'page', $tresc,
        [['id' => 'h1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Nasze usługi', 'evk_tl_en__text' => 'Our services (copy)']]]);

    // Stan jak po hurcie AI i po zmianie polskiego tekstu.
    evk_t_tls_ai($w['A'], $tresc, ['h1|text|en', 'a1|accordions.p1.title|en']);
    evk_t_tls_ai($w['B'], $tresc, ['hb|text|en']);
    // Szablon sekcji z tłumaczeniem AI: w liście bez „Na stronie”, nigdy jako „Następna strona”.
    evk_t_tls_ai($w['K1'], $tresc, ['dup1|text|en']);
    $a[1]['settings']['text'] = '<p>Projektujemy strony i sklepy.</p>';
    update_post_meta($w['A'], $tresc, wp_slash($a));
    delete_post_meta($w['A'], EVK_TL_EL_DOPISANE);

    // Słownik dopiero teraz (patrz wyżej).
    update_option('tl_translations', ['groups' => ['g' => ['name' => 'TLS', 'rows' => [
        'r1' => ['pl' => 'Słownikowy tekst', 'en' => 'Dictionary text', 'de' => ''],
    ]]]]);
    tl_invalidate_cache();
    update_option('evk_t_tls_h', $w['H'], false);
    update_option('evk_t_tls_f', $w['F'], false);

    // Tłumacz bez unfiltered_html (może edytować strony) i ktoś z samym dostępem do Tłumaczeń.
    $uz = [];
    foreach (['tlumacz' => ['edit_pages', 'edit_others_pages', 'edit_published_pages'], 'bez' => []] as $kto => $prawa) {
        $login = 'evk-t-tls-' . $kto;
        $u = get_user_by('login', $login);
        $uid = $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => 'evk-t-haslo-' . $kto,
            'user_email' => $login . '@example.test', 'role' => 'subscriber']);
        $user = get_user_by('id', $uid);
        foreach (array_merge(['evk_access_translations'], $prawa) as $p) $user->add_cap($p);
        $uz[] = $uid;
    }
    $zapis['wpisy'] = $w;
    $zapis['uzytkownicy'] = $uz;
    file_put_contents($plik, wp_json_encode($zapis));
    $out['wpisy'] = $w;
    $out['gotowe'] = function_exists('evk_tl_sprawdz_dane');
    break;

case 'mu':
    $zapis = evk_t_tls_zapis();
    if (!array_key_exists('mu_bylo', $zapis)) {
        $zapis['mu_bylo'] = is_dir($mu);
        file_put_contents($plik, wp_json_encode($zapis));
    }
    if (!is_dir($muDir)) mkdir($muDir, 0755, true);
    /* Atrapa w podkatalogu (WordPress ładuje tylko pliki z korzenia mu-plugins)
       i wczytywana warunkowo: funkcje z najwyższego poziomu pliku PHP deklaruje
       przy kompilacji, a sondy z wiersza poleceń nie mogą ich dostać. */
    file_put_contents($muDir . '/bricks.php', <<<'PHP'
<?php
namespace Bricks {
    if (!class_exists(__NAMESPACE__ . '\Database')) {
        /** Atrapa: tylko to, co czyta Evoke ONE — aktywne szablony strony. */
        class Database { public static $active_templates = []; }
    }
}
namespace {
    if (!defined('ABSPATH')) exit;
    add_action('init', function () {
        if (!post_type_exists('bricks_template')) {
            register_post_type('bricks_template', ['public' => false, 'show_ui' => false, 'capability_type' => 'page', 'map_meta_cap' => true]);
        }
    }, 5);
    add_filter('template_include', function ($t) {
        return (is_admin() || !is_singular('page')) ? $t : __DIR__ . '/szablon.php';
    }, 99);
    /** Render jak Bricks: każdy element przez prawdziwy filtr ustawień, id `brxe-{id}` albo własne CSS ID. */
    function evk_t_tls_rysuj($dane): void {
        foreach (is_array($dane) ? $dane : [] as $e) {
            if (!is_array($e) || empty($e['id'])) continue;
            $obiekt = (object) ['id' => (string) $e['id'], 'name' => (string) ($e['name'] ?? '')];
            $s = apply_filters('bricks/element/settings', (array) ($e['settings'] ?? []), $obiekt);
            $dom = !empty($s['_cssId']) ? (string) $s['_cssId'] : 'brxe-' . $e['id'];
            $razy = max(1, (int) ($s['_evk_t_razy'] ?? 1));
            for ($i = 0; $i < $razy; $i++) {
                $a = ' id="' . esc_attr($dom) . '" class="brxe-' . esc_attr((string) $e['name']) . '"';
                switch ($e['name']) {
                    case 'heading':    echo '<h2' . $a . '>' . $s['text'] . '</h2>'; break;
                    case 'text-basic': echo '<div' . $a . '>' . $s['text'] . '</div>'; break;
                    case 'button':     echo '<a' . $a . ' href="https://example.test/kontakt">' . $s['text'] . '</a>'; break;
                    case 'accordion':
                        echo '<div' . $a . '>';
                        foreach ((array) ($s['accordions'] ?? []) as $p) {
                            echo '<div class="accordion-item"><div class="accordion-title-wrapper"><h3>' . ($p['title'] ?? '')
                                . '</h3></div><div class="accordion-content-wrapper">' . ($p['content'] ?? '') . '</div></div>';
                        }
                        echo '</div>';
                        break;
                    case 'template':   evk_t_tls_rysuj(get_post_meta((int) ($s['template'] ?? 0), '_bricks_page_content_2', true)); break;
                    case 'container':  echo '<div' . $a . '></div>'; break;
                }
            }
        }
    }
}
PHP
    );
    file_put_contents($muDir . '/szablon.php', <<<'PHP'
<?php
if (!defined('ABSPATH')) exit;
$evk_h = (int) get_option('evk_t_tls_h');
$evk_f = (int) get_option('evk_t_tls_f');
\Bricks\Database::$active_templates = ['header' => $evk_h, 'footer' => $evk_f, 'content' => 0];
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?>
<style>body{margin:0;font:16px/1.5 sans-serif}main,header,footer{padding:16px}h2,h3{margin:12px 0}</style></head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header id="brx-header"><?php evk_t_tls_rysuj(get_post_meta($evk_h, '_bricks_page_header_2', true)); ?></header>
<main id="brx-content"><?php evk_t_tls_rysuj(get_post_meta(get_queried_object_id(), '_bricks_page_content_2', true)); ?></main>
<footer id="brx-footer"><?php evk_t_tls_rysuj(get_post_meta($evk_f, '_bricks_page_footer_2', true)); ?></footer>
<?php wp_footer(); ?>
</body>
</html>
PHP
    );
    file_put_contents($muPlik, "<?php\n// Wyłącznie test tl-sprawdz (tests/php/tl-sprawdz.php) — usuwany po teście.\n"
        . "if (PHP_SAPI !== 'cli-server') return;\n"
        . "require __DIR__ . '/evk-t-tl-sprawdz/bricks.php';\n");
    $out['mu'] = is_file($muPlik);
    break;

case 'stan':
    $l = (string) ($argv[2] ?? 'A');
    $id = evk_t_tls_wpis($l);
    $el = (string) ($argv[3] ?? '');
    $meta = $l === 'H' ? '_bricks_page_header_2' : ($l === 'F' ? '_bricks_page_footer_2' : $tresc);
    $miejsca = evk_tl_el_miejsca(get_post_meta($id, $meta, true));
    $stan = get_post_meta($id, EVK_TL_EL_STAN, true);
    $out['pola'] = [];
    $out['sprawdzone'] = [];
    foreach ($miejsca as $k => $m) {
        if (strpos($k, $el . '|') !== 0 || $m['jezyk'] !== 'en') continue;
        $out['pola'][$m['pole']] = $m['tlumaczenie'];
        $src = (string) ($stan[$meta][$k]['src'] ?? '');
        $out['sprawdzone'][$m['pole']] = $src === evk_tl_el_skrot($m['oryginal']) ? 'tak' : ($src === '' ? 'brak stanu' : $src);
    }
    $out['dopisane'] = array_keys(array_filter(evk_tl_el_dopisane($id, $meta), static function ($v, $k) use ($el) {
        return strpos((string) $k, $el . '|') === 0;
    }, ARRAY_FILTER_USE_BOTH));
    break;

case 'lista':
    wp_set_current_user((int) get_user_by('login', 'admin')->ID);
    ob_start();
    evk_tl_el_sekcja_do_sprawdzenia();
    $out['html'] = (string) ob_get_clean();
    $out['A'] = evk_t_tls_wpis('A');
    $out['B'] = evk_t_tls_wpis('B');
    break;

case 'odbierz':
    $u = get_user_by('login', (string) ($argv[2] ?? ''));
    if ($u && in_array((int) $u->ID, (array) (evk_t_tls_zapis()['uzytkownicy'] ?? []), true)) $u->remove_cap('evk_access_translations');
    $out['odebrane'] = $u ? !user_can($u, 'evk_access_translations') : false;
    break;

case 'sprzataj':
    $zapis = evk_t_tls_zapis();
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    tl_invalidate_cache();
    foreach ((array) ($zapis['wpisy'] ?? []) as $id) wp_delete_post((int) $id, true);
    if (!empty($zapis['uzytkownicy'])) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($zapis['uzytkownicy'] as $uid) wp_delete_user((int) $uid);
    }
    @unlink($muPlik);
    foreach (['bricks.php', 'szablon.php'] as $f) @unlink($muDir . '/' . $f);
    @rmdir($muDir);
    if (array_key_exists('mu_bylo', $zapis) && !$zapis['mu_bylo'] && is_dir($mu) && count((array) scandir($mu)) === 2) @rmdir($mu);
    @unlink($plik);
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
