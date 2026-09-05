<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Rewizje: przegląd bazy i sprzątanie.
 *
 * WordPress zapisuje rewizję przy każdym zapisie wpisu i domyślnie NIE KASUJE
 * ŻADNEJ. Na stronie prowadzonej od paru lat to zwykle największy pojedynczy
 * śmieć w bazie — dziesiątki tysięcy wierszy w `wp_posts`, z których nikt nigdy
 * nie skorzysta.
 *
 * DWA NARZĘDZIA, NIE JEDNO. Historia pojedynczego snippetu ma własne
 * czyszczenie w edytorze wpisu (`includes/snippets/wersje.php`) — tam wiadomo,
 * czego się dotyka. Tutaj jest widok całej bazy: ile tego jest, przy jakich
 * typach wpisów i ile zniknie przy zadanej liczbie.
 *
 * NAJPIERW PRZEGLĄD, POTEM KASOWANIE. Ekran pokazuje liczby, dopiero po nich
 * przycisk. Kasowanie jest nieodwracalne i nie ma powodu, żeby jego skutek
 * poznawać po fakcie.
 *
 * KASUJEMY `wp_delete_post_revision()`, a nie `DELETE FROM wp_posts`. Zapytanie
 * wprost byłoby szybsze i zostawiałoby po sobie metadane w `wp_postmeta`,
 * wpisy w `wp_term_relationships` i nietknięte pamięci podręczne obiektów —
 * czyli inny rodzaj śmiecia w miejsce sprzątanego.
 */

const EVK_REWIZJE_OPCJA = 'evk_rewizje';

/** Ile rewizji kasujemy w jednym żądaniu. Reszta idzie kolejnymi partiami. */
const EVK_REWIZJE_PARTIA = 200;

/** Ilu rodziców bierzemy pod uwagę w jednym przebiegu kasowania. */
const EVK_REWIZJE_RODZICOW = 500;

/**
 * Klucz typu dla rewizji, której rodzic już nie istnieje.
 *
 * Pusty łańcuch, a nie „orphan" — bo tyle właśnie oddaje `COALESCE` z zapytania
 * i nie ma powodu tłumaczyć tego w dwie strony. Nazwa dla człowieka powstaje
 * dopiero na ekranie.
 */
const EVK_REWIZJE_SIEROTY = '';

function evk_rewizje_ustawienia(): array {
    return wp_parse_args(get_option(EVK_REWIZJE_OPCJA, []), [
        'limit_on' => 0,
        'limit'    => 10,
    ]);
}

add_action('admin_init', function () {
    register_setting('evoke_one_rewizje', EVK_REWIZJE_OPCJA, [
        'type'              => 'array',
        'sanitize_callback' => 'evk_rewizje_sanitize',
    ]);
});

function evk_rewizje_sanitize($wejscie): array {
    return [
        // Przełącznik jedzie AJAX-em — zachowujemy go, gdy nie ma go w POST.
        'limit_on' => evk_preserve_toggle($wejscie, EVK_REWIZJE_OPCJA, 'limit_on'),
        /* Zero jest dozwolone i znaczy „nie trzymaj żadnej". Wartość ujemna
           w `wp_revisions_to_keep` znaczy dla WordPressa „bez ograniczeń",
           więc przepuszczona zmieniłaby włącznik w jego przeciwieństwo. */
        'limit'    => max(0, (int) ($wejscie['limit'] ?? 10)),
    ];
}

/**
 * STAŁY LIMIT — domyślnie wyłączony.
 *
 * Bez niego sprzątanie jest jednorazowe: rewizje odrastają przy każdej edycji
 * i za pół roku baza wygląda tak samo. Z nim WordPress kasuje najstarsze sam,
 * przy zapisie. Wyłączony domyślnie, bo to zmiana zachowania całej witryny,
 * a nie ustawienie tej wtyczki.
 */
add_filter('wp_revisions_to_keep', function ($ile, $post) {
    $u = evk_rewizje_ustawienia();
    return empty($u['limit_on']) ? $ile : (int) $u['limit'];
}, 10, 2);

// =========================================================================
// PRZEGLĄD
// =========================================================================

/**
 * Ile rewizji siedzi w bazie, w rozbiciu na typ wpisu rodzica.
 *
 * `bajtow` to długość samej treści, tytułu i wyciągu — nie rozmiar wiersza
 * w bazie ani zajętość na dysku. Prawdziwy rozmiar tabeli zna
 * `information_schema` i tylko dla CAŁEJ tabeli, więc podanie go przy typie
 * wpisu byłoby liczbą wyglądającą na dokładną i nieprawdziwą.
 */
function evk_rewizje_przeglad(): array {
    global $wpdb;

    $wiersze = $wpdb->get_results(
        "SELECT COALESCE(p.post_type, '') AS typ,
                COUNT(*) AS ile,
                COUNT(DISTINCT r.post_parent) AS wpisow,
                SUM(LENGTH(r.post_content) + LENGTH(r.post_title) + LENGTH(r.post_excerpt)) AS bajtow
           FROM {$wpdb->posts} r
           LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent
          WHERE r.post_type = 'revision'
          GROUP BY COALESCE(p.post_type, '')
          ORDER BY ile DESC",
        ARRAY_A
    );

    $out = [];
    foreach ((array) $wiersze as $w) {
        $out[] = [
            'typ'      => (string) $w['typ'],
            'etykieta' => evk_rewizje_nazwa_typu((string) $w['typ']),
            'ile'      => (int) $w['ile'],
            'wpisow'   => (int) $w['wpisow'],
            'bajtow'   => (int) $w['bajtow'],
        ];
    }
    return $out;
}

/** Nazwa typu wpisu dla człowieka. Typ może już nie istnieć — wtedy sam slug. */
function evk_rewizje_nazwa_typu(string $typ): string {
    if ($typ === EVK_REWIZJE_SIEROTY) return 'Bez rodzica (wpis skasowany)';
    $obiekt = get_post_type_object($typ);
    if ($obiekt && !empty($obiekt->labels->name)) return (string) $obiekt->labels->name;
    return $typ;
}

/**
 * Ile ZNIKNIE przy zadanych typach i zadanej liczbie do zostawienia.
 *
 * Liczone tym samym warunkiem, którym potem kasujemy — inaczej podsumowanie
 * i skutek byłyby dwiema różnymi rzeczami, a to jest dokładnie ten ekran,
 * na którym nie wolno im się rozjechać.
 */
function evk_rewizje_do_skasowania(array $typy, int $zostaw): array {
    global $wpdb;

    $zostaw = max(0, $zostaw);
    $typy   = evk_rewizje_typy_z_zadania($typy);
    if (!$typy) return ['razem' => 0, 'typy' => []];

    $out   = ['razem' => 0, 'typy' => []];
    $zwykle = array_values(array_diff($typy, [EVK_REWIZJE_SIEROTY]));

    if ($zwykle) {
        $miejsca = implode(', ', array_fill(0, count($zwykle), '%s'));
        /* `CASE`, a nie `GREATEST()`. Ta druga jest funkcją MySQL-a i nie ma jej
           w SQLite — czyli w bazie, na której te zapytania są sprawdzane
           testem. Zapis przez `CASE` jest standardowy, znaczy dokładnie to samo
           i pozwala testowi puszczać TEN SAM łańcuch SQL, którym jedzie
           produkcja, zamiast jego tłumaczenia. */
        $wiersze = $wpdb->get_results($wpdb->prepare(
            "SELECT typ, SUM(CASE WHEN ile > %d THEN ile - %d ELSE 0 END) AS nadmiar
               FROM (SELECT p.post_type AS typ, r.post_parent AS rodzic, COUNT(*) AS ile
                       FROM {$wpdb->posts} r
                       INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent
                      WHERE r.post_type = 'revision' AND p.post_type IN ($miejsca)
                      GROUP BY r.post_parent, p.post_type) t
              GROUP BY typ",
            array_merge([$zostaw, $zostaw], $zwykle)
        ), ARRAY_A);

        foreach ((array) $wiersze as $w) {
            $ile = (int) $w['nadmiar'];
            if ($ile <= 0) continue;
            $out['typy'][(string) $w['typ']] = $ile;
            $out['razem'] += $ile;
        }
    }

    if (in_array(EVK_REWIZJE_SIEROTY, $typy, true)) {
        /* SIEROTY IDĄ W CAŁOŚCI, niezależnie od „zostaw N".
           Rodzica już nie ma, więc nie ma czego przywracać ani do czego
           wracać — zostawianie dziesięciu najnowszych wersji nieistniejącego
           wpisu byłoby zostawianiem śmiecia w imię zasady. */
        $ile = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} r
               LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent
              WHERE r.post_type = 'revision' AND p.ID IS NULL"
        );
        if ($ile > 0) {
            $out['typy'][EVK_REWIZJE_SIEROTY] = $ile;
            $out['razem'] += $ile;
        }
    }

    return $out;
}

/**
 * Typy z żądania, sprowadzone do listy łańcuchów.
 *
 * BYŁA TU BIAŁA LISTA — porównanie z typami obecnymi w bazie — i zdjąłem ją,
 * bo mutacja pokazała, że nie pilnuje niczego: wszystkie sprawdzenia
 * przechodziły na zielono także BEZ niej. Powód jest prosty i wynika
 * z zapytań: nazwy typów lądują w `IN (…)` przez `%s` w `$wpdb->prepare()`,
 * więc nazwa spoza bazy nie pasuje do żadnego wiersza i nie ma jak niczego
 * objąć, a wstrzyknięcie nie ma którędy przejść. Biała lista kosztowała za to
 * przy każdym wywołaniu pełny przegląd bazy — grupowanie po całej tabeli
 * `wp_posts`, trzy razy na jedno żądanie AJAX.
 *
 * Zostaje to, co robi robotę, której `IN (…)` nie zrobi: odsianie wartości,
 * które nie są łańcuchem. `typy[]` przychodzi z żądania, więc może być
 * tablicą tablic — a `(string)` na tablicy daje ostrzeżenie PHP i napis
 * „Array".
 */
function evk_rewizje_typy_z_zadania($surowe): array {
    if (!is_array($surowe)) return [];
    $out = [];
    foreach ($surowe as $t) {
        if (!is_scalar($t)) continue;
        $t = (string) $t;
        if (!in_array($t, $out, true)) $out[] = $t;
    }
    return $out;
}

// =========================================================================
// KASOWANIE
// =========================================================================

// =========================================================================
// AJAX
// =========================================================================

/** Wspólna brama obu punktów: nonce, uprawnienie i odczyt argumentów. */
function evk_rewizje_argumenty(): array {
    check_ajax_referer('evk_rewizje_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.', 403);

    return [
        'typy'   => evk_rewizje_typy_z_zadania($_POST['typy'] ?? []),
        'zostaw' => max(0, (int) ($_POST['zostaw'] ?? 10)),
    ];
}

add_action('wp_ajax_evk_rewizje_podsumowanie', function () {
    $a = evk_rewizje_argumenty();
    wp_send_json_success(evk_rewizje_do_skasowania($a['typy'], $a['zostaw']));
});

add_action('wp_ajax_evk_rewizje_kasuj', function () {
    $a = evk_rewizje_argumenty();
    $skasowane = evk_rewizje_kasuj($a['typy'], $a['zostaw']);
    $zostalo   = evk_rewizje_do_skasowania($a['typy'], $a['zostaw']);

    wp_send_json_success([
        'skasowane' => $skasowane,
        'zostalo'   => $zostalo['razem'],
        /* Przegląd wraca w tej samej odpowiedzi: po skasowaniu partii tabela
           na ekranie mówi nieprawdę i nie ma powodu, żeby czekała na
           przeładowanie strony. */
        'przeglad'  => evk_rewizje_przeglad(),
    ]);
});

/**
 * Kasuje JEDNĄ PARTIĘ i mówi, ile jeszcze zostało.
 *
 * Partiami, bo na stronie z kilkudziesięcioma tysiącami rewizji jedno żądanie
 * nie ma szans dobiec do końca: `wp_delete_post_revision()` to dla każdej
 * rewizji zapytania o metadane, relacje i pamięci podręczne. Kasowanie
 * przerwane limitem czasu wykonania zostawiłoby robotę w połowie, bez ani
 * jednej informacji o tym, co zdążyło zniknąć.
 */
function evk_rewizje_kasuj(array $typy, int $zostaw, int $limit = EVK_REWIZJE_PARTIA): int {
    global $wpdb;

    $zostaw = max(0, $zostaw);
    $limit  = max(1, $limit);
    $typy   = evk_rewizje_typy_z_zadania($typy);
    if (!$typy) return 0;

    $skasowane = 0;

    // ── Sieroty: wszystkie, bez zostawiania ────────────────────────────────
    if (in_array(EVK_REWIZJE_SIEROTY, $typy, true)) {
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT r.ID FROM {$wpdb->posts} r
               LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent
              WHERE r.post_type = 'revision' AND p.ID IS NULL
              LIMIT %d",
            $limit
        ));
        foreach ((array) $ids as $id) {
            if (wp_delete_post_revision((int) $id)) $skasowane++;
            if ($skasowane >= $limit) return $skasowane;
        }
    }

    // ── Zwykłe typy: nadmiar ponad `$zostaw` przy każdym rodzicu ───────────
    $zwykle = array_values(array_diff($typy, [EVK_REWIZJE_SIEROTY]));
    if (!$zwykle) return $skasowane;

    $miejsca  = implode(', ', array_fill(0, count($zwykle), '%s'));
    $rodzice  = $wpdb->get_col($wpdb->prepare(
        "SELECT r.post_parent
           FROM {$wpdb->posts} r
           INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent
          WHERE r.post_type = 'revision' AND p.post_type IN ($miejsca)
          GROUP BY r.post_parent
         HAVING COUNT(*) > %d
          ORDER BY r.post_parent ASC
          LIMIT %d",
        array_merge($zwykle, [$zostaw, EVK_REWIZJE_RODZICOW])
    ));

    foreach ((array) $rodzice as $rodzic) {
        /* Sortowanie po dacie I identyfikatorze: dwie rewizje zapisane w tej
           samej sekundzie rozstrzygałyby się inaczej przy każdym przebiegu,
           a wtedy „zostaw dziesięć najnowszych" znaczyłoby za każdym razem
           coś innego.

           Odcięcie najnowszych robimy w PHP, a nie przez `OFFSET`. MySQL nie
           przyjmuje samego `OFFSET` i trzeba mu podać `LIMIT` z osiemnastoma
           trylionami — zapis, który wygląda na pomyłkę i nie działa poza
           MySQL-em. Jeden rodzic ma najwyżej kilkaset rewizji, więc nie ma tu
           czego oszczędzać. */
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type = 'revision' AND post_parent = %d
              ORDER BY post_date DESC, ID DESC",
            (int) $rodzic
        ));
        $ids = array_slice((array) $ids, $zostaw);

        foreach ((array) $ids as $id) {
            if (wp_delete_post_revision((int) $id)) $skasowane++;
            if ($skasowane >= $limit) return $skasowane;
        }
    }

    return $skasowane;
}
