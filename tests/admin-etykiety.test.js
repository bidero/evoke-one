/**
 * Każda kontrolka panelu ma nazwę dostępną (1.237.0).
 *
 * Pomiar przed zmianą, 47 zakładek z tab.php: 974 kontrolki, z tego 248 bez
 * żadnej nazwy, 224 z samym placeholderem i 32 z samym `title`. W pełnym
 * zakresie tego pliku (dziewięć ekranów spoza tab.php, szablony wierszy,
 * wszystkie odnośniki) — 726 bez nazwy. Czytnik ekranu mówił przy nich „pole
 * edycji" albo „pole wyboru" — bez słowa, czego dotyczą. Najczęstsze dwa wzory:
 *   — włącznik modułu (`<label class="evo-toggle">` bez tekstu), pierwszy
 *     checkbox niemal każdej zakładki;
 *   — `<label>Tekst</label><input name=…>` — etykieta widoczna na ekranie,
 *     ale niepowiązana z polem.
 *
 * Nazwa się liczy, gdy pochodzi z `aria-labelledby`, `aria-label`,
 * powiązanej etykiety (`for`/`id` albo etykieta wokół pola) albo — dla
 * przycisków i odnośników — z własnego tekstu. Sam placeholder i sam `title`
 * nie wystarczają: placeholder znika przy wpisywaniu, a `title` wiele
 * czytników pomija. Do tego: każde `for` wskazuje istniejące pole, a `id`
 * w zakładce się nie powtarzają (zdublowane `id` wiąże etykietę z pierwszym
 * polem, drugie zostaje bez nazwy).
 *
 * Zakładki: tests/lib/zakladki-panelu.js (ta sama lista co admin-tabs),
 * renderowane PRAWDZIWYM plikiem przez tests/php/tab.php. Do tego ekrany,
 * których tamta lista nie obejmuje, bo mają własne sondy — każdy z nich jest
 * częścią panelu i bez niego „każda kontrolka" znaczyłoby „każda, którą akurat
 * widać w tab.php".
 */

const fs = require('fs');
const path = require('path');
const { phpOutput } = require('./lib/harness');
const { TABS } = require('./lib/zakladki-panelu');

const EKRANY = [
  ['pulpit i powłoka panelu (pasek boczny, wyszukiwarka)', () => phpOutput('panel-start.php', '"{}"')],
  ['Animator', () => phpOutput('anim-tab.php', JSON.stringify(JSON.stringify(['alfa', 'beta'])))],
  ['Snippety: lista', () => phpOutput('tab.php', 'tools-snippety')],
  ['Snippety: edytor', () => phpOutput('tab.php', 'tools-snippety '
    + JSON.stringify(JSON.stringify({ evk_widok: 'edytor', evk_wpis: 'pierwszy' })))],
  ['Snippety: logi', () => phpOutput('tab.php', 'tools-snippety ' + JSON.stringify(JSON.stringify({ evk_widok: 'logi' })))],
  ['Snippety: tryb zaawansowany', () => phpOutput('tab.php', 'tools-snippety ' + JSON.stringify(JSON.stringify({ evk_widok: 'advanced' })))],
  /* Zasiew `og` ma trzy warstwy (og-layers liczy na dokładnie te) — tu każdy
     typ, bo każdy ma własne pola. Warstwa QR miała do 1.236.0 drugą parę X/Y
     pod tymi samymi nazwami: po dopięciu etykiet dałaby zdublowane `id`. */
  ['SEO → OG: warstwa każdego typu', () => phpOutput('tab.php', 'og "" "" ' + JSON.stringify(JSON.stringify({
    evk_og: { enabled: 1, layers: ['rect', 'photo', 'gradient', 'image', 'text', 'qr'].map((type) => ({ type, enabled: 1 })) },
  })))],
  ['Rewizje', () => phpOutput('rewizje.php', 'ekran')],
  ['Skrzynka wiadomości', () => phpOutput('inbox-page.php')],
];

module.exports = async function (t) {
  let razem = 0, bezNazwy = 0;
  const lista = TABS.map((slug) => ['zakładka „' + slug + '"', () => phpOutput('tab.php', slug)]).concat(EKRANY);
  for (const [etykieta, markup] of lista) {
    t.section(etykieta);
    const head = 'window.__tab = ' + JSON.stringify(markup()) + ';';
    const p = await t.open('admin-tabs.html', { viewport: { width: 1400, height: 900 }, head, settle: 60 });
    const w = await p.evaluate(() => {
      const tekst = (el) => (el ? (el.innerText || el.textContent || '') : '').replace(/\s+/g, ' ').trim();
      const nazwa = (el) => {
        const lb = el.getAttribute('aria-labelledby');
        if (lb && lb.split(/\s+/).map((id) => tekst(document.getElementById(id))).join('').trim()) return 'aria-labelledby';
        if ((el.getAttribute('aria-label') || '').trim()) return 'aria-label';
        if (el.labels && [...el.labels].some((l) => tekst(l))) return 'etykieta';
        const przycisk = el.tagName === 'BUTTON' || el.tagName === 'A' || (el.tagName === 'INPUT' && /^(submit|button|reset)$/.test(el.type));
        if (przycisk && (el.tagName === 'INPUT' ? (el.value || '').trim() : tekst(el))) return 'tekst';
        if ((el.getAttribute('placeholder') || '').trim()) return 'sam placeholder';
        if ((el.getAttribute('title') || '').trim()) return 'sam title';
        return 'brak';
      };
      const opis = (el) => el.tagName.toLowerCase() + (el.type ? '[' + el.type + ']' : '')
        + (el.name ? ' name=' + el.name : el.id ? ' #' + el.id : el.className ? ' .' + String(el.className).split(' ')[0] : '');
      const panel = document.getElementById('panel');
      /* Szablony wierszy (`<script type="text/template">` z {INDEX}, `<template>`)
         skrypt zakładki wstawia przy „Dodaj". Wstawiamy każdy DWA razy, jak
         dwa kliknięcia: nazwy muszą mieć także nowe wiersze, a `id` w szablonie
         bez {INDEX} powtórzyłoby się w drugim — tak wyglądał szablon Kursora
         po pierwszym przejściu skryptu etykiet (`…-INDEX-…` zamiast {INDEX}). */
      const szablony = [...panel.querySelectorAll('script[type="text/template"], template')];
      const wstawione = document.createElement('div');
      szablony.forEach((s) => [90001, 90002].forEach((i) => {
        wstawione.insertAdjacentHTML('beforeend', s.innerHTML.replace(/\{INDEX\}/g, String(i)));
      }));
      panel.appendChild(wstawione);
      /* Odnośniki wszystkie, nie tylko `a.button`: ikona z samym `title`
         (np. „Raport" w kampaniach newslettera) jest tak samo nieme. */
      const kontrolki = [...panel.querySelectorAll('input, select, textarea, button, a[href]')]
        .filter((el) => !(el.tagName === 'INPUT' && el.type === 'hidden'));
      const zle = kontrolki.map((el) => [opis(el), nazwa(el)]).filter(([, n]) => !['aria-labelledby', 'aria-label', 'etykieta', 'tekst'].includes(n));
      const dla = [...panel.querySelectorAll('label[for]')].map((l) => l.getAttribute('for')).filter((id) => !document.getElementById(id));
      const ids = [...panel.querySelectorAll('[id]')].map((el) => el.id);
      const dubel = [...new Set(ids.filter((id, i) => ids.indexOf(id) !== i))];
      return { razem: kontrolki.length, szablony: szablony.length, zle: zle.map(([o, n]) => o + ' (' + n + ')'), dla, dubel };
    });
    razem += w.razem;
    bezNazwy += w.zle.length;
    t.check('każda kontrolka ma nazwę dostępną', w.zle.length === 0,
      w.zle.length ? w.zle.length + ' bez nazwy: ' + w.zle.slice(0, 5).join(' | ')
        : w.razem + ' kontrolek' + (w.szablony ? ', w tym ' + w.szablony + ' szablon(y) wierszy wstawione dwa razy' : ''));
    t.check('każde „for" wskazuje pole, „id" bez powtórzeń', !w.dla.length && !w.dubel.length,
      JSON.stringify({ for_bez_pola: w.dla.slice(0, 4), zdublowane_id: w.dubel.slice(0, 4) }));
  }
  /* Import pliku z samej klawiatury (1.237.0). Strefa upuszczania to div
     z onclick, a pole pliku miało display:none — Tab nie miał dokąd pójść
     i importu ustawień ani tłumaczeń nie dało się zacząć bez myszy. Nazwa
     pola, do którego nie da się dojść, nic nie daje, więc oba naraz: Tab
     dochodzi do pola, a strefa pokazuje fokus. Style Tłumaczeń siedzą
     w render.php (własny <style>), nie w admin.css — stąd wstawka z pliku. */
  t.section('import pliku z klawiatury');
  const stylTl = fs.readFileSync(path.join(__dirname, '..', 'includes', 'admin', 'tl', 'render.php'), 'utf8')
    .match(/\.tl-drop-zone[^\n]*\n/g) || [];
  for (const [slug, pole, strefa, css] of [
    ['tools-io', '#evo-file-input', '#evo-drop-zone', ''],
    ['tl-io', '#tl-file-input', '#tl-drop-zone', '<style>' + stylTl.join('') + '</style>'],
  ]) {
    const head = 'window.__tab = ' + JSON.stringify(css + phpOutput('tab.php', slug)) + ';';
    const p = await t.open('admin-tabs.html', { viewport: { width: 1400, height: 900 }, head, settle: 60 });
    let doszlo = false;
    for (let i = 0; i < 80 && !doszlo; i++) {
      await p.keyboard.press('Tab');
      doszlo = await p.evaluate((s) => !!document.activeElement && document.activeElement.matches(s), pole);
    }
    await p.waitForTimeout(300); // strefa ma `transition: all .2s` — obwódka rośnie od zera
    const obw = await p.evaluate((z) => { const c = getComputedStyle(document.querySelector(z)); return c.outlineStyle + ' ' + c.outlineWidth; }, strefa);
    t.check(slug + ': Tab dochodzi do pola pliku, strefa ma obwódkę fokusu', doszlo && /^solid [1-9]/.test(obw),
      (doszlo ? 'pole osiągalne' : 'Tab nie doszedł do pola') + ', obwódka strefy: ' + obw);
  }

  t.section('wszystkie zakładki');
  t.check('było co mierzyć (1.237.0: 1413 kontrolek w 47 zakładkach i 9 ekranach)', razem > 1300, String(razem));
  t.check('żadnej kontrolki bez nazwy (przed 1.237.0: 726)', bezNazwy === 0, bezNazwy + ' z ' + razem);
};
