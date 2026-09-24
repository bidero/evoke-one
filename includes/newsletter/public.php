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

/**
 * BEZ NONCE (1.233.0). Formularz stoi na publicznych stronach, a te siedzą
 * w pełnym cache HTML: nonce w zbuforowanej stronie wygasa po 12–24 godzinach
 * i od tej chwili KAŻDY zapis kończył się „Nieprawidłowy token" — do
 * wyczyszczenia pamięci podręcznej. Dla niezalogowanych nonce niczego nie
 * chroni (każdy dostaje ważny, otwierając stronę), a zapis nie działa w imieniu
 * zalogowanego: adres przychodzi w żądaniu. Zostaje to, co naprawdę pilnuje:
 * podpis parametrów shortcode'u, honeypot, limit na adres IP i limit maili
 * z potwierdzeniem na jeden adres e-mail.
 */
function evk_nl_handle_public_subscribe(): void {
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

    if ($consent_text !== '' && !$consent_checked) wp_send_json_error(['msg' => 'Zaznacz wymaganą zgodę.']);

    $wynik = evk_nl_zapisz_z_formularza($list_id, $email, [], $consent_text, $confirm, 'formularz na stronie');
    $wynik['ok'] ? wp_send_json_success(['msg' => $wynik['msg']]) : wp_send_json_error(['msg' => $wynik['msg']]);
}

/** Ile maili z potwierdzeniem dostaje jeden adres na dobę. */
const EVK_NL_POTWIERDZEN_NA_DOBE = 3;

/**
 * Zapis osoby z formularza na stronie — wspólny dla shortcode'u i akcji
 * formularza Bricksa (includes/newsletter/bricks.php), żeby obie drogi miały
 * te same bezpieczniki i ten sam zapis zgody.
 *
 * `$pola`: pola do znaczników ({imie}); `$zgoda`: treść zgody, którą osoba
 * widziała; `$zrodlo`: skąd przyszedł zapis — idzie do zapisu zgody obok daty
 * i adresu IP. Zwraca ['ok' => bool, 'stan' => oczekuje|zapisany|juz_jest|blad,
 * 'msg' => komunikat dla osoby].
 */
function evk_nl_zapisz_z_formularza(int $list_id, string $email, array $pola, string $zgoda, bool $potwierdzenie, string $zrodlo): array {
    $blad = static function (string $msg): array { return ['ok' => false, 'stan' => 'blad', 'msg' => $msg]; };
    $lista = $list_id ? evk_nl_get_list($list_id) : null;
    if (!$lista)           return $blad('Nieprawidłowa lista.');
    if (!is_email($email)) return $blad('Podaj poprawny adres e-mail.');

    // Limit na adres IP — max 10/godz.
    $ip  = evk_nl_client_ip();
    $key = 'evk_nl_rl_' . md5($ip);
    $cnt = (int) get_transient($key);
    if ($cnt >= 10) return $blad('Zbyt wiele prób. Spróbuj ponownie później.');
    set_transient($key, $cnt + 1, HOUR_IN_SECONDS);

    $dane = $pola + [
        '_consent_at'   => current_time('mysql'),
        '_consent_ip'   => $ip,
        '_consent_text' => $zgoda,
    ];
    if ($zrodlo !== '') $dane['_consent_source'] = $zrodlo;

    if ($potwierdzenie) {
        $res = evk_nl_add_pending_subscriber($list_id, $email, $dane);
        if (empty($res['ok'])) return $blad('Nie udało się zapisać. Spróbuj ponownie.');
        if ((int) ($res['status'] ?? 0) !== 2 || empty($res['token'])) {
            return ['ok' => true, 'stan' => 'juz_jest', 'msg' => evk_nl_text('form_already')];
        }
        /* Limit maili z potwierdzeniem na JEDEN ADRES. Limit na IP nie chroni
           cudzej skrzynki: z wielu adresów IP dałoby się zasypać kogoś mailami
           „potwierdź zapis" — a każdy taki mail psuje reputację nadawcy tej
           strony. Po limicie odpowiedź jest ta sama, co po wysłaniu, żeby nie
           podpowiadać, co się stało. */
        $klucz_potw = 'evk_nl_pt_' . md5(strtolower($email));
        $wyslane    = (int) get_transient($klucz_potw);
        if ($wyslane >= EVK_NL_POTWIERDZEN_NA_DOBE) {
            return ['ok' => true, 'stan' => 'oczekuje', 'msg' => evk_nl_text('form_pending')];
        }
        /* Porażka wysyłki to BŁĄD dla osoby, nie „sprawdź skrzynkę". Do 1.232.0
           wynik wysyłki był ignorowany: przy niedziałającej poczcie formularz
           mówił „sprawdź skrzynkę", a mail nigdy nie wychodził. */
        if (evk_nl_send_confirm_email($email, (string) $res['token'], (string) ($lista['name'] ?? '')) !== true) {
            return $blad('Nie udało się wysłać wiadomości z potwierdzeniem. Spróbuj ponownie później.');
        }
        set_transient($klucz_potw, $wyslane + 1, DAY_IN_SECONDS);
        return ['ok' => true, 'stan' => 'oczekuje', 'msg' => evk_nl_text('form_pending')];
    }

    // Bez double opt-in — zapis natychmiastowy
    $dane['_confirmed_at'] = current_time('mysql');
    $id = evk_nl_add_subscriber($list_id, $email, $dane);
    return $id ? ['ok' => true, 'stan' => 'zapisany', 'msg' => evk_nl_text('form_success')]
               : $blad('Nie udało się zapisać.');
}

/* Adres z zapisu zgody i klucz limitu — za Cloudflare adres osoby, nie węzła
   (includes/security/ip-klienta.php). */
function evk_nl_client_ip(): string {
    return evk_ip_klienta() ?: '0.0.0.0';
}

// =========================================================================
// MAIL POTWIERDZAJĄCY (double opt-in)
// =========================================================================

/**
 * Mail z linkiem potwierdzającym. Zwraca true albo WP_Error — wynik czyta
 * evk_nl_zapisz_z_formularza(). Idzie przez wp_mail() (includes/newsletter/
 * mailer.php), więc nie wymaga już SMTP Evoke. Do 1.232.0 bez niego funkcja
 * kończyła się na pierwszej linii, a formularz i tak odpowiadał „sprawdź
 * skrzynkę".
 */
function evk_nl_send_confirm_email(string $email, string $token, string $list_name) {
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

    return evk_nl_wyslij([
        'to'      => $email,
        'subject' => $subject,
        'body'    => $body,
    ]);
}
