<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Atrapa dostawców AI (Gemini, Claude, OpenAI) dla testów tłumaczenia AI
 * (1.260.0). Filtr `pre_http_request` przechwytuje żądania na PRAWDZIWE
 * adresy dostawców — test sprawdza więc adres, nagłówki i ciało, które
 * wtyczka naprawdę by wysłała — zapisuje je i odpowiada w kształcie danego
 * dostawcy.
 *
 * Tłumaczenie atrapy: każdy węzeł tekstu dostaje przedrostek „EN:” (kod
 * z $GLOBALS['evk_t_ai_kod']); znaczniki i tagi `{…}` zostają, więc strażnik
 * przepuszcza. Tekst z „ZEPSUJ” wraca bez znaczników — strażnik odrzuca.
 *
 * $GLOBALS['evk_t_ai_scenariusz']: ok | 429 | 429-raz | limit-wydatkow |
 * dzienny | retry-gemini | 401 | odmowa | zly-json. `429-raz`: pierwsze
 * żądanie (znacznik w pliku — działa też między żądaniami serwera) dostaje
 * 429, kolejne idą jak `ok`.
 * $GLOBALS['evk_t_ai_zadania']: lista {url, headers, body} przechwyconych żądań.
 *
 * Model inny niż domyślny dostawcy (1.262.0) dopisuje się do przedrostka:
 * „EN[gpt-test-c]:” — ponowne tłumaczenie innym modelem daje inny tekst.
 * $GLOBALS['evk_t_ai_w_trakcie']: funkcja wołana w środku zapytania, przed
 * odpowiedzią (np. „Sprawdzone” klikane w trakcie przebiegu).
 *
 * Obraz w zapytaniu (1.271.0, alt z AI): treść wieloczęściowa u wszystkich
 * trzech dostawców; $GLOBALS['evk_t_ai_obrazy'] — [szerokość, wysokość, mime,
 * bajty] każdego wysłanego obrazu. Zapytanie z obrazem dostaje opis
 * „Opis obrazu {plik}” (plik z wiersza „File:” wiadomości) pod kluczem t1.
 *
 * Panel w przeglądarce (serwer `php -S`) dostaje tę atrapę jako mu-plugin
 * składany przez sondę z tego pliku — bramka CLI wyżej zatrzymałaby `require`.
 */

$GLOBALS['evk_t_ai_zadania'] = [];
$GLOBALS['evk_t_ai_obrazy'] = [];

function evk_t_ai_tlumacz(string $pl): string {
    $model = (string) ($GLOBALS['evk_t_ai_model'] ?? '');
    $kod = strtoupper((string) ($GLOBALS['evk_t_ai_kod'] ?? 'en'))
        . ($model !== '' && !in_array($model, ['gemini-3.8-flash', 'claude-opus-5-5', 'gpt-6-astra'], true) ? '[' . $model . ']' : '');
    if (strpos($pl, 'ZEPSUJ') !== false) return wp_strip_all_tags($pl);
    return substr((string) preg_replace('/>([^<]+)</u', '>' . $kod . ':$1<', '>' . $pl . '<'), 1, -1);
}

/** Teksty z sekcji TO TRANSLATE wiadomości. */
function evk_t_ai_do_tlumaczenia(string $wiadomosc): array {
    $p = strpos($wiadomosc, "\nTO TRANSLATE");
    $n = $p === false ? false : strpos($wiadomosc, "\n", $p + 1);
    $lista = $n === false ? null : json_decode(substr($wiadomosc, $n + 1), true);
    return is_array($lista) ? $lista : [];
}

function evk_t_ai_odpowiedz(int $kod, $cialo, array $naglowki = []): array {
    return ['headers' => $naglowki, 'body' => is_string($cialo) ? $cialo : (string) wp_json_encode($cialo),
        'response' => ['code' => $kod, 'message' => $kod === 200 ? 'OK' : 'Error'], 'cookies' => [], 'filename' => null];
}

/*
 * DeepL (1.277.0): /v2/translate, /v2/glossaries (POST, DELETE). Tłumaczenie
 * atrapy dostaje przedrostek z kodem DOCELOWYM („EN-GB:”) — test widzi wariant.
 * Jak prawdziwy DeepL w trybie HTML: elementy z `translate="no"` zostają
 * nietknięte, a „&” w tekście wraca jako „&amp;”.
 * Scenariusze: deepl-456 | deepl-403 | deepl-429 | deepl-glosariusz-blad.
 */
function evk_t_ai_deepl_tlumacz(string $t, string $kod): string {
    $chron = [];
    $t = (string) preg_replace_callback('~<([a-z][a-z0-9]*)[^>]*translate="no"[^>]*>.*?</\1>~is', static function ($m) use (&$chron) {
        $chron[] = $m[0];
        return '<evk-chron-' . (count($chron) - 1) . '>';
    }, $t);
    $t = substr((string) preg_replace_callback('/>([^<]+)</u', static function ($m) use ($kod) {
        return '>' . $kod . ':' . str_replace('&', '&amp;', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '<';
    }, '>' . $t . '<'), 1, -1);
    return (string) preg_replace_callback('~<evk-chron-(\d+)>~', static function ($m) use ($chron) { return $chron[(int) $m[1]]; }, $t);
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'deepl.com/') === false) return $pre;
    $cialo = json_decode((string) ($args['body'] ?? ''), true);
    $metoda = strtoupper((string) ($args['method'] ?? 'POST'));
    $GLOBALS['evk_t_ai_zadania'][] = ['url' => $url, 'metoda' => $metoda, 'headers' => (array) ($args['headers'] ?? []), 'body' => $cialo];
    $s = (string) ($GLOBALS['evk_t_ai_scenariusz'] ?? 'ok');
    if (strpos($url, '/v2/glossaries') !== false) {
        if ($metoda === 'DELETE') return evk_t_ai_odpowiedz(204, '');
        if ($s === 'deepl-glosariusz-blad') return evk_t_ai_odpowiedz(400, ['message' => 'Unsupported glossary language pair']);
        $GLOBALS['evk_t_ai_glosariusze'] = (int) ($GLOBALS['evk_t_ai_glosariusze'] ?? 0) + 1;
        return evk_t_ai_odpowiedz(201, ['glossary_id' => 'gl-' . md5((string) ($cialo['entries'] ?? '') . microtime()), 'ready' => true,
            'name' => (string) ($cialo['name'] ?? ''), 'source_lang' => 'pl', 'target_lang' => (string) ($cialo['target_lang'] ?? ''),
            'entry_count' => substr_count((string) ($cialo['entries'] ?? ''), "\n")]);
    }
    if ($s === 'deepl-456') return evk_t_ai_odpowiedz(456, ['message' => 'Quota exceeded']);
    if ($s === 'deepl-403') return evk_t_ai_odpowiedz(403, ['message' => 'Wrong endpoint']);
    if ($s === 'deepl-429') return evk_t_ai_odpowiedz(429, ['message' => 'Too many requests'], ['retry-after' => '9']);
    $kod = (string) ($cialo['target_lang'] ?? '?');
    $tl = [];
    foreach ((array) ($cialo['text'] ?? []) as $t) $tl[] = ['detected_source_language' => 'PL', 'text' => evk_t_ai_deepl_tlumacz((string) $t, $kod)];
    return evk_t_ai_odpowiedz(200, ['translations' => $tl]);
}, 9, 3);

add_filter('pre_http_request', function ($pre, $args, $url) {
    $dostawca = strpos($url, 'api.anthropic.com') !== false ? 'claude'
        : (strpos($url, 'generativelanguage.googleapis.com') !== false ? 'gemini'
        : (strpos($url, 'api.openai.com') !== false ? 'openai' : ''));
    if ($dostawca === '') return $pre;
    $cialo = json_decode((string) ($args['body'] ?? ''), true);
    $GLOBALS['evk_t_ai_zadania'][] = ['url' => $url, 'headers' => (array) ($args['headers'] ?? []), 'body' => $cialo];
    $GLOBALS['evk_t_ai_model'] = $dostawca === 'gemini'
        ? (preg_match('~/models/([^/:]+):~', $url, $m) ? rawurldecode($m[1]) : '') : (string) ($cialo['model'] ?? '');
    if (is_callable($GLOBALS['evk_t_ai_w_trakcie'] ?? null)) ($GLOBALS['evk_t_ai_w_trakcie'])();
    $s = (string) ($GLOBALS['evk_t_ai_scenariusz'] ?? 'ok');

    if ($s === '401') return evk_t_ai_odpowiedz(401, ['error' => ['message' => 'invalid x-api-key']]);
    if ($s === '429') return evk_t_ai_odpowiedz(429, ['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limited']], ['retry-after' => '7']);
    $raz = sys_get_temp_dir() . '/evk-t-tl-ai-429-raz';
    if ($s === '429-raz' && !is_file($raz)) {
        touch($raz);
        return evk_t_ai_odpowiedz(429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']], ['retry-after' => '1']);
    }
    if ($s === 'limit-wydatkow') return evk_t_ai_odpowiedz(429, ['type' => 'error', 'error' => ['type' => 'rate_limit_error',
        'message' => 'You have reached your API usage limits', 'details' => ['error_code' => 'enforced_spend_limit_reached']]]);
    if ($s === 'dzienny') return evk_t_ai_odpowiedz(429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded',
        'details' => [['@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
            'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]]]]]);
    if ($s === 'retry-gemini') return evk_t_ai_odpowiedz(429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded',
        'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '12s']]]]);
    if ($s === 'odmowa') return evk_t_ai_odpowiedz(200, ['type' => 'message', 'content' => [], 'stop_reason' => 'refusal',
        'stop_details' => ['type' => 'refusal', 'category' => 'cyber']]);

    /* Wiadomość i obrazy — także z treści wieloczęściowej (obraz w zapytaniu). */
    $obrazy = [];
    if ($dostawca === 'claude') {
        $c = $cialo['messages'][0]['content'] ?? '';
        $wiadomosc = is_string($c) ? $c : '';
        foreach (is_array($c) ? $c : [] as $b) {
            if (($b['type'] ?? '') === 'text') $wiadomosc = (string) $b['text'];
            if (($b['type'] ?? '') === 'image') $obrazy[] = (string) ($b['source']['data'] ?? '');
        }
    } elseif ($dostawca === 'gemini') {
        $wiadomosc = (string) ($cialo['contents'][0]['parts'][0]['text'] ?? '');
        foreach ((array) ($cialo['contents'][0]['parts'] ?? []) as $b) if (isset($b['inline_data']['data'])) $obrazy[] = (string) $b['inline_data']['data'];
    } else {
        $c = $cialo['input'] ?? '';
        $wiadomosc = is_string($c) ? $c : '';
        foreach ((array) (is_array($c) ? ($c[0]['content'] ?? []) : []) as $b) {
            if (($b['type'] ?? '') === 'input_text') $wiadomosc = (string) $b['text'];
            if (($b['type'] ?? '') === 'input_image') $obrazy[] = (string) preg_replace('~^data:[^,]*,~', '', (string) $b['image_url']);
        }
    }
    $tl = [];
    foreach ($obrazy as $b64) {
        $bajty = (string) base64_decode($b64);
        $wym = @getimagesizefromstring($bajty);
        $GLOBALS['evk_t_ai_obrazy'][] = [(int) ($wym[0] ?? 0), (int) ($wym[1] ?? 0), (string) ($wym['mime'] ?? ''), strlen($bajty)];
    }
    if ($obrazy) {
        $tl[] = ['key' => 't1', 'text' => 'Opis obrazu ' . (preg_match('/^File: (.+)$/m', $wiadomosc, $mm) ? trim($mm[1]) : '?')];
    }
    foreach ($obrazy ? [] : evk_t_ai_do_tlumaczenia($wiadomosc) as $w) {
        $tl[] = ['key' => (string) ($w['key'] ?? ''), 'text' => evk_t_ai_tlumacz((string) ($w['text'] ?? ''))];
    }
    $json = $s === 'zly-json' ? 'to nie jest JSON' : (string) wp_json_encode(['translations' => $tl], JSON_UNESCAPED_UNICODE);
    if ($dostawca === 'claude') {
        return evk_t_ai_odpowiedz(200, ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $cialo['model'] ?? '',
            'content' => [['type' => 'text', 'text' => $json]], 'stop_reason' => 'end_turn']);
    }
    if ($dostawca === 'gemini') {
        return evk_t_ai_odpowiedz(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $json]]], 'finishReason' => 'STOP']]]);
    }
    return evk_t_ai_odpowiedz(200, ['id' => 'resp_test', 'status' => 'completed',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $json]]]]]);
}, 10, 3);
