<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — DeepL jako czwarty dostawca tłumaczenia AI (1.277.0).
 *
 * Decyzje zgłaszającego (02.10):
 *  - klucz Free i Pro: Free kończy się „:fx” i idzie na api-free.deepl.com;
 *  - formalność osobno dla każdego języka;
 *  - słowniczek z ustawień jako glosariusz DeepL, `tag_handling=html`;
 *  - wariant języka z kodu HTML (en-US → EN-US, en-GB → EN-GB), bez regionu
 *    odmiany europejskie: EN-GB, PT-PT;
 *  - DeepL w tym samym wyborze co Gemini/Claude/OpenAI; opis obrazów (alt
 *    z AI) idzie przez osobny wybór modelu AI, bo DeepL tylko tłumaczy;
 *  - kontekst części strony w parametrze `context` (nie liczy się do limitu
 *    znaków).
 *
 * DeepL nie bierze promptu: opis strony i wskazówki dla języka nie mają tu
 * zastosowania. Teksty idą listą, w kolejności porcji, a strażnik szkieletu
 * (61, evk_tl_ai_zgodne) sprawdza wynik tak samo jak u modeli.
 *
 * CO CHRONIMY PRZED TŁUMACZENIEM. W trybie HTML DeepL zostawia nietknięte
 * elementy z `translate="no"`. Tagi `{…}` i zarejestrowane shortcody idą
 * w takim spanie ze znacznikiem, a po odpowiedzi wracają jako goły tekst.
 * Nowa linia w trybie HTML to zwykły odstęp, więc idzie jako `<br>` ze
 * znacznikiem i wraca jako „\n”. Tekst bez znaczników i encji (tytuł, alt)
 * po odpowiedzi traci encje, które DeepL dodaje w trybie HTML („&amp;”).
 */

const EVK_TL_DEEPL_GLOSARIUSZE = 'evk_tl_deepl_glosariusze';
/** Najdłuższy kontekst w znakach — DeepL nie liczy go do limitu, ale żądanie ma swój rozmiar. */
const EVK_TL_DEEPL_KONTEKST = 4000;

/** Adres API: Free dla klucza z „:fx”, inaczej Pro. Filtr podmienia adresy w testach. */
function evk_tl_deepl_baza(string $klucz): string {
    $a = (array) apply_filters('evk_tl_deepl_adresy', ['free' => 'https://api-free.deepl.com', 'pro' => 'https://api.deepl.com']);
    return rtrim((string) (substr($klucz, -3) === ':fx' ? $a['free'] : $a['pro']), '/');
}

/** Rodzaj klucza — podpis w „Do sprawdzenia” („deepl/free”, „deepl/pro”). */
function evk_tl_deepl_rodzaj(string $klucz): string {
    return substr($klucz, -3) === ':fx' ? 'free' : 'pro';
}

/**
 * Język docelowy DeepL z kodu HTML języka w ustawieniach: EN-GB, EN-US,
 * PT-PT, PT-BR, ZH-HANS, ZH-HANT, NB, reszta — sam kod języka wielkimi.
 */
function evk_tl_deepl_jezyk(string $lang): string {
    $j = tl_get_languages()[$lang] ?? [];
    $html = strtolower(str_replace('_', '-', (string) ($j['html'] ?? $lang)));
    $cz = explode('-', $html);
    $baza = $cz[0] !== '' ? $cz[0] : strtolower($lang);
    $region = $cz[1] ?? '';
    if ($baza === 'en') return $region === 'us' ? 'EN-US' : 'EN-GB';
    if ($baza === 'pt') return $region === 'br' ? 'PT-BR' : 'PT-PT';
    if ($baza === 'zh') return in_array($region, ['tw', 'hk', 'mo', 'hant'], true) ? 'ZH-HANT' : 'ZH-HANS';
    if ($baza === 'no' || $baza === 'nn') return 'NB';
    return strtoupper($baza);
}

/** Język glosariusza: sam kod bazowy, małymi (glosariusze nie znają wariantów). */
function evk_tl_deepl_jezyk_glosariusza(string $lang): string {
    return strtolower((string) explode('-', evk_tl_deepl_jezyk($lang))[0]);
}

/**
 * Formalność z ustawień: `prefer_more`/`prefer_less` — DeepL stosuje ją tam,
 * gdzie język ją zna, a przy innych nie zwraca błędu. Pusta — domyślna.
 */
function evk_tl_deepl_formalnosc(array $u, string $lang): string {
    $f = (string) ($u['formalnosc'][$lang] ?? '');
    return $f === 'formalna' ? 'prefer_more' : ($f === 'nieformalna' ? 'prefer_less' : '');
}

/** Wpisy glosariusza ze słowniczka: pary dla języka i nazwy „!…” (term → term). @return array<string,string> */
function evk_tl_deepl_wpisy(array $u, string $lang): array {
    $sl = evk_tl_ai_slowniczek($u['slowniczek'], $lang, array_keys(tl_get_languages()));
    $w = [];
    foreach ($sl['stale'] as $t) $w[$t] = $t;
    foreach ($sl['pary'] as $pl => $t) $w[$pl] = $t;
    /* TSV: bez tabulatorów i nowych linii, bez pustych stron. */
    $out = [];
    foreach ($w as $a => $b) {
        $a = trim(str_replace(["\t", "\r", "\n"], ' ', (string) $a));
        $b = trim(str_replace(["\t", "\r", "\n"], ' ', (string) $b));
        if ($a !== '' && $b !== '') $out[$a] = $b;
    }
    return $out;
}

/** Żądanie do DeepL. @return array{kod:int,o:mixed,retry:string,blad?:string} */
function evk_tl_deepl_http(string $metoda, string $klucz, string $sciezka, ?array $cialo = null): array {
    $args = ['method' => $metoda, 'timeout' => 120, 'headers' => ['Authorization' => 'DeepL-Auth-Key ' . $klucz]];
    if ($cialo !== null) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = (string) wp_json_encode($cialo);
    }
    $odp = wp_remote_request(evk_tl_deepl_baza($klucz) . $sciezka, $args);
    if (is_wp_error($odp)) return ['kod' => 0, 'o' => null, 'retry' => '', 'blad' => $odp->get_error_message()];
    $retry = wp_remote_retrieve_header($odp, 'retry-after');
    return ['kod' => (int) wp_remote_retrieve_response_code($odp), 'o' => json_decode((string) wp_remote_retrieve_body($odp), true),
        'retry' => is_string($retry) ? $retry : ''];
}

/**
 * Glosariusz dla języka: z pamięci, gdy słowniczek się nie zmienił; inaczej
 * nowy (stary usuwany). Bez wpisów — bez glosariusza. Błąd tworzenia nie
 * zatrzymuje tłumaczenia: idzie bez glosariusza, z notatką.
 *
 * @return array{id:string,uwaga:string}
 */
function evk_tl_deepl_glosariusz(array $u, string $klucz, string $lang): array {
    $wpisy = evk_tl_deepl_wpisy($u, $lang);
    $jg = evk_tl_deepl_jezyk_glosariusza($lang);
    $pamiec = get_option(EVK_TL_DEEPL_GLOSARIUSZE, []);
    $pamiec = is_array($pamiec) ? $pamiec : [];
    /* Klucz pamięci: konto (skrót klucza) i język — glosariusz należy do konta. */
    $kp = substr(md5($klucz), 0, 8) . ':' . $jg;
    $tsv = '';
    foreach ($wpisy as $a => $b) $tsv .= $a . "\t" . $b . "\n";
    $skrot = md5($tsv);
    $byl = is_array($pamiec[$kp] ?? null) ? $pamiec[$kp] : null;
    if ($byl && ($byl['skrot'] ?? '') === $skrot) return ['id' => (string) ($byl['id'] ?? ''), 'uwaga' => ''];
    if ($byl && !empty($byl['id'])) evk_tl_deepl_http('DELETE', $klucz, '/v2/glossaries/' . rawurlencode((string) $byl['id']));
    unset($pamiec[$kp]);
    if (!$wpisy) {
        update_option(EVK_TL_DEEPL_GLOSARIUSZE, $pamiec, false);
        return ['id' => '', 'uwaga' => ''];
    }
    $r = evk_tl_deepl_http('POST', $klucz, '/v2/glossaries', ['name' => 'Evoke ONE — słowniczek (' . $jg . ')',
        'source_lang' => 'pl', 'target_lang' => $jg, 'entries' => $tsv, 'entries_format' => 'tsv']);
    $id = is_array($r['o']) ? (string) ($r['o']['glossary_id'] ?? '') : '';
    if (($r['kod'] !== 200 && $r['kod'] !== 201) || $id === '') {
        update_option(EVK_TL_DEEPL_GLOSARIUSZE, $pamiec, false);
        $opis = is_array($r['o']) ? (string) ($r['o']['message'] ?? '') : (string) ($r['blad'] ?? '');
        return ['id' => '', 'uwaga' => 'Glosariusz DeepL nie powstał (' . $r['kod'] . ($opis !== '' ? ': ' . mb_substr($opis, 0, 200) : '') . ') — tłumaczę bez niego.'];
    }
    $pamiec[$kp] = ['id' => $id, 'skrot' => $skrot];
    update_option(EVK_TL_DEEPL_GLOSARIUSZE, $pamiec, false);
    return ['id' => $id, 'uwaga' => ''];
}

/** Tekst do wysłania: tagi {…}, shortcody i nowe linie chronione znacznikami. */
function evk_tl_deepl_zabezpiecz(string $t): string {
    $t = (string) preg_replace_callback('/\{[^{}]*\}|\[\/?[a-z_][a-z0-9_-]*[^\]]*\]/iu', static function ($m) {
        if ($m[0][0] === '[' && !(preg_match('~^\[/?([a-z_][a-z0-9_-]*)~i', $m[0], $n) && function_exists('shortcode_exists') && shortcode_exists(strtolower($n[1])))) return $m[0];
        return '<span translate="no" data-evk-dl="1">' . esc_html($m[0]) . '</span>';
    }, $t);
    return (string) preg_replace('/\r?\n/', '<br data-evk-dl="nl">', $t);
}

/** Odpowiedź z powrotem: znaczniki precz, treść chroniona z powrotem jako tekst. */
function evk_tl_deepl_przywroc(string $t, string $zrodlo): string {
    $t = (string) preg_replace_callback('~<span translate="no" data-evk-dl="1">(.*?)</span>~su', static function ($m) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }, $t);
    $t = (string) preg_replace('~<br data-evk-dl="nl"\s*/?>~', "\n", $t);
    /* Tekst bez znaczników i encji (tytuł, przycisk „Zespół & partnerzy”) — encje
       dodane przez tryb HTML („&amp;”) wracają jako znaki. Źródło z encją albo
       znacznikiem to HTML: zostaje, jak DeepL je oddał. */
    if (strpos($zrodlo, '<') === false && !preg_match('/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $zrodlo)) {
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $t;
}

/** Kontekst dla DeepL: teksty części strony, bez znaczników, przycięte do limitu. */
function evk_tl_deepl_kontekst(string $tytul, array $kontekst): string {
    $linie = [$tytul];
    foreach ($kontekst as $k) {
        $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) ($k['pl'] ?? '')), ENT_QUOTES, 'UTF-8')));
        if ($t !== '') $linie[] = $t;
    }
    return mb_substr(implode("\n", $linie), 0, EVK_TL_DEEPL_KONTEKST);
}

/** Błąd HTTP DeepL → komunikat, czekanie albo stop (jak evk_tl_ai_blad_http). @return array{blad:string,czekaj:int,stop:bool} */
function evk_tl_deepl_blad(int $kod, $o, string $retry): array {
    $opis = is_array($o) ? (string) ($o['message'] ?? '') : '';
    $opis = $opis !== '' ? ' ' . mb_substr($opis, 0, 300) : '';
    if ($kod === 456) return ['blad' => 'Limit znaków DeepL wyczerpany (konto Free: 500 tys. znaków miesięcznie).' . $opis, 'czekaj' => 0, 'stop' => true];
    if ($kod === 429) return ['blad' => 'Limit zapytań DeepL — czekam.', 'czekaj' => max(5, min(300, (int) $retry ?: 30)), 'stop' => false];
    if ($kod === 401 || $kod === 403) return ['blad' => 'DeepL odrzucił klucz API (' . $kod . ').' . $opis, 'czekaj' => 0, 'stop' => true];
    if ($kod >= 500) return ['blad' => 'DeepL przeciążony (' . $kod . ') — czekam.', 'czekaj' => max(5, min(300, (int) $retry ?: 20)), 'stop' => false];
    return ['blad' => 'Błąd DeepL (' . $kod . ').' . $opis, 'czekaj' => 0, 'stop' => true];
}

/**
 * Porcja tekstów przez DeepL — ten sam wynik co evk_tl_ai_wyslij():
 * tłumaczenia pod kluczami krótkimi albo błąd z czasem czekania lub stopem.
 *
 * @param array<string,array<string,mixed>> $porcja klucz krótki → tekst (`pl`)
 * @return array{ok:bool,tlumaczenia?:array<string,string>,blad?:string,czekaj?:int,stop?:bool,uwaga?:string,przygotowanie?:bool}
 */
function evk_tl_deepl_porcja(array $u, string $lang, string $tytul, array $kontekst, array $porcja): array {
    $klucz = evk_tl_ai_klucz($u);
    if ($klucz === '') return ['ok' => false, 'blad' => 'Brak klucza API DeepL — wpisz go w ustawieniach Tłumaczenia AI.', 'czekaj' => 0, 'stop' => true];
    $g = evk_tl_deepl_glosariusz($u, $klucz, $lang);
    $klucze = array_keys($porcja);
    $cialo = ['text' => array_map(static function ($w) { return evk_tl_deepl_zabezpiecz((string) $w['pl']); }, array_values($porcja)),
        'source_lang' => 'PL', 'target_lang' => evk_tl_deepl_jezyk($lang), 'tag_handling' => 'html'];
    $f = evk_tl_deepl_formalnosc($u, $lang);
    if ($f !== '') $cialo['formality'] = $f;
    if ($g['id'] !== '') $cialo['glossary_id'] = $g['id'];
    $ctx = evk_tl_deepl_kontekst($tytul, $kontekst);
    if ($ctx !== '') $cialo['context'] = $ctx;
    $r = evk_tl_deepl_http('POST', $klucz, '/v2/translate', $cialo);
    if ($r['kod'] === 0) return ['ok' => false, 'blad' => 'Brak połączenia z DeepL: ' . ($r['blad'] ?? ''), 'czekaj' => 15, 'stop' => false];
    if ($r['kod'] !== 200) return ['ok' => false] + evk_tl_deepl_blad($r['kod'], $r['o'], $r['retry']);
    $tl = is_array($r['o']) && is_array($r['o']['translations'] ?? null) ? array_values($r['o']['translations']) : null;
    if ($tl === null || count($tl) !== count($klucze)) return ['ok' => false, 'blad' => 'Odpowiedź DeepL nie pasuje do porcji.', 'czekaj' => 0, 'stop' => false];
    $out = [];
    foreach ($klucze as $i => $kr) {
        if (is_array($tl[$i]) && is_string($tl[$i]['text'] ?? null)) $out[$kr] = evk_tl_deepl_przywroc($tl[$i]['text'], (string) $porcja[$kr]['pl']);
    }
    return ['ok' => true, 'tlumaczenia' => $out] + ($g['uwaga'] !== '' ? ['uwaga' => $g['uwaga']] : []);
}
