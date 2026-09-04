<?php
if (!defined('ABSPATH')) exit;


// =========================================================================
// WALIDACJA SKŁADNI PHP
// =========================================================================

function evk_snippet_validate_syntax(string $code) {
    if (empty(trim($code))) return true;

    $old = error_reporting(0);
    @token_get_all("<?php\n" . $code);
    error_reporting($old);

    $err = error_get_last();
    if ($err && in_array($err['type'], [E_PARSE, E_COMPILE_ERROR], true)) {
        @error_clear_last();
        $line = 0;
        if (preg_match('/on line (\d+)/', $err['message'], $m)) {
            $line = max(0, (int)$m[1] - 1);
        }
        return ['message' => $err['message'], 'line' => $line];
    }
    return true;
}

// =========================================================================
// LOGOWANIE BŁĘDÓW
// =========================================================================

function evk_snippet_log_error(string $type, string $message, string $slug, int $line = 0, string $code_ctx = ''): void {
    $logs = get_option(EVK_SNIPPETS_LOG_OPTION, []);
    if (!is_array($logs)) $logs = [];

    array_unshift($logs, [
        'timestamp' => current_time('mysql'),
        'type'      => sanitize_text_field($type),
        'message'   => wp_strip_all_tags($message),
        'slug'      => sanitize_text_field($slug),
        'line'      => absint($line),
        'context'   => $code_ctx ? substr($code_ctx, 0, 2000) : '',
    ]);

    if (count($logs) > EVK_SNIPPETS_MAX_LOG) {
        $logs = array_slice($logs, 0, EVK_SNIPPETS_MAX_LOG);
    }
    update_option(EVK_SNIPPETS_LOG_OPTION, $logs);
}

function evk_snippet_code_context(string $code, int $line, int $ctx = 3): string {
    $lines = explode("\n", $code);
    $start = max(0, $line - $ctx - 1);
    $end   = min(count($lines), $line + $ctx);
    $out   = [];
    for ($i = $start; $i < $end; $i++) {
        $marker = ($i === $line - 1) ? ' >>> ' : '     ';
        $out[]  = sprintf('%s%4d: %s', $marker, $i + 1, $lines[$i]);
    }
    return implode("\n", $out);
}

// =========================================================================
// ZNACZNIK — czyj kod pracuje w tej chwili
// =========================================================================

/**
 * Stos wykonywanych wpisów.
 *
 * PO CO. Po błędzie krytycznym trzeba wiedzieć, CZYJ kod pękł — inaczej jedyną
 * odpowiedzią jest zgaszenie wszystkiego. Do 1.147.1 tak właśnie było:
 * `evk_snippet_log_error(..., 'unknown', ...)` i główny włącznik na off, więc
 * jeden zły wpis zabierał ze sobą wszystkie pozostałe.
 *
 * DLACZEGO W PAMIĘCI, A NIE W BAZIE. Funkcja zamykająca (`shutdown`) leci
 * w TYM SAMYM procesie co błąd — zwykła właściwość statyczna jest wtedy nadal
 * na miejscu. Zapisywanie znacznika do bazy przed każdym `eval()` kosztowałoby
 * jeden zapis na wpis na każde wyświetlenie strony, a kupowało wyłącznie
 * przypadek, w którym PHP nie odpala już nawet funkcji zamykających
 * (przepełnienie stosu) — a tam i tak nie mamy jak niczego zapisać.
 *
 * STOS, NIE POJEDYNCZA WARTOŚĆ. Wpis z miejsca „zawsze" może sam wywołać
 * `do_action('wp_footer')` i wtedy w środku jednego wykonania siedzi drugie.
 * Przy pojedynczej wartości zakończenie tego wewnętrznego zerowałoby znacznik
 * zewnętrznego i winny wychodziłby na nieznanego.
 */
final class EVK_Snippet_Znacznik {
    private static $stos = [];

    public static function zapal(array $znacznik): void { self::$stos[] = $znacznik; }
    public static function zgas(): void { array_pop(self::$stos); }
    public static function biezacy(): ?array { return self::$stos ? end(self::$stos) : null; }
}

/** Znacznik wpisu z listy. */
function evk_snippet_znacznik_wpisu(array $wpis): array {
    return ['zakres' => 'wpis', 'id' => (int) ($wpis['id'] ?? 0),
            'tytul'  => (string) ($wpis['tytul'] ?? '')];
}

/** Znacznik trybu zaawansowanego — jedno pole, bez identyfikatora wpisu. */
function evk_snippet_znacznik_advanced(): array {
    return ['zakres' => 'advanced', 'id' => 0, 'tytul' => 'Tryb zaawansowany'];
}

/** Plik, w którym stoi `eval()`. Po nim rozpoznajemy SWÓJ błąd — patrz engine.php. */
function evk_snippet_plik_eval(): string { return __FILE__; }

// =========================================================================
// ODCIĘCIE WINNEGO
// =========================================================================

/**
 * Wyłącza to i TYLKO to, co się wywróciło.
 *
 * Trzy zakresy, w kolejności od najwęższego:
 *
 *  · `wpis` — gaśnie jeden wpis, reszta pracuje dalej i główny włącznik zostaje
 *    włączony. To jest cały sens znacznika.
 *  · `advanced` — gaśnie tryb zaawansowany; wpisów nie ma po co ruszać, bo to
 *    osobne pole i osobna opcja.
 *  · brak znacznika — nie wiemy, czyj to kod (błąd wybuchł w haku, który
 *    zarejestrował sam snippet, więc leciał już poza naszym `eval()`). Wtedy
 *    wraca dawne zachowanie: główny włącznik na off. Strona wstaje kosztem
 *    wszystkich snippetów — to jedyne wyjście, które nie zostawia witryny
 *    w pętli błędu 500.
 */
function evk_snippet_odetnij(string $typ, string $wiadomosc, int $linia): array {
    $znacznik = EVK_Snippet_Znacznik::biezacy();
    $zakres   = $znacznik['zakres'] ?? 'nieznany';

    if ($zakres === 'wpis' && !empty($znacznik['id'])) {
        update_post_meta($znacznik['id'], EVK_SNIPPET_META_WLACZ, 0);
        update_post_meta($znacznik['id'], EVK_SNIPPET_META_AWARIA, [
            'type' => $typ, 'message' => $wiadomosc, 'line' => $linia,
            'czas' => current_time('mysql'),
        ]);
    } elseif ($zakres === 'advanced') {
        update_option(EVK_SNIPPETS_ADVANCED_ENABLED, 0);
    } else {
        update_option(EVK_SNIPPETS_ENABLED_OPTION, 0);
    }

    $wpis = [
        'zakres'  => $zakres,
        'id'      => (int) ($znacznik['id'] ?? 0),
        'tytul'   => (string) ($znacznik['tytul'] ?? ''),
        'type'    => $typ,
        'message' => $wiadomosc,
        'line'    => $linia,
        /* `slug` zostaje dla zgodności ze starym powiadomieniem — panel czyta
           je z transjentu, a ten potrafi przeżyć aktualizację wtyczki. */
        'slug'    => $zakres === 'nieznany' ? 'unknown' : (string) ($znacznik['tytul'] ?? ''),
    ];
    set_transient(EVK_SNIPPETS_FATAL_TRANSIENT, $wpis, DAY_IN_SECONDS);
    return $wpis;
}

// =========================================================================
// WYKONYWANIE SNIPPETÓW
// =========================================================================

function evk_snippet_execute(string $code, string $slug, array $znacznik = []): string {
    if (empty(trim($code))) return '';

    /* Znacznik zapalony PRZED walidacją, nie przed samym `eval()`: błąd składni
       też jest błędem tego wpisu i też ma wyłączyć wyłącznie jego. */
    EVK_Snippet_Znacznik::zapal($znacznik);
    try {
        return evk_snippet_execute_wewnetrzne($code, $slug);
    } finally {
        /* `finally`, bo między zapaleniem a zgaszeniem stoi `eval()` cudzego
           kodu: `exit` albo wyjątek przepuszczony wyżej zostawiłby znacznik
           zapalony i następny błąd — czyjkolwiek — poszedłby na konto tego wpisu. */
        EVK_Snippet_Znacznik::zgas();
    }
}

function evk_snippet_execute_wewnetrzne(string $code, string $slug): string {
    $validation = evk_snippet_validate_syntax($code);
    if (is_array($validation)) {
        evk_snippet_log_error('PHP Syntax Error', $validation['message'], $slug, $validation['line'],
            evk_snippet_code_context($code, $validation['line']));
        evk_snippet_odetnij('Syntax Error', $validation['message'], $validation['line']);
        return '';
    }

    $error_occurred = false;
    set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error_occurred, $slug, $code) {
        if (!WP_DEBUG && in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED, E_STRICT], true)) return true;
        $error_occurred = true;
        $types = [E_WARNING => 'PHP Warning', E_USER_WARNING => 'PHP Warning',
                  E_NOTICE  => 'PHP Notice',  E_USER_NOTICE  => 'PHP Notice',
                  E_DEPRECATED => 'PHP Deprecated', E_USER_DEPRECATED => 'PHP Deprecated'];
        $type = $types[$errno] ?? 'PHP Error';
        evk_snippet_log_error($type, $errstr, $slug, $errline,
            evk_snippet_code_context($code, $errline));
        return true;
    });

    ob_start();
    try {
        @eval('?>' . $code);
    } catch (ParseError $e) {
        $error_occurred = true;
        evk_snippet_log_error('PHP Parse Error', $e->getMessage(), $slug, $e->getLine(),
            evk_snippet_code_context($code, $e->getLine()));
        evk_snippet_odetnij('Parse Error', $e->getMessage(), $e->getLine());
    } catch (Throwable $e) {
        $error_occurred = true;
        evk_snippet_log_error(get_class($e), $e->getMessage(), $slug, $e->getLine(),
            evk_snippet_code_context($code, $e->getLine()));
        /* `Error` to wywrotka (wywołanie nieistniejącej funkcji, zły typ);
           `Exception` to sprawa rzucona świadomie i wpis ma prawo ją obsłużyć
           gdzie indziej. Gasimy tylko to pierwsze. */
        if ($e instanceof Error) {
            evk_snippet_odetnij(get_class($e), $e->getMessage(), $e->getLine());
        }
    }

    $output = ob_get_clean();
    restore_error_handler();
    return $error_occurred ? '' : $output;
}
