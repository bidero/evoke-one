<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — „Do sprawdzenia": pole języka w elemencie, którego
 * oryginał zmienił się po tłumaczeniu (1.242.0).
 *
 * Decyzja zgłaszającego: po zmianie polskiego tekstu stare tłumaczenie
 * zostaje (tak działa pole w elemencie, 51-translation-element-fields.php),
 * ale wtyczka ma je oznaczyć. Strona dalej pokazuje stare tłumaczenie, a
 * miejsce trafia na listę w panelu Tłumaczeń.
 *
 * JAK. Przy każdym zapisie danych Bricksa (treść strony, nagłówek, stopka —
 * `added_post_meta` / `updated_post_meta`) wtyczka zapamiętuje dla każdego
 * pola języka dwa skróty: tłumaczenia i oryginału, z którego powstało.
 * Zmieniło się tłumaczenie — skrót oryginału bierze się od nowa (ktoś
 * właśnie przetłumaczył bieżący tekst). Tłumaczenie bez zmian, a oryginał
 * inny niż zapamiętany — miejsce jest do sprawdzenia. Przycisk „Sprawdzone"
 * przyjmuje bieżący oryginał bez zmiany tłumaczenia.
 *
 * Stan leży we WŁASNYCH metadanych wpisu (`_evk_tl_el_stan`), a nie w danych
 * Bricksa: nie piszemy w cudzy format, którego nie da się tu sprawdzić
 * (CLAUDE.md). Tłumaczenia sprzed tej wersji dostają stan przy pierwszym
 * zapisie strony — od tej chwili uchodzą za aktualne.
 *
 * ZAPIS BRICKSA idzie przez update_post_meta (standard WordPressa) —
 * potwierdzone na stronie testowej przy 1.244.0: ramka „Do sprawdzenia"
 * pojawia się po zmianie polskiego tekstu. Komponenty i elementy globalne
 * leżą w opcjach — ten etap ich nie obejmuje.
 */

const EVK_TL_EL_STAN = '_evk_tl_el_stan';

/** Klucze metadanych z treścią Bricksa: strona, nagłówek, stopka. */
function evk_tl_el_klucze_meta(): array {
    return [
        defined('BRICKS_DB_PAGE_CONTENT') ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2',
        defined('BRICKS_DB_PAGE_HEADER') ? BRICKS_DB_PAGE_HEADER : '_bricks_page_header_2',
        defined('BRICKS_DB_PAGE_FOOTER') ? BRICKS_DB_PAGE_FOOTER : '_bricks_page_footer_2',
    ];
}

/** Skrót tekstu bez znaczenia dla odstępów na brzegach. */
function evk_tl_el_skrot(string $tekst): string {
    return md5(trim($tekst));
}

/**
 * Wszystkie pola języków w danych Bricksa: klucz miejsca
 * („id elementu|ścieżka pola|język") → oryginał, tłumaczenie i opis.
 * W pozycjach list ścieżka to „lista.id-pozycji.pole" (albo numer pozycji,
 * gdy pozycja nie ma identyfikatora).
 *
 * @param mixed $elementy
 * @return array<string,array<string,string>>
 */
function evk_tl_el_miejsca($elementy): array {
    $out = [];
    if (!is_array($elementy)) return $out;
    foreach ($elementy as $el) {
        if (!is_array($el) || !is_array($el['settings'] ?? null)) continue;
        evk_tl_el_zbierz($el['settings'], (string) ($el['id'] ?? ''), (string) ($el['name'] ?? ''), '', $out);
    }
    return $out;
}

/**
 * @param array<string,mixed> $ustawienia
 * @param array<string,array<string,string>> $out
 */
function evk_tl_el_zbierz(array $ustawienia, string $id, string $nazwa, string $sciezka, array &$out): void {
    foreach ($ustawienia as $k => $v) {
        $k = (string) $k;
        if (preg_match('/^evk_tl_([a-z0-9_]+?)__(.+)$/', $k, $m)) {
            if (!is_string($v)) continue;
            $zrodlo = isset($ustawienia[$m[2]]) && is_string($ustawienia[$m[2]]) ? $ustawienia[$m[2]] : '';
            $out[$id . '|' . $sciezka . $m[2] . '|' . $m[1]] = [
                'element' => $nazwa, 'pole' => $sciezka . $m[2], 'jezyk' => $m[1],
                'oryginal' => $zrodlo, 'tlumaczenie' => $v,
            ];
        } elseif (is_array($v) && $v && array_keys($v) === range(0, count($v) - 1)) {
            foreach ($v as $i => $pozycja) {
                if (!is_array($pozycja)) continue;
                $pid = isset($pozycja['id']) && is_scalar($pozycja['id']) && (string) $pozycja['id'] !== '' ? (string) $pozycja['id'] : (string) $i;
                evk_tl_el_zbierz($pozycja, $id, $nazwa, $sciezka . $k . '.' . $pid . '.', $out);
            }
        }
    }
}

/**
 * Nowy stan po zapisie jednej treści Bricksa. Zmienione (albo nowe)
 * tłumaczenie → skrót bieżącego oryginału; tłumaczenie bez zmian → stary
 * skrót oryginału zostaje. Puste tłumaczenie wypada ze stanu.
 *
 * @param array<string,array<string,string>> $stary
 * @param array<string,array<string,string>> $miejsca
 * @return array<string,array<string,string>>
 */
function evk_tl_el_nowy_stan(array $stary, array $miejsca): array {
    $nowy = [];
    foreach ($miejsca as $klucz => $m) {
        if (!function_exists('evk_tl_el_niepuste') || !evk_tl_el_niepuste($m['tlumaczenie'])) continue;
        $tl = evk_tl_el_skrot($m['tlumaczenie']);
        $byl = $stary[$klucz] ?? null;
        $nowy[$klucz] = (is_array($byl) && ($byl['tl'] ?? '') === $tl && isset($byl['src']))
            ? $byl
            : ['src' => evk_tl_el_skrot($m['oryginal']), 'tl' => $tl];
    }
    return $nowy;
}

/**
 * @param mixed $wartosc Rozpakowana wartość z `updated_post_meta` / `added_post_meta`.
 */
function evk_tl_el_meta_zmieniona($meta_id, $post_id, $meta_key, $wartosc): void {
    if (!in_array((string) $meta_key, evk_tl_el_klucze_meta(), true)) return;
    $post_id = (int) $post_id;
    /* Kopia danych w rewizji — pomijamy. update_post_meta() rewizji WordPress
       przekierowuje na rodzica, więc stan policzony od zera z kopii
       nadpisałby stan strony i wyczyścił „Do sprawdzenia" (1.246.0). */
    if (wp_is_post_revision($post_id)) return;
    $stan = get_post_meta($post_id, EVK_TL_EL_STAN, true);
    if (!is_array($stan)) $stan = [];
    $czesc = evk_tl_el_nowy_stan(is_array($stan[$meta_key] ?? null) ? $stan[$meta_key] : [], evk_tl_el_miejsca(maybe_unserialize($wartosc)));
    if ($czesc) $stan[$meta_key] = $czesc; else unset($stan[$meta_key]);
    /* wp_slash: update_post_meta zdejmuje ukośniki, a stan niesie teksty —
       poprzednią wersję (`poprz`, 1.262.0). Bez tego każdy zapis treści
       gubił ukośniki wsteczne z poprzedniej wersji (1.264.0). */
    if ($stan) update_post_meta($post_id, EVK_TL_EL_STAN, wp_slash($stan));
    else delete_post_meta($post_id, EVK_TL_EL_STAN);
}
add_action('added_post_meta', 'evk_tl_el_meta_zmieniona', 10, 4);
add_action('updated_post_meta', 'evk_tl_el_meta_zmieniona', 10, 4);

/**
 * Lista „Do sprawdzenia": miejsca, w których bieżący oryginał różni się od
 * tego, z którego powstało tłumaczenie. Liczone przy odczycie z bieżących
 * danych — oryginał cofnięty do dawnej postaci przestaje być na liście sam.
 * Tłumaczenie AI (61) ma źródło `ai` — różne od każdego skrótu, więc jest na
 * liście, dopóki ktoś go nie przyjmie; `ai` mówi, skąd się tu wzięło.
 *
 * @return list<array<string,mixed>>
 */
function evk_tl_el_do_sprawdzenia(int $limit = 200): array {
    global $wpdb;
    /* Wprost po kluczu metadanych, nie get_posts(['post_type' => 'any']):
       „any" pomija typy wyłączone z wyszukiwania, a takie bywają szablony
       Bricksa — nagłówek i stopka wypadłyby z listy. */
    $wpisy = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id LIMIT %d", EVK_TL_EL_STAN, $limit)));
    $out = [];
    foreach ($wpisy as $post_id) {
        $stan = get_post_meta($post_id, EVK_TL_EL_STAN, true);
        if (!is_array($stan)) continue;
        foreach ($stan as $meta_key => $czesc) {
            if (!is_array($czesc)) continue;
            $miejsca = evk_tl_el_miejsca(get_post_meta($post_id, (string) $meta_key, true));
            foreach ($czesc as $klucz => $s) {
                $m = $miejsca[$klucz] ?? null;
                if (!$m || !is_array($s) || evk_tl_el_skrot($m['oryginal']) === ($s['src'] ?? '')) continue;
                if (!evk_tl_el_niepuste($m['tlumaczenie'])) continue;
                $out[] = $m + ['post_id' => (int) $post_id, 'meta_key' => (string) $meta_key, 'klucz' => (string) $klucz,
                    'ai' => ($s['src'] ?? '') === 'ai'];
            }
        }
    }
    return $out;
}

/** „Sprawdzone": bieżący oryginał staje się tym, z którego jest tłumaczenie. */
function evk_tl_el_oznacz_sprawdzone(int $post_id, string $meta_key, string $klucz): bool {
    if (!in_array($meta_key, evk_tl_el_klucze_meta(), true)) return false;
    $stan = get_post_meta($post_id, EVK_TL_EL_STAN, true);
    if (!is_array($stan) || !isset($stan[$meta_key][$klucz])) return false;
    $m = evk_tl_el_miejsca(get_post_meta($post_id, $meta_key, true))[$klucz] ?? null;
    if (!$m) return false;
    $stan[$meta_key][$klucz]['src'] = evk_tl_el_skrot($m['oryginal']);
    update_post_meta($post_id, EVK_TL_EL_STAN, wp_slash($stan));
    return true;
}

add_action('wp_ajax_evk_tl_el_sprawdzone', function (): void {
    check_ajax_referer('evk_tl_el_sprawdzone', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('evk_access_translations')) {
        wp_send_json_error('Brak uprawnień.', 403);
    }
    $post_id = absint($_POST['post_id'] ?? 0);
    $meta_key = sanitize_text_field(wp_unslash($_POST['meta_key'] ?? ''));
    $klucz = (string) wp_unslash($_POST['klucz'] ?? '');
    /* Pole Evoke FIELDS (1.267.0): klucz `{miejsce}|{język}`, zapis przez Fields, z prawem edycji wpisu. */
    if (defined('EVK_TL_AI_POLA') && $meta_key === EVK_TL_AI_POLA && function_exists('evk_fields_tl_sprawdzone')) {
        if (!current_user_can('edit_post', $post_id)) wp_send_json_error('Brak uprawnień do tej strony.', 403);
        $p = strrpos($klucz, '|');
        $ok = $p !== false && evk_fields_tl_sprawdzone($post_id, substr($klucz, 0, $p), substr($klucz, $p + 1));
    } else {
        $ok = evk_tl_el_oznacz_sprawdzone($post_id, $meta_key, $klucz);
    }
    $ok ? wp_send_json_success() : wp_send_json_error('Nie ma już takiego miejsca — odśwież stronę.');
});

/** Adres edycji wpisu w builderze (funkcja Bricksa, gdy jest). */
function evk_tl_el_adres_edycji(int $post_id): string {
    if (function_exists('bricks_get_builder_edit_link')) return (string) bricks_get_builder_edit_link($post_id);
    return add_query_arg('bricks', 'run', (string) get_permalink($post_id));
}

/**
 * Adres miejsca w trybie sprawdzania na stronie (62, 1.261.0): strona w języku
 * z parametrem trybu i kotwicą elementu, którą skrypt trybu otwiera. Nagłówek
 * i stopka z szablonu — na stronie głównej (są na każdej). Szablon treści nie
 * ma jednej strony — bez adresu.
 */
function evk_tl_el_adres_sprawdzania(int $post_id, string $meta_key, string $jezyk, string $klucz): string {
    if (!function_exists('evk_tl_sprawdz_adres')) return '';
    $k = evk_tl_el_klucze_meta();
    if (get_post_type($post_id) === 'bricks_template') {
        if ($meta_key !== $k[1] && $meta_key !== $k[2]) return '';
        $adres = add_query_arg(EVK_TL_SPRAWDZ_PARAM, '1', function_exists('tl_url_jezyka') ? tl_url_jezyka(home_url('/'), $jezyk) : home_url('/'));
    } else {
        $adres = evk_tl_sprawdz_adres($post_id, $jezyk);
    }
    return $adres === '' ? '' : $adres . '#evk-tls=' . rawurlencode((string) strtok($klucz, '|'));
}

/** Sekcja „Do sprawdzenia" nad frazami w zakładce Tłumaczenia — tylko gdy jest co pokazać. */
function evk_tl_el_sekcja_do_sprawdzenia(): void {
    $lista = evk_tl_el_do_sprawdzenia();
    /* Pola Evoke FIELDS (1.267.0) — AI albo zmieniony oryginał, edycja w metaboksie wpisu. */
    if (function_exists('evk_tl_ai_pola_do_sprawdzenia')) $lista = array_merge($lista, evk_tl_ai_pola_do_sprawdzenia());
    if (!$lista) return;
    $skrot = static function (string $t): string {
        // Ta sama funkcja co lista tekstów (53): akapity nie sklejają się w jeden wyraz.
        if (function_exists('evk_tl_el_skrot_tekstu')) return evk_tl_el_skrot_tekstu($t);
        $t = trim(wp_strip_all_tags($t));
        return mb_strlen($t) > 80 ? mb_substr($t, 0, 79) . '…' : $t;
    };
    ?>
    <div class="evo-box tl-do-sprawdzenia" data-nonce="<?php echo esc_attr(wp_create_nonce('evk_tl_el_sprawdzone')); ?>">
        <h3>Tłumaczenia w elementach do sprawdzenia (<?php echo (int) count($lista); ?>)</h3>
        <p class="evo-desc">Tłumaczenie zrobiło AI albo oryginał zmienił się po przetłumaczeniu. Strona pokazuje obecne tłumaczenie.
        Popraw je wprost na stronie („Na stronie”), w Bricksie (pola Evoke FIELDS — w edycji wpisu) albo oznacz jako sprawdzone, jeśli pasuje.</p>
        <div class="evo-tbl-wrap"><table class="evo-table">
            <thead><tr><th scope="col">Strona</th><th scope="col">Pole</th><th scope="col">Język</th><th scope="col">Powód</th>
                <th scope="col">Oryginał teraz</th><th scope="col">Tłumaczenie</th><th scope="col"><span class="screen-reader-text">Akcja</span></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $m):
                $tytul = get_the_title($m['post_id']) ?: ('#' . $m['post_id']);
                $jezyk = strtoupper(str_replace('_', '-', $m['jezyk']));
                $pole_fields = defined('EVK_TL_AI_POLA') && $m['meta_key'] === EVK_TL_AI_POLA;
                $naStronie = $pole_fields ? '' : evk_tl_el_adres_sprawdzania((int) $m['post_id'], (string) $m['meta_key'], (string) $m['jezyk'], (string) $m['klucz']);
                $edycja = $pole_fields ? (string) get_edit_post_link((int) $m['post_id'], 'raw') : evk_tl_el_adres_edycji($m['post_id']); ?>
                <tr>
                    <td><a href="<?php echo esc_url($edycja); ?>"><?php echo esc_html($tytul); ?></a></td>
                    <td><?php echo esc_html($m['element'] . ': ' . $m['pole']); ?></td>
                    <td><?php echo esc_html($jezyk); ?></td>
                    <td><?php echo !empty($m['ai']) ? 'AI' : 'Zmienił się oryginał'; ?></td>
                    <td><?php echo esc_html($skrot($m['oryginal'])); ?></td>
                    <td><?php echo esc_html($skrot($m['tlumaczenie'])); ?></td>
                    <?php /* Nazwy z kontekstem: czytnik ekranu słyszałby inaczej rząd identycznych „Sprawdzone" i „Na stronie". */
                          $kontekst = $tytul . ', ' . $m['pole'] . ', ' . $jezyk; ?>
                    <td class="tl-do-sprawdzenia-akcje">
                        <?php if ($naStronie !== ''): ?>
                        <a class="button button-small" href="<?php echo esc_url($naStronie); ?>" aria-label="<?php echo esc_attr('Na stronie: ' . $kontekst); ?>">Na stronie</a>
                        <?php endif; ?>
                        <button type="button" class="button button-small tl-el-sprawdzone" data-post="<?php echo (int) $m['post_id']; ?>"
                        data-meta="<?php echo esc_attr($m['meta_key']); ?>" data-klucz="<?php echo esc_attr($m['klucz']); ?>"
                        aria-label="<?php echo esc_attr('Sprawdzone: ' . $kontekst); ?>">Sprawdzone</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <script>
    (function () {
        var box = document.querySelector('.tl-do-sprawdzenia');
        if (!box) return;
        box.addEventListener('click', function (e) {
            var b = e.target.closest('.tl-el-sprawdzone');
            if (!b) return;
            b.disabled = true;
            var fd = new FormData();
            fd.append('action', 'evk_tl_el_sprawdzone');
            fd.append('nonce', box.getAttribute('data-nonce'));
            fd.append('post_id', b.getAttribute('data-post'));
            fd.append('meta_key', b.getAttribute('data-meta'));
            fd.append('klucz', b.getAttribute('data-klucz'));
            fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (r && r.success) { b.closest('tr').remove(); if (!box.querySelector('tbody tr')) box.remove(); }
                    else { b.disabled = false; b.textContent = (r && r.data) || 'Błąd — spróbuj jeszcze raz'; }
                })
                .catch(function () { b.disabled = false; });
        });
    })();
    </script>
    <?php
}
