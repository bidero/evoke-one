/**
 * Atrapa serwera SMTP — osobny proces (tests/lib/smtp-atrapa.js go stawia).
 *
 *   node smtp-atrapa-serwer.js <port> <katalog>
 *
 * Osobny proces, bo sondy PHP idą przez `execSync` — serwer w procesie testu
 * stałby wtedy razem z nim i PHPMailer czekałby na powitanie do upływu czasu.
 *
 * Każda przyjęta (albo odrzucona) wiadomość to jedna linia JSON w
 * `<katalog>/poczta.jsonl`: numer połączenia, koperta, surowa treść, wynik.
 * Zachowanie steruje `<katalog>/ster.json`, czytany przy każdej komendzie:
 *
 *   odrzuc_rcpt: ['adres', …]   RCPT TO dla tych adresów → 550 5.1.1 (brak skrzynki)
 *   odpowiedz_rcpt: {adres: '…'} RCPT TO dla adresu → dokładnie ta odpowiedź
 *   limit:       N              po N przyjętych wiadomościach DATA → 451
 *                               (licząc od ostatniego zapisu ster.json)
 *
 * Mówi tylko tyle protokołu, ile potrzebuje PHPMailer: EHLO z AUTH, AUTH PLAIN
 * i LOGIN, MAIL, RCPT, DATA, RSET, NOOP, QUIT. Bez STARTTLS — z szyfrowaniem
 * „none" PHPMailer go nie żąda.
 */

const net = require('net');
const fs = require('fs');
const path = require('path');

const port = Number(process.argv[2]);
const katalog = process.argv[3];
const plikPoczty = path.join(katalog, 'poczta.jsonl');
const plikSteru = path.join(katalog, 'ster.json');

let polaczen = 0;
let przyjetych = 0;
let wersjaSteru = -1;

/* Nowy ster.json zeruje licznik przyjętych — „limit" liczy się od chwili,
   w której test go ustawił, a nie od startu atrapy. */
const ster = () => {
  try {
    const czas = fs.statSync(plikSteru).mtimeMs;
    if (czas !== wersjaSteru) { wersjaSteru = czas; przyjetych = 0; }
    return JSON.parse(fs.readFileSync(plikSteru, 'utf8'));
  } catch (e) { return {}; }
};
const zapisz = (o) => fs.appendFileSync(plikPoczty, JSON.stringify(o) + '\n');

net.createServer((gniazdo) => {
  const nr = ++polaczen;
  let bufor = '';
  let tryb = 'komendy';       // komendy | dane | auth-plain | auth-login-user | auth-login-haslo
  let koperta = { od: '', do: [] };
  let uzytkownik = '';
  const pisz = (t) => gniazdo.write(t + '\r\n');

  pisz('220 atrapa ESMTP');

  const komenda = (linia) => {
    const duze = linia.toUpperCase();
    if (tryb === 'auth-plain') {
      uzytkownik = (Buffer.from(linia, 'base64').toString().split('\0')[1] || '');
      tryb = 'komendy'; return pisz('235 2.7.0 OK');
    }
    if (tryb === 'auth-login-user') { uzytkownik = Buffer.from(linia, 'base64').toString(); tryb = 'auth-login-haslo'; return pisz('334 UGFzc3dvcmQ6'); }
    if (tryb === 'auth-login-haslo') { tryb = 'komendy'; return pisz('235 2.7.0 OK'); }

    if (duze.startsWith('EHLO')) return pisz('250-atrapa\r\n250-AUTH PLAIN LOGIN\r\n250-8BITMIME\r\n250 SIZE 10485760');
    if (duze.startsWith('HELO')) return pisz('250 atrapa');
    if (duze.startsWith('AUTH PLAIN')) {
      const reszta = linia.slice(10).trim();
      if (!reszta) { tryb = 'auth-plain'; return pisz('334 '); }
      uzytkownik = (Buffer.from(reszta, 'base64').toString().split('\0')[1] || '');
      return pisz('235 2.7.0 OK');
    }
    if (duze.startsWith('AUTH LOGIN')) { tryb = 'auth-login-user'; return pisz('334 VXNlcm5hbWU6'); }
    if (duze.startsWith('MAIL FROM:')) { koperta = { od: linia.slice(10).trim().replace(/^<|>.*$/g, ''), do: [] }; return pisz('250 2.1.0 OK'); }
    if (duze.startsWith('RCPT TO:')) {
      const adres = linia.slice(8).trim().replace(/^<|>.*$/g, '');
      const s = ster();
      if ((s.odpowiedz_rcpt || {})[adres]) return pisz(s.odpowiedz_rcpt[adres]);
      if ((s.odrzuc_rcpt || []).includes(adres)) return pisz('550 5.1.1 <' + adres + '>: brak takiej skrzynki');
      koperta.do.push(adres); return pisz('250 2.1.5 OK');
    }
    if (duze === 'DATA') { tryb = 'dane'; return pisz('354 dalej, zakończ kropką'); }
    if (duze === 'RSET') { koperta = { od: '', do: [] }; return pisz('250 2.0.0 OK'); }
    if (duze === 'NOOP') return pisz('250 2.0.0 OK');
    if (duze === 'QUIT') { pisz('221 2.0.0 do widzenia'); return gniazdo.end(); }
    return pisz('502 5.5.2 nieznana komenda');
  };

  gniazdo.on('data', (kawalek) => {
    bufor += kawalek.toString('binary');
    for (;;) {
      if (tryb === 'dane') {
        const koniec = bufor.indexOf('\r\n.\r\n');
        if (koniec === -1) return;
        const dane = Buffer.from(bufor.slice(0, koniec), 'binary').toString('utf8').replace(/^\.\./gm, '.');
        bufor = bufor.slice(koniec + 5);
        tryb = 'komendy';
        const limit = ster().limit;
        const odrzuc = typeof limit === 'number' && przyjetych >= limit;
        if (!odrzuc) przyjetych++;
        zapisz({ polaczenie: nr, uzytkownik, od: koperta.od, do: koperta.do, dane, wynik: odrzuc ? 451 : 250 });
        pisz(odrzuc ? '451 4.7.1 Limit wysyłki na godzinę przekroczony' : '250 2.0.0 przyjęto');
        continue;
      }
      const nl = bufor.indexOf('\r\n');
      if (nl === -1) return;
      const linia = Buffer.from(bufor.slice(0, nl), 'binary').toString('utf8');
      bufor = bufor.slice(nl + 2);
      komenda(linia);
    }
  });
  gniazdo.on('error', () => {});
}).listen(port, '127.0.0.1', () => {
  fs.writeFileSync(path.join(katalog, 'gotowy'), String(process.pid));
});
