/* Evoke ONE — tłumaczenia wpisów (1.252.0) i termów (1.253.0) w panelu.
   Przełącznik języka nad formularzem, pola języka w miejscu oryginałów,
   drugi edytor treści uruchamiany przy pierwszym wejściu w język, „Kopiuj
   z polskiego", „Sprawdzone", podpowiedź ze słownika i liczniki. Moduły:
   includes/55-translation-posts.php, includes/56-translation-terms.php.

   Pole języka (.evk-tlw-pole) mówi atrybutami, gdzie jest:
     data-oryginal  pole polskie (tekst do kopiowania, podglądu i liczników);
     data-ukryj     co chować w widoku języka (pole polskie z oprawą);
     data-po        za czym stanąć (wiersz formularza termu);
     data-do        do czego dołączyć (pudełko „Zajawka"). */
(function ($) {
    'use strict';

    var $przel = $('.evk-tlw-przelacznik');
    var $form = $($przel.attr('data-forma') || '#post');
    if (!$form.length || !$przel.length) return;

    var edytory = {};   // id pola treści języka => uruchomiony
    var $pola = $('.evk-tlw-pole');

    /* Pola języka na swoje miejsca; to, co zasłaniają, dostaje klasę chowaną
       w widoku języka. „Za wierszem" wstawiamy od końca, „na koniec pudełka"
       od początku — w obu razach języki stają po kolei. */
    $pola.each(function () {
        var doC = this.getAttribute('data-do');
        if (doC && $(doC).length) $(doC).first().append(this);
    });
    $($pola.get().reverse()).each(function () {
        var po = this.getAttribute('data-po');
        if (po && !this.getAttribute('data-do') && $(po).first().length) $(this).insertAfter($(po).first());
    });
    $pola.each(function () {
        var ukryj = this.getAttribute('data-ukryj');
        if (ukryj) $(ukryj).not('.evk-tlw-pole').addClass('evk-tlw-ukryty-w-jezyku');
    });

    function edytor(id) {
        return window.tinymce ? window.tinymce.get(id) : null;
    }

    /** Wartość pola języka (treść: z edytora wizualnego, gdy działa). */
    function wartosc($pole) {
        var $w = $pole.find('.evk-tlw-wejscie');
        if (!$w.length) return '';
        var ed = edytor($w.attr('id'));
        return (ed && !ed.isHidden()) ? ed.getContent() : String($w.val() || '');
    }

    /** Polski tekst pola (z pola wskazanego w data-oryginal). */
    function polski($pole) {
        var sel = $pole.attr('data-oryginal');
        if (!sel) return '';
        var $o = $(sel).first();
        var ed = edytor($o.attr('id'));
        return (ed && !ed.isHidden()) ? ed.getContent() : String($o.val() || '');
    }

    function pusty(html) {
        return $.trim($('<div>').html(String(html || '')).text().replace(/ /g, ' ')) === '';
    }

    function podglad(html) {
        var t = $.trim($('<div>').html(String(html || '')).text().replace(/[\s ]+/g, ' '));
        return t.length > 160 ? t.slice(0, 159) + '…' : (t || '—');
    }

    /* Drugi edytor treści: ustawienia jak edytor główny (ten sam pasek,
       „Dodaj medium", karty Wizualny/Tekst), uruchamiany dopiero przy wejściu
       w język — ukryty TinyMCE rysowałby się bez wysokości. */
    function uruchomEdytor(lang) {
        var $t = $('.evk-tlw-tresc[data-lang="' + lang + '"] .evk-tlw-edytor');
        if (!$t.length) return;
        var id = $t.attr('id');
        if (edytory[id] || !window.wp || !wp.editor || !wp.editor.initialize) return;
        edytory[id] = true;
        var baza = window.tinyMCEPreInit && tinyMCEPreInit.mceInit && tinyMCEPreInit.mceInit.content;
        var tiny = baza ? $.extend(true, {}, baza) : true;
        if (tiny !== true) {
            delete tiny.selector;
            tiny.body_class = String(tiny.body_class || '').replace(/\bcontent\b/, id);
            tiny.wp_autoresize_on = false;
        }
        wp.editor.initialize(id, { tinymce: tiny, quicktags: true, mediaButtons: true });
        var ed = edytor(id);
        if (ed) ed.on('change keyup undo redo', function () { oznaczZmiane($t.closest('.evk-tlw-pole')); licz(); });
    }

    function licz() {
        $przel.find('.evk-tl-jezyk').each(function () {
            var lang = this.getAttribute('data-lang'), n = 0, m = 0;
            if (lang === 'pl') return;
            $('.evk-tlw-pole[data-lang="' + lang + '"]').each(function () {
                var $w = $(this).find('.evk-tlw-wejscie');
                if (!$w.length || $w.attr('data-pl') !== '1') return;
                m++;
                if (!pusty(wartosc($(this)))) n++;
            });
            $(this).find('.evk-tl-licznik').text(m ? n + '/' + m : '');
            $(this).find('.evk-tl-licznik-sr').text(m ? ', przetłumaczone ' + n + ' z ' + m : '');
        });
    }

    function przelacz(lang) {
        if (!$przel.find('.evk-tl-jezyk[data-lang="' + lang + '"]').length) return;
        $przel.find('.evk-tl-jezyk').each(function () {
            var on = this.getAttribute('data-lang') === lang;
            this.setAttribute('aria-pressed', on ? 'true' : 'false');
            $(this).toggleClass('button-primary', on);
        });
        if (lang === 'pl') {
            $form.removeAttr('data-evk-tlw');
            /* Edytor główny ma przyklejany pasek (editor-expand.js), którego
               położenie i szerokość WordPress liczy przy przewijaniu. Liczone
               w widoku języka, gdy edytor jest schowany, wychodzą zera —
               po powrocie ikony leżały na tekście. „resize" liczy je od nowa
               (a przez wp-window-resized także wysokość edytora). */
            $(window).trigger('resize');
        } else {
            $form.attr('data-evk-tlw', lang);
            uruchomEdytor(lang);
        }
        licz();
    }

    /* Klasa `evk-tl-jezyk` jest wspólna z Evoke FIELDS: kliknięcie w jego
       przełącznik przełącza też pola wpisu i termu, a nasze — grupy FIELDS. */
    $(document).on('click', '.evk-tl-jezyk', function () {
        przelacz(String(this.getAttribute('data-lang') || ''));
    });

    /** Podpowiedź słownika widać tylko przy pustym polu. */
    function oznaczZmiane($pole) {
        if (!$pole.length) return;
        $pole.find('.evk-tlw-slownik').toggle(pusty(wartosc($pole)));
    }

    $(document).on('input change', '.evk-tlw-pole .evk-tlw-wejscie', function () {
        oznaczZmiane($(this).closest('.evk-tlw-pole'));
        licz();
    });

    /* Zmiana polskiego tekstu w tej sesji: podgląd oryginału na bieżąco,
       a tłumaczenia z treścią dostają „Do sprawdzenia". */
    function polskiZmieniony(sel) {
        $pola.filter(function () { return this.getAttribute('data-oryginal') === sel; }).each(function () {
            var $p = $(this), pl = polski($p);
            $p.find('.evk-tlw-oryginal-tekst').text(podglad(pl));
            $p.find('.evk-tlw-wejscie').attr('data-pl', pusty(pl) ? '0' : '1');
            if (!pusty(wartosc($p))) $p.find('.evk-tlw-do-sprawdzenia, .evk-tlw-sprawdzone').prop('hidden', false);
        });
        licz();
    }
    var oryginaly = [];
    $pola.each(function () {
        var sel = this.getAttribute('data-oryginal');
        if (sel && oryginaly.indexOf(sel) < 0) oryginaly.push(sel);
    });
    $.each(oryginaly, function (i, sel) {
        $(document).on('input', sel, function () { polskiZmieniony(sel); });
    });
    $(document).on('tinymce-editor-init', function (e, ed) {
        if (ed && ed.id === 'content') ed.on('change keyup', function () { polskiZmieniony('#content'); });
    });

    $(document).on('click', '.evk-tlw-kopiuj', function () {
        var $p = $(this).closest('.evk-tlw-pole');
        var tekst = polski($p);
        if (!pusty(wartosc($p)) && !window.confirm('Pole tłumaczenia nie jest puste. Zastąpić je polskim tekstem?')) return;
        var $w = $p.find('.evk-tlw-wejscie'), ed = edytor($w.attr('id'));
        if (ed && !ed.isHidden()) { ed.setContent(tekst); ed.fire('change'); } else { $w.val(tekst).trigger('input'); }
        oznaczZmiane($p);
        licz();
    });

    $(document).on('click', '.evk-tlw-sprawdzone', function () {
        var $p = $(this).closest('.evk-tlw-pole');
        $p.find('.evk-tlw-zrodlo').val('teraz');
        $p.find('.evk-tlw-do-sprawdzenia, .evk-tlw-sprawdzone').prop('hidden', true);
        $p.find('.evk-tlw-wejscie').trigger('focus');   // przycisk znika — fokus nie może przepaść
    });

    $(document).on('click', '.evk-tlw-wstaw', function () {
        var $p = $(this).closest('.evk-tlw-pole');
        $p.find('.evk-tlw-wejscie').val(String(this.getAttribute('data-tekst') || '')).trigger('input').trigger('focus');
    });

    /* Edytory języków zapisują się do swoich pól przed wysłaniem formularza. */
    $form.on('submit', function () {
        if (window.tinymce) window.tinymce.triggerSave();
    });

    /* Dodawanie termu idzie AJAX-em, a WordPress czyści potem tylko WIDOCZNE
       pola — pola języka (ukryte w widoku polskim) przeszłyby do następnego
       termu. Pusta nazwa polska w widoku języka: wracamy do polskiego, żeby
       błąd był widać. */
    if ($form.is('#addtag')) {
        $form.on('click', '#submit', function () {
            if (!$.trim(String($('#tag-name').val() || ''))) przelacz('pl');
        });
        $(document).ajaxSuccess(function (e, xhr, o) {
            if (typeof o.data !== 'string' || o.data.indexOf('action=add-tag') === -1) return;
            $form.find('.evk-tlw-wejscie, .evk-tlw-slug').val('');
            $form.find('.evk-tlw-zrodlo').val('');
            $form.find('.evk-tlw-slownik').hide();
            przelacz('pl');
            polskiZmieniony('#tag-name');
            polskiZmieniony('#tag-description');
        });
    }

    licz();
})(jQuery);
