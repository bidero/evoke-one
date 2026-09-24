/**
 * Wysyłka maili na PRAWDZIWYM WordPressie: `wp_mail()`, PHPMailer, atrapa
 * serwera SMTP (tests/lib/smtp-atrapa.js).
 *
 * Środowisko: `tools/testowy-wp.sh`. Bez niego test zapala się na czerwono.
 * Sonda: tests/php/newsletter-wysylka.php.
 */

const { phpOutput } = require('./lib/harness');
const smtpAtrapa = require('./lib/smtp-atrapa');

module.exports = async function (t) {
  const smtp = await smtpAtrapa.start();
  const sonda = (scenariusz) => {
    const surowe = phpOutput('newsletter-wysylka.php', scenariusz + ' ' + smtp.port, { dopuscBlad: true });
    try { return JSON.parse(surowe); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  };
  const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  try {
    t.section('środowisko: testowy WordPress z prawdziwą bazą');
    smtp.ster({ odrzuc_rcpt: ['odrzucona@example.com'] });
    const l = sonda('log');
    t.check('testowy WordPress jest (tools/testowy-wp.sh)', !l.brak, l.brak || 'jest');
    if (l.brak) return;

    // ── Log SMTP ────────────────────────────────────────────────────────────
    /* Do 1.232.0 filtr `wp_mail` dokładał domknięcia do `wp_mail_succeeded`
       i `wp_mail_failed` przy KAŻDYM mailu i nigdy ich nie zdejmował: drugi
       mail w tym samym żądaniu zapisywał pierwszy jeszcze raz, a porażka
       trzeciego trafiała do logu także jako porażka dwóch poprzednich. */
    t.section('log SMTP: jeden wpis na mail, z jego własnym wynikiem');
    t.check('atrapa przyjęła dwa maile, trzeci odrzuciła', jak(l.wyniki, [true, true, false]), JSON.stringify(l.wyniki));
    t.check('trzy wpisy, każdy raz i z właściwym wynikiem (najnowszy pierwszy)',
      jak(l.log, ['Trzeci → odrzucona@example.com: błąd', 'Drugi → druga@example.com: ok', 'Pierwszy → pierwsza@example.com: ok']),
      JSON.stringify(l.log));
    t.check('wpis porażki niesie odpowiedź serwera', /brak takiej skrzynki|odrzucona@example\.com/.test(l.blad_trzeciego || ''),
      l.blad_trzeciego);

    // Nagłówki wiadomości z atrapy (rozwinięte linie ciągłe).
    const naglowki = (m) => m.dane.split(/\r?\n\r?\n/)[0].replace(/\r?\n[ \t]+/g, ' ');
    const naglowek = (m, nazwa) => ((naglowki(m).match(new RegExp('^' + nazwa + ': (.*)$', 'mi')) || [])[1] || '');

    // ── Kampania przez SMTP Evoke ───────────────────────────────────────────
    /* Od 1.233.0 newsletter wysyła przez wp_mail(). SMTP Evoke zostaje
       transportem domyślnym (phpmailer_init), a to, co dawał własny PHPMailer
       — nagłówki wypisu, wersja tekstowa, jedno połączenie na paczkę — ma
       zostać. */
    t.section('kampania przez wp_mail() i SMTP Evoke');
    smtp.wyczysc();
    smtp.ster({});
    const k = sonda('kampania');
    const kp = smtp.poczta();
    const kampanijne = kp.filter((m) => m.do[0] !== 'po@example.com');
    t.check('trzy maile wyszły, kolejka „sent", kampania zakończona',
      kampanijne.length === 3 && Object.values(k.kolejka || {}).every((w) => w.status === 'sent') && k.status_kampanii === 'done',
      JSON.stringify({ maile: kampanijne.length, status: k.status_kampanii }));
    t.check('przez SMTP Evoke: logowanie kontem z ustawień, nadawca z ustawień',
      kampanijne.every((m) => m.uzytkownik === 'atrapa' && naglowek(m, 'From') === 'Evoke Test <nadawca@example.com>'),
      kampanijne.map((m) => m.uzytkownik + ' ' + naglowek(m, 'From')).join(' | '));
    t.check('List-Unsubscribe z linkiem wypisu TEGO subskrybenta + one-click',
      kampanijne.length === 3 && kampanijne.every((m) => naglowek(m, 'List-Unsubscribe') === '<' + (k.unsub || {})[m.do[0]] + '>'
        && naglowek(m, 'List-Unsubscribe-Post') === 'List-Unsubscribe=One-Click'),
      kampanijne.map((m) => naglowek(m, 'List-Unsubscribe')).join(' | '));
    t.check('HTML z wersją tekstową (multipart/alternative), znaczniki podstawione',
      kampanijne.every((m) => /^multipart\/alternative/.test(naglowek(m, 'Content-Type')) && /Content-Type: text\/plain/i.test(m.dane)
        && naglowek(m, 'Subject') === 'Kampania Ola'),
      kampanijne.map((m) => naglowek(m, 'Content-Type').slice(0, 30) + ' / ' + naglowek(m, 'Subject')).join(' | '));
    t.check('bez nagłówka X-Mailer w mailach kampanii', kampanijne.every((m) => !/^X-Mailer:/mi.test(naglowki(m))),
      kampanijne.map((m) => naglowek(m, 'X-Mailer') || '—').join(' | '));
    t.check('cała paczka jednym połączeniem SMTP', new Set(kampanijne.map((m) => m.polaczenie)).size === 1,
      JSON.stringify(kampanijne.map((m) => m.polaczenie)));
    t.check('maile kampanii nie zalewają logu SMTP', jak(k.log_po_paczce, []), JSON.stringify(k.log_po_paczce));
    /* PHPMailer w WordPressie jest wspólny dla żądania — to, co newsletter
       ustawia na czas swojego maila, nie może wyciec do poczty innych wtyczek. */
    const po = kp.find((m) => m.do[0] === 'po@example.com');
    t.check('zwykły mail po paczce: własne połączenie i zwykłe nagłówki',
      !!po && !kampanijne.some((m) => m.polaczenie === po.polaczenie) && /^PHPMailer/.test(naglowek(po, 'X-Mailer'))
        && /^text\/plain/.test(naglowek(po, 'Content-Type')),
      po ? po.polaczenie + ' ' + naglowek(po, 'X-Mailer') + ' ' + naglowek(po, 'Content-Type') : 'brak maila');
    t.check('zwykły mail trafia do logu SMTP', jak(k.log_po_zwyklym, ['Po kampanii → po@example.com: ok']), JSON.stringify(k.log_po_zwyklym));

    // ── Limit dostawcy ──────────────────────────────────────────────────────
    t.section('limit u dostawcy: bezpiecznik paczki i odpowiedź serwera');
    smtp.wyczysc();
    smtp.ster({ limit: 1 });
    const lim = sonda('limit');
    const kol = lim.kolejka || {};
    t.check('pierwszy wysłany, trzy porażki pod rząd przerywają paczkę, piąty czeka na następną',
      jak(Object.values(kol).map((w) => w.status), ['sent', 'pending', 'pending', 'pending', 'pending']) && (kol['l5@example.com'] || {}).blad === '',
      JSON.stringify(Object.values(kol).map((w) => w.status)));
    t.check('błąd w kolejce niesie odpowiedź serwera (limit), nie samo „data not accepted"',
      ['l2@example.com', 'l3@example.com', 'l4@example.com'].every((a) => /Odpowiedź serwera: .*Limit wysyłki/.test((kol[a] || {}).blad || '')),
      (kol['l2@example.com'] || {}).blad);
    t.check('dziennik kampanii: przerwanie paczki i wydłużony odstęp',
      (lim.logi || []).some((j) => /Paczka przerwana po 3/.test(JSON.parse(j).msg || ''))
        && (lim.logi || []).some((j) => /Odstęp wydłużony ×2/.test(JSON.parse(j).msg || '')),
      JSON.stringify(lim.logi).slice(0, 200));
    const limPoczta = smtp.poczta();
    t.check('po porażce połączenie jest zamykane (następny mail — świeże)',
      limPoczta.length === 4 && new Set(limPoczta.slice(1).map((m) => m.polaczenie)).size === 3,
      JSON.stringify(limPoczta.map((m) => m.polaczenie + ':' + m.wynik)));

    // ── Inna wtyczka pocztowa ───────────────────────────────────────────────
    /* Do 1.232.0 przy wyłączonym SMTP Evoke kampania kończyła się błędem
       „SMTP Evoke ONE jest wyłączony", a mail z potwierdzeniem zapisu nie
       wychodził wcale — nawet gdy strona wysyłała pocztę inną wtyczką. */
    t.section('SMTP Evoke wyłączony, pocztę ustawia inna wtyczka');
    smtp.wyczysc();
    smtp.ster({});
    const inny = sonda('inny');
    const ip = smtp.poczta();
    const kampaniaInny = ip.find((m) => m.do[0] === 'i1@example.com');
    const potw = ip.find((m) => m.do[0] === 'potwierdz@example.com');
    t.check('panel rozpoznaje inną wtyczkę i nie ostrzega',
      inny.transport && inny.transport.rodzaj === 'inny' && jak(inny.transport.inne, ['evk-t-inny-smtp']) && inny.ostrzezenie === '',
      JSON.stringify(inny.transport));
    t.check('kampania wychodzi transportem innej wtyczki (bez konta SMTP Evoke)',
      !!kampaniaInny && kampaniaInny.uzytkownik === '' && (inny.kolejka['i1@example.com'] || {}).status === 'sent',
      kampaniaInny ? 'użytkownik: „' + kampaniaInny.uzytkownik + '"' : 'brak maila');
    t.check('mail z potwierdzeniem zapisu wychodzi, formularz mówi „sprawdź skrzynkę"',
      !!potw && inny.zapis && inny.zapis.ok === true && inny.zapis.stan === 'oczekuje', JSON.stringify(inny.zapis));
    t.check('nadawca: nazwa strony zamiast „WordPress"',
      !!potw && naglowek(potw, 'From') === inny.nazwa_strony + ' <wordpress@stara.test>', potw ? naglowek(potw, 'From') : 'brak');
    t.check('potwierdzenie zapisu (nie kampania) jest w logu SMTP',
      (inny.log || []).length === 1 && /potwierdz@example\.com: ok$/.test(inny.log[0]), JSON.stringify(inny.log));

    // ── Co mówi panel ───────────────────────────────────────────────────────
    t.section('panel: czym pójdzie poczta');
    const tr = sonda('transport');
    const [bn, bnTekst] = tr.bez_niczego || [];
    const [se, seTekst] = tr.sam_evoke || [];
    const [ei, eiTekst] = tr.evoke_i_inna || [];
    t.check('bez SMTP i bez innej wtyczki: ostrzeżenie o mail() serwera z odnośnikiem do SMTP',
      bn && bn.rodzaj === 'mail' && /data-evk-nl-transport="mail"/.test(bnTekst || '') && /sub=smtp/.test(bnTekst || ''), JSON.stringify(bn));
    t.check('sam SMTP Evoke: bez ostrzeżenia (własny phpmailer_init się nie liczy)',
      se && se.rodzaj === 'evoke' && jak(se.inne, []) && seTekst === '', JSON.stringify(se));
    t.check('SMTP Evoke i inna wtyczka naraz: ostrzeżenie z nazwą tamtej',
      ei && ei.rodzaj === 'evoke' && /data-evk-nl-transport="dwa"/.test(eiTekst || '') && /evk-t-inny-smtp/.test(eiTekst || ''), JSON.stringify(ei));

    // ── Odbicia ─────────────────────────────────────────────────────────────
    /* Lista wykluczeń (1.233.0): adres, którego skrzynki nie ma (5.1.x), nie
       dostaje już niczego i nie wraca importem. Odmowa przekazania (5.7.x) to
       NIE odbicie — tak odpowiada źle ustawiony serwer na KAŻDEGO odbiorcę,
       a uznanie jej za odbicie wyłączyłoby całą listę. */
    t.section('odbicia: skrzynka nie istnieje (5.1.1) a odmowa przekazania (5.7.1)');
    smtp.wyczysc();
    smtp.ster({ odrzuc_rcpt: ['b1@example.com', 'b2@example.com', 'b3@example.com'],
      odpowiedz_rcpt: { 'relay@example.com': '550 5.7.1 <relay@example.com>: Relaying denied' } });
    const od = sonda('odbicia');
    const ko = od.kolejka || {};
    t.check('odbite: „failed" bez ponawiania, z powodem w kolejce',
      ['b1@example.com', 'b2@example.com', 'b3@example.com'].every((a) => (ko[a] || {}).status === 'failed' && /^Adres odrzucony: .*brak takiej skrzynki/.test((ko[a] || {}).blad || '')),
      JSON.stringify(['b1@example.com', 'b2@example.com', 'b3@example.com'].map((a) => (ko[a] || {}).status)));
    t.check('trzy odbicia pod rząd nie włączają bezpiecznika — reszta paczki wychodzi',
      (ko['ok1@example.com'] || {}).status === 'sent' && (ko['ok2@example.com'] || {}).status === 'sent', JSON.stringify([ko['ok1@example.com'], ko['ok2@example.com']]));
    t.check('odbite adresy na liście wykluczeń (powód: odbity)',
      jak(od.wykluczenia, { 'b1@example.com': 'odbity', 'b2@example.com': 'odbity', 'b3@example.com': 'odbity' }), JSON.stringify(od.wykluczenia));
    t.check('odbity adres wypisany ze WSZYSTKICH list', jak(od.b1_na_listach, [0, 0]), JSON.stringify(od.b1_na_listach));
    t.check('odmowa przekazania (5.7.1): zwykły błąd do ponowienia, adres aktywny i poza wykluczeniami',
      (ko['relay@example.com'] || {}).status === 'pending' && od.relay_status === 1 && !('relay@example.com' in (od.wykluczenia || {})),
      JSON.stringify([ko['relay@example.com'], od.relay_status]));
  } finally {
    await smtp.zatrzymaj();
  }
};
