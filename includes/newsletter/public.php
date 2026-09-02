<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — Publiczny zapis (shortcode + double opt-in + RODO)
 *
 * Shortcode: [evk_newsletter_form list="ID" button="Zapisz się" consent="Wyrażam zgodę..." confirm="1"]
 * - confirm="1" → double opt-in (mail potwierdzający, status pending=2 → 1)
 * - confirm="0" → zapis natychmiastowy (status=1)
 * Zgoda (consent) jest logowana: data, IP, treść w fields_json (_consent_*).
 */

// =========================================================================
// PODPIS PARAMETRÓW FORMULARZA
// =========================================================================

/**
 * Podpis tego, o czym NIE MA decydować przeglądarka.
 *
 * `confirm` i `consent` to atrybuty shortcode'u — decyzja autora strony, czy
 * zapis idzie przez potwierdzenie mailem i pod jaką treścią zgody. Do 1.129.0
 * handler czytał oba wprost z żądania, więc `confirm=0` w POST-cie wpisywało
 * adres na listę OD RAZU JAKO POTWIERDZONY, razem z wpisem zgody zbudowanym
 * z tego, co przysłał wysyłający. Dowód zgody, którego treścią sterował
 * składający żądanie, nie jest dowodem — to problem nie tylko techniczny.
 *
 * Podpis jak w śledzeniu newslettera i w ciasteczku konserwacji: HMAC na
 * `wp_salt('auth')`, sprawdzany przez `hash_equals()`.
 *
 * Treść zgody normalizujemy TUTAJ, tą samą funkcją, którą handler wywoła na
 * przysłanej wartości. Inaczej podpis liczony przy renderowaniu i podpis
 * liczony przy odbiorze rozjeżdżałyby się na pierwszym znaku, który
 * `sanitize_text_field()` zmienia.
 */
function evk_nl_form_sig(int $list_id, string $confirm, string $consent): string {
    return hash_hmac(
        'sha256',
        $list_id . '|' . $confirm . '|' . sanitize_text_field($consent),
        wp_salt('auth')
    );
}

// =========================================================================
// SHORTCODE
// =========================================================================

add_shortcode('evk_newsletter_form', 'evk_nl_subscribe_shortcode');

function evk_nl_subscribe_shortcode($atts): string {
    $a = shortcode_atts([
        'list'        => 0,
        'button'      => 'Zapisz się',
        'placeholder' => 'Twój adres e-mail',
        'consent'     => '',
        'confirm'     => '1',
        'success'     => '',
        'class'        => '',
        'input_class'  => '',
        'button_class' => '',
        'styles'       => '',
    ], $atts, 'evk_newsletter_form');

    $list_id = (int) $a['list'];
    if (!$list_id) return '';
    $opts = get_option('evk_newsletter', []);
    if (empty($opts['enabled'])) return '';

    $uid     = 'evknl' . wp_rand(1000, 9999);
    $nonce   = wp_create_nonce('evk_nl_public');
    $ajax    = esc_url(admin_url('admin-ajax.php'));
    $consent = trim($a['consent']);
    $confirm = ($a['confirm'] === '1') ? '1' : '0';

    $ap          = evk_nl_appearance();
    $use_styles  = ($a['styles'] === '0') ? false : $ap['default_styles'];
    $wrap_cls    = trim('evk-nl-widget ' . $ap['wrap'] . ' ' . evk_nl_sanitize_classes($a['class']));
    $input_cls   = trim('evk-nl-email ' . $ap['input'] . ' ' . evk_nl_sanitize_classes($a['input_class']));
    $btn_cls     = trim('evk-nl-btn ' . $ap['button'] . ' ' . evk_nl_sanitize_classes($a['button_class']));
    $consent_cls = trim('evk-nl-consent ' . $ap['consent']);

    if ($a['success'] !== '') {
        $success = $a['success'];
    } elseif ($confirm === '1') {
        $success = evk_nl_text('form_pending');
    } else {
        $success = evk_nl_text('form_success');
    }

    $consent_html = '';
    if ($consent !== '') {
        $consent_html = '<label class="' . esc_attr($consent_cls) . '"><input type="checkbox" class="evk-nl-ok"> <span>' . esc_html($consent) . '</span></label>';
    }

    ob_start();
    ?>
<div class="<?php echo esc_attr($wrap_cls); ?>" id="<?php echo esc_attr($uid); ?>">
    <div class="evk-nl-row">
        <input type="email" class="<?php echo esc_attr($input_cls); ?>" placeholder="<?php echo esc_attr($a['placeholder']); ?>" autocomplete="email">
        <button type="button" class="<?php echo esc_attr($btn_cls); ?>"><?php echo esc_html($a['button']); ?></button>
    </div>
    <?php echo $consent_html; ?>
    <input type="text" class="evk-nl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
    <div class="evk-nl-msg" role="status" aria-live="polite"></div>
</div>
<?php if ($use_styles): ?>
<style>
#<?php echo $uid; ?>{max-width:480px;font-family:inherit;}
#<?php echo $uid; ?> .evk-nl-row{display:flex;gap:8px;flex-wrap:wrap;}
#<?php echo $uid; ?> .evk-nl-email{flex:1;min-width:180px;padding:11px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;}
#<?php echo $uid; ?> .evk-nl-btn{padding:11px 22px;background:#2563eb;color:#fff;border:0;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;}
#<?php echo $uid; ?> .evk-nl-btn:hover{background:#1d4ed8;}
#<?php echo $uid; ?> .evk-nl-btn:disabled{opacity:.6;cursor:default;}
#<?php echo $uid; ?> .evk-nl-consent{display:flex;align-items:flex-start;gap:8px;margin-top:10px;font-size:13px;color:#475569;line-height:1.4;cursor:pointer;}
#<?php echo $uid; ?> .evk-nl-consent input{margin-top:2px;}
#<?php echo $uid; ?> .evk-nl-msg{margin-top:10px;font-size:14px;display:none;}
#<?php echo $uid; ?> .evk-nl-msg.ok{display:block;color:#16a34a;}
#<?php echo $uid; ?> .evk-nl-msg.err{display:block;color:#dc2626;}
</style>
<?php endif; ?>
<script>
(function(){
    var w=document.getElementById(<?php echo wp_json_encode($uid); ?>);
    if(!w||w.dataset.bound)return; w.dataset.bound='1';
    var btn=w.querySelector('.evk-nl-btn'),email=w.querySelector('.evk-nl-email'),
        msg=w.querySelector('.evk-nl-msg'),hp=w.querySelector('.evk-nl-hp'),
        ok=w.querySelector('.evk-nl-ok');
    function show(t,cls){msg.textContent=t;msg.className='evk-nl-msg '+cls;}
    function submit(){
        show('','');
        var fd=new FormData();
        fd.append('action','evk_nl_subscribe');
        fd.append('nonce',<?php echo wp_json_encode($nonce); ?>);
        fd.append('list',<?php echo (int) $list_id; ?>);
        fd.append('confirm',<?php echo wp_json_encode($confirm); ?>);
        fd.append('consent',<?php echo wp_json_encode($consent); ?>);
        fd.append('sig',<?php echo wp_json_encode(evk_nl_form_sig($list_id, $confirm, $consent)); ?>);
        fd.append('email',email.value);
        fd.append('evk_nl_hp',hp.value);
        if(ok)fd.append('consent_ok',ok.checked?'1':'');
        btn.disabled=true;
        fetch(<?php echo wp_json_encode($ajax); ?>,{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(d){
            btn.disabled=false;
            if(d&&d.success){show((d.data&&d.data.msg)||<?php echo wp_json_encode($success); ?>,'ok');email.value='';if(ok)ok.checked=false;}
            else{show((d&&d.data&&d.data.msg)||'Wystąpił błąd. Spróbuj ponownie.','err');}
        })
        .catch(function(){btn.disabled=false;show('Wystąpił błąd połączenia.','err');});
    }
    btn.addEventListener('click',submit);
    email.addEventListener('keydown',function(e){if(e.key==='Enter')submit();});
})();
</script>
    <?php
    return ob_get_clean();
}

// =========================================================================
// AJAX — zapis (priv + nopriv)
// =========================================================================

add_action('wp_ajax_evk_nl_subscribe', 'evk_nl_handle_public_subscribe');
add_action('wp_ajax_nopriv_evk_nl_subscribe', 'evk_nl_handle_public_subscribe');

function evk_nl_handle_public_subscribe(): void {
    if (!check_ajax_referer('evk_nl_public', 'nonce', false)) {
        wp_send_json_error(['msg' => 'Nieprawidłowy token. Odśwież stronę i spróbuj ponownie.']);
    }
    $opts = get_option('evk_newsletter', []);
    if (empty($opts['enabled'])) wp_send_json_error(['msg' => 'Zapisy są wyłączone.']);

    // Honeypot — bot wypełnił ukryte pole → udajemy sukces, nic nie zapisujemy
    if (!empty($_POST['evk_nl_hp'])) wp_send_json_success(['msg' => evk_nl_text('form_success')]);

    $list_id         = (int) ($_POST['list'] ?? 0);
    $email           = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $confirm_raw     = (string) ($_POST['confirm'] ?? '1');
    $consent_text    = sanitize_text_field(wp_unslash($_POST['consent'] ?? ''));
    $consent_checked = !empty($_POST['consent_ok']);
    $sig             = sanitize_text_field(wp_unslash($_POST['sig'] ?? ''));

    /* O double opt-in i o treści zgody decyduje shortcode, nie żądanie.
     *
     * BRAK PODPISU = STARA, ZBUFOROWANA STRONA. Formularz siedzi w treści
     * podstrony, więc po aktualizacji wtyczki krąży jeszcze w pamięciach
     * podręcznych. Odrzucanie takich zgłoszeń zepsułoby zapisy na czas
     * ważności cache'u, więc zamiast tego wymuszamy wariant bezpieczny:
     * potwierdzenie mailem. Nikt nie przeskoczy double opt-in, a stary
     * formularz dalej działa.
     *
     * Podpis, KTÓRY JUŻ JEST, musi się zgadzać — tu pobłażania nie ma. */
    if ($sig === '') {
        $confirm = true;
    } elseif (hash_equals(evk_nl_form_sig($list_id, $confirm_raw, $consent_text), $sig)) {
        $confirm = ($confirm_raw === '1');
    } else {
        wp_send_json_error(['msg' => 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.']);
    }

    if (!$list_id || !evk_nl_get_list($list_id)) wp_send_json_error(['msg' => 'Nieprawidłowa lista.']);
    if (!is_email($email))                        wp_send_json_error(['msg' => 'Podaj poprawny adres e-mail.']);
    if ($consent_text !== '' && !$consent_checked) wp_send_json_error(['msg' => 'Zaznacz wymaganą zgodę.']);

    // Rate limit per IP — max 10/godz.
    $ip  = evk_nl_client_ip();
    $key = 'evk_nl_rl_' . md5($ip);
    $cnt = (int) get_transient($key);
    if ($cnt >= 10) wp_send_json_error(['msg' => 'Zbyt wiele prób. Spróbuj ponownie później.']);
    set_transient($key, $cnt + 1, HOUR_IN_SECONDS);

    $consent = [
        '_consent_at'   => current_time('mysql'),
        '_consent_ip'   => $ip,
        '_consent_text' => $consent_text,
    ];

    if ($confirm) {
        $res = evk_nl_add_pending_subscriber($list_id, $email, $consent);
        if (empty($res['ok'])) wp_send_json_error(['msg' => 'Nie udało się zapisać. Spróbuj ponownie.']);
        // Wyślij mail potwierdzający tylko gdy faktycznie oczekuje na potwierdzenie
        if ((int) ($res['status'] ?? 0) === 2 && !empty($res['token'])) {
            $list = evk_nl_get_list($list_id);
            evk_nl_send_confirm_email($email, $res['token'], $list['name'] ?? '');
            wp_send_json_success(['msg' => evk_nl_text('form_pending')]);
        }
        // Już aktywny
        wp_send_json_success(['msg' => evk_nl_text('form_already')]);
    }

    // Bez double opt-in — zapis natychmiastowy
    $consent['_confirmed_at'] = current_time('mysql');
    $id = evk_nl_add_subscriber($list_id, $email, $consent);
    $id ? wp_send_json_success(['msg' => evk_nl_text('form_success')])
        : wp_send_json_error(['msg' => 'Nie udało się zapisać.']);
}

function evk_nl_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

// =========================================================================
// MAIL POTWIERDZAJĄCY (double opt-in)
// =========================================================================

function evk_nl_send_confirm_email(string $email, string $token, string $list_name) {
    if (!evk_nl_smtp_is_configured()) return false;

    $url   = evk_nl_confirm_url($token);
    $site  = get_bloginfo('name');
    $listr = $list_name ? ' do listy „' . esc_html($list_name) . '"' : '';

    $subject = evk_nl_text('confirm_subject', ['site' => $site]);
    $heading = esc_html(evk_nl_text('confirm_email_heading', ['site' => esc_html($site)]));
    $text    = evk_nl_text('confirm_email_text', ['site' => esc_html($site), 'list' => $listr]);
    $button  = esc_html(evk_nl_text('confirm_email_button'));

    $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1e293b;">'
          . '<h2 style="margin:0 0 12px;">' . $heading . '</h2>'
          . '<p style="color:#475569;line-height:1.6;margin:0 0 20px;">' . $text . '</p>'
          . '<p style="margin:0 0 24px;"><a href="' . esc_url($url) . '" style="display:inline-block;background:#2563eb;color:#fff;padding:13px 30px;border-radius:8px;text-decoration:none;font-weight:600;">' . $button . '</a></p>'
          . '<p style="color:#94a3b8;font-size:12px;line-height:1.5;margin:0;">Jeśli to nie Ty zapisywałeś się do newslettera, zignoruj tę wiadomość — nic się nie stanie.</p>'
          . '</div>';

    return evk_nl_phpmailer_send([
        'to'      => $email,
        'subject' => $subject,
        'body'    => $body,
        'smtp'    => evk_smtp_get(),
    ]);
}
