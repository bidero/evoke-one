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
  t.check('brak przejęcia template_redirect', php.stary_generator.template_redirect === false,
    String(php.stary_generator.template_redirect));
  t.check('robots.txt wskazuje wp-sitemap.xml',
    php.stary_generator.robots_txt.includes('/wp-sitemap.xml') &&
    !php.stary_generator.robots_txt.includes('test/sitemap.xml'),
    php.stary_generator.robots_txt.trim().split('\n').pop());
};
