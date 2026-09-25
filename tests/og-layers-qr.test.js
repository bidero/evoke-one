/**
 * Kod QR bez usług zewnętrznych (1.235.0): includes/opengraph/qr.php.
 *
 * Do 1.234.1 warstwa QR obrazka OG ściągała gotowy PNG z api.qrserver.com —
 * adres każdego wpisu szedł do obcej usługi, a gdy nie odpowiedziała w 5 s,
 * obrazek wychodził bez kodu.
 *
 * Koder jest nasz, więc sprawdza go ktoś inny: każdy kod czyta niezależny
 * dekoder (jsQR, devDependencies) i musi oddać dane bajt w bajt. Zestaw:
 *   — wszystkie wersje 1–40 poziomu L (tego z obrazków OG), dane dokładnie
 *     na pojemność wersji;
 *   — trzynaście wersji każdego z poziomów M, Q, H (bloki, wzorce wyrównania,
 *     informacja o wersji od 7, długość 16-bitowa od 10);
 *   — położenia wzorców wyrównania wszystkich wersji wprost z tablicy normy;
 *   — każda z ośmiu masek, polskie znaki;
 *   — wybór wersji na granicach pojemności z tablicy normy.
 * Na końcu warstwa obrazka OG: prawdziwe evk_og_render_layer() na GD, kod
 * czytany z pikseli, bez żadnego żądania HTTP.
 *
 * Sonda: tests/php/og-layers-qr.php (warstwa: tools/testowy-wp.sh).
 */

const fs = require('fs');
const { phpOutput } = require('./lib/harness');

/* jsQR 1.4.0 ma dla wersji 23 środki wzorców wyrównania [6, 30, 54, 74, 102],
   a norma (ISO/IEC 18004, aneks E) — [6, 30, 54, 78, 102]. Z tym jednym
   rozjazdem jsQR nie czyta ŻADNEGO kodu v23 zgodnego z normą. Sprawdzone przy
   pisaniu testu: ten sam kod z 74 zamiast 78 jsQR czyta przy każdej masce.
   Dekoder dostaje więc tę jedną liczbę z normy, a położenia wzorców dla
   wszystkich wersji sprawdza osobno tablica normy niżej. Gdy wydanie jsQR to
   poprawi, podmiana nic nie znajdzie i zostanie oryginał. */
const BLAD_JSQR = 'alignmentPatternCenters: [6, 30, 54, 74, 102]';
function wczytajDekoder() {
  const zrodlo = fs.readFileSync(require.resolve('jsqr'), 'utf8');
  if (!zrodlo.includes(BLAD_JSQR)) return require('jsqr');
  const m = { exports: {} };
  new Function('module', 'exports', zrodlo.replace(BLAD_JSQR, 'alignmentPatternCenters: [6, 30, 54, 78, 102]'))(m, m.exports);
  return typeof m.exports === 'function' ? m.exports : m.exports.default;
}
const jsQR = wczytajDekoder();

/** Środki wzorców wyrównania z normy (aneks E), wersje 2–40. */
const WYROWNANIE = {
  2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34],
  7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50], 11: [6, 30, 54], 12: [6, 32, 58], 13: [6, 34, 62],
  14: [6, 26, 46, 66], 15: [6, 26, 48, 70], 16: [6, 26, 50, 74], 17: [6, 30, 54, 78], 18: [6, 30, 56, 82], 19: [6, 30, 58, 86], 20: [6, 34, 62, 90],
  21: [6, 28, 50, 72, 94], 22: [6, 26, 50, 74, 98], 23: [6, 30, 54, 78, 102], 24: [6, 28, 54, 80, 106], 25: [6, 32, 58, 84, 110],
  26: [6, 30, 58, 86, 114], 27: [6, 34, 62, 90, 118],
  28: [6, 26, 50, 74, 98, 122], 29: [6, 30, 54, 78, 102, 126], 30: [6, 26, 52, 78, 104, 130], 31: [6, 30, 56, 82, 108, 134],
  32: [6, 34, 60, 86, 112, 138], 33: [6, 30, 58, 86, 114, 142], 34: [6, 34, 62, 90, 118, 146],
  35: [6, 30, 54, 78, 102, 126, 150], 36: [6, 24, 50, 76, 102, 128, 154], 37: [6, 28, 54, 80, 106, 132, 158],
  38: [6, 32, 58, 84, 110, 136, 162], 39: [6, 26, 54, 82, 110, 138, 166], 40: [6, 30, 58, 86, 114, 142, 170],
};

/** Moduły kodu jako tablica wierszy 0/1. */
function macierz(k) {
  return k.moduly.map((hex) => [...hex].map((c) => parseInt(c, 16).toString(2).padStart(4, '0')).join('').slice(0, k.n));
}

/** Wzorce wyrównania na pozycjach z normy: 5×5, ciemny środek i obwódka, jasny pierścień. */
function wyrownanieZgodne(k) {
  const poz = WYROWNANIE[k.wersja] || [];
  const m = macierz(k);
  const ost = poz.length - 1;
  for (let i = 0; i <= ost; i++) {
    for (let j = 0; j <= ost; j++) {
      if ((i === 0 && j === 0) || (i === 0 && j === ost) || (i === ost && j === 0)) continue;
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) {
        const ciemny = Math.max(Math.abs(dx), Math.abs(dy)) !== 1;
        if ((m[poz[i] + dy] || '')[poz[j] + dx] !== (ciemny ? '1' : '0')) return false;
      }
    }
  }
  return true;
}

const sonda = (a) => {
  const s = phpOutput('og-layers-qr.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};

/** Moduły (wiersze szesnastkowo) → obraz RGBA: 4 px na moduł, strefa ciszy 4 moduły. */
function obraz(k) {
  const n = k.n, px = 4, cisza = 4, bok = (n + 2 * cisza) * px;
  const rgba = new Uint8ClampedArray(bok * bok * 4).fill(255);
  k.moduly.forEach((hex, r) => {
    const bity = [...hex].map((c) => parseInt(c, 16).toString(2).padStart(4, '0')).join('');
    for (let c = 0; c < n; c++) {
      if (bity[c] !== '1') continue;
      for (let dy = 0; dy < px; dy++) for (let dx = 0; dx < px; dx++) {
        const i = (((cisza + r) * px + dy) * bok + (cisza + c) * px + dx) * 4;
        rgba[i] = rgba[i + 1] = rgba[i + 2] = 0;
      }
    }
  });
  return { rgba, bok };
}

/** Czy dekoder czyta kod i oddaje te same bajty; wersja według dekodera. */
function czytaj(k) {
  if (!k.moduly) return { ok: false, powod: 'brak kodu' };
  const { rgba, bok } = obraz(k);
  const w = jsQR(rgba, bok, bok);
  if (!w) return { ok: false, powod: 'dekoder nie odczytał' };
  const zgodne = Buffer.from(w.binaryData).equals(Buffer.from(k.dane, 'base64'));
  return { ok: zgodne, wersja: w.version, powod: zgodne ? '' : 'inne bajty' };
}

module.exports = async function (t) {
  const s = sonda('koder');
  t.section('koder: sonda');
  t.check('sonda oddała kody', !s.brak && Array.isArray(s.kody) && s.kody.length > 0, s.brak || String((s.kody || []).length));
  if (s.brak || !Array.isArray(s.kody)) return;

  const wyniki = s.kody.map((k) => ({ k, w: czytaj(k) }));
  const zle = (lista) => lista.filter((x) => !x.w.ok).map((x) => x.k.id + ' (' + x.w.powod + ')');
  const grupa = (przedrostek) => wyniki.filter((x) => x.k.id.startsWith(przedrostek));

  t.section('dekoder czyta każdy kod, dane bajt w bajt');
  const L = grupa('L-v');
  t.check('poziom L (obrazki OG): wszystkie wersje 1–40', L.length === 40 && zle(L).length === 0, zle(L).join(', ') || L.length + ' kodów');
  const zleWyrownanie = L.filter((x) => x.k.wersja >= 2 && !wyrownanieZgodne(x.k)).map((x) => 'v' + x.k.wersja);
  t.check('wzorce wyrównania na pozycjach z normy (aneks E), wersje 2–40', zleWyrownanie.length === 0 && L.length === 40,
    zleWyrownanie.join(', ') || 'zgodne');
  for (const p of ['M', 'Q', 'H']) {
    const g = grupa(p + '-v');
    t.check('poziom ' + p + ': 13 wersji od 1 do 40 (z 23)', g.length === 13 && zle(g).length === 0, zle(g).join(', ') || g.length + ' kodów');
  }
  const maski = grupa('maska-');
  t.check('każda z ośmiu masek', maski.length === 8 && zle(maski).length === 0 && maski.every((x, i) => x.k.maska === i),
    zle(maski).join(', ') || maski.map((x) => x.k.maska).join(','));
  const utf = grupa('utf8');
  t.check('polskie znaki (UTF-8) wracają bajt w bajt', utf.length === 1 && utf[0].w.ok, utf.length ? utf[0].w.powod || 'ok' : 'brak');
  const inneWersje = wyniki.filter((x) => x.w.ok && x.w.wersja !== x.k.wersja).map((x) => x.k.id + ': ' + x.k.wersja + '≠' + x.w.wersja);
  t.check('dekoder widzi tę samą wersję, co koder', inneWersje.length === 0, inneWersje.join(', ') || 'zgodne');

  t.section('wersja na granicach pojemności z normy (tryb bajtowy)');
  /* Tablica normy: ostatnia długość, która mieści się w wersji 1, 2, 10 i 40.
     Jeden bajt więcej to już następna wersja, a za wersją 40 — brak kodu. */
  const norma = { L: [17, 32, 271, 2953], M: [14, 26, 213, 2331], Q: [11, 20, 151, 1663], H: [7, 14, 119, 1273] };
  const wersjeNormy = [1, 2, 10, 40];
  for (const [p, dlugosci] of Object.entries(norma)) {
    const rozjazd = [];
    dlugosci.forEach((dl, i) => {
      const v = wersjeNormy[i];
      const [miesci, zaDuzo] = [(s.wersje[p] || {})[dl], (s.wersje[p] || {})[dl + 1]];
      if (miesci !== v) rozjazd.push(dl + ' B → v' + miesci + ' (norma: v' + v + ')');
      if (zaDuzo !== (v === 40 ? null : v + 1)) rozjazd.push((dl + 1) + ' B → ' + zaDuzo + ' (norma: ' + (v === 40 ? 'brak kodu' : 'v' + (v + 1)) + ')');
    });
    t.check('poziom ' + p + ': jak w tablicy normy', rozjazd.length === 0, rozjazd.join('; ') || 'zgodne');
  }

  t.section('warstwa obrazka OG na testowym WordPressie');
  const w = sonda('warstwa');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !w.brak && !!w.warstwy, w.brak || 'jest');
  if (w.brak || !w.warstwy) return;
  for (const [nazwa, opisWarstwy] of [['domyslne', 'białe moduły na czarnym (kolory domyślne)'], ['ciemne', 'ciemne moduły na jasnym']]) {
    const v = w.warstwy[nazwa];
    const szare = Buffer.from(v.szare, 'base64');
    const rgba = new Uint8ClampedArray(v.bok * v.bok * 4);
    for (let i = 0; i < szare.length; i++) { rgba[i * 4] = rgba[i * 4 + 1] = rgba[i * 4 + 2] = szare[i]; rgba[i * 4 + 3] = 255; }
    const odczyt = jsQR(rgba, v.bok, v.bok, { inversionAttempts: 'attemptBoth' });
    t.check('warstwa ' + opisWarstwy + ': dekoder czyta adres wpisu', !!odczyt && odczyt.data === w.adres,
      odczyt ? JSON.stringify([odczyt.data, w.adres]) : 'dekoder nie odczytał');
    t.check('warstwa ' + opisWarstwy + ': kwadrat w tym samym miejscu i rozmiarze co dotąd',
      v.rogi.every((c) => c === v.tlo) && v.obok.every((c) => c === '#808080'), JSON.stringify([v.rogi, v.obok]));
  }
  t.check('żadnego żądania HTTP (do 1.234.1: api.qrserver.com przy każdym obrazku)', w.http.length === 0, JSON.stringify(w.http));
};
