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

  t.section('Tło przy scrollu');
  t.check('zaznaczone → data-evk-bg', emit({ evkBgShift: true }).bg === '', 'atrybut obecny');
  t.check('niezaznaczone → brak atrybutu', emit({ evkBgShift: false }).bg === null, 'brak');
};
