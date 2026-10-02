<?php
if (!defined('ABSPATH')) exit;
/**
 * Tłumaczenie z Claude Desktop przez MCP (1.278.0).
 *
 * Decyzja zgłaszającego (02.10): tłumaczyć ma także Claude z subskrypcji
 * w Claude Desktop, bez klucza API. Droga: zdolności (Abilities API rdzenia
 * WordPressa) → MCP Adapter (`/wp-json/mcp/mcp-adapter-default-server`) →
 * `@automattic/mcp-wordpress-remote` (npx) z hasłem aplikacji → Claude Desktop.
 * Instrukcja połączenia: podzakładka „Claude Desktop” (admin/tl/tab-mcp.php).
 *
 * Tłumaczy klient, a wszystko inne idzie drogą hurtu AI (61,
 * `evk_tl_ai_krok()` z opcją `mcp`): te same części stron (Bricks, teksty
 * wpisu, SEO, pola Fields, termy, strony ustawień, komponenty, alty), pamięć
 * tłumaczeń, strażnik szkieletu (tagi HTML, `{…}`, shortcody) i znacznik
 * „Do sprawdzenia” z podpisem `mcp/…`. „Sprawdzone” klika tylko człowiek —
 * zdolności go nie zdejmują (decyzja 02.10).
 *
 * Trzy zdolności (nazwane narzędzia na domyślnym serwerze, jak u Bricksa —
 * jeden krok zamiast „discover → execute”):
 *   evoke/tlumaczenia-braki   — części stron z brakami, w każdym języku;
 *   evoke/tlumaczenia-pobierz — porcja tekstów z kontekstem i instrukcjami;
 *   evoke/tlumaczenia-zapisz  — zapis tłumaczeń z porcji.
 * I trzy prompty (menu „+” w Claude Desktop): strona, wszystkie braki,
 * sprawdzenie tłumaczeń AI.
 *
 * Prawo: jak zakładka Tłumaczenia (`manage_options` albo
 * `evk_access_translations`), a przy każdej części — jak krok z panelu
 * (`evk_tl_ai_jednostka_dozwolona()`).
 */

const EVK_TL_MCP_KATEGORIA = 'evoke';
/** Zdolności wystawione jako osobne narzędzia MCP. */
const EVK_TL_MCP_NARZEDZIA = ['evoke/tlumaczenia-braki', 'evoke/tlumaczenia-pobierz', 'evoke/tlumaczenia-zapisz'];
/** Ile części oddaje lista braków naraz. */
const EVK_TL_MCP_LISTA = 150;

function evk_tl_mcp_dostep(): bool {
    return current_user_can('manage_options') || current_user_can('evk_access_translations');
}

/** Abilities API w rdzeniu (WordPress 6.9+). */
function evk_tl_mcp_api(): bool {
    return function_exists('wp_register_ability') && function_exists('wp_register_ability_category');
}

/**
 * Zakres MCP: wszystkie części każdego wiersza tabeli zakresu (61) poza
 * brakującym polskim altem — ten wymaga obejrzenia obrazów modelem z wizją.
 *
 * @return array<string,list<string>>
 */
function evk_tl_mcp_zakres(): array {
    $out = [];
    foreach (evk_tl_ai_wiersze_zakresu() as $typ => $w) {
        $cz = array_values(array_diff($w['czesci'], ['alt_pl']));
        if ($cz) $out[$typ] = $cz;
    }
    return $out;
}

/** Identyfikator części dla klienta: „typ|post_id|meta_key|opcje|do”. */
function evk_tl_mcp_id(array $j): string {
    return implode('|', [(string) $j['typ'], (int) $j['post_id'], (string) $j['meta_key'], (string) ($j['opcje'] ?? ''), (int) ($j['do'] ?? 0)]);
}

/**
 * Część z identyfikatora: znany typ, prawo jak w kroku z panelu, opcje kroku.
 *
 * @return array{post_id:int,meta_key:string,lang:string,opcje:array<string,mixed>}|WP_Error
 */
function evk_tl_mcp_czesc(string $id, string $lang) {
    $p = explode('|', $id);
    if (count($p) !== 5) return new WP_Error('evk_tl_mcp_id', 'Nieznany identyfikator części — weź go z evoke-tlumaczenia-braki.');
    [$typ, $post_id, $meta_key, $gk, $do] = [$p[0], absint($p[1]), $p[2], $p[3], absint($p[4])];
    $zakres = evk_tl_mcp_zakres();
    if (!isset($zakres[$typ]) || $meta_key === EVK_TL_AI_ALT_PL) return new WP_Error('evk_tl_mcp_id', 'Tej części nie tłumaczy się przez MCP.');
    $j = evk_tl_ai_jednostka_dozwolona($post_id, $meta_key, sanitize_key($lang), $gk);
    if (!$j['ok']) return new WP_Error($j['kod'] === 403 ? 'evk_tl_mcp_prawo' : 'evk_tl_mcp_id', $j['blad'], ['status' => $j['kod']]);
    $opcje = ['do' => $do ?: $j['post_id'], 'opcje' => $j['gk']] + evk_tl_ai_opcje_czesci($zakres[$typ]);
    return ['post_id' => $j['post_id'], 'meta_key' => $meta_key, 'lang' => $j['lang'], 'opcje' => $opcje];
}

/** Model w podpisie „Do sprawdzenia”: „mcp/…”, z nazwy podanej przez klienta. */
function evk_tl_mcp_model($m): string {
    $m = substr((string) preg_replace('/[^a-z0-9._-]/', '', strtolower(trim((string) $m))), 0, 60);
    return $m !== '' ? $m : 'claude';
}

/**
 * Instrukcje dla klienta: te same zasady co w zapytaniu hurtu (61,
 * `evk_tl_ai_tresc()`) — opis strony, wskazówki języka, słowniczek — ale
 * zamiast formatu odpowiedzi: zapis przez evoke-tlumaczenia-zapisz.
 */
function evk_tl_mcp_instrukcje(string $lang): string {
    $u = evk_tl_ai_ustawienia();
    [$s] = evk_tl_ai_tresc($u, $lang, '', [], []);
    $s = (string) preg_replace('/^- Return JSON:.*$/m', '- Save with the tool evoke-tlumaczenia-zapisz: for every text send its "klucz" and "h" exactly as given, '
        . 'and the translation in "tekst". Texts you skip stay untranslated.', $s);
    return str_replace('Translate only the items under TO TRANSLATE.', 'Translate only the items in "teksty".', $s);
}

/**
 * Kontekst porcji jak w zapytaniu hurtu: okno EVK_TL_AI_KONTEKST tekstów
 * części wokół porcji, z numerem, opisem miejsca i istniejącym tłumaczeniem.
 *
 * @param list<array<string,string>>        $kontekst
 * @param array<string,array<string,mixed>> $porcja
 */
function evk_tl_mcp_kontekst(array $kontekst, array $porcja): string {
    $j = static function (string $t): string {
        return (string) wp_json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $od = max(0, min(array_map(static function ($w) { return (int) ($w['n'] ?? 1); }, $porcja ?: [['n' => 1]])) - 1 - intdiv(EVK_TL_AI_KONTEKST, 4));
    $ctx = [];
    foreach (array_slice($kontekst, $od, EVK_TL_AI_KONTEKST, true) as $i => $k) {
        $ctx[] = ($i + 1) . '. [' . $k['element'] . ' · ' . $k['opis'] . '] ' . $j($k['pl']) . (($k['tl'] ?? '') !== '' ? ' → ' . $j($k['tl']) : '');
    }
    return implode("\n", $ctx);
}

// =========================================================================
// WYKONANIE ZDOLNOŚCI
// =========================================================================

/**
 * Części z brakami. `jezyk` — tylko z brakami w tym języku; `ponownie` —
 * także niesprawdzone tłumaczenia AI (sprawdzanie); `szukaj` — fragment
 * tytułu; `strona` — ID wpisu albo termu.
 *
 * @param mixed $in
 * @return array<string,mixed>|WP_Error
 */
function evk_tl_mcp_braki($in) {
    $in = is_array($in) ? $in : [];
    $jezyki = tl_get_languages();
    $lang = sanitize_key((string) ($in['jezyk'] ?? ''));
    if ($lang !== '' && !isset($jezyki[$lang])) return new WP_Error('evk_tl_mcp_jezyk', 'Nieznany język. Języki: ' . implode(', ', array_keys($jezyki)) . '.');
    $szukaj = function_exists('mb_strtolower') ? mb_strtolower(trim((string) ($in['szukaj'] ?? ''))) : strtolower(trim((string) ($in['szukaj'] ?? '')));
    $strona = absint($in['strona'] ?? 0);
    $out = [];
    $razem = 0;
    foreach (evk_tl_ai_jednostki(!empty($in['ponownie']), evk_tl_mcp_zakres()) as $j) {
        if ($j['meta_key'] === EVK_TL_AI_ALT_PL) continue;
        $braki = (array) $j['braki'];
        if ($lang !== '' && empty($braki[$lang])) continue;
        if ($strona && (int) $j['post_id'] !== $strona) continue;
        $tytul = (string) $j['tytul'];
        if ($szukaj !== '' && strpos(function_exists('mb_strtolower') ? mb_strtolower($tytul) : strtolower($tytul), $szukaj) === false) continue;
        $razem++;
        if (count($out) >= EVK_TL_MCP_LISTA) continue;
        $out[] = ['czesc' => evk_tl_mcp_id($j), 'grupa' => (string) $j['grupa'], 'tytul' => $tytul, 'rodzaj' => (string) ($j['czesc'] ?? ''),
            'braki' => (object) $braki, 'ai_do_sprawdzenia' => (object) (array) ($j['ai'] ?? [])];
    }
    $lista = [];
    foreach ($jezyki as $kod => $d) $lista[] = ['kod' => (string) $kod, 'nazwa' => evk_tl_ai_jezyk((string) $kod)];
    return ['jezyki' => $lista, 'czesci' => $out, 'razem' => $razem, 'uciete' => $razem > count($out),
        'wskazowka' => $out ? 'Dla każdej części: evoke-tlumaczenia-pobierz → przetłumacz → evoke-tlumaczenia-zapisz, aż „gotowe”.' : 'Brak części do tłumaczenia.'];
}

/**
 * Porcja tekstów części w języku. Teksty z pamięci tłumaczeń zapisują się od
 * razu (jak w hurcie); reszta wraca z kluczem, skrótem oryginału `h`,
 * kontekstem i instrukcjami. `od` — numer, za którym szukać dalej
 * (`nastepne_od` z poprzedniego pobrania, gdy coś zostało pominięte).
 *
 * @param mixed $in
 * @return array<string,mixed>|WP_Error
 */
function evk_tl_mcp_pobierz($in) {
    $in = is_array($in) ? $in : [];
    $c = evk_tl_mcp_czesc((string) ($in['czesc'] ?? ''), (string) ($in['jezyk'] ?? ''));
    if (is_wp_error($c)) return $c;
    $ponownie = !empty($in['ponownie']);
    $w = evk_tl_ai_krok($c['post_id'], $c['meta_key'], $c['lang'], [],
        ['ponownie' => $ponownie, 'mcp' => ['tryb' => 'pobierz', 'od' => absint($in['od'] ?? 0), 'ai' => $ponownie]] + $c['opcje']);
    $out = ['czesc' => (string) $in['czesc'], 'jezyk' => $c['lang'], 'jezyk_nazwa' => evk_tl_ai_jezyk($c['lang']),
        'z_pamieci' => (int) $w['zapisane'], 'zostalo' => (int) $w['zostalo'], 'teksty' => []];
    if (empty($w['mcp'])) {
        $out['gotowe'] = true;
        $out['wskazowka'] = (int) $w['zostalo'] > 0 ? 'Za numerem „od” nie ma już tekstów — zostały tylko pominięte wcześniej.' : 'Ta część jest przetłumaczona w tym języku.';
        return $out;
    }
    $m = $w['mcp'];
    foreach ($m['porcja'] as $k => $b) {
        $t = ['klucz' => (string) $k, 'h' => evk_tl_ai_skrot((string) $b['pl']), 'n' => (int) $b['n'], 'miejsce' => $b['element'] . ' · ' . $b['opis'], 'pl' => (string) $b['pl']];
        if ($ponownie && (string) $b['bylo'] !== '') $t['obecne'] = (string) $b['bylo'];
        $out['teksty'][] = $t;
    }
    $out += ['gotowe' => false, 'tytul' => (string) $m['tytul'], 'instrukcje' => evk_tl_mcp_instrukcje($c['lang']),
        'kontekst' => evk_tl_mcp_kontekst($m['kontekst'], $m['porcja']), 'nastepne_od' => max(array_column($out['teksty'], 'n'))];
    if ($ponownie) {
        $out['wskazowka'] = 'Sprawdzanie: „obecne” to tłumaczenie AI do sprawdzenia. Zapisz tylko poprawione; dobre pomiń. '
            . 'Następna porcja: evoke-tlumaczenia-pobierz z od = nastepne_od. „Sprawdzone” zaznacza człowiek w panelu.';
    }
    return $out;
}

/**
 * Zapis tłumaczeń porcji. Tylko teksty bez tłumaczenia albo z niesprawdzonym
 * tłumaczeniem AI — sprawdzonych i wpisanych ręcznie nie nadpisuje. Poprawkę
 * przyjmuje zawsze; `ponownie` mówi tylko, co liczy `zostalo`.
 *
 * @param mixed $in
 * @return array<string,mixed>|WP_Error
 */
function evk_tl_mcp_zapisz($in) {
    $in = is_array($in) ? $in : [];
    $c = evk_tl_mcp_czesc((string) ($in['czesc'] ?? ''), (string) ($in['jezyk'] ?? ''));
    if (is_wp_error($c)) return $c;
    $dane = [];
    foreach ((array) ($in['tlumaczenia'] ?? []) as $t) {
        if (!is_array($t) || !is_string($t['klucz'] ?? null) || !is_string($t['tekst'] ?? null)) continue;
        $dane[$t['klucz']] = ['h' => (string) ($t['h'] ?? ''), 't' => $t['tekst']];
    }
    if (!$dane) return new WP_Error('evk_tl_mcp_puste', 'Brak tłumaczeń do zapisu (klucz, h, tekst).');
    $w = evk_tl_ai_krok($c['post_id'], $c['meta_key'], $c['lang'], [],
        ['ponownie' => true, 'mcp' => ['tryb' => 'zapisz', 'tlumaczenia' => $dane, 'model' => evk_tl_mcp_model($in['model'] ?? ''), 'ai' => !empty($in['ponownie'])]] + $c['opcje']);
    $odrzucone = [];
    foreach ((array) $w['odrzucone'] as $k) $odrzucone[] = ['klucz' => (string) $k, 'powod' => 'Znaczniki HTML, tagi {…} albo shortcody nie zgadzają się z oryginałem.'];
    foreach ((array) ($w['zmienione'] ?? []) as $k) $odrzucone[] = ['klucz' => (string) $k, 'powod' => 'Oryginał zmienił się od pobrania — pobierz jeszcze raz.'];
    foreach ((array) ($w['nieznane'] ?? []) as $k) $odrzucone[] = ['klucz' => (string) $k, 'powod' => 'Nie ma takiego tekstu do tłumaczenia (już sprawdzony, wpisany ręcznie albo zły klucz).'];
    $out = ['zapisane' => (int) $w['zapisane'], 'bez_zmian' => (int) $w['bez_zmian'], 'odrzucone' => $odrzucone, 'zostalo' => (int) $w['zostalo'],
        'gotowe' => (int) $w['zostalo'] === 0];
    if (!empty($w['adres']['stan'])) $out['adres'] = $w['adres'];
    return $out;
}

// =========================================================================
// PROMPTY
// =========================================================================

function evk_tl_mcp_prompt_strona($in): array {
    $in = is_array($in) ? $in : [];
    $strona = trim((string) ($in['strona'] ?? ''));
    $jezyk = trim((string) ($in['jezyk'] ?? ''));
    return ['text' => "Przetłumacz stronę „{$strona}” witryny WordPress (wtyczka Evoke ONE) na język: {$jezyk}.\n\n"
        . "1. Wywołaj evoke-tlumaczenia-braki z szukaj = tytuł strony (albo strona = ID, gdy podano liczbę) i jezyk = kod języka. "
        . "Jeśli kod jest nieznany, wybierz go z listy „jezyki”.\n"
        . "2. Dla każdej znalezionej części: evoke-tlumaczenia-pobierz, przetłumacz „teksty” według „instrukcje” i „kontekst”, "
        . "zapisz przez evoke-tlumaczenia-zapisz (klucz i h bez zmian). Powtarzaj, aż pobranie zwróci gotowe = true.\n"
        . "3. Odrzucone teksty popraw (zachowaj znaczniki HTML, {…} i shortcody) i zapisz jeszcze raz.\n"
        . "4. Na koniec podsumuj: ile zapisano, co odrzucono. Tłumaczenia trafiają na listę „Do sprawdzenia” w panelu."];
}

function evk_tl_mcp_prompt_braki($in): array {
    $in = is_array($in) ? $in : [];
    $jezyk = trim((string) ($in['jezyk'] ?? ''));
    return ['text' => "Przetłumacz wszystkie brakujące teksty witryny WordPress (wtyczka Evoke ONE) na język: {$jezyk}.\n\n"
        . "1. Wywołaj evoke-tlumaczenia-braki z jezyk = kod języka (gdy nieznany — wybierz z listy „jezyki”).\n"
        . "2. Idź po częściach po kolei: evoke-tlumaczenia-pobierz → przetłumacz według „instrukcje” i „kontekst” → "
        . "evoke-tlumaczenia-zapisz, aż gotowe = true; potem następna część. Trzymaj jedno słownictwo w całej witrynie.\n"
        . "3. Gdy lista była ucięta (uciete = true), po przejściu wywołaj evoke-tlumaczenia-braki jeszcze raz.\n"
        . "4. Co kilka części krótko raportuj postęp, a na koniec podsumuj."];
}

function evk_tl_mcp_prompt_sprawdz($in): array {
    $in = is_array($in) ? $in : [];
    $jezyk = trim((string) ($in['jezyk'] ?? ''));
    $strona = trim((string) ($in['strona'] ?? ''));
    return ['text' => "Sprawdź tłumaczenia AI na język {$jezyk} w witrynie WordPress (wtyczka Evoke ONE)"
        . ($strona !== '' ? " na stronie „{$strona}”" : '') . ".\n\n"
        . "1. Wywołaj evoke-tlumaczenia-braki z jezyk, ponownie = true" . ($strona !== '' ? ' i szukaj = tytuł strony' : '') . ".\n"
        . "2. Dla każdej części: evoke-tlumaczenia-pobierz z ponownie = true. Porównaj „pl” z „obecne”: znaczenie, ton, słowniczek, "
        . "naturalność. Teksty bez „obecne” przetłumacz.\n"
        . "3. Zapisz przez evoke-tlumaczenia-zapisz (ponownie = true) TYLKO poprawione albo nowe tłumaczenia; dobre pomiń. "
        . "Następną porcję pobierz z od = nastepne_od, aż gotowe = true.\n"
        . "4. Podsumuj: co poprawiono i dlaczego. Nie oznaczaj niczego jako sprawdzone — to robi człowiek w panelu."];
}

// =========================================================================
// REJESTRACJA
// =========================================================================

add_action('wp_abilities_api_categories_init', function (): void {
    if (!evk_tl_mcp_api()) return;
    wp_register_ability_category(EVK_TL_MCP_KATEGORIA, ['label' => 'Evoke ONE',
        'description' => 'Tłumaczenia stron Evoke ONE: braki, pobranie tekstów z kontekstem, zapis do sprawdzenia.']);
});

add_action('wp_abilities_api_init', function (): void {
    if (!evk_tl_mcp_api()) return;
    $prawo = static function (): bool { return evk_tl_mcp_dostep(); };
    $czesc = ['type' => 'string', 'description' => 'Identyfikator części z evoke-tlumaczenia-braki (pole „czesc”).'];
    $jezyk = ['type' => 'string', 'description' => 'Kod języka z listy „jezyki” (np. en, de).'];
    $ponownie = ['type' => 'boolean', 'default' => false, 'description' => 'Także niesprawdzone tłumaczenia AI (sprawdzanie i poprawki).'];
    $meta = static function (bool $odczyt, array $mcp = []): array {
        return ['show_in_rest' => true, 'mcp' => ['public' => true] + $mcp,
            'annotations' => ['readonly' => $odczyt, 'destructive' => false, 'idempotent' => $odczyt]];
    };
    wp_register_ability('evoke/tlumaczenia-braki', [
        'label' => 'Tłumaczenia: części z brakami',
        'description' => 'Lista części stron (treść Bricksa, teksty wpisu, SEO, pola Fields, kategorie, komponenty, alty) z liczbą tekstów bez '
            . 'tłumaczenia w każdym języku, oraz lista języków witryny. Zaczynaj od tego narzędzia.',
        'category' => EVK_TL_MCP_KATEGORIA,
        'input_schema' => ['type' => 'object', 'default' => [], 'properties' => [
            'jezyk' => $jezyk, 'ponownie' => $ponownie,
            'szukaj' => ['type' => 'string', 'description' => 'Fragment tytułu strony.'],
            'strona' => ['type' => 'integer', 'description' => 'ID wpisu albo kategorii.'],
        ], 'additionalProperties' => false],
        'execute_callback' => 'evk_tl_mcp_braki',
        'permission_callback' => $prawo,
        'meta' => $meta(true),
    ]);
    wp_register_ability('evoke/tlumaczenia-pobierz', [
        'label' => 'Tłumaczenia: pobierz teksty',
        'description' => 'Porcja tekstów jednej części do przetłumaczenia z polskiego: klucz, h (skrót oryginału), oryginał, miejsce; '
            . 'do tego kontekst całej części i instrukcje (opis strony, słowniczek, wskazówki języka). Teksty ze sprawdzonej pamięci '
            . 'tłumaczeń zapisują się same.',
        'category' => EVK_TL_MCP_KATEGORIA,
        'input_schema' => ['type' => 'object', 'properties' => [
            'czesc' => $czesc, 'jezyk' => $jezyk, 'ponownie' => $ponownie,
            'od' => ['type' => 'integer', 'default' => 0, 'description' => 'Pomiń teksty do tego numeru (nastepne_od z poprzedniego pobrania).'],
        ], 'required' => ['czesc', 'jezyk'], 'additionalProperties' => false],
        'execute_callback' => 'evk_tl_mcp_pobierz',
        'permission_callback' => $prawo,
        'meta' => $meta(false),
    ]);
    wp_register_ability('evoke/tlumaczenia-zapisz', [
        'label' => 'Tłumaczenia: zapisz',
        'description' => 'Zapis tłumaczeń z porcji (klucz i h bez zmian). Tłumaczenia dostają znacznik „Do sprawdzenia”. '
            . 'Odrzuca tekst ze zmienionymi znacznikami HTML, tagami {…} albo shortcodami; sprawdzonych tłumaczeń nie nadpisuje.',
        'category' => EVK_TL_MCP_KATEGORIA,
        'input_schema' => ['type' => 'object', 'properties' => [
            'czesc' => $czesc, 'jezyk' => $jezyk,
            'ponownie' => ['type' => 'boolean', 'default' => false, 'description' => 'Jak przy pobraniu (sprawdzanie) — wtedy „zostalo” liczy też tłumaczenia AI do sprawdzenia.'],
            'model' => ['type' => 'string', 'description' => 'Nazwa modelu do podpisu (np. claude-opus).'],
            'tlumaczenia' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'properties' => [
                'klucz' => ['type' => 'string'], 'h' => ['type' => 'string'], 'tekst' => ['type' => 'string'],
            ], 'required' => ['klucz', 'h', 'tekst']]],
        ], 'required' => ['czesc', 'jezyk', 'tlumaczenia'], 'additionalProperties' => false],
        'execute_callback' => 'evk_tl_mcp_zapisz',
        'permission_callback' => $prawo,
        'meta' => $meta(false),
    ]);

    $tekst = static function (string $opis): array { return ['type' => 'string', 'description' => $opis]; };
    wp_register_ability('evoke/przetlumacz-strone', [
        'label' => 'Przetłumacz stronę',
        'description' => 'Przetłumacz jedną stronę witryny na wybrany język.',
        'category' => EVK_TL_MCP_KATEGORIA,
        'input_schema' => ['type' => 'object', 'properties' => ['strona' => $tekst('Tytuł albo ID strony'), 'jezyk' => $tekst('Język, np. en')],
            'required' => ['strona', 'jezyk']],
        'execute_callback' => 'evk_tl_mcp_prompt_strona',
        'permission_callback' => $prawo,
        'meta' => $meta(true, ['type' => 'prompt']),
    ]);
    wp_register_ability('evoke/przetlumacz-braki', [
        'label' => 'Przetłumacz wszystkie braki',
        'description' => 'Przetłumacz wszystkie brakujące teksty witryny na wybrany język, strona po stronie.',
        'category' => EVK_TL_MCP_KATEGORIA,
        'input_schema' => ['type' => 'object', 'properties' => ['jezyk' => $tekst('Język, np. en')], 'required' => ['jezyk']],
        'execute_callback' => 'evk_tl_mcp_prompt_braki',
        'permission_callback' => $prawo,
        'meta' => $meta(true, ['type' => 'prompt']),
    ]);
    wp_register_ability('evoke/sprawdz-tlumaczenia', [
        'label' => 'Sprawdź tłumaczenia AI',
        'description' => 'Przejrzyj tłumaczenia AI „Do sprawdzenia” (także z DeepL, Gemini, OpenAI) i popraw złe.',
        'category' => EVK_TL_MCP_KATEGORIA,
        'input_schema' => ['type' => 'object', 'properties' => ['jezyk' => $tekst('Język, np. en'), 'strona' => $tekst('Tytuł strony (opcjonalnie)')],
            'required' => ['jezyk']],
        'execute_callback' => 'evk_tl_mcp_prompt_sprawdz',
        'permission_callback' => $prawo,
        'meta' => $meta(true, ['type' => 'prompt']),
    ]);
});

/* Nazwane narzędzia na domyślnym serwerze MCP Adaptera (jak Bricks): klient
   widzi je od razu w `tools/list`, bez „discover → execute”. */
add_filter('mcp_adapter_default_server_config', function ($config) {
    if (!is_array($config)) return $config;
    $config['tools'] = array_values(array_unique(array_merge((array) ($config['tools'] ?? []), EVK_TL_MCP_NARZEDZIA)));
    return $config;
});

// =========================================================================
// PANEL: MCP ADAPTER I HASŁO APLIKACJI (podzakładka „Claude Desktop”)
// =========================================================================

/** Paczka wydania MCP Adaptera (nie ma go w katalogu WordPress.org); filtr podmienia ją w testach. */
function evk_tl_mcp_adapter_zip(): string {
    return (string) apply_filters('evk_tl_mcp_adapter_zip', 'https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip');
}

/**
 * Stan MCP Adaptera: plik wtyczki (`mcp-adapter.php` w dowolnym katalogu —
 * Bricks instaluje ten sam), czy aktywny, wersja.
 *
 * @return array{plik:string,aktywny:bool,wersja:string}
 */
function evk_tl_mcp_adapter_stan(): array {
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plik = '';
    $wersja = '';
    foreach (get_plugins() as $p => $d) {
        if (basename((string) $p) !== 'mcp-adapter.php') continue;
        $plik = (string) $p;
        $wersja = (string) ($d['Version'] ?? '');
        if (is_plugin_active($plik)) break;
    }
    $aktywny = class_exists('WP\\MCP\\Core\\McpAdapter') || ($plik !== '' && is_plugin_active($plik));
    return ['plik' => $plik, 'aktywny' => $aktywny, 'wersja' => $wersja];
}

/**
 * Instaluje (gdy brak) i włącza MCP Adapter — przycisk w panelu, jak „Install
 * plugin” w zakładce AI Bricksa.
 *
 * @return array{plik:string,aktywny:bool,wersja:string}|WP_Error
 */
function evk_tl_mcp_adapter_wlacz() {
    $stan = evk_tl_mcp_adapter_stan();
    if ($stan['plik'] === '') {
        if (!current_user_can('install_plugins')) return new WP_Error('evk_tl_mcp_prawo', 'Instalacja wtyczek wymaga prawa administratora.');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        /* Skórka AJAX zbiera błędy (get_errors) — automatyczna ich nie oddaje. */
        $skora = new WP_Ajax_Upgrader_Skin();
        $inst = new Plugin_Upgrader($skora);
        $r = $inst->install(evk_tl_mcp_adapter_zip());
        if (is_wp_error($r)) return $r;
        if ($r !== true) {
            $bledy = $skora->get_errors();
            return new WP_Error('evk_tl_mcp_instalacja', $bledy->has_errors() ? $bledy->get_error_message()
                : 'Instalacja się nie udała (brak zapisu do katalogu wtyczek?). Wgraj zip ręcznie: Wtyczki → Dodaj → Wyślij wtyczkę na serwer.');
        }
        $stan = evk_tl_mcp_adapter_stan();
        if ($stan['plik'] === '') return new WP_Error('evk_tl_mcp_instalacja', 'Paczka rozpakowała się, ale bez pliku mcp-adapter.php.');
    }
    if (!$stan['aktywny']) {
        if (!current_user_can('activate_plugins')) return new WP_Error('evk_tl_mcp_prawo', 'Włączenie wtyczki wymaga prawa administratora.');
        $r = activate_plugin($stan['plik']);
        if (is_wp_error($r)) return $r;
        $stan['aktywny'] = true;
    }
    return $stan;
}

/** Adres serwera MCP — domyślny serwer MCP Adaptera (ten sam co dla zdolności Bricksa). */
function evk_tl_mcp_adres(): string {
    return rest_url('mcp/mcp-adapter-default-server');
}

/**
 * Wpis do `claude_desktop_config.json` (macOS: ~/Library/Application
 * Support/Claude/): serwer przez `npx @automattic/mcp-wordpress-remote`.
 */
function evk_tl_mcp_konfiguracja(string $login, string $haslo): string {
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $nazwa = 'wordpress-' . (sanitize_key(str_replace('.', '-', $host)) ?: 'strona');
    return (string) wp_json_encode(['mcpServers' => [$nazwa => [
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => ['WP_API_URL' => evk_tl_mcp_adres(), 'WP_API_USERNAME' => $login, 'WP_API_PASSWORD' => $haslo],
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Nazwa hasła aplikacji tworzonego z panelu. */
const EVK_TL_MCP_HASLO = 'Claude Desktop (Evoke)';

add_action('wp_ajax_evk_tl_mcp_adapter', function (): void {
    evk_tl_ajax_check('evk_tl_mcp');
    if (!current_user_can('manage_options')) wp_send_json_error('MCP Adapter włącza administrator.', 403);
    if (function_exists('set_time_limit')) @set_time_limit(120);
    $r = evk_tl_mcp_adapter_wlacz();
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json_success(['komunikat' => 'MCP Adapter ' . ($r['wersja'] !== '' ? $r['wersja'] . ' ' : '') . 'działa.'] + $r);
});

/* Hasło aplikacji dla bieżącego użytkownika — pokazane RAZ, w gotowej konfiguracji. */
add_action('wp_ajax_evk_tl_mcp_haslo', function (): void {
    evk_tl_ajax_check('evk_tl_mcp');
    $user = wp_get_current_user();
    if (!wp_is_application_passwords_available_for_user($user) || !current_user_can('create_app_password', $user->ID)) {
        wp_send_json_error('Hasła aplikacji są wyłączone na tej stronie (WordPress daje je tylko przez HTTPS).');
    }
    $r = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => EVK_TL_MCP_HASLO]);
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    $haslo = WP_Application_Passwords::chunk_password((string) $r[0]);
    wp_send_json_success(['login' => $user->user_login, 'haslo' => $haslo, 'konfiguracja' => evk_tl_mcp_konfiguracja($user->user_login, $haslo)]);
});
