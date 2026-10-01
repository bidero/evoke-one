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

## Wyniki (01.10, Bricks 2.4.2) i co z nich wyszło w 1.272.0

Krok A (zaznaczony nagłówek w edycji komponentu):

- stan powłoki: `components` (lista), `activeComponent` (edytowany),
  `activeId` — element WEWNĄTRZ komponentu, poza `content`/`header`/`footer`;
- komponent: `{id, category, desc, elements, properties, _created, _user_id, _version}`,
  nazwa w `label` elementu-korzenia (ten sam id co komponent);
- właściwość: `{label, type, id, connections: {idElementu: [klucz ustawienia]}}`;
- instancja na stronie: `{id, name, parent, children, settings, cid, properties: {idWłaściwości: wartość}}`;
  wstawiona z panelu bez zmian — BEZ klucza `properties`;
- pole „Tłumaczenie EN” w edycji komponentu jest i się zapisuje (`evk_tl_en__text`).

Próby na stronie `/en/`:

1. Komponent bez właściwości EN, instancja z własnym polskim tekstem
   („Nagłówek testowy”) — na `/en/` „Próba komponent EN”, czyli tłumaczenie
   tekstu komponentu. BŁĄD: odwiedzający widział tłumaczenie innego zdania.
2. Właściwość „Nagłówek EN” połączona z `evk_tl_en__text` nagłówka:
   - A (wartość „INSTANCJA EN”) — na `/en/` „INSTANCJA EN”: Bricks wpisuje
     wartość właściwości w ustawienie przed naszym filtrem renderu;
   - B (polski tekst, EN pusty) — na `/en/` polski „Nagłówek B”;
   - C (wstawiona z panelu, bez wartości) — pola połączone puste, także EN.
     Tekst stały (bez właściwości) — „STAŁY EN” w każdej instancji.

Co z tego wyszło (51-translation-components.php, tl-komponenty.js):

- właściwość tekstowa połączona z polem tłumaczonym dostaje sama
  „{etykieta} EN” (i każdy język) tuż pod sobą — w builderze od razu,
  na serwerze przy zapisie komponentów, po zmianie języków i raz po
  aktualizacji; stały identyfikator (FNV-1a), ręczne połączenie uznane;
- teksty stałe tłumaczy się raz, w komponencie — nie dostają właściwości
  (wynik C: właściwość bez wartości wyłączyłaby tłumaczenie z komponentu);
- hurt AI, lista tekstów, „Do sprawdzenia” i ✦ widzą teksty właściwości
  instancji (`{instancja}|prop:{właściwość}|{język}`) i teksty stałe
  (wiersz „Komponenty Bricksa”, opcja komponentów).

Do sprawdzenia na testowej (atrapa Bricksa nie odpowie):

- czy Bricks zapisuje właściwości dołożone w stanie (kropka przy „Zapisz”);
- czy ✦ w trybie edycji komponentu zapisuje się z komponentem
  (`activeComponent` może być kopią `components[…]`);
- czy wpis do `properties` instancji pokazuje się w panelu instancji od razu.
