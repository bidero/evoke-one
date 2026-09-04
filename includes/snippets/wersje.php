<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — snippety: historia zmian.
 *
 * Rewizje trzyma WordPress sam, odkąd typ wpisu ma `supports => revisions`.
 * Do 1.139.0 był na nie podgląd przy czterech stałych oknach; razem z oknami
 * zniknął, bo historia stała się osobna dla KAŻDEGO wpisu i panel obok
 * czterech pól przestał pasować. Wraca tutaj, jako część edytora wpisu.
 *
 * PRZYWRACANIE NIE ZAPISUJE. Kliknięcie wstawia starą treść do pola kodu
 * i na tym kończy swoją robotę — na stronie nadal pracuje to, co pracowało.
 * Wykonanie zmienia dopiero „Zapisz snippet". Treść snippetu JEST WYKONYWANA,
 * więc moment, w którym zaczyna działać, ma być decyzją, a nie skutkiem
 * kliknięcia w listę.
 */

/** Ile wersji pokazujemy na ekranie. W bazie zostają wszystkie. */
const EVK_SNIPPET_WERSJI_NA_EKRANIE = 20;

/**
 * Historia jednego wpisu, od najnowszej.
 *
 * `znakow` idzie do ekranu jako jedyna liczba, która coś mówi bez otwierania
 * podglądu: wersja krótsza o połowę to zwykle ta, w której coś wypadło.
 */
function evk_snippet_wersje(int $id, int $ile = EVK_SNIPPET_WERSJI_NA_EKRANIE): array {
    if (!$id) return [];

    $rewizje = wp_get_post_revisions($id, [
        'posts_per_page' => $ile,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $out = [];
    foreach ($rewizje as $rew) {
        $out[] = [
            'id'      => (int) $rew->ID,
            'data'    => (string) $rew->post_date,
            'autor'   => (string) get_the_author_meta('display_name', $rew->post_author),
            'znakow'  => strlen((string) $rew->post_content),
        ];
    }
    return $out;
}

/**
 * Czyści historię JEDNEGO wpisu, zostawiając `$zostaw` najnowszych wersji.
 *
 * Zwraca liczbę skasowanych. Kasowanie jest nieodwracalne, więc funkcja nie
 * ma prawa niczego domyślać się sama: `$zostaw` przychodzi z ekranu, a wartość
 * ujemna jest tu twardym zerem, a nie „skasuj wszystko od końca".
 */
function evk_snippet_wyczysc_wersje(int $id, int $zostaw): int {
    if (!$id) return 0;
    $zostaw = max(0, $zostaw);

    $rewizje = wp_get_post_revisions($id, ['orderby' => 'date', 'order' => 'DESC']);
    $rewizje = array_values($rewizje);
    if (count($rewizje) <= $zostaw) return 0;

    $skasowane = 0;
    foreach (array_slice($rewizje, $zostaw) as $rew) {
        if (wp_delete_post_revision($rew->ID)) $skasowane++;
    }
    return $skasowane;
}

// =========================================================================
// RÓŻNICA MIĘDZY WERSJAMI
// =========================================================================

/**
 * Ile linii najwyżej porównujemy dokładnie, po odcięciu wspólnych końców.
 *
 * Dokładne porównanie to tablica długości × długość. Przy tysiącu linii
 * z każdej strony byłby to milion komórek na jedno kliknięcie w podgląd —
 * i to w panelu, w żądaniu, które ma odpowiedzieć od razu. Powyżej progu
 * pokazujemy blok wymieniony w całości: mniej dokładnie, ale uczciwie i tanio.
 */
const EVK_SNIPPET_DIFF_LIMIT = 300;

/**
 * Różnica dwóch treści, linia po linii.
 *
 * Zwraca wiersze `['typ' => 'rowny'|'dodane'|'usuniete', 'stara', 'nowa',
 * 'tekst']`, gdzie `stara` i `nowa` to numery linii (albo `null`).
 *
 * ODCIĘCIE WSPÓLNYCH KOŃCÓW jest tu warunkiem użyteczności, nie oszczędnością:
 * poprawka jednej linii w stu daje po odcięciu dwa krótkie ogonki, więc
 * dokładne porównanie liczy się na kilku liniach zamiast na dwustu.
 * `wp_text_diff()` zrobiłby to samo, ale ciągnie za sobą arkusze rdzenia
 * i tabelę o klasach, których nasz panel nie zna.
 */
function evk_snippet_roznica(string $stare, string $nowe): array {
    $a = preg_split("/\r\n|\n|\r/", $stare);
    $b = preg_split("/\r\n|\n|\r/", $nowe);

    $out    = [];
    $przod  = 0;
    $maxPrz = min(count($a), count($b));
    while ($przod < $maxPrz && $a[$przod] === $b[$przod]) {
        $out[] = ['typ' => 'rowny', 'stara' => $przod + 1, 'nowa' => $przod + 1, 'tekst' => $a[$przod]];
        $przod++;
    }

    /* Ogon liczymy dopiero po przodzie i nie pozwalamy im na siebie wejść —
       inaczej przy treści, w której zostały same wspólne linie, ta sama linia
       trafiłaby do wyniku dwa razy. */
    $tyl    = 0;
    $maxTyl = min(count($a) - $przod, count($b) - $przod);
    while ($tyl < $maxTyl && $a[count($a) - 1 - $tyl] === $b[count($b) - 1 - $tyl]) $tyl++;

    $srodekA = array_slice($a, $przod, count($a) - $przod - $tyl);
    $srodekB = array_slice($b, $przod, count($b) - $przod - $tyl);

    if (count($srodekA) > EVK_SNIPPET_DIFF_LIMIT || count($srodekB) > EVK_SNIPPET_DIFF_LIMIT) {
        foreach ($srodekA as $i => $linia) {
            $out[] = ['typ' => 'usuniete', 'stara' => $przod + $i + 1, 'nowa' => null, 'tekst' => $linia];
        }
        foreach ($srodekB as $i => $linia) {
            $out[] = ['typ' => 'dodane', 'stara' => null, 'nowa' => $przod + $i + 1, 'tekst' => $linia];
        }
    } else {
        foreach (evk_snippet_diff_srodka($srodekA, $srodekB, $przod) as $w) $out[] = $w;
    }

    for ($i = 0; $i < $tyl; $i++) {
        $out[] = [
            'typ'   => 'rowny',
            'stara' => count($a) - $tyl + $i + 1,
            'nowa'  => count($b) - $tyl + $i + 1,
            'tekst' => $a[count($a) - $tyl + $i],
        ];
    }

    return $out;
}

/**
 * Najdłuższy wspólny podciąg dwóch kawałków — klasyczna tablica.
 *
 * `$przesuniecie` to liczba linii odciętych z przodu; numery w wyniku mają
 * być numerami w CAŁYM pliku, a nie w wyciętym środku.
 */
function evk_snippet_diff_srodka(array $a, array $b, int $przesuniecie): array {
    $n = count($a);
    $m = count($b);

    // Tablica długości wspólnych podciągów, liczona od końca.
    $dl = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $dl[$i][$j] = ($a[$i] === $b[$j])
                ? $dl[$i + 1][$j + 1] + 1
                : max($dl[$i + 1][$j], $dl[$i][$j + 1]);
        }
    }

    $out = [];
    $i = 0; $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $out[] = ['typ' => 'rowny', 'stara' => $przesuniecie + $i + 1,
                      'nowa' => $przesuniecie + $j + 1, 'tekst' => $a[$i]];
            $i++; $j++;
        } elseif ($dl[$i + 1][$j] >= $dl[$i][$j + 1]) {
            $out[] = ['typ' => 'usuniete', 'stara' => $przesuniecie + $i + 1,
                      'nowa' => null, 'tekst' => $a[$i]];
            $i++;
        } else {
            $out[] = ['typ' => 'dodane', 'stara' => null,
                      'nowa' => $przesuniecie + $j + 1, 'tekst' => $b[$j]];
            $j++;
        }
    }
    while ($i < $n) {
        $out[] = ['typ' => 'usuniete', 'stara' => $przesuniecie + $i + 1, 'nowa' => null, 'tekst' => $a[$i]];
        $i++;
    }
    while ($j < $m) {
        $out[] = ['typ' => 'dodane', 'stara' => null, 'nowa' => $przesuniecie + $j + 1, 'tekst' => $b[$j]];
        $j++;
    }
    return $out;
}

/**
 * Różnica jako znacznik.
 *
 * Wiersze RÓWNE też idą na ekran, ale tylko te blisko zmiany: sto niezmienionych
 * linii między dwiema poprawkami nie mówi nic, a spycha te poprawki poza ekran.
 * Zwinięte miejsca dostają wiersz z liczbą pominiętych linii, żeby nie wyglądało
 * to na urwaną treść.
 */
function evk_snippet_roznica_html(array $wiersze, int $kontekst = 3): string {
    if (!$wiersze) return '<p class="evo-hint">Ta wersja niczym się nie różni od obecnej treści.</p>';

    // Które wiersze pokazujemy: każda zmiana plus `$kontekst` linii wokół niej.
    $pokaz = [];
    foreach ($wiersze as $i => $w) {
        if ($w['typ'] === 'rowny') continue;
        for ($k = max(0, $i - $kontekst); $k <= min(count($wiersze) - 1, $i + $kontekst); $k++) {
            $pokaz[$k] = true;
        }
    }
    if (!$pokaz) return '<p class="evo-hint">Ta wersja niczym się nie różni od obecnej treści.</p>';

    $html = '<div class="evo-diff">';
    $pominiete = 0;
    foreach ($wiersze as $i => $w) {
        if (empty($pokaz[$i])) { $pominiete++; continue; }
        if ($pominiete) {
            $html .= sprintf('<div class="evo-diff-przerwa">… %d %s bez zmian …</div>',
                $pominiete, $pominiete === 1 ? 'linia' : 'linii');
            $pominiete = 0;
        }
        $znak = ['rowny' => ' ', 'dodane' => '+', 'usuniete' => '−'][$w['typ']];
        $html .= sprintf(
            '<div class="evo-diff-linia evo-diff-%s"><span class="evo-diff-nr">%s</span>'
            . '<span class="evo-diff-znak">%s</span><span class="evo-diff-tekst">%s</span></div>',
            $w['typ'],
            esc_html((string) ($w['nowa'] ?? $w['stara'] ?? '')),
            $znak,
            esc_html($w['tekst'])
        );
    }
    if ($pominiete) {
        $html .= sprintf('<div class="evo-diff-przerwa">… %d %s bez zmian …</div>',
            $pominiete, $pominiete === 1 ? 'linia' : 'linii');
    }
    return $html . '</div>';
}
