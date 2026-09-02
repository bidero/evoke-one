<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Co zostaje z pliku SVG, zanim wtyczka wstawi go w treść strony.
 *
 * Do 1.130.0 `tl_get_svg_content()` zdejmowało wyłącznie deklarację XML
 * i doctype — dwa `preg_replace` i nic więcej. Przez takie sito przechodziło
 * `<script>`, `onload=` na `<svg>`, `<foreignObject>` z `<iframe>`
 * i `javascript:` w `href`. Plik z biblioteki mediów stawał się skryptem
 * wykonywanym u każdego odwiedzającego, na każdej podstronie z przełącznikiem
 * języka.
 *
 * TEST CHODZI NA PRAWDZIWYM `wp_kses` (kopia w `tests/php/wp/kses.php`).
 * Atrapa sita bezpieczeństwa nie mówiłaby nic o rzeczywistości: sprawdzałaby,
 * czy moja własna imitacja usuwa to, co sama uznała za groźne.
 */
require __DIR__ . '/_wp-stubs.php';

/* Wersja odpalająca zarejestrowane filtry — sanityzacja rozszerza przez
   `safe_style_css` listę właściwości CSS, które kses przepuszcza w atrybucie
   `style`. Bez tego test sprawdzałby sito z jedną gałęzią wyłączoną. */
function apply_filters($hook, $value) {
    foreach ($GLOBALS['hooks'][$hook] ?? [] as $cb) { $value = $cb($value); }
    return $value;
}

/* Funkcje, których `kses.php` potrzebuje spoza siebie. */
function _deep_replace($search, $subject) {
    $subject = (string) $subject;
    $count = 1;
    while ($count) {
        foreach ((array) $search as $val) { $subject = str_replace($val, '', $subject, $count); }
    }
    return $subject;
}
function wp_allowed_protocols() { return ['http', 'https', 'mailto', 'tel']; }
function _x($s, $c, $d = '') { return $s; }
function wp_parse_str($s, &$a) { parse_str($s, $a); }
function did_action($h) { return 1; }

/* Biblioteka mediów — atrapa oddająca plik przygotowany przez test. */
$GLOBALS['plik'] = ['mime' => 'image/svg+xml', 'sciezka' => ''];
function get_post_mime_type($id) { return $GLOBALS['plik']['mime']; }
function get_attached_file($id) { return $GLOBALS['plik']['sciezka']; }

/* Reszta atrap dla pliku przełącznika języka — ładujemy go w całości, bo
   sanityzacja ma być sprawdzona TAM, GDZIE MIESZKA, a nie skopiowana do testu. */
function get_current_lang() { return 'pl'; }
function tl_get_languages() { return []; }
function tl_get_active_lang_codes() { return ['pl']; }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function get_option_default($k, $d = false) { return $d; }
function wp_get_attachment_url($id) { return 'https://example.test/plik.svg'; }
function is_admin_bar_showing() { return false; }
function shortcode_atts($pairs, $atts, $sc = '') { return array_merge($pairs, (array) $atts); }
function add_shortcode($tag, $cb) {}
function esc_url_raw_default($u) { return $u; }

require_once EVK_TEST_ROOT . '/tests/php/wp/kses.php';
require_once EVK_TEST_ROOT . '/includes/70-bricks-language-switcher.php';

/** Kładzie treść w pliku tymczasowym i puszcza ją przez PRAWDZIWY getter. */
function przez_sito(string $tresc, string $mime = 'image/svg+xml'): string {
    $tmp = tempnam(sys_get_temp_dir(), 'svg');
    file_put_contents($tmp, $tresc);
    $GLOBALS['plik'] = ['mime' => $mime, 'sciezka' => $tmp];
    $wynik = tl_get_svg_content(1);
    unlink($tmp);
    return $wynik;
}

$out = [];

// ── Połowa pierwsza: co ma wylecieć ───────────────────────────────────────
$ZLY = '<?xml version="1.0"?>' . "\n"
     . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
     . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)">'
     . '<script>alert(2)</script>'
     . '<path d="M0 0h24v24H0z" onclick="alert(3)" onmouseover="alert(4)"/>'
     . '<a href="javascript:alert(5)">klik</a>'
     . '<foreignObject><iframe src="https://zly-adres.test/"></iframe></foreignObject>'
     . '<use href="data:text/html;base64,PHNjcmlwdD4="/>'
     . '<set attributeName="href" to="javascript:alert(6)"/>'
     . '<animate attributeName="href" values="javascript:alert(7)"/>'
     . '<image href="x" onerror="alert(8)"/>'
     . '</svg>';

$czysty = przez_sito($ZLY);
$out['zly'] = [
    'wynik'      => $czysty,
    'script'     => stripos($czysty, '<script') !== false,
    'onload'     => stripos($czysty, 'onload') !== false,
    'onclick'    => stripos($czysty, 'onclick') !== false,
    'onmouseover'=> stripos($czysty, 'onmouseover') !== false,
    'onerror'    => stripos($czysty, 'onerror') !== false,
    'javascript' => stripos($czysty, 'javascript:') !== false,
    'iframe'     => stripos($czysty, '<iframe') !== false,
    'foreign'    => stripos($czysty, '<foreignobject') !== false,
    'data_uri'   => stripos($czysty, 'data:') !== false,
    'set'        => stripos($czysty, '<set') !== false,
    'animate'    => stripos($czysty, '<animate') !== false,
    'doctype'    => stripos($czysty, '<!doctype') !== false,
];

// ── Połowa druga: co ma przeżyć ───────────────────────────────────────────
// Ta połowa jest WAŻNIEJSZA. „Naprawa" zwracająca pusty łańcuch przeszłaby
// połowę pierwszą celująco — a flagi zniknęłyby ze wszystkich stron.
$FLAGA = '<?xml version="1.0" encoding="utf-8"?>'
       . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
       . ' viewBox="0 0 640 480" width="24" height="18" role="img" aria-label="Flaga">'
       . '<title>Polska</title>'
       . '<style type="text/css">.st0{fill:#D80027;} .st1{fill:#FFFFFF;}</style>'
       . '<defs><linearGradient id="grad" x1="0" y1="0" x2="1" y2="0">'
       . '<stop offset="0" stop-color="#fff" stop-opacity="0.8"/>'
       . '<stop offset="1" stop-color="#000"/></linearGradient>'
       . '<clipPath id="c"><rect x="0" y="0" width="640" height="480" rx="4"/></clipPath></defs>'
       . '<g clip-path="url(#c)" transform="translate(0,0)">'
       . '<path class="st0" d="M0 240h640v240H0z"/>'
       . '<path class="st1" d="M0 0h640v240H0z" style="fill:#FFFFFF;stroke:#cccccc;stroke-width:2"/>'
       . '<circle cx="320" cy="240" r="40" fill="url(#grad)" opacity="0.5"/>'
       . '<polygon points="0,0 10,0 5,10" fill="#000"/>'
       . '<use xlink:href="#c"/>'
       . '</g></svg>';

$flaga = przez_sito($FLAGA);
$szukaj = [
    'viewBox' => 'viewBox="0 0 640 480"',
    'title'         => '<title>Polska</title>',
    'style_blok'    => '.st0{fill:#D80027;}',
    'lineargradient'=> '<linearGradient',
    'stop_color'    => 'stop-color="#fff"',
    'clippath'      => '<clipPath',
    'grupa'         => 'transform="translate(0,0)"',
    'clip_path_attr'=> 'clip-path="url(#c)"',
    'path_d'        => 'd="M0 240h640v240H0z"',
    'klasa'         => 'class="st0"',
    'style_inline'  => 'fill:#FFFFFF',
    'stroke_inline' => 'stroke-width:2',
    'circle'        => '<circle',
    'fill_url'      => 'fill="url(#grad)"',
    'polygon'       => 'points="0,0 10,0 5,10"',
    'xlink'         => 'xlink:href="#c"',
    'aria'          => 'aria-label="Flaga"',
];
$out['flaga'] = ['wynik' => $flaga];
foreach ($szukaj as $nazwa => $fragment) {
    $out['flaga']['ma'][$nazwa] = strpos($flaga, $fragment) !== false;
}

// ── Wejścia, które nie są SVG-iem ─────────────────────────────────────────
$out['nie_svg']  = przez_sito('<svg><path d="M0 0"/></svg>', 'image/png');
$out['bez_id']   = tl_get_svg_content(0);
$out['pusty']    = przez_sito('');

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
