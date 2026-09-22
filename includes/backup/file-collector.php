<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — zbieranie plików do kopii.
 *
 * ZERO ZALEŻNOŚCI OD WORDPRESSA: ścieżki i wzorce przychodzą z zewnątrz,
 * więc tests/php/backup-pliki.php sprawdza to na drzewie katalogów
 * w katalogu tymczasowym, bez instalacji.
 *
 * DWIE FAZY, obie wznawialne między krokami:
 *
 *   1. LISTA — przejście po katalogu (w głąb, katalog po katalogu) i zapis
 *      pozycji do pliku JSONL. Lista na dysku, a nie w stanie zadania: strona
 *      z mediami ma setki tysięcy plików, a stan zapisuje się co krok.
 *   2. PAKOWANIE — odczyt listy od zapamiętanego miejsca i dopisywanie do
 *      EVK_Zip_Writer; plik przerwany w połowie ciągnie się w kolejnym kroku.
 *
 * Linia listy:  {"n":"wp-content/a/b.jpg","s":"/abs/a/b.jpg","z":1234}
 *               {"n":"wp-content/pusty/","d":1}              (pusty katalog)
 *               {"n64":"…","s64":"…","z":9}      (nazwa spoza UTF-8, base64)
 *
 * WYKLUCZENIA (evk_backup_excluded) — względem katalogu źródłowego:
 *   - wzorzec ze znakiem „/" jest ZAKOTWICZONY: `cache/` to wp-content/cache,
 *     a nie każdy katalog cache — wtyczki trzymają w katalogach o tej nazwie
 *     własny kod, a kopia bez niego odtworzyłaby zepsutą wtyczkę,
 *   - wzorzec bez „/" pasuje do NAZWY gdziekolwiek: `*.log`,
 *   - „/" na końcu: tylko katalogi. Glob jak w fnmatch, `*` nie przechodzi
 *     przez „/".
 *
 * DOWIĄZANIA SYMBOLICZNE są śledzone (uploads na innym dysku trafia do
 * kopii), z dwoma wyjątkami zapisanymi w logu:
 *   - cel nadrzędny wobec katalogu źródłowego (np. „/", katalog domowy) —
 *     kopia połknęłaby cały serwer,
 *   - cel, który jest katalogiem na bieżącej ścieżce albo jego przodkiem —
 *     pętla. Ścieżkę pamiętamy jako rzeczywiste katalogi, w których nastąpił
 *     skok przez dowiązanie (`hops`), bo bez skoków pętli być nie może.
 * Zerwane dowiązanie i plik specjalny (gniazdo, FIFO) są pomijane.
 */

/** Ile wpisów każdego rodzaju trzymamy w logu. Resztę tylko liczymy. */
const EVK_BACKUP_LOG_CAP = 50;

// =========================================================================
// WYKLUCZENIA
// =========================================================================

function evk_backup_excluded(string $rel, bool $katalog, array $wzorce): bool {
    $nazwa = basename($rel);
    foreach ($wzorce as $wz) {
        $wz = (string) $wz;
        $tylkoKatalog = substr($wz, -1) === '/';
        $w = rtrim($wz, '/');
        if ($w === '' || ($tylkoKatalog && !$katalog)) continue;
        if (strpos($wz, '/') !== false) {
            if (fnmatch($w, $rel, FNM_PATHNAME)) return true;
        } elseif (fnmatch($w, $nazwa)) {
            return true;
        }
    }
    return false;
}

/** Czy $a to $b albo jego przodek (obie ścieżki rzeczywiste). */
function evk_backup_path_within(string $dziecko, string $przodek): bool {
    $przodek = rtrim($przodek, '/');
    return $dziecko === $przodek || $przodek === '' || strpos($dziecko, $przodek . '/') === 0;
}

// =========================================================================
// FAZA 1: LISTA
// =========================================================================

/**
 * Stan początkowy listy.
 *
 * $root    katalog źródłowy (wp-content),
 * $prefix  przedrostek nazw w archiwum (`wp-content/`),
 * $wzorce  wykluczenia,
 * $lista   plik listy (zostaje wyzerowany),
 * $extra   pliki spoza katalogu: [nazwa w archiwum => ścieżka] (np. `_root/.htaccess`).
 */
function evk_backup_list_start(string $root, string $prefix, array $wzorce, string $lista, array $extra = []): array {
    $real = realpath($root);
    if ($real === false || !is_dir($real)) throw new \RuntimeException('Brak katalogu źródłowego: ' . $root);
    file_put_contents($lista, '');

    $st = [
        'root' => $real, 'prefix' => $prefix, 'patterns' => array_values($wzorce), 'list' => $lista,
        'bytes' => 0, 'files' => 0, 'dirs' => 0, 'size' => 0, 'done' => false,
        'stack' => [['rel' => '', 'real' => $real, 'hops' => []]],
        'log' => [], 'log_n' => [],
    ];

    // Pliki spoza katalogu (podgląd z katalogu głównego) — od razu, jest ich kilka.
    $linie = '';
    foreach ($extra as $nazwa => $sciezka) {
        if (!is_file($sciezka) || !is_readable($sciezka)) continue;
        $z = (int) filesize($sciezka);
        $linie .= evk_backup_list_line(['n' => $nazwa, 's' => $sciezka, 'z' => $z]);
        $st['files']++;
        $st['size'] += $z;
    }
    file_put_contents($lista, $linie);
    $st['bytes'] = strlen($linie);
    return $st;
}

/**
 * Linia listy. Nazwa albo ścieżka spoza UTF-8 (np. plik wgrany przez FTP
 * z Windows w cp1250) idzie jako base64 w polu z przyrostkiem „64" —
 * JSON_INVALID_UTF8_SUBSTITUTE podmieniłby znaki i plik nie dałby się potem
 * otworzyć pod zapisaną ścieżką.
 */
function evk_backup_list_line(array $w): string {
    foreach (['n', 's'] as $k) {
        if (isset($w[$k]) && !preg_match('//u', $w[$k])) {
            $w[$k . '64'] = base64_encode($w[$k]);
            unset($w[$k]);
        }
    }
    return json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

/** Odwrotność evk_backup_list_line() — pola „64" wracają na swoje miejsce. */
function evk_backup_list_decode(string $linia): ?array {
    $w = json_decode($linia, true);
    if (!is_array($w)) return null;
    foreach (['n', 's'] as $k) {
        if (isset($w[$k . '64'])) $w[$k] = (string) base64_decode($w[$k . '64']);
    }
    return isset($w['n']) ? $w : null;
}

function evk_backup_list_log(array &$st, string $rodzaj, string $co): void {
    $st['log_n'][$rodzaj] = ($st['log_n'][$rodzaj] ?? 0) + 1;
    if (count($st['log'][$rodzaj] ?? []) < EVK_BACKUP_LOG_CAP) $st['log'][$rodzaj][] = $co;
}

/**
 * Przetwarza katalogi ze stosu do $deadline. Co najmniej jeden na wywołanie.
 * Wznowienie: plik listy obcinany do zatwierdzonej długości (jak w zrzucie).
 */
function evk_backup_list_step(array $st, float $deadline): array {
    if ($st['done']) return $st;

    $fh = fopen($st['list'], 'c+b');
    if (!$fh) throw new \RuntimeException('Nie można otworzyć listy plików: ' . $st['list']);
    ftruncate($fh, $st['bytes']);
    fseek($fh, $st['bytes']);

    try {
        do {
            $kat = array_pop($st['stack']);
            $bufor = '';
            $dzieci = [];

            $wpisy = @scandir($kat['real']);
            if ($wpisy === false) {
                evk_backup_list_log($st, 'nieczytelne', $kat['rel'] === '' ? '.' : $kat['rel']);
                $wpisy = [];
            }
            $cokolwiek = false;
            foreach ($wpisy as $e) {
                if ($e === '.' || $e === '..') continue;
                $rel  = $kat['rel'] === '' ? $e : $kat['rel'] . '/' . $e;
                $path = $kat['real'] . '/' . $e;

                $link = is_link($path);
                $cel  = $link ? realpath($path) : $path;
                if ($cel === false) { evk_backup_list_log($st, 'zerwane_dowiazania', $rel); continue; }
                $katalog = is_dir($cel);

                if (evk_backup_excluded($rel, $katalog, $st['patterns'])) continue;

                if ($katalog) {
                    $hops = $kat['hops'];
                    if ($link) {
                        // Cel nadrzędny wobec źródła: kopia połknęłaby cały serwer.
                        if (evk_backup_path_within($st['root'], $cel)) { evk_backup_list_log($st, 'dowiazania_nadrzedne', $rel); continue; }
                        // Pętla: cel jest bieżącym katalogiem, jego przodkiem albo
                        // katalogiem (lub przodkiem katalogu), w którym był wcześniejszy skok.
                        $petla = evk_backup_path_within($kat['real'], $cel);
                        foreach ($hops as $h) { if (evk_backup_path_within($h, $cel)) { $petla = true; break; } }
                        if ($petla) { evk_backup_list_log($st, 'petle', $rel); continue; }
                        $hops[] = $kat['real'];
                    }
                    $dzieci[] = ['rel' => $rel, 'real' => $link ? $cel : $path, 'hops' => $hops];
                    $cokolwiek = true;
                } elseif (is_file($cel)) {
                    $z = @filesize($cel);
                    if ($z === false || !is_readable($cel)) { evk_backup_list_log($st, 'nieczytelne', $rel); continue; }
                    $bufor .= evk_backup_list_line(['n' => $st['prefix'] . $rel, 's' => $cel, 'z' => $z]);
                    $st['files']++;
                    $st['size'] += $z;
                    $cokolwiek = true;
                } else {
                    evk_backup_list_log($st, 'specjalne', $rel);
                }
            }

            /* Pusty katalog dostaje własny wpis — restore odtworzy np. pusty
               katalog uploads na bieżący miesiąc. Katalog, w którym wszystko
               wykluczono, też: istniał na stronie. Korzeń nie potrzebuje. */
            if (!$cokolwiek && $kat['rel'] !== '') {
                $bufor .= evk_backup_list_line(['n' => $st['prefix'] . $kat['rel'] . '/', 'd' => 1]);
            }

            // Odwrotnie na stos: przejście w kolejności alfabetycznej.
            foreach (array_reverse($dzieci) as $d) $st['stack'][] = $d;
            $st['dirs']++;

            if ($bufor !== '' && fwrite($fh, $bufor) !== strlen($bufor)) {
                throw new \RuntimeException('Zapis listy plików nie powiódł się (brak miejsca na dysku?)');
            }
            fflush($fh);
            $st['bytes'] = ftell($fh);
            if (!$st['stack']) $st['done'] = true;
        } while (!$st['done'] && microtime(true) < $deadline);
    } finally {
        fclose($fh);
    }
    return $st;
}

// =========================================================================
// FAZA 2: PAKOWANIE
// =========================================================================

function evk_backup_pack_start(string $lista): array {
    return ['list' => $lista, 'pos' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => [], 'skipped_n' => 0, 'done' => false];
}

/**
 * Dopisuje pliki z listy do archiwum do $deadline. Pozycja w liście posuwa
 * się dopiero, gdy plik jest w archiwum w całości (albo pominięty) — plik
 * przerwany w połowie następny krok podaje piszącemu jeszcze raz, a ten
 * ciągnie go od miejsca, w którym stanął.
 */
function evk_backup_pack_step(EVK_Zip_Writer $zip, array $st, float $deadline): array {
    if ($st['done']) return $st;
    $fh = fopen($st['list'], 'rb');
    if (!$fh) throw new \RuntimeException('Nie można otworzyć listy plików: ' . $st['list']);
    fseek($fh, $st['pos']);
    try {
        while (true) {
            $linia = fgets($fh);
            if ($linia === false) { $st['done'] = true; break; }
            $w = evk_backup_list_decode($linia);
            if ($w === null) throw new \RuntimeException('Uszkodzona lista plików przy bajcie ' . $st['pos']);

            if (!empty($w['d'])) {
                $zip->add_dir($w['n']);
                $wynik = 'done';
            } else {
                $wynik = $zip->add_file($w['s'], $w['n'], $deadline);
            }
            if ($wynik === 'partial') break;   // pozycja zostaje — ten sam plik w kolejnym kroku

            if ($wynik === 'skipped') {
                $st['skipped_n']++;
                if (count($st['skipped']) < EVK_BACKUP_LOG_CAP) $st['skipped'][] = $w['n'];
            } else {
                $st['files']++;
                $st['bytes'] += (int) ($w['z'] ?? 0);
            }
            $st['pos'] = ftell($fh);
            if (microtime(true) >= $deadline) break;
        }
    } finally {
        fclose($fh);
    }
    return $st;
}
