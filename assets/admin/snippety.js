/**
 * Evoke ONE — ekran snippetów: edytor kodu i historia zmian.
 *
 * Dwie rzeczy, bo są jedną: przywracanie wersji musi trafić do CodeMirrora,
 * a nie do pola tekstowego pod nim. Podmiana `value` w `<textarea>` nie dociera
 * do edytora — CodeMirror trzyma własną kopię treści i przepisuje pole dopiero
 * przy wysyłce formularza. Dlatego instancja powstaje tutaj i tutaj zostaje.
 */
(function ($) {
    'use strict';

    var edytorKodu = null;   // instancja CodeMirror pola #evk-kod

    function ustawKod(tresc) {
        if (edytorKodu && edytorKodu.codemirror) {
            edytorKodu.codemirror.setValue(tresc);
            edytorKodu.codemirror.focus();
            return true;
        }
        /* Bez CodeMirrora (wyłączony w profilu użytkownika albo niezaładowany)
           zostaje samo pole — i to jest w porządku, bo formularz i tak wysyła
           jego zawartość. */
        var pole = document.getElementById('evk-kod');
        if (!pole) return false;
        pole.value = tresc;
        pole.focus();
        return true;
    }

    $(function () {
        var cfg = window.evkSnippety || {};

        // ── Edytor kodu ────────────────────────────────────────────────────
        if (cfg.cm && window.wp && wp.codeEditor) {
            $('#evk-kod, #evk_advanced_code').each(function () {
                var inst = wp.codeEditor.initialize(this, cfg.cm);
                if (this.id === 'evk-kod') edytorKodu = inst;
            });
        }

        // ── Historia zmian ─────────────────────────────────────────────────
        var $box = $('.evo-wersje');
        if (!$box.length) return;

        var $widok = $box.find('.evo-wersja-widok');

        /** Pobiera wersję i oddaje ją funkcji `co` — jedno żądanie na oba przyciski. */
        function pobierz($wiersz, co) {
            var id = $wiersz.data('wersja');
            $.post(cfg.ajaxurl, {
                action: 'evk_get_snippet_revision',
                revision_id: id,
                nonce: $box.data('nonce')
            }).done(function (odp) {
                if (!odp || !odp.success) {
                    $widok.html('<p class="evo-hint">Nie udało się wczytać tej wersji.</p>')
                          .prop('hidden', false);
                    return;
                }
                co(odp.data);
            }).fail(function () {
                $widok.html('<p class="evo-hint">Nie udało się wczytać tej wersji.</p>')
                      .prop('hidden', false);
            });
        }

        $box.on('click', '.evo-wersja-podglad', function () {
            var $wiersz = $(this).closest('tr');
            $box.find('tr').removeClass('is-otwarta');
            $wiersz.addClass('is-otwarta');
            $widok.html('<p class="evo-hint">Wczytywanie…</p>').prop('hidden', false);
            pobierz($wiersz, function (dane) {
                $widok.html(dane.diff || '').prop('hidden', false);
            });
        });

        $box.on('click', '.evo-wersja-przywroc', function () {
            pobierz($(this).closest('tr'), function (dane) {
                if (!ustawKod(dane.content || '')) return;
                /* Mówimy WPROST, że na stronie nic się jeszcze nie zmieniło.
                   Bez tego zdania przywrócenie wygląda na zapisane — pole ma
                   nową treść, a strona starą. */
                $widok.html('<p class="evo-wersja-info">Treść wstawiona do pola kodu. '
                          + 'Na stronie zacznie działać po kliknięciu „Zapisz snippet".</p>')
                      .prop('hidden', false);
                var pole = document.getElementById('evk-kod');
                if (pole && pole.scrollIntoView) pole.scrollIntoView({ block: 'center' });
            });
        });
    });
})(jQuery);
