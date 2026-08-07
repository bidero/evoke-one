/**
 * Wygląd panelu — to, co da się zmierzyć.
 *
 * Dwie różne rzeczy w jednym pliku, obie liczbowe:
 *
 * 1. **Checkboxy w Animatorze nie były kwadratowe na mobile.** `.checkbox-label`
 *    to `display:flex`, a `input` nie miał `flex-shrink: 0` — przy szerokiej
 *    etykiecie i polu powiększonym przez WordPressa do 25 px poniżej 782 px
 *    był ściskany w poziomie. Mierzymy prostokąt, nie oglądamy.
 *
 * 2. **Przejście na tokeny CSS niczego nie może przemalować.** Wyliczone
 *    kolory kluczowych komponentów muszą zostać te same — to jedyny sensowny
 *    test na „refaktor nic nie popsuł". Wartości wzorcowe siedzą w tym pliku
 *    celowo, bo o niezmienność WOBEC NICH chodzi; odczyt z tego samego źródła
 *    co kod nie dowiódłby niczego.
 */

const { phpOutput } = require('./lib/harness');

/** Wzorzec sprzed przejścia na tokeny (1.41.0). */
const BEFORE = {
  // .evo-panel ma border-top: 0 (przykleja się do paska zakładek), więc górna
  // krawędź jest celowo zerowa — to nie usterka, tylko kształt komponentu.
  panel:  { bg: 'rgb(255, 255, 255)', border: 'rgb(0, 0, 0) 0px', radius: '0px' },
  card:   { bg: 'rgb(248, 250, 252)', border: 'rgb(215, 221, 231) 1px', radius: '10px' },
  info:   { bg: 'rgb(248, 250, 252)', border: 'rgb(215, 221, 231) 1px', radius: '10px' },
  row:    { bg: 'rgb(248, 250, 252)', border: 'rgb(215, 221, 231) 1px', radius: '8px' },
  title:  { color: 'rgb(17, 24, 39)', size: '14px' },
  badge:  { bg: 'rgb(226, 232, 240)', color: 'rgb(51, 65, 85)' },
  hint:   { color: 'rgb(107, 114, 128)', size: '12px' },
  // Sekcja zwijana z 1.42.0 — od początku na tokenach, więc wzorzec jest
  // jej stanem wyjściowym, nie punktem odniesienia sprzed refaktoru.
  note:   { bg: 'rgb(248, 250, 252)', border: 'rgb(215, 221, 231) 1px', radius: '10px' },
};

module.exports = async function (t) {
  const tab  = phpOutput('anim-tab.php', JSON.stringify(JSON.stringify(['alfa', 'beta'])));
  const head = 'window.__tab = ' + JSON.stringify(tab) + ';';

  // ── Checkboxy ──────────────────────────────────────────────────────────
  // Spłaszczenie NIE występuje przy każdej szerokości — trzeba trafić w układ,
  // w którym siatka ma kilka kolumn, a długa etykieta ściska pole. Zmierzone:
  // przy 320–380 px jest jedna szeroka kolumna i nic się nie dzieje, a przy
  // 480 px i 782 px pierwszy checkbox schodził do 21×25 i 24×25. Dlatego test
  // przemiata szerokości zamiast wybierać jedną „reprezentatywną".
  t.section('checkboxy w Animatorze — kwadratowe na każdej szerokości');

  const WIDTHS = [320, 380, 480, 600, 782, 1024, 1400];
  const bad = [];
  let seen = 0;

  for (const width of WIDTHS) {
    const p = await t.open('admin-style.html', { viewport: { width, height: 900 }, head, settle: 150 });
    const boxes = await p.evaluate(() => window.__checkboxes());
    seen += boxes.length;
    boxes.forEach((b) => {
      if (Math.abs(b.w - b.h) > 1) bad.push(width + 'px: ' + b.w + '×' + b.h);
    });
    await p.close();
  }

  t.check('checkboxy w ogóle są', seen > 0, seen + ' pomiarów');
  t.check('każdy jest kwadratowy na każdej szerokości', !bad.length,
    bad.join(', ') || WIDTHS.length + ' szerokości bez spłaszczeń');

  // Powyższy pomiar zależy od tego, czy długa etykieta trafi w wąską kolumnę,
  // a to przetasowuje się przy każdym dołożeniu pola do wiersza. Niezmiennik
  // jest twardszy i to on jest właściwą ochroną.
  const one = await t.open('admin-style.html', { viewport: { width: 900, height: 900 }, head, settle: 150 });
  const shrink = await one.evaluate(() => window.__shrink());
  t.check('pole wyboru nie ma prawa się kurczyć',
    shrink.length > 0 && shrink.every((v) => v === '0'), shrink.join(', ') || 'brak pól');

  const tight = await one.evaluate(() => {
    const el = document.querySelector('#tight input[type=checkbox]');
    const r  = el.getBoundingClientRect();
    return { w: Math.round(r.width), h: Math.round(r.height) };
  });
  t.check('kwadratowy nawet w wymuszonym ciasnym układzie',
    Math.abs(tight.w - tight.h) <= 1, tight.w + '×' + tight.h);
  await one.close();

  // ── Tokeny nie przemalowały panelu ─────────────────────────────────────
  t.section('przejście na tokeny nic nie zmieniło');

  const p = await t.open('admin-style.html', { viewport: { width: 1400, height: 900 }, head, settle: 200 });
  const now = await p.evaluate(() => window.__styles());

  Object.keys(BEFORE).forEach((key) => {
    const want = BEFORE[key];
    const got  = now[key];
    const diff = got ? Object.keys(want).filter((k) => want[k] !== got[k]) : ['brak elementu'];
    t.check('„' + key + '" wygląda jak przed refaktorem', !diff.length,
      diff.map((k) => k + ': ' + (got ? got[k] : '—') + ' ≠ ' + want[k]).join(', ') || 'bez zmian');
  });

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();
};
