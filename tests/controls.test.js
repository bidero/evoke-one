/**
 * Kontrolki Bricks → atrybuty na froncie.
 *
 * Idzie przez PRAWDZIWY filtr `bricks/element/render_attributes` na atrapach
 * WordPressa, bo dwie usterki siedziały właśnie tam:
 *  · atrybuty zapisywane płasko zamiast pod kluczem fragmentu HTML po prostu
 *    nie docierały do strony (1.24.0),
 *  · „Kolejność” równa zero jest ZNACZĄCA i nie może wypaść jak pusta (1.28.1).
 */

const { phpOutput } = require('./lib/harness');

const emit = (settings) => JSON.parse(phpOutput('controls.php', JSON.stringify(JSON.stringify(settings))));

module.exports = async function (t) {
  t.section('Animator — jedna lista, żadnych pól obok');

  // Wiersz repeatera jest OD 1.66.0 jedyną drogą. Wcześniej obok stał komplet
  // płaskich kontrolek `evkAnim*`; ta sama animacja dała się ustawić na dwa
  // sposoby, a przeniesienie konfiguracji na inny element znaczyło przepisanie
  // każdego pola z osobna.
  const one = (row) => JSON.parse(emit({ evkAnimList: [row] }).anim || '{}');

  let cfg = one({ animation: 'wejscie', trigger: 'load', delay: '0.35', order: '2' });
  t.check('slug, wyzwalacz, opóźnienie, kolejność',
    cfg.animation === 'wejscie' && cfg.trigger === 'load' && cfg.delay === 0.35
      && cfg.order === 2, JSON.stringify(cfg));

  t.check('kolejność 0 jest znacząca', one({ animation: 'wejscie', order: '0' }).order === 0,
    JSON.stringify(one({ animation: 'wejscie', order: '0' }).order));

  t.check('pusta kolejność nie nadpisuje biblioteki',
    !('order' in one({ animation: 'wejscie', order: '' })), 'brak klucza');

  t.check('bez animacji brak atrybutu', emit({}).anim === null, String(emit({}).anim));

  // Płaskie klucze nie mają już prawa działać — inaczej zostałaby druga,
  // niewidoczna w panelu droga i wróciłby problem, który ta zmiana usuwa.
  t.check('stare pola płaskie NIE działają',
    emit({ evkAnimAnimation: 'wejscie', evkAnimDelay: '0.35' }).anim === null,
    String(emit({ evkAnimAnimation: 'wejscie' }).anim));

  // ── Pola przeniesione do wiersza w 1.66.0 ──────────────────────────────
  t.section('nadpisania w wierszu listy');

  cfg = one({ animation: 'wejscie', easing: 'expo.out', end: 'bottom 10%',
              scrub: '0.5', targets: 'children', selector: '.karta' });
  t.check('easing, koniec, scrub, cel i selektor docierają',
    cfg.easing === 'expo.out' && cfg.end === 'bottom 10%' && cfg.scrub === 0.5
      && cfg.targets === 'children' && cfg.selector === '.karta', JSON.stringify(cfg));

  cfg = one({ animation: 'wejscie', stagger: '0.08' });
  t.check('stagger dociera', cfg.stagger === 0.08, JSON.stringify(cfg.stagger));

  cfg = one({ animation: 'wejscie', words: 'szybciej\n\nprościej\ntaniej' });
  t.check('lista słów per element',
    JSON.stringify(cfg.words) === JSON.stringify(['szybciej', 'prościej', 'taniej']),
    JSON.stringify(cfg.words));

  // ── Trójstan: to jest sedno tej kontrolki ──────────────────────────────
  // Wysyła '' / '1' / '0'. Dla !empty() ciąg '0' jest PUSTY, więc jawne „Nie"
  // wypadałoby tak samo jak „z biblioteki" — i nie dałoby się wyłączyć
  // w elemencie czegoś, co w bibliotece jest włączone.
  t.section('trójstan wartości logicznych');

  ['repeat', 'loop', 'loopYoyo', 'pin'].forEach((prop) => {
    const on = one({ animation: 'wejscie', [prop]: '1' });
    t.check('„' + prop + '" włączone dociera', on[prop] === 1, JSON.stringify(on[prop]));

    const off = one({ animation: 'wejscie', [prop]: '0' });
    t.check('„' + prop + '" WYŁĄCZONE dociera jako 0', off[prop] === 0,
      prop in off ? JSON.stringify(off[prop]) : 'WYPADŁO — pułapka !empty()');

    const inherit = one({ animation: 'wejscie', [prop]: '' });
    t.check('„' + prop + '" puste nie nadpisuje biblioteki', !(prop in inherit),
      JSON.stringify(inherit[prop]));
  });

  // ── Cel zewnętrzny ────────────────────────────────────────────────────
  // Sam silnik umie już animować element poza wyzwalaczem
  // (tests/animator.test.js), ale to kontrakt PHP decyduje, czy „external"
  // w ogóle wyjdzie z panelu.
  t.section('cel poza elementem');

  const ext = one({ animation: 'wejscie', targets: 'external', selector: '#naglowek' });
  t.check('„external" dociera jako cel', ext.targets === 'external', JSON.stringify(ext.targets));
  t.check('selektor jedzie razem z nim', ext.selector === '#naglowek', JSON.stringify(ext.selector));

  // ── Wiele animacji na elemencie ───────────────────────────────────────
  t.section('wiele animacji z buildera');

  const wiele = emit({
    evkAnimList: [
      { animation: 'wejscie', duration: '1.5' },
      { animation: 'wyjscie', trigger: 'exit' },
    ],
  }).anim;
  const lista = JSON.parse(wiele || 'null');
  t.check('repeater daje TABLICĘ', Array.isArray(lista), String(wiele).slice(0, 40));
  t.check('obie pozycje docierają',
    lista && lista.length === 2 && lista[0].animation === 'wejscie'
    && lista[1].animation === 'wyjscie', JSON.stringify(lista));
  t.check('pola wiersza docierają razem z nim',
    lista && lista[0].duration === 1.5 && lista[1].trigger === 'exit',
    JSON.stringify(lista && [lista[0].duration, lista[1].trigger]));

  // Wiersz bez wybranej animacji to wiersz, którego użytkownik nie wypełnił —
  // ma wypaść, a nie trafić na stronę jako pusta konfiguracja. Po jego
  // odpadnięciu zostaje JEDNA konfiguracja, więc wynikiem jest obiekt: dwie
  // reguły spotykają się tu naraz i dlatego sprawdzamy je razem.
  const zPustym = JSON.parse(emit({
    evkAnimList: [{ animation: 'wejscie' }, { animation: '' }],
  }).anim || 'null');
  t.check('pusty wiersz repeatera wypada',
    zPustym && !Array.isArray(zPustym) && zPustym.animation === 'wejscie',
    JSON.stringify(zPustym));

  // Trzy wiersze, jeden pusty → nadal tablica, ale o długości dwa.
  const trzy = JSON.parse(emit({
    evkAnimList: [{ animation: 'a' }, { animation: '' }, { animation: 'b' }],
  }).anim || 'null');
  t.check('pusty wiersz wypada też ze środka listy',
    Array.isArray(trzy) && trzy.length === 2
    && trzy[0].animation === 'a' && trzy[1].animation === 'b',
    JSON.stringify(trzy));

  // Jeden wiersz daje OBIEKT, nie jednoelementową tablicę: strona z nowym PHP
  // i zacacheowanym starym animator.js nadal zadziała.
  const jedenWiersz = emit({ evkAnimList: [{ animation: 'wejscie' }] }).anim;
  t.check('jeden wiersz repeatera daje obiekt', jedenWiersz && jedenWiersz.charAt(0) === '{',
    String(jedenWiersz).slice(0, 40));

  // Pola dokładane wierszom przez builder (m.in. `id`) nie mają prawa wyjść
  // na stronę — konfigurację składa whitelista, nie przepisanie całego wiersza.
  const zId = one({ id: 'abc123', animation: 'wejscie', duration: '1.5' });
  t.check('builderowe `id` wiersza nie wychodzi na stronę', !('id' in zId),
    JSON.stringify(Object.keys(zId)));

  t.section('Tło przy scrollu');
  t.check('zaznaczone → data-evk-bg', emit({ evkBgShift: true }).bg === '', 'atrybut obecny');
  t.check('niezaznaczone → brak atrybutu', emit({ evkBgShift: false }).bg === null, 'brak');

  // Moment przełączenia per sekcja. Pusty atrybut MUSI zostać pusty — taki
  // niosą wszystkie strony sprzed 1.53.0 i silnik czyta go jako „wartość
  // globalna". Wpisanie tam domyślnej setki zmieniłoby zachowanie wszędzie
  // tam, gdzie ktoś globalną przestawił.
  t.check('bez wpisanego procentu atrybut zostaje PUSTY',
    emit({ evkBgShift: true, evkBgShiftStart: '' }).bg === '', 'pusty');
  t.check('wpisany procent jedzie w atrybucie',
    emit({ evkBgShift: true, evkBgShiftStart: '40' }).bg === '40',
    String(emit({ evkBgShift: true, evkBgShiftStart: '40' }).bg));
  // Zero jest znaczące — ta sama pułapka co przy „Kolejności" w 1.28.1.
  t.check('zero nie wypada jak puste',
    emit({ evkBgShift: true, evkBgShiftStart: '0' }).bg === '0',
    String(emit({ evkBgShift: true, evkBgShiftStart: '0' }).bg));
};
