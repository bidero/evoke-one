<?php
if (!defined('ABSPATH')) exit;
?>
            <?php
            $sc      = EVK_Schema::get_instance()->get_settings();
            $langs   = function_exists('tl_get_languages') ? tl_get_languages() : [];
            $descs   = json_decode($sc['descriptions'],    true) ?: [];
            $socials = json_decode($sc['social_links'],    true) ?: [];
            $currs   = json_decode($sc['lang_currencies'], true) ?: [];
            $subs    = json_decode($sc['sub_entities'] ?? '[]', true) ?: [];
            $sub_types = EVK_Schema::sub_entity_types();
            $kontakty  = json_decode($sc['contact_points'] ?? '[]', true) ?: [];

            /* Wiersz repeatera punktów kontaktowych. `contactType` jest polem
               tekstowym, a nie listą: schema.org nie ma tu zamkniętego
               słownika, a Google podaje przykłady („customer service",
               „technical support", „reservations"), nie listę dozwolonych. */
            $render_kontakt_row = static function (array $row) {
                ob_start(); ?>
                <div class="evk-sub-row">
                    <input type="text" name="evk_schema_contact[type][]" value="<?php echo esc_attr($row['type'] ?? ''); ?>" placeholder="Rodzaj, np. reservations">
                    <input type="text" name="evk_schema_contact[telephone][]" value="<?php echo esc_attr($row['telephone'] ?? ''); ?>" placeholder="Telefon">
                    <input type="text" name="evk_schema_contact[email][]" value="<?php echo esc_attr($row['email'] ?? ''); ?>" placeholder="E-mail">
                    <button type="button" class="button evk-sub-remove" title="Usuń"><span class="dashicons dashicons-trash"></span></button>
                </div>
                <?php return ob_get_clean();
            };

            $wlasne     = json_decode($sc['custom_props'] ?? '[]', true) ?: [];
            $wezly_ed   = EVK_Schema::wezly_edytora();
            $znane      = EVK_Schema::wlasciwosci_znane();

            /* Wiersz edytora węzłów: węzeł + klucz + wartość.
               Klucz ma `list` wskazujący datalist WYBRANEGO węzła — podpowiedzi
               przełącza skrypt niżej, żeby przy „Witrynie" nie proponować
               `checkinTime`. Wpisanie klucza spoza listy jest DOZWOLONE
               (schema.org rośnie), ale skrypt zaznacza to ostrzeżeniem. */
            $render_wlasny_row = static function (array $row) use ($wezly_ed) {
                $r_wezel = $row['wezel'] ?? 'organization';
                ob_start(); ?>
                <div class="evk-sub-row evk-wlasne-row">
                    <select name="evk_schema_custom[wezel][]" class="evk-wlasne-wezel">
                        <?php foreach ($wezly_ed as $wk => $wl): ?>
                        <option value="<?php echo esc_attr($wk); ?>" <?php selected($r_wezel, $wk); ?>><?php echo esc_html($wl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="evk_schema_custom[klucz][]" class="evk-wlasne-klucz"
                           list="evk-wlasciwosci-<?php echo esc_attr($r_wezel); ?>"
                           value="<?php echo esc_attr($row['klucz'] ?? ''); ?>" placeholder="Właściwość, np. slogan">
                    <?php /* TEXTAREA, nie `input`. Wartością bywa cały węzeł
                             JSON — `{"@type":"QuantitativeValue","value":12}`
                             nie mieści się w jednolinijkowym polu na tyle,
                             żeby dało się go przeczytać przy pisaniu.
                             `flex-basis` w CSS daje temu polu najwięcej
                             miejsca w wierszu. */ ?>
                    <textarea name="evk_schema_custom[wartosc][]" class="evo-mono evk-wlasne-wartosc" rows="2"
                              placeholder='Wartość — tekst albo JSON, np. {"@type":"Rating","ratingValue":5}'><?php echo esc_textarea($row['wartosc'] ?? ''); ?></textarea>
                    <button type="button" class="button evk-sub-remove" title="Usuń"><span class="dashicons dashicons-trash"></span></button>
                </div>
                <?php return ob_get_clean();
            };

            // Renderuje jeden wiersz repeatera podrzędnych encji
            $render_sub_row = static function (array $row) use ($sub_types) {
                $r_type = $row['type'] ?? '';
                $r_name = $row['name'] ?? '';
                $r_desc = $row['description'] ?? '';
                ob_start(); ?>
                <div class="evk-sub-row">
                    <select name="evk_schema_sub[type][]">
                        <?php foreach ($sub_types as $tk => $tl): ?>
                        <option value="<?php echo esc_attr($tk); ?>" <?php selected($r_type, $tk); ?>><?php echo esc_html($tl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="evk_schema_sub[name][]" value="<?php echo esc_attr($r_name); ?>" placeholder="Nazwa, np. Parking dla gości">
                    <input type="text" name="evk_schema_sub[description][]" value="<?php echo esc_attr($r_desc); ?>" placeholder="Opis (opcjonalnie)">
                    <input type="text" name="evk_schema_sub[url][]" value="<?php echo esc_attr($row['url'] ?? ''); ?>" placeholder="Adres podstrony (opcjonalnie)">
                    <input type="text" name="evk_schema_sub[telephone][]" value="<?php echo esc_attr($row['telephone'] ?? ''); ?>" placeholder="Telefon (opcjonalnie)">
                    <input type="text" name="evk_schema_sub[image][]" value="<?php echo esc_attr($row['image'] ?? ''); ?>" placeholder="Adres zdjęcia (opcjonalnie)">
                    <button type="button" class="button evk-sub-remove" title="Usuń"><span class="dashicons dashicons-trash"></span></button>
                </div>
                <?php return ob_get_clean();
            };

            /*
             * PRESET WYNIKA Z TYPU DZIAŁALNOŚCI, nie jest osobnym ustawieniem.
             * Sekcje i pola branżowe noszą `data-preset` z listą presetów,
             * przy których mają być widoczne; skrypt na dole pokazuje właściwe
             * i przełącza je NATYCHMIAST po zmianie selecta, bez zapisu.
             * Dzięki temu widać skutek wyboru, zanim się go zatwierdzi.
             */
            $preset_teraz = EVK_Schema::preset_dla_typu($sc['org_type']);

            // Atrybut widoczności dla pola z rejestru — jedno miejsce, żeby
            // formularz i rejestr nie mogły się rozjechać.
            /* Select trójstanowy. Pięć takich pól w tej zakładce, a każde
               wpisane z ręki to osobna szansa na pomylenie „nie" z „nie podano"
               w wartości opcji. */
            $trojstan = static function (string $klucz, string $etykieta, string $sc_val, string $opis = ''): string {
                ob_start(); ?>
                <div class="evo-field evo-mb-0">
                    <label><?php echo esc_html($etykieta); ?></label>
                    <select name="evk_schema[<?php echo esc_attr($klucz); ?>]">
                        <option value=""  <?php selected($sc_val, '');  ?>>— nie podano</option>
                        <option value="1" <?php selected($sc_val, '1'); ?>>Tak</option>
                        <option value="0" <?php selected($sc_val, '0'); ?>>Nie</option>
                    </select>
                    <?php if ($opis): ?><div class="evo-desc"><?php echo esc_html($opis); ?></div><?php endif; ?>
                </div>
                <?php return ob_get_clean();
            };

            /* Podpowiedzi do pól, które oczekują NAZWY ZE SŁOWNIKA schema.org.
               `datalist`, a nie `select`: wyliczenia schema.org rosną, a lista
               sprzed dwóch lat blokowałaby prawidłową wartość bez obejścia. */
            $slownik_datalist = static function (string $klucz): string {
                $lista = EVK_Schema::slowniki()[$klucz] ?? [];
                if (!$lista) return '';
                $out = '<datalist id="evk-slownik-' . esc_attr($klucz) . '">';
                foreach ($lista as $v) $out .= '<option value="' . esc_attr($v) . '"></option>';
                return $out . '</datalist>';
            };

            $atr_presetu = static function (string $klucz) use ($preset_teraz): string {
                $opis = EVK_Schema::pola()[$klucz] ?? [];
                if (!isset($opis['preset'])) return '';
                $widoczne = EVK_Schema::pole_w_presecie($opis, $preset_teraz);
                return ' data-preset="' . esc_attr(implode(' ', $opis['preset'])) . '"'
                     . ($widoczne ? '' : ' hidden');
            };
            ?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_schema'); ?>
                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($sc['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-database"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Moduł Schema: <?php echo !empty($sc['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                        <p>Generuje JSON-LD @graph w &lt;head&gt; każdej podstrony.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($sc['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_schema" data-field="enabled" value="1" <?php checked(!empty($sc['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Dane organizacji</h3>
                    <details class="evo-note"><summary>Skąd biorą się widoczne pola</summary><div class="evo-note-body">Typ działalności decyduje, które pola branżowe zobaczysz niżej — i nie jest to tylko porządek na ekranie. <code>servesCuisine</code> istnieje w schema.org na gastronomii, a nie na gabinecie stomatologicznym; <code>checkinTime</code> na obiekcie noclegowym, a nie na salonie fryzjerskim. Pokazanie wszystkim wszystkiego dawałoby <strong>nieprawidłowy graf</strong> u każdego, kto wypełniłby pole spoza swojego typu. Zmiana typu <strong>nie kasuje</strong> tego, co już wpisane — ukryte pola zachowują wartości i wrócą po powrocie do poprzedniego typu.</div></details>
                    <div class="evo-grid evo-mb" style="--evo-col:280px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0">
                            <label>Typ działalności (@type)<span class="evo-tip" tabindex="0" role="note" data-tip="Typ inny niż „Organizacja&quot; tworzy w grafie osobny węzeł miejsca (#place) z polami firmy lokalnej poniżej; #organization pozostaje czystym wydawcą strony." aria-label="Typ inny niż „Organizacja&quot; tworzy w grafie osobny węzeł miejsca (#place) z polami firmy lokalnej poniżej; #organization pozostaje czystym wydawcą strony.">?</span></label>
                            <select name="evk_schema[org_type]" id="evk-org-type" data-presety="<?php
                                $mapa = [];
                                foreach (EVK_Schema::presety() as $pk => $pv) {
                                    foreach ($pv['typy'] as $t) $mapa[$t] = $pk;
                                }
                                echo esc_attr(wp_json_encode($mapa));
                            ?>">
                                <?php foreach (EVK_Schema::org_types() as $type_key => $type_label): ?>
                                <option value="<?php echo esc_attr($type_key); ?>" <?php selected($sc['org_type'], $type_key); ?>><?php echo esc_html($type_label); ?> — <?php echo esc_html($type_key); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="evo-desc">Branża: <strong id="evk-preset-nazwa"><?php
                                echo esc_html(EVK_Schema::presety()[$preset_teraz]['etykieta']);
                            ?></strong></div>
                        </div>
                        <div class="evo-field evo-mb-0"><label>Nazwa obiektu / firmy (site_name)</label><input type="text" name="evk_schema[site_name]" value="<?php echo esc_attr($sc['site_name']); ?>" placeholder="np. Piekarnia Przykładowa"></div>
                        <div class="evo-field evo-mb-0"><label>Nazwa operatora (Organization)</label><input type="text" name="evk_schema[operator_name]" value="<?php echo esc_attr($sc['operator_name']); ?>" placeholder="np. Przykładowa sp. z o.o."><div class="evo-desc">Wypełnij <strong>tylko wtedy, gdy operator to inna firma</strong> niż opisywany obiekt — sieć hoteli i jeden hotel, fundacja i jej kawiarnia. Wtedy graf dostaje dwa węzły. Puste (albo ta sama nazwa) = jedna firma, jeden węzeł.</div></div>
                        <div class="evo-field evo-mb-0"><label>Telefon</label><input type="text" name="evk_schema[telephone]" value="<?php echo esc_attr($sc['telephone']); ?>" placeholder="+48 000 000 000"></div>
                        <div class="evo-field evo-mb-0"><label>E-mail</label><input type="text" name="evk_schema[email]" value="<?php echo esc_attr($sc['email']); ?>" placeholder="biuro@domena.pl"></div>
                        <div class="evo-field evo-mb-0"><label>Ulica i numer</label><input type="text" name="evk_schema[street_address]" value="<?php echo esc_attr($sc['street_address']); ?>" placeholder="ul. Przykładowa 1"></div>
                        <div class="evo-field evo-mb-0"><label>Miejscowość</label><input type="text" name="evk_schema[locality]" value="<?php echo esc_attr($sc['locality']); ?>" placeholder="Warszawa"></div>
                        <div class="evo-field evo-mb-0"><label>Kod pocztowy</label><input type="text" name="evk_schema[postal_code]" value="<?php echo esc_attr($sc['postal_code']); ?>" placeholder="00-000"></div>
                        <div class="evo-field evo-mb-0"><label>Kod kraju (ISO)</label><input type="text" name="evk_schema[country]" value="<?php echo esc_attr($sc['country']); ?>" placeholder="PL" class="evo-w-full"></div>
                        <div class="evo-field evo-mb-0"><label>Typ kontaktu (contactType)</label><input type="text" name="evk_schema[contact_type]" value="<?php echo esc_attr($sc['contact_type']); ?>" placeholder="booking"></div>
                        <div class="evo-field evo-mb-0"><label>URL logo / faviconu</label><input type="text" name="evk_schema[favicon_url]" value="<?php echo esc_attr($sc['favicon_url']); ?>" placeholder="/wp-content/uploads/logo.png"><div class="evo-desc">Ścieżka relatywna lub pełny URL.</div></div>
                    </div>

                </div>

                <div class="evo-box">
                    <h3>Punkty kontaktowe (contactPoint)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Firmy mają zwykle osobne numery do rezerwacji, sprzedaży i wsparcia — <code>contactType</code> jest właśnie od ich rozróżniania. <strong>Puste = zachowanie dotychczasowe:</strong> jeden punkt złożony z telefonu i typu kontaktu z sekcji „Dane organizacji". Wiersz bez telefonu i bez e-maila jest pomijany.</div></details>
                    <div id="evk-kontakt-lista">
                        <?php foreach ($kontakty as $row) { echo $render_kontakt_row((array) $row); } ?>
                    </div>
                    <template id="evk-kontakt-tpl"><?php echo $render_kontakt_row([]); ?></template>
                    <button type="button" class="button evo-mt-xs" id="evk-kontakt-add"><span class="dashicons dashicons-plus-alt2 evo-ico-sm evo-ico-lead"></span> Dodaj punkt kontaktowy</button>
                    <script>
                    (function(){
                        var lista = document.getElementById('evk-kontakt-lista');
                        var tpl   = document.getElementById('evk-kontakt-tpl');
                        var add   = document.getElementById('evk-kontakt-add');
                        if (!lista || !tpl || !add) return;
                        add.addEventListener('click', function(){
                            lista.appendChild(tpl.content.cloneNode(true));
                        });
                        lista.addEventListener('click', function(e){
                            var btn = e.target.closest('.evk-sub-remove');
                            if (btn) btn.closest('.evk-sub-row').remove();
                        });
                    })();
                    </script>
                </div>

                <div class="evo-box">
                    <h3>Organizacja — dane rozszerzone</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Wszystkie pola są opcjonalne i wchodzą do węzła <code>#organization</code> wyłącznie wypełnione — puste nie zostawiają po sobie śladu w JSON-LD. Google używa ich do panelu wiedzy i do rozpoznania, że witryna i firma to ta sama encja.</div></details>

                    <div class="evo-field"><label>Czym się zajmujecie (knowsAbout) — jedna pozycja na linię<span class="evo-tip" tabindex="0" role="note" data-tip="Linia zaczynająca się od http staje się wskazaniem na encję (Wikipedia, Wikidata) — to mocniejszy sygnał. Pozostałe linie idą jako zwykły tekst. Można mieszać jedno z drugim." aria-label="Linia zaczynająca się od http staje się wskazaniem na encję (Wikipedia, Wikidata) — to mocniejszy sygnał. Pozostałe linie idą jako zwykły tekst. Można mieszać jedno z drugim.">?</span></label><textarea name="evk_schema[org_knows_about]" rows="4" class="evo-w-480" placeholder="stolarstwo meblowe&#10;renowacja mebli&#10;https://pl.wikipedia.org/wiki/Stolarstwo"><?php echo esc_textarea($sc['org_knows_about']); ?></textarea><div class="evo-desc">Linia od <code>http</code> → wskazanie na encję; reszta → tekst. Można mieszać.</div></div>

                    <div class="evo-grid evo-mb" style="--evo-col:280px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0"><label>Nazwa rejestrowa (legalName)</label><input type="text" name="evk_schema[org_legal_name]" value="<?php echo esc_attr($sc['org_legal_name']); ?>" placeholder="Przykładowa sp. z o.o."><div class="evo-desc">Gdy inna niż handlowa.</div></div>
                        <div class="evo-field evo-mb-0"><label>Nazwa skrócona (alternateName)</label><input type="text" name="evk_schema[org_alternate]" value="<?php echo esc_attr($sc['org_alternate']); ?>" placeholder="Przykładowa"></div>
                        <div class="evo-field evo-mb-0"><label>Hasło firmy (slogan)</label><input type="text" name="evk_schema[org_slogan]" value="<?php echo esc_attr($sc['org_slogan']); ?>" placeholder="Od 1998 roku"></div>
                        <div class="evo-field evo-mb-0"><label>Marka (brand)</label><input type="text" name="evk_schema[org_brand]" value="<?php echo esc_attr($sc['org_brand']); ?>" placeholder="Nazwa marki"></div>
                        <div class="evo-field evo-mb-0"><label>Data założenia (foundingDate)</label><input type="text" name="evk_schema[org_founding]" value="<?php echo esc_attr($sc['org_founding']); ?>" placeholder="1998 albo 1998-04-20"><div class="evo-desc">Sam rok wystarczy.</div></div>
                        <div class="evo-field evo-mb-0"><label>Założyciel (founder)</label><input type="text" name="evk_schema[org_founder]" value="<?php echo esc_attr($sc['org_founder']); ?>" placeholder="Imię i nazwisko"></div>
                        <div class="evo-field evo-mb-0"><label>Liczba pracowników</label><input type="text" name="evk_schema[org_employees]" value="<?php echo esc_attr($sc['org_employees']); ?>" placeholder="12"></div>
                        <div class="evo-field evo-mb-0"><label>NIP (vatID)</label><input type="text" name="evk_schema[org_vat_id]" value="<?php echo esc_attr($sc['org_vat_id']); ?>" placeholder="PL0000000000"></div>
                        <div class="evo-field evo-mb-0"><label>REGON / KRS (taxID)</label><input type="text" name="evk_schema[org_tax_id]" value="<?php echo esc_attr($sc['org_tax_id']); ?>" placeholder="000000000"></div>
                        <div class="evo-field evo-mb-0"><label>Faks (faxNumber)</label><input type="text" name="evk_schema[org_fax]" value="<?php echo esc_attr($sc['org_fax']); ?>" placeholder="+48 00 000 00 00"></div>
                    </div>

                    <div class="evo-field"><label>Obsługiwany obszar firmy (areaServed) — jeden na linię</label><textarea name="evk_schema[org_area_served]" rows="3" class="evo-w-480" placeholder="Warszawa&#10;mazowieckie&#10;Polska"><?php echo esc_textarea($sc['org_area_served']); ?></textarea><div class="evo-desc">Dla firmy bez fizycznego obiektu. Pole o tej samej nazwie w sekcji miejsca dotyczy obiektu.</div></div>
                    <div class="evo-field"><label>Nagrody i wyróżnienia (award) — jedno na linię</label><textarea name="evk_schema[org_award]" rows="3" class="evo-w-480" placeholder="Gazele Biznesu 2024"><?php echo esc_textarea($sc['org_award']); ?></textarea></div>
                    <div class="evo-field"<?php echo $atr_presetu('org_nonprofit'); ?>><label>Status organizacji pożytku (nonprofitStatus)</label><input type="text" name="evk_schema[org_nonprofit]" value="<?php echo esc_attr($sc['org_nonprofit']); ?>" placeholder="NonprofitANBI" class="evo-w-480" list="evk-slownik-org_nonprofit"><div class="evo-desc">Dla fundacji i stowarzyszeń. <strong>Wartość ze słownika schema.org</strong> (<code>NonprofitType</code>) — lista podpowiada, ale nie blokuje, bo schema.org ją rozszerza. Polskie OPP nie ma własnej pozycji w tym słowniku; jeśli żadna nie pasuje, <strong>lepiej zostawić puste</strong> niż wpisać nieprawdę.</div></div>
                    <?php echo $slownik_datalist('org_nonprofit'); ?>
                    <div class="evo-field"<?php echo $atr_presetu('org_offer_catalog'); ?>><label>Oferowane usługi (hasOfferCatalog) — jedna na linię</label><textarea name="evk_schema[org_offer_catalog]" rows="4" class="evo-w-480" placeholder="Porada prawna | /porady/ | Konsultacje online i na miejscu&#10;Reprezentacja w sądzie&#10;Obsługa spółek | /spolki/"><?php echo esc_textarea($sc['org_offer_catalog']); ?></textarea><div class="evo-desc">Opisuje ofertę <strong>firmy</strong>, nie zawartość budynku — dlatego trafia do <code>#organization</code>, a nie do miejsca.<br>Po kresce możesz dopisać <strong>adres i opis, w dowolnej kolejności</strong>: człon zaczynający się od <code>/</code> albo <code>http</code> jest adresem, każdy inny opisem. Sama nazwa też wystarczy. Adres względny (<code>/porady/</code>) sam dostaje domenę.<br>Wszystkie pola tej zakładki przyjmują <strong>znaczniki tłumaczeń</strong> <code>{tl_klucz}</code> — podstrona w danym języku dostaje wtedy jego wersję.</div></div>
                    <div class="evo-field"><label>Członkostwa (memberOf) — jedno na linię<span class="evo-tip" tabindex="0" role="note" data-tip="Format: „Nazwa | https://adres". Adres jest opcjonalny — sama nazwa wystarczy. Rozdzielnikiem jest pionowa kreska, bo nazwy zrzeszeń zawierają przecinki." aria-label="Format: „Nazwa | https://adres". Adres jest opcjonalny — sama nazwa wystarczy. Rozdzielnikiem jest pionowa kreska, bo nazwy zrzeszeń zawierają przecinki.">?</span></label><textarea name="evk_schema[org_member_of]" rows="3" class="evo-w-480" placeholder="Izba Rzemieślnicza | https://przyklad.test"><?php echo esc_textarea($sc['org_member_of']); ?></textarea><div class="evo-desc">Format: <code>Nazwa | adres</code>, adres opcjonalny.</div></div>
                </div>

                <div class="evo-box">
                    <h3>Miejsce / firma lokalna (węzeł #place)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Pola używane tylko, gdy typ działalności jest inny niż „Organizacja" (LocalBusiness i pochodne — np. obiekt noclegowy, restauracja). Trafiają do osobnego węzła #place powiązanego z #organization przez parentOrganization. Współrzędne znajdziesz np. w Mapach Google (PPM na pinezce).</div></details>
                    <div class="evo-grid evo-mb" style="--evo-col:280px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0"><label>Szerokość geograficzna (latitude)</label><input type="text" name="evk_schema[geo_lat]" value="<?php echo esc_attr($sc['geo_lat']); ?>" placeholder="53.12345"></div>
                        <div class="evo-field evo-mb-0"><label>Długość geograficzna (longitude)</label><input type="text" name="evk_schema[geo_lng]" value="<?php echo esc_attr($sc['geo_lng']); ?>" placeholder="21.12345"></div>
                        <div class="evo-field evo-mb-0"><label>Przedział cenowy (priceRange)</label><input type="text" name="evk_schema[price_range]" value="<?php echo esc_attr($sc['price_range']); ?>" placeholder="$$"><div class="evo-desc">Umownie: $ tanio … $$$$ drogo (albo np. „50–200 zł").</div></div>
                        <div class="evo-field evo-mb-0"><label>Link do mapy (hasMap)</label><input type="text" name="evk_schema[has_map]" value="<?php echo esc_attr($sc['has_map']); ?>" placeholder="https://maps.google.com/…"><div class="evo-desc">Np. link „Udostępnij" z Map Google.</div></div>
                    </div>
                    <div class="evo-field"><label>Udogodnienia (amenityFeature) — jedno na linię</label><textarea name="evk_schema[amenities]" rows="4" class="evo-w-480" placeholder="Parking&#10;Wi-Fi&#10;Dostęp dla wózków"><?php echo esc_textarea($sc['amenities']); ?></textarea></div>
                    <div class="evo-field"><label>Godziny otwarcia (openingHoursSpecification) — jedna reguła na linię<span class="evo-tip" tabindex="0" role="note" data-tip="Format: dni + godziny, np. „Pn-Pt 08:00-20:00&quot;, „Sob 09:00-14:00&quot;, „Codziennie 08:00-20:00&quot;. Dni: Pn, Wt, Śr, Cz, Pt, Sob, Nd (można łączyć przecinkiem i zakresem)." aria-label="Format: dni + godziny, np. „Pn-Pt 08:00-20:00&quot;, „Sob 09:00-14:00&quot;, „Codziennie 08:00-20:00&quot;. Dni: Pn, Wt, Śr, Cz, Pt, Sob, Nd (można łączyć przecinkiem i zakresem).">?</span></label><textarea name="evk_schema[opening_hours]" rows="3" class="evo-mono evo-w-480" placeholder="Pn-Pt 08:00-20:00&#10;Sob-Nd 09:00-18:00"><?php echo esc_textarea($sc['opening_hours']); ?></textarea></div>
                    <div class="evo-grid evo-mb" style="--evo-col:280px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0"<?php echo $atr_presetu('place_checkin'); ?>><label>Zameldowanie od (checkinTime)</label><input type="text" name="evk_schema[place_checkin]" value="<?php echo esc_attr($sc['place_checkin']); ?>" placeholder="15:00"></div>
                        <div class="evo-field evo-mb-0"<?php echo $atr_presetu('place_checkout'); ?>><label>Wymeldowanie do (checkoutTime)</label><input type="text" name="evk_schema[place_checkout]" value="<?php echo esc_attr($sc['place_checkout']); ?>" placeholder="11:00"></div>
                        <div class="evo-field evo-mb-0"<?php echo $atr_presetu('place_rooms'); ?>><label>Liczba pokoi (numberOfRooms)</label><input type="text" name="evk_schema[place_rooms]" value="<?php echo esc_attr($sc['place_rooms']); ?>" placeholder="24"></div>
                        <div class="evo-field evo-mb-0"<?php echo $atr_presetu('place_stars'); ?>><label>Kategoria / gwiazdki (starRating)</label><input type="text" name="evk_schema[place_stars]" value="<?php echo esc_attr($sc['place_stars']); ?>" placeholder="4"><div class="evo-desc">Oficjalna kategoria obiektu, nie ocena gości.</div></div>
                        <div class="evo-field evo-mb-0"<?php echo $atr_presetu('place_menu'); ?>><label>Adres menu (hasMenu)</label><input type="text" name="evk_schema[place_menu]" value="<?php echo esc_attr($sc['place_menu']); ?>" placeholder="https://przyklad.test/menu"></div>
                    </div>
                    <div class="evo-grid evo-mb" style="--evo-col:220px;--evo-gap:10px">
                        <label class="evo-choice"<?php echo $atr_presetu('place_pets'); ?>><input type="checkbox" name="evk_schema[place_pets]" value="1" <?php checked(!empty($sc['place_pets'])); ?>> Zwierzęta dozwolone</label>
                        <label class="evo-choice"<?php echo $atr_presetu('place_reservations'); ?>><input type="checkbox" name="evk_schema[place_reservations]" value="1" <?php checked(!empty($sc['place_reservations'])); ?>> Przyjmujemy rezerwacje</label>
                        <label class="evo-choice"<?php echo $atr_presetu('place_drive_thru'); ?>><input type="checkbox" name="evk_schema[place_drive_thru]" value="1" <?php checked(!empty($sc['place_drive_thru'])); ?>> Okienko drive-through</label>
                    </div>
                    <div class="evo-field"<?php echo $atr_presetu('place_languages'); ?>><label>Języki obsługi (availableLanguage) — jeden na linię</label><textarea name="evk_schema[place_languages]" rows="3" class="evo-w-480" placeholder="Polish&#10;English"><?php echo esc_textarea($sc['place_languages']); ?></textarea></div>
                    <div class="evo-field"<?php echo $atr_presetu('place_cuisine'); ?>><label>Rodzaj kuchni (servesCuisine) — jeden na linię</label><textarea name="evk_schema[place_cuisine]" rows="3" class="evo-w-480" placeholder="polska&#10;wegetariańska"><?php echo esc_textarea($sc['place_cuisine']); ?></textarea></div>
                    <div class="evo-field"<?php echo $atr_presetu('place_specialty'); ?>><label>Specjalizacja medyczna (medicalSpecialty) — jedna na linię<span class="evo-tip" tabindex="0" role="note" data-tip="To pole oczekuje nazwy ze słownika schema.org (MedicalSpecialty), po angielsku: Dentistry, Dermatology, Physiotherapy. Polska nazwa przejdzie, ale dla wyszukiwarek nic nie znaczy." aria-label="To pole oczekuje nazwy ze słownika schema.org (MedicalSpecialty), po angielsku: Dentistry, Dermatology, Physiotherapy. Polska nazwa przejdzie, ale dla wyszukiwarek nic nie znaczy.">?</span></label><textarea name="evk_schema[place_specialty]" rows="3" class="evo-w-480" placeholder="Dentistry"><?php echo esc_textarea($sc['place_specialty']); ?></textarea><div class="evo-desc"><strong>Po angielsku, ze słownika schema.org</strong> (<code>MedicalSpecialty</code>). Pełną listę masz niżej — pole jest wielolinijkowe, więc podpowiedzi nie da się w nie wpiąć; przepisz stamtąd.</div><details class="evo-note"><summary>Słownik MedicalSpecialty (<?php echo count(EVK_Schema::slowniki()['place_specialty']); ?> pozycji)</summary><div class="evo-note-body evo-mono"><?php echo esc_html(implode(', ', EVK_Schema::slowniki()['place_specialty'])); ?></div></details></div>

                    <div class="evo-grid evo-mb" style="--evo-col:280px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0"><label>Akceptowane waluty (currenciesAccepted)</label><input type="text" name="evk_schema[place_currencies]" value="<?php echo esc_attr($sc['place_currencies']); ?>" placeholder="PLN, EUR"></div>
                        <div class="evo-field evo-mb-0"><label>Numer oddziału (branchCode)</label><input type="text" name="evk_schema[place_branch]" value="<?php echo esc_attr($sc['place_branch']); ?>" placeholder="WAW-01"><div class="evo-desc">Przy wielu lokalizacjach.</div></div>
                        <div class="evo-field evo-mb-0"><label>Maksymalna liczba osób (maximumAttendeeCapacity)</label><input type="text" name="evk_schema[place_capacity]" value="<?php echo esc_attr($sc['place_capacity']); ?>" placeholder="120"></div>
                        <div class="evo-field evo-mb-0"><label>Faks obiektu (faxNumber)</label><input type="text" name="evk_schema[place_fax]" value="<?php echo esc_attr($sc['place_fax']); ?>" placeholder="+48 00 000 00 00"></div>
                        <?php
                        echo $trojstan('place_public',  'Dostępne publicznie (publicAccess)', (string) $sc['place_public']);
                        echo $trojstan('place_free',    'Wstęp bezpłatny (isAccessibleForFree)', (string) $sc['place_free']);
                        echo $trojstan('place_smoking', 'Palenie dozwolone (smokingAllowed)', (string) $sc['place_smoking']);
                        ?>
                    </div>
                    <div class="evo-field"><label>Formy płatności (paymentAccepted) — jedna na linię</label><textarea name="evk_schema[place_payment]" rows="3" class="evo-w-480" placeholder="Gotówka&#10;Karta&#10;BLIK"><?php echo esc_textarea($sc['place_payment']); ?></textarea></div>
                    <div class="evo-field"><label>Zdjęcia obiektu (photo) — jeden adres na linię</label><textarea name="evk_schema[place_photos]" rows="3" class="evo-mono evo-w-480" placeholder="https://przyklad.test/1.jpg"><?php echo esc_textarea($sc['place_photos']); ?></textarea></div>
                    <div class="evo-field"><label>Święta i przerwy (specialOpeningHoursSpecification) — jedna reguła na linię<span class="evo-tip" tabindex="0" role="note" data-tip="Format: data, potem godziny albo słowo oznaczające zamknięcie. Przykłady: „2026-12-24 zamknięte”, „2026-12-25..2026-12-26 nieczynne”, „2026-12-31 09:00-14:00”. Data w zapisie RRRR-MM-DD; dwie kropki oznaczają zakres dni." aria-label="Format: data, potem godziny albo słowo oznaczające zamknięcie. Przykłady: „2026-12-24 zamknięte”, „2026-12-25..2026-12-26 nieczynne”, „2026-12-31 09:00-14:00”. Data w zapisie RRRR-MM-DD; dwie kropki oznaczają zakres dni.">?</span></label><textarea name="evk_schema[place_special_hours]" rows="4" class="evo-mono evo-w-480" placeholder="2026-12-24 zamknięte&#10;2026-12-25..2026-12-26 nieczynne&#10;2026-12-31 09:00-14:00"><?php echo esc_textarea($sc['place_special_hours']); ?></textarea><div class="evo-desc">Nadpisuje zwykłe godziny w podanych dniach. Cokolwiek poza godzinami znaczy „zamknięte".</div></div>

                    <div class="evo-field"><label>Obsługiwany obszar (areaServed) — jeden na linię</label><textarea name="evk_schema[area_served]" rows="3" class="evo-w-480" placeholder="Warszawa&#10;mazowieckie&#10;Polska"><?php echo esc_textarea($sc['area_served']); ?></textarea></div>

                </div>

                <div class="evo-box">
                    <h3>Atrakcja turystyczna (TouristAttraction)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Osobny obiekt w grafie — włącz go w „Aktywne bloki JSON-LD" poniżej. Używa adresu i współrzędnych z pól powyżej.</div></details>
                    <div class="evo-field"><label>Nazwa atrakcji</label><input type="text" name="evk_schema[attraction_name]" value="<?php echo esc_attr($sc['attraction_name']); ?>" placeholder="np. Zabytkowy młyn nad rzeką" class="evo-w-480"><div class="evo-desc">Puste pole = nazwa organizacji.</div></div>
                    <div class="evo-grid evo-mb" style="--evo-col:280px;--evo-gap:16px">
                        <?php
                        echo $trojstan('attr_public', 'Dostępna publicznie (publicAccess)', (string) $sc['attr_public']);
                        echo $trojstan('attr_free',   'Wstęp bezpłatny (isAccessibleForFree)', (string) $sc['attr_free']);
                        ?>
                    </div>
                    <div class="evo-field"><label>Dla kogo (touristType) — jedna grupa na linię</label><textarea name="evk_schema[attr_tourist_type]" rows="3" class="evo-w-480" placeholder="Rodziny z dziećmi&#10;Wędkarze"><?php echo esc_textarea($sc['attr_tourist_type']); ?></textarea></div>
                    <div class="evo-field"><label>Języki obsługi atrakcji (availableLanguage) — jeden na linię</label><textarea name="evk_schema[attr_languages]" rows="3" class="evo-w-480" placeholder="Polish&#10;English"><?php echo esc_textarea($sc['attr_languages']); ?></textarea></div>
                    <div class="evo-field"><label>Godziny atrakcji (openingHoursSpecification) — jedna reguła na linię</label><textarea name="evk_schema[attr_hours]" rows="3" class="evo-mono evo-w-480" placeholder="Pn-Nd 09:00-17:00"><?php echo esc_textarea($sc['attr_hours']); ?></textarea><div class="evo-desc">Ten sam zapis co godziny obiektu. Atrakcja bywa czynna inaczej — plaża od maja, gdy recepcja cały rok.</div></div>

                </div>

                <div class="evo-box">
                    <h3>Dodatkowe obiekty i usługi (encje podrzędne)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Każda pozycja to osobny węzeł w grafie (np. parking, restauracja, sala konferencyjna, plac zabaw). Gdy ustawiony jest typ działalności inny niż „Organizacja", encje są powiązane z obiektem (#place) przez <code>containedInPlace</code>. Opisuje ofertę dokładniej niż jeden typ działalności.</div></details>
                    <div id="evk-sub-list">
                        <?php foreach ($subs as $row) { echo $render_sub_row((array) $row); } ?>
                    </div>
                    <template id="evk-sub-tpl"><?php echo $render_sub_row([]); ?></template>
                    <button type="button" class="button evo-mt-xs" id="evk-sub-add"><span class="dashicons dashicons-plus-alt2 evo-ico-sm evo-ico-lead"></span> Dodaj obiekt</button>
                    <script>
                    (function(){
                        var list = document.getElementById('evk-sub-list');
                        var tpl  = document.getElementById('evk-sub-tpl');
                        var add  = document.getElementById('evk-sub-add');
                        if (!list || !tpl || !add) return;
                        add.addEventListener('click', function(){
                            list.appendChild(tpl.content.cloneNode(true));
                        });
                        list.addEventListener('click', function(e){
                            var btn = e.target.closest('.evk-sub-remove');
                            if (btn) btn.closest('.evk-sub-row').remove();
                        });
                    })();
                    </script>

                </div>

                <div class="evo-box">
                    <h3>Opis organizacji per język</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Języki pobierane z modułu Tłumaczenia. Opis PL jest domyślnym fallbackiem.</div></details>
                    <div class="evo-field"><label>Polski (pl) — domyślny</label><textarea name="evk_schema_desc[pl]" rows="3" class="evo-w-full"><?php echo esc_textarea($descs['pl'] ?? ''); ?></textarea></div>
                    <?php foreach ($langs as $code => $lang_data): ?>
                    <div class="evo-field">
                        <label><?php echo esc_html($lang_data['name']); ?> (<?php echo esc_html($code); ?>)</label>
                        <textarea name="evk_schema_desc[<?php echo esc_attr($code); ?>]" rows="3" class="evo-w-full"><?php echo esc_textarea($descs[$code] ?? ''); ?></textarea>
                    </div>
                    <?php endforeach; ?>

                </div>

                <div class="evo-box">
                    <h3>Linki społecznościowe (sameAs)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Jeden URL na linię. Jeśli pole jest puste, moduł automatycznie przeszuka menu nawigacyjne.</div></details>
                    <div class="evo-field"><label>URLs (jeden na linię)</label><textarea name="evk_schema_socials" rows="4" class="evo-mono" style="max-width:480px"><?php echo esc_textarea(implode("\n", $socials)); ?></textarea></div>

                </div>

                <div class="evo-box">
                    <h3>Waluty per język (WooCommerce)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Dla produktów WooCommerce — przypisz walutę do wersji językowej.</div></details>
                    <?php foreach ($langs as $code => $lang_data): ?>
                    <div class="evo-field evk-schema-curr-row">
                        <label class="evk-schema-curr-lang"><?php echo esc_html($lang_data['name']); ?> (<?php echo esc_html($code); ?>)</label>
                        <input type="text" name="evk_schema_curr[<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr($currs[$code] ?? ''); ?>" placeholder="EUR" class="evo-w-80">
                    </div>
                    <?php endforeach; ?>

                </div>

                <div class="evo-box">
                    <h3>Edytor węzłów — właściwości spoza listy</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
                        Ujście na wszystko, czego nie ma w polach wyżej, i na to, co schema.org dopiero doda.
                        Każdy wiersz to <strong>węzeł + właściwość + wartość</strong>.
                        <ul style="margin:8px 0 0 18px;list-style:disc">
                            <li><strong>Wpisane tutaj wygrywa</strong> z tym, co moduł wyliczył sam — dlatego da się poprawić każdą wartość, ale i zepsuć literówką. Sprawdź w podglądzie niżej.</li>
                            <li><strong>Pusta wartość usuwa</strong> właściwość z węzła. To jedyny sposób, żeby zdjąć coś, co moduł wstawia zawsze.</li>
                            <li>Wartość zaczynająca się od <code>{</code> albo <code>[</code> i będąca poprawnym JSON-em wchodzi jako <strong>struktura</strong>, np. <code>{"@type":"QuantitativeValue","value":42}</code>. Reszta wchodzi jako tekst.</li>
                            <li>Podpowiedzi zmieniają się wraz z węzłem, bo <code>checkinTime</code> istnieje na obiekcie noclegowym, a nie na organizacji. Klucz spoza listy <strong>wolno wpisać</strong> — dostaniesz tylko ostrzeżenie.</li>
                            <li>Właściwości zaczynających się od <code>@</code> nie da się ustawić: <code>@id</code> i <code>@type</code> spinają graf i podmienione rozspoiłyby go.</li>
                        </ul>
                    </div></details>
                    <?php foreach ($znane as $wk => $lista): ?>
                    <datalist id="evk-wlasciwosci-<?php echo esc_attr($wk); ?>">
                        <?php foreach ($lista as $wl): ?><option value="<?php echo esc_attr($wl); ?>"></option><?php endforeach; ?>
                    </datalist>
                    <?php endforeach; ?>
                    <div id="evk-wlasne-list">
                        <?php foreach ($wlasne as $row) { echo $render_wlasny_row((array) $row); } ?>
                    </div>
                    <template id="evk-wlasne-tpl"><?php echo $render_wlasny_row([]); ?></template>
                    <button type="button" class="button evo-mt-xs" id="evk-wlasne-add"><span class="dashicons dashicons-plus-alt2 evo-ico-sm evo-ico-lead"></span> Dodaj właściwość</button>
                    <div id="evk-wlasne-ostrzezenia" class="evo-desc"></div>
                    <script>
                    (function () {
                        var list = document.getElementById('evk-wlasne-list');
                        var tpl  = document.getElementById('evk-wlasne-tpl');
                        var add  = document.getElementById('evk-wlasne-add');
                        var info = document.getElementById('evk-wlasne-ostrzezenia');
                        if (!list || !tpl || !add) return;

                        /* Listy podpowiedzi idą z PHP jednym obiektem — druga kopia
                           nazw wpisana w skrypcie rozjechałaby się z rejestrem przy
                           pierwszej dopisanej właściwości. */
                        var znane = <?php echo wp_json_encode($znane); ?>;

                        function odswiezWiersz(row) {
                            var wezel = row.querySelector('.evk-wlasne-wezel');
                            var klucz = row.querySelector('.evk-wlasne-klucz');
                            if (!wezel || !klucz) return;
                            klucz.setAttribute('list', 'evk-wlasciwosci-' + wezel.value);
                        }

                        /* OSTRZEŻENIE, NIE BLOKADA. Klucz spoza listy bywa
                           prawidłowy — schema.org rośnie szybciej, niż aktualizuje
                           się wtyczka. Blokada zapisu zamieniłaby furtkę w kolejną
                           zamkniętą listę, czyli w to, przed czym ten edytor jest. */
                        function odswiezOstrzezenia() {
                            var obce = [];
                            var wiersze = list.querySelectorAll('.evk-wlasne-row');
                            for (var i = 0; i < wiersze.length; i++) {
                                var wezel = wiersze[i].querySelector('.evk-wlasne-wezel');
                                var klucz = wiersze[i].querySelector('.evk-wlasne-klucz');
                                if (!wezel || !klucz) continue;
                                var v = klucz.value.trim();
                                var zly = v !== '' && !/^[A-Za-z][A-Za-z0-9_]*$/.test(v);
                                var spoza = v !== '' && !zly &&
                                    (znane[wezel.value] || []).indexOf(v) === -1;
                                klucz.style.borderColor = (zly || spoza) ? '#d98500' : '';
                                if (zly)   obce.push('„' + v + '" nie jest nazwą właściwości — ten wiersz NIE zostanie zapisany.');
                                if (spoza) obce.push('„' + v + '" nie ma na liście znanych właściwości tego węzła. Zapisze się — sprawdź pisownię w schema.org.');
                            }
                            if (info) info.textContent = obce.join(' ');
                        }

                        function odswiez() {
                            var wiersze = list.querySelectorAll('.evk-wlasne-row');
                            for (var i = 0; i < wiersze.length; i++) odswiezWiersz(wiersze[i]);
                            odswiezOstrzezenia();
                        }

                        add.addEventListener('click', function () {
                            list.appendChild(tpl.content.cloneNode(true));
                            odswiez();
                        });
                        list.addEventListener('click', function (e) {
                            var btn = e.target.closest('.evk-sub-remove');
                            if (btn) { btn.closest('.evk-wlasne-row').remove(); odswiezOstrzezenia(); }
                        });
                        list.addEventListener('change', odswiez);
                        list.addEventListener('input',  odswiezOstrzezenia);
                        odswiez();
                    })();
                    </script>
                </div>

                <div class="evo-box">
                    <h3>Podgląd JSON-LD</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
                        Graf <strong>strony głównej</strong>, zbudowany tą samą drogą co wyjście w <code>&lt;head&gt;</code>.
                        Pokazuje <strong>ustawienia ZAPISANE</strong> — po zmianie pola trzeba zapisać, żeby zobaczyć skutek.
                        Węzłów <code>BlogPosting</code>, <code>FAQPage</code> i <code>Product</code> tu nie ma:
                        powstają tylko na wpisie, stronie z akordeonem i karcie produktu, a panel nie jest żadną z nich.
                    </div></details>
                    <?php
                    $podglad = EVK_Schema::get_instance()->podglad_json($sc);
                    $adres   = home_url('/');
                    ?>
                    <?php if ($podglad === ''): ?>
                    <p class="evo-desc">Graf wychodzi pusty — moduł jest wyłączony albo wszystkie bloki są odhaczone.</p>
                    <?php else: ?>
                    <details class="evo-note">
                        <summary>Pokaż graf (<?php echo count(json_decode($podglad, true)['@graph']); ?> węzłów)</summary>
                        <pre class="evo-mono" style="max-height:420px;overflow:auto;white-space:pre-wrap;word-break:break-word"><?php echo esc_html($podglad); ?></pre>
                    </details>
                    <?php endif; ?>
                    <p class="evo-mt-xs">
                        <a class="button" target="_blank" rel="noopener"
                           href="https://search.google.com/test/rich-results?url=<?php echo rawurlencode($adres); ?>">
                            <span class="dashicons dashicons-external evo-ico-sm evo-ico-lead"></span> Sprawdź w Rich Results Test
                        </a>
                    </p>
                    <div class="evo-desc">Google pobiera stronę <strong>ze swojej strony</strong>, więc test działa tylko dla adresów dostępnych publicznie. Na <code>localhost</code> i za logowaniem nie zadziała — to nie jest usterka przycisku.</div>
                </div>

                <div class="evo-box">
                    <h3>Aktywne bloki JSON-LD</h3>
                    <div class="evo-grid evo-mb-lg" style="--evo-col:220px;--evo-gap:10px">
                        <?php
                        $blocks = [
                            'block_website'    => 'WebSite',
                            'block_org'        => 'Organization',
                            'block_breadcrumb' => 'BreadcrumbList',
                            'block_webpage'    => 'WebPage',
                            'block_article'    => 'BlogPosting (wpisy)',
                            'block_faq'        => 'FAQPage (Bricks accordion)',
                            'block_product'    => 'Product (WooCommerce)',
                            'block_attraction' => 'TouristAttraction',
                        ];
                        foreach ($blocks as $key => $label): ?>
                        <label class="evo-choice">
                            <input type="checkbox" name="evk_schema[<?php echo $key; ?>]" value="1" <?php checked(!empty($sc[$key])); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                
                </div>

<script>
                (function () {
                    var select = document.getElementById('evk-org-type');
                    var nazwa  = document.getElementById('evk-preset-nazwa');
                    if (!select) return;

                    var mapa;
                    try { mapa = JSON.parse(select.dataset.presety || '{}'); }
                    catch (e) { return; }   // bez mapy zostaje stan z serwera

                    /* Etykiety branż idą z PHP jednym obiektem. Druga kopia nazw
                       wpisana w skrypcie rozjechałaby się z rejestrem przy
                       pierwszej zmianie nazwy branży. */
                    var etykiety = <?php
                        $et = [];
                        foreach (EVK_Schema::presety() as $pk => $pv) $et[$pk] = $pv['etykieta'];
                        echo wp_json_encode($et);
                    ?>;

                    function odswiez() {
                        var preset = mapa[select.value] || 'firma-lokalna';
                        if (nazwa) nazwa.textContent = etykiety[preset] || preset;
                        var pola = document.querySelectorAll('[data-preset]');
                        for (var i = 0; i < pola.length; i++) {
                            /* `hidden`, a nie usunięcie z formularza: ukryte pole
                               DALEJ SIĘ ZAPISUJE. Zmiana typu działalności nie może
                               kasować tego, co ktoś wpisał — powrót do poprzedniego
                               typu ma przywrócić wartości, a nie zastać puste pola. */
                            pola[i].hidden =
                                pola[i].dataset.preset.split(' ').indexOf(preset) === -1;
                        }
                    }

                    select.addEventListener('change', odswiez);
                    odswiez();
                })();
                </script>

<?php evoke_one_pasek_zapisu('Zapisz ustawienia Schema'); ?>
            </form>
