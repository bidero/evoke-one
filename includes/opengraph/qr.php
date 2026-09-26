<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — kod QR rysowany na miejscu (1.235.0).
 *
 * Do 1.234.1 warstwa QR obrazka OG ściągała gotowy PNG z api.qrserver.com:
 * przy każdym generowaniu adres wpisu szedł do obcej usługi, a gdy ta nie
 * odpowiedziała w 5 s, obrazek wychodził bez kodu (ślad tylko w logu PHP).
 *
 * Koder według ISO/IEC 18004: tryb bajtowy (tekst jako bajty UTF-8), wersje
 * 1–40, poziomy korekcji L/M/Q/H, Reed–Solomon nad GF(256) z wielomianem
 * 0x11D, osiem masek i kara za każdą (serie, bloki 2×2, wzorce podobne do
 * znaczników, proporcja ciemnych). Tablice bloków korekcji — z normy.
 *
 * Poprawności pilnuje tests/og-layers-qr.test.js: niezależny dekoder (jsQR)
 * czyta kody wszystkich wersji i poziomów, z każdą maską, także narysowane
 * w GD przez warstwę obrazka OG.
 */

/** Tablice z normy: słowa korekcji na blok i liczba bloków, wersje 1–40 (indeks 0 pusty). */
function evk_qr_tablice(): array {
    static $t = null;
    if ($t !== null) return $t;
    $t = [
        'ecc' => [
            'L' => [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
            'M' => [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
            'Q' => [0, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
            'H' => [0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        ],
        'bloki' => [
            'L' => [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
            'M' => [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
            'Q' => [0, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
            'H' => [0, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
        ],
    ];
    return $t;
}

/** Moduły na dane i korekcję (bez wzorców i informacji o formacie/wersji). */
function evk_qr_moduly_danych(int $wersja): int {
    $wynik = (16 * $wersja + 128) * $wersja + 64;
    if ($wersja >= 2) {
        $wyrownan = intdiv($wersja, 7) + 2;
        $wynik -= (25 * $wyrownan - 10) * $wyrownan - 55;
        if ($wersja >= 7) $wynik -= 36;
    }
    return $wynik;
}

/** Słowa (bajty) na same dane w danej wersji i poziomie. */
function evk_qr_slowa_danych(int $wersja, string $poziom): int {
    $t = evk_qr_tablice();
    return intdiv(evk_qr_moduly_danych($wersja), 8) - $t['ecc'][$poziom][$wersja] * $t['bloki'][$poziom][$wersja];
}

/** Środki wzorców wyrównania (ten sam zestaw dla wierszy i kolumn). */
function evk_qr_polozenia_wyrownania(int $wersja): array {
    if ($wersja === 1) return [];
    $ile   = intdiv($wersja, 7) + 2;
    $krok  = intdiv($wersja * 8 + $ile * 3 + 5, $ile * 4 - 4) * 2;
    $wynik = [6];
    for ($poz = $wersja * 4 + 10; count($wynik) < $ile; $poz -= $krok) array_splice($wynik, 1, 0, [$poz]);
    return $wynik;
}

/** Mnożenie w GF(256) modulo x^8 + x^4 + x^3 + x^2 + 1 (0x11D). */
function evk_qr_gf_mnoz(int $x, int $y): int {
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = ($z << 1) ^ (($z >> 7) * 0x11D);
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

/** Współczynniki wielomianu generującego Reed–Solomona danego stopnia (bez wiodącej jedynki). */
function evk_qr_rs_dzielnik(int $stopien): array {
    $wynik = array_fill(0, $stopien, 0);
    $wynik[$stopien - 1] = 1;
    $pierwiastek = 1;
    for ($i = 0; $i < $stopien; $i++) {
        for ($j = 0; $j < $stopien; $j++) {
            $wynik[$j] = evk_qr_gf_mnoz($wynik[$j], $pierwiastek);
            if ($j + 1 < $stopien) $wynik[$j] ^= $wynik[$j + 1];
        }
        $pierwiastek = evk_qr_gf_mnoz($pierwiastek, 0x02);
    }
    return $wynik;
}

/** Słowa korekcji jednego bloku: reszta z dzielenia przez wielomian generujący. */
function evk_qr_rs_reszta(array $dane, array $dzielnik): array {
    $wynik = array_fill(0, count($dzielnik), 0);
    foreach ($dane as $bajt) {
        $czynnik = $bajt ^ (int) array_shift($wynik);
        $wynik[] = 0;
        foreach ($dzielnik as $i => $wsp) $wynik[$i] ^= evk_qr_gf_mnoz($wsp, $czynnik);
    }
    return $wynik;
}

/** Dane podzielone na bloki, z korekcją każdego, przeplecione słowo po słowie. */
function evk_qr_przeplot(array $slowa, int $wersja, string $poziom): array {
    $t        = evk_qr_tablice();
    $ileBlok  = $t['bloki'][$poziom][$wersja];
    $ecc      = $t['ecc'][$poziom][$wersja];
    $surowe   = intdiv(evk_qr_moduly_danych($wersja), 8);
    $krotkich = $ileBlok - $surowe % $ileBlok;   // krótsze bloki idą pierwsze
    $dlKrotki = intdiv($surowe, $ileBlok);
    $dzielnik = evk_qr_rs_dzielnik($ecc);

    $bloki = [];
    for ($i = 0, $k = 0; $i < $ileBlok; $i++) {
        $ile   = $dlKrotki - $ecc + ($i < $krotkich ? 0 : 1);
        $dane  = array_slice($slowa, $k, $ile);
        $k    += $ile;
        $kor   = evk_qr_rs_reszta($dane, $dzielnik);
        if ($i < $krotkich) $dane[] = 0;   // miejsce, które przeplot pomija
        $bloki[] = array_merge($dane, $kor);
    }
    $wynik = [];
    for ($i = 0, $dl = count($bloki[0]); $i < $dl; $i++) {
        foreach ($bloki as $j => $blok) {
            if ($i !== $dlKrotki - $ecc || $j >= $krotkich) $wynik[] = $blok[$i];
        }
    }
    return $wynik;
}

/**
 * Informacja o formacie (poziom + maska, BCH(15,5), maska 0x5412) w obu
 * kopiach i stały ciemny moduł. $ustaw(x, y, ciemny) rysuje moduł wzorca.
 */
function evk_qr_format(callable $ustaw, int $n, string $poziom, int $maska): void {
    $dane = (['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2][$poziom] << 3) | $maska;
    $r = $dane;
    for ($i = 0; $i < 10; $i++) $r = ($r << 1) ^ (($r >> 9) * 0x537);
    $bity = (($dane << 10) | $r) ^ 0x5412;
    $bit  = static function (int $i) use ($bity): bool { return (($bity >> $i) & 1) === 1; };

    for ($i = 0; $i <= 5; $i++) $ustaw(8, $i, $bit($i));
    $ustaw(8, 7, $bit(6));
    $ustaw(8, 8, $bit(7));
    $ustaw(7, 8, $bit(8));
    for ($i = 9; $i < 15; $i++) $ustaw(14 - $i, 8, $bit($i));
    for ($i = 0; $i < 8; $i++) $ustaw($n - 1 - $i, 8, $bit($i));
    for ($i = 8; $i < 15; $i++) $ustaw(8, $n - 15 + $i, $bit($i));
    $ustaw(8, $n - 8, true);
}

/** Nakłada maskę na moduły danych. XOR — drugie nałożenie tej samej cofa pierwsze. */
function evk_qr_maskuj(array &$m, array $wzorzec, int $n, int $maska): void {
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            if ($wzorzec[$y * $n + $x]) continue;
            switch ($maska) {
                case 0:  $odwroc = ($x + $y) % 2 === 0; break;
                case 1:  $odwroc = $y % 2 === 0; break;
                case 2:  $odwroc = $x % 3 === 0; break;
                case 3:  $odwroc = ($x + $y) % 3 === 0; break;
                case 4:  $odwroc = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                case 5:  $odwroc = $x * $y % 2 + $x * $y % 3 === 0; break;
                case 6:  $odwroc = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                default: $odwroc = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
            }
            if ($odwroc) $m[$y * $n + $x] ^= 1;
        }
    }
}

/** Historia długości serii do wykrywania wzorców 1:1:3:1:1 (kara N3). */
function evk_qr_historia(int $seria, array &$historia, int $n): void {
    if ($historia[0] === 0) $seria += $n;   // jasny margines przed pierwszą serią
    array_pop($historia);
    array_unshift($historia, $seria);
}

function evk_qr_znaczniki(array $h): int {
    $s = $h[1];
    $rdzen = $s > 0 && $h[2] === $s && $h[3] === $s * 3 && $h[4] === $s && $h[5] === $s;
    return ($rdzen && $h[0] >= $s * 4 && $h[6] >= $s ? 1 : 0) + ($rdzen && $h[6] >= $s * 4 && $h[0] >= $s ? 1 : 0);
}

/** Kara maski według normy: N1 serie, N2 bloki 2×2, N3 wzorce znaczników, N4 proporcja ciemnych. */
function evk_qr_kara(array $m, int $n): int {
    $wynik = 0;
    for ($os = 0; $os < 2; $os++) {
        for ($a = 0; $a < $n; $a++) {
            $kolor = 0; $seria = 0; $historia = [0, 0, 0, 0, 0, 0, 0];
            for ($b = 0; $b < $n; $b++) {
                $v = $os === 0 ? $m[$a * $n + $b] : $m[$b * $n + $a];
                if ($v === $kolor) {
                    $seria++;
                    if ($seria === 5) $wynik += 3;
                    elseif ($seria > 5) $wynik++;
                } else {
                    evk_qr_historia($seria, $historia, $n);
                    if ($kolor === 0) $wynik += evk_qr_znaczniki($historia) * 40;
                    $kolor = $v;
                    $seria = 1;
                }
            }
            if ($kolor === 1) { evk_qr_historia($seria, $historia, $n); $seria = 0; }
            evk_qr_historia($seria + $n, $historia, $n);   // jasny margines za ostatnią serią
            $wynik += evk_qr_znaczniki($historia) * 40;
        }
    }
    for ($y = 0; $y < $n - 1; $y++) {
        for ($x = 0; $x < $n - 1; $x++) {
            $c = $m[$y * $n + $x];
            if ($c === $m[$y * $n + $x + 1] && $c === $m[($y + 1) * $n + $x] && $c === $m[($y + 1) * $n + $x + 1]) $wynik += 3;
        }
    }
    $wszystkie = $n * $n;
    return $wynik + ((int) ceil(abs(array_sum($m) * 20 - $wszystkie * 10) / $wszystkie) - 1) * 10;
}

/**
 * Kod QR dla bajtów $dane w najmniejszej wersji, która je mieści.
 *
 * @return array{wersja: int, maska: int, moduly: list<list<int>>}|null
 *         moduły wierszami (1 = ciemny), bez strefy ciszy; null, gdy dane nie
 *         mieszczą się w wersji 40
 */
function evk_qr_koduj(string $dane, string $poziom = 'M', ?int $maska = null): ?array {
    if (!isset(evk_qr_tablice()['ecc'][$poziom])) $poziom = 'M';
    $dl = strlen($dane);

    $wersja = 0;
    for ($v = 1; $v <= 40; $v++) {
        if (4 + ($v < 10 ? 8 : 16) + 8 * $dl <= evk_qr_slowa_danych($v, $poziom) * 8) { $wersja = $v; break; }
    }
    if (!$wersja) return null;

    // Bity: tryb bajtowy (0100), długość, dane, terminator, dopełnienie do bajtu, bajty wypełnienia.
    $bity = [];
    $dopisz = static function (int $wartosc, int $ile) use (&$bity): void {
        for ($i = $ile - 1; $i >= 0; $i--) $bity[] = ($wartosc >> $i) & 1;
    };
    $dopisz(0b0100, 4);
    $dopisz($dl, $wersja < 10 ? 8 : 16);
    for ($i = 0; $i < $dl; $i++) $dopisz(ord($dane[$i]), 8);
    $pojemnosc = evk_qr_slowa_danych($wersja, $poziom) * 8;
    $dopisz(0, min(4, $pojemnosc - count($bity)));
    $dopisz(0, (8 - count($bity) % 8) % 8);
    for ($wypelnienie = 0xEC; count($bity) < $pojemnosc; $wypelnienie ^= 0xEC ^ 0x11) $dopisz($wypelnienie, 8);
    $slowa = [];
    foreach (array_chunk($bity, 8) as $osiem) $slowa[] = (int) bindec(implode('', $osiem));
    $kod = evk_qr_przeplot($slowa, $wersja, $poziom);

    // Macierz: wzorce stałe, potem dane. $wzorzec oznacza moduły, których maska nie dotyka.
    $n       = $wersja * 4 + 17;
    $m       = array_fill(0, $n * $n, 0);
    $wzorzec = array_fill(0, $n * $n, false);
    $ustaw = static function (int $x, int $y, bool $ciemny) use (&$m, &$wzorzec, $n): void {
        $m[$y * $n + $x] = $ciemny ? 1 : 0;
        $wzorzec[$y * $n + $x] = true;
    };

    for ($i = 0; $i < $n; $i++) {                            // wzorce taktujące
        $ustaw(6, $i, $i % 2 === 0);
        $ustaw($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {   // znaczniki z separatorami
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $xx = $cx + $dx; $yy = $cy + $dy;
                if ($xx < 0 || $xx >= $n || $yy < 0 || $yy >= $n) continue;
                $d = max(abs($dx), abs($dy));
                $ustaw($xx, $yy, $d !== 2 && $d !== 4);
            }
        }
    }
    $poz = evk_qr_polozenia_wyrownania($wersja);             // wzorce wyrównania
    $ile = count($poz);
    for ($i = 0; $i < $ile; $i++) {
        for ($j = 0; $j < $ile; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $ile - 1) || ($i === $ile - 1 && $j === 0)) continue;
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) $ustaw($poz[$i] + $dx, $poz[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }
    evk_qr_format($ustaw, $n, $poziom, 0);                   // rezerwuje miejsce; właściwa maska niżej
    if ($wersja >= 7) {                                       // informacja o wersji, BCH(18,6)
        $r = $wersja;
        for ($i = 0; $i < 12; $i++) $r = ($r << 1) ^ (($r >> 11) * 0x1F25);
        $bityWersji = ($wersja << 12) | $r;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bityWersji >> $i) & 1) === 1;
            $a = $n - 11 + $i % 3;
            $b = intdiv($i, 3);
            $ustaw($a, $b, $bit);
            $ustaw($b, $a, $bit);
        }
    }

    // Dane zygzakiem: pary kolumn od prawej, na przemian w górę i w dół, z pominięciem kolumny 6.
    $i = 0;
    $ileBitow = count($kod) * 8;
    for ($prawa = $n - 1; $prawa >= 1; $prawa -= 2) {
        if ($prawa === 6) $prawa = 5;
        $wGore = (($prawa + 1) & 2) === 0;
        for ($pion = 0; $pion < $n; $pion++) {
            $y = $wGore ? $n - 1 - $pion : $pion;
            for ($j = 0; $j < 2; $j++) {
                $x = $prawa - $j;
                if (!$wzorzec[$y * $n + $x] && $i < $ileBitow) {
                    $m[$y * $n + $x] = ($kod[$i >> 3] >> (7 - ($i & 7))) & 1;
                    $i++;
                }
            }
        }
    }

    if ($maska === null) {
        $maska = 0;
        $najmniej = PHP_INT_MAX;
        for ($k = 0; $k < 8; $k++) {
            evk_qr_maskuj($m, $wzorzec, $n, $k);
            evk_qr_format($ustaw, $n, $poziom, $k);
            $kara = evk_qr_kara($m, $n);
            if ($kara < $najmniej) { $najmniej = $kara; $maska = $k; }
            evk_qr_maskuj($m, $wzorzec, $n, $k);
        }
    }
    evk_qr_maskuj($m, $wzorzec, $n, $maska);
    evk_qr_format($ustaw, $n, $poziom, $maska);

    return ['wersja' => $wersja, 'maska' => $maska, 'moduly' => array_chunk($m, $n)];
}

/**
 * Rysuje kod w GD: kwadrat $rozmiar px w kolorze jasnym, w nim moduły
 * o całkowitej liczbie pikseli (ostre krawędzie), wyśrodkowane, ze strefą
 * ciszy $cisza modułów. Gdy kwadrat jest mniejszy niż liczba modułów,
 * rośnie do niej (moduł nie bywa mniejszy niż 1 px).
 *
 * $jasny = null: przezroczyste tło (1.238.0) — kwadratu nie ma, same moduły
 * na tym, co już leży na obrazku. Strefa ciszy i jasne moduły to wtedy tło
 * pod spodem, więc kod czyta się tylko tam, gdzie moduły się od niego odcinają.
 */
function evk_qr_rysuj($img, array $moduly, int $x, int $y, int $rozmiar, int $ciemny, ?int $jasny, int $cisza = 2): void {
    $n       = count($moduly);
    $ile     = $n + 2 * $cisza;
    $rozmiar = max($rozmiar, $ile);
    $px      = intdiv($rozmiar, $ile);
    $start   = intdiv($rozmiar - $px * $ile, 2) + $cisza * $px;
    if ($jasny !== null) imagefilledrectangle($img, $x, $y, $x + $rozmiar - 1, $y + $rozmiar - 1, $jasny);
    foreach ($moduly as $r => $wiersz) {
        foreach ($wiersz as $c => $v) {
            if (!$v) continue;
            $x0 = $x + $start + $c * $px;
            $y0 = $y + $start + $r * $px;
            imagefilledrectangle($img, $x0, $y0, $x0 + $px - 1, $y0 + $px - 1, $ciemny);
        }
    }
}
