<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Dwie kopie Evoke ONE aktywne naraz (1.233.1) na PRAWDZIWYM WordPressie.
 *
 *   php tests/php/zapis-wp-dwie-kopie.php             sonda (rodzic)
 *   php tests/php/zapis-wp-dwie-kopie.php ladowanie   samo wczytanie strony
 *
 * Zgłoszenie z testowa.evoke.pl: po przywróceniu kopii zapasowej aktywne były
 * „evoke-one-main" (z kopii) i kopia z gałęzi roboczej, a strona kończyła się
 * błędem krytycznym „Cannot redeclare function evoke_one_check_conflicts()".
 *
 * Rodzic kładzie w katalogu wtyczek drugą kopię — bieżącą albo 1.233.0 z gita
 * (ostatnią bez strażnika) — ustawia aktywne wtyczki w danej kolejności
 * i wczytuje stronę w OSOBNYM procesie (`ladowanie`): błąd krytyczny kończy
 * dziecko, a rodzic dalej sprząta. Na końcu wszystko wraca.
 */

$krok = $argv[1] ?? '';
/* Plik przywracania dociąga tylko rodzic. Dziecko wczytuje to, co wczytają
   aktywne kopie — dociągnięcie drugiego restore.php obok starej kopii
   wywróciłoby SONDĘ, nie wtyczkę. */
$evk_pliki = $krok === 'ladowanie' ? [] : ['restore' => 'evk_restore_aktywne_wtyczki'];
require __DIR__ . '/_testowy-wp.php';

// ── Dziecko: wczytanie strony z tym, co jest aktywne ────────────────────────
if ($krok === 'ladowanie') {
    wp_set_current_user(1);
    ob_start();
    do_action('admin_notices');
    $komunikaty = (string) ob_get_clean();
    echo "\n" . wp_json_encode([
        'dziala'      => plugin_basename(EVOKE_ONE_FILE),
        'moduly'      => function_exists('evk_nl_zapisz_z_formularza') && function_exists('evk_ip_klienta'),
        'komunikatow' => substr_count($komunikaty, 'data-evk-druga-kopia'),
        'komunikat'   => wp_strip_all_tags((string) (preg_match('#<div[^>]*data-evk-druga-kopia.*?</div>#s', $komunikaty, $m) ? $m[0] : '')),
    ]);
    exit;
}

// ── Rodzic ──────────────────────────────────────────────────────────────────
$out = [];
$repo = getenv('EVK_TEST_ROOT') ?: dirname(__DIR__, 2);
$przed = get_option('active_plugins', []);
$nowa  = WP_PLUGIN_DIR . '/evk-t-nowa-kopia';
$stara = WP_PLUGIN_DIR . '/evk-t-stara-kopia';
$usun = static function (string $d) {
    if (is_dir($d)) exec('rm -rf ' . escapeshellarg($d));
};
register_shutdown_function(static function () use ($przed, $nowa, $stara, $usun) {
    update_option('active_plugins', $przed);
    $usun($nowa);
    $usun($stara);
});

// Bieżąca kopia: plik główny i includes/ z repozytorium (osobne pliki, jak
// dwa katalogi na serwerze — nie dowiązanie).
$usun($nowa);
mkdir($nowa, 0755, true);
copy($repo . '/evoke-one.php', $nowa . '/evoke-one.php');
exec('cp -r ' . escapeshellarg($repo . '/includes') . ' ' . escapeshellarg($nowa . '/'));

/* 1.233.0 — ostatnia wersja bez strażnika i ze starą nazwą funkcji kolizji
   w pliku głównym (commit 9639c1e na gałęzi roboczej). */
$usun($stara);
mkdir($stara, 0755, true);
exec('cd ' . escapeshellarg($repo) . ' && git archive 9639c1e evoke-one.php includes | tar -x -C ' . escapeshellarg($stara) . ' 2>&1', $w, $kod);
$out['stara_wersja'] = is_file($stara . '/evoke-one.php')
    && strpos((string) file_get_contents($stara . '/evoke-one.php'), "define('EVOKE_ONE_VERSION', '1.233.0')") !== false;

$ladowanie = static function (array $aktywne) {
    update_option('active_plugins', $aktywne);
    exec('php ' . escapeshellarg(__FILE__) . ' ladowanie 2>&1', $linie, $kod);
    $tekst = implode("\n", $linie);
    $json = null;
    foreach (array_reverse($linie) as $l) {
        if (strpos(ltrim($l), '{') === 0) { $json = json_decode($l, true); break; }
    }
    return [
        'kod'       => $kod,
        'fatal'     => (bool) preg_match('/Fatal error|Cannot redeclare/i', $tekst),
        'ostrzezenia' => preg_match_all('/Warning:/', $tekst),
        'wynik'     => $json,
        'blad'      => $json ? '' : substr($tekst, 0, 300),
    ];
};

$T = 'evoke-one/evoke-one.php';
$N = 'evk-t-nowa-kopia/evoke-one.php';
$S = 'evk-t-stara-kopia/evoke-one.php';
/* Przywracanie liczone, póki druga kopia LEŻY na dysku: do 1.233.0 z listy
   wypadały tylko wpisy wskazujące brakujący plik, a na testowa.evoke.pl oba
   katalogi istniały. */
$out['po_przywroceniu_istniejaca'] = evk_restore_aktywne_wtyczki(['akismet/akismet.php', $S, $T], $T);
$out['istnieje_przy_liczeniu'] = is_file(WP_PLUGIN_DIR . '/' . $S);

$out['nowa_pierwsza']  = $ladowanie([$N, $T]);
$out['nowa_druga']     = $ladowanie([$T, $N]);
$out['stara_druga']    = $ladowanie([$T, $S]);   // tak było na testowa.evoke.pl: „claude…" przed „main"
$out['stara_pierwsza'] = $ladowanie([$S, $T]);
update_option('active_plugins', $przed);

// ── Przywracanie kopii: jedna aktywna kopia Evoke ONE ───────────────────────
$out['po_przywroceniu'] = evk_restore_aktywne_wtyczki(
    ['akismet/akismet.php', 'evoke-one-main/evoke-one.php', 'evoke-one-claude-x/evoke-one.php', 'evoke-one.php'],
    'evoke-one-claude-x/evoke-one.php'
);
$out['po_przywroceniu_bez_siebie'] = evk_restore_aktywne_wtyczki(['akismet/akismet.php', 'evoke-one-main/evoke-one.php'], 'evoke-one/evoke-one.php');

echo wp_json_encode($out);
