/**
 * Skrócenie silnika Animatora do wersji, która jedzie na stronę.
 *
 *   node tools/minifikuj.js            zbuduj
 *   node tools/minifikuj.js --sprawdz  tylko powiedz, czy plik jest aktualny
 *
 * DLACZEGO W OGÓLE. Zgłoszone z użycia: „elementy pojawiają się z opóźnieniem".
 * Zmierzone na lustrze przy dławieniu procesora 6× — silnik zaczynał działać
 * dopiero o 1757 ms, bo wcześniej trzeba pobrać i sparsować ~200 KiB JS-a.
 * Z tego `animator.js` to 79 112 B, a 47 170 B (60%) to komentarze. Na stronie
 * nie są nikomu potrzebne; w źródle są całą dokumentacją tego silnika i mają
 * tam zostać.
 *
 * ŹRÓDŁEM POZOSTAJE `animator.js`. Plik skrócony jest wytworem — nie edytuje
 * się go ręcznie, a `tests/animator.test.js` pilnuje, żeby nie był
 * nieaktualny. Bez tego sprawdzenia najgroźniejszy możliwy błąd tej zmiany to
 * cichy rozjazd: testy chodzą na źródle, a odwiedzający dostaje stary silnik.
 */

const fs = require('fs');
const path = require('path');
const { minify } = require('terser');

const KATALOG = path.join(__dirname, '..', 'assets', 'js');
const ZRODLO = path.join(KATALOG, 'animator.js');
const WYNIK = path.join(KATALOG, 'animator.min.js');

/**
 * Ustawienia terser — te same przy budowaniu i przy sprawdzaniu aktualności,
 * bo inaczej „nieaktualny" znaczyłoby tylko „zbudowany innymi ustawieniami".
 *
 * `format.comments: false` zdejmuje komentarze, ale zostawiamy jednolinijkowy
 * nagłówek: plik bez śladu pochodzenia trafia kiedyś do czyjejś konsoli
 * i nikt nie wie, skąd się wziął ani czego nie edytować.
 */
const USTAWIENIA = {
  compress: { passes: 2 },
  mangle: true,
  format: {
    comments: false,
    preamble: '/* Evoke ONE — Animator (silnik). Wytwór tools/minifikuj.js.'
      + ' Nie edytuj — źródłem jest assets/js/animator.js. */',
  },
};

async function zbuduj() {
  const zrodlo = fs.readFileSync(ZRODLO, 'utf8');
  const out = await minify(zrodlo, USTAWIENIA);
  if (!out || typeof out.code !== 'string') throw new Error('terser nic nie zwrócił');
  return { zrodlo: zrodlo, kod: out.code };
}

(async () => {
  const { zrodlo, kod } = await zbuduj();
  const sprawdz = process.argv.indexOf('--sprawdz') !== -1;
  const istnieje = fs.existsSync(WYNIK);
  const stary = istnieje ? fs.readFileSync(WYNIK, 'utf8') : null;

  if (sprawdz) {
    if (stary === kod) {
      console.log('animator.min.js jest aktualny');
      return;
    }
    console.error(istnieje
      ? 'animator.min.js jest NIEAKTUALNY — uruchom `node tools/minifikuj.js`'
      : 'brak animator.min.js — uruchom `node tools/minifikuj.js`');
    process.exit(1);
  }

  fs.writeFileSync(WYNIK, kod);
  const kb = (n) => (n / 1024).toFixed(1) + ' KiB';
  console.log('animator.js     ' + kb(Buffer.byteLength(zrodlo)));
  console.log('animator.min.js ' + kb(Buffer.byteLength(kod))
    + '  (' + Math.round(100 - (100 * kod.length) / zrodlo.length) + '% mniej)');
})();
