/**
 * Evoke ONE — statystyki bez cookies (1.283.0).
 *
 * Jedna odsłona = jeden beacon „v” przy wczytaniu i beacon „k” przy każdym
 * schowaniu karty (czas widoczności w sekundach, największe przewinięcie
 * w procentach). Nic nie zostaje w przeglądarce: bez cookies, bez
 * localStorage. Konfigurację drukuje PHP przed skryptem (window.evkStat).
 */
(function () {
  var c = window.evkStat, n = navigator;
  if (!c || !n.sendBeacon) return;
  if (c.d && (n.doNotTrack === '1' || window.doNotTrack === '1' || n.globalPrivacyControl)) return;
  if (n.webdriver || /bot|crawl|spider|headless/i.test(n.userAgent)) return;

  var b = new Uint8Array(8), k = '', i;
  (window.crypto || window.msCrypto).getRandomValues(b);
  for (i = 0; i < 8; i++) k += (b[i] + 256).toString(16).slice(1);

  var widoczny = 0, od = 0, przew = 0, d = document, e = d.documentElement;
  function wyslij(o) { o.k = k; n.sendBeacon(c.u, JSON.stringify(o)); }
  function mierz() {
    var h = e.scrollHeight;
    if (h > 0) przew = Math.max(przew, Math.min(100, Math.round((window.scrollY + window.innerHeight) / h * 100)));
  }
  function start() {
    var q = new URLSearchParams(location.search);
    wyslij({ t: 'v', s: location.pathname, q: location.search, r: d.referrer, w: window.innerWidth, p: c.p, j: c.j,
      us: q.get('utm_source') || '', um: q.get('utm_medium') || '', uc: q.get('utm_campaign') || '' });
    od = d.visibilityState === 'visible' ? Date.now() : 0;
    mierz();
    window.addEventListener('scroll', mierz, { passive: true });
    d.addEventListener('visibilitychange', function () {
      if (d.visibilityState === 'hidden') {
        if (od) { widoczny += Date.now() - od; od = 0; }
        mierz();
        wyslij({ t: 'k', c: Math.round(widoczny / 1000), d: przew });
      } else od = Date.now();
    });
    /* Zdarzenia (1.285.0): rodzaje włączone w panelu przychodzą w c.z (lista). Beacon wychodzi
       także przy opuszczaniu strony (link wychodzący, pobranie). Własne: data-evk-zdarzenie. */
    var z = c.z || [];
    function zd(r, e) { if (e) wyslij({ t: 'z', r: r, e: String(e).slice(0, 190) }); }
    d.addEventListener('click', function (ev) {
      var el = ev.target && ev.target.closest ? ev.target.closest('[data-evk-zdarzenie],a[href]') : null;
      if (!el) return;
      if (el.hasAttribute('data-evk-zdarzenie')) return zd('wlasne', el.getAttribute('data-evk-zdarzenie'));
      var h = el.getAttribute('href') || '', u;
      if (/^tel:/i.test(h)) { if (z.indexOf('tel') > -1) zd('tel', h.slice(4).replace(/[^\d+]/g, '')); return; }
      if (/^mailto:/i.test(h)) { if (z.indexOf('tel') > -1) zd('mail', h.slice(7).split('?')[0].toLowerCase()); return; }
      try { u = new URL(el.href, location.href); } catch (er) { return; }
      if (!/^https?:$/.test(u.protocol)) return;
      if (z.indexOf('pobrania') > -1 && /\.(pdf|zip|rar|7z|docx?|xlsx?|pptx?|csv|txt|odt|ods|odp|epub|mp3|mp4|mov|dmg|exe|apk)$/i.test(u.pathname)) {
        var nazwa = u.pathname.split('/').pop();
        try { nazwa = decodeURIComponent(nazwa); } catch (er) { /* zostaje zakodowana */ }
        return zd('pobranie', nazwa);
      }
      if (z.indexOf('wychodzace') > -1 && u.hostname && u.hostname !== location.hostname) zd('wychodzacy', u.hostname.replace(/^www\./, ''));
    }, true);
    d.addEventListener('submit', function (ev) {
      var f = ev.target;
      if (z.indexOf('formularze') < 0 || !f || f.tagName !== 'FORM') return;
      if (f.hasAttribute('data-evk-zdarzenie')) return zd('wlasne', f.getAttribute('data-evk-zdarzenie'));
      zd('formularz', f.id || f.getAttribute('name') || f.getAttribute('aria-label') || 'formularz');
    }, true);
  }
  /* Hotspoty (1.286.0): czy ta strona jest nagrywana, mówi plik z serwera (świeży co 5 min),
     nie HTML — strona z pamięci podręcznej nagrywa od chwili włączenia. Ścieżka jak w evk_stat_sciezka. */
  function hotspoty() {
    if (!c.h || !window.fetch) return;
    var q = new URLSearchParams(location.search), o = [];
    (c.hp || []).forEach(function (p) {
      var v = q.get(p);
      if (v !== null && (v = v.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40))) o.push(p + '=' + v);
    });
    var s = location.pathname + (o.length ? '?' + o.sort().join('&') : '');
    fetch(c.h + '?t=' + Math.floor(Date.now() / 3e5), { credentials: 'omit' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      var koniec = j && j.strony && j.strony[s];
      if (!koniec || koniec * 1000 < Date.now()) return;
      window.evkHot = { u: c.u, k: k };
      var sc = d.createElement('script');
      sc.src = c.hs; sc.async = true;
      d.head.appendChild(sc);
    }).catch(function () { /* brak pliku — nic nie nagrywamy */ });
  }
  /* Strona wczytana z wyprzedzeniem (prerender) liczy się dopiero, gdy ktoś na nią wejdzie. */
  function wejscie() { start(); hotspoty(); }
  if (d.prerendering) d.addEventListener('prerenderingchange', wejscie, { once: true }); else wejscie();
})();
