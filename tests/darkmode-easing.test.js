/**
 * Dark mode: easing wpisywany, nie wybierany z listy.
 *
 * Zgłoszone z użycia: „przy zmianie motywu (globalne) daj możliwość wybrania
 * dowolnego easingu, jak niżej w Elementy Bricks Builder".
 *
 * Silnik przyjmował dowolne `cubic-bezier(...)` na długo przed 1.114.0 —
 * blokował wyłącznie interfejs, i to niekonsekwentnie: dwa pola były tekstowe,
 * trzy listami wyboru, a `logo_easing` nie miał w panelu żadnego pola, choć był
 * sanityzowany i używany w CSS.
 *
 * Markup zakładki renderuje `tests/php/tab.php` PRAWDZIWYM plikiem zakładki,
 * a sanityzację sprawdza `tests/php/darkmode-easing.php` na PRAWDZIWEJ
 * instancji modułu — tej samej, której używa panel.
 */

const { phpOutput } = require('./lib/harness');

/** Wszystkie klucze easingów, które moduł sanityzuje. */
const EASINGI = ['global_easing', 'bricks_easing', 'logo_easing',
                 'ripple_easing', 'wipe_easing', 'post_trans_easing'];

module.exports = async function (t) {

  const tab = phpOutput('tab.php', 'darkmode');
  const d   = JSON.parse(phpOutput('darkmode-easing.php'));

  // ── Pola w panelu ────────────────────────────────────────────────────────
  t.section('każdy easing ma pole tekstowe, żaden nie ma listy');

  const listy = EASINGI.filter((k) =>
    new RegExp('<select[^>]*name="evk_darkmode\\[' + k + '\\]"').test(tab));
  t.check('żaden easing nie jest listą wyboru', listy.length === 0,
    listy.length ? listy.join(', ') : 'wszystkie sześć to pola');

  const pola = EASINGI.filter((k) =>
    new RegExp('<input[^>]*type="text"[^>]*name="evk_darkmode\\[' + k + '\\]"').test(tab));
  t.check('i wszystkie sześć naprawdę są w formularzu', pola.length === 6,
    pola.length + ': ' + pola.join(', '));

  /* `logo_easing` był sanityzowany i wchodził do CSS, ale nie miał pola —
     siedział na wartości domyślnej i nie dało się go ruszyć. */
  t.check('w tym „logo_easing", którego wcześniej nie było wcale',
    pola.includes('logo_easing'), pola.includes('logo_easing') ? 'jest' : 'brak');

  /* KONTROLA NEGATYWNA: lista zostaje tam, gdzie zestaw wartości jest ZAMKNIĘTY.
     Kierunek wycierania ma cztery możliwe wartości i piątej nie będzie — easing
     jest otwarty, kierunek nie. Bez tego sprawdzenia „zamieniliśmy listy na
     pola" mogłoby znaczyć „zamieniliśmy wszystkie". */
  t.check('a kierunek wycierania dalej jest listą',
    /<select[^>]*name="evk_darkmode\[wipe_direction\]"/.test(tab), 'jest listą');

  // ── Co przechodzi przez sanityzację ──────────────────────────────────────
  t.section('sanityzacja przepuszcza dowolną krzywą, a śmieci odrzuca');

  t.check('własne cubic-bezier przechodzą wszystkie cztery',
    d.wlasna.easingi.global_easing === 'cubic-bezier(0.87, 0, 0.13, 1)'
      && d.wlasna.easingi.wipe_easing === 'cubic-bezier(.25,.1,.25,1)'
      && d.wlasna.easingi.post_trans_easing === 'cubic-bezier(0.16, 1, 0.3, 1)'
      && d.wlasna.easingi.logo_easing === 'cubic-bezier(0.65, 0, 0.35, 1)',
    JSON.stringify(d.wlasna.easingi.global_easing));

  /* Punkty sterujące poza zakresem 0–1 są w krzywych legalne i to one dają
     „przestrzelenie". Regex musi je puścić razem z minusem. */
  t.check('ujemne punkty sterujące też', 
    d.ujemne.easingi.global_easing === 'cubic-bezier(0.68, -0.55, 0.27, 1.55)',
    d.ujemne.easingi.global_easing);

  t.check('nazwy z zestawu dalej działają', d.nazwa.easingi.global_easing === 'ease-in-out',
    d.nazwa.easingi.global_easing);

  t.check('śmieci nie przechodzą', d.smieci.easingi.global_easing === 'ease'
    && d.smieci.easingi.wipe_easing !== '1s ease',
    d.smieci.easingi.global_easing + ' / ' + d.smieci.easingi.wipe_easing);

  // ── Odrzucenie mówi o sobie ──────────────────────────────────────────────
  /*
   * Do 1.114.0 te pola były listami — pomylić się nie było jak, więc ciche
   * cofnięcie do domyślnej nikomu nie szkodziło. Przy polu tekstowym literówka
   * w `cubic-bezier` kasowała ustawienie bez słowa, a strona po zapisie
   * wyglądała tak samo jak przed.
   */
  t.section('odrzucona wartość mówi o sobie');

  t.check('jest komunikat i wymienia oba pola', d.smieci.komunikaty.length === 1
    && /global_easing/.test(d.smieci.komunikaty[0])
    && /wipe_easing/.test(d.smieci.komunikaty[0]),
    d.smieci.komunikaty[0] || 'brak komunikatu');

  /* KONTROLA NEGATYWNA: poprawny zapis milczy. Bez tego „jest komunikat"
     spełniłby też moduł, który krzyczy zawsze. */
  t.check('a poprawny zapis milczy', d.wlasna.komunikaty.length === 0,
    d.wlasna.komunikaty.join(' | ') || 'cisza');

  /* Wyczyszczone pole to świadome „wróć do domyślnego", nie pomyłka. */
  t.check('puste pole też milczy', d.puste.komunikaty.length === 0
    && d.puste.easingi.global_easing === 'ease',
    d.puste.easingi.global_easing + ', komunikatów: ' + d.puste.komunikaty.length);

  // ── Zmienne kolorów: nazwy przed wpuszczeniem do @property ───────────────
  /*
   * Nazwy z tego pola trafiają do `@property` i do listy przejść, więc byle co
   * wpuszczone tu kończy się nieważną regułą albo — gorzej — kolorem
   * podmienionym na `transparent` w całym motywie.
   */
  t.section('zmienne kolorów: nazwy normalizowane, śmieci odrzucane');

  t.check('brak myślników jest dopisywany, nie karany',
    d.zmienne_ok.zmienne.split('\n')[1] === '--kolor-b', d.zmienne_ok.zmienne.replace(/\n/g, ' '));
  t.check('spacje wokół nazwy nie przeszkadzają',
    d.zmienne_ok.zmienne.split('\n')[2] === '--kolor-c', d.zmienne_ok.zmienne.split('\n')[2]);
  t.check('powtórki znikają', d.zmienne_powtorka.zmienne === '--a', d.zmienne_powtorka.zmienne);

  t.check('śmieci odpadają, dobre wpisy zostają',
    d.zmienne_smieci.zmienne === '--dobra', d.zmienne_smieci.zmienne);
  t.check('i odrzucenie mówi o sobie', d.zmienne_smieci.komunikaty.length === 1
    && /zła nazwa/.test(d.zmienne_smieci.komunikaty[0]),
    d.zmienne_smieci.komunikaty[0] || 'brak komunikatu');

  /* KONTROLA NEGATYWNA: puste i poprawne pole milczy. Bez tego „jest komunikat"
     spełniłby też moduł, który krzyczy zawsze. */
  t.check('a poprawny i pusty zapis milczy',
    d.zmienne_ok.komunikaty.length === 0 && d.zmienne_puste.komunikaty.length === 0
      && d.zmienne_puste.zmienne === '',
    'cisza');
};
