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
  t.section('Animator');

  let r = emit({ evkAnimAnimation: 'wejscie', evkAnimTrigger: 'load', evkAnimDelay: '0.35', evkAnimOrder: '2' });
  let cfg = JSON.parse(r.anim || '{}');
  t.check('slug, wyzwalacz, opóźnienie, kolejność',
    cfg.animation === 'wejscie' && cfg.trigger === 'load' && cfg.delay === 0.35 && cfg.order === 2, r.anim);

  r = emit({ evkAnimAnimation: 'wejscie', evkAnimOrder: '0' });
  cfg = JSON.parse(r.anim || '{}');
  t.check('kolejność 0 jest znacząca', cfg.order === 0, r.anim);

  r = emit({ evkAnimAnimation: 'wejscie', evkAnimOrder: '' });
  cfg = JSON.parse(r.anim || '{}');
  t.check('pusta kolejność nie nadpisuje biblioteki', !('order' in cfg), r.anim);

  r = emit({});
  t.check('bez animacji brak atrybutu', r.anim === null, String(r.anim));

  // ── Nadpisania dołożone w 1.40.0 ───────────────────────────────────────
  t.section('nadpisania z panelu elementu');

  r = emit({ evkAnimAnimation: 'wejscie', evkAnimEasing: 'expo.out', evkAnimEnd: 'bottom 10%',
             evkAnimScrub: '0.5', evkAnimTargets: 'children', evkAnimSelector: '.karta' });
  cfg = JSON.parse(r.anim || '{}');
  t.check('easing, koniec, scrub, cel i selektor docierają',
    cfg.easing === 'expo.out' && cfg.end === 'bottom 10%' && cfg.scrub === 0.5
      && cfg.targets === 'children' && cfg.selector === '.karta', r.anim);

  r = emit({ evkAnimAnimation: 'wejscie', evkAnimWords: 'szybciej\n\nprościej\ntaniej' });
  cfg = JSON.parse(r.anim || '{}');
  t.check('lista słów per element',
    JSON.stringify(cfg.words) === JSON.stringify(['szybciej', 'prościej', 'taniej']),
    JSON.stringify(cfg.words));

  // ── Trójstan: to jest sedno tej wersji ─────────────────────────────────
  // Kontrolka wysyła '' / '1' / '0'. Dla !empty() ciąg '0' jest PUSTY, więc
  // jawne „Nie" wypadałoby tak samo jak „z biblioteki" — i nie dałoby się
  // wyłączyć w elemencie czegoś, co w bibliotece jest włączone.
  t.section('trójstan wartości logicznych');

  const bools = { evkAnimRepeat: 'repeat', evkAnimLoop: 'loop',
                  evkAnimLoopYoyo: 'loopYoyo', evkAnimPin: 'pin' };

  Object.keys(bools).forEach((id) => {
    const prop = bools[id];

    const on = JSON.parse(emit({ evkAnimAnimation: 'wejscie', [id]: '1' }).anim || '{}');
    t.check('„' + prop + '" włączone dociera', on[prop] === 1, JSON.stringify(on[prop]));

    const off = JSON.parse(emit({ evkAnimAnimation: 'wejscie', [id]: '0' }).anim || '{}');
    t.check('„' + prop + '" WYŁĄCZONE dociera jako 0', off[prop] === 0,
      prop in off ? JSON.stringify(off[prop]) : 'WYPADŁO — pułapka !empty()');

    const inherit = JSON.parse(emit({ evkAnimAnimation: 'wejscie', [id]: '' }).anim || '{}');
    t.check('„' + prop + '" puste nie nadpisuje biblioteki', !(prop in inherit),
      JSON.stringify(inherit[prop]));
  });

  // ── Cel zewnętrzny ────────────────────────────────────────────────────
  // Wybór z buildera musi DOJECHAĆ na stronę. Sam silnik umie już animować
  // element poza wyzwalaczem (tests/animator.test.js), ale to kontrakt PHP
  // decyduje, czy „external" w ogóle wyjdzie z panelu.
  t.section('cel poza elementem');

  const ext = JSON.parse(emit({
    evkAnimAnimation: 'wejscie', evkAnimTargets: 'external', evkAnimSelector: '#naglowek',
  }).anim || '{}');
  t.check('„external" dociera jako cel', ext.targets === 'external', JSON.stringify(ext.targets));
  t.check('selektor jedzie razem z nim', ext.selector === '#naglowek', JSON.stringify(ext.selector));

  // ── Repeater: wiele animacji na elemencie ─────────────────────────────
  // Kontrolki płaskie zostają obok repeatera i to nie jest zbędny balast:
  // niosą je wszystkie istniejące strony. Utrata tej ścieżki znaczyłaby, że
  // aktualizacja wtyczki gasi animacje wszędzie tam, gdzie ktoś ich użył.
  t.section('wiele animacji z buildera');

  const jedna = emit({ evkAnimAnimation: 'wejscie', evkAnimDuration: '1.5' }).anim;
  t.check('stare pola dają OBIEKT, nie tablicę', jedna && jedna.charAt(0) === '{',
    String(jedna).slice(0, 40));

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

  // Jeden wiersz w repeaterze daje OBIEKT, nie jednoelementową tablicę:
  // strona z nowym PHP i zacacheowanym starym animator.js nadal zadziała.
  const jedenWiersz = emit({ evkAnimList: [{ animation: 'wejscie' }] }).anim;
  t.check('jeden wiersz repeatera daje obiekt', jedenWiersz && jedenWiersz.charAt(0) === '{',
    String(jedenWiersz).slice(0, 40));

  // Repeater wygrywa z polami płaskimi — inaczej nie dałoby się przejść
  // na listę bez czyszczenia starych ustawień.
  const oba2 = JSON.parse(emit({
    evkAnimAnimation: 'stare',
    evkAnimList: [{ animation: 'nowe' }, { animation: 'nowe2' }],
  }).anim || 'null');
  t.check('repeater wygrywa z polami płaskimi',
    Array.isArray(oba2) && oba2.length === 2 && oba2[0].animation === 'nowe',
    JSON.stringify(oba2));

  t.section('Tło przy scrollu');
  t.check('zaznaczone → data-evk-bg', emit({ evkBgShift: true }).bg === '', 'atrybut obecny');
  t.check('niezaznaczone → brak atrybutu', emit({ evkBgShift: false }).bg === null, 'brak');
};
