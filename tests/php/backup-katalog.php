<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — katalog kopii, jego ochrona, sprawdzenie „kanarkiem" i manifest,
 * na PRAWDZIWYM WordPressie (tools/testowy-wp.sh).
 *
 *   php tests/php/backup-katalog.php <scenariusz>
 *
 * Scenariusze: katalog, kanarek, manifest.
 *
 * `kanarek` stawia prawdziwe serwery HTTP (php -S) na wolnych portach:
 *   - zwykły, który .htaccess ignoruje (jak nginx)  → katalog odsłonięty,
 *   - z routerem odpowiadającym 403 (jak Apache z działającym .htaccess),
 *   - zamknięty port → wyniku nie da się ustalić.
 * Wszystko w sys_get_temp_dir(), sprzątane na końcu.
 */

$evk_pliki = ['settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables',
              'storage' => 'evk_backup_dir', 'manifest' => 'evk_backup_manifest',
              'file-collector' => 'evk_backup_excluded'];
require __DIR__ . '/_testowy-wp.php';

$tmp = sys_get_temp_dir() . '/evk-katalog-' . getmypid();
@mkdir($tmp, 0700, true);
$procesy = [];
register_shutdown_function(static function () use ($tmp, &$procesy) {
    foreach ($procesy as $p) { @proc_terminate($p); @proc_close($p); }
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

/** Wolny port: gniazdo na porcie 0, system podaje numer, zamykamy. */
function wolny_port(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $n = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) strrchr((string) $n, ':'), 1);
}

/** php -S w tle; czeka, aż port przyjmuje połączenia. */
function serwer(string $docroot, ?string $router, array &$procesy): int {
    $port = wolny_port();
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot];
    if ($router) $cmd[] = $router;
    $p = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rury);
    $procesy[] = $p;
    for ($i = 0; $i < 60; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.1);
        if ($c) { fclose($c); return $port; }
        usleep(50000);
    }
    throw new RuntimeException('php -S nie wstał na porcie ' . $port);
}

$scen = $argv[1] ?? '';
$wynik = [];

switch ($scen) {
    case 'katalog':
        delete_option(EVK_BACKUP_DIR_OPTION);
        $a = evk_backup_dir_suffix();
        $b = evk_backup_dir_suffix();
        $dir = evk_backup_dir();
        $wynik['sufiks'] = $a;
        $wynik['sufiks_staly'] = $a === $b;
        $wynik['sufiks_format'] = (bool) preg_match('/^[a-f0-9]{20}$/', $a);
        // Nowy sufiks po usunięciu opcji — dwie instalacje nie dzielą adresu.
        delete_option(EVK_BACKUP_DIR_OPTION);
        $wynik['sufiks_inny_po_usunieciu'] = evk_backup_dir_suffix() !== $a;
        $dir = evk_backup_dir();

        $wynik['utworzony'] = evk_backup_ensure_dir($dir);
        $wynik['pliki'] = [];
        foreach (evk_backup_protection_files() as $n => $t) {
            $wynik['pliki'][$n] = is_file("$dir/$n") && file_get_contents("$dir/$n") === $t;
        }
        $ht = (string) @file_get_contents("$dir/.htaccess");
        $wynik['htaccess_obie_skladnie'] = strpos($ht, 'Require all denied') !== false && strpos($ht, 'Deny from all') !== false;
        // Ręczna zmiana w .htaccess przeżywa ponowne ensure.
        file_put_contents("$dir/.htaccess", $ht . "# dopisane ręcznie\n");
        evk_backup_ensure_dir($dir);
        $wynik['htaccess_nie_nadpisany'] = strpos((string) file_get_contents("$dir/.htaccess"), '# dopisane ręcznie') !== false;

        // Twarde wykluczenie łapie i katalog kopii, i katalog importu.
        $wynik['wykluczony_katalog'] = evk_backup_excluded(basename($dir), true, evk_backup_hard_exclusions());
        $wynik['wykluczony_import']  = evk_backup_excluded(basename(evk_backup_import_dir()), true, evk_backup_hard_exclusions());
        $wynik['w_wp_content'] = dirname($dir) === WP_CONTENT_DIR;

        foreach (array_keys(evk_backup_protection_files()) as $n) @unlink("$dir/$n");
        @rmdir($dir);
        break;

    case 'kanarek':
        $dir = "$tmp/www/kopie";
        @mkdir($dir, 0700, true);
        // 1) serwer bez obsługi .htaccess — jak nginx
        $p1 = serwer("$tmp/www", null, $procesy);
        $wynik['bez_htaccess'] = evk_backup_exposure_check($dir, "http://127.0.0.1:$p1/kopie");
        // 2) serwer odmawiający dostępu — jak Apache/LiteSpeed z działającym .htaccess
        file_put_contents("$tmp/router403.php", "<?php http_response_code(403); echo 'Forbidden';");
        $p2 = serwer("$tmp/www", "$tmp/router403.php", $procesy);
        $wynik['z_htaccess'] = evk_backup_exposure_check($dir, "http://127.0.0.1:$p2/kopie");
        // 3) nic nie słucha
        $p3 = wolny_port();
        $wynik['bez_serwera'] = evk_backup_exposure_check($dir, "http://127.0.0.1:$p3/kopie");
        // 4) serwer odpowiada 200, ale czymś innym niż kanarek (np. strona błędu)
        file_put_contents("$tmp/router200.php", "<?php echo 'strona główna';");
        $p4 = serwer("$tmp/www", "$tmp/router200.php", $procesy);
        $wynik['inna_tresc'] = evk_backup_exposure_check($dir, "http://127.0.0.1:$p4/kopie");
        // Kanarki posprzątane w każdym przypadku.
        $wynik['kanarki_zostaly'] = count(glob("$dir/evk-kanarek-*") ?: []);
        break;

    case 'manifest':
        $m = evk_backup_manifest(['dodatek' => 'x']);
        global $wpdb;
        $wynik['klucze'] = array_keys($m);
        $wynik['zgodne'] = [
            'siteurl'  => $m['siteurl'] === get_option('siteurl'),
            'home'     => $m['home'] === get_option('home'),
            'abspath'  => $m['abspath'] === ABSPATH,
            'prefix'   => $m['table_prefix'] === $wpdb->prefix,
            'content'  => $m['wp_content_dir'] === WP_CONTENT_DIR,
            'dodatek'  => ($m['dodatek'] ?? '') === 'x',
            'format'   => $m['format'] === 1,
        ];
        $wynik['stale_prawdziwe'] = $m['wp_config_constants'];

        // Stałe: wyłącznie nazwy, nigdy wartości.
        $cfg = "$tmp/wp-config.php";
        file_put_contents($cfg, "<?php\ndefine( 'DB_PASSWORD', 'TajneHaslo-7f3a' );\ndefine(\"EVK_GITHUB_TOKEN\", 'ghp_sekret');\n"
            . "define('WP_MEMORY_LIMIT','512M');\n// define('ZAKOMENTOWANA', 1);\n/* define('W_BLOKU', 1); */\n"
            . "\$x = 'define(\"W_LANCUCHU\", 1)';\n");
        $stale = evk_backup_wp_config_constants($cfg);
        $wynik['stale_z_pliku'] = $stale;
        $wynik['wartosci_wyciekly'] = strpos(json_encode($stale), 'Tajne') !== false || strpos(json_encode($stale), 'ghp_') !== false;

        // Pliki podglądu z katalogu głównego.
        $root = "$tmp/root";
        @mkdir($root, 0700, true);
        foreach (['.htaccess', 'robots.txt', 'google1a2b3c.html', 'wp-config.php', 'index.php', 'readme.html', 'BingSiteAuth.xml'] as $f) {
            file_put_contents("$root/$f", 'x');
        }
        file_put_contents("$root/ads.txt", str_repeat('x', 1048577));   // za duży
        $wynik['podglad'] = array_keys(evk_backup_root_preview_files($root));
        break;

    default:
        fwrite(STDERR, "Nieznany scenariusz: $scen\n");
        exit(2);
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
