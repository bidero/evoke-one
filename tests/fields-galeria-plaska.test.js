/**
 * Płaska galeria jako tag — Evoke FIELDS 1.73.0. Zgłoszenie: strona ustawień, galerie
 * w liście (repeaterze), natywna „Image Gallery" z danymi dynamicznymi bez pętli nic nie
 * pokazuje, w pętli pokazuje („nie ma też spłaszczonej listy"). Płaska lista istniała
 * tylko jako pętla, a pętla z galerią w środku daje osobną galerię na każdy obraz.
 *
 * Czwarty testowy WordPress (pola.test, obie wtyczki). Tagi idą przez funkcje, które
 * Bricks woła filtrami render_tag i render_content, pętla przez bricks/query/run —
 * Bricksa tu nie ma, więc to, czy natywna galeria bierze tę listę, zostaje do
 * sprawdzenia na stronie (lista ID działała już w 1.71.0 dla zwykłej galerii).
 */

const { phpOutput } = require('./lib/harness');

const sonda = (tryb) => {
  const w = phpOutput('fields-galeria-plaska.php', tryb, { dopuscBlad: true });
  try { return JSON.parse(w); } catch (e) { return { brak: 'sonda „' + tryb + '": ' + w.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);

module.exports = async function (t) {
  t.section('środowisko: pola.test z Evoke ONE i Evoke FIELDS');
  const u = sonda('ustaw');
  t.check('czwarty WordPress z Evoke FIELDS (tools/testowy-wp.sh)', !u.brak && u.gotowe === true, u.brak || J(u));
  if (u.brak || !u.gotowe) return;

  try {
    const r = sonda('tagi');
    t.check('sonda tagów odpowiada', !r.brak, r.brak || '');
    if (r.brak) return;
    const o = r.obrazy || [], B = r.obraz || {}, P = r.petla || {}, T = r.tekst || {}, H = r.podpowiedzi || {};

    t.section('natywna galeria (kontekst obrazu): obrazy ze wszystkich wierszy');
    t.check('strona ustawień, pole-lista: wszystkie wiersze po kolei, pusty wiersz pominięty',
      J(B.opcje) === J([o[0], o[1], o[2]]), J(B.opcje));
    t.check(':ids — to samo; :id — tylko pierwszy (jak element Image)',
      J(B.opcje_ids) === J([o[0], o[1], o[2]]) && J(B.opcje_id) === J([o[0]]), J([B.opcje_ids, B.opcje_id]));
    t.check('strona ustawień, grupa-lista', J(B.grupa_lista) === J([o[4], o[5], o[6]]), J(B.grupa_lista));
    t.check('lista w meta wpisu', J(B.wpis) === J([o[6], o[5], o[4]]), J(B.wpis));
    t.check('nieznana płaska galeria: pusta lista', J(B.nieznana) === '[]', J(B.nieznana));
    t.check('pole galerii z listy poza pętlą dalej puste (bez wiersza) — do tego jest płaski tag',
      J(B.pole_z_listy) === '[]', J(B.pole_z_listy));

    t.section('kolejność jak w pętli „EVK Galeria — wszystkie wiersze"');
    t.check('bez sortowania: tag = wiersze pętli', J(B.opcje) === J(P.opcje) && J(B.wpis) === J(P.wpis), J([B.opcje, P.opcje, B.wpis, P.wpis]));
    t.check('losowanie raz na dobę: tag = wiersze pętli (to samo ziarno), te same obrazy',
      J(B.losowa) === J(P.losowa) && J([...(B.losowa || [])].sort()) === J([...(r.losowa_zapisana || [])].sort()), J([B.losowa, P.losowa]));
    t.check('losowanie naprawdę miesza (pętla ≠ kolejność zapisana)', J(P.losowa) !== J(r.losowa_zapisana), J(P.losowa));

    t.section('pole wrażliwe');
    t.check('gość: pusto; redakcja: obrazy', J(r.tajna_gosc) === '[]' && J(r.tajna_admin) === J([o[0]]), J([r.tajna_gosc, r.tajna_admin]));

    t.section('tekst: jak pole galerii');
    t.check('domyślnie URL pierwszego obrazu', T.domyslnie === r.url_1, J(T.domyslnie));
    t.check('__ids, __count, :count', T.ids === [o[0], o[1], o[2]].join(',') && T.count === '3' && T.count_mod === '3', J([T.ids, T.count, T.count_mod]));
    t.check('nieznana płaska galeria: pusty tekst', T.nieznana === '', J(T.nieznana));
    t.check('treść mieszana z kilkoma płaskimi tagami', r.mieszana === 'Zdjęć: 3, w liście: 3, ID: ' + [o[6], o[5], o[4]].join(',') + '.', J(r.mieszana));

    t.section('podpowiedzi Bricksa');
    t.check('strona ustawień: grupa „EVK Galeria — wszystkie wiersze (Opcje)" z etykietą pola',
      H['{evk_galflatopt_gp_opcje.lista_gp.galeria_gp}'] === 'EVK Galeria — wszystkie wiersze (Opcje) | Opcje GP › Realizacje GP › Galeria GP'
      && H['{evk_galflatopt_gp_lista.galeria_l}'] === 'EVK Galeria — wszystkie wiersze (Opcje) | Lista GP › Galeria L',
      J([H['{evk_galflatopt_gp_opcje.lista_gp.galeria_gp}'], H['{evk_galflatopt_gp_lista.galeria_l}']]));
    t.check('warianty __ids i __count', !!H['{evk_galflatopt_gp_opcje.lista_gp.galeria_gp__ids}'] && !!H['{evk_galflatopt_gp_opcje.lista_gp.galeria_gp__count}'],
      J(Object.keys(H).filter((k) => k.includes('galeria_gp_'))));
    t.check('lista wpisu w grupie bez „(Opcje)"; grupa strony ustawień bez wariantu wpisowego',
      H['{evk_galflat_lista_w.gal_w}'] === 'EVK Galeria — wszystkie wiersze | Wpis GP › Lista W › Galeria W'
      && !Object.keys(H).some((k) => k.startsWith('{evk_galflat_lista_gp.') || k.startsWith('{evk_galflat_gp_lista.')),
      J(Object.keys(H).filter((k) => k.startsWith('{evk_galflat_'))));

    t.section('ściąga pola galerii w kreatorze');
    const S = r.sciaga || {};
    t.check('galeria w liście: „tylko w pętli" + płaski tag (także strony ustawień)', S.plaska && S.plaska_opcji && S.tylko_w_petli, J(S));
  } finally {
    sonda('sprzataj');
  }
};
