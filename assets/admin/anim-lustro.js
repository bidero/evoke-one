/**
 * Lustro listy Animatora w Atrybutach (1.275.0) — kopiowanie animacji
 * z elementu na element natywnym menu Bricksa.
 *
 * Pomiar w prawdziwym builderze (Bricks 2.4.2, test bricks-builder):
 *  - „Copy → All styles” nie przenosi listy `evkAnimList` (ani żadnego klucza
 *    spoza kontrolek stylu Bricksa, także z podkreśleniem na początku);
 *  - ikonka „Attributes” przy „Copy” jest TYLKO przy niepustych Atrybutach,
 *    a „Paste → Attributes” ZASTĘPUJE całą listę Atrybutów elementu
 *    (schowek systemowy, `source: bricksCopiedElementAttributes`);
 *  - prawy klik zaznacza element (`activeId`), a otwarte menu reaguje na
 *    Atrybuty dodane po otwarciu.
 *
 * Stąd: element zaznaczony albo kliknięty prawym przyciskiem, który ma listę,
 * dostaje w Atrybutach wiersz `data-evk-anim` z wierszami listy (bez `id`).
 * Zmiana listy poprawia wiersz; zmiana wiersza (wklejenie, ręczna poprawka)
 * przepisuje listę — ZASTĘPUJE ją (decyzja zgłaszającego). Serwer czyta taki
 * wiersz jak listę (evk_bricks_anim_lustro(), bricks-controls.php), więc front
 * — z zasłoną — wygląda tak samo z lustrem i bez.
 *
 * Elementów nie ruszamy przy otwarciu strony (builder pokazałby niezapisane
 * zmiany), tylko te, których ktoś dotknął. Ręczny wpis sprzed lustra (goła
 * nazwa, pojedynczy obiekt) zostaje, jak był. Lustro usunięte z Atrybutów przy
 * niepustej liście wraca — żeby go nie było, usuwa się wiersze listy.
 */
(function (root) {
  'use strict';

  const ATR = 'data-evk-anim';
  const obiekt = (v) => !!v && typeof v === 'object' && !Array.isArray(v);

  /** Wiersze listy bez pól buildera (`id`) — treść lustra. */
  function wiersze(lista) {
    return (Array.isArray(lista) ? lista : []).filter(obiekt).map((w) => {
      const o = {};
      Object.keys(w).forEach((k) => { if (k !== 'id') o[k] = w[k]; });
      return o;
    });
  }

  /** Wartość lustra; '' — lista pusta. */
  function wartosc(lista) {
    const w = wiersze(lista);
    return w.length ? JSON.stringify(w) : '';
  }

  /** Wiersze z wartości atrybutu albo null (tylko tablica obiektów z `animation`, jak w PHP). */
  function zAtrybutu(v) {
    let w;
    try { w = JSON.parse(String(v)); } catch (e) { return null; }
    if (!Array.isArray(w) || !w.length) return null;
    return w.every((r) => obiekt(r) && typeof r.animation === 'string') ? w : null;
  }

  function idWiersza() {
    let s = '';
    for (let i = 0; i < 6; i++) s += String.fromCharCode(97 + Math.floor(Math.random() * 26));
    return s;
  }

  function wierszAtrybutu(e) {
    const a = e.settings && Array.isArray(e.settings._attributes) ? e.settings._attributes : [];
    return a.find((r) => obiekt(r) && r.name === ATR) || null;
  }

  /**
   * Jeden krok dla elementu. `pamiec` — Map id → {l, a}: lista i atrybut po
   * ostatnim kroku. Zmienia `e.settings` w miejscu; zwraca, co zrobił:
   * '' | 'lustro' | 'lista' | 'usuniete'.
   */
  function krok(e, pamiec) {
    /* Ustawienia `[]` (pusty element z PHP) — bez listy, nie ma czego lustrzyć. */
    if (!e || !obiekt(e.settings)) return '';
    const id = String(e.id);
    const s = e.settings;
    const l = wartosc(s.evkAnimList);
    let r = wierszAtrybutu(e);
    const a = r ? String(r.value ?? '') : null;
    const byl = pamiec.get(id);
    let co = '';

    const dodaj = () => {
      s._attributes = (Array.isArray(s._attributes) ? s._attributes : []).concat([{ id: idWiersza(), name: ATR, value: l }]);
      co = 'lustro';
    };

    if (!byl || l === byl.l) {
      if (byl && a !== null && a !== byl.a) {
        /* Atrybut zmieniony (wklejenie, ręczna poprawka) — lista za nim, zastąpiona. */
        const w = zAtrybutu(a);
        if (w && wartosc(w) !== l) {
          s.evkAnimList = w.map((x) => Object.assign({ id: idWiersza() }, x));
          co = 'lista';
        }
      } else if (l && a === null) {
        /* Lista bez wiersza — lustro. PILNOWANE w każdym kroku, nie raz: dopisane
           w chwili prawego kliku Bricks gubi przy zaznaczaniu (prawdziwy builder),
           więc wraca w następnym kroku. Usunięte ręcznie też wraca — źródłem
           jest lista. Ręczny wpis sprzed lustra (a !== null) zostaje. */
        dodaj();
      }
    } else {
      /* Lista zmieniona w panelu — lustro za nią. Cudzy wpis ręczny zostaje. */
      const lustro = a !== null && zAtrybutu(a) !== null;
      if (!l) {
        if (r && lustro) {
          s._attributes = s._attributes.filter((x) => x !== r);
          co = 'usuniete';
        }
      } else if (r) {
        if (lustro) { r.value = l; co = 'lustro'; }
      } else {
        dodaj();
      }
    }
    r = wierszAtrybutu(e);
    pamiec.set(id, { l: wartosc(s.evkAnimList), a: r ? String(r.value ?? '') : null });
    return co;
  }

  const api = { wiersze, wartosc, zAtrybutu, krok };
  if (typeof module === 'object' && module.exports) { module.exports = api; return; }
  root.evkAnimLustro = api;

  // ── Builder: kanwa, stan powłoki ─────────────────────────────────────────
  if (!root.document || root.__evkAnimLustro) return;
  let powloka = null;
  try { powloka = root.parent && root.parent !== root && root.parent.document ? root.parent : null; } catch (e) { powloka = null; }
  if (!powloka) return;
  const pamiec = new Map();
  root.__evkAnimLustro = { pamiec, kroki: 0 };

  function stan() {
    try {
      const el = powloka.document.querySelector('.brx-body.main');
      const app = el && el.__vue_app__;
      return (app && app.config && app.config.globalProperties && app.config.globalProperties.$_state) || null;
    } catch (e) { return null; }
  }
  function element(st, id) {
    const obszary = [st.content, st.header, st.footer, st.activeComponent && st.activeComponent.elements];
    for (const tab of obszary) {
      if (!Array.isArray(tab)) continue;
      const e = tab.find((x) => x && String(x.id) === String(id));
      if (e) return e;
    }
    return null;
  }
  function dotknij(id) {
    const st = stan();
    const e = st && id ? element(st, id) : null;
    if (e) { krok(e, pamiec); root.__evkAnimLustro.kroki++; }
  }

  /* Prawy klik: lustro PRZED menu Bricksa (faza przechwytywania), w kanwie
     i w panelu struktury — ikonka „Attributes” od razu przy „Copy”. */
  function naMenu(ev) {
    const t = ev.target && ev.target.closest ? ev.target.closest('[data-id]') : null;
    if (t) dotknij(t.getAttribute('data-id'));
  }
  root.document.addEventListener('contextmenu', naMenu, true);
  powloka.document.addEventListener('contextmenu', naMenu, true);

  /* Zaznaczony element i każdy już dotknięty — co 300 ms, jak przyciski ✦. */
  const straz = setInterval(() => {
    const st = stan();
    if (!st) return;
    const akt = st.activeId || (st.activeElement && st.activeElement.id);
    if (akt) dotknij(akt);
    pamiec.forEach((_, id) => { if (String(id) !== String(akt)) dotknij(id); });
  }, 300);
  root.addEventListener('pagehide', () => {
    clearInterval(straz);
    try { powloka.document.removeEventListener('contextmenu', naMenu, true); } catch (e) { /* powłoka zamknięta */ }
  });
})(typeof window !== 'undefined' ? window : this);
