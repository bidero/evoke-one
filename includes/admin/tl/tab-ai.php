<?php
if (!defined('ABSPATH')) exit;
/**
 * Zakładka „Tłumaczenie AI” (1.260.0) — ustawienia i tłumaczenie brakujących
 * tekstów w elementach Bricksa (61-translation-ai.php). Nonce i adres liczy
 * sama, bez zmiennych z tl_render_page().
 *
 * Klucz API nigdy nie trafia do strony: pole jest puste, a obok stoi tylko
 * informacja, że klucz jest zapisany. Ustawienia zmienia administrator;
 * tłumacz (Role Manager) widzi je i może tłumaczyć.
 */
if (!function_exists('evk_tl_ai_ustawienia')) return;
$evk_u = evk_tl_ai_ustawienia();
$evk_d = evk_tl_ai_dostawcy();
$evk_admin = current_user_can('manage_options');
$evk_jezyki = tl_get_languages();
?>
<div class="evo-box tl-ai" data-nonce="<?php echo esc_attr(wp_create_nonce('evk_tl_ai')); ?>">
    <h3>Tłumaczenie AI</h3>
    <p class="evo-desc">AI uzupełnia puste pola „Tłumaczenie EN/DE” w elementach Bricksa. Wypełnione pola zostają nietknięte.
    Każde tłumaczenie AI trafia na listę „Do sprawdzenia” (zakładka EVOKE Tłumaczenia) — zdejmuje je „Sprawdzone” albo poprawka.</p>
    <p class="evo-desc">Model widzi wszystkie teksty tej części strony naraz, opis strony, wskazówki dla języka i słowniczek.
    Ten sam polski tekst ze sprawdzonym tłumaczeniem dostaje je bez pytania AI, a ponowne tłumaczenie tego samego tekstu daje ten sam wynik.</p>
    <p class="evo-desc"><strong>Prywatność:</strong> teksty stron idą do wybranego dostawcy. Na darmowym poziomie Gemini Google
    wykorzystuje je do ulepszania swoich usług; na płatnym (Gemini, Claude, OpenAI) — nie. Warunki DeepL Free i Pro opisuje DeepL.</p>
    <p class="evo-desc"><strong>DeepL</strong> tylko tłumaczy: bierze kontekst części strony, słowniczek (jako glosariusz) i formalność języka,
    a opis strony i wskazówki dla języków pomija. Klucz Free kończy się na <code>:fx</code>. Opis obrazów (alt z AI) idzie wtedy przez model AI z kluczem.</p>
    <p class="evo-desc"><strong>Google Cloud Translation (v3)</strong> tylko tłumaczy: 500 tys. znaków miesięcznie bez opłat, zamiast klucza plik JSON konta usługi
    (role Cloud Translation API Editor i Storage Object Admin). Słowniczek idzie jako glosariusz przez zasobnik Cloud Storage (region us-central1);
    opis strony, wskazówki i formalność Google pomija.</p>
</div>

<div class="evo-box tl-ai-ustawienia">
    <h3>Ustawienia</h3>
    <?php if (!$evk_admin): ?>
    <p class="evo-desc">Ustawienia zmienia administrator. Dostawca: <?php echo esc_html($evk_d[$evk_u['dostawca']]['nazwa']); ?>,
    model: <code><?php echo esc_html(evk_tl_ai_model($evk_u)); ?></code>, klucz API: <?php echo evk_tl_ai_klucz($evk_u) !== '' ? 'zapisany' : 'brak'; ?>.</p>
    <?php else: ?>
    <?php /* Układ (1.270.0): krótkie pola w jednym wierszu, wskazówki języków obok siebie —
             siatka `evo-grid` sama schodzi do jednej kolumny, gdy kolumny się nie mieszczą. */ ?>
    <div class="evo-grid evo-pola-rowne tl-ai-wiersz" style="--evo-col:200px;--evo-gap:16px">
    <div class="evo-field">
        <label for="tl-ai-dostawca">Dostawca</label>
        <select id="tl-ai-dostawca">
            <?php foreach ($evk_d as $evk_k => $evk_w): ?>
            <option value="<?php echo esc_attr($evk_k); ?>" <?php selected($evk_u['dostawca'], $evk_k); ?>
                data-model="<?php echo esc_attr($evk_w['model']); ?>"
                data-wlasny="<?php echo esc_attr((string) ($evk_u['modele'][$evk_k] ?? '')); ?>"
                data-klucz="<?php echo (string) ($evk_u['klucze'][$evk_k] ?? '') !== '' ? '1' : ''; ?>"
                data-opis="<?php echo esc_attr(explode(' — ', $evk_w['nazwa'], 2)[1] ?? ''); ?>"><?php echo esc_html(explode(' — ', $evk_w['nazwa'], 2)[0]); ?></option>
            <?php endforeach; ?>
        </select>
        <?php /* Nazwa w polu krótka, dopisek („plik JSON konta usługi”) pod nim — pełna nazwa nie mieściła się w kolumnie (zgłoszenie 02.10). */ ?>
        <p class="evo-desc tl-ai-dostawca-opis"></p>
    </div>
    <div class="evo-field">
        <div class="tl-ai-klucz-pole">
        <label for="tl-ai-klucz">Klucz API <span class="tl-ai-klucz-stan evo-label-note"></span></label>
        <input type="password" id="tl-ai-klucz" autocomplete="off" spellcheck="false" placeholder="wklej klucz — zapisany zostaje ukryty">
        </div>
        <?php /* Google (1.280.0): treść pliku JSON konta usługi zamiast klucza — pole puste, zapisany zostaje ukryty. */ ?>
        <div class="tl-ai-google-pole">
        <label for="tl-ai-google-json">Plik JSON konta usługi <span class="tl-ai-json-stan evo-label-note"></span></label>
        <textarea id="tl-ai-google-json" rows="3" autocomplete="off" spellcheck="false" placeholder="wklej całą treść pliku .json — zapisany zostaje ukryty"></textarea>
        </div>
        <label class="evo-check-row"><input type="checkbox" id="tl-ai-usun-klucz"> Usuń zapisany klucz tego dostawcy</label>
    </div>
    <div class="evo-field tl-ai-google-pole">
        <label for="tl-ai-zasobnik">Zasobnik Cloud Storage (słowniczek)</label>
        <input type="text" id="tl-ai-zasobnik" spellcheck="false" value="<?php echo esc_attr($evk_u['zasobnik']); ?>" placeholder="np. evoke-tlumaczenia-nazwa">
        <p class="evo-desc">Region us-central1. Bez zasobnika słowniczek nie działa jako glosariusz.</p>
    </div>
    <div class="evo-field tl-ai-model-pole">
        <label for="tl-ai-model">Model</label>
        <input type="text" id="tl-ai-model" spellcheck="false">
        <p class="evo-desc">Puste — model domyślny dostawcy (w podpowiedzi pola).</p>
    </div>
    <div class="evo-field tl-ai-tylko-tlumacz">
        <?php /* Etykieta w jednej linii jak w sąsiednich polach — dwuwierszowa spychała pole niżej niż w reszcie wiersza (zgłoszenie 02.10). */ ?>
        <label for="tl-ai-opisy">Opisy obrazów</label>
        <select id="tl-ai-opisy">
            <option value="">Pierwszy z kluczem</option>
            <?php foreach (EVK_TL_AI_LLM as $evk_k): ?>
            <option value="<?php echo esc_attr($evk_k); ?>" <?php selected($evk_u['opisy'], $evk_k); ?>><?php echo esc_html(explode(' — ', $evk_d[$evk_k]['nazwa'], 2)[0]); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="evo-desc">DeepL i Google tylko tłumaczą, więc opisy obrazów pisze model językowy.</p>
    </div>
    </div>
    <div class="evo-field">
        <label for="tl-ai-opis">Opis strony (branża, odbiorcy, ton)</label>
        <textarea id="tl-ai-opis" rows="3"><?php echo esc_textarea($evk_u['opis']); ?></textarea>
    </div>
    <div class="evo-grid tl-ai-wiersz" style="--evo-col:280px;--evo-gap:16px">
    <?php foreach ($evk_jezyki as $evk_kod => $evk_j): ?>
    <div class="evo-field">
        <label for="tl-ai-wsk-<?php echo esc_attr((string) $evk_kod); ?>">Wskazówki: <?php echo esc_html(strtoupper((string) $evk_kod) . ' (' . ($evk_j['name'] ?? $evk_kod) . ')'); ?></label>
        <textarea id="tl-ai-wsk-<?php echo esc_attr((string) $evk_kod); ?>" data-jezyk="<?php echo esc_attr((string) $evk_kod); ?>" class="tl-ai-wsk" rows="2"
            placeholder="np. zwracaj się per Sie; angielski brytyjski"><?php echo esc_textarea((string) ($evk_u['wskazowki'][$evk_kod] ?? '')); ?></textarea>
    </div>
    <?php endforeach; ?>
    </div>
    <?php /* Formalność DeepL (1.277.0) — osobno dla każdego języka; DeepL stosuje ją tam, gdzie język ją zna.
             `hidden` na opakowaniu bez klasy układu: na `.evo-grid` przegrałby z `display: grid` (CLAUDE.md). */ ?>
    <div class="tl-ai-deepl-pole">
    <div class="evo-grid tl-ai-wiersz" style="--evo-col:200px;--evo-gap:16px">
    <?php foreach ($evk_jezyki as $evk_kod => $evk_j): ?>
    <div class="evo-field">
        <label for="tl-ai-form-<?php echo esc_attr((string) $evk_kod); ?>">Formalność DeepL: <?php echo esc_html(strtoupper((string) $evk_kod)); ?></label>
        <select id="tl-ai-form-<?php echo esc_attr((string) $evk_kod); ?>" class="tl-ai-form" data-jezyk="<?php echo esc_attr((string) $evk_kod); ?>">
            <option value="">Domyślna</option>
            <option value="formalna" <?php selected($evk_u['formalnosc'][$evk_kod] ?? '', 'formalna'); ?>>Formalna (Sie, vous, Pan/Pani)</option>
            <option value="nieformalna" <?php selected($evk_u['formalnosc'][$evk_kod] ?? '', 'nieformalna'); ?>>Nieformalna (du, tu, Ty)</option>
        </select>
    </div>
    <?php endforeach; ?>
    </div>
    </div>
    <div class="evo-field">
        <label for="tl-ai-slowniczek">Słowniczek</label>
        <textarea id="tl-ai-slowniczek" rows="5" spellcheck="false" placeholder="realizacje | projects | Projekte&#10;!Evoke Design Studio"><?php echo esc_textarea($evk_u['slowniczek']); ?></textarea>
        <p class="evo-desc">Linia <code>polski | <?php echo esc_html(implode(' | ', array_map('strtoupper', array_keys($evk_jezyki)))); ?></code> — te tłumaczenia zawsze.
        Linia <code>!Nazwa</code> — nie tłumaczyć (marka, produkt). Linia od <code>#</code> — komentarz.</p>
    </div>
    <p class="tl-footer">
        <button type="button" class="button button-primary tl-ai-zapisz">Zapisz ustawienia AI</button>
        <?php /* 1.283.0: prawdziwe krótkie zapytanie do wybranego dostawcy (zapisany klucz), bez pamięci tłumaczeń. */ ?>
        <button type="button" class="button tl-ai-sprawdz">Sprawdź połączenie</button>
        <span class="tl-ai-zapis-stan" role="status"></span>
    </p>
    <?php endif; ?>
</div>

<?php $evk_dostepni = evk_tl_ai_dostepni($evk_u); ?>
<div class="evo-box tl-ai-tlumacz">
    <h3>Przetłumacz strony</h3>
    <?php /* Zakres (1.271.0, decyzja zgłaszającego): tabela „typ × część” — wiersz to
             rodzaj treści, kolumna to część; pole przy rodzaju zaznacza cały wiersz.
             Tylko części, które rodzaj naprawdę ma; reszta to „—”. Na telefonie
             każdy wiersz jest kartą (reguły w render.php). */
    $evk_wiersze = evk_tl_ai_wiersze_zakresu();
    $evk_tabele = [['wpis', 'Wpisy, strony i szablony', EVK_TL_AI_KOLUMNY_WPIS], ['term', 'Kategorie i tagi', EVK_TL_AI_KOLUMNY_TERM],
        ['opcje', 'Strony ustawień', EVK_TL_AI_KOLUMNY_OPCJE], ['obrazy', 'Obrazy', EVK_TL_AI_KOLUMNY_OBRAZY],
        ['frazy', 'Słownik fraz', EVK_TL_AI_KOLUMNY_FRAZY]]; ?>
    <p class="evo-desc">Zaznacz, co tłumaczyć: wiersz to rodzaj treści, kolumna — część. Pole przy nazwie zaznacza cały wiersz.
    Tłumaczenia dostają znacznik „AI — do sprawdzenia” i trafiają na listę „Do sprawdzenia”.</p>
    <div class="tl-ai-zakres">
    <?php foreach ($evk_tabele as [$evk_rodzaj, $evk_podpis, $evk_kolumny]):
        $evk_w = array_filter($evk_wiersze, static function ($w) use ($evk_rodzaj) { return $w['rodzaj'] === $evk_rodzaj; });
        if (!$evk_w) continue;
        /* Kolumny, które ma choć jeden wiersz tej tabeli. */
        $evk_kol = array_filter($evk_kolumny, static function ($k) use ($evk_w) {
            foreach ($evk_w as $w) if (in_array($k, $w['czesci'], true)) return true;
            return false;
        }, ARRAY_FILTER_USE_KEY); ?>
    <div class="evo-tbl-wrap"><table class="evo-table tl-ai-zakres-tabela" data-rodzaj="<?php echo esc_attr($evk_rodzaj); ?>">
        <caption><?php echo esc_html($evk_podpis); ?></caption>
        <thead><tr><th scope="col">Rodzaj</th><?php foreach ($evk_kol as $evk_k => $evk_n): ?><th scope="col"><?php echo esc_html($evk_n); ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($evk_w as $evk_typ => $evk_r): ?>
            <tr data-typ="<?php echo esc_attr($evk_typ); ?>">
                <th scope="row"><label class="tl-ai-typ-etykieta"><input type="checkbox" class="tl-ai-typ" data-typ="<?php echo esc_attr($evk_typ); ?>">
                    <?php echo esc_html($evk_r['nazwa']); ?></label></th>
                <?php foreach ($evk_kol as $evk_k => $evk_n):
                    if (!in_array($evk_k, $evk_r['czesci'], true)): ?>
                <td class="tl-ai-brak-czesci"><span aria-hidden="true">—</span></td>
                <?php continue; endif;
                    /* Domyślnie nic — zaznacza tłumaczący (1.272.0). */ ?>
                <td data-czesc="<?php echo esc_attr($evk_n); ?>"><label class="tl-ai-czesc-etykieta"><input type="checkbox" class="tl-ai-czesc"
                    data-typ="<?php echo esc_attr($evk_typ); ?>" value="<?php echo esc_attr($evk_k); ?>"
                    aria-label="<?php echo esc_attr($evk_r['nazwa'] . ': ' . $evk_n); ?>"><span class="tl-ai-czesc-nazwa" aria-hidden="true"><?php echo esc_html($evk_n); ?></span></label></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endforeach; ?>
    </div>
    <p class="evo-desc">Bricks — teksty w elementach (Bricks i szablony: nagłówek, stopka). Tytuł, treść i zajawka — pola WordPressa
    (treść stron zbudowanych w Bricksie jest w elementach). Adres — człon z tłumaczenia tytułu albo nazwy do Slugów URL, tylko gdy
    tego członu jeszcze nie przetłumaczono; adres innej strony — bez zapisu, z powodem w dzienniku. Pola Fields — wartości pól Evoke FIELDS, także na stronach ustawień.</p>
    <div class="evo-grid evo-pola-rowne tl-ai-wiersz" style="--evo-col:220px;--evo-gap:20px">
        <div>
            <div class="tl-ai-tryb" role="radiogroup" aria-labelledby="tl-ai-tryb-tytul">
                <p id="tl-ai-tryb-tytul"><strong>Co tłumaczyć</strong></p>
                <label class="evo-check-row"><input type="radio" name="tl-ai-tryb" value="puste" checked> Tylko puste pola</label>
                <label class="evo-check-row"><input type="radio" name="tl-ai-tryb" value="ponownie"> Puste pola i tłumaczenia AI „Do sprawdzenia” — od nowa</label>
            </div>
            <p><label class="evo-check-row"><input type="checkbox" id="tl-ai-bez-pamieci"> Pytaj AI od nowa (bez pamięci wyników)</label></p>
        </div>
        <div class="tl-ai-jezyki" role="group" aria-labelledby="tl-ai-jezyki-tytul">
            <p id="tl-ai-jezyki-tytul"><strong>Języki</strong></p>
            <?php foreach ($evk_jezyki as $evk_kod => $evk_j): ?>
            <label class="evo-check-row"><input type="checkbox" class="tl-ai-jezyk" value="<?php echo esc_attr((string) $evk_kod); ?>" checked>
                <?php echo esc_html(strtoupper((string) $evk_kod) . ' — ' . ($evk_j['name'] ?? $evk_kod)); ?></label>
            <?php endforeach; ?>
        </div>
        <div>
            <div class="evo-field">
                <label for="tl-ai-przebieg-dostawca">Dostawca tego przebiegu</label>
                <select id="tl-ai-przebieg-dostawca">
                    <?php foreach ($evk_dostepni as $evk_k => $evk_w): ?>
                    <option value="<?php echo esc_attr($evk_k); ?>" data-model="<?php echo esc_attr($evk_w['model']); ?>" <?php selected($evk_u['dostawca'], $evk_k); ?>><?php echo esc_html($evk_w['nazwa']); ?></option>
                    <?php endforeach; ?>
                    <?php if (!$evk_dostepni): ?>
                    <option value="" data-model="">Brak zapisanego klucza API</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="evo-field">
                <label for="tl-ai-przebieg-model">Model tego przebiegu</label>
                <input type="text" id="tl-ai-przebieg-model" spellcheck="false">
            </div>
        </div>
    </div>
    <p class="evo-desc">Od nowa AI tłumaczy tylko swoje niesprawdzone tłumaczenia; sprawdzone i wpisane ręcznie zostają, a poprzednią wersję
    przywrócisz w liście „Teksty w elementach” albo w okienku sprawdzania na stronie. Ten sam model przy tych samych ustawieniach daje wynik
    z pamięci — „Pytaj AI od nowa” wysyła zapytanie mimo to (zużywa limit dostawcy). Pusty model przebiegu — model z ustawień; ustawienia zostają bez zmian.</p>
    <p>
        <button type="button" class="button tl-ai-lista">Pokaż strony do tłumaczenia</button>
        <button type="button" class="button button-primary tl-ai-start" disabled>Przetłumacz zaznaczone</button>
        <button type="button" class="button tl-ai-stop" disabled>Zatrzymaj</button>
    </p>
    <p class="tl-ai-stan" role="status"></p>
    <div class="tl-ai-jednostki"></div>
    <ol class="tl-ai-dziennik"></ol>
</div>

<?php /* Strony z danymi Bricksa daje moduł 53. Na stronie ładuje się zawsze
         razem z 61; bez niego (harness zakładek w testach) pole czyszczenia
         zostaje bez listy — jak lista w „Teksty w elementach”. */
$evk_strony = function_exists('evk_tl_el_wpisy_bricksa') ? evk_tl_ai_strony_do_czyszczenia() : []; ?>
<div class="evo-box tl-ai-czysc">
    <h3>Wyczyść tłumaczenia strony</h3>
    <p class="evo-desc">Usuwa tłumaczenia z pól języków w elementach jednej części strony i zapomina wyniki AI dla jej tekstów
    (wszystkich modeli), więc „Przetłumacz strony” przetłumaczy ją od nowa — także tym samym modelem. Nagłówek i stopka to osobne
    pozycje, bo są na każdej stronie. Czyści tylko pola, które tłumaczy AI; teksty bez liter i same tagi danych zostają.</p>
    <?php if (!$evk_strony): ?>
    <p class="evo-desc">Nie ma stron z danymi Bricksa, które możesz edytować.</p>
    <?php else: ?>
    <?php /* Strona | Co usunąć | Języki w jednym wierszu (1.270.0). */ ?>
    <div class="evo-grid evo-pola-rowne tl-ai-wiersz" style="--evo-col:220px;--evo-gap:20px">
    <div class="evo-field">
        <label for="tl-ai-czysc-strona">Strona</label>
        <select id="tl-ai-czysc-strona">
            <?php foreach ($evk_strony as $evk_s): ?>
            <option value="<?php echo esc_attr($evk_s['post_id'] . '|' . $evk_s['meta_key']); ?>" data-typ="<?php echo esc_attr((string) get_post_type((int) $evk_s['post_id'])); ?>"><?php echo esc_html($evk_s['tytul'] . ' (' . $evk_s['czesc'] . ')'); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="tl-ai-czysc-zakres" role="radiogroup" aria-labelledby="tl-ai-czysc-zakres-tytul">
        <p id="tl-ai-czysc-zakres-tytul"><strong>Co usunąć</strong></p>
        <label class="evo-check-row"><input type="radio" name="tl-ai-czysc-zakres" value="ai" checked> Tylko tłumaczenia AI „Do sprawdzenia”</label>
        <label class="evo-check-row"><input type="radio" name="tl-ai-czysc-zakres" value="wszystkie"> Wszystkie tłumaczenia — także sprawdzone i wpisane ręcznie</label>
    </div>
    <div class="tl-ai-czysc-jezyki" role="group" aria-label="Języki do wyczyszczenia">
        <p aria-hidden="true"><strong>Języki</strong></p>
        <?php foreach ($evk_jezyki as $evk_kod => $evk_j): ?>
        <label class="evo-check-row"><input type="checkbox" class="tl-ai-czysc-jezyk" value="<?php echo esc_attr((string) $evk_kod); ?>" checked>
            <?php echo esc_html(strtoupper((string) $evk_kod) . ' — ' . ($evk_j['name'] ?? $evk_kod)); ?></label>
        <?php endforeach; ?>
    </div>
    </div>
    <p>
        <button type="button" class="button evo-btn-danger tl-ai-czysc-start">Wyczyść…</button>
        <button type="button" class="button tl-ai-czysc-przywroc" disabled>Przywróć wyczyszczone</button>
    </p>
    <p class="evo-desc">„Przywróć wyczyszczone” wpisuje usunięte tłumaczenia z powrotem do pustych pól, dopóki tej strony nie
    wyczyścisz ponownie. Tekst przetłumaczony od nowa pokazuje wyczyszczony jako poprzednią wersję — „Przywróć” w liście
    „Teksty w elementach” wraca do niego pojedynczo.</p>
    <p class="tl-ai-czysc-stan" role="status"></p>
    <?php endif; ?>
</div>
<script>
(function () {
    var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var box = document.querySelector('.tl-ai');
    if (!box) return;
    var NONCE = box.getAttribute('data-nonce');

    function wyslij(dane) {
        var fd = new FormData();
        fd.append('nonce', NONCE);
        Object.keys(dane).forEach(function (k) {
            var v = dane[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); });
            else if (v && typeof v === 'object') Object.keys(v).forEach(function (x) {
                /* Zakres (1.271.0): obiekt list — `zakres[typ][]=część`. */
                if (Array.isArray(v[x])) v[x].forEach(function (y) { fd.append(k + '[' + x + '][]', y); });
                else fd.append(k + '[' + x + ']', v[x]);
            });
            else fd.append(k, v);
        });
        return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function el(tag, tekst, klasa) {
        var e = document.createElement(tag);
        if (tekst !== undefined && tekst !== null) e.textContent = String(tekst);
        if (klasa) e.className = klasa;
        return e;
    }

    /* Ustawienia: model i stan klucza zależą od dostawcy. */
    var dostawca = document.getElementById('tl-ai-dostawca');
    if (dostawca) {
        var model = document.getElementById('tl-ai-model');
        var kluczStan = document.querySelector('.tl-ai-klucz-stan');
        var pokazDostawce = function () {
            var o = dostawca.options[dostawca.selectedIndex];
            model.placeholder = o.getAttribute('data-model');
            model.value = o.getAttribute('data-wlasny') || '';
            kluczStan.textContent = o.getAttribute('data-klucz') ? '(zapisany)' : '(brak)';
            document.querySelector('.tl-ai-dostawca-opis').textContent = o.getAttribute('data-opis') || '';
            /* DeepL: bez modelu, z formalnością i wyborem modelu do opisów obrazów.
               Google (1.280.0): bez modelu, plik JSON zamiast klucza, zasobnik. */
            var deepl = dostawca.value === 'deepl', google = dostawca.value === 'google';
            document.querySelectorAll('.tl-ai-model-pole').forEach(function (x) { x.hidden = deepl || google; });
            document.querySelectorAll('.tl-ai-deepl-pole').forEach(function (x) { x.hidden = !deepl; });
            document.querySelectorAll('.tl-ai-tylko-tlumacz').forEach(function (x) { x.hidden = !(deepl || google); });
            document.querySelectorAll('.tl-ai-google-pole').forEach(function (x) { x.hidden = !google; });
            document.querySelectorAll('.tl-ai-klucz-pole').forEach(function (x) { x.hidden = google; });
            document.querySelectorAll('.tl-ai-json-stan').forEach(function (x) { x.textContent = kluczStan.textContent; });
        };
        dostawca.addEventListener('change', pokazDostawce);
        pokazDostawce();
        document.querySelector('.tl-ai-sprawdz').addEventListener('click', function (e) {
            var b = e.currentTarget, st = document.querySelector('.tl-ai-zapis-stan');
            b.disabled = true;
            st.textContent = 'Sprawdzam połączenie…';
            wyslij({ action: 'evk_tl_ai_sprawdz', dostawca: dostawca.value, model: model.value })
                .then(function (r) { b.disabled = false; st.textContent = (r && r.success) ? r.data.komunikat : ((r && r.data) || 'Błąd.'); })
                .catch(function () { b.disabled = false; st.textContent = 'Błąd połączenia z serwerem.'; });
        });
        document.querySelector('.tl-ai-zapisz').addEventListener('click', function (e) {
            var b = e.currentTarget, stan = document.querySelector('.tl-ai-zapis-stan');
            var wsk = {};
            document.querySelectorAll('.tl-ai-wsk').forEach(function (t) { wsk[t.getAttribute('data-jezyk')] = t.value; });
            var form = {};
            document.querySelectorAll('.tl-ai-form').forEach(function (t) { form[t.getAttribute('data-jezyk')] = t.value; });
            b.disabled = true;
            stan.textContent = 'Zapisuję…';
            var google = dostawca.value === 'google';
            wyslij({ action: 'evk_tl_ai_ustawienia', dostawca: dostawca.value,
                klucz: google ? document.getElementById('tl-ai-google-json').value : document.getElementById('tl-ai-klucz').value,
                zasobnik: document.getElementById('tl-ai-zasobnik').value,
                usun_klucz: document.getElementById('tl-ai-usun-klucz').checked ? '1' : '', model: model.value,
                opis: document.getElementById('tl-ai-opis').value, slowniczek: document.getElementById('tl-ai-slowniczek').value, wskazowki: wsk,
                formalnosc: form, opisy: document.getElementById('tl-ai-opisy').value })
                .then(function (r) {
                    b.disabled = false;
                    if (!r || !r.success) { stan.textContent = (r && r.data) || 'Błąd zapisu.'; return; }
                    var o = dostawca.options[dostawca.selectedIndex];
                    o.setAttribute('data-klucz', r.data.klucz ? '1' : '');
                    o.setAttribute('data-wlasny', model.value);
                    document.getElementById('tl-ai-klucz').value = '';
                    document.getElementById('tl-ai-google-json').value = '';
                    document.getElementById('tl-ai-usun-klucz').checked = false;
                    pokazDostawce();
                    stan.textContent = r.data.komunikat;
                })
                .catch(function () { b.disabled = false; stan.textContent = 'Błąd połączenia.'; });
        });
    }

    /* Tłumaczenie: lista części stron z brakami, potem krok po kroku. */
    var t = document.querySelector('.tl-ai-tlumacz');
    var stan = t.querySelector('.tl-ai-stan');
    var lista = t.querySelector('.tl-ai-jednostki');
    var dziennik = t.querySelector('.tl-ai-dziennik');
    var start = t.querySelector('.tl-ai-start');
    var stop = t.querySelector('.tl-ai-stop');
    var jednostki = [];
    var zatrzymaj = false;
    var przebiegDostawca = document.getElementById('tl-ai-przebieg-dostawca');
    var przebiegModel = document.getElementById('tl-ai-przebieg-model');

    function jezyki() {
        return Array.prototype.map.call(t.querySelectorAll('.tl-ai-jezyk:checked'), function (c) { return c.value; });
    }
    /* Zakres z tabeli „typ × część” (1.271.0): typ => zaznaczone części. */
    function zakres() {
        var z = {};
        t.querySelectorAll('.tl-ai-czesc:checked').forEach(function (c) {
            var typ = c.getAttribute('data-typ');
            (z[typ] = z[typ] || []).push(c.value);
        });
        return z;
    }
    /* Pole wyboru przy rodzaju: cały wiersz, stan częściowy przy części zaznaczonych. */
    function stanWiersza(typ) {
        var cz = t.querySelectorAll('.tl-ai-czesc[data-typ="' + typ + '"]'), w = t.querySelector('.tl-ai-typ[data-typ="' + typ + '"]');
        var n = Array.prototype.filter.call(cz, function (c) { return c.checked; }).length;
        w.checked = n > 0 && n === cz.length;
        w.indeterminate = n > 0 && n < cz.length;
    }
    t.querySelectorAll('.tl-ai-typ').forEach(function (w) {
        var typ = w.getAttribute('data-typ');
        w.addEventListener('change', function () {
            t.querySelectorAll('.tl-ai-czesc[data-typ="' + typ + '"]').forEach(function (c) { c.checked = w.checked; });
            stanWiersza(typ);
        });
        stanWiersza(typ);
    });
    t.querySelectorAll('.tl-ai-czesc').forEach(function (c) {
        c.addEventListener('change', function () { stanWiersza(c.getAttribute('data-typ')); });
    });
    function tryb() {
        var r = t.querySelector('input[name="tl-ai-tryb"]:checked');
        return r ? r.value : 'puste';
    }
    /* Model przebiegu: podpowiedź to model z ustawień wybranego dostawcy. */
    function pokazPrzebieg() {
        var o = przebiegDostawca.options[przebiegDostawca.selectedIndex];
        przebiegModel.placeholder = o ? (o.getAttribute('data-model') || '') : '';
    }
    przebiegDostawca.addEventListener('change', pokazPrzebieg);
    pokazPrzebieg();
    /* Liczby na liście zależą od trybu — po zmianie trzeba ją pokazać od nowa. */
    t.querySelectorAll('input[name="tl-ai-tryb"], .tl-ai-czesc, .tl-ai-typ').forEach(function (r) {
        r.addEventListener('change', function () {
            jednostki = [];
            lista.textContent = '';
            start.disabled = true;
            stan.textContent = 'Zmieniony tryb — pokaż strony jeszcze raz.';
        });
    });
    function wpisz(tekst) { dziennik.appendChild(el('li', tekst)); }
    function opisAdresu(a) {
        return { zapisany: '„' + a.czlon + '” zapisany w Slugach URL', jest: 'już przetłumaczony („' + a.czlon + '”)',
            bez_tytulu: 'brak tłumaczenia tytułu (nazwy)', bez_adresu: 'bez polskiego adresu', ten_sam: 'taki sam jak polski',
            konflikt: 'bez zapisu — ' + a.powod }[a.stan] || a.stan;
    }
    function czekaj(s) { return new Promise(function (ok) { setTimeout(ok, s * 1000); }); }

    /* Lista części stron z brakami. `zaznacz` („post_id|meta_key”, po
       „Wyczyść tłumaczenia strony”): zaznaczona tylko ta część. */
    var przyciskListy = t.querySelector('.tl-ai-lista');
    /* Wiersze listy widoczne po szukajce — „zaznacz” działa tylko na nie (1.271.0). */
    function widoczne(lista) { return Array.prototype.filter.call(lista, function (c) { return c.closest('tr').style.display !== 'none'; }); }
    function stanPola(pole, lista) {
        var w = widoczne(lista), n = w.filter(function (c) { return c.checked; }).length;
        pole.checked = w.length > 0 && n === w.length;
        pole.indeterminate = n > 0 && n < w.length;
    }
    function pokazListe(zaznacz) {
        /* Tabela startuje pusta (1.272.0) — bez zaznaczenia nie ma o co pytać serwera. */
        if (!Object.keys(zakres()).length) {
            lista.textContent = '';
            start.disabled = true;
            stan.textContent = 'Zaznacz, co tłumaczyć.';
            return Promise.resolve();
        }
        przyciskListy.disabled = true;
        stan.textContent = 'Liczę braki…';
        var trybListy = tryb();
        return wyslij({ action: 'evk_tl_ai_lista', tryb: trybListy, zakres: zakres() }).then(function (r) {
            przyciskListy.disabled = false;
            lista.textContent = '';
            if (!r || !r.success) { stan.textContent = (r && r.data) || 'Błąd.'; return; }
            jednostki = r.data;
            if (!jednostki.length) {
                stan.textContent = trybListy === 'ponownie' ? 'Nie ma pustych pól ani niesprawdzonych tłumaczeń AI.' : 'Nie ma pustych pól języków w zaznaczonych częściach.';
                start.disabled = true;
                return;
            }
            stan.textContent = 'Części stron z brakami: ' + jednostki.length + '.';
            /* Szukajka (1.271.0): filtruje wiersze po nazwie strony. */
            var szukaj = el('p', null, 'tl-ai-szukaj'), sl = el('label', 'Szukaj na liście'), si = el('input');
            sl.setAttribute('for', 'tl-ai-szukaj'); si.type = 'search'; si.id = 'tl-ai-szukaj'; si.autocomplete = 'off';
            szukaj.appendChild(sl); szukaj.appendChild(si); lista.appendChild(szukaj);
            var wrap = el('div', null, 'evo-tbl-wrap'), tab = el('table', null, 'evo-table'), tr = el('tr');
            ['', 'Strona', 'Część', 'Do tłumaczenia'].forEach(function (n) { var th = el('th', n); th.setAttribute('scope', 'col'); tr.appendChild(th); });
            /* Zaznacz / odznacz wszystkie (1.269.0): pole w nagłówku, stan częściowy przy części wierszy. */
            var wszystkie = el('input');
            wszystkie.type = 'checkbox'; wszystkie.className = 'tl-ai-wszystkie'; wszystkie.setAttribute('aria-label', 'Zaznacz wszystkie');
            tr.firstChild.appendChild(wszystkie);
            var thead = el('thead'); thead.appendChild(tr); tab.appendChild(thead);
            /* Grupy po rodzaju treści (1.271.0): nagłówek z polem „zaznacz grupę” i liczbą pozycji. */
            var grupy = [];
            jednostki.forEach(function (j, i) {
                var g = grupy.length && grupy[grupy.length - 1].nazwa === j.grupa ? grupy[grupy.length - 1] : null;
                if (!g) {
                    g = { nazwa: j.grupa || '', tbody: el('tbody', null, 'tl-ai-grupa') };
                    var gtr = el('tr', null, 'tl-ai-grupa-naglowek'), gth = el('th'), gl = el('label'), gc = el('input'), gs = el('span');
                    gth.setAttribute('colspan', '4'); gth.setAttribute('scope', 'colgroup');
                    gc.type = 'checkbox'; gc.className = 'tl-ai-grupa-wybor';
                    gl.appendChild(gc); gl.appendChild(gs); gth.appendChild(gl); gtr.appendChild(gth); g.tbody.appendChild(gtr);
                    g.pole = gc; g.licznik = gs;
                    grupy.push(g);
                }
                var w = el('tr', null, 'tl-ai-wiersz'), td0 = el('td'), c = el('input');
                c.type = 'checkbox'; c.checked = !zaznacz || zaznacz === j.post_id + '|' + j.meta_key;
                c.className = 'tl-ai-wybor'; c.setAttribute('data-i', i);
                c.setAttribute('aria-label', 'Tłumacz: ' + j.tytul + ' (' + j.czesc + ')');
                w.setAttribute('data-szukaj', String(j.tytul + ' ' + j.czesc).toLowerCase());
                td0.appendChild(c); w.appendChild(td0);
                var td = el('td'), a = el('a', j.tytul); a.href = j.adres; td.appendChild(a); w.appendChild(td);
                w.appendChild(el('td', j.czesc));
                w.appendChild(el('td', Object.keys(j.braki).map(function (k) {
                    return k.toUpperCase() + ': ' + j.braki[k] + (j.ai && j.ai[k] ? ' (w tym AI od nowa: ' + j.ai[k] + ')' : '');
                }).join(', ')));
                g.tbody.appendChild(w);
            });
            grupy.forEach(function (g) {
                g.wybor = g.tbody.querySelectorAll('.tl-ai-wybor');
                g.licznik.textContent = ' ' + g.nazwa + ' (' + g.wybor.length + ')';
                g.pole.setAttribute('aria-label', 'Zaznacz grupę: ' + g.nazwa);
                g.pole.addEventListener('change', function () {
                    widoczne(g.wybor).forEach(function (c) { c.checked = g.pole.checked; });
                    odswiez();
                });
                tab.appendChild(g.tbody);
            });
            wrap.appendChild(tab); lista.appendChild(wrap);
            var wiersze = tab.querySelectorAll('.tl-ai-wybor');
            function odswiez() {
                stanPola(wszystkie, wiersze);
                grupy.forEach(function (g) { stanPola(g.pole, g.wybor); });
            }
            wszystkie.addEventListener('change', function () {
                widoczne(wiersze).forEach(function (c) { c.checked = wszystkie.checked; });
                odswiez();
            });
            tab.addEventListener('change', function (e) { if (e.target.classList.contains('tl-ai-wybor')) odswiez(); });
            si.addEventListener('input', function () {
                var q = si.value.trim().toLowerCase();
                grupy.forEach(function (g) {
                    var ile = 0;
                    Array.prototype.forEach.call(g.tbody.querySelectorAll('.tl-ai-wiersz'), function (w) {
                        var tak = !q || w.getAttribute('data-szukaj').indexOf(q) >= 0;
                        w.style.display = tak ? '' : 'none';
                        if (tak) ile++;
                    });
                    g.tbody.style.display = ile ? '' : 'none';
                });
                odswiez();
            });
            odswiez();
            start.disabled = false;
        }).catch(function () { przyciskListy.disabled = false; stan.textContent = 'Błąd połączenia.'; });
    }
    przyciskListy.addEventListener('click', function () { pokazListe(); });

    stop.addEventListener('click', function () { zatrzymaj = true; stop.disabled = true; stan.textContent = 'Zatrzymuję po bieżącym kroku…'; });

    start.addEventListener('click', async function () {
        /* Tylko widoczne po szukajce — ukryte nie idą, nawet zaznaczone wcześniej. */
        var wybrane = widoczne(t.querySelectorAll('.tl-ai-wybor:checked')).map(function (c) { return jednostki[+c.getAttribute('data-i')]; });
        var jez = jezyki();
        if (!wybrane.length || !jez.length) { stan.textContent = 'Zaznacz strony i języki.'; return; }
        /* Tryb, dostawca i model na cały przebieg — zmiana w trakcie go nie rusza. */
        var przebieg = { tryb: tryb(), dostawca: przebiegDostawca.value, model: przebiegModel.value.trim(),
            bez_pamieci: document.getElementById('tl-ai-bez-pamieci').checked ? '1' : '', zakres: zakres() };
        zatrzymaj = false; start.disabled = true; stop.disabled = false; dziennik.textContent = '';
        var suma = { zapisane: 0, z_ai: 0, z_pamieci: 0, z_wynikow: 0, odrzucone: 0, bez_zmian: 0 }, przerwane = '', podpisy = {};
        /* 1.283.0: kto tłumaczy ten przebieg — z listy; kto NAPRAWDĘ tłumaczył — z odpowiedzi serwera (`podpis`). */
        var wybranyDostawca = przebiegDostawca.options[przebiegDostawca.selectedIndex];
        wpisz('Przebieg: ' + (wybranyDostawca ? wybranyDostawca.textContent.split(' — ')[0] : '?') + ' · model ' + (przebieg.model || przebiegModel.placeholder || 'domyślny')
            + (przebieg.bez_pamieci ? ', bez pamięci wyników' : '') + '.');
        petla:
        for (var i = 0; i < wybrane.length; i++) {
            var j = wybrane[i];
            /* Brakujący alt PL (1.271.0): jedna runda, bez języków. */
            var jezJ = j.braki.pl ? ['pl'] : jez;
            for (var k = 0; k < jezJ.length; k++) {
                var lang = jezJ[k];
                if (!j.braki[lang]) continue;
                var pomin = [], proby = 0;
                while (!zatrzymaj) {
                    stan.textContent = (lang === 'pl' ? 'Opisuję: ' : 'Tłumaczę: ') + j.tytul + ' (' + j.czesc + ') — ' + lang.toUpperCase() + '…';
                    var r;
                    try {
                        r = await wyslij({ action: 'evk_tl_ai_krok', post_id: j.post_id, meta_key: j.meta_key, lang: lang, pomin: pomin,
                            tryb: przebieg.tryb, dostawca: przebieg.dostawca, model: przebieg.model, bez_pamieci: przebieg.bez_pamieci,
                            czesci: przebieg.zakres[j.typ] || [], do: j.do || '', opcje: j.opcje || '' });
                    } catch (e) { r = { success: false, data: 'Błąd połączenia.' }; }
                    if (!r || !r.success) { wpisz(j.tytul + ' ' + lang.toUpperCase() + ': ' + ((r && r.data) || 'błąd')); break; }
                    var d = r.data;
                    /* Odrzucone, bez zmian i już zapisane w tym przebiegu nie wracają —
                       w trybie „od nowa” świeże tłumaczenie AI jest znów niesprawdzone. */
                    pomin = pomin.concat(d.odrzucone || [], d.pominiete || [], d.zapisane_klucze || []);
                    suma.zapisane += d.zapisane; suma.z_ai += d.z_ai; suma.z_pamieci += d.z_pamieci; suma.odrzucone += (d.odrzucone || []).length;
                    suma.bez_zmian += d.bez_zmian || 0; suma.z_wynikow += d.z_wynikow || 0;
                    if (d.z_ai && d.podpis) podpisy[d.podpis] = (podpisy[d.podpis] || 0) + d.z_ai;
                    wpisz(j.tytul + ' (' + j.czesc + ') ' + lang.toUpperCase() + ': zapisane ' + d.zapisane + ' (od dostawcy'
                        + (d.podpis ? ' ' + d.podpis : '') + ': ' + d.z_ai + ', z pamięci: ' + d.z_pamieci + '), odrzucone: ' + (d.odrzucone || []).length
                        + (d.bez_zmian ? ', bez zmian: ' + d.bez_zmian : '') + ', zostało: ' + d.zostalo
                        + (d.blad ? ' — ' + d.blad : ''));
                    if (d.adres) wpisz(j.tytul + ' ' + lang.toUpperCase() + ', adres: ' + opisAdresu(d.adres));
                    if (d.blad) {
                        if (d.stop) { przerwane = d.blad; break petla; }
                        if (++proby > 5) break;
                        stan.textContent = d.blad + ' (' + d.czekaj + ' s)';
                        await czekaj(d.czekaj);
                        continue;
                    }
                    if (!d.zostalo) break;
                }
                if (zatrzymaj) break petla;
            }
        }
        start.disabled = false; stop.disabled = true;
        stan.textContent = przerwane || (zatrzymaj ? 'Zatrzymane.' : 'Gotowe.');
        wpisz('Razem: zapisane ' + suma.zapisane + ' (od dostawcy: ' + suma.z_ai + ', z pamięci: ' + suma.z_pamieci + '), odrzucone: ' + suma.odrzucone
            + (suma.bez_zmian ? ', bez zmian: ' + suma.bez_zmian : '') + '.');
        var kto = Object.keys(podpisy);
        if (kto.length) wpisz('Odpowiedział dostawca: ' + kto.map(function (p) { return p + ' (' + podpisy[p] + ')'; }).join(', ') + '.');
        else if (suma.z_pamieci) wpisz('Dostawca NIE był pytany: wszystkie teksty przyszły z pamięci — ' + (suma.z_pamieci - suma.z_wynikow)
            + ' ze sprawdzonych tłumaczeń, ' + suma.z_wynikow + ' z wcześniejszych wyników tego modelu. Działanie dostawcy sprawdzi „Sprawdź połączenie” w ustawieniach.');
        if (suma.bez_zmian) {
            wpisz(przebieg.bez_pamieci ? 'Bez zmian: model odpowiedział tym samym tekstem co obecny.'
                : 'Bez zmian: ten sam model przy tych samych ustawieniach daje ten sam wynik z pamięci. Zaznacz „Pytaj AI od nowa”, '
                + 'wybierz inny model albo zmień opis, wskazówki lub słowniczek.');
        }
    });

    /* Czyszczenie strony (1.264.0): liczby przy każdej zmianie wyboru,
       potwierdzenie z liczbami, potem lista hurtu z tą stroną zaznaczoną. */
    var cz = document.querySelector('.tl-ai-czysc');
    var czStrona = document.getElementById('tl-ai-czysc-strona');
    if (!cz || !czStrona) return;
    var czStan = cz.querySelector('.tl-ai-czysc-stan');
    var czStart = cz.querySelector('.tl-ai-czysc-start');
    var czPrzywroc = cz.querySelector('.tl-ai-czysc-przywroc');
    function czWybor() {
        var v = czStrona.value.split('|');
        var z = cz.querySelector('input[name="tl-ai-czysc-zakres"]:checked');
        return { post_id: v[0], meta_key: v.slice(1).join('|'), zakres: z ? z.value : 'ai',
            jezyki: Array.prototype.map.call(cz.querySelectorAll('.tl-ai-czysc-jezyk:checked'), function (c) { return c.value; }) };
    }
    function liczby(ile) {
        return Object.keys(ile || {}).map(function (k) { return k.toUpperCase() + ': ' + ile[k]; }).join(', ');
    }
    function czNazwa() { return czStrona.options[czStrona.selectedIndex].textContent; }
    function pokazKopie(d) {
        czPrzywroc.disabled = !d.kopia;
        czPrzywroc.textContent = d.kopia ? 'Przywróć wyczyszczone (' + d.kopia + ')' : 'Przywróć wyczyszczone';
    }
    function czPodglad(opisz) {
        var w = czWybor();
        return wyslij({ action: 'evk_tl_ai_czysc', post_id: w.post_id, meta_key: w.meta_key, zakres: w.zakres, jezyki: w.jezyki })
            .then(function (r) {
                if (!r || !r.success) { if (opisz) czStan.textContent = (r && r.data) || 'Błąd.'; return r; }
                pokazKopie(r.data);
                if (opisz) czStan.textContent = r.data.razem ? 'Do wyczyszczenia: ' + r.data.razem + ' (' + liczby(r.data.ile) + ').'
                    : 'Nic do wyczyszczenia w tym zakresie i językach.';
                return r;
            });
    }
    czStrona.addEventListener('change', function () { czPodglad(true); });
    cz.querySelectorAll('input[name="tl-ai-czysc-zakres"], .tl-ai-czysc-jezyk').forEach(function (x) {
        x.addEventListener('change', function () { czPodglad(true); });
    });
    czPodglad(false);

    czStart.addEventListener('click', function () {
        var w = czWybor();
        if (!w.jezyki.length) { czStan.textContent = 'Zaznacz języki.'; return; }
        czStart.disabled = true;
        czStan.textContent = 'Liczę…';
        czPodglad(false).then(function (r) {
            if (!r || !r.success) { czStart.disabled = false; czStan.textContent = (r && r.data) || 'Błąd.'; return; }
            if (!r.data.razem) { czStart.disabled = false; czStan.textContent = 'Nic do wyczyszczenia w tym zakresie i językach.'; return; }
            var pytanie = 'Wyczyścić ' + (w.zakres === 'ai' ? 'tłumaczenia AI „Do sprawdzenia”' : 'WSZYSTKIE tłumaczenia (także sprawdzone i wpisane ręcznie)')
                + ' na stronie „' + czNazwa() + '”?\n\nDo usunięcia: ' + r.data.razem + ' (' + liczby(r.data.ile) + ').'
                + ' Pola zostaną puste, a AI zapomni wyniki dla tych tekstów. „Przywróć wyczyszczone” wpisze je z powrotem do pustych pól.';
            if (!window.confirm(pytanie)) { czStart.disabled = false; czStan.textContent = 'Anulowane.'; return; }
            czStan.textContent = 'Czyszczę…';
            return wyslij({ action: 'evk_tl_ai_czysc', wykonaj: '1', post_id: w.post_id, meta_key: w.meta_key, zakres: w.zakres, jezyki: w.jezyki })
                .then(function (r2) {
                    czStart.disabled = false;
                    if (!r2 || !r2.success) { czStan.textContent = (r2 && r2.data) || 'Błąd.'; return; }
                    pokazKopie(r2.data);
                    czStan.textContent = 'Wyczyszczone: ' + r2.data.razem + ' (' + liczby(r2.data.wyczyszczone) + '). Strona jest zaznaczona na liście '
                        + '„Przetłumacz strony” wyżej — „Przetłumacz zaznaczone” przetłumaczy ją od nowa.';
                    /* Lista z tą stroną — także gdy treść Bricksa jej rodzaju była odznaczona w tabeli zakresu. */
                    var typ = czStrona.options[czStrona.selectedIndex].getAttribute('data-typ');
                    var bx = t.querySelector('.tl-ai-czesc[data-typ="' + typ + '"][value="bricks"]');
                    if (bx && !bx.checked) { bx.checked = true; stanWiersza(typ); }
                    return pokazListe(w.post_id + '|' + w.meta_key);
                });
        }).catch(function () { czStart.disabled = false; czStan.textContent = 'Błąd połączenia.'; });
    });

    czPrzywroc.addEventListener('click', function () {
        var w = czWybor();
        czPrzywroc.disabled = true;
        czStan.textContent = 'Przywracam…';
        wyslij({ action: 'evk_tl_ai_przywroc', post_id: w.post_id, meta_key: w.meta_key }).then(function (r) {
            if (!r || !r.success) { czPrzywroc.disabled = false; czStan.textContent = (r && r.data) || 'Błąd.'; return; }
            czStan.textContent = 'Przywrócone: ' + r.data.przywrocone + '.'
                + (r.data.pominiete ? ' Pominięte (pole już wypełnione albo elementu nie ma): ' + r.data.pominiete + '.' : '');
            czPodglad(false);
            if (jednostki.length) pokazListe();
        }).catch(function () { czPrzywroc.disabled = false; czStan.textContent = 'Błąd połączenia.'; });
    });
})();
</script>
