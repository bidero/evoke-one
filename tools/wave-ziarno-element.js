/**
 * Buduje `tests/fixtures/wave-ziarno-element.html` — wyjście prawdziwego
 * `render()` elementu Wave Background dla narzędzia porównującego ziarno.
 *
 *   node tools/wave-ziarno-element.js            zbuduj
 *   node tools/wave-ziarno-element.js --sprawdz  powiedz tylko, czy jest aktualny
 *
 * PO CO OSOBNY PLIK, SKORO `wave-bg-pomiar.html` mierzy bez niego. Tamten
 * fixture prowadzi Playwright i to on wstrzykuje świeże wyjście PHP-a przez
 * `window.__tresc`. `wave-ziarno.html` otwiera CZŁOWIEK w swojej przeglądarce,
 * na maszynie bez PHP-a — musi więc dostać znacznik gotowy. Wytwór zamiast
 * przepisanego ręcznie znacznika jest tu jedyną uczciwą drogą: odtworzenie
 * elementu w fixturze mierzyłoby nasze wyobrażenie o nim.
 *
 * Jedyna zmiana wobec wyjścia `render()`: adres three.js. Wtyczka składa go
 * z `EVOKE_ONE_URL`, a tu ma być względny wobec `tests/fixtures/`, żeby ten sam
 * plik działał dwiema drogami — z serwera testowego repozytorium i spod adresu
 * wtyczki zainstalowanej na żywej stronie (tam `tests/` jadą razem z wtyczką).
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const KORZEN = path.join(__dirname, '..');
const CEL = path.join(KORZEN, 'tests/fixtures/wave-ziarno-element.html');

/* Ziarno WŁĄCZONE i w domyślnej mocy — wariant „dziś" ma być tym, co widzi
   odwiedzający, a nie ustawieniem dobranym pod pomiar. */
const USTAWIENIA = '{"noise_enabled":true,"noise_intensity":0.08}';

const ADRES_Z_WTYCZKI = '\\/assets\\/vendor\\/three\\/';
const ADRES_WZGLEDNY = '..\\/..\\/assets\\/vendor\\/three\\/';

function wersja() {
  const m = fs.readFileSync(path.join(KORZEN, 'CHANGELOG.md'), 'utf8').match(/^## \[([0-9.]+)\]/m);
  if (!m) throw new Error('Nie znalazłem numeru wersji w CHANGELOG.md.');
  return m[1];
}

function zbuduj() {
  const html = execFileSync('php', ['tests/php/wave-bg-colors.php', USTAWIENIA, 'html'],
    { cwd: KORZEN, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });

  /* Podmiana MUSI trafić. Gdyby element przestał składać adres z EVOKE_ONE_URL,
     cichy brak podmiany zostawiłby ścieżkę od korzenia serwera — narzędzie
     działałoby z repozytorium i milczkiem padało spod adresu wtyczki. */
  if (!html.includes(ADRES_Z_WTYCZKI)) {
    throw new Error('Nie znalazłem adresu three.js w wyjściu render(). '
      + 'Element zmienił sposób składania adresu — popraw tę podmianę.');
  }

  const naglowek = [
    '<!--',
    '  GENEROWANE — nie edytować ręcznie.',
    '',
    '  Wyjście prawdziwego render() elementu Wave Background. Narzędzie',
    '  wave-ziarno.html wstrzykuje ten plik, żeby mierzyć TEN kod, który jedzie',
    '  na stronę, a nie jego odtworzenie w znaczniku narzędzia.',
    '',
    '  ODTWORZENIE (z korzenia repozytorium):',
    '',
    '      node tools/wave-ziarno-element.js',
    '',
    '  Jedyna zmiana wobec wyjścia render(): adres three.js — tu względny wobec',
    '  tests/fixtures/, żeby ten sam plik działał i z serwera testowego',
    '  repozytorium, i spod adresu wtyczki zainstalowanej na stronie.',
    '',
    '  Zbudowane z wersji: ' + wersja(),
    '-->',
  ].join('\n');

  return naglowek + '\n' + html.split(ADRES_Z_WTYCZKI).join(ADRES_WZGLEDNY);
}

const swieze = zbuduj();

if (process.argv.includes('--sprawdz')) {
  const stare = fs.existsSync(CEL) ? fs.readFileSync(CEL, 'utf8') : '';
  if (stare === swieze) {
    console.log('tests/fixtures/wave-ziarno-element.html — aktualny');
    process.exit(0);
  }
  console.error('tests/fixtures/wave-ziarno-element.html — NIEAKTUALNY. '
    + 'Przebuduj: node tools/wave-ziarno-element.js');
  process.exit(1);
}

fs.writeFileSync(CEL, swieze);
console.log('tests/fixtures/wave-ziarno-element.html — zbudowany z ' + wersja());
