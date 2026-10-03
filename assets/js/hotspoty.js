/**
 * Evoke ONE — hotspoty (1.286.0). Ładuje go skrypt statystyk tylko na stronie
 * z listy nagrywanych (hotspoty.json) — konfiguracja w window.evkHot.
 *
 * Zapisuje: kliknięcie (element jako selektor, miejsce w elemencie 0–1000,
 * miejsce na stronie), największe przewinięcie i wysokość dokumentu.
 * Złość: 3 kliknięcia w ciągu 1 s w promieniu 30 px (znacznik na trzecim).
 * Martwe: kliknięcie poza linkiem, przyciskiem i polem, po którym przez 1 s
 * nic się w dokumencie nie zmieniło (zaznaczanie tekstu się nie liczy).
 * Bez ruchu myszy i bez wartości pól. Wysyłka przy każdym schowaniu karty.
 *
 * Kliknięcia sprzed wczytania (1.293.0) przychodzą z bufora skryptu statystyk
 * (c.b) — z prostokątem elementu z chwili kliknięcia. Bez oceny „martwe”:
 * wtedy nikt jeszcze nie patrzył na zmiany w dokumencie.
 */
(function () {
  var c = window.evkHot, n = navigator, d = document, e = d.documentElement;
  if (!c || !n.sendBeacon || window.evkHotStart) return;
  window.evkHotStart = 1;
  var kliki = [], nowe = [], przew = 0, seria = [], zmiany = 0, LIMIT = 100;
  var AKTYWNE = 'a,button,input,select,textarea,label,summary,option,[onclick],[role=button],[role=link],[role=tab],[role=menuitem],[tabindex],[contenteditable],[data-evk-zdarzenie]';

  function selektor(el) {
    var p = [];
    while (el && el.nodeType === 1 && el !== e && p.length < 12) {
      if (el.id && /^[A-Za-z][\w-]*$/.test(el.id)) { p.unshift('#' + el.id); return p.join('>'); }
      var i = 1, s = el;
      while ((s = s.previousElementSibling)) if (s.tagName === el.tagName) i++;
      p.unshift(el.tagName.toLowerCase() + ':nth-of-type(' + i + ')');
      el = el.parentElement;
    }
    return p.join('>');
  }
  function etykieta(el) {
    var a = el.closest(AKTYWNE) || el, t = a.tagName.toLowerCase(), w;
    if (t === 'input' || t === 'select' || t === 'textarea') return t + (a.name ? ' ' + a.name : '');
    w = a.getAttribute('aria-label') || a.getAttribute('alt') || a.getAttribute('title') || (a.innerText || a.textContent || '');
    w = String(w).replace(/\s+/g, ' ').trim().slice(0, 60);
    return w || t;
  }
  function mierz() {
    var h = e.scrollHeight;
    if (h > 0) przew = Math.max(przew, Math.min(100, Math.round((window.scrollY + window.innerHeight) / h * 100)));
    if (c.p) przew = Math.max(przew, c.p());
  }
  var wyslane = 0;
  function wyslij() {
    /* Schowanie karty i „pagehide” przychodzą razem przy wyjściu — drugi beacon tylko z czymś nowym. */
    if (wyslane && !nowe.length) return;
    wyslane = 1;
    mierz();
    n.sendBeacon(c.u, JSON.stringify({ t: 'h', k: c.k, s: location.pathname, q: location.search, w: window.innerWidth, h: e.scrollHeight, d: przew, c: nowe }));
    nowe = [];
  }
  new MutationObserver(function () { zmiany++; }).observe(e, { subtree: true, childList: true, attributes: true, characterData: true });

  /** Jedno kliknięcie: element, jego prostokąt i miejsce w chwili kliknięcia; `wczesne` — z bufora. */
  function klik(el, r, cx, cy, px, py, teraz, wczesne) {
    if (!el || el.nodeType !== 1 || kliki.length >= LIMIT) return;
    var k = { s: selektor(el), l: etykieta(el),
      x: r.width ? Math.max(0, Math.min(1000, Math.round((cx - r.left) / r.width * 1000))) : 0,
      y: r.height ? Math.max(0, Math.min(1000, Math.round((cy - r.top) / r.height * 1000))) : 0,
      px: Math.round(px), py: Math.round(py), z: 0, m: 0 };
    if (!k.s) return;
    /* Złość: trzecie (i dalsze) szybkie kliknięcie w to samo miejsce; jedna seria — jeden znacznik. */
    seria = seria.filter(function (p) { return teraz - p.t < 1000 && Math.abs(p.x - cx) < 30 && Math.abs(p.y - cy) < 30; });
    seria.push({ t: teraz, x: cx, y: cy });
    if (seria.length === 3) k.z = 1;
    kliki.push(k); nowe.push(k);
    if (!wczesne && !el.closest(AKTYWNE)) {
      var przed = zmiany, adres = location.href;
      setTimeout(function () {
        var zaznaczenie = window.getSelection && String(window.getSelection());
        if (zmiany === przed && location.href === adres && !zaznaczenie && d.visibilityState === 'visible') k.m = 1;
      }, 1000);
    }
  }
  /* Najpierw bufor (oddanie kończy jego zbieranie), w tej samej chwili własna obsługa — bez luki i bez podwójnych. */
  (c.b ? c.b() : []).forEach(function (b) { klik(b.el, b.r, b.cx, b.cy, b.px, b.py, b.t, true); });
  d.addEventListener('click', function (ev) {
    klik(ev.target, ev.target && ev.target.nodeType === 1 ? ev.target.getBoundingClientRect() : null, ev.clientX, ev.clientY, ev.pageX, ev.pageY, Date.now(), false);
  }, true);
  window.addEventListener('scroll', mierz, { passive: true });
  d.addEventListener('visibilitychange', function () { if (d.visibilityState === 'hidden') wyslij(); });
  window.addEventListener('pagehide', wyslij);
  mierz();
})();
