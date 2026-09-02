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
 * 2. **Skóra panelu nie dryfuje.** Wzorzec zaczął życie jako „przejście na
 *    tokeny niczego nie przemalowało" (1.41.0), a w 1.43.0 został przestawiony
 *    na wartości z Evoke Fields — bo TA wersja przemalowuje celowo. Odtąd
 *    pilnuje czegoś innego, ale równie konkretnego: że kolejne zmiany nie
 *    ruszają wyglądu przypadkiem. Wartości siedzą w tym pliku celowo; odczyt
 *    z tego samego źródła co kod nie dowiódłby niczego.
 */

const { phpOutput, tokenRgb } = require('./lib/harness');

/** Wzorzec skóry Evoke Fields (1.43.0). */
const SKIN = {
  // .evo-panel ma border-top: 0 (przykleja się do paska zakładek), więc górna
  // krawędź jest celowo zerowa — to nie usterka, tylko kształt komponentu.
  panel:  { bg: 'rgb(255, 255, 255)', border: 'rgb(0, 0, 0) 0px', radius: '0px' },
  card:   { bg: 'rgb(248, 250, 252)', border: 'rgb(215, 221, 231) 1px', radius: '10px' },
  info:   { bg: 'rgb(248, 250, 252)', border: 'rgb(215, 221, 231) 1px', radius: '10px' },
  // Wiersz to teraz KARTA jak w Fields: białe tło, promień 12 px.
  row:    { bg: 'rgb(255, 255, 255)', border: 'rgb(215, 221, 231) 1px', radius: '12px' },
  title:  { color: 'rgb(17, 24, 39)', size: '14px' },
  // Plakietka klasy przeszła na kolory „kodu w tekście" z Fields.
  badge:  { bg: 'rgb(238, 242, 255)', color: 'rgb(55, 48, 163)' },
  hint:   { color: 'rgb(107, 114, 128)', size: '12px' },
  note:   { bg: 'rgb(248, 250, 252)', border: 'rgb(215, 221, 231) 1px', radius: '8px' },
  // Akcent i promień przycisku — bez tego dryf koloru akcentu przechodziłby
  // niezauważony, a to on najbardziej niesie tożsamość skóry.
  btn:    { bg: 'rgb(' + tokenRgb('evo-accent').join(', ') + ')', radius: '7px' },
  // Jednolita wysokość kontrolki to znak rozpoznawczy Fields.
  field:  { h: '38px', radius: '6px' },
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

  // ── Skóra Evoke Fields ─────────────────────────────────────────────────
  t.section('panel trzyma się skóry Evoke Fields');

  const p = await t.open('admin-style.html', { viewport: { width: 1400, height: 900 }, head, settle: 200 });
  const now = await p.evaluate(() => window.__styles());

  Object.keys(SKIN).forEach((key) => {
    const want = SKIN[key];
    const got  = now[key];
    const diff = got ? Object.keys(want).filter((k) => want[k] !== got[k]) : ['brak elementu'];
    t.check('„' + key + '" zgodny ze skórą', !diff.length,
      diff.map((k) => k + ': ' + (got ? got[k] : '—') + ' ≠ ' + want[k]).join(', ') || 'bez zmian');
  });

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');

  // ── Trzy usterki zgłoszone po 1.43.0 ───────────────────────────────────
  t.section('detale zgłoszone z panelu');

  // 1. „Paski nie wyglądają równo": nagłówek zwiniętego wiersza miał
  //    padding 12 px u góry i 0 u dołu — reguła zwijania zerowała dolny,
  //    bo powstała, gdy wiersz miał jeszcze własny padding.
  const bar = await p.evaluate(() => window.__bar());
  t.check('zwinięty pasek ma symetryczny padding', bar.padTop === bar.padBottom,
    bar.padTop + ' / ' + bar.padBottom);
  t.check('tytuł wyśrodkowany w pasku', Math.abs(bar.barMid - bar.titleMid) <= 1,
    'środek paska ' + bar.barMid.toFixed(1) + ', tytułu ' + bar.titleMid.toFixed(1));

  // 2. „Na select brakuje strzałek": skrót `background` skasował obraz tła.
  const sel = await p.evaluate(() => window.__select());
  t.check('lista rozwijana ma strzałkę', sel.image !== 'none' && /url\(/.test(sel.image),
    sel.image.slice(0, 40));
  t.check('jest miejsce na strzałkę', sel.padRight >= 24, sel.padRight + 'px');

  // 3. „Teksty nie są wyrównane w pionie z polami": etykieta checkboxa
  //    dziedziczyła margin-bottom: 5px po etykietach nagłówkowych i podnosiła
  //    checkbox o tyle nad pole w tym samym wierszu (703,5 przy 708,5).
  const align = await p.evaluate(() => window.__rowAlign());
  const mixed = align.filter((r) => r.mixed);
  t.check('są wiersze z polem i checkboxem obok siebie', mixed.length > 0,
    mixed.length + ' z ' + align.length + ' wierszy');
  const off = mixed.filter((r) => r.spread > 1);
  t.check('kończą się na tej samej wysokości', !off.length,
    off.map((r) => r.spread + 'px różnicy').join(', ') || 'równo');

  await p.close();
};
