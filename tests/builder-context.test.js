/**
 * Builder wykrywany w OBU oknach, nie tylko w powłoce.
 *
 * Zgłoszone z użycia: „animacje odpalają się w builderze mimo odznaczonego
 * »animuj w builderze«".
 *
 * `bricks_is_builder_main()` jest prawdziwe TYLKO w zewnętrznej powłoce
 * buildera — a powłoka nie rysuje treści. Treść idzie w ramce (canvas) i tam ta
 * funkcja zwraca fałsz, więc warunek zbudowany na niej samej nie zadziała
 * dokładnie tam, gdzie zależy najbardziej.
 *
 * Atrapa `animator-enqueue.php` idzie PRAWDZIWĄ ścieżką (sanityzacja → opcja →
 * enqueue), a kontekst `kanwa` ustawia WYŁĄCZNIE `?bricks=run` i zostawia obie
 * funkcje motywu na fałszu — najostrzejszy przypadek i dokładnie ten, którego
 * dotychczasowy warunek nie łapał.
 */

const fs   = require('fs');
const path = require('path');
const { phpOutput, ROOT } = require('./lib/harness');

const WIERSZ = JSON.stringify(JSON.stringify([{ slug: 'a', preset: 'fade-up' }]));

/** Wąskie sprawdzenie buildera — to, które gubi kanwę. */
const WASKIE = /function_exists\('bricks_is_builder_main'\)\s*&&\s*bricks_is_builder_main\(\)/;

/*
 * Wolno mu zostać w DWÓCH plikach i oba są uzasadnione:
 *   · 00-context-safety.php — to wspólny pomocnik, on tej funkcji UŻYWA,
 *   · 91-fonts.php — kanwa POTRZEBUJE krojów pisma. Bez nich tekst rysuje się
 *     zapasowym pismem i edytujesz coś, co nie wygląda jak strona. Tam
 *     rozszerzenie warunku byłoby regresją, nie poprawką.
 */
const WOLNO = ['00-context-safety.php', '91-fonts.php'];

function plikiPhp(katalog) {
  return fs.readdirSync(katalog, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(katalog, e.name);
    if (e.isDirectory()) return plikiPhp(p);
    return e.name.endsWith('.php') ? [p] : [];
  });
}

module.exports = async function (t) {

  const enq = (kontekst, podglad) => JSON.parse(
    phpOutput('animator-enqueue.php', WIERSZ + ' ' + kontekst + (podglad ? ' 1' : '')));

  t.section('animator nie wchodzi do buildera, gdy opcja jest odznaczona');

  /* SEDNO ZGŁOSZENIA. Ramka rysuje treść i to w niej animacje przeszkadzały. */
  t.check('w kanwie nie wystawia niczego', enq('kanwa').wystawiony === false, 'nie wystawia');
  /* Przypadek, który działał i ma działać dalej. */
  t.check('w powłoce dalej nie wystawia', enq('powloka').wystawiony === false, 'nie wystawia');

  /* KONTROLA NEGATYWNA: bez niej „nie wystawia" spełniłby też moduł wyłączony
     na amen — a wtedy strona zostałaby bez animacji w ogóle. */
  t.check('a na froncie wystawia normalnie', enq('front').wystawiony === true, 'wystawia');

  t.section('zaznaczona opcja wpuszcza animator do buildera');

  /* Kontrola pozytywna dla samego przełącznika: rozszerzenie warunku nie ma
     prawa zabrać możliwości podglądania animacji w builderze. */
  t.check('w kanwie wystawia', enq('kanwa', true).wystawiony === true, 'wystawia');
  t.check('w powłoce też', enq('powloka', true).wystawiony === true, 'wystawia');

  // ── Strażnik na całą wtyczkę ─────────────────────────────────────────────
  /*
   * Bez tego ta sama pomyłka wróci przy następnym module — bo wąska funkcja
   * wygląda na właściwą, dopóki nie wie się, co znaczy „main". Wzorowane na
   * `tests/php/bricks-required.php`, który tak samo przegląda wszystkie elementy.
   */
  t.section('żaden moduł frontowy nie pilnuje buildera samą powłoką');

  const winne = plikiPhp(path.join(ROOT, 'includes'))
    .filter((p) => WASKIE.test(fs.readFileSync(p, 'utf8')))
    .map((p) => path.basename(p))
    .filter((n) => !WOLNO.includes(n));

  t.check('poza dwoma uzasadnionymi wyjątkami — nigdzie', winne.length === 0,
    winne.length ? winne.join(', ') : 'czysto');

  /* Kontrola pozytywna: wspólny pomocnik naprawdę jest WOŁANY, i to dokładnie
     tam, gdzie miał być. Samo „nikt nie używa wąskiej" byłoby prawdą także
     wtedy, gdyby ktoś usunął warunki zupełnie.

     Linie komentarza odsiewane, bo `91-fonts.php` wymienia nazwę pomocnika
     w wyjaśnieniu, dlaczego akurat tam go NIE ma. */
  const bezKomentarzy = (tekst) => tekst.split('\n')
    .filter((l) => !/^\s*(\*|\/\/|\/\*)/.test(l)).join('\n');

  const OCZEKIWANE = ['animator.php', 'bgshift.php', 'motion.php',
    '96-lenis.php', '96-scroll-lock.php', '98-accessibility.php'];

  const wola = plikiPhp(path.join(ROOT, 'includes'))
    .filter((p) => /evk_w_builderze\(\)/.test(bezKomentarzy(fs.readFileSync(p, 'utf8'))))
    .map((p) => path.basename(p))
    .filter((n) => n !== '00-context-safety.php');

  const brakujace = OCZEKIWANE.filter((n) => !wola.includes(n));
  t.check('a wspólny warunek jest wołany we wszystkich sześciu modułach',
    brakujace.length === 0 && wola.length === OCZEKIWANE.length,
    brakujace.length ? 'brakuje: ' + brakujace.join(', ') : wola.join(', '));
};
