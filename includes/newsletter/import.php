<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — import z podglądem, mapowaniem kolumn i listą
 * wykluczeń (1.233.0)
 *
 * Do 1.232.x import był jednym kliknięciem „Importuj": plik szedł od razu do
 * bazy, z pliku brany był wyłącznie adres, a o tym, co się stało, mówiły
 * cztery liczby po fakcie. Teraz:
 *
 *   — PODGLĄD przed zapisem: kolumny pliku z przykładami, liczby (nowe / już
 *     na liście / wykluczone / błędne) i pierwsze wiersze z tym, co się z nimi
 *     stanie. Import idzie tą samą funkcją co podgląd — liczby z podglądu to
 *     dokładnie to, co zrobi import.
 *   — MAPOWANIE KOLUMN: kolumna z adresem wykrywana sama, pozostałe kolumny
 *     z nagłówkiem trafiają do subskrybenta jako znaczniki („Imię" → {imie}),
 *     każdą można pominąć.
 *   — LISTA WYKLUCZEŃ: import nigdy nie dopisuje adresu, który ktoś wypisał
 *     z DOWOLNEJ listy, który odbił się przy wysyłce, który usunięto na żądanie
 *     RODO, ani wpisanego ręcznie na listę wykluczeń.
 */

// =========================================================================
// LISTA WYKLUCZEŃ
// =========================================================================

/** Opcja z wykluczeniami: adres (małymi literami) => ['powod' => reczny|odbity, 'kiedy', 'szczegol']. */
const EVK_NL_WYKLUCZENIA = 'evk_nl_wykluczenia';

function evk_nl_wykluczenia(): array {
    $w = get_option(EVK_NL_WYKLUCZENIA, []);
    return is_array($w) ? $w : [];
}

/**
 * Dopisuje adres do wykluczeń i wyłącza go na WSZYSTKICH listach: lista
 * wykluczeń znaczy „nie wysyłać", więc zapisany adres przestaje dostawać
 * maile od razu, a nie dopiero przy następnym imporcie. Ponowne włączenie to
 * osobna, świadoma decyzja (Reaktywuj na liście subskrybentów).
 */
function evk_nl_wyklucz(string $email, string $powod, string $szczegol = ''): bool {
    $email = strtolower(trim($email));
    if (!is_email($email)) return false;
    $w = evk_nl_wykluczenia();
    $w[$email] = ['powod' => $powod, 'kiedy' => current_time('mysql'), 'szczegol' => $szczegol];
    update_option(EVK_NL_WYKLUCZENIA, $w, false);

    global $wpdb;
    $t = evk_nl_table('subscribers');
    $wpdb->query($wpdb->prepare(
        "UPDATE $t SET status = 0, unsubscribed_at = %s WHERE email = %s AND status IN (1, 2)",
        current_time('mysql'), $email
    ));
    return true;
}

function evk_nl_usun_wykluczenie(string $email): bool {
    $email = strtolower(trim($email));
    $w = evk_nl_wykluczenia();
    if (!isset($w[$email])) return false;
    unset($w[$email]);
    update_option(EVK_NL_WYKLUCZENIA, $w, false);
    return true;
}

/**
 * Czy odmowa serwera znaczy „ta skrzynka nie istnieje". Tylko rozszerzone
 * kody 5.1.x (zły adres odbiorcy: 5.1.1 brak skrzynki, 5.1.2 brak domeny,
 * 5.1.3 zła składnia, 5.1.6 skrzynka przeniesiona, 5.1.10 domena bez poczty).
 * NIE 5.7.x („relaying denied", blokady) — tak odpowiada źle ustawiony serwer
 * na KAŻDEGO odbiorcę, a to wyłączyłoby całą listę.
 */
function evk_nl_to_odbicie(string $kod_rozszerzony): bool {
    return (bool) preg_match('/^5\.1\.(1|2|3|6|10)$/', trim($kod_rozszerzony));
}

// =========================================================================
// TABELA Z PLIKU ALBO WKLEJKI
// =========================================================================

/**
 * Plik CSV/TXT albo wklejka jako tabela. Separator wykrywany jak dotąd
 * (evk_nl_csv_separator: przecinek, średnik, tabulator), BOM z Excela
 * zdejmowany. Nagłówek: pierwszy wiersz, w którym nie ma żadnego „@", jeśli
 * niżej są adresy.
 *
 * Zwraca ['naglowek' => string[]|null, 'wiersze' => string[][], 'kolumny' => int].
 */
function evk_nl_import_tabela(string $tresc): array {
    $tresc = (string) preg_replace('/^\xEF\xBB\xBF/', '', $tresc);
    $linie = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($tresc)) ?: [], static fn($l) => trim($l) !== ''));
    $sep   = evk_nl_csv_separator($linie);
    $wiersze = [];
    $kolumny = 0;
    foreach ($linie as $linia) {
        $pola = array_map(static fn($p) => trim((string) $p), str_getcsv($linia, $sep, '"', ''));
        $wiersze[] = $pola;
        $kolumny = max($kolumny, count($pola));
    }
    $naglowek = null;
    if (count($wiersze) > 1 && strpos(implode('', $wiersze[0]), '@') === false) {
        $naglowek = array_shift($wiersze);
    }
    return ['naglowek' => $naglowek, 'wiersze' => $wiersze, 'kolumny' => $kolumny];
}

/**
 * Adres z komórki: ściśle (evk_nl_czysty_email) albo z postaci
 * „Jan Kowalski <jan@firma.pl>" — to jedyny wyjątek od ścisłości, bo tak
 * wygląda adres skopiowany z programu pocztowego. „jan@firma.pl;Jan" dalej
 * NIE jest adresem (audyt 1.229.6: sklejone pola liczone jako dodane).
 */
function evk_nl_import_adres(string $komorka): string {
    $adres = evk_nl_czysty_email($komorka);
    if ($adres !== '') return $adres;
    if (preg_match('/<([^<>\s]+@[^<>\s]+)>\s*$/u', $komorka, $m)) return evk_nl_czysty_email($m[1]);
    return '';
}

/** Klucz znacznika z nagłówka kolumny: „Imię" → imie, „Nazwa firmy" → nazwa_firmy. */
function evk_nl_import_klucz(string $naglowek): string {
    $klucz = sanitize_key(preg_replace('/[\s\-]+/', '_', remove_accents(trim($naglowek))));
    return ltrim($klucz, '_');   // „_…" to zapis wewnętrzny (zgoda, IP) — nie do nadpisania z pliku
}

/**
 * Mapowanie proponowane: adres — kolumna z największą liczbą adresów wśród
 * pierwszych 50 wierszy; pozostałe kolumny z nagłówkiem — znaczniki z jego
 * nazwy. Bez nagłówka nie wiadomo, co jest czym: reszta kolumn domyślnie
 * pominięta.
 *
 * Mapa: ['email' => int|null, 'pola' => [indeks kolumny => klucz]].
 */
function evk_nl_import_mapa_domyslna(array $tabela): array {
    $trafienia = array_fill(0, max(1, (int) $tabela['kolumny']), 0);
    foreach (array_slice($tabela['wiersze'], 0, 50) as $wiersz) {
        foreach ($wiersz as $i => $komorka) {
            if (evk_nl_import_adres($komorka) !== '') $trafienia[$i]++;
        }
    }
    arsort($trafienia);
    $email = (int) array_key_first($trafienia);
    if ($trafienia[$email] === 0) $email = 0;

    $pola = [];
    foreach ((array) $tabela['naglowek'] as $i => $nazwa) {
        if ($i === $email) continue;
        $klucz = evk_nl_import_klucz((string) $nazwa);
        if ($klucz === '') continue;
        $baza = $klucz;
        for ($n = 2; in_array($klucz, $pola, true); $n++) $klucz = $baza . '_' . $n;
        $pola[$i] = $klucz;
    }
    return ['email' => $email, 'pola' => $pola];
}

/** Mapa z żądania (JSON z panelu) — tylko indeksy istniejących kolumn i poprawne klucze. */
function evk_nl_import_mapa_z_zadania($surowa, array $tabela): array {
    $mapa = is_string($surowa) ? json_decode($surowa, true) : $surowa;
    if (!is_array($mapa) || !isset($mapa['email'])) return evk_nl_import_mapa_domyslna($tabela);
    $kolumny = (int) $tabela['kolumny'];
    $email   = (int) $mapa['email'];
    if ($email < 0 || $email >= max(1, $kolumny)) $email = 0;
    $pola = [];
    foreach ((array) ($mapa['pola'] ?? []) as $i => $klucz) {
        $i = (int) $i;
        $klucz = evk_nl_import_klucz((string) $klucz);
        if ($i === $email || $i < 0 || $i >= $kolumny || $klucz === '' || in_array($klucz, $pola, true)) continue;
        $pola[$i] = $klucz;
    }
    return ['email' => $email, 'pola' => $pola];
}

// =========================================================================
// PODGLĄD I IMPORT — ta sama funkcja
// =========================================================================

/**
 * Przechodzi przez wiersze tabeli: ocenia każdy i — gdy `$zapisz` — dopisuje
 * nowych. Podgląd i import to TO SAMO przejście, więc liczby z podglądu są
 * dokładnie tym, co zrobi import.
 *
 * Stany wiersza: nowy, jest (już na tej liście), duplikat (drugi raz
 * w pliku), wykluczony (powód: wypisany, odbity, reczny, rodo), bledny.
 *
 * Zwraca liczby w dotychczasowych kluczach (added, skipped = jest+duplikat,
 * unsubscribed = wykluczeni, invalid), rozbicie wykluczeń w `powody`
 * i `probka` — pierwsze wiersze z ich stanem.
 */
function evk_nl_import_przejdz(int $list_id, array $tabela, array $mapa, bool $zapisz, int $probka = 20): array {
    global $wpdb;
    $t = evk_nl_table('subscribers');

    // Wszystko, o co pytamy przy każdym wierszu — jednym zapytaniem, nie tysiącem.
    $na_liscie = [];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT email, status FROM $t WHERE list_id = %d", $list_id), ARRAY_A) ?: [] as $r) {
        $na_liscie[strtolower($r['email'])] = (int) $r['status'];
    }
    $wypisani = array_flip(array_map('strtolower', $wpdb->get_col("SELECT DISTINCT email FROM $t WHERE status = 0") ?: []));
    $wykluczenia = evk_nl_wykluczenia();
    $zrodlo = 'import z pliku' . (wp_get_current_user()->user_login ? ' (' . wp_get_current_user()->user_login . ')' : '');

    $wynik = ['added' => 0, 'skipped' => 0, 'unsubscribed' => 0, 'invalid' => 0,
              'powody' => ['wypisany' => 0, 'odbity' => 0, 'reczny' => 0, 'rodo' => 0], 'probka' => []];
    $widziane = [];
    foreach ($tabela['wiersze'] as $wiersz) {
        $adres = evk_nl_import_adres((string) ($wiersz[$mapa['email']] ?? ''));
        $pola  = [];
        foreach ($mapa['pola'] as $i => $klucz) {
            $wartosc = sanitize_text_field((string) ($wiersz[$i] ?? ''));
            if ($wartosc !== '') $pola[$klucz] = $wartosc;
        }
        $male = strtolower($adres);
        $powod = '';
        if ($adres === '') {
            $stan = 'bledny';
        } elseif (isset($widziane[$male])) {
            $stan = 'duplikat';
        } elseif (isset($na_liscie[$male]) && $na_liscie[$male] !== 0) {
            $stan = 'jest';
        } elseif (isset($na_liscie[$male])) {
            $stan = 'wykluczony'; $powod = 'wypisany';
        } elseif (isset($wykluczenia[$male])) {
            $stan = 'wykluczony'; $powod = (string) ($wykluczenia[$male]['powod'] ?? 'reczny');
        } elseif (isset($wypisani[$male])) {
            $stan = 'wykluczony'; $powod = 'wypisany';
        } elseif (evk_nl_zablokowany($adres)) {
            $stan = 'wykluczony'; $powod = 'rodo';
        } else {
            $stan = 'nowy';
        }
        if ($adres !== '') $widziane[$male] = true;

        switch ($stan) {
            case 'nowy':
                if ($zapisz) {
                    $utworzony = false;
                    $id = evk_nl_add_subscriber($list_id, $adres, $pola + ['_consent_source' => $zrodlo], $utworzony);
                    if (!$id)         { $stan = 'bledny'; $wynik['invalid']++; break; }
                    if (!$utworzony)  { $stan = 'jest';   $wynik['skipped']++; break; }
                }
                $wynik['added']++;
                break;
            case 'jest':
            case 'duplikat':
                $wynik['skipped']++;
                break;
            case 'wykluczony':
                $wynik['unsubscribed']++;
                $wynik['powody'][$powod] = ($wynik['powody'][$powod] ?? 0) + 1;
                break;
            default:
                $wynik['invalid']++;
        }
        if (count($wynik['probka']) < $probka) {
            $wynik['probka'][] = ['komorki' => array_slice($wiersz, 0, 8), 'adres' => $adres, 'stan' => $stan, 'powod' => $powod, 'pola' => $pola];
        }
    }
    return $wynik;
}
