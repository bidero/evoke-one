/**
 * Sprawdzanie tłumaczeń na stronie (1.261.0) — obrysy elementów, okienko
 * z polskim oryginałem i polem języka, zapis przez AJAX.
 *
 * Dane: <script type="application/json" id="evk-tl-sprawdz-dane">
 * (includes/62-translation-review-front.php). Element w DOM po id z danych
 * (`brxe-{id}` albo własne CSS ID); w pętli każde wystąpienie.
 *
 * Decyzje zgłaszającego (29–30.09):
 *  - okienko przy klikniętym elemencie, nie pisanie w tekście;
 *  - obrys „Do sprawdzenia" i „Brak tłumaczenia", klikać da się każdy tekst;
 *  - „Zapisz" oznacza cały element jako sprawdzony — okienko pokazuje
 *    wszystkie jego teksty;
 *  - po zapisie okienko zostaje przy elemencie, a fokus idzie na „Następne".
 *
 * Po zapisie skrypt pobiera stronę jeszcze raz i podmienia węzeł elementu.
 * Element z własnym skryptem Bricksa (akordeon, zakładki, slider…) straciłby
 * obsługę, więc wtedy — i gdy węzła nie ma — strona przeładowuje się i wraca
 * w to samo miejsce z otwartym okienkiem.
 */
(function () {
  'use strict';

  const daneEl = document.getElementById('evk-tl-sprawdz-dane');
  if (!daneEl || window.__evkTlSprawdz) return;
  let DANE;
  try { DANE = JSON.parse(daneEl.textContent || '{}'); } catch (e) { return; }

  const JEZYK = String(DANE.jezyk || '').toUpperCase();
  const OPISY = {
    ai: 'AI — do sprawdzenia', zmiana: 'Zmienił się polski tekst', ok: 'Przetłumaczone',
    slownik: 'Ze słownika', czesc: 'Częściowo ze słownika', brak: 'Brak tłumaczenia',
  };
  const POWROT = 'evkTlSprawdzPowrot';

  /* Stan widoczny także dla testu (tylko do odczytu). */
  const stan = { elementy: DANE.elementy || {}, otwarty: null, wezly: new Map(), liczby: {} };
  window.__evkTlSprawdz = stan;

  // ── Pomocnicze ─────────────────────────────────────────────────────────
  function el(tag, atrybuty, dzieci) {
    const e = document.createElement(tag);
    Object.keys(atrybuty || {}).forEach((k) => {
      if (atrybuty[k] === false || atrybuty[k] === null || atrybuty[k] === undefined) return;
      e.setAttribute(k, atrybuty[k] === true ? '' : String(atrybuty[k]));
    });
    [].concat(dzieci === undefined ? [] : dzieci).forEach((d) => {
      e.appendChild(typeof d === 'string' ? document.createTextNode(d) : d);
    });
    return e;
  }

  /* Tekst z HTML-u bez wykonywania czegokolwiek (DOMParser — dokument obojętny:
     bez skryptów i bez wczytywania obrazków). Koniec bloku daje odstęp. */
  function tekstZHtml(html) {
    const d = new DOMParser().parseFromString('<body>' + String(html || '').replace(/<\/(p|div|li|h[1-6])>|<br\s*\/?>/gi, '$& '), 'text/html');
    return (d.body.textContent || '').replace(/\s+/g, ' ').trim();
  }

  /* Węzły elementu w dokumencie: id z danych, w pętli także `brxe-{id}-…`,
     a gdy id nie ma — klasa `brxe-{id}`. Bez interfejsu tego skryptu. */
  function wezlyW(korzen, e) {
    const out = [];
    const dodaj = (n) => { if (n && out.indexOf(n) === -1 && !n.closest('.evk-tls-ui')) out.push(n); };
    try {
      korzen.querySelectorAll('[id="' + CSS.escape(e.dom) + '"]').forEach(dodaj);
      if (e.dom === 'brxe-' + e.id) korzen.querySelectorAll('[id^="' + CSS.escape(e.dom) + '-"]').forEach(dodaj);
      if (!out.length) korzen.querySelectorAll('.brxe-' + CSS.escape(e.id)).forEach(dodaj);
    } catch (err) { /* nieprawidłowy identyfikator — element pominięty */ }
    return out;
  }

  function kategoria(e) {
    if (e.uwaga) return 'poza';
    const s = (e.pola || []).map((p) => p.stan);
    if (s.some((x) => x === 'ai' || x === 'zmiana')) return 'sprawdz';
    if (s.some((x) => x === 'brak' || x === 'czesc')) return 'brak';
    return 'ok';
  }

  // ── Obrysy i pasek ─────────────────────────────────────────────────────
  const pasek = el('div', { class: 'evk-tls-ui evk-tls-pasek', role: 'region', 'aria-label': 'Sprawdzanie tłumaczeń ' + JEZYK });
  const licz = {};
  function licznik(k, napis) {
    licz[k] = el('span', { class: 'evk-tls-licznik', 'data-k': k });
    licz[k].dataset.napis = napis;
    return licz[k];
  }
  pasek.appendChild(el('strong', { class: 'evk-tls-nazwa' }, 'Sprawdzanie · ' + JEZYK));
  pasek.appendChild(licznik('sprawdz', 'Do sprawdzenia'));
  pasek.appendChild(licznik('brak', 'Brak tłumaczenia'));
  pasek.appendChild(licznik('poza', 'Tylko w builderze'));
  pasek.appendChild(licznik('niema', 'Nie znaleziono na stronie'));
  const pasekNastepne = el('button', { type: 'button', class: 'evk-tls-przycisk evk-tls-pasek-nastepne' }, 'Następne');
  pasek.appendChild(pasekNastepne);
  const wszystkie = el('input', { type: 'checkbox', id: 'evk-tls-wszystkie', class: 'evk-tls-wszystkie-pole' });
  pasek.appendChild(el('label', { for: 'evk-tls-wszystkie', class: 'evk-tls-wybor' }, [wszystkie, ' Obrys wszystkich tekstów']));
  const nastepnaStrona = el('a', { class: 'evk-tls-przycisk evk-tls-strona', href: DANE.nastepna || '#' }, 'Następna strona do sprawdzenia ›');
  if (!DANE.nastepna) nastepnaStrona.hidden = true;
  pasek.appendChild(nastepnaStrona);
  pasek.appendChild(el('a', { class: 'evk-tls-przycisk evk-tls-koniec', href: DANE.koniec || '?' }, 'Zakończ'));
  const pasekStan = el('span', { class: 'evk-tls-pasek-stan', role: 'status' });
  pasek.appendChild(pasekStan);

  function ozdob() {
    const liczby = { sprawdz: 0, brak: 0, poza: 0, niema: 0 };
    stan.wezly = new Map();
    Object.keys(stan.elementy).forEach((id) => {
      const e = stan.elementy[id];
      const wezly = wezlyW(document, e);
      stan.wezly.set(id, wezly);
      const k = kategoria(e);
      if (!wezly.length) { liczby.niema++; return; }
      if (k in liczby) liczby[k]++;
      wezly.forEach((w) => {
        w.classList.add('evk-tls-el');
        w.setAttribute('data-evk-tls', k);
        w.setAttribute('data-evk-tls-id', id);
        if (!w.hasAttribute('tabindex') && !w.matches('a[href], button, input, select, textarea')) {
          w.setAttribute('tabindex', '0');
          w.setAttribute('data-evk-tls-tab', '');
        }
      });
    });
    stan.liczby = liczby;
    Object.keys(licz).forEach((k) => {
      licz[k].textContent = licz[k].dataset.napis + ': ' + (liczby[k] || 0);
      licz[k].hidden = (k === 'poza' || k === 'niema') && !liczby[k];
    });
    if (DANE.nastepna) { nastepnaStrona.href = DANE.nastepna; nastepnaStrona.hidden = false; } else nastepnaStrona.hidden = true;
  }

  // ── Okienko ────────────────────────────────────────────────────────────
  const okno = el('div', { class: 'evk-tls-ui evk-tls-okno', role: 'dialog', 'aria-labelledby': 'evk-tls-tytul', hidden: true });
  let oknoStan = null;

  function wiersze(p) {
    return Math.min(8, Math.max(2, Math.ceil(Math.max(String(p.tl || '').length, String(p.pl || '').length) / 55)));
  }

  function rysujOkno(e, ile) {
    okno.textContent = '';
    const glowa = el('div', { class: 'evk-tls-glowa' });
    glowa.appendChild(el('h2', { id: 'evk-tls-tytul', class: 'evk-tls-tytul' }, e.etykieta + ' · ' + JEZYK));
    glowa.appendChild(el('button', { type: 'button', class: 'evk-tls-przycisk evk-tls-zamknij', 'aria-label': 'Zamknij' }, '×'));
    okno.appendChild(glowa);
    if (e.tytul) okno.appendChild(el('p', { class: 'evk-tls-skad' }, e.czesc + ': ' + e.tytul));

    if (e.uwaga) {
      okno.appendChild(el('p', { class: 'evk-tls-uwaga evk-tls-uwaga-mocna' }, e.uwaga === 'wiele'
        ? 'Ten element jest w kilku miejscach (np. na skopiowanej stronie) — popraw go w builderze.'
        : 'Ten element nie leży w danych strony (element globalny albo komponent) — popraw go w builderze.'));
    } else {
      if (ile > 1) okno.appendChild(el('p', { class: 'evk-tls-uwaga' }, 'Ten element występuje na stronie ' + ile + ' razy (np. w pętli) — zmiana dotyczy każdego wystąpienia.'));
      if (!e.edycja) okno.appendChild(el('p', { class: 'evk-tls-uwaga evk-tls-uwaga-mocna' }, 'Nie możesz edytować tej strony — tylko podgląd.'));
      const lista = el('div', { class: 'evk-tls-pola' });
      (e.pola || []).forEach((p, i) => {
        const idp = 'evk-tls-pole-' + i;
        const pole = el('div', { class: 'evk-tls-pole', 'data-stan': p.stan });
        pole.appendChild(el('div', { class: 'evk-tls-pole-glowa' }, [
          el('label', { for: idp, class: 'evk-tls-etykieta' }, p.etykieta + ' — ' + JEZYK),
          el('span', { class: 'evk-tls-znak', 'data-stan': p.stan }, OPISY[p.stan] || p.stan),
        ]));
        pole.appendChild(el('p', { class: 'evk-tls-pl' }, [el('span', { class: 'evk-tls-pl-kod' }, 'PL'), ' ' + tekstZHtml(p.pl)]));
        const t = el('textarea', { id: idp, rows: wiersze(p), 'data-sciezka': p.sciezka, spellcheck: 'true', lang: DANE.jezyk || false });
        t.value = p.tl || '';
        t.defaultValue = p.tl || '';
        if (!e.edycja) t.readOnly = true;
        pole.appendChild(t);
        if ((p.stan === 'slownik' || p.stan === 'czesc') && p.slownik) {
          pole.appendChild(el('p', { class: 'evk-tls-uwaga' }, 'Na stronie ze słownika: „' + tekstZHtml(p.slownik) + '”. Tłumaczenie wpisane tutaj ma pierwszeństwo.'));
        }
        if (/<[a-z]/i.test(p.pl || '')) pole.appendChild(el('p', { class: 'evk-tls-uwaga' }, 'Znaczniki HTML zachowaj jak w oryginale.'));
        lista.appendChild(pole);
      });
      okno.appendChild(lista);
    }

    const stopka = el('div', { class: 'evk-tls-stopka' });
    if (!e.uwaga && e.edycja) {
      stopka.appendChild(el('button', { type: 'button', class: 'evk-tls-przycisk evk-tls-glowny evk-tls-zapisz' }, 'Zapisz'));
      if ((e.pola || []).some((p) => p.stan === 'ai' || p.stan === 'zmiana')) {
        stopka.appendChild(el('button', { type: 'button', class: 'evk-tls-przycisk evk-tls-sprawdzone' }, 'Sprawdzone'));
      }
    }
    stopka.appendChild(el('button', { type: 'button', class: 'evk-tls-przycisk evk-tls-nastepne' }, 'Następne ›'));
    okno.appendChild(stopka);
    if (!e.uwaga && e.edycja) okno.appendChild(el('p', { class: 'evk-tls-podpowiedz' }, 'Zapis oznacza teksty tego elementu jako sprawdzone. Ctrl+Enter — zapisz, Esc — zamknij.'));
    oknoStan = el('p', { class: 'evk-tls-stan', role: 'status' });
    okno.appendChild(oknoStan);
  }

  function ustawStan(tekst, blad) {
    if (!oknoStan) return;
    oknoStan.textContent = tekst;
    oknoStan.classList.toggle('evk-tls-blad', !!blad);
  }

  function pozycja(cel) {
    if (okno.hidden) return;
    if (window.matchMedia('(max-width: 600px)').matches || !cel || !cel.isConnected) {
      okno.classList.add('evk-tls-arkusz');
      okno.style.top = '';
      okno.style.left = '';
      return;
    }
    okno.classList.remove('evk-tls-arkusz');
    const szer = Math.min(440, window.innerWidth - 32);
    okno.style.width = szer + 'px';
    const r = cel.getBoundingClientRect();
    const h = okno.offsetHeight;
    const dol = window.innerHeight - (pasek.offsetHeight || 0) - 10;
    let top = r.bottom + 10;
    if (top + h > dol && r.top - h - 10 >= 10) top = r.top - h - 10;
    top = Math.max(10, Math.min(top, dol - h));
    okno.style.top = top + 'px';
    okno.style.left = Math.max(16, Math.min(r.left, window.innerWidth - szer - 16)) + 'px';
  }

  function otworz(id, wezel) {
    const e = stan.elementy[id];
    if (!e) return;
    stan.otwarty = id;
    const wezly = stan.wezly.get(id) || [];
    const cel = wezel && wezel.isConnected ? wezel : wezly[0];
    document.querySelectorAll('.evk-tls-aktywny').forEach((n) => n.classList.remove('evk-tls-aktywny'));
    wezly.forEach((n) => n.classList.add('evk-tls-aktywny'));
    rysujOkno(e, wezly.length);
    okno.hidden = false;
    if (cel) cel.scrollIntoView({ block: 'nearest' });
    pozycja(cel);
    const pierwsze = okno.querySelector('textarea:not([readonly])') || okno.querySelector('.evk-tls-nastepne');
    if (pierwsze) pierwsze.focus({ preventScroll: true });
  }

  function zamknij() {
    const id = stan.otwarty;
    okno.hidden = true;
    stan.otwarty = null;
    document.querySelectorAll('.evk-tls-aktywny').forEach((n) => n.classList.remove('evk-tls-aktywny'));
    const w = id ? (stan.wezly.get(id) || [])[0] : null;
    if (w && w.isConnected) w.focus({ preventScroll: true });
  }

  /* Kolejka „Następne": najpierw do sprawdzenia, a gdy ich nie ma — braki;
     po kolei w dokumencie, w kółko. */
  function nastepny() {
    let kolejka = Array.from(document.querySelectorAll('.evk-tls-el[data-evk-tls="sprawdz"]'));
    if (!kolejka.length) kolejka = Array.from(document.querySelectorAll('.evk-tls-el[data-evk-tls="brak"]'));
    const inne = kolejka.filter((n) => n.getAttribute('data-evk-tls-id') !== stan.otwarty);
    if (!inne.length) {
      const napis = 'Na tej stronie nie ma już nic do sprawdzenia.';
      if (!okno.hidden) ustawStan(napis); else pasekStan.textContent = napis;
      return;
    }
    const biez = stan.otwarty ? (stan.wezly.get(stan.otwarty) || [])[0] : null;
    const cel = (biez && inne.find((n) => biez.compareDocumentPosition(n) & Node.DOCUMENT_POSITION_FOLLOWING)) || inne[0];
    cel.scrollIntoView({ block: 'center' });
    otworz(cel.getAttribute('data-evk-tls-id'), cel);
  }

  // ── Zapis i odświeżenie ────────────────────────────────────────────────
  function zapamietaj(id) {
    try {
      sessionStorage.setItem(POWROT, JSON.stringify({ id: id, y: window.scrollY, adres: location.pathname + location.search }));
    } catch (e) { /* bez pamięci sesji — strona wróci na górę */ }
  }

  async function odswiez(id) {
    let html;
    try { html = await (await fetch(location.href, { credentials: 'same-origin' })).text(); } catch (e) { return false; }
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const dane = doc.getElementById('evk-tl-sprawdz-dane');
    if (dane) {
      try {
        const d = JSON.parse(dane.textContent || '{}');
        if (d.elementy) stan.elementy = d.elementy;
        DANE.nastepna = d.nastepna || '';
      } catch (e) { /* stare dane zostają */ }
    }
    const e = stan.elementy[id];
    const stare = (stan.wezly.get(id) || []).filter((n) => n.isConnected);
    const nowe = e ? wezlyW(doc, e) : [];
    if (!stare.length || nowe.length !== stare.length) return false;
    stare.forEach((w, i) => w.replaceWith(document.importNode(nowe[i], true)));
    return true;
  }

  function blokuj(tak) {
    okno.querySelectorAll('button, textarea').forEach((b) => { if (b.tagName === 'BUTTON') b.disabled = tak; });
  }

  async function zapisz(tylkoSprawdzone) {
    const id = stan.otwarty;
    const e = id ? stan.elementy[id] : null;
    if (!e || e.uwaga || !e.edycja) return;
    const fd = new FormData();
    fd.append('action', 'evk_tl_sprawdz_zapisz');
    fd.append('nonce', DANE.nonce || '');
    fd.append('post_id', e.post);
    fd.append('meta_key', e.meta);
    fd.append('lang', DANE.jezyk || '');
    fd.append('element', id);
    if (!tylkoSprawdzone) {
      okno.querySelectorAll('textarea[data-sciezka]').forEach((t) => {
        if (t.value !== t.defaultValue) fd.append('pola[' + t.getAttribute('data-sciezka') + ']', t.value);
      });
    }
    ustawStan('Zapisuję…');
    blokuj(true);
    let r = null;
    try { r = await (await fetch(DANE.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (err) { r = null; }
    blokuj(false);
    if (!r || !r.success) { ustawStan((r && r.data) || 'Błąd zapisu — spróbuj jeszcze raz.', true); return; }
    e.pola = (r.data && r.data.pola) || e.pola;
    if (r.data && r.data.zmienione > 0) {
      if (e.przeladuj || !(await odswiez(id))) {
        zapamietaj(id);
        location.reload();
        return;
      }
    }
    ozdob();
    const wezly = stan.wezly.get(id) || [];
    otworz(id, wezly[0]);
    ustawStan('Zapisano.');
    const dalej = okno.querySelector('.evk-tls-nastepne');
    if (dalej) dalej.focus({ preventScroll: true });
  }

  // ── Zdarzenia ──────────────────────────────────────────────────────────
  okno.addEventListener('click', (ev) => {
    const b = ev.target instanceof Element ? ev.target.closest('button') : null;
    if (!b || b.disabled) return;
    if (b.classList.contains('evk-tls-zamknij')) zamknij();
    else if (b.classList.contains('evk-tls-zapisz')) zapisz(false);
    else if (b.classList.contains('evk-tls-sprawdzone')) zapisz(true);
    else if (b.classList.contains('evk-tls-nastepne')) nastepny();
  });
  pasekNastepne.addEventListener('click', nastepny);
  wszystkie.addEventListener('change', () => document.body.classList.toggle('evk-tls-wszystkie', wszystkie.checked));

  /* Klik w element strony otwiera okienko zamiast odnośnika, przycisku czy
     rozwinięcia akordeonu — w fazie przechwytywania, zanim zobaczy go Bricks. */
  document.addEventListener('click', (ev) => {
    const t = ev.target;
    if (!(t instanceof Element) || t.closest('.evk-tls-ui, #wpadminbar')) return;
    const w = t.closest('.evk-tls-el');
    if (!w) return;
    ev.preventDefault();
    ev.stopPropagation();
    otworz(w.getAttribute('data-evk-tls-id'), w);
  }, true);

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && !okno.hidden) { ev.preventDefault(); zamknij(); return; }
    if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey) && !okno.hidden && okno.contains(document.activeElement)) {
      ev.preventDefault();
      zapisz(false);
      return;
    }
    const t = ev.target;
    if ((ev.key === 'Enter' || ev.key === ' ') && t instanceof Element && t.classList.contains('evk-tls-el') && !t.closest('.evk-tls-ui')) {
      ev.preventDefault();
      otworz(t.getAttribute('data-evk-tls-id'), t);
    }
  }, true);

  let klatka = 0;
  const przelicz = () => {
    if (klatka || okno.hidden) return;
    klatka = requestAnimationFrame(() => {
      klatka = 0;
      const w = stan.otwarty ? (stan.wezly.get(stan.otwarty) || [])[0] : null;
      pozycja(w);
    });
  };
  window.addEventListener('resize', przelicz);
  window.addEventListener('scroll', przelicz, { passive: true });

  // ── Start ──────────────────────────────────────────────────────────────
  function start() {
    document.body.classList.add('evk-tls-tryb');
    document.body.appendChild(pasek);
    document.body.appendChild(okno);
    ozdob();
    let powrot = null;
    try {
      powrot = JSON.parse(sessionStorage.getItem(POWROT) || 'null');
      sessionStorage.removeItem(POWROT);
    } catch (e) { powrot = null; }
    if (powrot && powrot.adres === location.pathname + location.search && stan.elementy[powrot.id]) {
      window.scrollTo(0, powrot.y || 0);
      otworz(powrot.id);
      ustawStan('Zapisano.');
      const dalej = okno.querySelector('.evk-tls-nastepne');
      if (dalej) dalej.focus({ preventScroll: true });
      return;
    }
    /* Odnośnik „Na stronie" z listy „Do sprawdzenia" w panelu: #evk-tls={id}. */
    const kotwica = /^#evk-tls=(.+)$/.exec(location.hash);
    let id = '';
    try { id = kotwica ? decodeURIComponent(kotwica[1]) : ''; } catch (e) { id = ''; }
    const w = id && stan.elementy[id] ? (stan.wezly.get(id) || [])[0] : null;
    if (w) {
      w.scrollIntoView({ block: 'center' });
      otworz(id, w);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
