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

const { phpOutput } = require('./lib/harness');

const scen = (nazwa) => JSON.parse(phpOutput('snippety.php', nazwa));

module.exports = async function (t) {

  // ── Rodzaj rozstrzyga, co się z treścią dzieje ─────────────────────────
  t.section('rodzaj wpisu decyduje o opakowaniu');

  const op = scen('opakowanie');

  /* O to prosiliście wprost: „jeśli wybieram rodzaj skryptu, chcę nie wpisywać
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
  t.section('kolejność wykonania jest Wasza, nie bazy');

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
  t.check('i nie cofa Waszych późniejszych zmian',
    mig.drugi_raz.rodzaj === 'php' && mig.drugi_raz.miejsce === 'footer'
      && mig.drugi_raz.grupa === 'Moja grupa',
    'rodzaj ' + mig.drugi_raz.rodzaj + ', miejsce ' + mig.drugi_raz.miejsce
      + ', grupa „' + mig.drugi_raz.grupa + '"');

  // ── Panel ──────────────────────────────────────────────────────────────
  t.section('lista pokazuje, co masz i co pracuje');

  /* Renderowana PRAWDZIWA `evk_snippets_render_tab()` przez harness zakładek —
     ten sam, którym mierzone są pozostałe ekrany panelu. */
  const lista = phpOutput('tab.php', 'tools-snippety');

  t.check('każdy wpis ma wiersz', (lista.match(/evo-stan-btn/g) || []).length === 2,
    (lista.match(/evo-stan-btn/g) || []).length + ' wierszy');
  t.check('nazwa wpisu jest widoczna', lista.includes('Sticky header'), 'Sticky header');

  /* O to prosiliście: „w środku wpisuję rodzaj snippeta, który wyświetla się
     na liście, z możliwością segregacji". */
  t.check('rodzaj widać bez wchodzenia do środka',
    lista.includes('>CSS<') && lista.includes('>PHP<'), 'CSS i PHP');
  t.check('grupa też', lista.includes('Wygląd'), 'Wygląd');

  /* Włącznik ma pokazywać PRAWDĘ, a nie zawsze to samo — pierwszy wpis jest
     włączony, drugi nie. */
  t.check('włącznik odróżnia stany',
    lista.includes('evo-stan-btn is-on') && lista.includes('evo-stan-btn is-off'),
    'wł. i wył. rozróżnione');

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
};
