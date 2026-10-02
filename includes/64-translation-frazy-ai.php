<?php
if (!defined('ABSPATH')) exit;
/**
 * Frazy słownika i etykiety menu w tłumaczeniu AI (1.279.0).
 *
 * Decyzje zgłaszającego (02.10): frazy tłumaczy hurt w zakładce
 * „Tłumaczenie AI” (z wyborem dostawcy i modelu na przebieg) i Claude
 * Desktop przez MCP (63); etykiety wszystkich menu WordPressa dopisują się
 * same do grupy „Menu” w słowniku (silnik fraz podmienia je w całym HTML-u
 * strony, 60); fraza z AI ma znacznik „AI” i trafia na listę „Do sprawdzenia”.
 *
 * Jednostka hurtu: grupa słownika (`opcje` = klucz grupy), klucz miejsca
 * `{wiersz}|{język}` — jak części innych rodzajów, przez `evk_tl_ai_krok()`.
 *
 * Znacznik AI NIE siedzi w słowniku: zakładka zapisuje cały słownik z pól
 * formularza (`tl_save_translations`), więc dodatkowe pole wiersza by
 * zginęło. Siedzi w osobnej opcji: język → skrót polskiej frazy → skrót
 * tłumaczenia i model. Znacznik obowiązuje, póki tłumaczenie ma ten sam
 * skrót — poprawka w zakładce gasi go sama, „Sprawdzone” go usuwa.
 * Przeniesienie albo duplikat frazy nic nie psuje (klucz to tekst, nie wiersz).
 */

const EVK_TL_AI_FRAZY = 'evk_frazy';
const EVK_TL_FRAZY_AI = 'evk_tl_frazy_ai';
/** Grupa słownika na etykiety menu. */
const EVK_TL_FRAZY_MENU = 'menu';

function evk_tl_frazy_dostepne(): bool {
    return function_exists('tl_sanitize_phrase') && function_exists('tl_invalidate_cache');
}

/** @return array<string,array<string,mixed>> Wartości z bazy — kształt sprawdza odczyt. */
function evk_tl_frazy_znaczniki(): array {
    $z = get_option(EVK_TL_FRAZY_AI, []);
    return is_array($z) ? $z : [];
}

/** Model znacznika AI frazy w języku albo null (brak, albo tłumaczenie od tego czasu poprawione). */
function evk_tl_frazy_ai(string $pl, string $kod, string $tl): ?string {
    $z = evk_tl_frazy_znaczniki()[$kod][md5(trim($pl))] ?? null;
    if (!is_array($z) || trim($tl) === '' || ($z['h'] ?? '') !== md5($tl)) return null;
    return (string) ($z['m'] ?? '');
}

/**
 * Klucze indeksu słownika (`tl_get_match_index()`) fraz z tłumaczeniem AI
 * w języku. Pamięć tłumaczeń (61) bierze słownik jako sprawdzony — bez tego
 * niesprawdzone tłumaczenie frazy szłoby do elementów jak sprawdzone, a tryb
 * „ponownie” dostawałby je z powrotem jako „bez zmian”.
 *
 * @return array<string,true>
 */
function evk_tl_frazy_klucze_ai(string $lang): array {
    $z = evk_tl_frazy_znaczniki()[evk_tl_ai_kod($lang)] ?? [];
    if (!$z || !function_exists('tl_normalize_text_for_match')) return [];
    $out = [];
    foreach (evk_tl_frazy_slownik()['groups'] as $g) {
        foreach ((array) ($g['rows'] ?? []) as $r) {
            $pl = trim((string) ($r['pl'] ?? ''));
            if ($pl === '' || !isset($z[md5($pl)]) || evk_tl_frazy_ai($pl, evk_tl_ai_kod($lang), (string) ($r[$lang] ?? '')) === null) continue;
            $out[mb_strtolower(tl_normalize_text_for_match($pl))] = true;
        }
    }
    return $out;
}

/** @return array{groups:array<string,array<string,mixed>>} */
function evk_tl_frazy_slownik(): array {
    $d = get_option('tl_translations', ['groups' => []]);
    return is_array($d) && is_array($d['groups'] ?? null) ? $d : ['groups' => []];
}

/**
 * Etykiety menu WordPressa do grupy „Menu” — tylko te, których słownik
 * jeszcze nie ma (w żadnej grupie). Woła to zapis menu i lista hurtu/MCP.
 *
 * @return int Ile dopisano.
 */
function evk_tl_frazy_menu(): int {
    if (!evk_tl_frazy_dostepne() || !function_exists('wp_get_nav_menus')) return 0;
    $d = evk_tl_frazy_slownik();
    $sa = [];
    foreach ($d['groups'] as $g) foreach ((array) ($g['rows'] ?? []) as $r) $sa[trim((string) ($r['pl'] ?? ''))] = true;
    $nowe = [];
    foreach ((array) wp_get_nav_menus() as $menu) {
        foreach ((array) wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false]) as $poz) {
            $e = trim(tl_sanitize_phrase((string) ($poz->title ?? '')));
            if ($e === '' || isset($sa[$e]) || !preg_match('/\p{L}/u', wp_strip_all_tags($e))) continue;
            $sa[$e] = true;
            $nowe[] = $e;
        }
    }
    if (!$nowe) return 0;
    if (!isset($d['groups'][EVK_TL_FRAZY_MENU])) $d['groups'][EVK_TL_FRAZY_MENU] = ['name' => 'Menu', 'rows' => []];
    foreach ($nowe as $e) {
        $wiersz = ['pl' => $e, 'dd_key' => ''];
        foreach (evk_tl_kody_jezykow() as $kod) $wiersz[$kod] = '';
        $d['groups'][EVK_TL_FRAZY_MENU]['rows']['row_m' . substr(md5($e), 0, 10)] = $wiersz;
    }
    update_option('tl_translations', $d);
    tl_invalidate_cache();
    return count($nowe);
}
add_action('wp_update_nav_menu', function (): void {
    if (get_option('evk_tl_module_enabled')) evk_tl_frazy_menu();
}, 20);

/**
 * Teksty grupy w kształcie evk_tl_ai_teksty(): kontekst (wszystkie frazy
 * grupy) i braki — puste tłumaczenia, w trybie ponownym także z AI.
 *
 * @return array{kontekst:list<array<string,string>>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty_fraz(string $gk, string $lang, bool $ponownie = false): array {
    $out = ['kontekst' => [], 'braki' => []];
    $g = evk_tl_frazy_slownik()['groups'][$gk] ?? null;
    if (!is_array($g)) return $out;
    $kod = evk_tl_ai_kod($lang);
    $nazwa = (string) ($g['name'] ?? $gk);
    foreach ((array) ($g['rows'] ?? []) as $rid => $r) {
        $pl = trim((string) ($r['pl'] ?? ''));
        if ($pl === '' || !evk_tl_ai_do_tlumaczenia($pl)) continue;
        $tl = (string) ($r[$lang] ?? '');
        $jest = trim($tl) !== '';
        $znowu = $jest && $ponownie && evk_tl_frazy_ai($pl, $kod, $tl) !== null;
        $out['kontekst'][] = ['element' => 'Fraza', 'opis' => $nazwa, 'pl' => $pl, 'tl' => $jest && !$znowu ? $tl : ''];
        if ($jest && !$znowu) continue;
        $out['braki'][(string) $rid . '|' . $kod] = ['pl' => $pl, 'element' => 'Fraza', 'opis' => $nazwa, 'id' => (string) $rid, 'sciezka' => (string) $rid,
            'pole' => $lang, 'n' => count($out['kontekst']), 'bylo' => $znowu ? $tl : ''];
    }
    return $out;
}

/**
 * Zapis tłumaczeń fraz grupy. Wiersz, którego tłumaczenie ktoś wpisał
 * w międzyczasie (inne niż oczekiwane), zostaje nietknięty.
 *
 * @param array<string,string>               $gotowe
 * @param array<string,bool>                 $ai
 * @param array<string,array<string,mixed>>  $braki
 * @return list<string>
 */
function evk_tl_ai_zapisz_frazy(string $gk, string $lang, array $gotowe, array $ai, string $model, array $braki): array {
    $d = evk_tl_frazy_slownik();
    if (!isset($d['groups'][$gk]['rows'])) return [];
    $kod = evk_tl_ai_kod($lang);
    $z = evk_tl_frazy_znaczniki();
    $out = [];
    foreach ($gotowe as $k => $tl) {
        $rid = (string) ($braki[$k]['id'] ?? '');
        $r = $d['groups'][$gk]['rows'][$rid] ?? null;
        if (!is_array($r) || trim((string) ($r['pl'] ?? '')) !== $braki[$k]['pl'] || (string) ($r[$lang] ?? '') !== (string) $braki[$k]['bylo']) continue;
        $tl = tl_sanitize_phrase((string) $tl);
        if (trim($tl) === '') continue;
        $d['groups'][$gk]['rows'][$rid][$lang] = $tl;
        $h = md5((string) $braki[$k]['pl']);
        if (!empty($ai[$k])) $z[$kod][$h] = ['h' => md5($tl), 'm' => $model];
        else unset($z[$kod][$h]);
        $out[] = (string) $k;
    }
    if ($out) {
        update_option('tl_translations', $d);
        update_option(EVK_TL_FRAZY_AI, $z, false);
        tl_invalidate_cache();
    }
    return $out;
}

/**
 * Grupy słownika z brakami (lista hurtu i MCP). Najpierw dopisuje etykiety menu.
 *
 * @param list<string> $jezyki
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_fraz(array $jezyki, bool $ponownie): array {
    if (!evk_tl_frazy_dostepne()) return [];
    evk_tl_frazy_menu();
    $out = [];
    foreach (evk_tl_frazy_slownik()['groups'] as $gk => $g) {
        $braki = [];
        $ai = [];
        foreach ($jezyki as $j) {
            $b = evk_tl_ai_teksty_fraz((string) $gk, $j, $ponownie)['braki'];
            if ($b) $braki[$j] = count($b);
            $n = count(array_filter($b, static function ($x) { return $x['bylo'] !== ''; }));
            if ($n) $ai[$j] = $n;
        }
        if (!$braki) continue;
        $out[] = ['post_id' => 0, 'meta_key' => EVK_TL_AI_FRAZY, 'opcje' => (string) $gk, 'tytul' => 'Frazy: ' . (string) ($g['name'] ?? $gk),
            'czesc' => 'Słownik fraz', 'adres' => admin_url('options-general.php?page=' . (defined('TL_MENU_SLUG') ? TL_MENU_SLUG : 'evoke-tlumaczenia') . '&tab=translations'),
            'braki' => $braki, 'ai' => (object) $ai];
    }
    return $out;
}

/**
 * Lista „Do sprawdzenia” (52): frazy ze znacznikiem AI. Klucz
 * `{skrót frazy}|{język}`, edycja w zakładce fraz.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_frazy_do_sprawdzenia(): array {
    if (!evk_tl_frazy_dostepne()) return [];
    $z = evk_tl_frazy_znaczniki();
    if (!$z) return [];
    $jezyki = tl_get_languages();
    $adres = admin_url('options-general.php?page=' . (defined('TL_MENU_SLUG') ? TL_MENU_SLUG : 'evoke-tlumaczenia') . '&tab=translations');
    $out = [];
    foreach (evk_tl_frazy_slownik()['groups'] as $g) {
        foreach ((array) ($g['rows'] ?? []) as $r) {
            $pl = trim((string) ($r['pl'] ?? ''));
            foreach (array_keys($jezyki) as $lang) {
                $kod = evk_tl_ai_kod((string) $lang);
                if ($pl === '' || !isset($z[$kod][md5($pl)]) || evk_tl_frazy_ai($pl, $kod, (string) ($r[$lang] ?? '')) === null) continue;
                $klucz = md5($pl) . '|' . $kod;
                if (isset($out[$klucz])) continue;
                $out[$klucz] = ['post_id' => 0, 'meta_key' => EVK_TL_AI_FRAZY, 'klucz' => $klucz, 'tytul' => 'Słownik fraz', 'edycja' => $adres,
                    'element' => 'Fraza', 'pole' => (string) ($g['name'] ?? ''), 'jezyk' => $kod, 'oryginal' => $pl, 'tlumaczenie' => (string) $r[$lang], 'ai' => true];
            }
        }
    }
    return array_values($out);
}

/** „Sprawdzone” frazy: znacznik AI w tym języku znika. */
function evk_tl_frazy_sprawdzone(string $klucz): bool {
    $p = strrpos($klucz, '|');
    if ($p === false) return false;
    [$h, $kod] = [substr($klucz, 0, $p), substr($klucz, $p + 1)];
    $z = evk_tl_frazy_znaczniki();
    if (!isset($z[$kod][$h])) return false;
    unset($z[$kod][$h]);
    if (!$z[$kod]) unset($z[$kod]);
    update_option(EVK_TL_FRAZY_AI, $z, false);
    return true;
}
