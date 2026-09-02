/**
 * Publiczny zapis do newslettera — kto decyduje o double opt-in.
 *
 * Naprawiane w 1.130.0. Do 1.129.0 handler czytał `confirm` i `consent` wprost
 * z żądania, a to są atrybuty shortcode'u — decyzja autora strony, czy zapis
 * idzie przez potwierdzenie mailem i pod jaką treścią zgody. `confirm=0`
 * w POST-cie wpisywało adres na listę OD RAZU JAKO POTWIERDZONY, razem
 * z wpisem zgody zbudowanym z tego, co przysłał wysyłający.
 *
 * To nie jest wyłącznie kwestia techniczna: dowód zgody, którego treścią
 * sterował składający żądanie, nie jest dowodem.
 *
 * Podpis w teście pochodzi Z PRAWDZIWEGO FORMULARZA — plik PHP renderuje
 * shortcode i wyłuskuje `sig` z jego wyjścia. Policzenie podpisu tą samą
 * funkcją, co kod produkcyjny, sprawdzałoby wyłącznie, że `hash_hmac` jest
 * deterministyczne; tak sprawdzamy, że formularz i handler mówią o tym samym.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('newsletter-zapis.php'));

  const jak  = (k) => php[k].dodani.map((d) => d.jak).join(',');
  const ok   = (k) => php[k].odpowiedz && php[k].odpowiedz.success === true;
  const msg  = (k) => (php[k].odpowiedz && php[k].odpowiedz.data && php[k].odpowiedz.data.msg) || '';

  // ── Podpis w formularzu ───────────────────────────────────────────────
  t.section('formularz podpisuje swoje parametry');

  t.check('shortcode drukuje podpis', php.podpis_w_formularzu === true,
    php.podpis_w_formularzu ? 'sig obecny' : 'BRAK sig w formularzu');

  // Autor strony świadomie wyłączył potwierdzanie — to ma dalej działać,
  // inaczej „naprawa" polegałaby na zabraniu istniejącej opcji.
  t.check('confirm=0 z własnym podpisem zapisuje natychmiast',
    ok('z_podpisem_confirm0') && jak('z_podpisem_confirm0') === 'natychmiast',
    jak('z_podpisem_confirm0') || msg('z_podpisem_confirm0'));
  t.check('confirm=1 z podpisem idzie przez potwierdzenie',
    ok('z_podpisem_confirm1') && jak('z_podpisem_confirm1') === 'pending',
    jak('z_podpisem_confirm1') || msg('z_podpisem_confirm1'));

  // ── Bez podpisu ───────────────────────────────────────────────────────
  t.section('żądanie bez podpisu');

  // Formularz siedzi w treści podstrony, więc po aktualizacji krąży jeszcze
  // w pamięciach podręcznych. Nie odrzucamy takich zgłoszeń — wymuszamy
  // wariant bezpieczny, żeby zapisy nie padły na czas ważności cache'u.
  t.check('zapis się udaje mimo braku podpisu', ok('bez_podpisu_confirm0'),
    msg('bez_podpisu_confirm0'));
  t.check('ale confirm=0 z żądania jest ignorowane',
    jak('bez_podpisu_confirm0') === 'pending', jak('bez_podpisu_confirm0') || 'nikogo nie dodano');

  // ── Podpis, który nie pasuje ──────────────────────────────────────────
  t.section('podpis, który nie pasuje');

  // To jest ten warunek, dla którego wydanie powstało: podpis z formularza
  // z confirm="1" podstawiony pod confirm=0.
  t.check('cudzy podpis odrzucony', !ok('cudzy_podpis') && jak('cudzy_podpis') === '',
    msg('cudzy_podpis'));
  t.check('nikt nie trafia na listę', php.cudzy_podpis.dodani.length === 0,
    JSON.stringify(php.cudzy_podpis.dodani));

  // Podpis obejmuje treść zgody, więc nie da się dopisać komuś innej zgody
  // niż ta, którą naprawdę widział na stronie.
  t.check('podmieniona treść zgody odrzucona',
    !ok('podmieniona_zgoda') && jak('podmieniona_zgoda') === '', msg('podmieniona_zgoda'));
  t.check('podpis z innej listy odrzucony',
    !ok('podpis_innej_listy') && jak('podpis_innej_listy') === '', msg('podpis_innej_listy'));

  // ── Zabezpieczenia, które już były ────────────────────────────────────
  t.section('to, co działało, ma działać dalej');

  // Bot dostaje udawany sukces i nic się nie zapisuje — celowo, żeby nie
  // podpowiadać mu, że został rozpoznany.
  t.check('honeypot udaje sukces i nic nie zapisuje',
    ok('honeypot') && php.honeypot.dodani.length === 0, jak('honeypot') || 'nic nie dodano');
  t.check('nieprawidłowy e-mail odrzucony', !ok('zly_email'), msg('zly_email'));
  t.check('niezaznaczona zgoda odrzucona', !ok('zgoda_niezaznaczona'), msg('zgoda_niezaznaczona'));
  t.check('nieznana lista odrzucona', !ok('nieznana_lista'), msg('nieznana_lista'));
  t.check('limit 10 zapisów na godzinę działa', !ok('po_limicie'), msg('po_limicie'));

  // ── Eksport listy adresów ─────────────────────────────────────────────
  //
  // Handler kończy się `exit`, więc każdy scenariusz biegnie osobnym procesem
  // i CAŁE jego wyjście jest plikiem CSV. Sprawdzamy dane, które naprawdę
  // wychodzą z serwera, a nie to, co deklaruje funkcja pomocnicza.
  t.section('eksport listy adresów');

  const csv = (scen) => phpOutput('newsletter-eksport.php', scen || '')
    .replace(/^\uFEFF/, '')
    .trim().split('\n');

  const plik = csv('');
  const naglowek = plik[0];
  const wiersze = plik.slice(1);

  // Wypisani i oczekujący na potwierdzenie nie mają prawa wyjść: pierwsi nie
  // chcą, drudzy nie potwierdzili zgody. Wgranie ich gdzie indziej byłoby
  // wysyłką bez zgody.
  t.check('wychodzą tylko aktywni', wiersze.length === 2, wiersze.length + ' wierszy');
  t.check('wypisany nie wychodzi', !plik.join('\n').includes('wypisany@'),
    plik.join('\n').includes('wypisany@') ? 'JEST W PLIKU' : 'nieobecny');
  t.check('oczekujący nie wychodzi', !plik.join('\n').includes('oczekujacy@'),
    plik.join('\n').includes('oczekujacy@') ? 'JEST W PLIKU' : 'nieobecny');

  // Token to sekret linku wypisu i potwierdzenia — kto go ma, może wypisać
  // kogoś z listy albo potwierdzić za niego zgodę.
  t.check('token NIE wychodzi w pliku', !plik.join('\n').includes('tajny-token'),
    plik.join('\n').includes('tajny-token') ? 'WYCIEKŁ' : 'nieobecny');

  // Etykiety z konfiguracji listy, a kolumny także dla pola, którego w tej
  // konfiguracji nie ma — bo liczy się to, co naprawdę siedzi u subskrybentów.
  t.check('kolumny stałe i własne', naglowek.includes('E-mail') && naglowek.includes('Data zgody'),
    naglowek);
  t.check('etykiety pól z konfiguracji listy',
    naglowek.includes('Imię') && naglowek.includes('Firma'), naglowek);
  t.check('pole spoza konfiguracji też dostaje kolumnę', naglowek.includes('miasto'), naglowek);
  t.check('brakujące pole daje pustą komórkę', wiersze[0].endsWith(','),
    wiersze[0].slice(-30));

  // Excel wykonuje wartość zaczynającą się od „=" jako formułę, a treść
  // w tym eksporcie pochodzi od osób spoza serwisu.
  const formula = csv('formula').join('\n');
  t.check('formuła w adresie unieszkodliwiona', formula.includes("'=HYPERLINK"),
    formula.split('\n')[1].slice(0, 40));
  t.check('formuła w polu własnym unieszkodliwiona', formula.includes("'=1+1"),
    formula.split('\n')[2].slice(-20));

  // Pętla po `offset` to klasyczne miejsce na błąd o jeden — przy trzech
  // wierszach w atrapie niewidoczny, przy 1200 i partii 500 już tak.
  const partie = csv('partie').slice(1);
  const unikalne = new Set(partie.map((w) => w.split(',')[0]));
  t.check('1200 adresów wychodzi w komplecie', partie.length === 1200,
    partie.length + ' wierszy');
  t.check('bez powtórzeń między partiami', unikalne.size === 1200,
    unikalne.size + ' różnych adresów');

  // ── Czystość pliku na nowszym PHP ──────────────────────────────────────
  t.section('eksport nie wkleja komunikatów PHP do pliku');

  /* PHP 8.4 wycofuje domyślny znak ucieczki `fputcsv()` i przy każdym wywołaniu
     BEZ jawnego parametru dokłada komunikat deprecacyjny. Na serwerze
     z włączonym `display_errors` ten komunikat leci tam, gdzie leci reszta
     wyjścia — czyli w środek pobieranego pliku CSV.

     Dlatego strumień błędów jest tu sklejony z wyjściem: sam `phpOutput()`
     bierze tylko stdout, więc sprawdzenie po nim przechodziłoby na zielono
     także wtedy, gdyby komunikat wracał. */
  const { execSync } = require('child_process');
  const zEkranemBledow = execSync(
    'php -d display_errors=1 -d error_reporting=E_ALL '
    + JSON.stringify(require('path').join(__dirname, 'php', 'newsletter-eksport.php'))
    + ' 2>&1').toString();

  t.check('w wyjściu nie ma komunikatów PHP',
    !/Deprecated|Warning|Notice/i.test(zEkranemBledow),
    (zEkranemBledow.match(/(Deprecated|Warning|Notice)[^\n]*/i) || ['czysto'])[0].slice(0, 90));

  /* Statycznie po wszystkich miejscach, nie tylko po tym jednym eksporcie.
     Skrzynka formularzy ma własne `fputcsv()` i własny harness, którego tu nie
     ma — bez tego sprawdzenia jej regresja przeszłaby niezauważona. */
  const fs = require('fs');
  const pathMod = require('path');
  const zrodla = [];
  (function zbierz(kat) {
    for (const wpis of fs.readdirSync(kat, { withFileTypes: true })) {
      const pelna = pathMod.join(kat, wpis.name);
      if (wpis.isDirectory()) zbierz(pelna);
      else if (wpis.name.endsWith('.php')) zrodla.push(pelna);
    }
  })(pathMod.join(__dirname, '..', 'includes'));

  /* Argumenty czytane ZNAK PO ZNAKU, z pilnowaniem cudzysłowów.
     Wyrażenie regularne kończące listę na średniku wygląda tu naturalnie
     i jest błędne: `fputcsv($out, $line, ';')` ma średnik W ŚRODKU argumentu,
     więc takie wywołanie nie dopasowuje się wcale i wypada ze sprawdzenia.
     Złapane mutacją — skrzynka formularzy przechodziła wtedy na zielono
     z cofniętą poprawką. */
  const licznikArgumentow = (tresc, od) => {
    let gl = 0, arg = 1, cudzyslow = null;
    for (let i = od; i < tresc.length; i++) {
      const zn = tresc[i];
      if (cudzyslow) {
        if (zn === '\\') i++;
        else if (zn === cudzyslow) cudzyslow = null;
        continue;
      }
      if (zn === '"' || zn === "'") { cudzyslow = zn; continue; }
      if ('([{'.includes(zn)) { gl++; continue; }
      if (')]}'.includes(zn)) { if (gl === 0) return arg; gl--; continue; }
      if (zn === ',' && gl === 0) arg++;
    }
    return arg;
  };

  const bezEscape = [];
  for (const plikPhp of zrodla) {
    const tresc = fs.readFileSync(plikPhp, 'utf8');
    let i = -1;
    while ((i = tresc.indexOf('fputcsv(', i + 1)) !== -1) {
      if (licznikArgumentow(tresc, i + 'fputcsv('.length) < 5) {
        bezEscape.push(pathMod.relative(pathMod.join(__dirname, '..'), plikPhp));
      }
    }
  }
  t.check('każde fputcsv() podaje znak ucieczki jawnie', !bezEscape.length,
    bezEscape.length ? bezEscape.join(', ') : zrodla.length + ' plików przejrzanych');
};
