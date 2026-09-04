/**
 * Snippety jako wpisy (1.139.0).
 *
 * Treść snippetu JEST WYKONYWANA — to jedyny moduł we wtyczce, w którym
 * pomyłka kosztuje całą stronę. Do 1.139.0 były to cztery stałe okna, każde
 * jeden wielki blok: nie dało się wyłączyć kawałka bez komentowania kodu,
 * rewizje dotyczyły całości, a przy błędzie krytycznym gasły WSZYSTKIE
 * snippety, bo silnik nie wiedział, który pękł.
 *
 * Sprawdzenia niżej stoją na prawdziwym silniku (`includes/snippets/*.php`),
 * nie na opisie: harness zakłada wpisy, odpala to samo `init`, które odpala
 * WordPress, i patrzy, co wyszło na haki.
 */

const fs   = require('fs');
const path = require('path');
const { phpOutput, ROOT, rgb, tokenRgb } = require('./lib/harness');

const scen = (nazwa) => JSON.parse(phpOutput('snippety.php', nazwa));

const KORZEN    = ROOT;
const rozbij    = rgb;
const rgbTokenu = tokenRgb;
const bliski    = (a, b, tol = 6) =>
  !!a && !!b && a.length === 3 && a.every((v, i) => Math.abs(v - b[i]) <= tol);

/**
 * Pliki, w których szukamy liczby mnogiej: kod wtyczki, testy i changelog.
 *
 * Przegląd idzie po katalogach, a nie po liście nazw, bo lista rozjechałaby się
 * przy pierwszym nowym pliku — dokładnie tak, jak rozjechała się lista czternastu
 * pozycji w wyszukiwarce przed 1.138.0.
 */
function plikiDoPrzegladu() {
  const out = [];
  const wejdz = (katalog) => {
    for (const wpis of fs.readdirSync(katalog, { withFileTypes: true })) {
      const p = path.join(katalog, wpis.name);
      if (wpis.isDirectory()) { if (wpis.name !== 'node_modules') wejdz(p); continue; }
      if (/\.(php|js)$/.test(wpis.name)) out.push(p);
    }
  };
  wejdz(path.join(KORZEN, 'includes'));
  for (const plik of fs.readdirSync(path.join(KORZEN, 'tests'))) {
    if (plik.endsWith('.test.js')) out.push(path.join(KORZEN, 'tests', plik));
  }
  out.push(path.join(KORZEN, 'CHANGELOG.md'));
  return out;
}

module.exports = async function (t) {

  // ── Rodzaj rozstrzyga, co się z treścią dzieje ─────────────────────────
  t.section('rodzaj wpisu decyduje o opakowaniu');

  const op = scen('opakowanie');

  /* O to prosiłeś wprost: „jeśli wybieram rodzaj skryptu, chcę nie wpisywać
     <style> <script> <?php — system niech sam to opakuje". */
  t.check('CSS dostaje znacznik <style>',
    /<style id="evk-snippet-\d+">\s*body \{ color: red \}\s*<\/style>/.test(op.head),
    op.head.slice(0, 60));
  t.check('JavaScript dostaje znacznik <script>',
    /<script id="evk-snippet-\d+">\s*console\.log\(1\)\s*<\/script>/.test(op.footer),
    op.footer.slice(0, 60));
  t.check('HTML idzie bez zmian', op.head.includes('<p>cześć</p>'), 'surowo');
  t.check('PHP wykonuje się bez pisania <?php', op.head.includes('z php'),
    op.head.includes('<?php') ? 'znacznik przeszedł do wyjścia' : 'wykonane');

  /* Identyfikator w znaczniku — żeby dało się dojść, skąd na stronie wziął się
     dany styl. Bez tego pierwszym pytaniem przy zgłoszeniu „coś nadpisuje mi
     nagłówek" jest „ale co". */
  t.check('znacznik niesie numer wpisu', op.head.includes('id="evk-snippet-' + op.id_css + '"'),
    'evk-snippet-' + op.id_css);

  /* CSS i JavaScript NIE przechodzą przez eval() — każde miejsce, którego przez
     eval() nie przepuszczamy, to jedno miejsce mniej do popsucia. Widać to po
     tym, że treść wychodzi dosłownie: `<?php` w arkuszu zostałby wypisany,
     a nie wykonany. */
  t.check('a treść CSS wychodzi dosłownie', op.head.includes('body { color: red }'),
    'bez wykonania');

  // ── Domknięcie znacznika w treści ──────────────────────────────────────
  t.section('treść nie wychodzi z własnego bloku');

  /* Ta sama klasa błędu, którą naprawialiśmy w 1.133.0 we własnym CSS-ie
     panelu: `</style><script>…` wychodziło z bloku i wykonywało się jako
     znacznik strony. Tu treść pochodzi od administratora, ale administrator
     też ma prawo wkleić coś, czego nie przewidział. */
  const dom = scen('domkniecie');
  t.check('domknięcie w treści jest rozbrojone', !/[^\\]<\/style>[\s\S]*<script>/.test(dom.head),
    dom.head.replace(/\s+/g, ' ').slice(0, 70));
  t.check('a blok nadal się zamyka', dom.head.trim().endsWith('</style>'), 'zamknięty');

  // ── Włącznik ───────────────────────────────────────────────────────────
  t.section('wyłączony wpis nie wchodzi');

  const wyl = scen('wylaczony');
  t.check('włączony wychodzi', wyl.head.includes('widoczny'), wyl.head);
  t.check('wyłączony nie', !wyl.head.includes('ukryty'),
    wyl.head.includes('ukryty') ? 'WSZEDŁ' : 'nieobecny');

  // ── Kolejność ──────────────────────────────────────────────────────────
  t.section('kolejność wykonania jest Twoja, nie bazy');

  /* Przy równych numerach rozstrzyga identyfikator, żeby dwa wpisy z zerem
     wykonywały się zawsze tak samo — inaczej strona wyglądałaby raz tak,
     raz inaczej, zależnie od tego, co zwróci baza. */
  t.check('wpisy idą po ustawionej kolejności', scen('kolejnosc').head === 'ABC',
    scen('kolejnosc').head);

  // ── Miejsca ────────────────────────────────────────────────────────────
  t.section('miejsce wpisu to hak, na którym siada');

  const m = scen('miejsca');
  t.check('frontend trafia do <head>', m.head.includes('FRONT'), m.head);
  t.check('stopka do stopki', m.footer.includes('STOPKA'), m.footer);
  t.check('a wpis panelu nie wychodzi na front', !m.head.includes('PANEL') && !m.footer.includes('PANEL'),
    m.panel === '' ? 'panel pusty poza wp-admin' : m.panel);
  t.check('„zawsze" wykonuje się od razu, bez haka', m.od_razu === 'TAK', m.od_razu || 'nie wykonano');

  // ── Tryb dawny ─────────────────────────────────────────────────────────
  t.section('cztery stare okna działają jak dotąd');

  /* Rodzaj `szablon` istnieje wyłącznie dla nich: treść to HTML ze wstawkami
     `<?php … ?>`, bo tak wykonywał ją silnik przed 1.139.0. Gdyby migracja
     nadała im nowy, wygodny rodzaj, zmieniłaby sposób wykonania — i popsuła
     działające strony. */
  t.check('HTML ze wstawką PHP liczy się jak dawniej', scen('szablon').head === 'przed 4 po',
    scen('szablon').head);

  // ── Migracja ───────────────────────────────────────────────────────────
  t.section('migracja czterech okien na wpisy');

  const mig = scen('migracja');
  const poSlugu = Object.fromEntries(mig.wpisy.map((w) => [w.slug, w]));

  t.check('wszystkie cztery są wpisami', mig.wpisy.length === 4, mig.wpisy.length + '');
  t.check('każdy dostaje tryb dawny, nie nowy',
    mig.wpisy.every((w) => w.rodzaj === 'szablon'),
    mig.wpisy.map((w) => w.rodzaj).join(', '));

  for (const [slug, miejsce] of [['evk-snippet-frontend-head', 'head'],
                                 ['evk-snippet-footer', 'footer'],
                                 ['evk-snippet-admin-head', 'admin_head'],
                                 ['evk-snippet-functions-php', 'init']]) {
    t.check('„' + slug.replace('evk-snippet-', '') + '" ląduje na swoim miejscu',
      poSlugu[slug] && poSlugu[slug].miejsce === miejsce,
      poSlugu[slug] ? poSlugu[slug].miejsce : 'brak wpisu');
  }

  t.check('treść przechodzi nietknięta',
    poSlugu['evk-snippet-footer'].kod === '<b>evk-snippet-footer</b>',
    poSlugu['evk-snippet-footer'].kod);
  t.check('i wpisy dostają czytelne tytuły',
    poSlugu['evk-snippet-functions-php'].tytul === 'PHP (functions.php)',
    poSlugu['evk-snippet-functions-php'].tytul);

  /* Cztery okna istniały zawsze, także puste. Lista czterech pustych wpisów
     „włączonych" to hałas, a nie stan. */
  t.check('pusty blok wchodzi wyłączony',
    poSlugu['evk-snippet-admin-head'].wlaczony === 0,
    'wlaczony=' + poSlugu['evk-snippet-admin-head'].wlaczony);
  t.check('a niepusty włączony',
    poSlugu['evk-snippet-footer'].wlaczony === 1,
    'wlaczony=' + poSlugu['evk-snippet-footer'].wlaczony);

  /* Jedyny nieodwracalny krok w tej przebudowie zasługuje na siatkę. */
  t.check('kopia treści sprzed migracji zostaje zapisana',
    mig.kopia && mig.kopia['evk-snippet-footer'] === '<b>evk-snippet-footer</b>',
    mig.kopia ? Object.keys(mig.kopia).length + ' bloków' : 'brak kopii');

  /* Powtórzona migracja nie ma prawa niczego ruszyć — a „niczego" znaczy przede
     wszystkim: nie ma prawa cofnąć zmian wprowadzonych PO niej. Liczenie wpisów
     tego nie łapie, bo migracja żadnego nie zakłada; łapie to dopiero
     przestawiony rodzaj, miejsce i grupa. Sprawdzone mutacją — bez bezpiecznika
     jednorazowości sam licznik świecił na zielono. */
  t.check('powtórzona migracja nie zakłada nowych wpisów', mig.drugi_raz.bez_nowych === true,
    mig.drugi_raz.bez_nowych ? 'bez nowych' : 'POWSTAŁY NOWE');
  t.check('i nie cofa Twoich późniejszych zmian',
    mig.drugi_raz.rodzaj === 'php' && mig.drugi_raz.miejsce === 'footer'
      && mig.drugi_raz.grupa === 'Moja grupa',
    'rodzaj ' + mig.drugi_raz.rodzaj + ', miejsce ' + mig.drugi_raz.miejsce
      + ', grupa „' + mig.drugi_raz.grupa + '"');

  // ── Panel ──────────────────────────────────────────────────────────────
  // ── Izolacja błędu krytycznego (1.148.0) ───────────────────────────────
  t.section('wywraca się jeden wpis, nie cały moduł');

  /* DO 1.147.1 KAŻDY BŁĄD KRYTYCZNY GASIŁ WSZYSTKO. Silnik nie wiedział, czyj
     kod pękł, więc jedyną odpowiedzią było przestawienie głównego włącznika —
     dwadzieścia działających wpisów milkło przez jeden zły. Teraz wykonanie
     zostawia znacznik i po wywrotce gaśnie dokładnie ten wpis, który ją
     wywołał. */
  const aw = scen('awaria-wpis');

  t.check('winny wpis gaśnie',
    aw.stan.wpisy['Wywrotka'].wlaczony === 0,
    'wlaczony = ' + aw.stan.wpisy['Wywrotka'].wlaczony);
  t.check('a sąsiedzi pracują dalej',
    aw.head === 'AC' && aw.stan.wpisy['Pierwszy'].wlaczony === 1
                     && aw.stan.wpisy['Trzeci'].wlaczony === 1,
    'wyjście „' + aw.head + '"');
  /* SEDNO CAŁEJ ZMIANY. Bez tego sprawdzenia wszystko powyżej przeszłoby też
     dla silnika, który po cichu gasi główny włącznik. */
  t.check('główny włącznik zostaje włączony', aw.stan.glowny === 1,
    'evk_snippets_enabled = ' + aw.stan.glowny);
  t.check('przy wpisie zostaje ślad: co i w której linii',
    !!aw.stan.wpisy['Wywrotka'].awaria
      && aw.stan.wpisy['Wywrotka'].awaria.message === 'bum'
      && aw.stan.wpisy['Wywrotka'].awaria.line > 0,
    JSON.stringify(aw.stan.wpisy['Wywrotka'].awaria));
  t.check('powiadomienie wie, KTÓRY wpis padł',
    aw.stan.transjent && aw.stan.transjent.zakres === 'wpis'
      && aw.stan.transjent.tytul === 'Wywrotka' && aw.stan.transjent.id > 0,
    JSON.stringify(aw.stan.transjent));
  /* Wyłączenie ma znaczyć „już nie wchodzi", a nie tylko „ma metadaną".
     Licznik w kodzie wpisu odróżnia jedno od drugiego — samo wyjście nie,
     bo wpis, który rzuca, i tak niczego nie wypisuje. */
  t.check('w drugim przebiegu nie jest już wykonywany',
    aw.wejsc === 1 && aw.head_drugi === 'AC',
    aw.wejsc + ' wejść, wyjście „' + aw.head_drugi + '"');

  const adv = scen('awaria-advanced');
  t.check('tryb zaawansowany gaśnie sam, bez wpisów',
    adv.stan.advanced === 0 && adv.stan.glowny === 1
      && adv.stan.wpisy['Pierwszy'].wlaczony === 1 && adv.head === 'A',
    'advanced ' + adv.stan.advanced + ', główny ' + adv.stan.glowny
      + ', wyjście „' + adv.head + '"');

  /* NIEZNANY SPRAWCA. Kod zarejestrowany przez snippet (hak, domknięcie) leci
     długo po tym, jak znacznik zgasł — wtedy nie da się wskazać wpisu. Wraca
     dawne zachowanie: główny włącznik na off. Zostawienie wszystkiego
     włączonego zamknęłoby witrynę w pętli błędu 500. */
  const nn = scen('awaria-nieznana');
  t.check('bez znacznika gaśnie całe wykonywanie',
    nn.stan.glowny === 0, 'evk_snippets_enabled = ' + nn.stan.glowny);
  t.check('i powiadomienie mówi wprost, że sprawcy nie znamy',
    nn.stan.transjent && nn.stan.transjent.zakres === 'nieznany',
    JSON.stringify(nn.stan.transjent));
  t.check('pojedynczych wpisów przy tym nie rusza',
    nn.stan.wpisy['Pierwszy'].wlaczony === 1 && !nn.stan.wpisy['Pierwszy'].awaria,
    'Pierwszy: ' + JSON.stringify(nn.stan.wpisy['Pierwszy']));

  /* KONTROLA NEGATYWNA. Bez niej sprawdzenia wyżej przechodziłyby także dla
     silnika, który uznaje za swój KAŻDY błąd krytyczny w witrynie — i gasi
     nasze działające snippety za cudzą wywrotkę. */
  const cu = scen('awaria-cudza');
  t.check('cudzy eval() nie gasi naszych snippetów',
    cu.stan.glowny === 1 && cu.stan.transjent === false,
    'główny ' + cu.stan.glowny + ', transjent ' + JSON.stringify(cu.stan.transjent));
  t.check('ani błąd w cudzym pliku',
    cu.stan_po_drugim.glowny === 1 && cu.stan_po_drugim.transjent === false,
    'główny ' + cu.stan_po_drugim.glowny);

  const cz = scen('awaria-czyszczenie');
  t.check('ślad po wywrotce zostaje aż do naprawy', !!cz.po_awarii.awaria,
    JSON.stringify(cz.po_awarii));
  t.check('a zapis wpisu go zdejmuje',
    cz.po_zapisie.awaria === null && cz.po_zapisie.wlaczony === 1,
    JSON.stringify(cz.po_zapisie));

  /* PRAWDZIWY BŁĄD NIEPRZECHWYTYWALNY — i to jest ta klasa, dla której cały
     znacznik powstał. Redeklaracji funkcji `try/catch` nie widzi: PHP nie
     rzuca wyjątku, tylko kończy żądanie kodem 255. Zostaje funkcja zamykająca,
     która leci w tym samym procesie — więc znacznik nadal mówi, czyj kod
     pracował. */
  const tw = JSON.parse(phpOutput('snippety.php', 'fatal-twardy', { dopuscBlad: true }));
  t.check('po prawdziwym fatalu gaśnie wpis, który go wywołał',
    tw.stan.wpisy['Redeklaracja'].wlaczony === 0
      && !!tw.stan.wpisy['Redeklaracja'].awaria,
    JSON.stringify(tw.stan.wpisy['Redeklaracja']));
  t.check('sąsiad zostaje nietknięty',
    tw.stan.wpisy['Pierwszy'].wlaczony === 1 && !tw.stan.wpisy['Pierwszy'].awaria,
    JSON.stringify(tw.stan.wpisy['Pierwszy']));
  t.check('a wykonywanie jako całość zostaje włączone',
    tw.stan.glowny === 1, 'evk_snippets_enabled = ' + tw.stan.glowny);
  t.check('log zna nazwę wpisu, nie „unknown"',
    tw.stan.transjent && tw.stan.transjent.slug === 'Redeklaracja',
    tw.stan.transjent ? tw.stan.transjent.slug : '—');

  t.section('lista pokazuje, co masz i co pracuje');

  /* Renderowana PRAWDZIWA `evk_snippets_render_tab()` przez harness zakładek —
     ten sam, którym mierzone są pozostałe ekrany panelu. */
  const lista = phpOutput('tab.php', 'tools-snippety');

  /* `[^"]*` łapałoby też `class="evo-switch-knob"` — gałkę, nie przełącznik.
     Stąd dopuszczona wyłącznie spacja i kolejne klasy. */
  const przelaczniki = lista.match(/class="evo-switch(?: [^"]*)?"/g) || [];
  t.check('każdy wpis ma wiersz', przelaczniki.length === 2, przelaczniki.length + ' wierszy');
  t.check('nazwa wpisu jest widoczna', lista.includes('Sticky header'), 'Sticky header');

  /* O to prosiłeś: „w środku wpisuję rodzaj snippeta, który wyświetla się
     na liście, z możliwością segregacji". */
  t.check('rodzaj widać bez wchodzenia do środka',
    lista.includes('>CSS<') && lista.includes('>PHP<'), 'CSS i PHP');
  t.check('grupa też', lista.includes('Wygląd'), 'Wygląd');

  /* Włącznik ma pokazywać PRAWDĘ, a nie zawsze to samo — pierwszy wpis jest
     włączony, drugi nie. */
  t.check('włącznik odróżnia stany',
    przelaczniki.filter((k) => k.includes('is-on')).length === 1
      && przelaczniki.filter((k) => !k.includes('is-on')).length === 1,
    przelaczniki.join(' | '));
  t.check('i mówi to czytnikom ekranu',
    lista.includes('aria-checked="true"') && lista.includes('aria-checked="false"'),
    'aria-checked w obu stanach');

  t.section('po wywrotce panel mówi, KTÓRY wpis padł');

  /* Zasiew zakłada ten stan PRAWDZIWĄ drogą — zapala znacznik i woła
     `evk_snippet_odetnij()`, czyli to samo, co robi silnik po błędzie. Gdyby
     metadana i transjent były tu wpisane z ręki, sprawdzenie pilnowałoby
     własnej kopii, a nie kodu. */
  const listaAwaria = phpOutput('tab.php', 'tools-snippety ' + JSON.stringify(JSON.stringify(
    { evk_stan: 'awaria' })));

  t.check('wiersz wpisu nosi znacznik wywrotki',
    listaAwaria.includes('evo-badge-alarm') && listaAwaria.includes('wyłączony po błędzie'),
    'plakietka przy wierszu');
  t.check('a nad listą stoi powiadomienie z nazwą wpisu',
    /wyłączył się po błędzie krytycznym/.test(listaAwaria)
      && listaAwaria.includes('Wyłączony test'),
    (listaAwaria.match(/<strong>[^<]*wyłączył się[^<]*<\/strong>/) || ['—'])[0]);
  t.check('powiadomienie nie twierdzi, że stanęło całe wykonywanie',
    !/wykonywanie snippetów wyłączone/i.test(listaAwaria),
    'brak zdania o całym module');
  /* WYCINAMY SAM BANER, a nie szukamy po całej stronie. Zmierzone mutacją:
     sprawdzenie puszczone na cały ekran przechodziło także dla baneru bez
     komunikatu — bo te same słowa niesie podpowiedź plakietki przy wierszu. */
  const baner = (listaAwaria.match(
    /<div class="notice notice-error inline[^"]*">([\s\S]*?)<\/div>/) || [])[1] || '';
  t.check('i niesie powód: co pękło i w której linii',
    baner.includes('Cannot redeclare function evk_moja_funkcja') && /linia 12/.test(baner),
    baner ? baner.replace(/\s+/g, ' ').trim().slice(0, 90) : 'brak baneru');

  /* KONTROLA NEGATYWNA. Bez niej wszystkie cztery sprawdzenia wyżej
     przechodziłyby też dla ekranu, który maluje plakietkę i powiadomienie
     zawsze — a wtedy nie mówią one niczego o stanie wpisu. */
  t.check('bez wywrotki nie ma ani plakietki, ani powiadomienia',
    !lista.includes('evo-badge-alarm') && !/wyłączył się po błędzie/.test(lista)
      && !lista.includes('notice-error'),
    'czysty ekran');

  /* Plakietka musi mieć REGUŁĘ, nie tylko klasę. `.evo-badge` stała
     w znaczniku w trzech miejscach i nie była opisana w arkuszu ani razu. */
  const arkuszPanelu = fs.readFileSync(path.join(KORZEN, 'assets/admin/admin.css'), 'utf8');
  t.check('a klasy plakietek są opisane w arkuszu',
    /\.evo-badge\s*\{/.test(arkuszPanelu) && /\.evo-badge-alarm\s*\{/.test(arkuszPanelu),
    'reguły .evo-badge i .evo-badge-alarm');

  // ── Przycisk „Odrzuć" przy powiadomieniu (1.149.1) ─────────────────────
  t.section('powiadomienie o błędzie daje się odrzucić');

  /* ZGŁOSZONE Z UŻYCIA: „nie działa przycisk odrzuć po błędzie".
     Zmierzone w przeglądarce: skrypt powiadomienia wywracał się na starcie
     z „$j is not defined" — `$j` to konwencja z cudzych motywów
     (`var $j = jQuery.noConflict()`), której we wtyczce nie było nigdy.
     Goła, niezadeklarowana nazwa w JavaScripcie nie oddaje `undefined`, tylko
     RZUCA, więc zapis `($j || jQuery)` nie miał jak sięgnąć po jQuery: obsługa
     kliknięcia nie wpinała się wcale. */
  const powiad = scen('powiadomienie');

  t.check('uchwyt po stronie serwera naprawdę kasuje transjent',
    powiad.transjent_przed === true && powiad.transjent_po === false,
    'przed ' + powiad.transjent_przed + ', po ' + powiad.transjent_po);

  const otworzPowiadomienie = async (odpowiedz) => t.open('snippety-powiadomienie.html', {
    head: 'window.__notice = ' + JSON.stringify(powiad.html) + ';'
        + (odpowiedz ? 'window.__odpowiedz = ' + JSON.stringify(odpowiedz) + ';' : ''),
  });

  const strP = await otworzPowiadomienie(null);
  /* To sprawdzenie zapaliłoby się na kodzie sprzed poprawki — i o to chodzi:
     usterka była JEDNYM błędem w konsoli, którego nikt nie miał powodu otwierać. */
  t.check('skrypt powiadomienia nie wywraca się na starcie',
    strP.errors.length === 0, strP.errors.join(' | ') || 'brak błędów');

  const klik = await strP.evaluate(() => window.__odrzuc());
  t.check('kliknięcie wysyła żądanie do panelu',
    klik.zapytan === 1 && klik.zapytanie.dane.action === 'evk_dismiss_snippet_fatal'
      && klik.zapytanie.dane.nonce === powiad.nonce
      && klik.zapytanie.url.includes('admin-ajax.php'),
    klik.zapytan === 0 ? 'zero żądań — obsługa się nie wpięła'
                       : JSON.stringify(klik.zapytanie.dane));
  await strP.waitForTimeout(120);
  const poKliku = await strP.evaluate(() => window.__stan());
  t.check('i powiadomienie znika', poKliku.jest === false, String(poKliku.jest));
  await strP.close();

  /* KONTROLA NEGATYWNA. Bez niej „powiadomienie znika" przechodziłoby też dla
     skryptu kasującego je bez patrzenia na odpowiedź — a wtedy odmowa serwera
     (przeterminowany nonce) wyglądałaby na załatwioną i wracałaby przy
     następnym przeładowaniu, bez śladu, co jest zepsute. */
  const strO = await otworzPowiadomienie({ success: false });
  await strO.evaluate(() => window.__odrzuc());
  await strO.waitForTimeout(120);
  const poOdmowie = await strO.evaluate(() => window.__stan());
  t.check('przy odmowie serwera powiadomienie zostaje',
    poOdmowie.jest === true && poOdmowie.zablokowany === false,
    'jest: ' + poOdmowie.jest + ', przycisk zablokowany: ' + poOdmowie.zablokowany);
  await strO.close();

  // ── Historia zmian (1.149.0) ───────────────────────────────────────────
  t.section('historia zmian wpisu');

  const roz = scen('roznica');

  /* PODGLĄD MA POKAZAĆ ZMIANĘ, NIE CAŁY PLIK. Poprawka jednej linii w sześćdziesięciu
     daje sześćdziesiąt jeden wierszy różnicy — na ekran idzie zmiana z otoczeniem
     i wiersz mówiący, ile linii zwinięto. */
  const zmiany = roz.jedna_linia.filter((w) => w.typ !== 'rowny');
  t.check('różnica znajduje dokładnie jedną poprawioną linię',
    zmiany.length === 2 && zmiany[0].typ === 'usuniete' && zmiany[1].typ === 'dodane'
      && zmiany[1].tekst === 'linia 30 POPRAWIONA',
    zmiany.map((w) => w.typ + ' „' + w.tekst + '"').join(', '));
  t.check('i numeruje ją po obu stronach',
    zmiany[0].stara === 30 && zmiany[0].nowa === null
      && zmiany[1].nowa === 30 && zmiany[1].stara === null,
    JSON.stringify(zmiany.map((w) => [w.stara, w.nowa])));

  const liniiHtml = (roz.jedna_linia_html.match(/evo-diff-linia/g) || []).length;
  const przerw    = (roz.jedna_linia_html.match(/evo-diff-przerwa/g) || []).length;
  t.check('na ekran idzie zmiana z otoczeniem, a nie sześćdziesiąt linii',
    liniiHtml > 0 && liniiHtml <= 10 && roz.jedna_linia.length === 61,
    liniiHtml + ' z ' + roz.jedna_linia.length + ' wierszy');
  t.check('a zwinięte miejsca mówią, ile linii pominięto',
    przerw === 2 && /\d+ linii bez zmian/.test(roz.jedna_linia_html),
    (roz.jedna_linia_html.match(/… [^<]*…/g) || []).join(' | '));

  t.check('dopisana linia jest „dodane", nie „zmienione"',
    roz.dodane.length === 3 && roz.dodane[1].typ === 'dodane' && roz.dodane[1].tekst === 'NOWA',
    roz.dodane.map((w) => w.typ).join(' '));
  t.check('a skasowana — „usunięte"',
    roz.usuniete.length === 3 && roz.usuniete[1].typ === 'usuniete',
    roz.usuniete.map((w) => w.typ).join(' '));

  /* PRZEPLOT. Bez porównania szukającego wspólnego podciągu wyszłoby
     „wszystko usunięte, wszystko dodane" — i podgląd nie pokazywałby niczego
     poza tym, że plik jest inny. */
  t.check('wspólne linie w środku zostają rozpoznane',
    roz.przeplot.filter((w) => w.typ === 'rowny').map((w) => w.tekst).join(',') === 'a,c',
    roz.przeplot.map((w) => w.typ[0] + w.tekst).join(' '));

  t.check('identyczna treść nie udaje różnicy',
    roz.bez_zmian.every((w) => w.typ === 'rowny')
      && /niczym się nie różni/.test(roz.bez_zmian_html),
    roz.bez_zmian.length + ' wierszy, same równe');

  /* PRÓG DOKŁADNEGO PORÓWNANIA. Powyżej niego blok idzie wymieniony w całości —
     mniej dokładnie, ale bez tablicy o milionie komórek w żądaniu panelu.
     Co dziesiąta linia jest tu wspólna: dokładne porównanie znalazłoby ich
     trzydzieści kilka, blok nie znajduje żadnej poza odciętym ogonem. */
  t.check('wielka zmiana nie liczy się dokładnie, tylko blokiem',
    roz.wielka.rownych === 1 && roz.wielka.usuniete === 349 && roz.wielka.dodane === 349,
    JSON.stringify(roz.wielka));
  t.check('i mieści się w kilkudziesięciu milisekundach', roz.wielka.ms < 300,
    roz.wielka.ms + ' ms');

  /* ODCIĘCIE WSPÓLNYCH KOŃCÓW rozstrzyga tu o WYNIKU, nie o czasie: plik ma
     osiemset linii, czyli więcej niż próg dokładnego porównania. Bez odcięcia
     całość poszłaby blokiem i podgląd pokazywałby osiemset zmienionych linii
     zamiast jednej poprawionej. Zmierzone mutacją — bez tego przypadku
     zdjęcie odcięcia przechodziło na zielono. */
  t.check('poprawka jednej linii w długim pliku zostaje jedną linią',
    roz.duzy_plik.zmian === 2 && roz.duzy_plik.rownych === 799
      && roz.duzy_plik.tresci.join(' → ') === 'linia 400 → linia 400 POPRAWIONA',
    roz.duzy_plik.zmian + ' zmian, ' + roz.duzy_plik.rownych + ' równych');
  t.check('i liczy się w kilku milisekundach', roz.duzy_plik.ms < 300,
    roz.duzy_plik.ms + ' ms');

  const wl = scen('wersje-lista');
  t.check('ekran dostaje dwadzieścia ostatnich wersji',
    wl.ile === 20 && wl.w_bazie === 25, wl.ile + ' z ' + wl.w_bazie + ' w bazie');
  t.check('od najnowszej', wl.najnowsza > wl.najstarsza,
    wl.najnowsza + ' … ' + wl.najstarsza);
  t.check('a historia cudzego wpisu tu nie wchodzi', wl.po_dolozeniu_cudzej === 20,
    wl.po_dolozeniu_cudzej + ' wersji po dołożeniu rewizji strony');

  const wc = scen('wersje-czyszczenie');
  t.check('czyszczenie zostawia zadaną liczbę wersji',
    wc.skasowane === 15 && wc.zostalo === 10,
    'skasowanych ' + wc.skasowane + ', zostało ' + wc.zostalo);
  /* Liczba wyszłaby ta sama przy kasowaniu z drugiej strony listy — a zniknęłaby
     wtedy historia, po którą się tu przychodzi. */
  t.check('i zostawia NAJNOWSZE, nie pierwsze z brzegu',
    wc.najnowsza_zostala && wc.najstarsza_znikla,
    'najnowsza została: ' + wc.najnowsza_zostala + ', najstarsza znikła: ' + wc.najstarsza_znikla);
  t.check('powtórzone nie kasuje już nic', wc.drugi_raz === 0, wc.drugi_raz + ' skasowanych');
  t.check('a liczba ujemna znaczy zero, nie „od końca"',
    wc.ujemne === 10 && wc.po_ujemnym === 0,
    'skasowano ' + wc.ujemne + ', zostało ' + wc.po_ujemnym);

  const wa = scen('wersje-ajax');
  t.check('uchwyt oddaje treść wersji i różnicę',
    wa.wersja.success === true && wa.wersja.data.content.includes('STARE')
      && wa.wersja.data.diff.includes('evo-diff-usuniete'),
    wa.wersja.success ? 'treść + różnica' : JSON.stringify(wa.wersja));
  /* KONTROLA NEGATYWNA. Identyfikator przychodzi z żądania, więc bez sprawdzenia
     typu rodzica punkt oddawałby treść dowolnej rewizji w witrynie. */
  t.check('ale nie odda historii cudzego wpisu',
    wa.cudza.success === false && !JSON.stringify(wa.cudza).includes('sekret'),
    JSON.stringify(wa.cudza.data));
  t.check('ani nieistniejącej wersji', wa.nieistniejaca.success === false,
    JSON.stringify(wa.nieistniejaca.data));

  const wf = scen('wersje-formularz');
  t.check('czyszczenie z formularza działa i wraca do edytora',
    wf.zostalo === 5 && wf.adres.includes('evk_widok=edytor') && wf.adres.includes('evk_ile=10'),
    wf.zostalo + ' wersji, adres: ' + wf.adres.split('&').slice(-3).join('&'));
  t.check('bez nonce’a nie kasuje nic', wf.bez_nonce_bez_zmian, String(wf.bez_nonce_bez_zmian));
  /* Ta sama klasa dziury, którą zamyka sprawdzenie typu przy usuwaniu wpisu:
     identyfikator z formularza nie ma prawa skasować historii cudzej strony. */
  t.check('i nie rusza historii wpisu spoza snippetów', wf.obca_historia_zyje === 4,
    wf.obca_historia_zyje + ' rewizji strony');

  // ── Historia w przeglądarce ────────────────────────────────────────────
  t.section('przywracanie trafia do edytora, nie do pola pod nim');

  const ekranEdytora = phpOutput('tab.php', 'tools-snippety ' + JSON.stringify(JSON.stringify(
    { evk_widok: 'edytor', evk_wpis: 'pierwszy' })));

  /* Odpowiedź serwera jest PRAWDZIWA — wprost z uchwytu AJAX wyżej. */
  const odpowiedz = { success: true, data: wa.wersja.data };

  const strEd = await t.open('snippety-edytor.html', {
    viewport: { width: 1280, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(ekranEdytora) + ';'
        + 'window.__odpowiedz = ' + JSON.stringify(odpowiedz) + ';',
  });

  const hist = await strEd.evaluate(() => window.__historia());
  t.check('edytor pokazuje historię wpisu', hist.wierszy === 3, hist.wierszy + ' wersji');
  t.check('a panel podglądu startuje schowany', hist.schowany === true, String(hist.schowany));

  const pod = await strEd.evaluate(() => window.__podglad(1));
  t.check('podgląd wczytuje różnicę tej wersji',
    pod.widoczny && pod.html.includes('evo-diff-usuniete'),
    pod.html.slice(0, 60));
  t.check('i pyta o wersję z tego wiersza, z nonce’em',
    pod.zapytanie && String(pod.zapytanie.dane.revision_id) === '1003'
      && pod.zapytanie.dane.nonce === 'testnonce'
      && pod.zapytanie.dane.action === 'evk_get_snippet_revision',
    pod.zapytanie ? JSON.stringify(pod.zapytanie.dane) : 'brak żądania');
  t.check('otwarty wiersz jest oznaczony', pod.podswietlony, String(pod.podswietlony));

  /* SEDNO. CodeMirror trzyma własną kopię treści i przepisuje pole dopiero przy
     wysyłce — przywracanie ustawiające `value` pola nie zmieniłoby niczego, co
     widać na ekranie, a przy zapisie zostałaby stara treść z edytora. */
  const wrot = await strEd.evaluate(() => window.__przywroc(2));
  t.check('przywracanie wstawia treść do INSTANCJI edytora',
    wrot.doEdytora && wrot.doEdytora.includes('STARE'),
    wrot.doEdytora === null ? 'nic nie trafiło do CodeMirrora' : '„' + wrot.doEdytora + '"');
  t.check('i mówi wprost, że na stronie nic się jeszcze nie zmieniło',
    /Zapisz snippet/.test(wrot.komunikat) && /po klikni/.test(wrot.komunikat),
    wrot.komunikat.slice(0, 70));

  t.check('bez błędów JS w edytorze z historią', strEd.errors.length === 0,
    strEd.errors.join(' | ') || 'brak');
  await strEd.close();

  t.section('edytor pyta o wszystko, czego wpis potrzebuje');

  const edytor = phpOutput('tab.php', 'tools-snippety ' + JSON.stringify(JSON.stringify(
    { evk_widok: 'edytor', evk_wpis: 'nowy' })));
  for (const [pole, nazwa] of [['evk-tytul', 'nazwę'], ['evk-rodzaj', 'rodzaj'],
                               ['evk-miejsce', 'miejsce'], ['evk-grupa', 'grupę'],
                               ['evk-kolejnosc', 'kolejność'], ['evk-kod', 'kod']]) {
    t.check('edytor ma pole na ' + nazwa, edytor.includes('id="' + pole + '"'), pole);
  }
  t.check('i wszystkie pięć rodzajów do wyboru',
    ['PHP', 'CSS', 'JavaScript', 'HTML', 'HTML + PHP'].every((r) => edytor.includes(r)),
    'pięć pozycji');
  /* Nowy wpis nie ma historii i nie ma jej skąd wziąć — pudełko z pustą tabelą
     i przyciskiem „Wyczyść starsze" byłoby obietnicą bez pokrycia. */
  t.check('a nowy wpis nie dostaje pudełka historii',
    !edytor.includes('evo-wersje'), 'brak sekcji historii');

  // ── Żądanie panelu ─────────────────────────────────────────────────────
  t.section('wpisy panelu i frontu nie mieszają się');

  /* Z frontu obie strony warunku po `is_admin()` wyglądają tak samo, więc
     sprawdzenie robione tylko stamtąd przechodzi niezależnie od tego, czy
     warunek istnieje. Ten scenariusz idzie z drugiej strony. */
  const pnl = scen('panel');
  t.check('wpis panelu wychodzi w panelu', pnl.panel.includes('PANEL'), pnl.panel || 'pusto');
  t.check('a wpis frontu nie rejestruje się w panelu',
    pnl.hakow_front === 0 && !pnl.head.includes('FRONT'),
    pnl.hakow_front + ' haków frontu, wyjście: „' + pnl.head + '"');

  // ── Akcje z listy (1.139.1) ────────────────────────────────────────────
  t.section('akcje działają też pod adresem bez ?sub=');

  /* ZGŁOSZONE Z UŻYCIA: „nie działa wyłączanie snippetów".
     Pasek boczny prowadzi do Narzędzi adresem `?page=evoke-one&tab=narzedzia`,
     a `tab-narzedzia.php` domyśla sobie `sub=snippets`. Ekran się rysował,
     formularze wracały na ten sam adres — i brama w `ajax.php` odrzucała je,
     bo wymagała `sub=snippets` w `$_GET`. Nic się nie działo: ani włącznik,
     ani usuwanie, ani zapis. Scenariusz PHP posyła POST dokładnie stamtąd. */
  const akc = scen('akcje');

  t.check('włącznik wyłącza wpis', akc.przed === 1 && akc.po_wylaczeniu === 0,
    akc.przed + ' → ' + akc.po_wylaczeniu);
  t.check('i włącza go z powrotem', akc.po_wlaczeniu === 1, String(akc.po_wlaczeniu));
  t.check('po akcji jest przekierowanie, nie przeładowanie formularza',
    akc.przekierowanie === true, String(akc.przekierowanie));
  t.check('zapis nowego wpisu też przechodzi', akc.zapisany === true, String(akc.zapisany));
  t.check('i usunięcie snippetu', akc.snippet_usuniety === true, String(akc.snippet_usuniety));

  /* Po zdjęciu warunków na adres nonce ZOSTAJE JEDYNYM wejściem, więc musi
     mieć własne pokrycie — inaczej poprawka układu otworzyłaby drzwi. */
  t.check('bez nonce\'a nic się nie dzieje', akc.bez_nonce_bez_zmian === true,
    String(akc.bez_nonce_bez_zmian));
  t.check('podrobiony nonce zatrzymuje żądanie',
    akc.zly_nonce_zablokowany === true && akc.zly_nonce_bez_zmian === true,
    'zablokowany: ' + akc.zly_nonce_zablokowany + ', bez zmian: ' + akc.zly_nonce_bez_zmian);
  t.check('usuwanie patrzy na typ wpisu, nie na samą liczbę',
    akc.obcy_wpis_zyje === true, String(akc.obcy_wpis_zyje));

  // ── Teksty, o których nie ma potrzeby pisać ────────────────────────────
  t.section('ekran nie tłumaczy się z własnej historii');

  /* ZGŁOSZONE Z UŻYCIA, dosłownie: „Nie ma potrzeby pisać »Rodzaj decyduje,
     czym wpis jest i w co system go owija«… »Tryb czterech okien sprzed
     1.139.0«… »Tylko do Waszego porządku«". Sprawdzamy WYJŚCIE, nie źródło. */
  const nowy = phpOutput('tab.php', 'tools-snippety ' + JSON.stringify(JSON.stringify(
    { evk_widok: 'edytor', evk_wpis: 'nowy' })));
  const zbedne = [
    ['Rodzaj decyduje', lista],
    ['Tryb czterech okien', nowy],
    ['porządku — sortowanie', nowy],
  ].filter(([fraza, gdzie]) => gdzie.includes(fraza));
  t.check('trzech zdań nie ma na ekranie', !zbedne.length,
    zbedne.map(([f]) => f).join(' | ') || 'czysto');

  /* Liczba mnoga zniknęła z całej wtyczki, nie tylko z tych trzech zdań.

     Dwa wyjątki, oba wymuszone przez sam wzorzec:
      · TEN plik trzyma wzorzec, więc zawsze sam w siebie trafia;
      · „whitelista" odmieniona przez miejscownik daje „po whiteliście" —
        końcówka jak w „prosiliście", a to rzeczownik. */
  const mnoga = [];
  const dozwolone = /^whiteliście$/i;
  for (const plik of plikiDoPrzegladu()) {
    if (plik === __filename) continue;
    const tresc = fs.readFileSync(plik, 'utf8');
    const traf = (tresc.match(/\b(Was|Wam|Wami|[Ww]asz\w*|\w+liście)\b/g) || [])
      .filter((s) => !dozwolone.test(s));
    if (traf.length) mnoga.push(path.relative(KORZEN, plik) + ': ' + traf.join(', '));
  }
  t.check('i nigdzie nie zwracamy się w liczbie mnogiej', !mnoga.length,
    mnoga.slice(0, 3).join(' | ') || 'czysto');

  // ── Układ listy w przeglądarce ─────────────────────────────────────────
  t.section('lista mieści się w karcie panelu');

  /* ZGŁOSZONE Z UŻYCIA: „trzeba zmienić ułożenie, bo wszystko się rozjechało".
     To jest pytanie o piksele, więc mierzymy w przeglądarce, w PRAWDZIWEJ
     powłoce panelu — z paskiem bocznym po lewej, jak na ekranie.

     GRANICA TEGO SPRAWDZENIA: fixture wciąga wyłącznie `admin.css` wtyczki,
     bo arkuszy wp-admin nie ma w repozytorium. Klasa `fixed` z `wp-list-table`
     tutaj więc nie działa i sama w sobie nie zostałaby złapana — dlatego
     `table-layout: auto` stoi wprost w naszej regule, a nie polega na tym,
     że nikt tej klasy nie dopisze. Mierzone jest rozłożenie szerokości. */
  const strona = await t.open('snippety-lista.html', {
    viewport: { width: 1280, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(lista) + ';',
  });

  const tab = await strona.evaluate(() => window.__tabela());
  t.check('tabela nie wychodzi poza treść karty',
    tab && tab.prawaTabeli <= tab.prawaTresci + 1,
    tab ? 'tabela do ' + tab.prawaTabeli + ' px, karta do ' + tab.prawaTresci + ' px' : 'brak tabeli');
  t.check('i nie rozpycha strony w poziomie', tab && !tab.stronaPrzewija,
    tab ? String(tab.stronaPrzewija) : '—');

  const nazwa = tab && tab.naglowki.find((n) => n.tekst === 'Nazwa');
  t.check('kolumna „Nazwa" ma czym oddychać', nazwa && nazwa.szer >= 200,
    nazwa ? nazwa.szer + ' px' : 'brak kolumny');
  t.check('i jest najszersza ze wszystkich',
    nazwa && tab.naglowki.every((n) => n === nazwa || n.szer <= nazwa.szer),
    tab ? tab.naglowki.map((n) => n.tekst + ' ' + n.szer).join(', ') : '—');

  // ── Przełącznik ────────────────────────────────────────────────────────
  t.section('stan wpisu to przełącznik, nie plakietka');

  /* ZGŁOSZONE Z UŻYCIA: „przełącznik snippetów myślałem o czymś takim…
     albo takim zielonym jak mamy przy opcjach (tylko może mniejszy)".
     38 × 22 to wymiary z tego zgłoszenia. */
  const prz = await strona.evaluate(() => window.__przelaczniki());
  t.check('są dwa i oba są przyciskami', prz.length === 2
    && prz.every((p) => p.znacznik === 'BUTTON'), JSON.stringify(prz.map((p) => p.znacznik)));
  t.check('mają rozmiar 38 × 22', prz.every((p) => p.szer === 38 && p.wys === 22),
    prz.map((p) => p.szer + '×' + p.wys).join(', '));

  const zielen = rgbTokenu('evo-on');
  const zapalony = prz.find((p) => p.wlaczony), zgaszony = prz.find((p) => !p.wlaczony);
  t.check('włączony jest zielony jak przy modułach',
    zapalony && bliski(rozbij(zapalony.tlo), zielen),
    (zapalony ? zapalony.tlo : '—') + ' wobec ' + zielen.join(','));
  t.check('a wyłączony nie', zgaszony && !bliski(rozbij(zgaszony.tlo), zielen),
    zgaszony ? zgaszony.tlo : '—');
  t.check('gałka przesuwa się tylko przy włączonym',
    zapalony && zgaszony && zapalony.przesuniecie === 16 && zgaszony.przesuniecie === 0,
    (zapalony ? zapalony.przesuniecie : '?') + ' vs ' + (zgaszony ? zgaszony.przesuniecie : '?'));

  t.check('bez błędów JS na liście', !strona.errors.length,
    strona.errors.join(' | ') || 'brak');
  await strona.close();

  // ── Wersja mobilna ─────────────────────────────────────────────────────
  t.section('na telefonie wiersz jest kartą, nie słupkiem wartości');

  /* ZGŁOSZONE Z UŻYCIA (zrzut z 390 px): „coś się rozjeżdża w wersji
     mobilnej". Tabela miała klasę `wp-list-table`, a rdzeń WordPressa
     poniżej 782 px rozkłada takie komórki na bloki i podpisuje je atrybutem
     `data-colname` — którego nasze komórki nie miały. Zostawał pionowy ciąg
     samych wartości bez podpisów, a nagłówki tabeli sterczały nad nim
     osobnym słupkiem. */
  const waska = await t.open('snippety-lista.html', {
    viewport: { width: 390, height: 850 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(lista) + ';',
  });

  /* Klasy rdzenia ściągają na tabelę cudze reguły — i to one zrobiły oba
     zgłoszone bałagany: `wp-list-table` rozłożyła komórki bez podpisów,
     `widefat` narysowała ramkę wokół kart, której własna reguła nie zdjęła,
     bo przegrywała specyficznością. Arkuszy wp-admin w repozytorium nie ma,
     więc pomiar w przeglądarce nie zauważyłby ich powrotu; sprawdzamy sam
     znacznik. Tabela ma stać na naszych regułach, a nie wygrywać z cudzymi
     na punkty. */
  const klasyRdzenia = ['wp-list-table', 'widefat', 'striped']
    .filter((k) => new RegExp('<table[^>]*\\b' + k + '\\b').test(lista));
  t.check('tabela nie nosi klas rdzenia', !klasyRdzenia.length,
    klasyRdzenia.join(', ') || (lista.match(/<table[^>]*>/) || ['brak tabeli'])[0]);

  const mob = await waska.evaluate(() => window.__mobil());
  t.check('nagłówek tabeli schowany', mob && !mob.naglowekWidoczny,
    mob ? String(mob.naglowekWidoczny) : 'brak tabeli');
  t.check('wiersz ma ramkę karty', mob && mob.wierszBlokiem && mob.ramkaWiersza >= 1,
    mob ? mob.ramkaWiersza + ' px ramki' : '—');

  /* Bez podpisów „head" i „0" nie znaczą nic — to była istota zgłoszenia. */
  t.check('wartości mają podpisy',
    mob && ['Rodzaj', 'Miejsce', 'Grupa', 'Kolejność']
      .every((e) => mob.podpisy.some((p) => p.startsWith(e))),
    mob ? mob.podpisy.join(' | ') || 'BRAK PODPISÓW' : '—');

  t.check('przełącznik siedzi w rogu karty', mob && mob.przelacznikWRogu,
    mob ? String(mob.przelacznikWRogu) : '—');

  /* ZGŁOSZONE Z UŻYCIA: „nadal przełączniki są złe na mobilnej".
     Winna była NASZA reguła celów dotykowych (`admin.css`, blok 782 px):
     `min-height: var(--evo-tap)` na każdym przycisku panelu rozciągało
     przełącznik do 44 px wysokości — zielony prostokąt z gałką przyklejoną
     do góry, dokładnie jak na zrzucie. Kształt ma zostać, palec ma dostać
     swoje 44 px z niewidzialnej nakładki. */
  t.check('przełącznik nie rozciąga się na wąskim ekranie',
    mob && mob.przelacznik && mob.przelacznik.szer === 38 && mob.przelacznik.wys === 22,
    mob && mob.przelacznik ? mob.przelacznik.szer + '×' + mob.przelacznik.wys : '—');
  t.check('ale pole dotyku ma pełne 44 px', mob && mob.poleDotyku >= 44,
    mob ? mob.poleDotyku + ' px' : '—');

  /* ZGŁOSZONE Z UŻYCIA dwa razy: „jest jakaś ramka ekstra", a potem „usuń tę
     ramkę prostokątną". Obramowanie tabeli obrysowywało wszystkie karty naraz.
     Za pierwszym razem zdjąłem je regułą o jednej klasie i nie zadziałało —
     rdzeń rysował ramkę selektorem o wyższej specyficzności, a tego testy nie
     widzą, bo arkuszy wp-admin nie ma w repozytorium. Dlatego tabela nie nosi
     już klas rdzenia (sprawdzenie wyżej): ramka jest nasza, więc jej brak na
     wąskim ekranie da się zmierzyć.

     Mierzymy TU, a nie na szerokim ekranie: przy `border-collapse` przeglądarka
     podaje dla tabeli ramkę scaloną z komórek, więc na desktopie wychodzi 1 px
     niezależnie od tego, czy tabela ma własne obramowanie. Na wąskim ekranie
     tabela jest blokiem i scalanie nie działa — liczba mówi prawdę. */
  t.check('tabela nie dokłada ramki wokół kart', mob && mob.ramkaTabeli === 0,
    mob ? mob.ramkaTabeli + ' px' : '—');
  t.check('nic nie wystaje poza kartę', mob && mob.poza === 0,
    mob ? mob.poza + ' komórek poza' : '—');
  t.check('i strona nie przewija się w bok', mob && !mob.stronaPrzewija,
    mob ? String(mob.stronaPrzewija) : '—');

  /* ZGŁOSZONE Z UŻYCIA: „tytuł snippeta powinien być klikalny (prowadzi do
     edycji) — wtedy nie trzeba przycisku edytuj". */
  t.check('tytuł prowadzi do edytora',
    /<a[^>]+evk_widok=edytor[^>]+class="evo-snippet-nazwa"|class="evo-snippet-nazwa"[^>]*>/.test(lista)
      && lista.includes('evo-snippet-nazwa'),
    (lista.match(/<a[^>]*evo-snippet-nazwa[^>]*>/) || ['brak odnośnika'])[0].slice(0, 80));
  t.check('i nie ma już osobnego przycisku „Edytuj"', !lista.includes('>Edytuj<'),
    lista.includes('>Edytuj<') ? 'przycisk został' : 'usunięty');

  /* „Można w ogóle zastąpić usuń przyciskiem usuń z animatora z koszem — dla
     powtarzalności". Ten sam komponent, nie własna kopia wyglądu. */
  /* Animatora nie renderuje harness zakładek (potrzebuje własnej klasy
     silnika), więc porównujemy ZNACZNIK w źródle — a „ten sam komponent" to
     właśnie fakt o znaczniku, nie o pikselach. */
  const animator = fs.readFileSync(path.join(KORZEN, 'includes/admin/tab-animator.php'), 'utf8');
  /* Okno na tyle szerokie, żeby zmieścić `onclick` z Animatora między klasą
     a ikoną — chodzi o „ten sam przycisk", nie o identyczny odstęp znaków. */
  const kosztuKlasa = /class="evo-btn-remove"[\s\S]{0,200}dashicons-trash/;
  t.check('kosz to ten sam komponent co w Animatorze',
    kosztuKlasa.test(lista) && kosztuKlasa.test(animator),
    'evo-btn-remove + dashicons-trash: lista ' + kosztuKlasa.test(lista)
      + ', Animator ' + kosztuKlasa.test(animator));

  /* ZGŁOSZONE Z UŻYCIA: „przycisk Nowy snippet w wersji mobilnej — tekst nie
     jest wyśrodkowany w pionie". `min-height` rozciągało pudełko, ale napis
     zostawał przy górnej krawędzi. */
  const nowyBtn = await waska.evaluate(() => window.__przycisk('+ Nowy snippet'));
  t.check('napis w „+ Nowy snippet" stoi w pionowym środku',
    nowyBtn && nowyBtn.odchylenie <= 1,
    nowyBtn ? nowyBtn.odchylenie + ' px od środka, przycisk ' + nowyBtn.wys + ' px' : 'brak przycisku');

  /* ZGŁOSZONE Z UŻYCIA: „mobilne edytuj i usuń są różnej wielkości". „Edytuj"
     był `<a>`, „Usuń" `<button>` — przy tym samym `min-height` różniły się
     domyślną wysokością wiersza. Przycisku edycji już nie ma (tytuł prowadzi
     do edytora), ale reguła musi trzymać oba znaczniki w jednej wysokości. */
  const kosz = await waska.evaluate(() => window.__przycisk('Usuń'));
  t.check('kosz i „+ Nowy snippet" mają tę samą wysokość',
    kosz && nowyBtn && kosz.wys === nowyBtn.wys,
    (kosz ? kosz.wys : '?') + ' px vs ' + (nowyBtn ? nowyBtn.wys : '?') + ' px');

  /* Cała karta prowadzi do edycji — poza przełącznikiem i koszem. */
  const cel = await waska.evaluate(() => window.__celKlikniecia());
  t.check('kliknięcie w puste miejsce karty otwiera edycję',
    cel && cel.pusteMiejsce === 'edycja', cel ? cel.pusteMiejsce : '—');
  t.check('ale przełącznik zostaje przełącznikiem',
    cel && cel.naPrzelaczniku === 'przełącznik', cel ? cel.naPrzelaczniku : '—');
  t.check('a kosz koszem', cel && cel.naKoszu === 'kosz', cel ? cel.naKoszu : '—');

  /* Nagłówek tabeli jest schowany, więc sortowanie po nazwie, rodzaju i grupie
     musi mieć własne wejście — inaczej znikło razem z nim. */
  const sort = await waska.evaluate(() => {
    const f = document.querySelector('.evo-sort-mobile');
    if (!f) return null;
    return {
      widoczny: getComputedStyle(f).display !== 'none',
      klucze: Array.from(f.querySelectorAll('option')).map((o) => o.value),
    };
  });
  t.check('sortowanie ma własne pole na telefonie', sort && sort.widoczny,
    sort ? String(sort.widoczny) : 'brak pola');
  t.check('i zna te same klucze co nagłówki kolumn',
    sort && ['tytul', 'rodzaj', 'grupa'].every((k) => sort.klucze.includes(k)),
    sort ? sort.klucze.join(', ') : '—');

  t.check('bez błędów JS na wąskim ekranie', !waska.errors.length,
    waska.errors.join(' | ') || 'brak');
  await waska.close();

  // ── Edytor: powrót do listy ────────────────────────────────────────────
  t.section('powrót do listy jest częścią paska widoków');

  /* ZGŁOSZONE Z UŻYCIA: „przycisk wróć do listy jest innej wielkości".
     `.evo-viewtabs` jest `inline-flex`, więc odnośnik stoi z pigułkami
     w jednej linii — i sterczał ponad nie o sześć pikseli. */
  const ed = await t.open('snippety-lista.html', {
    viewport: { width: 1280, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(nowy) + ';',
  });

  const pow = await ed.evaluate(() => window.__powrot());
  t.check('powrót ma wysokość pigułki', pow && pow.powrot === pow.pigulka,
    pow ? pow.powrot + ' px vs ' + pow.pigulka + ' px' : 'brak odnośnika');
  t.check('i stoi z nią w jednej linii', pow && pow.wJednejLinii,
    pow ? String(pow.wJednejLinii) : '—');
  t.check('bez błędów JS w edytorze', !ed.errors.length, ed.errors.join(' | ') || 'brak');
  await ed.close();

  // ── Karty wyboru w rzędzie ─────────────────────────────────────────────
  t.section('pola obok siebie mają tę samą wysokość');

  /* ZGŁOSZONE Z UŻYCIA: „wyrównaj pola obok siebie do tych samych wysokości".
     Karty kończyły się tam, gdzie kończył się ich opis — jedna na dwie linie,
     druga na jedną. Mierzymy na Lenisie, bo tam w jednym rzędzie stoi pięć
     kart o opisach różnej długości; poprawka siedzi w komponencie, więc
     działa wszędzie, gdzie taki rząd występuje. */
  const lenis = await t.open('snippety-lista.html', {
    viewport: { width: 1280, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(phpOutput('tab.php', 'fe-lenis')) + ';',
  });

  const karty = await lenis.evaluate(() => window.__karty());
  const wLinii = karty ? karty.reduce((n, l) => Math.max(n, l.length), 0) : 0;
  t.check('rząd ma karty stojące obok siebie', wLinii > 1,
    karty ? karty.map((l) => l.length + ' w linii').join(', ') : 'brak rzędu');
  t.check('i w każdej linii są równej wysokości',
    karty && karty.every((linia) => new Set(linia).size === 1),
    karty ? karty.map((l) => l.join('/')).join('  |  ') : '—');
  await lenis.close();

  // ── Pola w siatce edytora ──────────────────────────────────────────────
  t.section('pola edytora wypełniają swoje sloty');

  /* ZGŁOSZONE Z UŻYCIA, ze zrzutem edytora: „pola muszą być równe". Trzy
     globalne reguły z `admin.css` (`max-width: 420px` na tekście, `90px` na
     liczbie, `min-width: 200px` na liście) dobrane są pod formularze, w których
     pola stoją jedno pod drugim. W siatce dawały rząd złożony z przypadku. */
  const edPola = await t.open('snippety-lista.html', {
    viewport: { width: 1280, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(nowy) + ';',
  });

  const pola = await edPola.evaluate(() => window.__pola());
  t.check('siatka ma wszystkie pięć pól', pola && pola.length === 5,
    pola ? pola.map((p) => p.nazwa).join(', ') : 'brak siatki');
  t.check('każde wypełnia swój slot',
    pola && pola.every((p) => Math.abs(p.szer - p.slot) <= 1),
    pola ? pola.map((p) => p.nazwa + ' ' + p.szer + '/' + p.slot).join(', ') : '—');
  t.check('więc wszystkie mają tę samą szerokość',
    pola && new Set(pola.map((p) => p.szer)).size === 1,
    pola ? pola.map((p) => p.szer).join(', ') : '—');
  await edPola.close();

  // ── Logi błędów ────────────────────────────────────────────────────────
  t.section('logi mieszczą się w karcie');

  /* ZGŁOSZONE Z UŻYCIA: „wyświetlanie logów do poprawy w skryptach. Wyjeżdżają
     poza. Może górna linia info, a poniżej log?". Dokładnie tak: metryczka
     w linii u góry, komunikat pod spodem, fragment kodu we własnym pudełku.
     Zasiew niesie DŁUGI komunikat i długą linię kodu — bez tego sprawdzenie
     przechodziłoby też dla układu, który wyjeżdża. */
  const widokLogow = phpOutput('tab.php', 'tools-snippety ' + JSON.stringify(JSON.stringify(
    { evk_widok: 'logi' })));

  /* Karta logu ma pole daty i do 1.148.0 było ono PUSTE: znacznik czytał
     `time`, a zapis kładzie `timestamp`. Zasiew woła teraz prawdziwy
     `evk_snippet_log_error()`, więc rozjazd nazw zapala się tutaj. */
  const data = widokLogow.match(/<time>([^<]*)<\/time>/);
  t.check('karta logu pokazuje datę zdarzenia',
    !!data && /\d{4}-\d{2}-\d{2}/.test(data[1]), data ? '„' + data[1] + '"' : 'brak <time>');

  for (const [szer, nazwa] of [[1280, 'szerokim'], [390, 'wąskim']]) {
    const strLog = await t.open('snippety-lista.html', {
      viewport: { width: szer, height: 900 },
      head: 'window.__panel = ' + JSON.stringify(
              phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
          + 'window.__tresc = ' + JSON.stringify(widokLogow) + ';',
    });
    const log = await strLog.evaluate(() => window.__logi());
    t.check('na ' + nazwa + ' ekranie karta mieści się w panelu',
      log && log.kartaWKarcie,
      log ? 'karta do ' + log.prawaKarty + ' px, panel do ' + log.prawaTresci + ' px' : 'brak logu');
    t.check('i nic z niej nie wystaje', log && log.pozaKarta.length === 0,
      log ? log.pozaKarta.join(', ') || 'nic' : '—');
    t.check('a strona nie przewija się w bok', log && !log.stronaPrzewija,
      log ? String(log.stronaPrzewija) : '—');
    if (szer === 1280) {
      /* Sedno: długa linia kodu ma przewijać się WEWNĄTRZ pudełka. Gdyby
         zawijała się albo rozpychała kartę, ten pomiar byłby fałszywy. */
      t.check('a długi kod przewija się w swoim pudełku', log && log.kodPrzewija,
        log ? String(log.kodPrzewija) : '—');
    }
    await strLog.close();
  }

  // ── Przycisk usuwania na trzech ekranach ───────────────────────────────
  t.section('kosz wygląda tak samo wszędzie');

  /* Znacznik był identyczny w Animatorze, Kursorze i snippetach — ta sama
     klasa, ta sama ikona — a Kursor definiował `.evo-btn-remove` po swojemu
     w bloku `<style>` na stronie: goły czerwony napis zamiast ghosta z ramką.
     Jedna nazwa, trzy wyglądy. */
  const zrodloKursora = fs.readFileSync(
    path.join(KORZEN, 'includes/admin/tab-cursor.php'), 'utf8');
  t.check('Kursor nie definiuje już własnego kosza',
    !/\.evo-btn-remove\s*\{/.test(zrodloKursora),
    (zrodloKursora.match(/\.evo-btn-remove\s*\{[^}]*\}/) || ['brak własnej reguły'])[0].slice(0, 60));

  const koszSnippety = await t.open('snippety-lista.html', {
    viewport: { width: 1280, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(lista) + ';',
  });
  const wSnippetach = await koszSnippety.evaluate(() => window.__kosz());
  await koszSnippety.close();

  const koszKursor = await t.open('snippety-lista.html', {
    viewport: { width: 1280, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(phpOutput('tab.php', 'fe-cursor')) + ';',
  });
  const wKursorze = await koszKursor.evaluate(() => window.__kosz());
  await koszKursor.close();

  t.check('oba ekrany mają ten przycisk', !!wSnippetach && !!wKursorze,
    'snippety ' + !!wSnippetach + ', kursor ' + !!wKursorze);
  t.check('i wygląda identycznie',
    wSnippetach && wKursorze
      && wSnippetach.wys === wKursorze.wys
      && wSnippetach.ramka === wKursorze.ramka
      && wSnippetach.tlo === wKursorze.tlo
      && wSnippetach.promien === wKursorze.promien,
    wSnippetach && wKursorze
      ? JSON.stringify(wSnippetach) + ' vs ' + JSON.stringify(wKursorze) : '—');
};
