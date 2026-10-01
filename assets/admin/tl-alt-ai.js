/* ✦ przy tekście alternatywnym obrazów (1.271.0): okno mediów i ekran edycji
   obrazu (pola z attachment_fields_to_edit, 57-translation-alt.php).

   „Przetłumacz” — alt języka z polskiego altu (AJAX `evk_tl_ai_pola`, jak ✦
   w edycji wpisu); „Opisz obraz (AI)” — polski alt z obrazu (`evk_tl_ai_opisz`).
   Oba tylko wpisują: okno mediów zapisuje pole samo przy zmianie, ekran obrazu
   — przyciskiem „Aktualizuj”. Ukryte `.evk-alt-zrodlo`: `ai` (wpis z ✦),
   `teraz` („Sprawdzone”), pusto — ręczna zmiana albo bez zmian. */
(function ($) {
    'use strict';

    var AI = window.evkAltAi || null;
    var trwa = false, pomin = false;

    /* Polskie pole altu: okno mediów (`data-setting="alt"`) albo ekran obrazu. */
    function altPl($w) {
        var $f = $w.closest('.attachment-details, .media-modal-content, .media-frame-content, form')
            .find('[data-setting="alt"] textarea, [data-setting="alt"] input').first();
        return $f.length ? $f : $('#attachment_alt');
    }
    function zajety(tak, $b) {
        trwa = tak;
        $('.evk-alt-tlumacz, .evk-alt-opisz').prop('disabled', tak);
        if ($b) $b.attr('aria-busy', tak ? 'true' : 'false');
    }
    function blad(r) {
        if (r === -1 || r === '-1' || r === 0 || r === '0') return 'Sesja wygasła albo brak uprawnień — przeładuj stronę.';
        return (r && typeof r.data === 'string' && r.data) || 'Serwer odmówił.';
    }

    $(document).on('input', '.evk-alt-pole', function () {
        if (pomin) return;
        var $w = $(this).closest('.evk-alt-ai');
        $w.find('.evk-alt-zrodlo').val('');
        $w.removeAttr('data-ai');
    });

    $(document).on('click', '.evk-alt-sprawdzone', function () {
        var $w = $(this).closest('.evk-alt-ai');
        $w.find('.evk-alt-zrodlo').val('teraz');
        $w.removeAttr('data-ai');
        $w.find('.evk-alt-pole').trigger('change').trigger('focus');   // okno mediów zapisuje przy zmianie
    });

    $(document).on('click', '.evk-alt-tlumacz', function () {
        if (!AI || trwa) return;
        var $b = $(this), $w = $b.closest('.evk-alt-ai'), $p = $w.find('.evk-alt-pole'), $stan = $w.find('.evk-alt-ai-stan');
        var lang = String($w.attr('data-lang') || ''), L = lang.toUpperCase();
        var pl = $.trim(String(altPl($w).val() || $w.attr('data-pl') || '')), obecny = $.trim(String($p.val() || ''));
        if (!pl) { $stan.text('Brak polskiego altu.'); return; }
        if (obecny && !window.confirm('Zastąpić obecny tekst alternatywny ' + L + '?\n\n„' + obecny.slice(0, 200) + '”')) return;
        zajety(true, $b);
        $stan.text('Tłumaczę na ' + L + '…');
        $.post(AI.ajax, { action: 'evk_tl_ai_pola', nonce: AI.nonce, post_id: $w.attr('data-id'), lang: lang,
            kontekst: JSON.stringify([{ el: 'Obraz', opis: 'Tekst alternatywny', pole: '', poz: 0, pl: pl, tl: '' }]),
            teksty: JSON.stringify({ k1: { n: 1, bylo: obecny } }) })
            .then(function (r) {
                if (!r || !r.success) return $stan.text(blad(r));
                var t = ((r.data || {}).tlumaczenia || {}).k1;
                if (typeof t !== 'string') return $stan.text((r.data || {}).blad || 'Brak tłumaczenia.');
                pomin = true;
                $p.val(t).trigger('input');
                pomin = false;
                $w.find('.evk-alt-zrodlo').val('ai');
                $w.attr('data-ai', '1');
                $p.trigger('change');
                $stan.text('Wpisane (AI' + (AI.model ? ' · ' + AI.model : '') + ') — sprawdź.');
            }, function () { $stan.text('Brak połączenia z serwerem.'); })
            .always(function () { zajety(false, $b); });
    });

    $(document).on('click', '.evk-alt-opisz', function () {
        if (!AI || trwa) return;
        var $b = $(this), $w = $b.closest('.evk-alt-ai'), $stan = $w.find('.evk-alt-ai-stan'), $f = altPl($w);
        var obecny = $.trim(String($f.val() || ''));
        if (obecny && !window.confirm('Zastąpić obecny tekst alternatywny?\n\n„' + obecny.slice(0, 200) + '”')) return;
        zajety(true, $b);
        $stan.text('AI ogląda obraz…');
        $.post(AI.ajax, { action: 'evk_tl_ai_opisz', nonce: AI.nonce, post_id: $w.attr('data-id') })
            .then(function (r) {
                if (!r || !r.success) return $stan.text(blad(r));
                $f.val(r.data.tekst).trigger('input').trigger('change');
                $w.attr('data-ai', '1');
                $stan.text('Wpisane (AI' + (r.data.model ? ' · ' + r.data.model : '') + ') — sprawdź.');
            }, function () { $stan.text('Brak połączenia z serwerem.'); })
            .always(function () { zajety(false, $b); });
    });
})(jQuery);
