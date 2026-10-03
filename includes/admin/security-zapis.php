<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — zapis podstron opcji `evk_security` (formularze `data-section`)
 * AJAX-em. Wspólny dla Bezpieczeństwa i — od 1.289.0 — Logowania, dokąd
 * przeszedł limit logowań; dołącza go zakładka PO swojej podstronie.
 */
?>
<script>
jQuery(function($) {
    var nonce = '<?php echo esc_js(wp_create_nonce('evk_security_nonce')); ?>';

    $('form[data-section]').on('submit', function(e) {
        e.preventDefault();
        var form    = $(this);
        var section = form.data('section');
        var btn     = form.find('button[type=submit]');
        var saved   = form.find('.evk-sec-saved');

        btn.prop('disabled', true).text('Zapisuję...');

        var data = {
            action: 'evk_save_security_section',
            nonce:   nonce,
            section: section,
            data:    {}
        };

        // Checkboxy niezaznaczone = 0 (domyślnie pomijane przez serialize)
        form.find('input[type=checkbox]').each(function() {
            var name = $(this).attr('name') || '';
            var m = name.match(/\[([^\]]+)\](\[\])?$/);
            if (!m) return;
            var key = m[1];
            if (m[2]) {
                if (!data.data[key]) data.data[key] = [];
                if ($(this).is(':checked')) data.data[key].push($(this).val());
            } else {
                if (!data.data[key]) data.data[key] = 0;
                if ($(this).is(':checked')) data.data[key] = 1;
            }
        });

        // Pola tekstowe, number, textarea
        form.find('input:not([type=checkbox]):not([type=submit]):not([type=button]), textarea, select').each(function() {
            var name = $(this).attr('name') || '';
            var m = name.match(/\[([^\]]+)\]$/);
            if (!m) return;
            data.data[m[1]] = $(this).val();
        });

        $.post(ajaxurl, data, function(res) {
            btn.prop('disabled', false).text('Zapisz');
            if (res.success) {
                saved.stop(true).show().delay(2500).fadeOut();
            } else {
                alert(res.data || 'Błąd zapisu.');
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Zapisz');
            alert('Błąd połączenia.');
        });
    });
});
</script>
