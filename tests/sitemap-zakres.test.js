/**
 * Mapa strony — zakres: typy treści, taksonomie, użytkownicy, kotwice.
 *
 * DLACZEGO TEN PLIK POWSTAŁ. Do 1.220.0 mapy strony nie sprawdzało nic poza
 * renderowaniem zakładki. Przez to dwie usterki przeżyły kilkadziesiąt wydań,
 * a obie widać wyłącznie z żywej strony:
 *
 * 1. `include_users` zapisywało się od 1.95.0 i NIE BYŁO CZYTANE PRZEZ NIC —
 *    sekcja użytkowników wisiała w mapie niezależnie od checkboksa.
 * 2. Własny generator `/sitemap.xml` nigdy nie odpowiadał, bo reguły rewrite
 *    nie były przebudowywane po włączeniu modułu; żądanie przejmował rdzeń
 *    WordPressa i przekierowywał na `wp-sitemap.xml`.
 *
 * Wspólny mianownik: OBIE wyglądały w panelu poprawnie. Sprawdzenie panelu
 * nie mówi nic o tym, co wtyczka wystawia światu — dlatego tutaj wołane są
 * prawdziwe filtry modułu, z takimi argumentami, jakie podaje im WordPress.
 *
 * Sonda odtwarza wariant „Evoke ONE + Evoke FIELDS": definiuje
 * `evk_noindex_post_types()` przed załadowaniem modułu, bo moduł pyta o nią
 * przez `function_exists()` — FIELDS to osobna wtyczka i na części stron jej
 * nie ma.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('sitemap-zakres.php'));

  // ── Szerokość list ────────────────────────────────────────────────────
  /* JEDYNE sprawdzenie w tym pliku, które potrzebuje przeglądarki — i ma
     powód. Pierwsza wersja ekranu nazwała wiersze `.evk-sm-row`, a ta klasa
     jest zajęta przez wiersze kolejności menu bocznego w white-label i niesie
     `max-width: 600px`. Skutek: listy mapy kończyły się w dwóch trzecich
     akordeonu, mimo że ani jedna reguła ekranu tego nie robiła. Żaden
     sprawdzian PHP-owy tego nie widzi, bo markup był poprawny.

     Zmierzone przy 1400 px: wnętrze akordeonu 1308 px, siatka 1308 px,
     wiersze 1306 px (dwa piksele to własna ramka siatki). Próg stoi wokół
     tych liczb, a nie wokół wyobrażenia o nich. */
  t.section('listy zajmują całą szerokość akordeonu');

  const head = 'window.__tab = ' + JSON.stringify(phpOutput('tab.php', 'sitemap')) + ';';
  const p = await t.open('admin-tabs.html', { viewport: { width: 1400, height: 900 }, head, settle: 120 });

  const szer = await p.evaluate(() => {
    const szerokosc = (el) => (el ? Math.round(el.getBoundingClientRect().width) : 0);
    const body = document.querySelector('.evo-acc[open] .evo-acc-body');
    const grid = document.querySelector('.evk-map-grid');
    const styl = body ? getComputedStyle(body) : null;
    return {
      wnetrze: styl ? Math.round(szerokosc(body) - parseFloat(styl.paddingLeft) - parseFloat(styl.paddingRight)) : 0,
      siatka:  szerokosc(grid),
      wiersze: [...document.querySelectorAll('.evk-map-row')].map(szerokosc),
    };
  });
  await p.close();

  t.check('siatka wypełnia akordeon', Math.abs(szer.siatka - szer.wnetrze) <= 2,
    szer.siatka + ' px przy wnętrzu ' + szer.wnetrze + ' px');
  const waskie = szer.wiersze.filter((w) => w < szer.siatka - 4);
  t.check('żaden wiersz nie jest węższy od siatki', !waskie.length,
    waskie.length ? waskie.join(', ') + ' px' : szer.wiersze.length + ' wierszy po ' + szer.wiersze[0] + ' px');
  // Kontrola sensowności: bez wierszy dwa sprawdzenia wyżej przechodzą na pusto.
  t.check('było co mierzyć', szer.wiersze.length >= 4, szer.wiersze.length + ' wierszy');

  // ── Typy treści ───────────────────────────────────────────────────────
  t.section('typy treści wchodzą do mapy wg ustawień, nie na sztywno');

  // Do 1.220.0 filtr znał wyłącznie `page` i `post`; CPT z FIELDS/ACF/Metabox
  // jechał do mapy bez pytania i nie dawało się go stamtąd zabrać.
  t.check('odznaczony typ znika z mapy', !php.typy_w_mapie.includes('realizacja'),
    php.typy_w_mapie.join(', '));
  t.check('typ oznaczony w Evoke FIELDS znika z mapy', !php.typy_w_mapie.includes('slajd'),
    php.typy_w_mapie.join(', '));
  // Kontrola sensowności: filtr ma USUWAĆ wskazane, a nie czyścić listę.
  t.check('reszta typów zostaje',
    php.typy_w_mapie.includes('page') && php.typy_w_mapie.includes('post') && php.typy_w_mapie.includes('menu'),
    php.typy_w_mapie.join(', '));

  t.section('taksonomie — oba źródła flagi');

  t.check('taksonomia odznaczona w panelu znika', !php.taksonomie_w_mapie.includes('rodzaj'),
    php.taksonomie_w_mapie.join(', '));
  t.check('taksonomia oznaczona w FIELDS znika', !php.taksonomie_w_mapie.includes('kolor-slajdu'),
    php.taksonomie_w_mapie.join(', '));
  t.check('wbudowane zostają',
    php.taksonomie_w_mapie.includes('category') && php.taksonomie_w_mapie.includes('post_tag'),
    php.taksonomie_w_mapie.join(', '));

  // ── Użytkownicy ───────────────────────────────────────────────────────
  t.section('sekcja użytkowników słucha checkboksa');

  t.check('wyłączona — provider odcięty', php.users_wylaczeni === 'odcięty', php.users_wylaczeni);
  t.check('włączona — provider przechodzi', php.users_wlaczeni === 'przeszedł', php.users_wlaczeni);
  // Filtr dostaje KAŻDY provider; gdyby nie sprawdzał nazwy, wyłączony
  // checkbox użytkowników skasowałby całą mapę.
  t.check('inne sekcje nietknięte', php.users_inny_provider === 'przeszedł', php.users_inny_provider);

  // ── Wykluczenia pojedynczych wpisów ───────────────────────────────────
  t.section('wykluczone wpisy działają w każdym typie treści');

  t.check('CPT dostaje post__not_in',
    JSON.stringify(php.wykluczenia_cpt.post__not_in) === JSON.stringify([42, 77]),
    JSON.stringify(php.wykluczenia_cpt));
  // Wykluczenia wtyczki dokładają się do cudzych, zamiast je nadpisywać.
  t.check('istniejące wykluczenia zostają',
    JSON.stringify(php.wykluczenia_strony.post__not_in) === JSON.stringify([5, 42, 77]),
    JSON.stringify(php.wykluczenia_strony));

  // ── „Poza indeksem" ───────────────────────────────────────────────────
  t.section('poza indeksem: wyszukiwarka WP i meta robots');

  t.check('oznaczony typ dostaje exclude_from_search',
    php.rejestracja_menu.exclude_from_search === true,
    JSON.stringify(php.rejestracja_menu));
  t.check('zwykły typ zostaje bez zmian',
    php.rejestracja_strony.exclude_from_search === undefined,
    JSON.stringify(php.rejestracja_strony));

  // Wyrzucenie z mapy nie wyprowadza z indeksu tego, co już w nim jest —
  // mapa jest podpowiedzią, nie zakazem. Stąd meta tag.
  t.check('wpis oznaczonego typu ma noindex',
    php.robots_menu.includes('noindex') && php.robots_menu.includes('follow'),
    JSON.stringify(php.robots_menu));
  t.check('flaga z Evoke FIELDS działa tak samo',
    php.robots_slajd.includes('noindex'), JSON.stringify(php.robots_slajd));
  t.check('zwykła strona bez noindex', php.robots_strona.length === 0,
    JSON.stringify(php.robots_strona));

  // ── Sekcje z kotwicami ────────────────────────────────────────────────
  t.section('sekcje kotwic jako osobne pozycje w indeksie mapy');

  /* Strona jednoekranowa jest w mapie JEDNYM adresem, choć niesie kilkanaście
     osobnych treści. Sekcja kotwic zgłasza je z osobna i robi to WŁASNYM
     plikiem w indeksie (`wp-sitemap-menu-1.xml`) — dlatego sprawdzamy nie samą
     listę adresów, tylko to, co moduł rejestruje w `wp_sitemaps_init`. */
  const nazwy = Object.keys(php.sekcje);
  t.check('każda sekcja to osobny provider pod swoją nazwą',
    nazwy.includes('menu') && nazwy.includes('cennik'), nazwy.join(', '));

  const menu = php.sekcje.menu || { adresy: [] };
  t.check('adresy to strona bazowa z kolejnymi kotwicami',
    menu.adresy.length === 2 &&
    menu.adresy[0].loc === 'https://example.test/oferta/#desery' &&
    menu.adresy[1].loc === 'https://example.test/oferta/#napoje',
    menu.adresy.map((a) => a.loc).join(' | '));
  t.check('lastmod ze strony bazowej, w formacie W3C',
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/.test(menu.adresy[0].lastmod),
    String(menu.adresy[0].lastmod));

  // Własny adres bywa jedynym wyjściem, gdy jednoekranowa treść nie jest
  // stroną WordPressa (szablon Bricks pod własnym adresem).
  const cennik = php.sekcje.cennik || { adresy: [] };
  t.check('własny adres wygrywa ze stroną i dostaje domenę',
    cennik.adresy.length === 1 && cennik.adresy[0].loc === 'https://example.test/uslugi/#pakiety',
    JSON.stringify(cennik.adresy));
  /* `lastmod` musi iść z tego samego źródła co adres. Przy ręcznym adresie
     data wybranej wcześniej strony opisywałaby inny dokument — pole jest
     opcjonalne, więc lepiej go nie ma, niż ma kłamać. */
  t.check('przy własnym adresie nie ma zmyślonego lastmod',
    cennik.adresy[0].lastmod === undefined, JSON.stringify(cennik.adresy[0]));

  /* Nazwy `posts`, `taxonomies`, `users` i `translations` są zajęte przez
     WordPressa i przez sekcję tłumaczeń. `WP_Sitemaps_Registry::add_sitemap()`
     odmawia drugiego providera o tej samej nazwie, więc sekcja powstałaby
     tylko z nazwy — bez ani jednego adresu w mapie. */
  t.check('nazwa zajęta przez rdzeń nie rejestruje sekcji', !nazwy.includes('posts'),
    nazwy.join(', '));
  // Provider bez adresów dokłada do indeksu pozycję, która odpowiada zerem.
  t.check('sekcja bez kotwic nie rejestruje się', !nazwy.includes('puste'),
    nazwy.join(', '));
  /* Ten sam warunek, drugi powód: kotwice są, ale strona bazowa została
     skasowana po skonfigurowaniu sekcji. Adresu nie ma z czego zbudować. */
  t.check('sekcja ze skasowaną stroną bazową też nie', !nazwy.includes('widmo'),
    nazwy.join(', '));
  t.check('bez konfiguracji nie ma żadnej sekcji', php.sekcje_bez_konfiguracji.length === 0,
    JSON.stringify(php.sekcje_bez_konfiguracji));

  // ── Sanityzacja zapisu ────────────────────────────────────────────────
  t.section('zapis z jednego ekranu nie kasuje ustawień drugiego');

  // Ten sam wpis w opcjach zapisują DWA ekrany panelu: SEO → Mapa strony
  // (komplet pól) i starsza zakładka Tłumaczenia → Mapa strony (same pola
  // sekcji tłumaczeń). Budowanie tablicy od zera kasowałoby po cichu typy,
  // taksonomie i kotwice — ekran, który je ustawił, nie pokazuje się tam,
  // gdzie znikają.
  const stary = php.zapis_starym_ekranem;
  t.check('typy treści przeżywają zapis starym ekranem',
    JSON.stringify(stary.excluded_types) === JSON.stringify(['realizacja']),
    JSON.stringify(stary.excluded_types));
  t.check('sekcje kotwic przeżywają zapis starym ekranem',
    stary.anchor_sections.length === 1 && stary.anchor_sections[0].slug === 'menu',
    JSON.stringify(stary.anchor_sections));
  t.check('pole obecne w zapisie jednak się zmienia', stary.enabled === 0, String(stary.enabled));

  const nowy = php.zapis_nowym_ekranem;
  t.check('slugi są sanityzowane i odduplikowane',
    JSON.stringify(nowy.excluded_types) === JSON.stringify(['realizacja']) &&
    JSON.stringify(nowy.noindex_types) === JSON.stringify(['slajd']),
    JSON.stringify(nowy.excluded_types) + ' / ' + JSON.stringify(nowy.noindex_types));
  /* Kotwice przychodzą tak, jak je wpisze człowiek: z kratką, wielką literą,
     duplikatem i pustym wierszem po kliknięciu „dodaj". Wszystkie cztery
     przypadki wychodzą jedną listą dwóch kotwic. */
  const sekcje = nowy.anchor_sections;
  t.check('kotwice znormalizowane, bez duplikatów i pustych',
    sekcje.length === 1 && JSON.stringify(sekcje[0].anchors) === JSON.stringify(['desery', 'napoje']),
    JSON.stringify(sekcje.map((s) => s.anchors)));
  // Slug decyduje o nazwie pliku w indeksie mapy, więc powstaje z nazwy.
  t.check('slug sekcji powstaje z nazwy', sekcje[0].slug === 'menu-lokalu', String(sekcje[0].slug));
  t.check('sekcja bez nazwy i sekcja bez kotwic wypadają', sekcje.length === 1,
    sekcje.map((s) => s.name).join(', '));
  t.check('ID wpisów przechodzą przez absint',
    JSON.stringify(nowy.excluded_ids) === JSON.stringify([42]),
    JSON.stringify(nowy.excluded_ids));

  // ── Skan noindex ──────────────────────────────────────────────────────
  t.section('automatyczne pomijanie czyta tylko znane pola SEO');

  /* Skan chodził po WSZYSTKICH metadanych i uznawał za `noindex` każdy klucz
     z „noindex" albo „robots" w nazwie, o niepustej wartości. Własne pole
     `noindex_uwagi` z treścią „sprawdzić z klientem" wyrzucało przez to stronę
     z mapy — po cichu, bez śladu na ekranie edycji. Kierunek błędu był
     najgorszy z możliwych: pomyłka USUWAŁA treść z mapy. */
  const skan = php.noindex_skan;
  t.check('Yoast z włączoną flagą wyklucza',
    JSON.stringify(skan['11']) === JSON.stringify(['_yoast_wpseo_meta-robots-noindex']),
    JSON.stringify(skan['11']));
  // Kontrola negatywna dla tego samego pola: „0" znaczy „indeksuj".
  t.check('Yoast z wyłączoną flagą nie wyklucza', skan['12'].length === 0, JSON.stringify(skan['12']));
  // Bricks trzyma to w JSON-ie razem z resztą ustawień strony.
  t.check('Bricks rozpoznany w środku JSON-a',
    JSON.stringify(skan['13']) === JSON.stringify(['_bricks_page_settings']), JSON.stringify(skan['13']));
  /* SEOPress: klucz nie zawiera słowa „noindex", a wartością jest „yes" —
     dawna heurystyka nie miała jak go rozpoznać. */
  t.check('SEOPress rozpoznany mimo innej nazwy i wartości',
    JSON.stringify(skan['14']) === JSON.stringify(['_seopress_robots_index']), JSON.stringify(skan['14']));
  t.check('pole z zakładki SEO Evoke ONE rozpoznane',
    JSON.stringify(skan['17']) === JSON.stringify(['_evoke_seo_robots']), JSON.stringify(skan['17']));

  // Sedno poprawki: własne pola o mylącej nazwie nie ruszają mapy.
  t.check('własne pole „noindex_uwagi" nie wyklucza', skan['15'].length === 0, JSON.stringify(skan['15']));
  t.check('własne pole „robots_txt_snippet" nie wyklucza', skan['16'].length === 0, JSON.stringify(skan['16']));

  // Lista jest po to, żeby dało się ją zobaczyć — ekran diagnostyki ją drukuje.
  t.check('lista znanych pól nie jest pusta', php.noindex_klucze.length >= 6,
    php.noindex_klucze.length + ' kluczy');

  // ── Martwy generator /sitemap.xml ─────────────────────────────────────
  t.section('własny /sitemap.xml nie wraca');

  // Generator z tagami hreflang nigdy nie odpowiedział na żywej stronie:
  // reguły rewrite nie były przebudowywane po włączeniu modułu tłumaczeń,
  // więc żądanie przejmował rdzeń i przekierowywał na wp-sitemap.xml.
  // Do wp-sitemap.xml hreflang nie wejdzie — renderer WordPressa przyjmuje
  // wyłącznie loc, lastmod, changefreq i priority — ale nie musi: tagi
  // hreflang jadą w <head> każdej podstrony.
  t.check('moduł nie rejestruje reguły rewrite', php.stary_generator.rewrite_rule === false,
    'add_rewrite_rule w źródle: ' + php.stary_generator.rewrite_rule);
  t.check('brak query var mapy', php.stary_generator.query_var === false,
    String(php.stary_generator.query_var));
  t.check('robots.txt wskazuje wp-sitemap.xml',
    php.stary_generator.robots_txt.includes('/wp-sitemap.xml') &&
    !php.stary_generator.robots_txt.includes('test/sitemap.xml'),
    php.stary_generator.robots_txt.trim().split('\n').pop());

  // ── Sekcja hreflang ───────────────────────────────────────────────────
  t.section('hreflang: powiązania wersji językowych w mapie');

  /* Renderer kończy się `exit` — musi, skoro wypisuje cały dokument zamiast
     szablonu strony — więc każde wywołanie idzie osobnym procesem i całym
     wyjściem sondy jest XML. */
  const xml = phpOutput('sitemap-hreflang.php', 'en');

  t.check('dokument deklaruje przestrzeń xhtml',
    xml.includes('xmlns:xhtml="http://www.w3.org/1999/xhtml"'), xml.split('\n')[3]);

  /* ARKUSZ STYLÓW — ZGŁOSZONE Z ŻYWEJ STRONY („czy tak ma być, że wyświetla
     się goły tekst?"). Plik był poprawny, ale bez `<?xml-stylesheet ?>`
     przeglądarka pokazuje go po swojemu: Safari samą treść znaczników, czyli
     adresy i daty zlepione w ciąg. Pozostałe sekcje `wp-sitemap-*` niosą tę
     linię od rdzenia i wyglądają jak tabela.

     Instrukcja przetwarzania MUSI stać przed elementem głównym — po nim jest
     poza prologiem i parser ją ignoruje. Stąd sprawdzenie kolejności, a nie
     samej obecności. */
  const liniaXsl = xml.indexOf('<?xml-stylesheet');
  t.check('arkusz stylów rdzenia dopięty przed <urlset>',
    liniaXsl > 0 && liniaXsl < xml.indexOf('<urlset') &&
    xml.includes('href="https://example.test/wp-sitemap.xsl"'),
    xml.split('\n')[1]);

  /* Trzy wersje językowe strony = trzy bloki `<url>`. Wersja, która ma tylko
     wpis w cudzych powiązaniach, a własnego bloku nie ma, jest dla
     wyszukiwarki niezgłoszona. */
  const bloki = xml.split('<url>').slice(1);
  t.check('każda wersja ma własny blok', bloki.length === 9, bloki.length + ' bloków');

  /* KOMPLET powiązań w KAŻDYM bloku, nie tylko wskazanie na siebie: deklaracja,
     której druga strona nie potwierdza, jest odrzucana. */
  const niepelne = bloki.filter((b) =>
    !b.includes('hreflang="pl"') || !b.includes('hreflang="en-US"') || !b.includes('hreflang="x-default"'));
  t.check('każdy blok niesie komplet powiązań', !niepelne.length,
    niepelne.length ? niepelne.length + ' bloków niepełnych' : 'pl, en-US, de-DE, x-default');

  // Etykiety z ustawień języków, nie gołe kody — `en-US`, nie `en`.
  t.check('etykiety językowe z ustawień', xml.includes('hreflang="de-DE"') && !xml.includes('hreflang="de"'),
    'de-DE');

  /* `lastmod` strony głównej — data TREŚCI, nie moment wygenerowania pliku.
     Na żywej stronie stała tam godzina bieżącego żądania: dwa wejścia pod ten
     sam adres w odstępie czterech minut dawały dwie różne daty ostatniej
     zmiany. Zmyślona świeżość jest gorsza niż brak pola, bo podważa wszystkie
     pozostałe daty w tym pliku. Fixtura ma dwie strony: 1 i 2 kwietnia. */
  const lastmodGlownej = (bloki[0].match(/<lastmod>([^<]+)<\/lastmod>/) || [])[1];
  t.check('lastmod strony głównej z najnowszej treści',
    lastmodGlownej === '2026-04-02T09:00:00+00:00', String(lastmodGlownej));

  const xDefault = (xml.match(/hreflang="x-default" href="([^"]+)"/) || [])[1];
  t.check('x-default wskazuje wybrany język', xDefault === 'https://example.test/en/',
    String(xDefault));

  const xmlPl = phpOutput('sitemap-hreflang.php', 'pl');
  const xDefaultPl = (xmlPl.match(/hreflang="x-default" href="([^"]+)"/) || [])[1];
  t.check('zmiana ustawienia zmienia x-default', xDefaultPl === 'https://example.test/',
    String(xDefaultPl));

  /* „Pomijaj podstrony bez przetłumaczonego sluga": adres niemiecki strony
     `kontakt` znika — i z bloków, i z powiązań, bo jedno bez drugiego to
     zerwane wskazanie. */
  const xmlTlum = phpOutput('sitemap-hreflang.php', 'pl tylko-tlumaczone');
  t.check('bez tłumaczenia sluga nie ma ani bloku, ani powiązania',
    !xmlTlum.includes('/de/kontakt/') && xmlTlum.split('<url>').length - 1 === 8,
    (xmlTlum.split('<url>').length - 1) + ' bloków');

  /* Renderer wisi na `template_redirect` i musi schodzić z drogi każdemu
     innemu żądaniu — także pozostałym sekcjom mapy, które rysuje rdzeń. */
  const obce = phpOutput('sitemap-hreflang.php', 'pl inna-sekcja');
  t.check('żądanie innej sekcji przechodzi do rdzenia', obce.trim() === 'NIE-PRZEJETO',
    obce.trim().slice(0, 40));

  // ── Drugie źródło deklaracji: tagi w <head> ───────────────────────────
  t.section('hreflang w <head> mówi to samo co mapa');

  /* Do 1.224.2 tej połowy nie sprawdzało NIC, a to ona jest źródłem, które
     wyszukiwarka widzi na każdej podstronie. Mapa i `<head>` muszą deklarować
     to samo — rozbieżność jest sygnałem sprzecznym, rozstrzyganym po stronie
     wyszukiwarki, nie po naszej. */
  const glowa = JSON.parse(phpOutput('hreflang-head.php', 'pl'));
  const tagi  = glowa.tagi.map((t2) => t2.tag);

  /* `pl-PL` leciał obok `pl`, oba na ten sam adres. Sprzeczności w tym nie ma,
     ale region ZAWĘŻA („polski w Polsce"), a mapa wypisywała samo `pl` — więc
     dwa źródła opisywały ten sam język dwoma zestawami tagów. */
  t.check('polski deklarowany raz, jako pl',
    tagi.filter((x) => x.toLowerCase().startsWith('pl')).length === 1 && tagi.includes('pl'),
    tagi.join(', '));
  t.check('żaden język nie jest zadeklarowany dwa razy',
    new Set(tagi).size === tagi.length, tagi.join(', '));
  // Etykiety obcych języków idą z ustawień, nie z kodu katalogu.
  t.check('etykiety obcych języków z ustawień',
    tagi.includes('en') && tagi.includes('de-DE'), tagi.join(', '));

  const xdGlowa = glowa.tagi.find((t2) => t2.tag === 'x-default');
  t.check('x-default w <head> z tego samego ustawienia co w mapie',
    xdGlowa && xdGlowa.url === 'https://example.test/oferta/', JSON.stringify(xdGlowa));

  const glowaEn = JSON.parse(phpOutput('hreflang-head.php', 'en'));
  const xdEn = glowaEn.tagi.find((t2) => t2.tag === 'x-default');
  t.check('i zmienia się razem z nim', xdEn && xdEn.url === 'https://example.test/en/offer/',
    JSON.stringify(xdEn));
};
