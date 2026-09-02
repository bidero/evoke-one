<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Snippety jako wpisy — wykonanie, opakowanie i migracja.
 *
 * Treść snippetu JEST WYKONYWANA, więc to jest ten moduł, w którym pomyłka
 * kosztuje najwięcej. Sprawdzamy trzy rzeczy, każdą na prawdziwym kodzie
 * wtyczki, nie na opisie:
 *
 *  · czy rodzaj rozstrzyga, co się z treścią dzieje (CSS ma NIE przechodzić
 *    przez `eval()`, PHP ma działać bez pisania `<?php`);
 *  · czy wpis wyłączony naprawdę nie wchodzi;
 *  · czy migracja czterech starych okien zachowuje ich zachowanie co do znaku.
 *
 *   php tests/php/snippety.php <scenariusz>
 */
require __DIR__ . '/_wp-stubs.php';

define('DAY_IN_SECONDS', 86400);
define('WP_DEBUG', false);

function wp_specialchars_decode($s, $q = null) { return html_entity_decode((string) $s, ENT_QUOTES); }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_get_post_revisions($id, $args = []) { return []; }
/* Rejestracja typu wpisu nas tu nie interesuje — sprawdza ją
   tests/php/odpornosc.php. Przyjmujemy zgłoszenie i idziemy dalej. */
function register_post_type($typ, $args = []) { $GLOBALS['typy'][$typ] = $args; return (object) $args; }
function current_time($t = 'mysql') { return '2027-03-01 10:00:00'; }
$GLOBALS['transients'] = [];

require_once EVK_TEST_ROOT . '/includes/snippets/definitions.php';
require_once EVK_TEST_ROOT . '/includes/snippets/wpisy.php';
require_once EVK_TEST_ROOT . '/includes/snippets/validation.php';
require_once EVK_TEST_ROOT . '/includes/snippets/engine.php';

$GLOBALS['options'][EVK_SNIPPETS_ENABLED_OPTION] = 1;

/** Zakłada wpis i zwraca jego identyfikator. */
function zaloz(array $dane): int {
    return evk_snippet_zapisz_wpis(array_merge(
        ['tytul' => 'Testowy', 'kod' => '', 'rodzaj' => 'php',
         'miejsce' => 'head', 'grupa' => '', 'wlaczony' => 1, 'kolejnosc' => 0],
        $dane));
}

/** Uruchamia zarejestrowane akcje danego haka i zwraca to, co wypisały. */
function odpal_hak(string $hak): string {
    ob_start();
    foreach ($GLOBALS['hooks'][$hak] ?? [] as $cb) $cb();
    return (string) ob_get_clean();
}

/** Przebieg silnika: to samo `init`, które odpala WordPress. */
function przebieg(): void {
    $GLOBALS['hooks']['wp_head'] = [];
    $GLOBALS['hooks']['wp_footer'] = [];
    $GLOBALS['hooks']['admin_head'] = [];
    foreach ($GLOBALS['hooks']['init'] ?? [] as $cb) $cb();
}

$scenariusz = $argv[1] ?? '';
$out = [];

if ($scenariusz === 'opakowanie') {
    $css = zaloz(['rodzaj' => 'css',  'kod' => 'body { color: red }', 'miejsce' => 'head']);
    $js  = zaloz(['rodzaj' => 'js',   'kod' => 'console.log(1)',      'miejsce' => 'footer']);
    $htm = zaloz(['rodzaj' => 'html', 'kod' => '<p>cześć</p>',        'miejsce' => 'head']);
    $php = zaloz(['rodzaj' => 'php',  'kod' => 'echo "z php";',       'miejsce' => 'head']);

    przebieg();
    $out['head']   = odpal_hak('wp_head');
    $out['footer'] = odpal_hak('wp_footer');
    $out['id_css'] = $css;

} elseif ($scenariusz === 'wylaczony') {
    zaloz(['rodzaj' => 'html', 'kod' => '<b>widoczny</b>',  'wlaczony' => 1]);
    zaloz(['rodzaj' => 'html', 'kod' => '<b>ukryty</b>',    'wlaczony' => 0]);
    przebieg();
    $out['head'] = odpal_hak('wp_head');

} elseif ($scenariusz === 'kolejnosc') {
    zaloz(['rodzaj' => 'html', 'kod' => 'C', 'kolejnosc' => 30]);
    zaloz(['rodzaj' => 'html', 'kod' => 'A', 'kolejnosc' => 10]);
    zaloz(['rodzaj' => 'html', 'kod' => 'B', 'kolejnosc' => 20]);
    przebieg();
    $out['head'] = preg_replace('/\s+/', '', odpal_hak('wp_head'));

} elseif ($scenariusz === 'miejsca') {
    zaloz(['rodzaj' => 'html', 'kod' => 'FRONT',  'miejsce' => 'head']);
    zaloz(['rodzaj' => 'html', 'kod' => 'STOPKA', 'miejsce' => 'footer']);
    zaloz(['rodzaj' => 'html', 'kod' => 'PANEL',  'miejsce' => 'admin_head']);
    zaloz(['rodzaj' => 'php',  'kod' => '$GLOBALS["od_razu"] = "TAK";', 'miejsce' => 'init']);
    przebieg();
    $out['head']    = odpal_hak('wp_head');
    $out['footer']  = odpal_hak('wp_footer');
    $out['panel']   = odpal_hak('admin_head');
    $out['od_razu'] = $GLOBALS['od_razu'] ?? '';

} elseif ($scenariusz === 'domkniecie') {
    // Domknięcie znacznika w treści nie ma prawa wyjść z bloku.
    zaloz(['rodzaj' => 'css', 'kod' => 'a{}</style><script>alert(1)</script>']);
    przebieg();
    $out['head'] = odpal_hak('wp_head');

} elseif ($scenariusz === 'migracja') {
    // Cztery stare okna: wpisy o stałych slugach, bez żadnych metadanych.
    foreach (evk_snippets_defs() as $def) {
        $tresc = $def['slug'] === 'evk-snippet-admin-head' ? '' : ('<b>' . $def['slug'] . '</b>');
        wp_insert_post([
            'post_title' => '', 'post_content' => $tresc, 'post_status' => 'private',
            'post_type' => 'evk_code_snippet', 'post_name' => $def['slug'],
        ]);
    }
    evk_snippety_migruj();

    $out['wpisy'] = array_map(function ($w) {
        return ['slug' => $w['slug'], 'rodzaj' => $w['rodzaj'], 'miejsce' => $w['miejsce'],
                'wlaczony' => $w['wlaczony'], 'tytul' => $w['tytul'], 'kod' => $w['kod']];
    }, evk_snippety_wszystkie());
    $out['kopia']  = get_option(EVK_SNIPPETY_KOPIA);
    /* Powtórzona migracja nie ma prawa niczego ruszyć — a „niczego" znaczy
       przede wszystkim: nie ma prawa cofnąć zmian, które wprowadziliście PO
       niej. Liczenie wpisów tego nie łapie (migracja i tak żadnego nie
       zakłada); łapie to dopiero przestawiony rodzaj. */
    $wpisy = evk_snippety_wszystkie();
    $pierwszy = $wpisy[0]['id'];
    evk_snippet_zapisz_wpis(['id' => $pierwszy, 'tytul' => 'Moja nazwa', 'kod' => $wpisy[0]['kod'],
                             'rodzaj' => 'php', 'miejsce' => 'footer', 'grupa' => 'Moja grupa',
                             'wlaczony' => 1, 'kolejnosc' => 5]);
    $ile_przed = count($GLOBALS['posts_store']);
    evk_snippety_migruj();

    $po = evk_snippety_wszystkie();
    $ten = null;
    foreach ($po as $w) if ($w['id'] === $pierwszy) $ten = $w;

    $out['drugi_raz'] = [
        'bez_nowych'   => count($GLOBALS['posts_store']) === $ile_przed,
        'rodzaj'       => $ten['rodzaj']  ?? '',
        'miejsce'      => $ten['miejsce'] ?? '',
        'grupa'        => $ten['grupa']   ?? '',
    ];

} elseif ($scenariusz === 'panel') {
    /* To samo, co scenariusz „miejsca", ale w żądaniu PANELU. Bez tego warunki
       po `is_admin()` w silniku nie mają jak się pomylić — obie strony
       sprawdzenia wyglądają tak samo z frontu. */
    $GLOBALS['is_admin'] = true;
    zaloz(['rodzaj' => 'html', 'kod' => 'FRONT', 'miejsce' => 'head']);
    zaloz(['rodzaj' => 'html', 'kod' => 'PANEL', 'miejsce' => 'admin_head']);
    przebieg();
    $out['panel']       = odpal_hak('admin_head');
    $out['head']        = odpal_hak('wp_head');
    $out['hakow_front'] = count($GLOBALS['hooks']['wp_head'] ?? []);

} elseif ($scenariusz === 'szablon') {
    // Tryb dawny: HTML ze wstawkami PHP — tak działały cztery okna.
    zaloz(['rodzaj' => 'szablon', 'kod' => 'przed <?php echo 2 + 2; ?> po']);
    przebieg();
    $out['head'] = trim(odpal_hak('wp_head'));
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
