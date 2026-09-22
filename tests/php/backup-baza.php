<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — zrzut bazy do JSONL na PRAWDZIWYM WordPressie i MariaDB/MySQL.
 *
 * Środowisko stawia `tools/testowy-wp.sh` (katalog w EVK_WP_PATH, domyślnie
 * ~/.cache/evk-testowy-wp). Bez niego sonda oddaje {"brak": "…"} — test
 * zapala się wtedy na czerwono z instrukcją, zamiast po cichu nic nie
 * sprawdzać.
 *
 * Sonda zakłada własne tabele-fikstury (prefiks instalacji + `evk_t_`),
 * zrzuca bazę krok po kroku i porównuje KAŻDY wiersz z pliku z tym, co oddaje
 * SELECT. Fikstury i plik zrzutu sprząta na końcu, także po błędzie.
 *
 *   php tests/php/backup-baza.php <scenariusz>
 *
 * Scenariusze: pelny, ubity, pamiec.
 */

$sciezka_wp = getenv('EVK_WP_PATH') ?: (getenv('HOME') . '/.cache/evk-testowy-wp');
if (!is_file($sciezka_wp . '/wp-load.php') || !is_file($sciezka_wp . '/wp-config.php')) {
    echo json_encode(['brak' => 'Brak testowego WordPressa w ' . $sciezka_wp . ' — uruchom tools/testowy-wp.sh']);
    exit;
}

$_SERVER['HTTP_HOST']   = 'stara.test';
$_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
/* Nie `$wp`: tak nazywa się globalny obiekt WordPressa i nadpisanie go
   wywraca rejestrację taksonomii. */
require $sciezka_wp . '/wp-load.php';

$root = getenv('EVK_TEST_ROOT') ?: dirname(__DIR__, 2);
/* Wtyczka w testowym WordPressie jest dowiązaniem do repozytorium, więc jej
   pliki mogą być już załadowane spod innej ścieżki — require_once by tego nie
   rozpoznał. Pytamy o funkcję, nie o plik. */
foreach (['settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables', 'db-dump' => 'evk_backup_db_dump_step'] as $plik => $fn) {
    if (!function_exists($fn)) require $root . '/includes/backup/' . $plik . '.php';
}

global $wpdb;
$p = $wpdb->prefix;
$T = [
    'mix'   => $p . 'evk_t_mix',
    'comp'  => $p . 'evk_t_comp',
    'nopk'  => $p . 'evk_t_nopk',
    'strpk' => $p . 'evk_t_strpk',
];
$widok = $p . 'evk_t_widok';
/* Pułapka LIKE: `_` w `wp_` pasuje do dowolnego znaku, więc `wpXevk_t_obcy`
   łapie się na `LIKE 'wp_%'`. To tabela innej instalacji w tej samej bazie. */
$obcy  = substr($p, 0, -1) . 'X' . 'evk_t_obcy';
$plik  = sys_get_temp_dir() . '/evk-baza-' . getmypid() . '.jsonl';

$sprzataj = static function () use ($wpdb, $T, $widok, $obcy, $plik) {
    $wpdb->query("DROP VIEW IF EXISTS `$widok`");
    foreach (array_merge(array_values($T), [$obcy]) as $t) $wpdb->query("DROP TABLE IF EXISTS `$t`");
    @unlink($plik);
};
$sprzataj();
register_shutdown_function($sprzataj);

// ── Fikstury ────────────────────────────────────────────────────────────────
$wpdb->query("CREATE TABLE `{$T['mix']}` (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    txt longtext NULL,
    bin blob NULL,
    emoji varchar(100) NULL,
    ser longtext NULL,
    podwojone int GENERATED ALWAYS AS (id * 2) VIRTUAL,
    PRIMARY KEY (id)
) DEFAULT CHARSET=utf8mb4");
mt_srand(11);
for ($i = 1; $i <= 437; $i++) {
    $bin = '';
    for ($k = 0; $k < 40; $k++) $bin .= chr(mt_rand(0, 255));   // prawie na pewno nie UTF-8
    $wpdb->insert($T['mix'], [
        'txt'   => $i % 7 === 0 ? null : ($i % 7 === 1 ? '' : "wiersz $i — zażółć \"cudzysłów\" \\ ukośnik\nnowa linia"),
        'bin'   => $i % 5 === 0 ? null : $bin,
        'emoji' => '🦊 ' . $i,
        'ser'   => serialize(['url' => 'https://stara.test/' . $i, 'n' => $i]),
    ]);
}
// Jeden wiersz bardzo duży — jak układ strony Bricksa w postmeta.
$wpdb->insert($T['mix'], ['txt' => str_repeat('duży wiersz ', 180000), 'emoji' => 'duży']);

$wpdb->query("CREATE TABLE `{$T['comp']}` (a int NOT NULL, b varchar(20) NOT NULL, v text, PRIMARY KEY (a, b)) DEFAULT CHARSET=utf8mb4");
for ($i = 0; $i < 131; $i++) $wpdb->insert($T['comp'], ['a' => intdiv($i, 3), 'b' => 'k' . ($i % 3) . chr(65 + $i % 26), 'v' => "v$i"]);

$wpdb->query("CREATE TABLE `{$T['nopk']}` (x int, y varchar(10)) DEFAULT CHARSET=utf8mb4");
for ($i = 0; $i < 123; $i++) $wpdb->insert($T['nopk'], ['x' => $i % 10, 'y' => "y$i"]);

/* Klucz tekstowy: porządek i porównanie „>" idą tą samą kolacją, więc
   stronicowanie po kluczu musi zadziałać także na polskich literach. */
$wpdb->query("CREATE TABLE `{$T['strpk']}` (k varchar(40) NOT NULL, v int, PRIMARY KEY (k)) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_520_ci");
$slowa = ['ala', 'Ąka', 'bąk', 'Ćma', 'dąb', 'Ełk', 'źdźbło', 'żaba', 'Zebra', 'łoś', 'Łódź', 'ósemka'];
$n = 0;
foreach ($slowa as $s) for ($j = 0; $j < 6; $j++) $wpdb->insert($T['strpk'], ['k' => $s . '-' . $j, 'v' => $n++]);

$wpdb->query("CREATE VIEW `$widok` AS SELECT id, emoji FROM `{$T['mix']}`");
$wpdb->query("CREATE TABLE `$obcy` (id int PRIMARY KEY)");
$wpdb->query("INSERT INTO `$obcy` VALUES (1)");
evk_backup_create_tables();   // tabela zadań istnieje — i ma się NIE znaleźć w zrzucie

// ── Pomocnicze ──────────────────────────────────────────────────────────────

/** Czyta plik zrzutu: nagłówki i zdekodowane wiersze, per tabela. */
function czytaj(string $plik): array {
    $wynik = ['naglowki' => [], 'wiersze' => [], 'zle_linie' => 0, 'b64' => 0, 'kolejnosc_zla' => [], 'linii' => 0];
    $fh = fopen($plik, 'rb');
    while (($l = fgets($fh)) !== false) {
        $wynik['linii']++;
        $j = json_decode($l, true);
        if (!is_array($j) || !isset($j['table'])) { $wynik['zle_linie']++; continue; }
        $t = $j['table'];
        if (isset($j['create'])) {
            if (isset($wynik['naglowki'][$t])) $wynik['kolejnosc_zla'][] = $t . ': nagłówek drugi raz';
            $wynik['naglowki'][$t] = $j;
            continue;
        }
        if (!isset($wynik['naglowki'][$t])) $wynik['kolejnosc_zla'][] = $t . ': wiersz przed nagłówkiem';
        $w = $j['row'];
        foreach ($j['b64'] ?? [] as $k) { $w[$k] = base64_decode($w[$k]); $wynik['b64']++; }
        $wynik['wiersze'][$t][] = $w;
    }
    fclose($fh);
    return $wynik;
}

/** Porównanie zrzutu z bazą dla jednej tabeli. Bez klucza — jako multizbiór. */
function porownaj(string $t, array $zrzut, array $kolumny, array $pk): array {
    global $wpdb;
    $lista = implode(', ', array_map('evk_backup_db_ident', $kolumny));
    $order = $pk ? ' ORDER BY ' . implode(', ', array_map('evk_backup_db_ident', $pk)) : '';
    $baza  = (array) $wpdb->get_results("SELECT $lista FROM " . evk_backup_db_ident($t) . $order, ARRAY_A);
    if (!$pk) {
        $klucz = static function ($w) { return serialize($w); };
        $a = array_map($klucz, $baza);  sort($a);
        $b = array_map($klucz, $zrzut); sort($b);
        $rowne = $a === $b;
    } else {
        $rowne = $baza === $zrzut;   // === : ta sama kolejność, typy, NULL ≠ ''
    }
    return ['w_bazie' => count($baza), 'w_zrzucie' => count($zrzut), 'rowne' => $rowne];
}

/** Zrzut do końca, krok po kroku — każdy krok to nowe „żądanie" ze stanu. */
function do_konca(string $plik, array $st, int $paczka, int &$krokow): array {
    while (!$st['done']) {
        $st = evk_backup_db_dump_step($plik, $st, 0.0, $paczka);   // termin minął: jedna paczka
        $krokow++;
        if ($krokow > 100000) throw new RuntimeException('zrzut się nie kończy');
    }
    return $st;
}

/** Fakty o zrzucie, wspólne dla scenariuszy. */
function fakty(string $plik, array $st, array $T, string $widok, string $obcy): array {
    $czyt = czytaj($plik);
    $tabele = [];
    foreach ($st['tables'] as $t) {
        $h = $czyt['naglowki'][$t] ?? null;
        $tabele[$t] = $h ? porownaj($t, $czyt['wiersze'][$t] ?? [], $h['cols'], $h['pk']) : ['brak_naglowka' => true];
    }
    $mix = $czyt['naglowki'][$T['mix']] ?? [];
    return [
        'tabele'          => $tabele,
        'fikstury'        => array_values($T),
        'w_stanie'        => $st['tables'],
        'pominiete'       => $st['skipped'],
        'widok'           => $widok,
        'obcy_w_zrzucie'  => isset($czyt['naglowki'][$obcy]),
        'zadania_w_zrzucie' => isset($czyt['naglowki'][evk_backup_jobs_table()]),
        'generowane'      => $mix['generated'] ?? [],
        'generowana_w_wierszach' => isset(($czyt['wiersze'][$T['mix']][0] ?? [])['podwojone']),
        'b64'             => $czyt['b64'],
        'zle_linie'       => $czyt['zle_linie'],
        'kolejnosc_zla'   => $czyt['kolejnosc_zla'],
        'pk'              => ['mix' => $mix['pk'] ?? null, 'comp' => $czyt['naglowki'][$T['comp']]['pk'] ?? null,
                              'nopk' => $czyt['naglowki'][$T['nopk']]['pk'] ?? null],
        'create_ma_nazwe' => strpos($mix['create'] ?? '', $T['mix']) !== false,
        'wierszy_stan'    => $st['rows'],
        'linii'           => $czyt['linii'],
        'szacunek'        => $st['estimate'],
    ];
}

// ── Scenariusze ─────────────────────────────────────────────────────────────
$scen = $argv[1] ?? '';
$wynik = [];

switch ($scen) {
    case 'pelny':
        $krokow = 0;
        $st = do_konca($plik, evk_backup_db_dump_start(), 50, $krokow);
        $wynik = fakty($plik, $st, $T, $widok, $obcy) + ['krokow' => $krokow];
        break;

    case 'ubity':
        /* Kilka kroków, zapamiętany stan, potem praca, której stan „przepadł"
           (krok ubity przed zapisem), i śmieci na końcu pliku. Wznowienie ze
           starego stanu ma dać dokładnie ten sam zrzut — bez duplikatów. */
        $st = evk_backup_db_dump_start();
        for ($i = 0; $i < 7; $i++) $st = evk_backup_db_dump_step($plik, $st, 0.0, 50);
        $zapisany = $st;
        for ($i = 0; $i < 5; $i++) $st = evk_backup_db_dump_step($plik, $st, 0.0, 50);
        file_put_contents($plik, "{\"table\":\"śmieci\",\"row\":{\"urwany", FILE_APPEND);
        $wynik['przed_wznowieniem'] = filesize($plik);
        $wynik['stan_bytes'] = $zapisany['bytes'];
        $krokow = 0;
        $st = do_konca($plik, $zapisany, 50, $krokow);
        $wynik += fakty($plik, $st, $T, $widok, $obcy);
        break;

    case 'pamiec':
        /* Tabela z dużymi wierszami (1 MB) po tysiącu drobnych: paczka musi
           się zmniejszyć PRZED pobraniem dużych, a nie po. */
        $duza = $p . 'evk_t_duza';
        $wpdb->query("DROP TABLE IF EXISTS `$duza`");
        register_shutdown_function(static function () use ($wpdb, $duza) { $wpdb->query("DROP TABLE IF EXISTS `$duza`"); });
        $wpdb->query("CREATE TABLE `$duza` (id int NOT NULL AUTO_INCREMENT, v longtext, PRIMARY KEY (id))");
        for ($i = 0; $i < 1000; $i++) $wpdb->insert($duza, ['v' => 'drobny ' . $i]);
        $mb = str_repeat('0123456789abcdef', 65536);
        for ($i = 0; $i < 60; $i++) $wpdb->insert($duza, ['v' => $mb]);
        $st = ['tables' => [$duza], 'skipped' => [], 'estimate' => 0, 'ti' => 0, 'cur' => null, 'bytes' => 0, 'rows' => 0, 'done' => false];
        $przed = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $st = evk_backup_db_dump_step($plik, $st, microtime(true) + 600);
        $wynik = [
            'wierszy'        => $st['rows'],
            'szczyt_mb'      => round((memory_get_peak_usage() - $przed) / 1048576, 1),
            'reset_szczytu'  => function_exists('memory_reset_peak_usage'),
            'paczka_mb'      => EVK_BACKUP_DUMP_BYTES / 1048576,
        ];
        break;

    default:
        fwrite(STDERR, "Nieznany scenariusz: $scen\n");
        exit(2);
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
