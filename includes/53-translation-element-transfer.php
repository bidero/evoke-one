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
 * Pole języka, którego nowy zapis NIE MA wcale, a stary miał (ten sam
 * element, ta sama pozycja listy), zostaje. „Do sprawdzenia" (52) oznaczy
 * je, gdy oryginał się zmienił.
 *
 * GRANICA. Jeśli Bricks przy czyszczeniu pola usuwa klucz, wyczyszczenie
 * tłumaczenia w builderze wygląda tak samo jak pole nieznane builderowi —
 * stare tłumaczenie wróci (z „Do sprawdzenia", gdy zmienił się oryginał).
 * Co Bricks robi przy czyszczeniu pola tekstowego, sprawdza próba 1.243.0.
 *
 * CZEGO TU NIE SPRAWDZIMY: że builder zapisuje treść przez update_post_meta
 * (jak przy „Do sprawdzenia" — do potwierdzenia na stronie). Przycisk od
 * tego nie zależy: zapisuje sam.
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
 */
function evk_tl_el_uzupelnij_poziom(array &$ust, ?array $stare, array $pola, array $slownik): int {
    $zmiany = 0;
    foreach ($stare ?? [] as $k => $v) {
        $k = (string) $k;
        if (array_key_exists($k, $ust) || !preg_match('/^evk_tl_[a-z0-9_]+?__.+$/', $k) || !evk_tl_el_niepuste($v)) continue;
        $ust[$k] = $v;
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
 * @return array{elementy:mixed,zmiany:int,nieznane:array<string,int>}
 */
function evk_tl_el_uzupelnij($nowe, $stare, array $mapa, array $slownik): array {
    $wynik = ['elementy' => $nowe, 'zmiany' => 0, 'nieznane' => []];
    if (!is_array($nowe)) return $wynik;
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
        $zmiany = evk_tl_el_uzupelnij_poziom($ust, $stary['settings'] ?? null, (array) ($def['pola'] ?? []), $slownik);
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
                $sp = isset($poz['id']) && is_scalar($poz['id']) ? ($stare_poz[(string) $poz['id']] ?? null) : null;
                $zm = evk_tl_el_uzupelnij_poziom($poz, $sp, (array) ($listy[$k] ?? []), $slownik);
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
    if ($sprawdz !== null || $w_trakcie || !is_array($wartosc)) return $sprawdz;
    if (!in_array((string) $meta_key, evk_tl_el_klucze_meta(), true)) return $sprawdz;
    $stare = get_post_meta((int) $post_id, (string) $meta_key, true);
    $wynik = evk_tl_el_uzupelnij($wartosc, $stare, evk_tl_el_mapa(), evk_tl_el_slownik());
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
        if ($zapisz) update_post_meta($post_id, $meta_key, wp_slash($u['elementy']));
        $wynik['strony'][] = ['post_id' => $post_id, 'tytul' => get_the_title($post_id) ?: ('#' . $post_id),
            'czesc' => evk_tl_el_czesc($meta_key), 'zmiany' => $u['zmiany'], 'adres' => evk_tl_el_adres_edycji($post_id)];
        $wynik['razem'] += $u['zmiany'];
    }
    ksort($wynik['nieznane']);
    return $wynik;
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
