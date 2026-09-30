/**
 * Lista „Teksty w elementach" (1.263.0) — edycja tłumaczenia pod wierszem.
 *
 * Decyzje zgłaszającego (30.09): „Edytuj" przy języku otwiera pod wierszem
 * cały polski tekst, pole tłumaczenia i przyciski (Zapisz, Sprawdzone,
 * Przetłumacz ponownie, Przywróć); „Zapisz" oznacza jako sprawdzony tylko
 * ten tekst — wiersz listy to jedno pole w jednym języku.
 *
 * Dane: <script type="application/json" id="tl-teksty-dane"> (53,
 * evk_tl_el_widok_tekstow()) — wiersze tej strony listy z pełnymi tekstami
 * (tabela pokazuje skróty). Zapis i „Przetłumacz ponownie" przez AJAX trybu
 * sprawdzania (62): te same warunki (nonce, dostęp do Tłumaczeń, prawo edycji
 * strony, wp_kses_post bez unfiltered_html) i ten sam zapis (wykaz
 * dopisanych, poprzednia wersja do „Przywróć").
 *
 * Tabela na telefonie przewija się w poziomie, więc edytor stoi przyklejony
 * do lewej krawędzi obszaru przewijania i ma jego szerokość — inaczej pole
 * tłumaczenia wystawałoby poza ekran razem z szeroką tabelą.
 */
(function () {
  'use strict';

  const daneEl = document.getElementById('tl-teksty-dane');
  const lista = document.querySelector('.tl-teksty');
  if (!daneEl || !lista) return;
  let DANE;
  try { DANE = JSON.parse(daneEl.textContent || '{}'); } catch (e) { return; }
  const WIERSZE = DANE.wiersze || {};

  const OPISY = {
    ai: 'AI — do sprawdzenia', zmiana: 'Zmienił się polski tekst', ok: 'Przetłumaczone',
    slownik: 'Ze słownika', czesc: 'Częściowo ze słownika', brak: 'Brak tłumaczenia',
  };
  const POCHODZENIE = { pole: 'w elemencie', slownik: 'ze słownika', czesc: 'słownik: część tekstu', brak: 'brak tłumaczenia' };
  const krotkiModel = (m) => String(m || '').replace(/^[a-z]+\//, '');
  const krotkaNazwa = (n) => String(n || '').split(/ [(—]/)[0];
  /* Dostawca i model do „Przetłumacz ponownie" — pamiętane między wierszami. */
  const wyborAi = { dostawca: DANE.ai ? DANE.ai.domyslny : '', model: '' };

  /* Otwarty edytor: wiersz listy, język, <tr> edytora, model tekstu w polu
     i poprzedni tekst z modelem („Przywróć" zamienia je miejscami). */
  let otwarte = null;

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

  /* Tekst z HTML-u bez wykonywania czegokolwiek; koniec bloku daje odstęp
     (jak evk_tl_el_skrot_tekstu() w 53). */
  function tekstZHtml(html) {
    const d = new DOMParser().parseFromString('<body>' + String(html || '').replace(/<\/(p|div|li|h[1-6]|blockquote)>|<br\s*\/?>/gi, '$& '), 'text/html');
    return (d.body.textContent || '').replace(/\s+/g, ' ').trim();
  }
  function skrot(html) {
    const t = tekstZHtml(html);
    return t.length > 80 ? t.slice(0, 79) + '…' : t;
  }

  /** Komórka języka po zapisie — tak jak rysuje ją PHP (53). */
  function rysujKomorke(i, j) {
    const td = lista.querySelector('td[data-w="' + i + '"][data-j="' + j + '"]');
    const l = WIERSZE[i] && WIERSZE[i].jezyki[j];
    if (!td || !l) return;
    const przycisk = td.querySelector('.tl-teksty-edytuj');
    const zrodlo = l.tl ? 'pole' : (l.stan === 'slownik' || l.stan === 'czesc' ? l.stan : 'brak');
    const tekst = l.tl || l.slownik || '';
    td.textContent = '';
    if (tekst) { td.appendChild(document.createTextNode(skrot(tekst))); td.appendChild(el('br')); }
    td.appendChild(el('span', { class: zrodlo === 'brak' || zrodlo === 'czesc' ? 'evo-danger-tx' : 'evo-faint' }, POCHODZENIE[zrodlo]));
    if (l.stan === 'ai' || l.stan === 'zmiana') {
      td.appendChild(el('br'));
      td.appendChild(el('span', { class: 'evo-accent-tx' }, 'do sprawdzenia'));
    }
    if (przycisk) { td.appendChild(el('br')); td.appendChild(przycisk); }
  }

  function czesc(sel) { return otwarte ? otwarte.tr.querySelector(sel) : null; }

  function ustawStan(tekst, blad) {
    const s = czesc('.tl-teksty-stan');
    if (!s) return;
    s.textContent = tekst;
    s.classList.toggle('evo-danger-tx', !!blad);
  }

  function blokuj(tak) {
    if (otwarte) otwarte.tr.querySelectorAll('button').forEach((b) => { b.disabled = tak; });
  }

  /* Szerokość edytora = widoczna szerokość obszaru przewijania tabeli. */
  function dopasuj() {
    const ed = czesc('.tl-teksty-edytor');
    const wrap = ed && ed.closest('.evo-tbl-wrap');
    if (!ed || !wrap) return;
    const td = ed.parentElement;
    const cs = getComputedStyle(td);
    ed.style.width = Math.max(220, wrap.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - 2) + 'px';
  }

  function rysujPoprz() {
    const w = czesc('.tl-teksty-poprz');
    if (!w) return;
    const x = otwarte.poprz;
    w.hidden = !(x && x.t);
    if (w.hidden) return;
    w.querySelector('.tl-teksty-poprz-glowa').textContent = 'Poprzednio' + (x.m ? ' (' + krotkiModel(x.m) + ')' : '') + ':';
    w.querySelector('.tl-teksty-poprz-tekst').textContent = tekstZHtml(x.t);
  }

  /** Niezapisana zmiana w polu — przy zmianie wiersza pytamy, czy ją porzucić. */
  function porzucic() {
    const pole = czesc('#tl-teksty-pole');
    return !pole || pole.value === pole.defaultValue || window.confirm('Porzucić niezapisaną zmianę tłumaczenia?');
  }

  function zamknij(fokus) {
    if (!otwarte) return;
    const { i, j, tr } = otwarte;
    tr.remove();
    otwarte = null;
    if (!fokus) return;
    const b = lista.querySelector('.tl-teksty-edytuj[data-w="' + i + '"][data-j="' + j + '"]');
    if (b) b.focus();
  }

  function otworz(i, j) {
    const w = WIERSZE[i];
    const l = w && w.jezyki[j];
    const wiersz = lista.querySelector('tr[data-w="' + i + '"]');
    if (!l || !wiersz) return;
    zamknij(false);
    const J = String(j).toUpperCase();
    const ed = el('div', { class: 'tl-teksty-edytor', role: 'group', 'aria-labelledby': 'tl-teksty-tytul' });
    const znak = el('span', { class: 'tl-teksty-znak', 'data-stan': l.stan }, OPISY[l.stan] || l.stan);
    if (l.model) znak.appendChild(el('span', { class: 'tl-teksty-znak-model' }, ' · ' + krotkiModel(l.model)));
    ed.appendChild(el('p', { id: 'tl-teksty-tytul', class: 'tl-teksty-tytul' },
      [el('strong', {}, J), ' · ' + w.tytul + ' · ' + w.element + ' (' + w.opis + ') ', znak]));
    ed.appendChild(el('p', { class: 'tl-teksty-pl' }, [el('span', { class: 'tl-teksty-pl-kod' }, 'PL'), ' ' + tekstZHtml(w.pl)]));
    ed.appendChild(el('label', { for: 'tl-teksty-pole', class: 'tl-teksty-etykieta' }, 'Tłumaczenie ' + J));
    const dl = Math.max(String(l.tl || '').length, String(w.pl || '').length);
    const pole = el('textarea', { id: 'tl-teksty-pole', rows: Math.min(8, Math.max(2, Math.ceil(dl / 70))), lang: j, spellcheck: 'true' });
    pole.value = l.tl || '';
    pole.defaultValue = l.tl || '';
    ed.appendChild(pole);
    if ((l.stan === 'slownik' || l.stan === 'czesc') && l.slownik) {
      ed.appendChild(el('p', { class: 'tl-teksty-uwaga' }, 'Na stronie ze słownika: „' + tekstZHtml(l.slownik) + '”. Tłumaczenie wpisane tutaj ma pierwszeństwo.'));
    }
    if (/<[a-z]/i.test(w.pl || '')) ed.appendChild(el('p', { class: 'tl-teksty-uwaga' }, 'Znaczniki HTML zachowaj jak w oryginale.'));
    ed.appendChild(el('div', { class: 'tl-teksty-poprz', hidden: true }, [
      el('p', {}, [el('span', { class: 'tl-teksty-poprz-glowa' }, ''), ' ', el('span', { class: 'tl-teksty-poprz-tekst' }, '')]),
      el('button', { type: 'button', class: 'button tl-teksty-przywroc', 'aria-label': 'Przywróć poprzednie tłumaczenie ' + J }, 'Przywróć'),
    ]));
    const akcje = el('p', { class: 'tl-teksty-akcje' });
    akcje.appendChild(el('button', { type: 'button', class: 'button button-primary tl-teksty-zapisz' }, 'Zapisz'));
    if (l.stan === 'ai' || l.stan === 'zmiana') akcje.appendChild(el('button', { type: 'button', class: 'button tl-teksty-sprawdzone' }, 'Sprawdzone'));
    if (DANE.ai) akcje.appendChild(el('button', { type: 'button', class: 'button tl-teksty-ai' }, l.tl ? 'Przetłumacz ponownie' : 'Przetłumacz (AI)'));
    akcje.appendChild(el('button', { type: 'button', class: 'button tl-teksty-nastepny' }, 'Następny ›'));
    if (l.adres) akcje.appendChild(el('a', { class: 'button', href: l.adres, 'aria-label': 'Na stronie: ' + w.tytul + ', ' + J }, 'Na stronie'));
    akcje.appendChild(el('button', { type: 'button', class: 'button tl-teksty-zamknij' }, 'Zamknij'));
    ed.appendChild(akcje);
    if (DANE.ai) {
      const wybor = el('select', { id: 'tl-teksty-ai-dostawca' });
      Object.keys(DANE.ai.dostawcy).forEach((k) => {
        const d = DANE.ai.dostawcy[k];
        const o = el('option', { value: k }, krotkaNazwa(d.nazwa) + ' · ' + d.model);
        if (k === wyborAi.dostawca) o.selected = true;
        wybor.appendChild(o);
      });
      const model = el('input', { type: 'text', id: 'tl-teksty-ai-model', spellcheck: 'false', 'aria-label': 'Model (puste — z ustawień)',
        placeholder: (DANE.ai.dostawcy[wybor.value] || {}).model || '' });
      model.value = wyborAi.model;
      wybor.addEventListener('change', () => {
        wyborAi.dostawca = wybor.value;
        model.placeholder = (DANE.ai.dostawcy[wybor.value] || {}).model || '';
      });
      model.addEventListener('input', () => { wyborAi.model = model.value.trim(); });
      ed.appendChild(el('p', { class: 'tl-teksty-ai-wybor' }, [el('label', { for: 'tl-teksty-ai-dostawca' }, 'Model AI'), wybor, model]));
    }
    ed.appendChild(el('p', { class: 'tl-teksty-podpowiedz evo-faint' }, 'Ctrl+Enter — zapisz, Esc — zamknij.'));
    ed.appendChild(el('p', { class: 'tl-teksty-stan', role: 'status' }));

    const tr = el('tr', { class: 'tl-teksty-edycja' }, [el('td', { colspan: DANE.kolumny || 5 }, ed)]);
    wiersz.after(tr);
    otwarte = { i: i, j: j, tr: tr, obecny: l.model || '', poprz: l.poprz && l.poprz.t ? { t: l.poprz.t, m: l.poprz.m || '' } : null };
    rysujPoprz();
    dopasuj();
    pole.focus({ preventScroll: true });
    tr.scrollIntoView({ block: 'nearest' });
  }

  async function wyslij(pary) {
    const fd = new FormData();
    pary.forEach(([k, v]) => fd.append(k, v));
    try {
      return await (await fetch(DANE.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })).json();
    } catch (e) { return null; }
  }

  async function zapisz(tylkoSprawdzone) {
    if (!otwarte) return;
    const { i, j } = otwarte;
    const w = WIERSZE[i];
    const l = w.jezyki[j];
    const pole = czesc('#tl-teksty-pole');
    const pary = [['action', 'evk_tl_sprawdz_zapisz'], ['nonce', DANE.nonce || ''], ['post_id', w.post], ['meta_key', w.meta],
      ['lang', j], ['element', w.id], ['sciezki[]', w.sciezka]];
    if (!tylkoSprawdzone && pole && pole.value !== pole.defaultValue) pary.push(['pola[' + w.sciezka + ']', pole.value]);
    ustawStan('Zapisuję…');
    blokuj(true);
    const r = await wyslij(pary);
    blokuj(false);
    if (!otwarte || otwarte.i !== i || otwarte.j !== j) return;
    if (!r || !r.success) { ustawStan((r && r.data) || 'Błąd zapisu — spróbuj jeszcze raz.', true); return; }
    const p = ((r.data && r.data.pola) || []).find((x) => x.sciezka === w.sciezka);
    if (p) Object.assign(l, { tl: p.tl || '', stan: p.stan, model: p.model || '', poprz: p.poprz || null, slownik: p.slownik || '' });
    rysujKomorke(i, j);
    otworz(i, j);
    ustawStan('Zapisano.');
    const dalej = czesc('.tl-teksty-nastepny');
    if (dalej) dalej.focus({ preventScroll: true });
  }

  async function ponownie(przycisk) {
    if (!otwarte) return;
    const { i, j } = otwarte;
    const w = WIERSZE[i];
    const pole = czesc('#tl-teksty-pole');
    ustawStan('Tłumaczę…');
    przycisk.disabled = true;
    const r = await wyslij([['action', 'evk_tl_sprawdz_ai'], ['nonce', DANE.nonce || ''], ['post_id', w.post], ['meta_key', w.meta],
      ['lang', j], ['element', w.id], ['sciezka', w.sciezka], ['dostawca', wyborAi.dostawca], ['model', wyborAi.model]]);
    przycisk.disabled = false;
    if (!otwarte || otwarte.i !== i || otwarte.j !== j) return;
    if (!r || !r.success) { ustawStan((r && r.data) || 'Błąd tłumaczenia — spróbuj jeszcze raz.', true); return; }
    if (r.data.tekst === pole.value) {
      ustawStan('Ten sam wynik — ' + krotkiModel(r.data.model) + ' przy tych ustawieniach tłumaczy tak samo.');
      return;
    }
    if (pole.value) otwarte.poprz = { t: pole.value, m: otwarte.obecny || '' };
    otwarte.obecny = r.data.model;
    pole.value = r.data.tekst;
    rysujPoprz();
    ustawStan('Nowe tłumaczenie (' + krotkiModel(r.data.model) + ') — sprawdź i zapisz.');
    pole.focus({ preventScroll: true });
  }

  /* „Przywróć": tekst w polu i poprzedni zamieniają się miejscami — drugi
     klik wraca. Zapis dopiero „Zapisz". */
  function przywroc() {
    const pole = czesc('#tl-teksty-pole');
    const x = otwarte && otwarte.poprz;
    if (!pole || !x) return;
    otwarte.poprz = { t: pole.value, m: otwarte.obecny || '' };
    otwarte.obecny = x.m;
    pole.value = x.t;
    rysujPoprz();
    ustawStan('Przywrócone w polu — zapisz, jeśli ma zostać.');
  }

  /** Następny wiersz tej strony listy w tym samym języku (tylko do edycji). */
  function nastepny() {
    if (!otwarte) return;
    const { i, j } = otwarte;
    const dalej = Object.keys(WIERSZE).map(Number).sort((a, b) => a - b)
      .find((k) => k > i && WIERSZE[k].edycja && WIERSZE[k].jezyki[j]);
    if (dalej === undefined) { ustawStan('To ostatni tekst na tej stronie listy.'); return; }
    if (porzucic()) otworz(dalej, j);
  }

  lista.addEventListener('click', (ev) => {
    const b = ev.target instanceof Element ? ev.target.closest('button') : null;
    if (!b || b.disabled) return;
    if (b.classList.contains('tl-teksty-edytuj')) {
      const i = +b.getAttribute('data-w');
      const j = b.getAttribute('data-j');
      if (otwarte && otwarte.i === i && otwarte.j === j) { czesc('#tl-teksty-pole').focus(); return; }
      if (porzucic()) otworz(i, j);
    } else if (b.classList.contains('tl-teksty-zapisz')) zapisz(false);
    else if (b.classList.contains('tl-teksty-sprawdzone')) zapisz(true);
    else if (b.classList.contains('tl-teksty-ai')) ponownie(b);
    else if (b.classList.contains('tl-teksty-przywroc')) przywroc();
    else if (b.classList.contains('tl-teksty-nastepny')) nastepny();
    else if (b.classList.contains('tl-teksty-zamknij')) zamknij(true);
  });

  lista.addEventListener('keydown', (ev) => {
    if (!otwarte || !otwarte.tr.contains(ev.target)) return;
    if (ev.key === 'Escape') { ev.preventDefault(); if (porzucic()) zamknij(true); return; }
    if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); zapisz(false); }
  });

  let klatka = 0;
  window.addEventListener('resize', () => {
    if (klatka || !otwarte) return;
    klatka = requestAnimationFrame(() => { klatka = 0; dopasuj(); });
  });

  window.__evkTlTeksty = { otwarte: () => (otwarte ? { i: otwarte.i, j: otwarte.j } : null) };
})();
