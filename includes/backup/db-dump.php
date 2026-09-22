<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — zrzut bazy do JSONL, wznawialny między krokami.
 *
 * DLACZEGO JSONL, A NIE SQL. Przy przenosinach strony restore podmienia stary
 * adres na nowy W WARTOŚCI KOLUMNY (serialize-replace.php). W gotowym
 * `INSERT INTO …` ta wartość jest schowana pod dwiema warstwami ucieczek —
 * SQL-ową nad serializacją PHP — i podmiana na tekście zapytania to proszenie
 * się o uszkodzone dane. W JSONL każda wartość leży osobno: restore dekoduje
 * wiersz, podmienia, wstawia przez $wpdb, a ucieczki robi WordPress.
 * Konsekwencja: plik NIE jest importowalny przez phpMyAdmin.
 *
 * FORMAT — jedna linia JSON na zdarzenie:
 *
 *   {"table":"wp_options","create":"CREATE TABLE …","cols":[…],"pk":["option_id"]}
 *   {"table":"wp_options","row":{"option_id":"1","option_value":"…"}}
 *   {"table":"wp_x","row":{"id":"7","dane":"AAEC…"},"b64":["dane"]}
 *
 * Nagłówek tabeli poprzedza jej wiersze. Wartości to łańcuchy albo null —
 * dokładnie to, co oddaje $wpdb; NULL i pusty łańcuch zostają rozróżnione.
 * Wartość, która nie jest poprawnym UTF-8 (BLOB, stary latin1, ucięty znak
 * wielobajtowy), idzie jako base64 i jej kolumna trafia do listy `b64` —
 * json_encode() zwróciłby na niej false i wiersz zginąłby po cichu.
 * Kolumny generowane (VIRTUAL/STORED) są pomijane: baza liczy je sama, a próba
 * wstawienia wartości kończy się błędem.
 *
 * CO ZRZUCAMY. Tabele z prefiksem tej instalacji — wyłącznie BASE TABLE;
 * widoki są wymieniane w `skipped`. Bez `evk_backup_jobs`: restore podmienia
 * tabele, a stan zadania, które właśnie przywraca, nie może zniknąć razem
 * z nimi (patrz tables.php).
 *
 * STRONICOWANIE PO KLUCZU (`WHERE pk > ostatni ORDER BY pk LIMIT n`), nie
 * OFFSET — OFFSET na wp_postmeta z setkami tysięcy wierszy przechodzi za każdym
 * razem przez wszystko, co już zrzucone. Tabela bez klucza głównego (rzadkość)
 * idzie OFFSET-em i jest to zapisane w komentarzu przy jej obsłudze.
 *
 * SPÓJNOŚĆ. Zrzut w wielu krokach nie jest migawką — wiersz zmieniony między
 * krokami trafi do kopii w jednym albo drugim stanie. Dla stron, gdzie to
 * ważne (sklep), jest opcja trybu konserwacji na czas zrzutu (ustawienia).
 *
 * WZNAWIANIE — jak w zip-writer.php: stan niesie długość pliku zatwierdzoną
 * po ostatniej paczce, a każde wywołanie najpierw obcina plik do niej. Krok
 * ubity w połowie paczki zostawia za nią śmieci, które w ten sposób znikają.
 */

/**
 * WIELKOŚĆ PACZKI liczona w BAJTACH, nie w wierszach. Zmierzone (1.226.0) na
 * 100 000 wierszy postmeta + 200 wierszach po 300 KB (jak układy Bricksa):
 *
 *   stała paczka   50 wierszy:  2,0 s, szczyt pamięci  +63 MB
 *   stała paczka  200 wierszy:  2,7 s, szczyt pamięci +195 MB
 *   stała paczka 1000 wierszy:  2,4 s, szczyt pamięci +195 MB
 *   paczka ze średniej poprzedniej:  2,2 s, +147 MB — spóźnia się na granicy
 *     drobnych i dużych wierszy (1000 drobnych, potem 200 dużych naraz)
 *   paczka z LENGTH() przed pobraniem:  1,5 s, +16 MB   ← to
 *
 * Przed każdą paczką idzie lekkie zapytanie o sam klucz i sumę LENGTH()
 * kolumn (evk_backup_db_fit) i paczka bierze tyle wierszy, ile mieści się
 * w EVK_BACKUP_DUMP_BYTES — nie więcej niż EVK_BACKUP_DUMP_BATCH, nie mniej
 * niż jeden. 200 wierszy po 300 KB w jednej paczce wysadzało limit 128 MB.
 */
const EVK_BACKUP_DUMP_BATCH = 1000;
const EVK_BACKUP_DUMP_BYTES = 4194304;

/** Cytowanie identyfikatora MySQL. */
function evk_backup_db_ident(string $n): string {
    return '`' . str_replace('`', '``', $n) . '`';
}

/**
 * Tabele do zrzutu: [nazwy BASE TABLE, pominięte widoki].
 * LIKE z esc_like — `_` w prefiksie `wp_` jest w LIKE znakiem dowolnym,
 * więc bez tego złapałaby się tabela `wpXcos` innej instalacji w tej bazie.
 */
function evk_backup_db_tables(): array {
    global $wpdb;
    $wiersze = $wpdb->get_results($wpdb->prepare(
        'SELECT TABLE_NAME AS n, TABLE_TYPE AS t FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
        $wpdb->esc_like($wpdb->prefix) . '%'
    ), ARRAY_A);

    $tabele = [];
    $pominiete = [];
    $bez = evk_backup_jobs_table();
    $nazwy = array_map(static function ($w) { return (string) $w['n']; }, (array) $wiersze);
    $obce = evk_backup_db_foreign_prefixes($nazwy, $wpdb->prefix);
    foreach ((array) $wiersze as $w) {
        $n = (string) $w['n'];
        // LIKE bywa niewrażliwy na wielkość liter — prefiks sprawdzamy dosłownie.
        if (strpos($n, $wpdb->prefix) !== 0 || $n === $bez) continue;
        foreach ($obce as $o) { if (strpos($n, $o) === 0) continue 2; }
        if ($w['t'] === 'BASE TABLE') $tabele[] = $n;
        else $pominiete[] = $n;
    }
    return [$tabele, $pominiete];
}

/**
 * Prefiksy INNYCH instalacji, które zaczynają się od naszego: przy `wp_`
 * druga instalacja w tej samej bazie z prefiksem `wp_sklep_` ma tabele
 * pasujące do `wp_%`. Poznajemy ją po trzech tabelach rdzenia naraz
 * (options, posts, users) — pojedynczą tabelę wtyczki z takim
 * przyrostkiem trudno pomylić z całą instalacją. Bez tego zrzut
 * zabierałby cudzą instalację, a przywracanie by ją usuwało.
 */
function evk_backup_db_foreign_prefixes(array $tabele, string $prefix): array {
    $zbior = array_flip($tabele);
    $obce = [];
    foreach ($tabele as $t) {
        if (strpos($t, $prefix) !== 0 || substr($t, -7) !== 'options' || $t === $prefix . 'options') continue;
        $p = substr($t, 0, -7);
        if (isset($zbior[$p . 'posts'], $zbior[$p . 'users'])) $obce[] = $p;
    }
    return $obce;
}

/** Stan początkowy zrzutu. */
function evk_backup_db_dump_start(): array {
    global $wpdb;
    [$tabele, $pominiete] = evk_backup_db_tables();

    // Szacunek do paska postępu — TABLE_ROWS w InnoDB jest przybliżony.
    $szac = 0;
    if ($tabele) {
        $ph = implode(',', array_fill(0, count($tabele), '%s'));
        $szac = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)",
            $tabele
        ));
    }

    return [
        'tables'   => $tabele,
        'skipped'  => $pominiete,
        'estimate' => $szac,
        'ti'       => 0,
        'cur'      => null,
        'bytes'    => 0,
        'rows'     => 0,
        'done'     => $tabele === [],
    ];
}

/** Opis tabeli do nagłówka i do zapytań: kolumny (bez generowanych) i klucz. */
function evk_backup_db_describe(string $tabela): array {
    global $wpdb;
    $kol = $wpdb->get_results($wpdb->prepare(
        'SELECT COLUMN_NAME AS c, EXTRA AS e FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
        $tabela
    ), ARRAY_A);
    $cols = [];
    $gen  = [];
    foreach ((array) $kol as $k) {
        if (stripos((string) $k['e'], 'GENERATED') !== false) $gen[] = (string) $k['c'];
        else $cols[] = (string) $k['c'];
    }

    $pk = [];
    foreach ((array) $wpdb->get_results('SHOW KEYS FROM ' . evk_backup_db_ident($tabela) . " WHERE Key_name = 'PRIMARY'", ARRAY_A) as $k) {
        $pk[(int) $k['Seq_in_index']] = (string) $k['Column_name'];
    }
    ksort($pk);

    $create = $wpdb->get_row('SHOW CREATE TABLE ' . evk_backup_db_ident($tabela), ARRAY_A);
    if (!$create || empty($create['Create Table'])) {
        throw new \RuntimeException('Nie da się odczytać struktury tabeli ' . $tabela . ': ' . $wpdb->last_error);
    }

    return ['cols' => $cols, 'generated' => $gen, 'pk' => array_values($pk), 'create' => (string) $create['Create Table']];
}

/**
 * Jedna paczka wierszy tabeli od punktu `cur`. Oddaje wiersze (ARRAY_A).
 * $lista = null: kolumny z `cur`; inaczej gotowa lista wyrażeń SELECT.
 */
function evk_backup_db_fetch(string $tabela, array $cur, int $ile, ?string $lista = null): array {
    global $wpdb;
    $lista = $lista ?? implode(', ', array_map('evk_backup_db_ident', $cur['cols']));
    $sql   = 'SELECT ' . $lista . ' FROM ' . evk_backup_db_ident($tabela);

    if ($cur['pk']) {
        $pk    = array_map('evk_backup_db_ident', $cur['pk']);
        $order = ' ORDER BY ' . implode(', ', $pk) . ' LIMIT ' . $ile;
        if ($cur['last'] === null) {
            $wynik = $wpdb->get_results($sql . $order, ARRAY_A);
        } else {
            // Porównanie krotek działa i dla klucza złożonego: (a,b) > (x,y).
            $ph = implode(', ', array_fill(0, count($pk), '%s'));
            $warunek = count($pk) === 1 ? $pk[0] . ' > %s' : '(' . implode(', ', $pk) . ') > (' . $ph . ')';
            $wynik = $wpdb->get_results($wpdb->prepare($sql . ' WHERE ' . $warunek . $order, $cur['last']), ARRAY_A);
        }
    } else {
        /* Bez klucza głównego nie ma czego pamiętać poza liczbą wierszy.
           Kolejność bez ORDER BY nie jest gwarantowana przez SQL, ale InnoDB
           czyta taką tabelę po ukrytym identyfikatorze wiersza — stabilnie,
           dopóki nikt nie wstawia w środek. */
        $wynik = $wpdb->get_results($sql . ' LIMIT ' . (int) $cur['offset'] . ', ' . $ile, ARRAY_A);
    }

    if ($wpdb->last_error !== '') {
        throw new \RuntimeException('Zrzut tabeli ' . $tabela . ' nie powiódł się: ' . $wpdb->last_error);
    }
    return (array) $wynik;
}

/**
 * Ile wierszy od punktu `cur` mieści się w EVK_BACKUP_DUMP_BYTES — z lekkiego
 * zapytania o sumę LENGTH() kolumn, BEZ pobierania ich treści. Zawsze co
 * najmniej 1 (wiersz większy niż limit idzie sam).
 */
function evk_backup_db_fit(string $tabela, array $cur, int $max): int {
    $dlugosc = implode(' + ', array_map(static function ($c) {
        return 'COALESCE(LENGTH(' . evk_backup_db_ident($c) . '), 0)';
    }, $cur['cols']));
    $wiersze = evk_backup_db_fetch($tabela, $cur, $max, '(' . $dlugosc . ') AS evk_dl');
    $suma = 0;
    $ile  = 0;
    foreach ($wiersze as $w) {
        $suma += (int) $w['evk_dl'];
        if ($ile > 0 && $suma > EVK_BACKUP_DUMP_BYTES) break;
        $ile++;
    }
    return max(1, $ile);
}

/** Wiersz → linia JSONL. Wartości spoza UTF-8 idą jako base64. */
function evk_backup_db_encode_row(string $tabela, array $wiersz): string {
    $b64 = [];
    foreach ($wiersz as $k => $v) {
        if ($v !== null && !preg_match('//u', (string) $v)) {
            $wiersz[$k] = base64_encode((string) $v);
            $b64[] = $k;
        }
    }
    $linia = ['table' => $tabela, 'row' => $wiersz];
    if ($b64) $linia['b64'] = $b64;
    $json = json_encode($linia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new \RuntimeException('Nie da się zakodować wiersza tabeli ' . $tabela . ': ' . json_last_error_msg());
    }
    return $json . "\n";
}

/**
 * Pracuje do $deadline (microtime), dopisując do $plik. Oddaje nowy stan.
 * Co najmniej jedna paczka na wywołanie — postęp jest zawsze, nawet przy
 * terminie, który już minął. $paczka to GÓRNA granica wierszy w paczce
 * (testy zaniżają ją, żeby zrzut szedł wieloma krokami).
 */
function evk_backup_db_dump_step(string $plik, array $st, float $deadline, int $paczka = EVK_BACKUP_DUMP_BATCH): array {
    if (!empty($st['done'])) return $st;

    $fh = fopen($plik, 'c+b');
    if (!$fh) throw new \RuntimeException('Nie można otworzyć pliku zrzutu: ' . $plik);
    ftruncate($fh, $st['bytes']);
    fseek($fh, $st['bytes']);

    $zapisz = static function (string $s) use ($fh): void {
        if (fwrite($fh, $s) !== strlen($s)) {
            throw new \RuntimeException('Zapis zrzutu bazy nie powiódł się (brak miejsca na dysku?)');
        }
    };

    try {
        do {
            $tabela = $st['tables'][$st['ti']];

            if ($st['cur'] === null) {
                $opis = evk_backup_db_describe($tabela);
                $naglowek = ['table' => $tabela, 'create' => $opis['create'], 'cols' => $opis['cols'], 'pk' => $opis['pk']];
                if ($opis['generated']) $naglowek['generated'] = $opis['generated'];
                $zapisz(json_encode($naglowek, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $st['cur'] = ['cols' => $opis['cols'], 'pk' => $opis['pk'], 'last' => null, 'offset' => 0];
            }

            $ile = evk_backup_db_fit($tabela, $st['cur'], min($paczka, EVK_BACKUP_DUMP_BATCH));
            $wiersze = evk_backup_db_fetch($tabela, $st['cur'], $ile);
            $bufor = '';
            foreach ($wiersze as $w) $bufor .= evk_backup_db_encode_row($tabela, $w);
            $zapisz($bufor);

            $n = count($wiersze);
            $st['rows'] += $n;
            if ($n > 0) {
                $ostatni = $wiersze[$n - 1];
                $st['cur']['last'] = $st['cur']['pk'] ? array_map(static function ($k) use ($ostatni) { return $ostatni[$k]; }, $st['cur']['pk']) : null;
                $st['cur']['offset'] += $n;
            }
            if ($n < $ile || $n === 0) {
                $st['ti']++;
                $st['cur'] = null;
                if ($st['ti'] >= count($st['tables'])) $st['done'] = true;
            }

            // Zatwierdzenie paczki: dane na dysku, dopiero potem długość w stanie.
            fflush($fh);
            $st['bytes'] = ftell($fh);
        } while (!$st['done'] && microtime(true) < $deadline);
    } finally {
        fclose($fh);
    }

    return $st;
}
