/**
 * Silnik podmiany tłumaczeń — zachowanie i koszt.
 *
 * DO 1.185.0 TEN KOD NIE MIAŁ ŻADNEGO POKRYCIA, mimo że przechodzi przez niego
 * każdy znak każdej obcojęzycznej podstrony. Zmiana wydajnościowa w 1.186.0
 * była pierwszym powodem, żeby to nadrobić — bo przepisania nietestowanego
 * kodu nie da się inaczej obronić niż „wygląda tak samo".
 *
 * Zadane pytanie brzmiało: „czy da się przyspieszyć efekty pracy modułu".
 * Odpowiedź (zmierzona): koszt rósł jak `węzły × frazy`, bo KAŻDY węzeł
 * tekstowy przeglądał CAŁĄ bibliotekę, normalizując przy tym każdą frazę
 * od nowa. Porównanie było dokładną równością, więc pętla nie miała czego
 * szukać — wystarczy indeks i jedno sięgnięcie.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const d = JSON.parse(phpOutput('tl-silnik.php', 'zachowanie'));

  // ── Co ma się podmienić ─────────────────────────────────────────────────
  t.section('podmiana trafia w tekst mimo różnic zapisu');

  t.check('dokładne trafienie', d.dokladne.gotowe === '<p>Ask for a quote</p>', d.dokladne.gotowe);
  /* Normalizacja wyrównuje wielkość liter i odstępy — inaczej fraza wpisana
     w panelu musiałaby zgadzać się ze stroną co do znaku. */
  t.check('inna wielkość liter', d.inna_wielkosc.gotowe === '<p>Ask for a quote</p>',
    d.inna_wielkosc.gotowe);
  t.check('odstępy wokół tekstu', d.odstepy_wokol.gotowe === '<p>Ask for a quote</p>',
    d.odstepy_wokol.gotowe);
  t.check('odstępy wokół FRAZY w panelu',
    d.fraza_z_odstepami.gotowe === '<p>With spaces</p>', d.fraza_z_odstepami.gotowe);
  t.check('zdwojone spacje', d.zdwojona_spacja.gotowe === '<p>Double space</p>',
    d.zdwojona_spacja.gotowe);
  /* Twarda spacja i encja to ten sam tekst dla czytelnika — i mają dawać to
     samo trafienie co zwykła spacja. */
  t.check('twarda spacja daje to samo trafienie',
    d.twarda_spacja.token === d.zdwojona_spacja.token, d.twarda_spacja.token);
  t.check('encja w treści', d.encja.gotowe === '<p>Amp entity</p>', d.encja.gotowe);

  // ── Czego ruszać nie wolno ──────────────────────────────────────────────
  /* KONTROLE NEGATYWNE. Bez nich „podmienia" przechodziłoby także dla kodu,
     który podmienia WSZYSTKO — a to jest gorsza usterka niż brak podmiany. */
  t.section('a reszta strony zostaje nietknięta');

  t.check('fraza bez przekładu zostaje po polsku',
    d.brak_tlumaczenia.gotowe === '<p>Bez tłumaczenia</p>', d.brak_tlumaczenia.gotowe);
  t.check('tekst spoza biblioteki nietknięty',
    d.nic_nie_pasuje.gotowe === '<p>Zupełnie inny tekst</p>', d.nic_nie_pasuje.gotowe);
  t.check('pusty węzeł nietknięty', d.pusty_wezel.gotowe === '<p>   </p>', d.pusty_wezel.gotowe);
  /* Po polsku silnik ma wychodzić natychmiast — ani tokenu, ani zmiany. */
  t.check('po polsku nic się nie dzieje',
    d.po_polsku.gotowe === '<p>Zapytaj o wycenę</p>' && !d.po_polsku.token.includes('##TL_'),
    d.po_polsku.gotowe);

  // ── Atrybuty ────────────────────────────────────────────────────────────
  t.section('atrybuty tłumaczą się osobną drogą');

  t.check('title się tłumaczy', d.atrybut_title.gotowe.includes('title="Ask for a quote"'),
    d.atrybut_title.gotowe);
  t.check('obcy title zostaje', d.atrybut_obcy.gotowe.includes('title="Cokolwiek innego"'),
    d.atrybut_obcy.gotowe);

  // ── Koszt ───────────────────────────────────────────────────────────────
  /* SEDNO ZMIANY WYDAJNOŚCIOWEJ i jedyny sposób, żeby pętla nie wróciła bokiem.
     Przy stałej liczbie węzłów dziesięciokrotnie większa biblioteka ma kosztować
     TYLE SAMO. Zmierzone na 600 węzłach:

         frazy │ pętla (do 1.185.0) │ indeks
            30 │           12,9 ms  │ 1,6 ms
           100 │           40,1 ms  │ 1,5 ms
           300 │          119,2 ms  │ 1,7 ms
          1000 │          418,7 ms  │ 1,5 ms

     KAŻDY ROZMIAR W OSOBNYM PROCESIE. `get_translation_config()` trzyma wynik
     w statyku, więc dwa rozmiary w jednym procesie mierzyłyby ten sam, pierwszy.
     Pierwsza wersja tego pomiaru miała ten błąd i „dowodziła", że koszt nie
     rośnie — podczas gdy rósł liniowo. */
  t.section('koszt nie rośnie z wielkością biblioteki');

  const male  = JSON.parse(phpOutput('tl-silnik.php', 'koszt 30'));
  const duze  = JSON.parse(phpOutput('tl-silnik.php', 'koszt 300'));

  /* Strażnik pomiaru: biblioteka NAPRAWDĘ urosła. Bez tego cała sekcja
     przechodziłaby przy pomiarze tej samej biblioteki dwa razy — czyli
     dokładnie tak, jak przechodziła u mnie, zanim to zauważyłem. */
  t.check('pomiar naprawdę objął dwie różne biblioteki',
    male.fraz === 30 && duze.fraz === 300, male.fraz + ' i ' + duze.fraz + ' fraz');

  t.check('dziesięciokrotnie większa biblioteka nie kosztuje więcej',
    duze.ms < male.ms * 2.5,
    male.ms + ' ms przy 30 frazach, ' + duze.ms + ' ms przy 300');

  /* I w liczbach bezwzględnych. Przed zmianą 300 fraz kosztowało 119 ms
     na każde żądanie obcojęzycznej podstrony. */
  t.check('600 węzłów mieści się w kilku milisekundach', duze.ms < 12,
    duze.ms + ' ms');
};
