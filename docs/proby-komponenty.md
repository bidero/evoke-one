# Próba: komponenty Bricksa a tłumaczenia

Decyzja zgłaszającego (30.09): „Próba na testowej, potem pełna obsługa”.
Bricksa nie ma na maszynie testowej, a lista „Teksty w elementach”, hurt AI,
sprawdzanie na stronie i przyciski AI w builderze (1.265.0) czytają dziś
wyłącznie treść wpisu (`_bricks_page_content_2`, nagłówek, stopka).
Komponenty i elementy globalne siedzą w opcjach, więc tych tekstów nie widać.

Co już działa bez zmian:

- **słownik fraz** — działa na gotowym HTML-u strony (50), więc tłumaczy też
  teksty z komponentów;
- **tagi `{tl_klucz}`** w polach komponentu — rozwijane przy renderze (40).

Żeby zrobić pełną obsługę, trzeba wiedzieć trzy rzeczy:

1. jak komponent i jego instancja wyglądają w stanie buildera (klucze,
   elementy, właściwości);
2. czy pole „Tłumaczenie EN” przy elemencie WEWNĄTRZ komponentu zapisuje się
   i czy strona `/en/` je pokazuje (podmiana idzie filtrem ustawień elementu —
   czy Bricks przepuszcza przez niego elementy komponentu);
3. gdzie wpisuje się wartości właściwości komponentu (Properties) w instancji
   — to teksty konkretnej strony, a nie komponentu.

## Jak

1. Na testowej: komponent z nagłówkiem i tekstem, najlepiej z jedną
   właściwością (Property) połączoną z tekstem. Instancja na stronie.
2. Otwórz tę stronę w builderze, zaznacz **instancję komponentu**.
3. Konsola przeglądarki (F12 → Konsola), kontekst **top**. Wklej krok A,
   Enter, skopiuj mi cały wynik.
4. Wejdź w edycję komponentu, zaznacz **nagłówek wewnątrz**. Krok A jeszcze
   raz, skopiuj wynik.
5. W trybie edycji komponentu: czy przy tym nagłówku jest pole „Tłumaczenie
   EN”? Wpisz „PRÓBA KOMPONENT EN”, zapisz.
6. Otwórz stronę z instancją w wersji `/en/`: czy nagłówek komponentu
   pokazuje „PRÓBA KOMPONENT EN”?
7. Jeśli komponent ma właściwość: zaznacz instancję i napisz, gdzie w panelu
   wpisuje się jej wartość i czy jest przy niej „Tłumaczenie EN”.

Po próbie wyczyść pole „Tłumaczenie EN” w komponencie.

## Krok A — komponenty i zaznaczony element w stanie buildera

```js
(() => {
  const app = document.querySelector('.brx-body.main')?.__vue_app__;
  const st = app?.config?.globalProperties?.$_state;
  if (!st) return console.log('BRAK stanu powłoki — uruchom w głównym oknie buildera (kontekst top)');
  const krotko = (v) => { try { const s = JSON.stringify(v); return s && s.length > 400 ? s.slice(0, 400) + '…' : s; } catch (e) { return String(v); } };
  console.log('K1 klucze stanu z „compon”/„global”:', Object.keys(st).filter((k) => /compon|global/i.test(k)).join(', ') || '(brak)');
  const bd = window.bricksData || {};
  console.log('K1b bricksData z „compon”:', Object.keys(bd).filter((k) => /compon/i.test(k)).join(', ') || '(brak)');
  const zrodlo = st.components || bd.components;
  const lista = Array.isArray(zrodlo) ? zrodlo : (zrodlo && typeof zrodlo === 'object' ? Object.values(zrodlo) : []);
  console.log('K2 komponentów:', lista.length);
  lista.slice(0, 5).forEach((c, i) => {
    console.log('K2.' + i, 'id:', c && c.id, 'nazwa:', c && (c.label || c.name || c.title), 'klucze:', Object.keys(c || {}).join(', '));
    ((c && c.elements) || []).slice(0, 10).forEach((e) => console.log('   el', e.id, e.name, 'parent:', e.parent,
      'ustawienia:', Object.keys(e.settings || {}).join(', ')));
    if (c && c.properties) console.log('   właściwości:', krotko(c.properties));
  });
  const id = st.activeId || st.activeElement?.id;
  const obszar = ['content', 'header', 'footer'].find((o) => (st[o] || []).some((e) => e && e.id === id));
  const el = obszar ? st[obszar].find((e) => e && e.id === id) : st.activeElement;
  console.log('K3 zaznaczony:', id, el ? el.name : '(brak)', 'obszar:', obszar || '(poza content/header/footer)');
  if (el) console.log('K3 klucze elementu:', Object.keys(el).join(', '), '\n   element:', krotko(el));
  if (el && el.cid) console.log('K4 komponent tej instancji:', krotko(lista.find((c) => c && c.id === el.cid)));
  console.log('K5 klucze stanu z „active”/„edit”:', Object.keys(st).filter((k) => /active|edit/i.test(k))
    .map((k) => k + '=' + krotko(st[k])).join(' | ').slice(0, 1500));
})();
```
