/**
 * Evoke FIELDS 1.71.0 — czwarty testowy WordPress (pola.test, obie wtyczki).
 *
 * Zgłoszenia:
 * - galerii FIELDS nie dało się podać natywnej „Image Gallery" Bricksa: tag
 *   w kontekście obrazu oddawał tylko pierwsze ID, a pętla robiła wiele
 *   jednoobrazkowych galerii;
 * - pola pętli (`{evk_field_img__id}` itd.) znała tylko ściąga w kreatorze;
 * - grupy pól nie dało się dodać bez miejsca — po cichu dostawała „Wpisy"
 *   i metaboks przy każdym wpisie. Teraz „Tylko strona ustawień".
 *
 * Bricksa tu nie ma: tagi idą przez funkcje, które Bricks woła filtrami
 * (render_tag z kontekstem 'image', query/run). Edytor grupy i ekran wpisu —
 * w Chromium, z prawdziwym zapisem.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (tryb) => {
  const w = phpOutput('fields-galeria-lokalizacja.php', tryb, { dopuscBlad: true });
  try { return JSON.parse(w); } catch (e) { return { brak: 'sonda „' + tryb + '": ' + w.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);

module.exports = async function (t) {
  t.section('środowisko: pola.test z Evoke ONE i Evoke FIELDS');
  const u = sonda('ustaw');
  t.check('czwarty WordPress z Evoke FIELDS 1.71.0+ (tools/testowy-wp.sh)', !u.brak && u.gotowe === true, u.brak || J(u));
  if (u.brak || !u.gotowe) return;

  try {
    // ── Galeria w kontekście obrazu ─────────────────────────────────────────
    t.section('galeria FIELDS w natywnej „Image Gallery" (kontekst obrazu)');
    const g = sonda('galeria');
    const O = g.obrazy || [];
    t.check('{evk_field_galeria} w kontekście obrazu: CAŁA galeria (lista ID), nie tylko pierwsze',
      J(g.obraz_galeria) === J([O[0], O[1], O[2]]), J(g.obraz_galeria));
    t.check('{evk_field_galeria__ids} w kontekście obrazu: to samo', J(g.obraz_ids) === J([O[0], O[1], O[2]]), J(g.obraz_ids));
    t.check('pusta galeria: pusta lista', J(g.obraz_pusta) === '[]', J(g.obraz_pusta));
    t.check('pole Obraz (__id) w elemencie Image bez zmian: jedno ID', J(g.obraz_pojedynczy) === J([O[3]]), J(g.obraz_pojedynczy));
    t.check('w tekście bez zmian: __ids = lista po przecinku, bez propa = URL pierwszego',
      g.tekst_ids === [O[0], O[1], O[2]].join(',') && g.tekst_url === g.url_pierwszego, J([g.tekst_ids, g.tekst_url]));
    t.check('galeria ze strony ustawień z sortowaniem „losowo, co dzień": wszystkie obrazy, w tej samej kolejności co pętla tej galerii',
      Array.isArray(g.opcje_obraz) && g.opcje_obraz.length === 5 && J(g.opcje_obraz) === J(g.opcje_petla)
        && J([...g.opcje_obraz].sort((a, b) => a - b)) === J([...O].sort((a, b) => a - b)), J([g.opcje_obraz, g.opcje_petla]));

    // ── Podpowiedzi ─────────────────────────────────────────────────────────
    t.section('podpowiedzi buildera (lista tagów)');
    const p = sonda('podpowiedzi');
    t.check('pola pętli galerii w podpowiedziach („EVK Pętla: galeria")',
      ['{evk_field_img__id}', '{evk_field_img}', '{evk_field_cat}', '{evk_field_cat__label}'].every((x) => (p.galeria || []).includes(x)), J(p.galeria));
    t.check('pola pętli kategorii galerii („EVK Pętla: kategorie galerii")',
      J(p.kategorie) === J(['{evk_field_name}', '{evk_field_slug}']), J(p.kategorie));
    t.check('grupa „Tylko strona ustawień": w „EVK:" tylko pola list (do pętli „Opcje"), bez {evk_field_…} pól z góry grupy',
      J(p.opcje_evk) === J(['{evk_field_pozycja_gl}']), J(p.opcje_evk));
    t.check('grupa „Tylko strona ustawień": tagi {evk_opt_…} są', (p.opcje_opcje || []).includes('{evk_opt_gl_opcje_haslo_gl}')
      && (p.opcje_opcje || []).includes('{evk_opt_gl_opcje_galeria_opc}'), J(p.opcje_opcje));
    t.check('grupa wpisu bez zmian', (p.wpis_evk || []).includes('{evk_field_galeria_gl}') && (p.wpis_evk || []).includes('{evk_field_obraz_gl__id}'), J(p.wpis_evk));

    // ── Pętle ───────────────────────────────────────────────────────────────
    t.section('pętle grupy „Tylko strona ustawień"');
    const L = (sonda('petle').petle) || {};
    t.check('bez pętli wpisowych (czytałyby meta, której grupa nie ma)', L.galeria_opc === null && L.evk_galcat_galeria_opc === null && L.lista_gl === null, J(L));
    t.check('pętle „Opcje" są', !!L['evk_opt_gl_opcje.galeria_opc'] && !!L['evk_galcatopt_gl_opcje.galeria_opc'] && !!L['evk_opt_gl_opcje.lista_gl'], J(L));
    t.check('ten sam klucz pola w grupie wpisu: jej pętla zostaje', /Wpis GL/.test(L.galeria_wsp || '') && !!L['evk_opt_gl_opcje.galeria_wsp'], J(L));
    t.check('grupa opcji ZA grupą wpisu z tym samym kluczem: pętla wpisowa wraca do grupy wpisu, nie znika',
      /Inna GL/.test(L.galeria_inna || '') && /Opcje GL 2/.test(L['evk_opt_gl_opcje2.galeria_inna'] || ''), J([L.galeria_inna, L['evk_opt_gl_opcje2.galeria_inna']]));

    t.section('ten sam klucz pola w grupie opcji i grupie wpisu');
    const k = sonda('konflikt');
    t.check('{evk_field_…} bierze pole z grupy wpisu, nie z grupy opcji (pierwszej na liście)', k.kontekst === 'post:text' && k.etykieta === 'a', J(k));
    t.check('pole grupy opcji: tylko przez {evk_opt_…}', k.haslo_z_wpisu === '' && k.haslo_z_opcji === 'Hasło', J(k));

    const e = sonda('eksport');
    t.check('eksport i import zachowują „Tylko strona ustawień" (dawniej zamieniane na „Wpisy")', e.eksport === 'options' && e.import === 'options', J(e));

    // ── Edytor grupy i ekran wpisu (Chromium) ───────────────────────────────
    t.section('edytor grupy: „Tylko strona ustawień" i zakładki stron');
    const s0 = sonda('stan');
    let serwer = null, browser = null;
    const bledy = [];
    try {
      serwer = await serwerWp.start(s0.wp);
      const b = serwer.baza;
      browser = await chromium.launch({ executablePath: chromiumPath() });
      const pg = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
      pg.on('pageerror', (er) => bledy.push(pg.url().replace(b, '') + ': ' + er.message));
      await serwerWp.zaloguj(pg, b);
      const edytujGrupe = () => pg.goto(b + '/wp-admin/post.php?post=' + s0.grupy.opcje + '&action=edit', { waitUntil: 'load' });
      const lokalizacja = () => pg.evaluate(() => {
        const box = document.querySelector('.evk-group-location');
        const blok = document.querySelector('.evk-loc-options');
        const war = document.querySelector('.evk-loc-warn');
        return {
          typ: (document.querySelector('.evk-group-object-type') || {}).value,
          blok: blok ? blok.offsetHeight : -1,
          zaklad: [...document.querySelectorAll('.evk-loc-options input[type="checkbox"]')].map((i) => (i.checked ? '✔ ' : '') + (i.closest('label') || {}).textContent.replace(/\s+/g, ' ').trim()),
          ostrzezenie: war ? war.offsetHeight > 0 : false,
          atr: box ? box.getAttribute('data-object-type') : null,
        };
      });
      await edytujGrupe();
      let l = await lokalizacja();
      t.check('„Pokaż w" = „Tylko strona ustawień", blok zakładek widoczny', l.typ === 'options' && l.atr === 'options' && l.blok > 0, J(l));
      t.check('zakładki wszystkich stron z etykietą „Strona › Zakładka", nic nie zaznaczone', J(l.zaklad.filter((z) => /Strona GL/.test(z))) === J(['Strona GL › Ogólne', 'Strona GL › Galeria']), J(l.zaklad));
      t.check('ostrzeżenie: grupa na żadnej stronie ustawień', l.ostrzezenie === true, J(l));

      /* Zaznaczenie skryptem, nie kliknięciem: widoczność bloku ma osobne sprawdzenie,
         a tu chodzi o zapis — przy ukrytym bloku reszta testu ma iść dalej. */
      const zaznacz = (wartosc, tak) => pg.evaluate(([v, t]) => {
        const i = document.querySelector('.evk-loc-options input[value="' + v + '"]');
        if (i) i.checked = t;
        return !!i;
      }, [wartosc, tak]);
      const zapiszGrupe = async () => {
        await Promise.all([pg.waitForNavigation({ waitUntil: 'load', timeout: 30000 }), pg.click('#publish')]);
      };
      await zaznacz('strona-gl|1', true);
      await zapiszGrupe();
      let s = sonda('stan');
      t.check('zapis grupy: grupa trafia na zaznaczoną zakładkę, inna grupa zostaje na swojej',
        J(s.zakladki) === J([['Ogólne', ['gl_inna']], ['Galeria', ['gl_opcje']]]), J(s.zakladki));
      t.check('zapis grupy nie rusza definicji pól ani typu', s.typ_opcji === 'options' && s.pola_opcji === 5, J(s));
      l = await lokalizacja();
      t.check('po zapisie: zakładka zaznaczona, bez ostrzeżenia', l.zaklad.includes('✔ Strona GL › Galeria') && !l.ostrzezenie, J(l));
      /* Obcięty POST (max_input_vars): brakuje zaznaczeń i znacznika końca listy —
         zapis nie może zdjąć grupy ze stron. */
      await pg.evaluate(() => document.querySelectorAll('.evk-loc-options input[type="checkbox"], input[name="evk_group_settings_tabs_sent"]').forEach((i) => i.remove()));
      await zapiszGrupe();
      s = sonda('stan');
      t.check('obcięty formularz (bez znacznika końca listy): grupa zostaje na zakładce', J(s.zakladki) === J([['Ogólne', ['gl_inna']], ['Galeria', ['gl_opcje']]]), J(s.zakladki));
      await zaznacz('strona-gl|1', false);
      await zapiszGrupe();
      s = sonda('stan');
      t.check('odznaczenie zdejmuje grupę z zakładki', J(s.zakladki) === J([['Ogólne', ['gl_inna']], ['Galeria', []]]), J(s.zakladki));

      t.section('ekran wpisu: grupa „Tylko strona ustawień" bez metaboksu');
      await pg.goto(b + '/wp-admin/post.php?post=' + s0.wpis + '&action=edit', { waitUntil: 'load' });
      await pg.waitForTimeout(1500);
      const mb = await pg.evaluate(() => ({ wpis: !!document.querySelector('#evk_rep_gl_wpis'), opcje: !!document.querySelector('#evk_rep_gl_opcje') }));
      t.check('metaboks grupy wpisu jest, grupy opcji — nie ma', mb.wpis && !mb.opcje, J(mb));

      t.section('edytor grupy na telefonie (360 px)');
      await pg.setViewportSize({ width: 360, height: 800 });
      await edytujGrupe();
      const tel = await pg.evaluate(() => {
        const box = document.querySelector('#evk_group_pts');
        const r = box ? box.getBoundingClientRect() : null;
        const pola = [...document.querySelectorAll('.evk-loc-options input[type="checkbox"]')];
        return {
          szer: window.innerWidth,
          prawa: r ? Math.round(r.right) : null,
          przewijaSie: box ? box.scrollWidth > box.clientWidth + 1 : null,
          bezNazwy: pola.filter((i) => !((i.closest('label') || {}).textContent || '').trim()).length,
          pol: pola.length,
          celMin: pola.length ? Math.min(...pola.map((i) => Math.min(i.getBoundingClientRect().width, i.getBoundingClientRect().height))) : null,
        };
      });
      t.check('skrzynka „Lokalizacja" mieści się w 360 px, bez przewijania w poziomie', tel.prawa !== null && tel.prawa <= tel.szer && tel.przewijaSie === false, J(tel));
      t.check('pola zaznaczenia zakładek mają nazwy (etykieta z tekstem)', tel.pol >= 2 && tel.bezNazwy === 0, J(tel));
      t.check('bez błędów JS w edytorze grupy i na ekranie wpisu', !bledy.length, bledy.join(' | '));
    } finally {
      if (browser) await browser.close();
      if (serwer) await serwer.zatrzymaj();
    }
  } finally {
    sonda('sprzataj');
  }
};
