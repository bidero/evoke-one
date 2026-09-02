/**
 * Cztery drobiazgi domykające audyt bezpieczeństwa (1.133.0).
 *
 * Trzy pierwsze są o wychodzeniu poza swoje miejsce — tekst, który miał być
 * treścią, stawał się znacznikiem. Czwarty to zwykła usterka znaleziona przy
 * okazji: przycisk wysyłał parametr, którego serwer nie czytał.
 *
 * * **Własny CSS panelu** szedł do strony taki, jaki przyszedł z formularza,
 *   a `</style><script>…` wychodziło z bloku i wykonywało się w panelu każdego
 *   administratora.
 * * **Adres strony źródłowej w skrzynce** trafiał do `href` bez sprawdzenia
 *   schematu. Bierze się z nagłówka `Referer`, czyli podaje go ten, kto wysłał
 *   formularz — `javascript:…` dawało w panelu link wykonujący skrypt.
 * * **Komunikat blokady logowania** dopuszczał `span` ze `style`, a wyświetla
 *   się na PUBLICZNEJ stronie logowania. Do tego dwie drogi zapisu miały dwie
 *   różne listy dozwolonych znaczników.
 * * **„Eksportuj grupę"** posyłało `group_id`, którego handler nie czytał —
 *   przycisk oddawał komplet tłumaczeń zamiast wybranej grupy.
 *
 * Sanityzacja komunikatu chodzi na prawdziwym `wp_kses` (kopia WordPressa
 * w `tests/php/wp/`), a Referer — na prawdziwym skrypcie strony skrzynki,
 * uruchomionym w przeglądarce z podstawionym AJAX-em.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('drobiazgi.php'));

  // ── Własny CSS panelu ─────────────────────────────────────────────────
  t.section('własny CSS nie wychodzi z bloku <style>');

  const c = php.css;
  t.check('zamknięcie stylu wycięte', c.wyjscie_ze_stylu.ma_zamkniecie === false,
    c.wyjscie_ze_stylu.wynik);
  t.check('wielkie litery nie pomagają', c.wielkie_litery.ma_zamkniecie === false,
    c.wielkie_litery.wynik);
  t.check('spacja po ukośniku nie pomaga', c.ze_spacja.ma_zamkniecie === false,
    c.ze_spacja.wynik);

  // Wycinamy WYŁĄCZNIE `</style`, nie wszystkie znaki „<" — parser HTML kończy
  // blok dokładnie na tej sekwencji, a współczesny CSS używa „<" w zapytaniach
  // zakresowych. Usuwanie każdego nawiasu popsułoby poprawne arkusze.
  t.check('zwykły CSS nietknięty', c.zwykly_css.wynik === '#adminmenu{background:#111}',
    c.zwykly_css.wynik);
  t.check('zapytanie zakresowe przeżywa',
    c.zapytanie_zakresu.wynik === '@media (400px <= width <= 700px){.evk{display:none}}',
    c.zapytanie_zakresu.wynik);

  // Nie tylko przy wypisywaniu: także przy zapisie, żeby w bazie nie osiadały
  // wartości, które trzeba potem odsiewać przy każdym wyświetleniu.
  t.check('sanityzator ustawień zarejestrowany', c.sanityzator_zarejestrowany === true,
    String(c.sanityzator_zarejestrowany));
  t.check('zapis czyści treść', c.przez_zapis && !/<\/\s*style/i.test(c.przez_zapis),
    String(c.przez_zapis));

  // ── Komunikat blokady logowania ───────────────────────────────────────
  t.section('komunikat na stronie logowania');

  const k = php.komunikat;
  t.check('atrybut style wycięty', k.ma_style === false, k.przez_grupe);
  t.check('skrypt wycięty', k.ma_script === false, k.przez_grupe);
  t.check('onerror wycięty', k.ma_onerror === false, k.przez_grupe);

  // Druga połowa: formatowanie ma zostać, inaczej „naprawa" polegałaby na
  // skasowaniu możliwości napisania czegokolwiek.
  t.check('formatowanie zostaje', k.ma_strong && k.ma_link && k.ma_span,
    k.przez_grupe);

  // Dwie drogi zapisu (formularz przez register_setting i zapis przez AJAX)
  // miały dwie różne listy — ten sam tekst dostawał różne sita zależnie od
  // tego, którym przyciskiem go zapisano.
  t.check('obie drogi zapisu dają ten sam wynik', k.obie_drogi_te_same === true,
    k.obie_drogi_te_same ? 'jedna lista' : 'RÓŻNE wyniki');

  // ── Eksport pojedynczej grupy ─────────────────────────────────────────
  t.section('„Eksportuj grupę" eksportuje grupę');

  const grupy = (wyjscie) => Object.keys(JSON.parse(wyjscie).tl_translations.groups);
  const jedna = grupy(phpOutput('tl-eksport.php', 'grupa'));
  const wszystkie = grupy(phpOutput('tl-eksport.php', 'admin'));

  t.check('z group_id wychodzi jedna grupa',
    jedna.length === 1 && jedna[0] === 'g2', jedna.join(', '));
  t.check('bez group_id wychodzą wszystkie', wszystkie.length === 2, wszystkie.join(', '));

  // ── Adres strony źródłowej w skrzynce ─────────────────────────────────
  t.section('adres źródłowy wiadomości');

  const head = 'window.__page = ' + JSON.stringify(phpOutput('inbox-page.php')) + ';';
  const p = await t.open('inbox-referer.html', { viewport: { width: 1400, height: 900 }, head, settle: 150 });

  const pokaz = async (ref) => {
    await p.evaluate((x) => window.__pokazReferer(x), ref);
    await p.waitForTimeout(80);
    return p.evaluate(() => window.__meta());
  };

  const zwykly = await pokaz('https://example.test/kontakt/');
  t.check('zwykły adres jest linkiem', zwykly.link === 'https://example.test/kontakt/',
    String(zwykly.link));

  // Sedno: esc() w skrypcie strony zabezpiecza cudzysłów, ale NIE schemat.
  const js = await pokaz('javascript:alert(1)');
  t.check('javascript: nie jest linkiem', js.link === null, String(js.link));
  t.check('ale adres nadal widać jako tekst', js.tekst.includes('javascript:alert(1)'),
    js.tekst.includes('javascript:alert(1)') ? 'widoczny w treści' : 'ZNIKNĄŁ');

  const dane = await pokaz('data:text/html,<script>alert(1)</script>');
  t.check('data: nie jest linkiem', dane.link === null, String(dane.link));

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();
};
