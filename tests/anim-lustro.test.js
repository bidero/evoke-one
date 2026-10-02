/**
 * Lustro listy Animatora w Atrybutach (1.275.0) — czysta logika
 * assets/admin/anim-lustro.js, bez przeglądarki. Droga w prawdziwym
 * builderze (prawy klik, Copy/Paste → Attributes, zapis, front):
 * bricks-builder-animator.
 */

const L = require('../assets/admin/anim-lustro.js');

const J = (x) => JSON.stringify(x);
const el = (id, s) => ({ id, settings: s });
const lustro = (e) => ((e.settings._attributes || []).find((r) => r.name === 'data-evk-anim') || {}).value;

module.exports = async function (t) {
  t.section('lustro: lista → Atrybuty');
  const m = new Map();
  const a = el('a', { evkAnimList: [{ id: 'r1', animation: 'wjazd', delay: '0.2' }] });
  t.check('lista bez Atrybutów: wiersz data-evk-anim z wierszami listy bez `id`',
    L.krok(a, m) === 'lustro' && lustro(a) === '[{"animation":"wjazd","delay":"0.2"}]', J(a.settings));
  t.check('drugi krok bez zmian nic nie robi', L.krok(a, m) === '' && a.settings._attributes.length === 1, J(a.settings));
  a.settings.evkAnimList.push({ id: 'r2', animation: 'fade' });
  t.check('wiersz dopisany do listy — lustro za nią', L.krok(a, m) === 'lustro' && lustro(a) === '[{"animation":"wjazd","delay":"0.2"},{"animation":"fade"}]', lustro(a));
  delete a.settings._attributes;
  t.check('lustro zgubione (Bricks przy zaznaczaniu, ręczne usunięcie) — wraca w następnym kroku', L.krok(a, m) === 'lustro' && !!lustro(a), J(a.settings));
  a.settings.evkAnimList = [];
  t.check('lista wyczyszczona — lustro znika z Atrybutów', L.krok(a, m) === 'usuniete' && J(a.settings._attributes) === '[]', J(a.settings));

  t.section('wklejenie: Atrybuty → lista (zastępuje)');
  const b = el('b', { evkAnimList: [{ id: 'x1', animation: 'stara' }], _attributes: [{ id: 'q', name: 'data-x', value: '1' }] });
  L.krok(b, m);
  b.settings._attributes = [{ id: 'n', name: 'data-evk-anim', value: '[{"animation":"wjazd","delay":"0.2"},{"animation":"fade"}]' }];
  const co = L.krok(b, m);
  t.check('wklejone lustro zastępuje listę celu, wiersze dostają nowe 6-literowe id',
    co === 'lista' && J(b.settings.evkAnimList.map((r) => [r.animation, r.delay || null, /^[a-z]{6}$/.test(r.id)])) === J([['wjazd', '0.2', true], ['fade', null, true]]), J(b.settings));
  t.check('po wklejeniu lustro i lista zgodne — następny krok nic nie robi', L.krok(b, m) === '', J(b.settings));

  t.section('wpisy ręczne i elementy bez listy');
  const c = el('c', { evkAnimList: [{ id: 'y', animation: 'z' }], _attributes: [{ id: 'q', name: 'data-evk-anim', value: 'wjazd' }] });
  L.krok(c, m);
  c.settings.evkAnimList.push({ id: 'y2', animation: 'w' });
  L.krok(c, m);
  t.check('ręczny wpis sprzed lustra (goła nazwa) zostaje — także po zmianie listy', J(c.settings._attributes) === J([{ id: 'q', name: 'data-evk-anim', value: 'wjazd' }]), J(c.settings));
  const d = el('d', { text: 'x' });
  t.check('element bez listy — bez Atrybutów', L.krok(d, m) === '' && !d.settings._attributes, J(d.settings));
  t.check('ustawienia `[]` (pusty element z PHP) — bez zmian', L.krok(el('e', []), m) === '', '');
  t.check('wiersze z atrybutu: tylko niepusta tablica obiektów z „animation”', J([L.zAtrybutu('wjazd'), L.zAtrybutu('{"animation":"a"}'), L.zAtrybutu('[]'),
    L.zAtrybutu('[{"delay":1}]'), L.zAtrybutu('[{"animation":"a"}]')]) === J([null, null, null, null, [{ animation: 'a' }]]), '');
};
