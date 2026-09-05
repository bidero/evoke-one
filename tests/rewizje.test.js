/**
 * Rewizje — przegląd bazy i sprzątanie (1.150.0).
 *
 * WordPress zapisuje rewizję przy każdym zapisie wpisu i domyślnie nie kasuje
 * żadnej; na stronie prowadzonej od paru lat to zwykle największy pojedynczy
 * śmieć w bazie. Moduł jest prawie w całości zapytaniami SQL, a najdroższa
 * pomyłka brzmi „skasować za dużo" — i tego nie da się cofnąć.
 *
 * DLATEGO SCENARIUSZE PHP CHODZĄ PO PRAWDZIWEJ BAZIE. Harness zakłada tabelę
 * o kształcie `wp_posts` w SQLite i puszcza przez nią te same łańcuchy SQL,
 * którymi jedzie produkcja. Atrapa `$wpdb` oddająca gotowy wynik sprawdzałaby
 * atrapę — akurat tutaj byłoby to sprawdzenie bez treści.
 */

const fs   = require('fs');
const path = require('path');
const { phpOutput, ROOT } = require('./lib/harness');

const scen = (nazwa) => JSON.parse(phpOutput('rewizje.php', nazwa));

module.exports = async function (t) {

  // ── Przegląd ───────────────────────────────────────────────────────────
  t.section('przegląd mówi, ile tego jest i przy czym');

  const p = scen('przeglad');
  const wg = Object.fromEntries(p.przeglad.map((w) => [w.typ, w]));

  t.check('liczy rewizje w rozbiciu na typ wpisu rodzica',
    wg.post.ile === 15 && wg.page.ile === 5,
    'wpisy ' + wg.post.ile + ', strony ' + wg.page.ile);
  t.check('i mówi, przy ilu wpisach siedzą',
    wg.post.wpisow === 2 && wg.page.wpisow === 1,
    'wpisy przy ' + wg.post.wpisow + ', strony przy ' + wg.page.wpisow);
  /* Rewizja po skasowanym wpisie to czysty śmieć i ekran ma ją pokazać osobno —
     inaczej wpadałaby do worka „wpisy" i nikt by jej nie znalazł. */
  t.check('rewizje po skasowanych wpisach mają własny wiersz',
    wg[''] && wg[''].ile === 4 && wg[''].etykieta.includes('Bez rodzica'),
    wg[''] ? wg[''].ile + ' × „' + wg[''].etykieta + '"' : 'brak wiersza');
  t.check('a suma zgadza się z tym, co w bazie',
    p.przeglad.reduce((s, w) => s + w.ile, 0) === p.razem,
    p.razem + ' rewizji');

  // ── Podsumowanie przed kasowaniem ──────────────────────────────────────
  t.section('podsumowanie liczy to samo, co potem zniknie');

  const s = scen('podsumowanie');

  /* Wpisy: jeden ma 12 rewizji (nadmiar 2), drugi 3 (nadmiar 0).
     Strona: 25, czyli nadmiar 15. Razem 17. */
  t.check('zostaw 10 → liczy sam nadmiar, wpis po wpisie',
    s.zostaw10.razem === 17 && s.zostaw10.typy.post === 2 && s.zostaw10.typy.page === 15,
    JSON.stringify(s.zostaw10));
  /* Gdyby liczyło „wszystko minus 10 na typ", z wpisów wyszłoby 5 zamiast 2 —
     to jest ta pomyłka, którą łapie wpis z trzema rewizjami. */
  t.check('wpis z trzema rewizjami nie dokłada nic',
    s.zostaw10.typy.post === 2, 'nadmiar wpisów: ' + s.zostaw10.typy.post);
  t.check('zostaw 0 → wszystko',
    s.zostaw0.razem === 40 && s.zostaw0.typy.post === 15 && s.zostaw0.typy.page === 25,
    JSON.stringify(s.zostaw0));
  t.check('zaznaczenie jednego typu liczy tylko jego',
    s.same_strony.razem === 15 && !('post' in s.same_strony.typy),
    JSON.stringify(s.same_strony));
  /* Rodzica już nie ma, więc nie ma czego zostawiać — „zostaw 10" ich nie
     dotyczy i ekran mówi to wprost plakietką przy wierszu. */
  t.check('sieroty idą w całości mimo „zostaw 10"',
    s.sieroty.razem === 4, JSON.stringify(s.sieroty));

  /* KONTROLA NEGATYWNA. Typy przychodzą z żądania i prowadzą prosto do
     kasowania — nazwa spoza bazy nie ma prawa niczego objąć. */
  t.check('typ spoza bazy nie obejmuje niczego',
    s.nieznany.razem === 0, JSON.stringify(s.nieznany));
  t.check('pusty wybór też', s.pusty.razem === 0, JSON.stringify(s.pusty));

  // ── Kasowanie ──────────────────────────────────────────────────────────
  t.section('kasowanie zabiera nadmiar i zostawia najnowsze');

  const k = scen('kasowanie');
  t.check('zostaje dokładnie tyle, ile kazano',
    k.skasowane === 17 && k.po.wpis === 10 && k.po.strona === 10,
    'skasowano ' + k.skasowane + ', zostało ' + JSON.stringify(k.po));
  /* Liczby wyżej byłyby identyczne przy kasowaniu z drugiej strony listy —
     a zniknęłaby wtedy historia, po którą się tu przychodzi. */
  t.check('i są to NAJNOWSZE, nie pierwsze z brzegu',
    k.zostaly_wpis[0] === 'wpis 3' && k.zostaly_wpis[9] === 'wpis 12'
      && k.zostaly_strona[0] === 'strona 16',
    k.zostaly_wpis[0] + '…' + k.zostaly_wpis[9] + ' | ' + k.zostaly_strona[0]);
  t.check('powtórzone nie zabiera już nic', k.drugi_raz === 0, k.drugi_raz + ' skasowanych');

  const kw = scen('kasowanie-wybor');
  t.check('niezaznaczony typ zostaje nietknięty',
    kw.skasowane === 7 && kw.wpis === 5 && kw.strona === 12 && kw.sieroty === 4,
    'wpisy ' + kw.wpis + ', strony ' + kw.strona + ', sieroty ' + kw.sieroty);
  t.check('sieroty kasowane osobno i w całości',
    kw.skasowane_sieroty === 4 && kw.sieroty_po === 0 && kw.strona_po === 12,
    'sieroty ' + kw.sieroty_po + ', strony nadal ' + kw.strona_po);
  /* NAJWAŻNIEJSZE SPRAWDZENIE W TYM PLIKU. Moduł kasuje wiersze w `wp_posts`;
     pomyłka w warunku zabrałaby treść strony, a nie jej historię. Atrapa
     `wp_delete_post_revision()` zapisuje każdą próbę na wierszu, który nie
     jest rewizją — ta lista ma zostać pusta. */
  t.check('ani jeden zwykły wpis nie został tknięty',
    kw.wpisy_zyja === 2 && kw.proby_na_nierewizjach.length === 0,
    kw.wpisy_zyja + ' wpisy żyją, prób na nie-rewizjach: '
      + JSON.stringify(kw.proby_na_nierewizjach));

  // ── Partie ─────────────────────────────────────────────────────────────
  t.section('kasowanie partiami dobiega do końca');

  const pa = scen('partie');
  t.check('każdy przebieg posuwa robotę naprzód',
    pa.przebiegi.slice(0, -1).every((n) => n > 0) && pa.przebiegi[pa.przebiegi.length - 1] === 0,
    pa.przebiegi.join(' + '));
  /* Partia to siedem, do skasowania trzydzieści — czyli musi być kilka
     okrążeń. Jedno wystarczające okrążenie znaczyłoby, że limit partii nie
     działa i przy dziesiątkach tysięcy rewizji żądanie padłoby na czasie. */
  t.check('i żaden nie przekracza wielkości partii',
    pa.przebiegi.every((n) => n <= 7) && pa.przebiegi.length > 4,
    pa.przebiegi.length + ' przebiegów, najw. ' + Math.max(...pa.przebiegi));
  t.check('suma partii to dokładnie tyle, ile zapowiedziało podsumowanie',
    pa.razem === pa.do_skasowania, pa.razem + ' z ' + pa.do_skasowania);
  t.check('a przy każdym wpisie zostaje zadana liczba',
    pa.po_wpisie.every((n) => n === 10) && pa.zostalo === 30,
    JSON.stringify(pa.po_wpisie));

  // ── Stały limit ────────────────────────────────────────────────────────
  t.section('stały limit nie rusza witryny, dopóki go nie włączysz');

  const l = scen('limit');
  t.check('filtr jest wpięty', l.filtr_wpiety, String(l.filtr_wpiety));
  /* Domyślnie WYŁĄCZONY: to zmiana zachowania całej witryny, nie ustawienie
     tej wtyczki. `-1` znaczy dla WordPressa „bez ograniczeń". */
  t.check('domyślnie oddaje wartość WordPressa bez zmian', l.domyslnie === -1,
    String(l.domyslnie));
  t.check('po włączeniu — własną liczbę', l.po_wlaczeniu === 7, String(l.po_wlaczeniu));
  t.check('po wyłączeniu wraca do wartości WordPressa', l.po_wylaczeniu === -1,
    String(l.po_wylaczeniu));

  /* Liczba ujemna w `wp_revisions_to_keep` znaczy „bez ograniczeń", więc
     przepuszczona zamieniłaby włącznik w jego przeciwieństwo. */
  t.check('liczba ujemna nie przechodzi', l.sanit_ujemna.limit === 0, String(l.sanit_ujemna.limit));
  t.check('ani tekst', l.sanit_tekst.limit === 0, String(l.sanit_tekst.limit));
  t.check('zero zostaje zerem', l.sanit_zero.limit === 0, String(l.sanit_zero.limit));
  t.check('a zwykła liczba przechodzi bez zmiany', l.sanit_zwykla.limit === 7,
    String(l.sanit_zwykla.limit));

  // ── Punkty AJAX ────────────────────────────────────────────────────────
  t.section('punkty AJAX pytają o uprawnienie przed dotknięciem bazy');

  const a = scen('ajax');
  t.check('podsumowanie odpowiada liczbą', a.podsumowanie.data.razem === 2,
    JSON.stringify(a.podsumowanie.data));
  t.check('kasowanie zabiera nadmiar i oddaje odświeżony przegląd',
    a.kasowanie.data.skasowane === 2 && a.kasowanie.data.zostalo === 0
      && Array.isArray(a.kasowanie.data.przeglad),
    JSON.stringify(a.kasowanie.data.skasowane) + ', przegląd: '
      + (a.kasowanie.data.przeglad || []).length + ' wierszy');
  t.check('niezaznaczony typ zostaje po tamtej stronie nietknięty',
    a.po_kasowaniu.strona === 12, a.po_kasowaniu.strona + ' rewizji strony');
  /* Nazwa typu spoza bazy nie ma jak niczego objąć — nie dlatego, że stoi na
     białej liście, tylko dlatego, że nie pasuje do żadnego wiersza w `IN (…)`.
     Białą listę stąd zdjąłem: mutacja pokazała, że nie pilnuje niczego,
     a kosztowała pełne grupowanie po `wp_posts` trzy razy na żądanie. */
  t.check('typ spoza bazy nic nie obejmuje', a.nieznany_typ.data.razem === 0,
    JSON.stringify(a.nieznany_typ.data));
  /* `typy[]` przychodzi z żądania, więc bywa tablicą tablic. `(string)` na
     tablicy daje ostrzeżenie PHP, a ostrzeżenie w odpowiedzi AJAX psuje JSON
     i uchwyt przestaje działać — to jest ta robota, której `IN (…)` nie zrobi. */
  t.check('zagnieżdżona tablica w żądaniu jest odsiewana bez ostrzeżeń',
    a.zagniezdzone.success === true && a.ostrzezenia.length === 0,
    a.ostrzezenia.length ? a.ostrzezenia.join(' | ') : 'brak ostrzeżeń');
  /* KONTROLA NEGATYWNA. Bez niej wszystko powyżej przechodziłoby także dla
     punktu, który kasuje każdemu, kto zna adres. */
  t.check('bez uprawnienia żądanie odbija się i nic nie znika',
    a.bez_uprawnien.success === false
      && a.po_odmowie.wpis === 10 && a.po_odmowie.strona === 12,
    JSON.stringify(a.bez_uprawnien.data) + ', stan: ' + JSON.stringify(a.po_odmowie));

  // ── Ekran ──────────────────────────────────────────────────────────────
  t.section('ekran pokazuje liczby, zanim cokolwiek skasuje');

  const ekran = phpOutput('rewizje.php', 'ekran');

  t.check('tabela ma wiersz na każdy typ z bazy',
    (ekran.match(/tr data-typ=/g) || []).length === 3,
    (ekran.match(/tr data-typ="[^"]*"/g) || []).join(', '));
  t.check('i pokazuje liczby, nie same nazwy',
    ekran.includes('>25<') && ekran.includes('>15<'), 'liczby rewizji w kolumnie');
  t.check('sieroty są oznaczone jako kasowane w całości',
    ekran.includes('kasowane w całości'), 'plakietka przy wierszu');
  /* SEDNO KOLEJNOŚCI: przycisku kasowania NIE MA w znaczniku. Dokłada go
     skrypt dopiero po policzeniu — narysowany od razu byłby przyciskiem
     „skasuj nie wiadomo ile". */
  t.check('przycisku kasowania nie ma przed policzeniem',
    !ekran.includes('evk-rewizje-kasuj') && ekran.includes('evk-rewizje-policz'),
    'jest „Policz", nie ma „Skasuj"');
  t.check('stały limit jest w formularzu ustawień, z własnym przełącznikiem',
    ekran.includes('data-option="evk_rewizje"') && ekran.includes('data-field="limit_on"')
      && ekran.includes('name="evk_rewizje[limit]"'),
    'przełącznik i pole liczby');

  const pusty = phpOutput('rewizje.php', 'ekran-pusty');
  /* KONTROLA NEGATYWNA dla całego ekranu: przy pustej bazie nie ma czego
     zaznaczać ani kasować, więc nie ma też tabeli. */
  t.check('pusta baza nie dostaje tabeli ani przycisku',
    !pusty.includes('evo-rewizje-tbl') && !pusty.includes('evk-rewizje-policz')
      && pusty.includes('ani jednej rewizji'),
    'sam komunikat');

  // ── Przebieg w przeglądarce ────────────────────────────────────────────
  t.section('dwa kroki i pętla partii');

  const otworz = async (opcje) => t.open('rewizje.html', {
    viewport: { width: 1280, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(
            phpOutput('panel-start.php', '"{}" narzedzia')) + ';'
        + 'window.__tresc = ' + JSON.stringify(ekran) + ';'
        + (opcje || ''),
  });

  const str = await otworz(
    'window.__podsumowanie = ' + JSON.stringify({ success: true, data: { razem: 17, typy: {} } }) + ';'
    + 'window.__odpowiedzi = ' + JSON.stringify([
        { success: true, data: { skasowane: 7, zostalo: 10, przeglad: [{ typ: 'post', ile: 8 }] } },
        { success: true, data: { skasowane: 7, zostalo: 3,  przeglad: [{ typ: 'post', ile: 4 }] } },
        { success: true, data: { skasowane: 3, zostalo: 0,  przeglad: [{ typ: 'post', ile: 2 }] } },
      ]) + ';');

  const przed = await str.evaluate(() => window.__stan());
  t.check('na starcie nie ma podsumowania ani przycisku',
    przed.widoczne === false && przed.maPrzycisk === false,
    'widoczne: ' + przed.widoczne + ', przycisk: ' + przed.maPrzycisk);

  await str.evaluate(() => { window.__zaznacz(['post']); window.__ustawZostaw(10); window.__policz(); });
  await str.waitForTimeout(120);
  const poLiczeniu = await str.evaluate(() => window.__stan());

  t.check('„Policz" pyta o zaznaczone typy i zadaną liczbę',
    poLiczeniu.zapytania[0].dane.action === 'evk_rewizje_podsumowanie'
      && poLiczeniu.zapytania[0].dane.zostaw === '10'
      && JSON.stringify(poLiczeniu.zapytania[0].dane.typy) === '["post"]'
      && poLiczeniu.zapytania[0].dane.nonce === 'testnonce',
    JSON.stringify(poLiczeniu.zapytania[0].dane));
  /* Liczba stoi w zdaniu I w przycisku. Bez niej przycisk wygląda tak samo
     niezależnie od tego, czy zniknie dziesięć rewizji, czy czterdzieści tysięcy. */
  t.check('a odpowiedź nazywa liczbę i w zdaniu, i na przycisku',
    /Zniknie 17 rewizji/.test(poLiczeniu.tekst) && /Skasuj 17 rewizji/.test(poLiczeniu.napis),
    poLiczeniu.napis + ' | ' + poLiczeniu.tekst.slice(0, 60));
  t.check('dopiero teraz jest co kliknąć', poLiczeniu.maPrzycisk, String(poLiczeniu.maPrzycisk));

  await str.evaluate(() => window.__kasuj());
  await str.waitForTimeout(300);
  const poKasowaniu = await str.evaluate(() => window.__stan());

  /* PĘTLA PARTII: jedno żądanie podsumowania i trzy kasujące, bo tyle
     odpowiedzi stało w kolejce. Skrypt kończy dopiero na „zostało zero". */
  const kasujace = poKasowaniu.zapytania.filter((z) => z.dane.action === 'evk_rewizje_kasuj');
  t.check('kasowanie idzie partiami aż do końca', kasujace.length === 3,
    kasujace.length + ' żądań kasujących');
  t.check('i mówi, ile w sumie zniknęło',
    /Skasowanych rewizji: 17/.test(poKasowaniu.tekst), poKasowaniu.tekst.slice(0, 70));
  /* Tabela ma się odświeżyć po drodze — bez tego pokazuje liczby sprzed
     kasowania i mówi nieprawdę aż do przeładowania strony. */
  t.check('a tabela pokazuje już nowe liczby', poKasowaniu.wTabeli.post === '2',
    JSON.stringify(poKasowaniu.wTabeli));
  t.check('bez błędów JS', str.errors.length === 0, str.errors.join(' | ') || 'brak');
  await str.close();

  // ── Warunek końca pętli ────────────────────────────────────────────────
  /* Gdyby serwer z jakiegokolwiek powodu przestał kasować, pętla oparta na
     „zostało" chodziłaby w kółko bez końca — dlatego warunkiem końca jest
     „skasowano zero", a nie „zostało zero". Odpowiedź niżej mówi jedno
     i drugie naraz. */
  const strStop = await otworz(
    'window.__podsumowanie = ' + JSON.stringify({ success: true, data: { razem: 9, typy: {} } }) + ';'
    + 'window.__odpowiedzi = ' + JSON.stringify([
        { success: true, data: { skasowane: 0, zostalo: 9 } },
      ]) + ';');
  await strStop.evaluate(() => { window.__policz(); });
  await strStop.waitForTimeout(120);
  await strStop.evaluate(() => window.__kasuj());
  await strStop.waitForTimeout(400);
  const stop = await strStop.evaluate(() => window.__stan());
  const kasujaceStop = stop.zapytania.filter((z) => z.dane.action === 'evk_rewizje_kasuj');
  t.check('serwer, który nic nie skasował, zatrzymuje pętlę',
    kasujaceStop.length === 1, kasujaceStop.length + ' żądań kasujących');
  await strStop.close();

  // ── Odmowa potwierdzenia ───────────────────────────────────────────────
  const strNie = await otworz(
    'window.__potwierdz = false;'
    + 'window.__podsumowanie = ' + JSON.stringify({ success: true, data: { razem: 9, typy: {} } }) + ';');
  await strNie.evaluate(() => { window.__policz(); });
  await strNie.waitForTimeout(120);
  await strNie.evaluate(() => window.__kasuj());
  await strNie.waitForTimeout(200);
  const nie = await strNie.evaluate(() => window.__stan());
  t.check('odmowa w okienku potwierdzenia nie wysyła nic',
    nie.zapytania.filter((z) => z.dane.action === 'evk_rewizje_kasuj').length === 0,
    nie.zapytan + ' żądań ogółem');
  await strNie.close();

  // ── Wpięcie w panel ────────────────────────────────────────────────────
  t.section('ekran jest osiągalny z panelu');

  const helpers = fs.readFileSync(path.join(ROOT, 'includes/admin/helpers.php'), 'utf8');
  const router  = fs.readFileSync(path.join(ROOT, 'includes/admin/tab-narzedzia.php'), 'utf8');

  t.check('mapa ekranów zna „rewizje"', /'rewizje'\s*=>\s*\[/.test(helpers), 'wpis w evoke_one_ekrany()');
  t.check('a router go obsługuje', /case 'rewizje':/.test(router), 'case w tab-narzedzia.php');
  /* Ekran w mapie bez obsługi w routerze rysowałby pustkę pod własnym
     adresem — i pasek boczny prowadziłby donikąd. */
  t.check('i prowadzi do właściwego pliku',
    /case 'rewizje':[\s\S]{0,120}tools-rewizje\.php/.test(router), 'tools-rewizje.php');
};
