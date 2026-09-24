/**
 * Newsletter i obrazek OG na PRAWDZIWYM WordPressie — to, czego atrapy nie widzą.
 *
 * Audyt 1.229.6 znalazł błędy, które przechodziły zielono przez testy na
 * atrapach, bo atrapa nie ma prawdziwego `sanitize_email()`, `add_query_arg()`,
 * `esc_url_raw()` ani katalogu uploads:
 *
 *   — CSV z polskiego Excela (średnik) dawał adresy „jan@firma.plJanKowalski",
 *     liczone jako DODANE,
 *   — import przywracał osoby wypisane i kasował zapis ich wypisu,
 *   — śledzenie kliknięć psuło linki z `&amp;`: UTM-y dostawały „amp;",
 *     a przy zwykłych odnośnikach link zewnętrzny kończył na stronie głównej,
 *   — og:image wskazywał `og-fallback.jpg`, którego zwykle nie ma (404).
 *
 * Środowisko: `tools/testowy-wp.sh`. Bez niego test zapala się na czerwono.
 * Sonda: tests/php/zapis-wp-newsletter.php.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const sonda = (scenariusz) => {
    const surowe = phpOutput('zapis-wp-newsletter.php', scenariusz, { dopuscBlad: true });
    try { return JSON.parse(surowe); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  };
  const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  t.section('środowisko: testowy WordPress z prawdziwą bazą');
  const i = sonda('import');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !i.brak, i.brak || 'jest');
  if (i.brak) return;

  // ── Import: czytanie pliku ──────────────────────────────────────────────
  t.section('import CSV: separator, BOM, nagłówek, kolumna z adresem');
  const dwa = ['jan@example.com', 'ewa@example.com'];
  t.check('polski Excel (średnik, BOM, nagłówek): same adresy',
    jak(i.excel, ['jan.kowalski@firma.pl', 'anna@example.com']), JSON.stringify(i.excel));
  t.check('przecinek, imię PRZED adresem', jak(i.przecinek_imie_pierwsze, dwa), JSON.stringify(i.przecinek_imie_pierwsze));
  t.check('tabulator (wklejka z arkusza)', jak(i.tabulator, dwa), JSON.stringify(i.tabulator));
  t.check('średnik w polu w cudzysłowie nie dzieli pola', jak(i.cudzyslow, dwa), JSON.stringify(i.cudzyslow));
  t.check('jedna kolumna', jak(i.jedna_kolumna, dwa), JSON.stringify(i.jedna_kolumna));
  t.check('wiersz bez adresu (nie nagłówek) idzie dalej, żeby trafić do błędnych',
    jak(i.wiersz_bez_adresu, ['jan@example.com', 'Kowalski']), JSON.stringify(i.wiersz_bez_adresu));
  t.check('pole tekstowe: „Imię <adres>", adres ze średnikiem, linia bez adresu zostaje jako błędna',
    jak(i.textarea, ['jan@example.com', 'zly-adres', 'ewa@example.com']), JSON.stringify(i.textarea));

  // ── Import: zapis ───────────────────────────────────────────────────────
  t.section('import: ścisłe adresy, wypisani zostają wypisani');
  t.check('wiersz Excela przeczytany w całości NIE jest dodawany',
    i.smieci_z_przecinka && i.smieci_z_przecinka.added === 0 && i.smieci_z_przecinka.invalid === 1, JSON.stringify(i.smieci_z_przecinka));
  t.check('wynik: dodano 1, na liście 2, wypisani 1, błędne 1',
    jak(i.wynik, { added: 1, skipped: 2, unsubscribed: 1, invalid: 1 }), JSON.stringify(i.wynik));
  const w = i.wypisana || {};
  t.check('wypisana osoba po imporcie dalej wypisana', w.status_przed === 0 && w.status_po === 0,
    JSON.stringify({ przed: w.status_przed, po: w.status_po }));
  t.check('zapis wypisu i zgody zostaje nietknięty', w.wypis_zostal === true && w.zgoda_zostala === true,
    JSON.stringify({ wypis: w.wypis_zostal, zgoda: w.zgoda_zostala }));
  t.check('niepotwierdzona (double opt-in) zostaje niepotwierdzona', i.oczekujaca_status === 2, JSON.stringify(i.oczekujaca_status));
  t.check('formularz zapisu (decyzja samej osoby) dalej przywraca', i.formularz_przywraca === 1, JSON.stringify(i.formularz_przywraca));

  // ── Kliknięcia ──────────────────────────────────────────────────────────
  const k = sonda('klik');
  for (const [tryb, opis] of [['zwykle', 'zwykłe odnośniki'], ['ladne', 'ładne odnośniki']]) {
    const r = k[tryb] || {};
    t.section('śledzenie kliknięć, ' + opis + ': link z `&amp;` z edytora');
    t.check('oba linki idą przez śledzenie (podpisane)', /sig=/.test(r.wewn_href || '') && /sig=/.test(r.zewn_href || ''),
      JSON.stringify([r.wewn_href, r.zewn_href]));
    t.check('adres w atrybucie zapisany jako HTML (bez gołego „&")',
      !!r.zewn_href && !/&(?!#038;|amp;)/.test(r.wewn_href + r.zewn_href), r.zewn_href);
    /* Samo przekierowanie tego nie pokaże: naprawa starych linków przy
       kliknięciu zamienia `&amp;` i tak. Link ma nieść prawdziwy adres. */
    t.check('link w mailu niesie prawdziwy adres celu (bez „&amp;")',
      r.wewn_url === k.wzor_wewn && r.zewn_url === k.wzor_zewn, JSON.stringify([r.wewn_url, r.zewn_url]));
    t.check('link wewnętrzny: cel z kompletem UTM-ów', r.wewn_cel === k.wzor_wewn, r.wewn_cel);
    t.check('link zewnętrzny: cel z kompletem parametrów, nie strona główna', r.zewn_cel === k.wzor_zewn, r.zewn_cel);
  }

  t.section('śledzenie kliknięć: maile wysłane przed poprawką');
  t.check('stary link (cel z `&amp;`, podpisany) prowadzi do prawdziwego adresu', k.stary_link === k.wzor_zewn, k.stary_link);
  t.check('encja w części z hostem nie wyprowadza poza serwis, nawet podpisana', k.encja_w_hoscie === k.strona_glowna, k.encja_w_hoscie);

  // ── OG ──────────────────────────────────────────────────────────────────
  t.section('og:image: nigdy adres nieistniejącego pliku');
  const o = sonda('og');
  t.check('bez obrazka, miniatury i ustawienia — pusto (bez og:image)', o.bez_niczego === '', JSON.stringify(o.bez_niczego));
  t.check('meta strony też bez og:image', o.meta_bez_niczego === '', JSON.stringify(o.meta_bez_niczego));
  t.check('wygenerowany plik zniknął z dysku — pusto, nie 404', o.plik_zniknal === '', JSON.stringify(o.plik_zniknal));
  t.check('miniatura wpisu, gdy nie ma wygenerowanego obrazka', !!o.wzor_miniatura && o.miniatura === o.wzor_miniatura, o.miniatura);
  t.check('obrazek domyślny z ustawień', o.z_ustawien === 'https://example.com/domyslny.jpg', o.z_ustawien);
  t.check('og-fallback.jpg z uploads, gdy plik naprawdę jest', o.plik_w_uploads === o.wzor_uploads, o.plik_w_uploads);
};
