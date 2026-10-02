/**
 * PRAWDZIWY builder Bricksa (1.274.0) — piąty testowy WordPress z licencją
 * (EVK_BRICKS_KLUCZ, tools/testowy-wp.sh), Chromium przez `php -S`, atrapa
 * dostawców AI jako mu-plugin (sonda tests/php/bricks-builder.php).
 *
 * Trzy rzeczy, których fixtura builder-ai.html nie pokazała, bo odtwarzała
 * panel z opisu, a nie z Bricksa:
 *  - pole „Właściwości” instancji to jednowierszowa textarea `auto-height`
 *    z ⚡ w rogu; ✦ „pod ⚡” wisiało pod polem — ma stać obok ⚡, w polu;
 *  - bliźniak języka spoza ustawień („Nagłówek DE”) zostaje w komponencie,
 *    a ✦ przy nim wysyłało kod, którego serwer nie zna („Nieznany język.”);
 *  - jednorazowe przejście po komponentach przy pierwszym żądaniu panelu,
 *    z pustą mapą pól, kasowało bliźniaki razem z wpisanymi tłumaczeniami.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('bricks-builder.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const WLASCIWOSCI = ['Nagłówek:brknwn', 'Nagłówek EN:brknwe', 'Nagłówek DE:brknwd'];

/** Stan powłoki buildera (Vue). */
const stan = (p, f, arg) => p.evaluate(([fs, a]) => {
  const st = document.querySelector('.brx-body.main').__vue_app__.config.globalProperties.$_state;
  return new Function('st', 'a', 'return (' + fs + ')(st, a);')(st, a);
}, [String(f), arg === undefined ? null : arg]);

module.exports = async function (t) {
  t.section('środowisko: piąty testowy WordPress, Bricks z licencją');
  const wp = sonda('wp');
  t.check('piąty WordPress z motywem Bricks (tools/testowy-wp.sh; zip w ../bricks-motyw albo EVK_BRICKS_ZIP)',
    !wp.brak && wp.motyw === 'Bricks' && !!wp.bricks, wp.brak || J(wp));
  if (wp.brak || wp.motyw !== 'Bricks') return;
  t.check('licencja Bricksa aktywna — bez niej builder się nie otwiera (zmienna EVK_BRICKS_KLUCZ, potem tools/testowy-wp.sh)',
    wp.licencja === true, J({ klucz_w_zmiennej: wp.klucz, licencja: wp.licencja }));
  if (wp.licencja !== true) return;

  sonda('sprzataj');
  let serwer = null;
  let browser = null;
  try {
    const mod = sonda('modul');
    const mu = sonda('mu');
    const u = sonda('ustaw');
    t.check('sonda: moduł, atrapa AI, komponent i strona', mod.gotowe === true && mu.mu === true && u.gotowe === true, J([mod, mu, u]));
    if (u.gotowe !== true) return;

    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1500, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => { if (/evoke-one/.test(String(e.stack || ''))) bledy.push(e.message); });
    const zadania = [];
    p.on('request', (r) => {
      const d = r.postData() || '';
      if (/admin-ajax\.php/.test(r.url()) && /action=evk_tl_ai_builder/.test(d)) zadania.push(Object.fromEntries(new URLSearchParams(d)));
    });
    const odpowiedzi = [];
    p.on('response', async (r) => {
      if (/admin-ajax\.php/.test(r.url()) && /action=evk_tl_ai_builder/.test(r.request().postData() || '')) odpowiedzi.push(await r.text().catch(() => ''));
    });

    t.section('jednorazowe przejście po komponentach: pusta mapa pól, potem logowanie');
    const s0 = sonda('przejscie-raz');
    t.check('przejście (admin_init) z pustą mapą: bliźniaki EN i DE zostają, przejście czeka na mapę',
      J(s0.wlasciwosci) === J(WLASCIWOSCI) && s0.przejscie === null, J(s0));
    await serwerWp.zaloguj(p, serwer.baza);
    const s1 = sonda('stan');
    t.check('po logowaniu (przejście już z mapą albo nadal czeka): bliźniaki EN i DE w bazie całe',
      J(s1.wlasciwosci) === J(WLASCIWOSCI), J(s1));

    t.section('builder: ✦ we „Właściwościach” instancji');
    await p.goto(serwer.baza + '/?page_id=' + u.strona + '&bricks=run', { timeout: 60000 });
    await p.waitForSelector('.brx-body.main', { timeout: 60000 });
    await p.waitForFunction(() => Array.from(document.querySelectorAll('iframe')).some((f) => { try { return !!f.contentWindow.__evkTlPodglad; } catch (e) { return false; } }),
      null, { timeout: 30000 }).catch(() => {});
    const wStanie = await stan(p, (st) => st.components.map((k) => [k.id, k.properties.map((w) => w.label + ':' + w.id)]));
    t.check('builder: komponent z bliźniakami EN i DE w stanie (DE — język spoza ustawień, zostaje)', J(wStanie) === J([['brkn01', WLASCIWOSCI]]), J(wStanie));

    await stan(p, (st) => { st.activeId = 'bbina1'; st.activePanel = 'element'; });
    await p.waitForSelector('#bricks-panel-component-instance .evk-tl-ai-ikona', { timeout: 10000 }).catch(() => {});
    await p.waitForTimeout(700);
    const ik = await p.evaluate(() => Array.from(document.querySelectorAll('#bricks-panel-component-instance li')).map((li) => {
      const et = li.querySelector('.label span:not(.indicator):not(.bricks-svg-wrapper)');
      return [et ? et.textContent.trim() : '?', li.querySelectorAll('.evk-tl-ai-ikona').length];
    }));
    t.check('✦ tylko przy „Nagłówek EN” — nie przy polskim i nie przy DE spoza ustawień', J(ik) === J([['Nagłówek', 0], ['Nagłówek EN', 1], ['Nagłówek DE', 0]]), J(ik));

    const geo = await p.evaluate(() => {
      const b = document.querySelector('#bricks-panel-component-instance .evk-tl-ai-ikona');
      if (!b) return null;
      const li = b.closest('li');
      const r = (e) => { const x = e.getBoundingClientRect(); return { l: Math.round(x.left), r: Math.round(x.right), t: Math.round(x.top), b: Math.round(x.bottom) }; };
      const ta = li.querySelector('textarea');
      return { ik: r(b), bolt: r(li.querySelector('.dynamic-tag-picker-button')), pole: r(ta), rows: ta.rows };
    });
    console.log('      geometria: ' + J(geo));
    t.check('jednowierszowe pole: ✦ w polu, na lewo od ⚡, w tym samym wierszu, bez nachodzenia',
      !!geo && geo.rows === 1 && geo.ik.t >= geo.pole.t && geo.ik.b <= geo.pole.b && geo.ik.r <= geo.bolt.l
      && Math.abs(geo.ik.t - geo.bolt.t) <= 1 && geo.ik.l > geo.pole.l, J(geo));
    const naWierzchu = await p.evaluate(() => {
      const b = document.querySelector('#bricks-panel-component-instance .evk-tl-ai-ikona');
      const x = b.getBoundingClientRect();
      const e = document.elementFromPoint(x.left + x.width / 2, x.top + x.height / 2);
      return !!e && (e === b || b.contains(e));
    });
    t.check('✦ na wierzchu — klik w jego środek trafia w niego', naWierzchu);

    t.section('builder: klik ✦ — tłumaczenie właściwości instancji przez serwer');
    await stan(p, (st) => { st.activeId = 'bbinb1'; });
    await p.waitForFunction(() => { const b = document.querySelector('#bricks-panel-component-instance .evk-tl-ai-ikona'); return b && /^bbinb1\|/.test(b.getAttribute('data-dla')); },
      null, { timeout: 10000 }).catch(() => {});
    await p.click('#bricks-panel-component-instance .evk-tl-ai-ikona');
    await p.waitForFunction(() => { const s = document.querySelector('#bricks-panel-component-instance .evk-tl-ai-pole-stan'); return s && s.textContent && !/^Tłumaczę/.test(s.textContent); },
      null, { timeout: 20000 }).catch(() => {});
    const w = await p.evaluate(() => {
      const s = document.querySelector('#bricks-panel-component-instance .evk-tl-ai-pole-stan');
      const li = s && s.closest('li');
      return { stan: s ? s.textContent : null, pole: li ? li.querySelector('textarea').value : null };
    });
    const wl = await stan(p, (st) => st.content.find((e) => e.id === 'bbinb1').properties);
    console.log('      zapytania: ' + J(zadania.map((z) => z.lang)) + ', odpowiedź: ' + (odpowiedzi[0] || '').slice(0, 160));
    t.check('jedno zapytanie z językiem z ustawień (en), serwer je przyjmuje', zadania.length === 1 && zadania[0].lang === 'en'
      && /"success":true/.test(odpowiedzi[0] || '') && !/Nieznany/.test(odpowiedzi[0] || ''), J([zadania.map((z) => z.lang), odpowiedzi]));
    t.check('wynik w `properties` instancji B (bliźniak EN), w polu panelu i w komunikacie',
      wl && wl.brknwe === 'EN:Nagłówek B' && w.pole === 'EN:Nagłówek B' && /^Wpisane \(AI/.test(w.stan || ''), J([wl, w]));

    const s2 = sonda('stan');
    t.check('po wczytaniu buildera mapa pól jest, a bliźniaki w bazie nadal całe', s2.mapa > 0 && J(s2.wlasciwosci) === J(WLASCIWOSCI), J(s2));
    t.check('bez błędów JS Evoke w builderze', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
