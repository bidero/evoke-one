<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — akcja „Newsletter (Evoke ONE)" w formularzu Bricksa (1.233.0)
 *
 * Formularz Bricksa zapisuje osobę na listę newslettera tak samo jak
 * shortcode: przez evk_nl_zapisz_z_formularza() (includes/newsletter/public.php),
 * z tym samym potwierdzeniem mailem, zapisem zgody, limitami i blokadą RODO.
 *
 * Trzy zastosowania, jedna akcja:
 *   — osobny formularz zapisu: e-mail (+ np. imię), bez pola zgody albo z
 *     wymaganym checkboxem,
 *   — formularz kontaktowy z checkboxem „Zapisz mnie do newslettera":
 *     wskazany jako „Pole zgody" — bez zaznaczenia akcja nic nie robi,
 *     a wiadomość i tak idzie,
 *   — rejestracja konta z takim samym checkboxem.
 *
 * API Bricksa od 1.12.2, udokumentowane w Bricks Academy (Form → „Custom form
 * actions"): akcję i jej ustawienia dokłada filtr `bricks/elements/form/controls`,
 * grupę ustawień `bricks/elements/form/control_groups`, obsługę po wysłaniu
 * `bricks/form/action/{akcja}`. Obiekt formularza: `get_settings()`,
 * `get_fields()` (wartości pod `form-field-{id}` oraz `formId`, `postId`),
 * `set_result()` z typem `danger` przy błędzie.
 *
 * BRICKSA NIE MA NA MASZYNIE TESTOWEJ (CLAUDE.md): test newsletter-bricks
 * sprawdza kształt kontrolek z przykładu w dokumentacji i obsługę z obiektem
 * formularza o tych metodach. Czy builder przyjmuje kontrolki i jak pokazuje
 * komunikat — dowód ze strony, nie z tego pliku.
 */

const EVK_NL_BRICKS_AKCJA = 'evk-newsletter';

/**
 * Własne akcje formularza Bricks obsługuje od 1.12.2. Starszy Bricks próbowałby
 * wczytać klasę akcji o tej nazwie — akcji po prostu nie pokazujemy.
 */
function evk_nl_bricks_obslugiwany(): bool {
    return defined('BRICKS_VERSION') && version_compare((string) BRICKS_VERSION, '1.12.2', '>=');
}

/** Listy newslettera do pola wyboru w builderze: id => nazwa (raz na żądanie). */
function evk_nl_bricks_listy(): array {
    static $listy = null;
    if ($listy !== null) return $listy;
    $listy = [];
    if (empty(get_option('evk_newsletter', [])['enabled'])) return $listy;
    foreach (evk_nl_get_lists() as $l) $listy[(string) $l['id']] = (string) $l['name'];
    return $listy;
}

add_filter('bricks/elements/form/controls', 'evk_nl_bricks_kontrolki');

function evk_nl_bricks_kontrolki($controls) {
    if (!is_array($controls) || !evk_nl_bricks_obslugiwany()) return $controls;
    if (!isset($controls['actions']['options']) || !is_array($controls['actions']['options'])) return $controls;

    $controls['actions']['options'][EVK_NL_BRICKS_AKCJA] = 'Newsletter (Evoke ONE)';

    $wlaczony = !empty(get_option('evk_newsletter', [])['enabled']);
    $controls['evkNlLista'] = [
        'group'       => 'evkNewsletter',
        'label'       => 'Lista',
        'type'        => 'select',
        'options'     => evk_nl_bricks_listy(),
        'placeholder' => 'Wybierz listę',
        'description' => $wlaczony ? 'Lista newslettera Evoke ONE, na którą trafi osoba.'
                                   : 'Moduł Newsletter jest wyłączony — włącz go w Evoke ONE, żeby wybrać listę.',
    ];
    $controls['evkNlEmail'] = [
        'group'       => 'evkNewsletter',
        'label'       => 'Pole e-mail',
        'type'        => 'select',
        'options'     => [],
        'map_fields'  => true,
        'placeholder' => 'Pierwsze pole typu e-mail',
        'description' => 'Puste: pierwsze pole typu „Email" w formularzu.',
    ];
    $controls['evkNlZgoda'] = [
        'group'       => 'evkNewsletter',
        'label'       => 'Pole zgody (checkbox)',
        'type'        => 'select',
        'options'     => [],
        'map_fields'  => true,
        'placeholder' => 'Brak — zapis przy każdym wysłaniu',
        'description' => 'Zapis tylko wtedy, gdy ten checkbox jest zaznaczony — tak dodasz „Zapisz mnie do newslettera" do formularza kontaktowego albo rejestracji. Jego treść zapisze się jako treść zgody.',
    ];
    $controls['evkNlPola'] = [
        'group'       => 'evkNewsletter',
        'label'       => 'Pola do zapisania',
        'type'        => 'select',
        'multiple'    => true,
        'options'     => [],
        'map_fields'  => true,
        'description' => 'Trafiają do subskrybenta jako znaczniki w treści maila: pole „Imię" → {imie}.',
    ];
    $controls['evkNlBezPotwierdzenia'] = [
        'group'       => 'evkNewsletter',
        'label'       => 'Zapisuj bez potwierdzenia mailem',
        'type'        => 'checkbox',
        'description' => 'Domyślnie osoba dostaje mail z linkiem i dopiero kliknięcie ją zapisuje (double opt-in). Komunikat po wysłaniu to ten z ustawień formularza — przy potwierdzeniu mailem napisz w nim, żeby sprawdzić skrzynkę.',
    ];
    return $controls;
}

add_filter('bricks/elements/form/control_groups', 'evk_nl_bricks_grupy');

function evk_nl_bricks_grupy($groups) {
    if (!is_array($groups) || !evk_nl_bricks_obslugiwany()) return $groups;
    $groups['evkNewsletter'] = [
        'title'    => 'Newsletter (Evoke ONE)',
        'required' => ['actions', '=', EVK_NL_BRICKS_AKCJA],
    ];
    return $groups;
}

// =========================================================================
// OBSŁUGA PO WYSŁANIU
// =========================================================================

/**
 * Identyfikator pola z ustawienia akcji. Pole wyboru z `map_fields` podaje
 * identyfikator pola formularza; na wszelki wypadek przyjmujemy też postać
 * z przedrostkiem `form-field-`, w jakiej wartości przychodzą w get_fields().
 */
function evk_nl_bricks_id($wartosc): string {
    $id = is_scalar($wartosc) ? trim((string) $wartosc) : '';
    return strpos($id, 'form-field-') === 0 ? substr($id, 11) : $id;
}

/** Wysłana wartość pola jako tekst; pole wielokrotne (checkboxy) — po przecinku. */
function evk_nl_bricks_wartosc(array $pola, string $id): string {
    if ($id === '' || !array_key_exists('form-field-' . $id, $pola)) return '';
    $w = $pola['form-field-' . $id];
    if (is_array($w)) {
        $w = implode(', ', array_filter(array_map(static fn($x) => is_scalar($x) ? trim((string) $x) : '', $w), static fn($x) => $x !== ''));
    }
    return is_scalar($w) ? sanitize_text_field((string) $w) : '';
}

/** Ustawienia pola formularza (typ, etykieta, opcje) po identyfikatorze. */
function evk_nl_bricks_pole(array $ustawienia, string $id): array {
    foreach ((array) ($ustawienia['fields'] ?? []) as $pole) {
        if (is_array($pole) && (string) ($pole['id'] ?? '') === $id) return $pole;
    }
    return [];
}

/**
 * Klucz znacznika z etykiety pola: „Imię" → imie, „Imię i nazwisko" →
 * imie_i_nazwisko. Pole bez etykiety — jego identyfikator.
 */
function evk_nl_bricks_klucz(array $ustawienia, string $id): string {
    $pole     = evk_nl_bricks_pole($ustawienia, $id);
    $etykieta = trim((string) ($pole['label'] ?? ''));
    if ($etykieta === '') $etykieta = trim((string) ($pole['placeholder'] ?? ''));
    $klucz = sanitize_key(preg_replace('/[\s\-]+/', '_', remove_accents($etykieta)));
    // Klucze od „_" to zapis wewnętrzny (zgoda, jej data i IP) — nie do nadpisania z formularza.
    $klucz = ltrim($klucz, '_');
    return $klucz !== '' ? $klucz : sanitize_key($id);
}

/**
 * Treść zgody tak, jak osoba ją widziała: etykieta pola i tekst zaznaczonej
 * opcji. Od Bricksa 1.10.2 opcja może mieć osobno wartość i etykietę
 * (dwukropek) — wysyłana jest wartość, a zgoda ma mieć tekst z ekranu, więc
 * szukamy opcji, której jedna strona to wysłana wartość, i bierzemy drugą.
 */
function evk_nl_bricks_tekst_zgody(array $ustawienia, string $id, string $wyslane): string {
    $pole  = evk_nl_bricks_pole($ustawienia, $id);
    $opcje = preg_split('/\r\n|\r|\n/', (string) ($pole['options'] ?? '')) ?: [];
    $teksty = [];
    foreach (array_map('trim', explode(',', $wyslane)) as $w) {
        $tekst = $w;
        foreach ($opcje as $linia) {
            $linia = trim($linia);
            if ($linia === $w) { $tekst = $linia; break; }
            if (strpos($linia, ':') === false) continue;
            [$a, $b] = array_map('trim', explode(':', $linia, 2));
            if ($a === $w) { $tekst = $b; break; }
            if ($b === $w) { $tekst = $a; break; }
        }
        if ($tekst !== '') $teksty[] = $tekst;
    }
    $etykieta = trim((string) ($pole['label'] ?? ''));
    $tresc    = implode(', ', $teksty);
    return sanitize_text_field($etykieta !== '' && $etykieta !== $tresc ? $etykieta . ': ' . $tresc : $tresc);
}

/** Identyfikator pierwszego pola danego typu. */
function evk_nl_bricks_pierwsze_pole(array $ustawienia, string $typ): string {
    foreach ((array) ($ustawienia['fields'] ?? []) as $pole) {
        if (is_array($pole) && ($pole['type'] ?? '') === $typ) return (string) ($pole['id'] ?? '');
    }
    return '';
}

/** Komunikat błędu w formularzu (typ `danger` — ten, który Bricks pokazuje jako błąd). */
function evk_nl_bricks_blad($form, string $komunikat): void {
    if (!method_exists($form, 'set_result')) return;
    $form->set_result([
        'action'  => EVK_NL_BRICKS_AKCJA,
        'type'    => 'danger',
        'message' => $komunikat,
    ]);
}

add_action('bricks/form/action/' . EVK_NL_BRICKS_AKCJA, 'evk_nl_bricks_akcja');

/**
 * Zapis z formularza Bricksa. Sukces nic nie ustawia — Bricks pokazuje wtedy
 * komunikat z ustawień formularza (autor strony wpisuje tam „sprawdź skrzynkę",
 * jeśli zapis idzie z potwierdzeniem). Błąd idzie przez set_result().
 */
function evk_nl_bricks_akcja($form): void {
    if (!is_object($form) || !method_exists($form, 'get_settings') || !method_exists($form, 'get_fields')) return;
    $ustawienia = (array) $form->get_settings();
    $pola       = (array) $form->get_fields();

    if (empty(get_option('evk_newsletter', [])['enabled'])) {
        evk_nl_bricks_blad($form, 'Zapisy do newslettera są wyłączone.');
        return;
    }

    /* Pole zgody: bez zaznaczenia ta osoba newslettera nie chce — akcja nic
       nie robi i niczego nie zgłasza, reszta formularza (wiadomość, rejestracja)
       idzie normalnie. */
    $zgoda = '';
    $zgoda_id = evk_nl_bricks_id($ustawienia['evkNlZgoda'] ?? '');
    if ($zgoda_id !== '') {
        $zaznaczone = evk_nl_bricks_wartosc($pola, $zgoda_id);
        if ($zaznaczone === '') return;
        $zgoda = evk_nl_bricks_tekst_zgody($ustawienia, $zgoda_id, $zaznaczone);
    }

    $email_id = evk_nl_bricks_id($ustawienia['evkNlEmail'] ?? '') ?: evk_nl_bricks_pierwsze_pole($ustawienia, 'email');
    $email    = sanitize_email(evk_nl_bricks_wartosc($pola, $email_id));
    if ($email === '') {
        evk_nl_bricks_blad($form, 'Podaj adres e-mail, aby zapisać się do newslettera.');
        return;
    }

    $znaczniki = [];
    foreach ((array) ($ustawienia['evkNlPola'] ?? []) as $pole) {
        $id = evk_nl_bricks_id($pole);
        if ($id === '' || $id === $email_id || $id === $zgoda_id) continue;
        $wartosc = evk_nl_bricks_wartosc($pola, $id);
        if ($wartosc !== '') $znaczniki[evk_nl_bricks_klucz($ustawienia, $id)] = $wartosc;
    }

    $zrodlo = 'formularz Bricksa ' . sanitize_key((string) ($pola['formId'] ?? ''))
            . (!empty($pola['postId']) ? ' (wpis ' . (int) $pola['postId'] . ')' : '');
    $wynik = evk_nl_zapisz_z_formularza(
        (int) ($ustawienia['evkNlLista'] ?? 0), $email, $znaczniki, $zgoda,
        empty($ustawienia['evkNlBezPotwierdzenia']), $zrodlo
    );
    if (!$wynik['ok']) evk_nl_bricks_blad($form, $wynik['msg']);
}
