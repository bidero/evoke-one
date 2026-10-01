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
 * @return array{0:string,1:array<string,string>,2:array<string,mixed>}
 */
function evk_tl_ai_zadanie(array $u, string $system, string $wiadomosc): array {
    $model = evk_tl_ai_model($u);
    $klucz = evk_tl_ai_klucz($u);
    $adres = (string) (evk_tl_ai_adresy()[$u['dostawca']] ?? '');
    $schemat = evk_tl_ai_schemat();
    if ($u['dostawca'] === 'claude') {
        $naglowki = ['Content-Type' => 'application/json', 'x-api-key' => $klucz, 'anthropic-version' => '2023-06-01'];
        $cialo = ['model' => $model, 'max_tokens' => 16000, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $wiadomosc]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schemat]]];
        if (in_array($model, ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-1'], true)) {
            $naglowki['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $cialo['fallbacks'] = 'default';
        }
        if ($model === 'claude-opus-5-5') $cialo['output_config']['effort'] = 'medium';
        return [$adres, $naglowki, $cialo];
    }
    if ($u['dostawca'] === 'openai') {
        return [$adres, ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $klucz], [
            'model' => $model, 'instructions' => $system, 'input' => $wiadomosc,
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'translations', 'strict' => true, 'schema' => $schemat]],
        ]];
    }
    return [sprintf($adres, rawurlencode($model)), ['Content-Type' => 'application/json', 'x-goog-api-key' => $klucz], [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $wiadomosc]]]],
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
function evk_tl_ai_wyslij(array $u, string $system, string $wiadomosc): array {
    if (evk_tl_ai_klucz($u) === '') return ['ok' => false, 'blad' => 'Brak klucza API tego dostawcy — wpisz go w ustawieniach Tłumaczenia AI.', 'czekaj' => 0, 'stop' => true];
    [$adres, $naglowki, $cialo] = evk_tl_ai_zadanie($u, $system, $wiadomosc);
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
    $u = evk_tl_ai_na_przebieg(evk_tl_ai_ustawienia(), (string) ($opcje['dostawca'] ?? ''), (string) ($opcje['model'] ?? ''));
    $pola = $meta_key === EVK_TL_AI_POLA;
    $wpis = $meta_key === EVK_TL_AI_WPIS;
    $t = $pola ? evk_tl_ai_teksty_pol($post_id, $lang, !empty($opcje['ponownie']))
        : ($wpis ? evk_tl_ai_teksty_wpisu($post_id, $lang, (array) ($opcje['wpis_pola'] ?? []), !empty($opcje['ponownie']))
        : evk_tl_ai_teksty(get_post_meta($post_id, $meta_key, true), $lang, empty($opcje['ponownie']) ? null : evk_tl_ai_stan_czesci($post_id, $meta_key)));
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
        [$system, $wiadomosc] = evk_tl_ai_tresc($u, $lang, (string) (get_the_title($post_id) ?: ('#' . $post_id)), $t['kontekst'], $tresc);
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
    $wynik['zapisane_klucze'] = $pola ? evk_tl_ai_zapisz_pola($post_id, $lang, $gotowe, $ai, $braki)
        : ($wpis ? evk_tl_ai_zapisz_wpis($post_id, $lang, $gotowe, $ai, $braki)
        : evk_tl_ai_zapisz($post_id, $meta_key, $lang, $gotowe, $ai, evk_tl_ai_podpis($u), $bylo));
    $wynik['zapisane'] = count($wynik['zapisane_klucze']);
    $wynik['bez_zmian'] = count($rowne);
    $wynik['pominiete'] = array_keys($rowne);
    $wynik['zostalo'] = count($reszta) - count($porcja);
    return $przerwa ? $wynik + $przerwa : $wynik;
}

/**
 * Części stron z tekstami bez tłumaczenia: liczba braków w każdym języku.
 * W trybie ponownym (1.262.0) liczą się też niesprawdzone tłumaczenia AI —
 * `ai` mówi, ile ich jest wśród braków.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki(bool $ponownie = false, bool $pola = false, array $wpis_pola = [], bool $adres = false): array {
    $jezyki = array_map('strval', evk_tl_kody_jezykow());
    $out = [];
    foreach (evk_tl_el_wpisy_bricksa() as [$post_id, $meta_key]) {
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
        $out[] = ['post_id' => $post_id, 'meta_key' => $meta_key, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => evk_tl_el_czesc($meta_key), 'adres' => evk_tl_el_adres_edycji($post_id), 'braki' => $braki, 'ai' => (object) $ai];
    }
    /* Pola Evoke FIELDS (1.267.0) i teksty wpisów (1.268.0) — tylko po zaznaczeniu pól wyboru. */
    if ($pola) $out = array_merge($out, evk_tl_ai_jednostki_pol($jezyki, $ponownie));
    if ($wpis_pola || $adres) $out = array_merge($out, evk_tl_ai_jednostki_wpisow($jezyki, $wpis_pola, $adres, $ponownie));
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

/** @param mixed $raw @return list<string> */
function evk_tl_ai_pola_wpisu_z($raw): array {
    return array_values(array_intersect(EVK_TL_AI_POLA_WPISU, array_map('strval', (array) wp_unslash($raw))));
}

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
function evk_tl_ai_jednostki_wpisow(array $jezyki, array $pola, bool $adres, bool $ponownie): array {
    if (!function_exists('evk_tlw_typy') || !($typy = evk_tlw_typy())) return [];
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
function evk_tl_ai_teksty_pol(int $post_id, string $lang, bool $ponownie = false): array {
    $out = ['kontekst' => [], 'braki' => []];
    if (!evk_tl_ai_pola_dostepne()) return $out;
    foreach (evk_fields_tl_teksty($post_id) as $m) {
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
    if ($out['braki']) {
        /* Słownictwo reszty strony: teksty treści Bricksa z obecnymi tłumaczeniami. */
        $tresc = evk_tl_ai_teksty(get_post_meta($post_id, evk_tl_el_klucze_meta()[0], true), $lang)['kontekst'];
        $out['kontekst'] = array_merge($out['kontekst'], $tresc);
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
function evk_tl_ai_zapisz_pola(int $post_id, string $lang, array $gotowe, array $ai, array $braki): array {
    $out = [];
    foreach ($gotowe as $k => $tl) {
        $miejsce = (string) ($braki[$k]['sciezka'] ?? '');
        if ($miejsce !== '' && evk_fields_tl_wpisz($post_id, $miejsce, $lang, (string) $tl, !empty($ai[$k]))) $out[] = (string) $k;
    }
    return $out;
}

/**
 * Wpisy z polami Fields z brakami (lista hurtu): część `evk_fields`.
 *
 * @param list<string> $jezyki
 * @return list<array<string,mixed>>
 */
function evk_tl_ai_jednostki_pol(array $jezyki, bool $ponownie): array {
    if (!evk_tl_ai_pola_dostepne() || !($typy = evk_fields_tl_typy())) return [];
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
    if ($strona === null && (!$post_id || !current_user_can('edit_post', $post_id))) return null;
    if (!current_user_can('manage_options') && !current_user_can('evk_access_translations')) return null;
    $u = evk_tl_ai_ustawienia();
    if (evk_tl_ai_klucz($u) === '') return null;
    return ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('evk_tl_ai_pola'), 'post' => $post_id,
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
    wp_send_json_success(evk_tl_ai_jednostki(($_POST['tryb'] ?? '') === 'ponownie', !empty($_POST['pola']) && evk_tl_ai_pola_dostepne(),
        evk_tl_ai_pola_wpisu_z($_POST['wpis_pola'] ?? []), !empty($_POST['adres'])));
});

/** Jeden krok tłumaczenia. */
add_action('wp_ajax_evk_tl_ai_krok', function (): void {
    evk_tl_ajax_check('evk_tl_ai');
    $post_id = absint($_POST['post_id'] ?? 0);
    $meta_key = sanitize_text_field(wp_unslash((string) ($_POST['meta_key'] ?? '')));
    $lang = sanitize_key((string) ($_POST['lang'] ?? ''));
    $czesc_ok = in_array($meta_key, evk_tl_el_klucze_meta(), true) || ($meta_key === EVK_TL_AI_POLA && evk_tl_ai_pola_dostepne())
        || ($meta_key === EVK_TL_AI_WPIS && function_exists('evk_tlw_typy') && in_array((string) get_post_type($post_id), evk_tlw_typy(), true));
    if (!$post_id || !$czesc_ok || !isset(tl_get_languages()[$lang])) {
        wp_send_json_error('Nieznana strona albo język.');
    }
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('Brak uprawnień do tej strony.', 403);
    $pomin = array_values(array_filter(array_map('strval', (array) wp_unslash($_POST['pomin'] ?? []))));
    /* Dostawca i model przebiegu (1.262.0): tylko na to żądanie, ustawienia
       bez zmian. Dostawca bez klucza kończy się stopem „Brak klucza API”. */
    $opcje = ['ponownie' => ($_POST['tryb'] ?? '') === 'ponownie', 'dostawca' => sanitize_key((string) ($_POST['dostawca'] ?? '')),
        'model' => (string) wp_unslash($_POST['model'] ?? ''), 'bez_pamieci' => !empty($_POST['bez_pamieci']),
        'wpis_pola' => evk_tl_ai_pola_wpisu_z($_POST['wpis_pola'] ?? []), 'adres' => !empty($_POST['adres'])];
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
    $tytul = '';
    if ($slug !== '') {
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
