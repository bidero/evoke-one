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

  /* Klasa `wp-list-table` ściąga na tabelę responsywne reguły rdzenia — to
     one zrobiły ten bałagan. Nasz układ i tak by je dziś przykrył, więc
     pomiar w przeglądarce nie zauważyłby jej powrotu; arkuszy wp-admin nie
     ma zresztą w repozytorium. Sprawdzamy więc sam znacznik: tabela ma stać
     na naszych regułach, a nie wygrywać z cudzymi na punkty. */
  t.check('tabela nie nosi klasy wp-list-table', !/<table[^>]*wp-list-table/.test(lista),
    (lista.match(/<table[^>]*>/) || ['brak tabeli'])[0]);

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

  /* ZGŁOSZONE Z UŻYCIA: „jest jakaś ramka ekstra". Obramowanie tabeli
     obrysowywało wszystkie karty naraz — ramka wokół ramek. */
  t.check('tabela nie dokłada ramki wokół kart', mob && mob.ramkaTabeli === 0,
    mob ? mob.ramkaTabeli + ' px' : '—');
  t.check('nic nie wystaje poza kartę', mob && mob.poza === 0,
    mob ? mob.poza + ' komórek poza' : '—');
  t.check('i strona nie przewija się w bok', mob && !mob.stronaPrzewija,
    mob ? String(mob.stronaPrzewija) : '—');

  t.check('bez błędów JS na wąskim ekranie', !waska.errors.length,
    waska.errors.join(' | ') || 'brak');
  await waska.close();
};
