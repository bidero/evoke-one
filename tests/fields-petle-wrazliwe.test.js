/**
 * Pętle nad „Polem wrażliwym" — Evoke FIELDS 1.74.0. Pole wrażliwe renderuje się tylko
 * redakcji albo gościowi z kluczem tego wpisu („w pętlach/rankingach i cudzych stronach
 * zwraca pustkę" — podpowiedź pola). Tag tę bramkę miał, pętle Bricksa nie: wiersze galerii
 * mają syntetyczne pola img/cat bez flagi, a relacja, użytkownicy i termy pola wracają jako
 * obiekty WP. Galeria wrażliwa była więc widoczna dla gościa przez pętlę.
 *
 * Czwarty testowy WordPress (pola.test, obie wtyczki). Pętle idą przez bricks/query/run,
 * jak w Bricksie — Bricksa tu nie ma.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (tryb) => {
  const w = phpOutput('fields-petle-wrazliwe.php', tryb, { dopuscBlad: true });
  try { return JSON.parse(w); } catch (e) { return { brak: 'sonda „' + tryb + '": ' + w.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);

/* Pełne pętle: 3 obrazy galerii, 2 kategorie, po jednym powiązanym wpisie, użytkowniku
   i termie, 2 obrazy w wierszu listy, pola jawne i opcje po jednym. */
const PELNE = { galeria: 3, galeria_kategorie: 2, relacja: 1, uzytkownicy: 1, termy: 1, galeria_w_wierszu: 2,
  galeria_plaska: 2, kategorie_plaskie: 2, jawna: 1, jawna_relacja: 1, opcje: 1 };
const WRAZLIWE_PUSTE = (p) => ['galeria', 'galeria_kategorie', 'relacja', 'uzytkownicy', 'termy', 'galeria_w_wierszu',
  'galeria_plaska', 'kategorie_plaskie'].every((k) => p[k] === 0);

module.exports = async function (t) {
  t.section('środowisko: pola.test z Evoke ONE i Evoke FIELDS');
  const u = sonda('ustaw');
  t.check('czwarty WordPress z Evoke FIELDS (tools/testowy-wp.sh)', !u.brak && u.gotowe === true, u.brak || J(u));
  if (u.brak || !u.gotowe) return;

  try {
    const r = sonda('petle');
    t.check('sonda pętli odpowiada', !r.brak, r.brak || '');
    if (r.brak) return;
    const G = r.gosc || {}, R = r.redakcja || {};

    t.section('gość bez klucza: pętle nad polem wrażliwym puste, jak tag');
    t.check('galeria wrażliwa (tag tej galerii też pusty)', G.galeria === 0 && J(r.tag_gosc) === '[]', J([G.galeria, r.tag_gosc]));
    t.check('kategorie galerii wrażliwej', G.galeria_kategorie === 0, J(G.galeria_kategorie));
    t.check('relacja, użytkownicy, termy pola', G.relacja === 0 && G.uzytkownicy === 0 && G.termy === 0, J([G.relacja, G.uzytkownicy, G.termy]));
    t.check('galeria wrażliwa w wierszu listy', G.galeria_w_wierszu === 0, J(G.galeria_w_wierszu));
    t.check('płaska galeria z listy i jej kategorie', G.galeria_plaska === 0 && G.kategorie_plaskie === 0, J([G.galeria_plaska, G.kategorie_plaskie]));
    t.check('pola jawne bez zmian', G.jawna === 1 && G.jawna_relacja === 1, J([G.jawna, G.jawna_relacja]));
    t.check('pętla „Opcje" jak tag opcji — bez bramki', G.opcje === 1, J(G.opcje));

    t.section('redakcja');
    t.check('wszystkie pętle pełne', J(R) === J(PELNE), J(R));

    t.section('gość z kluczem wpisu');
    const k = sonda('klucz');
    t.check('klucz wpisu rozpoznany (?key= na stronie wpisu)', k.autoryzowany === true, J(k.autoryzowany));
    t.check('pętle TEGO wpisu pełne', J(k.ten_wpis) === J(PELNE), J(k.ten_wpis));
    t.check('pętle innego wpisu puste (klucz otwiera tylko swój wpis), jawne i opcje bez zmian',
      WRAZLIWE_PUSTE(k.inny_wpis || {}) && (k.inny_wpis || {}).jawna === 1 && (k.inny_wpis || {}).opcje === 1, J(k.inny_wpis));
  } finally {
    sonda('sprzataj');
  }
};
