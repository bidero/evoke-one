/**
 * Dokąd prowadzi link kliknięcia z newslettera.
 *
 * Naprawiane w 1.129.0. Do 1.128.0 brak podpisu w linku znaczył „stary link,
 * przepuść po walidacji adresu", więc żądanie
 * `?evk_nl=click&evk_nl_token=cokolwiek&url=https://zly-adres/` przekierowywało,
 * gdzie tylko chciał wysyłający — z domeny klienta, więc dla czytającego
 * i dla filtrów pocztowych link wyglądał jak własny. Token subskrybenta niczego
 * nie chronił: przekierowanie działo się POZA sprawdzeniem, czy taki
 * subskrybent istnieje.
 *
 * To było jedyne ustalenie z audytu osiągalne dla kogoś NIEZALOGOWANEGO —
 * nie trzeba było mieć konta ani niczego znać, wystarczył adres strony.
 *
 * Zasada po naprawie: **podpis jest zgodą na wyjście poza serwis.** Bez podpisu
 * adres przechodzi przez `wp_validate_redirect()`, więc stare maile z linkami
 * wewnętrznymi działają dalej, a stare linki na zewnątrz kończą na stronie
 * głównej. Dlatego test ma osobno przypadek wewnętrzny i zewnętrzny: sprawdzenie
 * samego „nie przekierowuje na obcy adres" przeszłoby także wtedy, gdyby
 * naprawa zabiła wszystkie niepodpisane linki.
 */

const { phpOutput } = require('./lib/harness');

const DOM  = 'https://example.test';
const ZLY  = 'https://zly-adres.test/phishing';

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('newsletter-klik.php'));

  // ── Podpisany link ────────────────────────────────────────────────────
  t.section('link z podpisem');

  t.check('podpisany link zewnętrzny prowadzi do celu',
    php.podpisany_zewn.cel === ZLY, String(php.podpisany_zewn.cel));
  t.check('kliknięcie trafia do statystyk', php.podpisany_zewn.log.length === 1,
    JSON.stringify(php.podpisany_zewn.log));

  // Świadoma decyzja: o bezpieczeństwie decyduje podpis, więc uszkodzony token
  // nie zostawia czytającego na stronie głównej — tylko nie liczy kliknięcia.
  t.check('zmyślony token nie blokuje przekierowania',
    php.podpisany_zly_token.cel === ZLY, String(php.podpisany_zly_token.cel));
  t.check('ale kliknięcia bez subskrybenta nie liczymy',
    php.podpisany_zly_token.log.length === 0, JSON.stringify(php.podpisany_zly_token.log));

  // ── Bez podpisu ───────────────────────────────────────────────────────
  t.section('link bez podpisu');

  // To jest ten warunek, dla którego wydanie powstało.
  t.check('adres zewnętrzny bez podpisu NIE prowadzi do celu',
    php.bez_podpisu_zewn.cel !== ZLY, String(php.bez_podpisu_zewn.cel));
  t.check('trafia na stronę główną', php.bez_podpisu_zewn.cel === DOM,
    String(php.bez_podpisu_zewn.cel));

  // Druga połowa: naprawa nie może zabić starych maili z linkami do własnej
  // strony, a takich w newsletterze jest większość.
  t.check('adres wewnętrzny bez podpisu działa dalej',
    php.bez_podpisu_wewn.cel === DOM + '/artykul/', String(php.bez_podpisu_wewn.cel));
  t.check('i dalej liczy się jako kliknięcie',
    php.bez_podpisu_wewn.log.length === 1, JSON.stringify(php.bez_podpisu_wewn.log));

  // ── Podpis, który nie pasuje ──────────────────────────────────────────
  t.section('podpis, który nie pasuje');

  // Podpis wiąże adres z NUMEREM KAMPANII — bez tego jeden podpisany link
  // dałoby się przenieść do dowolnej innej wysyłki.
  t.check('podpis z innej kampanii nie przechodzi',
    php.podpis_z_innej_kampanii.cel === DOM, String(php.podpis_z_innej_kampanii.cel));
  t.check('podmieniony cel przy cudzym podpisie nie przechodzi',
    php.podmieniony_cel.cel === DOM, String(php.podmieniony_cel.cel));
  t.check('śmieciowy podpis nie przechodzi',
    php.podpis_smieciowy.cel === DOM, String(php.podpis_smieciowy.cel));

  // ── Adresy, które nie są adresami ─────────────────────────────────────
  t.section('adresy odrzucane z góry');

  t.check('javascript: nie przechodzi', php.javascript.cel === DOM, String(php.javascript.cel));
  t.check('adres protokołowo-względny nie przechodzi',
    php.protokolowo_wzgledny.cel === DOM, String(php.protokolowo_wzgledny.cel));
  t.check('pusty adres kończy na stronie głównej', php.pusty.cel === DOM, String(php.pusty.cel));

  // ── Zobacz w przeglądarce ─────────────────────────────────────────────
  t.section('„Zobacz w przeglądarce" — kampanie przed wysyłką');

  // Znalezione przy pisaniu tej naprawy, nie w audycie: numery kampanii są
  // kolejne, więc /nl/view/7/ zgaduje się samo, a wersja robocza to często
  // materiał przed premierą.
  //
  // KAŻDY STATUS W OSOBNYM PROCESIE, bo przy zgodzie handler renderuje stronę
  // i kończy `exit`. Pierwsza wersja tego testu pytała o wszystkie statusy
  // w jednym przebiegu i zdjęcie bramki wywalało cały plik wyjątkiem zamiast
  // pokazać, KTÓRE sprawdzenie padło.
  const widok = (status) => phpOutput('newsletter-klik.php', 'widok ' + status).trim();

  t.check('kampania robocza odmawia', widok('draft') === 'odmowa', widok('draft').slice(0, 40));
  t.check('kampania zaplanowana odmawia', widok('scheduled') === 'odmowa',
    widok('scheduled').slice(0, 40));

  // Druga połowa: kampania po wysyłce ma się dalej otwierać, także bez tokenu —
  // przekazany dalej link nie może przestać działać.
  const strona = widok('done');
  t.check('wysłana kampania renderuje treść', strona.includes('Treść kampanii'),
    strona.length + ' bajtów');
  t.check('strona podglądu jest noindex', strona.includes('noindex'),
    strona.includes('noindex') ? 'noindex,nofollow' : 'BRAK');
};
