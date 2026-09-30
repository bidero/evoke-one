<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — tłumaczenia ze słownika w polach języków elementów (1.244.0).
 *
 * DECYZJA ZGŁASZAJĄCEGO: pole „Tłumaczenie EN" w elemencie pokazuje to, co
 * widać na stronie. Do 1.243.0 pole było puste, a stronę tłumaczył słownik
 * (zakładka Tłumaczenia) — w builderze wyglądało to na brak tłumaczenia,
 * a przy zmianie polskiego tekstu tłumaczenie ze słownika i tak ginęło.
 *
 * DWIE DROGI, jedna funkcja (`evk_tl_el_uzupelnij()`):
 *   - przy każdym zapisie danych Bricksa (treść strony, nagłówek, stopka):
 *     puste pole języka, a polski tekst jest w całości frazą słownika →
 *     pole dostaje tłumaczenie ze słownika;
 *   - przycisk „Przenieś" w zakładce Teksty w elementach: to samo dla
 *     wszystkich stron naraz, z podglądem — dla stron zapisanych wcześniej.
 *
 * PRZENIESIENIE ZE STAREGO ZAPISU. Builder nie wie o polach, które serwer
 * dopisał po jego otwarciu, i następnym zapisem wysyła stan bez nich. Bez
 * tej reguły zmiana polskiego tekstu w tej samej sesji skasowałaby
 * tłumaczenie — dokładnie to, przed czym pola w elementach mają chronić.
 *
 * WYKAZ DOPISANYCH (1.246.0). Próba z 1.243.0 pokazała, że Bricks przy
 * czyszczeniu pola USUWA klucz — wyczyszczone tłumaczenie wygląda więc tak
 * samo jak pole nieznane builderowi. Do 1.245.0 wracało. Teraz wraca tylko
 * pole z wykazu `_evk_tl_el_dopisane`: dopisane przez serwer (słownik,
 * przycisk, poprzednie przeniesienie) i jeszcze nieprzysłane przez builder.
 * Pole przysłane przez builder wypada z wykazu, a otwarcie wpisu w builderze
 * (przez kogoś, kto może go edytować) czyści wykaz — od tej chwili brak pola
 * znaczy „wyczyszczone". „Do sprawdzenia" (52) oznacza przeniesione pole,
 * gdy oryginał się zmienił. Granica: dwie karty buildera z tym samym wpisem.
 * Otwarcie drugiej czyści wykaz, więc zapis z pierwszej, otwartej przed
 * przeniesieniem, zgubi przeniesione pola — jak każdy zapis ze starej karty
 * gubi zmiany z nowej.
 *
 * REWIZJE pomijamy. update_post_meta() i delete_post_meta() z identyfikatorem
 * rewizji WordPress przekierowuje na rodzica, więc wykaz policzony z kopii
 * w rewizji nadpisałby wykaz strony. Rewizja zostaje, jak przyszła; przy
 * przywróceniu dane idą na stronę i przechodzą przez ten sam filtr.
 *
 * ZAPIS BUILDERA idzie przez update_post_meta — potwierdzone na stronie
 * testowej (1.244.0: „Do sprawdzenia" z 52 działa, a stoi na tym samym haku).
 * Tu tego nie sprawdzimy, bo Bricksa tu nie ma. Przycisk od tego nie zależy:
 * zapisuje sam.
 */

/**
 * Klucz słownika dla polskiego tekstu pola i opakowanie tłumaczenia.
 * Tekst bez znaczników albo jeden akapit `<p>…</p>` (tak zapisuje pole
 * edytora). Tekst złożony z kilku fraz albo ze znacznikami w środku — null:
 * zostaje słownikowi, który tłumaczy go na stronie węzeł po węźle.
 *
 * @return array{0:string,1:string,2:string}|null [klucz, przed, po]
 */
function evk_tl_el_klucz_slownika(string $pl): ?array {
    $przed = '';
    $po = '';
    $tekst = $pl;
    if (preg_match('/^\s*<p>(.*)<\/p>\s*$/su', $pl, $m) && strpos($m[1], '<') === false) {
        $tekst = $m[1];
        $przed = '<p>';
        $po = '</p>';
    } elseif (strpos($pl, '<') !== false) {
        return null;
    }
    $klucz = mb_strtolower(tl_normalize_text_for_match($tekst));
    return $klucz === '' ? null : [$klucz, $przed, $po];
}

/**
 * Jeden poziom ustawień (element albo pozycja listy): najpierw pola języków
 * ze starego zapisu, których nowy nie ma wcale, potem puste pola języków ze
 * słownika. Zwraca liczbę zmian.
 *
 * @param array<string,mixed>      $ust     Ustawienia — zmieniane w miejscu.
 * @param array<string,mixed>|null $stare   Te same ustawienia ze starego zapisu.
 * @param string[]                 $pola    Pola tłumaczalne tego poziomu (z mapy).
 * @param array<string,array<string,array<string,mixed>>> $slownik Język → indeks tl_get_match_index().
 * @param string                   $miejsce Przedrostek klucza w wykazie: „id|" albo „id|lista.pozycja.".
 * @param array{przed:array<string,bool>,po:array<string,bool>} $dopisane Wykaz przed zapisem i po nim.
 */
function evk_tl_el_uzupelnij_poziom(array &$ust, ?array $stare, array $pola, array $slownik, string $miejsce, array &$dopisane): int {
    $zmiany = 0;
    foreach ($stare ?? [] as $k => $v) {
        $k = (string) $k;
        if (array_key_exists($k, $ust) || !isset($dopisane['przed'][$miejsce . $k])) continue;
        if (!preg_match('/^evk_tl_[a-z0-9_]+?__.+$/', $k) || !evk_tl_el_niepuste($v)) continue;
        $ust[$k] = $v;
        $dopisane['po'][$miejsce . $k] = true;
        $zmiany++;
    }
    foreach ($pola as $pole) {
        $pl = $ust[$pole] ?? null;
        if (!is_string($pl) || trim($pl) === '') continue;
        $klucz = null;
        foreach ($slownik as $jezyk => $indeks) {
            $bliz = evk_tl_el_klucz((string) $jezyk, (string) $pole);
            if (evk_tl_el_niepuste($ust[$bliz] ?? null)) continue;
            $klucz = $klucz ?? (evk_tl_el_klucz_slownika($pl) ?? false);
            if ($klucz === false) break;
            $tlum = $indeks[$klucz[0]]['tlum'] ?? '';
            if (!is_string($tlum) || trim($tlum) === '') continue;
            $ust[$bliz] = $klucz[1] . $tlum . $klucz[2];
            $dopisane['po'][$miejsce . $bliz] = true;
            $zmiany++;
        }
    }
    return $zmiany;
}

/**
 * Uzupełnia dane Bricksa (lista elementów): pola języków ze starego zapisu
 * i ze słownika. Czysta funkcja — mapa i słownik przychodzą z zewnątrz.
 *
 * @param mixed $nowe   Dane do zapisu.
 * @param mixed $stare  Dane zapisane dotąd (null: bez przenoszenia ze starego).
 * @param array<string,array<string,mixed>> $mapa     Z evk_tl_el_mapa().
 * @param array<string,array<string,array<string,mixed>>> $slownik Z evk_tl_el_slownik().
 * @param array<string,bool> $wykaz Pola dopisane przez serwer i nieznane builderowi (przed zapisem).
 * @return array{elementy:mixed,zmiany:int,nieznane:array<string,int>,dopisane:array<string,bool>}
 */
function evk_tl_el_uzupelnij($nowe, $stare, array $mapa, array $slownik, array $wykaz = []): array {
    $wynik = ['elementy' => $nowe, 'zmiany' => 0, 'nieznane' => [], 'dopisane' => []];
    if (!is_array($nowe)) return $wynik;
    $dopisane = ['przed' => $wykaz, 'po' => []];
    $po_id = [];
    foreach (is_array($stare) ? $stare : [] as $el) {
        if (is_array($el) && isset($el['id']) && is_scalar($el['id']) && is_array($el['settings'] ?? null)) $po_id[(string) $el['id']] = $el;
    }
    foreach ($nowe as $i => $el) {
        if (!is_array($el) || !is_array($el['settings'] ?? null)) continue;
        $nazwa = (string) ($el['name'] ?? '');
        $stary = isset($el['id']) && is_scalar($el['id']) ? ($po_id[(string) $el['id']] ?? null) : null;
        if ($stary !== null && (string) ($stary['name'] ?? '') !== $nazwa) $stary = null;
        $def = $mapa[$nazwa] ?? null;
        if (!is_array($def) && $nazwa !== '') $wynik['nieznane'][$nazwa] = ($wynik['nieznane'][$nazwa] ?? 0) + 1;

        $ust = $el['settings'];
        $id = isset($el['id']) && is_scalar($el['id']) ? (string) $el['id'] : '';
        $zmiany = evk_tl_el_uzupelnij_poziom($ust, $stary['settings'] ?? null, (array) ($def['pola'] ?? []), $slownik, $id . '|', $dopisane);
        // Pozycje list po identyfikatorze pozycji — kolejność w builderze się zmienia.
        $listy = (array) ($def['listy'] ?? []);
        foreach ($ust as $k => $v) {
            if (!is_array($v) || !$v || array_keys($v) !== range(0, count($v) - 1)) continue;
            $stare_poz = [];
            foreach ((array) ($stary['settings'][$k] ?? []) as $sp) {
                if (is_array($sp) && isset($sp['id']) && is_scalar($sp['id'])) $stare_poz[(string) $sp['id']] = $sp;
            }
            foreach ($v as $j => $poz) {
                if (!is_array($poz)) continue;
                $pid = isset($poz['id']) && is_scalar($poz['id']) ? (string) $poz['id'] : null;
                $sp = $pid !== null ? ($stare_poz[$pid] ?? null) : null;
                $zm = evk_tl_el_uzupelnij_poziom($poz, $sp, (array) ($listy[$k] ?? []), $slownik,
                    $id . '|' . $k . '.' . ($pid ?? (string) $j) . '.', $dopisane);
                if ($zm) {
                    $ust[$k][$j] = $poz;
                    $zmiany += $zm;
                }
            }
        }
        if ($zmiany) {
            $wynik['elementy'][$i]['settings'] = $ust;
            $wynik['zmiany'] += $zmiany;
        }
    }
    ksort($dopisane['po']);
    $wynik['dopisane'] = $dopisane['po'];
    return $wynik;
}

/**
 * Słownik dla języków strony: język → indeks `tl_get_match_index()`
 * (znormalizowana fraza PL → tłumaczenie). Języki bez żadnej frazy pomija.
 *
 * @return array<string,array<string,array<string,mixed>>>
 */
function evk_tl_el_slownik(): array {
    $out = [];
    foreach (evk_tl_kody_jezykow() as $j) {
        $indeks = tl_get_match_index((string) $j);
        if ($indeks) $out[(string) $j] = $indeks;
    }
    return $out;
}

// =========================================================================
// ZAPIS DANYCH BRICKSA — uzupełnione dane idą do bazy zamiast wysłanych
// =========================================================================

/**
 * `update_post_metadata` / `add_post_metadata` dostają wartość już po
 * `wp_unslash()`, więc ponowny zapis idzie przez `wp_slash()`. Ponowny zapis
 * przechodzi przez ten sam filtr — stąd blokada. Stan „Do sprawdzenia" (52)
 * liczy się przy nim z uzupełnionej wartości.
 *
 * @param mixed $sprawdz Wynik wcześniejszego filtra (null = zapisuj normalnie).
 * @param mixed $wartosc
 * @param mixed $piaty   $prev_value (update) albo $unique (add).
 * @return mixed
 */
function evk_tl_el_przed_zapisem($sprawdz, $post_id, $meta_key, $wartosc, $piaty = '') {
    static $w_trakcie = false;
    if ($sprawdz !== null || $w_trakcie || !empty($GLOBALS['evk_tl_el_zapis_przycisku']) || !is_array($wartosc)) return $sprawdz;
    if (!in_array((string) $meta_key, evk_tl_el_klucze_meta(), true)) return $sprawdz;
    if (wp_is_post_revision((int) $post_id)) return $sprawdz;
    $przed = evk_tl_el_dopisane((int) $post_id, (string) $meta_key);
    $stare = get_post_meta((int) $post_id, (string) $meta_key, true);
    $wynik = evk_tl_el_uzupelnij($wartosc, $stare, evk_tl_el_mapa(), evk_tl_el_slownik(), $przed);
    if ($wynik['dopisane'] !== $przed) evk_tl_el_zapisz_dopisane((int) $post_id, (string) $meta_key, $wynik['dopisane']);
    if (!$wynik['zmiany']) return $sprawdz;
    $w_trakcie = true;
    try {
        return current_filter() === 'add_post_metadata'
            ? add_metadata('post', (int) $post_id, (string) $meta_key, wp_slash($wynik['elementy']), (bool) $piaty)
            : update_metadata('post', (int) $post_id, (string) $meta_key, wp_slash($wynik['elementy']), $piaty);
    } finally {
        $w_trakcie = false;
    }
}
add_filter('update_post_metadata', 'evk_tl_el_przed_zapisem', 10, 5);
add_filter('add_post_metadata', 'evk_tl_el_przed_zapisem', 10, 5);

/** Metadane wpisu: pola języków dopisane przez serwer, których builder jeszcze nie przysłał. */
const EVK_TL_EL_DOPISANE = '_evk_tl_el_dopisane';

/** @return array<string,bool> Wykaz dla jednego klucza metadanych (treść, nagłówek, stopka). */
function evk_tl_el_dopisane(int $post_id, string $meta_key): array {
    $wykaz = get_post_meta($post_id, EVK_TL_EL_DOPISANE, true);
    return is_array($wykaz) && is_array($wykaz[$meta_key] ?? null) ? $wykaz[$meta_key] : [];
}

/** @param array<string,bool> $dopisane */
function evk_tl_el_zapisz_dopisane(int $post_id, string $meta_key, array $dopisane): void {
    $wykaz = get_post_meta($post_id, EVK_TL_EL_DOPISANE, true);
    $wykaz = is_array($wykaz) ? $wykaz : [];
    if ($dopisane) $wykaz[$meta_key] = $dopisane; else unset($wykaz[$meta_key]);
    if ($wykaz) update_post_meta($post_id, EVK_TL_EL_DOPISANE, $wykaz);
    else delete_post_meta($post_id, EVK_TL_EL_DOPISANE);
}

/**
 * Otwarcie wpisu w builderze: builder wczytuje dane razem z polami
 * dopisanymi przez serwer, więc od tej chwili je zna — wykaz znika.
 */
function evk_tl_el_builder_otwarty(int $post_id): void {
    if ($post_id > 0) delete_post_meta($post_id, EVK_TL_EL_DOPISANE);
}

/**
 * Tylko u kogoś, kto może wpis edytować: evk_w_builderze() patrzy na sam
 * adres, a „?bricks=run" dopisze do adresu każdy gość.
 */
function evk_tl_el_otwarcie_buildera(): void {
    if (!evk_w_builderze() || !is_singular()) return;
    $post_id = (int) get_queried_object_id();
    if ($post_id > 0 && current_user_can('edit_post', $post_id)) evk_tl_el_builder_otwarty($post_id);
}
add_action('template_redirect', 'evk_tl_el_otwarcie_buildera', 1);

// =========================================================================
// PRZYCISK „PRZENIEŚ" — wszystkie strony naraz
// =========================================================================

/**
 * Wpisy z danymi Bricksa: [post_id, meta_key]. Wprost po kluczu metadanych
 * (jak „Do sprawdzenia") — szablony nagłówka i stopki bywają typem spoza
 * wyszukiwania. Bez wersji, kosza i szkiców automatycznych.
 *
 * @return list<array{0:int,1:string}>
 */
function evk_tl_el_wpisy_bricksa(): array {
    global $wpdb;
    $klucze = evk_tl_el_klucze_meta();
    $wiersze = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT pm.post_id, pm.meta_key FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key IN (%s, %s, %s) AND p.post_type <> 'revision' AND p.post_status NOT IN ('trash', 'auto-draft')
         ORDER BY pm.post_id, pm.meta_key", $klucze[0], $klucze[1], $klucze[2]), ARRAY_A);
    $out = [];
    foreach ($wiersze as $w) {
        if (is_array($w)) $out[] = [(int) $w['post_id'], (string) $w['meta_key']];
    }
    return $out;
}

/**
 * Przeniesienie ze słownika na wszystkich stronach: podgląd (bez zapisu)
 * albo zapis. Strony ze zmianami, suma i typy elementów spoza mapy.
 *
 * @return array{strony:list<array<string,mixed>>,razem:int,nieznane:array<string,int>,znane:int}
 */
function evk_tl_el_przenies(bool $zapisz): array {
    $mapa = evk_tl_el_mapa();
    $slownik = evk_tl_el_slownik();
    $wynik = ['strony' => [], 'razem' => 0, 'nieznane' => [], 'znane' => count($mapa)];
    foreach (evk_tl_el_wpisy_bricksa() as [$post_id, $meta_key]) {
        $dane = get_post_meta($post_id, $meta_key, true);
        if (!is_array($dane)) continue;
        $u = evk_tl_el_uzupelnij($dane, null, $mapa, $slownik);
        foreach ($u['nieznane'] as $n => $ile) $wynik['nieznane'][$n] = ($wynik['nieznane'][$n] ?? 0) + $ile;
        if (!$u['zmiany']) continue;
        if ($zapisz) {
            /* Otwarty builder nie zna tych pól — do wykazu, żeby jego następny
               zapis ich nie zgubił. Zapis bez haka: ten wziąłby pola przysłane
               tutaj za „znane builderowi" i wyczyścił wykaz. */
            $wykaz = evk_tl_el_dopisane($post_id, $meta_key) + $u['dopisane'];
            ksort($wykaz);
            evk_tl_el_zapisz_dopisane($post_id, $meta_key, $wykaz);
            $GLOBALS['evk_tl_el_zapis_przycisku'] = true;
            try {
                update_post_meta($post_id, $meta_key, wp_slash($u['elementy']));
            } finally {
                $GLOBALS['evk_tl_el_zapis_przycisku'] = false;
            }
        }
        $wynik['strony'][] = ['post_id' => $post_id, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => evk_tl_el_czesc($meta_key), 'zmiany' => $u['zmiany'], 'adres' => evk_tl_el_adres_edycji($post_id)];
        $wynik['razem'] += $u['zmiany'];
    }
    ksort($wynik['nieznane']);
    return $wynik;
}

/**
 * Zapis pól języka w elementach jednej części strony (1.261.0) — wspólny dla
 * hurtu AI (61) i poprawek z trybu sprawdzania na stronie (62).
 *
 * `$zmiany`: klucz miejsca jak w stanie „Do sprawdzenia" („id|ścieżka|język",
 * ścieżka „pole" albo „lista.pozycja.pole") → tekst; pusty tekst usuwa
 * tłumaczenie. `$tylko_puste` (hurt AI) nie rusza wypełnionych pól.
 *
 * Jak przycisk „Przenieś": pole, którego jeszcze nie było, idzie do wykazu
 * dopisanych (otwarty builder go nie zgubi), a zapis omija hak z tego pliku,
 * który wziąłby dopisane pola za przysłane przez builder. Poprawka pola,
 * które builder już zna, do wykazu nie idzie — builder otwarty sprzed
 * poprawki i tak nadpisze je swoją wartością przy zapisie.
 *
 * `$nadpisz` (1.262.0): klucze, które wolno nadpisać mimo `$tylko_puste` —
 * ponowne tłumaczenie AI. Że to wciąż niesprawdzone tłumaczenie AI, pilnuje
 * wołający (61) po stanie „Do sprawdzenia” tuż przed zapisem.
 *
 * @param array<string,string> $zmiany
 * @param array<string,mixed>  $nadpisz
 * @return list<string> Klucze, które naprawdę się zmieniły.
 */
function evk_tl_el_zapisz_pola(int $post_id, string $meta_key, string $lang, array $zmiany, bool $tylko_puste, array $nadpisz = []): array {
    $dane = get_post_meta($post_id, $meta_key, true);
    if (!is_array($dane) || !$zmiany) return [];
    $kod = (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($lang));
    $wykaz = evk_tl_el_dopisane($post_id, $meta_key);
    $zmienione = [];
    foreach ($zmiany as $klucz => $tekst) {
        $cz = explode('|', (string) $klucz);
        if (count($cz) !== 3 || $cz[2] !== $kod) continue;
        $sciezka = explode('.', $cz[1]);
        if (count($sciezka) !== 1 && count($sciezka) !== 3) continue;
        $pole = (string) end($sciezka);
        $bliz = evk_tl_el_klucz($lang, $pole);
        foreach ($dane as $i => $el) {
            if (!is_array($el) || (string) ($el['id'] ?? '') !== $cz[0] || !is_array($el['settings'] ?? null)) continue;
            if (count($sciezka) === 1) {
                $ust = &$dane[$i]['settings'];
                $miejsce = $cz[0] . '|';
            } else {
                $ust = null;
                foreach ((array) ($el['settings'][$sciezka[0]] ?? []) as $j => $poz) {
                    $pid = is_array($poz) && isset($poz['id']) && is_scalar($poz['id']) && (string) $poz['id'] !== '' ? (string) $poz['id'] : (string) $j;
                    if ($pid === $sciezka[1] && is_array($poz)) {
                        $ust = &$dane[$i]['settings'][$sciezka[0]][$j];
                        break;
                    }
                }
                if ($ust === null) break;
                $miejsce = $cz[0] . '|' . $sciezka[0] . '.' . $sciezka[1] . '.';
            }
            $bylo = $ust[$bliz] ?? null;
            if ($tylko_puste && !isset($nadpisz[$klucz]) && evk_tl_el_niepuste($bylo)) { unset($ust); break; }
            if ($tekst === '') {
                if (array_key_exists($bliz, $ust)) {
                    unset($ust[$bliz], $wykaz[$miejsce . $bliz]);
                    $zmienione[] = (string) $klucz;
                }
            } elseif ($bylo !== $tekst) {
                if (!evk_tl_el_niepuste($bylo)) $wykaz[$miejsce . $bliz] = true;
                $ust[$bliz] = $tekst;
                $zmienione[] = (string) $klucz;
            }
            unset($ust);
            break;
        }
    }
    if (!$zmienione) return [];
    ksort($wykaz);
    evk_tl_el_zapisz_dopisane($post_id, $meta_key, $wykaz);
    $GLOBALS['evk_tl_el_zapis_przycisku'] = true;
    try {
        update_post_meta($post_id, $meta_key, wp_slash($dane));
    } finally {
        $GLOBALS['evk_tl_el_zapis_przycisku'] = false;
    }
    return $zmienione;
}

/** Która część strony: treść, nagłówek albo stopka. */
function evk_tl_el_czesc(string $meta_key): string {
    $k = evk_tl_el_klucze_meta();
    return $meta_key === $k[1] ? 'Nagłówek' : ($meta_key === $k[2] ? 'Stopka' : 'Treść');
}

add_action('wp_ajax_evk_tl_el_przenies', function (): void {
    evk_tl_ajax_check();
    wp_send_json_success(evk_tl_el_przenies(($_POST['tryb'] ?? '') === 'zapisz'));
});

// =========================================================================
// WIDOK „TEKSTY W ELEMENTACH" (1.245.0) — tylko do odczytu
// =========================================================================

/**
 * Tekst przetłumaczony tak, jak zrobi to słownik na stronie: węzeł tekstu po
 * węźle, dokładna równość po normalizacji (jak tl_tokenize_content()).
 * Zwraca [tekst, pochodzenie]: 'slownik' (każdy węzeł), 'czesc' (niektóre),
 * 'brak' (żaden).
 *
 * @param array<string,array<string,mixed>> $indeks Z tl_get_match_index().
 * @return array{0:string,1:string}
 */
function evk_tl_el_ze_slownika(string $pl, array $indeks): array {
    $wezly = 0;
    $trafione = 0;
    $wynik = (string) preg_replace_callback('/>([^<]+)</u', static function ($m) use ($indeks, &$wezly, &$trafione) {
        $k = mb_strtolower(tl_normalize_text_for_match($m[1]));
        if ($k === '') return $m[0];
        $wezly++;
        $tlum = $indeks[$k]['tlum'] ?? null;
        if (!is_string($tlum) || $tlum === '') return $m[0];
        $trafione++;
        return '>' . $tlum . '<';
    }, '>' . $pl . '<');
    if (!$trafione) return ['', 'brak'];
    return [substr($wynik, 1, -1), $trafione === $wezly ? 'slownik' : 'czesc'];
}

/**
 * Wszystkie teksty elementów Bricksa: pola z mapy (z polskim tekstem), dla
 * każdego języka tekst i pochodzenie — pole w elemencie, słownik, część
 * słownikiem albo brak — oraz „do sprawdzenia" (52).
 *
 * @return array{wiersze:list<array<string,mixed>>,liczby:array<string,int>,nieznane:array<string,int>,znane:int}
 */
function evk_tl_el_teksty(): array {
    $mapa = evk_tl_el_mapa();
    $jezyki = array_map('strval', evk_tl_kody_jezykow());
    $indeksy = [];
    foreach ($jezyki as $j) $indeksy[$j] = tl_get_match_index($j);
    $sprawdz = [];
    foreach (evk_tl_el_do_sprawdzenia(1000) as $m) $sprawdz[$m['post_id'] . '|' . $m['meta_key'] . '|' . $m['klucz']] = true;

    $wynik = ['wiersze' => [], 'liczby' => ['wszystko' => 0, 'braki' => 0, 'sprawdz' => 0], 'nieznane' => [], 'znane' => count($mapa)];
    $dodaj = static function (array $ust, string $pole, array $baza) use ($jezyki, $indeksy, $sprawdz, &$wynik): void {
        $pl = $ust[$pole] ?? null;
        if (!is_string($pl) || trim(wp_strip_all_tags($pl)) === '') return;
        $w = $baza + ['pl' => $pl, 'jezyki' => [], 'braki' => false, 'sprawdz' => false];
        foreach ($jezyki as $j) {
            $bliz = $ust[evk_tl_el_klucz($j, $pole)] ?? null;
            if (evk_tl_el_niepuste($bliz)) {
                $l = ['tekst' => (string) $bliz, 'zrodlo' => 'pole'];
            } else {
                [$tekst, $zrodlo] = evk_tl_el_ze_slownika($pl, $indeksy[$j]);
                $l = ['tekst' => $tekst, 'zrodlo' => $zrodlo];
            }
            $l['sprawdz'] = isset($sprawdz[$w['post_id'] . '|' . $w['meta_key'] . '|' . $w['id'] . '|' . $w['sciezka'] . '|' . preg_replace('/[^a-z0-9_]/', '_', strtolower($j))]);
            if ($l['zrodlo'] === 'brak' || $l['zrodlo'] === 'czesc') $w['braki'] = true;
            if ($l['sprawdz']) $w['sprawdz'] = true;
            $w['jezyki'][$j] = $l;
        }
        $wynik['wiersze'][] = $w;
        $wynik['liczby']['wszystko']++;
        if ($w['braki']) $wynik['liczby']['braki']++;
        if ($w['sprawdz']) $wynik['liczby']['sprawdz']++;
    };

    foreach (evk_tl_el_wpisy_bricksa() as [$post_id, $meta_key]) {
        $dane = get_post_meta($post_id, $meta_key, true);
        if (!is_array($dane)) continue;
        $strona = ['post_id' => $post_id, 'meta_key' => $meta_key, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => evk_tl_el_czesc($meta_key), 'adres' => evk_tl_el_adres_edycji($post_id)];
        foreach ($dane as $el) {
            if (!is_array($el) || !is_array($el['settings'] ?? null)) continue;
            $nazwa = (string) ($el['name'] ?? '');
            $def = $mapa[$nazwa] ?? null;
            if (!is_array($def)) {
                if ($nazwa !== '') $wynik['nieznane'][$nazwa] = ($wynik['nieznane'][$nazwa] ?? 0) + 1;
                continue;
            }
            $id = (string) ($el['id'] ?? '');
            foreach ((array) ($def['pola'] ?? []) as $pole) {
                $dodaj($el['settings'], (string) $pole, $strona + ['element' => $nazwa, 'id' => $id, 'sciezka' => (string) $pole,
                    'opis' => evk_tl_el_nazwa_pola((string) $pole)]);
            }
            foreach ((array) ($def['listy'] ?? []) as $lista => $pola) {
                $pozycje = $el['settings'][$lista] ?? null;
                if (!is_array($pozycje) || !$pozycje || array_keys($pozycje) !== range(0, count($pozycje) - 1)) continue;
                foreach ($pozycje as $i => $poz) {
                    if (!is_array($poz)) continue;
                    $pid = isset($poz['id']) && is_scalar($poz['id']) && (string) $poz['id'] !== '' ? (string) $poz['id'] : (string) $i;
                    foreach ((array) $pola as $pole) {
                        $dodaj($poz, (string) $pole, $strona + ['element' => $nazwa, 'id' => $id,
                            'sciezka' => $lista . '.' . $pid . '.' . $pole, 'opis' => 'pozycja ' . ($i + 1) . ' · ' . evk_tl_el_nazwa_pola((string) $pole)]);
                    }
                }
            }
        }
    }
    ksort($wynik['nieznane']);
    return $wynik;
}

/**
 * Tekst do komórki tabeli: bez znaczników, najwyżej 80 znaków. Koniec bloku
 * i `<br>` dają odstęp — samo wp_strip_all_tags() skleja dwa akapity
 * w „…świecieNieznany…".
 */
function evk_tl_el_skrot_tekstu(string $t): string {
    $t = (string) preg_replace('~</(p|div|li|h[1-6]|blockquote)>|<br\s*/?>~i', '$0 ', $t);
    $t = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($t)));
    return mb_strlen($t) > 80 ? mb_substr($t, 0, 79) . '…' : $t;
}

/**
 * Lista „Teksty w elementach": filtr (wszystko / braki / sprawdz), strona
 * listy i adres zakładki, do którego doklejane są parametry.
 */
function evk_tl_el_widok_tekstow(string $pokaz, int $str, string $baza, int $na_strone = 50): void {
    $d = evk_tl_el_teksty();
    $filtry = ['wszystko' => 'Wszystkie', 'braki' => 'Bez tłumaczenia', 'sprawdz' => 'Do sprawdzenia'];
    if (!isset($filtry[$pokaz])) $pokaz = 'wszystko';
    $wiersze = array_values(array_filter($d['wiersze'], static function ($w) use ($pokaz) {
        return $pokaz === 'wszystko' || ($pokaz === 'braki' ? $w['braki'] : $w['sprawdz']);
    }));
    $stron = max(1, (int) ceil(count($wiersze) / max(1, $na_strone)));
    $str = min(max(1, $str), $stron);
    $jezyki = array_map('strval', evk_tl_kody_jezykow());
    $adres = static function (array $q) use ($baza): string {
        return (string) add_query_arg($q + ['tab' => 'elementy'], $baza);
    };
    $pochodzenie = ['pole' => 'w elemencie', 'slownik' => 'ze słownika', 'czesc' => 'słownik: część tekstu', 'brak' => 'brak tłumaczenia'];
    ?>
    <div class="evo-box tl-teksty">
        <h3>Teksty w elementach</h3>
        <p class="evo-desc">Teksty elementów Bricksa ze wszystkich stron i szablonów, z tłumaczeniem w każdym języku.
        „W elemencie" to pole „Tłumaczenie" w builderze. „Ze słownika" pochodzi z zakładki EVOKE Tłumaczenia —
        przycisk „Przenieś" wyżej przepisze je do elementów. Tłumaczenia poprawiasz w Bricksie.</p>
        <?php if (!$d['znane']): ?>
            <p class="evo-desc">Wtyczka nie zna jeszcze pól elementów Bricksa. Otwórz dowolną stronę w builderze i wróć tutaj.</p>
        <?php else: ?>
            <nav class="tl-teksty-filtry" aria-label="Filtr tekstów">
                <?php foreach ($filtry as $klucz => $nazwa): ?>
                    <a class="button<?php echo $klucz === $pokaz ? ' button-primary' : ''; ?>"
                       href="<?php echo esc_url($adres(['pokaz' => $klucz])); ?>"<?php echo $klucz === $pokaz ? ' aria-current="page"' : ''; ?>>
                        <?php echo esc_html($nazwa . ' (' . (int) $d['liczby'][$klucz] . ')'); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if (!$wiersze): ?>
                <p class="evo-desc">Brak tekstów w tym widoku.</p>
            <?php else: ?>
                <div class="evo-tbl-wrap"><table class="evo-table">
                    <thead><tr><th scope="col">Strona</th><th scope="col">Element</th><th scope="col">Polski</th>
                        <?php foreach ($jezyki as $j): ?><th scope="col"><?php echo esc_html(strtoupper($j)); ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($wiersze, ($str - 1) * $na_strone, $na_strone) as $w): ?>
                        <tr>
                            <td><a href="<?php echo esc_url($w['adres']); ?>"><?php echo esc_html($w['tytul']); ?></a>
                                <?php if ($w['czesc'] !== 'Treść'): ?><br><span class="evo-faint"><?php echo esc_html($w['czesc']); ?></span><?php endif; ?></td>
                            <td><?php echo esc_html($w['element']); ?><br><span class="evo-faint"><?php echo esc_html($w['opis']); ?></span></td>
                            <td><?php echo esc_html(evk_tl_el_skrot_tekstu($w['pl'])); ?></td>
                            <?php foreach ($jezyki as $j): $l = $w['jezyki'][$j] ?? ['tekst' => '', 'zrodlo' => 'brak', 'sprawdz' => false]; ?>
                                <td><?php if ($l['tekst'] !== ''): ?><?php echo esc_html(evk_tl_el_skrot_tekstu($l['tekst'])); ?><br><?php endif; ?>
                                    <span class="<?php echo $l['zrodlo'] === 'brak' || $l['zrodlo'] === 'czesc' ? 'evo-danger-tx' : 'evo-faint'; ?>"><?php echo esc_html($pochodzenie[$l['zrodlo']] ?? $l['zrodlo']); ?></span>
                                    <?php if (!empty($l['sprawdz'])): ?><br><span class="evo-accent-tx">do sprawdzenia</span><?php endif; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if ($stron > 1): ?>
                    <nav class="tl-teksty-strony" aria-label="Strony listy tekstów">
                        <?php if ($str > 1): ?><a class="button" href="<?php echo esc_url($adres(['pokaz' => $pokaz, 'str' => $str - 1])); ?>">Poprzednie</a><?php endif; ?>
                        <span>Strona <?php echo (int) $str; ?> z <?php echo (int) $stron; ?></span>
                        <?php if ($str < $stron): ?><a class="button" href="<?php echo esc_url($adres(['pokaz' => $pokaz, 'str' => $str + 1])); ?>">Następne</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($d['nieznane']): ?>
                <p class="evo-desc">Pominięte typy elementów — wtyczka nie zna jeszcze ich pól:
                    <?php echo esc_html(implode(', ', array_map(static function ($n, $ile) { return $n . ': ' . $ile; }, array_keys($d['nieznane']), $d['nieznane']))); ?>.
                    Otwórz w builderze stronę z takim elementem.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
