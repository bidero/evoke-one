/**
 * Właściwości tłumaczeń komponentów Bricksa (1.272.0) — to samo co
 * evk_tl_kp_uzupelnij() w includes/51-translation-components.php, na stanie
 * buildera. Builder dostaje „Nagłówek EN” pod „Nagłówek” od razu, bez
 * zapisu i przeładowania; serwer robi to samo przy zapisie komponentów.
 *
 * Identyfikator bliźniaka: FNV-1a 32 bit z „komponent|właściwość|język”,
 * 6 małych liter — taki sam jak w PHP (test `tl-komponenty` porównuje oba
 * na wspólnych przypadkach). Moduł czysty: bez DOM, działa też w Node.
 */
(function (root) {
  'use strict';

  const TYPY = ['text'];

  function id(komponent, wlasciwosc, lang) {
    const s = new TextEncoder().encode(String(komponent) + '|' + String(wlasciwosc) + '|' + String(lang));
    let h = 0x811c9dc5;
    for (let i = 0; i < s.length; i++) {
      h ^= s[i];
      h = Math.imul(h, 0x01000193) >>> 0;
    }
    let w = '';
    for (let i = 0; i < 6; i++) {
      w += String.fromCharCode(97 + (h % 26));
      h = Math.floor(h / 26);
    }
    return w;
  }

  const kodJezyka = (j) => String(j).toLowerCase().replace(/[^a-z0-9_]/g, '_');
  const kluczJezyka = (j, k) => 'evk_tl_' + kodJezyka(j) + '__' + k;
  const obiekt = (v) => !!v && typeof v === 'object' && !Array.isArray(v);

  /** Połączenia z polami tłumaczonymi (mapa pól typu elementu), bez kluczy języków. */
  function tlumaczone(wl, typy, mapa) {
    const out = {};
    const pol = obiekt(wl.connections) ? wl.connections : {};
    Object.keys(pol).forEach((el) => {
      const def = mapa[typy[el] || ''];
      const pola = def && Array.isArray(def.pola) ? def.pola : [];
      (Array.isArray(pol[el]) ? pol[el] : []).forEach((k) => {
        k = String(k);
        if (k.indexOf('evk_tl_') !== 0 && pola.indexOf(k) !== -1) (out[el] = out[el] || []).push(k);
      });
    });
    return out;
  }

  /** [język, połączenia bez przedrostka] albo null — wszystkie klucze to `evk_tl_{język}__…` jednego języka. */
  function jakoBlizniak(wl) {
    let lang = null;
    const pl = {};
    const pol = obiekt(wl.connections) ? wl.connections : {};
    for (const el of Object.keys(pol)) {
      for (const k of (Array.isArray(pol[el]) ? pol[el] : [])) {
        const m = /^evk_tl_([a-z0-9_]+?)__(.+)$/.exec(String(k));
        if (!m) return null;
        if (lang !== null && lang !== m[1]) return null;
        lang = m[1];
        (pl[el] = pl[el] || []).push(m[2]);
      }
    }
    return lang === null ? null : [lang, pl];
  }

  /** Czy mapa pól zna typy wszystkich połączonych elementów (jak w PHP). */
  function typyZnane(wl, typy, mapa) {
    return Object.keys(obiekt(wl.connections) ? wl.connections : {}).every((el) => typy[el] === undefined || !!mapa[typy[el]]);
  }

  function rowne(a, b) {
    const n = (c) => {
      const o = {};
      Object.keys(c || {}).sort().forEach((el) => { o[el] = (Array.isArray(c[el]) ? c[el] : []).map(String).sort(); });
      return JSON.stringify(o);
    };
    return n(a) === n(b);
  }

  const zId = (p) => p && (typeof p.id === 'string' || typeof p.id === 'number');

  /**
   * Uzupełnia `properties` każdego komponentu W MIEJSCU (stan Vue) i mówi,
   * czy coś się zmieniło. Bez zmian — tablica nietknięta (Bricks nie widzi
   * fałszywej zmiany).
   */
  function uzupelnij(komponenty, jezyki, mapa) {
    let zmiana = false;
    (Array.isArray(komponenty) ? komponenty : []).forEach((k) => {
      if (!obiekt(k) || !Array.isArray(k.properties)) return;
      const nowe = noweWlasciwosci(k, jezyki, mapa || {});
      if (nowe === null) return;
      k.properties.splice(0, k.properties.length, ...nowe);
      zmiana = true;
    });
    return zmiana;
  }

  /** Nowa lista właściwości albo null, gdy bez zmian. Kolejność kroków jak w PHP. */
  function noweWlasciwosci(k, jezyki, mapa) {
    const cid = String(k.id);
    const typy = {};
    const zajete = {};
    (Array.isArray(k.elements) ? k.elements : []).forEach((e) => {
      if (!obiekt(e)) return;
      typy[String(e.id)] = String(e.name || '');
      zajete[String(e.id)] = true;
    });
    const przed = k.properties.filter(obiekt);
    const props = przed.map((p) => Object.assign({}, p));
    props.forEach((p) => { if (zId(p)) zajete[String(p.id)] = true; });
    const poId = {};
    props.forEach((p, i) => { if (zId(p)) poId[String(p.id)] = i; });
    const blizniaki = {};
    const uzyte = {};
    props.slice().forEach((p) => {
      if (TYPY.indexOf(String(p.type || '')) === -1 || !zId(p)) return;
      const tl = tlumaczone(p, typy, mapa);
      if (!Object.keys(tl).length) return;
      const pid = String(p.id);
      jezyki.forEach((lang) => {
        const kod = kodJezyka(lang);
        const chce = {};
        Object.keys(tl).forEach((el) => { chce[el] = tl[el].map((x) => kluczJezyka(lang, x)); });
        const etykieta = String(p.label || '').trim() + ' ' + kod.toUpperCase();
        let nasz = id(cid, pid, kod);
        if (poId[nasz] !== undefined) {
          props[poId[nasz]].connections = chce;
          props[poId[nasz]].label = etykieta;
          (blizniaki[pid] = blizniaki[pid] || []).push(nasz);
          uzyte[nasz] = true;
          return;
        }
        const reczny = props.find((t) => zId(t) && !uzyte[String(t.id)] && rowne(obiekt(t.connections) ? t.connections : {}, chce));
        if (reczny) {
          (blizniaki[pid] = blizniaki[pid] || []).push(String(reczny.id));
          uzyte[String(reczny.id)] = true;
          return;
        }
        for (let s = 1; zajete[nasz]; s++) nasz = id(cid, pid + '#' + s, kod);
        zajete[nasz] = true;
        props.push({ label: etykieta, type: String(p.type), id: nasz, connections: chce });
        poId[nasz] = props.length - 1;
        (blizniaki[pid] = blizniaki[pid] || []).push(nasz);
        uzyte[nasz] = true;
      });
    });
    /* Bliźniak wyłączonego języka zostaje pod swoją właściwością — jak w PHP. */
    const plTl = {};
    props.forEach((p) => {
      if (TYPY.indexOf(String(p.type || '')) === -1 || !zId(p)) return;
      const tl = tlumaczone(p, typy, mapa);
      if (Object.keys(tl).length) plTl[String(p.id)] = tl;
    });
    props.forEach((t) => {
      const tid = zId(t) ? String(t.id) : '';
      const b = tid !== '' && !uzyte[tid] ? jakoBlizniak(t) : null;
      if (!b) return;
      const pid = Object.keys(plTl).find((x) => rowne(plTl[x], b[1]));
      if (pid) { (blizniaki[pid] = blizniaki[pid] || []).push(tid); uzyte[tid] = true; }
    });
    const nowe = [];
    props.forEach((p) => {
      const pid = zId(p) ? String(p.id) : '';
      if (pid !== '' && uzyte[pid]) return;
      /* Osierocony bliźniak znika tylko przy mapie znającej jego elementy — jak w PHP (1.274.0). */
      if (jakoBlizniak(p) && / [A-Z0-9_]{2,}$/.test(String(p.label || '')) && typyZnane(p, typy, mapa)) return;
      nowe.push(p);
      (blizniaki[pid] || []).forEach((b) => nowe.push(props[poId[b]]));
    });
    return JSON.stringify(nowe) === JSON.stringify(przed) ? null : nowe;
  }

  /** Pary komponentu: id właściwości PL → { etykieta, blizniaki: { język: id } }. */
  function pary(k, mapa) {
    const out = {};
    if (!obiekt(k)) return out;
    const typy = {};
    (Array.isArray(k.elements) ? k.elements : []).forEach((e) => { if (obiekt(e)) typy[String(e.id)] = String(e.name || ''); });
    const props = (Array.isArray(k.properties) ? k.properties : []).filter(obiekt);
    const pol = {};
    props.forEach((p) => {
      if (TYPY.indexOf(String(p.type || '')) === -1 || !zId(p)) return;
      const tl = tlumaczone(p, typy, mapa || {});
      if (Object.keys(tl).length) { out[String(p.id)] = { etykieta: String(p.label || ''), blizniaki: {} }; pol[String(p.id)] = tl; }
    });
    props.forEach((t) => {
      const b = jakoBlizniak(t);
      if (!b || !zId(t)) return;
      const pid = Object.keys(out).find((x) => !out[x].blizniaki[b[0]] && rowne(pol[x], b[1]));
      if (pid) out[pid].blizniaki[b[0]] = String(t.id);
    });
    return out;
  }

  const api = { id, uzupelnij, noweWlasciwosci, pary };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.evkTlKomponenty = api;
})(typeof window !== 'undefined' ? window : this);
