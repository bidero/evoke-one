<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — tłumaczenie AI tekstów w elementach Bricksa (1.260.0).
 *
 * DECYZJE ZGŁASZAJĄCEGO (29.09):
 *   - dostawca do wyboru: Gemini (domyślnie — jedyny z darmowym poziomem
 *     API), Claude albo OpenAI z płatnym kluczem;
 *   - na początek elementy Bricksa hurtem, z panelu Tłumaczeń;
 *   - AI wypełnia tylko puste pola języków, a wynik ma znacznik
 *     „Do sprawdzenia” (zdejmuje go „Sprawdzone” albo poprawka);
 *   - tłumaczenie z kontekstem i powtarzalne.
 *
 * KONTEKST. Jedno zapytanie obejmuje teksty jednej części strony (treść,
 * nagłówek albo stopka): model widzi wszystkie w kolejności, z typem
 * elementu i nazwą pola, a tłumaczy tylko brakujące. Do tego opis strony
 * i wskazówki dla języka (np. forma „Sie”) z ustawień.
 *
 * POWTARZALNOŚĆ — nie parametrami modelu: Claude Opus 5.5 odrzuca
 * `temperature`, a Google zaleca dla Gemini 3 wartość domyślną. Zamiast tego:
 *   - słowniczek: pary PL → język i nazwy „nie tłumacz”, w każdym zapytaniu;
 *   - pamięć tłumaczeń: ten sam polski tekst ze sprawdzonym tłumaczeniem
 *     (pole w innym elemencie albo cała fraza słownika) dostaje je bez
 *     pytania AI — oszczędza też darmowy limit;
 *   - pamięć wyników: ten sam tekst przy tych samych ustawieniach (dostawca,
 *     model, wersja zapytania, słowniczek, opis, wskazówki) daje ten sam
 *     wynik, także na innej stronie.
 *
 * STRAŻNICY. Tłumaczenie musi mieć te same znaczniki HTML (w tej samej
 * kolejności), te same tagi `{…}` i te same zarejestrowane shortcody co
 * oryginał — inaczej odrzut. Tekst bez liter (sam tag danych dynamicznych,
 * liczba) do AI nie idzie.
 *
 * ZAPIS jak przycisk „Przenieś” (53): tylko puste pola, wykaz pól dopisanych
 * (otwarty builder ich nie zgubi), a stan „Do sprawdzenia” (52) dostaje
 * skrót źródła `ai`. Lista pokazuje takie miejsca z powodem „AI”,
 * a „Sprawdzone” przyjmuje bieżący oryginał jak zwykle.
 *
 * PONOWNIE (1.262.0, decyzje zgłaszającego z 29.09): przebieg „puste i AI do
 * sprawdzenia” tłumaczy od nowa wyłącznie niesprawdzone tłumaczenia AI —
 * sprawdzone i wpisane ręcznie nigdy. Dostawcę i model wybiera się przy
 * przebiegu, ustawienia zostają. Stan miejsca (52) dostaje `model`
 * („dostawca/model”) i `poprz` (poprzedni tekst z jego modelem) — okienko
 * sprawdzania na stronie (62) pokazuje je i przywraca.
 *
 * CZYSZCZENIE (1.264.0, zgłoszone 30.09: „bez tego tłumaczenie hurtowe nie
 * pozwala na ponowne tłumaczenie tym samym modelem”). Decyzje zgłaszającego:
 *   - „Wyczyść tłumaczenia strony” w zakładce Tłumaczenie AI: jedna część
 *     strony (treść, nagłówek albo stopka), wybrane języki, zakres do wyboru —
 *     tylko niesprawdzone tłumaczenia AI (domyślnie) albo wszystkie;
 *   - czyszczenie zapomina też wyniki AI dla tekstów tej strony, wszystkich
 *     modeli — hurt zapyta od nowa, także tym samym modelem;
 *   - w hurcie „Pytaj AI od nowa”: przebieg bez odczytu pamięci wyników;
 *   - „Przywróć wyczyszczone”: wyczyszczone tłumaczenia (ze stanem) wracają
 *     do pustych pól, dopóki tej strony nie wyczyści się ponownie. Pole
 *     wypełnione od nowa przez AI dostaje wyczyszczony tekst jako `poprz`,
 *     więc lista „Teksty w elementach” i okienko na stronie przywracają go
 *     pojedynczo.
 * Pamięć wyników ma przez to klucz „tekst.ustawienia”: pierwsza część to
 * sam tekst w języku, więc wyniki wszystkich modeli dla tekstu da się
 * znaleźć bez znajomości modelu.
 *
 * BUILDER (1.265.0, decyzje zgłaszającego z 30.09): „Przetłumacz (AI)” pod
 * polem „Tłumaczenie EN” i przy przełączniku PL | EN | DE (zaznaczony
 * element z dziećmi, język przełącznika; przy PL nieaktywny z podpowiedzią),
 * model z ustawień. Skrypt kanwy (assets/admin/tl-builder-podglad.js) wpisuje
 * wynik do stanu buildera — puste pola bez pytania, wypełnione po
 * potwierdzeniu — a zapis zostaje ręczny, w Bricksie. Serwer tylko tłumaczy.
 *
 * KLUCZ API tylko w opcji: nigdy w HTML ani JS, poza paczką ustawień.
 * HTTP przez wp_remote_post — wtyczka nie ma Composera, a trzech dostawców
 * obsługuje jeden kod. Adresy da się podmienić filtrem `evk_tl_ai_adresy`
 * (testy stawiają atrapę).
 */

const EVK_TL_AI_OPCJA  = 'evk_tl_ai';
const EVK_TL_AI_PAMIEC = 'evk_tl_ai_pamiec';
/** Wersja zapytania: zmiana treści zapytania unieważnia pamięć wyników. */
const EVK_TL_AI_WERSJA = 1;
/** Najwięcej tekstów i znaków w jednym zapytaniu: odpowiedź ok. 2 tys. tokenów,
    czyli kilkadziesiąt sekund nawet u wolniejszego modelu — mieści się w limicie
    czasu hostingu i serwera pośredniczącego (Cloudflare: 100 s). */
const EVK_TL_AI_PORCJA = 25;
const EVK_TL_AI_ZNAKI  = 6000;
/** Kontekst: tyle tekstów wokół porcji (długie strony nie rosną bez końca). */
const EVK_TL_AI_KONTEKST = 200;
/** Pamięć wyników: tyle ostatnich tłumaczeń. */
const EVK_TL_AI_PAMIEC_MAX = 5000;
/** Kopia wyczyszczonych tłumaczeń wpisu (1.264.0): klucz meta części →
    czas i miejsca (tekst i wpis stanu 52). */
const EVK_TL_AI_WYCZYSZCZONE = '_evk_tl_ai_wyczyszczone';

// =========================================================================
// USTAWIENIA
// =========================================================================

/** Dostawcy: nazwa i model domyślny (z dokumentacji dostawców, 29.09.2026). */
function evk_tl_ai_dostawcy(): array {
    return [
        'gemini' => ['nazwa' => 'Gemini (Google) — darmowy poziom', 'model' => 'gemini-3.8-flash'],
        'claude' => ['nazwa' => 'Claude (Anthropic) — płatny klucz', 'model' => 'claude-opus-5-5'],
        'openai' => ['nazwa' => 'OpenAI — płatny klucz', 'model' => 'gpt-6-astra'],
    ];
}

/**
 * @return array{dostawca:string,klucze:array<string,string>,modele:array<string,string>,opis:string,wskazowki:array<string,string>,slowniczek:string}
 */
function evk_tl_ai_ustawienia(): array {
    $u = get_option(EVK_TL_AI_OPCJA, []);
    $u = is_array($u) ? $u : [];
    $d = evk_tl_ai_dostawcy();
    $napisy = static function ($v) use ($d): array {
        return array_map('strval', array_intersect_key(is_array($v) ? $v : [], $d));
    };
    return [
        'dostawca'   => isset($d[$u['dostawca'] ?? '']) ? (string) $u['dostawca'] : 'gemini',
        'klucze'     => $napisy($u['klucze'] ?? []),
        'modele'     => $napisy($u['modele'] ?? []),
        'opis'       => (string) ($u['opis'] ?? ''),
        'wskazowki'  => array_map('strval', is_array($u['wskazowki'] ?? null) ? $u['wskazowki'] : []),
        'slowniczek' => (string) ($u['slowniczek'] ?? ''),
    ];
}

function evk_tl_ai_model(array $u): string {
    $m = trim((string) ($u['modele'][$u['dostawca']] ?? ''));
    return $m !== '' ? $m : evk_tl_ai_dostawcy()[$u['dostawca']]['model'];
}

function evk_tl_ai_klucz(array $u): string {
    return trim((string) ($u['klucze'][$u['dostawca']] ?? ''));
}

/** Nazwa modelu z formularza: znaki spotykane w nazwach modeli trzech dostawców. */
function evk_tl_ai_czysty_model(string $m): string {
    return substr((string) preg_replace('/[^A-Za-z0-9._:-]/', '', trim($m)), 0, 100);
}

/**
 * Ustawienia z dostawcą i modelem wybranymi na jeden przebieg (1.262.0).
 * Zapisane ustawienia zostają bez zmian; pusty model — ten z ustawień.
 */
function evk_tl_ai_na_przebieg(array $u, string $dostawca, string $model): array {
    if (isset(evk_tl_ai_dostawcy()[$dostawca])) $u['dostawca'] = $dostawca;
    $model = evk_tl_ai_czysty_model($model);
    if ($model !== '') $u['modele'][$u['dostawca']] = $model;
    return $u;
}

/** Model w stanie „Do sprawdzenia”: „dostawca/model”. */
function evk_tl_ai_podpis(array $u): string {
    return $u['dostawca'] . '/' . evk_tl_ai_model($u);
}

/**
 * Dostawcy z zapisanym kluczem — do wyboru przy przebiegu i w okienku
 * sprawdzania: nazwa i model z ustawień (albo domyślny).
 *
 * @return array<string,array{nazwa:string,model:string}>
 */
function evk_tl_ai_dostepni(array $u): array {
    $out = [];
    foreach (evk_tl_ai_dostawcy() as $k => $d) {
        if (trim((string) ($u['klucze'][$k] ?? '')) === '') continue;
        $out[$k] = ['nazwa' => $d['nazwa'], 'model' => evk_tl_ai_model(['dostawca' => $k] + $u)];
    }
    return $out;
}

/**
 * Słowniczek z ustawień: linia `polski | EN | DE` (kolumny w kolejności
 * języków), linia `!Nazwa` — nie tłumaczyć, `#` — komentarz.
 *
 * @param list<string> $kody Języki w kolejności kolumn.
 * @return array{stale:list<string>,pary:array<string,string>}
 */
function evk_tl_ai_slowniczek(string $tekst, string $lang, array $kody): array {
    $out = ['stale' => [], 'pary' => []];
    $kol = array_search($lang, $kody, true);
    foreach ((array) preg_split('/\R/u', $tekst) as $linia) {
        $linia = trim((string) $linia);
        if ($linia === '' || $linia[0] === '#') continue;
        if ($linia[0] === '!') {
            $t = trim(substr($linia, 1));
            if ($t !== '') $out['stale'][] = $t;
            continue;
        }
        $cz = array_map('trim', explode('|', $linia));
        $t = $kol === false ? '' : (string) ($cz[$kol + 1] ?? '');
        if ($cz[0] !== '' && $t !== '') $out['pary'][$cz[0]] = $t;
    }
    return $out;
}

// =========================================================================
// TEKSTY DO TŁUMACZENIA
// =========================================================================

/** Czy tekst ma co tłumaczyć: litery poza znacznikami, tagami `{…}` i shortcodami. */
function evk_tl_ai_do_tlumaczenia(string $pl): bool {
    $t = html_entity_decode(wp_strip_all_tags($pl), ENT_QUOTES, 'UTF-8');
    $t = (string) preg_replace('/\{[^{}]*\}|\[[^\[\]]*\]/u', ' ', $t);
    return (bool) preg_match('/\p{L}/u', $t);
}

/**
 * Nazwa języka w zapytaniu: po angielsku, z kodem z ustawień („English
 * (en-US)”). Nazwa z ustawień jest polska („Angielski”), a zapytanie idzie
 * po angielsku. Bez rozszerzenia intl — hosting nie zawsze je ma.
 */
function evk_tl_ai_jezyk(string $lang): string {
    $nazwy = ['en' => 'English', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian',
        'pt' => 'Portuguese', 'nl' => 'Dutch', 'cs' => 'Czech', 'sk' => 'Slovak', 'uk' => 'Ukrainian', 'ru' => 'Russian',
        'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'nb' => 'Norwegian', 'fi' => 'Finnish', 'hu' => 'Hungarian',
        'ro' => 'Romanian', 'lt' => 'Lithuanian', 'lv' => 'Latvian', 'et' => 'Estonian', 'hr' => 'Croatian', 'sl' => 'Slovenian',
        'sr' => 'Serbian', 'bg' => 'Bulgarian', 'el' => 'Greek', 'tr' => 'Turkish', 'he' => 'Hebrew', 'ar' => 'Arabic',
        'ja' => 'Japanese', 'zh' => 'Chinese', 'ko' => 'Korean', 'vi' => 'Vietnamese'];
    $j = tl_get_languages()[$lang] ?? [];
    $html = (string) ($j['html'] ?? $lang);
    $baza = strtolower((string) (preg_split('/[-_]/', $html)[0] ?? ''));
    $nazwa = $nazwy[$baza] ?? $nazwy[strtolower($lang)] ?? (string) ($j['name'] ?? $lang);
    return $nazwa . ' (' . $html . ')';
}

/** Kod języka w kluczu miejsca — jak w stanie „Do sprawdzenia” (52). */
function evk_tl_ai_kod(string $lang): string {
    return (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($lang));
}

/**
 * Teksty jednej części strony: wszystkie (kontekst, w kolejności, z już
 * istniejącym tłumaczeniem — model trzyma się jego słownictwa) i te bez
 * tłumaczenia w języku, z numerem w kontekście. Klucz miejsca jak w stanie
 * (52): „id|ścieżka|język”.
 *
 * Tryb ponowny (1.262.0): `$stan` — stan „Do sprawdzenia” tej części (52).
 * Niesprawdzone tłumaczenie AI idzie do tłumaczenia jak brak, z obecnym
 * tekstem w `bylo`. `$wymus` — klucze tłumaczone od nowa niezależnie od
 * stanu (okienko sprawdzania, 62). Kontekst pokazuje takie teksty bez
 * obecnego tłumaczenia: inny model ma przetłumaczyć po swojemu, a nie
 * powtórzyć tamto.
 *
 * @param mixed                     $dane  Dane Bricksa (lista elementów).
 * @param array<string,mixed>|null $stan
 * @param array<string,bool>        $wymus
 * @return array{kontekst:list<array{element:string,opis:string,pl:string,tl:string}>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty($dane, string $lang, ?array $stan = null, array $wymus = []): array {
    $out = ['kontekst' => [], 'braki' => []];
    if (!is_array($dane)) return $out;
    $mapa = evk_tl_el_mapa();
    $kod = evk_tl_ai_kod($lang);
    $dodaj = static function (array $ust, string $pole, string $id, string $element, string $sciezka, string $opis) use ($lang, $kod, $stan, $wymus, &$out): void {
        $pl = $ust[$pole] ?? null;
        if (!is_string($pl) || !evk_tl_ai_do_tlumaczenia($pl)) return;
        $tl = $ust[evk_tl_el_klucz($lang, $pole)] ?? null;
        $jest = evk_tl_el_niepuste($tl);
        $klucz = $id . '|' . $sciezka . '|' . $kod;
        $ponownie = $jest && is_string($tl) && (isset($wymus[$klucz]) || ($stan !== null && evk_tl_ai_niesprawdzone($stan[$klucz] ?? null)));
        $out['kontekst'][] = ['element' => $element, 'opis' => $opis, 'pl' => $pl, 'tl' => $jest && is_string($tl) && !$ponownie ? $tl : ''];
        if ($jest && !$ponownie) return;
        $out['braki'][$klucz] = ['pl' => $pl, 'element' => $element, 'opis' => $opis,
            'id' => $id, 'sciezka' => $sciezka, 'pole' => $pole, 'n' => count($out['kontekst']), 'bylo' => $ponownie ? (string) $tl : ''];
    };
    foreach ($dane as $el) {
        /* Instancja komponentu (1.272.0): teksty jej właściwości, tłumaczenie do bliźniaka (51). */
        if (is_array($el) && isset($el['cid'])) {
            $v = function_exists('evk_tl_kp_instancja') ? evk_tl_kp_instancja($el, $lang) : null;
            if ($v) foreach ($v['pola'] as $pole => $opis) $dodaj($v['ustawienia'], $pole, (string) ($el['id'] ?? ''), $v['nazwa'], $pole, $opis);
            continue;
        }
        if (!is_array($el) || !is_array($el['settings'] ?? null)) continue;
        $nazwa = (string) ($el['name'] ?? '');
        $def = $mapa[$nazwa] ?? null;
        if (!is_array($def)) continue;
        $id = (string) ($el['id'] ?? '');
        foreach ((array) ($def['pola'] ?? []) as $pole) {
            $dodaj($el['settings'], (string) $pole, $id, $nazwa, (string) $pole, evk_tl_el_nazwa_pola((string) $pole));
        }
        foreach ((array) ($def['listy'] ?? []) as $lista => $pola) {
            $pozycje = $el['settings'][$lista] ?? null;
            if (!is_array($pozycje) || !$pozycje || array_keys($pozycje) !== range(0, count($pozycje) - 1)) continue;
            foreach ($pozycje as $i => $poz) {
                if (!is_array($poz)) continue;
                $pid = isset($poz['id']) && is_scalar($poz['id']) && (string) $poz['id'] !== '' ? (string) $poz['id'] : (string) $i;
                foreach ((array) $pola as $pole) {
                    $dodaj($poz, (string) $pole, $id, $nazwa, $lista . '.' . $pid . '.' . $pole,
                        'pozycja ' . ($i + 1) . ' · ' . evk_tl_el_nazwa_pola((string) $pole));
                }
            }
        }
    }
    return $out;
}

/**
 * Niesprawdzone tłumaczenie AI: źródło `ai`. Każda zmiana tekstu liczy stan
 * od nowa (52, poprawka w builderze albo w okienku), a „Sprawdzone” wpisuje
 * skrót oryginału — w obu przypadkach to już nie AI.
 *
 * @param mixed $s Wpis stanu miejsca (52).
 */
function evk_tl_ai_niesprawdzone($s): bool {
    return is_array($s) && ($s['src'] ?? '') === 'ai';
}

/** Stan „Do sprawdzenia” jednej części wpisu (52): klucz miejsca → wpis. */
function evk_tl_ai_stan_czesci(int $post_id, string $meta_key): array {
    $stan = get_post_meta($post_id, EVK_TL_EL_STAN, true);
    return is_array($stan) && is_array($stan[$meta_key] ?? null) ? $stan[$meta_key] : [];
}

/**
 * Pamięć tłumaczeń języka: polski tekst → sprawdzone tłumaczenie. Pola
 * języków w elementach całej strony (bez tych „Do sprawdzenia”), a dla
 * tekstów bez znaczników w środku — cała fraza słownika.
 *
 * @return array{pola:array<string,string>,slownik:array<string,array<string,mixed>>}
 */
function evk_tl_ai_pamiec_tlumaczen(string $lang): array {
    static $pamiec = [];
    if (isset($pamiec[$lang])) return $pamiec[$lang];
    $kod = evk_tl_ai_kod($lang);
    $sprawdz = [];
    foreach (evk_tl_el_do_sprawdzenia(1000) as $m) $sprawdz[$m['post_id'] . '|' . $m['meta_key'] . '|' . $m['klucz']] = true;
    $pola = [];
    foreach (evk_tl_el_wpisy_bricksa() as [$post_id, $meta_key]) {
        foreach (evk_tl_el_miejsca(get_post_meta($post_id, $meta_key, true)) as $klucz => $m) {
            if ($m['jezyk'] !== $kod || $m['oryginal'] === '' || !evk_tl_el_niepuste($m['tlumaczenie'])) continue;
            if (isset($sprawdz[$post_id . '|' . $meta_key . '|' . $klucz]) || isset($pola[$m['oryginal']])) continue;
            $pola[$m['oryginal']] = $m['tlumaczenie'];
        }
    }
    return $pamiec[$lang] = ['pola' => $pola, 'slownik' => function_exists('tl_get_match_index') ? (array) tl_get_match_index($lang) : []];
}

/** Sprawdzone tłumaczenie z pamięci; null gdy go nie ma. */
function evk_tl_ai_z_pamieci(string $pl, string $lang): ?string {
    $p = evk_tl_ai_pamiec_tlumaczen($lang);
    if (isset($p['pola'][$pl])) return $p['pola'][$pl];
    $k = evk_tl_el_klucz_slownika($pl);
    $t = $k ? ($p['slownik'][$k[0]]['tlum'] ?? '') : '';
    return is_string($t) && trim($t) !== '' ? $k[1] . $t . $k[2] : null;
}

/** Pierwsza część klucza pamięci wyników: sam tekst w języku. */
function evk_tl_ai_klucz_tekstu(string $lang, string $pl): string {
    return md5($lang . "\n" . $pl);
}

/**
 * Klucz pamięci wyników: ten sam tekst przy tych samych ustawieniach.
 * „tekst.ustawienia” (1.264.0) — czyszczenie strony zapomina wyniki
 * wszystkich modeli dla jej tekstów po pierwszej części.
 */
function evk_tl_ai_klucz_wyniku(array $u, string $lang, string $pl): string {
    return evk_tl_ai_klucz_tekstu($lang, $pl) . '.' . md5((string) wp_json_encode([EVK_TL_AI_WERSJA, $u['dostawca'], evk_tl_ai_model($u),
        $u['opis'], $u['wskazowki'][$lang] ?? '', $u['slowniczek']]));
}

function evk_tl_ai_wynik(array $u, string $lang, string $pl): ?string {
    $p = get_option(EVK_TL_AI_PAMIEC, []);
    $t = is_array($p) ? ($p[evk_tl_ai_klucz_wyniku($u, $lang, $pl)] ?? null) : null;
    return is_string($t) ? $t : null;
}

/**
 * Pamięć bez wpisów w starym kluczu (sam skrót, sprzed 1.264.0): nowy kod
 * ich nie odczyta, a zajmowałyby miejsce do wypchnięcia przez limit.
 *
 * @param mixed $p
 * @return array<string,string>
 */
function evk_tl_ai_pamiec_biezaca($p): array {
    if (!is_array($p)) return [];
    foreach (array_keys($p) as $k) {
        if (strpos((string) $k, '.') === false) unset($p[$k]);
    }
    return $p;
}

/** @param array<string,string> $nowe klucz wyniku → tłumaczenie */
function evk_tl_ai_zapamietaj(array $nowe): void {
    if (!$nowe) return;
    $p = evk_tl_ai_pamiec_biezaca(get_option(EVK_TL_AI_PAMIEC, []));
    foreach ($nowe as $k => $t) {
        unset($p[$k]);
        $p[$k] = $t;
    }
    if (count($p) > EVK_TL_AI_PAMIEC_MAX) $p = array_slice($p, -EVK_TL_AI_PAMIEC_MAX, null, true);
    update_option(EVK_TL_AI_PAMIEC, $p, false);
}

/**
 * Zapomina wyniki wszystkich modeli i ustawień dla tekstów (1.264.0).
 *
 * @param list<array{0:string,1:string}> $teksty [język, polski tekst]
 * @return int Ile wyników zapomniano.
 */
function evk_tl_ai_zapomnij(array $teksty): int {
    if (!$teksty) return 0;
    $szukane = [];
    foreach ($teksty as [$lang, $pl]) $szukane[evk_tl_ai_klucz_tekstu((string) $lang, (string) $pl)] = true;
    $p = evk_tl_ai_pamiec_biezaca(get_option(EVK_TL_AI_PAMIEC, []));
    $ile = 0;
    foreach (array_keys($p) as $k) {
        if (isset($szukane[strtok((string) $k, '.')])) {
            unset($p[$k]);
            $ile++;
        }
    }
    update_option(EVK_TL_AI_PAMIEC, $p, false);
    return $ile;
}

// =========================================================================
// STRAŻNIK TŁUMACZENIA
// =========================================================================

/**
 * To, czego tłumaczenie nie może zmienić: znaczniki HTML po kolei, tagi `{…}`
 * i zarejestrowane shortcody (bez kolejności — szyk zdania bywa inny).
 *
 * @return array{0:list<string>,1:list<string>,2:list<string>}
 */
function evk_tl_ai_szkielet(string $t): array {
    preg_match_all('~<\s*(/?)\s*([a-z][a-z0-9-]*)~i', $t, $m, PREG_SET_ORDER);
    $tagi = array_map(static function ($x) { return $x[1] . strtolower($x[2]); }, $m);
    preg_match_all('/\{[^{}]*\}/u', $t, $k);
    $klamry = $k[0];
    sort($klamry);
    preg_match_all('~\[/?([a-z_][a-z0-9_-]*)[^\]]*\]~i', $t, $s, PREG_SET_ORDER);
    $kody = [];
    foreach ($s as $x) {
        if (function_exists('shortcode_exists') && shortcode_exists(strtolower($x[1]))) $kody[] = $x[0];
    }
    sort($kody);
    return [$tagi, $klamry, $kody];
}

function evk_tl_ai_zgodne(string $pl, string $t): bool {
    return trim(wp_strip_all_tags($t)) !== '' && evk_tl_ai_szkielet($pl) === evk_tl_ai_szkielet($t);
}

// =========================================================================
// ZAPYTANIE I DOSTAWCY
// =========================================================================

/** Schemat odpowiedzi — ten sam u trzech dostawców. */
function evk_tl_ai_schemat(): array {
    return [
        'type' => 'object',
        'properties' => ['translations' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['key' => ['type' => 'string'], 'text' => ['type' => 'string']],
            'required' => ['key', 'text'],
            'additionalProperties' => false,
        ]]],
        'required' => ['translations'],
        'additionalProperties' => false,
    ];
}

/**
 * Treść zapytania: instrukcje (stałe dla języka i ustawień) i wiadomość
 * z kontekstem strony oraz tekstami do tłumaczenia pod kluczami t1, t2…
 * Teksty jako napisy JSON — wieloliniowy akapit nie rozbija listy.
 *
 * @param list<array<string,string>>       $kontekst
 * @param array<string,array<string,mixed>> $porcja  klucz krótki → tekst
 * @return array{0:string,1:string}
 */
function evk_tl_ai_tresc(array $u, string $lang, string $tytul, array $kontekst, array $porcja): array {
    $jezyk = evk_tl_ai_jezyk($lang);
    $sl = evk_tl_ai_slowniczek($u['slowniczek'], $lang, array_keys(tl_get_languages()));
    $s = "You translate website copy from Polish into {$jezyk}.\n\nRules:\n"
       . "- Translate naturally for native readers, in the tone of the website. Keep the meaning; do not add or leave out content.\n"
       . "- Keep unchanged: HTML tags and their order, placeholders in curly braces {…}, shortcodes in square brackets […], URLs, e-mail addresses, numbers.\n"
       . "- Keep each text's form: a heading stays a heading, a button label stays short, line breaks stay.\n"
       . "- Translate only the items under TO TRANSLATE. The CONTEXT lists the texts of this part of the page in order, so that the translations fit together; "
       . "a text that already has a translation shows it after the arrow — keep the terminology consistent with it.\n"
       . "- Return JSON: {\"translations\":[{\"key\":\"t1\",\"text\":\"…\"}]}, exactly one entry per key.\n";
    if (trim($u['opis']) !== '') $s .= "\nAbout the website:\n" . trim($u['opis']) . "\n";
    $wsk = trim((string) ($u['wskazowki'][$lang] ?? ''));
    if ($wsk !== '') $s .= "\nGuidance for {$jezyk}:\n" . $wsk . "\n";
    if ($sl['stale']) $s .= "\nNever translate these names; keep them exactly as written:\n- " . implode("\n- ", $sl['stale']) . "\n";
    if ($sl['pary']) {
        $s .= "\nGlossary (always use these translations):\n";
        foreach ($sl['pary'] as $pl => $t) $s .= "- {$pl} → {$t}\n";
    }
    $j = static function (string $t): string {
        return (string) wp_json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $od = max(0, min(array_map(static function ($w) { return (int) ($w['n'] ?? 1); }, $porcja ?: [['n' => 1]])) - 1 - intdiv(EVK_TL_AI_KONTEKST, 4));
    $ctx = [];
    foreach (array_slice($kontekst, $od, EVK_TL_AI_KONTEKST, true) as $i => $k) {
        $ctx[] = ($i + 1) . '. [' . $k['element'] . ' · ' . $k['opis'] . '] ' . $j($k['pl']) . (($k['tl'] ?? '') !== '' ? ' → ' . $j($k['tl']) : '');
    }
    $do = [];
    foreach ($porcja as $klucz => $w) {
        $do[] = ['key' => $klucz, 'n' => (int) ($w['n'] ?? 0), 'element' => $w['element'] . ' · ' . $w['opis'], 'text' => $w['pl']];
    }
    $m = 'Page: ' . $tytul . "\n\nCONTEXT:\n" . implode("\n", $ctx) . "\n\nTO TRANSLATE (n = number in CONTEXT):\n"
       . wp_json_encode($do, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return [$s, $m];
}

/** Adresy dostawców; filtr podmienia je w testach. */
function evk_tl_ai_adresy(): array {
    return (array) apply_filters('evk_tl_ai_adresy', [
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
        'claude' => 'https://api.anthropic.com/v1/messages',
        'openai' => 'https://api.openai.com/v1/responses',
    ]);
}

/**
 * Żądanie do dostawcy: [adres, nagłówki, ciało].
 *
 * Claude: odpowiedź w schemacie przez `output_config.format`; dla modeli,
 * które je znają, `fallbacks: "default"` (odmowa klasyfikatora przechodzi
 * na model zapasowy po stronie serwera) i jawny `effort` (Opus 5.5 domyślnie
 * `medium`). Bez `temperature` — Opus 5.5 zwraca na nią 400.
 *
 * Obrazy (1.271.0, alt z AI): `[{mime, dane (base64)}]` — Claude blok `image`,
 * OpenAI `input_image`, Gemini `inline_data`.
 *
 * @param list<array{mime:string,dane:string}> $obrazy
 * @return array{0:string,1:array<string,string>,2:array<string,mixed>}
 */
function evk_tl_ai_zadanie(array $u, string $system, string $wiadomosc, array $obrazy = []): array {
    $model = evk_tl_ai_model($u);
    $klucz = evk_tl_ai_klucz($u);
    $adres = (string) (evk_tl_ai_adresy()[$u['dostawca']] ?? '');
    $schemat = evk_tl_ai_schemat();
    if ($u['dostawca'] === 'claude') {
        $naglowki = ['Content-Type' => 'application/json', 'x-api-key' => $klucz, 'anthropic-version' => '2023-06-01'];
        /* Obrazy (1.271.0, alt z AI): bloki `image` przed tekstem. */
        $tresc = $wiadomosc;
        if ($obrazy) {
            $tresc = [];
            foreach ($obrazy as $o) $tresc[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $o['mime'], 'data' => $o['dane']]];
            $tresc[] = ['type' => 'text', 'text' => $wiadomosc];
        }
        $cialo = ['model' => $model, 'max_tokens' => 16000, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $tresc]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schemat]]];
        if (in_array($model, ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-1'], true)) {
            $naglowki['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $cialo['fallbacks'] = 'default';
        }
        if ($model === 'claude-opus-5-5') $cialo['output_config']['effort'] = 'medium';
        return [$adres, $naglowki, $cialo];
    }
    if ($u['dostawca'] === 'openai') {
        $wejscie = $wiadomosc;
        if ($obrazy) {
            $czesci = [['type' => 'input_text', 'text' => $wiadomosc]];
            foreach ($obrazy as $o) $czesci[] = ['type' => 'input_image', 'image_url' => 'data:' . $o['mime'] . ';base64,' . $o['dane']];
            $wejscie = [['role' => 'user', 'content' => $czesci]];
        }
        return [$adres, ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $klucz], [
            'model' => $model, 'instructions' => $system, 'input' => $wejscie,
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'translations', 'strict' => true, 'schema' => $schemat]],
        ]];
    }
    return [sprintf($adres, rawurlencode($model)), ['Content-Type' => 'application/json', 'x-goog-api-key' => $klucz], [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => array_merge([['text' => $wiadomosc]],
            array_map(static function ($o) { return ['inline_data' => ['mime_type' => $o['mime'], 'data' => $o['dane']]]; }, $obrazy))]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'responseJsonSchema' => $schemat],
    ]];
}

/**
 * Tekst odpowiedzi (JSON jako napis) albo błąd bez ponawiania.
 *
 * @param array<string,mixed> $o
 * @return array{tekst?:string,blad?:string}
 */
function evk_tl_ai_tekst_odpowiedzi(string $dostawca, array $o): array {
    if ($dostawca === 'claude') {
        $powod = (string) ($o['stop_reason'] ?? '');
        if ($powod === 'refusal') return ['blad' => 'Model odmówił tłumaczenia tej porcji (' . (string) ($o['stop_details']['category'] ?? 'bez kategorii') . ').'];
        if ($powod === 'max_tokens') return ['blad' => 'Odpowiedź ucięta limitem długości.'];
        foreach ((array) ($o['content'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text') return ['tekst' => (string) ($b['text'] ?? '')];
        }
        return ['blad' => 'Odpowiedź bez tekstu.'];
    }
    if ($dostawca === 'openai') {
        if (($o['status'] ?? 'completed') !== 'completed') return ['blad' => 'Odpowiedź niepełna (' . (string) ($o['status'] ?? '') . ').'];
        foreach ((array) ($o['output'] ?? []) as $w) {
            foreach ((array) ($w['content'] ?? []) as $c) {
                if (!is_array($c)) continue;
                if (($c['type'] ?? '') === 'refusal') return ['blad' => 'Model odmówił tłumaczenia tej porcji.'];
                if (($c['type'] ?? '') === 'output_text') return ['tekst' => (string) ($c['text'] ?? '')];
            }
        }
        return ['blad' => 'Odpowiedź bez tekstu.'];
    }
    if (!empty($o['promptFeedback']['blockReason'])) return ['blad' => 'Zapytanie zablokowane (' . (string) $o['promptFeedback']['blockReason'] . ').'];
    $k = $o['candidates'][0] ?? null;
    if (!is_array($k)) return ['blad' => 'Odpowiedź bez tekstu.'];
    $tekst = '';
    foreach ((array) ($k['content']['parts'] ?? []) as $p) {
        if (is_array($p) && empty($p['thought'])) $tekst .= (string) ($p['text'] ?? '');
    }
    if ($tekst === '') return ['blad' => 'Odpowiedź bez tekstu (' . (string) ($k['finishReason'] ?? '') . ').'];
    return ['tekst' => $tekst];
}

/**
 * Błąd HTTP dostawcy: komunikat, ile sekund czekać (ponowienie) albo stop.
 *   - 429 z czasem (retry-after, RetryInfo Gemini) → czekaj;
 *   - limit dzienny darmowego poziomu Gemini, limit wydatków Claude,
 *     brak środków OpenAI → stop (ponawianie nic nie da);
 *   - 529 / 5xx → czekaj chwilę; 401/403 → stop (klucz); reszta → stop.
 *
 * @param mixed $o Zdekodowane ciało.
 * @return array{blad:string,czekaj:int,stop:bool}
 */
function evk_tl_ai_blad_http(string $dostawca, int $kod, array $naglowki, $o): array {
    $opis = is_array($o) ? (string) ($o['error']['message'] ?? '') : '';
    $opis = $opis !== '' ? ' ' . mb_substr($opis, 0, 300) : '';
    $za = (int) ($naglowki['retry-after'] ?? 0);
    if ($kod === 429) {
        if ($dostawca === 'gemini' && is_array($o)) {
            foreach ((array) ($o['error']['details'] ?? []) as $d) {
                if (!is_array($d)) continue;
                if (isset($d['retryDelay'])) $za = max($za, (int) ceil((float) $d['retryDelay']));
                foreach ((array) ($d['violations'] ?? []) as $v) {
                    if (is_array($v) && stripos((string) ($v['quotaId'] ?? ''), 'PerDay') !== false) {
                        return ['blad' => 'Dzienny limit darmowego poziomu Gemini wyczerpany — spróbuj jutro.', 'czekaj' => 0, 'stop' => true];
                    }
                }
            }
        }
        if ($dostawca === 'claude' && is_array($o) && ($o['error']['details']['error_code'] ?? '') === 'enforced_spend_limit_reached') {
            return ['blad' => 'Osiągnięty miesięczny limit wydatków konta Claude.' . $opis, 'czekaj' => 0, 'stop' => true];
        }
        if ($dostawca === 'openai' && is_array($o) && ($o['error']['code'] ?? '') === 'insufficient_quota') {
            return ['blad' => 'Brak środków na koncie OpenAI.' . $opis, 'czekaj' => 0, 'stop' => true];
        }
        return ['blad' => 'Limit zapytań dostawcy — czekam.', 'czekaj' => max(5, min(300, $za ?: 30)), 'stop' => false];
    }
    if ($kod === 529 || $kod >= 500) return ['blad' => 'Dostawca przeciążony (' . $kod . ') — czekam.', 'czekaj' => max(5, min(300, $za ?: 20)), 'stop' => false];
    if ($kod === 401 || $kod === 403) return ['blad' => 'Dostawca odrzucił klucz API (' . $kod . ').' . $opis, 'czekaj' => 0, 'stop' => true];
    return ['blad' => 'Błąd dostawcy (' . $kod . ').' . $opis, 'czekaj' => 0, 'stop' => true];
}

/**
 * Jedno zapytanie. Wynik: tłumaczenia pod kluczami krótkimi albo błąd
 * (z czasem czekania albo stopem).
 *
 * @return array{ok:bool,tlumaczenia?:array<string,string>,blad?:string,czekaj?:int,stop?:bool}
 */
function evk_tl_ai_wyslij(array $u, string $system, string $wiadomosc, array $obrazy = []): array {
    if (evk_tl_ai_klucz($u) === '') return ['ok' => false, 'blad' => 'Brak klucza API tego dostawcy — wpisz go w ustawieniach Tłumaczenia AI.', 'czekaj' => 0, 'stop' => true];
    [$adres, $naglowki, $cialo] = evk_tl_ai_zadanie($u, $system, $wiadomosc, $obrazy);
    $odp = wp_remote_post($adres, ['headers' => $naglowki, 'body' => (string) wp_json_encode($cialo), 'timeout' => 120]);
    if (is_wp_error($odp)) return ['ok' => false, 'blad' => 'Brak połączenia z dostawcą: ' . $odp->get_error_message(), 'czekaj' => 15, 'stop' => false];
    $kod = (int) wp_remote_retrieve_response_code($odp);
    $o = json_decode((string) wp_remote_retrieve_body($odp), true);
    if ($kod !== 200) {
        $retry = wp_remote_retrieve_header($odp, 'retry-after');
        return ['ok' => false] + evk_tl_ai_blad_http($u['dostawca'], $kod, ['retry-after' => is_string($retry) ? $retry : ''], $o);
    }
    if (!is_array($o)) return ['ok' => false, 'blad' => 'Odpowiedź dostawcy nie jest JSON-em.', 'czekaj' => 0, 'stop' => true];
    $t = evk_tl_ai_tekst_odpowiedzi($u['dostawca'], $o);
    if (isset($t['blad'])) return ['ok' => false, 'blad' => $t['blad'], 'czekaj' => 0, 'stop' => false];
    $j = json_decode((string) $t['tekst'], true);
    if (!is_array($j) || !is_array($j['translations'] ?? null)) return ['ok' => false, 'blad' => 'Odpowiedź nie pasuje do schematu.', 'czekaj' => 0, 'stop' => false];
    $out = [];
    foreach ($j['translations'] as $w) {
        if (is_array($w) && is_string($w['key'] ?? null) && is_string($w['text'] ?? null)) $out[$w['key']] = $w['text'];
    }
    return ['ok' => true, 'tlumaczenia' => $out];
}

// =========================================================================
// ZAPIS
// =========================================================================

/**
 * Wpisuje tłumaczenia w puste pola języka (wspólny zapis z 53: wykaz
 * dopisanych, zapis bez haka) i oznacza tłumaczenia AI w stanie
 * „Do sprawdzenia”. Zwraca liczbę zapisanych pól.
 *
 * Tryb ponowny (1.262.0): `$bylo` — klucz → obecne tłumaczenie AI, które
 * nowe ma zastąpić. Zastępuje tylko wtedy, gdy tuż przed zapisem wciąż jest
 * niesprawdzone: „Sprawdzone” albo poprawka w trakcie zapytania do AI
 * wygrywa (poprawka liczy stan od nowa). Stary tekst trafia do `poprz`
 * razem ze swoim modelem.
 *
 * Pole wyczyszczone przez „Wyczyść tłumaczenia strony” (1.264.0) i teraz
 * wypełnione: `poprz` to wyczyszczony tekst z kopii (gdy się różni) —
 * „Przywróć” w liście i w okienku na stronie wraca do niego pojedynczo.
 *
 * @param array<string,string> $gotowe klucz miejsca → tłumaczenie
 * @param array<string,bool>   $ai     klucze z AI (znacznik „Do sprawdzenia”)
 * @param array<string,string> $bylo   klucz miejsca → zastępowane tłumaczenie AI
 * @return list<string> Zapisane klucze.
 */
function evk_tl_ai_zapisz(int $post_id, string $meta_key, string $lang, array $gotowe, array $ai, string $model = '', array $bylo = []): array {
    $przed = evk_tl_ai_stan_czesci($post_id, $meta_key);
    foreach (array_keys($bylo) as $klucz) {
        if (!evk_tl_ai_niesprawdzone($przed[$klucz] ?? null)) unset($gotowe[$klucz], $bylo[$klucz]);
    }
    $zapisane = array_flip(evk_tl_el_zapisz_pola($post_id, $meta_key, $lang, $gotowe, true, $bylo));
    if (!$zapisane) return [];
    /* Stan (52) policzył się przy zapisie ze skrótem bieżącego oryginału.
       Tłumaczenia AI dostają źródło `ai`: różne od każdego skrótu, więc
       miejsce jest „Do sprawdzenia”, dopóki ktoś go nie przyjmie. */
    $stan = get_post_meta($post_id, EVK_TL_EL_STAN, true);
    if (is_array($stan)) {
        $kopia = evk_tl_ai_kopia_czesci($post_id, $meta_key);
        foreach (array_keys($zapisane) as $klucz) {
            if (!isset($stan[$meta_key][$klucz])) continue;
            if (isset($ai[$klucz])) {
                $stan[$meta_key][$klucz]['src'] = 'ai';
                if ($model !== '') $stan[$meta_key][$klucz]['model'] = $model;
            }
            if (isset($bylo[$klucz])) {
                $stan[$meta_key][$klucz]['poprz'] = ['t' => $bylo[$klucz], 'm' => (string) ($przed[$klucz]['model'] ?? '')];
            } elseif (isset($kopia[$klucz]) && $kopia[$klucz]['t'] !== $gotowe[$klucz]) {
                $stan[$meta_key][$klucz]['poprz'] = ['t' => $kopia[$klucz]['t'], 'm' => (string) ($kopia[$klucz]['s']['model'] ?? '')];
            }
        }
        // wp_slash: `poprz` to tekst, a update_post_meta zdejmuje ukośniki (1.264.0).
        update_post_meta($post_id, EVK_TL_EL_STAN, wp_slash($stan));
    }
    return array_map('strval', array_keys($zapisane));
}

// =========================================================================
// KROK
// =========================================================================

/**
 * Jeden krok dla jednej części strony w jednym języku: pamięć tłumaczeń
 * i pamięć wyników od razu, reszta — jedna porcja do AI. Klucze z `$pomin`
 * (odrzucone albo bez zmian wcześniej w tym przebiegu) nie idą drugi raz.
 *
 * `$opcje` (1.262.0): `ponownie` — także niesprawdzone tłumaczenia AI;
 * `dostawca` i `model` — na ten przebieg, ustawienia bez zmian. Wynik równy
 * obecnemu tekstowi (ten sam model przy tych samych ustawieniach) liczy się
 * jako „bez zmian” i trafia do `pominiete`. `zapisane_klucze` — do `$pomin`
 * w następnych krokach: w trybie ponownym świeże tłumaczenie AI jest znów
 * niesprawdzone i wracałoby jako „bez zmian”.
 *
 * `bez_pamieci` (1.264.0, „Pytaj AI od nowa”): bez odczytu pamięci wyników —
 * ten sam model tłumaczy jeszcze raz, a nowy wynik zastępuje zapamiętany.
 * Pamięć tłumaczeń (sprawdzone tłumaczenie tego samego tekstu) działa dalej.
 *
 * @param list<string>        $pomin
 * @param array<string,mixed> $opcje
 * @return array<string,mixed>
 */
function evk_tl_ai_krok(int $post_id, string $meta_key, string $lang, array $pomin = [], array $opcje = []): array {
    /* Teksty wpisu (1.268.0): po ostatnim kroku — adres z tytułu, gdy zaznaczony
       (także sam, bez tekstów do AI). Kroki idą jak dla każdej części. */
    if ($meta_key === EVK_TL_AI_WPIS && empty($opcje['_wpis'])) {
        $w = evk_tl_ai_krok($post_id, $meta_key, $lang, $pomin, ['_wpis' => true] + $opcje);
        if (!empty($opcje['adres']) && empty($w['zostalo']) && empty($w['stop']) && empty($w['czekaj'])) $w['adres'] = evk_tl_ai_adres_z_tytulu($post_id, $lang);
        return $w;
    }
    /* Brakujący alt PL (1.271.0): AI ogląda obrazy porcji, osobna droga. */
    if ($meta_key === EVK_TL_AI_ALT_PL) return evk_tl_ai_krok_alt_pl($post_id, (int) ($opcje['do'] ?? $post_id), $pomin, $opcje);
    /* Term (1.270.0): adres z nazwy po ostatnim kroku, jak adres z tytułu wpisu. */
    if ($meta_key === EVK_TL_AI_TERM && empty($opcje['_wpis'])) {
        $w = evk_tl_ai_krok($post_id, $meta_key, $lang, $pomin, ['_wpis' => true] + $opcje);
        if (!empty($opcje['term_adres']) && empty($w['zostalo']) && empty($w['stop']) && empty($w['czekaj'])) $w['adres'] = evk_tl_ai_adres_z_nazwy($post_id, $lang);
        return $w;
    }
    $u = evk_tl_ai_na_przebieg(evk_tl_ai_ustawienia(), (string) ($opcje['dostawca'] ?? ''), (string) ($opcje['model'] ?? ''));
    $pola = $meta_key === EVK_TL_AI_POLA || $meta_key === EVK_TL_AI_POLA_TERMU;
    $obiekt = $meta_key === EVK_TL_AI_POLA_TERMU ? 'term' : 'post';
    $wpis = $meta_key === EVK_TL_AI_WPIS;
    $term = $meta_key === EVK_TL_AI_TERM;
    $seo = $meta_key === EVK_TL_AI_SEO;
    $alt = $meta_key === EVK_TL_AI_ALT;
    /* Grupa strony ustawień (1.272.0): bez wpisu, klucz grupy w opcjach kroku. */
    $gk = $meta_key === EVK_TL_AI_POLA_OPCJI ? (string) ($opcje['opcje'] ?? '') : '';
    /* Teksty stałe komponentu (1.272.0): id komponentu w opcjach kroku. */
    $kp = defined('EVK_TL_KP') && $meta_key === EVK_TL_KP ? evk_tl_kp_komponent((string) ($opcje['opcje'] ?? '')) : null;
    $t = $kp ? evk_tl_ai_teksty(evk_tl_kp_elementy_stale($kp), $lang, empty($opcje['ponownie']) ? null : evk_tl_kp_stan((string) $kp['id']))
        : ($gk !== '' ? evk_tl_ai_teksty_opcji($gk, $lang, !empty($opcje['ponownie']))
        : ($alt ? evk_tl_ai_teksty_alt($post_id, (int) ($opcje['do'] ?? $post_id), $lang, !empty($opcje['ponownie']))
        : ($pola ? evk_tl_ai_teksty_pol($post_id, $lang, !empty($opcje['ponownie']), $obiekt)
        : ($wpis ? evk_tl_ai_teksty_wpisu($post_id, $lang, (array) ($opcje['wpis_pola'] ?? []), !empty($opcje['ponownie']))
        : ($seo ? evk_tl_ai_teksty_seo($post_id, $lang, (array) ($opcje['seo_pola'] ?? []), !empty($opcje['ponownie']))
        : ($term ? evk_tl_ai_teksty_termu($post_id, $lang, (array) ($opcje['term_pola'] ?? []), !empty($opcje['ponownie']))
        : evk_tl_ai_teksty(get_post_meta($post_id, $meta_key, true), $lang, empty($opcje['ponownie']) ? null : evk_tl_ai_stan_czesci($post_id, $meta_key))))))));
    $braki = array_diff_key($t['braki'], array_flip($pomin));
    $wynik = ['zapisane' => 0, 'z_pamieci' => 0, 'z_ai' => 0, 'bez_zmian' => 0, 'odrzucone' => [], 'pominiete' => [], 'zapisane_klucze' => [], 'zostalo' => 0];
    if (!$braki) return $wynik;

    $gotowe = [];
    $ai = [];
    $rowne = [];
    foreach ($braki as $k => $b) {
        $z = evk_tl_ai_z_pamieci($b['pl'], $lang);
        $w = $z === null && empty($opcje['bez_pamieci']) ? evk_tl_ai_wynik($u, $lang, $b['pl']) : null;
        if ($z === null && $w === null) continue;
        $gotowe[$k] = $z ?? $w;
        if ($gotowe[$k] === $b['bylo']) { $rowne[$k] = true; continue; }
        if ($z === null) $ai[$k] = true;
        $wynik['z_pamieci']++;
    }

    /* To, co przyszło z pamięci, model widzi w kontekście jak każde inne
       tłumaczenie — reszta strony trzyma się tego samego słownictwa. */
    foreach ($gotowe as $k => $z) $t['kontekst'][$braki[$k]['n'] - 1]['tl'] = $z;

    $reszta = array_diff_key($braki, $gotowe);
    $porcja = [];
    $przerwa = null;
    $znaki = 0;
    foreach ($reszta as $k => $b) {
        if ($porcja && (count($porcja) >= EVK_TL_AI_PORCJA || $znaki + strlen($b['pl']) > EVK_TL_AI_ZNAKI)) break;
        $porcja[$k] = $b;
        $znaki += strlen($b['pl']);
    }
    if ($porcja) {
        $krotkie = [];
        $n = 0;
        foreach ($porcja as $k => $b) $krotkie['t' . (++$n)] = $k;
        $tresc = [];
        foreach ($krotkie as $kr => $k) $tresc[$kr] = $porcja[$k];
        $tytul = $kp ? 'Komponent: ' . evk_tl_kp_nazwa($kp) : ($gk !== '' ? evk_tl_ai_tytul_opcji($gk) : evk_tl_ai_tytul_czesci($post_id, $meta_key));
        [$system, $wiadomosc] = evk_tl_ai_tresc($u, $lang, $tytul, $t['kontekst'], $tresc);
        $r = evk_tl_ai_wyslij($u, $system, $wiadomosc);
        if (!$r['ok'] && (!empty($r['stop']) || !empty($r['czekaj']))) {
            /* Przejściowe (limit, przeciążenie) albo końcowe (klucz, limit
               dzienny): ta sama porcja wraca w następnym kroku. */
            $przerwa = ['blad' => (string) $r['blad'], 'czekaj' => (int) ($r['czekaj'] ?? 0), 'stop' => !empty($r['stop'])];
            $porcja = [];
        } else {
            /* Błąd porcji (odmowa modelu, odpowiedź poza schematem): ponowienie
               nic nie da — jej teksty idą do odrzuconych, reszta leci dalej. */
            if (!$r['ok']) {
                $wynik['blad'] = (string) $r['blad'];
                $r['tlumaczenia'] = [];
            }
            $pamiec = [];
            foreach ($krotkie as $kr => $k) {
                $tl = $r['tlumaczenia'][$kr] ?? null;
                if (!is_string($tl) || !evk_tl_ai_zgodne($porcja[$k]['pl'], $tl)) {
                    $wynik['odrzucone'][] = $k;
                    continue;
                }
                $gotowe[$k] = $tl;
                $pamiec[evk_tl_ai_klucz_wyniku($u, $lang, $porcja[$k]['pl'])] = $tl;
                if ($tl === $porcja[$k]['bylo']) { $rowne[$k] = true; continue; }
                $ai[$k] = true;
                $wynik['z_ai']++;
            }
            evk_tl_ai_zapamietaj($pamiec);
        }
    }
    /* Wynik równy obecnemu tekstowi nie ma czego zapisać. Zastępowane
       tłumaczenia AI idą do zapisu jako oczekiwany stan pola (i do `poprz`). */
    $gotowe = array_diff_key($gotowe, $rowne);
    $bylo = [];
    foreach (array_keys($gotowe) as $k) {
        if ($braki[$k]['bylo'] !== '') $bylo[$k] = $braki[$k]['bylo'];
    }
    $wynik['zapisane_klucze'] = $kp ? evk_tl_ai_zapisz_komponent((string) $kp['id'], $lang, $gotowe, $ai, evk_tl_ai_podpis($u), $bylo)
        : ($gk !== '' ? evk_tl_ai_zapisz_pola_opcji($gk, $lang, $gotowe, $ai, $braki) : ($alt ? evk_tl_ai_zapisz_alt($lang, $gotowe, $ai, $braki) : ($pola ? evk_tl_ai_zapisz_pola($post_id, $lang, $gotowe, $ai, $braki, $obiekt)
        : ($wpis ? evk_tl_ai_zapisz_wpis($post_id, $lang, $gotowe, $ai, $braki)
        : ($term ? evk_tl_ai_zapisz_term($post_id, $lang, $gotowe, $ai, $braki)
        : ($seo ? evk_tl_ai_zapisz_seo($post_id, $lang, $gotowe, $ai, $braki)
        : evk_tl_ai_zapisz($post_id, $meta_key, $lang, $gotowe, $ai, evk_tl_ai_podpis($u), $bylo)))))));
    $wynik['zapisane'] = count($wynik['zapisane_klucze']);
    $wynik['bez_zmian'] = count($rowne);
    $wynik['pominiete'] = array_keys($rowne);
    $wynik['zostalo'] = count($reszta) - count($porcja);
    return $przerwa ? $wynik + $przerwa : $wynik;
}

/**
 * Kolumny tabeli zakresu w „Przetłumacz strony” (1.271.0): część => nazwa,
 * osobno dla typów treści, taksonomii i obrazów.
 */
const EVK_TL_AI_KOLUMNY_WPIS = ['bricks' => 'Bricks', 'post_title' => 'Tytuł', 'post_content' => 'Treść', 'post_excerpt' => 'Zajawka',
    'adres' => 'Adres', 'seo' => 'SEO', 'fields' => 'Pola Fields'];
const EVK_TL_AI_KOLUMNY_TERM = ['name' => 'Nazwa', 'description' => 'Opis', 'adres' => 'Adres', 'fields' => 'Pola Fields'];
const EVK_TL_AI_KOLUMNY_OBRAZY = ['alt' => 'Tłumaczenie altu', 'alt_pl' => 'Brakujący alt PL'];
const EVK_TL_AI_KOLUMNY_OPCJE = ['fields' => 'Pola Fields'];

/** Typy treści z danymi Bricksa (bez wersji i kosza). @return list<string> */
function evk_tl_ai_typy_bricksa(): array {
    static $typy = null;
    if ($typy !== null) return $typy;
    if (!function_exists('evk_tl_el_klucze_meta')) return $typy = [];
    global $wpdb;
    $k = evk_tl_el_klucze_meta();
    $typy = array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.post_type FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key IN (%s, %s, %s) AND p.post_type <> 'revision' AND p.post_status NOT IN ('trash', 'auto-draft')", $k[0], $k[1], $k[2])));
    return $typy;
}

/**
 * Wiersze tabeli zakresu (1.271.0, decyzja zgłaszającego: „każdy element jako
 * pole wyboru, a w nim wybór części”): klucz typu => nazwa, rodzaj (`wpis`,
 * `term`, `obrazy`) i części, które ten typ naprawdę ma. Klucz taksonomii ma
 * przedrostek `tax:`, obrazy — `obrazy`, szablony Bricksa — `bricks_template`.
 *
 * @return array<string,array{nazwa:string,rodzaj:string,czesci:list<string>}>
 */
function evk_tl_ai_wiersze_zakresu(): array {
    $out = [];
    $bricks = evk_tl_ai_typy_bricksa();
    $wpisy = function_exists('evk_tlw_typy') ? evk_tlw_typy() : [];
    $fields = evk_tl_ai_pola_dostepne() ? evk_fields_tl_typy() : [];
    $typy = array_values(array_unique(array_merge(array_diff($bricks, ['bricks_template']), $wpisy, $fields)));
    foreach ($typy as $typ) {
        $obj = get_post_type_object($typ);
        if (!$obj) continue;
        $cz = [];
        if (in_array($typ, $bricks, true)) $cz[] = 'bricks';
        if (in_array($typ, $wpisy, true)) {
            $pola = evk_tlw_pola($typ);
            foreach (['post_title', 'post_content', 'post_excerpt'] as $p) if (isset($pola[$p])) $cz[] = $p;
            $cz[] = 'adres';
            if (evk_tl_ai_seo_dostepne()) $cz[] = 'seo';
        }
        if (in_array($typ, $fields, true)) $cz[] = 'fields';
        $out[$typ] = ['nazwa' => (string) $obj->labels->name, 'rodzaj' => 'wpis', 'czesci' => $cz];
    }
    if (in_array('bricks_template', $bricks, true)) $out['bricks_template'] = ['nazwa' => 'Szablony Bricksa', 'rodzaj' => 'wpis', 'czesci' => ['bricks']];
    /* Teksty stałe komponentów (1.272.0) — teksty z właściwości tłumaczy treść strony z instancją. */
    if (function_exists('evk_tl_kp_dostepne') && evk_tl_kp_dostepne()) $out['komponenty'] = ['nazwa' => 'Komponenty Bricksa', 'rodzaj' => 'wpis', 'czesci' => ['bricks']];
    $tax = evk_tl_ai_termy_dostepne() ? evk_tlt_taksonomie() : [];
    $tax_f = evk_tl_ai_pola_termow_dostepne() ? evk_fields_tl_taksonomie() : [];
    foreach (array_values(array_unique(array_merge($tax, $tax_f))) as $t) {
        $obj = get_taxonomy($t);
        if (!$obj) continue;
        $cz = in_array($t, $tax, true) ? ['name', 'description', 'adres'] : [];
        if (in_array($t, $tax_f, true)) $cz[] = 'fields';
        $out['tax:' . $t] = ['nazwa' => (string) $obj->labels->name, 'rodzaj' => 'term', 'czesci' => $cz];
    }
    /* Strony ustawień Fields (1.272.0): wiersz na stronę, do której jest prawo. */
    foreach (evk_tl_ai_strony_opcji() as $slug => $st) $out['opcje:' . $slug] = ['nazwa' => $st['nazwa'], 'rodzaj' => 'opcje', 'czesci' => ['fields']];
    if (evk_tl_ai_alt_dostepne()) $out['obrazy'] = ['nazwa' => 'Obrazy (biblioteka mediów)', 'rodzaj' => 'obrazy', 'czesci' => ['alt', 'alt_pl']];
    return $out;
}

/**
 * Zakres z żądania (`zakres[typ][]=część`) — tylko znane typy i części.
 *
 * @param mixed $raw
 * @return array<string,list<string>>
 */
function evk_tl_ai_zakres_z($raw): array {
    $out = [];
    $raw = is_array($raw) ? wp_unslash($raw) : [];
    foreach (evk_tl_ai_wiersze_zakresu() as $typ => $w) {
        $cz = array_values(array_intersect($w['czesci'], array_map('strval', (array) ($raw[$typ] ?? []))));
        if ($cz) $out[$typ] = $cz;
    }
    return $out;
}

/** Domyślny zakres: treść Bricksa każdego typu — tak tłumaczył hurt przed tabelą. @return array<string,list<string>> */
function evk_tl_ai_zakres_domyslny(): array {
    $out = [];
    foreach (evk_tl_ai_wiersze_zakresu() as $typ => $w) if (in_array('bricks', $w['czesci'], true)) $out[$typ] = ['bricks'];
    return $out;
}

/**
 * Opcje kroku z części zaznaczonych przy typie jednostki: teksty i adres
 * wpisu, nazwa, opis i adres termu, SEO, alt.
 *
 * @param list<string> $czesci
 * @return array<string,mixed>
 */
function evk_tl_ai_opcje_czesci(array $czesci): array {
    return ['wpis_pola' => array_values(array_intersect(EVK_TL_AI_POLA_WPISU, $czesci)), 'adres' => in_array('adres', $czesci, true),
        'term_pola' => array_values(array_intersect(EVK_TL_AI_POLA_TERMOW, $czesci)), 'term_adres' => in_array('adres', $czesci, true),
        'seo_pola' => in_array('seo', $czesci, true) ? ['seo_title', 'seo_desc', 'seo_keywords'] : []];
}

/**
 * Części stron z tekstami bez tłumaczenia: liczba braków w każdym języku.
 * W trybie ponownym (1.262.0) liczą się też niesprawdzone tłumaczenia AI —
 * `ai` mówi, ile ich jest wśród braków.
 *
 * Zakres (1.271.0): typ => części (tabela „typ × część”); null — treść
 * Bricksa każdego typu. Jednostka niesie `typ` i `grupa` (nazwa wiersza) —
 * lista w panelu grupuje po nich, a krok dostaje części swojego typu.
 *
 * @param array<string,list<string>>|null $zakres
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki(bool $ponownie = false, ?array $zakres = null): array {
    $jezyki = array_map('strval', evk_tl_kody_jezykow());
    $wiersze = evk_tl_ai_wiersze_zakresu();
    $zakres = $zakres ?? evk_tl_ai_zakres_domyslny();
    $out = [];
    $dodaj = static function (array $lista, string $typ) use (&$out, $wiersze): void {
        foreach ($lista as $j) $out[] = $j + ['typ' => $typ, 'grupa' => $wiersze[$typ]['nazwa'] ?? $typ];
    };
    $bricks = [];
    foreach (evk_tl_el_wpisy_bricksa() as [$post_id, $meta_key]) {
        $typ = (string) get_post_type($post_id);
        if (!in_array('bricks', $zakres[$typ] ?? [], true)) continue;
        $dane = get_post_meta($post_id, $meta_key, true);
        if (!is_array($dane)) continue;
        $stan = $ponownie ? evk_tl_ai_stan_czesci($post_id, $meta_key) : null;
        $braki = [];
        $ai = [];
        foreach ($jezyki as $j) {
            $b = evk_tl_ai_teksty($dane, $j, $stan)['braki'];
            if ($b) $braki[$j] = count($b);
            $n = count(array_filter($b, static function ($x) { return $x['bylo'] !== ''; }));
            if ($n) $ai[$j] = $n;
        }
        if (!$braki) continue;
        $bricks[$typ][] = ['post_id' => $post_id, 'meta_key' => $meta_key, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => evk_tl_el_czesc($meta_key), 'adres' => evk_tl_el_adres_edycji($post_id), 'braki' => $braki, 'ai' => (object) $ai];
    }
    /* Kolejność wierszy tabeli: w obrębie typu — Bricks, teksty wpisu, SEO, pola Fields. */
    foreach ($wiersze as $typ => $w) {
        $cz = $zakres[$typ] ?? [];
        if (!$cz) continue;
        $o = evk_tl_ai_opcje_czesci($cz);
        if ($w['rodzaj'] === 'wpis') {
            $dodaj($bricks[$typ] ?? [], $typ);
            if ($typ === 'komponenty' && in_array('bricks', $cz, true)) $dodaj(evk_tl_ai_jednostki_komponentow($jezyki, $ponownie), $typ);
            if ($o['wpis_pola'] || $o['adres']) $dodaj(evk_tl_ai_jednostki_wpisow($jezyki, $o['wpis_pola'], $o['adres'], $ponownie, [$typ]), $typ);
            if ($o['seo_pola'] && function_exists('evk_tl_ai_jednostki_seo')) $dodaj(evk_tl_ai_jednostki_seo($jezyki, $ponownie, [$typ]), $typ);
            if (in_array('fields', $cz, true)) $dodaj(evk_tl_ai_jednostki_pol($jezyki, $ponownie, [$typ]), $typ);
        } elseif ($w['rodzaj'] === 'term') {
            $tax = substr($typ, 4);
            if ($o['term_pola'] || $o['term_adres']) $dodaj(evk_tl_ai_jednostki_termow($jezyki, $o['term_pola'], $o['term_adres'], $ponownie, [$tax]), $typ);
            if (in_array('fields', $cz, true)) $dodaj(evk_tl_ai_jednostki_pol_termow($jezyki, $ponownie, [$tax]), $typ);
        } elseif ($w['rodzaj'] === 'opcje') {
            $dodaj(evk_tl_ai_jednostki_pol_opcji($jezyki, $ponownie, substr($typ, 6)), $typ);
        } elseif (function_exists('evk_tl_ai_jednostki_obrazow')) {
            $dodaj(evk_tl_ai_jednostki_obrazow($jezyki, $cz, $ponownie), $typ);
        }
    }
    return $out;
}

/**
 * Jeden tekst od nowa, bez zapisu — „Przetłumacz ponownie” w okienku
 * sprawdzania na stronie (62, 1.262.0). Kontekst jak w hurcie, bez obecnego
 * tłumaczenia tego tekstu. Pamięć wyników też jak w hurcie: ten sam model
 * przy tych samych ustawieniach daje ten sam tekst bez zapytania. Pamięci
 * tłumaczeń tu nie ma — ktoś prosi o wynik konkretnego modelu.
 *
 * @return array{ok:bool,tekst?:string,model?:string,z_pamieci?:bool,blad?:string}
 */
function evk_tl_ai_jeden(int $post_id, string $meta_key, string $lang, string $klucz, array $u): array {
    $t = evk_tl_ai_teksty(get_post_meta($post_id, $meta_key, true), $lang, null, [$klucz => true]);
    $b = $t['braki'][$klucz] ?? null;
    if (!$b) return ['ok' => false, 'blad' => 'Tego tekstu AI nie tłumaczy (sam tag danych dynamicznych albo bez liter).'];
    $model = evk_tl_ai_podpis($u);
    $w = evk_tl_ai_wynik($u, $lang, $b['pl']);
    if ($w !== null) return ['ok' => true, 'tekst' => $w, 'model' => $model, 'z_pamieci' => true];
    [$system, $wiadomosc] = evk_tl_ai_tresc($u, $lang, (string) (get_the_title($post_id) ?: ('#' . $post_id)), $t['kontekst'], ['t1' => $b]);
    $r = evk_tl_ai_wyslij($u, $system, $wiadomosc);
    if (!$r['ok']) {
        return ['ok' => false, 'blad' => !empty($r['czekaj']) ? 'Dostawca prosi o przerwę — spróbuj za ' . (int) $r['czekaj'] . ' s.' : (string) $r['blad']];
    }
    $tl = $r['tlumaczenia']['t1'] ?? null;
    if (!is_string($tl) || !evk_tl_ai_zgodne($b['pl'], $tl)) {
        return ['ok' => false, 'blad' => 'Tłumaczenie odrzucone: znaczniki HTML, tagi {…} albo shortcody nie zgadzają się z oryginałem.'];
    }
    evk_tl_ai_zapamietaj([evk_tl_ai_klucz_wyniku($u, $lang, $b['pl']) => $tl]);
    return ['ok' => true, 'tekst' => $tl, 'model' => $model, 'z_pamieci' => false];
}

// =========================================================================
// CZYSZCZENIE STRONY (1.264.0)
// =========================================================================

/**
 * Kopia wyczyszczonych tłumaczeń jednej części wpisu: klucz miejsca →
 * tekst (`t`) i wpis stanu 52 sprzed czyszczenia (`s`, może go nie być).
 *
 * @return array<string,array{t:string,s:array<string,mixed>|null}>
 */
function evk_tl_ai_kopia_czesci(int $post_id, string $meta_key): array {
    $k = get_post_meta($post_id, EVK_TL_AI_WYCZYSZCZONE, true);
    $m = is_array($k) && is_array($k[$meta_key]['miejsca'] ?? null) ? $k[$meta_key]['miejsca'] : [];
    $out = [];
    foreach ($m as $klucz => $w) {
        if (!is_array($w) || !is_string($w['t'] ?? null)) continue;
        $out[(string) $klucz] = ['t' => $w['t'], 's' => is_array($w['s'] ?? null) ? $w['s'] : null];
    }
    return $out;
}

/**
 * Pole, które hurt AI tłumaczy: w mapie pól elementu (51), a tekst ma litery
 * i nie jest samym tagiem danych dynamicznych. Czyszczenie rusza tylko takie
 * — inne pole języka (np. wpisane ręcznie przy elemencie spoza mapy) nie
 * wróciłoby z hurtu.
 *
 * @param array<string,mixed> $mapa
 * @param array<string,string> $m Miejsce z evk_tl_el_miejsca().
 */
function evk_tl_ai_do_hurtu(array $mapa, array $m): bool {
    $def = $mapa[$m['element']] ?? null;
    if (!is_array($def) || !evk_tl_ai_do_tlumaczenia($m['oryginal'])) return false;
    $cz = explode('.', $m['pole']);
    if (count($cz) === 1) return in_array($cz[0], array_map('strval', (array) ($def['pola'] ?? [])), true);
    return count($cz) === 3 && in_array($cz[2], array_map('strval', (array) ($def['listy'][$cz[0]] ?? [])), true);
}

/**
 * Miejsca do wyczyszczenia: niepuste tłumaczenia w wybranych językach —
 * zakres `ai` tylko niesprawdzone tłumaczenia AI, `wszystkie` każde.
 *
 * @param list<string> $jezyki
 * @return array<string,array{lang:string,t:string,pl:string,s:array<string,mixed>|null}>
 */
function evk_tl_ai_do_wyczyszczenia(int $post_id, string $meta_key, array $jezyki, string $zakres): array {
    $kody = [];
    foreach ($jezyki as $lang) $kody[evk_tl_ai_kod((string) $lang)] = (string) $lang;
    $stan = evk_tl_ai_stan_czesci($post_id, $meta_key);
    $mapa = evk_tl_el_mapa();
    $out = [];
    foreach (evk_tl_el_miejsca(get_post_meta($post_id, $meta_key, true)) as $klucz => $m) {
        if (!isset($kody[$m['jezyk']]) || !evk_tl_el_niepuste($m['tlumaczenie']) || !evk_tl_ai_do_hurtu($mapa, $m)) continue;
        $s = is_array($stan[$klucz] ?? null) ? $stan[$klucz] : null;
        if ($zakres !== 'wszystkie' && !evk_tl_ai_niesprawdzone($s)) continue;
        $out[(string) $klucz] = ['lang' => $kody[$m['jezyk']], 't' => $m['tlumaczenie'], 'pl' => $m['oryginal'], 's' => $s];
    }
    return $out;
}

/**
 * Czyści tłumaczenia jednej części strony i zapomina wyniki AI dla jej
 * tekstów (wszystkich modeli). Kopia (tekst i wpis stanu) idzie PRZED
 * zapisem pól: przerwany zapis zostawia kopię, a nie zgubione tłumaczenia.
 * Kopia łączy się z poprzednią — to samo miejsce dostaje nową wartość,
 * inne (np. drugi język wyczyszczony wcześniej) zostają.
 *
 * @param list<string> $jezyki
 * @return array{wyczyszczone:array<string,int>,razem:int,zapomniane:int}
 */
function evk_tl_ai_czysc(int $post_id, string $meta_key, array $jezyki, string $zakres): array {
    $do = evk_tl_ai_do_wyczyszczenia($post_id, $meta_key, $jezyki, $zakres);
    $wynik = ['wyczyszczone' => [], 'razem' => 0, 'zapomniane' => 0];
    if (!$do) return $wynik;
    $kopia = get_post_meta($post_id, EVK_TL_AI_WYCZYSZCZONE, true);
    $kopia = is_array($kopia) ? $kopia : [];
    $miejsca = evk_tl_ai_kopia_czesci($post_id, $meta_key);
    foreach ($do as $klucz => $m) $miejsca[$klucz] = ['t' => $m['t'], 's' => $m['s']];
    $kopia[$meta_key] = ['czas' => time(), 'miejsca' => $miejsca];
    // wp_slash: update_post_meta zdejmuje ukośniki, a to są teksty.
    update_post_meta($post_id, EVK_TL_AI_WYCZYSZCZONE, wp_slash($kopia));

    $zmiany = [];
    foreach ($do as $klucz => $m) $zmiany[$m['lang']][$klucz] = '';
    $teksty = [];
    // Po kolei jak języki w ustawieniach — liczby w panelu w tej samej kolejności.
    foreach ($jezyki as $lang) {
        if (!isset($zmiany[$lang])) continue;
        $zapisane = evk_tl_el_zapisz_pola($post_id, $meta_key, (string) $lang, $zmiany[$lang], false);
        if ($zapisane) $wynik['wyczyszczone'][evk_tl_ai_kod((string) $lang)] = count($zapisane);
        foreach ($zapisane as $klucz) $teksty[] = [(string) $lang, $do[$klucz]['pl']];
    }
    $wynik['razem'] = array_sum($wynik['wyczyszczone']);
    $wynik['zapomniane'] = evk_tl_ai_zapomnij($teksty);
    return $wynik;
}

/**
 * „Przywróć wyczyszczone”: tłumaczenia z kopii wracają do PUSTYCH pól
 * (pole wypełnione od czasu czyszczenia zostaje) razem ze swoim wpisem
 * stanu — tłumaczenie AI znów „Do sprawdzenia” ze swoim modelem, sprawdzone
 * sprawdzone. Kopia zostaje do następnego czyszczenia tej strony.
 *
 * @return array{przywrocone:int,pominiete:int}
 */
function evk_tl_ai_przywroc(int $post_id, string $meta_key): array {
    $kopia = evk_tl_ai_kopia_czesci($post_id, $meta_key);
    $wynik = ['przywrocone' => 0, 'pominiete' => 0];
    if (!$kopia) return $wynik;
    $jezyki = [];
    foreach (array_keys(tl_get_languages()) as $lang) $jezyki[evk_tl_ai_kod((string) $lang)] = (string) $lang;
    $zmiany = [];
    foreach ($kopia as $klucz => $w) {
        $kod = substr((string) strrchr($klucz, '|'), 1);
        if (isset($jezyki[$kod])) $zmiany[$jezyki[$kod]][$klucz] = $w['t'];
    }
    $zapisane = [];
    foreach ($zmiany as $lang => $z) {
        foreach (evk_tl_el_zapisz_pola($post_id, $meta_key, (string) $lang, $z, true) as $klucz) $zapisane[$klucz] = true;
    }
    $wynik['przywrocone'] = count($zapisane);
    $wynik['pominiete'] = count($kopia) - count($zapisane);
    if (!$zapisane) return $wynik;
    /* Zapis policzył świeży stan (52): skrót bieżącego oryginału, czyli
       „sprawdzone”. Wpis sprzed czyszczenia ma ten sam skrót tłumaczenia,
       więc wraca w całości (źródło `ai`, model, poprzednia wersja). */
    $stan = get_post_meta($post_id, EVK_TL_EL_STAN, true);
    if (is_array($stan)) {
        foreach (array_keys($zapisane) as $klucz) {
            $s = $kopia[$klucz]['s'];
            if (is_array($s) && isset($stan[$meta_key][$klucz]) && ($s['tl'] ?? '') === ($stan[$meta_key][$klucz]['tl'] ?? null)) {
                $stan[$meta_key][$klucz] = $s;
            }
        }
        update_post_meta($post_id, EVK_TL_EL_STAN, wp_slash($stan));
    }
    return $wynik;
}

/**
 * Podgląd dla panelu: ile tłumaczeń zniknie (według języka) i ile czeka
 * w kopii na „Przywróć wyczyszczone” (pola dziś puste).
 *
 * @param list<string> $jezyki
 * @return array{ile:object,razem:int,kopia:int,czas:int}
 */
function evk_tl_ai_podglad_czyszczenia(int $post_id, string $meta_key, array $jezyki, string $zakres): array {
    // Po kolei jak języki w ustawieniach (klucze w danych Bricksa bywają w innej).
    $ile = array_fill_keys(array_map('evk_tl_ai_kod', array_map('strval', $jezyki)), 0);
    foreach (evk_tl_ai_do_wyczyszczenia($post_id, $meta_key, $jezyki, $zakres) as $m) $ile[evk_tl_ai_kod($m['lang'])]++;
    $ile = array_filter($ile);
    $pelne = [];
    foreach (evk_tl_el_miejsca(get_post_meta($post_id, $meta_key, true)) as $klucz => $m) {
        if (evk_tl_el_niepuste($m['tlumaczenie'])) $pelne[$klucz] = true;
    }
    $kopia = get_post_meta($post_id, EVK_TL_AI_WYCZYSZCZONE, true);
    return ['ile' => (object) $ile, 'razem' => array_sum($ile),
        'kopia' => count(array_diff_key(evk_tl_ai_kopia_czesci($post_id, $meta_key), $pelne)),
        'czas' => is_array($kopia) ? (int) ($kopia[$meta_key]['czas'] ?? 0) : 0];
}

/**
 * Części stron do wyboru w „Wyczyść tłumaczenia strony”: każda z danymi
 * Bricksa, którą użytkownik może edytować — także w pełni przetłumaczona
 * (lista hurtu pokazuje tylko strony z brakami).
 *
 * @return list<array{post_id:int,meta_key:string,tytul:string,czesc:string}>
 */
function evk_tl_ai_strony_do_czyszczenia(): array {
    $wpisy = evk_tl_el_wpisy_bricksa();
    if (function_exists('_prime_post_caches')) {
        _prime_post_caches(array_values(array_unique(array_map(static function (array $w): int { return $w[0]; }, $wpisy))), false, false);
    }
    $out = [];
    foreach ($wpisy as [$post_id, $meta_key]) {
        if (!current_user_can('edit_post', $post_id)) continue;
        $out[] = ['post_id' => $post_id, 'meta_key' => $meta_key, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => evk_tl_el_czesc($meta_key)];
    }
    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['tytul'], $b['tytul']) ?: ($a['post_id'] <=> $b['post_id'] ?: strcmp($a['meta_key'], $b['meta_key']));
    });
    return $out;
}

// =========================================================================
// BUILDER (1.265.0)
// =========================================================================

/** Kontekst z buildera: najwięcej tekstów i bajtów (cała część strony, jak w hurcie). */
const EVK_TL_AI_BUILDER_TEKSTY = 2000;
const EVK_TL_AI_BUILDER_ZNAKI  = 400000;

/**
 * Dane przycisków AI w builderze (dane kanwy, 58): tylko dla kogoś z dostępem
 * do Tłumaczeń i tylko z kluczem API dostawcy z ustawień — inaczej przycisków
 * nie ma wcale. Klucz nie wychodzi do przeglądarki. Porcja i limit znaków —
 * żeby skrypt dzielił teksty tak jak serwer je przyjmuje.
 *
 * @return array{ajax:string,nonce:string,post:int,model:string,porcja:int,znaki:int}|null
 */
function evk_tl_ai_builder_dane(): ?array {
    if (!current_user_can('manage_options') && !current_user_can('evk_access_translations')) return null;
    $u = evk_tl_ai_ustawienia();
    if (evk_tl_ai_klucz($u) === '') return null;
    return ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('evk_tl_ai_builder'),
        'post' => (int) get_queried_object_id(), 'model' => evk_tl_ai_podpis($u),
        'porcja' => EVK_TL_AI_PORCJA, 'znaki' => EVK_TL_AI_ZNAKI];
}

/**
 * Wejście tłumaczenia z buildera: kontekst i teksty z JSON-a, w granicach.
 * Kontekst: lista {el, pole, poz, pl, tl} — wszystkie teksty części strony
 * w kolejności stanu buildera, `poz` — numer pozycji listy (0 poza listą).
 * Teksty: klucz przeglądarki → {n, bylo} — numer w kontekście (od 1)
 * i obecne tłumaczenie pola (puste przy pustym polu). Opis pola jak w hurcie:
 * „Tytuł”, „pozycja 2 · Tytuł”.
 *
 * @param mixed $kontekst
 * @param mixed $teksty
 * @return array{0:list<array{element:string,opis:string,pl:string,tl:string}>,1:array<string,array{n:int,bylo:string}>}|string Błąd jako napis.
 */
function evk_tl_ai_builder_wejscie($kontekst, $teksty) {
    if (!is_array($kontekst) || !is_array($teksty) || !$teksty) return 'Brak tekstów do tłumaczenia.';
    if (count($kontekst) > EVK_TL_AI_BUILDER_TEKSTY) return 'Za dużo tekstów w tej części strony — przetłumacz ją w panelu Tłumaczeń.';
    $k = [];
    $bajty = 0;
    foreach (array_values($kontekst) as $w) {
        if (!is_array($w) || !is_string($w['pl'] ?? null)) return 'Zły kontekst.';
        $tl = is_string($w['tl'] ?? null) ? $w['tl'] : '';
        $pole = (string) preg_replace('/[^A-Za-z0-9_-]/', '', is_string($w['pole'] ?? null) ? $w['pole'] : '');
        $poz = is_numeric($w['poz'] ?? null) ? max(0, (int) $w['poz']) : 0;
        $bajty += strlen($w['pl']) + strlen($tl);
        /* Pola Evoke FIELDS (1.267.0) mają gotowy opis — etykietę pola z grupy. */
        $opis = is_string($w['opis'] ?? null) && trim($w['opis']) !== '' ? mb_substr(sanitize_text_field($w['opis']), 0, 120)
            : ($poz ? 'pozycja ' . $poz . ' · ' : '') . evk_tl_el_nazwa_pola($pole);
        $k[] = ['element' => (string) preg_replace('/[^A-Za-z0-9_-]/', '', is_string($w['el'] ?? null) ? $w['el'] : ''),
            'opis' => $opis, 'pl' => $w['pl'], 'tl' => $tl];
    }
    if ($bajty > EVK_TL_AI_BUILDER_ZNAKI) return 'Za dużo tekstu w tej części strony — przetłumacz ją w panelu Tłumaczeń.';
    if (count($teksty) > EVK_TL_AI_PORCJA) return 'Za dużo tekstów naraz (najwięcej ' . EVK_TL_AI_PORCJA . ').';
    $t = [];
    $bajty = 0;
    foreach ($teksty as $klucz => $w) {
        $n = is_array($w) && is_numeric($w['n'] ?? null) ? (int) $w['n'] : 0;
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', (string) $klucz) || $n < 1 || $n > count($k)) return 'Zły tekst do tłumaczenia.';
        $t[(string) $klucz] = ['n' => $n, 'bylo' => is_string($w['bylo'] ?? null) ? $w['bylo'] : ''];
        $bajty += strlen($k[$n - 1]['pl']);
    }
    /* Jak porcja hurtu: jeden długi tekst przechodzi, kilka — w limicie. */
    if (count($t) > 1 && $bajty > EVK_TL_AI_ZNAKI) return 'Za długie teksty naraz (najwięcej ' . EVK_TL_AI_ZNAKI . ' znaków).';
    return [$k, $t];
}

/**
 * Tłumaczenie tekstów z buildera (1.265.0), BEZ zapisu — wynik wpisuje do
 * stanu buildera skrypt kanwy, a zapisuje Bricks. Teksty i kontekst idą ze
 * stanu, więc liczą się też niezapisane zmiany.
 *
 * Kolejno jak w kroku hurtu: pamięć tłumaczeń, pamięć wyników, jedna porcja
 * do AI i strażnik. Pole wypełnione (przycisk przy wypełnionym polu, po
 * potwierdzeniu) ma dostać coś innego niż obecny tekst: pamięć równa
 * obecnemu tekstowi nie wystarcza — pytamy AI, a nowy wynik zastępuje
 * zapamiętany. Wynik AI równy obecnemu tekstowi to `bez_zmian`.
 *
 * Po zapisie w Bricksie stan „Do sprawdzenia” (52) liczy się jak przy każdej
 * zmianie tłumaczenia, czyli bez znacznika AI: tłumacz widział tekst
 * w builderze przed zapisem (jak przy przyciskach przy polach, plan 29.09).
 *
 * @param list<array{element:string,opis:string,pl:string,tl:string}> $kontekst
 * @param array<string,array{n:int,bylo:string}>                       $teksty
 * @return array<string,mixed>
 */
function evk_tl_ai_builder(int $post_id, string $lang, array $kontekst, array $teksty, string $tytul = ''): array {
    $u = evk_tl_ai_ustawienia();
    $wynik = ['tlumaczenia' => [], 'zrodla' => [], 'bez_zmian' => [], 'odrzucone' => [], 'pominiete' => [], 'model' => evk_tl_ai_podpis($u)];
    $braki = [];
    foreach ($teksty as $klucz => $t) {
        $k = $kontekst[$t['n'] - 1];
        if (!evk_tl_ai_do_tlumaczenia($k['pl'])) {
            $wynik['pominiete'][] = $klucz;
            continue;
        }
        $braki[$klucz] = ['pl' => $k['pl'], 'element' => $k['element'], 'opis' => $k['opis'], 'n' => $t['n'], 'bylo' => $t['bylo']];
    }
    foreach ($braki as $klucz => $b) {
        $z = evk_tl_ai_z_pamieci($b['pl'], $lang);
        if ($z === $b['bylo']) $z = null;
        $w = $z === null ? evk_tl_ai_wynik($u, $lang, $b['pl']) : null;
        if ($w === $b['bylo']) $w = null;
        if ($z === null && $w === null) continue;
        $wynik['tlumaczenia'][$klucz] = $z ?? $w;
        $wynik['zrodla'][$klucz] = $z !== null ? 'pamiec' : 'wynik';
        /* Model widzi to w kontekście jak każde inne tłumaczenie. */
        $kontekst[$b['n'] - 1]['tl'] = $z ?? $w;
    }
    $reszta = array_diff_key($braki, $wynik['tlumaczenia']);
    if (!$reszta) return $wynik;

    $krotkie = [];
    $porcja = [];
    foreach ($reszta as $klucz => $b) {
        $kr = 't' . (count($krotkie) + 1);
        $krotkie[$kr] = $klucz;
        $porcja[$kr] = $b;
    }
    /* Tytuł strony w zapytaniu — albo nazwa strony ustawień Fields (bez wpisu). */
    if ($tytul === '') $tytul = (string) (get_the_title($post_id) ?: ('#' . $post_id));
    [$system, $wiadomosc] = evk_tl_ai_tresc($u, $lang, $tytul, $kontekst, $porcja);
    $r = evk_tl_ai_wyslij($u, $system, $wiadomosc);
    if (!$r['ok']) {
        $wynik['blad'] = (string) $r['blad'];
        $wynik['czekaj'] = (int) ($r['czekaj'] ?? 0);
        $wynik['stop'] = !empty($r['stop']);
        /* Błąd porcji (odmowa modelu, odpowiedź poza schematem): ponowienie nic
           nie da — jej teksty jako odrzucone. Limit i przeciążenie — do ponowienia. */
        if (!$wynik['stop'] && !$wynik['czekaj']) $wynik['odrzucone'] = array_values($krotkie);
        return $wynik;
    }
    $pamiec = [];
    foreach ($krotkie as $kr => $klucz) {
        $tl = $r['tlumaczenia'][$kr] ?? null;
        if (!is_string($tl) || !evk_tl_ai_zgodne($reszta[$klucz]['pl'], $tl)) {
            $wynik['odrzucone'][] = $klucz;
            continue;
        }
        $pamiec[evk_tl_ai_klucz_wyniku($u, $lang, $reszta[$klucz]['pl'])] = $tl;
        if ($tl === $reszta[$klucz]['bylo']) {
            $wynik['bez_zmian'][] = $klucz;
            continue;
        }
        $wynik['tlumaczenia'][$klucz] = $tl;
        $wynik['zrodla'][$klucz] = 'ai';
    }
    evk_tl_ai_zapamietaj($pamiec);
    return $wynik;
}

// =========================================================================
// TEKSTY WPISÓW: TYTUŁ, TREŚĆ, ZAJAWKA, ADRES (1.268.0)
// =========================================================================

/*
 * Tytuł, treść (edytor WordPressa) i zajawka wpisu — teksty modułu wpisów
 * (55: meta `_evk_tl_{język}__{pole}` i źródło). W hurcie osobna część wpisu
 * (`evk_wpis`), tylko po zaznaczeniu pól wyboru — każde pole osobno, wszystkie
 * domyślnie odznaczone (decyzja zgłaszającego z 30.09). Treść strony rysowanej
 * przez Bricksa pomijana: jej teksty są w elementach. Zapis ze znacznikiem
 * „AI — do sprawdzenia” (źródło `ai-{skrót}`, jak w Evoke FIELDS 1.75.0).
 *
 * „Adres z tytułu”: po tekstach — człon adresu z tłumaczenia tytułu do mapy
 * adresów (Slugi URL), tylko gdy tego polskiego członu mapa jeszcze nie
 * tłumaczy; konflikt (ten sam człon innej strony) — bez zapisu, z powodem.
 */
const EVK_TL_AI_WPIS = 'evk_wpis';
const EVK_TL_AI_POLA_WPISU = ['post_title', 'post_content', 'post_excerpt'];

/**
 * Teksty wpisu w kształcie evk_tl_ai_teksty(): kontekst (tytuł, treść, zajawka,
 * potem pola Fields i treść Bricksa strony) i braki — tylko pól z `$pola`.
 * Klucz braku: `{pole}|{język}`.
 *
 * @param list<string> $pola
 * @return array{kontekst:list<array{element:string,opis:string,pl:string,tl:string}>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty_wpisu(int $post_id, string $lang, array $pola, bool $ponownie = false): array {
    $out = ['kontekst' => [], 'braki' => []];
    $post = get_post($post_id);
    if (!$post || !function_exists('evk_tlw_pola')) return $out;
    $dostepne = evk_tlw_pola($post->post_type);
    foreach (EVK_TL_AI_POLA_WPISU as $pole) {
        if (!isset($dostepne[$pole]) || ($pole === 'post_content' && evk_tlw_z_bricksa($post_id))) continue;
        $pl = (string) $post->{$pole};
        if (!evk_tl_ai_do_tlumaczenia($pl)) continue;
        $tl = evk_tlw_meta($post_id, $lang, $pole);
        $jest = !evk_tlw_pusty($tl);
        $ponow = $ponownie && $jest && evk_tlw_ai((string) get_post_meta($post_id, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo', true));
        $out['kontekst'][] = ['element' => 'Wpis', 'opis' => $dostepne[$pole], 'pl' => $pl, 'tl' => $jest && !$ponow ? $tl : ''];
        if (!in_array($pole, $pola, true) || ($jest && !$ponow)) continue;
        $out['braki'][$pole . '|' . $lang] = ['pl' => $pl, 'element' => 'Wpis', 'opis' => $dostepne[$pole], 'id' => '', 'sciezka' => $pole,
            'pole' => $pole, 'n' => count($out['kontekst']), 'bylo' => $ponow ? $tl : ''];
    }
    if ($out['braki']) {
        /* Słownictwo reszty strony: pola Fields i teksty treści Bricksa. */
        if (evk_tl_ai_pola_dostepne()) {
            foreach (evk_fields_tl_teksty($post_id) as $m) {
                if (!evk_tl_ai_do_tlumaczenia((string) $m['pl'])) continue;
                $out['kontekst'][] = ['element' => 'Evoke FIELDS · ' . (string) ($m['grupa'] ?? ''), 'opis' => (string) ($m['opis'] ?? ''),
                    'pl' => (string) $m['pl'], 'tl' => (string) ($m['tl'][$lang] ?? '')];
            }
        }
        $out['kontekst'] = array_merge($out['kontekst'], evk_tl_ai_teksty(get_post_meta($post_id, evk_tl_el_klucze_meta()[0], true), $lang)['kontekst']);
    }
    return $out;
}

/**
 * Zapis kroku hurtu dla tekstów wpisu — jak formularz modułu wpisów:
 * sanityzacja rdzenia dla pola, tytuł bez odstępów na brzegach. Z AI i pamięci
 * wyników — źródło ze znacznikiem, z pamięci tłumaczeń — bez.
 *
 * @param array<string,string>              $gotowe
 * @param array<string,bool>                $ai
 * @param array<string,array<string,mixed>> $braki
 * @return list<string>
 */
function evk_tl_ai_zapisz_wpis(int $post_id, string $lang, array $gotowe, array $ai, array $braki): array {
    $out = [];
    foreach ($gotowe as $k => $tl) {
        $pole = (string) ($braki[$k]['sciezka'] ?? '');
        if (!in_array($pole, EVK_TL_AI_POLA_WPISU, true)) continue;
        $tekst = (string) wp_unslash(sanitize_post_field($pole, wp_slash((string) $tl), $post_id, 'db'));
        if ($pole === 'post_title') $tekst = trim($tekst);
        if (evk_tlw_pusty($tekst)) continue;
        update_post_meta($post_id, '_evk_tl_' . $lang . '__' . $pole, wp_slash($tekst));
        update_post_meta($post_id, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo',
            (!empty($ai[$k]) ? 'ai-' : '') . evk_tlw_zrodlo((string) get_post_field($pole, $post_id, 'raw')));
        $out[] = (string) $k;
    }
    return $out;
}

/**
 * Adres z tytułu: człon z tłumaczenia tytułu do mapy adresów.
 *
 * @return array{stan:string,czlon:string,powod:string} stan: zapisany | jest (mapa już tłumaczy) |
 *         bez_tytulu | bez_adresu (wpis bez polskiego członu) | ten_sam | konflikt
 */
function evk_tl_ai_adres_z_tytulu(int $post_id, string $lang): array {
    $w = ['stan' => '', 'czlon' => '', 'powod' => ''];
    $pl = (string) get_post_field('post_name', $post_id, 'raw');
    if ($pl === '') return ['stan' => 'bez_adresu'] + $w;
    if (evk_tlw_slug($pl, $lang) !== '') return ['stan' => 'jest', 'czlon' => evk_tlw_slug($pl, $lang)] + $w;
    $tytul = evk_tlw_meta($post_id, $lang, 'post_title');
    if (evk_tlw_pusty($tytul)) return ['stan' => 'bez_tytulu'] + $w;
    $czlon = sanitize_title($tytul);
    if ($czlon === '' || $czlon === $pl) return ['stan' => 'ten_sam', 'czlon' => $czlon] + $w;
    $powod = evk_tlw_slug_konflikt($pl, $lang, $czlon, $post_id);
    if ($powod !== '') return ['stan' => 'konflikt', 'czlon' => $czlon, 'powod' => $powod];
    evk_tlw_zapisz_slug($pl, $lang, $czlon);
    return ['stan' => 'zapisany', 'czlon' => $czlon, 'powod' => ''];
}

/**
 * Wpisy z brakami tekstów (lista hurtu): część `evk_wpis`. Sam „Adres” liczy
 * wpisy z tłumaczeniem tytułu, a bez członu w mapie adresów (1 na język).
 *
 * @param list<string> $jezyki
 * @param list<string> $pola
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_wpisow(array $jezyki, array $pola, bool $adres, bool $ponownie, array $tylko = []): array {
    if (!function_exists('evk_tlw_typy') || !($typy = evk_tlw_typy())) return [];
    if ($tylko) $typy = array_values(array_intersect($typy, $tylko));
    if (!$typy) return [];
    $ids = array_map('intval', get_posts(['post_type' => $typy, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts' => 1000, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'suppress_filters' => true]));
    if ($ids) update_meta_cache('post', $ids);
    $out = [];
    foreach ($ids as $post_id) {
        $braki = [];
        $ai = [];
        $pl = (string) get_post_field('post_name', $post_id, 'raw');
        foreach ($jezyki as $j) {
            $b = $pola ? evk_tl_ai_teksty_wpisu($post_id, $j, $pola, $ponownie)['braki'] : [];
            $n = count($b);
            /* Adres bez tekstów do AI: tytuł już przetłumaczony, członu w mapie brak. */
            if (!$n && $adres && $pl !== '' && evk_tlw_slug($pl, $j) === '' && !evk_tlw_pusty(evk_tlw_meta($post_id, $j, 'post_title'))) $n = 1;
            if ($n) $braki[$j] = $n;
            $x = count(array_filter($b, static function ($y) { return $y['bylo'] !== ''; }));
            if ($x) $ai[$j] = $x;
        }
        if (!$braki) continue;
        $czesc = $pola ? 'Teksty wpisu' : 'Adres wpisu';
        $out[] = ['post_id' => $post_id, 'meta_key' => EVK_TL_AI_WPIS, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => $czesc, 'adres' => (string) get_edit_post_link($post_id, 'raw'), 'braki' => $braki, 'ai' => (object) $ai];
    }
    return $out;
}

/**
 * Lista „Do sprawdzenia” (52): tytuły, treści i zajawki wpisów z tłumaczeniem
 * AI albo po zmianie polskiego tekstu — część `evk_wpis`, klucz `{pole}|{język}`.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_wpisy_do_sprawdzenia(int $limit = 200): array {
    global $wpdb;
    if (!function_exists('evk_tlw_nieaktualne')) return [];
    $wiersze = (array) $wpdb->get_results($wpdb->prepare(
        /* Najnowsze wpisy najpierw — limit nie ucina świeżo tłumaczonych (1.269.0). */
        "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_key LIKE %s ORDER BY post_id DESC LIMIT %d",
        $wpdb->esc_like('_evk_tl_') . '%', '%' . $wpdb->esc_like('__zrodlo'), $limit * 4));
    $nazwy = ['post_title' => 'Tytuł', 'post_content' => 'Treść', 'post_excerpt' => 'Zajawka'];
    $out = [];
    foreach ($wiersze as $w) {
        if (!preg_match('/^_evk_tl_([a-z0-9_]+?)__(post_title|post_content|post_excerpt)__zrodlo$/', (string) $w->meta_key, $m)) continue;
        $pid = (int) $w->post_id;
        [, $lang, $pole] = $m;
        $tl = evk_tlw_meta($pid, $lang, $pole);
        $pl = (string) get_post_field($pole, $pid, 'raw');
        $z = (string) $w->meta_value;
        if (evk_tlw_pusty($tl) || evk_tlw_pusty($pl)) continue;
        $stary = evk_tlw_nieaktualne($tl, $z, $pl);
        if (!$stary && !evk_tlw_ai($z)) continue;
        $out[] = ['post_id' => $pid, 'meta_key' => EVK_TL_AI_WPIS, 'klucz' => $pole . '|' . $lang, 'element' => 'Wpis', 'pole' => $nazwy[$pole],
            'jezyk' => $lang, 'oryginal' => $pl, 'tlumaczenie' => $tl, 'ai' => !$stary];
        if (count($out) >= $limit) break;
    }
    return $out;
}

// =========================================================================
// POLA EVOKE FIELDS (1.267.0)
// =========================================================================

/*
 * Wartości pól Evoke FIELDS (osobna wtyczka, Fields ≥ 1.75.0) — trzecie
 * źródło tekstów obok treści Bricksa. Fields nie zna AI: podaje teksty
 * wpisu (`evk_fields_tl_teksty()`), przyjmuje tłumaczenie
 * (`evk_fields_tl_wpisz()`, ze znacznikiem „AI — do sprawdzenia”) i pyta
 * filtrem `evk_fields_tl_ai`, dokąd wysłać teksty z przycisków w metaboksie.
 *
 * W hurcie pola to osobna część wpisu (`evk_fields`, „Pola Evoke FIELDS”),
 * tylko po zaznaczeniu pola wyboru (decyzja zgłaszającego z 30.09: domyślnie
 * bez pól). Kontekst: pola wpisu i teksty treści Bricksa tej strony.
 */
const EVK_TL_AI_POLA = 'evk_fields';

/** Fields z API tekstów (1.75.0+) — bez niego ani pola wyboru, ani przycisków. */
function evk_tl_ai_pola_dostepne(): bool {
    return function_exists('evk_fields_tl_teksty') && function_exists('evk_fields_tl_wpisz') && function_exists('evk_fields_tl_typy');
}

/**
 * Teksty pól wpisu w kształcie evk_tl_ai_teksty(): kontekst (najpierw pola,
 * potem treść Bricksa tej strony) i braki — klucz `{miejsce Fields}|{język}`.
 * W trybie ponownym także niesprawdzone tłumaczenia AI (z obecnym tekstem w `bylo`).
 *
 * @return array{kontekst:list<array{element:string,opis:string,pl:string,tl:string}>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty_pol(int $post_id, string $lang, bool $ponownie = false, string $obiekt = 'post'): array {
    if (!evk_tl_ai_pola_dostepne() || ($obiekt === 'term' && !evk_tl_ai_pola_termow_dostepne())) return ['kontekst' => [], 'braki' => []];
    $out = evk_tl_ai_teksty_z_pol($obiekt === 'term' ? evk_fields_tl_teksty($post_id, 'term') : evk_fields_tl_teksty($post_id), $lang, $ponownie);
    if ($out['braki']) {
        /* Słownictwo reszty strony: teksty treści Bricksa z obecnymi tłumaczeniami;
           przy termie (1.270.0) — jego nazwa i opis. */
        $tresc = $obiekt === 'term' ? evk_tl_ai_teksty_termu($post_id, $lang, [])['kontekst']
            : evk_tl_ai_teksty(get_post_meta($post_id, evk_tl_el_klucze_meta()[0], true), $lang)['kontekst'];
        $out['kontekst'] = array_merge($out['kontekst'], $tresc);
    }
    return $out;
}

/**
 * Teksty Fields (z evk_fields_tl_teksty*) → kontekst i braki w kształcie
 * evk_tl_ai_teksty(); klucz braku `{miejsce}|{język}`.
 *
 * @param list<array<string,mixed>> $teksty
 * @return array{kontekst:list<array{element:string,opis:string,pl:string,tl:string}>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty_z_pol(array $teksty, string $lang, bool $ponownie): array {
    $out = ['kontekst' => [], 'braki' => []];
    foreach ($teksty as $m) {
        $pl = (string) ($m['pl'] ?? '');
        if (!evk_tl_ai_do_tlumaczenia($pl)) continue;
        $tl = (string) ($m['tl'][$lang] ?? '');
        $ponow = $ponownie && $tl !== '' && !empty($m['ai'][$lang]);
        $out['kontekst'][] = ['element' => 'Evoke FIELDS · ' . (string) ($m['grupa'] ?? ''), 'opis' => (string) ($m['opis'] ?? ''),
            'pl' => $pl, 'tl' => $tl !== '' && !$ponow ? $tl : ''];
        if ($tl !== '' && !$ponow) continue;
        $out['braki'][(string) $m['klucz'] . '|' . $lang] = ['pl' => $pl, 'element' => 'Evoke FIELDS', 'opis' => (string) ($m['opis'] ?? ''),
            'id' => '', 'sciezka' => (string) $m['klucz'], 'pole' => '', 'n' => count($out['kontekst']), 'bylo' => $ponow ? $tl : ''];
    }
    return $out;
}

/**
 * Zapis kroku hurtu dla pól: każde tłumaczenie przez Fields. Z pamięci
 * tłumaczeń (sprawdzone) — bez znacznika AI, z AI i pamięci wyników — ze znacznikiem.
 *
 * @param array<string,string>               $gotowe
 * @param array<string,bool>                 $ai
 * @param array<string,array<string,mixed>>  $braki
 * @return list<string> Klucze zapisane.
 */
function evk_tl_ai_zapisz_pola(int $post_id, string $lang, array $gotowe, array $ai, array $braki, string $obiekt = 'post'): array {
    $out = [];
    foreach ($gotowe as $k => $tl) {
        $miejsce = (string) ($braki[$k]['sciezka'] ?? '');
        if ($miejsce === '') continue;
        $ok = $obiekt === 'term' ? evk_fields_tl_wpisz($post_id, $miejsce, $lang, (string) $tl, !empty($ai[$k]), 'term')
            : evk_fields_tl_wpisz($post_id, $miejsce, $lang, (string) $tl, !empty($ai[$k]));
        if ($ok) $out[] = (string) $k;
    }
    return $out;
}

/**
 * Wpisy z polami Fields z brakami (lista hurtu): część `evk_fields`.
 *
 * @param list<string> $jezyki
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_pol(array $jezyki, bool $ponownie, array $tylko = []): array {
    if (!evk_tl_ai_pola_dostepne() || !($typy = evk_fields_tl_typy())) return [];
    if ($tylko) $typy = array_values(array_intersect($typy, $tylko));
    if (!$typy) return [];
    $ids = get_posts(['post_type' => $typy, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts' => 1000, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'suppress_filters' => true]);
    $out = [];
    foreach (array_map('intval', $ids) as $post_id) {
        $braki = [];
        $ai = [];
        foreach ($jezyki as $j) {
            $b = evk_tl_ai_teksty_pol($post_id, $j, $ponownie)['braki'];
            if ($b) $braki[$j] = count($b);
            $n = count(array_filter($b, static function ($x) { return $x['bylo'] !== ''; }));
            if ($n) $ai[$j] = $n;
        }
        if (!$braki) continue;
        $out[] = ['post_id' => $post_id, 'meta_key' => EVK_TL_AI_POLA, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => 'Pola Evoke FIELDS', 'adres' => (string) get_edit_post_link($post_id, 'raw'), 'braki' => $braki, 'ai' => (object) $ai];
    }
    return $out;
}

/*
 * Przyciski AI w metaboksie Fields: dane tylko dla kogoś z dostępem do
 * Tłumaczeń i prawem edycji wpisu, tylko z kluczem API. Klucz nie wychodzi
 * do przeglądarki.
 */
add_filter('evk_fields_tl_ai', function ($dane, $post_id = 0, $kontekst = []) {
    $post_id = (int) $post_id;
    /* Strona ustawień Fields (1.268.0, Fields 1.76.0): prawo do tej strony zamiast prawa edycji wpisu. */
    $strona = is_array($kontekst) && is_string($kontekst['strona'] ?? null) ? evk_tl_ai_strona_ustawien($kontekst['strona']) : null;
    /* Edycja termu (1.270.0): ✦ Tłumaczeń (56) i pól Fields (1.77.0) — prawo edycji termu. */
    $term = is_array($kontekst) ? absint($kontekst['term'] ?? 0) : 0;
    if ($term) {
        if (!(get_term($term) instanceof WP_Term) || !current_user_can('edit_term', $term)) return null;
        $post_id = 0;
    } elseif ($strona === null && (!$post_id || !current_user_can('edit_post', $post_id))) return null;
    if (!current_user_can('manage_options') && !current_user_can('evk_access_translations')) return null;
    $u = evk_tl_ai_ustawienia();
    if (evk_tl_ai_klucz($u) === '') return null;
    return ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('evk_tl_ai_pola'), 'post' => $post_id, 'term' => $term,
        'model' => evk_tl_ai_podpis($u), 'porcja' => EVK_TL_AI_PORCJA, 'znaki' => EVK_TL_AI_ZNAKI];
}, 10, 3);

/**
 * Strona ustawień Evoke FIELDS, którą bieżący użytkownik może zapisywać
 * (Fields 1.76.0+, `evk_fields_tl_strona()`); null — brak strony, prawa albo Fields.
 *
 * @return array{slug:string,nazwa:string}|null
 */
function evk_tl_ai_strona_ustawien(string $slug): ?array {
    if ($slug === '' || !function_exists('evk_fields_tl_strona')) return null;
    $s = evk_fields_tl_strona(sanitize_key($slug));
    return is_array($s) ? $s : null;
}

/**
 * Lista „Do sprawdzenia” (52): pola Fields z tłumaczeniem AI albo po zmianie
 * oryginału — w kształcie wierszy elementów (element, pole, język, oryginał,
 * tłumaczenie, ai), część `evk_fields`, klucz `{miejsce}|{język}`.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_pola_do_sprawdzenia(int $limit = 200): array {
    if (!evk_tl_ai_pola_dostepne() || !($typy = evk_fields_tl_typy())) return [];
    $ids = array_map('intval', get_posts(['post_type' => $typy, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts' => 1000, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true, 'suppress_filters' => true]));
    if ($ids) update_meta_cache('post', $ids);
    $out = [];
    foreach ($ids as $post_id) {
        foreach (evk_fields_tl_teksty($post_id) as $m) {
            foreach ((array) ($m['tl'] ?? []) as $j => $tl) {
                if ((string) $tl === '' || (empty($m['ai'][$j]) && empty($m['stale'][$j]))) continue;
                $out[] = ['post_id' => $post_id, 'meta_key' => EVK_TL_AI_POLA, 'klucz' => (string) $m['klucz'] . '|' . $j,
                    'element' => 'Evoke FIELDS · ' . (string) ($m['grupa'] ?? ''), 'pole' => (string) ($m['opis'] ?? ''), 'jezyk' => (string) $j,
                    'oryginal' => (string) $m['pl'], 'tlumaczenie' => (string) $tl, 'ai' => !empty($m['ai'][$j]) && empty($m['stale'][$j])];
                if (count($out) >= $limit) return $out;
            }
        }
    }
    return $out;
}

// =========================================================================
// KOMPONENTY BRICKSA: TEKSTY STAŁE (1.272.0)
// =========================================================================

/**
 * Komponenty z tekstami stałymi bez tłumaczenia (lista hurtu): `post_id` 0,
 * id komponentu w `opcje`, część `evk_komponent`.
 *
 * @param list<string> $jezyki
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_komponentow(array $jezyki, bool $ponownie): array {
    $out = [];
    foreach (evk_tl_kp_komponenty() as $k) {
        $cid = (string) ($k['id'] ?? '');
        $el = evk_tl_kp_elementy_stale($k);
        $stan = $ponownie ? evk_tl_kp_stan($cid) : null;
        $braki = [];
        $ai = [];
        foreach ($jezyki as $j) {
            $b = evk_tl_ai_teksty($el, $j, $stan)['braki'];
            if ($b) $braki[$j] = count($b);
            $n = count(array_filter($b, static function ($x) { return $x['bylo'] !== ''; }));
            if ($n) $ai[$j] = $n;
        }
        if (!$braki) continue;
        $out[] = ['post_id' => 0, 'meta_key' => EVK_TL_KP, 'opcje' => $cid, 'tytul' => 'Komponent: ' . evk_tl_kp_nazwa($k),
            'czesc' => 'Teksty stałe', 'adres' => '', 'braki' => $braki, 'ai' => (object) $ai];
    }
    return $out;
}

/**
 * Zapis kroku dla tekstów stałych komponentu — jak evk_tl_ai_zapisz(), stan
 * w opcji `evk_tl_kp_stan` (51).
 *
 * @param array<string,string> $gotowe
 * @param array<string,bool>   $ai
 * @param array<string,string> $bylo
 * @return list<string>
 */
function evk_tl_ai_zapisz_komponent(string $cid, string $lang, array $gotowe, array $ai, string $model, array $bylo): array {
    $przed = evk_tl_kp_stan($cid);
    foreach (array_keys($bylo) as $klucz) {
        if (!evk_tl_ai_niesprawdzone($przed[$klucz] ?? null)) unset($gotowe[$klucz], $bylo[$klucz]);
    }
    $zapisane = evk_tl_kp_zapisz_pola($cid, $lang, $gotowe, true, $bylo);
    if (!$zapisane) return [];
    $stan = get_option(EVK_TL_KP_STAN, []);
    if (is_array($stan)) {
        foreach ($zapisane as $klucz) {
            if (!isset($stan[$cid][$klucz])) continue;
            if (isset($ai[$klucz])) {
                $stan[$cid][$klucz]['src'] = 'ai';
                if ($model !== '') $stan[$cid][$klucz]['model'] = $model;
            }
            if (isset($bylo[$klucz])) $stan[$cid][$klucz]['poprz'] = ['t' => $bylo[$klucz], 'm' => (string) ($przed[$klucz]['model'] ?? '')];
        }
        update_option(EVK_TL_KP_STAN, $stan, false);
    }
    return $zapisane;
}

// =========================================================================
// STRONY USTAWIEŃ EVOKE FIELDS (1.272.0)
// =========================================================================

/*
 * Grupy pól na stronach ustawień Fields (Fields 1.78.0: `evk_fields_tl_grupy_stron()`,
 * teksty i zapis w opcji grupy). W tabeli zakresu — wiersz na stronę ustawień
 * (`opcje:{slug}`, część „Pola Fields”), w liście — pozycja na grupę
 * („Strona › Grupa”). Jednostka nie ma wpisu: `post_id` 0, grupa w `opcje`.
 * Prawo — uprawnienie strony ustawień, jak przy jej zapisie.
 */
const EVK_TL_AI_POLA_OPCJI = 'evk_fields_opcje';

/** Fields z API grup stron ustawień (1.78.0+). */
function evk_tl_ai_pola_opcji_dostepne(): bool {
    return evk_tl_ai_pola_dostepne() && function_exists('evk_fields_tl_grupy_stron') && function_exists('evk_fields_tl_teksty_opcji')
        && function_exists('evk_fields_tl_wpisz_opcji') && function_exists('evk_fields_tl_sprawdzone_opcji');
}

/**
 * Strony ustawień z grupami pól tłumaczonych, do których bieżący użytkownik ma prawo.
 *
 * @return array<string,array{nazwa:string,prawo:string,grupy:array<string,array{nazwa:string,zakladka:int}>}>
 */
function evk_tl_ai_strony_opcji(): array {
    if (!evk_tl_ai_pola_opcji_dostepne()) return [];
    return array_filter(evk_fields_tl_grupy_stron(), static function ($s) { return current_user_can((string) $s['prawo']); });
}

/**
 * Grupa strony ustawień (z prawem): {slug, strona, grupa, adres}; null — nie ma albo brak prawa.
 *
 * @return array{slug:string,strona:string,grupa:string,adres:string}|null
 */
function evk_tl_ai_grupa_opcji(string $gk): ?array {
    foreach (evk_tl_ai_strony_opcji() as $slug => $s) {
        if (!isset($s['grupy'][$gk])) continue;
        $g = $s['grupy'][$gk];
        return ['slug' => (string) $slug, 'strona' => $s['nazwa'], 'grupa' => $g['nazwa'],
            'adres' => admin_url('admin.php?page=' . rawurlencode((string) $slug) . ($g['zakladka'] ? '&tab=' . $g['zakladka'] : ''))];
    }
    return null;
}

/** Podpis grupy w liście, dzienniku i dla AI: „Strona › Grupa”. */
function evk_tl_ai_tytul_opcji(string $gk): string {
    $g = evk_tl_ai_grupa_opcji($gk);
    return $g ? $g['strona'] . ' › ' . $g['grupa'] : $gk;
}

/**
 * Teksty grupy strony ustawień w kształcie evk_tl_ai_teksty() — kontekstem jest sama grupa.
 *
 * @return array{kontekst:list<array{element:string,opis:string,pl:string,tl:string}>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty_opcji(string $gk, string $lang, bool $ponownie = false): array {
    if (!evk_tl_ai_pola_opcji_dostepne()) return ['kontekst' => [], 'braki' => []];
    return evk_tl_ai_teksty_z_pol(evk_fields_tl_teksty_opcji($gk), $lang, $ponownie);
}

/**
 * Zapis kroku hurtu dla grupy strony ustawień — przez Fields, jak pola wpisu.
 *
 * @param array<string,string>               $gotowe
 * @param array<string,bool>                 $ai
 * @param array<string,array<string,mixed>>  $braki
 * @return list<string>
 */
function evk_tl_ai_zapisz_pola_opcji(string $gk, string $lang, array $gotowe, array $ai, array $braki): array {
    $out = [];
    foreach ($gotowe as $k => $tl) {
        $miejsce = (string) ($braki[$k]['sciezka'] ?? '');
        if ($miejsce !== '' && evk_fields_tl_wpisz_opcji($gk, $miejsce, $lang, (string) $tl, !empty($ai[$k]))) $out[] = (string) $k;
    }
    return $out;
}

/**
 * Grupy jednej strony ustawień z brakami (lista hurtu).
 *
 * @param list<string> $jezyki
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_pol_opcji(array $jezyki, bool $ponownie, string $slug): array {
    $s = evk_tl_ai_strony_opcji()[$slug] ?? null;
    if (!$s) return [];
    $out = [];
    foreach (array_keys($s['grupy']) as $gk) {
        $gk = (string) $gk;
        $braki = [];
        $ai = [];
        foreach ($jezyki as $j) {
            $b = evk_tl_ai_teksty_opcji($gk, $j, $ponownie)['braki'];
            if ($b) $braki[$j] = count($b);
            $n = count(array_filter($b, static function ($x) { return $x['bylo'] !== ''; }));
            if ($n) $ai[$j] = $n;
        }
        if (!$braki) continue;
        $g = evk_tl_ai_grupa_opcji($gk);
        $out[] = ['post_id' => 0, 'meta_key' => EVK_TL_AI_POLA_OPCJI, 'opcje' => $gk, 'tytul' => evk_tl_ai_tytul_opcji($gk),
            'czesc' => 'Pola Evoke FIELDS', 'adres' => $g ? $g['adres'] : '', 'braki' => $braki, 'ai' => (object) $ai];
    }
    return $out;
}

/**
 * „Do sprawdzenia” (52): pola grup stron ustawień z tłumaczeniem AI albo po
 * zmianie oryginału. `post_id` 0, klucz `{grupa}#{miejsce}|{język}`, wiersz niesie tytuł i adres.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_pola_opcji_do_sprawdzenia(int $limit = 200): array {
    $out = [];
    foreach (evk_tl_ai_strony_opcji() as $s) {
        foreach (array_keys($s['grupy']) as $gk) {
            $gk = (string) $gk;
            $g = evk_tl_ai_grupa_opcji($gk);
            foreach (evk_fields_tl_teksty_opcji($gk) as $m) {
                foreach ((array) ($m['tl'] ?? []) as $j => $tl) {
                    if ((string) $tl === '' || (empty($m['ai'][$j]) && empty($m['stale'][$j]))) continue;
                    $out[] = ['post_id' => 0, 'meta_key' => EVK_TL_AI_POLA_OPCJI, 'klucz' => $gk . '#' . (string) $m['klucz'] . '|' . $j,
                        'tytul' => evk_tl_ai_tytul_opcji($gk), 'edycja' => $g ? $g['adres'] : '',
                        'element' => 'Evoke FIELDS · ' . (string) ($m['grupa'] ?? ''), 'pole' => (string) ($m['opis'] ?? ''), 'jezyk' => (string) $j,
                        'oryginal' => (string) $m['pl'], 'tlumaczenie' => (string) $tl, 'ai' => !empty($m['ai'][$j]) && empty($m['stale'][$j])];
                    if (count($out) >= $limit) return $out;
                }
            }
        }
    }
    return $out;
}

/** „Sprawdzone” z listy: klucz `{grupa}#{miejsce}|{język}`, prawo strony ustawień. */
function evk_tl_ai_pola_opcji_sprawdzone(string $klucz): bool {
    $h = strpos($klucz, '#');
    $p = strrpos($klucz, '|');
    if ($h === false || $p === false || $p < $h) return false;
    $gk = substr($klucz, 0, $h);
    return evk_tl_ai_grupa_opcji($gk) !== null && evk_fields_tl_sprawdzone_opcji($gk, substr($klucz, $h + 1, $p - $h - 1), substr($klucz, $p + 1));
}

// =========================================================================
// KATEGORIE I TAGI (1.270.0)
// =========================================================================

/*
 * Nazwa i opis termu (moduł 56: meta termu `_evk_tl_{język}__{pole}` i źródło)
 * oraz pola Evoke FIELDS w termach (Fields 1.77.0, `evk_fields_tl_teksty($id, 'term')`).
 * W hurcie osobne części: `evk_term` (pola wyboru „Nazwa”, „Opis”, „Adres
 * z nazwy” — domyślnie odznaczone, jak przy wpisach) i `evk_fields_term`
 * (razem z polem „Pola Evoke FIELDS”). Identyfikatorem części jest id termu
 * w `post_id` — kształt jednostki hurtu zostaje ten sam.
 */
const EVK_TL_AI_TERM = 'evk_term';
const EVK_TL_AI_POLA_TERMU = 'evk_fields_term';
const EVK_TL_AI_POLA_TERMOW = ['name', 'description'];

/** Moduł termów (56) wczytany. */
function evk_tl_ai_termy_dostepne(): bool {
    return function_exists('evk_tlt_taksonomie') && function_exists('evk_tlt_meta');
}

/** Fields podaje teksty pól termów (1.77.0+). */
function evk_tl_ai_pola_termow_dostepne(): bool {
    return evk_tl_ai_pola_dostepne() && function_exists('evk_fields_tl_obiekty') && function_exists('evk_fields_tl_taksonomie')
        && in_array('term', (array) evk_fields_tl_obiekty(), true);
}

/** Nazwa rodzaju termu („Kategoria”, „Tag”) — element w kontekście i w liście. */
function evk_tl_ai_rodzaj_termu(WP_Term $t): string {
    $tax = get_taxonomy($t->taxonomy);
    return $tax ? (string) $tax->labels->singular_name : $t->taxonomy;
}

/** Podpis termu w liście hurtu i w dzienniku: „Nazwa (Kategoria)”. */
function evk_tl_ai_tytul_termu(int $term_id): string {
    $t = get_term($term_id);
    return $t instanceof WP_Term ? $t->name . ' (' . evk_tl_ai_rodzaj_termu($t) . ')' : '#' . $term_id;
}

/** Tytuł części dla AI i dziennika: wpis albo term. */
function evk_tl_ai_tytul_czesci(int $id, string $meta_key): string {
    if ($meta_key === EVK_TL_AI_TERM || $meta_key === EVK_TL_AI_POLA_TERMU) return evk_tl_ai_tytul_termu($id);
    if ($meta_key === EVK_TL_AI_ALT) return 'Biblioteka mediów — teksty alternatywne obrazów';
    return (string) (get_the_title($id) ?: ('#' . $id));
}

/**
 * Teksty termu w kształcie evk_tl_ai_teksty(): kontekst (nazwa, opis, potem
 * pola Fields termu) i braki — tylko pól z `$pola`. Klucz braku: `{pole}|{język}`.
 *
 * @param list<string> $pola
 * @return array{kontekst:list<array{element:string,opis:string,pl:string,tl:string}>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty_termu(int $term_id, string $lang, array $pola, bool $ponownie = false): array {
    $out = ['kontekst' => [], 'braki' => []];
    $t = get_term($term_id);
    if (!($t instanceof WP_Term) || !evk_tl_ai_termy_dostepne()) return $out;
    $element = evk_tl_ai_rodzaj_termu($t);
    $nazwy = ['name' => 'Nazwa', 'description' => 'Opis'];
    foreach (EVK_TL_AI_POLA_TERMOW as $pole) {
        $pl = (string) $t->{$pole};
        if (!evk_tl_ai_do_tlumaczenia($pl)) continue;
        $tl = evk_tlt_meta($term_id, $lang, $pole);
        $jest = !evk_tlw_pusty($tl);
        $ponow = $ponownie && $jest && evk_tlw_ai((string) get_term_meta($term_id, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo', true));
        $out['kontekst'][] = ['element' => $element, 'opis' => $nazwy[$pole], 'pl' => $pl, 'tl' => $jest && !$ponow ? $tl : ''];
        if (!in_array($pole, $pola, true) || ($jest && !$ponow)) continue;
        $out['braki'][$pole . '|' . $lang] = ['pl' => $pl, 'element' => $element, 'opis' => $nazwy[$pole], 'id' => '', 'sciezka' => $pole,
            'pole' => $pole, 'n' => count($out['kontekst']), 'bylo' => $ponow ? $tl : ''];
    }
    if ($out['braki'] && evk_tl_ai_pola_termow_dostepne()) {
        foreach (evk_fields_tl_teksty($term_id, 'term') as $m) {
            if (!evk_tl_ai_do_tlumaczenia((string) $m['pl'])) continue;
            $out['kontekst'][] = ['element' => 'Evoke FIELDS · ' . (string) ($m['grupa'] ?? ''), 'opis' => (string) ($m['opis'] ?? ''),
                'pl' => (string) $m['pl'], 'tl' => (string) ($m['tl'][$lang] ?? '')];
        }
    }
    return $out;
}

/**
 * Zapis kroku hurtu dla nazwy i opisu termu — jak formularz modułu 56:
 * sanityzacja rdzenia dla pola termu, bez odstępów na brzegach; z AI —
 * źródło ze znacznikiem.
 *
 * @param array<string,string>              $gotowe
 * @param array<string,bool>                $ai
 * @param array<string,array<string,mixed>> $braki
 * @return list<string>
 */
function evk_tl_ai_zapisz_term(int $term_id, string $lang, array $gotowe, array $ai, array $braki): array {
    $t = get_term($term_id);
    if (!($t instanceof WP_Term)) return [];
    $out = [];
    foreach ($gotowe as $k => $tl) {
        $pole = (string) ($braki[$k]['sciezka'] ?? '');
        if (!in_array($pole, EVK_TL_AI_POLA_TERMOW, true)) continue;
        $tekst = trim((string) wp_unslash(sanitize_term_field($pole, wp_slash((string) $tl), $term_id, $t->taxonomy, 'db')));
        if (evk_tlw_pusty($tekst)) continue;
        update_term_meta($term_id, '_evk_tl_' . $lang . '__' . $pole, wp_slash($tekst));
        update_term_meta($term_id, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo', (!empty($ai[$k]) ? 'ai-' : '') . evk_tlw_zrodlo((string) $t->{$pole}));
        $out[] = (string) $k;
    }
    return $out;
}

/**
 * Adres z nazwy: człon z tłumaczenia nazwy do mapy adresów — zasady jak
 * evk_tl_ai_adres_z_tytulu() (konflikt jak przy zapisie termu w 56).
 *
 * @return array{stan:string,czlon:string,powod:string}
 */
function evk_tl_ai_adres_z_nazwy(int $term_id, string $lang): array {
    $w = ['stan' => '', 'czlon' => '', 'powod' => ''];
    $t = get_term($term_id);
    $pl = $t instanceof WP_Term ? (string) $t->slug : '';
    if ($pl === '') return ['stan' => 'bez_adresu'] + $w;
    if (evk_tlw_slug($pl, $lang) !== '') return ['stan' => 'jest', 'czlon' => evk_tlw_slug($pl, $lang)] + $w;
    $nazwa = evk_tlt_meta($term_id, $lang, 'name');
    if (evk_tlw_pusty($nazwa)) return ['stan' => 'bez_tytulu'] + $w;
    $czlon = sanitize_title($nazwa);
    if ($czlon === '' || $czlon === $pl) return ['stan' => 'ten_sam', 'czlon' => $czlon] + $w;
    $powod = evk_tlw_slug_konflikt($pl, $lang, $czlon, 0, '', $term_id);
    if ($powod !== '') return ['stan' => 'konflikt', 'czlon' => $czlon, 'powod' => $powod];
    evk_tlw_zapisz_slug($pl, $lang, $czlon);
    return ['stan' => 'zapisany', 'czlon' => $czlon, 'powod' => ''];
}

/**
 * Termy (lista hurtu): id termów z obsługiwanych taksonomii, najstarsze najpierw.
 *
 * @param list<string> $taksonomie
 * @return list<int>
 */
function evk_tl_ai_id_termow(array $taksonomie): array {
    if (!$taksonomie) return [];
    $ids = get_terms(['taxonomy' => $taksonomie, 'hide_empty' => false, 'number' => 1000, 'orderby' => 'term_id', 'order' => 'ASC', 'fields' => 'ids']);
    return is_array($ids) ? array_map('intval', $ids) : [];
}

/**
 * Termy z brakami (lista hurtu): część `evk_term`. Sam „Adres z nazwy” liczy
 * termy z tłumaczeniem nazwy, a bez członu w mapie adresów (1 na język).
 *
 * @param list<string> $jezyki
 * @param list<string> $pola
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_termow(array $jezyki, array $pola, bool $adres, bool $ponownie, array $tylko = []): array {
    if (!evk_tl_ai_termy_dostepne()) return [];
    $out = [];
    foreach (evk_tl_ai_id_termow($tylko ? array_values(array_intersect(evk_tlt_taksonomie(), $tylko)) : evk_tlt_taksonomie()) as $id) {
        $t = get_term($id);
        if (!($t instanceof WP_Term)) continue;
        $braki = [];
        $ai = [];
        foreach ($jezyki as $j) {
            $b = $pola ? evk_tl_ai_teksty_termu($id, $j, $pola, $ponownie)['braki'] : [];
            $n = count($b);
            if (!$n && $adres && $t->slug !== '' && evk_tlw_slug($t->slug, $j) === '' && !evk_tlw_pusty(evk_tlt_meta($id, $j, 'name'))) $n = 1;
            if ($n) $braki[$j] = $n;
            $x = count(array_filter($b, static function ($y) { return $y['bylo'] !== ''; }));
            if ($x) $ai[$j] = $x;
        }
        if (!$braki) continue;
        $out[] = ['post_id' => $id, 'meta_key' => EVK_TL_AI_TERM, 'tytul' => evk_tl_ai_tytul_termu($id),
            'czesc' => $pola ? 'Nazwa i opis' : 'Adres z nazwy', 'adres' => (string) get_edit_term_link($id, $t->taxonomy), 'braki' => $braki, 'ai' => (object) $ai];
    }
    return $out;
}

/**
 * Termy z polami Fields z brakami (lista hurtu): część `evk_fields_term`.
 *
 * @param list<string> $jezyki
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_pol_termow(array $jezyki, bool $ponownie, array $tylko = []): array {
    if (!evk_tl_ai_pola_termow_dostepne()) return [];
    $out = [];
    foreach (evk_tl_ai_id_termow($tylko ? array_values(array_intersect(evk_fields_tl_taksonomie(), $tylko)) : evk_fields_tl_taksonomie()) as $id) {
        $braki = [];
        $ai = [];
        foreach ($jezyki as $j) {
            $b = evk_tl_ai_teksty_pol($id, $j, $ponownie, 'term')['braki'];
            if ($b) $braki[$j] = count($b);
            $n = count(array_filter($b, static function ($x) { return $x['bylo'] !== ''; }));
            if ($n) $ai[$j] = $n;
        }
        if (!$braki) continue;
        $t = get_term($id);
        $out[] = ['post_id' => $id, 'meta_key' => EVK_TL_AI_POLA_TERMU, 'tytul' => evk_tl_ai_tytul_termu($id), 'czesc' => 'Pola Evoke FIELDS',
            'adres' => $t instanceof WP_Term ? (string) get_edit_term_link($id, $t->taxonomy) : '', 'braki' => $braki, 'ai' => (object) $ai];
    }
    return $out;
}

/** Czy bieżący użytkownik może tłumaczyć tę część termu (krok hurtu). */
function evk_tl_ai_term_do_kroku(int $term_id, string $meta_key): bool {
    $t = get_term($term_id);
    if (!($t instanceof WP_Term) || !current_user_can('edit_term', $term_id)) return false;
    if ($meta_key === EVK_TL_AI_TERM) return evk_tl_ai_termy_dostepne() && in_array($t->taxonomy, evk_tlt_taksonomie(), true);
    return $meta_key === EVK_TL_AI_POLA_TERMU && evk_tl_ai_pola_termow_dostepne() && in_array($t->taxonomy, evk_fields_tl_taksonomie(), true);
}

/**
 * Lista „Do sprawdzenia” (52): nazwy i opisy termów z tłumaczeniem AI albo po
 * zmianie polskiego tekstu — część `evk_term`, klucz `{pole}|{język}`;
 * wiersz niesie tytuł i adres edycji termu.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_termy_do_sprawdzenia(int $limit = 200): array {
    global $wpdb;
    if (!evk_tl_ai_termy_dostepne()) return [];
    $wiersze = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key LIKE %s AND meta_key LIKE %s ORDER BY term_id DESC LIMIT %d",
        $wpdb->esc_like('_evk_tl_') . '%', '%' . $wpdb->esc_like('__zrodlo'), $limit * 4));
    $nazwy = ['name' => 'Nazwa', 'description' => 'Opis'];
    $out = [];
    foreach ($wiersze as $w) {
        if (!preg_match('/^_evk_tl_([a-z0-9_]+?)__(name|description)__zrodlo$/', (string) $w->meta_key, $m)) continue;
        $id = (int) $w->term_id;
        $t = get_term($id);
        if (!($t instanceof WP_Term) || !in_array($t->taxonomy, evk_tlt_taksonomie(), true)) continue;
        [, $lang, $pole] = $m;
        $tl = evk_tlt_meta($id, $lang, $pole);
        $pl = (string) $t->{$pole};
        $z = (string) $w->meta_value;
        if (evk_tlw_pusty($tl) || evk_tlw_pusty($pl)) continue;
        $stary = evk_tlw_nieaktualne($tl, $z, $pl);
        if (!$stary && !evk_tlw_ai($z)) continue;
        $out[] = ['post_id' => $id, 'meta_key' => EVK_TL_AI_TERM, 'klucz' => $pole . '|' . $lang, 'element' => evk_tl_ai_rodzaj_termu($t),
            'pole' => $nazwy[$pole], 'jezyk' => $lang, 'oryginal' => $pl, 'tlumaczenie' => $tl, 'ai' => !$stary,
            'tytul' => $t->name, 'edycja' => (string) get_edit_term_link($id, $t->taxonomy)];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * Lista „Do sprawdzenia” (52): pola Fields termów — część `evk_fields_term`.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_pola_termow_do_sprawdzenia(int $limit = 200): array {
    if (!evk_tl_ai_pola_termow_dostepne()) return [];
    $out = [];
    foreach (array_reverse(evk_tl_ai_id_termow(evk_fields_tl_taksonomie())) as $id) {
        $t = get_term($id);
        if (!($t instanceof WP_Term)) continue;
        foreach (evk_fields_tl_teksty($id, 'term') as $m) {
            foreach ((array) ($m['tl'] ?? []) as $j => $tl) {
                if ((string) $tl === '' || (empty($m['ai'][$j]) && empty($m['stale'][$j]))) continue;
                $out[] = ['post_id' => $id, 'meta_key' => EVK_TL_AI_POLA_TERMU, 'klucz' => (string) $m['klucz'] . '|' . $j,
                    'element' => 'Evoke FIELDS · ' . (string) ($m['grupa'] ?? ''), 'pole' => (string) ($m['opis'] ?? ''), 'jezyk' => (string) $j,
                    'oryginal' => (string) $m['pl'], 'tlumaczenie' => (string) $tl, 'ai' => !empty($m['ai'][$j]) && empty($m['stale'][$j]),
                    'tytul' => $t->name, 'edycja' => (string) get_edit_term_link($id, $t->taxonomy)];
                if (count($out) >= $limit) return $out;
            }
        }
    }
    return $out;
}

// =========================================================================
// SEO (1.271.0)
// =========================================================================

/*
 * Tytuł, opis i słowa kluczowe SEO wpisu (85-seo: `_evk_tl_{język}__seo_*`
 * i źródło). Polski tekst — ten, który działa na stronie: Bricks przed zakładką
 * SEO (evk_seo_pl_pola()). Pole ze znacznikiem `{tl_…}` tłumaczy się samo —
 * AI go pomija. W hurcie część `evk_seo` (kolumna „SEO” tabeli zakresu).
 */
const EVK_TL_AI_SEO = 'evk_seo';
const EVK_TL_AI_POLA_SEO = ['seo_title' => 'Tytuł SEO', 'seo_desc' => 'Opis SEO', 'seo_keywords' => 'Słowa kluczowe'];

/** Moduł SEO z wersjami językowymi i moduł wpisów (źródło) — bez nich SEO poza AI. */
function evk_tl_ai_seo_dostepne(): bool {
    return function_exists('evk_seo_pl_pola') && function_exists('evk_seo_meta_jezyka') && function_exists('evk_tlw_zrodlo');
}

/**
 * Teksty SEO wpisu w kształcie evk_tl_ai_teksty(): kontekst (tytuł wpisu
 * z tłumaczeniem, pola SEO) i braki — tylko pól z `$pola`. Klucz `{pole}|{język}`.
 *
 * @param list<string> $pola
 * @return array{kontekst:list<array{element:string,opis:string,pl:string,tl:string}>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty_seo(int $post_id, string $lang, array $pola, bool $ponownie = false): array {
    $out = ['kontekst' => [], 'braki' => []];
    if (!evk_tl_ai_seo_dostepne() || !get_post($post_id)) return $out;
    $tytul = (string) get_post_field('post_title', $post_id, 'raw');
    if (evk_tl_ai_do_tlumaczenia($tytul)) {
        $out['kontekst'][] = ['element' => 'Wpis', 'opis' => 'Tytuł', 'pl' => $tytul, 'tl' => function_exists('evk_tlw_meta') ? evk_tlw_meta($post_id, $lang, 'post_title') : ''];
    }
    $pl = evk_seo_pl_pola($post_id);
    foreach (evk_seo_pola_jezykowe() as $klucz => $pole) {
        $tekst = trim((string) ($pl[$klucz] ?? ''));
        if (!evk_tl_ai_do_tlumaczenia($tekst) || evk_seo_ma_tl($tekst)) continue;
        $tl = evk_seo_meta_jezyka($post_id, $lang, $pole);
        $ponow = $ponownie && $tl !== '' && evk_tlw_ai(evk_seo_zrodlo_jezyka($post_id, $lang, $pole));
        $opis = EVK_TL_AI_POLA_SEO[$pole] ?? $pole;
        $out['kontekst'][] = ['element' => 'SEO', 'opis' => $opis, 'pl' => $tekst, 'tl' => $tl !== '' && !$ponow ? $tl : ''];
        if (!in_array($pole, $pola, true) || ($tl !== '' && !$ponow)) continue;
        $out['braki'][$pole . '|' . $lang] = ['pl' => $tekst, 'element' => 'SEO', 'opis' => $opis, 'id' => '', 'sciezka' => $pole,
            'pole' => $pole, 'n' => count($out['kontekst']), 'bylo' => $ponow ? $tl : ''];
    }
    return $out;
}

/**
 * Zapis kroku hurtu dla SEO — jak zakładka SEO: sanityzacja pola, źródło
 * ze znacznikiem przy AI.
 *
 * @param array<string,string>              $gotowe
 * @param array<string,bool>                $ai
 * @param array<string,array<string,mixed>> $braki
 * @return list<string>
 */
function evk_tl_ai_zapisz_seo(int $post_id, string $lang, array $gotowe, array $ai, array $braki): array {
    $out = [];
    foreach ($gotowe as $k => $tl) {
        $pole = (string) ($braki[$k]['sciezka'] ?? '');
        if (!isset(EVK_TL_AI_POLA_SEO[$pole])) continue;
        $tekst = $pole === 'seo_desc' ? sanitize_textarea_field((string) $tl) : sanitize_text_field((string) $tl);
        if ($tekst === '') continue;
        update_post_meta($post_id, '_evk_tl_' . $lang . '__' . $pole, wp_slash($tekst));
        update_post_meta($post_id, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo', (!empty($ai[$k]) ? 'ai-' : '') . evk_tlw_zrodlo((string) $braki[$k]['pl']));
        $out[] = (string) $k;
    }
    return $out;
}

/** Wpisy z polskim SEO (zakładka albo ustawienia strony Bricksa) wśród typów. @param list<string> $typy @return list<int> */
function evk_tl_ai_id_wpisow_seo(array $typy): array {
    global $wpdb;
    if (!$typy) return [];
    $in = implode(',', array_fill(0, count($typy), '%s'));
    $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key IN ('_evoke_seo_title', '_evoke_seo_desc', '_evoke_seo_keywords', '_bricks_page_settings') AND pm.meta_value <> ''
        AND p.post_type IN ($in) AND p.post_status IN ('publish', 'draft', 'pending', 'private', 'future') ORDER BY pm.post_id LIMIT 2000", ...$typy));
    return array_map('intval', (array) $ids);
}

/**
 * Wpisy z brakami SEO (lista hurtu): część `evk_seo`, wszystkie trzy pola.
 *
 * @param list<string> $jezyki
 * @param list<string> $tylko
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_seo(array $jezyki, bool $ponownie, array $tylko = []): array {
    if (!evk_tl_ai_seo_dostepne() || !function_exists('evk_tlw_typy')) return [];
    $typy = $tylko ? array_values(array_intersect(evk_tlw_typy(), $tylko)) : evk_tlw_typy();
    $out = [];
    foreach (evk_tl_ai_id_wpisow_seo($typy) as $post_id) {
        $braki = [];
        $ai = [];
        foreach ($jezyki as $j) {
            $b = evk_tl_ai_teksty_seo($post_id, $j, array_keys(EVK_TL_AI_POLA_SEO), $ponownie)['braki'];
            if ($b) $braki[$j] = count($b);
            $x = count(array_filter($b, static function ($y) { return $y['bylo'] !== ''; }));
            if ($x) $ai[$j] = $x;
        }
        if (!$braki) continue;
        $out[] = ['post_id' => $post_id, 'meta_key' => EVK_TL_AI_SEO, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => 'SEO', 'adres' => (string) get_edit_post_link($post_id, 'raw'), 'braki' => $braki, 'ai' => (object) $ai];
    }
    return $out;
}

/**
 * Lista „Do sprawdzenia” (52): pola SEO z tłumaczeniem AI albo po zmianie
 * polskiego tekstu — część `evk_seo`, odnośnik do edycji wpisu (metaboks SEO).
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_seo_do_sprawdzenia(int $limit = 200): array {
    global $wpdb;
    if (!evk_tl_ai_seo_dostepne()) return [];
    $wiersze = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_key LIKE %s ORDER BY post_id DESC LIMIT %d",
        $wpdb->esc_like('_evk_tl_') . '%', '%' . $wpdb->esc_like('__zrodlo'), $limit * 4));
    $out = [];
    foreach ($wiersze as $w) {
        if (!preg_match('/^_evk_tl_([a-z0-9_]+?)__(seo_title|seo_desc|seo_keywords)__zrodlo$/', (string) $w->meta_key, $m)) continue;
        $pid = (int) $w->post_id;
        [, $lang, $pole] = $m;
        $klucz = array_search($pole, evk_seo_pola_jezykowe(), true);
        $tl = evk_seo_meta_jezyka($pid, $lang, $pole);
        $pl = (string) (evk_seo_pl_pola($pid)[$klucz] ?? '');
        $z = (string) $w->meta_value;
        if ($tl === '' || trim($pl) === '') continue;
        $stary = evk_tlw_nieaktualne($tl, $z, $pl);
        if (!$stary && !evk_tlw_ai($z)) continue;
        $out[] = ['post_id' => $pid, 'meta_key' => EVK_TL_AI_SEO, 'klucz' => $pole . '|' . $lang, 'element' => 'SEO', 'pole' => EVK_TL_AI_POLA_SEO[$pole],
            'jezyk' => $lang, 'oryginal' => $pl, 'tlumaczenie' => $tl, 'ai' => !$stary, 'edycja' => (string) get_edit_post_link($pid, 'raw')];
        if (count($out) >= $limit) break;
    }
    return $out;
}

// =========================================================================
// ALT OBRAZÓW (1.271.0)
// =========================================================================

/*
 * Tekst alternatywny obrazów z biblioteki (57: `_evk_tl_{język}__alt` i źródło):
 *   — tłumaczenie polskiego altu (część `evk_alt`);
 *   — brakujący polski alt — AI ogląda obraz (część `evk_alt_pl`, decyzja
 *     zgłaszającego). Wynik idzie do `_wp_attachment_image_alt`, a skrót
 *     opisu do `_evk_alt_ai`: „AI — do sprawdzenia”, dopóki alt jest tym
 *     tekstem (poprawka albo „Sprawdzone” zdejmuje znacznik).
 * W hurcie wiersz „Obrazy” tabeli zakresu, pozycje po EVK_TL_AI_ALT_PORCJA
 * obrazów: identyfikator części to pierwszy obraz porcji, `do` — ostatni.
 */
const EVK_TL_AI_ALT = 'evk_alt';
const EVK_TL_AI_ALT_PL = 'evk_alt_pl';
const EVK_TL_AI_ALT_PORCJA = 25;
/* Obraz do AI: najmniejszy rozmiar biblioteki z dłuższym bokiem co najmniej tyle (zgłaszający: „mniej niż 768”). */
const EVK_TL_AI_ALT_BOK = 512;
/* Opisów na jeden krok hurtu — każdy to osobne zapytanie z obrazem. */
const EVK_TL_AI_ALT_NA_KROK = 3;

/** Moduł altów (57) i wpisów (źródło) wczytane. */
function evk_tl_ai_alt_dostepne(): bool {
    return function_exists('evk_tlw_jezyki') && function_exists('evk_tlw_zrodlo');
}

/** Polski alt obrazu (surowo — w panelu filtr języka 57 i tak nie działa). */
function evk_tl_ai_alt_pl(int $id): string {
    return trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
}

/** Obrazy biblioteki, najstarsze najpierw. @return list<int> */
function evk_tl_ai_id_obrazow(): array {
    return array_map('intval', get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit',
        'numberposts' => 5000, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'suppress_filters' => true]));
}

/**
 * Plik obrazu dla AI: najmniejszy rozmiar z dłuższym bokiem ≥ EVK_TL_AI_ALT_BOK
 * (zwykle `medium_large`, 768 px), a gdy takiego nie ma — oryginał. SVG i pliki
 * powyżej 4 MB — null.
 *
 * @return array{mime:string,dane:string,plik:string,szer:int,wys:int}|null
 */
function evk_tl_ai_obraz_do_ai(int $id): ?array {
    $plik = (string) get_attached_file($id);
    $meta = wp_get_attachment_metadata($id);
    if ($plik === '' || !is_array($meta)) return null;
    $wybor = ['file' => $plik, 'width' => (int) ($meta['width'] ?? 0), 'height' => (int) ($meta['height'] ?? 0), 'mime' => (string) get_post_mime_type($id)];
    $najlepszy = null;
    foreach ((array) ($meta['sizes'] ?? []) as $r) {
        $bok = max((int) $r['width'], (int) $r['height']);
        if ($bok < EVK_TL_AI_ALT_BOK || empty($r['file'])) continue;
        if ($najlepszy === null || $bok < max($najlepszy['width'], $najlepszy['height'])) {
            $najlepszy = ['file' => dirname($plik) . '/' . $r['file'], 'width' => (int) $r['width'], 'height' => (int) $r['height'],
                'mime' => (string) $r['mime-type']];
        }
    }
    if ($najlepszy !== null && max($najlepszy['width'], $najlepszy['height']) < max($wybor['width'], $wybor['height'])) $wybor = $najlepszy;
    if (!in_array($wybor['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || !is_readable($wybor['file'])) return null;
    $rozmiar = (int) filesize($wybor['file']);
    if ($rozmiar <= 0 || $rozmiar > 4 * 1024 * 1024) return null;
    return ['mime' => $wybor['mime'], 'dane' => base64_encode((string) file_get_contents($wybor['file'])), 'plik' => wp_basename($wybor['file']),
        'szer' => $wybor['width'], 'wys' => $wybor['height']];
}

/**
 * Polski alt z obrazu (AI ogląda obraz). Kontekst: nazwa pliku, tytuł
 * załącznika, wpis, do którego go wgrano, i opis strony z ustawień.
 *
 * @return array{ok:bool,tekst?:string,blad?:string,czekaj?:int,stop?:bool}
 */
function evk_tl_ai_opisz_obraz(int $id, array $u): array {
    $o = evk_tl_ai_obraz_do_ai($id);
    if ($o === null) return ['ok' => false, 'blad' => 'Tego pliku AI nie obejrzy (format albo rozmiar).'];
    $s = "You write alt text in Polish for images on a website.\n\nRules:\n"
       . "- Describe what matters in the image for someone who cannot see it, in one short sentence (at most 125 characters).\n"
       . "- Do not start with \"Obraz przedstawia\", \"Zdjęcie\" or similar. No quotes, no file names, no hashtags.\n"
       . "- If the image contains readable text that matters, include it.\n"
       . "- Return JSON: {\"translations\":[{\"key\":\"t1\",\"text\":\"…\"}]}.\n";
    if (trim((string) $u['opis']) !== '') $s .= "\nAbout the website:\n" . trim((string) $u['opis']) . "\n";
    $rodzic = (int) wp_get_post_parent_id($id);
    $m = 'File: ' . $o['plik'] . "\nTitle: " . (string) get_the_title($id) . ($rodzic ? "\nUsed on page: " . (string) get_the_title($rodzic) : '');
    $r = evk_tl_ai_wyslij($u, $s, $m, [['mime' => $o['mime'], 'dane' => $o['dane']]]);
    if (!$r['ok']) return ['ok' => false, 'blad' => (string) $r['blad'], 'czekaj' => (int) ($r['czekaj'] ?? 0), 'stop' => !empty($r['stop'])];
    $tekst = trim(sanitize_text_field((string) ($r['tlumaczenia']['t1'] ?? '')));
    if ($tekst === '') return ['ok' => false, 'blad' => 'AI nie zwróciło opisu.'];
    return ['ok' => true, 'tekst' => mb_substr($tekst, 0, 200)];
}

/** Czy polski alt to wciąż nieprzejrzany opis AI (skrót w `_evk_alt_ai`). */
function evk_tl_ai_alt_pl_z_ai(int $id): bool {
    $z = (string) get_post_meta($id, '_evk_alt_ai', true);
    $alt = evk_tl_ai_alt_pl($id);
    return $z !== '' && $alt !== '' && evk_tlw_zrodlo($alt) === $z;
}

/** Zapis polskiego altu z AI ze znacznikiem „do sprawdzenia”. */
function evk_tl_ai_zapisz_alt_pl(int $id, string $tekst): void {
    update_post_meta($id, '_wp_attachment_image_alt', wp_slash($tekst));
    update_post_meta($id, '_evk_alt_ai', evk_tlw_zrodlo($tekst));
}

/**
 * Teksty altów porcji obrazów (identyfikatory od–do) w kształcie
 * evk_tl_ai_teksty(): kontekst — polskie alty porcji, braki — puste
 * (w trybie ponownym także niesprawdzone AI) alty języka. Klucz `{id}|{język}`.
 *
 * @return array{kontekst:list<array{element:string,opis:string,pl:string,tl:string}>,braki:array<string,array<string,mixed>>}
 */
function evk_tl_ai_teksty_alt(int $od, int $do, string $lang, bool $ponownie = false): array {
    $out = ['kontekst' => [], 'braki' => []];
    if (!evk_tl_ai_alt_dostepne()) return $out;
    foreach (evk_tl_ai_id_obrazow() as $id) {
        if ($id < $od || $id > $do) continue;
        $pl = evk_tl_ai_alt_pl($id);
        if (!evk_tl_ai_do_tlumaczenia($pl)) continue;
        $tl = (string) get_post_meta($id, '_evk_tl_' . $lang . '__alt', true);
        $ponow = $ponownie && $tl !== '' && evk_tlw_ai((string) get_post_meta($id, '_evk_tl_' . $lang . '__alt__zrodlo', true));
        $plik = wp_basename((string) get_attached_file($id));
        $out['kontekst'][] = ['element' => 'Obraz', 'opis' => 'Alt (' . $plik . ')', 'pl' => $pl, 'tl' => $tl !== '' && !$ponow ? $tl : ''];
        if ($tl !== '' && !$ponow) continue;
        $out['braki'][$id . '|' . $lang] = ['pl' => $pl, 'element' => 'Obraz', 'opis' => 'Alt', 'id' => '', 'sciezka' => (string) $id,
            'pole' => 'alt', 'n' => count($out['kontekst']), 'bylo' => $ponow ? $tl : ''];
    }
    return $out;
}

/**
 * Zapis kroku hurtu dla altów — jak pole w oknie mediów: tekst bez znaczników,
 * źródło ze znacznikiem przy AI. Bez prawa edycji obrazu — pominięty.
 *
 * @param array<string,string>              $gotowe
 * @param array<string,bool>                $ai
 * @param array<string,array<string,mixed>> $braki
 * @return list<string>
 */
function evk_tl_ai_zapisz_alt(string $lang, array $gotowe, array $ai, array $braki): array {
    $out = [];
    foreach ($gotowe as $k => $tl) {
        $id = (int) ($braki[$k]['sciezka'] ?? 0);
        $tekst = sanitize_text_field((string) $tl);
        if (!$id || $tekst === '' || !current_user_can('edit_post', $id)) continue;
        update_post_meta($id, '_evk_tl_' . $lang . '__alt', wp_slash($tekst));
        update_post_meta($id, '_evk_tl_' . $lang . '__alt__zrodlo', (!empty($ai[$k]) ? 'ai-' : '') . evk_tlw_zrodlo((string) $braki[$k]['pl']));
        $out[] = (string) $k;
    }
    return $out;
}

/**
 * Obrazy z brakami (lista hurtu), porcjami: „Brakujący alt PL” (obrazy bez
 * polskiego altu, braki pod kodem `pl`) i „Alt” (tłumaczenia; przy zaznaczonym
 * tworzeniu altu PL liczą się też obrazy, które go dopiero dostaną).
 *
 * @param list<string> $jezyki
 * @param list<string> $czesci
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_obrazow(array $jezyki, array $czesci, bool $ponownie): array {
    if (!evk_tl_ai_alt_dostepne()) return [];
    $ids = evk_tl_ai_id_obrazow();
    $tworz = in_array('alt_pl', $czesci, true);
    $out = [];
    $porcje = static function (array $lista): array { return array_chunk($lista, EVK_TL_AI_ALT_PORCJA); };
    $adres = admin_url('upload.php?mode=list');
    $nr = 0;
    if ($tworz) {
        $bez = array_values(array_filter($ids, static function ($id) { return evk_tl_ai_alt_pl($id) === ''; }));
        foreach ($porcje($bez) as $p) {
            $out[] = ['post_id' => $p[0], 'do' => end($p), 'meta_key' => EVK_TL_AI_ALT_PL, 'tytul' => 'Obrazy bez altu ' . ($nr * EVK_TL_AI_ALT_PORCJA + 1) . '–'
                . ($nr * EVK_TL_AI_ALT_PORCJA + count($p)), 'czesc' => 'Alt PL (AI ogląda obraz)', 'adres' => $adres, 'braki' => ['pl' => count($p)], 'ai' => (object) []];
            $nr++;
        }
    }
    if (in_array('alt', $czesci, true)) {
        $kandydaci = array_values(array_filter($ids, static function ($id) use ($tworz) { return $tworz || evk_tl_ai_alt_pl($id) !== ''; }));
        $nr = 0;
        foreach ($porcje($kandydaci) as $p) {
            $braki = [];
            $ai = [];
            foreach ($jezyki as $j) {
                $n = 0;
                $x = 0;
                foreach ($p as $id) {
                    $tl = (string) get_post_meta($id, '_evk_tl_' . $j . '__alt', true);
                    $z = (string) get_post_meta($id, '_evk_tl_' . $j . '__alt__zrodlo', true);
                    if ($tl === '') $n++;
                    elseif ($ponownie && evk_tlw_ai($z)) { $n++; $x++; }
                }
                if ($n) $braki[$j] = $n;
                if ($x) $ai[$j] = $x;
            }
            $nr++;
            if (!$braki) continue;
            $out[] = ['post_id' => $p[0], 'do' => end($p), 'meta_key' => EVK_TL_AI_ALT, 'tytul' => 'Obrazy ' . (($nr - 1) * EVK_TL_AI_ALT_PORCJA + 1) . '–'
                . (($nr - 1) * EVK_TL_AI_ALT_PORCJA + count($p)), 'czesc' => 'Alt', 'adres' => $adres, 'braki' => $braki, 'ai' => (object) $ai];
        }
    }
    return $out;
}

/**
 * Krok „Brakujący alt PL”: do EVK_TL_AI_ALT_NA_KROK obrazów porcji bez altu,
 * każdy osobnym zapytaniem z obrazem. Nieudane idą do `odrzucone` (klucz
 * `{id}|pl`) — przebieg ich nie ponawia.
 *
 * @param list<string>        $pomin
 * @param array<string,mixed> $opcje
 * @return array<string,mixed>
 */
function evk_tl_ai_krok_alt_pl(int $od, int $do, array $pomin, array $opcje): array {
    $u = evk_tl_ai_na_przebieg(evk_tl_ai_ustawienia(), (string) ($opcje['dostawca'] ?? ''), (string) ($opcje['model'] ?? ''));
    $wynik = ['zapisane' => 0, 'z_pamieci' => 0, 'z_ai' => 0, 'bez_zmian' => 0, 'odrzucone' => [], 'pominiete' => [], 'zapisane_klucze' => [], 'zostalo' => 0];
    $czeka = [];
    foreach (evk_tl_ai_id_obrazow() as $id) {
        if ($id < $od || $id > $do || evk_tl_ai_alt_pl($id) !== '' || in_array($id . '|pl', $pomin, true) || !current_user_can('edit_post', $id)) continue;
        $czeka[] = $id;
    }
    foreach (array_slice($czeka, 0, EVK_TL_AI_ALT_NA_KROK) as $id) {
        $r = evk_tl_ai_opisz_obraz($id, $u);
        if (!$r['ok']) {
            if (!empty($r['stop']) || !empty($r['czekaj'])) {
                $wynik['zostalo'] = count($czeka) - $wynik['zapisane'] - count($wynik['odrzucone']);
                return $wynik + ['blad' => (string) $r['blad'], 'czekaj' => (int) ($r['czekaj'] ?? 0), 'stop' => !empty($r['stop'])];
            }
            $wynik['odrzucone'][] = $id . '|pl';
            $wynik['blad'] = (string) $r['blad'];
            continue;
        }
        evk_tl_ai_zapisz_alt_pl($id, (string) $r['tekst']);
        $wynik['zapisane_klucze'][] = $id . '|pl';
        $wynik['zapisane']++;
        $wynik['z_ai']++;
    }
    $wynik['zostalo'] = max(0, count($czeka) - $wynik['zapisane'] - count($wynik['odrzucone']));
    return $wynik;
}

/**
 * Lista „Do sprawdzenia” (52): alty języków z AI albo po zmianie polskiego
 * altu (część `evk_alt`, klucz `alt|{język}`) i polskie alty z AI, jeszcze
 * nieprzejrzane (część `evk_alt_pl`, klucz `alt|pl`). Odnośnik do edycji obrazu.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_alt_do_sprawdzenia(int $limit = 200): array {
    global $wpdb;
    if (!evk_tl_ai_alt_dostepne()) return [];
    $wiersze = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE (meta_key LIKE %s OR meta_key = '_evk_alt_ai') ORDER BY post_id DESC LIMIT %d",
        $wpdb->esc_like('_evk_tl_') . '%' . $wpdb->esc_like('__alt__zrodlo'), $limit * 4));
    $out = [];
    foreach ($wiersze as $w) {
        $id = (int) $w->post_id;
        $pl = evk_tl_ai_alt_pl($id);
        if ($pl === '') continue;
        $wiersz = ['post_id' => $id, 'element' => 'Obraz', 'oryginal' => $pl, 'tytul' => (string) (get_the_title($id) ?: wp_basename((string) get_attached_file($id))),
            'edycja' => (string) get_edit_post_link($id, 'raw')];
        if ((string) $w->meta_key === '_evk_alt_ai') {
            if (!evk_tl_ai_alt_pl_z_ai($id)) continue;
            $out[] = $wiersz + ['meta_key' => EVK_TL_AI_ALT_PL, 'klucz' => 'alt|pl', 'pole' => 'Alt PL (AI)', 'jezyk' => 'pl', 'tlumaczenie' => $pl, 'ai' => true];
        } elseif (preg_match('/^_evk_tl_([a-z0-9_]+?)__alt__zrodlo$/', (string) $w->meta_key, $m)) {
            $tl = (string) get_post_meta($id, '_evk_tl_' . $m[1] . '__alt', true);
            $z = (string) $w->meta_value;
            if ($tl === '') continue;
            $stary = evk_tlw_nieaktualne($tl, $z, $pl);
            if (!$stary && !evk_tlw_ai($z)) continue;
            $out[] = $wiersz + ['meta_key' => EVK_TL_AI_ALT, 'klucz' => 'alt|' . $m[1], 'pole' => 'Alt', 'jezyk' => $m[1], 'tlumaczenie' => $tl, 'ai' => !$stary];
        }
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** „Sprawdzone” dla altu (52): język — źródło bez znacznika; `pl` — zdjęcie znacznika opisu AI. */
function evk_tl_ai_alt_sprawdzone(int $id, string $lang): bool {
    if (!evk_tl_ai_alt_dostepne() || evk_tl_ai_alt_pl($id) === '') return false;
    if ($lang === 'pl') {
        delete_post_meta($id, '_evk_alt_ai');
        return true;
    }
    if ((string) get_post_meta($id, '_evk_tl_' . $lang . '__alt', true) === '') return false;
    update_post_meta($id, '_evk_tl_' . $lang . '__alt__zrodlo', evk_tlw_zrodlo(evk_tl_ai_alt_pl($id)));
    return true;
}

/* ✦ „Opisz obraz (AI)” przy polskim alcie (okno mediów, edycja obrazu): tylko
   opis, bez zapisu — zapisuje WordPress z polem altu. Skrót opisu idzie do
   `_evk_alt_ai`: zapisany bez zmian alt dostaje znacznik „do sprawdzenia”. */
add_action('wp_ajax_evk_tl_ai_opisz', function (): void {
    evk_tl_ajax_check('evk_tl_ai_pola');
    $id = absint($_POST['post_id'] ?? 0);
    if (!$id || !wp_attachment_is_image($id) || !current_user_can('edit_post', $id)) wp_send_json_error('Brak uprawnień do tego obrazu.', 403);
    if (function_exists('set_time_limit')) @set_time_limit(120);
    $r = evk_tl_ai_opisz_obraz($id, evk_tl_ai_ustawienia());
    if (!$r['ok']) wp_send_json_error((string) $r['blad']);
    update_post_meta($id, '_evk_alt_ai', evk_tlw_zrodlo((string) $r['tekst']));
    wp_send_json_success(['tekst' => $r['tekst'], 'model' => evk_tl_ai_podpis(evk_tl_ai_ustawienia())]);
});

/**
 * Dane przycisków ✦ poza edycją jednego wpisu (zakładka SEO, 1.271.0): adres,
 * nonce `evk_tl_ai_pola` i model — dostęp do Tłumaczeń i klucz API. Prawo
 * edycji wpisu sprawdza AJAX przy każdym żądaniu.
 *
 * @return array<string,mixed>|null
 */
function evk_tl_ai_dane_przyciskow(): ?array {
    if (!current_user_can('manage_options') && !current_user_can('evk_access_translations')) return null;
    $u = evk_tl_ai_ustawienia();
    if (evk_tl_ai_klucz($u) === '') return null;
    return ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('evk_tl_ai_pola'), 'post' => 0, 'model' => evk_tl_ai_podpis($u)];
}

// =========================================================================
// AJAX
// =========================================================================

/** Ustawienia (z kluczem API) — tylko administrator. */
add_action('wp_ajax_evk_tl_ai_ustawienia', function (): void {
    check_ajax_referer('evk_tl_ai', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.', 403);
    $u = evk_tl_ai_ustawienia();
    $p = wp_unslash($_POST);
    $d = evk_tl_ai_dostawcy();
    if (isset($d[$p['dostawca'] ?? ''])) $u['dostawca'] = (string) $p['dostawca'];
    $klucz = trim(sanitize_text_field((string) ($p['klucz'] ?? '')));
    if (!empty($p['usun_klucz'])) unset($u['klucze'][$u['dostawca']]);
    elseif ($klucz !== '') $u['klucze'][$u['dostawca']] = $klucz;
    $u['modele'][$u['dostawca']] = trim(sanitize_text_field((string) ($p['model'] ?? '')));
    $u['opis'] = sanitize_textarea_field((string) ($p['opis'] ?? ''));
    $u['slowniczek'] = sanitize_textarea_field((string) ($p['slowniczek'] ?? ''));
    $u['wskazowki'] = [];
    foreach (array_keys(tl_get_languages()) as $j) {
        $u['wskazowki'][(string) $j] = sanitize_textarea_field((string) ($p['wskazowki'][$j] ?? ''));
    }
    update_option(EVK_TL_AI_OPCJA, $u, false);
    wp_send_json_success(['komunikat' => 'Zapisano.', 'klucz' => evk_tl_ai_klucz($u) !== '']);
});

/** Lista części stron z brakami (w trybie ponownym — także z niesprawdzonymi tłumaczeniami AI). */
add_action('wp_ajax_evk_tl_ai_lista', function (): void {
    evk_tl_ajax_check('evk_tl_ai');
    /* Zakres z tabeli „typ × część” (1.271.0). */
    wp_send_json_success(evk_tl_ai_jednostki(($_POST['tryb'] ?? '') === 'ponownie', evk_tl_ai_zakres_z($_POST['zakres'] ?? [])));
});

/** Jeden krok tłumaczenia. */
add_action('wp_ajax_evk_tl_ai_krok', function (): void {
    evk_tl_ajax_check('evk_tl_ai');
    $post_id = absint($_POST['post_id'] ?? 0);
    $meta_key = sanitize_text_field(wp_unslash((string) ($_POST['meta_key'] ?? '')));
    $lang = sanitize_key((string) ($_POST['lang'] ?? ''));
    /* Termy (1.270.0): identyfikator termu w `post_id`, prawo edycji termu.
       Obrazy (1.271.0): porcja od `post_id` do `do`, prawo wgrywania plików
       (każdy obraz — jeszcze prawo jego edycji przy zapisie). */
    $term = $meta_key === EVK_TL_AI_TERM || $meta_key === EVK_TL_AI_POLA_TERMU;
    $obrazy = in_array($meta_key, [EVK_TL_AI_ALT, EVK_TL_AI_ALT_PL], true);
    if ($obrazy && $lang === 'pl' && $meta_key === EVK_TL_AI_ALT_PL) $lang = (string) array_key_first(tl_get_languages());
    $czesc_ok = $term || ($obrazy && evk_tl_ai_alt_dostepne()) || in_array($meta_key, evk_tl_el_klucze_meta(), true) || ($meta_key === EVK_TL_AI_POLA && evk_tl_ai_pola_dostepne())
        || (in_array($meta_key, [EVK_TL_AI_WPIS, EVK_TL_AI_SEO], true) && function_exists('evk_tlw_typy') && in_array((string) get_post_type($post_id), evk_tlw_typy(), true));
    /* Grupa strony ustawień (1.272.0): bez wpisu — klucz grupy w `opcje`, prawo strony ustawień. */
    $kpk = defined('EVK_TL_KP') && $meta_key === EVK_TL_KP;
    $gk = $meta_key === EVK_TL_AI_POLA_OPCJI || $kpk ? sanitize_key(wp_unslash((string) ($_POST['opcje'] ?? ''))) : '';
    if ($gk !== '') {
        if (!isset(tl_get_languages()[$lang])) wp_send_json_error('Nieznana strona albo język.');
        /* Komponent (1.272.0): globalny dla strony — prawo administratora. */
        if ($kpk && (!current_user_can('manage_options') || !evk_tl_kp_komponent($gk))) wp_send_json_error('Brak uprawnień do komponentów.', 403);
        if ($meta_key === EVK_TL_AI_POLA_OPCJI && evk_tl_ai_grupa_opcji($gk) === null) wp_send_json_error('Brak uprawnień do tej strony ustawień.', 403);
        $post_id = 0;
    } elseif (!$post_id || !$czesc_ok || !isset(tl_get_languages()[$lang])) {
        wp_send_json_error('Nieznana strona albo język.');
    }
    if ($gk === '' && ($term ? !evk_tl_ai_term_do_kroku($post_id, $meta_key) : ($obrazy ? !current_user_can('upload_files') : !current_user_can('edit_post', $post_id)))) {
        wp_send_json_error('Brak uprawnień do tej strony.', 403);
    }
    $pomin = array_values(array_filter(array_map('strval', (array) wp_unslash($_POST['pomin'] ?? []))));
    /* Dostawca i model przebiegu (1.262.0): tylko na to żądanie, ustawienia
       bez zmian. Dostawca bez klucza kończy się stopem „Brak klucza API”. */
    $opcje = ['ponownie' => ($_POST['tryb'] ?? '') === 'ponownie', 'dostawca' => sanitize_key((string) ($_POST['dostawca'] ?? '')),
        'model' => (string) wp_unslash($_POST['model'] ?? ''), 'bez_pamieci' => !empty($_POST['bez_pamieci']), 'do' => absint($_POST['do'] ?? 0) ?: $post_id, 'opcje' => $gk]
        /* Części typu tej jednostki z tabeli zakresu (1.271.0). */
        + evk_tl_ai_opcje_czesci(array_values(array_filter(array_map('strval', (array) wp_unslash($_POST['czesci'] ?? [])))));
    if (function_exists('set_time_limit')) @set_time_limit(180);
    wp_send_json_success(evk_tl_ai_krok($post_id, $meta_key, $lang, $pomin, $opcje));
});

/**
 * Wpis i część strony z żądania czyszczenia — jak w kroku: znany klucz
 * części i prawo edycji wpisu (także szablonu nagłówka czy stopki).
 *
 * @return array{0:int,1:string}
 */
function evk_tl_ai_czesc_z_zadania(): array {
    $post_id = absint($_POST['post_id'] ?? 0);
    $meta_key = sanitize_text_field(wp_unslash((string) ($_POST['meta_key'] ?? '')));
    if (!$post_id || !in_array($meta_key, evk_tl_el_klucze_meta(), true)) wp_send_json_error('Nieznana strona.');
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('Brak uprawnień do tej strony.', 403);
    return [$post_id, $meta_key];
}

/** „Wyczyść tłumaczenia strony” (1.264.0): podgląd, a z `wykonaj` — czyszczenie. */
add_action('wp_ajax_evk_tl_ai_czysc', function (): void {
    evk_tl_ajax_check('evk_tl_ai');
    [$post_id, $meta_key] = evk_tl_ai_czesc_z_zadania();
    $jezyki = array_values(array_intersect(array_map('strval', array_keys(tl_get_languages())),
        array_map('strval', (array) wp_unslash($_POST['jezyki'] ?? []))));
    $zakres = ($_POST['zakres'] ?? '') === 'wszystkie' ? 'wszystkie' : 'ai';
    if (empty($_POST['wykonaj'])) wp_send_json_success(evk_tl_ai_podglad_czyszczenia($post_id, $meta_key, $jezyki, $zakres));
    if (!$jezyki) wp_send_json_error('Zaznacz języki.');
    $w = evk_tl_ai_czysc($post_id, $meta_key, $jezyki, $zakres);
    wp_send_json_success($w + evk_tl_ai_podglad_czyszczenia($post_id, $meta_key, $jezyki, $zakres));
});

/** „Przywróć wyczyszczone” (1.264.0). */
add_action('wp_ajax_evk_tl_ai_przywroc', function (): void {
    evk_tl_ajax_check('evk_tl_ai');
    [$post_id, $meta_key] = evk_tl_ai_czesc_z_zadania();
    wp_send_json_success(evk_tl_ai_przywroc($post_id, $meta_key));
});

/**
 * Tłumaczenie z buildera (1.265.0): teksty ze stanu buildera, bez zapisu.
 * Dostęp do Tłumaczeń i prawo edycji strony z buildera (szablonu też); bez
 * strony — prawo edycji w ogóle.
 */
add_action('wp_ajax_evk_tl_ai_builder', function (): void {
    evk_tl_ai_ajax_bez_zapisu('evk_tl_ai_builder', false);
});

/**
 * Pola Evoke FIELDS w metaboksie wpisu (1.267.0): to samo co builder — tylko
 * tłumaczy, zapis zostaje w formularzu wpisu. Wpis obowiązkowy (prawo edycji).
 */
add_action('wp_ajax_evk_tl_ai_pola', function (): void {
    evk_tl_ai_ajax_bez_zapisu('evk_tl_ai_pola', true);
});

/**
 * Tłumaczenie bez zapisu (builder, pola Fields): nonce, dostęp, prawo edycji
 * wpisu — albo strony ustawień Fields (`strona`, 1.268.0) — język, wejście.
 */
function evk_tl_ai_ajax_bez_zapisu(string $nonce, bool $wymagaj_wpisu): void {
    evk_tl_ajax_check($nonce);
    $post_id = absint($_POST['post_id'] ?? 0);
    $slug = $wymagaj_wpisu ? sanitize_key(wp_unslash((string) ($_POST['strona'] ?? ''))) : '';
    $term_id = $wymagaj_wpisu ? absint($_POST['term_id'] ?? 0) : 0;
    $tytul = '';
    if ($term_id) {
        /* Edycja termu (1.270.0): prawo edycji termu, tytuł kontekstu — nazwa termu. */
        $term = get_term($term_id);
        if (!($term instanceof WP_Term) || !current_user_can('edit_term', $term_id)) wp_send_json_error('Brak uprawnień do tego termu.', 403);
        $post_id = 0;
        $tytul = $term->name;
    } elseif ($slug !== '') {
        $strona = evk_tl_ai_strona_ustawien($slug);
        if ($strona === null) wp_send_json_error('Brak uprawnień do tej strony ustawień.', 403);
        $post_id = 0;
        $tytul = $strona['nazwa'];
    } else {
        if ($wymagaj_wpisu && !$post_id) wp_send_json_error('Brak wpisu.', 400);
        if (!($post_id ? current_user_can('edit_post', $post_id) : current_user_can('edit_posts'))) {
            wp_send_json_error('Brak uprawnień do tej strony.', 403);
        }
    }
    $lang = sanitize_key((string) ($_POST['lang'] ?? ''));
    if (!isset(tl_get_languages()[$lang])) wp_send_json_error('Nieznany język.');
    $json = static function (string $pole) {
        $v = $_POST[$pole] ?? null;
        return is_string($v) ? json_decode(wp_unslash($v), true) : null;
    };
    $we = evk_tl_ai_builder_wejscie($json('kontekst'), $json('teksty'));
    if (is_string($we)) wp_send_json_error($we);
    if (function_exists('set_time_limit')) @set_time_limit(180);
    $w = evk_tl_ai_builder($post_id, $lang, $we[0], $we[1], $tytul);
    $w['tlumaczenia'] = (object) $w['tlumaczenia'];
    $w['zrodla'] = (object) $w['zrodla'];
    wp_send_json_success($w);
}
