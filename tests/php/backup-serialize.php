<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — podmiana bezpieczna dla serializacji, wprost z PRAWDZIWEGO modułu.
 *
 * Bez _wp-stubs.php: moduł ma działać w gołym PHP-ie, a atrapy mogłyby to
 * przykryć. Definiujemy wyłącznie ABSPATH, bez którego plik kończy pracę
 * w pierwszej linii.
 *
 * TRYBY (argument pierwszy):
 *
 *   wartosci <base64 JSON>
 *       {"urls":[[stary,nowy]], "paths":[[stara,nowa]], "emails":bool,
 *        "values":[base64,…]}
 *       → [{"out":base64, "stats":{…}, "unser":true|false|null}, …]
 *       `unser` mówi, czy wynik da się odczytać unserialize() (null, gdy
 *       wejście nie było serializacją).
 *
 *   pary <base64 JSON>
 *       {"urls":…, "paths":…, "emails":…} → mapa „szukaj => zamień"
 *
 *   losowe <ziarno> <ile>
 *       Porównanie różnicowe: parser kontra WZORZEC NIEZALEŻNY, czyli
 *       unserialize() → podmiana na drzewie → serialize(). Tutaj wolno go
 *       użyć: dane są nasze i wygenerowane chwilę wcześniej (jedyna klasa
 *       to stdClass). W module unserialize() nie ma i mieć nie może.
 *       → {"ile":N, "rozjazdy":[…pierwsze trzy…], "niepoprawne":K,
 *          "podmian":P, "obiektow":O, "zagniezdzen":Z}
 *       Trzy ostatnie liczby są po to, żeby „zero rozjazdów" nie znaczyło
 *       „nic nie było do podmiany".
 */

define('ABSPATH', __DIR__ . '/');
$root = getenv('EVK_TEST_ROOT') ?: dirname(__DIR__, 2);
require $root . '/includes/backup/serialize-replace.php';

$tryb = $argv[1] ?? '';

function wejscie(string $arg): array {
    $j = json_decode((string) base64_decode($arg), true);
    if (!is_array($j)) { fwrite(STDERR, "zły JSON\n"); exit(2); }
    return $j;
}

function kompiluj(array $j): array {
    return evk_sr_compile(evk_sr_build_pairs($j['urls'] ?? [], $j['paths'] ?? [], !empty($j['emails'])));
}

/** Czy łańcuch jest poprawną serializacją — dla sprawdzenia WYNIKU. */
function odczytywalne(string $s): bool {
    if ($s === 'b:0;') return true;
    return @unserialize($s, ['allowed_classes' => false]) !== false;
}

if ($tryb === 'pary') {
    $j = wejscie($argv[2] ?? '');
    echo json_encode(evk_sr_build_pairs($j['urls'] ?? [], $j['paths'] ?? [], !empty($j['emails'])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tryb === 'wartosci') {
    $j = wejscie($argv[2] ?? '');
    $c = kompiluj($j);
    $wynik = [];
    foreach ($j['values'] ?? [] as $b64) {
        $v = (string) base64_decode($b64);
        $stats = [];
        $out = evk_sr_replace($v, $c, $stats);
        $wynik[] = [
            'out'   => base64_encode($out),
            'stats' => $stats,
            'unser' => evk_sr_looks_serialized($v) ? odczytywalne($out) : null,
        ];
    }
    echo json_encode($wynik);
    exit;
}

if ($tryb === 'losowe') {
    mt_srand((int) ($argv[2] ?? 1));
    $ile = (int) ($argv[3] ?? 200);

    $urls = [['https://stara.pl', 'https://nowa-znacznie-dluzsza-domena.com.pl']];
    $c    = evk_sr_compile(evk_sr_build_pairs($urls));

    /* Kawałki tekstu: adresy w różnych wariantach, polskie znaki (bajty ≠
       znaki), znaki udające składnię serializacji, granice domeny. */
    $kawalki = [
        'https://stara.pl/o-nas', 'http://www.stara.pl', 'https:\/\/stara.pl\/x',
        'https%3A%2F%2Fstara.pl%2Fa', '//stara.pl/img.jpg', 'https://stara.pl.eu',
        'https://stara.plus', 'zażółć gęślą jaźń', '";s:3:"x";}', 'a:1:{', '{"k":"v"}',
        ' ', "\n", '„cudzysłów"', 'https://stara.pl.', 'mail@stara.pl',
    ];
    $tekst = static function () use ($kawalki): string {
        $s = '';
        for ($i = mt_rand(0, 4); $i > 0; $i--) $s .= $kawalki[mt_rand(0, count($kawalki) - 1)];
        return $s;
    };
    $drzewo = static function (int $gl) use (&$drzewo, $tekst) {
        $r = mt_rand(0, $gl > 3 ? 5 : 9);
        switch ($r) {
            case 0: return mt_rand(-1000, 1000);
            case 1: return mt_rand(0, 1) === 1;
            case 2: return null;
            case 3: return mt_rand(0, 1000) / 7;
            case 4: case 5: return $tekst();
            case 6: // serializacja zagnieżdżona w łańcuchu
                return serialize($drzewo($gl + 1));
            case 7: // obiekt klasy, której nie ma
                $o = new stdClass();
                for ($i = mt_rand(0, 3); $i > 0; $i--) $o->{'p' . $i} = $drzewo($gl + 1);
                return $o;
            default:
                $a = [];
                for ($i = mt_rand(0, 4); $i > 0; $i--) {
                    $a[mt_rand(0, 1) ? $i : ('k' . $i . $tekst())] = $drzewo($gl + 1);
                }
                return $a;
        }
    };

    /* Wzorzec: ta sama podmiana tekstowa, ale na drzewie po unserialize().
       Klucze bez zmian — tak jak w module. Łańcuch, który sam jest
       serializacją, schodzi rekurencyjnie, tak jak w module. */
    $wzorzec = static function ($v) use (&$wzorzec, $c) {
        if (is_string($v)) {
            if (evk_sr_looks_serialized($v)) {
                $u = @unserialize($v);
                if ($u !== false || $v === 'b:0;') return serialize($wzorzec($u));
                return $v;   // wygląda na serializację, nie czyta się — bez zmian
            }
            $st = ['replaced' => 0];
            return evk_sr_plain($v, $c, $st);
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) $v[$k] = $wzorzec($x);
            return $v;
        }
        if (is_object($v)) {
            foreach (get_object_vars($v) as $k => $x) $v->$k = $wzorzec($x);
            return $v;
        }
        return $v;
    };

    $rozjazdy = [];
    $niepoprawne = 0;
    $podmian = $obiektow = $zagniezdzen = 0;
    for ($n = 0; $n < $ile; $n++) {
        $dane = $drzewo(0);
        /* Obiekty idą przez klasę, której NIE MA. Nazwa ma te same osiem
           znaków co stdClass, więc podmiana nie rusza żadnej długości —
           także w serializacjach zagnieżdżonych w łańcuchach. Wzorzec liczy
           na stdClass (na niekompletnej klasie PHP nie pozwala zmieniać
           właściwości), a wynik porównujemy po tej samej zamianie nazwy. */
        $std  = serialize($dane);
        $brak = str_replace('O:8:"stdClass"', 'O:8:"BrakKlas"', $std);
        $st   = [];
        $out  = evk_sr_replace($brak, $c, $st);
        $ref  = str_replace('O:8:"stdClass"', 'O:8:"BrakKlas"', serialize($wzorzec(unserialize($std))));
        if (!odczytywalne($out)) $niepoprawne++;
        $podmian     += $st['replaced'];
        $obiektow    += substr_count($brak, 'O:8:"BrakKlas"');
        $zagniezdzen += preg_match_all('/s:\d+:"[aOsibdN][:;]/', $brak);
        if ($out !== $ref && count($rozjazdy) < 3) {
            $rozjazdy[] = ['we' => $brak, 'parser' => $out, 'wzorzec' => $ref];
        }
    }
    echo json_encode(['ile' => $ile, 'rozjazdy' => $rozjazdy, 'niepoprawne' => $niepoprawne,
                      'podmian' => $podmian, 'obiektow' => $obiektow, 'zagniezdzen' => $zagniezdzen],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

fwrite(STDERR, "Nieznany tryb: $tryb\n");
exit(2);
