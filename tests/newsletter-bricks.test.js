/**
 * Akcja „Newsletter (Evoke ONE)" w formularzu Bricksa (1.233.0) na PRAWDZIWYM
 * WordPressie. Sonda: tests/php/newsletter-bricks.php.
 *
 * BRICKSA TU NIE MA (CLAUDE.md). Sprawdzamy dwie rzeczy, które da się
 * sprawdzić bez niego:
 *   — kontrolki i grupa mają kształt z przykładu w Bricks Academy
 *     („Custom form actions", od Bricksa 1.12.2),
 *   — obsługa po wysłaniu robi to, co trzeba, z obiektem formularza, który ma
 *     wyłącznie udokumentowane metody (get_settings, get_fields, set_result).
 * Czy builder przyjmuje kontrolki i jak pokazuje komunikat — dowód ze strony.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const sonda = (scenariusz) => {
    const surowe = phpOutput('newsletter-bricks.php', scenariusz, { dopuscBlad: true });
    try { return JSON.parse(surowe); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  };
  const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  t.section('środowisko: testowy WordPress z prawdziwą bazą');
  const k = sonda('kontrolki');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !k.brak, k.brak || 'jest');
  if (k.brak) return;

  // ── Kontrolki w builderze ─────────────────────────────────────────────────
  t.section('builder: akcja i jej ustawienia (kształt z dokumentacji Bricksa)');
  t.check('akcja dopisana do „Actions", istniejące akcje zostają',
    jak(k.opcje_akcji, { email: 'Email', redirect: 'Redirect', 'evk-newsletter': 'Newsletter (Evoke ONE)' }), JSON.stringify(k.opcje_akcji));
  const kt = k.kontrolki || {};
  t.check('lista do wyboru: listy newslettera (id => nazwa)',
    kt.evkNlLista && kt.evkNlLista.type === 'select' && Object.keys(kt.evkNlLista.options || {}).includes(k.lista),
    JSON.stringify(kt.evkNlLista));
  t.check('pole e-mail i pole zgody: wybór z pól formularza (map_fields)',
    ['evkNlEmail', 'evkNlZgoda'].every((n) => kt[n] && kt[n].type === 'select' && kt[n].map_fields === true && jak(kt[n].options, [])),
    JSON.stringify([kt.evkNlEmail, kt.evkNlZgoda]));
  t.check('pola do znaczników: wielokrotny wybór z pól formularza',
    kt.evkNlPola && kt.evkNlPola.multiple === true && kt.evkNlPola.map_fields === true, JSON.stringify(kt.evkNlPola));
  /* Odwrócony przełącznik zamiast „potwierdzaj mailem" z domyślnym true: nie
     wiadomo, czy Bricks zapisuje wartość domyślną kontrolki w formularzu, do
     którego akcję dodano później — a brak ustawienia ma znaczyć double opt-in. */
  t.check('przełącznik „bez potwierdzenia" (brak ustawienia = double opt-in)',
    kt.evkNlBezPotwierdzenia && kt.evkNlBezPotwierdzenia.type === 'checkbox', JSON.stringify(kt.evkNlBezPotwierdzenia));
  t.check('wszystkie ustawienia w jednej grupie', Object.values(kt).every((x) => x.group === 'evkNewsletter'), Object.keys(kt).join(', '));
  t.check('grupa widoczna tylko przy wybranej akcji',
    jak((k.grupy || {}).evkNewsletter, { title: 'Newsletter (Evoke ONE)', required: ['actions', '=', 'evk-newsletter'] })
      && jak((k.grupy || {}).email, { title: 'Email' }),
    JSON.stringify(k.grupy));

  const st = sonda('stary');
  t.check('Bricks starszy niż 1.12.2 (bez własnych akcji): niczego nie dokładamy',
    jak(st.opcje_akcji, { email: 'Email', redirect: 'Redirect' }) && jak(st.kontrolki, []) && jak(st.grupy, { email: { title: 'Email' } }),
    JSON.stringify([st.opcje_akcji, st.kontrolki]));

  // ── Obsługa po wysłaniu ───────────────────────────────────────────────────
  const a = sonda('akcja');
  t.section('osobny formularz zapisu');
  const os = a.osobny || {};
  t.check('zapis czeka na potwierdzenie, mail z linkiem poszedł na adres z pola e-mail (pierwsze pole typu e-mail)',
    os.status === 2 && jak(os.maile, [{ do: 'ola.bricks@example.com', link: true }]) && jak(os.wyniki, []), JSON.stringify(os));
  const p = os.pola || {};
  t.check('wybrane pola jako znaczniki z etykiet: „Imię" → imie, „Nazwa firmy" → nazwa_firmy',
    p.imie === 'Ola' && p.nazwa_firmy === 'Firma Sp. z o.o.', JSON.stringify(p));
  t.check('pole z etykietą „_consent_ip" nie nadpisuje adresu z zapisu zgody',
    p.consent_ip === '6.6.6.6' && p._consent_ip !== '6.6.6.6', JSON.stringify({ pole: p.consent_ip, zgoda: p._consent_ip }));
  t.check('zapis zgody wie, skąd przyszedł zapis (formularz i wpis)',
    p._consent_source === 'formularz Bricksa abcdef (wpis 12)' && !!p._consent_at, JSON.stringify(p._consent_source));

  t.section('formularz kontaktowy z „Zapisz mnie do newslettera"');
  const kb = a.kontakt_bez_zgody || {};
  t.check('checkbox niezaznaczony: brak zapisu, brak maila i brak błędu (wiadomość idzie dalej)',
    kb.status === null && jak(kb.maile, []) && jak(kb.wyniki, []), JSON.stringify(kb));
  const kz = a.kontakt_ze_zgoda || {};
  t.check('checkbox zaznaczony: zapis z potwierdzeniem mailem',
    kz.status === 2 && kz.maile && kz.maile.length === 1 && jak(kz.wyniki, []), JSON.stringify({ status: kz.status, maile: kz.maile }));
  t.check('treść zgody to tekst z ekranu (etykieta opcji, nie jej wartość)',
    (kz.pola || {})._consent_text === 'Newsletter: Chcę dostawać newsletter', JSON.stringify((kz.pola || {})._consent_text));
  t.check('pole wskazane z przedrostkiem „form-field-" też działa',
    (a.przedrostek || {}).status === 2, JSON.stringify(a.przedrostek));

  t.section('bez potwierdzenia i błędy');
  const bp = a.bez_potwierdzenia || {};
  t.check('„bez potwierdzenia": aktywny od razu, bez maila, z czasem potwierdzenia',
    bp.status === 1 && jak(bp.maile, []) && !!(bp.pola || {})._confirmed_at, JSON.stringify(bp));
  const blad = (x, tekst) => x && x.status === null && x.wyniki && x.wyniki.length === 1
    && x.wyniki[0].type === 'danger' && x.wyniki[0].action === 'evk-newsletter' && x.wyniki[0].message === tekst;
  t.check('brak adresu e-mail: błąd w formularzu', blad(a.brak_adresu, 'Podaj adres e-mail, aby zapisać się do newslettera.'),
    JSON.stringify(a.brak_adresu));
  t.check('nieistniejąca lista: błąd w formularzu', blad(a.zla_lista, 'Nieprawidłowa lista.'), JSON.stringify(a.zla_lista));
  t.check('moduł Newsletter wyłączony: błąd w formularzu, bez zapisu', blad(a.wylaczony, 'Zapisy do newslettera są wyłączone.'),
    JSON.stringify(a.wylaczony));
};
