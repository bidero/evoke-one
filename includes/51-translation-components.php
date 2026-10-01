<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — komponenty Bricksa a tłumaczenia (1.272.0).
 *
 * Próby na testowej (Bricks 2.4.2, docs/proby-komponenty.md):
 *  - komponent żyje w opcji Bricksa: `{id, elements, properties}`, właściwość
 *    to `{label, type, id, connections: {idElementu: [klucz ustawienia]}}`;
 *  - instancja na stronie to element z `cid` i `properties: {idWłaściwości: wartość}`;
 *  - Bricks wpisuje wartość właściwości instancji w połączone ustawienie
 *    PRZED naszym filtrem renderu (51) — właściwość połączona z
 *    `evk_tl_en__text` działa na `/en/` bez dodatkowego haka;
 *  - połączone ustawienie bez wartości w instancji jest PUSTE (instancja C).
 *    Dlatego tłumaczenie z komponentu („Tłumaczenie EN” w jego edycji) pasuje
 *    tylko do tekstów stałych, a tekst z właściwości tłumaczy się w instancji.
 *
 * Co tu jest:
 *  - każda tekstowa właściwość połączona z polem tłumaczonym (mapa pól, 51)
 *    dostaje bliźniaczą „{etykieta} EN” połączoną z `evk_tl_en__{klucz}` tych
 *    samych elementów, tuż pod sobą — przy każdym zapisie komponentów,
 *    po zmianie listy języków i raz po aktualizacji wtyczki (builder robi
 *    to samo na żywo, tl-builder-podglad.js). Identyfikator bliźniaka jest
 *    stały (FNV-1a z komponentu, właściwości i języka), więc zapis z buildera
 *    bez bliźniaków nie gubi wartości wpisanych w instancjach. Istniejące
 *    połączenie z tym samym kluczem (ręczna właściwość „Tłumaczenie”) jest
 *    uznawane — drugiej nie dodajemy;
 *  - instancja w treści strony: teksty jej właściwości jako miejsca
 *    `{id}|prop:{idWłaściwości}|{język}` — hurt AI, lista „Do sprawdzenia”
 *    i lista tekstów widzą je obok zwykłych pól elementów;
 *  - teksty stałe komponentów (pola bez właściwości) — hurt i „Do sprawdzenia”
 *    z opcji komponentów, stan w `evk_tl_kp_stan`.
 */

/** Typy właściwości, które dostają bliźniaka — tekstowe (próba: `text`). */
const EVK_TL_KP_TYPY = ['text'];
/** Część hurtu i „Do sprawdzenia”: teksty stałe komponentu. */
const EVK_TL_KP = 'evk_komponent';
/** Stan „Do sprawdzenia” tekstów stałych: id komponentu → stan miejsc (jak EVK_TL_EL_STAN). */
const EVK_TL_KP_STAN = 'evk_tl_kp_stan';
/** Wersja przejścia po wszystkich komponentach po aktualizacji wtyczki. */
const EVK_TL_KP_WERSJA = 1;

/** Nazwa opcji komponentów Bricksa. Stała Bricksa — po załadowaniu motywu. */
function evk_tl_kp_opcja(): string {
    return defined('BRICKS_DB_COMPONENTS') ? (string) BRICKS_DB_COMPONENTS : 'bricks_components';
}

/** @return list<array<string,mixed>> */
function evk_tl_kp_komponenty(): array {
    $k = get_option(evk_tl_kp_opcja(), []);
    return is_array($k) ? array_values(array_filter($k, 'is_array')) : [];
}

/** Stały identyfikator bliźniaka: 6 małych liter z FNV-1a 32 bit — jak w tl-builder-podglad.js. */
function evk_tl_kp_id(string $komponent, string $wlasciwosc, string $lang): string {
    $h = 0x811c9dc5;
    $s = $komponent . '|' . $wlasciwosc . '|' . $lang;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $h ^= ord($s[$i]);
        $h = ($h * 0x01000193) & 0xffffffff;
    }
    $id = '';
    for ($i = 0; $i < 6; $i++) {
        $id .= chr(97 + $h % 26);
        $h = intdiv($h, 26);
    }
    return $id;
}

/** Nazwa komponentu: etykieta elementu-korzenia, inaczej id. */
function evk_tl_kp_nazwa(array $komp): string {
    foreach ((array) ($komp['elements'] ?? []) as $e) {
        if (is_array($e) && (string) ($e['id'] ?? '') === (string) ($komp['id'] ?? '') && is_string($e['label'] ?? null) && trim($e['label']) !== '') return trim($e['label']);
    }
    return (string) ($komp['id'] ?? '');
}

/**
 * Połączenia właściwości z polami tłumaczonymi: id elementu → klucze z mapy pól
 * (51) typu tego elementu. Klucze języków i pola spoza mapy odpadają.
 *
 * @param array<string,string>              $typy id elementu → nazwa typu
 * @param array<string,array<string,mixed>> $mapa
 * @return array<string,list<string>>
 */
function evk_tl_kp_tlumaczone(array $wl, array $typy, array $mapa): array {
    $out = [];
    foreach ((array) ($wl['connections'] ?? []) as $el => $klucze) {
        $pola = (array) ($mapa[$typy[(string) $el] ?? '']['pola'] ?? []);
        foreach ((array) $klucze as $k) {
            $k = (string) $k;
            if (strncmp($k, 'evk_tl_', 7) !== 0 && in_array($k, $pola, true)) $out[(string) $el][] = $k;
        }
    }
    return $out;
}

/**
 * Język bliźniaka: wszystkie połączenia to `evk_tl_{język}__…` jednego języka.
 * Zwraca [język, połączenia bez przedrostka] albo null.
 *
 * @return array{0:string,1:array<string,list<string>>}|null
 */
function evk_tl_kp_jako_blizniak(array $wl): ?array {
    $lang = null;
    $pl = [];
    foreach ((array) ($wl['connections'] ?? []) as $el => $klucze) {
        foreach ((array) $klucze as $k) {
            if (!preg_match('/^evk_tl_([a-z0-9_]+?)__(.+)$/', (string) $k, $m)) return null;
            if ($lang !== null && $lang !== $m[1]) return null;
            $lang = $m[1];
            $pl[(string) $el][] = $m[2];
        }
    }
    return $lang === null ? null : [$lang, $pl];
}

/**
 * Czy mapa pól zna typy wszystkich elementów, z którymi łączy się właściwość.
 * Element, którego w komponencie już nie ma, nie przeszkadza — jego połączenie
 * i tak nic nie znaczy.
 *
 * @param array<string,string>              $typy
 * @param array<string,array<string,mixed>> $mapa
 */
function evk_tl_kp_typy_znane(array $wl, array $typy, array $mapa): bool {
    foreach (array_keys((array) ($wl['connections'] ?? [])) as $el) {
        if (isset($typy[(string) $el]) && !isset($mapa[$typy[(string) $el]])) return false;
    }
    return true;
}

/** Te same połączenia bez względu na kolejność. */
function evk_tl_kp_rowne(array $a, array $b): bool {
    $n = static function (array $c): array {
        $o = [];
        foreach ($c as $el => $k) { $k = array_map('strval', (array) $k); sort($k); $o[(string) $el] = $k; }
        ksort($o);
        return $o;
    };
    return $n($a) === $n($b);
}

/**
 * Pary właściwości jednego komponentu: id właściwości PL → etykieta, połączenia
 * tłumaczone i bliźniaki (język → id). Bliźniaki rozpoznane po połączeniach,
 * więc także ręczne.
 *
 * @return array<string,array{etykieta:string,polaczenia:array<string,list<string>>,blizniaki:array<string,string>}>
 */
function evk_tl_kp_pary(array $komp, array $mapa): array {
    $typy = [];
    foreach ((array) ($komp['elements'] ?? []) as $e) if (is_array($e)) $typy[(string) ($e['id'] ?? '')] = (string) ($e['name'] ?? '');
    $props = array_values(array_filter((array) ($komp['properties'] ?? []), 'is_array'));
    /** @var array<string,array{etykieta:string,polaczenia:array<string,list<string>>,blizniaki:array<string,string>}> $out */
    $out = [];
    foreach ($props as $p) {
        if (!in_array((string) ($p['type'] ?? ''), EVK_TL_KP_TYPY, true) || !is_scalar($p['id'] ?? null)) continue;
        $tl = evk_tl_kp_tlumaczone($p, $typy, $mapa);
        if ($tl) $out[(string) $p['id']] = ['etykieta' => (string) ($p['label'] ?? ''), 'polaczenia' => $tl, 'blizniaki' => []];
    }
    foreach ($props as $t) {
        $b = evk_tl_kp_jako_blizniak($t);
        if (!$b || !is_scalar($t['id'] ?? null)) continue;
        foreach ($out as $pid => $para) {
            if (!isset($para['blizniaki'][$b[0]]) && evk_tl_kp_rowne($para['polaczenia'], $b[1])) { $out[$pid]['blizniaki'][$b[0]] = (string) $t['id']; break; }
        }
    }
    return $out;
}

/**
 * Komponenty z właściwościami tłumaczeń (czysta funkcja — test bez Bricksa).
 * Brakujący bliźniak powstaje tuż pod właściwością PL; nasz bliźniak (stały
 * id) dostaje bieżące połączenia i etykietę; bliźniak bez właściwości PL
 * z etykietą „… KOD” znika. Bliźniaki języków spoza listy zostają pod swoją
 * właściwością (wyłączony język nie kasuje tłumaczeń wpisanych w instancjach).
 *
 * @param list<string>                      $jezyki
 * @param array<string,array<string,mixed>> $mapa
 * @return array<int|string,mixed>
 */
function evk_tl_kp_uzupelnij(array $komponenty, array $jezyki, array $mapa): array {
    foreach ($komponenty as $ki => $komp) {
        if (!is_array($komp) || !is_array($komp['properties'] ?? null)) continue;
        $cid = (string) ($komp['id'] ?? '');
        $typy = [];
        $zajete = [];
        foreach ((array) ($komp['elements'] ?? []) as $e) {
            if (!is_array($e)) continue;
            $typy[(string) ($e['id'] ?? '')] = (string) ($e['name'] ?? '');
            $zajete[(string) ($e['id'] ?? '')] = true;
        }
        $props = array_values(array_filter($komp['properties'], 'is_array'));
        foreach ($props as $p) if (is_scalar($p['id'] ?? null)) $zajete[(string) $p['id']] = true;
        $po_id = [];
        foreach ($props as $i => $p) if (is_scalar($p['id'] ?? null)) $po_id[(string) $p['id']] = $i;
        $blizniaki = [];   // id PL → [id bliźniaka, …] w kolejności języków
        $uzyte = [];       // id bliźniaków przypiętych do właściwości PL
        foreach ($props as $p) {
            if (!in_array((string) ($p['type'] ?? ''), EVK_TL_KP_TYPY, true) || !is_scalar($p['id'] ?? null)) continue;
            $tl = evk_tl_kp_tlumaczone($p, $typy, $mapa);
            if (!$tl) continue;
            $pid = (string) $p['id'];
            foreach ($jezyki as $lang) {
                $lang = (string) $lang;
                $kod = (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($lang));
                $chce = [];
                foreach ($tl as $el => $klucze) foreach ($klucze as $k) $chce[$el][] = evk_tl_el_klucz($lang, $k);
                $etykieta = trim((string) ($p['label'] ?? '')) . ' ' . strtoupper($kod);
                $nasz = evk_tl_kp_id($cid, $pid, $kod);
                if (isset($po_id[$nasz])) {
                    $props[$po_id[$nasz]]['connections'] = $chce;
                    $props[$po_id[$nasz]]['label'] = $etykieta;
                    $blizniaki[$pid][] = $nasz;
                    $uzyte[$nasz] = true;
                    continue;
                }
                $reczny = null;
                foreach ($props as $t) {
                    if (is_scalar($t['id'] ?? null) && !isset($uzyte[(string) $t['id']]) && evk_tl_kp_rowne((array) ($t['connections'] ?? []), $chce)) { $reczny = (string) $t['id']; break; }
                }
                if ($reczny !== null) { $blizniaki[$pid][] = $reczny; $uzyte[$reczny] = true; continue; }
                /* Kolizja z istniejącym id (niemal niemożliwa) — sól, nadal powtarzalnie. */
                for ($s = 1; isset($zajete[$nasz]); $s++) $nasz = evk_tl_kp_id($cid, $pid . '#' . $s, $kod);
                $zajete[$nasz] = true;
                $props[] = ['label' => $etykieta, 'type' => (string) $p['type'], 'id' => $nasz, 'connections' => $chce];
                $po_id[$nasz] = count($props) - 1;
                $blizniaki[$pid][] = $nasz;
                $uzyte[$nasz] = true;
            }
        }
        /* Bliźniak wyłączonego języka zostaje pod swoją właściwością — wpisane
           w instancjach tłumaczenia nie giną, wrócą z językiem. */
        $pl_tl = [];
        foreach ($props as $p) {
            if (in_array((string) ($p['type'] ?? ''), EVK_TL_KP_TYPY, true) && is_scalar($p['id'] ?? null) && ($tl = evk_tl_kp_tlumaczone($p, $typy, $mapa))) $pl_tl[(string) $p['id']] = $tl;
        }
        foreach ($props as $t) {
            $tid = is_scalar($t['id'] ?? null) ? (string) $t['id'] : '';
            $b = $tid !== '' && !isset($uzyte[$tid]) ? evk_tl_kp_jako_blizniak($t) : null;
            if (!$b) continue;
            foreach ($pl_tl as $pid => $tl) {
                if (evk_tl_kp_rowne($tl, $b[1])) { $blizniaki[$pid][] = $tid; $uzyte[$tid] = true; break; }
            }
        }
        /* Nowa kolejność: każda właściwość PL, pod nią jej bliźniaki. Osierocony
           bliźniak z naszą etykietą („… EN”) — bez właściwości PL nic nie znaczy.
           Ale tylko wtedy, gdy mapa pól zna typy jego elementów: przy pustej albo
           niepełnej mapie właściwość PL też nie wygląda na tłumaczoną, a skasowany
           bliźniak zabrałby tłumaczenia wpisane w instancjach (1.274.0, prawdziwy
           Bricks: przejście z pustą mapą usuwało ręczny „Nagłówek EN”). */
        $nowe = [];
        foreach ($props as $p) {
            $id = is_scalar($p['id'] ?? null) ? (string) $p['id'] : '';
            if ($id !== '' && isset($uzyte[$id])) continue;
            if (evk_tl_kp_jako_blizniak($p) && preg_match('/ [A-Z0-9_]{2,}$/', (string) ($p['label'] ?? ''))
                && evk_tl_kp_typy_znane($p, $typy, $mapa)) continue;
            $nowe[] = $p;
            foreach ($blizniaki[$id] ?? [] as $b) $nowe[] = $props[$po_id[$b]];
        }
        if ($nowe !== array_values(array_filter($komp['properties'], 'is_array'))) $komponenty[$ki]['properties'] = $nowe;
    }
    return $komponenty;
}

/** Kody języków z wartości opcji `tl_languages` (bez polskiego) — tl_get_languages() trzyma swoją pamięć. @return list<string> */
function evk_tl_kp_kody($jezyki): array {
    $out = [];
    foreach ((array) $jezyki as $j) {
        $k = is_array($j) ? trim((string) ($j['code'] ?? '')) : '';
        if ($k !== '' && $k !== 'pl') $out[] = $k;
    }
    return $out;
}

/* Każdy zapis komponentów (builder, import) — z bliźniakami. Stała Bricksa jest po załadowaniu motywu. */
add_action('after_setup_theme', static function (): void {
    $o = evk_tl_kp_opcja();
    add_filter('pre_update_option_' . $o, 'evk_tl_kp_przed_zapisem', 10, 2);
    add_action('update_option_' . $o, 'evk_tl_kp_po_zapisie', 10, 2);
    add_action('add_option_' . $o, static function ($nazwa, $wartosc): void { evk_tl_kp_po_zapisie([], $wartosc); }, 10, 2);
}, 20);

/** @param mixed $nowe */
function evk_tl_kp_przed_zapisem($nowe, $stare = null) {
    if (!is_array($nowe) || !empty($GLOBALS['evk_tl_kp_bez_uzupelnienia'])) return $nowe;
    $jezyki = $GLOBALS['evk_tl_kp_jezyki'] ?? evk_tl_kody_jezykow();
    return evk_tl_kp_uzupelnij($nowe, array_map('strval', (array) $jezyki), evk_tl_el_mapa());
}

/** Przejście po wszystkich komponentach (zmiana języków, aktualizacja wtyczki). @param list<string>|null $jezyki */
function evk_tl_kp_przejscie(?array $jezyki = null): bool {
    $k = get_option(evk_tl_kp_opcja(), null);
    if (!is_array($k)) return false;
    if ($jezyki !== null) $GLOBALS['evk_tl_kp_jezyki'] = $jezyki;
    try {
        return update_option(evk_tl_kp_opcja(), $k);
    } finally {
        unset($GLOBALS['evk_tl_kp_jezyki']);
    }
}

/* Nowy język: właściwości „… DE” we wszystkich komponentach od razu, bez otwierania buildera. */
add_action('update_option_tl_languages', static function ($stare, $nowe): void { evk_tl_kp_przejscie(evk_tl_kp_kody($nowe)); }, 10, 2);
add_action('add_option_tl_languages', static function ($nazwa, $nowe): void { evk_tl_kp_przejscie(evk_tl_kp_kody($nowe)); }, 10, 2);

/* Raz po aktualizacji: istniejące komponenty dostają bliźniaki — instancje
   przestają pokazywać tłumaczenie tekstu komponentu przy własnym tekście. */
add_action('admin_init', static function (): void {
    if ((int) get_option('evk_tl_kp_przejscie', 0) >= EVK_TL_KP_WERSJA) return;
    /* Bez mapy pól (świeża instalacja, przed pierwszym renderem) przejście nic
       by nie rozpoznało, a zostałoby odhaczone — czeka na mapę. */
    if (!evk_tl_el_mapa()) return;
    update_option('evk_tl_kp_przejscie', EVK_TL_KP_WERSJA, false);
    evk_tl_kp_przejscie();
});

// =========================================================================
// INSTANCJE W TREŚCI STRONY
// =========================================================================

/**
 * Pary wszystkich komponentów: id komponentu → {nazwa, pary}. Pamięć na
 * żądanie, liczona od nowa po zmianie opcji albo mapy.
 *
 * @return array<string,array{nazwa:string,pary:array<string,array<string,mixed>>}>
 */
function evk_tl_kp_wszystkie_pary(): array {
    static $pamiec = ['klucz' => '', 'wynik' => []];
    $komponenty = evk_tl_kp_komponenty();
    $mapa = evk_tl_el_mapa();
    $klucz = md5(serialize([$komponenty, $mapa]));
    if ($pamiec['klucz'] === $klucz) return $pamiec['wynik'];
    $out = [];
    foreach ($komponenty as $k) {
        $p = evk_tl_kp_pary($k, $mapa);
        if ($p) $out[(string) ($k['id'] ?? '')] = ['nazwa' => evk_tl_kp_nazwa($k), 'pary' => $p];
    }
    $pamiec = ['klucz' => $klucz, 'wynik' => $out];
    return $out;
}

/**
 * Instancja jako wirtualne ustawienia: `prop:{id PL}` → tekst z instancji,
 * `evk_tl_{język}__prop:{id PL}` → wartość bliźniaka. Tylko właściwości
 * z bliźniakiem w danym języku (bez języka — wszystkie). Null: to nie
 * instancja albo komponent bez par.
 *
 * @param array<string,mixed> $el
 * @return array{nazwa:string,ustawienia:array<string,string>,pola:array<string,string>}|null
 */
function evk_tl_kp_instancja(array $el, ?string $lang = null): ?array {
    $cid = is_scalar($el['cid'] ?? null) ? (string) $el['cid'] : '';
    $k = $cid !== '' ? (evk_tl_kp_wszystkie_pary()[$cid] ?? null) : null;
    if (!$k) return null;
    $wart = is_array($el['properties'] ?? null) ? $el['properties'] : [];
    $ust = [];
    $pola = [];
    foreach ($k['pary'] as $pid => $para) {
        $bl = $lang === null ? $para['blizniaki'] : array_intersect_key($para['blizniaki'], [preg_replace('/[^a-z0-9_]/', '_', strtolower($lang)) => true]);
        if (!$bl) continue;
        $pole = 'prop:' . $pid;
        $ust[$pole] = is_string($wart[$pid] ?? null) ? $wart[$pid] : '';
        foreach ($bl as $kod => $tid) {
            if (is_string($wart[$tid] ?? null)) $ust[evk_tl_el_klucz((string) $kod, $pole)] = $wart[$tid];
        }
        $pola[$pole] = $para['etykieta'] !== '' ? $para['etykieta'] : $pid;
    }
    return $pola ? ['nazwa' => 'Komponent ' . $k['nazwa'], 'ustawienia' => $ust, 'pola' => $pola] : null;
}

/** Id bliźniaka właściwości PL w języku — do zapisu w instancji. */
function evk_tl_kp_blizniak(string $cid, string $pid, string $lang): string {
    $kod = (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($lang));
    return (string) (evk_tl_kp_wszystkie_pary()[$cid]['pary'][$pid]['blizniaki'][$kod] ?? '');
}

/**
 * Miejsca tłumaczeń instancji (dla stanu „Do sprawdzenia”, 52): klucz
 * `{id}|prop:{id PL}|{język}` → oryginał, tłumaczenie, opis.
 *
 * @param array<string,mixed>                $el
 * @param array<string,array<string,string>> $out
 */
function evk_tl_kp_miejsca_instancji(array $el, array &$out): void {
    $v = evk_tl_kp_instancja($el);
    if (!$v) return;
    $id = (string) ($el['id'] ?? '');
    foreach ($v['ustawienia'] as $k => $t) {
        if (!preg_match('/^evk_tl_([a-z0-9_]+?)__(prop:.+)$/', (string) $k, $m)) continue;
        $out[$id . '|' . $m[2] . '|' . $m[1]] = ['element' => $v['nazwa'], 'pole' => $v['pola'][$m[2]] ?? $m[2], 'jezyk' => $m[1],
            'oryginal' => $v['ustawienia'][$m[2]] ?? '', 'tlumaczenie' => (string) $t];
    }
}

/**
 * Zapis tłumaczenia właściwości w instancji (z evk_tl_el_wpisz_dane, 53):
 * wartość bliźniaka w `properties`. Null — bez bliźniaka w tym języku.
 *
 * @param array<string,mixed> $el
 */
function evk_tl_kp_wpisz_instancji(array &$el, string $pid, string $lang, string $tekst, bool $tylko_puste, bool $nadpisz): ?bool {
    $tid = evk_tl_kp_blizniak((string) ($el['cid'] ?? ''), $pid, $lang);
    if ($tid === '') return null;
    if (!is_array($el['properties'] ?? null)) $el['properties'] = [];
    $bylo = $el['properties'][$tid] ?? null;
    if ($tylko_puste && !$nadpisz && evk_tl_el_niepuste($bylo)) return false;
    if ($tekst === '') {
        if (!array_key_exists($tid, $el['properties'])) return false;
        unset($el['properties'][$tid]);
        return true;
    }
    if ($bylo === $tekst) return false;
    $el['properties'][$tid] = $tekst;
    return true;
}

// =========================================================================
// TEKSTY STAŁE KOMPONENTÓW (opcja komponentów)
// =========================================================================

/** Komponent po id. @return array<string,mixed>|null */
function evk_tl_kp_komponent(string $cid): ?array {
    foreach (evk_tl_kp_komponenty() as $k) if ((string) ($k['id'] ?? '') === $cid) return $k;
    return null;
}

/**
 * Elementy komponentu bez pól połączonych z właściwościami — teksty stałe.
 * Pole z właściwością tłumaczy się w instancji, więc tu odpada.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_kp_elementy_stale(array $komp): array {
    $pol = [];
    foreach ((array) ($komp['properties'] ?? []) as $p) {
        foreach ((array) (is_array($p) ? ($p['connections'] ?? []) : []) as $el => $klucze) foreach ((array) $klucze as $k) $pol[(string) $el][(string) $k] = true;
    }
    $out = [];
    foreach ((array) ($komp['elements'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $id = (string) ($e['id'] ?? '');
        if (isset($pol[$id]) && is_array($e['settings'] ?? null)) {
            foreach (array_keys($pol[$id]) as $k) {
                unset($e['settings'][$k]);
                if (strncmp($k, 'evk_tl_', 7) !== 0) foreach (array_keys($e['settings']) as $s) if (preg_match('/^evk_tl_[a-z0-9_]+?__' . preg_quote($k, '/') . '$/', (string) $s)) unset($e['settings'][$s]);
            }
        }
        $out[] = $e;
    }
    return $out;
}

/** Stan „Do sprawdzenia” tekstów stałych jednego komponentu. @return array<string,mixed> */
function evk_tl_kp_stan(string $cid): array {
    $s = get_option(EVK_TL_KP_STAN, []);
    return is_array($s) && is_array($s[$cid] ?? null) ? $s[$cid] : [];
}

/** Po zapisie komponentów: stan miejsc jak dla treści strony (52). @param mixed $nowe */
function evk_tl_kp_po_zapisie($stare, $nowe): void {
    if (!is_array($nowe) || !function_exists('evk_tl_el_nowy_stan')) return;
    $stan = get_option(EVK_TL_KP_STAN, []);
    $stan = is_array($stan) ? $stan : [];
    $po = [];
    foreach ($nowe as $k) {
        if (!is_array($k)) continue;
        $cid = (string) ($k['id'] ?? '');
        $czesc = evk_tl_el_nowy_stan(is_array($stan[$cid] ?? null) ? $stan[$cid] : [], evk_tl_el_miejsca(evk_tl_kp_elementy_stale($k)));
        if ($czesc) $po[$cid] = $czesc;
    }
    if ($po !== $stan) update_option(EVK_TL_KP_STAN, $po, false);
}

/**
 * Zapis tłumaczeń tekstów stałych do opcji komponentów (hurt AI). Klucze
 * miejsc jak w treści strony (`{id}|{ścieżka}|{język}`).
 *
 * @param array<string,string> $zmiany
 * @param array<string,mixed>  $nadpisz
 * @return list<string>
 */
function evk_tl_kp_zapisz_pola(string $cid, string $lang, array $zmiany, bool $tylko_puste, array $nadpisz = []): array {
    $wszystkie = get_option(evk_tl_kp_opcja(), []);
    if (!is_array($wszystkie)) return [];
    foreach ($wszystkie as $i => $k) {
        if (!is_array($k) || (string) ($k['id'] ?? '') !== $cid || !is_array($k['elements'] ?? null)) continue;
        $wykaz = [];
        $elementy = $k['elements'];
        $zmienione = evk_tl_el_wpisz_dane($elementy, $lang, $zmiany, $tylko_puste, $nadpisz, $wykaz);
        if (!$zmienione) return [];
        $wszystkie[$i]['elements'] = $elementy;
        update_option(evk_tl_kp_opcja(), $wszystkie);
        return $zmienione;
    }
    return [];
}

/** Komponenty z tekstami stałymi do tłumaczenia — wiersz tabeli zakresu, tylko z prawem administratora. */
function evk_tl_kp_dostepne(): bool {
    return current_user_can('manage_options') && (bool) evk_tl_kp_komponenty();
}

/**
 * „Do sprawdzenia” tekstów stałych: AI albo zmieniony oryginał. `post_id` 0,
 * klucz `{komponent}#{miejsce}`, wiersz niesie tytuł; edycja w builderze.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_kp_do_sprawdzenia(int $limit = 200): array {
    $out = [];
    if (!current_user_can('manage_options')) return $out;
    foreach (evk_tl_kp_komponenty() as $k) {
        $cid = (string) ($k['id'] ?? '');
        $stan = evk_tl_kp_stan($cid);
        if (!$stan) continue;
        $miejsca = evk_tl_el_miejsca(evk_tl_kp_elementy_stale($k));
        foreach ($stan as $klucz => $s) {
            $m = $miejsca[$klucz] ?? null;
            if (!$m || !is_array($s) || evk_tl_el_skrot($m['oryginal']) === ($s['src'] ?? '') || !evk_tl_el_niepuste($m['tlumaczenie'])) continue;
            $out[] = $m + ['post_id' => 0, 'meta_key' => EVK_TL_KP, 'klucz' => $cid . '#' . $klucz, 'ai' => ($s['src'] ?? '') === 'ai',
                'tytul' => 'Komponent: ' . evk_tl_kp_nazwa($k), 'edycja' => ''];
            if (count($out) >= $limit) return $out;
        }
    }
    return $out;
}

/** „Sprawdzone” tekstu stałego: bieżący oryginał staje się źródłem. */
function evk_tl_kp_sprawdzone(string $klucz): bool {
    $h = strpos($klucz, '#');
    if ($h === false) return false;
    $cid = substr($klucz, 0, $h);
    $miejsce = substr($klucz, $h + 1);
    $k = evk_tl_kp_komponent($cid);
    $stan = get_option(EVK_TL_KP_STAN, []);
    if (!$k || !is_array($stan) || !isset($stan[$cid][$miejsce])) return false;
    $m = evk_tl_el_miejsca(evk_tl_kp_elementy_stale($k))[$miejsce] ?? null;
    if (!$m) return false;
    $stan[$cid][$miejsce]['src'] = evk_tl_el_skrot($m['oryginal']);
    update_option(EVK_TL_KP_STAN, $stan, false);
    return true;
}
