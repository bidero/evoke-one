/* Evoke ONE — tłumaczenia wpisów w klasycznym edytorze (1.252.0).
   Przełącznik języka nad tytułem, drugi edytor treści uruchamiany przy
   pierwszym wejściu w język, „Kopiuj z polskiego", „Sprawdzone", podpowiedź
   ze słownika i liczniki. Moduł: includes/55-translation-posts.php. */
(function ($) {
    'use strict';

    var $form = $('#post');
    var $przel = $('.evk-tlw-przelacznik');
    if (!$form.length || !$przel.length) return;

    var edytory = {};   // id pola treści języka => uruchomiony

    /* Zajawki języków — do pudełka „Zajawka", pod pole polskie. Bez pudełka
       (typ bez zajawki, pudełko usunięte) zostają pod edytorem treści. */
    var $excerpt = $('#excerpt');
    if ($excerpt.length) $('.evk-tlw-zajawka').insertAfter($excerpt.closest('.inside').children().last());

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

    /** Polski tekst pola z formularza WordPressa. */
    function polski(pole) {
        if (pole === 'post_title') return String($('#title').val() || '');
        if (pole === 'post_excerpt') return String($excerpt.val() || '');
        if (pole === 'post_content') {
            var ed = edytor('content');
            return (ed && !ed.isHidden()) ? ed.getContent() : String($('#content').val() || '');
        }
        return '';
    }

    function pusty(html) {
        return $.trim($('<div>').html(String(html || '')).text().replace(/ /g, ' ')) === '';
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
        } else {
            $form.attr('data-evk-tlw', lang);
            uruchomEdytor(lang);
        }
        licz();
    }

    /* Klasa `evk-tl-jezyk` jest wspólna z Evoke FIELDS: kliknięcie w jego
       przełącznik przełącza też pola wpisu, a nasze — grupy FIELDS. */
    $(document).on('click', '.evk-tl-jezyk', function () {
        przelacz(String(this.getAttribute('data-lang') || ''));
    });

    /** Zmienione tłumaczenie przestaje być „Do sprawdzenia" dopiero przy zapisie (skrót z renderu). */
    function oznaczZmiane($pole) {
        if (!$pole.length) return;
        $pole.find('.evk-tlw-slownik').toggle(pusty(wartosc($pole)));
    }

    $(document).on('input change', '.evk-tlw-pole .evk-tlw-wejscie', function () {
        oznaczZmiane($(this).closest('.evk-tlw-pole'));
        licz();
    });

    /* Zmiana polskiego tekstu w tej sesji: tłumaczenia z treścią dostają „Do sprawdzenia". */
    function polskiZmieniony(pole) {
        $('.evk-tlw-pole[data-pole="' + pole + '"]').each(function () {
            var $p = $(this);
            if (pusty(wartosc($p))) return;
            $p.find('.evk-tlw-do-sprawdzenia, .evk-tlw-sprawdzone').prop('hidden', false);
        });
        $('.evk-tlw-pole[data-pole="' + pole + '"] .evk-tlw-wejscie').attr('data-pl', pusty(polski(pole)) ? '0' : '1');
        licz();
    }
    $('#title').on('input', function () { polskiZmieniony('post_title'); });
    $excerpt.on('input', function () { polskiZmieniony('post_excerpt'); });
    $('#content').on('input', function () { polskiZmieniony('post_content'); });
    $(document).on('tinymce-editor-init', function (e, ed) {
        if (ed && ed.id === 'content') ed.on('change keyup', function () { polskiZmieniony('post_content'); });
    });

    $(document).on('click', '.evk-tlw-kopiuj', function () {
        var $p = $(this).closest('.evk-tlw-pole'), pole = $p.attr('data-pole');
        var tekst = polski(pole);
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

    licz();
})(jQuery);
