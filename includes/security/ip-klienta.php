<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — adres IP odwiedzającego (1.233.0)
 *
 * Jedno źródło adresu dla limitu logowań, newslettera (zapis zgody, limit
 * zapisów) i logów 404. Do 1.232.0 każde z nich czytało `REMOTE_ADDR` samo —
 * a na stronie za Cloudflare to adres SERWERA CLOUDFLARE, nie odwiedzającego.
 * Skutki: limit logowań blokował węzeł Cloudflare, czyli naraz wszystkich,
 * którzy przez niego wchodzą (także administratora); limit 10 zapisów na
 * godzinę dzielił cały ruch z danego węzła; zgoda na newsletter zapisywała
 * adres Cloudflare jako adres osoby.
 *
 * NAGŁÓWEK TYLKO OD ZAUFANEGO POŚREDNIKA. `CF-Connecting-IP` i
 * `X-Forwarded-For` może wysłać każdy, kto zna adres serwera — gdyby wtyczka
 * wierzyła im zawsze, atakujący zmieniałby „swój" adres przy każdej próbie
 * logowania (limit przestaje działać) albo podawał cudzy (blokuje np.
 * administratora). Dlatego nagłówek liczy się wyłącznie wtedy, gdy połączenie
 * przyszło z adresu pośrednika: z sieci Cloudflare (lista niżej) albo z
 * adresów wpisanych w ustawieniach.
 */

/**
 * Sieci Cloudflare — https://www.cloudflare.com/ips-v4 i /ips-v6, stan
 * z 2026-09-24 (niezmienny od 2021). Cloudflare zapowiada zmiany z wyprzedzeniem;
 * nowy zakres, zanim trafi do wtyczki, da się dopisać w ustawieniach
 * („Dodatkowe zaufane adresy").
 */
const EVK_CLOUDFLARE_SIECI = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/** Tryby: brak pośrednika, Cloudflare, inny pośrednik (np. load balancer). */
const EVK_IP_TRYBY = ['brak', 'cloudflare', 'inne'];

/**
 * Poprawny adres IP albo pusty łańcuch. Adres IPv4 zapisany jako IPv6
 * („::ffff:1.2.3.4" — tak podaje go serwer nasłuchujący na obu rodzinach)
 * wraca jako zwykły IPv4: inaczej nie pasowałby do żadnej sieci IPv4
 * i ten sam człowiek miałby w logach dwa różne adresy.
 */
function evk_ip_poprawny($ip): string {
    $ip = trim((string) $ip);
    if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) $ip = $m[1];
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Czy adres należy do sieci (CIDR) albo jest nim samym (adres bez „/").
 * IPv4 i IPv6 porównywane na bajtach z `inet_pton()`; adres jednej rodziny
 * nigdy nie pasuje do sieci drugiej.
 */
function evk_ip_w_sieci(string $ip, string $siec): bool {
    $siec = trim($siec);
    if ($siec === '') return false;
    [$adres, $maska] = array_pad(explode('/', $siec, 2), 2, null);
    $a = @inet_pton($ip);
    $s = @inet_pton((string) $adres);
    if ($a === false || $s === false || strlen($a) !== strlen($s)) return false;
    $bity = $maska === null ? strlen($a) * 8 : (int) $maska;
    if ($bity < 0 || $bity > strlen($a) * 8 || ($maska !== null && !ctype_digit($maska))) return false;
    $pelne = intdiv($bity, 8);
    if (substr($a, 0, $pelne) !== substr($s, 0, $pelne)) return false;
    $reszta = $bity % 8;
    if ($reszta === 0) return true;
    $m = (0xFF << (8 - $reszta)) & 0xFF;
    return (ord($a[$pelne]) & $m) === (ord($s[$pelne]) & $m);
}

/**
 * Wpisy z pola „zaufane adresy": po jednym na linię (albo po przecinku),
 * każdy to adres albo sieć CIDR. Śmieci wypadają — pole nie może niechcący
 * zaufać wszystkiemu.
 */
function evk_ip_lista_sieci(string $tekst): array {
    $wynik = [];
    foreach (preg_split('/[\s,;]+/', $tekst) ?: [] as $wpis) {
        $wpis = trim($wpis);
        if ($wpis === '') continue;
        [$adres, $maska] = array_pad(explode('/', $wpis, 2), 2, null);
        if (!evk_ip_poprawny($adres)) continue;
        if ($maska !== null) {
            $max = strpos($adres, ':') !== false ? 128 : 32;
            if (!ctype_digit($maska) || (int) $maska > $max) continue;
            // Sieć /0 to „ufaj wszystkim" — dokładnie to, przed czym ta funkcja chroni.
            if ((int) $maska === 0) continue;
        }
        $wynik[] = $wpis;
    }
    return array_values(array_unique($wynik));
}

/** Ustawienia z `evk_security`: tryb i dodatkowe zaufane adresy. */
function evk_ip_ustawienia(): array {
    $s    = (array) get_option('evk_security', []);
    $tryb = in_array($s['proxy_tryb'] ?? '', EVK_IP_TRYBY, true) ? $s['proxy_tryb'] : 'brak';
    return ['tryb' => $tryb, 'zaufane' => evk_ip_lista_sieci((string) ($s['proxy_zaufane'] ?? ''))];
}

/** Czy połączenie z tego adresu przyszło od zaufanego pośrednika. */
function evk_ip_zaufany_posrednik(string $ip, array $ust): bool {
    if ($ip === '' || $ust['tryb'] === 'brak') return false;
    $sieci = $ust['zaufane'];
    if ($ust['tryb'] === 'cloudflare') $sieci = array_merge(EVK_CLOUDFLARE_SIECI, $sieci);
    foreach ($sieci as $siec) {
        if (evk_ip_w_sieci($ip, $siec)) return true;
    }
    return false;
}

/**
 * Adres IP odwiedzającego — z uwzględnieniem zaufanego pośrednika.
 *
 * Cloudflare: `CF-Connecting-IP`, ale tylko od węzła Cloudflare.
 * Inny pośrednik: `X-Forwarded-For` czytany OD PRAWEJ — każdy pośrednik
 * dopisuje adres, od którego dostał żądanie, na końcu, a lewą część listy
 * dowolnie ustawia sam odwiedzający. Pierwszy od prawej adres, który nie jest
 * zaufanym pośrednikiem, to klient.
 *
 * Bez adresu w ogóle (wiersz poleceń) — pusty łańcuch; wywołujący decydują,
 * co wtedy (newsletter zapisuje „0.0.0.0").
 */
function evk_ip_klienta(?array $serwer = null, ?array $ust = null): string {
    $serwer = $serwer ?? $_SERVER;
    $zdalny = evk_ip_poprawny($serwer['REMOTE_ADDR'] ?? '');
    $ust    = $ust ?? evk_ip_ustawienia();
    if (!evk_ip_zaufany_posrednik($zdalny, $ust)) return $zdalny;

    if ($ust['tryb'] === 'cloudflare') {
        return evk_ip_poprawny($serwer['HTTP_CF_CONNECTING_IP'] ?? '') ?: $zdalny;
    }

    $lancuch = array_map('trim', explode(',', (string) ($serwer['HTTP_X_FORWARDED_FOR'] ?? '')));
    for ($i = count($lancuch) - 1; $i >= 0; $i--) {
        $ip = evk_ip_poprawny($lancuch[$i]);
        if ($ip === '') break;          // śmieć w łańcuchu — dalej w lewo nie wierzymy
        if (!evk_ip_zaufany_posrednik($ip, $ust)) return $ip;
    }
    return $zdalny;
}

/**
 * Co widać w bieżącym żądaniu — do podpowiedzi w panelu.
 *
 * `przez_cloudflare`: połączenie przyszło z sieci Cloudflare (niezależnie od
 * ustawienia), czyli strona na pewno za nim stoi. `wg_trybu`: adres, jaki
 * wtyczka przyjęłaby w każdym z trybów przy obecnych zaufanych adresach —
 * żeby wybrać tryb, patrząc na własny adres, bez zapisywania na próbę.
 */
function evk_ip_diagnoza(?array $serwer = null): array {
    $serwer = $serwer ?? $_SERVER;
    $zdalny = evk_ip_poprawny($serwer['REMOTE_ADDR'] ?? '');
    $ust    = evk_ip_ustawienia();
    $wg_trybu = [];
    foreach (EVK_IP_TRYBY as $tryb) {
        $wg_trybu[$tryb] = evk_ip_klienta($serwer, ['tryb' => $tryb] + $ust);
    }
    return [
        'zdalny'           => $zdalny,
        'cf_naglowek'      => evk_ip_poprawny($serwer['HTTP_CF_CONNECTING_IP'] ?? ''),
        'xff'              => trim((string) ($serwer['HTTP_X_FORWARDED_FOR'] ?? '')),
        'przez_cloudflare' => evk_ip_zaufany_posrednik($zdalny, ['tryb' => 'cloudflare', 'zaufane' => []]),
        'tryb'             => $ust['tryb'],
        'wg_trybu'         => $wg_trybu,
    ];
}
