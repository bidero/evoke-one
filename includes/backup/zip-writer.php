<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — strumieniowy zapis archiwum ZIP (z ZIP64).
 *
 * ZERO ZALEŻNOŚCI OD WORDPRESSA — testowany w gołym PHP-ie
 * (tests/php/backup-zip.php).
 *
 * DLACZEGO NIE ZipArchive. Zmierzone przed napisaniem tego pliku (1.225.0):
 * dopisanie JEDNEGO pliku 1 KB do istniejącego archiwum przez ZipArchive
 * kosztuje przy close() 0,48 s dla 50 MB, 1,79 s dla 200 MB i 7,58 s dla
 * 800 MB — libzip przepisuje całe archiwum do pliku tymczasowego obok. Kopia
 * robiona w wielu tickach płaciłaby to w KAŻDYM ticku, a przy 3 GB samo
 * close() przekroczyłoby czas ticka i kopia nie skończyłaby się nigdy.
 * Do tego addFile() jest leniwe (0,0005 s), więc czas zjada close(), którego
 * nie da się przerwać w połowie.
 *
 * TEN ZAPIS TYLKO DOPISUJE. Każdy plik to nagłówek lokalny + dane; wpisy
 * katalogu centralnego idą do pliku pobocznego (`<archiwum>.cd`), a finish()
 * dokleja je na końcu razem z rekordem końcowym. Wynik to zwykły ZIP:
 * otwiera go unzip, menedżery archiwów i ZipArchive (którym czyta restore).
 *
 * WZNAWIANIE. state() oddaje stan ZATWIERDZONY: długość archiwum i pliku
 * pobocznego, liczbę wpisów i ewentualnie plik przerwany w połowie. resume()
 * obcina oba pliki do tych długości — tick ubity między zapisem danych
 * a zapisem stanu zostawia za nimi śmieci, które w ten sposób znikają,
 * a przerwaną pracę po prostu robi się jeszcze raz.
 *
 * PLIK DŁUŻSZY NIŻ TICK. Deflate kończy tick opróżnieniem ZLIB_FULL_FLUSH:
 * strumień jest wtedy wyrównany do bajtu i nie odwołuje się do niczego
 * sprzed siebie, więc następny tick zaczyna nowy kontekst i dopisuje dalsze
 * bloki. CRC części z kolejnych ticków składa evk_zip_crc32_combine() —
 * kontekstu hash_init() nie da się przenieść między żądaniami na każdej
 * wersji PHP, a o wersji na hostingu nie decydujemy.
 *
 * ZIP64 włącza się sam, gdy jest potrzebny: plik ≥ ~3,75 GB (nagłówek
 * lokalny), przesunięcie ≥ 4 GB, więcej niż 65 535 wpisów (miniatury mediów
 * przekraczają to łatwo) albo katalog centralny ≥ 4 GB.
 */

final class EVK_Zip_Writer {

    /** Porcja odczytu z pliku źródłowego. */
    const CHUNK = 1048576;

    /** Od tej wielkości nagłówek lokalny dostaje rozszerzenie ZIP64. Zapas
        pod 4 GB na wypadek, gdyby deflate powiększył dane nieściśliwe. */
    const ZIP64_ENTRY = 0xF0000000;

    /** Rozszerzenia, których nie ma sensu kompresować — i tak są skompresowane. */
    const STORED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'm4v', 'mov',
                        'webm', 'mkv', 'avi', 'mp3', 'm4a', 'ogg', 'oga', 'opus', 'aac', 'flac',
                        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf',
                        'docx', 'xlsx', 'pptx', 'odt', 'ods', 'jar', 'apk', 'br', 'zst'];

    /** Tylko do testów: wymusza ZIP64 dla każdego wpisu i rekordu końcowego. */
    public static $force_zip64 = false;

    /** Tylko do testów: poziom deflate. */
    public static $level = 6;

    /** @var resource */
    private $fh;
    /** @var resource */
    private $cd;
    private string $path;
    private array $st;

    private function __construct(string $path, array $state) {
        $this->path = $path;
        $this->st   = $state;
        $fh = fopen($path, 'c+b');
        $cd = fopen($path . '.cd', 'c+b');
        if (!$fh || !$cd) throw new \RuntimeException('Nie można otworzyć archiwum: ' . $path);
        $this->fh = $fh;
        $this->cd = $cd;
        // Obcięcie do stanu zatwierdzonego — patrz „WZNAWIANIE" wyżej.
        ftruncate($this->fh, $state['offset']);
        ftruncate($this->cd, $state['cd_size']);
        fseek($this->fh, $state['offset']);
        fseek($this->cd, $state['cd_size']);
    }

    public function __destruct() {
        if (is_resource($this->fh)) fclose($this->fh);
        if (is_resource($this->cd)) fclose($this->cd);
    }

    /** Nowe archiwum. Istniejący plik o tej nazwie zostaje wyzerowany. */
    public static function create(string $path): self {
        return new self($path, ['offset' => 0, 'cd_size' => 0, 'count' => 0, 'pending' => null]);
    }

    /** Wznowienie od stanu zapisanego przez state(). */
    public static function resume(string $path, array $state): self {
        foreach (['offset', 'cd_size', 'count'] as $k) {
            if (!isset($state[$k]) || !is_int($state[$k])) throw new \InvalidArgumentException('Zły stan archiwum: ' . $k);
        }
        $state['pending'] = $state['pending'] ?? null;
        return new self($path, $state);
    }

    /** Stan zatwierdzony — do zapisania w rekordzie zadania. */
    public function state(): array {
        return $this->st;
    }

    public function count(): int {
        return $this->st['count'];
    }

    /** Czy w archiwum wisi plik przerwany w połowie. */
    public function has_pending(): bool {
        return $this->st['pending'] !== null;
    }

    // =====================================================================
    // DODAWANIE
    // =====================================================================

    /**
     * Dodaje plik z dysku. Pracuje do $deadline (microtime(true)).
     *
     * Zwraca:
     *   'done'    — plik w całości w archiwum,
     *   'partial' — czas minął w połowie; stan zapamiętany, następne
     *               wywołanie z TYM SAMYM plikiem ciągnie dalej,
     *   'skipped' — plik zniknął albo się skurczył w trakcie; wpis cofnięty.
     *
     * Przerwany plik wznawia się wywołaniem add_file() z tymi samymi $src
     * i $name — inny argument przy wiszącym pliku to błąd programisty.
     */
    public function add_file(string $src, string $name, float $deadline): string {
        $p = $this->st['pending'];
        if ($p !== null && ($p['src'] !== $src || $p['name'] !== $name)) {
            throw new \LogicException('W archiwum wisi inny plik: ' . $p['name']);
        }

        if ($p === null) {
            $size = @filesize($src);
            if ($size === false || !is_readable($src)) return 'skipped';
            $mtime = (int) @filemtime($src);
            $method = $this->method_for($name, $size);
            $z64 = self::$force_zip64 || $size >= self::ZIP64_ENTRY;
            $p = [
                'src' => $src, 'name' => $name, 'size' => $size, 'method' => $method,
                'z64' => $z64, 'mtime' => $mtime, 'header' => $this->st['offset'],
                'pos' => 0, 'crc' => 0, 'comp' => 0,
            ];
            $this->write($this->local_header($name, $method, $mtime, $z64));
        }

        $in = @fopen($src, 'rb');
        if (!$in || @fseek($in, $p['pos']) !== 0) {
            if ($in) fclose($in);
            $this->rollback($p);
            return 'skipped';
        }

        $deflate = $p['method'] === 8 ? deflate_init(ZLIB_ENCODING_RAW, ['level' => self::$level]) : null;
        $hash = hash_init('crc32b');
        $czesc = 0;   // bajty źródła przeczytane w TYM wywołaniu

        while ($p['pos'] < $p['size']) {
            $ile  = (int) min(self::CHUNK, $p['size'] - $p['pos']);
            $dane = fread($in, $ile);
            if ($dane === false || strlen($dane) !== $ile) {
                // Plik się skurczył albo zniknął w trakcie — cofnij wpis.
                fclose($in);
                $this->rollback($p);
                return 'skipped';
            }
            hash_update($hash, $dane);
            $p['pos'] += $ile;
            $czesc    += $ile;

            $koniec  = $p['pos'] >= $p['size'];
            $przerwa = !$koniec && microtime(true) >= $deadline;
            if ($deflate) {
                /* Tryb opróżnienia jedzie RAZEM z ostatnią porcją danych.
                   deflate_add() z pustym wejściem nie robi nic — osobne
                   wywołanie z ZLIB_FULL_FLUSH zostawiało bufor w kontekście,
                   który ginął razem z żądaniem, i strumień się urywał. */
                $dane = deflate_add($deflate, $dane, $koniec ? ZLIB_FINISH : ($przerwa ? ZLIB_FULL_FLUSH : ZLIB_NO_FLUSH));
            }
            $this->write($dane);
            $p['comp'] += strlen($dane);

            if ($przerwa) {
                // Przerwa w połowie pliku — deflate opróżniony do granicy bajtu.
                fclose($in);
                $p['crc'] = evk_zip_crc32_combine($p['crc'], self::hash_crc($hash), $czesc);
                $this->commit($p);
                return 'partial';
            }
        }
        fclose($in);

        // Plik pusty przy deflate: strumień i tak musi mieć blok końcowy.
        if ($deflate && $p['size'] === 0) {
            $dane = deflate_add($deflate, '', ZLIB_FINISH);
            $this->write($dane);
            $p['comp'] += strlen($dane);
        }

        $p['crc'] = evk_zip_crc32_combine($p['crc'], self::hash_crc($hash), $czesc);
        $this->close_entry($p);
        return 'done';
    }

    /** Dodaje wpis z treścią w pamięci (manifest, małe pliki). */
    public function add_string(string $name, string $data, ?int $mtime = null): void {
        if ($this->st['pending'] !== null) throw new \LogicException('W archiwum wisi plik: ' . $this->st['pending']['name']);
        $mtime  = $mtime ?? time();
        $method = $this->method_for($name, strlen($data));
        $z64    = self::$force_zip64 || strlen($data) >= self::ZIP64_ENTRY;
        $p = [
            'src' => '', 'name' => $name, 'size' => strlen($data), 'method' => $method,
            'z64' => $z64, 'mtime' => $mtime, 'header' => $this->st['offset'],
            'pos' => strlen($data), 'crc' => crc32($data), 'comp' => 0,
        ];
        $this->write($this->local_header($name, $method, $mtime, $z64));
        $dane = $method === 8 ? (string) gzdeflate($data, self::$level) : $data;
        $this->write($dane);
        $p['comp'] = strlen($dane);
        $this->close_entry($p);
    }

    /** Dodaje wpis katalogu (np. pusty katalog uploads). Nazwa kończy się „/". */
    public function add_dir(string $name, ?int $mtime = null): void {
        if ($this->st['pending'] !== null) throw new \LogicException('W archiwum wisi plik: ' . $this->st['pending']['name']);
        $name  = rtrim($name, '/') . '/';
        $mtime = $mtime ?? time();
        $p = [
            'src' => '', 'name' => $name, 'size' => 0, 'method' => 0, 'z64' => self::$force_zip64,
            'mtime' => $mtime, 'header' => $this->st['offset'], 'pos' => 0, 'crc' => 0, 'comp' => 0,
            'dir' => true,
        ];
        $this->write($this->local_header($name, 0, $mtime, $p['z64']));
        $this->close_entry($p);
    }

    /**
     * Zamyka archiwum: dokleja katalog centralny i rekord końcowy, usuwa plik
     * poboczny. Po finish() obiekt nie nadaje się do niczego.
     */
    public function finish(): void {
        if ($this->st['pending'] !== null) throw new \LogicException('W archiwum wisi plik: ' . $this->st['pending']['name']);

        $cd_offset = $this->st['offset'];
        $cd_size   = $this->st['cd_size'];
        $count     = $this->st['count'];

        // Katalog centralny z pliku pobocznego — porcjami, nie w całości w pamięci.
        rewind($this->cd);
        while (!feof($this->cd)) {
            $dane = fread($this->cd, self::CHUNK);
            if ($dane === false || $dane === '') break;
            $this->write($dane);
        }

        $z64 = self::$force_zip64 || $count >= 0xFFFF || $cd_offset >= 0xFFFFFFFF || $cd_size >= 0xFFFFFFFF;
        if ($z64) {
            $z64_offset = $this->st['offset'];
            $this->write(pack('VP', 0x06064b50, 44)
                . pack('vv', 0x0300 | 45, 45)
                . pack('VV', 0, 0)
                . pack('PPPP', $count, $count, $cd_size, $cd_offset));
            $this->write(pack('VVPV', 0x07064b50, 0, $z64_offset, 1));
        }
        $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0,
            min($count, 0xFFFF), min($count, 0xFFFF),
            $z64 ? 0xFFFFFFFF : $cd_size, $z64 ? 0xFFFFFFFF : $cd_offset, 0));

        fflush($this->fh);
        fclose($this->fh);
        fclose($this->cd);
        @unlink($this->path . '.cd');
    }

    // =====================================================================
    // WNĘTRZE
    // =====================================================================

    private function method_for(string $name, int $size): int {
        if ($size < 64) return 0;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::STORED_EXT, true) ? 0 : 8;
    }

    private function write(string $dane): void {
        if ($dane === '') return;
        $n = fwrite($this->fh, $dane);
        if ($n !== strlen($dane)) {
            throw new \RuntimeException('Zapis archiwum nie powiódł się (brak miejsca na dysku?)');
        }
        $this->st['offset'] += $n;
    }

    /** Zatwierdza stan z plikiem przerwanym w połowie. */
    private function commit(array $p): void {
        fflush($this->fh);
        $this->st['pending'] = $p;
    }

    /** Cofa wpis: archiwum wraca do długości sprzed nagłówka. */
    private function rollback(array $p): void {
        ftruncate($this->fh, $p['header']);
        fseek($this->fh, $p['header']);
        $this->st['offset']  = $p['header'];
        $this->st['pending'] = null;
    }

    private static function hash_crc($hash): int {
        return (int) hexdec(hash_final($hash));
    }

    /** Czas i data w zapisie DOS; wszystko sprzed 1980 staje na 1980-01-01. */
    private static function dos_time(int $t): array {
        $d = getdate(max($t, 315532800));
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }

    /** Bit 11: nazwa w UTF-8. Nazwę nie-UTF-8 zostawiamy bez flagi. */
    private static function flags(string $name): int {
        return preg_match('//u', $name) ? 0x0800 : 0;
    }

    /**
     * Nagłówek lokalny z zerowym CRC i rozmiarami — właściwe wartości wpisuje
     * close_entry() po zapisaniu danych (patch w miejscu, bez deskryptora).
     */
    private function local_header(string $name, int $method, int $mtime, bool $z64): string {
        [$t, $d] = self::dos_time($mtime);
        $extra = $z64 ? pack('vvPP', 0x0001, 16, 0, 0) : '';
        return pack('VvvvvvVVVvv', 0x04034b50, $z64 ? 45 : 20, self::flags($name), $method, $t, $d,
                0, $z64 ? 0xFFFFFFFF : 0, $z64 ? 0xFFFFFFFF : 0, strlen($name), strlen($extra))
            . $name . $extra;
    }

    /** Uzupełnia nagłówek lokalny i dopisuje wpis katalogu centralnego. */
    private function close_entry(array $p): void {
        $crc = $p['crc'] & 0xFFFFFFFF;

        // Poprawka nagłówka lokalnego: CRC (offset 14) i rozmiary.
        $koniec = $this->st['offset'];
        fseek($this->fh, $p['header'] + 14);
        if ($p['z64']) {
            fwrite($this->fh, pack('VVV', $crc, 0xFFFFFFFF, 0xFFFFFFFF));
            fseek($this->fh, $p['header'] + 30 + strlen($p['name']) + 4);
            fwrite($this->fh, pack('PP', $p['size'], $p['comp']));
        } else {
            fwrite($this->fh, pack('VVV', $crc, $p['comp'], $p['size']));
        }
        fseek($this->fh, $koniec);

        // Wpis katalogu centralnego. Pola ≥ 4 GB idą do rozszerzenia ZIP64,
        // w kolejności ze specyfikacji: rozmiar, rozmiar po kompresji, offset.
        $z64 = [];
        $size = $p['size'];
        $comp = $p['comp'];
        $off  = $p['header'];
        $wymus = self::$force_zip64;
        if ($wymus || $size >= 0xFFFFFFFF) { $z64[] = pack('P', $size); $size = 0xFFFFFFFF; }
        if ($wymus || $comp >= 0xFFFFFFFF) { $z64[] = pack('P', $comp); $comp = 0xFFFFFFFF; }
        if ($wymus || $off  >= 0xFFFFFFFF) { $z64[] = pack('P', $off);  $off  = 0xFFFFFFFF; }
        $extra = $z64 ? pack('vv', 0x0001, 8 * count($z64)) . implode('', $z64) : '';

        [$t, $d] = self::dos_time($p['mtime']);
        $attr = !empty($p['dir']) ? ((040755 << 16) | 0x10) : (0100644 << 16);
        $wpis = pack('VvvvvvvVVVvvvvvV', 0x02014b50, 0x0300 | 45, ($extra !== '' || $p['z64']) ? 45 : 20,
                self::flags($p['name']), $p['method'], $t, $d, $crc, $comp, $size,
                strlen($p['name']), strlen($extra), 0, 0, 0, $attr)
            . pack('V', $off) . $p['name'] . $extra;

        if (fwrite($this->cd, $wpis) !== strlen($wpis)) {
            throw new \RuntimeException('Zapis katalogu centralnego nie powiódł się (brak miejsca na dysku?)');
        }
        fflush($this->fh);
        fflush($this->cd);
        $this->st['cd_size'] += strlen($wpis);
        $this->st['count']++;
        $this->st['pending'] = null;
    }
}

/**
 * CRC32 sklejenia dwóch bloków danych z CRC każdego z nich osobno
 * (crc32_combine z zlib: mnożenie macierzy nad GF(2)). Koszt zależy od
 * logarytmu długości drugiego bloku, nie od samej długości.
 */
function evk_zip_crc32_combine(int $crc1, int $crc2, int $len2): int {
    if ($len2 <= 0) return $crc1;

    $razy = static function (array $mat, int $vec): int {
        $sum = 0;
        for ($i = 0; $vec; $i++, $vec >>= 1) {
            if ($vec & 1) $sum ^= $mat[$i];
        }
        return $sum;
    };
    $kwadrat = static function (array $mat) use ($razy): array {
        $sq = [];
        for ($n = 0; $n < 32; $n++) $sq[$n] = $razy($mat, $mat[$n]);
        return $sq;
    };

    $odd = [0xEDB88320];
    for ($n = 1, $row = 1; $n < 32; $n++, $row <<= 1) $odd[$n] = $row;
    $even = $kwadrat($odd);   // dwa bity zerowe
    $odd  = $kwadrat($even);  // cztery

    do {
        $even = $kwadrat($odd);
        if ($len2 & 1) $crc1 = $razy($even, $crc1);
        $len2 >>= 1;
        if ($len2 === 0) break;
        $odd = $kwadrat($even);
        if ($len2 & 1) $crc1 = $razy($odd, $crc1);
        $len2 >>= 1;
    } while ($len2 !== 0);

    return ($crc1 ^ $crc2) & 0xFFFFFFFF;
}
