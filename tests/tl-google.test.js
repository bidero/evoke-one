/**
 * Google Cloud Translation v3 jako piąty dostawca (1.280.0) — sonda
 * tests/php/tl-google.php, Google przez atrapę (tests/php/_ai-atrapa.php),
 * która sprawdza podpis JWT kluczem z pary wygenerowanej w teście.
 *
 * Decyzje zgłaszającego (02.10): v3 (ten sam darmowy limit co v2), plik
 * JSON konta usługi, słowniczek jako glosariusz przez Cloud Storage.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (...a) => {
  const s = phpOutput('tl-google.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const sciezki = (z) => (z || []).map((x) => x.metoda + ' ' + x.url.replace(/^https:\/\/[a-z0-9.]+googleapis\.com/, ''));
const tlumacz = (z) => (z || []).filter((x) => /:translateText$/.test(x.url));
const SLOWNICZEK = 'usługi | services | Dienstleistungen\\n!Evoke\\n# komentarz';

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress z OpenSSL');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh), PHP ma OpenSSL', !wp.brak && !!wp.wp && wp.openssl === true, wp.brak || J(wp));
  if (wp.brak || !wp.wp) return;
  sonda('sprzataj');
  try {
    const p = sonda('przygotuj');
    t.check('języki, para kluczy RSA, strona A', p.gotowe === true, J(p));
    if (p.gotowe !== true) return;

    t.section('kody języków Google');
    t.check('en-GB → en, de-DE → de, pt → pt-PT, pt-BR → pt, zh-TW → zh-TW',
      J(sonda('jezyki').jezyki) === J({ en: 'en', de: 'de', pt: 'pt-PT', br: 'pt', tw: 'zh-TW' }));

    t.section('ustawienia: plik JSON konta usługi (prawdziwy AJAX)');
    const z = sonda('ajax-ustawienia', 'zly', 'evoke-test', '');
    t.check('plik bez konta usługi odrzucony z wyjaśnieniem, nic nie zapisane', z.odp && z.odp.success === false && /service_account/.test(z.odp.data) && z.dostawca === null, J(z));
    const u = sonda('ajax-ustawienia', 'json', 'gs://evoke-test/', SLOWNICZEK);
    t.check('zapis: tylko potrzebne pola pliku, zasobnik bez „gs://”, bez modelu, podpis google/nmt',
      u.odp && u.odp.success === true && J(u.pola_klucza) === J(['type', 'client_email', 'private_key', 'project_id', 'private_key_id'])
      && u.zasobnik === 'evoke-test' && u.model_google === null && u.podpis === 'google/nmt', J(u));

    t.section('pierwszy krok: token, słowniczek do zasobnika, glosariusz w przygotowaniu');
    const k1 = sonda('krok', 'en');
    t.check('token: JWT podpisany kluczem z pliku (atrapa sprawdza podpis), potem wgranie TSV i utworzenie glosariusza',
      J(sciezki(k1.zadania).map((x) => x.replace(/(name=evoke-slowniczek-en-)[0-9a-f]{8}/, '$1X').replace(/op-[0-9a-f]+/, 'op'))) === J([
        'POST /token', 'POST /upload/storage/v1/b/evoke-test/o?uploadType=media&name=evoke-slowniczek-en-X.tsv',
        'POST /v3/projects/projekt-test/locations/us-central1/glossaries']) && (k1.zadania[0].body || {}).assertion === 'jwt', J(sciezki(k1.zadania)));
    const plik = Object.keys((k1.stan || {}).pliki || {})[0] || '';
    const glo = (k1.zadania || [])[2] || { body: {} };
    t.check('plik TSV: para i nazwa „!”; glosariusz pl → en z gs://zasobnik/plik',
      (k1.stan.pliki || {})[plik] === 'Evoke\tEvoke\nusługi\tservices\n' && J(glo.body.languagePair) === J({ sourceLanguageCode: 'pl', targetLanguageCode: 'en' })
      && glo.body.inputConfig.gcsSource.inputUri === 'gs://evoke-test/' + plik, J([plik, glo.body]));
    t.check('hurt czeka (5 s), nic nie zapisane, bez tłumaczenia', k1.wynik && k1.wynik.czekaj === 5 && !k1.wynik.stop && k1.wynik.zapisane === 0
      && tlumacz(k1.zadania).length === 0 && /glosariusz/.test(k1.wynik.blad || ''), J(k1.wynik));
    const k2 = sonda('krok', 'en');
    t.check('drugi krok: operacja w toku — znów czekaj, token z pamięci (bez /token)',
      k2.wynik.czekaj === 5 && J(sciezki(k2.zadania).map((x) => x.replace(/op-[0-9a-f]+/, 'op'))) === J(['GET /v3/projects/projekt-test/locations/us-central1/operations/op']), J(sciezki(k2.zadania)));

    t.section('glosariusz gotowy: tłumaczenie v3 w trybie HTML');
    const k3 = sonda('krok', 'en');
    const tr = tlumacz(k3.zadania)[0] || { body: {} };
    console.log('      żądanie: ' + J(tr.body).slice(0, 400));
    t.check(':translateText w us-central1, pl → en, text/html, glosariusz w żądaniu',
      /\/v3\/projects\/projekt-test\/locations\/us-central1:translateText$/.test(tr.url || '') && tr.body.sourceLanguageCode === 'pl' && tr.body.targetLanguageCode === 'en'
      && tr.body.mimeType === 'text/html' && /\/glossaries\/evoke-en-[0-9a-f]{8}$/.test(((tr.body.glossaryConfig || {}).glossary) || ''), J(tr.body));
    t.check('{post_title} i shortcode w translate="no", nowa linia jako <br>', (tr.body.contents || []).some((x) => x.includes('<span translate="no" data-evk-dl="1">{post_title}</span>') && x.includes('<br data-evk-dl="nl">'))
      && (tr.body.contents || []).some((x) => x.includes('<span translate="no" data-evk-dl="1">[tl key=fraza]</span>')), J(tr.body.contents));
    const pola = k3.pola || {};
    console.log('      pola: ' + J(pola));
    t.check('wynik z glosariusza; tag, shortcode i nowa linia wracają; w tekście bez znaczników „&” i apostrof jako znaki',
      pola['gt1|evk_tl_en__text'] === '<p>G-EN[gl]:Witamy na {post_title}G-EN[gl]: &amp; razem</p>\nG-EN[gl]:Druga linia'
      && pola['gt2|evk_tl_en__text'] === 'G-EN[gl]:Zobacz [tl key=fraza]G-EN[gl]: teraz' && pola['gb1|evk_tl_en__text'] === "G-EN[gl]:Zespół & O'Neill", J(pola));
    t.check('cztery teksty zapisane jako AI', k3.wynik && k3.wynik.zapisane === 4 && k3.wynik.z_ai === 4, J(k3.wynik));

    t.section('zmiana słowniczka i brak zasobnika');
    sonda('ajax-ustawienia', 'json', 'evoke-test', 'usługi | offerings | Leistungen');
    sonda('wyczysc');
    const k4 = sonda('krok', 'en');
    t.check('nowy słowniczek: stary glosariusz usunięty, nowy plik i nowy glosariusz', sciezki(k4.zadania).some((x) => /^DELETE \/v3\/.*\/glossaries\/evoke-en-/.test(x))
      && sciezki(k4.zadania).some((x) => /^POST \/upload\/storage/.test(x)) && k4.wynik.czekaj === 5, J(sciezki(k4.zadania)));
    sonda('ajax-ustawienia', 'json', '', 'usługi | offerings | Leistungen');
    const k5 = sonda('krok', 'en');
    t.check('bez zasobnika: tłumaczenie bez glosariusza, z uwagą', k5.wynik.zapisane === 4 && /zasobnik Cloud Storage/.test(k5.wynik.blad || '')
      && !('glossaryConfig' in ((tlumacz(k5.zadania)[0] || { body: {} }).body)) && /^G-EN:/.test((k5.pola || {})['gh1|evk_tl_en__text'] || ''), J(k5.wynik));
    sonda('ajax-ustawienia', 'json', 'evoke-test', '');
    sonda('wyczysc');
    const k6 = sonda('krok', 'de');
    t.check('pusty słowniczek: bez glosariusza i bez uwagi; DE', k6.wynik.zapisane === 4 && !k6.wynik.blad && /^G-DE:/.test((k6.pola || {})['gh1|evk_tl_de__text'] || ''), J(k6.wynik));

    t.section('błędy Google');
    sonda('wyczysc');
    const b429 = sonda('krok', 'pt', 'google-429');
    t.check('429: czekaj (retry-after 11 s), bez stopu', b429.wynik.czekaj === 11 && !b429.wynik.stop, J(b429.wynik));
    const b403 = sonda('krok', 'pt', 'google-403');
    t.check('403: stop z podpowiedzią o roli i API', b403.wynik.stop === true && /odmówił dostępu.*Cloud Translation API Editor/.test(b403.wynik.blad || ''), J(b403.wynik));
    sonda('wyczysc', 'token');
    const bt = sonda('krok', 'pt', 'google-token-blad');
    t.check('konto usługi odrzucone przy tokenie: stop z opisem Google', bt.wynik.stop === true && /odrzucił konto usługi \(400: Invalid JWT Signature/.test(bt.wynik.blad || ''), J(bt.wynik));
    /* „!Evoke” — nazwa we wszystkich językach (para „usługi | services” jest tylko w kolumnie EN). */
    sonda('ajax-ustawienia', 'json', 'evoke-test', '!Evoke');
    const bz = sonda('krok', 'pt', 'google-zasobnik-403');
    t.check('zasobnik bez uprawnień: uwaga o roli Storage Object Admin, tłumaczenie bez glosariusza', bz.wynik.zapisane === 4
      && /Storage Object Admin/.test(bz.wynik.blad || '') && /^G-PT-PT:/.test((bz.pola || {})['gh1|evk_tl_pt__text'] || ''), J(bz.wynik));
    sonda('wyczysc');
    const bg1 = sonda('krok', 'pt', 'google-glosariusz-blad');
    const bg2 = sonda('krok', 'pt', 'google-glosariusz-blad');
    const bg3 = sonda('krok', 'pt', 'google-glosariusz-blad');
    t.check('glosariusz zakończony błędem: uwaga i tłumaczenie bez niego', bg1.wynik.czekaj === 5 && bg2.wynik.czekaj === 5 && bg3.wynik.zapisane === 4
      && /Glosariusz Google nie powstał \(Invalid glossary file\)/.test(bg3.wynik.blad || ''), J([bg1.wynik.czekaj, bg2.wynik.czekaj, bg3.wynik]));

    t.section('jeden tekst i opisy obrazów');
    const j1 = sonda('jeden', 'br', 'gh1|text|br');
    t.check('„Przetłumacz ponownie” przy glosariuszu w przygotowaniu: powód i czas, nie ogólna przerwa',
      j1.wynik && j1.wynik.ok === false && /^Google przygotowuje glosariusz ze słowniczka — czekam\. Spróbuj za 5 s\.$/.test(j1.wynik.blad || ''), J(j1.wynik));
    sonda('jeden', 'br', 'gh1|text|br');
    const j = sonda('jeden', 'br', 'gh1|text|br');
    t.check('po przygotowaniu: pt-BR → „pt” z glosariuszem, podpis google/nmt', j.wynik && j.wynik.ok === true && /^G-PT\[gl\]:/.test(j.wynik.tekst || '') && j.wynik.model === 'google/nmt', J(j.wynik));
    const o = sonda('opisy');
    t.check('opis obrazu: bez klucza AI — odmowa z wyjaśnieniem; z kluczem Claude — Claude', o.bez === 'google' && o.opisz && o.opisz.stop === true
      && /DeepL i Google Translation tylko tłumaczą/.test(o.opisz.blad || '') && o.z_claude === 'claude', J(o));
  } finally {
    sonda('sprzataj');
  }
};
