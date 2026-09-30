# Próby w builderze: przycisk AI przy polu „Tłumaczenie EN” i przy elemencie

Bricksa nie ma na maszynie testowej, więc zanim powstanie przycisk
„Przetłumacz” w builderze, trzeba na testowej sprawdzić cztery rzeczy:

1. gdzie w panelu stoi pole „Tłumaczenie EN” (żeby dołożyć przy nim przycisk);
2. czy wpis do stanu buildera widać w polu panelu i na kanwie;
3. czy Bricks uzna stronę za zmienioną i zapisze to pole;
4. czy Ctrl+Z cofa taki wpis.

Z prób 1.257 wiadomo już, że stan elementów to
`__vue_app__.config.globalProperties.$_state` (osobno w powłoce i w kanwie),
a pola panelu zmieniają najpierw stan powłoki.

## Wynik (30.09, testowa, od zgłaszającego)

- A1: zaznaczony `hfemng`, element `heading`, obszar `content` — działa
  `st.activeId || st.activeElement?.id` i szukanie w `content` / `header` /
  `footer`.
- A3: stan powłoki ma m.in. `content`, `header`, `footer`,
  `activeControlKey`, `isSaving`.
- A4: pole „Tłumaczenie EN” to
  `div[data-controlkey="evk_tl_en__text"] > div.control.control-text >
  div.control-inner.has-label > label + input#evk_tl_en__text.large`,
  w grupie `li.control-group[data-control-group="evk_tl"]`.
- Krok B (wpis wprost do `settings` w stanie powłoki): „Pole pokazuje
  tekst, pojawia się kropka przy zapisie. Undo/redo działa. Tekst się
  zapisuje”. Krok C nie był potrzebny.

Wniosek: przycisk przy polu i przycisk przy elemencie mogą wpisywać
tłumaczenie wprost do `settings` elementu w stanie powłoki; zapis zostaje
ręczny (Bricks sam widzi zmianę).

## Jak

1. Otwórz w builderze stronę testową i zaznacz **nagłówek** (element z polem
   „Tłumaczenie EN” w panelu).
2. Otwórz konsolę przeglądarki (F12 → Konsola). Kontekst: **top** (główne
   okno buildera, nie ramka `iframe`).
3. Wklej krok A, Enter. Skopiuj mi cały wynik.
4. Wklej krok B, Enter. Sprawdź i napisz mi:
   - czy pole „Tłumaczenie EN” w panelu pokazuje „PRÓBA EN …”;
   - czy kanwa z przełącznikiem EN pokazuje ten tekst;
   - czy Bricks pokazuje, że są niezapisane zmiany (przycisk zapisu);
   - Ctrl+Z raz: czy tekst wraca do poprzedniego;
   - Ctrl+Y (albo Ctrl+Shift+Z): czy wraca „PRÓBA EN …”;
   - zapisz stronę (Ctrl+S), przeładuj builder: czy „PRÓBA EN …” jest w polu.
5. Jeśli po kroku B pole w panelu się NIE zmieniło albo Bricks nie widzi
   zmian — wklej krok C (wpis „jak człowiek”, przez pole panelu) i sprawdź to
   samo.

Po próbie możesz po prostu wyczyścić pole „Tłumaczenie EN” w panelu.

## Krok A — co jest zaznaczone i jak wygląda panel

```js
(() => {
  const app = document.querySelector('.brx-body.main')?.__vue_app__;
  const st = app?.config?.globalProperties?.$_state;
  if (!st) return console.log('BRAK stanu powłoki — uruchom w głównym oknie buildera (kontekst top)');
  const id = st.activeId || st.activeElement?.id;
  const el = ['content', 'header', 'footer'].map((o) => (st[o] || []).find((e) => e && e.id === id)).find(Boolean);
  console.log('A1 zaznaczony:', id, el && el.name, 'obszar:', ['content', 'header', 'footer'].find((o) => (st[o] || []).includes(el)));
  console.log('A2 ustawienia:', JSON.stringify(el && el.settings));
  console.log('A3 klucze stanu:', Object.keys(st).slice(0, 80).join(', '));
  const opis = (n) => n.tagName.toLowerCase() + (n.id ? '#' + n.id : '')
    + (typeof n.className === 'string' && n.className.trim() ? '.' + n.className.trim().split(/\s+/).join('.') : '')
    + [...n.attributes].filter((a) => a.name.startsWith('data-')).map((a) => '[' + a.name + '="' + a.value + '"]').join('');
  [...document.querySelectorAll('label, .label, [class*="label"]')]
    .filter((x) => /Tłumaczenie/.test(x.textContent) && x.children.length < 4).slice(0, 3)
    .forEach((x, i) => {
      const w = [];
      for (let n = x, k = 0; n && k < 7; n = n.parentElement, k++) w.push(opis(n));
      const pole = x.closest('[class*="control"]');
      console.log('A4.' + i, JSON.stringify(x.textContent.trim()), '\n   ' + w.join('\n   < '),
        '\n   pole:', pole ? [...pole.querySelectorAll('input, textarea, [contenteditable], iframe')].map(opis).join(' | ') : 'nie znalezione');
    });
  window.__evkProba = { st, el, id };
})();
```

## Krok B — wpis wprost do stanu powłoki

```js
(() => {
  const p = window.__evkProba;
  if (!p || !p.el) return console.log('Najpierw krok A');
  const k = Object.keys(p.el.settings || {}).find((x) => /^evk_tl_en__/.test(x)) || 'evk_tl_en__text';
  p.przed = p.el.settings[k];
  p.el.settings[k] = 'PRÓBA EN ' + new Date().toLocaleTimeString();
  console.log('B wpisane do', k, '— było:', JSON.stringify(p.przed));
})();
```

## Krok C — wpis przez pole panelu (tylko gdy B nie zadziałał)

```js
(() => {
  const x = [...document.querySelectorAll('label, .label, [class*="label"]')].find((l) => /Tłumaczenie EN/.test(l.textContent) && l.children.length < 4);
  const pole = x && x.closest('[class*="control"]');
  const input = pole && pole.querySelector('input[type="text"], textarea');
  if (input) {
    const set = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(input), 'value').set;
    set.call(input, 'PRÓBA C ' + new Date().toLocaleTimeString());
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return console.log('C wpisane przez', input.tagName);
  }
  const ramka = pole && pole.querySelector('iframe');
  const ed = window.tinymce && ramka && window.tinymce.get(ramka.id.replace(/_ifr$/, ''));
  if (ed) {
    ed.setContent('<p>PRÓBA C ' + new Date().toLocaleTimeString() + '</p>');
    ed.fire('change'); ed.fire('input');
    return console.log('C wpisane przez TinyMCE', ed.id);
  }
  console.log('C nie znalazłem pola — wklej mi wynik kroku A');
})();
```
