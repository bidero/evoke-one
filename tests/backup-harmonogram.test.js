/**
 * Backup — kopia nocna i powiadomienia (includes/backup/schedule.php) na
 * prawdziwym WordPressie z tools/testowy-wp.sh. Maile przechwycone,
 * nic nie wychodzi.
 *
 * Decyzje z 1.227.0, których pilnuje ten plik: godzina w strefie strony
 * (także przez zmianę czasu), nadrabianie spóźnionej kopii przy pierwszej
 * wizycie, e-mail WYŁĄCZNIE na wpisane adresy, komunikat w panelu znikający
 * po udanej kopii.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (scen) => JSON.parse(phpOutput('backup-harmonogram.php', scen));

module.exports = async function (t) {

  t.section('termin kopii nocnej');

  const tm = sonda('termin');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !tm.brak, tm.brak || 'jest');
  if (tm.brak) return;
  t.check('przed godziną: dziś; o godzinie i po: jutro',
    tm.przed_godzina === '2026-06-10 03:00' && tm.po_godzinie === '2026-06-11 03:00', JSON.stringify(tm));
  /* Zdarzenie cykliczne „daily" przesunęłoby się tu o godzinę: doba ze
     zmianą czasu ma 23 albo 25 godzin. */
  t.check('przez zmianę czasu (Warszawa, marzec i październik) wciąż 03:00 czasu strony',
    tm.przed_letnim === '2026-03-29 03:00' && tm.przed_zimowym === '2026-10-25 03:00');
  t.check('północ na przełomie roku', tm.polnoc === '2027-01-01 00:00', tm.polnoc);

  t.section('zdarzenie w WP-Cron nadąża za ustawieniami');

  const s = sonda('sync');
  t.check('włączony harmonogram: jedno zdarzenie o godzinie z ustawień, termin w argumencie',
    s.zaplanowane.length === 1 && s.godzina === '04:15' && s.zaplanowane[0][1][0] === s.plan.at, JSON.stringify(s.zaplanowane));
  t.check('ponowne dopasowanie bez zmian nie planuje drugi raz', s.bez_zmian === true);
  t.check('zmiana godziny: stare zdarzenie znika, nowe o nowej godzinie', JSON.stringify(s.po_zmianie) === '["05:30"]',
    JSON.stringify(s.po_zmianie));
  t.check('wyłączony harmonogram albo moduł: zdarzenia nie ma',
    !s.po_wylaczeniu[0].length && s.po_wylaczeniu[1] === false && !s.modul_wylaczony.length);
  t.check('zdarzenie usunięte z crona z zewnątrz wraca samo', s.odtworzone === 1);

  t.section('uruchomienie, spóźnienie, zajęty silnik');

  const u = sonda('uruchom');
  t.check('kopia nocna rusza jako „nocna", następny termin zaplanowany, bez powiadomień',
    u.zadanie === 'schedule' && u.nastepne.length === 1 && u.nastepne[0] && u.alert === false && !u.maile.length,
    JSON.stringify(u));

  const sp = sonda('spoznienie');
  t.check('spóźniona o ponad dobę: kopia i tak rusza (nadrabianie)', sp.zadanie === 'schedule');
  t.check('…a powiadomienie mówi, ile po terminie i co zrobić (cron systemowy)',
    sp.alert && /2 dni po terminie/.test(sp.alert.text) && /cron systemowy/.test(sp.alert.text)
      && sp.maile.length === 1 && sp.maile[0][0][0] === 'kopie@example.com', JSON.stringify(sp.alert && sp.alert.text));

  const z = sonda('zajety');
  t.check('trwa inna kopia: ponowienie za 15 min, bez powiadomienia',
    z.ponowienie.length === 1 && z.ponowienie_za_s >= 895 && z.ponowienie_za_s <= 905 && z.alert_po_ponowieniu === false,
    JSON.stringify(z.ponowienie));
  t.check('…po 12 h spóźnienia: powiadomienie, że kopii nie ma', z.alert_po_12h && /nie została zrobiona/.test(z.alert_po_12h.title)
    && z.maile === 1, JSON.stringify(z.alert_po_12h));

  const w = sonda('watchdog');
  t.check('WP-Cron nie działa wcale: po dobie od terminu powiadomienie, raz', w.godzine_po[0] === false && w.godzine_po[1] === 0
    && w.doba_po[0] === 'Kopia nocna nie ruszyła' && w.doba_po[1] === 1, JSON.stringify(w));

  t.section('powiadomienia o nieudanej kopii');

  const b = sonda('blad');
  t.check('adresy: po przecinku, średniku, spacji; złe odrzucone, bez powtórzeń',
    b.adresy === 'a@example.com, b@example.com', b.adresy);
  t.check('nieudana kopia: jeden mail na oba adresy, z powodem, i komunikat w panelu',
    b.z_mailem[0].length === 1 && JSON.stringify(b.z_mailem[0][0][0]) === '["a@example.com","b@example.com"]'
      && b.z_mailem[0][0][2] === true && /nocna.*Brak miejsca/.test(b.z_mailem[1]), JSON.stringify(b.z_mailem));
  t.check('udana kopia gasi komunikat', b.po_udanej === false);
  t.check('puste pole adresu = bez maili (bez zastępstwa adresem administratora); komunikat wyłączony = bez komunikatu',
    b.bez_ustawien[0] === 0 && b.bez_ustawien[1] === false, JSON.stringify(b.bez_ustawien));
  t.check('nieudane przywracanie nie wysyła powiadomień o kopii', b.przywracanie[0] === 0 && b.przywracanie[1] === false);

  const k = sonda('komunikat');
  t.check('komunikat w panelu: dla administratora, z krzyżykiem i linkiem do zakładki',
    k.jest && k.zamykany && k.link, JSON.stringify(k));
  t.check('treść komunikatu uciekana, subskrybent go nie widzi', k.escape && !k.dla_subskrybenta);
};
