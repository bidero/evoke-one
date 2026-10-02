/**
 * DeepL jako czwarty dostawca tłumaczenia AI (1.277.0) — sonda
 * tests/php/tl-deepl.php, DeepL przez atrapę (tests/php/_ai-atrapa.php).
 *
 * Decyzje zgłaszającego (02.10): klucz Free (kończy się „:fx”, api-free)
 * i Pro; formalność dla każdego języka; słowniczek jako glosariusz;
 * `tag_handling=html`; wariant z kodu HTML (bez regionu: EN-GB, PT-PT);
 * kontekst strony w parametrze `context`; DeepL w wyborze dostawców,
 * a opis obrazów przez model AI z kluczem.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (...a) => {
  const s = phpOutput('tl-deepl.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const tlumacz = (z) => z.filter((x) => /\/v2\/translate$/.test(x.url));
const glos = (z) => z.filter((x) => /\/v2\/glossaries/.test(x.url));
const SLOWNICZEK = 'usługi | services | Dienstleistungen\n!Evoke\n# komentarz';

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  sonda('sprzataj');
  try {
    const p = sonda('przygotuj');
    t.check('języki z wariantami, mapa pól, strona A', p.gotowe === true, J(p));
    if (p.gotowe !== true) return;

    t.section('wariant języka z kodu HTML');
    const w = sonda('warianty');
    t.check('en → EN-GB, en-US → EN-US, de-DE → DE, pt → PT-PT, pt-BR → PT-BR; glosariusz bez wariantu',
      J(w.warianty) === J({ en: ['EN-GB', 'en'], de: ['DE', 'de'], us: ['EN-US', 'en'], pt: ['PT-PT', 'pt'], br: ['PT-BR', 'pt'] }), J(w));

    t.section('ustawienia: DeepL z panelu (prawdziwy AJAX)');
    const u = sonda('ajax-ustawienia', 'free', SLOWNICZEK);
    t.check('zapis: dostawca deepl, formalność EN formalna i DE nieformalna (zła wartość odrzucona), bez modelu',
      u.odp && u.odp.success === true && u.dostawca === 'deepl' && J(u.formalnosc) === J({ en: 'formalna', de: 'nieformalna' }) && u.model_deepl === null, J(u));
    t.check('klucz z „:fx” → Free; podpis „deepl/free”', u.rodzaj === 'free' && u.podpis === 'deepl/free', J([u.rodzaj, u.podpis]));

    t.section('krok hurtu: Free, EN (EN-GB), formalność, glosariusz, kontekst, ochrona tagów');
    const k1 = sonda('krok', 'en');
    const z1 = k1.zadania || [];
    const tr1 = tlumacz(z1)[0] || { body: {} };
    console.log('      żądanie: ' + J(Object.assign({}, tr1.body, { context: (tr1.body.context || '').slice(0, 60) + '…' })));
    t.check('adres Free (api-free.deepl.com/v2/translate), nagłówek DeepL-Auth-Key, jedno żądanie tłumaczenia',
      tlumacz(z1).length === 1 && /^https:\/\/api-free\.deepl\.com\/v2\/translate$/.test(tr1.url) && tr1.auth === 'free', J(tlumacz(z1).map((x) => [x.url, x.auth])));
    t.check('źródło PL, cel EN-GB, tag_handling=html, formalność prefer_more',
      tr1.body.source_lang === 'PL' && tr1.body.target_lang === 'EN-GB' && tr1.body.tag_handling === 'html' && tr1.body.formality === 'prefer_more', J(tr1.body));
    t.check('kontekst: tytuł i teksty części strony bez znaczników', /^Strona DeepL A\nNasze usługi w Evoke\nWitamy na \{post_title\} & razem Druga linia/.test(tr1.body.context || ''), J(tr1.body.context));
    const g1 = glos(z1);
    t.check('glosariusz ze słowniczka utworzony raz: pl → en, wpisy TSV (para i nazwa „!”), id w żądaniu',
      g1.length === 1 && g1[0].metoda === 'POST' && g1[0].body.source_lang === 'pl' && g1[0].body.target_lang === 'en' && g1[0].body.entries_format === 'tsv'
      && g1[0].body.entries === 'Evoke\tEvoke\nusługi\tservices\n' && /^gl-/.test(tr1.body.glossary_id || ''), J(g1.map((x) => x.body)));
    const teksty = tr1.body.text || [];
    t.check('tag {post_title} i shortcode [tl …] w translate="no", nowa linia jako <br> ze znacznikiem',
      teksty.some((x) => x.includes('<span translate="no" data-evk-dl="1">{post_title}</span>') && x.includes('<br data-evk-dl="nl">'))
      && teksty.some((x) => x.includes('<span translate="no" data-evk-dl="1">[tl key=fraza]</span>')), J(teksty));
    const pola = k1.pola || {};
    console.log('      pola: ' + J(pola));
    t.check('wynik: tag i shortcode wróciły jako tekst, nowa linia wróciła, HTML z encją bez zmian',
      pola['dt1|evk_tl_en__text'] === '<p>EN-GB:Witamy na {post_title}EN-GB: &amp; razem</p>\nEN-GB:Druga linia'
      && pola['dt2|evk_tl_en__text'] === 'EN-GB:Zobacz [tl key=fraza]EN-GB: teraz', J(pola));
    t.check('tekst bez znaczników i encji: „&amp;” z trybu HTML wraca jako „&”', pola['db1|evk_tl_en__text'] === 'EN-GB:Zespół & partnerzy', J(pola['db1|evk_tl_en__text']));
    t.check('zapisane cztery teksty, wszystkie z AI', k1.wynik && k1.wynik.zapisane === 4 && k1.wynik.z_ai === 4, J(k1.wynik));

    t.section('glosariusz: ten sam słowniczek — bez nowego; zmieniony — stary usunięty, nowy');
    sonda('wyczysc');
    const k2 = sonda('krok', 'en');
    t.check('drugi przebieg z tym samym słowniczkiem: bez żądań do glosariuszy, to samo id', glos(k2.zadania || []).length === 0
      && (tlumacz(k2.zadania || [])[0] || { body: {} }).body.glossary_id === tr1.body.glossary_id, J(glos(k2.zadania || [])));
    sonda('ajax-ustawienia', 'free', 'usługi | offerings | Leistungen');
    sonda('wyczysc');
    const k3 = sonda('krok', 'en');
    const g3 = glos(k3.zadania || []);
    t.check('zmieniony słowniczek: DELETE starego, POST nowego, nowe id w tłumaczeniu',
      J(g3.map((x) => x.metoda)) === J(['DELETE', 'POST']) && g3[0].url.endsWith('/v2/glossaries/' + tr1.body.glossary_id)
      && (tlumacz(k3.zadania || [])[0] || { body: {} }).body.glossary_id !== tr1.body.glossary_id, J(g3.map((x) => [x.metoda, x.url])));

    t.section('Pro, DE (nieformalna), US (EN-US), bez słowniczka — bez glosariusza');
    sonda('ajax-ustawienia', 'pro', '');
    const k4 = sonda('krok', 'de');
    const tr4 = tlumacz(k4.zadania || [])[0] || { body: {} };
    t.check('klucz bez „:fx” → api.deepl.com; DE z formalnością prefer_less; pusty słowniczek — bez glosariusza (stary usunięty)',
      /^https:\/\/api\.deepl\.com\/v2\/translate$/.test(tr4.url) && tr4.auth === 'pro' && tr4.body.target_lang === 'DE' && tr4.body.formality === 'prefer_less'
      && !('glossary_id' in tr4.body) && glos(k4.zadania || []).every((x) => x.metoda === 'DELETE'), J([tr4.url, tr4.body.target_lang, tr4.body.formality, glos(k4.zadania || [])]));
    const k5 = sonda('krok', 'us');
    const tr5 = tlumacz(k5.zadania || [])[0] || { body: {} };
    t.check('US: EN-US, formalność domyślna — bez parametru', tr5.body.target_lang === 'EN-US' && !('formality' in tr5.body), J(tr5.body));

    t.section('błędy DeepL');
    sonda('wyczysc');
    const b456 = sonda('krok', 'pt', 'deepl-456');
    t.check('456: limit znaków — stop, z komunikatem', b456.wynik && b456.wynik.stop === true && /Limit znaków DeepL/.test(b456.wynik.blad || ''), J(b456.wynik));
    const b403 = sonda('krok', 'pt', 'deepl-403');
    t.check('403: zły klucz — stop', b403.wynik && b403.wynik.stop === true && /odrzucił klucz/.test(b403.wynik.blad || ''), J(b403.wynik));
    const b429 = sonda('krok', 'pt', 'deepl-429');
    t.check('429: czekaj (retry-after 9 s), bez stopu', b429.wynik && b429.wynik.czekaj === 9 && !b429.wynik.stop, J(b429.wynik));
    sonda('ajax-ustawienia', 'pro', SLOWNICZEK);
    const bg = sonda('krok', 'pt', 'deepl-glosariusz-blad');
    t.check('glosariusz nie powstał: tłumaczenie idzie bez niego, uwaga w wyniku', bg.wynik && bg.wynik.zapisane === 4
      && /Glosariusz DeepL nie powstał/.test(bg.wynik.blad || '') && !('glossary_id' in ((tlumacz(bg.zadania || [])[0] || { body: {} }).body)), J(bg.wynik));

    t.section('jeden tekst i builder przez DeepL');
    const j = sonda('jeden', 'br', 'dh1|text|br');
    t.check('„Przetłumacz ponownie”: PT-BR, podpis deepl/pro', j.wynik && j.wynik.ok === true && /^PT-BR:/.test(j.wynik.tekst || '') && j.wynik.model === 'deepl/pro', J(j.wynik));
    const bu = sonda('builder', 'en');
    t.check('builder: oba teksty z DeepL, tag zachowany', bu.wynik && J(bu.wynik.tlumaczenia) === J({ k1: 'EN-GB:Nagłówek z buildera', k2: 'EN-GB:Tekst z buildera {post_title}' })
      && tlumacz(bu.zadania || []).length === 1, J(bu.wynik));

    t.section('opis obrazu przy DeepL');
    const o1 = sonda('opisy', 'deepl,claude,openai', 'openai');
    const o2 = sonda('opisy', 'deepl,claude', '');
    const o3 = sonda('opisy', 'deepl', '');
    t.check('wybrany model do opisów, a bez wyboru pierwszy z kluczem', o1.dla_opisow === 'openai' && o2.dla_opisow === 'claude', J([o1.dla_opisow, o2.dla_opisow]));
    t.check('bez modelu AI z kluczem: opis odmawia z wyjaśnieniem (stop)', o3.dla_opisow === 'deepl' && o3.opisz && o3.opisz.stop === true
      && /wymaga klucza Gemini, Claude albo OpenAI/.test(o3.opisz.blad || ''), J(o3.opisz));
  } finally {
    sonda('sprzataj');
  }
};
