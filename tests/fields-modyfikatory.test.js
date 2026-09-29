/**
 * Modyfikatory tagów Evoke FIELDS 1.72.0 — jak w Bricksie: `{evk_field_opis:plain:20}`,
 * `{evk_field_data:d.m.Y}`, `{evk_field_link:link}` (zgłoszenie: „takie same opcje
 * jak w Bricks do danych dynamicznych — :slug, :plain itd.").
 *
 * Czwarty testowy WordPress (pola.test, obie wtyczki). Tagi idą przez funkcje,
 * które Bricks woła filtrami render_tag i render_content — Bricksa tu nie ma,
 * więc to, czy Bricks przekazuje część po „:" bez zmian, zostaje do sprawdzenia
 * na stronie.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (tryb) => {
  const w = phpOutput('fields-modyfikatory.php', tryb, { dopuscBlad: true });
  try { return JSON.parse(w); } catch (e) { return { brak: 'sonda „' + tryb + '": ' + w.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);

module.exports = async function (t) {
  t.section('środowisko: pola.test z Evoke ONE i Evoke FIELDS');
  const u = sonda('ustaw');
  t.check('czwarty WordPress z Evoke FIELDS 1.72.0+ (tools/testowy-wp.sh)', !u.brak && u.gotowe === true, u.brak || J(u));
  if (u.brak || !u.gotowe) return;

  try {
    const r = sonda('tagi');
    const T = r.tagi || {}, O = r.oczekiwane || {};

    t.section('tekst: :plain, liczba słów, :slug');
    t.check(':plain — bez znaczników i encji', T.plain === 'Pierwsze drugie trzecie & czwarte piąte szóste', J(T.plain));
    t.check(':3 — trzy słowa, bez znaczników, z wielokropkiem', T.slowa_3 === 'Pierwsze drugie trzecie…', J(T.slowa_3));
    t.check('łańcuch :plain:3', T.plain_3 === 'Pierwsze drugie trzecie…', J(T.plain_3));
    t.check(':slug — polskie znaki na łacińskie, spacje na myślniki', T.slug === 'zazolc-gesla-jazn', J(T.slug));
    t.check('nieznany modyfikator niczego nie zmienia', T.nieznany === 'Zażółć Gęślą Jaźń', J(T.nieznany));

    t.section('daty: format w tagu i :timestamp');
    t.check(':d.m.Y', T.data === '29.09.2026', J(T.data));
    t.check(':H:i — format z „:" składa się z powrotem', T.godzina === '14:05', J(T.godzina));
    t.check(':d.m.Y H:i — format ze spacją', T.data_godzina === '29.09.2026 14:05', J(T.data_godzina));
    t.check('modyfikator przed formatem działa na wynik (:slug:j F Y)', /^29-[a-z]+-2026$/.test(T.data_slug || ''), J(T.data_slug));
    t.check(':timestamp i :raw (ISO) jak __timestamp i __raw', T.znacznik === O.znacznik_daty && T.data_raw === '2026-09-29' && T.data_raw_old === '2026-09-29', J([T.znacznik, T.data_raw, T.data_raw_old]));

    t.section('wybór i surowe: :label, :value, :raw');
    t.check(':label = __label (etykieta opcji)', T.etykieta === 'Beta' && T.etykieta_old === 'Beta', J([T.etykieta, T.etykieta_old]));
    t.check(':value i :raw = wartość zapisana', T.wartosc === 'b' && T.surowa === 'b', J([T.wartosc, T.surowa]));

    t.section('linki i obrazy: :url, :link, :id, rozmiar');
    t.check('link: :url, :link (gotowy <a>), :label', T.link_url === 'https://example.com/x' && T.link_a === '<a href="https://example.com/x">Przykład</a>' && T.link_etyk === 'Przykład',
      J([T.link_url, T.link_a, T.link_etyk]));
    t.check('obraz: :id = __id', T.obraz_id === O.obrazy[0] && T.obraz_id_old === O.obrazy[0], J([T.obraz_id, T.obraz_id_old]));
    t.check('obraz: własny rozmiar przez „:" działa (przez „__" nie przechodził)', T.obraz_rozm === O.url_rozmiaru && T.obraz_rozm_old === '', J([T.obraz_rozm, T.obraz_rozm_old]));
    t.check('taksonomia: :slug to slug termu (znaczenie propa), nie slug nazwy', T.kat_slug === 'kat-gm-slug', J(T.kat_slug));
    t.check('taksonomia: :url i :link', T.kat_url === O.link_termu && T.kat_link === '<a href="' + O.link_termu + '">Kategoria GM</a>', J([T.kat_url, T.kat_link]));
    t.check('relacja: :url i :link', T.rel_url === O.link_wpisu && T.rel_link === '<a href="' + O.link_wpisu + '">Powiązany GM</a>', J([T.rel_url, T.rel_link]));
    t.check('tekst z adresem: :link', T.adres_link === '<a href="https://evoke.pl/kontakt">https://evoke.pl/kontakt</a>', J(T.adres_link));

    t.section('__meta: i strona ustawień');
    t.check('__meta:klucz bez zmian', T.meta === 'Zielony Kolor', J(T.meta));
    t.check('__meta: z kluczem o nazwie modyfikatora (plain) — to klucz mety, nie modyfikator', T.meta_plain === 'meta-o-nazwie-plain', J(T.meta_plain));
    t.check('__meta:klucz:slug — modyfikator po kluczu mety', T.meta_slug === 'zielony-kolor', J(T.meta_slug));
    t.check('{evk_opt_…:slug}', T.opcja_slug === 'haslo-opcji', J(T.opcja_slug));

    t.section('treść mieszana i kontekst obrazu');
    t.check('tekst z kilkoma tagami z modyfikatorami (w tym __meta: i {evk_opt_…})',
      r.mieszana === 'Data: 29.09.2026, wybór: Beta, meta: Zielony Kolor, opcja: haslo-opcji.', J(r.mieszana));
    t.check('kontekst obrazu: modyfikator tekstowy (rozmiar) ignorowany — ID załącznika', J(r.obraz_ctx) === J([O.obrazy[0]]), J(r.obraz_ctx));
    t.check('kontekst obrazu: :ids galerii = cała lista', J(r.galeria_ctx) === J(O.obrazy), J(r.galeria_ctx));
    t.check('kontekst obrazu: :avatar = __avatar (URL awatara, nie ID użytkownika)', J(r.awatar_ctx) === J([O.awatar]), J(r.awatar_ctx));

    t.section('tłumaczenie wartości (1.70) + modyfikator');
    const j = sonda('jezyk');
    t.check('najpierw język, potem modyfikator (:2)', j.pl === 'Witaj przetłumaczony…' && j.en === 'Hello translated…', J(j));
  } finally {
    sonda('sprzataj');
  }
};
