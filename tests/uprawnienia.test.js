/**
 * Kto może co w punktach AJAX Tłumaczeń.
 *
 * Naprawiane w 1.127.0. Do 1.126.0 uprawnienia dla akcji `tl_*` dokładał hook
 * w Role Managerze: na `admin_init` doklejał `manage_options` każdemu, kto ma
 * `evk_access_translations`, jeśli tylko nazwa akcji w żądaniu zaczynała się
 * od `tl_`. `admin-ajax.php` też odpala `admin_init`, więc wystarczyło zawołać
 * `tl_import` — punkt zapisujący `evk_snippets_advanced_content`, czyli PHP
 * wykonywany potem przez `eval()`. Rola do tłumaczenia fraz dawała w efekcie
 * wykonanie dowolnego kodu na serwerze.
 *
 * Test stoi na trzech nogach, bo naprawa może zawieść na trzy różne sposoby:
 *
 * * **Hook wraca** — licznik `add_cap()` na atrapie użytkownika. Sprawdzanie,
 *   czy w pliku nie ma pewnego słowa, byłoby sprawdzaniem tekstu; tu mierzymy
 *   zachowanie i złapie to także „poprawioną" wersję tego samego pomysłu.
 * * **Naprawa zabiera rolom pracę** — tłumacz ma dalej zapisywać tłumaczenia.
 *   Bez tej połowy „naprawa" polegałaby na zamknięciu wszystkiego.
 * * **Ograniczenie nie sięga danych** — paczka eksportu jest w teście PRAWDZIWA
 *   (osobny proces PHP, bo handler kończy się `exit`), więc widać w niej, co
 *   naprawdę wychodzi, a nie co helper deklaruje.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('tl-uprawnienia.php'));

  // ── Hook eskalacji ────────────────────────────────────────────────────
  t.section('doklejanie uprawnień po prefiksie akcji');

  // Żądanie takie, jakim eskalacja się odpalała: użytkownik z samym
  // `evk_access_translations` woła `tl_import`, my odpalamy WSZYSTKIE
  // callbacki `admin_init`.
  t.check('żaden hook admin_init nie nadaje uprawnień',
    Array.isArray(php.nadane_uprawnienia) && php.nadane_uprawnienia.length === 0,
    php.nadane_uprawnienia.length ? 'nadane: ' + php.nadane_uprawnienia.join(', ') : 'nic nie nadano');

  // ── Bramka punktów AJAX ───────────────────────────────────────────────
  t.section('bramka punktów AJAX Tłumaczeń');

  const akcje = Object.keys(php.bramka.tlumacz);
  t.check('sprawdzono wszystkie zapisy TL', akcje.length === 8, akcje.length + ' punktów');

  const wpuszcza = (kto) => akcje.filter((a) => php.bramka[kto][a] === 'wpuszczony');
  const odmawia  = (kto) => akcje.filter((a) => php.bramka[kto][a] === 'odmowa');

  t.check('tłumacz zapisuje tłumaczenia', wpuszcza('tlumacz').length === akcje.length,
    odmawia('tlumacz').join(', ') || 'wszystkie punkty otwarte');
  t.check('administrator zapisuje tłumaczenia', wpuszcza('admin').length === akcje.length,
    odmawia('admin').join(', ') || 'wszystkie punkty otwarte');
  t.check('redaktor odbija się od wszystkich', odmawia('redaktor').length === akcje.length,
    wpuszcza('redaktor').join(', ') || 'wszystkie punkty zamknięte');

  // Nazwy nonce'ów muszą zostać te, które drukują strony. Własna nazwa
  // oznaczałaby, że każdy zapis pada w produkcji, a test świeciłby na zielono,
  // bo atrapa nonce'a niczego nie weryfikuje.
  t.check('zapisy proszą o tl_ajax_nonce', php.nonce_zapisu === 'tl_ajax_nonce', String(php.nonce_zapisu));
  t.check('edytor inline prosi o tl_inline_nonce', php.nonce_inline === 'tl_inline_nonce', String(php.nonce_inline));

  // ── Import ────────────────────────────────────────────────────────────
  t.section('import — co wolno wgrać komu');

  const imp = php.tlumacz_import;
  t.check('tłumacz wgrywa tłumaczenia', imp.tl_translations && imp.tl_dd_keys,
    'tl_translations + tl_dd_keys');
  // To jest ten jeden warunek, dla którego całe wydanie powstało.
  t.check('tłumacz NIE wgrywa kodu PHP snippetu', imp.snippet_php === null,
    imp.snippet_php === null ? 'opcja nietknięta' : 'ZAPISANO: ' + imp.snippet_php);
  t.check('tłumacz NIE włącza snippetów', imp.snippety_wlaczone === null, String(imp.snippety_wlaczone));
  t.check('tłumacz NIE nadpisuje SMTP', imp.smtp === null, JSON.stringify(imp.smtp));
  t.check('tłumacz NIE nadpisuje ustawień bezpieczeństwa', imp.bezpieczenstwo === null,
    JSON.stringify(imp.bezpieczenstwo));
  t.check('tłumacz NIE zmienia hasła konserwacji', imp.haslo_konserwacji === null,
    String(imp.haslo_konserwacji));
  t.check('tłumacz NIE wstrzykuje CSS panelu', imp.white_label === null, JSON.stringify(imp.white_label));
  t.check('tłumacz NIE rusza newslettera', imp.newsletter === null && imp.zapytania_do_bazy === 0,
    imp.zapytania_do_bazy + ' zapytań do bazy');

  // Druga połowa: naprawa nie może po cichu obciąć panelu Import/Eksport.
  const adm = php.admin_import;
  t.check('administrator wgrywa dalej wszystko',
    adm.tl_translations && adm.snippet_php && adm.smtp && adm.haslo_konserwacji && adm.newsletter,
    JSON.stringify(adm));

  t.check('bez uprawnień import odmawia',
    php.obcy_import && php.obcy_import.success === false, JSON.stringify(php.obcy_import));
  t.check('bez uprawnień eksport kończy się wp_die',
    php.obcy_eksport && php.obcy_eksport.wp_die === true, JSON.stringify(php.obcy_eksport));

  // ── Eksport — prawdziwa paczka ────────────────────────────────────────
  t.section('eksport — co naprawdę wychodzi z serwera');

  const paczkaTl = JSON.parse(phpOutput('tl-eksport.php', 'tlumacz'));
  const kluczeTl = Object.keys(paczkaTl).filter((k) => !k.startsWith('_') && k !== 'exported_at' && k !== 'version');
  const obce = kluczeTl.filter((k) => !k.startsWith('tl_'));

  t.check('tłumacz dostaje moduły tłumaczeń', kluczeTl.includes('tl_translations') && kluczeTl.includes('tl_dd_keys'),
    kluczeTl.join(', '));
  t.check('w paczce tłumacza nie ma nic spoza tl_', !obce.length, obce.join(', ') || 'same tl_*');

  // Wartości, nie tylko nazwy kluczy: gdyby kiedyś któryś zbieracz zaczął
  // dokładać hasło pod inną nazwą, sprawdzanie kluczy tego nie zauważy.
  const surowa = JSON.stringify(paczkaTl);
  t.check('paczka tłumacza nie niesie haseł ani kodu',
    !surowa.includes('tajne-haslo-smtp') && !surowa.includes('tajne-haslo-konserwacji')
    && !surowa.includes('kod snippetu'),
    surowa.length + ' bajtów');

  // Strona Tłumaczeń nie wysyła pola `modules` w ogóle, a zakładka
  // Import/Eksport wysyła listę zaznaczonych. Puste pole ≠ brak pola:
  // do 1.126.0 oba znaczyły to samo i „Eksportuj wszystko" na stronie
  // Tłumaczeń zwracało plik z samym nagłówkiem, bez ani jednej frazy.
  const paczkaAdmin = JSON.parse(phpOutput('tl-eksport.php', 'admin'));
  const paczkaPusta = JSON.parse(phpOutput('tl-eksport.php', 'puste'));
  t.check('brak pola modules = pełny eksport', Object.keys(paczkaAdmin).length > 20,
    Object.keys(paczkaAdmin).length + ' kluczy');
  t.check('puste pole modules = pusty eksport', Object.keys(paczkaPusta).length === 3,
    Object.keys(paczkaPusta).join(', '));

  // ── Ograniczenie modułów ──────────────────────────────────────────────
  t.section('ograniczenie modułów');

  t.check('administrator bez ograniczeń', php.limit_admin === null, String(php.limit_admin));
  t.check('tłumacz tylko moduły tl_',
    Array.isArray(php.limit_tlumacz) && php.limit_tlumacz.length > 0
    && php.limit_tlumacz.every((m) => m.startsWith('tl_')),
    (php.limit_tlumacz || []).join(', '));
  // Lista bierze się z rejestru modułów, nie z własnego spisu — inaczej nowy
  // moduł `tl_*` trzeba by dopisywać w dwóch miejscach.
  const tlWRejestrze = php.moduly_io.filter((m) => m.startsWith('tl_'));
  t.check('lista tłumacza pokrywa się z rejestrem',
    JSON.stringify(php.limit_tlumacz) === JSON.stringify(tlWRejestrze),
    tlWRejestrze.join(', '));
  t.check('obcy nie ma żadnego modułu',
    Array.isArray(php.limit_obcy) && php.limit_obcy.length === 0, JSON.stringify(php.limit_obcy));

  // ── Dostępy do modułów w Role Managerze ───────────────────────────────
  t.section('dostępy do modułów');

  const rm = php.role_manager;

  // Evoke FIELDS dołącza do czwórki obsługiwanej do 1.134.0. FIELDS jest
  // OSOBNĄ WTYCZKĄ: Evoke ONE potrafi nadać uprawnienie i pokazać je w panelu,
  // ale sprawdzić je musi sam FIELDS przy rejestracji menu i przy zapisach.
  // Test pilnuje tej połowy, którą mamy.
  t.check('zaznaczenie nadaje dostęp do FIELDS', rm.fields_nadane === true,
    String(rm.fields_nadane));
  t.check('odznaczenie go odbiera', rm.fields_odebrane === true, String(rm.fields_odebrane));
  // Druga połowa: dopisanie nowego dostępu nie może zjeść pozostałych.
  t.check('inne dostępy zapisują się obok', rm.tlumaczenia_obok === true,
    String(rm.tlumaczenia_obok));

  t.check('administrator dostaje komplet dostępów', rm.admin_dostaje.length === 5,
    rm.admin_dostaje.join(', '));
  t.check('FIELDS jest wśród nich', rm.admin_dostaje.includes('evk_access_fields'),
    rm.admin_dostaje.join(', '));

  // Bez tego filtra administrator nie widziałby własnych modułów, dopóki nie
  // przeładuje sesji — uprawnienie z `init` siada dopiero przy następnym logowaniu.
  t.check('filtr przepisuje dostęp administratorowi', rm.filtr_dla_admina === true,
    String(rm.filtr_dla_admina));
};
