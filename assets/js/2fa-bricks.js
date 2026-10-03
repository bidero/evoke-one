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
 */
(function () {
  var cfg = window.evk2fa || {};
  function formularz(id) {
    return document.getElementById('brxe-' + id) || document.querySelector('form[data-script-id="' + id + '"]');
  }
  function wroc(f) {
    f.querySelectorAll('[data-evk2fa]').forEach(function (e) { e.remove(); });
    f.querySelectorAll('[data-evk2fa-ukryte]').forEach(function (e) { e.style.display = ''; e.removeAttribute('data-evk2fa-ukryte'); });
    delete f.dataset.evk2fa;
  }
  function krokKodu(f, d) {
    var grupy = [].filter.call(f.querySelectorAll('.form-group'), function (g) {
      return !g.classList.contains('submit-button-wrapper') && !g.hasAttribute('data-evk2fa');
    });
    var wzor = grupy.filter(function (g) { return g.querySelector('input:not([type=checkbox]):not([type=hidden])'); })[0];
    if (!wzor) return;
    /* Pole kodu: klon pierwszego pola formularza — ten sam wygląd. Klon PRZED schowaniem pól,
       inaczej przejąłby ich `display: none`. */
    var g = wzor.cloneNode(true), pole = g.querySelector('input:not([type=checkbox]):not([type=hidden])'), et = g.querySelector('label');
    g.setAttribute('data-evk2fa', '');
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
    /* Odstęp liter tylko dla wpisanych cyfr — podpowiedź („Kod z aplikacji”) zostaje jak w polach formularza. */
    if (!document.getElementById('evk-2fa-styl')) {
      var st = document.createElement('style');
      st.id = 'evk-2fa-styl';
      st.textContent = 'input[name="evk_2fa_kod"]:not(:placeholder-shown){letter-spacing:.25em}';
      document.head.appendChild(st);
    }
    /* Formularz z etykietami — etykieta; formularz z samymi podpowiedziami w polach (bez <label>) — podpowiedź, jak jego pola. */
    pole.setAttribute('aria-label', 'Kod z aplikacji');
    if (et) { et.textContent = 'Kod z aplikacji'; et.setAttribute('for', pole.id); pole.setAttribute('placeholder', '123 456'); } else pole.setAttribute('placeholder', 'Kod z aplikacji');
    grupy.forEach(function (x) { x.setAttribute('data-evk2fa-ukryte', ''); x.style.display = 'none'; });
    var ukryty = document.createElement('input');
    ukryty.type = 'hidden'; ukryty.name = 'evk_2fa_token'; ukryty.value = d.token; ukryty.setAttribute('data-evk2fa', '');
    var pierwsza = grupy[0];
    pierwsza.parentNode.insertBefore(g, pierwsza);
    pierwsza.parentNode.insertBefore(ukryty, pierwsza);
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
        if (ce) { if (ce.contains(c)) { ce.textContent = ''; ce.appendChild(c); ce.appendChild(document.createTextNode(' Zapamiętaj to urządzenie na ' + (cfg.dni || 30) + ' dni')); } else { ce.textContent = 'Zapamiętaj to urządzenie na ' + (cfg.dni || 30) + ' dni'; ce.setAttribute('for', c.id); } }
      } else {
        z = document.createElement('div');
        z.className = 'form-group';
        var l = document.createElement('label'), cb = document.createElement('input');
        cb.type = 'checkbox'; cb.name = 'evk_2fa_pamietaj'; cb.value = '1';
        l.appendChild(cb); l.appendChild(document.createTextNode(' Zapamiętaj to urządzenie na ' + (cfg.dni || 30) + ' dni'));
        z.appendChild(l);
      }
      z.style.display = '';
      z.removeAttribute('data-evk2fa-ukryte');
      z.setAttribute('data-evk2fa', '');
      po.parentNode.insertBefore(z, po.nextSibling);
      po = z;
    }
    /* Kod zapasowy i powrót — odnośniki-przyciski pod polem. */
    var nawig = document.createElement('div');
    nawig.className = 'form-group';
    nawig.setAttribute('data-evk2fa', '');
    nawig.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px 16px;font-size:.875em';
    [['Nie masz telefonu? Użyj kodu zapasowego', function () {
      if (et) et.textContent = 'Kod zapasowy';
      pole.setAttribute('aria-label', 'Kod zapasowy');
      pole.setAttribute('inputmode', 'text'); pole.setAttribute('autocomplete', 'off'); pole.setAttribute('placeholder', et ? 'xxxx-xxxx' : 'Kod zapasowy (xxxx-xxxx)'); pole.value = ''; pole.focus();
      this.remove();
    }], ['← Wróć', function () { wroc(f); var m = f.querySelector('.message'); if (m) m.remove(); }]].forEach(function (p) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = p[0];
      b.style.cssText = 'background:none;border:0;padding:0;min-height:24px;color:inherit;text-decoration:underline;cursor:pointer;font:inherit';
      b.addEventListener('click', p[1]);
      nawig.appendChild(b);
    });
    po.parentNode.insertBefore(nawig, po.nextSibling);
    f.dataset.evk2fa = '1';
    /* Bricks dokłada swój komunikat („Wpisz kod…”) zaraz po tym zdarzeniu — w kroku kodu niepotrzebny. */
    setTimeout(function () { var m = f.querySelector('.message'); if (m) m.remove(); }, 0);
    pole.focus();
  }
  document.addEventListener('bricks/form/error', function (e) {
    var det = e.detail || {}, res = det.res || {}, d = res.data && res.data.evk2fa, f = formularz(det.elementId);
    if (!d || !f) return;
    if (d.wygasl) { wroc(f); return; }
    if (d.blad) {
      var p = f.querySelector('input[name="evk_2fa_kod"]');
      if (p) { p.value = ''; p.focus(); }
      return;
    }
    if (f.dataset.evk2fa !== '1' && d.token) krokKodu(f, d);
  });
})();
