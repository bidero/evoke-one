/**
 * Fala przy zmianie motywu — co widać ZA jej czołem.
 *
 * Zgłoszone z użycia: „ripple dziwnie przeskakuje zaraz po rozpoczęciu
 * rozchodzenia się fali… na desktopie w Chrome. W Safari jest OK".
 *
 * `::view-transition-new` w Chrome pokazuje ŻYWY dokument, nie zamrożony
 * obrazek. Fala odsłaniała więc powierzchnię, która sama jeszcze się
 * przefarbowywała globalnym przejściem: przez pierwsze 400 ms jej wnętrze było
 * szare zamiast docelowego koloru, a potem skokowo się domykało.
 *
 * Mierzymy ZE ZRZUTU EKRANU, bo o to, co widać, tu właśnie chodzi. Animacja
 * jest ZATRZYMANA, a czas ustawiany z ręki — inaczej próbki lądowałyby tam,
 * gdzie zdążył zrzut, a nie w równych odstępach.
 *
 * CSS i skrypt idą PRAWDZIWE, z `93-darkmode.php` przez `darkmode-head.php`.
 */

const { phpOutput } = require('./lib/harness');

/** Wstrzyknięcie modułu i kliknięcie w przełącznik; zwraca czas trwania fali. */
async function zapal(p, ustawienia) {
  await p.evaluate((html) => {
    const d = document.createElement('div');
    d.innerHTML = html;
    Array.from(d.children).forEach((n) => {
      if (n.tagName === 'SCRIPT') {
        const s = document.createElement('script');
        s.textContent = n.textContent;
        document.body.appendChild(s);
      } else { document.head.appendChild(n); }
    });
    window.dispatchEvent(new Event('DOMContentLoaded'));
  }, phpOutput('darkmode-head.php', JSON.stringify(JSON.stringify(ustawienia || {}))));
  await p.waitForTimeout(250);
  await p.click('.brxe-toggle-mode');
  await p.waitForTimeout(120);
  return p.evaluate(`(function(){
    var a = document.getAnimations();
    a.forEach(function(x){ x.pause(); });
    return Math.max.apply(null, a.map(function(x){
      return (x.effect && x.effect.getComputedTiming().duration) || 0; }).concat([0]));
  })()`);
}

/** Jasność i zasięg fali na linii przez jej środek, przy zadanym czasie. */
async function probka(p, t) {
  await p.evaluate((v) => document.getAnimations().forEach((a) => { a.currentTime = v; }), t);
  await p.waitForTimeout(30);
  const buf = await p.screenshot({ clip: { x: 0, y: 60, width: 800, height: 1 } });
  return p.evaluate(async (b64) => {
    const img = new Image();
    img.src = 'data:image/png;base64,' + b64;
    await img.decode();
    const c = document.createElement('canvas');
    c.width = img.width; c.height = 1;
    const ctx = c.getContext('2d');
    ctx.drawImage(img, 0, 0);
    const d = ctx.getImageData(0, 0, img.width, 1).data;
    const jasnosc = (x) => (d[x * 4] + d[x * 4 + 1] + d[x * 4 + 2]) / 3;
    /* Przycisk zajmuje x 40–80, więc czoło fali szukamy od 85 w prawo. */
    let czolo = 84;
    for (let x = 85; x < img.width; x++) { if (jasnosc(x) < 128) czolo = x; else break; }
    return { promien: czolo - 60, wnetrze: Math.round(jasnosc(90)) };
  }, buf.toString('base64'));
}

module.exports = async function (t) {

  const V = { viewport: { width: 800, height: 600 }, settle: 200 };

  // ── Za czołem fali od razu docelowy kolor ────────────────────────────────
  t.section('za czołem fali jest docelowy kolor, nie szarość');

  const p = await t.open('darkmode-ripple.html', V);
  const czas = await zapal(p);
  t.check('fala ma swoją animację', czas > 0, czas + ' ms');

  /* 0,17 czasu trwania: czoło jest już wyraźnie za punktem pomiaru, a globalne
     przejście (0,4 s z 1,2 s fali) jeszcze by trwało. */
  const wSrodku = await probka(p, Math.round(czas * 0.17));
  t.check('czoło fali naprawdę minęło punkt pomiaru', wSrodku.promien > 40,
    'promień ' + wSrodku.promien + ' px');
  t.check('a wnętrze jest już docelowe, nie szare', wSrodku.wnetrze < 20,
    'jasność ' + wSrodku.wnetrze);

  /* Kontrola pozytywna: fala NAPRAWDĘ się rozchodzi. Bez niej „wnętrze jest
     ciemne" byłoby prawdą także dla strony, która przeskoczyła od razu. */
  const pozniej = await probka(p, Math.round(czas * 0.4));
  t.check('i rośnie dalej', pozniej.promien > wSrodku.promien + 100,
    wSrodku.promien + ' → ' + pozniej.promien + ' px');
  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── A bez fali globalne przejście ma dalej działać ───────────────────────
  /*
   * KONTROLA NEGATYWNA dla całej poprawki. Wyciszamy globalne przejście TYLKO
   * tam, gdzie fala je zastępuje — z wyłączoną falą ma płynnie przefarbowywać
   * stronę, bo wtedy to ONO jest efektem.
   */
  t.section('z wyłączoną falą globalne przejście dalej płynnie farbuje');

  const b = await t.open('darkmode-ripple.html', V);
  await b.evaluate((html) => {
    const d = document.createElement('div');
    d.innerHTML = html;
    Array.from(d.children).forEach((n) => {
      if (n.tagName === 'SCRIPT') {
        const s = document.createElement('script');
        s.textContent = n.textContent;
        document.body.appendChild(s);
      } else { document.head.appendChild(n); }
    });
    window.dispatchEvent(new Event('DOMContentLoaded'));
  }, phpOutput('darkmode-head.php', JSON.stringify(JSON.stringify({ ripple_enabled: 0 }))));
  await b.waitForTimeout(250);

  await b.click('.brxe-toggle-mode');
  await b.waitForTimeout(120);
  const wTrakcie = await b.evaluate(`(function(){
    var s = getComputedStyle(document.body).backgroundColor.match(/\\d+/g);
    return Math.round((+s[0] + +s[1] + +s[2]) / 3);
  })()`);
  t.check('w trakcie przejścia tło jest POMIĘDZY', wTrakcie > 20 && wTrakcie < 235,
    'jasność ' + wTrakcie);

  await b.waitForTimeout(600);
  const poWszystkim = await b.evaluate(`(function(){
    var s = getComputedStyle(document.body).backgroundColor.match(/\\d+/g);
    return Math.round((+s[0] + +s[1] + +s[2]) / 3);
  })()`);
  t.check('a na końcu dochodzi do docelowego', poWszystkim < 20, 'jasność ' + poWszystkim);
  await b.close();

  // ── Sam znacznik: co i kiedy jest wyciszane ──────────────────────────────
  t.section('wyciszenie obejmuje ustawione selektory i tylko przy fali');

  const zFala = phpOutput('darkmode-head.php', JSON.stringify(JSON.stringify({})));
  const bezFali = phpOutput('darkmode-head.php', JSON.stringify(JSON.stringify({ ripple_enabled: 0 })));

  /* Wycinamy CAŁY blok reguły, zamiast zgadywać, ile znaków ma lista
     selektorów — jest konfigurowalna i potrafi urosnąć. */
  const blok = zFala.match(/html\.is-theme-toggling body[\s\S]*?\}/);
  const selektory = blok ? (blok[0].match(/html\.is-theme-toggling /g) || []).length : 0;
  t.check('przy fali reguła wycisza wszystkie skonfigurowane selektory',
    !!blok && /transition: none !important/.test(blok[0]) && selektory >= 6,
    blok ? selektory + ' selektorów' : 'brak reguły');
  t.check('a bez fali nie ma jej wcale',
    !/html\.is-theme-toggling [^{]*transition: none/.test(bezFali), 'brak');
};
