/**
 * Ekran startowy panelu — Control Center.
 *
 * Powstał z paczki przygotowanej przez innego agenta i przyszedł BEZ testów.
 * To nie jest drobiazg: pulpit czyta kilkanaście opcji po nazwie i wypisuje
 * z nich liczby, a licznik sięgający po nieistniejącą opcję **kłamie po cichu**
 * — pokazuje „0 aktywnych" i nikt się nie dowie, że pyta o zły klucz.
 * Sprawdzenia niżej są w większości o tym.
 *
 * Znacznik bierzemy z PRAWDZIWEJ `evoke_one_render_settings()`, a nie z kopii
 * w fixturze; zasiew konfiguracji idzie z wiersza poleceń, więc porównujemy
 * liczbę na ekranie z tym, co naprawdę siedzi w opcjach.
 */

const fs   = require('fs');
const path = require('path');
const { phpOutput } = require('./lib/harness');

/** Panel wyrenderowany przez PHP dla podanego zasiewu opcji. */
const panel = (zasiew, tab) =>
  phpOutput('panel-start.php',
    JSON.stringify(JSON.stringify(zasiew || {})) + (tab ? ' ' + tab : ''));

module.exports = async function (t) {

  // ── Liczniki modułów ───────────────────────────────────────────────────
  t.section('liczby na pulpicie odpowiadają opcjom w bazie');

  const pusty = panel({});
  t.check('bez włączonych modułów licznik pokazuje zero',
    /0 aktywnych z 9/.test(pusty),
    (pusty.match(/\d+ aktywnych z \d+/) || ['brak'])[0]);

  const trzy = panel({ evk_animator: 1, evk_parallax: 1, evk_darkmode: 1 });
  t.check('trzy włączone moduły to trzy na kaflu',
    /3 aktywnych z 9/.test(trzy),
    (trzy.match(/\d+ aktywnych z \d+/) || ['brak'])[0]);

  /* Każdy moduł osobno, bo licznik zbiorczy przeszedłby także wtedy, gdyby
     jedna nazwa opcji była błędna, a inna liczyła się podwójnie. */
  const modulyFrontendu = ['evk_animator', 'evk_parallax', 'evk_darkmode', 'evk_cursor',
    'evk_lenis', 'evk_bgshift', 'evk_fonts', 'evk_theme_color', 'evk_a11y'];
  const zle = modulyFrontendu.filter((opcja) => !/1 aktywnych z 9/.test(panel({ [opcja]: 1 })));
  t.check('każda z dziewięciu nazw opcji jest trafiona', !zle.length,
    zle.length ? 'nie liczą się: ' + zle.join(', ') : '9 z 9');

  /* Nazwy z listy muszą istnieć w kodzie wtyczki. Sam licznik przechodziłby
     też wtedy, gdyby panel i ten test uzgodniły wspólną literówkę. */
  const zrodlaWtyczki = (function zbierz(kat, akc) {
    for (const wpis of fs.readdirSync(kat, { withFileTypes: true })) {
      const p = path.join(kat, wpis.name);
      if (wpis.isDirectory()) zbierz(p, akc);
      else if (wpis.name.endsWith('.php')) akc.push(fs.readFileSync(p, 'utf8'));
    }
    return akc;
  })(path.join(__dirname, '..', 'includes'), []).join('\n');

  const nieznane = modulyFrontendu.filter(
    (opcja) => !new RegExp("register_setting\\([^)]*'" + opcja + "'|'" + opcja + "'").test(zrodlaWtyczki));
  t.check('i każda występuje w kodzie modułów', !nieznane.length,
    nieznane.join(', ') || 'wszystkie znane');

  // ── Wynik gotowości ────────────────────────────────────────────────────
  t.section('wynik liczy tylko to, co da się nie zdać');

  const wynik = (html) => {
    const m = html.match(/evo-health-score"><strong>(\d+)<\/strong>/);
    return m ? Number(m[1]) : null;
  };

  /* Do 1.137.0 w mianowniku stały `defined('ABSPATH')` i `PHP >= 7.4`. Obie są
     prawdziwe zawsze, gdy ten kod się wykonuje, więc pusta instalacja
     pokazywała 38/100 mimo że nie było skonfigurowane nic. */
  t.check('pusta konfiguracja to zero, nie „trochę"', wynik(pusty) === 0,
    wynik(pusty) + '/100');

  const polowa = panel({ ssl: 1, evk_smtp: 1, evk_schema: 1 });
  t.check('trzy z sześciu kontrolek to 50', wynik(polowa) === 50, wynik(polowa) + '/100');

  const komplet = panel({
    ssl: 1, evk_smtp: 1, evk_schema: 1,
    evk_cleanup: { disable_xmlrpc: 1 },
    evk_security: { limit_login_enabled: 1 },
    tl_sitemap_settings: 1,
  });
  t.check('komplet kontrolek to sto', wynik(komplet) === 100, wynik(komplet) + '/100');
  t.check('i wtedy panel mówi, że jest dobrze',
    /Wszystko działa dobrze/.test(komplet), 'komunikat nagłówka');

  /* Środowisko jest do zobaczenia, nie do oceniania — ma być na pasku, ale
     poza wynikiem. Bez tego sprawdzenia „pusta konfiguracja to zero" dałoby
     się spełnić także wycinając te kontrolki z ekranu. */
  t.check('wersja PHP i Bricks nadal widoczne, ale poza wynikiem',
    /is-info[^>]*>.*?PHP /s.test(pusty) && /is-info[^>]*>.*?Bricks/s.test(pusty),
    'oznaczone jako informacja');

  // ── Kierowanie do miejsc ───────────────────────────────────────────────
  t.section('linki prowadzą tam, gdzie naprawdę coś jest');

  /* Strukturę bierzemy od samej wtyczki (`--mapa`), a nie z listy przepisanej
     tutaj: kopia rozjechałaby się przy pierwszym dołożonym module i test
     przestałby cokolwiek pilnować. */
  const mapa = JSON.parse(phpOutput('panel-start.php', '--mapa'));
  const zakladki = Object.keys(mapa.zakladki);
  t.check('zakładek jest osiem', zakladki.length === 8, zakladki.join(', '));

  const podzakladki = {};
  for (const [tabKey, ekrany] of Object.entries(mapa.ekrany)) podzakladki[tabKey] = Object.keys(ekrany);
  const ekranowRazem = Object.values(podzakladki).reduce((s, e) => s + e.length, 0);
  t.check('ekranów w środku jest trzydzieści jeden', ekranowRazem === 31, ekranowRazem + '');

  /* Nazwy wycinamy DO OGRANICZNIKA (`&` albo koniec adresu), a nie klasą
     dozwolonych znaków. Wzorzec `([a-z_]+)` wygląda rozsądnie i jest tu
     błędny: przy adresie `tab=newsletterXX` dopasowuje samo `newsletter`,
     resztę połyka i sprawdzenie przechodzi na zielono. Złapane mutacją. */
  const adresy = [...pusty.matchAll(/href="[^"]*?[?&](?:amp;)?tab=([^&"]+)(?:&(?:amp;)?sub=([^&"]+))?[^"]*"/g)]
    .map((m) => ({ tab: decodeURIComponent(m[1]), sub: m[2] ? decodeURIComponent(m[2]) : '' }));
  t.check('jest co sprawdzać — ekran linkuje w kilkanaście miejsc',
    adresy.length >= 15, adresy.length + ' odsyłaczy');

  const zlyTab = adresy.filter((a) => !zakladki.includes(a.tab));
  t.check('każdy odsyłacz celuje w istniejącą zakładkę', !zlyTab.length,
    zlyTab.map((a) => a.tab).join(', ') || 'komplet');

  const zlySub = adresy.filter((a) => a.sub && podzakladki[a.tab] && !podzakladki[a.tab].includes(a.sub));
  t.check('i w istniejącą podzakładkę', !zlySub.length,
    zlySub.map((a) => a.tab + '/' + a.sub).join(', ') || 'komplet');

  /* Kontrolka, która nie przeszła, musi dać się naprawić jednym kliknięciem —
     inaczej lista „wymaga uwagi" jest samym narzekaniem. */
  const doNaprawy = (pusty.match(/evo-health-issue(?! is-good)/g) || []).length;
  const zLinkiem  = (pusty.match(/evo-health-issue(?! is-good)[\s\S]*?Skonfiguruj/g) || []).length;
  t.check('każda niezdana kontrolka z linkiem ma dokąd prowadzić',
    doNaprawy > 0 && zLinkiem >= doNaprawy - 1,
    zLinkiem + ' z ' + doNaprawy + ' pozycji z odsyłaczem');

  // ── Nawigacja ──────────────────────────────────────────────────────────
  t.section('sidebar zna komplet zakładek i wie, gdzie stoisz');

  const naSeo = panel({}, 'strona');
  const wPasku = [...naSeo.matchAll(/class="evo-sidebar-link[^"]*"[^>]*?>[\s\S]*?<span>([^<]+)<\/span>/g)]
    .map((m) => m[1]);
  /* Liczba brana z MAPY, nie wpisana z ręki. Do 1.139.6 stało tu `=== 9`
     („osiem zakładek plus pomoc") i przy usuwaniu martwego odsyłacza „Pomoc"
     sprawdzenie zapłonęło z powodu, który nie był usterką. Zakładka dołożona
     do `evoke_one_zakladki()` ma się pojawić w pasku — i to jest to, co tu
     naprawdę mierzymy. */
  t.check('pasek pokazuje każdą zakładkę z mapy', wPasku.length === zakladki.length,
    wPasku.length + ' z ' + zakladki.length + ': ' + wPasku.join(', '));
  const brakWPasku = Object.values(mapa.zakladki).map((z) => z.label).filter((l) => !wPasku.includes(l));
  t.check('i żadna nie wypadła po drodze', !brakWPasku.length,
    brakWPasku.join(', ') || 'komplet');
  t.check('bieżąca zakładka jest zaznaczona',
    /class="evo-sidebar-link is-active"[^>]*>[\s\S]*?<span>SEO<\/span>/.test(naSeo),
    'SEO');
  const zaznaczonePierwszego = (naSeo.match(/class="evo-sidebar-link is-active"/g) || []).length;
  t.check('a pozostałe zakładki nie', zaznaczonePierwszego === 1,
    zaznaczonePierwszego + ' zaznaczonych');

  // ── Kompletność wyszukiwarki ───────────────────────────────────────────
  t.section('wyszukiwarka zna każdy ekran panelu');

  /* Do 1.138.0 paleta miała czternaście pozycji wpisanych z ręki obok listy
     zakładek, przy panelu mającym trzydzieści kilka ekranów. Taka lista nie ma
     jak nadążyć: dołożenie modułu nie przypomina o dopisaniu go tutaj i nic
     tego nie zauważa. Teraz jedno i drugie płynie z `evoke_one_ekrany()`,
     więc sprawdzenie sprowadza się do porównania liczb. */
  const wpisyPalety = [...pusty.matchAll(/data-evo-search-item>([\s\S]*?)<\/a>/g)].map((m) => m[1]);
  const bezEkranow = zakladki.filter((z) => z !== 'dashboard' && !podzakladki[z]).length;
  t.check('wpisów palety tyle, ile ekranów panelu',
    wpisyPalety.length === ekranowRazem + bezEkranow,
    wpisyPalety.length + ' wpisów wobec ' + (ekranowRazem + bezEkranow) + ' ekranów');

  /* Kontrola, że to naprawdę TE ekrany, a nie tylko tyle samo sztuk. */
  const brakujace = [];
  for (const [tabKey, ekrany] of Object.entries(mapa.ekrany)) {
    for (const [sub, ekran] of Object.entries(ekrany)) {
      if (!wpisyPalety.some((w) => w.includes(ekran.label))) brakujace.push(tabKey + '/' + sub);
    }
  }
  t.check('i każdy z nich po nazwie', !brakujace.length,
    brakujace.join(', ') || 'komplet');

  /* Etykiety są polskie, a nazwy, pod którymi ludzie znają te rzeczy — nie.
     Bez słów pomocniczych „dark" nie znajduje „Trybu ciemnego", a „gsap"
     Animatora, więc wyszukiwarka działa tylko dla tego, kto już wie, jak coś
     nazwaliśmy po polsku. */
  for (const [fraza, etykieta] of [['dark', 'Tryb ciemny'], ['gsap', 'Animator'],
                                   ['301', 'Przekierowania 301'], ['wcag', 'Dostępność']]) {
    const trafienie = wpisyPalety.find((w) => w.toLowerCase().includes(fraza));
    t.check('„' + fraza + '" trafia w „' + etykieta + '"',
      !!trafienie && trafienie.includes(etykieta),
      trafienie ? trafienie.replace(/<[^>]*>/g, ' ').trim().slice(0, 46) : 'brak trafienia');
  }

  /* KAŻDY KLUCZ MUSI MIEĆ CO WYŚWIETLIĆ.
     Sama zgodność nazw nic nie znaczy, jeśli lista i pasek podzakładek czytają
     tę samą mapę — takie sprawdzenie potwierdza samo siebie. Rozstrzyga dopiero
     to, czy klucz prowadzi do PLIKU, który się wyrenderuje: zakładki dobierają
     ekran albo po nazwie pliku (`tab-{klucz}.php`, `security-{klucz}.php`),
     albo gałęzią `if ($sub === '{klucz}')` u siebie. Klucz, którego nie łapie
     żadna z tych dróg, daje pusty ekran — i tak właśnie zachował się Animator
     przy mutacji, którą to sprawdzenie dołożyło. */
  const rendererZakladki = {
    wydajnosc: 'tab-wydajnosc.php', strona: 'tab-seo.php',
    bezpieczenstwo: 'tab-bezpieczenstwo.php', narzedzia: 'tab-narzedzia.php',
    admin_panel: 'tab-admin.php',
  };
  const nieosiagalne = [];
  for (const [tabKey, ekrany] of Object.entries(mapa.ekrany)) {
    const src = fs.readFileSync(
      path.join(__dirname, '..', 'includes', 'admin', rendererZakladki[tabKey]), 'utf8');
    for (const sub of Object.keys(ekrany)) {
      const wGalezi = src.includes("'" + sub + "'");
      const poPliku = ['tab-', 'security-', 'tools-'].some((prefiks) =>
        fs.existsSync(path.join(__dirname, '..', 'includes', 'admin', prefiks + sub + '.php')));
      if (!wGalezi && !poPliku) nieosiagalne.push(tabKey + '/' + sub);
    }
  }
  t.check('każdy ekran z mapy ma co wyświetlić', !nieosiagalne.length,
    nieosiagalne.join(', ') || ekranowRazem + ' ekranów osiągalnych');

  // ── Drugi poziom paska ─────────────────────────────────────────────────
  t.section('pasek boczny prowadzi wprost do modułu');

  /* ZGŁOSZONE Z UŻYCIA: „dodaj animatora do panelu z lewej". */
  const podlinki = (html) => [...html.matchAll(/evo-sidebar-sublink[^>]*>([^<]+)</g)].map((m) => m[1]);

  const naFrontendzie = panel({}, 'wydajnosc');
  t.check('rozwinięta sekcja pokazuje swoje ekrany',
    podlinki(naFrontendzie).length === podzakladki.wydajnosc.length,
    podlinki(naFrontendzie).length + ' z ' + podzakladki.wydajnosc.length);
  t.check('Animator jest wśród nich', podlinki(naFrontendzie).includes('Animator'),
    podlinki(naFrontendzie).slice(0, 5).join(', ') + '…');

  /* Rozwinięta ma być TYLKO bieżąca sekcja — komplet 31 pozycji naraz jest
     równie nieczytelny co dwupoziomowy pasek u góry, od którego uciekaliśmy. */
  t.check('a pozostałe sekcje zostają zwinięte',
    podlinki(naFrontendzie).length === podzakladki.wydajnosc.length,
    'na pulpicie: ' + podlinki(pusty).length + ' podlinków');
  t.check('na pulpicie nie ma żadnych', podlinki(pusty).length === 0,
    podlinki(pusty).length + '');

  // ── Kolor przewodni ────────────────────────────────────────────────────
  t.section('kolor przewodni jedzie z tokenu');

  const css = fs.readFileSync(path.join(__dirname, '..', 'assets', 'admin', 'admin.css'), 'utf8');

  /* Granica idzie po KLAMRZE bloku tokenów, nie po komentarzu niżej. Dzieliłem
     to najpierw po nagłówku „Admin Panel Styles" i sprawdzenie chodziło ślepe:
     blok Control Center wszedł do pliku PRZED tym nagłówkiem, więc cała nowa
     warstwa nawigacji wypadała z przeglądu. Wyszło mutacją — wpisany wprost
     `#2563eb` w regule paska świecił na zielono. */
  const otwarcie = css.indexOf('.wrap {');
  const zamkniecie = css.indexOf('\n}', otwarcie);
  t.check('blok tokenów daje się wyodrębnić', otwarcie >= 0 && zamkniecie > otwarcie,
    'od ' + otwarcie + ' do ' + zamkniecie);

  const blokTokenow = css.slice(otwarcie, zamkniecie);
  const reszta = css.slice(zamkniecie)
    .split('\n').filter((l) => !/^\s*(\*|\/\*)/.test(l)).join('\n');
  t.check('a poza nim jest co przeglądać', reszta.length > css.length / 2,
    Math.round(reszta.length / 1024) + ' KiB reguł');

  t.check('token akcentu niesie markę', /--evo-accent:\s*#6e00a5/.test(blokTokenow),
    (blokTokenow.match(/--evo-accent:\s*#[0-9a-f]{6}/i) || ['brak'])[0]);

  /* Sedno: barwa ma być JEDNA i w jednym miejscu. Wartość wpisana wprost
     w regule nie zareaguje na White Label ani na kolejną zmianę marki —
     a przez cztery wydania (1.119.0–1.122.0) zdejmowaliśmy dokładnie ten dług. */
  const wprost = reszta.split('\n')
    .map((l, i) => ({ l, i }))
    .filter(({ l }) => /#6e00a5|#55007f|#2563eb|#1d4ed8|#eff6ff|#93c5fd/i.test(l));
  t.check('i nigdzie nie jest wpisana wprost w regule', !wprost.length,
    wprost.map(({ l }) => l.trim().slice(0, 44)).join(' | ') || 'czysto');

  // ── Pasek zapisu ───────────────────────────────────────────────────────
  t.section('pasek zapisu jest stopką, nie pudełkiem w pudełku');

  /* ZGŁOSZONE Z UŻYCIA: „ten pasek średnio wygląda z tą zaokrągloną ramką,
     stykającą się". Składały się na to DWIE reguły `.evo-save-bar` — jedna
     rozlewała pasek na całą szerokość karty, druga („jak w Fields") dokładała
     ramkę dookoła i zaokrąglenie wszystkich rogów. Pasek jest przy tym
     przyklejony, więc przy krótkim formularzu stawał w połowie białej karty
     i te rogi wisiały w powietrzu. */
  /* Mierzymy na DWÓCH ekranach, bo pasek stoi w panelu w jednych miejscach
     i wewnątrz `.evo-box` w innych — piętnaście z dwudziestu jeden w pudełku.
     Sprawdzenie zrobione tylko na jednym z nich przechodziłoby także dla
     reguły dobranej pod wyściółkę tego jednego pojemnika; dokładnie tak
     powstała usterka: `-28px` pasowało do karty i wychodziło 8 px poza
     pudełko. */
  for (const [ekran, gdzie] of [['sec-login', 'w karcie'], ['tools-smtp', 'w pudełku']]) {
    const zPaskiem = await t.open('snippety-lista.html', {
      viewport: { width: 1280, height: 900 },
      head: 'window.__panel = ' + JSON.stringify(panel({}, 'narzedzia')) + ';'
          + 'window.__tresc = ' + JSON.stringify(phpOutput('tab.php', ekran)) + ';',
    });

    const pasek = await zPaskiem.evaluate(() => {
      const bar = document.querySelector('.evo-save-bar');
      if (!bar) return null;
      const rodzic = bar.parentElement;
      const s = getComputedStyle(bar), sr = getComputedStyle(rodzic);
      const b = bar.getBoundingClientRect(), r = rodzic.getBoundingClientRect();
      const msg = bar.querySelector('.evo-save-msg');
      // Krawędzie TREŚCI pojemnika — pasek ma się w nich zmieścić co do piksela.
      const lewaTresci  = r.left  + parseFloat(sr.borderLeftWidth)  + parseFloat(sr.paddingLeft);
      const prawaTresci = r.right - parseFloat(sr.borderRightWidth) - parseFloat(sr.paddingRight);
      return {
        rodzic: rodzic.className || rodzic.tagName,
        promien: s.borderTopLeftRadius + ' ' + s.borderBottomLeftRadius,
        ramki: [s.borderTopWidth, s.borderRightWidth, s.borderBottomWidth, s.borderLeftWidth]
          .map((w) => Math.round(parseFloat(w))),
        tlo:  s.backgroundColor,
        blur: s.backdropFilter,
        odchylenie: Math.round(Math.max(Math.abs(b.left - lewaTresci), Math.abs(b.right - prawaTresci))),
        /* Komunikat jest ukryty do chwili zapisu (`display: none`), więc nie ma
           czego mierzyć w pikselach — `auto` jest tu całą treścią reguły. */
        komunikatZPrawej: msg ? getComputedStyle(msg).marginLeft : null,
      };
    });

    t.check('pasek ' + gdzie + ': kanty proste', pasek && pasek.promien === '0px 0px',
      pasek ? pasek.promien : 'brak paska');
    t.check('pasek ' + gdzie + ': jedna kreska u góry, nie ramka dookoła',
      pasek && pasek.ramki[0] === 1 && pasek.ramki.slice(1).every((w) => w === 0),
      pasek ? pasek.ramki.join('/') : '—');
    t.check('pasek ' + gdzie + ': tło kryjące, bez rozmycia',
      pasek && !/rgba\([^)]*,\s*0?\.\d+\)/.test(pasek.tlo) && pasek.blur === 'none',
      pasek ? pasek.tlo + ', ' + pasek.blur : '—');
    t.check('pasek ' + gdzie + ': mieści się w treści pojemnika co do piksela',
      pasek && pasek.odchylenie <= 1,
      pasek ? pasek.odchylenie + ' px od krawędzi „' + pasek.rodzic + '"' : '—');
    if (ekran === 'sec-login') {
      t.check('a potwierdzenie zapisu odpychane jest na prawy koniec',
        pasek && pasek.komunikatZPrawej === 'auto',
        pasek ? 'margin-left: ' + pasek.komunikatZPrawej : '—');
    }
    await zPaskiem.close();
  }

  /* ZGŁOSZONE Z UŻYCIA: „w 404 jest zupełnie inaczej". Zapis był jedynym
     w panelu oznaczonym jako `secondary`, więc wyglądał na akcję poboczną,
     a pola ustawień wisiały bez pudełka — stąd „brakuje delikatnej ramki". */
  const w404 = phpOutput('tab.php', 'tools-logs404');
  t.check('Logi 404: zapis jest akcją główną', /button-primary[^>]*>\s*Zapisz ustawienia/.test(w404)
    || /Zapisz ustawienia[\s\S]{0,80}button-primary/.test(w404)
    || /class="[^"]*button-primary[^"]*"[^>]*value="Zapisz ustawienia"/.test(w404),
    (w404.match(/[^>]*Zapisz ustawienia[^<]*/) || ['brak przycisku'])[0].trim().slice(0, 60));
  t.check('Logi 404: ustawienia stoją w pudełku', w404.includes('evo-box'), 'evo-box');
  t.check('Logi 404: kosz z ikony, nie z emoji',
    w404.includes('dashicons-trash') && !w404.includes('🗑'), 'dashicons-trash');

  /* „Zapisywanie wygląda źle w SMTP" — w pasku zapisu siedziało pole adresu
     i przycisk wysyłki testu, czyli akcja, która niczego nie zapisuje. */
  const smtp = phpOutput('tab.php', 'tools-smtp');
  const pasekSmtp = (smtp.match(/<div class="evo-save-bar[\s\S]*?<\/div>/) || [''])[0];
  t.check('SMTP: w pasku zapisu został sam zapis',
    !/input|Wyślij/.test(pasekSmtp), pasekSmtp.replace(/\s+/g, ' ').slice(0, 70));

  // ── Tabele panelu ──────────────────────────────────────────────────────
  t.section('tabele mają nasz wygląd, nie rdzenia');

  /* ZGŁOSZONE Z UŻYCIA: „wszędzie mamy zaokrąglenia, a w SEO kanty w ramce są
     ostre", potem to samo o Role Managerze. Obie tabele nosiły klasy rdzenia
     (`wp-list-table widefat fixed striped`), a rdzeń rysuje ramkę kwadratową.
     Ramki rdzenia w pomiarze nie widać — arkuszy wp-admin nie ma
     w repozytorium — więc sprawdzamy DWIE rzeczy: znacznik (czy klasy odeszły)
     i piksele (czy nasz komponent naprawdę tam dociera). */
  /* Przegląd idzie po CAŁYM katalogu, a nie po liście nazw plików: lista
     rozjechałaby się przy pierwszej nowej tabeli, a to właśnie „gdzieś jeszcze
     została stara klasa" jest tu usterką. Do 1.139.9 takich tabel było
     siedem — Limit logowań, SMTP, Logi 404, Przekierowania 301 i trzy ekrany
     newslettera. */
  const zTabelami = (function zbierz(kat, akc) {
    for (const wpis of fs.readdirSync(kat, { withFileTypes: true })) {
      const pl = path.join(kat, wpis.name);
      if (wpis.isDirectory()) zbierz(pl, akc);
      else if (wpis.name.endsWith('.php')) akc.push([pl, fs.readFileSync(pl, 'utf8')]);
    }
    return akc;
  })(path.join(__dirname, '..', 'includes'), []);

  const zRdzeniem = zTabelami
    .filter(([, tresc]) => /<table[^>]*\b(wp-list-table|widefat)\b/.test(tresc))
    .map(([pl]) => path.relative(path.join(__dirname, '..'), pl));
  t.check('żadna tabela w panelu nie nosi klas rdzenia', !zRdzeniem.length,
    zRdzeniem.join(', ') || zTabelami.length + ' plików przejrzanych, czysto');

  /* Ta sama zależność siedziała na POLACH: `class="widefat"` daje im pełną
     szerokość z arkusza rdzenia. Nie widać jej po ramce, ale to ten sam dług. */
  const zPolami = zTabelami
    .filter(([, tresc]) => /<(input|select|textarea)[^>]*class="[^"]*\bwidefat\b/.test(tresc))
    .map(([pl]) => path.relative(path.join(__dirname, '..'), pl));
  t.check('ani pola formularzy', !zPolami.length, zPolami.join(', ') || 'czysto');

  /* Selektory w skryptach chodzą za klasami. Przy zamianie klasy tabeli
     `$('table.wp-list-table')` przestaje cokolwiek znajdować — i nie widać
     tego w wyglądzie, tylko w tym, że „Wyczyść logi" zostawia tabelę na
     ekranie. Złapane przy 1.139.9 w `tools-logs404.php`. */
  const martweSelektory = zTabelami
    .filter(([, tresc]) => /\$\(['"][^'"]*(wp-list-table|\.widefat)/.test(tresc))
    .map(([pl]) => path.relative(path.join(__dirname, '..'), pl));
  t.check('i żaden skrypt nie szuka tabeli po klasie rdzenia', !martweSelektory.length,
    martweSelektory.join(', ') || 'czysto');

  for (const [ekran, nazwa] of [['seo-meta', 'SEO'], ['adm-roles', 'Role Manager'],
                                ['sec-login', 'Limit logowań'], ['tools-redirect', 'Przekierowania'],
                                ['nl-reports', 'Raporty newslettera']]) {
    const str = await t.open('snippety-lista.html', {
      viewport: { width: 1400, height: 1000 },
      head: 'window.__panel = ' + JSON.stringify(panel({}, 'narzedzia')) + ';'
          + 'window.__tresc = ' + JSON.stringify(phpOutput('tab.php', ekran)) + ';',
    });
    const tbl = await str.evaluate(() => {
      const el = document.querySelector('.evo-tbl');
      if (!el) return null;
      const s = getComputedStyle(el);
      const th = el.querySelector('th');
      return {
        promien: s.borderTopLeftRadius,
        ramka:   Math.round(parseFloat(s.borderTopWidth)),
        naglowekTlo: th ? getComputedStyle(th).backgroundColor : null,
      };
    });
    t.check(nazwa + ' ma zaokrągloną ramkę', tbl && tbl.promien === '8px' && tbl.ramka === 1,
      tbl ? tbl.promien + ', ramka ' + tbl.ramka + ' px' : 'brak tabeli .evo-tbl');
    await str.close();
  }

  // ── Role Manager ───────────────────────────────────────────────────────
  t.section('Role Manager wypełnia szerokość');

  /* ZGŁOSZONE Z UŻYCIA: „Role manager nie zajmuje całej dostępnej szerokości".
     `.evo-grid-21` to siatka 2fr/1fr, a dostawała JEDNO dziecko — prawa trzecia
     część ekranu zostawała pusta, a boks „Dostęp do Evoke ONE" lądował pod
     siatką zamiast w niej. Mierzymy w przeglądarce, bo to jest pytanie
     o piksele, nie o znaczniki. */
  const role = await t.open('panel-start.html', {
    viewport: { width: 1400, height: 1000 },
    head: 'window.__panel = ' + JSON.stringify(
      phpOutput('tab.php', 'adm-roles ' + JSON.stringify(JSON.stringify(
        { role_action: 'edit', edit_role: 'editor' })))) + ';',
  });

  const siatka = await role.evaluate(() => window.__szerokosci('.evo-grid-21'));
  t.check('siatka ma dwie kolumny, nie jedną', siatka && siatka.dzieci.length === 2,
    siatka ? siatka.dzieci.length + ' dzieci' : 'brak siatki');
  t.check('druga kolumna sięga prawej krawędzi',
    siatka && Math.abs(siatka.dzieci[siatka.dzieci.length - 1].prawa - siatka.prawa) <= 2,
    siatka ? 'kolumna do ' + siatka.dzieci[siatka.dzieci.length - 1].prawa
             + ' px, siatka do ' + siatka.prawa + ' px' : '—');
  t.check('i ma realną szerokość, a nie zero',
    siatka && siatka.dzieci[siatka.dzieci.length - 1].szer > 200,
    siatka ? siatka.dzieci[siatka.dzieci.length - 1].szer + ' px' : '—');
  t.check('bez błędów JS na ekranie ról', !role.errors.length,
    role.errors.join(' | ') || 'brak');
  await role.close();

  // ── Przeglądarka ───────────────────────────────────────────────────────
  t.section('paleta i wersja wąska');

  const otworz = (szer) => t.open('panel-start.html', {
    viewport: { width: szer, height: 900 },
    head: 'window.__panel = ' + JSON.stringify(panel({ evk_animator: 1 })) + ';',
  });

  const p = await otworz(1400);

  const przed = await p.evaluate(() => window.__paleta());
  t.check('paleta startuje zamknięta', !przed.otwarta && !przed.widoczna,
    JSON.stringify(przed));

  await p.evaluate(() => window.__skrot(null));
  const poSkrocie = await p.evaluate(() => window.__paleta());
  t.check('Ctrl+K poza polem otwiera paletę', poSkrocie.otwarta, JSON.stringify(poSkrocie));

  await p.evaluate(() => window.__zamknij());
  const poEsc = await p.evaluate(() => window.__paleta());
  t.check('Escape ją zamyka', !poEsc.otwarta, JSON.stringify(poEsc));

  /* ZGŁOSZENIEM BYŁOBY: „w edytorze skryptów Ctrl+K przestał działać".
     Nasłuch wisi na dokumencie, więc bez warunku po `event.target` skrót
     zjada kombinację także w trakcie pisania. */
  await p.evaluate(() => window.__skrot('#evo-command-input'));
  const wPolu = await p.evaluate(() => window.__paleta());
  t.check('a w polu tekstowym skrót nie przechwytuje pisania', !wPolu.otwarta,
    JSON.stringify(wPolu));

  const szeroki = await p.evaluate(() => window.__uklad());
  t.check('na desktopie sidebar stoi, paska mobilnego nie ma',
    szeroki.pasekWidoczny && !szeroki.mobilnyWidoczny, JSON.stringify(szeroki));
  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  const w = await otworz(390);
  const waski = await w.evaluate(() => window.__uklad());
  t.check('przy 390 px sidebar schodzi z drogi',
    !waski.pasekWidoczny && waski.mobilnyWidoczny, JSON.stringify(waski));
  t.check('a treść dostaje całą szerokość', waski.szerokoscTresci >= 340,
    waski.szerokoscTresci + ' px');

  await w.evaluate(() => window.__burger());
  const poBurgerze = await w.evaluate(() => window.__uklad());
  t.check('burger wysuwa nawigację', poBurgerze.pasekWidoczny,
    JSON.stringify(poBurgerze));
  t.check('bez błędów JS w wersji wąskiej', !w.errors.length, w.errors.join(' | ') || 'brak');
  await w.close();
};
