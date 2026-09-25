/**
 * Eksport CSV skrzynki formularzy (1.234.0), na PRAWDZIWYM WordPressie.
 *
 * Do 1.233.5:
 *   — komórka dostawała całą tablicę Bricksa: „select, Rezerwacja noclegu,
 *     Temat" zamiast „Rezerwacja noclegu";
 *   — treść zaczynająca się od = + - @ otwierała się w Excelu jako FORMUŁA,
 *     a piszą ją obcy ludzie: pola formularza, przeglądarka, referer
 *     (CSV injection).
 *
 * Plik powstaje w tej samej funkcji co pobieranie w panelu
 * (evk_inbox_csv_zapisz). Sonda: tests/php/inbox-csv.php.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const surowe = phpOutput('inbox-csv.php', '', { dopuscBlad: true });
  let s;
  try { s = JSON.parse(surowe); } catch (e) { s = { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  t.section('środowisko: testowy WordPress');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !s.brak, s.brak || 'jest');
  if (s.brak) return;

  const [r1, r2] = s.rekordy || [];
  const w = (r, k) => (r || {})[k];

  t.section('wartości pól jak w panelu skrzynki, nie tablica Bricksa');
  t.check('lista wyboru: sama wartość', w(r1, 'Temat') === 'Rezerwacja noclegu', JSON.stringify(w(r1, 'Temat')));
  t.check('pole wielokrotnego wyboru: wartości po przecinku', w(r1, 'Opcje') === 'Śniadanie, Parking', JSON.stringify(w(r1, 'Opcje')));
  t.check('starszy Bricks („typ, wartość"): sama wartość', w(r2, 'Temat') === 'Zapytanie ofertowe', JSON.stringify(w(r2, 'Temat')));
  t.check('BOM i nagłówek z etykietami pól', s.bom === true && jak((s.naglowek || []).slice(0, 5), ['ID', 'Formularz', 'Data', 'Temat', 'Opcje']),
    JSON.stringify(s.naglowek));

  t.section('bez formuł: treść od obcych zaczynająca się od = + - @');
  t.check('pole formularza z =HYPERLINK(…) jako tekst', w(r1, 'Imie') === '\'=HYPERLINK("http://zly.example","klik")', JSON.stringify(w(r1, 'Imie')));
  t.check('pole z @SUM(…) jako tekst', w(r1, 'Uwagi') === '\'@SUM(1+1)', JSON.stringify(w(r1, 'Uwagi')));
  t.check('przeglądarka i referer (z nagłówków żądania) też', w(r1, 'Przeglądarka') === '\'=cmd|\' /C calc\'!A0' && w(r1, 'Referer') === '\'-2+3+cmd|x',
    JSON.stringify([w(r1, 'Przeglądarka'), w(r1, 'Referer')]));
  t.check('telefon ze spacjami (+48 600…) jako tekst', w(r1, 'Telefon') === '\'+48 600 100 200', JSON.stringify(w(r1, 'Telefon')));
  t.check('liczby zostają liczbami (-5)', w(r1, 'Liczba') === '-5', JSON.stringify(w(r1, 'Liczba')));
  t.check('zwykłe wartości bez zmian', w(r2, 'Imie') === 'Anna' && w(r1, 'IP') === '203.0.113.7', JSON.stringify([w(r2, 'Imie'), w(r1, 'IP')]));
  t.check('komórka po komórce: formuły z apostrofem, liczby i tekst bez',
    jak(s.komorki, ["'=1+1", "'+48 600", '-5', '+48', '3,14', "'@x", "'\tx", 'zwykły', '', 'a=b']), JSON.stringify(s.komorki));
};
