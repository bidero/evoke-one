/**
 * Co zostaje z pliku SVG, zanim wtyczka wstawi go w treść strony.
 *
 * Naprawiane w 1.131.0. Do 1.130.0 `tl_get_svg_content()` zdejmowało wyłącznie
 * deklarację XML i doctype — dwa `preg_replace` i nic więcej. Przez takie sito
 * przechodziło `<script>`, `onload=` na `<svg>`, `<foreignObject>` z `<iframe>`
 * i `javascript:` w `href`, a plik z biblioteki mediów stawał się skryptem
 * wykonywanym u każdego odwiedzającego, na każdej podstronie z przełącznikiem
 * języka. Pięć miejsc wywołania — wszystkie przez ten jeden getter.
 *
 * Test chodzi na PRAWDZIWYM `wp_kses` (kopia WordPressa w `tests/php/wp/`).
 * Atrapa sita bezpieczeństwa nie mówiłaby nic o rzeczywistości: sprawdzałaby,
 * czy moja własna imitacja usuwa to, co sama uznała za groźne.
 *
 * DRUGA POŁOWA TESTU JEST WAŻNIEJSZA OD PIERWSZEJ. „Naprawa" zwracająca pusty
 * łańcuch przeszłaby sprawdzenia „nie ma skryptu" celująco — a flagi zniknęłyby
 * ze wszystkich stron. Dlatego prawdziwa flaga jedzie tędy w komplecie:
 * z `viewBox`, blokiem `<style>` z Illustratora, gradientem, `clipPath`
 * i stylem w atrybucie.
 *
 * Czego test nie sprawdza: że przeglądarka naprawdę nie wykona wyczyszczonego
 * pliku (patrzymy na łańcuch, nie na zachowanie silnika) ani drogi
 * `/wp-content/uploads/…svg`, którą serwer oddaje z pominięciem wtyczki —
 * ta świadomie zostaje otwarta, bo SVG wgrywają u Ciebie administratorzy.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const php = JSON.parse(phpOutput('svg-sanityzacja.php'));

  // ── Co ma wylecieć ────────────────────────────────────────────────────
  t.section('groźny plik zostaje rozbrojony');

  const z = php.zly;
  t.check('nie ma <script>', z.script === false, z.script ? z.wynik : 'usunięty');
  t.check('nie ma onload na <svg>', z.onload === false, z.onload ? z.wynik : 'usunięty');
  t.check('nie ma onclick ani onmouseover', !z.onclick && !z.onmouseover,
    (z.onclick || z.onmouseover) ? z.wynik : 'usunięte');
  t.check('nie ma onerror', z.onerror === false, z.onerror ? z.wynik : 'usunięty');
  t.check('nie ma javascript: w adresie', z.javascript === false,
    z.javascript ? z.wynik : 'usunięty');
  t.check('nie ma <iframe> ani <foreignObject>', !z.iframe && !z.foreign,
    (z.iframe || z.foreign) ? z.wynik : 'usunięte');
  t.check('nie ma adresu data:', z.data_uri === false, z.data_uri ? z.wynik : 'usunięty');
  // <set> i <animate> potrafią podmienić href już po wczytaniu strony —
  // to osobny, mniej znany wektor niż sam <script>.
  t.check('nie ma <set> ani <animate>', !z.set && !z.animate,
    (z.set || z.animate) ? z.wynik : 'usunięte');
  t.check('nie ma doctype', z.doctype === false, z.doctype ? z.wynik : 'usunięty');

  // ── Co ma przeżyć ─────────────────────────────────────────────────────
  t.section('prawdziwa flaga przechodzi bez uszczerbku');

  const ma = php.flaga.ma;
  const brakuje = Object.keys(ma).filter((k) => !ma[k]);

  t.check('wynik nie jest pusty', php.flaga.wynik.length > 200,
    php.flaga.wynik.length + ' znaków');

  // viewBox osobno, bo bez niego flaga nie ma jak się skalować — a to jest
  // atrybut, który najłatwiej zgubić: kses dopasowuje nazwy po małych literach.
  t.check('viewBox przeżywa z wielką literą', ma.viewBox === true,
    ma.viewBox ? 'viewBox="0 0 640 480"' : 'ZGUBIONY');

  t.check('struktura SVG zostaje',
    ma.lineargradient && ma.clippath && ma.grupa && ma.circle && ma.polygon,
    brakuje.join(', ') || 'komplet');
  t.check('ścieżki i klasy zostają', ma.path_d && ma.klasa, brakuje.join(', ') || 'komplet');

  // Illustrator eksportuje flagi z blokiem <style> i klasami na kształtach.
  // Bez tego elementu takie pliki wyszłyby z sita czarne.
  t.check('blok <style> z klasami zostaje', ma.style_blok === true,
    ma.style_blok ? '.st0{fill:#D80027;}' : 'ZGUBIONY');

  // wp_kses filtruje też zawartość atrybutu style, a jego lista właściwości
  // CSS jest listą dla HTML-a — nie ma na niej fill ani stroke.
  t.check('styl w atrybucie zachowuje fill i stroke', ma.style_inline && ma.stroke_inline,
    (ma.style_inline && ma.stroke_inline) ? 'fill + stroke-width' : 'ZGUBIONE');

  t.check('odwołania xlink:href i url(#…) zostają', ma.xlink && ma.fill_url && ma.clip_path_attr,
    brakuje.join(', ') || 'komplet');
  t.check('dostępność zostaje (title, aria-label)', ma.title && ma.aria,
    brakuje.join(', ') || 'komplet');

  t.check('nic z listy nie zginęło', brakuje.length === 0, brakuje.join(', ') || 'komplet');

  // ── Wejścia, które nie są SVG-iem ─────────────────────────────────────
  t.section('wejścia spoza zakresu');

  t.check('plik o innym typie MIME daje pustkę', php.nie_svg === '', JSON.stringify(php.nie_svg));
  t.check('brak identyfikatora daje pustkę', php.bez_id === '', JSON.stringify(php.bez_id));
  t.check('pusty plik daje pustkę', php.pusty === '', JSON.stringify(php.pusty));
};
