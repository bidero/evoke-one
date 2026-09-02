/**
 * Trzy miejsca, w których wtyczka dawała się przewrócić albo obejść.
 *
 * Naprawiane w 1.132.0, wszystkie z audytu:
 *
 * * **Import CSV** przyjmował plik bez żadnego sprawdzenia poza „czy `tmp_name`
 *   niepuste". Zawartość szła prosto do `file_get_contents()`, stamtąd
 *   `preg_split()` robił tablicę linii, a import kolejną tablicę adresów —
 *   każdy krok to osobna kopia w pamięci.
 * * **Limiter logowania** trzymał próby i blokady w opcjach indeksowanych
 *   adresem IP, nieprzycinanych i AUTOLOADOWANYCH, czyli wczytywanych przy
 *   każdym żądaniu do serwisu. Kto ma pulę IPv6 — a ma ją każdy VPS —
 *   rozdmuchiwał je do megabajtów i zamieniał ochronę przed zgadywaniem haseł
 *   w dźwignię do przewrócenia strony.
 * * **Typ wpisu snippetów** miał `capability_type => 'post'`, więc prawo do
 *   edycji kodu wykonywanego przez `eval()` mapowało się na `edit_others_posts`
 *   — czyli na Redaktora.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('odpornosc.php'));

  // ── Import CSV ────────────────────────────────────────────────────────
  t.section('import CSV sprawdza, co dostał');

  const c = php.csv;

  // Każdy powód odmowy ma własny komunikat. Wspólne „Nieprawidłowy plik" nie
  // tylko nie mówi użytkownikowi, co poprawić — sprawia też, że test nie
  // odróżnia jednej przyczyny od drugiej i przechodzi, cokolwiek sprawdzono.
  t.check('brak pliku', c.brak_pliku === 'Brak pliku.', c.brak_pliku);
  t.check('limit serwera rozpoznany osobno',
    c.blad_rozmiaru === 'Plik jest za duży dla tego serwera.', c.blad_rozmiaru);
  t.check('przerwane wysyłanie odrzucone',
    c.blad_czesciowy === 'Nie udało się wgrać pliku.', c.blad_czesciowy);
  t.check('pusty plik odrzucony', c.pusty === 'Plik jest pusty.', c.pusty);
  t.check('plik ponad limit odrzucony',
    c.za_duzy === 'Plik jest za duży (maksimum 2 MB).', c.za_duzy);
  t.check('limit to 2 MB', c.limit_bajtow === 2097152, c.limit_bajtow + ' B');
  t.check('obce rozszerzenie odrzucone',
    c.zle_rozszerzenie === 'Dozwolone są pliki .csv i .txt.', c.zle_rozszerzenie);

  // Kontrola zawartości stoi w handlerze ZA `is_uploaded_file()`, które w CLI
  // zawsze odmawia — dlatego jest osobną funkcją i wołamy ją wprost.
  t.check('plik binarny nie uchodzi za tekst', c.obrazek_to_nie_tekst === false,
    String(c.obrazek_to_nie_tekst));
  t.check('lista adresów uchodzi za tekst', c.csv_to_tekst === true, String(c.csv_to_tekst));

  // Poprawny plik przechodzi wszystkie sprawdzenia metadanych. W CLI kończy na
  // `is_uploaded_file()` — inny komunikat znaczyłby, że coś odrzuciło go wcześniej.
  t.check('poprawny plik przechodzi metadane',
    c.poprawny_do_uploadu === 'Nieprawidłowy plik.', c.poprawny_do_uploadu);

  // ── Limiter logowania ─────────────────────────────────────────────────
  t.section('limiter logowania ma sufit');

  const l = php.limiter;
  t.check('tysiąc dwieście wpisów schodzi do sufitu',
    l.przed === 1200 && l.po === l.sufit, l.przed + ' → ' + l.po + ' (sufit ' + l.sufit + ')');
  t.check('wpisy sprzed okna resetu wylatują', l.stare_wylecialy === true,
    String(l.stare_wylecialy));
  t.check('najświeższe zostają', l.najswiezszy_zostal === true, String(l.najswiezszy_zostal));

  // Zapis idzie prawdziwą ścieżką: hook wp_login_failed.
  t.check('nieudane logowanie przycina przy zapisie', l.po_zapisie === l.sufit,
    l.po_zapisie + ' wpisów');
  t.check('i nadal notuje nowy adres', l.nowy_ip_zapisany === true,
    String(l.nowy_ip_zapisany));

  // To jest sedno: opcja wczytywana przy każdym żądaniu do serwisu kontra
  // opcja wczytywana tylko wtedy, gdy ktoś się loguje.
  t.check('próby zapisane BEZ autoloadu', l.autoload_prob === false,
    String(l.autoload_prob));
  t.check('blokady zapisane BEZ autoloadu', l.autoload_blokad === false,
    String(l.autoload_blokad));

  // ── Snippety ──────────────────────────────────────────────────────────
  t.section('kod snippetów nie jest zwykłym wpisem');

  const s = php.snippety;
  t.check('typ wpisu zarejestrowany', s.zarejestrowany === true, String(s.zarejestrowany));
  t.check('własny capability_type, nie „post"',
    s.capability_type === 'evk_code_snippet', String(s.capability_type));
  t.check('mapowanie uprawnień włączone', s.map_meta_cap === true, String(s.map_meta_cap));

  /* TO SPRAWDZENIE POWSTAŁO PO AWARII, KTÓRĄ POPRZEDNIE PRZEPUŚCIŁO.
   *
   * W 1.132.0 tablica `capabilities` mapowała na `manage_options` WSZYSTKO,
   * łącznie z meta-capami `edit_post`, `read_post`, `delete_post`. WordPress
   * robi wtedy wpis w GLOBALNEJ tablicy `$post_type_meta_caps` pod kluczem
   * `manage_options`, a `map_meta_cap()` przekierowuje odtąd każde sprawdzenie
   * `manage_options` w całej witrynie na `edit_post` — bez identyfikatora wpisu,
   * czyli z wynikiem `do_not_allow`. Administrator tracił menu Ustawienia,
   * dostęp do buildera i do wszystkich wtyczek pytających o to uprawnienie.
   *
   * Poprzednia wersja testu sprawdzała, że WSZYSTKIE uprawnienia prowadzą do
   * `manage_options` — czyli POTWIERDZAŁA zepsutą konfigurację jako poprawną.
   * Mutacje przechodziły, bo mutowały to samo błędne założenie. Sprawdzamy więc
   * teraz MECHANIZM rdzenia, a nie kształt tablicy. */
  t.check('brak meta-capów w tablicy uprawnień', s.meta_capy.length === 0,
    s.meta_capy.length ? 'ZATRUWA: ' + s.meta_capy.join(', ') : 'edit_post/read_post/delete_post nieobecne');
  t.check('nic nie trafia do globalnej tablicy meta-capów rdzenia',
    s.zatruwa.length === 0,
    s.zatruwa.length
      ? 'ZATRUTE W CAŁEJ WITRYNIE: ' + s.zatruwa.join(', ')
      : '$post_type_meta_caps nietknięte');

  // Druga połowa: ochrona, o którą chodziło w 1.132.0, ma zostać. Bez niej
  // Redaktor (edit_others_posts) sięgałby treści idącej przez eval() każdą
  // ogólną drogą edycji wpisów — WP-CLI, importerem, cudzym endpointem.
  t.check('primitywy prowadzą do manage_options', s.primitywy_admin === true,
    s.ile_primitywow + ' uprawnień');
  t.check('edit_others_posts nie jest drogą dojścia',
    s.uprawnienia.edit_others_posts === 'manage_options',
    String(s.uprawnienia.edit_others_posts));
};
