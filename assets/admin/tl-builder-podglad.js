/**
 * Podgląd tłumaczeń w builderze Bricksa (1.257.0, #82).
 *
 * Działa w KANWIE (ramka `?bricks=run&brickspreview=true`). Przełącznik
 * PL | EN | DE wstawia do paska powłoki obok breakpointów — powłoka to
 * `window.parent`, to samo pochodzenie. Kanwa pokazuje teksty z pól
 * „Tłumaczenie EN" na żywo; tekst bez tłumaczenia zostaje polski z obrysem
 * i znaczkiem „brak EN". Obrazy i SVG z pól „Obraz EN"/„SVG EN" podmieniane
 * bez obrysu (decyzja zgłaszającego: obraz bywa wspólny dla języków).
 *
 * Trzy fakty z prób na testowej (Bricks 2.4.2), na których to stoi:
 *  - stan elementów to `__vue_app__.config.globalProperties.$_state`
 *    (`content`/`header`/`footer`), osobny obiekt w powłoce i w kanwie.
 *    Pola panelu zmieniają najpierw stan POWŁOKI, więc stąd czytamy
 *    wartości, a kanwę tylko jako zapas;
 *  - DOM nagłówka to `settings.text` jako HTML;
 *  - Bricks przepisuje tekst z kanwy do ustawień PRZY KAŻDYM ZNAKU (w próbie
 *    48 ms po literze). Dlatego element, w który się klika albo który dostaje
 *    fokus, wraca do polskiego, ZANIM Bricks go zobaczy (faza przechwytywania),
 *    i dopiero po wyjściu z edycji znów pokazuje tłumaczenie. Element
 *    z `contenteditable=true` nie jest nigdy ruszany.
 *
 * Po przeładowaniu buildera start zawsze z PL (decyzja zgłaszającego). Wybór
 * żyje w oknie powłoki, więc przeżywa tylko przeładowanie samej kanwy.
 *
 * Skrypt przejmuje też rozwijanie `{tl_…}` i `[tl key=…]` w kanwie (do 1.256.0
 * robił to osobny skrypt w 40-dynamic-data-shortcode.php): dwa obserwatory na
 * tych samych węzłach budziłyby się nawzajem.
 */
(function () {
  'use strict';

  const daneEl = document.getElementById('evk-tl-podglad-dane');
  if (!daneEl || window.__evkTlPodglad) return;
  let DANE;
  try { DANE = JSON.parse(daneEl.textContent || '{}'); } catch (e) { return; }

  const JEZYKI = Array.isArray(DANE.jezyki) && DANE.jezyki.length ? DANE.jezyki : ['pl'];
  const MAPA = DANE.mapa || {};
  const SLOWNIK = DANE.slownik || {};
  const NAPISY = Object.assign({ brak: 'brak %s', grupa: 'Podgląd języka', przycisk: 'Podgląd: %s' }, DANE.napisy || {});

  let powloka = null;
  try { powloka = window.parent && window.parent !== window && window.parent.document ? window.parent : null; } catch (e) { powloka = null; }

  let tryb = 'pl';
  if (powloka && typeof powloka.__evkTlTryb === 'string' && JEZYKI.indexOf(powloka.__evkTlTryb) !== -1) tryb = powloka.__evkTlTryb;

  /* Licznik przebiegów — test pilnuje, że podgląd sam siebie nie budzi. */
  const licznik = { przebiegi: 0 };
  window.__evkTlPodglad = licznik;

  // ── Stan Bricksa ────────────────────────────────────────────────────────
  function stanZ(doc, selektor) {
    try {
      const el = doc && doc.querySelector(selektor);
      const app = el && el.__vue_app__;
      return (app && app.config && app.config.globalProperties && app.config.globalProperties.$_state) || null;
    } catch (e) { return null; }
  }
  function stany() {
    const w = [];
    const p = powloka ? stanZ(powloka.document, '.brx-body.main') : null;
    if (p) w.push(p);
    const k = stanZ(document, '.brx-body.iframe');
    if (k && k !== p) w.push(k);
    return w;
  }
  /** id → element; stan powłoki wygrywa (tam najpierw trafia pisanie w panelu). */
  function indeks() {
    const mapa = new Map();
    stany().reverse().forEach((st) => {
      ['content', 'header', 'footer'].forEach((obszar) => {
        const tab = st[obszar];
        if (!Array.isArray(tab)) return;
        tab.forEach((el) => { if (el && el.id) mapa.set(String(el.id), el); });
      });
    });
    return mapa;
  }

  // ── Wartości ────────────────────────────────────────────────────────────
  const przedrostek = (j) => 'evk_tl_' + String(j).toLowerCase().replace(/[^a-z0-9_]/g, '_') + '__';
  /* Jak evk_tl_el_niepuste(): edytor zostawia po wyczyszczeniu `<p></p>` albo `&nbsp;`. */
  const niepusty = (v) => typeof v === 'string' && v.replace(/<[^>]*>/g, '').replace(/&nbsp;|\u00a0/gi, ' ').trim() !== '';
  const zeSlownika = (v) => /\{tl_[a-z0-9_]+\}|\[tl\s+key=|\{tl:/i.test(v);
  const dynamiczny = (v) => /\{[^{}\s]+\}/.test(v);
  const obrazNiepusty = (v) => !!v && typeof v === 'object' && !Array.isArray(v)
    && ((parseInt(v.id, 10) || 0) > 0 || (typeof v.url === 'string' && v.url.trim() !== ''));

  const szablon = document.createElement('template');
  const normy = new Map();
  function norm(html) {
    if (normy.has(html)) return normy.get(html);
    szablon.innerHTML = html;
    const w = szablon.innerHTML.replace(/\s+/g, ' ').trim();
    if (normy.size > 2000) normy.clear();
    normy.set(html, w);
    return w;
  }
  function tekstZHtml(html) {
    szablon.innerHTML = html;
    return szablon.content.textContent || '';
  }

  // ── Zmiany w DOM i ich cofanie ──────────────────────────────────────────
  /* Rekord: { root, wezel, rodzaj: 'html'|'tekst'|'atrybuty'|'svg', przed, po }.
     Cofamy tylko to, co wciąż jest nasze (Vue mogło w międzyczasie przerysować). */
  let zmiany = [];

  function cofnij(filtr) {
    const zostaja = [];
    zmiany.forEach((z) => {
      if (filtr && !filtr(z)) { zostaja.push(z); return; }
      if (!z.wezel.isConnected) return;
      if (z.rodzaj === 'html' && z.wezel.innerHTML === z.po) z.wezel.innerHTML = z.przed;
      else if (z.rodzaj === 'tekst' && z.wezel.nodeValue === z.po) z.wezel.nodeValue = z.przed;
      else if (z.rodzaj === 'atrybuty' && z.wezel.getAttribute('src') === z.po) {
        Object.keys(z.przed).forEach((a) => {
          if (z.przed[a] === null) z.wezel.removeAttribute(a); else z.wezel.setAttribute(a, z.przed[a]);
        });
      } else if (z.rodzaj === 'svg' && z.wezel.innerHTML === z.po) {
        z.wezel.innerHTML = z.przed.html;
        if (z.przed.viewBox === null) z.wezel.removeAttribute('viewBox'); else z.wezel.setAttribute('viewBox', z.przed.viewBox);
      }
    });
    zmiany = zostaja;
  }

  /** Węzły elementu `root` — bez korzeni potomnych (każdy element pilnuje swoich). */
  function wlasne(root) {
    const w = [root];
    root.querySelectorAll('*').forEach((n) => { if (n.closest('[data-id]') === root) w.push(n); });
    return w;
  }
  function tekstyWlasne(root) {
    const w = [];
    const iter = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let n;
    while ((n = iter.nextNode())) {
      if (n.parentElement && n.parentElement.closest('[data-id]') === root) w.push(n);
    }
    return w;
  }

  function znajdz(root, wezly, pl, uzyte) {
    const cel = norm(pl);
    for (const w of wezly) {
      if (uzyte.has(w)) continue;
      if (norm(w.innerHTML) === cel) return { rodzaj: 'html', wezel: w };
    }
    if (/[<>]/.test(pl)) return null;
    const tekst = tekstZHtml(pl).trim();
    if (!tekst) return null;
    for (const n of tekstyWlasne(root)) {
      if (!uzyte.has(n) && n.nodeValue.trim() === tekst) return { rodzaj: 'tekst', wezel: n };
    }
    return null;
  }

  /** 'ok' — przetłumaczone, 'brak' — widoczny polski bez tłumaczenia, '' — nic do zrobienia. */
  function zastosuj(root, wezly, pl, obcy, uzyte) {
    if (!niepusty(pl) || zeSlownika(pl) || dynamiczny(pl)) return '';
    const cel = znajdz(root, wezly, pl, uzyte);
    if (!cel) return '';
    uzyte.add(cel.wezel);
    if (!niepusty(obcy)) return 'brak';
    if (cel.rodzaj === 'html') {
      const przed = cel.wezel.innerHTML;
      cel.wezel.innerHTML = obcy;
      zmiany.push({ root, wezel: cel.wezel, rodzaj: 'html', przed, po: cel.wezel.innerHTML });
    } else {
      const przed = cel.wezel.nodeValue;
      const lead = (przed.match(/^\s*/) || [''])[0], tail = (przed.match(/\s*$/) || [''])[0];
      cel.wezel.nodeValue = lead + tekstZHtml(obcy).trim() + tail;
      zmiany.push({ root, wezel: cel.wezel, rodzaj: 'tekst', przed, po: cel.wezel.nodeValue });
    }
    return 'ok';
  }

  /* Ścieżka pliku bez hosta, rozmiaru i rozszerzenia: obraz-1024x683.jpg ≈ obraz.jpg,
     także gdy kanwa podaje adres względny albo z innego hosta (CDN z tą samą ścieżką). */
  function rdzen(url) {
    let sciezka = String(url || '');
    if (!sciezka) return '';
    try { sciezka = new URL(sciezka, document.baseURI).pathname; } catch (e) { sciezka = sciezka.split(/[?#]/)[0]; }
    return sciezka.replace(/-\d+x\d+(?=\.[a-z0-9]+$)/i, '').replace(/\.[a-z0-9]+$/i, '');
  }

  /* Obrazy elementu i pozycji jego list (slajdy, karuzela) — jak podmiana na stronie. */
  function obrazy(root, s) {
    obrazyZ(root, s);
    Object.keys(s).forEach((k) => {
      if (Array.isArray(s[k])) s[k].forEach((poz) => { if (poz && typeof poz === 'object' && !Array.isArray(poz)) obrazyZ(root, poz); });
    });
  }

  function obrazyZ(root, s) {
    const pre = przedrostek(tryb);
    Object.keys(s).forEach((k) => {
      if (k.indexOf(pre) !== 0) return;
      const zrodlo = k.slice(pre.length), v = s[k], pl = s[zrodlo];
      if (!obrazNiepusty(v) || !pl || typeof pl !== 'object' || Array.isArray(pl) || typeof v.url !== 'string' || !v.url) return;
      const plRdzen = rdzen(pl.url);
      if (!plRdzen) return;
      /* Najpierw <img> o tym samym pliku (także SVG w elemencie Image), a bez
         trafienia — SVG wstawione w treść (element SVG). */
      let trafione = false;
      wlasne(root).forEach((w) => {
        if (w.tagName !== 'IMG' || rdzen(w.getAttribute('src')) !== plRdzen) return;
        trafione = true;
        const przed = { src: w.getAttribute('src'), srcset: w.getAttribute('srcset'), sizes: w.getAttribute('sizes') };
        w.removeAttribute('srcset');
        w.removeAttribute('sizes');
        w.setAttribute('src', v.url);
        zmiany.push({ root, wezel: w, rodzaj: 'atrybuty', przed, po: w.getAttribute('src') });
      });
      if (!trafione && String(v.url).split(/[?#]/)[0].toLowerCase().endsWith('.svg')) svg(root, v.url);
    });
  }

  /* SVG wstawiane w treść: plik języka pobrany raz, oczyszczony (bez skryptów,
     obcych obiektów i atrybutów zdarzeń), wstawiony w miejsce polskiego. */
  const svgi = new Map();
  function czysteSvg(tekst) {
    const doc = new DOMParser().parseFromString(tekst, 'image/svg+xml');
    const el = doc.documentElement;
    if (!el || el.nodeName.toLowerCase() !== 'svg') return null;
    el.querySelectorAll('script, foreignObject').forEach((n) => n.remove());
    [el].concat(Array.from(el.querySelectorAll('*'))).forEach((n) => {
      Array.from(n.attributes).forEach((a) => {
        if (/^on/i.test(a.name) || (/href$/i.test(a.name) && /^\s*javascript:/i.test(a.value))) n.removeAttribute(a.name);
      });
    });
    return { html: el.innerHTML, viewBox: el.getAttribute('viewBox') };
  }
  function svg(root, url) {
    const cel = root.tagName.toLowerCase() === 'svg' ? root : wlasne(root).find((w) => w.tagName.toLowerCase() === 'svg');
    if (!cel) return;
    const gotowe = svgi.get(url);
    if (gotowe === undefined) {
      svgi.set(url, null);
      fetch(url, { credentials: 'same-origin' }).then((r) => (r.ok ? r.text() : '')).then((t) => {
        svgi.set(url, t ? czysteSvg(t) : false);
        zaplanuj();
      }).catch(() => svgi.set(url, false));
      return;
    }
    if (!gotowe) return;
    const przed = { html: cel.innerHTML, viewBox: cel.getAttribute('viewBox') };
    cel.innerHTML = gotowe.html;
    if (gotowe.viewBox) cel.setAttribute('viewBox', gotowe.viewBox);
    zmiany.push({ root, wezel: cel, rodzaj: 'svg', przed, po: cel.innerHTML });
  }

  // ── Słownik: {tl_…} i [tl key=…] ────────────────────────────────────────
  const tlOryginaly = new WeakMap();
  function zSlownika(tekst, jezyk) {
    const wartosc = (m, k) => {
      const w = SLOWNIK[String(k).toLowerCase()];
      if (!w) return m;
      return (jezyk !== 'pl' && w[jezyk]) || w.pl || m;
    };
    return tekst.replace(/\{tl_([a-z0-9_]+)\}/gi, wartosc).replace(/\[tl\s+key=["']?([a-z0-9_]+)["']?[^\]]*\]/gi, wartosc);
  }
  function slownik(korzen) {
    const iter = document.createTreeWalker(korzen || document.body, NodeFilter.SHOW_TEXT, {
      acceptNode(n) {
        const p = n.parentElement;
        if (!p || p.closest('script, style, textarea, input, #evk-tl-nakladka')) return NodeFilter.FILTER_REJECT;
        return NodeFilter.FILTER_ACCEPT;
      },
    });
    const wezly = [];
    let n;
    while ((n = iter.nextNode())) wezly.push(n);
    wezly.forEach((w) => {
      const rek = tlOryginaly.get(w);
      const zrodlo = rek && w.nodeValue === rek.po ? rek.przed : w.nodeValue;
      if (!/\{tl_|\[tl/i.test(zrodlo)) return;
      const root = w.parentElement.closest('[data-id]');
      /* Tekst w trakcie pisania zostaje: Bricks zapisuje z kanwy każdy znak, więc
         podmiana tagu wpisanego ręcznie trafiłaby do ustawień zamiast tagu. */
      if (root && root.isContentEditable) return;
      /* Edytowany element: polski, jak do 1.256.0 — Bricks zapisze to, co widać. */
      const jezyk = root && root === edytowany ? 'pl' : tryb;
      const nowy = zSlownika(zrodlo, jezyk);
      if (w.nodeValue !== nowy) w.nodeValue = nowy;
      tlOryginaly.set(w, { przed: zrodlo, po: nowy });
    });
  }

  // ── Przebieg ────────────────────────────────────────────────────────────
  let edytowany = null;
  const braki = new Set();

  function wEdycji(root) {
    return root === edytowany || root.isContentEditable || !!root.querySelector('[contenteditable="true"]');
  }

  function tlumaczRoot(root, el) {
    const s = (el && el.settings) || {};
    const def = MAPA[el.name] || null;
    const pre = przedrostek(tryb);
    const wezly = wlasne(root);
    const uzyte = new Set();
    let brak = false;
    if (def) {
      (def.pola || []).forEach((k) => { if (zastosuj(root, wezly, s[k], s[pre + k], uzyte) === 'brak') brak = true; });
      Object.keys(def.listy || {}).forEach((lk) => {
        const lista = s[lk];
        if (!Array.isArray(lista)) return;
        lista.forEach((poz) => {
          if (!poz || typeof poz !== 'object') return;
          (def.listy[lk] || []).forEach((k) => { if (zastosuj(root, wezly, poz[k], poz[pre + k], uzyte) === 'brak') brak = true; });
        });
      });
    }
    obrazy(root, s);
    if (brak) braki.add(root);
  }

  function przebieg(tylko) {
    licznik.przebiegi++;
    const idx = tryb === 'pl' ? null : indeks();
    wstrzymaj(() => {
      const roots = tylko ? Array.from(tylko).filter((r) => r.isConnected) : Array.from(document.querySelectorAll('[data-id]'));
      const zbior = new Set(roots);
      /* Cofnięcie przed ponownym nałożeniem: szukamy polskiego w DOM. */
      cofnij((z) => zbior.has(z.root) && !wEdycji(z.root));
      roots.forEach((r) => braki.delete(r));
      if (tryb !== 'pl') {
        roots.forEach((root) => {
          if (wEdycji(root)) return;
          const el = idx.get(String(root.getAttribute('data-id')));
          if (el) tlumaczRoot(root, el);
        });
      }
      if (tylko) roots.forEach((r) => slownik(r));
      else slownik(document.body);
      document.querySelectorAll('.evk-tl-brak').forEach((r) => { if (!braki.has(r)) r.classList.remove('evk-tl-brak'); });
      braki.forEach((r) => { if (r.isConnected) r.classList.add('evk-tl-brak'); else braki.delete(r); });
    });
    etykieta();
  }

  // ── Obserwator: przerysowania Vue budzą przebieg, własne zmiany nie ─────
  const OPCJE = { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['contenteditable'] };
  let doPrzebiegu = null, planowanyWszystko = false, zegar = 0;

  function zbierz(rekordy) {
    rekordy.forEach((r) => {
      const cel = r.target.nodeType === 1 ? r.target : r.target.parentElement;
      if (!cel || cel.closest('#evk-tl-nakladka')) return;
      if (r.type === 'attributes') {
        if (edytowany && cel.getAttribute('contenteditable') === 'false' && edytowany.contains(cel)) setTimeout(zwolnij, 0);
        return;
      }
      const root = cel.closest('[data-id]');
      if (root) { (doPrzebiegu = doPrzebiegu || new Set()).add(root); }
      r.addedNodes && r.addedNodes.forEach((n) => {
        if (n.nodeType !== 1) return;
        n.querySelectorAll && n.querySelectorAll('[data-id]').forEach((x) => (doPrzebiegu = doPrzebiegu || new Set()).add(x));
        if (n.matches && n.matches('[data-id]')) (doPrzebiegu = doPrzebiegu || new Set()).add(n);
      });
    });
    if (doPrzebiegu) zaplanuj(doPrzebiegu);
  }

  const obserwator = new MutationObserver(zbierz);

  function wstrzymaj(fn) {
    const oczekujace = obserwator.takeRecords();
    obserwator.disconnect();
    try { fn(); } finally {
      obserwator.takeRecords();
      obserwator.observe(document.body, OPCJE);
      if (oczekujace.length) zbierz(oczekujace);
    }
  }

  function zaplanuj(roots) {
    if (!roots) planowanyWszystko = true;
    else if (roots !== doPrzebiegu) roots.forEach((r) => (doPrzebiegu = doPrzebiegu || new Set()).add(r));
    if (zegar) return;
    zegar = setTimeout(() => {
      zegar = 0;
      const wszystko = planowanyWszystko;
      const zbior = doPrzebiegu;
      planowanyWszystko = false;
      doPrzebiegu = null;
      przebieg(wszystko ? null : zbior);
    }, 40);
  }

  // ── Edycja w kanwie: na czas edycji polski ──────────────────────────────
  function edycja(root) {
    if (!root || tryb === 'pl') return;
    if (edytowany && edytowany !== root) zwolnij(true);
    if (edytowany === root) return;
    edytowany = root;
    wstrzymaj(() => {
      /* Obraz zostaje — w obrazie się nie pisze, a mignięcie polskim byłoby tylko szumem. */
      cofnij((z) => z.root === root && (z.rodzaj === 'html' || z.rodzaj === 'tekst'));
      slownik(root);
      root.classList.add('evk-tl-edycja');
      root.classList.remove('evk-tl-brak');
      braki.delete(root);
    });
    etykieta();
  }

  function zwolnij(wymus) {
    if (!edytowany) return;
    const r = edytowany;
    const pisze = r.isContentEditable || !!r.querySelector('[contenteditable="true"]');
    /* Wciąż edytowalny: czekamy, aż Bricks zdejmie `contenteditable` (rekord
       atrybutu w obserwatorze woła nas wtedy jeszcze raz). */
    if (!wymus && pisze) return;
    edytowany = null;
    wstrzymaj(() => r.classList.remove('evk-tl-edycja'));
    if (!pisze || wymus) zaplanuj(new Set([r]));
    else setTimeout(() => zaplanuj(new Set([r])), 0);
  }

  const rootZ = (e) => (e.target && e.target.closest ? e.target.closest('[data-id]') : null);
  document.addEventListener('pointerdown', (e) => {
    const r = rootZ(e);
    poKliknieciu = r;
    if (r) edycja(r); else if (edytowany) zwolnij(true);
    etykieta();
  }, true);
  document.addEventListener('focusin', (e) => { const r = rootZ(e); if (r) edycja(r); }, true);
  document.addEventListener('focusout', () => setTimeout(() => zwolnij(false), 60), true);
  document.addEventListener('pointerup', () => setTimeout(() => {
    if (edytowany && !edytowany.isContentEditable && !edytowany.querySelector('[contenteditable="true"]')) zwolnij(false);
  }, 250), true);
  /* Zabezpieczenie: pisanie w elemencie, który wciąż pokazuje tłumaczenie
     (fokus z pominięciem zdarzeń wyżej) — najpierw polski, dopiero potem znak. */
  document.addEventListener('beforeinput', (e) => {
    if (tryb === 'pl') return;
    const r = rootZ(e);
    if (r && r !== edytowany && zmiany.some((z) => z.root === r)) { e.preventDefault(); edycja(r); }
  }, true);

  // ── Napis „brak EN" po najechaniu, jak nazwa elementu w Bricksie ────────
  /* Nad ramką przy prawym rogu, jak zakładka; znika po kliknięciu w element
     i na czas przewijania (decyzja zgłaszającego). Na dole po lewej Bricks pisze
     nazwę elementu — w 1.257.1 napisy tam na siebie nachodziły. Jeden napis,
     liczony przy najechaniu: w 1.257.0 znaczki wisiały nad każdym elementem
     i przy przewijaniu zostawały w tyle za ramką. */
  let nakladka = null, napis = null, pod = null, poKliknieciu = null, przewija = false, zegarPrzewijania = 0;
  function etykieta() {
    if (!nakladka) {
      nakladka = document.createElement('div');
      nakladka.id = 'evk-tl-nakladka';
      nakladka.setAttribute('aria-hidden', 'true');
      napis = document.createElement('span');
      napis.className = 'evk-tl-znacznik';
      napis.hidden = true;
      nakladka.appendChild(napis);
      wstrzymaj(() => document.body.appendChild(nakladka));
    }
    const r = pod;
    const pokaz = tryb !== 'pl' && !przewija && !!r && r.isConnected && r !== poKliknieciu && r !== edytowany
      && r.classList.contains('evk-tl-brak');
    wstrzymaj(() => {
      if (!pokaz) { napis.hidden = true; napis.removeAttribute('data-dla'); return; }
      const b = r.getBoundingClientRect();
      const n = nakladka.getBoundingClientRect();
      /* Względem nakładki, nie okna: przy przekształconym przodku `fixed` liczy się
         od niego. Skala z szerokości — kanwę da się pomniejszyć. 4 px = obrys
         i jego odsunięcie, więc napis przylega do zewnętrznej krawędzi ramki. */
      const skala = nakladka.offsetWidth ? n.width / nakladka.offsetWidth : 1;
      napis.textContent = NAPISY.brak.replace('%s', tryb.toUpperCase());
      napis.setAttribute('data-dla', r.getAttribute('data-id') || '');
      napis.style.left = '';
      /* Bez miejsca nad ramką (element przy górnej krawędzi kanwy) — w ramce, u góry. */
      const nadRamka = (b.top - n.top) / skala - 4 >= 16;
      napis.classList.toggle('wewnatrz', !nadRamka);
      if (nadRamka) {
        napis.style.top = '';
        napis.style.right = ((n.right - b.right) / skala - 4) + 'px';
        napis.style.bottom = ((n.bottom - b.top) / skala + 4) + 'px';
      } else {
        napis.style.bottom = '';
        napis.style.right = ((n.right - b.right) / skala) + 'px';
        napis.style.top = ((b.top - n.top) / skala) + 'px';
      }
      napis.hidden = false;
    });
  }

  document.addEventListener('pointerover', (e) => {
    const r = rootZ(e);
    if (r === pod) return;
    pod = r;
    if (poKliknieciu && poKliknieciu !== r) poKliknieciu = null;
    etykieta();
  }, true);
  document.addEventListener('pointerout', (e) => {
    if (e.relatedTarget) return;
    pod = null;
    poKliknieciu = null;
    etykieta();
  }, true);
  /* Przewijanie: napis znika od razu, a po zatrzymaniu wraca dla elementu,
     który jest wtedy pod nieruchomym wskaźnikiem. */
  window.addEventListener('scroll', () => {
    if (!przewija) { przewija = true; etykieta(); }
    clearTimeout(zegarPrzewijania);
    zegarPrzewijania = setTimeout(() => {
      przewija = false;
      const nad = document.querySelectorAll(':hover');
      const ostatni = nad.length ? nad[nad.length - 1] : null;
      const r = ostatni && ostatni.closest ? ostatni.closest('[data-id]') : null;
      if (r !== pod) { pod = r; poKliknieciu = null; }
      etykieta();
    }, 150);
  }, true);
  window.addEventListener('resize', () => etykieta());

  const styl = document.createElement('style');
  styl.id = 'evk-tl-podglad-kanwa';
  styl.textContent = '.evk-tl-brak{outline:2px dashed #f59e0b !important;outline-offset:2px}'
    + '.evk-tl-edycja{outline:2px solid #3b82f6 !important;outline-offset:2px}'
    + '#evk-tl-nakladka{position:fixed;inset:0;pointer-events:none;z-index:2147483000}'
    + '#evk-tl-nakladka .evk-tl-znacznik{position:absolute;font:600 10px/1.5 system-ui,sans-serif;padding:0 5px;border-radius:3px 3px 0 0;background:#f59e0b;color:#1f2937;white-space:nowrap}'
    + '#evk-tl-nakladka .evk-tl-znacznik.wewnatrz{border-radius:0 0 0 3px}'
    + '#evk-tl-nakladka .evk-tl-znacznik[hidden]{display:none}';
  document.head.appendChild(styl);

  // ── Przełącznik w pasku powłoki ─────────────────────────────────────────
  function ustawTryb(j) {
    if (JEZYKI.indexOf(j) === -1 || j === tryb) return;
    if (edytowany) zwolnij(true);
    tryb = j;
    if (powloka) powloka.__evkTlTryb = j;
    odswiezPrzelacznik();
    if (AI) {
      if (!trwa) pokazStan('');
      odswiezAi();
    }
    wstrzymaj(() => {
      cofnij(null);
      braki.forEach((r) => r.classList.remove('evk-tl-brak'));
      braki.clear();
    });
    przebieg(null);
  }

  function odswiezPrzelacznik() {
    if (!powloka) return;
    const ul = powloka.document.getElementById('evk-tl-podglad');
    if (!ul) return;
    ul.querySelectorAll('button[data-jezyk]').forEach((b) => b.setAttribute('aria-pressed', b.getAttribute('data-jezyk') === tryb ? 'true' : 'false'));
  }

  function wstawPrzelacznik() {
    if (!powloka || JEZYKI.length < 2) return;
    const pd = powloka.document;
    const pasek = pd.getElementById('bricks-toolbar-top');
    if (!pasek) return;
    const stary = pd.getElementById('evk-tl-podglad');
    if (stary && stary.__evkWlasciciel === window && pasek.contains(stary)) return;
    if (stary) stary.remove();
    /* Opakowanie (1.270.0): przełącznik i ✦ to w pasku Bricksa JEDEN element —
       pasek rozkłada swoje grupy na całą szerokość, więc osobne grupy stały
       daleko od siebie (uwaga zgłaszającego, zrzut z 1.269.0). */
    let opak = pd.getElementById('evk-tl-pasek');
    if (opak && opak.__evkWlasciciel !== window) { opak.remove(); opak = null; }
    if (!opak) {
      opak = pd.createElement('div');
      opak.id = 'evk-tl-pasek';
      opak.__evkWlasciciel = window;
      const srodek = pasek.querySelector('.group-wrapper.center');
      if (srodek && srodek.parentNode === pasek) srodek.insertAdjacentElement('afterend', opak);
      else pasek.appendChild(opak);
    }
    if (!pd.getElementById('evk-tl-podglad-styl')) {
      const s = pd.createElement('style');
      s.id = 'evk-tl-podglad-styl';
      s.textContent = '#evk-tl-pasek{display:flex;align-items:center;gap:6px;margin:0 8px;padding:0}'
        + '#evk-tl-podglad{display:flex;align-items:center;gap:2px;margin:0;padding:0;list-style:none}'
        + '#evk-tl-podglad li{margin:0;padding:0;list-style:none}'
        + '#evk-tl-podglad button{min-width:32px;border:0;border-radius:4px;background:transparent;color:inherit;font:600 12px/1 inherit;letter-spacing:.02em;cursor:pointer;opacity:.75}'
        + '#evk-tl-podglad button:hover{opacity:1}'
        + '#evk-tl-podglad button[aria-pressed="true"]{background:var(--builder-color-accent,#ffd64f);color:#1f2937;opacity:1}'
        + '#evk-tl-podglad button:focus-visible{outline:2px solid currentColor;outline-offset:1px}';
      pd.head.appendChild(s);
    }
    const ul = pd.createElement('ul');
    ul.id = 'evk-tl-podglad';
    ul.className = 'group-wrapper evk-tl-podglad';
    ul.setAttribute('role', 'group');
    ul.setAttribute('aria-label', NAPISY.grupa);
    JEZYKI.forEach((j) => {
      const li = pd.createElement('li');
      const b = pd.createElement('button');
      b.type = 'button';
      b.setAttribute('data-jezyk', j);
      b.setAttribute('aria-pressed', j === tryb ? 'true' : 'false');
      /* Dymek jak w całym builderze (1.269.0, próba 30.09): `data-balloon` na `li` paska. */
      b.setAttribute('aria-label', NAPISY.przycisk.replace('%s', j.toUpperCase()));
      li.setAttribute('data-balloon', NAPISY.przycisk.replace('%s', j.toUpperCase()));
      li.setAttribute('data-balloon-pos', 'bottom');
      b.textContent = j.toUpperCase();
      b.addEventListener('click', () => ustawTryb(j));
      li.appendChild(b);
      ul.appendChild(li);
    });
    ul.__evkWlasciciel = window;
    opak.prepend(ul);
  }

  // ── Przyciski AI (1.265.0) ──────────────────────────────────────────────
  /* „Przetłumacz (AI)” przy przełączniku — zaznaczony element z dziećmi,
     w języku przełącznika — i pod polem „Tłumaczenie EN” zaznaczonego
     elementu. Decyzje zgłaszającego (30.09): przy PL przycisk nieaktywny
     z podpowiedzią „Wybierz EN albo DE”, model z ustawień, wynik do stanu
     buildera — puste pola bez pytania, wypełnione po potwierdzeniu — i zapis
     ręczny w Bricksie.

     Próba na testowej (docs/proby-builder-ai.md): wpis wprost do `settings`
     elementu w stanie POWŁOKI pokazuje się w polu panelu, Bricks widzi
     zmianę (kropka przy zapisie), cofnij/ponów działa, a pole się zapisuje.

     Serwer (61) tylko tłumaczy. Teksty i kontekst idą ze stanu, z
     niezapisanymi zmianami. Wynik trafia do pola tylko wtedy, gdy polski
     tekst i pole są takie jak przed zapytaniem — pisanie w trakcie wygrywa. */
  const AI = DANE.ai && typeof DANE.ai === 'object' && typeof DANE.ai.ajax === 'string' ? DANE.ai : null;
  const OBSZARY = ['content', 'header', 'footer'];
  const OBCE = JEZYKI.filter((j) => j !== 'pl');
  const KODY = OBCE.map((j) => j.toUpperCase());
  const WYBIERZ = 'Wybierz ' + (KODY.length > 1 ? KODY.slice(0, -1).join(', ') + ' albo ' + KODY[KODY.length - 1] : (KODY[0] || ''));
  let trwa = false;
  let ostatniAktywny = null;

  function stanPowloki() { return powloka ? stanZ(powloka.document, '.brx-body.main') : null; }

  /* Komponenty (1.272.0, próba na testowej): definicje w `components` stanu
     powłoki, edytowany komponent w `activeComponent` (jego elementy to obszar
     „komponent”), instancja na stronie — element z `cid` i `properties`.
     Logika właściwości tłumaczeń: tl-komponenty.js (ta sama co w PHP, 51). */
  const KP = window.evkTlKomponenty || null;
  function elementyObszaru(st, obszar) {
    if (obszar === 'komponent') return st && st.activeComponent && Array.isArray(st.activeComponent.elements) ? st.activeComponent.elements : [];
    return st && Array.isArray(st[obszar]) ? st[obszar] : [];
  }
  function komponent(st, cid) {
    return (st && Array.isArray(st.components) ? st.components : []).find((k) => k && String(k.id) === String(cid)) || null;
  }
  /** Pole elementu edytowanego komponentu połączone z właściwością — tłumaczy się w instancji. */
  function polaczone(st, obszar, id, pole) {
    if (obszar !== 'komponent' || !st.activeComponent) return false;
    return (Array.isArray(st.activeComponent.properties) ? st.activeComponent.properties : []).some((p) => p && p.connections
      && Array.isArray(p.connections[id]) && p.connections[id].indexOf(pole) !== -1);
  }
  /** Właściwości „… EN” w komponentach od razu w builderze — bez czekania na zapis. */
  function synchronizujKomponenty() {
    const st = stanPowloki();
    if (!KP || !st || !OBCE.length) return;
    const lista = Array.isArray(st.components) ? st.components : [];
    KP.uzupelnij(lista, OBCE, MAPA);
    if (st.activeComponent && lista.indexOf(st.activeComponent) === -1) KP.uzupelnij([st.activeComponent], OBCE, MAPA);
  }

  /** Zaznaczony element w stanie powłoki: element, jego obszar i stan. */
  function aktywny() {
    const st = stanPowloki();
    const id = st && (st.activeId || (st.activeElement && st.activeElement.id));
    if (!id) return null;
    for (const o of OBSZARY.concat(['komponent'])) {
      const el = elementyObszaru(st, o).find((e) => e && String(e.id) === String(id));
      if (el) return { st, el, obszar: o };
    }
    return null;
  }

  /* Jak evk_tl_ai_do_tlumaczenia(): litery poza znacznikami, tagami {…} i shortcodami. */
  const doTlumaczenia = (v) => typeof v === 'string' && /\p{L}/u.test(tekstZHtml(v).replace(/\{[^{}]*\}|\[[^\[\]]*\]/g, ' '));

  /** Teksty elementu z mapy pól — pola, potem pozycje list, jak w hurcie. Obiekt
      w stanie szukamy przy wpisie od nowa (`obiekt()`): cofnięcie w Bricksie
      mogło go w międzyczasie podmienić. */
  function tekstyElementu(el, lang, st) {
    const w = [];
    const pre = przedrostek(lang);
    /* Instancja komponentu (1.272.0): teksty jej właściwości, tłumaczenie do bliźniaka „… EN”. */
    if (el.cid) {
      const k = KP ? komponent(st, el.cid) : null;
      const pary = k ? KP.pary(k, MAPA) : {};
      const obj = el.properties && typeof el.properties === 'object' ? el.properties : {};
      const kod = String(lang).toLowerCase().replace(/[^a-z0-9_]/g, '_');
      Object.keys(pary).forEach((pid) => {
        const tid = pary[pid].blizniaki[kod];
        if (!tid || typeof obj[pid] !== 'string') return;
        w.push({ id: String(el.id), nazwa: 'Komponent', pole: 'prop:' + pid, klPl: pid, klTl: tid, kp: true, lista: '', indeks: -1, idPoz: '', poz: 0, obj });
      });
      return w;
    }
    const def = MAPA[el.name];
    const s = el.settings;
    if (!def || !s || typeof s !== 'object') return w;
    const baza = { id: String(el.id), nazwa: String(el.name) };
    (def.pola || []).forEach((pole) => {
      if (typeof s[pole] === 'string') w.push(Object.assign({ pole, klPl: pole, klTl: pre + pole, lista: '', indeks: -1, idPoz: '', poz: 0, obj: s }, baza));
    });
    Object.keys(def.listy || {}).forEach((lista) => {
      const pozycje = s[lista];
      if (!Array.isArray(pozycje)) return;
      pozycje.forEach((p, i) => {
        if (!p || typeof p !== 'object') return;
        (def.listy[lista] || []).forEach((pole) => {
          if (typeof p[pole] !== 'string') return;
          w.push(Object.assign({ pole, klPl: pole, klTl: pre + pole, lista, indeks: i, idPoz: p.id !== undefined && p.id !== null ? String(p.id) : '', poz: i + 1, obj: p }, baza));
        });
      });
    });
    return w;
  }
  const kluczMiejsca = (x) => x.id + '|' + (x.lista ? x.lista + '.' + (x.idPoz || x.indeks) + '.' : '') + x.pole;

  function obiekt(x) {
    const st = stanPowloki();
    if (!st) return null;
    let el = null;
    for (const o of OBSZARY.concat(['komponent'])) {
      el = elementyObszaru(st, o).find((e) => e && String(e.id) === x.id) || null;
      if (el) break;
    }
    /* Instancja: wartości właściwości; puste `properties` (instancja wstawiona bez wartości) — nowe. */
    if (el && x.kp) {
      if (!el.properties || typeof el.properties !== 'object') el.properties = {};
      return el.properties;
    }
    const s = el && el.settings;
    if (!s || typeof s !== 'object') return null;
    if (!x.lista) return s;
    const pozycje = s[x.lista];
    if (!Array.isArray(pozycje)) return null;
    if (x.idPoz) return pozycje.find((p) => p && String(p.id) === x.idPoz) || null;
    return pozycje[x.indeks] && typeof pozycje[x.indeks] === 'object' ? pozycje[x.indeks] : null;
  }

  /** Element z dziećmi: id z `children` i z `parent` (Bricks trzyma oba). */
  function potomkowie(st, obszar, el) {
    const dzieci = new Map();
    const dodaj = (r, d) => { if (!dzieci.has(r)) dzieci.set(r, new Set()); dzieci.get(r).add(d); };
    elementyObszaru(st, obszar).forEach((e) => {
      if (!e || e.id === undefined) return;
      (Array.isArray(e.children) ? e.children : []).forEach((c) => dodaj(String(e.id), String(c)));
      if (e.parent !== undefined && e.parent !== null && String(e.parent) !== '0' && e.parent !== '') dodaj(String(e.parent), String(e.id));
    });
    const wynik = new Set([String(el.id)]);
    const kolejka = [String(el.id)];
    while (kolejka.length) {
      (dzieci.get(kolejka.shift()) || []).forEach((d) => { if (!wynik.has(d)) { wynik.add(d); kolejka.push(d); } });
    }
    return wynik;
  }

  /** Kontekst jak w hurcie: teksty obszaru w kolejności stanu, z obecnym
      tłumaczeniem — poza tłumaczonymi teraz (model ma przetłumaczyć po swojemu). */
  function kontekstObszaru(st, obszar, lang, tlumaczone) {
    const kontekst = [];
    const numer = new Map();
    elementyObszaru(st, obszar).forEach((e) => {
      if (!e || e.id === undefined) return;
      tekstyElementu(e, lang, st).forEach((x) => {
        if (polaczone(st, obszar, x.id, x.pole)) return;
        const pl = x.obj[x.klPl];
        if (!doTlumaczenia(pl)) return;
        const k = kluczMiejsca(x);
        const tl = x.obj[x.klTl];
        kontekst.push({ el: x.nazwa, pole: x.pole, poz: x.poz, pl, tl: !tlumaczone.has(k) && niepusty(tl) ? tl : '' });
        numer.set(k, kontekst.length);
      });
    });
    return { kontekst, numer };
  }

  /* Porcje jak w hurcie (61): liczba tekstów i bajty (strlen) — jeden długi tekst przechodzi sam. */
  const koder = new TextEncoder();
  function porcje(lista) {
    const max = Math.max(1, parseInt(AI.porcja, 10) || 25);
    const limit = Math.max(1, parseInt(AI.znaki, 10) || 6000);
    const out = [];
    let biezaca = [];
    let suma = 0;
    lista.forEach((x) => {
      const b = koder.encode(x.pl).length;
      if (biezaca.length && (biezaca.length >= max || suma + b > limit)) { out.push(biezaca); biezaca = []; suma = 0; }
      biezaca.push(x);
      suma += b;
    });
    if (biezaca.length) out.push(biezaca);
    return out;
  }

  async function zapytaj(lang, kontekst, teksty) {
    const cialo = new URLSearchParams({ action: 'evk_tl_ai_builder', nonce: String(AI.nonce || ''), post_id: String(AI.post || 0), lang,
      kontekst: JSON.stringify(kontekst), teksty: JSON.stringify(teksty) });
    let odp;
    try {
      odp = await fetch(AI.ajax, { method: 'POST', credentials: 'same-origin', body: cialo });
    } catch (e) {
      return { blad: 'Brak połączenia z serwerem.', stop: true };
    }
    let j = null;
    try { j = await odp.json(); } catch (e) { j = null; }
    if (j === -1 || j === 0) return { blad: 'Sesja wygasła albo brak uprawnień — przeładuj builder.', stop: true };
    if (!j || typeof j !== 'object') return { blad: 'Serwer odpowiedział błędem (' + odp.status + ').', stop: true };
    if (!j.success) return { blad: typeof j.data === 'string' && j.data ? j.data : 'Serwer odmówił (' + odp.status + ').', stop: true };
    return j.data && typeof j.data === 'object' ? j.data : {};
  }

  /** Pole zaznaczonego elementu w panelu (poza kontrolkami list) — edytor
      TinyMCE w nim może nie śledzić stanu; bez tego zmiana w edytorze
      zapisałaby stary tekst z powrotem. */
  function edytorPola(x, klucz, tekst) {
    const a = aktywny();
    const tm = powloka && powloka.tinymce;
    if (!a || String(a.el.id) !== x.id || x.lista || !tm || typeof tm.get !== 'function') return;
    const ctrl = Array.from(powloka.document.querySelectorAll('[data-controlkey="' + klucz + '"]'))
      .find((c) => !(c.parentElement && c.parentElement.closest('[data-controlkey]')));
    if (!ctrl) return;
    const ramka = ctrl.querySelector('iframe[id$="_ifr"]');
    const pole = ctrl.querySelector('textarea[id]');
    const ed = (ramka && tm.get(ramka.id.slice(0, -4))) || (pole && tm.get(pole.id));
    if (ed && typeof ed.getContent === 'function' && ed.getContent() !== tekst) ed.setContent(tekst);
  }

  /**
   * Tłumaczy teksty jednego obszaru porcjami i wpisuje wyniki do stanu
   * powłoki. `postep(zrobione, razem)` — do komunikatu.
   */
  async function przetlumacz(obszar, lang, lista, postep) {
    const w = { wpisane: 0, pamiec: 0, bezZmian: 0, odrzucone: 0, pominiete: 0, zmienione: 0, blad: '', zrodlo: '' };
    const st = stanPowloki();
    if (!st) { w.blad = 'Nie widzę stanu buildera — przeładuj builder.'; return w; }
    const { kontekst, numer } = kontekstObszaru(st, obszar, lang, new Set(lista.map(kluczMiejsca)));
    const ids = new Set();
    let zrobione = 0;
    for (const partia of porcje(lista)) {
      postep(zrobione, lista.length);
      const teksty = {};
      const miejsca = {};
      partia.forEach((x, i) => {
        const k = 'k' + (i + 1);
        miejsca[k] = x;
        teksty[k] = { n: numer.get(kluczMiejsca(x)) || 0, bylo: niepusty(x.surowe) ? x.surowe : '' };
      });
      const odp = await zapytaj(lang, kontekst, teksty);
      const tl = odp.tlumaczenia && typeof odp.tlumaczenia === 'object' ? odp.tlumaczenia : {};
      const zr = odp.zrodla && typeof odp.zrodla === 'object' ? odp.zrodla : {};
      Object.keys(tl).forEach((k) => {
        const x = miejsca[k];
        if (!x || typeof tl[k] !== 'string') return;
        const obj = obiekt(x);
        if (!obj || obj[x.klPl] !== x.pl || String(obj[x.klTl] ?? '') !== String(x.surowe ?? '')) { w.zmienione++; return; }
        obj[x.klTl] = tl[k];
        if (!x.kp) edytorPola(x, x.klTl, tl[k]);
        ids.add(x.id);
        w.wpisane++;
        w.zrodlo = zr[k] || 'ai';
        if (zr[k] === 'pamiec' || zr[k] === 'wynik') w.pamiec++;
        /* Następne porcje widzą to w kontekście, jak w hurcie. */
        if (teksty[k].n) kontekst[teksty[k].n - 1].tl = tl[k];
      });
      w.bezZmian += Array.isArray(odp.bez_zmian) ? odp.bez_zmian.length : 0;
      w.odrzucone += Array.isArray(odp.odrzucone) ? odp.odrzucone.length : 0;
      w.pominiete += Array.isArray(odp.pominiete) ? odp.pominiete.length : 0;
      zrobione += partia.length;
      if (odp.blad) {
        w.blad = odp.czekaj ? 'Dostawca prosi o przerwę — spróbuj za ' + odp.czekaj + ' s.' : String(odp.blad);
        if (odp.stop || odp.czekaj) break;
      }
    }
    if (ids.size) {
      const roots = new Set();
      ids.forEach((id) => document.querySelectorAll('[data-id="' + id.replace(/["\\]/g, '') + '"]').forEach((r) => roots.add(r)));
      if (roots.size) zaplanuj(roots);
    }
    return w;
  }

  function opisPola(w) {
    if (w.wpisane) {
      const skad = w.zrodlo === 'pamiec' ? 'z pamięci tłumaczeń — sprawdzone tłumaczenie tego tekstu'
        : (w.zrodlo === 'wynik' ? 'z pamięci wyników AI · ' + AI.model : 'AI · ' + AI.model);
      return 'Wpisane (' + skad + '). Zapisz stronę w Bricksie.';
    }
    if (w.blad) return w.blad;
    if (w.zmienione) return 'Tekst albo pole zmieniły się w trakcie — nic nie wpisuję.';
    if (w.bezZmian) return 'AI zwróciło ten sam tekst — bez zmian.';
    if (w.odrzucone) return 'Tłumaczenie odrzucone: znaczniki HTML, tagi {…} albo shortcody nie zgadzają się z oryginałem.';
    if (w.pominiete) return 'Tego tekstu AI nie tłumaczy (sam tag danych dynamicznych albo bez liter).';
    return 'Brak tłumaczenia.';
  }

  function opisElementu(w, L, komponenty) {
    const cz = [];
    if (w.wpisane) cz.push('wpisane ' + w.wpisane + (w.pamiec ? ' (z pamięci ' + w.pamiec + ')' : ''));
    if (w.bezZmian) cz.push('bez zmian ' + w.bezZmian);
    if (w.odrzucone) cz.push('odrzucone ' + w.odrzucone + ' (znaczniki, tagi {…} albo shortcody)');
    if (w.zmienione) cz.push('zmienione w trakcie ' + w.zmienione);
    if (w.pominiete) cz.push('pominięte ' + w.pominiete);
    if (komponenty) cz.push('komponenty pominięte ' + komponenty);
    let t = L + ': ' + (cz.length ? cz.join(', ') : 'nic nie wpisane') + '.';
    if (w.blad) t += ' ' + w.blad;
    if (w.wpisane) t += ' Zapisz stronę w Bricksie.';
    return t;
  }

  function zajety(tak, przycisk) {
    trwa = tak;
    if (przycisk) przycisk.setAttribute('aria-busy', tak ? 'true' : 'false');
    odswiezAi();
  }

  async function tlumaczPole(przycisk, stanPola, lang, pole) {
    if (trwa) { stanPola.textContent = 'Trwa tłumaczenie — poczekaj na koniec.'; return; }
    const a = aktywny();
    const s = a && a.el.settings;
    const pl = s ? s[pole] : null;
    if (!a || typeof pl !== 'string' || !niepusty(pl)) { stanPola.textContent = 'Brak polskiego tekstu w tym polu elementu.'; return; }
    /* Pole edytowanego komponentu z właściwością (1.272.0): tekst daje instancja, tłumaczenie też. */
    if (polaczone(a.st, a.obszar, String(a.el.id), pole)) {
      stanPola.textContent = 'To pole ma właściwość komponentu — tłumaczenie wpisuje się w instancji (właściwość „… ' + lang.toUpperCase() + '”).';
      return;
    }
    if (!doTlumaczenia(pl)) { stanPola.textContent = 'Tego tekstu AI nie tłumaczy (sam tag danych dynamicznych albo bez liter).'; return; }
    const surowe = s[przedrostek(lang) + pole];
    if (niepusty(surowe) && !powloka.confirm('Zastąpić obecne tłumaczenie ' + lang.toUpperCase() + '?\n\n„'
      + tekstZHtml(surowe).trim().slice(0, 200) + '”')) return;
    const x = { id: String(a.el.id), nazwa: String(a.el.name), pole, klPl: pole, klTl: przedrostek(lang) + pole, lista: '', indeks: -1, idPoz: '', poz: 0, obj: s, pl, surowe };
    zajety(true, przycisk);
    stanPola.textContent = 'Tłumaczę na ' + lang.toUpperCase() + '…';
    try {
      stanPola.textContent = opisPola(await przetlumacz(a.obszar, lang, [x], () => {}));
    } finally {
      zajety(false, przycisk);
    }
  }

  async function tlumaczElement() {
    if (trwa) return;
    if (tryb === 'pl') { pokazStan(WYBIERZ + ' w przełączniku obok — przycisk tłumaczy na język podglądu.'); return; }
    const a = aktywny();
    if (!a) { pokazStan('Zaznacz element na kanwie albo w strukturze — przetłumaczę go razem z dziećmi.'); return; }
    const lang = tryb;
    const L = lang.toUpperCase();
    const ids = potomkowie(a.st, a.obszar, a.el);
    const lista = [];
    let komponenty = 0;
    elementyObszaru(a.st, a.obszar).forEach((e) => {
      if (!e || !ids.has(String(e.id))) return;
      const teksty = tekstyElementu(e, lang, a.st).filter((x) => !polaczone(a.st, a.obszar, x.id, x.pole));
      /* Instancja komponentu (1.272.0): teksty właściwości z bliźniakiem „… EN”. Bez par
         (komponent bez właściwości tłumaczeń) — pominięta, jej teksty żyją w komponencie. */
      if (e.cid && !teksty.length) { komponenty++; return; }
      teksty.forEach((x) => {
        x.pl = x.obj[x.klPl];
        x.surowe = x.obj[x.klTl];
        if (doTlumaczenia(x.pl)) lista.push(x);
      });
    });
    if (!lista.length) {
      pokazStan('W zaznaczonym elemencie nie ma tekstów do tłumaczenia' + (komponenty ? ' (komponenty pomijam)' : '') + '.');
      return;
    }
    const pelne = lista.filter((x) => niepusty(x.surowe));
    let doTl = lista.filter((x) => !niepusty(x.surowe));
    if (pelne.length) {
      const pytanie = doTl.length
        ? 'Zaznaczony element ma już tłumaczenia ' + L + ': ' + pelne.length + '.\n\nOK — przetłumacz je od nowa razem z pustymi polami (' + doTl.length + ').\nAnuluj — tylko puste pola.'
        : 'Zastąpić istniejące tłumaczenia ' + L + ' (' + pelne.length + ')?';
      if (powloka.confirm(pytanie)) doTl = lista;
    }
    if (!doTl.length) { pokazStan(L + ': bez zmian — wszystkie pola są już wypełnione.'); return; }
    zajety(true);
    try {
      const w = await przetlumacz(a.obszar, lang, doTl, (z, r) => pokazStan('Tłumaczę na ' + L + '… ' + z + '/' + r));
      pokazStan(opisElementu(w, L, komponenty));
    } finally {
      zajety(false);
    }
  }

  /** Komunikat przycisku przy przełączniku: dymek pod paskiem, `role="status"`.
      Pusty — schowany wzrokowo, ale w drzewie dostępności (region stoi przed zmianą). */
  function pokazStan(tekst) {
    if (!powloka) return;
    const pd = powloka.document;
    const d = pd.getElementById('evk-tl-ai-dymek');
    const s = pd.getElementById('evk-tl-ai-stan');
    const z = pd.getElementById('evk-tl-ai-zamknij');
    if (!d || !s) return;
    if (s.textContent !== tekst) s.textContent = tekst;
    d.classList.toggle('widoczny', !!tekst);
    if (z) z.hidden = !tekst;
    if (!tekst) return;
    const b = pd.getElementById('evk-tl-ai-element');
    const r = b ? b.getBoundingClientRect() : { left: 8, bottom: 48 };
    d.style.top = Math.round(r.bottom + 6) + 'px';
    d.style.left = Math.round(Math.max(8, Math.min(r.left, pd.documentElement.clientWidth - d.offsetWidth - 8))) + 'px';
  }

  function odswiezAi() {
    if (!AI || !powloka) return;
    const pd = powloka.document;
    const b = pd.getElementById('evk-tl-ai-element');
    if (!b) return;
    const a = aktywny();
    const id = a ? String(a.el.id) : '';
    /* Inny element: stary komunikat już go nie dotyczy. */
    if (id !== ostatniAktywny) {
      if (ostatniAktywny !== null && !trwa) pokazStan('');
      ostatniAktywny = id;
    }
    const powod = trwa ? 'Tłumaczę…' : (tryb === 'pl' ? WYBIERZ : (!a ? 'Zaznacz element' : ''));
    const opis = powod || ('Zaznaczony element z dziećmi → ' + tryb.toUpperCase() + ' (' + AI.model + ')');
    if (b.getAttribute('aria-disabled') !== (powod ? 'true' : 'false')) b.setAttribute('aria-disabled', powod ? 'true' : 'false');
    if (b.getAttribute('aria-busy') !== (trwa ? 'true' : 'false')) b.setAttribute('aria-busy', trwa ? 'true' : 'false');
    /* Dymek Bricksa na `li` przycisku (próba 30.09: `data-balloon`, `.bricks-toolbar li[data-balloon]`) zamiast `title`. */
    const li = b.parentElement;
    if (li && li.getAttribute('data-balloon') !== opis) li.setAttribute('data-balloon', opis);
    if (b.hasAttribute('title')) b.removeAttribute('title');
    const p = pd.getElementById('evk-tl-ai-podpowiedz');
    if (p && p.textContent !== opis) p.textContent = opis;
  }

  function wstawAi() {
    if (!AI || !powloka || !OBCE.length) return;
    const pd = powloka.document;
    const przel = pd.getElementById('evk-tl-podglad');
    if (!przel) return;
    if (!pd.getElementById('evk-tl-ai-styl')) {
      const s = pd.createElement('style');
      s.id = 'evk-tl-ai-styl';
      s.textContent = '#evk-tl-ai{display:flex;align-items:center;margin:0;padding:0;list-style:none}'
        + '#evk-tl-ai li{margin:0;padding:0;list-style:none}'
        + '#evk-tl-ai button{width:28px;height:28px;border-radius:4px;opacity:.85}'
        + '#evk-tl-ai button:hover,.evk-tl-ai-ikona:hover{opacity:1}'
        + '#evk-tl-ai button[aria-disabled="true"]{opacity:.45;cursor:not-allowed}'
        + '#evk-tl-ai button[aria-busy="true"],.evk-tl-ai-ikona[aria-busy="true"]{cursor:progress}'
        + '#evk-tl-ai button:focus-visible,#evk-tl-ai-dymek button:focus-visible,.evk-tl-ai-ikona:focus-visible{outline:2px solid currentColor;outline-offset:1px}'
        + '#evk-tl-ai-dymek{position:fixed;z-index:100000;display:flex;align-items:flex-start;gap:8px;max-width:360px;padding:8px 8px 8px 12px;border-radius:6px;background:#fff;color:#1f2937;box-shadow:0 4px 16px rgba(0,0,0,.35);font:13px/1.45 system-ui,sans-serif}'
        + '#evk-tl-ai-dymek:not(.widoczny){width:1px;height:1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap;box-shadow:none}'
        + '#evk-tl-ai-dymek [hidden]{display:none!important}'
        + '#evk-tl-ai-dymek button{flex:none;min-width:24px;min-height:24px;border:0;border-radius:4px;background:transparent;color:inherit;font:600 16px/1 system-ui,sans-serif;cursor:pointer}'
        /* Ikonka ✦: panel Bricksa stylizuje przyciski (wielkie litery, marginesy,
           tło) regułami mocniejszymi niż jedna klasa — zerujemy z `!important`
           wszystko, co mogłoby ją przesunąć albo rozepchnąć. */
        + '#evk-tl-ai button,.evk-tl-ai-ikona{display:inline-flex!important;align-items:center;justify-content:center;box-sizing:border-box!important;'
        + 'margin:0!important;padding:0!important;border:0!important;background:transparent!important;color:inherit!important;min-width:0!important;'
        + 'min-height:0!important;max-width:none!important;font:inherit;line-height:0!important;text-transform:none!important;letter-spacing:normal;box-shadow:none!important;cursor:pointer}'
        + '.evk-tl-ai-ikona{flex:none;border-radius:3px;opacity:.75}'
        + '.evk-tl-ai-ikona svg,#evk-tl-ai button svg{display:block;flex:none;pointer-events:none}'
        + '.evk-tl-ai-pole-stan{display:block;margin:4px 0 0;font-size:11px;line-height:1.35;opacity:.85;text-transform:none}'
        + '.evk-tl-ai-pole-stan:empty{display:none}';
      pd.head.appendChild(s);
    }
    let d = pd.getElementById('evk-tl-ai-dymek');
    if (d && d.__evkWlasciciel !== window) { d.remove(); d = null; }
    if (!d) {
      d = pd.createElement('div');
      d.id = 'evk-tl-ai-dymek';
      const s = pd.createElement('div');
      s.id = 'evk-tl-ai-stan';
      s.setAttribute('role', 'status');
      const z = pd.createElement('button');
      z.type = 'button';
      z.id = 'evk-tl-ai-zamknij';
      z.setAttribute('aria-label', 'Zamknij komunikat');
      z.textContent = '×';
      z.hidden = true;
      const zamknij = () => {
        pokazStan('');
        const b = pd.getElementById('evk-tl-ai-element');
        if (b) b.focus();
      };
      z.addEventListener('click', zamknij);
      d.addEventListener('keydown', (e) => { if (e.key === 'Escape') zamknij(); });
      d.append(s, z);
      d.__evkWlasciciel = window;
      pd.body.appendChild(d);
    }
    const stary = pd.getElementById('evk-tl-ai');
    if (stary && stary.__evkWlasciciel === window && stary.previousElementSibling === przel) return;
    if (stary) stary.remove();
    const ul = pd.createElement('ul');
    ul.id = 'evk-tl-ai';
    ul.className = 'group-wrapper evk-tl-ai';
    ul.setAttribute('role', 'group');
    ul.setAttribute('aria-label', 'Tłumaczenie AI');
    const li = pd.createElement('li');
    li.setAttribute('data-balloon-pos', 'bottom');
    const b = pd.createElement('button');
    b.type = 'button';
    b.id = 'evk-tl-ai-element';
    b.setAttribute('aria-label', 'Przetłumacz zaznaczony element (AI)');
    b.appendChild(ikonaAi(pd, 16));
    b.setAttribute('aria-describedby', 'evk-tl-ai-podpowiedz');
    b.addEventListener('click', () => { tlumaczElement(); });
    const p = pd.createElement('span');
    p.id = 'evk-tl-ai-podpowiedz';
    p.hidden = true;
    li.append(b, p);
    ul.appendChild(li);
    ul.__evkWlasciciel = window;
    przel.insertAdjacentElement('afterend', ul);
    odswiezAi();
  }

  /** Pole języka w panelu → [język, pole] dla przycisku; null — bez przycisku. */
  function celPola(ctrl, a) {
    const klucz = ctrl.getAttribute('data-controlkey') || '';
    const lang = OBCE.find((j) => klucz.indexOf(przedrostek(j)) === 0);
    const def = a && MAPA[a.el.name];
    if (!lang || !def) return null;
    const pole = klucz.slice(przedrostek(lang).length);
    /* Tylko pola samego elementu: pole pozycji listy siedzi w kontrolce listy. */
    if ((def.pola || []).indexOf(pole) === -1 || (ctrl.parentElement && ctrl.parentElement.closest('[data-controlkey]'))) return null;
    return [lang, pole];
  }

  /* ✦ przy polu (1.266.0). Próba na testowej (30.09, docs/proby-builder-ai.md):
     ⚡ (dane dynamiczne, `.dynamic-tag-picker-button`) to rodzeństwo pola —
     przy polu tekstowym w przepływie, 28×32, w wierszu flex; przy textarea
     absolutny, 20×20, w prawym górnym rogu. ✦ staje zaraz przed ⚡ albo pod
     nim, z wymiarami ⚡ odczytanymi teraz, a nie wpisanymi na sztywno. Klas ⚡
     nie kopiujemy — Bricks może łapać kliknięcia po klasie.

     Ukryte pole (warunek `required` przejęty ze źródła) zostawia PUSTĄ obudowę
     `[data-controlkey]` bez `.control` i bez pola — tam ✦ nie ma (duplikat
     z 1.265.0 pod Podstawowym tekstem: przycisk pola „Czytaj więcej”). */
  const SVG_NS = 'http://www.w3.org/2000/svg';
  function ikonaAi(pd, rozmiar) {
    const svg = pd.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 16 16');
    svg.setAttribute('width', String(rozmiar));
    svg.setAttribute('height', String(rozmiar));
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const p = pd.createElementNS(SVG_NS, 'path');
    p.setAttribute('d', 'M8 0C8.6 4.6 11.4 7.4 16 8C11.4 8.6 8.6 11.4 8 16C7.4 11.4 4.6 8.6 0 8C4.6 7.4 7.4 4.6 8 0Z');
    p.setAttribute('fill', 'currentColor');
    svg.appendChild(p);
    return svg;
  }

  /** Pole kontrolki: [węzeł, rodzaj] — edytor, textarea albo pole tekstowe; null — pusta obudowa. */
  function polePanelu(ctrl) {
    const c = ctrl.querySelector('.control');
    if (!c) return null;
    const ed = c.querySelector('.wp-editor-wrap') || c.querySelector('iframe[id$="_ifr"]');
    if (ed) return [ed, 'edytor'];
    const ta = c.querySelector('textarea:not([hidden])');
    if (ta) return [ta, 'textarea'];
    const inp = c.querySelector('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"])');
    return inp ? [inp, 'input'] : null;
  }

  const px = (v) => Math.round(v) + 'px';
  function ustaw(w, props) {
    Object.keys(props).forEach((k) => { if (w.style[k] !== props[k]) w.style[k] = props[k]; });
  }
  /** Odstęp z prawej, który pole oddaje ikonkom (textarea: ⚡ i ✦ nad tekstem). */
  function odsun(pole, ile) {
    if (!('__evkPadding' in pole)) pole.__evkPadding = pole.style.paddingRight;
    if ((parseFloat(pole.ownerDocument.defaultView.getComputedStyle(pole).paddingRight) || 0) < ile) pole.style.paddingRight = ile + 'px';
  }
  function usunIkone(b) {
    const pole = b.__evkPole;
    if (pole && '__evkPadding' in pole) { pole.style.paddingRight = pole.__evkPadding; delete pole.__evkPadding; }
    const host = b.__evkHost;
    if (host && '__evkPozycja' in host) { host.style.position = host.__evkPozycja; delete host.__evkPozycja; }
    b.remove();
  }

  /** Ustawia ✦ przy ⚡ (albo przy prawej krawędzi pola, gdy ⚡ nie ma). */
  function ulozIkone(b, pole, rodzaj, bolt) {
    const okno = pole.ownerDocument.defaultView;
    b.__evkPole = rodzaj === 'textarea' || rodzaj === 'input' ? pole : null;
    if (bolt && bolt.parentNode) {
      const w = bolt.offsetWidth || 28;
      const h = bolt.offsetHeight || 32;
      const poz = okno.getComputedStyle(bolt).position;
      if (poz !== 'absolute' && poz !== 'fixed') {
        /* [pole][✦][⚡] — pole zwęża się, ⚡ zostaje przy prawej krawędzi. */
        if (b.parentNode !== bolt.parentNode || b.nextSibling !== bolt) bolt.parentNode.insertBefore(b, bolt);
        ustaw(b, { position: '', top: '', right: '', width: px(w), height: px(h) });
        b.__evkPole = null;
        return;
      }
      /* ⚡ absolutny: ✦ pod nim, ta sama odległość od prawej, 4 px przerwy. */
      if (b.parentNode !== bolt.parentNode) bolt.parentNode.appendChild(b);
      const op = bolt.offsetParent;
      ustaw(b, { position: 'absolute', width: px(w), height: px(h), top: px(bolt.offsetTop + bolt.offsetHeight + 4),
        right: px((op ? op.clientWidth : 0) - bolt.offsetLeft - bolt.offsetWidth) });
      if (rodzaj === 'textarea') odsun(pole, 28);
      return;
    }
    /* Bez ⚡: tam, gdzie stałby ⚡ — róg textarea i edytora, prawa krawędź pola tekstowego. */
    const host = pole.parentNode;
    if (b.parentNode !== host || b.previousSibling !== pole) host.insertBefore(b, pole.nextSibling);
    if (okno.getComputedStyle(host).position === 'static') {
      host.__evkPozycja = host.style.position;
      host.style.position = 'relative';
      b.__evkHost = host;
    }
    const prawa = host.clientWidth - pole.offsetLeft - pole.offsetWidth;
    if (rodzaj === 'input') {
      ustaw(b, { position: 'absolute', width: '28px', height: px(pole.offsetHeight || 32), top: px(pole.offsetTop), right: px(prawa) });
      odsun(pole, 28);
    } else {
      ustaw(b, { position: 'absolute', width: '20px', height: '20px', top: px(pole.offsetTop + 4), right: px(prawa + 4) });
      if (rodzaj === 'textarea') odsun(pole, 28);
    }
  }

  /* Panel rysuje Vue powłoki od nowa przy każdym zaznaczeniu — przyciski
     dokładamy co 300 ms, jak podgląd aktywnego elementu. Nasze węzły szukamy
     w CAŁEJ kontrolce (✦ siedzi głęboko, przy ⚡), a na koniec w całym panelu
     zostaje po jednym na pole. */
  function wstawPrzyciskiPol() {
    if (!AI || !powloka) return;
    const pd = powloka.document;
    const a = aktywny();
    const widziane = new Set();
    pd.querySelectorAll('[data-controlkey^="evk_tl_"]').forEach((ctrl) => {
      const cel = celPola(ctrl, a);
      const pp = cel ? polePanelu(ctrl) : null;
      const dla = pp ? a.el.id + '|' + cel.join('|') : '';
      let b = null;
      ctrl.querySelectorAll('.evk-tl-ai-ikona').forEach((x) => {
        if (!b && dla && !widziane.has(dla) && x.__evkWlasciciel === window && x.getAttribute('data-dla') === dla) b = x;
        else usunIkone(x);
      });
      let s = null;
      ctrl.querySelectorAll('.evk-tl-ai-pole-stan').forEach((x) => {
        if (!s && b && x.__evkWlasciciel === window) s = x;
        else x.remove();
      });
      if (!dla || widziane.has(dla)) return;
      widziane.add(dla);
      const [pole, rodzaj] = pp;
      if (!b) {
        b = pd.createElement('button');
        b.type = 'button';
        b.className = 'evk-tl-ai-ikona';
        b.setAttribute('data-dla', dla);
        const etykieta = ctrl.querySelector('label');
        b.setAttribute('aria-label', 'Przetłumacz (AI) — ' + ((etykieta && etykieta.textContent.trim()) || 'Tłumaczenie ' + cel[0].toUpperCase()));
        /* Dymek Bricksa (`.bricks-panel [data-balloon]`). Panel przycina wszystko,
           co z niego wystaje — dymek na prawo (1.270.0) chował się pod kanwą —
           więc w górę i w lewo od ✦, w kilku wierszach (1.271.0, decyzja zgłaszającego). */
        b.setAttribute('data-balloon', 'Przetłumacz z polskiego używając ' + AI.model);
        b.setAttribute('data-balloon-pos', 'top-right');
        b.setAttribute('data-balloon-length', 'medium');
        b.appendChild(ikonaAi(pd, rodzaj === 'input' ? 14 : 12));
        b.addEventListener('click', () => {
          const st = ctrl.querySelector('.evk-tl-ai-pole-stan');
          if (st) tlumaczPole(b, st, cel[0], cel[1]);
        });
        b.__evkWlasciciel = window;
      }
      ulozIkone(b, pole, rodzaj, ctrl.querySelector('.dynamic-tag-picker-button'));
      if (!s) {
        s = pd.createElement('div');
        s.className = 'evk-tl-ai-pole-stan';
        s.setAttribute('role', 'status');
        s.__evkWlasciciel = window;
      }
      const c = ctrl.querySelector('.control');
      if (s.parentNode !== c) c.appendChild(s);
    });
  }

  /* Pasek rysuje Vue powłoki — po przerysowaniu grupy może nie być. Sprawdzenie
     co sekundę kosztuje mniej niż obserwowanie całej powłoki. */
  wstawPrzelacznik();
  wstawAi();
  const straz = setInterval(() => { wstawPrzelacznik(); wstawAi(); synchronizujKomponenty(); }, 1000);
  const strazPol = AI ? setInterval(() => { wstawPrzyciskiPol(); odswiezAi(); }, 300) : 0;
  window.addEventListener('pagehide', () => {
    clearInterval(straz);
    if (strazPol) clearInterval(strazPol);
    try {
      const pd = powloka && powloka.document;
      if (!pd) return;
      ['evk-tl-podglad', 'evk-tl-ai', 'evk-tl-ai-dymek', 'evk-tl-pasek'].forEach((id) => {
        const u = pd.getElementById(id);
        if (u && u.__evkWlasciciel === window) u.remove();
      });
      pd.querySelectorAll('.evk-tl-ai-ikona').forEach((u) => { if (u.__evkWlasciciel === window) usunIkone(u); });
      pd.querySelectorAll('.evk-tl-ai-pole-stan').forEach((u) => { if (u.__evkWlasciciel === window) u.remove(); });
    } catch (e) { /* powłoka już zamknięta */ }
  });

  /* Pisanie w polu panelu nie zmienia DOM kanwy (Vue nie rusza węzła, którego
     wartość `text` się nie zmieniła), więc podgląd aktywnego elementu sprawdzamy
     co 300 ms po podpisie jego ustawień. */
  let podpis = '';
  setInterval(() => {
    if (tryb === 'pl') return;
    const st = stany()[0];
    const id = st && (st.activeId || (st.activeElement && st.activeElement.id));
    if (!id) { podpis = ''; return; }
    const el = indeks().get(String(id));
    let nowy = '';
    try { nowy = id + ':' + JSON.stringify(el && el.settings); } catch (e) { nowy = ''; }
    if (nowy === podpis) return;
    podpis = nowy;
    const roots = document.querySelectorAll('[data-id="' + String(id).replace(/["\\]/g, '') + '"]');
    if (roots.length) zaplanuj(new Set(roots));
  }, 300);

  obserwator.observe(document.body, OPCJE);
  przebieg(null);
  setTimeout(() => zaplanuj(), 300);
  setTimeout(() => zaplanuj(), 1000);
})();
