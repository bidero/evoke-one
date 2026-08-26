<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Generyczny zapis ustawień przez AJAX (`wp_ajax_evk_settings_save`).
 *
 * Sedno: wynik ma być IDENTYCZNY z tym, co zapisze zwykły formularz przez
 * `options.php`. To nie jest wymaganie kosmetyczne — options.php JEST drogą
 * zapasową, na którą skrypt spada, gdy AJAX padnie. Gdyby obie drogi zapisywały
 * inaczej, ta sama zakładka dawałaby dwa różne wyniki zależnie od tego, czy
 * akurat zadziałał JavaScript.
 *
 * Sprawdzane są cztery rzeczy, każda okupiona konkretnym ryzykiem:
 *
 * 1. **Zgodność z options.php** — jak wyżej.
 * 2. **Brak pola też jest zapisem.** Odznaczony checkbox nie przychodzi
 *    w żądaniu wcale. Gdyby brak oznaczał „pomiń", nie dałoby się niczego
 *    odznaczyć — zmiana wyglądałaby na zapisaną i wracała po odświeżeniu.
 * 3. **Nazwa nonce'a zgadza się z `settings_fields()`.** Formularz drukuje
 *    `{grupa}-options`; własna nazwa po stronie endpointu oznaczałaby, że
 *    KAŻDY zapis pada w produkcji, a testy dalej świecą na zielono.
 * 4. **Nieznana grupa jest odrzucana.** Biała lista pochodzi z rejestru
 *    WordPressa, nie z naszego spisu.
 */
require __DIR__ . '/_wp-stubs.php';

// Atrapa `check_ajax_referer` zapisuje nazwę nonce'a do $GLOBALS['nonce_asked'] —
// to jest jedno ze sprawdzeń niżej.

function tl_get_active_lang_codes() { return ['pl']; }
function bricks_is_builder_main() { return false; }
/* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. Plik jest liściem: potrzebuje tylko `is_admin()`
   z atrap i niczego więcej. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

require EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require EVK_TEST_ROOT . '/includes/98-accessibility.php';
// Polityka ruchu rejestruje się w TEJ SAMEJ grupie co widżet dostępności,
// a jej jedynym polem jest samotny checkbox. To najostrzejszy przypadek dla
// pętli zapisu: odznaczony, nie przychodzi w żądaniu ŻADNE pole `evk_motion`.
require EVK_TEST_ROOT . '/includes/anim/motion.php';

// `register_setting()` siedzi w module pod `admin_init` — odpalamy hooka,
// żeby biała lista i sanityzatory w ogóle powstały.
foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) { $cb(); }

$handler = null;
foreach ($GLOBALS['hooks']['wp_ajax_evk_settings_save'] ?? [] as $cb) { $handler = $cb; }

/** Woła endpoint i zwraca odpowiedź (wp_send_json_* rzuca wyjątkiem w atrapach). */
function call_save(callable $handler, array $post) {
    $_POST = $post;
    try { $handler(); } catch (EVK_Test_Json $e) { return $e->payload; }
    return null;
}

$out = [];

// ── 1. Zgodność z options.php ─────────────────────────────────────────────
// Ładunek jak z formularza: część pól obecna, część nie.
$payload = [
    'position_side'        => 'left',
    'position_right'       => '30px',
    'enable_high_contrast' => '1',
    'widget_width'         => '480px',
];

$GLOBALS['options']['evk_a11y'] = ['enabled' => 1];
$res = call_save($handler, [
    'option_page' => 'evoke_one_a11y',
    '_wpnonce'    => 'testnonce',
    'evk_a11y'    => $payload,
]);
$via_ajax = $GLOBALS['options']['evk_a11y'];

// Droga formularza: dokładnie to, co robi pętla w options.php.
$GLOBALS['options']['evk_a11y'] = ['enabled' => 1];
update_option('evk_a11y', wp_unslash($payload));
$via_form = $GLOBALS['options']['evk_a11y'];

$out['response']    = $res;
$out['same_result'] = ($via_ajax === $via_form);
$out['saved_side']  = $via_ajax['position_side'] ?? null;
$out['nonce_asked'] = $GLOBALS['nonce_asked'];

// ── 2. Brak pola w żądaniu też musi być zapisem ───────────────────────────
// Stan wyjściowy z WŁĄCZONYM wysokim kontrastem; formularz przychodzi bez
// tego pola, bo checkbox jest odznaczony. Po zapisie ma być wyłączony.
$GLOBALS['options']['evk_a11y'] = ['enabled' => 1, 'enable_high_contrast' => 1];
call_save($handler, [
    'option_page' => 'evoke_one_a11y',
    '_wpnonce'    => 'testnonce',
    'evk_a11y'    => ['position_side' => 'right'],
]);
$out['unchecked_off'] = empty($GLOBALS['options']['evk_a11y']['enable_high_contrast']);

// Cała opcja nieobecna w żądaniu. `evk_motion` ma w formularzu jedno pole —
// odznaczony checkbox nie przysyła nic, więc gdyby brak opcji oznaczał
// „pomiń", tego ustawienia NIE DAŁOBY SIĘ WYŁĄCZYĆ. Wyglądałoby na zapisane
// i wracało po odświeżeniu.
$GLOBALS['options']['evk_motion'] = ['respect_reduced' => 1];
call_save($handler, [
    'option_page' => 'evoke_one_a11y',
    '_wpnonce'    => 'testnonce',
    'evk_a11y'    => ['position_side' => 'right'],
]);
$out['lone_checkbox_off'] = empty($GLOBALS['options']['evk_motion']['respect_reduced']);
// Przełącznik modułu ma przeżyć zapis formularza — steruje nim osobny AJAX.
$out['toggle_kept'] = !empty($GLOBALS['options']['evk_a11y']['enabled']);

// Opcja spoza grupy nie może zostać ruszona przez zapis tej grupy.
$GLOBALS['options']['evk_darkmode'] = ['enabled' => 1, 'wipe_color' => '#000000'];
call_save($handler, [
    'option_page'  => 'evoke_one_a11y',
    '_wpnonce'     => 'testnonce',
    'evk_a11y'     => ['position_side' => 'right'],
    'evk_darkmode' => ['wipe_color' => '#ff0000'],
]);
$out['other_untouched'] = ($GLOBALS['options']['evk_darkmode']['wipe_color'] === '#000000');

// ── 3. Nieznana grupa ─────────────────────────────────────────────────────
$out['unknown_group'] = call_save($handler, [
    'option_page' => 'nie_ma_takiej_grupy',
    '_wpnonce'    => 'testnonce',
]);
$out['no_group'] = call_save($handler, ['_wpnonce' => 'testnonce']);

// ── 4. Grupy w rejestrze ──────────────────────────────────────────────────
$out['groups'] = array_keys($GLOBALS['new_allowed_options']);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
