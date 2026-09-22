<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — odczyt archiwum ZIP (z ZIP64) do przywracania.
 *
 * ZERO ZALEŻNOŚCI OD WORDPRESSA — testowany w gołym PHP-ie
 * (tests/php/backup-czytnik.php). Potrzebuje evk_zip_crc32_combine()
 * z zip-writer.php.
 *
 * DLACZEGO NIE ZipArchive. Rozpakowanie idzie krokami, a plik w archiwum
 * bywa większy, niż jeden krok zdąży rozpakować (zrzut bazy, wideo).
 * ZipArchive::getStream() nie przewija strumienia deflate — wznowienie
 * w połowie wpisu i tak wymagałoby czytania od początku, a do tego nie
 * sprawdza CRC (zmierzone przy testach zapisu, 1.225.0). Ten czytnik zna
 * swój format: nagłówki lokalne, katalog centralny, rozszerzenie ZIP64.
 *
 * WZNAWIANIE W POŁOWIE WPISU:
 *   - wpis bez kompresji (media, archiwa — patrz STORED_EXT w zapisie):
 *     przewinięcie do miejsca, w którym stanął poprzedni krok,
 *   - wpis deflate: rozpakowanie OD POCZĄTKU wpisu z odrzuceniem bajtów, które
 *     poprzedni krok już zapisał. Kontekstu inflate nie da się przenieść
 *     między żądaniami. Zmierzone (1.227.0) na 200 MB zrzutu JSONL: samo
 *     rozpakowanie bez zapisu 0,32 s (~630 MB/s), z zapisem 1,25 s — więc
 *     wznowienie w końcówce zrzutu 2 GB kosztuje ~3 s. Pisarz nie potrzebował
 *     przez to punktów synchronizacji w strumieniu.
 *
 * CRC liczony w trakcie, części z kolejnych kroków sklejane jak w zapisie.
 * Niezgodność CRC albo rozmiaru to wyjątek — uszkodzonego pliku nie
 * przywracamy po cichu.
 */

final class EVK_Zip_Reader {

    const CHUNK = 262144;

    /**
     * Czyta katalog centralny. Dla każdego wpisu woła $kazdy(array $wpis):
     *   n    nazwa (surowa, NIE sprawdzona — patrz evk_zip_safe_name),
     *   o    przesunięcie nagłówka lokalnego,
     *   c    rozmiar po kompresji,  z  rozmiar,
     *   m    metoda (0 bez kompresji, 8 deflate),  crc,
     *   d    1 dla katalogu (nazwa kończy się „/").
     * Oddaje liczbę wpisów. Rzuca przy uszkodzonym albo obcym zapisie.
     */
    public static function scan(string $zip, callable $kazdy): int {
        $fh = @fopen($zip, 'rb');
        if (!$fh) throw new \RuntimeException('Nie można otworzyć archiwum: ' . basename($zip));
        try {
            [$ile, $cd_off, $cd_size] = self::end_record($fh, (int) filesize($zip));
            fseek($fh, $cd_off);
            $bufor = '';
            $koniec = $cd_off + $cd_size;
            $poz = $cd_off;
            $n = 0;
            while ($n < $ile) {
                // Bufor dociągany porcjami: wpis to 46 bajtów + nazwa + rozszerzenia + komentarz.
                while (strlen($bufor) < 46 && $poz < $koniec) {
                    $dane = fread($fh, (int) min(self::CHUNK, $koniec - $poz));
                    if ($dane === false || $dane === '') break;
                    $bufor .= $dane;
                    $poz += strlen($dane);
                }
                if (strlen($bufor) < 46) throw new \RuntimeException('Katalog centralny archiwum jest ucięty.');
                $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', $bufor);
                if ($h['sig'] !== 0x02014b50) throw new \RuntimeException('Uszkodzony katalog centralny archiwum (wpis ' . ($n + 1) . ').');
                $dl = 46 + $h['nlen'] + $h['elen'] + $h['clen'];
                while (strlen($bufor) < $dl && $poz < $koniec) {
                    $dane = fread($fh, (int) min(self::CHUNK, $koniec - $poz));
                    if ($dane === false || $dane === '') break;
                    $bufor .= $dane;
                    $poz += strlen($dane);
                }
                if (strlen($bufor) < $dl) throw new \RuntimeException('Katalog centralny archiwum jest ucięty.');

                $nazwa = substr($bufor, 46, $h['nlen']);
                $extra = substr($bufor, 46 + $h['nlen'], $h['elen']);
                $size = $h['size'];
                $comp = $h['comp'];
                $off  = $h['off'];
                // ZIP64: pola ustawione na 0xFFFFFFFF są w rozszerzeniu 0x0001, w tej kolejności.
                if ($size === 0xFFFFFFFF || $comp === 0xFFFFFFFF || $off === 0xFFFFFFFF) {
                    $z64 = self::extra_field($extra, 0x0001);
                    if ($z64 === null) throw new \RuntimeException('Brak rozszerzenia ZIP64 przy wpisie ' . $nazwa);
                    $pola = ['size' => $size, 'comp' => $comp, 'off' => $off];
                    $p = 0;
                    foreach ($pola as $pole => $wartosc) {
                        if ($wartosc !== 0xFFFFFFFF) continue;
                        if (strlen($z64) < $p + 8) throw new \RuntimeException('Uszkodzone rozszerzenie ZIP64 przy wpisie ' . $nazwa);
                        $pola[$pole] = (int) unpack('P', substr($z64, $p, 8))[1];
                        $p += 8;
                    }
                    ['size' => $size, 'comp' => $comp, 'off' => $off] = $pola;
                }
                $wpis = ['n' => $nazwa, 'o' => $off, 'c' => $comp, 'z' => $size, 'm' => $h['method'], 'crc' => $h['crc']];
                if (substr($nazwa, -1) === '/') $wpis['d'] = 1;
                $kazdy($wpis);
                $bufor = (string) substr($bufor, $dl);
                $n++;
            }
            return $n;
        } finally {
            fclose($fh);
        }
    }

    /** Liczba wpisów, przesunięcie i rozmiar katalogu centralnego (z rekordu końcowego, także ZIP64). */
    private static function end_record($fh, int $rozmiar): array {
        if ($rozmiar < 22) throw new \RuntimeException('To nie jest archiwum ZIP (plik za krótki).');
        // Rekord końcowy: 22 bajty + komentarz do 65 535 — szukany od końca.
        $ile = (int) min($rozmiar, 22 + 65535);
        fseek($fh, $rozmiar - $ile);
        $ogon = (string) fread($fh, $ile);
        $p = strrpos($ogon, "PK\x05\x06");
        if ($p === false || strlen($ogon) - $p < 22) throw new \RuntimeException('To nie jest archiwum ZIP albo jest ucięte (brak rekordu końcowego).');
        $e = unpack('Vsig/vdisk/vcddisk/vn1/vn/Vsize/Voff/vclen', substr($ogon, $p, 22));
        $n = (int) $e['n'];
        $size = (int) $e['size'];
        $off = (int) $e['off'];

        if ($n === 0xFFFF || $size === 0xFFFFFFFF || $off === 0xFFFFFFFF) {
            // Lokalizator ZIP64 leży tuż przed rekordem końcowym.
            $lok = $rozmiar - $ile + $p - 20;
            if ($lok < 0) throw new \RuntimeException('Brak lokalizatora ZIP64.');
            fseek($fh, $lok);
            $l = unpack('Vsig/Vdisk/Poff/Vdisks', (string) fread($fh, 20));
            if ($l['sig'] !== 0x07064b50) throw new \RuntimeException('Uszkodzony lokalizator ZIP64.');
            fseek($fh, (int) $l['off']);
            $r = unpack('Vsig/Psize/vmade/vneed/Vdisk/Vcddisk/Pn1/Pn/Pcdsize/Pcdoff', (string) fread($fh, 56));
            if ($r['sig'] !== 0x06064b50) throw new \RuntimeException('Uszkodzony rekord końcowy ZIP64.');
            $n = (int) $r['n'];
            $size = (int) $r['cdsize'];
            $off = (int) $r['cdoff'];
        }
        if ($off + $size > $rozmiar) throw new \RuntimeException('Archiwum jest ucięte (katalog centralny poza końcem pliku).');
        return [$n, $off, $size];
    }

    private static function extra_field(string $extra, int $id): ?string {
        $p = 0;
        while ($p + 4 <= strlen($extra)) {
            $h = unpack('vid/vlen', substr($extra, $p, 4));
            if ($h['id'] === $id) return substr($extra, $p + 4, $h['len']);
            $p += 4 + $h['len'];
        }
        return null;
    }

    /** Przesunięcie danych wpisu — za nagłówkiem lokalnym (jego rozszerzenia bywają inne niż w katalogu). */
    private static function data_offset($fh, array $w): int {
        fseek($fh, $w['o']);
        $h = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vsize/vnlen/velen', (string) fread($fh, 30));
        if (!$h || $h['sig'] !== 0x04034b50) throw new \RuntimeException('Uszkodzony nagłówek lokalny wpisu ' . $w['n']);
        return $w['o'] + 30 + $h['nlen'] + $h['elen'];
    }

    /**
     * Rozpakowuje wpis do $cel od pozycji $st['pos'], do $deadline.
     * $st: ['pos' => bajty już zapisane, 'crc' => CRC tych bajtów].
     * Oddaje nowy stan z 'done' => true, gdy wpis cały i sprawdzony.
     * Co najmniej jedna porcja na wywołanie — postęp jest zawsze.
     */
    public static function extract(string $zip, array $w, string $cel, array $st, float $deadline): array {
        $st += ['pos' => 0, 'crc' => 0];
        if (!in_array($w['m'], [0, 8], true)) throw new \RuntimeException('Nieobsługiwana metoda kompresji (' . $w['m'] . ') we wpisie ' . $w['n']);

        $fh = @fopen($zip, 'rb');
        if (!$fh) throw new \RuntimeException('Nie można otworzyć archiwum: ' . basename($zip));
        $out = @fopen($cel, 'c+b');
        if (!$out) { fclose($fh); throw new \RuntimeException('Nie można zapisać pliku: ' . $cel); }

        try {
            // Obcięcie do stanu zatwierdzonego — krok ubity po zapisie, a przed zapisem stanu.
            ftruncate($out, $st['pos']);
            fseek($out, $st['pos']);
            $dane_od = self::data_offset($fh, $w);
            $hash = hash_init('crc32b');
            $nowe = 0;

            if ($w['m'] === 0) {
                fseek($fh, $dane_od + $st['pos']);
                while ($st['pos'] + $nowe < $w['z']) {
                    $kawal = fread($fh, (int) min(self::CHUNK, $w['z'] - $st['pos'] - $nowe));
                    if ($kawal === false || $kawal === '') throw new \RuntimeException('Archiwum ucięte we wpisie ' . $w['n']);
                    self::write($out, $kawal, $cel);
                    hash_update($hash, $kawal);
                    $nowe += strlen($kawal);
                    if (microtime(true) >= $deadline) break;
                }
            } else {
                fseek($fh, $dane_od);
                $inf = inflate_init(ZLIB_ENCODING_RAW);
                $pominac = $st['pos'];
                $czytane = 0;
                $koniec = false;
                while (!$koniec) {
                    if ($czytane >= $w['c']) throw new \RuntimeException('Strumień deflate urwany we wpisie ' . $w['n']);
                    $kawal = fread($fh, (int) min(self::CHUNK, $w['c'] - $czytane));
                    if ($kawal === false || $kawal === '') throw new \RuntimeException('Archiwum ucięte we wpisie ' . $w['n']);
                    $czytane += strlen($kawal);
                    $wyj = @inflate_add($inf, $kawal, ZLIB_SYNC_FLUSH);
                    if ($wyj === false) throw new \RuntimeException('Uszkodzone dane deflate we wpisie ' . $w['n']);
                    $koniec = inflate_get_status($inf) === ZLIB_STREAM_END;
                    if ($pominac > 0) {
                        // Bajty zapisane przez poprzedni krok — już są w pliku.
                        $ile = min($pominac, strlen($wyj));
                        $wyj = (string) substr($wyj, $ile);
                        $pominac -= $ile;
                    }
                    if ($wyj !== '') {
                        self::write($out, $wyj, $cel);
                        hash_update($hash, $wyj);
                        $nowe += strlen($wyj);
                    }
                    if ($pominac === 0 && $nowe > 0 && microtime(true) >= $deadline) break;
                }
                /* Strumień zakończony przed zapisanym rozmiarem — uszkodzenie, które
                   inflate przyjął jako blok końcowy. Bez tego sprawdzenia każde
                   kolejne wywołanie kończyło się bez postępu i przywracanie
                   kręciło się w kółko (złapane testem „uszkodzone"). */
                if ($koniec && $st['pos'] + $nowe < $w['z']) {
                    throw new \RuntimeException('Uszkodzony plik w archiwum (dane kończą się przed czasem): ' . $w['n']);
                }
            }

            fflush($out);
            $st['crc'] = evk_zip_crc32_combine($st['crc'], (int) hexdec(hash_final($hash)), $nowe);
            $st['pos'] += $nowe;
            $st['done'] = $st['pos'] >= $w['z'];
            if ($st['pos'] > $w['z']) throw new \RuntimeException('Wpis ' . $w['n'] . ' dłuższy niż zapisany rozmiar.');
            if ($st['done'] && ($st['crc'] & 0xFFFFFFFF) !== ($w['crc'] & 0xFFFFFFFF)) {
                throw new \RuntimeException('Uszkodzony plik w archiwum (CRC się nie zgadza): ' . $w['n']);
            }
            return $st;
        } finally {
            fclose($fh);
            fclose($out);
        }
    }

    /** Mały wpis w całości do pamięci (manifest). $max chroni przed wpisem-pułapką. */
    public static function read(string $zip, array $w, int $max = 4194304): string {
        if ($w['z'] > $max) throw new \RuntimeException('Wpis ' . $w['n'] . ' za duży do odczytu w pamięci.');
        $tmp = tempnam(sys_get_temp_dir(), 'evkzip');
        if ($tmp === false) throw new \RuntimeException('Brak katalogu tymczasowego.');
        try {
            $st = ['pos' => 0, 'crc' => 0];
            do { $st = self::extract($zip, $w, $tmp, $st, microtime(true) + 30); } while (empty($st['done']));
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private static function write($out, string $dane, string $cel): void {
        if (fwrite($out, $dane) !== strlen($dane)) {
            throw new \RuntimeException('Zapis pliku nie powiódł się (brak miejsca na dysku?): ' . $cel);
        }
    }
}

/**
 * Nazwa wpisu, którą wolno zapisać na dysk — albo null. Archiwum przyjęte
 * przez FTP albo od kogoś może mieć w nazwie `../`, ścieżkę bezwzględną,
 * literę dysku czy bajt zerowy (zip slip). Odrzucamy też `.` i puste
 * segmenty — nie ma ich w archiwach tej wtyczki, a normalizacja to miejsce,
 * w którym takie obejścia zwykle przechodzą.
 */
function evk_zip_safe_name(string $n): ?string {
    if ($n === '' || strpos($n, "\0") !== false || strpos($n, '\\') !== false) return null;
    if ($n[0] === '/' || preg_match('/^[A-Za-z]:/', $n)) return null;
    $czesci = explode('/', rtrim($n, '/'));
    foreach ($czesci as $c) {
        if ($c === '' || $c === '.' || $c === '..') return null;
    }
    return $n;
}
