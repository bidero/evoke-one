/**
 * Drzewo dostępności po podziale tekstu.
 *
 * Broni usterki z 1.28.1: SplitText z domyślnym `aria: "auto"` ustawia na
 * kontenerze aria-label z surowego textContent i oznacza każdy kawałek
 * aria-hidden. Na elemencie nestable z dowolnymi dziećmi Bricks znaczyło to,
 * że nagłówek i odnośnik traciły swoje nazwy — link stawał się dla czytnika
 * ekranu bezimienny, a etykieta była zlepkiem („ProjektujemyRobimy”).
 *
 * Zachowanie GSAP-a jest natomiast POPRAWNE dla pojedynczego nagłówka, dlatego
 * test pilnuje obu przypadków: kontener ma stracić auto-etykietę, a nagłówek
 * ma ją zachować.
 */

async function axTree(page) {
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Accessibility.enable');
  const { nodes } = await cdp.send('Accessibility.getFullAXTree');
  return nodes.filter((n) => ['heading', 'link'].includes(n.role && n.role.value))
              .map((n) => n.role.value + ':' + JSON.stringify((n.name && n.name.value) || ''));
}

module.exports = async function (t) {
  // ── Scroll Reading: kontener nestable ─────────────────────────────────
  t.section('Scroll Reading — kontener z nagłówkiem i odnośnikiem');
  let page = await t.open('aria.html', { query: 'mode=sr' });
  const sr = await page.evaluate(() => {
    const el = document.querySelector('[data-evk-sr]');
    const w = document.querySelector('.evk-sr-word');
    return { label: el.getAttribute('aria-label'),
             pieces: document.querySelectorAll('.evk-sr-word').length,
             hidden: document.querySelectorAll('.evk-sr-word[aria-hidden="true"]').length,
             tag: w && w.tagName, display: w && getComputedStyle(w).display };
  });
  t.check('brak sfabrykowanego aria-label', sr.label === null, JSON.stringify(sr.label));
  t.check('żaden kawałek nie jest ukryty', sr.hidden === 0, sr.hidden + '/' + sr.pieces);
  t.check('kawałki to spany', sr.tag === 'SPAN', sr.tag + ', display ' + sr.display);
  let tree = await axTree(page);
  t.check('nagłówek i odnośnik mają nazwy',
    tree.includes('heading:"Projektujemy"') && tree.includes('link:"piszemy o tym"'), tree.join('  |  '));
  await page.close();

  // ── Animator: pojedynczy nagłówek — auto MA zostać ────────────────────
  t.section('Animator — pojedynczy nagłówek');
  page = await t.open('aria.html', { query: 'mode=anim-one' });
  const one = await page.evaluate(() => {
    const el = document.querySelector('.evk-anim-split');
    const l = document.querySelector('.evk-anim-line');
    return { label: el.getAttribute('aria-label'),
             pieces: document.querySelectorAll('.evk-anim-line').length,
             hidden: document.querySelectorAll('.evk-anim-line[aria-hidden="true"]').length,
             tag: l && l.tagName, display: l && getComputedStyle(l).display };
  });
  t.check('aria-label zachowany', !!one.label, JSON.stringify(one.label));
  t.check('kawałki nadal ukryte', one.pieces > 0 && one.hidden === one.pieces, one.hidden + '/' + one.pieces);
  t.check('linie blokowe (transformacje)', one.tag === 'DIV' && one.display === 'block',
    one.tag + ', display ' + one.display);
  await page.close();

  // ── Animator: kontener z dziećmi — auto MA zniknąć ────────────────────
  t.section('Animator — kontener z wieloma dziećmi');
  page = await t.open('aria.html', { query: 'mode=anim-many' });
  const many = await page.evaluate(() => ({
    label: document.querySelector('.evk-anim-split').getAttribute('aria-label'),
    hidden: document.querySelectorAll('.evk-anim-line[aria-hidden="true"]').length,
    pieces: document.querySelectorAll('.evk-anim-line').length,
    display: (function () { const l = document.querySelector('.evk-anim-line'); return l && getComputedStyle(l).display; })(),
  }));
  t.check('brak sklejonego aria-label', many.label === null, JSON.stringify(many.label));
  t.check('nic nie jest ukryte', many.hidden === 0, many.hidden + '/' + many.pieces);
  t.check('linie nadal blokowe', many.display === 'block', many.display);
  tree = await axTree(page);
  t.check('nagłówek i odnośnik mają nazwy',
    tree.includes('heading:"Projektujemy"') && tree.includes('link:"piszemy o tym"'), tree.join('  |  '));
  await page.close();
};
