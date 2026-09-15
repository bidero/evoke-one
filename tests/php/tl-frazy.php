<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Co przeżywa drogę: pole w panelu → opcja → `{tl_...}` na stronie.
 *
 * ZGŁOSZONE Z UŻYCIA: „moduł tłumaczeń nie wyświetla poprawnie elementów
 * z zapisanym <br> przy użyciu {tl_...}. Pomija <br>".
 *
 * CHODZI NA PRAWDZIWYM `wp_kses` (kopia WordPressa w `tests/php/wp/kses.php`,
 * patrz README obok). Atrapa sita jest tu bezwartościowa z definicji: pytanie
 * brzmi „co sito przepuszcza", więc sito udawane odpowiadałoby na nie samo
 * sobie. Ta sama zasada, na której stoi `tests/php/svg-sanityzacja.php`.
 *
 * Wypisuje JSON: wynik sanityzacji dla zestawu przypadków oraz to, co wychodzi
 * z `{tl_...}` i ze skrótkodu `[tl]` po przejściu PEŁNEJ drogi zapisu.
 */
require __DIR__ . '/_wp-stubs.php';

function tl_get_active_lang_codes() { return ['en']; }
function bricks_is_builder_main() { return false; }
function get_current_lang() { return 'pl'; }
function add_shortcode($tag, $cb) {}
function wp_rand($min = 0, $max = 0) { return 1234; }

/* Pamięć podręczna — sonda ma czytać opcje, nie transienty. */
function get_transient($k) { return false; }
function set_transient($k, $v, $t = 0) { return true; }
function delete_transient($k) { return true; }
define('TL_TRANSIENT_CONFIG', 'c');
define('TL_TRANSIENT_INLINE', 'i');
define('TL_TRANSIENT_SLUGS',  's');
define('TL_TRANSIENT_TOKENS', 't');
define('TL_CACHE_TTL', 60);

/* Funkcje, których `kses.php` potrzebuje spoza siebie — jak w svg-sanityzacja.php. */
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
function apply_filters($hook, $value) { return $value; }

require_once EVK_TEST_ROOT . '/tests/php/wp/kses.php';
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';
require EVK_TEST_ROOT . '/includes/20-helpers-cache-inline.php';
require EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require EVK_TEST_ROOT . '/includes/40-dynamic-data-shortcode.php';

/* Przypadki: co ma przejść, co ma wylecieć. Klucz jest nazwą sprawdzenia. */
$proby = [
    'br'          => 'Pierwsza linia<br>druga linia',
    'br_xhtml'    => 'Pierwsza<br />druga',
    'wyroznienie' => 'Tekst z <strong>wyróżnieniem</strong>',
    'odnosnik'    => 'Zobacz <a href="https://evoke.pl">stronę</a>',
    'span_klasa'  => 'Słowo <span class="akcent">wyróżnione</span>',
    'bez_znacznikow' => 'Zwykły tekst bez niczego',
    'skrypt'      => 'Zło <script>alert(1)</script> koniec',
    'blok'        => 'Tekst <div>w bloku</div> koniec',
    'zdarzenie'   => 'Klik <span onclick="zle()">tu</span>',
    'iframe'      => 'Ramka <iframe src="//zle"></iframe> koniec',
];

$sanityzacja = [];
foreach ($proby as $nazwa => $wejscie) {
    $sanityzacja[$nazwa] = tl_sanitize_phrase($wejscie);
}

/* PEŁNA droga zapisu — ta sama, którą jedzie panel Tłumaczeń. */
$payload = ['groups' => ['g1' => ['name' => 'Grupa', 'rows' => [
    'r1' => [
        'pl'     => 'Pierwsza linia<br>druga linia',
        'dd_key' => 'naglowek',
        'en'     => 'First line<br>second line',
    ],
]]]];

$czyste  = tl_sanitize_translations_payload($payload);
$dd_keys = tl_rebuild_dd_keys_from_rows($czyste, []);
$GLOBALS['options']['tl_dd_keys']     = $dd_keys;
$GLOBALS['options']['tl_translations'] = $czyste;

echo json_encode([
    'sanityzacja' => $sanityzacja,
    // Co naprawdę wylądowało w opcjach — bo tam usterka siedziała.
    'w_bazie_pl'  => $czyste['groups']['g1']['rows']['r1']['pl'],
    'w_bazie_en'  => $czyste['groups']['g1']['rows']['r1']['en'],
    'w_dd_keys'   => $dd_keys['naglowek'] ?? '',
    // I co z tego wychodzi na stronę dwiema drogami.
    'dd_tag'      => tl_render_dd_tags_in_content('{tl_naglowek}'),
    'skrotkod'    => tl_replace_tl_tags_in_html('<p>[tl key="naglowek"]</p>'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
