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

/** Wstrzyknięcie modułu bez klikania — zwraca dopiero po ustaniu ruchu. */
async function wstrzyknij(p, ustawienia) {
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
}

/**
 * Zamraża WSZYSTKIE animacje w chwili, gdy powstają animacje fali.
 *
 * Bez tego próbka lądowałaby tam, gdzie zdążył zrzut: fala rusza dopiero
 * w `transition.ready`, a globalne przejście już wtedy biegnie. Zamrożenie
 * w jednym, powtarzalnym punkcie jest jedynym sposobem, żeby porównywać
 * jasności między wersjami.
 */
async function zamroz(p) {
  await p.evaluate(`(function () {
    var oa = Element.prototype.animate;
    Element.prototype.animate = function (kf, opt) {
      var pe = String((opt && opt.pseudoElement) || '');
      var a = oa.call(this, kf, opt);
      if (pe.indexOf('theme-ripple') > -1) {
        window.__fala = window.__fala || [];
        window.__fala.push(a);
        document.getAnimations().forEach(function (x) { x.pause(); x.currentTime = 0; });
        window.__zamrozone = true;
      }
      return a;
    };
  })()`);
}

/** Jasność w dwóch punktach kadru przy zadanym czasie animacji. */
async function jasnosci(p, t, punkty) {
  /* `t === null` znaczy „zmierz stan bieżący" — bez zatrzymywania animacji.
     Przy przejściu CSS, którego nie zamrażamy, przestawianie czasu przewinęłoby
     je do końca i pomiar straciłby sens. */
  if (t !== null) {
    await p.evaluate((v) => document.getAnimations().forEach((a) => { a.pause(); a.currentTime = v; }), t);
    await p.waitForTimeout(60);
  }
  const buf = await p.screenshot();
  return p.evaluate(async ({ b64, punkty }) => {
    const img = new Image();
    img.src = 'data:image/png;base64,' + b64;
    await img.decode();
    const c = document.createElement('canvas');
    c.width = img.width; c.height = img.height;
    const ctx = c.getContext('2d');
    ctx.drawImage(img, 0, 0);
    const d = ctx.getImageData(0, 0, img.width, img.height).data;
    return punkty.map(([x, y]) => {
      const i = (y * img.width + x) * 4;
      return Math.round((d[i] + d[i + 1] + d[i + 2]) / 3);
    });
  }, { b64: buf.toString('base64'), punkty });
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

  // ── Fala naprawdę odsłania, gdy kolor motywu leży na korzeniu ────────────
  /*
   * ZGŁOSZONE Z UŻYCIA po 1.116.0: „idzie fala, ale wszystko zmienia się tak,
   * jakby nie miała na nic wpływu".
   *
   * Ta sekcja pilnuje samego ODSŁANIANIA: róg ma trzymać stary kolor, dopóki
   * fala nie dojdzie. Atrapa obok mierzy tylko wąski pasek przy źródle i nie
   * widzi, co się dzieje po drugiej stronie kadru.
   *
   * UWAGA na przyszłość: sama usterka z 1.116.0 TU SIĘ NIE ODTWARZA. Nawet
   * z wyciszeniem założonym przed migawkami ta atrapa dalej odsłania — na
   * żywej stronie nie. Przed tamtym błędem broni sprawdzenie znacznikowe
   * niżej („nie stoi pod klasą zakładaną przed migawkami"), a nie ta sekcja.
   * Zmierzone: mutacja przywracająca stan 1.116.0 zostawia ją zieloną.
   */
  t.section('daleki róg trzyma stary kolor, dopóki fala nie dojdzie');

  const k = await t.open('darkmode-ripple-korzen.html', V);
  await wstrzyknij(k);
  await zamroz(k);
  await k.click('.brxe-toggle-mode');
  await k.waitForFunction('window.__zamrozone === true', null, { timeout: 5000 });
  const czasK = await k.evaluate('window.__fala[0].effect.getComputedTiming().duration');

  /* Przycisk stoi w (40,40)–(80,80), środek fali w (60,60). BLISKO to 56 px od
     środka, DALEKO — przeciwny róg, jakieś 910 px. */
  const BLISKO = [100, 100], DALEKO = [770, 570];

  const start = await jasnosci(k, 0, [BLISKO, DALEKO]);
  t.check('na starcie cały kadr jest w starym kolorze',
    start[0] > 200 && start[1] > 200, start.join(' / '));

  const wTrakcieK = await jasnosci(k, Math.round(czasK * 0.3), [BLISKO, DALEKO]);
  t.check('w połowie drogi okolica źródła jest już nowa', wTrakcieK[0] < 40,
    'jasność ' + wTrakcieK[0]);
  t.check('a daleki róg wciąż trzyma stary kolor', wTrakcieK[1] > 200,
    'jasność ' + wTrakcieK[1]);

  /* Kontrola pozytywna: bez niej „róg trzyma stary kolor" spełniłaby też fala,
     która nie odsłania niczego i nigdy. */
  const koniecK = await jasnosci(k, czasK, [BLISKO, DALEKO]);
  t.check('a gdy fala dojdzie — róg też się zmienia', koniecK[1] < 40,
    'jasność ' + koniecK[1]);

  /* Sam mechanizm, niezależnie od tego, co widać: klasa wyciszająca powstaje
     dopiero po migawkach i znika po przejściu. */
  t.check('klasa wyciszająca stoi na korzeniu w trakcie fali',
    await k.evaluate(`document.documentElement.classList.contains('is-theme-settled')`), 'jest');
  t.check('bez błędów JS', !k.errors.length, k.errors.join(' | ') || 'brak');
  await k.close();

  // ── Gradient ze zmiennej czeka na falę ───────────────────────────────────
  /*
   * ZGŁOSZONE Z UŻYCIA: „mam problem z dark mode i gradientami (w których
   * kolory mają odpowiedniki w ciemnym)… kolor gradientu zmienia się od razu,
   * a nie czeka na falę".
   *
   * Gradient to `background-image`, którego Chrome nie interpoluje, gdy kolor
   * przychodzi z `var()`. Zmierzone na lustrze: nawet z wymuszonym
   * `transition: background-image 1s` gradient przeskakuje po 60 ms, podczas
   * gdy kolory obok płyną 222 → 183 → 53 → 43. Dopiero rejestracja zmiennej
   * przez `@property` czyni ją animowalną — a wtedy w chwili migawki stoi
   * jeszcze na starej wartości i fala ma co odsłaniać.
   *
   * Mierzymy najpierw BEZ fali, bo tam widać sam mechanizm: czy zmienna płynie,
   * czy przeskakuje. Kontrola negatywna (bez wpisanej zmiennej) działa właśnie
   * w tym trybie.
   */
  t.section('zarejestrowana zmienna płynie, niezarejestrowana przeskakuje');

  /** Jasność gradientu w trakcie zmiany motywu, bez fali. */
  async function gradientBezFali(ustawienia) {
    const q = await t.open('darkmode-gradient.html', V);
    await wstrzyknij(q, Object.assign({ ripple_enabled: 0 }, ustawienia));
    await q.click('.brxe-toggle-mode');
    /* Ze ZRZUTU, nie z `getComputedStyle`: przy gradiencie ze zmiennej ten
       drugi potrafi oddać wartość sprzed klatki, a chodzi o to, co widać. */
    await q.waitForTimeout(150);   // 0,4 s przejścia — jesteśmy w jego środku
    const wTrakcie = (await jasnosci(q, null, [[400, 300]]))[0];
    await q.waitForTimeout(600);
    const naKoncu = (await jasnosci(q, null, [[400, 300]]))[0];
    const bledy = q.errors.slice();
    await q.close();
    return { wTrakcie, naKoncu, bledy };
  }

  const zarejestrowana = await gradientBezFali({ color_vars: '--evk-proba' });
  t.check('zarejestrowana zmienna jest w trakcie POMIĘDZY',
    zarejestrowana.wTrakcie > 20 && zarejestrowana.wTrakcie < 235,
    'jasność ' + zarejestrowana.wTrakcie);
  t.check('i dochodzi do docelowej', zarejestrowana.naKoncu < 20,
    'jasność ' + zarejestrowana.naKoncu);
  t.check('bez błędów JS', !zarejestrowana.bledy.length, zarejestrowana.bledy.join(' | ') || 'brak');

  /* KONTROLA NEGATYWNA — i zarazem stan, który zgłosiłeś: bez rejestracji
     zmienna nie jest animowalna, więc gradient jest docelowy natychmiast. */
  const bezRejestracji = await gradientBezFali({});
  t.check('niezarejestrowana przeskakuje od razu, bez pośrednich',
    bezRejestracji.wTrakcie < 20, 'jasność ' + bezRejestracji.wTrakcie);

  // ── A pod falą gradient czeka na jej czoło ───────────────────────────────
  t.section('pod falą gradient trzyma stary kolor, dopóki nie dojdzie');

  const f = await t.open('darkmode-gradient.html', V);
  await wstrzyknij(f, { color_vars: '--evk-proba' });
  await zamroz(f);
  await f.click('.brxe-toggle-mode');
  await f.waitForFunction('window.__zamrozone === true', null, { timeout: 5000 });
  const czasF = await f.evaluate('window.__fala[0].effect.getComputedTiming().duration');

  const ROG = [770, 570];   // przeciwny róg — fala dochodzi tam na końcu
  t.check('na starcie gradient jest w starym kolorze',
    (await jasnosci(f, 0, [ROG]))[0] > 200, 'jasność ' + (await jasnosci(f, 0, [ROG]))[0]);
  const polowa = (await jasnosci(f, Math.round(czasF * 0.3), [ROG]))[0];
  t.check('trzyma go, choć motyw już się przełączył', polowa > 200, 'jasność ' + polowa);
  /* Kontrola pozytywna: bez niej „róg trzyma stary kolor" spełniłaby też
     zmienna, która nigdy się nie zmienia. */
  const naKoniec = (await jasnosci(f, czasF, [ROG]))[0];
  t.check('a gdy fala dojdzie — zmienia się', naKoniec < 40, 'jasność ' + naKoniec);
  t.check('bez błędów JS', !f.errors.length, f.errors.join(' | ') || 'brak');
  await f.close();

  // ── Sam znacznik: co i kiedy jest wyciszane ──────────────────────────────
  t.section('wyciszenie obejmuje ustawione selektory i tylko przy fali');

  const zFala = phpOutput('darkmode-head.php', JSON.stringify(JSON.stringify({})));
  const bezFali = phpOutput('darkmode-head.php', JSON.stringify(JSON.stringify({ ripple_enabled: 0 })));

  /* Wycinamy CAŁY blok reguły, zamiast zgadywać, ile znaków ma lista
     selektorów — jest konfigurowalna i potrafi urosnąć. */
  const blok = zFala.match(/html\.is-theme-settled body[\s\S]*?\}/);
  const selektory = blok ? (blok[0].match(/html\.is-theme-settled /g) || []).length : 0;
  t.check('przy fali reguła wycisza wszystkie skonfigurowane selektory',
    !!blok && /transition: none !important/.test(blok[0]) && selektory >= 6,
    blok ? selektory + ' selektorów' : 'brak reguły');
  t.check('a bez fali nie ma jej wcale',
    !/html\.is-theme-settled [^{]*transition: none/.test(bezFali), 'brak');

  /* Stan z 1.116.0: wyciszenie pod `is-theme-toggling`, czyli już PRZED
     migawkami. To właśnie zabierało starej migawce jej stary kolor. */
  t.check('i nie stoi pod klasą zakładaną przed migawkami',
    !/html\.is-theme-toggling [^{}]*\{[^}]*transition: none/.test(zFala), 'brak');

  // ── Zmienne kolorów: rejestracja, przejście, przycięcie po migawkach ─────
  t.section('zmienne kolorów rejestrują się i wchodzą do przejścia');

  /* Sanityzację nazw sprawdza `tests/php/darkmode-easing.php` — ten harness
     wstawia ustawienia wprost, z pominięciem `sanitize_settings`. */
  const zeZm = phpOutput('darkmode-head.php',
    JSON.stringify(JSON.stringify({ color_vars: '--a\n--b' })));

  t.check('zmienna jest zarejestrowana jako kolor',
    /@property --a \{\s*syntax: "<color>";\s*inherits: true;\s*initial-value: transparent;/.test(zeZm),
    'jest');
  t.check('zmienna wchodzi do listy przejść',
    /transition:[^;]*--a 0\.4s/.test(zeZm), 'jest');

  /* Po migawkach zmienne mają zamilknąć, ale kolory korzenia NIE — na nich
     stoi całe odsłanianie (1.117.0). Stąd przycięcie samej listy właściwości. */
  const przyciecie = zeZm.match(/html\.is-theme-settled \{[^}]*\}/);
  t.check('po migawkach lista właściwości korzenia traci zmienne',
    !!przyciecie && !/--a/.test(przyciecie[0]), przyciecie ? 'przycięta' : 'brak reguły');
  t.check('ale kolor tła korzenia w niej zostaje',
    !!przyciecie && /background-color/.test(przyciecie[0]), 'jest');

  t.check('bez wpisanych zmiennych nie ma ani rejestracji, ani przycięcia',
    !/@property --a\b/.test(zFala) && !/html\.is-theme-settled \{/.test(zFala), 'brak');
};
