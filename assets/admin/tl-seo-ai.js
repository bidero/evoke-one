/* ✦ przy polach SEO wersji językowych (1.271.0): zakładka SEO i metaboks SEO
   we wpisie (pasek z evk_seo_narzedzia_pola(), 85-seo-jezyki.php).

   Ten sam AJAX co ✦ w edycji wpisu (`evk_tl_ai_pola`, 61): serwer tylko
   tłumaczy, zapis zostaje w „Zapisz” zakładki albo w „Zaktualizuj” wpisu.
   Ukryte `.evk-seo-zrodlo` niesie do zapisu: `ai` (wpis z ✦), `teraz`
   („Sprawdzone”), pusto — ręczna zmiana albo bez zmian. Kontekst: tytuł wpisu
   i pozostałe pola SEO tego języka. Przełącznik języka metaboksu też tu. */
(function ($) {
    'use strict';

    var AI = window.evkSeoAi || null;
    var NAZWY = { title: 'Tytuł SEO', desc: 'Opis SEO', keywords: 'Słowa kluczowe' };
    var WERSJA = '.evk-seo-wersja, .evk-seo-mb-wersja';
    var pomin = false, trwa = false;

    function pole($n) {
        return $n.closest(WERSJA).find('.evk-seo-pole[data-pole="' + $n.attr('data-pole') + '"]');
    }
    function zmieniony($n) { $n.closest('.evoke-seo-row').addClass('is-dirty'); }

    /* Ręczna zmiana pola — tłumacz przejrzał: bez znaku AI, zapis porówna tekst. */
    $(document).on('input', '.evk-seo-pole', function () {
        if (pomin) return;
        var $n = $(this).closest(WERSJA).find('.evk-seo-ai-narzedzia[data-pole="' + this.getAttribute('data-pole') + '"]');
        $n.find('.evk-seo-zrodlo').val('');
        $n.removeAttr('data-ai');
    });

    $(document).on('click', '.evk-seo-sprawdzone', function () {
        var $n = $(this).closest('.evk-seo-ai-narzedzia');
        $n.find('.evk-seo-zrodlo').val('teraz');
        $n.removeAttr('data-ai');
        zmieniony($n);
        pole($n).trigger('focus');   // przycisk znika — fokus nie może przepaść
    });

    $(document).on('click', '.evk-seo-ai', function () {
        if (!AI || trwa) return;
        var $b = $(this), $n = $b.closest('.evk-seo-ai-narzedzia'), $p = pole($n), $stan = $n.find('.evk-seo-ai-stan');
        var lang = String($n.attr('data-lang') || ''), L = lang.toUpperCase();
        var $wpis = $b.closest('[data-evk-seo-post]'), obecny = $.trim(String($p.val() || ''));
        if (obecny && !window.confirm('Zastąpić obecne tłumaczenie ' + L + '?\n\n„' + obecny.slice(0, 200) + '”')) return;
        var kontekst = [], n = 0, tytul = String($wpis.attr('data-tytul') || '');
        if ($.trim(tytul)) kontekst.push({ el: 'Wpis', opis: 'Tytuł', pole: '', poz: 0, pl: tytul, tl: '' });
        $n.closest(WERSJA).find('.evk-seo-ai-narzedzia').each(function () {
            var $x = $(this), pl = String($x.attr('data-pl') || '');
            if (!$.trim(pl)) return;
            kontekst.push({ el: 'SEO', opis: NAZWY[$x.attr('data-pole')] || '', pole: '', poz: 0, pl: pl, tl: this === $n[0] ? '' : $.trim(String(pole($x).val() || '')) });
            if (this === $n[0]) n = kontekst.length;
        });
        if (!n) { $stan.text('Brak polskiego tekstu.'); return; }
        trwa = true;
        $('.evk-seo-ai').prop('disabled', true);
        $b.attr('aria-busy', 'true');
        $stan.text('Tłumaczę na ' + L + '…');
        $.post(AI.ajax, { action: 'evk_tl_ai_pola', nonce: AI.nonce, post_id: $wpis.attr('data-evk-seo-post'), lang: lang,
            kontekst: JSON.stringify(kontekst), teksty: JSON.stringify({ k1: { n: n, bylo: obecny } }) })
            .then(function (r) {
                if (r === -1 || r === '-1' || r === 0 || r === '0') return $stan.text('Sesja wygasła albo brak uprawnień — przeładuj stronę.');
                if (!r || !r.success) return $stan.text((r && typeof r.data === 'string' && r.data) || 'Serwer odmówił.');
                var d = r.data || {}, t = (d.tlumaczenia || {}).k1;
                if (typeof t === 'string') {
                    /* Pole zmienione w trakcie — pisanie wygrywa. */
                    if ($.trim(String($p.val() || '')) !== obecny) return $stan.text('Pole zmieniło się w trakcie — nic nie wpisuję.');
                    pomin = true;
                    $p.val(t).trigger('input');   // liczniki i „zmieniony wiersz” w admin.js
                    pomin = false;
                    $n.find('.evk-seo-zrodlo').val('ai');
                    $n.attr('data-ai', '1');
                    zmieniony($n);
                    $stan.text('Wpisane (' + ((d.zrodla || {}).k1 === 'ai' ? 'AI' : 'z pamięci') + (AI.model ? ' · ' + AI.model : '') + ') — sprawdź i zapisz.');
                    return;
                }
                if (d.blad) return $stan.text(d.czekaj ? 'Dostawca prosi o przerwę — spróbuj za ' + d.czekaj + ' s.' : String(d.blad));
                if ((d.bez_zmian || []).length) return $stan.text('AI zwróciło ten sam tekst — bez zmian.');
                if ((d.odrzucone || []).length) return $stan.text('Tłumaczenie odrzucone: znaczniki albo tagi {…} nie zgadzają się z oryginałem.');
                $stan.text('Brak tłumaczenia.');
            }, function () { $stan.text('Brak połączenia z serwerem.'); })
            .always(function () { trwa = false; $('.evk-seo-ai').prop('disabled', false); $b.attr('aria-busy', 'false'); });
    });

    /* Metaboks SEO: przełącznik PL | języki — jedna wersja pól naraz. */
    $(document).on('click', '.evk-seo-mb-przelacznik .evk-tl-jezyk', function () {
        var lang = this.getAttribute('data-lang'), $mb = $(this).closest('.evk-seo-mb');
        $mb.find('.evk-seo-mb-przelacznik .evk-tl-jezyk').each(function () {
            var tak = this.getAttribute('data-lang') === lang;
            this.setAttribute('aria-pressed', tak ? 'true' : 'false');
            $(this).toggleClass('button-primary', tak);
        });
        $mb.find('.evk-seo-mb-wersja').each(function () { this.hidden = this.getAttribute('data-lang') !== lang; });
    });
})(jQuery);
