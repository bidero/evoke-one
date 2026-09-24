/**
 * Adres IP odwiedzającego (1.233.0) na PRAWDZIWYM WordPressie.
 *
 * Do 1.232.0 limit logowań, newsletter i logi 404 czytały `REMOTE_ADDR`
 * wprost. Na stronie za Cloudflare to adres WĘZŁA Cloudflare: limit logowań
 * blokował naraz wszystkich, którzy przez ten węzeł wchodzą (także
 * administratora), a zapis zgody na newsletter notował adres Cloudflare jako
 * adres osoby.
 *
 * Środowisko: `tools/testowy-wp.sh`. Bez niego test zapala się na czerwono.
 * Sonda: tests/php/ip-klienta.php.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const surowe = phpOutput('ip-klienta.php', '', { dopuscBlad: true });
  let s;
  try { s = JSON.parse(surowe); } catch (e) { s = { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  t.section('środowisko: testowy WordPress z prawdziwą bazą');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !s.brak, s.brak || 'jest');
  if (s.brak) return;

  t.section('sieci: IPv4, IPv6, maski, śmieci w ustawieniach');
  t.check('dopasowanie do sieci — granice masek, rodziny adresów, adres pojedynczy',
    jak(s.w_sieci, [true, false, true, true, false, false, true, false, true, true, false, false]), JSON.stringify(s.w_sieci));
  // „0.0.0.0/0" w polu zaufanych = ufaj każdemu nagłówkowi, czyli brak ochrony.
  t.check('lista zaufanych: bez sieci /0, bez śmieci i powtórzeń',
    jak(s.lista_sieci, ['10.0.0.5', '192.168.0.0/16', '2001:db8::/33']), JSON.stringify(s.lista_sieci));
  t.check('adres IPv4 zapisany jako IPv6 (::ffff:) wraca jako IPv4', s.mapowany_v4 === '1.2.3.4', s.mapowany_v4);

  t.section('tryby pośrednika');
  const tr = s.tryby || {};
  t.check('bez ustawienia: za Cloudflare widać węzeł (tak było do 1.232.0)', tr.brak_za_cf === '172.68.1.10', tr.brak_za_cf);
  t.check('Cloudflare: adres z CF-Connecting-IP, gdy połączenie przyszło z sieci Cloudflare', tr.cf === '5.6.7.8', tr.cf);
  t.check('Cloudflare: nagłówek spoza sieci Cloudflare ignorowany (podróbka)', tr.cf_podrobiony === '9.9.9.9', tr.cf_podrobiony);
  t.check('Cloudflare: śmieć w nagłówku — adres połączenia', tr.cf_smiec === '172.68.1.10', tr.cf_smiec);
  t.check('Cloudflare: węzeł podany jako ::ffff: też rozpoznany', tr.cf_wezel_jako_v6 === '5.6.7.8', tr.cf_wezel_jako_v6);
  t.check('Cloudflare + lokalny pośrednik: tylko gdy dopisany do zaufanych',
    tr.cf_lokalny_bez_zaufania === '10.0.0.5' && tr.cf_lokalny_zaufany === '5.6.7.8',
    JSON.stringify([tr.cf_lokalny_bez_zaufania, tr.cf_lokalny_zaufany]));
  t.check('inny pośrednik: X-Forwarded-For od prawej, lewa część (od odwiedzającego) bez znaczenia',
    tr.inne === '5.6.7.8' && tr.inne_lancuch === '5.6.7.8', JSON.stringify([tr.inne, tr.inne_lancuch]));
  t.check('inny pośrednik: nagłówek od niezaufanego adresu ignorowany', tr.inne_obcy === '9.9.9.9', tr.inne_obcy);
  t.check('inny pośrednik: bez nagłówka albo ze śmieciem z prawej — adres połączenia',
    tr.inne_bez_naglowka === '10.0.0.5' && tr.inne_smiec_z_prawej === '10.0.0.5' && tr.inne_smiec_w_srodku === '5.6.7.8',
    JSON.stringify([tr.inne_bez_naglowka, tr.inne_smiec_z_prawej, tr.inne_smiec_w_srodku]));
  t.check('nieznany tryb w opcji = bez pośrednika', tr.nieznany_tryb === '172.68.1.10', tr.nieznany_tryb);

  t.section('limit logowań za Cloudflare');
  const lg = s.logowanie || {};
  t.check('bez ustawienia: próby jednej osoby blokują też drugą (wspólny węzeł)',
    lg.brak && lg.brak.a === true && lg.brak.b === true && jak(lg.brak.klucze, ['172.68.1.10']), JSON.stringify(lg.brak));
  t.check('z ustawieniem „Cloudflare": zablokowana tylko osoba, która zgadywała hasło',
    lg.cloudflare && lg.cloudflare.a === true && lg.cloudflare.b === false && jak(lg.cloudflare.klucze, ['5.6.7.8']),
    JSON.stringify(lg.cloudflare));
  t.check('podrabiany nagłówek (zmieniany co próbę) nie omija limitu',
    lg.podrobiony && lg.podrobiony.zablokowany === true && jak(lg.podrobiony.klucze, ['9.9.9.9']), JSON.stringify(lg.podrobiony));

  t.section('newsletter i logi 404');
  t.check('zgoda na newsletter notuje adres osoby, nie węzła', s.newsletter_ip === '5.6.7.8', s.newsletter_ip);
  t.check('bez adresu (wiersz poleceń) — 0.0.0.0 jak dotąd', s.newsletter_bez_adresu === '0.0.0.0', s.newsletter_bez_adresu);
  t.check('log 404 notuje adres osoby', s.hak_404 === 1 && s.log_404_ip === '5.6.7.8', JSON.stringify([s.hak_404, s.log_404_ip]));

  t.section('zapis ustawień z panelu');
  /* `register_setting()` przepuszcza każdy zapis `evk_security` przez
     evk_security_sanitize() — pole, którego tam nie ma, zginęłoby po cichu. */
  const z = s.zapis || {};
  t.check('tryb i zaufane adresy zapisują się razem z limitem logowań',
    z.sukces === true && z.tryb === 'cloudflare' && z.zaufane === '10.0.0.5\n192.168.0.0/16' && z.limit === 7, JSON.stringify(z));
  t.check('nieznany tryb z żądania zapisuje się jako „brak"',
    s.zapis_zly_tryb && s.zapis_zly_tryb.tryb === 'brak', JSON.stringify(s.zapis_zly_tryb));
  t.check('pełna sanityzacja opcji (import ustawień) nie gubi pól pośrednika',
    jak(s.sanityzacja_pelna, { tryb: 'inne', zaufane: '10.1.1.1' }), JSON.stringify(s.sanityzacja_pelna));

  t.section('ekran „Limit logowań": pole pośrednika i ostrzeżenie');
  const e = s.ekran || {};
  t.check('pola z etykietami (select trybu, lista zaufanych adresów)', e.pole_trybu === true && e.pole_adresow === true,
    JSON.stringify([e.pole_trybu, e.pole_adresow]));
  t.check('żądanie z sieci Cloudflare przy trybie „brak" — ostrzeżenie', e.ostrzezenie_cf === true, JSON.stringify(e.ostrzezenie_cf));
  t.check('ostrzeżenie znika po wybraniu „Cloudflare" i przy połączeniu wprost',
    e.ostrzezenie_po === false && e.ostrzezenie_wprost === false, JSON.stringify([e.ostrzezenie_po, e.ostrzezenie_wprost]));
  t.check('ekran pokazuje, jaki adres wtyczka przyjęłaby przy „Cloudflare"', e.adres_przy_cf === true, JSON.stringify(e.adres_przy_cf));
  t.check('zapisany tryb jest wybrany w polu', e.wybrany_cf === true, JSON.stringify(e.wybrany_cf));
};
