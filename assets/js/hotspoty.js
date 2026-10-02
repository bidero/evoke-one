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

  d.addEventListener('click', function (ev) {
    var el = ev.target;
    if (!el || el.nodeType !== 1 || kliki.length >= LIMIT) return;
    var r = el.getBoundingClientRect(), teraz = Date.now();
    var k = { s: selektor(el), l: etykieta(el),
      x: r.width ? Math.max(0, Math.min(1000, Math.round((ev.clientX - r.left) / r.width * 1000))) : 0,
      y: r.height ? Math.max(0, Math.min(1000, Math.round((ev.clientY - r.top) / r.height * 1000))) : 0,
      px: Math.round(ev.pageX), py: Math.round(ev.pageY), z: 0, m: 0 };
    if (!k.s) return;
    /* Złość: trzecie (i dalsze) szybkie kliknięcie w to samo miejsce; jedna seria — jeden znacznik. */
    seria = seria.filter(function (p) { return teraz - p.t < 1000 && Math.abs(p.x - ev.clientX) < 30 && Math.abs(p.y - ev.clientY) < 30; });
    seria.push({ t: teraz, x: ev.clientX, y: ev.clientY });
    if (seria.length === 3) k.z = 1;
    kliki.push(k); nowe.push(k);
    if (!el.closest(AKTYWNE)) {
      var przed = zmiany, adres = location.href;
      setTimeout(function () {
        var zaznaczenie = window.getSelection && String(window.getSelection());
        if (zmiany === przed && location.href === adres && !zaznaczenie && d.visibilityState === 'visible') k.m = 1;
      }, 1000);
    }
  }, true);
  window.addEventListener('scroll', mierz, { passive: true });
  d.addEventListener('visibilitychange', function () { if (d.visibilityState === 'hidden') wyslij(); });
  window.addEventListener('pagehide', wyslij);
  mierz();
})();
