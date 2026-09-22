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

  // ── Kotwice ───────────────────────────────────────────────────────────
  t.section('kotwice zamiast martwych permalinków');

  t.check('adres to strona docelowa z kotwicą',
    php.kotwice.length === 1 && php.kotwice[0].loc === 'https://example.test/oferta/#danie-dnia',
    JSON.stringify(php.kotwice));
  // Wykluczony wpis (#102) i wpis bez sluga (#103) nie mają czego wnieść.
  t.check('wykluczony wpis i wpis bez sluga odpadają', php.kotwice.length === 1,
    php.kotwice.map((k) => k.loc).join(' | '));
  t.check('lastmod w formacie W3C',
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/.test(php.kotwice[0].lastmod),
    String(php.kotwice[0].lastmod));
  // Nieistniejąca strona docelowa daje PUSTĄ listę, nie adres z „false".
  t.check('brak strony docelowej — brak adresów', php.kotwice_bez_strony.length === 0,
    JSON.stringify(php.kotwice_bez_strony));
  t.check('bez konfiguracji — brak adresów', php.kotwice_bez_konfiguracji.length === 0,
    JSON.stringify(php.kotwice_bez_konfiguracji));

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
  t.check('kotwice przeżywają zapis starym ekranem',
    JSON.stringify(stary.anchor_types) === JSON.stringify({ menu: 7 }),
    JSON.stringify(stary.anchor_types));
  t.check('pole obecne w zapisie jednak się zmienia', stary.enabled === 0, String(stary.enabled));

  const nowy = php.zapis_nowym_ekranem;
  t.check('slugi są sanityzowane i odduplikowane',
    JSON.stringify(nowy.excluded_types) === JSON.stringify(['realizacja']) &&
    JSON.stringify(nowy.noindex_types) === JSON.stringify(['slajd']),
    JSON.stringify(nowy.excluded_types) + ' / ' + JSON.stringify(nowy.noindex_types));
  t.check('kotwica bez strony docelowej wypada',
    JSON.stringify(nowy.anchor_types) === JSON.stringify({ menu: 7 }),
    JSON.stringify(nowy.anchor_types));
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
