<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — snippety: lista wpisów i edytor.
 *
 * Zastępuje cztery stałe okna z lat sprzed 1.139.0. Ekran ma dwa stany:
 * LISTA (co masz i co pracuje) oraz EDYTOR jednego wpisu. Logi błędów
 * i tryb zaawansowany zostają tam, gdzie były.
 */

/** Adres zakładki — jedno miejsce, bo składa go i lista, i każdy formularz. */
function evk_snippety_url(array $args = []): string {
    return add_query_arg($args, admin_url('options-general.php?page=evoke-one&tab=narzedzia&sub=snippets'));
}

function evk_snippets_render_tab(): void {
    if (!current_user_can('manage_options')) return;

    $wylaczone_stala = defined('EVK_CODE_DISABLE') && EVK_CODE_DISABLE;
    $wlaczone        = (int) get_option(EVK_SNIPPETS_ENABLED_OPTION, 0);
    $adv             = (int) get_option(EVK_SNIPPETS_ADVANCED_ENABLED, 0);
    $fatal           = get_transient(EVK_SNIPPETS_FATAL_TRANSIENT);
    $logi            = (array) get_option(EVK_SNIPPETS_LOG_OPTION, []);

    $widok = sanitize_key($_GET['evk_widok'] ?? 'lista');
    if (!in_array($widok, ['lista', 'edytor', 'logi', 'advanced'], true)) $widok = 'lista';

    if (!empty($_GET['evk_zapisano'])) {
        printf('<div class="updated notice is-dismissible"><p>%s</p></div>',
            esc_html([
                'wpis'    => 'Snippet zapisany.',
                'usuniety'=> 'Snippet usunięty.',
                'stan'    => 'Stan snippetu zmieniony.',
                'logi'    => 'Logi wyczyszczone.',
                'wersje'  => 'Historia wyczyszczona — skasowanych wersji: '
                           . (int) ($_GET['evk_ile'] ?? 0) . '.',
            ][$_GET['evk_zapisano']] ?? 'Zapisano.'));
    }

    // ── Stan modułu ───────────────────────────────────────────────────────
    ?>
    <div class="evo-status-card evo-mb">
        <div class="evo-status-icon <?php echo $wlaczone ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-editor-code"></span>
        </div>
        <div class="evo-status-text">
            <h3>Snippety: <?php echo $wlaczone ? 'włączone' : 'wyłączone'; ?></h3>
            <p><?php echo $wlaczone
                ? 'Wpisy oznaczone jako włączone są wykonywane.'
                : 'Nic się nie wykonuje, niezależnie od stanu pojedynczych wpisów.'; ?></p>
        </div>
        <div class="evo-status-actions">
            <span class="evo-toggle-label"><?php echo $wlaczone ? 'Włączone' : 'Wyłączone'; ?></span>
            <label class="evo-toggle">
                <input type="checkbox" data-option="evk_snippets_enabled" data-field="_scalar"
                       value="1" <?php checked(1, $wlaczone); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <?php if ($wylaczone_stala): ?>
    <div class="notice notice-error inline evo-mb">
        <p><strong>Wykonywanie wyłączone stałą <code>EVK_CODE_DISABLE</code>.</strong>
           Usuń ją z <code>wp-config.php</code>, żeby włączyć z powrotem.</p>
    </div>
    <?php endif; ?>

    <?php /* WARUNEK NIE PATRZY JUŻ NA GŁÓWNY WŁĄCZNIK.
             Do 1.148.0 powiadomienie pokazywało się wyłącznie przy zgaszonym
             wykonywaniu — bo tylko tak wtedy silnik reagował na błąd. Odkąd
             wypada pojedynczy wpis, główny włącznik zostaje włączony
             i ten warunek chowałby jedyną informację o tym, co się stało. */ ?>
    <?php if (is_array($fatal)): $tf = evk_snippet_tresc_powiadomienia($fatal); ?>
    <div class="notice notice-error inline evo-mb">
        <p><strong><?php echo esc_html($tf['naglowek']); ?></strong> <?php echo esc_html($tf['reszta']); ?></p>
        <p class="evo-mono-xs"><?php echo esc_html($fatal['message'] ?? ''); ?>
           <?php if (!empty($fatal['line'])): ?>— linia <?php echo (int) $fatal['line']; ?><?php endif; ?></p>
    </div>
    <?php endif; ?>

    <?php /* Pasek WIDOKÓW jednego ekranu, nie ekranów sekcji — te drugie
             pokazuje pasek boczny i od 1.139.1 nie dublujemy ich u góry treści.
             Stąd osobna, lżejsza klasa: dwa identycznie wyglądające paski jeden
             pod drugim mówiły, że są tym samym poziomem nawigacji, a nie są. */ ?>
    <div class="evo-viewtabs">
        <?php
        /* „Zaawansowane" widoczne ZAWSZE, także wyłączone — włącznik mieszka
           w środku. Do 1.139.0 zakładka pojawiała się dopiero przy włączonej
           opcji, a jedyne pole, które tę opcję ustawiało, zniknęło razem
           z czterema oknami: nie było już czym jej włączyć. */
        $zakladki = ['lista' => 'Wpisy', 'logi' => 'Logi błędów', 'advanced' => 'Zaawansowane'];
        foreach ($zakladki as $klucz => $etykieta):
            $aktywna = ($widok === $klucz) || ($widok === 'edytor' && $klucz === 'lista');
        ?>
        <a href="<?php echo esc_url(evk_snippety_url(['evk_widok' => $klucz])); ?>"
           class="evo-viewtab<?php echo $aktywna ? ' is-active' : ''; ?>">
            <?php echo esc_html($etykieta); ?><?php if ($klucz === 'logi' && $logi): ?>
            <span class="evo-count-badge"><?php echo count($logi); ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if ($widok === 'edytor'): ?>
        <?php /* Powrót jest CZĘŚCIĄ paska, nie sąsiadem obok niego. Do 1.139.4
                 stał za nim jako zwykły `.button` i sterczał o kilka pikseli;
                 pilnowanie tego arytmetyką paddingów rozjechałoby się przy
                 pierwszej zmianie kroju. Ta sama klasa bazowa = ta sama
                 wysokość, bez liczenia. */ ?>
        <a href="<?php echo esc_url(evk_snippety_url()); ?>" class="evo-viewtab evo-viewtab-back">
            ← Wróć do listy
        </a>
        <?php endif; ?>
    </div>

    <?php
    if ($widok === 'edytor')        evk_snippety_edytor();
    elseif ($widok === 'logi')      evk_snippety_logi($logi);
    elseif ($widok === 'advanced')  evk_snippety_advanced($adv);
    else                            evk_snippety_lista();
}

// =========================================================================
// LISTA
// =========================================================================

function evk_snippety_lista(): void {
    $wpisy   = evk_snippety_wszystkie();
    $rodzaje = evk_snippet_rodzaje();
    $miejsca = evk_snippet_miejsca();

    $sortuj = sanitize_key($_GET['evk_sort'] ?? '');
    if (in_array($sortuj, ['rodzaj', 'grupa', 'tytul'], true)) {
        usort($wpisy, function ($a, $b) use ($sortuj) {
            return strcasecmp((string) $a[$sortuj], (string) $b[$sortuj]);
        });
    }
    ?>
    <div class="evo-row-between evo-mb">
        <span class="evo-hint evo-m0"><?php echo count($wpisy); ?> <?php
            echo count($wpisy) === 1 ? 'wpis' : (count($wpisy) < 5 ? 'wpisy' : 'wpisów'); ?></span>
        <a href="<?php echo esc_url(evk_snippety_url(['evk_widok' => 'edytor', 'evk_wpis' => 'nowy'])); ?>"
           class="button button-primary">+ Nowy snippet</a>
    </div>

    <?php if ($wpisy): ?>
    <?php /* SORTOWANIE NA TELEFONIE.
             Na szerokim ekranie sortuje się klikając nagłówki kolumn — a te
             poniżej 782 px są schowane, bo wiersz jest kartą. Bez tego pola
             sortowanie znikałoby razem z nagłówkiem. Zwykły formularz GET:
             działa bez jednej linii skryptu, tak jak reszta tego ekranu. */ ?>
    <form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>" class="evo-sort-mobile evo-mb">
        <input type="hidden" name="page" value="evoke-one">
        <input type="hidden" name="tab"  value="narzedzia">
        <input type="hidden" name="sub"  value="snippets">
        <label for="evk-sort">Sortuj</label>
        <select id="evk-sort" name="evk_sort">
            <?php foreach (['' => 'Kolejność wykonania', 'tytul' => 'Nazwa',
                            'rodzaj' => 'Rodzaj', 'grupa' => 'Grupa'] as $klucz => $etykieta): ?>
            <option value="<?php echo esc_attr($klucz); ?>" <?php selected($klucz, $sortuj); ?>>
                <?php echo esc_html($etykieta); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Zastosuj</button>
    </form>
    <?php endif; ?>

    <?php if (!$wpisy): ?>
    <div class="evo-empty">
        <p>Nie ma jeszcze żadnego wpisu.</p>
    </div>
    <?php else: ?>
    <?php /* UKŁAD AUTOMATYCZNY, NIE `fixed`.
             Do 1.139.0 tabela miała klasę `fixed` i sześć kolumn przypiętych
             na sztywno — razem 750 px. Panel oddaje treści około 780 px przy
             oknie 1280, więc na „Nazwę" schodziło trzydzieści pikseli, a przy
             węższym oknie tabela wychodziła poza kartę. Teraz szerokości
             pilnuje przeglądarka, przypięte zostają dwie naprawdę wąskie
             kolumny, a `.evo-tbl-wrap` daje przewijanie w obrębie pudełka
             zamiast rozpychania strony. */ ?>
    <div class="evo-tbl-wrap">
    <?php /* BEZ ANI JEDNEJ KLASY RDZENIA — ani `wp-list-table`, ani `widefat`,
             ani `striped`.
             `wp-list-table` wciągała responsywne reguły `list-tables.css`, które
             poniżej 782 px rozkładają komórki na bloki i podpisują je atrybutem
             `data-colname` (naszych komórek to nie dotyczyło, więc zostawał
             pionowy ciąg wartości bez podpisów). `widefat` rysowała ramkę
             wokół wszystkich kart naraz, a zdjęcie jej własną regułą nie
             wystarczyło: rdzeń wygrywał specyficznością. Zamiast dokładać wagi
             w wyścigu na punkty — koniec wyścigu. Cały wygląd tabeli siedzi
             w `admin.css` przy `.evo-snippety-tbl` i daje się zmierzyć. */ ?>
    <table class="evo-tbl evo-snippety-tbl">
        <thead><tr>
            <th>Stan</th>
            <th class="evo-col-nazwa"><a href="<?php echo esc_url(evk_snippety_url(['evk_sort' => 'tytul'])); ?>">Nazwa</a></th>
            <th><a href="<?php echo esc_url(evk_snippety_url(['evk_sort' => 'rodzaj'])); ?>">Rodzaj</a></th>
            <th>Miejsce</th>
            <th class="evo-col-grupa"><a href="<?php echo esc_url(evk_snippety_url(['evk_sort' => 'grupa'])); ?>">Grupa</a></th>
            <th>Kolejność</th>
            <th>Akcje</th>
        </tr></thead>
        <tbody>
        <?php foreach ($wpisy as $w): ?>
        <tr>
            <td class="evo-col-stan">
                <?php /* Adres wpisany wprost, choć formularz i tak wróciłby na
                         bieżącą stronę. To druga warstwa po poprawce bramy
                         w `ajax.php`: żądanie niesie komplet `page/tab/sub`
                         niezależnie od tego, którędy wszedłeś na ekran. */ ?>
                <form method="post" action="<?php echo esc_url(evk_snippety_url()); ?>" class="evo-inline-block">
                    <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
                    <input type="hidden" name="evk_przelacz_wpis" value="<?php echo (int) $w['id']; ?>">
                    <?php /* Przycisk, nie pole wyboru: `admin.js` obsługuje
                             `.evo-toggle input` wyłącznie przez AJAX i przy braku
                             `data-option` wychodzi bez akcji, więc samo zaznaczenie
                             niczego by nie wysłało. Przycisk w formularzu działa
                             bez jednej linii skryptu i z klawiatury. */ ?>
                    <button type="submit" role="switch"
                            aria-checked="<?php echo $w['wlaczony'] ? 'true' : 'false'; ?>"
                            class="evo-switch<?php echo $w['wlaczony'] ? ' is-on' : ''; ?>"
                            title="<?php echo $w['wlaczony'] ? 'Wyłącz' : 'Włącz'; ?>"
                            aria-label="<?php echo esc_attr(($w['wlaczony'] ? 'Wyłącz' : 'Włącz') . ' snippet ' . $w['tytul']); ?>">
                        <span class="evo-switch-knob"></span>
                    </button>
                </form>
            </td>
            <?php /* TYTUŁ JEST ODNOŚNIKIEM DO EDYCJI — stąd nie ma już przycisku
                     „Edytuj". Na telefonie odnośnik rozciąga się nakładką na całą
                     kartę (patrz `.evo-col-nazwa a::after` w admin.css), więc palec
                     nie musi trafiać w kilkunastopikselowy napis. Przełącznik
                     i kosz stoją nad tą nakładką i pozostają osobnymi celami. */ ?>
            <td class="evo-col-nazwa">
                <a href="<?php echo esc_url(evk_snippety_url(['evk_widok' => 'edytor', 'evk_wpis' => $w['id']])); ?>"
                   class="evo-snippet-nazwa"><?php echo esc_html($w['tytul']); ?></a>
                <?php /* ZNACZNIK PRZY WIERSZU, NIE TYLKO BANER U GÓRY.
                         Baner daje się odrzucić jednym klikiem i wtedy jedynym
                         śladem po wywrotce byłby przestawiony włącznik —
                         nie do odróżnienia od wyłączonego ręcznie. */ ?>
                <?php if (!empty($w['awaria'])): ?>
                <span class="evo-badge evo-badge-alarm"
                      title="<?php echo esc_attr(sprintf('%s: %s (linia %d, %s)',
                          $w['awaria']['type'] ?? '', $w['awaria']['message'] ?? '',
                          (int) ($w['awaria']['line'] ?? 0), $w['awaria']['czas'] ?? '')); ?>">
                    wyłączony po błędzie
                </span>
                <?php endif; ?>
            </td>
            <td data-etykieta="Rodzaj"><span class="evo-badge"><?php echo esc_html($rodzaje[$w['rodzaj']]['label'] ?? $w['rodzaj']); ?></span></td>
            <?php /* Krótka etykieta, nie pełna: „Frontend — <head>" jest na
                     miejscu w wyborze w edytorze, ale w kolumnie tabeli zabiera
                     szerokość „Nazwie". */ ?>
            <td class="evo-hint" data-etykieta="Miejsce"><?php echo esc_html($miejsca[$w['miejsce']]['krotki'] ?? $w['miejsce']); ?></td>
            <td class="evo-hint evo-col-grupa" data-etykieta="Grupa"><?php echo $w['grupa'] !== '' ? esc_html($w['grupa']) : '—'; ?></td>
            <td class="evo-hint" data-etykieta="Kolejność"><?php echo (int) $w['kolejnosc']; ?></td>
            <td class="evo-akcje">
                <?php /* Ten sam przycisk co przy wierszu animacji w Animatorze:
                         kosz plus napis, ghost, czerwony dopiero na najechaniu.
                         Jeden komponent w obu miejscach — nie ma pytania,
                         dlaczego usuwanie wygląda tu inaczej. */ ?>
                <form method="post" action="<?php echo esc_url(evk_snippety_url()); ?>" class="evo-inline-block"
                      onsubmit="return confirm('Usunąć snippet „<?php echo esc_js($w['tytul']); ?>”?');">
                    <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
                    <input type="hidden" name="evk_usun_wpis" value="<?php echo (int) $w['id']; ?>">
                    <button type="submit" class="evo-btn-remove">
                        <span class="dashicons dashicons-trash evo-ico"></span> Usuń
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php
}

// =========================================================================
// EDYTOR
// =========================================================================

function evk_snippety_edytor(): void {
    $id = $_GET['evk_wpis'] ?? 'nowy';
    $id = ($id === 'nowy') ? 0 : (int) $id;

    $wpis = ['id' => 0, 'tytul' => '', 'kod' => '', 'rodzaj' => 'php',
             'miejsce' => 'head', 'grupa' => '', 'wlaczony' => 1, 'kolejnosc' => 0];
    if ($id) {
        foreach (evk_snippety_wszystkie() as $w) if ($w['id'] === $id) $wpis = $w;
    }

    $rodzaje = evk_snippet_rodzaje();
    $miejsca = evk_snippet_miejsca();
    ?>

    <form method="post" action="<?php echo esc_url(evk_snippety_url()); ?>">
        <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
        <input type="hidden" name="evk_wpis_id" value="<?php echo (int) $wpis['id']; ?>">

        <div class="evo-grid evo-pola-rowne" style="--evo-col:220px;--evo-gap:16px">
            <div class="evo-field">
                <label for="evk-tytul">Nazwa</label>
                <input type="text" id="evk-tytul" name="evk_tytul" required
                       value="<?php echo esc_attr($wpis['tytul']); ?>" placeholder="np. Sticky header">
            </div>
            <div class="evo-field">
                <label for="evk-rodzaj">Rodzaj</label>
                <select id="evk-rodzaj" name="evk_rodzaj">
                    <?php foreach ($rodzaje as $k => $r): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($k, $wpis['rodzaj']); ?>>
                        <?php echo esc_html($r['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="evo-field">
                <label for="evk-miejsce">Miejsce</label>
                <select id="evk-miejsce" name="evk_miejsce">
                    <?php foreach ($miejsca as $k => $m): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($k, $wpis['miejsce']); ?>>
                        <?php echo esc_html($m['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="evo-field">
                <label for="evk-grupa">Grupa</label>
                <input type="text" id="evk-grupa" name="evk_grupa"
                       value="<?php echo esc_attr($wpis['grupa']); ?>" placeholder="np. SEO, klient X">
            </div>
            <div class="evo-field">
                <label for="evk-kolejnosc">Kolejność</label>
                <input type="number" id="evk-kolejnosc" name="evk_kolejnosc"
                       value="<?php echo (int) $wpis['kolejnosc']; ?>" step="10">
                <div class="evo-desc">Mniejsza liczba wykonuje się wcześniej.</div>
            </div>
            <div class="evo-field">
                <label class="evo-check">
                    <input type="checkbox" name="evk_wlaczony" value="1" <?php checked(1, $wpis['wlaczony']); ?>>
                    <span class="evo-strong-500">Włączony</span>
                </label>
            </div>
        </div>

        <div class="evo-field evo-mt-lg">
            <label for="evk-kod">Kod</label>
            <textarea id="evk-kod" name="evk_kod" rows="24" class="evo-mono"
                      spellcheck="false"><?php echo esc_textarea($wpis['kod']); ?></textarea>
        </div>

        <div class="evo-save-bar">
            <?php submit_button('Zapisz snippet', 'primary', 'evk_zapisz_wpis', false); ?>
            <a href="<?php echo esc_url(evk_snippety_url()); ?>" class="button">Anuluj</a>
        </div>
    </form>

    <?php /* HISTORIA POZA FORMULARZEM EDYTORA — formularz w formularzu jest
             nieprawidłowy i przeglądarka rozwiązuje to po swojemu: wewnętrzny
             znika, a jego przycisk zaczyna wysyłać zewnętrzny. Czyszczenie
             historii wysyłałoby wtedy zapis wpisu. */ ?>
    <?php evk_snippety_wersje_ekran($wpis); ?>
    <?php
}

// =========================================================================
// HISTORIA ZMIAN
// =========================================================================

function evk_snippety_wersje_ekran(array $wpis): void {
    if (empty($wpis['id'])) return;   // nowy wpis nie ma jeszcze czego pokazać

    $wersje = evk_snippet_wersje((int) $wpis['id']);
    ?>
    <div class="evo-box evo-mt-lg evo-wersje" data-wpis="<?php echo (int) $wpis['id']; ?>"
         data-nonce="<?php echo esc_attr(wp_create_nonce('evk_snippets_nonce')); ?>">
        <h3>Historia zmian</h3>

        <?php if (!$wersje): ?>
        <p class="evo-desc evo-mb-0">Ten wpis nie ma jeszcze zapisanej historii —
           pierwsza wersja odłoży się przy następnym zapisie.</p>
        <?php else: ?>

        <p class="evo-desc">
            <strong>Przywrócenie nie zapisuje.</strong> Wstawia starą treść do pola kodu wyżej;
            na stronie zacznie działać dopiero po kliknięciu „Zapisz snippet".
            Podgląd pokazuje, czym ta wersja różni się od tego, co masz teraz.
        </p>

        <div class="evo-tbl-wrap">
        <table class="evo-tbl evo-wersje-tbl">
            <thead><tr>
                <th>Kiedy</th><th>Kto</th><th>Rozmiar</th><th>Akcje</th>
            </tr></thead>
            <tbody>
            <?php foreach ($wersje as $w): ?>
            <tr data-wersja="<?php echo (int) $w['id']; ?>">
                <td><?php echo esc_html(mysql2date('j.m.Y, H:i', $w['data'])); ?></td>
                <td class="evo-hint" data-etykieta="Kto"><?php echo $w['autor'] !== '' ? esc_html($w['autor']) : '—'; ?></td>
                <td class="evo-hint" data-etykieta="Rozmiar"><?php echo (int) $w['znakow']; ?> zn.</td>
                <td class="evo-akcje">
                    <button type="button" class="button evo-wersja-podglad">Podgląd</button>
                    <button type="button" class="button evo-wersja-przywroc">Przywróć</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="evo-wersja-widok" hidden></div>

        <?php /* Czyszczenie stoi POD listą i ma własne pole z liczbą — bo to,
                 co znika, jest nie do odzyskania, a liczba obok przycisku
                 mówi wprost, co zostanie. */ ?>
        <form method="post" action="<?php echo esc_url(evk_snippety_url()); ?>" class="evo-mt-lg evo-wersje-czysc"
              onsubmit="return confirm('Skasować starsze wersje tego snippetu? Tego nie da się cofnąć.');">
            <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
            <input type="hidden" name="evk_wyczysc_wersje" value="<?php echo (int) $wpis['id']; ?>">
            <label for="evk-zostaw-wersji">Zostaw najnowszych wersji</label>
            <input type="number" id="evk-zostaw-wersji" name="evk_zostaw_wersji" value="10" min="0" step="1">
            <button type="submit" class="button">Wyczyść starsze</button>
        </form>

        <?php endif; ?>
    </div>
    <?php
}

// =========================================================================
// LOGI
// =========================================================================

function evk_snippety_logi(array $logi): void {
    if (!$logi): ?>
        <div class="evo-empty"><p>Brak zapisanych błędów.</p></div>
    <?php return; endif; ?>

    <?php /* WPIS LOGU JAKO KARTA, NIE WIERSZ TABELI.
             ZGŁOSZONE Z UŻYCIA: „wyświetlanie logów do poprawy, wyjeżdżają
             poza". Tabela miała cztery kolumny na sztywno, a w ostatniej
             siedział komunikat błędu razem z fragmentem kodu — czyli treść
             o nieprzewidywalnej długości w kolumnie o z góry ustalonej
             szerokości. Teraz: górna linia z metryczką (kiedy, rodzaj, wpis,
             linia), pod nią komunikat, a fragment kodu we własnym pudełku
             z poziomym przewijaniem. Nic nie wychodzi poza kartę. */ ?>
    <div class="evo-logi">
    <?php foreach (array_reverse($logi) as $log): ?>
        <article class="evo-log">
            <header class="evo-log-meta">
                <span class="evo-badge"><?php echo esc_html($log['type'] ?? ''); ?></span>
                <?php /* `timestamp`, nie `time`. Klucz zapisuje
                         `evk_snippet_log_error()` i nigdy nie nazywał się
                         inaczej — pole daty stało tu puste od początku. */ ?>
                <time><?php echo esc_html($log['timestamp'] ?? ''); ?></time>
                <?php if (!empty($log['slug'])): ?>
                <span class="evo-log-wpis"><?php echo esc_html($log['slug']); ?></span>
                <?php endif; ?>
                <?php if (!empty($log['line'])): ?>
                <span class="evo-log-linia">linia <?php echo (int) $log['line']; ?></span>
                <?php endif; ?>
            </header>
            <p class="evo-log-komunikat"><?php echo esc_html($log['message'] ?? ''); ?></p>
            <?php if (!empty($log['context'])): ?>
            <pre class="evo-log-kod"><?php echo esc_html($log['context']); ?></pre>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>

    <form method="post" action="<?php echo esc_url(evk_snippety_url()); ?>" class="evo-mt-lg"
          onsubmit="return confirm('Wyczyścić wszystkie logi?');">
        <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
        <button type="submit" name="evk_clear_logs" class="button">Wyczyść logi</button>
    </form>
    <?php
}

// =========================================================================
// TRYB ADVANCED — jedno pole wykonywane bez podziału na wpisy
// =========================================================================

function evk_snippety_advanced(int $wlaczony): void { ?>
    <?php /* Włącznik trybu mieszka TUTAJ, a nie w karcie stanu.
             Do 1.139.0 opcję ustawiało pole w formularzu czterech okien —
             razem z nimi zniknęło i tryb zaawansowany nie miał już jak wrócić do
             gry: zakładka pokazywała się dopiero przy włączonej opcji, więc
             widok, w którym stał włącznik, był nieosiągalny. */ ?>
    <div class="evo-status-card evo-mb">
        <div class="evo-status-icon <?php echo $wlaczony ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-editor-code"></span>
        </div>
        <div class="evo-status-text">
            <h3>Zaawansowane: <?php echo $wlaczony ? 'włączone' : 'wyłączone'; ?></h3>
            <p>Pole niżej wykonuje się tylko przy włączonym trybie.</p>
        </div>
        <div class="evo-status-actions">
            <span class="evo-toggle-label"><?php echo $wlaczony ? 'Włączony' : 'Wyłączony'; ?></span>
            <label class="evo-toggle">
                <input type="checkbox" data-option="evk_snippets_advanced_enabled" data-field="_scalar"
                       value="1" <?php checked(1, $wlaczony); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <div class="notice notice-error inline evo-mb">
        <p><strong>Kod z tego pola wykonuje się bez podziału na wpisy</strong>,
           więc błąd tutaj gasi całe wykonywanie, a nie jeden snippet.
           Zwykłe wpisy są bezpieczniejsze — to pole zostaje dla przypadków,
           które muszą zdążyć przed wszystkim innym.</p>
    </div>
    <form method="post" action="<?php echo esc_url(evk_snippety_url()); ?>">
        <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
        <div class="evo-field">
            <label for="evk_advanced_code">Kod PHP</label>
            <textarea id="evk_advanced_code" name="evk_advanced_code" rows="24" class="evo-mono"
                      spellcheck="false"><?php echo esc_textarea(evk_snippets_advanced_get()); ?></textarea>
        </div>
        <div class="evo-save-bar">
            <?php submit_button('Zapisz', 'primary', 'evk_zapisz_advanced', false); ?>
        </div>
    </form>
    <?php
}
