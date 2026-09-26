/**
 * White Label: etykieta własnej pozycji paska admina (1.238.0).
 *
 * Do 1.237.0 zapisana pozycja miała w formularzu DWA pola o tej samej nazwie:
 * tekstowe (to, które się edytuje) i za nim ukryte ze starą etykietą. PHP
 * z dwóch pól o jednej nazwie bierze ostatnie, więc zmiana etykiety przepadała
 * przy zapisie, a wyczyszczenie jej (sposób na usunięcie pozycji) nie usuwało
 * niczego.
 *
 * Droga jak na żywo: prawdziwa zakładka (tests/php/tab.php) w przeglądarce,
 * edycja pól, formularz zserializowany przez FormData, rozbiór parse_str()
 * z semantyką $_POST i prawdziwy sanityzator z register_setting()
 * (tests/php/admin-whitelabel.php).
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const zapisane = { enabled: 1, bar_nodes_extra: { 'moj-wezel': 'Stara etykieta', 'drugi-wezel': 'Do usunięcia' } };
  const head = 'window.__tab = ' + JSON.stringify(phpOutput('tab.php', 'whitelabel "" "" '
    + JSON.stringify(JSON.stringify({ evk_white_label: zapisane })))) + ';';
  const p = await t.open('admin-tabs.html', { viewport: { width: 1400, height: 900 }, head, settle: 60 });

  t.section('własne pozycje paska: edycja i usuwanie przez zapis formularza');
  const w = await p.evaluate(() => {
    const form = document.getElementById('evk-wl-form');
    const pole = (id) => form.querySelector('input[type=text][name="evk_white_label[bar_nodes_extra][' + id + ']"]');
    const ile = (id) => form.querySelectorAll('[name="evk_white_label[bar_nodes_extra][' + id + ']"]').length;
    const przed = { moj: ile('moj-wezel'), drugi: ile('drugi-wezel'), polaTekstowe: !!pole('moj-wezel') && !!pole('drugi-wezel') };
    if (!przed.polaTekstowe) return { przed };
    pole('moj-wezel').value = 'Nowa etykieta';
    pole('drugi-wezel').value = '';
    return { przed, cialo: new URLSearchParams(new FormData(form)).toString() };
  });
  t.check('formularz ma pole tekstowe każdej zapisanej pozycji', w.przed.polaTekstowe, JSON.stringify(w.przed));
  if (!w.cialo) return;
  t.check('każda pozycja ma w formularzu jedno pole (do 1.237.0: dwa, tekstowe i ukryte)',
    w.przed.moj === 1 && w.przed.drugi === 1, JSON.stringify(w.przed));

  const z = JSON.parse(phpOutput('admin-whitelabel.php', JSON.stringify(JSON.stringify(w.cialo)) + ' '
    + JSON.stringify(JSON.stringify(zapisane))));
  t.check('sonda zapisu działa', !z.brak && !!z.z_formularza, z.brak || JSON.stringify(z));
  if (z.brak || !z.zapisane) return;
  t.check('zmieniona etykieta dochodzi do zapisu', z.zapisane['moj-wezel'] === 'Nowa etykieta', JSON.stringify(z));
  t.check('wyczyszczona etykieta usuwa pozycję', !('drugi-wezel' in z.zapisane), JSON.stringify(z));
};
