/**
 * Pola języków w elementach Bricksa (1.241.0).
 *
 * ZGŁOSZONE Z UŻYCIA: tłumaczenie przypięte do TEKSTU oryginału (słownik
 * „fraza PL → EN/DE") ginęło przy każdej zmianie polskiego tekstu. Decyzja
 * zgłaszającego: w każdym elemencie grupa „Tłumaczenia" z polem na każdy
 * język dla każdego tekstu, bez pól technicznych, także w pozycjach list.
 * Kolejność: pole w elemencie → słownik → oryginał.
 *
 * Sonda (tests/php/tl-pola-elementow.php) woła PRAWDZIWE funkcje modułu na
 * tablicach w kształcie Bricksa, a na końcu przepuszcza wynik przez prawdziwy
 * tokenizer słownika. Czy Bricks POKAŻE te pola w panelu i czy zobaczy je
 * dostęp „Edytuj treść", stąd nie widać — to sprawdza się na stronie.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const d = JSON.parse(phpOutput('tl-pola-elementow.php'));
  const k = d.kontrolki;
  const nowe = (el) => k[el].nowe;

  t.section('rejestracja: filtry dla każdego elementu z rejestru Bricksa');
  t.check('kontrolki i grupy dla każdego elementu', Object.values(d.rejestracja).every((r) => r.kontrolki && r.grupy),
    JSON.stringify(d.rejestracja));

  t.section('grupa „Tłumaczenia" na końcu treści');
  const klucze = Object.keys(d.grupy);
  t.check('grupa dołożona jako ostatnia, w zakładce treści',
    klucze[klucze.length - 1] === 'evk_tl' && d.grupy.evk_tl.title === 'Tłumaczenia' && d.grupy.evk_tl.tab === 'content',
    JSON.stringify(d.grupy));
  t.check('bez aktywnych języków grupy nie ma', !('evk_tl' in d.grupy_bez_jezykow), JSON.stringify(d.grupy_bez_jezykow));

  t.section('pola języków: teksty tak, techniczne nie');
  t.check('nagłówek: tekst w EN i DE', JSON.stringify(nowe('heading')) === '["evk_tl_en__text","evk_tl_de__text"]', JSON.stringify(nowe('heading')));
  t.check('obrazek: tekst alternatywny i podpis', ['evk_tl_en__altText', 'evk_tl_en__captionCustom'].every((x) => nowe('image').includes(x)), JSON.stringify(nowe('image')));
  t.check('formularz: tekst przycisku i komunikat sukcesu',
    ['evk_tl_en__submitButtonText', 'evk_tl_en__successMessage'].every((x) => nowe('form').includes(x)), JSON.stringify(nowe('form')));
  t.check('licznik: przedrostek tak, liczba nie', nowe('counter').includes('evk_tl_en__prefix') && !nowe('counter').some((x) => /countTo/.test(x)),
    JSON.stringify(nowe('counter')));
  t.check('element Evoke: etykieta tak, selektor i adres nie',
    JSON.stringify(nowe('evoke-offcanvas-menu')) === '["evk_tl_en__closeLabel","evk_tl_de__closeLabel"]', JSON.stringify(nowe('evoke-offcanvas-menu')));
  const wszystkieNowe = Object.values(k).flatMap((v) => v.nowe);
  const techniczne = ['customTag', '_cssId', 'separatorText', 'maxWidth', 'emailSubject', 'redirect', 'fromName', 'shortcode', 'toggleSelector', 'triggerAttr', 'countTo'];
  t.check('żadnego pola technicznego (tag, CSS, styl, temat maila, adres, skrótkod, selektor, liczba)',
    !wszystkieNowe.some((x) => techniczne.includes(x.replace(/^evk_tl_[a-z_]+?__/, ''))), JSON.stringify(wszystkieNowe));
  t.check('element bez tekstów i skrótkod: tablica bez zmian', k['bez-tekstu'].bez_zmian && k.shortcode.bez_zmian);
  t.check('pola języków na końcu tablicy (po polach elementu)',
    k.heading.kolejnosc.slice(-2).join() === 'evk_tl_en__text,evk_tl_de__text', JSON.stringify(k.heading.kolejnosc));

  t.section('definicja pola języka');
  const def = k.heading.definicje.evk_tl_en__text || {};
  t.check('typ pola źródłowego, etykieta „Tekst — EN", grupa „Tłumaczenia"',
    def.type === 'text' && def.label === 'Tekst — EN' && def.group === 'evk_tl' && def.tab === 'content', JSON.stringify(def));
  t.check('bez `default` (kropka „ma ustawienia" w builderze) i bez edycji na kanwie',
    !('default' in def) && !('inlineEditing' in def), JSON.stringify(def));
  t.check('dane dynamiczne zostają, jak w polu źródłowym', def.hasDynamicData === 'text', JSON.stringify(def));
  t.check('typ edytora i akapitu zachowany', (k.image.definicje.evk_tl_en__captionCustom || {}).type === 'textarea'
    && ((k.accordion.defs_listy.accordions || {}).evk_tl_en__content || {}).type === 'editor');

  t.section('pozycje list: pola języków wewnątrz pozycji');
  const akordeon = k.accordion.pola_listy.accordions || [];
  t.check('akordeon: tytuł i treść w EN i DE, ikona nie',
    ['evk_tl_en__title', 'evk_tl_de__title', 'evk_tl_en__content', 'evk_tl_de__content'].every((x) => akordeon.includes(x))
    && !akordeon.some((x) => /icon/.test(x) && /^evk_tl/.test(x)), JSON.stringify(akordeon));
  const pola = k.form.pola_listy.fields || [];
  t.check('pola formularza: etykieta i tekst zastępczy tak, wartość i opcje nie (wysyłane formularzem)',
    pola.includes('evk_tl_en__label') && pola.includes('evk_tl_en__placeholder')
    && !pola.some((x) => /^evk_tl_.*__(value|options|type)$/.test(x)), JSON.stringify(pola));
  const wPozycji = (k.accordion.defs_listy.accordions || {}).evk_tl_en__title || {};
  t.check('pole w pozycji bez grupy (grupa należy do elementu)', !('group' in wPozycji), JSON.stringify(wPozycji));

  t.section('podmiana przed renderem');
  const p = d.podmiana;
  t.check('strona polska: bez zmian', p.pl && p.pl.text === 'Zapytaj o wycenę' && p.pl.accordions[0].title === 'Jeden', JSON.stringify(p.pl));
  t.check('strona EN: tekst z pola EN', p.en && p.en.text === 'Get a quote', JSON.stringify(p.en && p.en.text));
  t.check('strona DE: pusty edytor (`<p></p>`) zostawia polski — dla słownika', p.de && p.de.text === 'Zapytaj o wycenę', JSON.stringify(p.de && p.de.text));
  t.check('pozycje listy: przetłumaczona pozycja podmieniona, pusta (same spacje) zostaje po polsku',
    p.en && p.en.accordions[0].title === 'One' && p.en.accordions[0].content === '<p>Body</p>' && p.en.accordions[1].title === 'Dwa',
    JSON.stringify(p.en && p.en.accordions));
  t.check('pole języka nie-tekstowe pominięte', p.en && p.en.caption === 'Podpis', JSON.stringify(p.en && p.en.caption));
  t.check('odnośnik (tablica nie-lista) nietknięty', p.en && p.en.link && p.en.link.url === 'https://example.test/', JSON.stringify(p.en && p.en.link));
  t.check('w builderze i w wp-admin bez podmiany (tam edytuje się oryginał)',
    p.builder_en && p.builder_en.text === 'Zapytaj o wycenę' && p.admin_en && p.admin_en.text === 'Zapytaj o wycenę');
  t.check('kod języka z łącznikiem (pt-br): klucz z podkreślnikiem i podmiana działa',
    d.klucz_pt_br === 'evk_tl_pt_br__text' && p.pt_br && p.pt_br.text === 'Original', d.klucz_pt_br + ' ' + JSON.stringify(p.pt_br));

  t.section('kolejność: pole w elemencie → słownik → oryginał (prawdziwy tokenizer)');
  const o = d.kolejnosc;
  t.check('pole w elemencie wygrywa ze słownikiem', o.pole_elementu === '<h2>Get a quote</h2>', o.pole_elementu);
  t.check('puste pole: słownik tłumaczy jak dotąd', o.slownik === '<h2>Ask for a quote (słownik)</h2>', o.slownik);
  t.check('bez obu: oryginał', o.oryginal === '<h2>Bez tłumaczenia nigdzie</h2>', o.oryginal);
  t.check('SEDNO: polski zmieniony po tłumaczeniu — pole w elemencie dalej działa', o.po_zmianie_pl === '<h2>Get a quote</h2>', o.po_zmianie_pl);
  t.check('dla porównania: sam słownik po takiej zmianie już nie tłumaczy (zgłoszona usterka)',
    o.po_zmianie_bez === '<h2>Zapytaj o wycenę projektu</h2>', o.po_zmianie_bez);
};
