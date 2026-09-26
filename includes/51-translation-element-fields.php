<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — pola języków w elementach Bricksa (1.241.0).
 *
 * ZGŁOSZONE Z UŻYCIA: tłumaczenie przypięte do TEKSTU oryginału (słownik
 * „fraza PL → EN/DE") ginęło przy każdej zmianie polskiego tekstu, bo strona
 * szuka dokładnie tej frazy. Decyzja zgłaszającego: w każdym elemencie
 * zwinięta grupa „Tłumaczenia" z polem na każdy język dla każdego tekstu.
 * Tłumaczenie siedzi w elemencie, więc zmiana polskiego tekstu go nie usuwa —
 * zostaje stare. Obejmuje wszystkie pola tekstowe (tekst, akapit, edytor)
 * wszystkich elementów, bez technicznych, także pozycje list.
 *
 * KOLEJNOŚĆ: pole w elemencie → słownik → oryginał. Podmiana idzie przez
 * `bricks/element/settings`, PRZED renderem. Słownik (tokenizer na gotowym
 * HTML-u, 50-translation-engine.php) widzi już tekst w języku strony i nie
 * dopasuje go do żadnej frazy polskiej. Puste pole zostawia polski tekst,
 * a ten słownik tłumaczy jak dotąd.
 *
 * POZYCJE LIST (repeater: akordeon, zakładki, lista, pola formularza) — pola
 * języków stoją wewnątrz pozycji, pod jej polami. Grupa elementu obejmuje
 * tylko pola elementu; pozycja listy ma własny zestaw pól i tylko tam da się
 * je dołożyć.
 *
 * CZEGO TU NIE SPRAWDZIMY (Bricksa na tej maszynie nie ma — CLAUDE.md):
 * czy panel elementu pokaże grupę i pola, czy dostęp „Edytuj treść" je
 * widzi i zapisuje, czy filtr ustawień działa w szablonach, komponentach
 * i pętlach zapytań. Filtr kontrolek dla wszystkich elementów działa na
 * stronie zgłaszającego od dawna (Animator), filtr ustawień też
 * (70-bricks-language-switcher.php, obrazek flagi).
 */

const EVK_TL_EL_GRUPA = 'evk_tl';

/** Klucz pola języka: evk_tl_{język}__{kontrolka}; kod języka tylko [a-z0-9_]. */
function evk_tl_el_klucz(string $jezyk, string $kontrolka): string {
    return 'evk_tl_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($jezyk)) . '__' . $kontrolka;
}

/**
 * Czy kontrolka niesie treść do tłumaczenia: typ tekstowy, zakładka treści,
 * bez CSS i bez „technicznej" nazwy. Filtr `evk_tl_el_tlumaczalna` poprawia
 * werdykt dla konkretnego pola (klucz, definicja, nazwa elementu).
 *
 * @param mixed $def Definicja kontrolki z tablicy Bricksa.
 */
function evk_tl_el_tlumaczalna(string $klucz, $def, string $element = ''): bool {
    $tak = is_array($def)
        && in_array($def['type'] ?? '', ['text', 'textarea', 'editor'], true)
        && ($def['tab'] ?? 'content') !== 'style'
        && empty($def['css'])
        && $klucz !== '' && $klucz[0] !== '_' && strncmp($klucz, 'evk', 3) !== 0
        && !evk_tl_el_techniczna_nazwa($klucz, (string) ($def['label'] ?? ''));
    return (bool) apply_filters('evk_tl_el_tlumaczalna', $tak, $klucz, $def, $element);
}

/**
 * Nazwa, która mówi „to nie jest treść": selektory, identyfikatory, adresy,
 * kod, liczby i wartości wysyłane formularzem (opcje listy, wartość pola,
 * temat i treść maila do administratora). Klucz w camelCase dzielimy na słowa:
 * „customCssId" → custom_css_id, „submitButtonText" → submit_button_text
 * (treść, bo żadne słowo nie jest techniczne).
 */
function evk_tl_el_techniczna_nazwa(string $klucz, string $etykieta): bool {
    $slowa = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $klucz));
    $techniczne = 'id|ids|class|classes|selector|selectors|url|link|href|attr|attrs|attribute|attributes'
        . '|code|css|js|script|shortcode|query|slug|key|name|tag|tags|target|rel|anchor|icon|svg'
        . '|width|height|size|gap|offset|duration|delay|ease|easing|speed|zindex|breakpoint|pattern'
        . '|regex|format|value|options|email|recipient|recipients|redirect|action|endpoint|api|token'
        . '|param|params|mailto|tel|phone|date|time|color|colour|unit|lang|locale|percentage|count';
    if (preg_match('/(^|[_\-])(' . $techniczne . ')([_\-]|$)/', $slowa)) return true;
    return (bool) preg_match('/\b(selektor|identyfikator|adres|klas[aey]|kod|atrybut|selector|class|url|css)\b/iu', $etykieta);
}

// =========================================================================
// KONTROLKI — grupa „Tłumaczenia" i pola języków w każdym elemencie
// =========================================================================

add_action('init', function (): void {
    if (!class_exists('\Bricks\Elements')) return;
    $elementy = \Bricks\Elements::$elements ?? null;
    if (!is_array($elementy)) return;
    foreach ($elementy as $klucz => $el) {
        // Bricks kluczuje tablicę nazwą elementu, a wartość też niesie 'name' — jak w Animatorze.
        $nazwa = (is_array($el) && !empty($el['name'])) ? $el['name'] : (string) $klucz;
        if ($nazwa === '') continue;
        add_filter("bricks/elements/{$nazwa}/control_groups", 'evk_tl_el_grupy');
        add_filter("bricks/elements/{$nazwa}/controls", function ($kontrolki) use ($nazwa) {
            return evk_tl_el_kontrolki($kontrolki, $nazwa);
        });
    }
// PHP_INT_MAX jak Animator: Bricks rejestruje elementy na init/10, inne wtyczki później.
}, PHP_INT_MAX);

/** @param mixed $grupy */
function evk_tl_el_grupy($grupy) {
    if (!is_array($grupy) || !evk_tl_kody_jezykow()) return $grupy;
    $grupy[EVK_TL_EL_GRUPA] = ['title' => 'Tłumaczenia', 'tab' => 'content'];
    return $grupy;
}

/**
 * Polska nazwa pola, które w Bricksie nie ma etykiety — tekst nagłówka ma
 * tylko edycję na kanwie, więc do 1.242.0 panel pokazywał sam klucz
 * („text — EN"). Klucza spoza listy nie zgadujemy: zostaje, jak był.
 */
function evk_tl_el_nazwa_pola(string $klucz): string {
    $nazwy = ['text' => 'Tekst', 'title' => 'Tytuł', 'subtitle' => 'Podtytuł', 'content' => 'Treść',
        'description' => 'Opis', 'label' => 'Etykieta', 'placeholder' => 'Tekst zastępczy', 'caption' => 'Podpis'];
    return $nazwy[$klucz] ?? $klucz;
}

/**
 * Etykieta pola języka (1.243.0, decyzja zgłaszającego): „Tłumaczenie EN".
 * Gdy element albo pozycja listy ma kilka pól tekstowych, sama
 * „Tłumaczenie EN" nie mówi, którego dotyczy — wtedy „Tłumaczenie EN · Tytuł".
 *
 * @param array<string,mixed> $def
 */
function evk_tl_el_etykieta(array $def, string $klucz, string $jezyk, bool $wiele): string {
    $etykieta = 'Tłumaczenie ' . strtoupper($jezyk);
    if (!$wiele) return $etykieta;
    $nazwa = !empty($def['label']) ? wp_strip_all_tags((string) $def['label']) : evk_tl_el_nazwa_pola($klucz);
    return $etykieta . ' · ' . $nazwa;
}

/**
 * Pole języka na wzór pola źródłowego — tylko to, czego pole tekstowe
 * potrzebuje. Bez `default`: kontrolka z domyślną raportuje wartość także
 * w nietkniętym elemencie i zapala w builderze kropkę „grupa ma ustawienia"
 * (tests/php/anim-kontrolki.php). Bez `inlineEditing`: edycja na kanwie
 * dotyczy oryginału, a kanwa pokazuje polski.
 *
 * @param array<string,mixed> $def
 * @return array<string,mixed>
 */
function evk_tl_el_pole(array $def, string $klucz, string $jezyk, bool $w_grupie, bool $wiele = false): array {
    $pole = ['type' => $def['type'], 'label' => evk_tl_el_etykieta($def, $klucz, $jezyk, $wiele)];
    if ($w_grupie) {
        $pole['tab']   = 'content';
        $pole['group'] = EVK_TL_EL_GRUPA;
    }
    foreach (['hasDynamicData', 'required'] as $k) {
        if (isset($def[$k])) $pole[$k] = $def[$k];
    }
    return $pole;
}

/**
 * Dokłada pola języków: po polach tekstowych elementu (na końcu tablicy,
 * w grupie „Tłumaczenia") i wewnątrz pozycji list.
 *
 * @param mixed $kontrolki
 * @return mixed
 */
function evk_tl_el_kontrolki($kontrolki, string $element = '') {
    if (!is_array($kontrolki)) return $kontrolki;
    $jezyki = evk_tl_kody_jezykow();
    if (!$jezyki) return $kontrolki;

    $pola = [];
    foreach ($kontrolki as $klucz => $def) {
        $klucz = (string) $klucz;
        if (is_array($def) && ($def['type'] ?? '') === 'repeater' && is_array($def['fields'] ?? null)) {
            $w_pozycji = [];
            foreach ($def['fields'] as $pk => $pdef) {
                if (evk_tl_el_tlumaczalna((string) $pk, $pdef, $element)) $w_pozycji[(string) $pk] = $pdef;
            }
            if ($w_pozycji) $kontrolki[$klucz]['fields'] = $def['fields'] + evk_tl_el_pola_jezykow($w_pozycji, $jezyki, false);
            continue;
        }
        if (evk_tl_el_tlumaczalna($klucz, $def, $element)) $pola[$klucz] = $def;
    }
    return $pola ? $kontrolki + evk_tl_el_pola_jezykow($pola, $jezyki, true) : $kontrolki;
}

/**
 * Pola języków dla pól tłumaczalnych jednego poziomu (element albo pozycja
 * listy): najpierw wszystkie EN, potem wszystkie DE — tłumacz jednego języka
 * ma swoje pola obok siebie.
 *
 * @param array<string,mixed> $pola   Klucz => definicja pola tłumaczalnego.
 * @param string[]            $jezyki
 * @return array<string,array<string,mixed>>
 */
function evk_tl_el_pola_jezykow(array $pola, array $jezyki, bool $w_grupie): array {
    $wiele = count($pola) > 1;
    $nowe = [];
    foreach ($jezyki as $j) {
        foreach ($pola as $klucz => $def) {
            $nowe[evk_tl_el_klucz($j, (string) $klucz)] = evk_tl_el_pole($def, (string) $klucz, $j, $w_grupie, $wiele);
        }
    }
    return $nowe;
}

// =========================================================================
// RENDER — podmiana tekstu na język strony, przed renderem elementu
// =========================================================================

/**
 * Czy pole języka coś niesie. Edytor zostawia po wyczyszczeniu `<p></p>`
 * albo `&nbsp;` — to ma zostawić polski, żeby zadziałał słownik.
 *
 * @param mixed $v
 */
function evk_tl_el_niepuste($v): bool {
    return is_string($v) && trim(wp_strip_all_tags(str_replace('&nbsp;', ' ', $v))) !== '';
}

/**
 * Jeden poziom ustawień: K ← evk_tl_{L}__K, gdy pole języka niepuste; w listach
 * (repeater) to samo w każdej pozycji. Lista rozpoznawana ręcznie, nie
 * `array_is_list()` (PHP 8.1) — wtyczka nie deklaruje minimalnego PHP,
 * patrz 90-schema.php.
 *
 * @param array<string,mixed> $ustawienia
 * @return array<string,mixed>
 */
function evk_tl_el_podmien(array $ustawienia, string $jezyk): array {
    $przedrostek = evk_tl_el_klucz($jezyk, '');
    $dl = strlen($przedrostek);
    foreach ($ustawienia as $k => $v) {
        $k = (string) $k;
        if (strncmp($k, $przedrostek, $dl) === 0) {
            $zrodlo = substr($k, $dl);
            if ($zrodlo !== '' && evk_tl_el_niepuste($v)) $ustawienia[$zrodlo] = $v;
        } elseif (is_array($v) && $v && array_keys($v) === range(0, count($v) - 1)) {
            foreach ($v as $i => $pozycja) {
                if (is_array($pozycja)) $ustawienia[$k][$i] = evk_tl_el_podmien($pozycja, $jezyk);
            }
        }
    }
    return $ustawienia;
}

add_filter('bricks/element/settings', function ($ustawienia, $element) {
    if (!is_array($ustawienia) || is_admin() || evk_w_builderze()) return $ustawienia;
    $jezyk = get_current_lang();
    if ($jezyk === 'pl' || $jezyk === '') return $ustawienia;
    return evk_tl_el_podmien($ustawienia, $jezyk);
}, 20, 2);
