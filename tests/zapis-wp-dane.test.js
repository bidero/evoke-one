/**
 * Dane i prywatność (wydanie 1.232.0) na PRAWDZIWYM WordPressie.
 *
 * Środowisko: `tools/testowy-wp.sh`. Bez niego test zapala się na czerwono.
 * Sonda: tests/php/zapis-wp-dane.php. Odinstalowanie ma osobny plik
 * (zapis-wp-odinstalowanie) na trzeciej, jednorazowej stronie.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const sonda = (scenariusz) => {
    const surowe = phpOutput('zapis-wp-dane.php', scenariusz, { dopuscBlad: true });
    try { return JSON.parse(surowe); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  };

  t.section('środowisko: testowy WordPress z prawdziwą bazą');
  const k = sonda('konflikty');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !k.brak, k.brak || 'jest');
  if (k.brak) return;

  // ── Kolizje ─────────────────────────────────────────────────────────────
  /* Do 1.231.x każda aktywna wtyczka o pliku „system…" wyłączała CAŁE
     Evoke ONE — wzorzec bez śladu, skąd się wziął. */
  t.section('kolizje: tylko znane wtyczki wyłączają Evoke ONE');
  t.check('bez innych wtyczek — bez kolizji', k.nic === false, JSON.stringify(k.nic));
  t.check('wtyczka „system…" (system-dashboard, systempay) — bez kolizji',
    k.system_dashboard === false && k.systempay === false,
    JSON.stringify({ system_dashboard: k.system_dashboard, systempay: k.systempay }));
  t.check('znane kolizje zostają: stare Tłumaczenia, Parallax, WP Maintenance Mode',
    k.stare_tlumaczenia === true && k.parallax === true && k.wp_maintenance === true,
    JSON.stringify({ tlumaczenia: k.stare_tlumaczenia, parallax: k.parallax, wp_maintenance: k.wp_maintenance }));

  // ── OG ──────────────────────────────────────────────────────────────────
  t.section('obrazek OG: przegenerowanie tylko z prawem do tego wpisu');
  const o = sonda('og-prawo');
  t.check('autor NIE przegeneruje obrazka cudzego wpisu',
    o.autor_cudzy && o.autor_cudzy.sukces === false && o.autor_cudzy.dane === 'Brak uprawnień.', JSON.stringify(o.autor_cudzy));
  t.check('autor przegeneruje obrazek własnego wpisu', o.autor_wlasny && o.autor_wlasny.sukces === true, JSON.stringify(o.autor_wlasny));
  t.check('administrator — każdy wpis', o.admin_cudzy && o.admin_cudzy.sukces === true, JSON.stringify(o.admin_cudzy));

  // ── Eksport ustawień ────────────────────────────────────────────────────
  /* Do 1.231.x każdy eksport niósł hasło SMTP i hasło obejścia konserwacji,
     a paczka wędruje mailem i leży w Pobranych. */
  t.section('eksport ustawień: hasła tylko na wyraźne życzenie');
  const fs = require('fs');
  const os = require('os');
  const path = require('path');
  const eksport = (wariant) => {
    const s = phpOutput('zapis-wp-dane.php', 'eksport' + (wariant ? ' ' + wariant : ''), { dopuscBlad: true });
    try { return { json: JSON.parse(s), surowe: s }; } catch (e) { return { json: null, surowe: s }; }
  };
  const bez = eksport('');
  const zH = eksport('z');
  const bj = bez.json || {};
  t.check('bez „Dołącz hasła": w pliku nie ma hasła SMTP ani hasła obejścia',
    !!bez.json && !!bj.evk_smtp && !('password' in bj.evk_smtp) && !('maintenance_bypass_password' in bj)
      && !bez.surowe.includes('test-haslo'), bez.surowe.slice(0, 200));
  t.check('reszta ustawień zostaje w pliku (host SMTP, godziny obejścia)',
    !!bj.evk_smtp && bj.evk_smtp.host === 'smtp.example.com' && Number(bj.maintenance_bypass_hours) === 24, JSON.stringify(bj.evk_smtp));
  const zj = zH.json || {};
  t.check('z „Dołącz hasła": oba hasła w pliku',
    !!zj.evk_smtp && zj.evk_smtp.password === 'test-haslo-smtp' && zj.maintenance_bypass_password === 'test-haslo-obejscia',
    JSON.stringify({ smtp: zj.evk_smtp && zj.evk_smtp.password, obejscie: zj.maintenance_bypass_password }));

  const plik = (nazwa, tresc) => {
    const p = path.join(os.tmpdir(), 'evk-t-' + process.pid + '-' + nazwa + '.json');
    fs.writeFileSync(p, tresc);
    return p;
  };
  const pBez = plik('bez', bez.surowe);
  const pZ = plik('z', zH.surowe);
  const i0 = sonda('import-hasla ' + pBez);
  const i1 = sonda('import-hasla ' + pZ);
  fs.unlinkSync(pBez);
  fs.unlinkSync(pZ);
  t.check('import pliku bez haseł: hasła ze strony zostają, reszta się wczytuje',
    i0.sukces === true && i0.haslo === 'haslo-na-stronie' && i0.obejscie === 'obejscie-na-stronie' && i0.host === 'smtp.example.com',
    JSON.stringify(i0));
  t.check('import pliku z hasłami: hasła z pliku', i1.sukces === true && i1.haslo === 'test-haslo-smtp' && i1.obejscie === 'test-haslo-obejscia',
    JSON.stringify(i1));

  const zakladka = phpOutput('tab.php', 'tools-io', { dopuscBlad: true });
  const pole = (zakladka.match(/<input[^>]*id="evo-export-hasla"[^>]*>/) || [])[0] || '';
  t.check('w zakładce Import/Eksport pole „Dołącz hasła", domyślnie odznaczone',
    !!pole && !/checked/.test(pole) && /Dołącz hasła/.test(zakladka), pole || 'brak pola');
  const adminJs = fs.readFileSync(path.join(__dirname, '..', 'assets/admin/admin.js'), 'utf8');
  t.check('eksport w panelu wysyła stan pola (hasla=1 tylko przy zaznaczeniu)',
    /name:\s*'hasla',\s*value:\s*\$\('#evo-export-hasla'\)\.is\(':checked'\)\s*\?\s*'1'\s*:\s*''/.test(adminJs), 'wzorzec w admin.js');

  // ── RODO ────────────────────────────────────────────────────────────────
  /* Do 1.231.x wtyczka nie zgłaszała WordPressowi żadnych swoich danych:
     Narzędzia → Eksport / Usuń dane osobowe pomijały newsletter, log SMTP
     i blokady logowania. */
  const r = sonda('rodo');
  const ek = r.eksport || {};
  t.section('RODO: eksport danych osoby');
  const nl = Array.isArray(ek.newsletter) ? ek.newsletter : [];
  const zapisy = nl.filter((w) => w.grupa === 'evk-newsletter');
  t.check('newsletter: oba zapisy, z zapisem zgody (czas, IP, treść) i potwierdzeniem',
    zapisy.length === 2 && zapisy.some((w) => w['Zgoda — adres IP'] === '5.6.7.8' && w['Zgoda — treść'] && w['Potwierdzono']),
    JSON.stringify(zapisy));
  t.check('newsletter: wysyłka (wysłano, otwarto) i kliknięcie z adresem',
    nl.some((w) => w.grupa === 'evk-newsletter-wysylki' && w['Kampania'] === 'Kampania RODO' && w['Otwarto'])
      && nl.some((w) => w.grupa === 'evk-newsletter-zdarzenia' && w['Adres'] === 'https://example.com/oferta-rodo'),
    JSON.stringify(nl.filter((w) => w.grupa !== 'evk-newsletter')));
  // Niezgłoszony eksporter to napis zamiast listy — sprawdzenie ma zgasnąć, nie wywrócić pliku.
  const lista = (x) => (Array.isArray(x) ? x : []);
  t.check('log SMTP: moje wiadomości (także we wspólnej, adres wielkimi literami), bez cudzej',
    JSON.stringify(lista(ek.smtp).map((w) => w['Temat'])) === JSON.stringify(['Potwierdź zapis', 'Do dwojga']), JSON.stringify(ek.smtp));
  t.check('ochrona logowania: blokady moim loginem i moim adresem, bez cudzej',
    JSON.stringify(lista(ek.logowanie).map((w) => w['Adres IP'])) === JSON.stringify(['10.0.0.1', '10.0.0.2']), JSON.stringify(ek.logowanie));

  t.section('RODO: usuwanie danych osoby');
  const po = r.po || {};
  const us = r.usuniecie || {};
  t.check('każdy z trzech usuwaczy zgłasza usunięcie',
    ['newsletter', 'smtp', 'logowanie'].every((k) => us[k] && us[k].items_removed === true), JSON.stringify(us));
  t.check('newsletter: moje zapisy, wysyłki i zdarzenia usunięte',
    po.moje_zapisy === 0 && po.moje_wysylki === 0 && po.moje_zdarzenia === 0, JSON.stringify(po));
  t.check('newsletter: dane innej osoby nietknięte', po.jej_zapis === true && po.jej_wysylki === 1 && po.jej_zdarzenia === 1,
    JSON.stringify({ zapis: po.jej_zapis, wysylki: po.jej_wysylki, zdarzenia: po.jej_zdarzenia }));
  t.check('log SMTP: moja wiadomość znika, wspólna zostaje bez mnie (pozostali w pierwotnej postaci), cudza bez zmian',
    JSON.stringify(po.smtp) === JSON.stringify(['Do dwojga → Inna <inna-rodo@example.com>', 'Tylko do niej → inna-rodo@example.com']),
    JSON.stringify(po.smtp));
  t.check('ochrona logowania: moje blokady i próby z tych IP usunięte, cudze zostają',
    JSON.stringify(po.blokady) === JSON.stringify(['10.0.0.3']) && JSON.stringify(po.proby) === JSON.stringify(['10.0.0.3']),
    JSON.stringify({ blokady: po.blokady, proby: po.proby }));

  t.section('RODO: skrót adresu blokuje import, samodzielny zapis go zdejmuje');
  t.check('po usunięciu mój adres jest na liście blokady — jako skrót, bez adresu',
    po.skrot_moj === true && po.skrot_jej === false && po.adres_w_opcji === false,
    JSON.stringify({ moj: po.skrot_moj, jej: po.skrot_jej, adres_w_opcji: po.adres_w_opcji }));
  t.check('import pomija mój adres (jak wypisany), nowy dodaje',
    r.import && r.import.unsubscribed === 1 && r.import.added === 1 && r.import_moj_zapis === 0, JSON.stringify(r.import));
  t.check('sam formularz (bez potwierdzenia) blokady nie zdejmuje, potwierdzenie — tak',
    r.po_formularzu_zablokowany === true && r.po_potwierdzeniu_zablokowany === false,
    JSON.stringify({ formularz: r.po_formularzu_zablokowany, potwierdzenie: r.po_potwierdzeniu_zablokowany }));

  t.section('RODO: tekst do polityki prywatności');
  const pol = r.polityka || {};
  t.check('opisuje działające moduły: newsletter (ze skrótem po usunięciu), logi 404',
    pol.newsletter === true && pol.skrot === true && pol.logi404 === true, JSON.stringify(pol));
  t.check('nie opisuje modułu, który nie działa (newsletter wyłączony)', r.polityka_bez_newslettera === true,
    JSON.stringify(r.polityka_bez_newslettera));
};
