<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — skrócone pliki frontu (1.248.0).
 *
 * Zgłoszone: „w kodzie strony jest mnóstwo poprawek, opisów w skryptach,
 * które wcale nie są tam potrzebne". Na stronę jadą więc wersje `.min` plików
 * JS i CSS wtyczki, zbudowane przez tools/minifikuj.js (tam lista plików
 * i pomiar: 467 → 156 KiB). Źródła z komentarzami zostają w repozytorium —
 * są dokumentacją i to na nich chodzą testy zachowania.
 *
 * Źródło zamiast `.min`:
 *   · przy `SCRIPT_DEBUG` — zwyczaj WordPressa: ktoś szuka błędu i chce czytać
 *     kod z komentarzami;
 *   · gdy pliku `.min` nie ma — wtyczka skopiowana bez kroku budowania.
 *     Lepiej wolniej niż wcale.
 */

/**
 * Adres pliku JS albo CSS wtyczki → adres jego wersji `.min`, jeśli ma być
 * użyta. Adresy spoza wtyczki i pliki już skrócone wracają bez zmian.
 */
function evk_zasob_url(string $url): string {
    if ((defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) || !defined('EVOKE_ONE_URL')) return $url;
    if (strpos($url, EVOKE_ONE_URL) !== 0) return $url;
    $wzgledna = substr($url, strlen(EVOKE_ONE_URL));
    if (preg_match('/\.min\.(?:js|css)$/', $wzgledna) || !preg_match('/^(.+)\.(js|css)$/', $wzgledna, $m)) return $url;
    $min = $m[1] . '.min.' . $m[2];
    return file_exists(dirname(__DIR__) . '/' . $min) ? EVOKE_ONE_URL . $min : $url;
}

// =========================================================================
// KOD DRUKOWANY WPROST W HTML — BEZ KOMENTARZY (1.249.0)
// =========================================================================
/*
 * Moduły drukują część skryptów i stylów wprost do strony (`<script>` w <head>,
 * `wp_add_inline_script()`, moduł fali w render()). Te wstawki nie mają pliku,
 * więc nie przechodzą przez tools/minifikuj.js — komentarze zdejmujemy przy
 * druku. Tylko komentarze i nadmiar odstępów: nazwy i składnia zostają, więc
 * wynik czyta się jak źródło bez objaśnień.
 *
 * LEKSER, NIE WYRAŻENIE REGULARNE. `//` bywa w adresach w łańcuchach, `/*`
 * w wyrażeniach regularnych, a shadery fali siedzą w literałach szablonowych
 * z `${…}`. Każdy z tych przypadków wyrażenie regularne psuje. Test
 * (minifikacja) porównuje ciągi tokenów strony z SCRIPT_DEBUG i bez niego:
 * mają być identyczne, więc zmiana czegokolwiek poza komentarzami zapala.
 *
 * Nowa linia zostaje tam, gdzie była (także w miejscu komentarza wielowierszowego):
 * od niej zależy automatyczne wstawianie średników.
 */

/** Zdejmować komentarze? Nie przy SCRIPT_DEBUG — ktoś szuka błędu i chce czytać kod. */
function evk_wstawki_skracaj(): bool {
    return !(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG);
}

/**
 * JavaScript bez komentarzy. Łańcuchy, literały szablonowe (z zagnieżdżonym
 * `${…}`) i wyrażenia regularne zostają nietknięte; odstęp w miejscu
 * komentarza albo ciąg odstępów zamienia się w jedną spację albo jedną nową
 * linię. Kod, którego lekser nie domyka (niezamknięty komentarz, łańcuch czy
 * szablon), wraca bez zmian.
 */
function evk_js_bez_komentarzy(string $js): string {
    if (!evk_wstawki_skracaj() || $js === '') return $js;
    $n = strlen($js);
    $out = '';
    $i = 0;
    $odstep = '';      // zaległy odstęp: '', ' ' albo "\n"
    $ostatni = '';     // ostatni znaczący znak na wyjściu; 'a' = słowo
    $slowo = '';       // to słowo, gdy $ostatni === 'a'
    $szablony = [];    // stos otwartych `${`: głębokość zwykłych nawiasów { w każdym
    static $przedRegex = ['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await'];

    /* Fragment literału szablonowego od $i do zamykającego ` albo do `${`.
       Zwraca nową pozycję albo -1, gdy literał się nie domyka. */
    $szablon = static function (int $i) use ($js, $n, &$out, &$szablony): int {
        while ($i < $n) {
            $c = $js[$i];
            if ($c === '\\') { $out .= substr($js, $i, 2); $i += 2; continue; }
            if ($c === '`') { $out .= '`'; return $i + 1; }
            if ($c === '$' && $i + 1 < $n && $js[$i + 1] === '{') { $out .= '${'; $szablony[] = 0; return $i + 2; }
            $out .= $c;
            $i++;
        }
        return -1;
    };

    while ($i < $n) {
        $c = $js[$i];

        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === "\v") {
            if ($c === "\n" || $c === "\r") $odstep = "\n";
            elseif ($odstep === '') $odstep = ' ';
            $i++;
            continue;
        }
        if ($c === '/' && $i + 1 < $n && ($js[$i + 1] === '/' || $js[$i + 1] === '*')) {
            if ($js[$i + 1] === '/') {
                $k = strpos($js, "\n", $i);
                $i = $k === false ? $n : $k;
                continue;
            }
            $k = strpos($js, '*/', $i + 2);
            if ($k === false) return $js;
            if (strpbrk(substr($js, $i, $k - $i), "\n\r") !== false) $odstep = "\n";
            elseif ($odstep === '') $odstep = ' ';
            $i = $k + 2;
            continue;
        }

        if ($odstep !== '') {
            if ($out !== '') $out .= $odstep;
            $odstep = '';
        }

        if ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $n && $js[$j] !== $c) {
                if ($js[$j] === '\\') $j++;
                elseif ($js[$j] === "\n") return $js;
                $j++;
            }
            if ($j >= $n) return $js;
            $out .= substr($js, $i, $j + 1 - $i);
            $i = $j + 1;
            $ostatni = '"';
            continue;
        }
        if ($c === '`') {
            $out .= '`';
            $i = $szablon($i + 1);
            if ($i < 0) return $js;
            $ostatni = substr($out, -1) === '`' ? '`' : '{';
            continue;
        }
        if ($c === '{') {
            if ($szablony) $szablony[count($szablony) - 1]++;
            $out .= '{';
            $ostatni = '{';
            $i++;
            continue;
        }
        if ($c === '}') {
            $top = count($szablony) - 1;
            if ($top >= 0 && $szablony[$top] === 0) {
                array_pop($szablony);
                $out .= '}';
                $i = $szablon($i + 1);
                if ($i < 0) return $js;
                $ostatni = substr($out, -1) === '`' ? '`' : '{';
                continue;
            }
            if ($top >= 0) $szablony[$top]--;
            $out .= '}';
            $ostatni = '}';
            $i++;
            continue;
        }
        if ($c === '/') {
            $regex = $ostatni === '' || strpos('(,=:[!&|?{};+-*%<>~^', $ostatni) !== false
                || ($ostatni === 'a' && in_array($slowo, $przedRegex, true));
            if ($regex) {
                $j = $i + 1;
                $klasa = false;
                while ($j < $n) {
                    $z = $js[$j];
                    if ($z === '\\') { $j += 2; continue; }
                    if ($z === "\n") return $js;
                    if ($z === '[') $klasa = true;
                    elseif ($z === ']') $klasa = false;
                    elseif ($z === '/' && !$klasa) break;
                    $j++;
                }
                if ($j >= $n) return $js;
                $j++;
                while ($j < $n && (ctype_alpha($js[$j]))) $j++;   // flagi
                $out .= substr($js, $i, $j - $i);
                $i = $j;
                $ostatni = '"';   // po wyrażeniu jak po łańcuchu: dalej dzielenie
                continue;
            }
        }
        if (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 128) {
            $j = $i + 1;
            while ($j < $n && (ctype_alnum($js[$j]) || $js[$j] === '_' || $js[$j] === '$' || ord($js[$j]) >= 128
                || ($js[$j] === '.' && ctype_digit($c)))) $j++;
            $slowo = substr($js, $i, $j - $i);
            $out .= $slowo;
            $ostatni = 'a';
            $i = $j;
            continue;
        }
        $out .= $c;
        $ostatni = $c;
        $i++;
    }
    return $szablony ? $js : $out;
}

/**
 * CSS bez komentarzy. Łańcuchy zostają nietknięte; ciąg odstępów (także
 * w miejscu komentarza) zamienia się w jedną spację. Niezamknięty komentarz
 * albo łańcuch — kod wraca bez zmian.
 */
function evk_css_bez_komentarzy(string $css): string {
    if (!evk_wstawki_skracaj() || $css === '') return $css;
    $n = strlen($css);
    $out = '';
    $odstep = false;
    for ($i = 0; $i < $n; ) {
        $c = $css[$i];
        if ($c === '/' && $i + 1 < $n && $css[$i + 1] === '*') {
            $k = strpos($css, '*/', $i + 2);
            if ($k === false) return $css;
            $odstep = true;
            $i = $k + 2;
            continue;
        }
        if (ctype_space($c)) { $odstep = true; $i++; continue; }
        if ($odstep && $out !== '') $out .= ' ';
        $odstep = false;
        if ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $n && $css[$j] !== $c) {
                if ($css[$j] === '\\') $j++;
                elseif ($css[$j] === "\n") return $css;
                $j++;
            }
            if ($j >= $n) return $css;
            $out .= substr($css, $i, $j + 1 - $i);
            $i = $j + 1;
            continue;
        }
        $out .= $c;
        $i++;
    }
    return $out;
}

/**
 * Fragment HTML z wstawkami: każdy `<script>` bez `src` (zwykły albo moduł)
 * i każdy `<style>` przechodzi przez funkcje wyżej. JSON-LD i inne typy
 * danych zostają bez zmian.
 *
 * SKANER, NIE WYRAŻENIE REGULARNE (1.249.1). W 1.249.0 szło tu
 * `(.*?)</script>` po całym buforze stopki. Na wstawce od ~1 MB wzwyż PCRE
 * przekraczało limit kroków (pcre.backtrack_limit, milion) i
 * preg_replace_callback() oddawało null. `(string) null` to pusty tekst, więc
 * znikała CAŁA stopka. Builder Bricksa drukuje w niej właśnie tyle danych
 * i przestał się ładować: nie dostawał swoich skryptów. Teraz treść
 * wstawki jest tylko wycinana (strpos), a wyrażenia regularne chodzą
 * wyłącznie po atrybutach jednego znacznika.
 *
 * @param bool $tylkoNasze Tylko znaczniki z `id="evk-…"` — przy buforze całego
 *                         <head>, w którym jest też cudzy kod.
 */
function evk_wstawki_bez_komentarzy(string $html, bool $tylkoNasze = false): string {
    if (!evk_wstawki_skracaj() || $html === '') return $html;
    $n   = strlen($html);
    $out = '';
    $poz = 0;
    while ($poz < $n) {
        $s = stripos($html, '<script', $poz);
        $t = stripos($html, '<style', $poz);
        if ($s === false && $t === false) break;
        if ($s !== false && ($t === false || $s < $t)) { $start = $s; $tag = 'script'; }
        else { $start = (int) $t; $tag = 'style'; }
        $poNazwie = $start + 1 + strlen($tag);
        $z = $html[$poNazwie] ?? '';
        if ($z !== '>' && $z !== '/' && !ctype_space($z)) {   // `<scripts…`, `<styled…` — inny znacznik
            $out .= substr($html, $poz, $poNazwie - $poz);
            $poz  = $poNazwie;
            continue;
        }
        $gt = strpos($html, '>', $poNazwie);
        if ($gt === false) break;
        /* Wstawkę kończy pierwsze `</script` (`</style`) — tak samo czyta ją
           przeglądarka, łańcuch w środku skryptu też. */
        $koniec = stripos($html, '</' . $tag, $gt + 1);
        if ($koniec === false) break;
        $out .= substr($html, $poz, $gt + 1 - $poz)
            . evk_wstawka_bez_komentarzy($tag, substr($html, $poNazwie, $gt - $poNazwie), substr($html, $gt + 1, $koniec - $gt - 1), $tylkoNasze);
        $poz = $koniec;
    }
    return $out . substr($html, $poz);
}

/** Treść jednej wstawki: bez komentarzy, gdy to (nasz) JS albo CSS; inaczej bajt w bajt. */
function evk_wstawka_bez_komentarzy(string $tag, string $atrybuty, string $tresc, bool $tylkoNasze): string {
    if ($tylkoNasze && !preg_match('#(?:^|\s)id\s*=\s*["\']?evk-#i', $atrybuty)) return $tresc;
    if ($tag === 'style') return evk_css_bez_komentarzy($tresc);
    if (preg_match('#(?:^|\s)src\s*=#i', $atrybuty)) return $tresc;
    if (preg_match('#(?:^|\s)type\s*=\s*["\']?([^"\'\s>]+)#i', $atrybuty, $typ)
        && !in_array(strtolower($typ[1]), ['text/javascript', 'module', 'application/javascript'], true)) return $tresc;
    return evk_js_bez_komentarzy($tresc);
}

/*
 * <head> I STOPKA: bufor od pierwszego do ostatniego callbacka `wp_head`
 * i `wp_footer`, a z niego tylko znaczniki z `id="evk-…"` — nasze własne
 * (`evk-darkmode-js`, `evk-scroll-lock`…) i te, które WordPress składa dla
 * naszych uchwytów (`evk-gsap-js-after`, `evk-accessibility-css-inline-css`).
 * Cudzy kod zostaje bajt w bajt. Jedno miejsce zamiast owijania każdego
 * modułu z osobna — i nowy moduł z wstawką w <head> jest objęty sam.
 *
 * Gdy ktoś po drodze zamknie albo zostawi otwarty bufor, poziom się nie
 * zgadza i nie ruszamy niczego: wszystko wychodzi tak, jak zostało wypisane.
 *
 * BUILDER BRICKSA (oba okna) — bez bufora (1.249.1). Tam nie ma czego
 * skracać dla gościa, a stopka niesie megabajty danych buildera: każda
 * pomyłka w obróbce zatrzymuje całą pracę na stronie.
 */
function evk_wstawki_bufor_start(): void {
    if (!evk_wstawki_skracaj()) return;
    if (function_exists('evk_w_builderze') && evk_w_builderze()) return;
    ob_start();
    $GLOBALS['evk_wstawki_bufory'][] = ob_get_level();
}

function evk_wstawki_bufor_koniec(): void {
    $poziom = is_array($GLOBALS['evk_wstawki_bufory'] ?? null) ? array_pop($GLOBALS['evk_wstawki_bufory']) : null;
    if ($poziom === null || ob_get_level() !== $poziom) return;
    echo evk_wstawki_bez_komentarzy((string) ob_get_clean(), true);
}

foreach (['wp_head', 'wp_footer'] as $evk_hak) {
    add_action($evk_hak, 'evk_wstawki_bufor_start', PHP_INT_MIN);
    add_action($evk_hak, 'evk_wstawki_bufor_koniec', PHP_INT_MAX);
}
unset($evk_hak);
