<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Akcja „Newsletter (Evoke ONE)" formularza Bricksa (includes/newsletter/bricks.php)
 * na PRAWDZIWYM WordPressie.
 *
 *   php tests/php/newsletter-bricks.php <scenariusz>
 *
 * Scenariusze:
 *   kontrolki  — Bricks 2.1: akcja i ustawienia dokładane filtrami z dokumentacji,
 *   stary      — Bricks 1.12.1: nic (własne akcje są od 1.12.2),
 *   akcja      — obsługa po wysłaniu, z atrapą obiektu formularza.
 *
 * BRICKSA TU NIE MA. Atrapa formularza ma wyłącznie metody z dokumentacji
 * (get_settings, get_fields, set_result) i wartości w kształcie z przykładu
 * w Bricks Academy (`form-field-{id}`, `formId`, `postId`). To, czy builder
 * przyjmuje kontrolki i jak pokazuje błąd, sprawdza się na stronie.
 */

$scenariusz = $argv[1] ?? '';
define('BRICKS_VERSION', $scenariusz === 'stary' ? '1.12.1' : '2.1');

require __DIR__ . '/_testowy-wp.php';

$out = ['scenariusz' => $scenariusz];
$sprzatanie = [];
register_shutdown_function(static function () use (&$sprzatanie) {
    foreach (array_reverse($sprzatanie) as $f) { try { $f(); } catch (Throwable $e) {} }
});
wp_set_current_user(1);

foreach (['evk_newsletter', 'evk_nl_zablokowane'] as $opcja) {
    $przed = get_option($opcja, null);
    $sprzatanie[] = static function () use ($opcja, $przed) {
        $przed === null ? delete_option($opcja) : update_option($opcja, $przed);
    };
}
update_option('evk_newsletter', array_merge((array) get_option('evk_newsletter', []), ['enabled' => 1]));
evk_nl_create_tables();
$lista = (int) evk_nl_create_list('Sonda Bricks ' . wp_rand());
$sprzatanie[] = static function () use ($lista) { evk_nl_delete_list($lista); };

/** Atrapa obiektu formularza Bricksa — tylko udokumentowane metody. */
class EVK_T_Formularz_Bricks {
    public $wyniki = [];
    private $ust;
    private $pola;
    public function __construct(array $ust, array $pola) { $this->ust = $ust; $this->pola = $pola; }
    public function get_settings() { return $this->ust; }
    public function get_fields() { return $this->pola; }
    public function set_result($wynik) { $this->wyniki[] = $wynik; }
}

/* Maile przechwycone przed wysyłką — transport sprawdza newsletter-wysylka. */
$GLOBALS['maile'] = [];
add_filter('pre_wp_mail', static function ($r, $atts) { $GLOBALS['maile'][] = $atts; return true; }, 10, 2);

/** Formularz jak z buildera: imię, e-mail, checkbox zgody, firma. */
function evk_t_ustawienia(array $akcja): array {
    return $akcja + [
        'fields' => [
            ['id' => 'imie01', 'type' => 'text', 'label' => 'Imię'],
            ['id' => 'mail01', 'type' => 'email', 'label' => 'E-mail'],
            ['id' => 'zgod01', 'type' => 'checkbox', 'label' => 'Newsletter', 'options' => "tak : Chcę dostawać newsletter"],
            ['id' => 'firm01', 'type' => 'text', 'label' => 'Nazwa firmy'],
            ['id' => 'podst1', 'type' => 'text', 'label' => '_consent_ip'],
        ],
        'actions' => ['email', EVK_NL_BRICKS_AKCJA],
    ];
}

/** Jedno wysłanie formularza: wyniki set_result, maile, stan subskrybenta. */
function evk_t_wyslij(array $akcja, array $pola): array {
    $GLOBALS['maile'] = [];
    $email = '';
    foreach ($pola as $k => $v) { if (is_string($v) && strpos($v, '@') !== false) $email = $v; }
    /* Limity z poprzednich przebiegów zostają w bazie: na adres IP i na maile
       z potwierdzeniem do jednego adresu (3 na dobę). */
    delete_transient('evk_nl_rl_' . md5(evk_nl_client_ip()));
    if ($email !== '') delete_transient('evk_nl_pt_' . md5(strtolower($email)));
    $form = new EVK_T_Formularz_Bricks(evk_t_ustawienia($akcja), $pola + ['formId' => 'abcdef', 'postId' => 12]);
    do_action('bricks/form/action/' . EVK_NL_BRICKS_AKCJA, $form);
    global $wpdb;
    $sub = $email === '' ? null : $wpdb->get_row($wpdb->prepare(
        'SELECT status, fields_json FROM ' . evk_nl_table('subscribers') . ' WHERE email=%s ORDER BY id DESC LIMIT 1', $email), ARRAY_A);
    return [
        'wyniki' => $form->wyniki,
        'maile'  => array_map(static function ($m) {
            return ['do' => $m['to'], 'link' => (bool) preg_match('#evk_nl=confirm|/nl/confirm/#', (string) $m['message'])];
        }, $GLOBALS['maile']),
        'status' => $sub ? (int) $sub['status'] : null,
        'pola'   => $sub ? (json_decode((string) $sub['fields_json'], true) ?: []) : null,
    ];
}

switch ($scenariusz) {

case 'kontrolki':
case 'stary':
    $kontrolki = apply_filters('bricks/elements/form/controls', [
        'actions' => ['label' => 'Actions', 'type' => 'select', 'multiple' => true,
                      'options' => ['email' => 'Email', 'redirect' => 'Redirect']],
    ]);
    $grupy = apply_filters('bricks/elements/form/control_groups', ['email' => ['title' => 'Email']]);
    $out['opcje_akcji'] = $kontrolki['actions']['options'] ?? null;
    $out['kontrolki'] = array_map(static function ($k) {
        return array_intersect_key($k, array_flip(['group', 'type', 'multiple', 'map_fields', 'options']));
    }, array_diff_key($kontrolki, ['actions' => 1]));
    $out['grupy'] = $grupy;
    $out['lista'] = (string) $lista;
    break;

case 'akcja':
    $baza = ['evkNlLista' => $lista, 'evkNlPola' => ['imie01', 'firm01', 'podst1']];

    // Osobny formularz zapisu: bez pola zgody, bez wskazanego pola e-mail.
    $out['osobny'] = evk_t_wyslij($baza, ['form-field-imie01' => 'Ola', 'form-field-mail01' => 'ola.bricks@example.com',
        'form-field-firm01' => 'Firma Sp. z o.o.', 'form-field-podst1' => '6.6.6.6']);

    // Kontakt z „Zapisz mnie": checkbox NIE zaznaczony (Bricks go nie wysyła).
    $kontakt = $baza + ['evkNlZgoda' => 'zgod01', 'evkNlEmail' => 'mail01'];
    $out['kontakt_bez_zgody'] = evk_t_wyslij($kontakt, ['form-field-mail01' => 'kontakt.bez@example.com']);
    // …i zaznaczony (wartość jako tablica, jak z checkboxów).
    $out['kontakt_ze_zgoda'] = evk_t_wyslij($kontakt, ['form-field-mail01' => 'kontakt.ze@example.com',
        'form-field-zgod01' => ['tak'], 'form-field-imie01' => 'Jan']);
    // Pole wskazane z przedrostkiem form-field- (tak, jak wartości przychodzą).
    $out['przedrostek'] = evk_t_wyslij(['evkNlLista' => $lista, 'evkNlEmail' => 'form-field-mail01'],
        ['form-field-mail01' => 'przedrostek@example.com']);

    // Bez potwierdzenia mailem.
    $out['bez_potwierdzenia'] = evk_t_wyslij($baza + ['evkNlBezPotwierdzenia' => true],
        ['form-field-mail01' => 'od.razu@example.com']);

    // Błędy: brak adresu, zła lista, moduł wyłączony.
    $out['brak_adresu'] = evk_t_wyslij($baza, ['form-field-imie01' => 'Bez adresu']);
    $out['zla_lista'] = evk_t_wyslij(['evkNlLista' => 999999], ['form-field-mail01' => 'zla.lista@example.com']);
    update_option('evk_newsletter', array_merge((array) get_option('evk_newsletter', []), ['enabled' => 0]));
    $out['wylaczony'] = evk_t_wyslij($baza, ['form-field-mail01' => 'wylaczony@example.com']);
    break;

default:
    $out['blad'] = 'nieznany scenariusz';
}

echo wp_json_encode($out);
