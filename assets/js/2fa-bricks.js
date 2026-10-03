/**
 * Evoke ONE — 2FA: drugi krok w formularzu logowania Bricksa (1.287.0).
 *
 * Po poprawnym haśle serwer odpowiada błędem z danymi `evk2fa` (token).
 * Bricks zgłasza go zdarzeniem „bricks/form/error” — wtedy TEN formularz
 * zamienia się w miejscu w krok kodu: jego pola znikają, wchodzi klon jego
 * pierwszego pola (te same klasy, więc ten sam wygląd) z etykietą „Kod
 * z aplikacji”, a jego przycisk zostaje. Wysłanie idzie zwykłą drogą
 * Bricksa: te same pola (hasło nadal w przeglądarce, nikt go nie odsyła
 * z serwera) plus token i kod. „Wróć” przywraca formularz.
 *
 * Wygląd i teksty (1.289.0) — kontrolki „Logowanie dwuetapowe (Evoke)”
 * w formularzu (includes/logowanie/2fa-bricks.php), w atrybucie `data-evk2fa`.
 * Klasy: .evk-2fa-nawig (linki), .evk-2fa-link (każdy link), .evk-2fa-blad
 * (komunikat po złej próbie). Linki bez własnych klas mają wygląd etykiet
 * formularza (zmienne --evk-2fa-et-…, liczone z jego etykiety); globalnie
 * nadpisują go zmienne --evk-2fa-link-color, -size, -font, -weight, -hover,
 * a kontrolki elementu wygrywają ze wszystkim.
 */
(function () {
  var cfg = window.evk2fa || {};
  var DOMYSLNE = {
    tfaEtykieta: 'Kod z aplikacji',
    tfaPodpowiedz: '123 456',
    tfaZapasowy: 'Nie masz telefonu? Użyj kodu zapasowego',
    tfaZapasowyEtykieta: 'Kod zapasowy',
    tfaWroc: '← Wróć',
    tfaPamietaj: 'Zapamiętaj to urządzenie na ' + (cfg.dni || 30) + ' dni',
    tfaBlad: 'Nieprawidłowy kod. Zostało prób: {proby}.'
  };
  /* Specyficzność klasy: wygrywa z gołym `button` motywu i z `.form-group` Bricksa
     (kolumna, w warstwie @layer bricks), przegrywa z kontrolkami elementu (#brxe-…).
     Pełny wygląd tylko przy linkach BEZ własnych klas (.evk-2fa-link--goly) —
     klasy frameworka ustawiają go same. */
  var STYL = '.evk-2fa-nawig{display:flex;flex-direction:row;flex-wrap:wrap;row-gap:var(--evk-2fa-odstep-pion,4px);column-gap:var(--evk-2fa-odstep-poziom,16px)}'
    /* Zerowa baza (:where — każda klasa z nią wygrywa): bez tła i ramki przycisku przeglądarki także przy własnych klasach. */
    + ':where(.evk-2fa-link){background:none;border:0;padding:0;font:inherit;color:inherit;text-align:inherit}'
    + '.evk-2fa-link{min-height:24px;cursor:pointer}'
    + '.evk-2fa-link--goly{background:none;border:0;padding:0;margin:0;text-align:inherit;text-decoration:none;line-height:inherit;'
    + 'font-family:var(--evk-2fa-link-font,var(--evk-2fa-et-font,inherit));font-weight:var(--evk-2fa-link-weight,var(--evk-2fa-et-weight,inherit));'
    + 'font-size:var(--evk-2fa-link-size,calc(var(--evk-2fa-et-size,1em)*.9));color:var(--evk-2fa-link-color,var(--evk-2fa-et-color,inherit))}'
    + '.evk-2fa-link--goly:hover,.evk-2fa-link--goly:focus-visible{text-decoration:underline;color:var(--evk-2fa-link-hover,var(--evk-2fa-link-color,var(--evk-2fa-et-color,inherit)))}'
    + '.evk-2fa-blad{margin:4px 0 0;color:var(--evk-2fa-blad-color,#c62828);font-size:var(--evk-2fa-blad-size,calc(var(--evk-2fa-et-size,1em)*.9))}'
    + '.evk-2fa-blad:empty{display:none}'
    + 'input[name="evk_2fa_kod"]:not(:placeholder-shown){letter-spacing:.25em}';

  function styl() {
    if (document.getElementById('evk-2fa-styl')) return;
    var st = document.createElement('style');
    st.id = 'evk-2fa-styl';
    st.textContent = STYL;
    document.head.appendChild(st);
  }
  function ustawienia(f) {
    var u = {};
    try { u = JSON.parse(f.getAttribute('data-evk2fa') || '{}') || {}; } catch (e) { u = {}; }
    for (var k in DOMYSLNE) if (!u[k]) u[k] = DOMYSLNE[k];
    return u;
  }
  function formularz(id) {
    return document.getElementById('brxe-' + id) || document.querySelector('form[data-script-id="' + id + '"]');
  }
  function wroc(f) {
    f.querySelectorAll('[data-evk2fa-el]').forEach(function (e) { e.remove(); });
    f.querySelectorAll('[data-evk2fa-ukryte]').forEach(function (e) { e.style.display = ''; e.removeAttribute('data-evk2fa-ukryte'); });
    delete f.dataset.evk2faKrok;
  }
  /* Wygląd etykiety formularza — domyślny wygląd linków. Liczony PRZED schowaniem pól. */
  function zEtykiety(f, cel) {
    var et = f.querySelector('.form-group label');
    if (!et) return;
    var s = getComputedStyle(et);
    cel.style.setProperty('--evk-2fa-et-color', s.color);
    cel.style.setProperty('--evk-2fa-et-font', s.fontFamily);
    cel.style.setProperty('--evk-2fa-et-size', s.fontSize);
    cel.style.setProperty('--evk-2fa-et-weight', s.fontWeight);
  }
  function krokKodu(f, d) {
    var u = ustawienia(f);
    var grupy = [].filter.call(f.querySelectorAll('.form-group'), function (g) {
      return !g.classList.contains('submit-button-wrapper') && !g.hasAttribute('data-evk2fa-el');
    });
    var wzor = grupy.filter(function (g) { return g.querySelector('input:not([type=checkbox]):not([type=hidden])'); })[0];
    if (!wzor) return;
    styl();
    /* Pole kodu: klon pierwszego pola formularza — ten sam wygląd. Klon PRZED schowaniem pól,
       inaczej przejąłby ich `display: none`. */
    var g = wzor.cloneNode(true), pole = g.querySelector('input:not([type=checkbox]):not([type=hidden])'), et = g.querySelector('label');
    g.setAttribute('data-evk2fa-el', '');
    g.querySelectorAll('input:not([type=checkbox]):not([type=hidden])').forEach(function (x) { if (x !== pole) x.remove(); });
    pole.id = 'evk-2fa-kod-' + Math.random().toString(36).slice(2, 8);
    pole.name = 'evk_2fa_kod';
    pole.type = 'text';
    pole.value = '';
    pole.required = true;
    pole.setAttribute('inputmode', 'numeric');
    pole.setAttribute('autocomplete', 'one-time-code');
    pole.setAttribute('maxlength', '9');
    pole.removeAttribute('aria-describedby');
    /* Formularz z etykietami — etykieta; formularz z samymi podpowiedziami w polach (bez <label>) — podpowiedź, jak jego pola. */
    pole.setAttribute('aria-label', u.tfaEtykieta);
    if (et) { et.textContent = u.tfaEtykieta; et.setAttribute('for', pole.id); pole.setAttribute('placeholder', u.tfaPodpowiedz); } else pole.setAttribute('placeholder', u.tfaEtykieta);
    var nawig = document.createElement('div');
    nawig.className = 'form-group evk-2fa-nawig';
    nawig.setAttribute('data-evk2fa-el', '');
    zEtykiety(f, nawig);
    grupy.forEach(function (x) { x.setAttribute('data-evk2fa-ukryte', ''); x.style.display = 'none'; });
    var ukryty = document.createElement('input');
    ukryty.type = 'hidden'; ukryty.name = 'evk_2fa_token'; ukryty.value = d.token; ukryty.setAttribute('data-evk2fa-el', '');
    var pierwsza = grupy[0];
    pierwsza.parentNode.insertBefore(g, pierwsza);
    pierwsza.parentNode.insertBefore(ukryty, pierwsza);
    /* Komunikat po złej próbie — pod polem kodu, ten sam rozmiar co linki; pusty = niewidoczny. */
    var blad = document.createElement('p');
    blad.className = 'evk-2fa-blad';
    blad.setAttribute('role', 'alert');
    blad.setAttribute('data-evk2fa-el', '');
    zEtykiety(f, blad);
    g.appendChild(blad);
    var po = g;
    /* „Zapamiętaj urządzenie”: klon pola zaznaczenia formularza, gdy jest (ten sam wygląd), inaczej zwykłe. */
    if (d.pamietaj) {
      var wz = grupy.filter(function (x) { return x.querySelector('input[type=checkbox]'); })[0], z;
      if (wz) {
        z = wz.cloneNode(true);
        z.querySelectorAll('input[type=checkbox]').forEach(function (x, i) { if (i) x.closest('li, label, div') && x.closest('li, label, div').remove(); });
        var c = z.querySelector('input[type=checkbox]');
        c.name = 'evk_2fa_pamietaj'; c.value = '1'; c.checked = false; c.id = pole.id + '-p';
        var ce = z.querySelector('label[for]') || c.closest('label') || z.querySelector('label');
        if (ce) { if (ce.contains(c)) { ce.textContent = ''; ce.appendChild(c); ce.appendChild(document.createTextNode(' ' + u.tfaPamietaj)); } else { ce.textContent = u.tfaPamietaj; ce.setAttribute('for', c.id); } }
      } else {
        z = document.createElement('div');
        z.className = 'form-group';
        var l = document.createElement('label'), cb = document.createElement('input');
        cb.type = 'checkbox'; cb.name = 'evk_2fa_pamietaj'; cb.value = '1';
        l.appendChild(cb); l.appendChild(document.createTextNode(' ' + u.tfaPamietaj));
        z.appendChild(l);
      }
      z.style.display = '';
      z.removeAttribute('data-evk2fa-ukryte');
      z.setAttribute('data-evk2fa-el', '');
      po.parentNode.insertBefore(z, po.nextSibling);
      po = z;
    }
    /* Kod zapasowy i powrót — przyciski z wyglądem linków, pod polem albo pod przyciskiem formularza. */
    var klasy = (u.klasy || '').split(/\s+/).filter(Boolean);
    [[u.tfaZapasowy, 'zapasowy', function () {
      if (et) et.textContent = u.tfaZapasowyEtykieta;
      pole.setAttribute('aria-label', u.tfaZapasowyEtykieta);
      pole.setAttribute('inputmode', 'text'); pole.setAttribute('autocomplete', 'off'); pole.setAttribute('placeholder', et ? 'xxxx-xxxx' : u.tfaZapasowyEtykieta + ' (xxxx-xxxx)'); pole.value = ''; pole.focus();
      this.remove();
    }], [u.tfaWroc, 'wroc', function () { wroc(f); var m = f.querySelector('.message'); if (m) m.remove(); }]].forEach(function (p) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = p[0];
      b.className = 'evk-2fa-link evk-2fa-link--' + p[1] + (klasy.length ? ' ' + klasy.join(' ') : ' evk-2fa-link--goly');
      b.addEventListener('click', p[2]);
      nawig.appendChild(b);
    });
    var przycisk = f.querySelector('.submit-button-wrapper');
    if (u.polozenie === 'przycisk' && przycisk) przycisk.parentNode.insertBefore(nawig, przycisk.nextSibling);
    else po.parentNode.insertBefore(nawig, po.nextSibling);
    f.dataset.evk2faKrok = '1';
    /* Bricks dokłada swój komunikat („Wpisz kod…”) zaraz po tym zdarzeniu — w kroku kodu niepotrzebny. */
    setTimeout(function () { var m = f.querySelector('.message'); if (m) m.remove(); }, 0);
    if (!u.podglad) pole.focus();
  }
  function zlaProba(f, d) {
    var p = f.querySelector('input[name="evk_2fa_kod"]'), b = f.querySelector('.evk-2fa-blad');
    if (b) b.textContent = ustawienia(f).tfaBlad.replace(/\{proby\}/g, String(d.proby || ''));
    setTimeout(function () { var m = f.querySelector('.message'); if (m) m.remove(); }, 0);
    if (p) { p.value = ''; if (!ustawienia(f).podglad) p.focus(); }
  }
  document.addEventListener('bricks/form/error', function (e) {
    var det = e.detail || {}, res = det.res || {}, d = res.data && res.data.evk2fa, f = formularz(det.elementId);
    if (!d || !f) return;
    if (d.wygasl) { wroc(f); return; }
    if (d.blad) { zlaProba(f, d); return; }
    if (f.dataset.evk2faKrok !== '1' && d.token) krokKodu(f, d);
  });
  /* Podgląd w builderze: krok kodu (z przykładowym komunikatem) w formularzu z włączonym
     podglądem — także po każdym przerysowaniu elementu przez Bricksa. */
  function podglady() {
    document.querySelectorAll('form[data-evk2fa]').forEach(function (f) {
      if (f.dataset.evk2faKrok === '1' || !ustawienia(f).podglad) return;
      krokKodu(f, { token: 'podglad', pamietaj: true });
      zlaProba(f, { proby: 4 });
    });
  }
  if (document.querySelector('form[data-evk2fa]') || window.self !== window.top) {
    podglady();
    if (window.MutationObserver && window.self !== window.top) new MutationObserver(podglady).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
