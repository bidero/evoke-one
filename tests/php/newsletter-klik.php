<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Dokąd prowadzi link kliknięcia z newslettera.
 *
 * Do 1.128.0 brak podpisu w linku znaczył „stary link, przepuść po walidacji
 * adresu", więc `?evk_nl=click&evk_nl_token=cokolwiek&url=https://zly-adres/`
 * przekierowywało, gdzie tylko chciał wysyłający — z domeny klienta, więc dla
 * czytającego i dla filtrów pocztowych link wyglądał jak własny. Token
 * subskrybenta niczego nie chronił: przekierowanie działo się poza
 * sprawdzeniem, czy taki subskrybent w ogóle istnieje. To jedyne ustalenie
 * z audytu osiągalne dla kogoś NIEZALOGOWANEGO.
 *
 * Argument 1 wybiera scenariusz:
 *   (brak)          — JSON z wynikami wszystkich kliknięć
 *   widok <status>  — „Zobacz w przeglądarce" dla kampanii o tym statusie.
 *                     KAŻDY status w osobnym procesie, bo handler kończy się
 *                     `exit` i pierwsza pokazana kampania zabiłaby resztę
 *                     przebiegu. Wyjście to albo słowo `odmowa`, albo cała
 *                     wyrenderowana strona.
 *
 * CZEGO TEN TEST NIE SPRAWDZA: nagłówka `Referrer-Policy` (`header()` jest
 * funkcją wbudowaną i w CLI nie zostawia śladu) ani prawdziwego wyszukiwania
 * subskrybenta — `evk_nl_get_subscriber_by_token()` jest tu atrapą, bo pytanie
 * brzmi „dokąd przekierować", a nie „jak działa baza".
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_VERSION', '0.0.0-test');

function wp_salt($scheme = 'auth') { return 'sol-testowa-' . $scheme; }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_bloginfo($co = 'name') { return 'Serwis testowy'; }
function current_time($type = 'mysql') { return '2026-01-01 00:00:00'; }
function wp_rand($min = 0, $max = 0) { return $min; }

/** Odwzorowuje rdzeń: przepuszcza tylko http(s) na publiczny adres. */
function wp_http_validate_url($url) {
    $url = (string) $url;
    if (!preg_match('#^https?://#i', $url)) return false;
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
}
/** Odwzorowuje rdzeń: obcy host (i adres protokołowo-względny) → zapasowy. */
function wp_validate_redirect($cel, $zapasowy = '') {
    $cel = (string) $cel;
    if (strpos($cel, '//') === 0) return $zapasowy;
    if (preg_match('#^https?://#i', $cel)) {
        return strpos($cel, 'https://example.test') === 0 ? $cel : $zapasowy;
    }
    return ($cel !== '' && $cel[0] === '/') ? $cel : $zapasowy;
}

class EVK_Test_Redirect extends Exception {
    public $cel;
    public function __construct($cel) { parent::__construct('redirect'); $this->cel = $cel; }
}
function wp_redirect($cel, $status = 302) { throw new EVK_Test_Redirect($cel); }

/* Newsletter — tyle atrap, ile potrzebuje ścieżka kliknięcia i podglądu. */
$GLOBALS['subskrybent'] = ['id' => 5, 'email' => 'kto@example.test', 'token' => 'tok123', 'fields_json' => '{}'];
$GLOBALS['log']         = [];
$GLOBALS['kampania']    = null;

function evk_nl_get_subscriber_by_token($token) {
    return $token === 'tok123' ? $GLOBALS['subskrybent'] : null;
}
function evk_nl_table($co) { return 'wp_evk_nl_' . $co; }
function evk_nl_log($campaign_id, $event, $sid, $data = []) {
    $GLOBALS['log'][] = ['kampania' => $campaign_id, 'zdarzenie' => $event, 'dane' => $data];
}
function evk_nl_get_campaign($id) { return $GLOBALS['kampania']; }
function evk_nl_get_template($id) {
    return ['id' => 1, 'name' => 'Szablon', 'subject' => 'Temat wysyłki', 'body_html' => '<p>Treść kampanii</p>'];
}
function evk_nl_fields_to_merge_tags($fields) { return []; }
function evk_nl_replace_merge_tags($text, $merge) { return strtr((string) $text, $merge); }
function evk_nl_text($key, $repl = []) { return $key; }

class EVK_Test_Wpdb {
    public $prefix = 'wp_';
    public function prepare($q, ...$a) { return $q; }
    public function get_var($q) { return 0; }
    public function get_row($q, $m = null) { return null; }
    public function query($q) { return 1; }
}
$GLOBALS['wpdb'] = new EVK_Test_Wpdb();

require_once EVK_TEST_ROOT . '/includes/newsletter/tracking.php';

// =========================================================================

$KAMPANIA = 3;
$WEWN     = 'https://example.test/artykul/';
$ZEWN     = 'https://zly-adres.test/phishing';

/** Podpis taki, jaki dokłada `evk_nl_click_url()` przy wysyłce. */
function podpis(string $cel, int $kampania): string {
    return hash_hmac('sha256', $kampania . '|' . $cel, wp_salt('auth'));
}

/** Jedno kliknięcie. Zwraca cel przekierowania i wpisy, które trafiły do logu. */
function klik(string $token, string $url, string $sig = '', int $kampania = 3): array {
    $GLOBALS['log'] = [];
    $_GET = ['url' => $url, 'sig' => $sig];
    try {
        evk_nl_handle_click($token, $kampania);
    } catch (EVK_Test_Redirect $e) {
        return ['cel' => $e->cel, 'log' => $GLOBALS['log']];
    }
    return ['cel' => null, 'log' => $GLOBALS['log']];
}

$scenariusz = $argv[1] ?? '';

if ($scenariusz === 'widok') {
    $GLOBALS['kampania'] = [
        'id' => 3, 'template_id' => 1, 'name' => 'K',
        'status' => $argv[2] ?? 'draft',
    ];
    try {
        evk_nl_handle_view(3, 'tok123');   // przy zgodzie kończy `exit`
    } catch (EVK_Test_Die $e) {
        echo 'odmowa';
    }
    exit;
}

$out = [];

// ── Podpisany link ────────────────────────────────────────────────────────
$out['podpisany_zewn']       = klik('tok123', $ZEWN, podpis($ZEWN, $KAMPANIA));
$out['podpisany_zly_token']  = klik('nie-ma-takiego', $ZEWN, podpis($ZEWN, $KAMPANIA));

// ── Bez podpisu ───────────────────────────────────────────────────────────
// Sedno wydania: adres zewnętrzny bez podpisu nie ma prowadzić do celu.
$out['bez_podpisu_zewn'] = klik('tok123', $ZEWN);
// Stare maile z linkami wewnętrznymi mają dalej działać.
$out['bez_podpisu_wewn'] = klik('tok123', $WEWN);

// ── Podpis, który nie pasuje ──────────────────────────────────────────────
$out['podpis_z_innej_kampanii'] = klik('tok123', $ZEWN, podpis($ZEWN, 99));
$out['podmieniony_cel']         = klik('tok123', 'https://inny-zly.test/x', podpis($ZEWN, $KAMPANIA));
$out['podpis_smieciowy']        = klik('tok123', $ZEWN, 'aaaa');

// ── Adresy, które nie są adresami ─────────────────────────────────────────
$out['javascript'] = klik('tok123', 'javascript:alert(1)');
$out['protokolowo_wzgledny'] = klik('tok123', '//zly-adres.test/x');
$out['pusty'] = klik('tok123', '');

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
