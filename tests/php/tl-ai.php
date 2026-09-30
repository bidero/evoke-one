<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tłumaczenie AI tekstów w elementach (1.260.0) — prawdziwy WordPress, dostawcy
 * przez atrapę (tests/php/_ai-atrapa.php, filtr pre_http_request na prawdziwych
 * adresach API).
 *
 *   php tests/php/tl-ai.php wp
 *   php tests/php/tl-ai.php przygotuj                    języki, mapa pól, moduł, ustawienia AI
 *   php tests/php/tl-ai.php strony                       strony A, B, C, D (moduł już wczytany: stan 52 liczy się przy zapisie)
 *   php tests/php/tl-ai.php lista
 *   php tests/php/tl-ai.php krok <dostawca> <jezyk> <A|B|C|D> [scenariusz] [pomin,…] [bez-klucza]
 *   php tests/php/tl-ai.php wyczysc <A> <jezyk> <id>     usuwa pole języka elementu (jak builder) i jego stan
 *   php tests/php/tl-ai.php sprawdzone <A> <klucz>
 *   php tests/php/tl-ai.php ajax-ustawienia <admin|tlumacz>
 *   php tests/php/tl-ai.php ajax-krok <admin|czytelnik> [meta_key] [tryb] [dostawca] [model]
 *   php tests/php/tl-ai.php ponownie <jezyk> <strona> <dostawca> <model> [sprawdzone-w-trakcie] [pomin,…]
 *                                                        przebieg „od nowa” (1.262.0); ustawienia: Claude
 *   php tests/php/tl-ai.php lista-ponownie               lista w trybie ponownym: braki i ile z nich to AI
 *   php tests/php/tl-ai.php bez-pamieci                  czyści pamięć wyników (model odpowie jeszcze raz)
 *   php tests/php/tl-ai.php jeden <strona> <klucz> <dostawca> <model>
 *                                                        „Przetłumacz ponownie” jednego tekstu, bez zapisu
 *   php tests/php/tl-ai.php zakladka                     zakładka z zapisanym kluczem
 *   php tests/php/tl-ai.php mu                           atrapa dostawców jako mu-plugin (panel przez php -S), Gemini
 *   php tests/php/tl-ai.php scenariusz <nazwa>           scenariusz atrapy w serwerze; zeruje dziennik żądań
 *   php tests/php/tl-ai.php zadania                      żądania do dostawców wysłane przez serwer
 *   php tests/php/tl-ai.php pola <A|B|C>                 pola języków strony i liczba miejsc „Do sprawdzenia”
 *   php tests/php/tl-ai.php sprzataj
 *
 * Strona A: nagłówek, tekst z <strong>, przycisk „Kontakt ai-test” (pamięć
 * tłumaczeń z B), nagłówek z samym {post_title}, tekst z {author_name},
 * nagłówek już przetłumaczony, tekst „ZEPSUJ” (strażnik odrzuca), akordeon
 * z dwiema pozycjami, nagłówek „2026” (bez liter).
 * Strona B: przycisk „Kontakt ai-test” ze sprawdzonym EN „Contact us”.
 * Strona C: dwa świeże teksty — do scenariuszy błędów.
 * Strona D: 27 nagłówków i długi tekst (7 tys. znaków) — podział na porcje.
 */
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

const EVK_T_AI_KLUCZ = 'test-klucz-ai-123';
$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-tl-ai.json';
$opcje = ['tl_languages', 'evk_tl_module_enabled', 'evk_tl_el_pola', 'evk_tl_ai', 'evk_tl_ai_pamiec'];
$out   = ['krok' => $krok];
$tresc = '_bricks_page_content_2';
$mu    = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-tl-ai.php';
$scen  = sys_get_temp_dir() . '/evk-t-tl-ai-scenariusz';
$dziennik = sys_get_temp_dir() . '/evk-t-tl-ai-zadania.log';
$raz   = sys_get_temp_dir() . '/evk-t-tl-ai-429-raz';

function evk_t_ai_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}

/** Pola języka z elementów strony: id → pola evk_tl_* (także w pozycjach list). */
function evk_t_ai_pola(int $id): array {
    $out = [];
    foreach ((array) get_post_meta($id, '_bricks_page_content_2', true) as $el) {
        foreach ((array) ($el['settings'] ?? []) as $k => $v) {
            if (strpos((string) $k, 'evk_tl_') === 0) $out[$el['id'] . '|' . $k] = $v;
            if (is_array($v) && $v && array_keys($v) === range(0, count($v) - 1)) {
                foreach ($v as $poz) {
                    foreach ((array) $poz as $pk => $pv) {
                        if (strpos((string) $pk, 'evk_tl_') === 0) $out[$el['id'] . '|' . $k . '.' . ($poz['id'] ?? '') . '.' . $pk] = $pv;
                    }
                }
            }
        }
    }
    ksort($out);
    return $out;
}

/** Ustawienia AI testu: dostawca z kluczem testowym, opis, wskazówki, słowniczek. */
function evk_t_ai_ustaw(string $dostawca, bool $klucz = true): void {
    update_option('evk_tl_ai', [
        'dostawca' => $dostawca,
        'klucze' => $klucz ? ['gemini' => EVK_T_AI_KLUCZ, 'claude' => EVK_T_AI_KLUCZ, 'openai' => EVK_T_AI_KLUCZ] : [],
        'modele' => [],
        'opis' => 'Studio projektowe AI-TEST.',
        'wskazowki' => ['en' => 'British English AI-TEST', 'de' => 'Sie-Form AI-TEST'],
        'slowniczek' => "sklepy | shops | Läden\n!Evoke AI-TEST\n# komentarz AI-TEST",
    ], false);
}

switch ($krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'strony' => [], 'uzytkownicy' => []]));
    }
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    update_option('evk_tl_el_pola', [
        'heading' => ['pola' => ['text'], 'listy' => []],
        'text-basic' => ['pola' => ['text'], 'listy' => []],
        'button' => ['pola' => ['text'], 'listy' => []],
        'accordion' => ['pola' => [], 'listy' => ['items' => ['title', 'content']]],
    ], false);
    delete_option('evk_tl_ai_pamiec');
    evk_t_ai_ustaw('claude');
    $out['gotowe'] = true;
    break;

case 'strony':
    $zapis = evk_t_ai_zapis();
    $a = [
        ['id' => 'h1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Nasze usługi']],
        ['id' => 't1', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => '<p>Projektujemy <strong>strony</strong> i sklepy.</p>']],
        ['id' => 'b1', 'name' => 'button', 'parent' => 0, 'settings' => ['text' => 'Kontakt ai-test']],
        ['id' => 'd1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => '{post_title}']],
        ['id' => 'd2', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => 'Autor: {author_name}']],
        ['id' => 'h2', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Już przetłumaczone', 'evk_tl_en__text' => 'Already translated']],
        ['id' => 'z1', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => '<p>ZEPSUJ <em>to</em></p>']],
        ['id' => 'a1', 'name' => 'accordion', 'parent' => 0, 'settings' => ['items' => [
            ['id' => 'p1', 'title' => 'Pytanie pierwsze', 'content' => '<p>Odpowiedź pierwsza.</p>'],
            ['id' => 'p2', 'title' => 'Pytanie drugie', 'content' => '<p>Odpowiedź druga.</p>'],
        ]]],
        ['id' => 'n1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => '2026']],
    ];
    $b = [['id' => 'k1', 'name' => 'button', 'parent' => 0, 'settings' => ['text' => 'Kontakt ai-test', 'evk_tl_en__text' => 'Contact us']]];
    $c = [
        ['id' => 'c1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Oferta specjalna']],
        ['id' => 'c2', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => 'Zadzwoń do nas dzisiaj.']],
    ];
    /* D: 27 nagłówków i długi tekst na końcu — porcje: 25 (limit liczby),
       2 (długi tekst nie mieści się w limicie znaków), sam długi tekst. */
    $d = [];
    for ($i = 1; $i <= 27; $i++) $d[] = ['id' => 'n' . $i, 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Pozycja ' . $i]];
    $d[] = ['id' => 'dl', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => '<p>' . str_repeat('Długi tekst akapitu. ', 350) . '</p>']];
    $id = [];
    foreach (['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d] as $l => $dane) {
        $id[$l] = (int) wp_insert_post(['post_type' => 'page', 'post_title' => 'Strona AI ' . $l, 'post_status' => 'publish']);
        update_post_meta($id[$l], $tresc, wp_slash($dane));
    }
    $zapis['strony'] = $id;
    file_put_contents($plik, wp_json_encode($zapis));
    $out['strony'] = $id;
    $out['tl'] = function_exists('evk_tl_ai_krok');
    break;

case 'lista':
    $ids = array_flip(array_map('intval', (array) (evk_t_ai_zapis()['strony'] ?? [])));
    $out['lista'] = [];
    foreach (evk_tl_ai_jednostki() as $j) {
        if (isset($ids[$j['post_id']])) $out['lista'][$ids[$j['post_id']]] = $j['braki'];
    }
    ksort($out['lista']);
    break;

case 'krok':
    $strony = (array) (evk_t_ai_zapis()['strony'] ?? []);
    $dostawca = (string) ($argv[2] ?? 'claude');
    $lang = (string) ($argv[3] ?? 'en');
    $id = (int) ($strony[$argv[4] ?? 'A'] ?? 0);
    evk_t_ai_ustaw($dostawca, ($argv[7] ?? '') !== 'bez-klucza');
    $GLOBALS['evk_t_ai_scenariusz'] = (string) ($argv[5] ?? 'ok');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $pomin = array_values(array_filter(explode(',', (string) ($argv[6] ?? ''))));
    $out['wynik'] = evk_tl_ai_krok($id, $tresc, $lang, $pomin);
    $out['zadania'] = $GLOBALS['evk_t_ai_zadania'];
    $out['pola'] = evk_t_ai_pola($id);
    $stan = get_post_meta($id, EVK_TL_EL_STAN, true);
    $out['stan'] = is_array($stan) ? ($stan[$tresc] ?? []) : [];
    $out['dopisane'] = array_keys(evk_tl_el_dopisane($id, $tresc));
    $out['do_sprawdzenia'] = array_values(array_map(static function ($m) { return $m['klucz']; },
        array_filter(evk_tl_el_do_sprawdzenia(1000), static function ($m) use ($id) { return (int) $m['post_id'] === $id; })));
    break;

case 'ponownie':
    $strony = (array) (evk_t_ai_zapis()['strony'] ?? []);
    $lang = (string) ($argv[2] ?? 'en');
    $id = (int) ($strony[$argv[3] ?? 'A'] ?? 0);
    evk_t_ai_ustaw('claude');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $wTrakcie = (string) ($argv[6] ?? '');
    if ($wTrakcie !== '') {
        /* „Sprawdzone” klikane w trakcie zapytania do AI (lista albo okienko). */
        $GLOBALS['evk_t_ai_w_trakcie'] = static function () use ($id, $tresc, $wTrakcie): void {
            evk_tl_el_oznacz_sprawdzone($id, $tresc, $wTrakcie);
        };
    }
    $pomin = array_values(array_filter(explode(',', (string) ($argv[7] ?? ''))));
    $out['wynik'] = evk_tl_ai_krok($id, $tresc, $lang, $pomin,
        ['ponownie' => true, 'dostawca' => (string) ($argv[4] ?? ''), 'model' => (string) ($argv[5] ?? '')]);
    $out['zadania'] = $GLOBALS['evk_t_ai_zadania'];
    $out['pola'] = evk_t_ai_pola($id);
    $stan = get_post_meta($id, EVK_TL_EL_STAN, true);
    $out['stan'] = is_array($stan) ? ($stan[$tresc] ?? []) : [];
    $u = get_option('evk_tl_ai');
    $out['ustawienia'] = ['dostawca' => $u['dostawca'] ?? '', 'modele' => $u['modele'] ?? null];
    $out['do_sprawdzenia'] = array_values(array_map(static function ($m) { return $m['klucz']; },
        array_filter(evk_tl_el_do_sprawdzenia(1000), static function ($m) use ($id) { return (int) $m['post_id'] === $id; })));
    break;

case 'bez-pamieci':
    delete_option('evk_tl_ai_pamiec');
    $out['pamiec'] = get_option('evk_tl_ai_pamiec', null);
    break;

case 'lista-ponownie':
    $ids = array_flip(array_map('intval', (array) (evk_t_ai_zapis()['strony'] ?? [])));
    $out['lista'] = [];
    foreach (evk_tl_ai_jednostki(true) as $j) {
        if (isset($ids[$j['post_id']])) $out['lista'][$ids[$j['post_id']]] = ['braki' => $j['braki'], 'ai' => $j['ai']];
    }
    ksort($out['lista']);
    break;

case 'jeden':
    $id = (int) ((array) (evk_t_ai_zapis()['strony'] ?? []))[$argv[2] ?? 'A'];
    $klucz = (string) ($argv[3] ?? '');
    evk_t_ai_ustaw('claude');
    $lang = (string) (explode('|', $klucz)[2] ?? 'en');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $przed = evk_t_ai_pola($id);
    $out['wynik'] = evk_tl_ai_jeden($id, $tresc, $lang, $klucz,
        evk_tl_ai_na_przebieg(evk_tl_ai_ustawienia(), (string) ($argv[4] ?? ''), (string) ($argv[5] ?? '')));
    $out['zadania'] = $GLOBALS['evk_t_ai_zadania'];
    $out['bez_zapisu'] = $przed === evk_t_ai_pola($id);
    break;

case 'wyczysc':
    $id = (int) ((array) (evk_t_ai_zapis()['strony'] ?? []))[$argv[2] ?? 'A'];
    $lang = (string) ($argv[3] ?? 'en');
    $el = (string) ($argv[4] ?? '');
    /* Jak człowiek: builder otwarty (wykaz dopisanych znika — inaczej hak 53
       wziąłby brak pola za nieaktualny builder i wpisał je z powrotem), pole
       wyczyszczone, zapis. */
    evk_tl_el_builder_otwarty($id);
    $dane = get_post_meta($id, $tresc, true);
    foreach ($dane as $i => $e) {
        if (($e['id'] ?? '') === $el) unset($dane[$i]['settings']['evk_tl_' . $lang . '__text']);
    }
    update_post_meta($id, $tresc, wp_slash($dane));
    $out['pola'] = evk_t_ai_pola($id);
    break;

case 'sprawdzone':
    $id = (int) ((array) (evk_t_ai_zapis()['strony'] ?? []))[$argv[2] ?? 'A'];
    $out['ok'] = evk_tl_el_oznacz_sprawdzone($id, $tresc, (string) ($argv[3] ?? ''));
    $out['do_sprawdzenia'] = array_values(array_map(static function ($m) { return $m['klucz']; },
        array_filter(evk_tl_el_do_sprawdzenia(1000), static function ($m) use ($id) { return (int) $m['post_id'] === $id; })));
    break;

case 'ajax-ustawienia':
case 'ajax-krok':
    $kto = (string) ($argv[2] ?? 'admin');
    if ($kto === 'admin') {
        $u = get_user_by('login', 'admin');
        $uid = $u ? (int) $u->ID : 0;
    } else {
        $login = 'evk-t-ai-' . $kto;
        $u = get_user_by('login', $login);
        $uid = $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(),
            'user_email' => $login . '@example.test', 'role' => $kto === 'tlumacz' ? 'editor' : 'subscriber']);
        get_user_by('id', $uid)->add_cap('evk_access_translations');
        $zapis = evk_t_ai_zapis();
        $zapis['uzytkownicy'] = array_values(array_unique(array_merge($zapis['uzytkownicy'] ?? [], [$uid])));
        file_put_contents($plik, wp_json_encode($zapis));
    }
    wp_set_current_user($uid);
    $strony = (array) (evk_t_ai_zapis()['strony'] ?? []);
    $post = $krok === 'ajax-ustawienia'
        ? ['action' => 'evk_tl_ai_ustawienia', 'dostawca' => 'gemini', 'klucz' => 'nowy-klucz-AJAX-456', 'model' => '',
           'opis' => 'Opis AJAX', 'slowniczek' => '', 'wskazowki' => ['en' => 'EN AJAX']]
        : ['action' => 'evk_tl_ai_krok', 'post_id' => (string) ($strony['C'] ?? 0), 'meta_key' => (string) ($argv[3] ?? $tresc), 'lang' => 'de'];
    if ($krok === 'ajax-krok' && isset($argv[4])) {
        $post += ['tryb' => (string) $argv[4], 'dostawca' => (string) ($argv[5] ?? ''), 'model' => (string) ($argv[6] ?? '')];
    }
    $post['nonce'] = wp_create_nonce('evk_tl_ai');
    $_POST = $_REQUEST = wp_slash($post);
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    $GLOBALS['evk_t_ai_kod'] = 'de';
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($komunikat = '') {
            if (is_scalar($komunikat)) echo $komunikat;
            throw new RuntimeException('koniec');
        };
    });
    ob_start();
    try {
        do_action('wp_ajax_' . $post['action']);
    } catch (RuntimeException $e) {
        // wp_send_json() kończy tu.
    }
    $wyjscie = (string) ob_get_clean();
    $out['odp'] = json_decode($wyjscie, true) ?? $wyjscie;
    $out['surowe'] = $wyjscie;
    $out['zapisane'] = get_option('evk_tl_ai');
    $out['modele'] = array_map(static function ($z) { return $z['url'] . ' ' . (string) ($z['body']['model'] ?? ''); }, $GLOBALS['evk_t_ai_zadania']);
    break;

case 'zakladka':
    wp_set_current_user((int) get_user_by('login', 'admin')->ID);
    evk_t_ai_ustaw('claude');
    ob_start();
    require dirname(__DIR__, 2) . '/includes/admin/tl/tab-ai.php';
    $html = (string) ob_get_clean();
    $out['ma_klucz'] = strpos($html, EVK_T_AI_KLUCZ) !== false;
    $out['zapisany'] = (bool) preg_match('/value="claude"[^>]*data-klucz="1"/', $html);
    $out['nonce'] = strpos($html, 'data-nonce=') !== false;
    break;

case 'mu':
    $zapis = evk_t_ai_zapis();
    if (!array_key_exists('mu_bylo', $zapis)) {
        $zapis['mu_bylo'] = is_dir($mu);
        file_put_contents($plik, wp_json_encode($zapis));
    }
    $bramka = "if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }";
    $atrapa = (string) file_get_contents(__DIR__ . '/_ai-atrapa.php');
    if (strpos($atrapa, $bramka) === false || strpos($atrapa, '<?php') !== 0) { $out['brak'] = 'atrapa bez bramki CLI w pierwszych liniach'; break; }
    $atrapa = str_replace($bramka, "if (!defined('ABSPATH')) exit;", $atrapa);
    /* Atrapa w podkatalogu (WordPress ładuje tylko pliki z korzenia
       mu-plugins), wczytywana warunkowo — tylko w serwerze testowym. Funkcje
       z najwyższego poziomu pliku PHP deklaruje przy kompilacji, więc `return`
       nad nimi nie pomoże: sondy z wiersza poleceń (także innych testów)
       dostałyby drugą kopię funkcji atrapy. */
    if (!is_dir($mu . '/evk-t-tl-ai')) mkdir($mu . '/evk-t-tl-ai', 0755, true);
    file_put_contents($mu . '/evk-t-tl-ai/atrapa.php', $atrapa);
    file_put_contents($muPlik, "<?php\n// Wyłącznie test tl-ai (tests/php/tl-ai.php) — usuwany po teście.\n"
        . "if (PHP_SAPI !== 'cli-server') return;\n"
        . '$GLOBALS[\'evk_t_ai_scenariusz\'] = trim((string) @file_get_contents(' . var_export($scen, true) . ')) ?: \'ok\';' . "\n"
        . '$GLOBALS[\'evk_t_ai_kod\'] = isset($_POST[\'lang\']) && is_string($_POST[\'lang\']) ? $_POST[\'lang\'] : \'en\';' . "\n"
        . 'require __DIR__ . \'/evk-t-tl-ai/atrapa.php\';' . "\n"
        . 'add_action(\'shutdown\', function () { foreach ($GLOBALS[\'evk_t_ai_zadania\'] as $z) file_put_contents('
        . var_export($dziennik, true) . ', $z[\'url\'] . "\\n", FILE_APPEND); });' . "\n");
    evk_t_ai_ustaw('gemini');
    $out['mu'] = is_file($muPlik);
    break;

case 'scenariusz':
    file_put_contents($scen, (string) ($argv[2] ?? 'ok'));
    @unlink($raz);
    @unlink($dziennik);
    $out['scenariusz'] = (string) file_get_contents($scen);
    break;

case 'zadania':
    $out['zadania'] = is_file($dziennik) ? array_values(array_filter(explode("\n", (string) file_get_contents($dziennik)))) : [];
    break;

case 'pola':
    $id = (int) ((array) (evk_t_ai_zapis()['strony'] ?? []))[$argv[2] ?? 'A'];
    $out['pola'] = evk_t_ai_pola($id);
    $out['do_sprawdzenia'] = count(array_filter(evk_tl_el_do_sprawdzenia(1000), static function ($m) use ($id) { return (int) $m['post_id'] === $id; }));
    break;

case 'sprzataj':
    $zapis = evk_t_ai_zapis();
    @unlink($muPlik);
    @unlink($mu . '/evk-t-tl-ai/atrapa.php');
    @rmdir($mu . '/evk-t-tl-ai');
    if (array_key_exists('mu_bylo', $zapis) && !$zapis['mu_bylo'] && is_dir($mu) && count((array) scandir($mu)) === 2) @rmdir($mu);
    foreach ([$scen, $dziennik, $raz] as $f) @unlink($f);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
    if (!empty($zapis['uzytkownicy'])) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($zapis['uzytkownicy'] as $uid) wp_delete_user((int) $uid);
    }
    @unlink($plik);
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
