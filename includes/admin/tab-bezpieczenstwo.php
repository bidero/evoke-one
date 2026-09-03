<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Bezpieczeństwo
 */

$sub      = sanitize_key($_GET['sub'] ?? 'login');

/* Lista mieszka w evoke_one_ekrany() (includes/admin/helpers.php), bo
   czytają ją także pasek boczny i wyszukiwarka — patrz komentarz tam. */
$subs = evoke_one_ekrany()['bezpieczenstwo'];

if (!array_key_exists($sub, $subs)) $sub = 'login';

/* Paska podzakładek tu nie ma od 1.139.1. Wypisywał ekrany tej sekcji nad
   treścią, a od 1.138.0 pasek boczny pokazuje dokładnie tę samą listę — te
   same pozycje i te same adresy, obie z `evoke_one_ekrany()`. Dwa identyczne
   spisy jeden pod drugim czytało się jak dwa poziomy nawigacji, którymi nie
   były. `$subs` zostaje: rozstrzyga, czy `?sub=` z adresu istnieje. */

$evk_sec   = evk_security_get();
$sec_nonce = wp_create_nonce('evk_security_nonce');

$sub_file = EVOKE_ONE_DIR . 'includes/admin/security-' . $sub . '.php';
if (file_exists($sub_file)) {
    require $sub_file;
}

// Globalny JS dla AJAX save — po załadowaniu subtaba
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
