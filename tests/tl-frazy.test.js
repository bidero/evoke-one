/**
 * Frazy tłumaczeń — co przeżywa drogę pole w panelu → opcja → `{tl_...}`.
 *
 * ZGŁOSZONE Z UŻYCIA: „moduł tłumaczeń nie wyświetla poprawnie elementów
 * z zapisanym <br> przy użyciu {tl_...}. Pomija <br>".
 *
 * Objaw brzmiał jak usterka WYŚWIETLANIA, a siedział w ZAPISIE: wszystkie
 * punkty zapisu frazy wołały `sanitize_textarea_field()`, a ono woła w środku
 * `wp_strip_all_tags()`. Znacznik nie tyle się nie pokazywał, co nie dojeżdżał
 * do bazy — i to razem ze sklejeniem słów („Pierwsza liniadruga linia").
 *
 * Dlatego sprawdzenia patrzą na OBA końce drogi: co wylądowało w opcji i co
 * wychodzi na stronę. Sprawdzenie samego wyjścia przechodziłoby także wtedy,
 * gdyby ktoś naprawił escapowanie, a zapis zostawił wycinający.
 *
 * Wszystko chodzi na PRAWDZIWYM `wp_kses` — patrz nagłówek `tests/php/tl-frazy.php`.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const d = JSON.parse(phpOutput('tl-frazy.php'));
  const s = d.sanityzacja;

  // ── Co ma przejść ───────────────────────────────────────────────────────
  t.section('fraza zachowuje znaczniki liniowe');

  t.check('łamanie wiersza zostaje', s.br === 'Pierwsza linia<br>druga linia', s.br);
  t.check('zapis XHTML też', s.br_xhtml === 'Pierwsza<br />druga', s.br_xhtml);
  t.check('wyróżnienie zostaje', s.wyroznienie.includes('<strong>'), s.wyroznienie);
  t.check('odnośnik zostaje z adresem',
    s.odnosnik.includes('<a href="https://evoke.pl">'), s.odnosnik);
  t.check('span z klasą zostaje',
    s.span_klasa.includes('<span class="akcent">'), s.span_klasa);

  /* Fraza bez znaczników ma przejść BAJT W BAJT tak jak dotąd — zmiana miała
     dotknąć wyłącznie tych fraz, które znacznik niosą. */
  t.check('tekst bez znaczników nietknięty',
    s.bez_znacznikow === 'Zwykły tekst bez niczego', s.bez_znacznikow);

  // ── Co ma wylecieć ──────────────────────────────────────────────────────
  /* KONTROLA NEGATYWNA I SEDNO BEZPIECZEŃSTWA. Przepuszczenie znaczników
     liniowych nie może otworzyć drogi na skrypt ani na własny układ — bez tych
     sprawdzeń „zachowuje <br>" przechodziłoby także dla zwykłego `return $value`. */
  t.section('a nie przepuszcza niczego groźnego');

  t.check('skrypt wylatuje', !s.skrypt.includes('<script'), s.skrypt);
  t.check('znacznik blokowy wylatuje', !s.blok.includes('<div'), s.blok);
  t.check('ramka wylatuje', !s.iframe.includes('<iframe'), s.iframe);
  /* Zdarzenie wylatuje, ale sam znacznik zostaje — to jest zachowanie kses,
     a nie niedopatrzenie: sito czyści ATRYBUTY, nie kasuje dozwolonego
     znacznika za towarzystwo. */
  t.check('atrybut zdarzenia wylatuje', !/onclick/i.test(s.zdarzenie), s.zdarzenie);
  t.check('ale dozwolony znacznik obok zostaje', s.zdarzenie.includes('<span>'), s.zdarzenie);

  // ── Pełna droga ─────────────────────────────────────────────────────────
  /* TO JEST TEN KONIEC, KTÓRY BYŁ ZEPSUTY. Zapis szedł przez
     `tl_sanitize_translations_payload()` i `tl_rebuild_dd_keys_from_rows()`,
     więc do opcji trafiała już fraza bez znacznika. */
  t.section('znacznik dojeżdża do bazy i wraca na stronę');

  t.check('w opcji tłumaczeń (pl)', d.w_bazie_pl === 'Pierwsza linia<br>druga linia', d.w_bazie_pl);
  t.check('w opcji tłumaczeń (en)', d.w_bazie_en === 'First line<br>second line', d.w_bazie_en);
  t.check('w kluczach Dynamic Data', d.w_dd_keys === 'Pierwsza linia<br>druga linia', d.w_dd_keys);

  t.check('{tl_...} oddaje znacznik, nie encję',
    d.dd_tag === 'Pierwsza linia<br>druga linia', d.dd_tag);
  /* Skrótkod wypisywał `&lt;br&gt;` jako widoczny tekst, bo szedł przez
     `esc_html()`. Sprawdzamy oba brzegi: znacznik jest i encji nie ma. */
  t.check('skrótkod [tl] też, a nie &lt;br&gt;',
    d.skrotkod.includes('<br>') && !d.skrotkod.includes('&lt;br'), d.skrotkod);

  /* Słowa nie mogą się skleić — to był drugi, cichszy objaw tego samego:
     `wp_strip_all_tags()` usuwał znacznik bez zostawiania spacji. */
  t.check('słowa nie sklejone', !d.dd_tag.includes('liniadruga'), d.dd_tag);
};
