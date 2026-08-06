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

  t.section('Tło przy scrollu');
  t.check('zaznaczone → data-evk-bg', emit({ evkBgShift: true }).bg === '', 'atrybut obecny');
  t.check('niezaznaczone → brak atrybutu', emit({ evkBgShift: false }).bg === null, 'brak');
};
