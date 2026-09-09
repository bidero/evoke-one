/**
 * Zapis formularza ustawień bez przeładowania strony.
 *
 * PYTANIE, KTÓRE ROZSTRZYGA TEN PLIK, jest o bezpieczeństwo, nie o wygodę:
 * przez przełącznik AJAX przechodzi 0/1 na wskazanym polu, a przez ten uchwyt
 * CAŁA TABLICA ustawień. Pominięta sanitacja nie jest tu niedogodnością.
 *
 * Wartość jedzie do bazy przez `update_option()`, a ono przepuszcza ją przez
 * `sanitize_callback` zarejestrowany w `register_setting()`. Tą samą drogą jedzie
 * przełącznik AJAX od kilkudziesięciu wydań — `evk_preserve_toggle()` istnieje
 * właśnie dlatego, że callback się wtedy NAPRAWDĘ odpala.
 *
 * WARUNKIEM tej drogi jest to, że każda opcja z listy dozwolonych MA takie sito.
 * Wpis bez `sanitize_callback` zapisywałby surową tablicę z żądania prosto do
 * bazy, a uchwyt wyglądałby przy tym identycznie — dlatego pierwsze sprawdzenie
 * niżej pyta o to wprost, po nazwie opcji.
 *
 * Uchwyt wołał kiedyś filtr dodatkowo, jawnie, „na wszelki wypadek". Zmierzone
 * mutacją: usunięcie tego wołania nie zmieniało NICZEGO — wartość i tak
 * przechodziła przez sito. Kod, którego usunięcie niczego nie psuje, nie broni
 * przed niczym, więc poszedł, a sprawdzenie zostało przepisane na warunek,
 * który naprawdę rozstrzyga.
 *
 * Sitem jest PRAWDZIWY `sanitize_settings()` modułu sierotek, nie atrapa:
 * atrapa sprawdzałaby wyłącznie, czy moja imitacja usuwa to, co sama uznała
 * za groźne.
 */

const { phpOutput, rgb } = require('./lib/harness');

const zapisz = (form, stan, option) =>
  JSON.parse(phpOutput('zapis.php', JSON.stringify(JSON.stringify(
    Object.assign({ form: form }, stan ? { stan } : {}, option ? { option } : {})))));

module.exports = async function (t) {

  // ── Sanitacja ──────────────────────────────────────────────────────────
  t.section('wartość z formularza przechodzi przez sito modułu');

  /* WARUNEK BEZPIECZEŃSTWA CAŁEGO UCHWYTU, sprawdzany po nazwie każdej opcji
     z listy dozwolonych — nie „czy jakieś sito istnieje", tylko czy ma je
     dokładnie ta opcja, którą wolno zapisać. Dopisanie do listy opcji bez
     `sanitize_callback` zapali tutaj, zanim cokolwiek trafi do bazy surowe. */
  const czyste = zapisz('evk_sierotki[wyjatki]=abc');
  const bezSita = Object.keys(czyste.sita).filter((o) => !czyste.sita[o]);
  t.check('każda opcja z listy dozwolonych ma zarejestrowane sito', !bezSita.length,
    bezSita.join(', ') || Object.keys(czyste.sita).join(', '));

  const zeSmieciami = zapisz('evk_sierotki[wyjatki]=<script>alert(1)</script> abc');
  t.check('znaczniki HTML nie dojeżdżają do bazy',
    !/<script/.test(String(zeSmieciami.w_bazie.wyjatki)),
    JSON.stringify(zeSmieciami.w_bazie.wyjatki));
  t.check('a treść pola zostaje', /abc/.test(String(zeSmieciami.w_bazie.wyjatki)),
    JSON.stringify(zeSmieciami.w_bazie.wyjatki));

  /* Checkbox nieodznaczony NIE PRZYCHODZI w POST — tak działa formularz i tak
     samo musi to znieść zapis AJAX-em, bo pola idą tym samym `serialize()`. */
  const zPtaszkiem = zapisz('evk_sierotki[wyjatki]=&evk_sierotki[jednostki]=1');
  const bezPtaszka = zapisz('evk_sierotki[wyjatki]=', { enabled: 1, jednostki: 1, wyjatki: '' });
  t.check('zaznaczony checkbox zapisuje jedynkę', zPtaszkiem.w_bazie.jednostki === 1,
    String(zPtaszkiem.w_bazie.jednostki));
  t.check('a odznaczony — zero, mimo że nie ma go w wysyłce',
    bezPtaszka.w_bazie.jednostki === 0, String(bezPtaszka.w_bazie.jednostki));

  // ── Granica bezpieczeństwa ─────────────────────────────────────────────
  t.section('uchwyt zapisuje tylko to, co wolno');

  const obca = zapisz('evk_darkmode[x]=1', null, 'evk_darkmode');
  t.check('opcja spoza listy jest odrzucona',
    obca.odpowiedz.success === false && /not_allowed/.test(String(obca.odpowiedz.data)),
    String(obca.odpowiedz.data));

  /* Odrzucenie musi być PRZED zapisem, nie po. Uchwyt, który zapisuje i dopiero
     potem sprawdza, przechodziłby sprawdzenie wyżej i psuł dane. */
  t.check('i niczego przy tym nie zapisuje',
    obca.w_bazie.wyjatki === '' && obca.w_bazie.jednostki === 0,
    JSON.stringify(obca.w_bazie));

  /* WYSYŁKA BEZ TABLICY TEJ OPCJI TO NIE JEST „ZAPISZ PUSTKĘ". Uchwyt, który
     zapisałby wtedy pustą tablicę, kasowałby ustawienia modułu przy każdym
     żądaniu, które przyszło nie stąd. */
  const bezDanych = zapisz('cos_innego=1', { enabled: 1, jednostki: 1, wyjatki: 'abc' });
  t.check('wysyłka bez danych tej opcji jest odrzucona',
    bezDanych.odpowiedz.success === false, String(bezDanych.odpowiedz.data));
  t.check('a ustawienia zostają nietknięte',
    bezDanych.w_bazie.wyjatki === 'abc' && bezDanych.w_bazie.jednostki === 1,
    JSON.stringify(bezDanych.w_bazie));

  // ── Współistnienie z przełącznikiem AJAX ───────────────────────────────
  t.section('zapis formularza nie gasi przełącznika modułu');

  /* Ten sam moduł ma DWIE drogi zapisu: przełącznik (pole `enabled`) i formularz
     (reszta pól). Formularz nie niesie `enabled`, więc zapis bez tej ostrożności
     wyłączałby moduł za każdym razem, gdy ktoś zmieni cokolwiek innego —
     po cichu, bo ekran po zapisie wyglądałby poprawnie. */
  const wlaczony = zapisz('evk_sierotki[wyjatki]=abc', { enabled: 1, jednostki: 0, wyjatki: '' });
  t.check('włączony zostaje włączony', wlaczony.w_bazie.enabled === 1,
    JSON.stringify(wlaczony.w_bazie));

  /* I odwrotnie — inaczej „zachowujemy stan" znaczyłoby „zawsze włączamy". */
  const wylaczony = zapisz('evk_sierotki[wyjatki]=abc', { enabled: 0, jednostki: 0, wyjatki: '' });
  t.check('a wyłączony zostaje wyłączony', wylaczony.w_bazie.enabled === 0,
    JSON.stringify(wylaczony.w_bazie));

  // ── Ekran w przeglądarce ───────────────────────────────────────────────
  t.section('przycisk zostaje, znika przeładowanie');

  const ekran = phpOutput('tab.php', 'fe-sierotki');

  /* FORMULARZ MA ZOSTAĆ PEŁNOPRAWNY. Skrypt jest nakładką: gdy nie wystartuje
     albo uchwyt odpowie błędem, formularz musi pojechać do `options.php` po
     staremu. Ekran ustawień, którego nie da się zapisać, jest gorszy niż
     przeładowanie — dlatego `action` i `settings_fields()` zostają na miejscu. */
  t.check('formularz nadal umie pojechać zwykłą drogą',
    /action="options\.php"/.test(ekran) && /name="option_page"/.test(ekran),
    'action + settings_fields na miejscu');
  t.check('i jest oznaczony do zapisu bez przeładowania',
    /data-evo-zapis="evk_sierotki"/.test(ekran), 'data-evo-zapis');
  t.check('przycisk zapisu nie zniknął', /type="submit"/.test(ekran), 'jest');

  /* ZAKŁADKA MUSI SIEDZIEĆ W `.wrap`, tak jak na żywo. Zmienne kolorów panelu
     są zadeklarowane na `.wrap`, nie na `:root` — wstrzyknięcie samej treści
     zakładki daje otoczenie, w którym `var(--evo-…)` nie rozwiązuje się wcale
     i każdy pomiar koloru mówi o czymś, czego użytkownik nigdy nie zobaczy.
     Zmierzone: bez tej otoczki komunikat wychodził czarny. */
  const strona = await t.open('panel-start.html', {
    viewport: { width: 1200, height: 900 },
    head: 'window.evkZapis = { url: "about:blank", nonce: "x" };'
        + 'window.__wyslane = []; window.__nawigacje = 0;'
        + '(function () { var o = XMLHttpRequest.prototype.open;'
        + '  XMLHttpRequest.prototype.open = function (m, u) { window.__wyslane.push(m + " " + u); return o.apply(this, arguments); };'
        + '}());'
        + 'window.__panel = ' + JSON.stringify('<div class="wrap evo-control-center">' + ekran + '</div>') + ';',
  });

  /* Zwykły submit przeładowałby stronę. Liczymy to wprost: nasłuch na
     `beforeunload` jest jedynym sygnałem, który odróżnia „zapisało w miejscu"
     od „wyszło na options.php i wróciło". */
  await strona.evaluate(() => {
    window.addEventListener('beforeunload', () => { window.__nawigacje++; });
    document.querySelector('form[data-evo-zapis]').requestSubmit();
  });
  await strona.waitForTimeout(300);

  const po = await strona.evaluate(() => ({
    wyslane: window.__wyslane.slice(),
    nawigacje: window.__nawigacje,
    info: (document.querySelector('.evo-zapis-info') || {}).textContent || '',
  }));

  t.check('submit idzie AJAX-em, nie przeładowaniem',
    po.wyslane.length === 1 && po.nawigacje === 0,
    po.wyslane.length + ' żądań, ' + po.nawigacje + ' nawigacji');

  /* ŻĄDANIE PADŁO — w fixturze nie ma czego odpytać — więc powyższe pokazuje
     przy okazji ŚCIEŻKĘ AWARYJNĄ: jedno żądanie, nie kaskada. I nie jest to
     detal. `$form.off('submit')` NIE zdejmuje nasłuchu delegowanego na
     dokumencie, więc pierwsza wersja wracała tym samym uchwytem do siebie:
     zmierzone 264 żądania w trzysta milisekund przy jednym kliknięciu.
     Wysłanie awaryjne idzie teraz natywnym `form.submit()`, który nasłuchów
     nie odpala wcale. */
  t.check('nieudany zapis nie robi kaskady żądań', po.wyslane.length === 1,
    po.wyslane.length + ' żądań');
  t.check('i mówi wprost, że wysyła formularz zwykłą drogą',
    /zwykłą drogą/.test(po.info), po.info || 'brak');

  /* ŚCIEŻKA UDANA. Podstawiamy `$.post` dopiero tutaj, bo skrypt bierze `$`
     z globalnego jQuery przy starcie i woła `.post` dopiero przy zapisie —
     podmiana metody na tym samym obiekcie działa, a init-script wykonuje się
     zanim jQuery w ogóle istnieje. */
  await strona.evaluate(() => {
    window.__wyslane = [];
    window.jQuery.post = function () {
      window.__wyslane.push('POST udany');
      return window.jQuery.Deferred().resolve({ success: true, data: {} }).promise();
    };
    document.querySelector('form[data-evo-zapis]').requestSubmit();
  });
  await strona.waitForTimeout(200);

  const udany = await strona.evaluate(() => {
    var el = document.querySelector('.evo-zapis-info');
    var cs = el ? getComputedStyle(el) : null;
    return {
      info: el ? el.textContent : '',
      nawigacje: window.__nawigacje,
      blad: !!el && el.classList.contains('is-err'),
      kolor: cs ? cs.color : '',
      widoczny: !!cs && cs.display !== 'none',
      przycisk: !document.querySelector('form[data-evo-zapis] [type=submit]').disabled,
    };
  });

  t.check('udany zapis potwierdza i nie przeładowuje',
    /Zapisano/.test(udany.info) && udany.nawigacje === 0 && !udany.blad,
    '„' + udany.info + '", ' + udany.nawigacje + ' nawigacji');

  /* KOMUNIKAT MA BYĆ ZIELONY — zgłoszone z użycia po pierwszym wydaniu.
     Mierzymy kolor wyliczony przez przeglądarkę, nie zapis w arkuszu: pierwsza
     wersja miała w regule zieleń (`--evo-on-dark`), a na ekranie wychodziła
     inaczej. Zieleń bierze się teraz z `.evo-save-msg`, czyli stąd, skąd biorą
     ją pozostałe potwierdzenia w panelu.

     Sprawdzamy KANAŁY, nie równość z jedną wartością: reguła ma pilnować, że
     to zieleń, a nie zamrażać konkretny odcień, którego nikt nie ustalał.
     Warunek brzmi „zielony przeważa i wyraźnie odstaje od czerwonego" — bez
     wymagania przewagi nad niebieskim, bo zieleń panelu (#047857) jest morska
     i taki próg odrzucałby kolor, który jest w porządku. */
  const [r, g, b] = rgb(udany.kolor);
  t.check('i jest zielony',
    udany.widoczny && g === Math.max(r, g, b) && g - r > 60,
    udany.kolor + (udany.widoczny ? '' : ' (ukryty!)'));

  /* Przycisk zablokowany na czas zapisu ma wrócić do użycia — inaczej po
     pierwszym zapisie ekran jest martwy aż do przeładowania, czyli dokładnie
     tego, co mieliśmy usunąć. */
  t.check('a przycisk wraca do użycia', udany.przycisk,
    udany.przycisk ? 'aktywny' : 'zablokowany');

  /* Komunikat musi mieć `role="status"` — bez przeładowania i bez powiadomienia
     WordPressa niewidzący nie dostaje żadnego sygnału, że zapis się wydarzył. */
  t.check('czytany przez czytnik ekranu',
    await strona.evaluate(() => {
      var el = document.querySelector('.evo-zapis-info');
      return !!el && el.getAttribute('role') === 'status';
    }), 'role="status"');

  await strona.close();
};
