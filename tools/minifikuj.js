/**
 * Skrócone wersje plików JS i CSS, które jadą na stronę.
 *
 *   node tools/minifikuj.js                     zbuduj wszystkie
 *   node tools/minifikuj.js --sprawdz           tylko powiedz, czy wszystkie są aktualne
 *   node tools/minifikuj.js --sprawdz animator  to samo dla plików, których ścieżka zawiera „animator"
 *   node tools/minifikuj.js --lista             lista źródeł (JSON), dla strażnika pokrycia
 *
 * DLACZEGO W OGÓLE. Najpierw Animator, zgłoszone z użycia: „elementy pojawiają
 * się z opóźnieniem". Zmierzone na lustrze przy dławieniu procesora 6× — silnik
 * zaczynał działać dopiero o 1757 ms, bo wcześniej trzeba pobrać i sparsować
 * ~200 KiB JS-a. Z tego `animator.js` to 79 112 B, a 47 170 B (60%) to
 * komentarze. Potem cała reszta (1.248.0), zgłoszone: „w kodzie strony jest
 * mnóstwo poprawek, opisów w skryptach, które wcale nie są tam potrzebne".
 * Zmierzone na 23 plikach frontu: 499 KB źródeł, 184 KB po skróceniu.
 * Komentarze w źródłach są dokumentacją i mają tam zostać.
 *
 * ŹRÓDŁEM POZOSTAJE PLIK BEZ `.min`. Plik skrócony jest wytworem — nie edytuje
 * się go ręcznie, a `tests/minifikacja.test.js` (i dla Animatora
 * `tests/animator.test.js`) pilnuje, żeby nie był nieaktualny. Bez tego
 * najgroźniejszy możliwy błąd to cichy rozjazd: testy chodzą na źródle,
 * a odwiedzający dostaje stary kod.
 *
 * PUŁAPKA PRZY MUTACJACH: zmiana źródła zapala strażnika aktualności, więc
 * skrypt mutujący musi przebudować (`node tools/minifikuj.js`) PO wprowadzeniu
 * mutacji i przywrócić oba pliki po przebiegu.
 *
 * NOWY PLIK FRONTU trzeba dopisać do `PLIKI` niżej. Strażnik pokrycia
 * w `tests/minifikacja.test.js` zapali się, gdy PHP kolejkuje plik JS albo CSS
 * wtyczki, którego tu nie ma.
 */

const fs = require('fs');
const path = require('path');
const { minify } = require('terser');
const esbuild = require('esbuild');

const KORZEN = path.join(__dirname, '..');

/** Źródła, ścieżki od korzenia wtyczki. Wynik: obok, z `.min` przed rozszerzeniem. */
const PLIKI = [
  'assets/js/animator.js',
  'assets/js/accessibility.js',
  'assets/js/bg-shift.js',
  'assets/js/parallax.js',
  'assets/js/warstwy.js',
  'assets/js/statystyki.js',
  'includes/bricks-elements/evoke-burger/assets/burger.css',
  'includes/bricks-elements/evoke-burger/assets/burger.js',
  'includes/bricks-elements/evoke-circular-menu/assets/circular-menu.css',
  'includes/bricks-elements/evoke-circular-menu/assets/circular-menu.js',
  'includes/bricks-elements/evoke-circular-title/assets/circular-title.css',
  'includes/bricks-elements/evoke-circular-title/assets/circular-title.js',
  'includes/bricks-elements/evoke-grain/assets/grain.css',
  'includes/bricks-elements/evoke-grain/assets/grain.js',
  'includes/bricks-elements/evoke-horizontal-scroll/assets/hscroll.css',
  'includes/bricks-elements/evoke-horizontal-scroll/assets/hscroll.js',
  'includes/bricks-elements/evoke-marquee/assets/marquee.css',
  'includes/bricks-elements/evoke-marquee/assets/marquee.js',
  'includes/bricks-elements/evoke-offcanvas-menu/assets/offcanvas-menu.css',
  'includes/bricks-elements/evoke-offcanvas-menu/assets/offcanvas-menu.js',
  'includes/bricks-elements/evoke-scroll-reading/assets/scroll-reading.css',
  'includes/bricks-elements/evoke-scroll-reading/assets/scroll-reading.js',
  'includes/bricks-elements/evoke-stacking-cards/assets/stacking-cards.css',
  'includes/bricks-elements/evoke-stacking-cards/assets/stacking-cards.js',
];

/** `x.js` → `x.min.js`, `x.css` → `x.min.css`. */
const doMin = (plik) => plik.replace(/\.(js|css)$/, '.min.$1');

/** Jednolinijkowy nagłówek: plik bez śladu pochodzenia trafia kiedyś do czyjejś
 *  konsoli i nikt nie wie, skąd się wziął ani czego nie edytować. */
const naglowek = (plik) => '/* Evoke ONE — wytwór tools/minifikuj.js. Nie edytuj — źródłem jest ' + plik + '. */';

/**
 * Ustawienia terser — te same przy budowaniu i przy sprawdzaniu aktualności,
 * bo inaczej „nieaktualny" znaczyłoby tylko „zbudowany innymi ustawieniami".
 * Nazwy najwyższego poziomu zostają (bez `toplevel`): pliki są zwykłymi
 * skryptami i część z nich wystawia funkcje innym.
 */
const USTAWIENIA_JS = {
  compress: { passes: 2 },
  mangle: true,
  format: { comments: false },
};

async function zbuduj(plik) {
  const zrodlo = fs.readFileSync(path.join(KORZEN, plik), 'utf8');
  if (plik.endsWith('.css')) {
    /* esbuild bez `target`, więc niczego nie obniża — zdejmuje komentarze
       i odstępy. `legalComments: 'none'`, bo `/*!` też nie jest tu nikomu
       potrzebne. */
    const out = esbuild.transformSync(zrodlo, { loader: 'css', minify: true, legalComments: 'none' });
    return naglowek(plik) + '\n' + out.code;
  }
  const out = await minify(zrodlo, { ...USTAWIENIA_JS, format: { ...USTAWIENIA_JS.format, preamble: naglowek(plik) } });
  if (!out || typeof out.code !== 'string') throw new Error('terser nic nie zwrócił: ' + plik);
  return out.code;
}

(async () => {
  const arg = process.argv.slice(2);
  if (arg.includes('--lista')) {
    console.log(JSON.stringify(PLIKI));
    return;
  }
  const sprawdz = arg.includes('--sprawdz');
  const zawezenie = arg.filter((a) => !a.startsWith('--'));
  const wybrane = zawezenie.length ? PLIKI.filter((p) => zawezenie.some((z) => p.includes(z))) : PLIKI;
  if (!wybrane.length) {
    console.error('żaden plik nie pasuje do: ' + zawezenie.join(' '));
    process.exit(1);
  }

  const kb = (n) => (n / 1024).toFixed(1) + ' KiB';
  const zle = [];
  let przed = 0, po = 0;
  for (const plik of wybrane) {
    const kod = await zbuduj(plik);
    const cel = path.join(KORZEN, doMin(plik));
    const stary = fs.existsSync(cel) ? fs.readFileSync(cel, 'utf8') : null;
    if (sprawdz) {
      if (stary !== kod) zle.push(doMin(plik) + (stary === null ? ' — brak pliku' : ' — NIEAKTUALNY'));
      continue;
    }
    if (stary !== kod) fs.writeFileSync(cel, kod);
    const z = fs.statSync(path.join(KORZEN, plik)).size;
    przed += z;
    po += Buffer.byteLength(kod);
    console.log(kb(z).padStart(10) + ' → ' + kb(Buffer.byteLength(kod)).padStart(9) + '  ' + doMin(plik));
  }

  if (sprawdz) {
    if (zle.length) {
      console.error(zle.join('\n') + '\nuruchom `node tools/minifikuj.js`');
      process.exit(1);
    }
    console.log('aktualne: ' + wybrane.length + ' z ' + PLIKI.length);
    return;
  }
  console.log(kb(przed).padStart(10) + ' → ' + kb(po).padStart(9) + '  razem ('
    + Math.round(100 - (100 * po) / przed) + '% mniej)');
})().catch((e) => { console.error(e && e.message ? e.message : e); process.exit(1); });
