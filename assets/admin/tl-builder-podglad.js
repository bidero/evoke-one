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
  const NAPISY = Object.assign({ brak: 'brak %s', edycja: 'edycja PL', grupa: 'Podgląd języka', przycisk: 'Podgląd: %s' }, DANE.napisy || {});

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
    znaczniki();
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
    znaczniki();
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
  document.addEventListener('pointerdown', (e) => { const r = rootZ(e); if (r) edycja(r); else if (edytowany) zwolnij(true); }, true);
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

  // ── Znaczniki na nakładce (bez zmiany układu strony) ────────────────────
  let nakladka = null, klatka = 0;
  function znaczniki() {
    if (!nakladka) {
      nakladka = document.createElement('div');
      nakladka.id = 'evk-tl-nakladka';
      nakladka.setAttribute('aria-hidden', 'true');
      document.body.appendChild(nakladka);
    }
    const lista = [];
    if (tryb !== 'pl') {
      braki.forEach((r) => { if (r.isConnected && r !== edytowany) lista.push([r, NAPISY.brak.replace('%s', tryb.toUpperCase()), 'brak']); });
      if (edytowany && edytowany.isConnected) lista.push([edytowany, NAPISY.edycja, 'edycja']);
    }
    wstrzymaj(() => {
      nakladka.textContent = '';
      lista.forEach(([r, napis, rodzaj]) => {
        const b = r.getBoundingClientRect();
        if (!b.width && !b.height) return;
        const z = document.createElement('span');
        z.className = 'evk-tl-znacznik ' + rodzaj;
        z.textContent = napis;
        z.style.left = Math.max(0, b.left) + 'px';
        z.style.top = Math.max(0, b.top) + 'px';
        nakladka.appendChild(z);
      });
    });
  }
  const przeliczZnaczniki = () => { if (!klatka) klatka = requestAnimationFrame(() => { klatka = 0; znaczniki(); }); };
  window.addEventListener('scroll', przeliczZnaczniki, true);
  window.addEventListener('resize', przeliczZnaczniki);

  const styl = document.createElement('style');
  styl.id = 'evk-tl-podglad-kanwa';
  styl.textContent = '.evk-tl-brak{outline:2px dashed #f59e0b !important;outline-offset:2px}'
    + '.evk-tl-edycja{outline:2px solid #3b82f6 !important;outline-offset:2px}'
    + '#evk-tl-nakladka{position:fixed;inset:0;pointer-events:none;z-index:2147483000}'
    + '#evk-tl-nakladka .evk-tl-znacznik{position:absolute;transform:translateY(-100%);font:600 10px/1.5 system-ui,sans-serif;padding:0 5px;border-radius:3px;background:#f59e0b;color:#1f2937;white-space:nowrap}'
    + '#evk-tl-nakladka .evk-tl-znacznik.edycja{background:#3b82f6;color:#fff}';
  document.head.appendChild(styl);

  // ── Przełącznik w pasku powłoki ─────────────────────────────────────────
  function ustawTryb(j) {
    if (JEZYKI.indexOf(j) === -1 || j === tryb) return;
    if (edytowany) zwolnij(true);
    tryb = j;
    if (powloka) powloka.__evkTlTryb = j;
    odswiezPrzelacznik();
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
    if (!pd.getElementById('evk-tl-podglad-styl')) {
      const s = pd.createElement('style');
      s.id = 'evk-tl-podglad-styl';
      s.textContent = '#evk-tl-podglad{display:flex;align-items:center;gap:2px;margin:0 8px;padding:0;list-style:none}'
        + '#evk-tl-podglad li{margin:0;padding:0;list-style:none}'
        + '#evk-tl-podglad button{min-width:32px;height:28px;padding:0 8px;border:0;border-radius:4px;background:transparent;color:inherit;font:600 12px/1 inherit;letter-spacing:.02em;cursor:pointer;opacity:.75}'
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
      b.title = NAPISY.przycisk.replace('%s', j.toUpperCase());
      b.textContent = j.toUpperCase();
      b.addEventListener('click', () => ustawTryb(j));
      li.appendChild(b);
      ul.appendChild(li);
    });
    ul.__evkWlasciciel = window;
    const srodek = pasek.querySelector('.group-wrapper.center');
    if (srodek && srodek.parentNode === pasek) srodek.insertAdjacentElement('afterend', ul);
    else pasek.appendChild(ul);
  }

  /* Pasek rysuje Vue powłoki — po przerysowaniu grupy może nie być. Sprawdzenie
     co sekundę kosztuje mniej niż obserwowanie całej powłoki. */
  wstawPrzelacznik();
  const straz = setInterval(wstawPrzelacznik, 1000);
  window.addEventListener('pagehide', () => {
    clearInterval(straz);
    try {
      const u = powloka && powloka.document.getElementById('evk-tl-podglad');
      if (u && u.__evkWlasciciel === window) u.remove();
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
