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
  }
  /* Strona wczytana z wyprzedzeniem (prerender) liczy się dopiero, gdy ktoś na nią wejdzie. */
  if (d.prerendering) d.addEventListener('prerenderingchange', start, { once: true }); else start();
})();
