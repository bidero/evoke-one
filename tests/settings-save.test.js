/**
 * Zapis ustawień bez przeładowania — obie strony.
 *
 * Po stronie PHP (`tests/php/settings-save.php`) sedno jest jedno: wynik ma być
 * IDENTYCZNY z zapisem przez `options.php`. To nie jest wymaganie kosmetyczne —
 * options.php JEST drogą zapasową, na którą skrypt spada, gdy AJAX padnie.
 * Gdyby obie drogi zapisywały inaczej, ta sama zakładka dawałaby dwa różne
 * wyniki zależnie od tego, czy akurat zadziałał JavaScript.
 *
 * Po stronie przeglądarki sprawdzamy trzy rzeczy, każdą okupioną błędem
 * z poprzednich wydań:
 *
 * * **Kolejność pól przeżywa wysłanie.** Ładunek idzie ciągiem par, nie
 *   obiektem JS — obiekt porządkuje klucze wyglądające na liczby NUMERYCZNIE
 *   i przestawione wiersze repeatera wracają na stare miejsca (1.38.0).
 * * **W żądaniu jest DOKŁADNIE JEDNO pole `action`.** `settings_fields()`
 *   drukuje `action=update`; bez odsiania o routingu decyduje to, które z dwóch
 *   wygra przy parsowaniu (1.38.0).
 * * **Awaria AJAX-a wysyła formularz normalną drogą.** Skrypt nie może być
 *   jedyną drogą zapisu.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  // ── Przełącznik elementów ─────────────────────────────────────────────
  // Handler `evk_ajax_toggle` ma własną białą listę „opcja => dozwolone pola".
  // To DRUGIE miejsce, w którym trzeba pamiętać o nowym elemencie, i dokładnie
  // na tym poległ Offcanvas Menu: był w rejestrze, miał włącznik w panelu,
  // a przełącznik odbijał się z `not_allowed`. Panel rysował się poprawnie,
  // więc zobaczył to dopiero użytkownik.
  //
  // Test woła PRAWDZIWY handler dla każdej pozycji rejestru — porównanie dwóch
  // list byłoby tautologią, odkąd jedna powstaje z drugiej.
  t.section('każdy element z rejestru da się włączyć');

  const tg = JSON.parse(phpOutput('toggle.php'));
  const rejected = Object.keys(tg.result).filter((k) => tg.result[k] !== 'ok');
  t.check('rejestr nie jest pusty', tg.elements.length > 0, tg.elements.length + ' elementów');
  t.check('żaden nie odbija się od whitelisty', !rejected.length,
    rejected.map((k) => k + ' → ' + tg.result[k]).join(' | ') || 'wszystkie przechodzą');

  // ── Serwer ────────────────────────────────────────────────────────────
  t.section('endpoint zapisuje tak samo jak options.php');

  const php = JSON.parse(phpOutput('settings-save.php'));

  t.check('zapis się udaje', php.response && php.response.success === true,
    JSON.stringify(php.response));
  t.check('wynik identyczny z zapisem przez options.php', php.same_result === true,
    php.same_result ? 'te same dane' : 'RÓŻNE dane');
  t.check('pola z formularza trafiają do opcji', php.saved_side === 'left', String(php.saved_side));

  // Nazwa nonce'a musi zgadzać się z tym, co drukuje settings_fields( $grupa ).
  // Własna nazwa oznaczałaby, że KAŻDY zapis pada w produkcji, a test i tak
  // świeciłby na zielono, bo atrapa nonce'a niczego nie weryfikuje.
  t.check('prosi o nonce z settings_fields()', php.nonce_asked === 'evoke_one_a11y-options',
    String(php.nonce_asked));

  // Odznaczony checkbox nie przychodzi w żądaniu wcale. Gdyby brak pola
  // oznaczał „pomiń", nie dałoby się niczego odznaczyć — zmiana wyglądałaby
  // na zapisaną i wracała po odświeżeniu strony.
  t.check('brak pola w żądaniu wyłącza opcję', php.unchecked_off === true,
    php.unchecked_off ? 'wyłączone' : 'zostało włączone');

  // Ostrzejszy przypadek: CAŁA opcja nieobecna w żądaniu. `evk_motion` jest
  // w tej samej grupie co `evk_a11y`, ale ma w formularzu jedno pole —
  // odznaczony checkbox nie przysyła nic. Gdyby brak opcji oznaczał „pomiń",
  // „Szanuj systemowe ograniczenie animacji" nie dałoby się wyłączyć.
  t.check('brak całej opcji też wyłącza', php.lone_checkbox_off === true,
    php.lone_checkbox_off ? 'wyłączone' : 'zostało włączone');

  // Przełącznik modułu ma osobny AJAX i nie jest częścią formularza.
  t.check('przełącznik modułu przeżywa zapis formularza', php.toggle_kept === true,
    php.toggle_kept ? 'zachowany' : 'ZGASZONY');

  // Biała lista pochodzi z rejestru WordPressa — zapis grupy nie może ruszyć
  // opcji, która do niej nie należy, choćby przyszła w tym samym żądaniu.
  t.check('opcja spoza grupy nietknięta', php.other_untouched === true,
    php.other_untouched ? 'nietknięta' : 'NADPISANA');

  t.check('nieznana grupa odrzucona',
    php.unknown_group && php.unknown_group.success === false, JSON.stringify(php.unknown_group));
  t.check('brak grupy odrzucony',
    php.no_group && php.no_group.success === false, JSON.stringify(php.no_group));
  t.check('rejestr grup niepusty', php.groups.length > 0, php.groups.join(', '));

  // ── Przeglądarka ──────────────────────────────────────────────────────
  t.section('formularz zakładki wysyła się bez przeładowania');

  const p = await t.open('settings-save.html', { viewport: { width: 1200, height: 800 }, settle: 120 });

  const stopped = await p.evaluate(() => window.__submit('zwykly'));
  await p.waitForTimeout(60);
  t.check('domyślne wysłanie zatrzymane', stopped === false, 'preventDefault: ' + !stopped);

  const body = await p.evaluate(() => window.__lastBody());
  t.check('poszło żądanie AJAX', !!body, body ? body.url : 'brak');

  const actions = body.names.filter((n) => n === 'action');
  t.check('dokładnie jedno pole „action"', actions.length === 1, actions.length + ' szt.');
  t.check('akcja to evk_settings_save', /(^|&)action=evk_settings_save/.test(body.raw),
    body.raw.slice(-40));

  // option_page i _wpnonce NIOSĄ grupę i uprawnienie — bez nich endpoint
  // nie ma jak sprawdzić, co wolno zapisać.
  t.check('grupa i nonce jadą razem z polami',
    body.names.includes('option_page') && body.names.includes('_wpnonce'),
    body.names.slice(0, 4).join(', '));

  // Kolejność: pola repeatera są w DOM jako 1, 2, 0 i tak mają dojść.
  const rows = body.names.filter((n) => /^evk_a11y\[rows\]/.test(n))
    .map((n) => n.match(/\[rows\]\[(\d+)\]/)[1]);
  t.check('kolejność pól repeatera zachowana', rows.join(',') === '1,2,0', rows.join(','));

  const note = await p.evaluate(() => window.__note('zwykly'));
  t.check('pasek zapisu melduje wynik', note && !note.err, note ? note.text : 'brak komunikatu');

  // Formularz z własną obsługą (biblioteka animacji) ma zostać nietknięty.
  const before = await p.evaluate(() => window.__posts.length);
  await p.evaluate(() => window.__submit('wlasny'));
  await p.waitForTimeout(60);
  const afterOwn = await p.evaluate(() => window.__posts.length);
  t.check('formularz z własną obsługą pominięty', afterOwn === before,
    afterOwn - before + ' dodatkowych żądań');

  // Formularz bez option_page nie jest nasz.
  await p.evaluate(() => window.__submit('obcy'));
  await p.waitForTimeout(60);
  const afterAlien = await p.evaluate(() => window.__posts.length);
  t.check('obcy formularz pominięty', afterAlien === before, afterAlien - before + ' dodatkowych');

  // ── Droga zapasowa ────────────────────────────────────────────────────
  t.section('gdy AJAX padnie, formularz idzie normalnie');

  await p.evaluate(() => { window.__reply = 'fail'; window.__nativeSubmits = 0; });
  await p.evaluate(() => window.__submit('zwykly'));
  await p.waitForTimeout(80);

  const submits = await p.evaluate(() => window.__nativeSubmits);
  t.check('formularz wysłany natywnie', submits === 1, submits + ' razy');

  const errNote = await p.evaluate(() => window.__note('zwykly'));
  t.check('komunikat o awarii widoczny', errNote && errNote.err,
    errNote ? errNote.text : 'brak');

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();
};
