<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Pliki `.min` frontu (1.248.0): wybór pliku w PHP i prawdziwa strona.
 *
 *   php tests/php/minifikacja.php pomocnik          evk_zasob_url() na przypadkach
 *   php tests/php/minifikacja.php debug             to samo przy SCRIPT_DEBUG
 *   php tests/php/minifikacja.php strona-ustaw      testowy WordPress: moduły frontu włączone
 *   php tests/php/minifikacja.php strona-przywroc   opcje z powrotem
 *   php tests/php/minifikacja.php lekser            wstawki bez komentarzy: trudne przypadki (1.249.0)
 *   php tests/php/minifikacja.php fala [debug]      moduł Wave BG z render(), bez komentarzy / przy SCRIPT_DEBUG
 *   php tests/php/minifikacja.php bufor [builder]   stopka z 3 MB danych przez bufor wp_footer (1.249.1)
 */
$tryb = $argv[1] ?? '';

if ($tryb === 'bufor') {
    /* 1.249.0 gubiło CAŁĄ stopkę, gdy jedna wstawka miała od ~1 MB wzwyż:
       wyrażenie regularne przekraczało limit PCRE i dawało pusty tekst.
       Builder Bricksa drukuje w stopce tyle danych — przestał się ładować.
       Tu prawdziwa droga: bufor otwarty i zamknięty tymi funkcjami, które
       wiszą na wp_footer, a w nim 3 MB cudzych danych obok naszej wstawki. */
    define('ABSPATH', '/');
    function add_action(...$a): void {}
    require __DIR__ . '/../../includes/00-context-safety.php';
    require __DIR__ . '/../../includes/02-zasoby-frontu.php';
    if (($argv[2] ?? '') === 'builder') $_GET['bricks'] = 'run';
    $dane    = str_repeat('{"k":"wartość \\/ <b>x</b>"},', 110000);   // ~3 MB jak bricksData
    $cudze   = '<script id="bricks-builder-js-extra">var bricksData = [' . $dane . '0];</script>'
        . "\n<style id=\"cudzy-css\">/* cudzy */ .c{}</style>\n"
        . '<script src="https://example.test/builder.js?ver=1" id="bricks-builder-js"></script>';
    $stopka  = '<script id="evk-a">/* nasz */ a();</script>' . "\n" . $cudze;
    $poziom  = ob_get_level();
    $t0      = microtime(true);
    ob_start();
    evk_wstawki_bufor_start();
    $otwarty = ob_get_level() > $poziom + 1;
    echo $stopka;
    evk_wstawki_bufor_koniec();
    $wynik = (string) ob_get_clean();
    echo json_encode([
        'wej'       => strlen($stopka),
        'wyj'       => strlen($wynik),
        'ms'        => (int) round((microtime(true) - $t0) * 1000),
        'otwarty'   => $otwarty,
        'bez_zmian' => $wynik === $stopka,
        'zgodne'    => $wynik === '<script id="evk-a">a();</script>' . "\n" . $cudze,
    ]);
    exit;
}

if ($tryb === 'fala') {
    if (($argv[2] ?? '') === 'debug') define('SCRIPT_DEBUG', true);
    $argv = [$argv[0], '{}', 'html'];
    $argc = 3;
    require __DIR__ . '/wave-bg-colors.php';
    exit;
}

if ($tryb === 'lekser') {
    define('ABSPATH', '/');
    function add_action(...$a): void {}
    require __DIR__ . '/../../includes/02-zasoby-frontu.php';
    /* Każdy przypadek to coś, co wyrażenie regularne zamiast leksera psuje:
       `//` w adresie, `/*` w łańcuchu i w wyrażeniu regularnym, komentarz
       w `${…}` literału szablonowego (także zagnieżdżonego), `{}` w środku
       `${…}`, nowa linia w miejscu komentarza (średniki wstawiane
       automatycznie), słowo kluczowe przed wyrażeniem regularnym. */
    $js = [
        'łańcuchy'       => "var a = 'http://x.pl'; // k\nvar b = \"/* nie komentarz */\"; /* k */ var c = 'a\\'b//c';",
        'szablon'        => "var s = `a \${ b /* k */ + `c \${ d } // e` } /* f */ g`; // koniec\nx();",
        'regex'          => "var r = /\\/\\/[a-z]*\\/*/g; // k\nvar t = x.replace(/[/*]/, ''); if (a) { y = 1 / 2 / 3; }",
        'nowe linie'     => "a = b\n/* k\nk */\n(c)\nx = 1 // k\n-2",
        'słowa kluczowe' => "function f(){ return /a\\/b/.test(s) } typeof /x/ === 'object'; var q = a++ / 2; var w = (a) / 2 / 3;",
        'nawiasy'        => "var o = { a: `\${ { b: 1 }.b } // nie` }; /* k */ o.a;",
        // Jedyna nowa linia leży w komentarzu — bez niej „x = 1 y = 2" to błąd składni.
        'linia w komentarzu' => "x = 1/* k\n*/y = 2",
    ];
    $wynik = ['js' => [], 'css' => []];
    foreach ($js as $k => $v) $wynik['js'][$k] = [$v, evk_js_bez_komentarzy($v)];
    $wynik['niedomkniety'] = evk_js_bez_komentarzy('var a = 1; /* bez końca') === 'var a = 1; /* bez końca';
    $css = ".a{ color : red; /* k */ background:url('//x.pl/a.png') } /* k */ .b::after{ content:\"/* nie */\" }\n\n.c  .d { margin:0 }";
    $wynik['css']['łańcuchy i adresy'] = [$css, evk_css_bez_komentarzy($css)];
    $html = '<script>/* a */ x();</script><script src="a.js">/* zostaje */</script>'
        . '<script type="application/ld+json">{"a":"/* zostaje */"}</script><style>/* b */ .x{}</style>'
        . '<script type="module">// c' . "\n" . 'y();</script>';
    $wynik['html'] = evk_wstawki_bez_komentarzy($html);
    $wynik['html_nasze'] = evk_wstawki_bez_komentarzy('<script id="evk-a">/* a */ x();</script><script id="cudzy">/* b */ y();</script>'
        . '<style id="evk-b">/* c */ .x{}</style><style>/* d */ .y{}</style>', true);
    echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($tryb === 'pomocnik' || $tryb === 'debug') {
    define('ABSPATH', '/');
    define('EVOKE_ONE_URL', 'https://example.test/wp-content/plugins/evoke-one/');
    if ($tryb === 'debug') define('SCRIPT_DEBUG', true);
    function add_action(...$a): void {}   // 02 wiesza bufor <head> przy wczytaniu
    require __DIR__ . '/../../includes/02-zasoby-frontu.php';
    $u = EVOKE_ONE_URL;
    $przypadki = [
        'js z .min'          => $u . 'assets/js/parallax.js',
        'css elementu z .min' => $u . 'includes/bricks-elements/evoke-offcanvas-menu/assets/offcanvas-menu.css',
        'bez pliku .min'     => $u . 'assets/js/nie-ma-takiego.js',
        'już skrócony'       => $u . 'assets/vendor/gsap/gsap.min.js',
        'obcy adres'         => 'https://cdn.example.test/assets/js/parallax.js',
        'nie js ani css'     => $u . 'assets/img/logo.png',
    ];
    $wynik = [];
    foreach ($przypadki as $nazwa => $url) $wynik[$nazwa] = str_replace($u, '', evk_zasob_url($url));
    echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require __DIR__ . '/_testowy-wp.php';

const EVK_TEST_MIN_KOPIA = 'evk_test_minifikacja_kopia';
/* Moduły, które kolejkują własne pliki JS/CSS na froncie (i kilka obok,
   żeby strona wyglądała jak prawdziwa). Animator potrzebowałby animacji
   w treści, elementy — Bricksa; te są w fixturach. */
const EVK_TEST_MIN_MODULY = ['evk_parallax', 'evk_darkmode', 'evk_cursor', 'evk_lenis', 'evk_bgshift', 'evk_a11y'];

switch ($tryb) {
    case 'strona-ustaw':
        if (get_option(EVK_TEST_MIN_KOPIA, null) === null) {
            $k = [];
            foreach (EVK_TEST_MIN_MODULY as $o) $k[$o] = get_option($o, null);
            update_option(EVK_TEST_MIN_KOPIA, $k, false);
        }
        foreach (EVK_TEST_MIN_MODULY as $o) {
            $v = get_option($o, []);
            update_option($o, array_merge(is_array($v) ? $v : [], ['enabled' => 1]));
        }
        echo json_encode(['wp' => rtrim(ABSPATH, '/')]);
        break;

    case 'strona-przywroc':
        $k = get_option(EVK_TEST_MIN_KOPIA, null);
        if (is_array($k)) {
            foreach ($k as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
            delete_option(EVK_TEST_MIN_KOPIA);
        }
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['brak' => 'nieznane polecenie: ' . $tryb]);
}
