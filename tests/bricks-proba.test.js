/**
 * Element próbny przełączników (1.243.0).
 *
 * Rejestruje się WYŁĄCZNIE po define('EVK_BRICKS_PROBA', true) w wp-config.php
 * strony testowej. Gałąź jedzie aktualizatorem na żywe strony, więc bez stałej
 * (albo z inną wartością niż true) elementu ma nie być ani w builderze, ani
 * w panelu. Co próba sprawdza na prawdziwym Bricksie — nagłówek pliku
 * includes/bricks-elements/proba/proba-przelacznikow.php.
 *
 * Stąd nie widać, co Bricks ZAPISZE w elemencie — po to jest próba na stronie.
 * Tu: że rejestruje się tylko ze stałą, że kontrolki mają umówiony kształt
 * i że render pokazuje surowy stan kluczy (brak klucza ≠ null ≠ pusty napis).
 */

const { phpOutput } = require('./lib/harness');

const sonda = (tryb) => JSON.parse(phpOutput('bricks-proba.php', tryb));

module.exports = async function (t) {
  t.section('rejestracja tylko ze stałą w wp-config.php');
  const opis = { bez: 'bez stałej', false: 'stała = false', napis: 'stała = \'1\' (nie ściśle true)' };
  for (const tryb of Object.keys(opis)) {
    const w = sonda(tryb);
    t.check(`${opis[tryb]}: elementu próbnego nie ma`, w.zarejestrowane.length === 0, JSON.stringify(w.zarejestrowane));
  }
  const tak = sonda('tak');
  t.check('stała = true: jeden element, z własną nazwą i klasą',
    JSON.stringify(tak.zarejestrowane) === '[{"plik":"proba-przelacznikow.php","nazwa":"evk-proba-przelacznikow","klasa":"Evk_Proba_Przelacznikow_Element"}]',
    JSON.stringify(tak.zarejestrowane));

  t.section('kontrolki próby');
  const k = tak.kontrolki || {};
  t.check('znacznik ukryty (warunek, który nie zachodzi) i jawny, oba z domyślną 2',
    k.proba_znacznik && k.proba_znacznik.default === 2 && JSON.stringify(k.proba_znacznik.required) === '["proba_nigdy","=","tak"]'
      && k.proba_jawny && k.proba_jawny.default === 2 && !('required' in k.proba_jawny), JSON.stringify(k));
  t.check('„Włącz A" i „Włącz B" domyślnie zaznaczone, „Włącz B" tylko przy znaczniku 2',
    k.proba_wlacz && k.proba_wlacz.default === true && k.proba_nowy && k.proba_nowy.default === true
      && JSON.stringify(k.proba_nowy.required) === '["proba_znacznik","=",2]', JSON.stringify(k));
  t.check('„Wyłącz B" bez domyślnej, tylko gdy znacznik nie jest 2',
    k.proba_stary_off && !('default' in k.proba_stary_off) && JSON.stringify(k.proba_stary_off.required) === '["proba_znacznik","!=",2]',
    JSON.stringify(k.proba_stary_off));

  t.section('render: surowy stan kluczy, bez interpretacji');
  t.check('nowy element: wartości kluczy po kolei',
    /proba_znacznik: 2\nproba_jawny: 2\nproba_wlacz: true\nproba_nowy: true\nproba_stary_off: brak klucza\nproba_tekst: brak klucza/.test(tak.nowy || ''),
    tak.nowy);
  t.check('„stary" element bez kluczy: sześć razy „brak klucza"', ((tak.stary || '').match(/: brak klucza/g) || []).length === 6, tak.stary);
  t.check('null i pusty napis odróżnione od braku klucza',
    /proba_wlacz: null/.test(tak.odznaczony || '') && /proba_tekst: &quot;&quot;/.test(tak.odznaczony || ''), tak.odznaczony);
};
