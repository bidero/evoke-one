<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — Subtab: Ustawienia (teksty + wyglad formularza)
 */

$o   = get_option('evk_newsletter', []);
$def = evk_nl_text_defaults();
$ap  = evk_nl_appearance();
$val = function (string $k) use ($o, $def) {
    return isset($o[$k]) && trim((string) $o[$k]) !== '' ? (string) $o[$k] : ($def[$k] ?? '');
};

$first_list = function_exists('evk_nl_get_lists') ? (evk_nl_get_lists()[0] ?? null) : null;
$example_id = $first_list['id'] ?? 1;
?>

<?php /* Potwierdzenie zapisu stoi przy przycisku, jak w całym panelu (1.167.0).
         Czarne powiadomienie u góry było tu jedynym śladem po zapisie i mówiło
         to samo, tylko gdzie indziej i w innym kolorze. */ ?>

<form method="post" action="">
    <?php wp_nonce_field('evk_nl_settings', 'evk_nl_settings_nonce'); ?>
    <input type="hidden" name="evk_nl_action" value="save_settings">

    <!-- ── WYGLĄD FORMULARZA (shortcode) ────────────────────────────── -->
    <div class="evo-box">
        <h3>Wygląd formularza zapisu</h3>
        <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
                Wstaw formularz shortcodem:
                <code>[evk_newsletter_form list="<?php echo (int) $example_id; ?>" consent="Wyrażam zgodę..."]</code>.
                Możesz podać własne klasy poniżej (dopisywane do elementów) i wyłączyć domyślne style, aby formularz przejął wygląd z Twojego motywu.
                Atrybuty shortcode mają priorytet: <code>class</code>, <code>input_class</code>, <code>button_class</code>, <code>styles="0"</code>.
            </div></details>

        <label class="checkbox-label evo-mb" style="font-size:14px">
            <input type="checkbox" name="form_default_styles" value="1" <?php checked($ap['default_styles']); ?>>
            <span>Używaj domyślnych stylów Evoke ONE (odznacz, jeśli stylujesz własnymi klasami)</span>
        </label>

        <div class="evk-nl-grid2 evo-mb-xs" style="--evo-gap:14px">
            <div class="evo-field" style="margin:0;">
                <label for="evo-f-form_wrap_class">Klasy kontenera</label>
                <input id="evo-f-form_wrap_class" type="text" name="form_wrap_class" value="<?php echo esc_attr($ap['wrap']); ?>" placeholder="np. my-form">
            </div>
            <div class="evo-field" style="margin:0;">
                <label for="evo-f-form_input_class">Klasy pola e-mail</label>
                <input id="evo-f-form_input_class" type="text" name="form_input_class" value="<?php echo esc_attr($ap['input']); ?>" placeholder="np. form-control">
            </div>
            <div class="evo-field" style="margin:0;">
                <label for="evo-f-form_button_class">Klasy przycisku</label>
                <input id="evo-f-form_button_class" type="text" name="form_button_class" value="<?php echo esc_attr($ap['button']); ?>" placeholder="np. btn btn-primary">
            </div>
            <div class="evo-field" style="margin:0;">
                <label for="evo-f-form_consent_class">Klasy zgody (checkbox)</label>
                <input id="evo-f-form_consent_class" type="text" name="form_consent_class" value="<?php echo esc_attr($ap['consent']); ?>" placeholder="np. form-check">
            </div>
        </div>

    </div>

    <!-- ── LIST-UNSUBSCRIBE ─────────────────────────────────────────── -->
    <div class="evo-box">
        <h3>List-Unsubscribe (pasek „wypisz" w kliencie)</h3>
        <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
                Pasek wypisu działa już dzięki linkowi <code>https</code> (one-click Gmail/Apple). Opcjonalnie możesz dodać adres <code>mailto</code> dla starszych klientów —
                <strong>wymaga skrzynki, którą monitorujesz</strong> i ręcznie/automatycznie obsługujesz prośby o wypis. Puste = tylko https (zalecane, jeśli nie masz takiej skrzynki).
            </div></details>
        <div class="evo-field" style="margin-bottom:8px;">
            <label for="evo-f-unsub_mailto">Adres mailto do wypisu (opcjonalnie)</label>
            <input id="evo-f-unsub_mailto" type="email" name="unsub_mailto" value="<?php echo esc_attr($o['unsub_mailto'] ?? ''); ?>" placeholder="np. newsletter@twojadomena.pl" class="evo-w-xl">
        </div>

    </div>

    <!-- ── TEKSTY ───────────────────────────────────────────────────── -->
    <div class="evo-box">
        <h3>Teksty komunikatów</h3>
        <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">W tekstach możesz użyć <code>{email}</code> — zostanie podmieniony na adres subskrybenta. W opisach (nie tytułach) dozwolone proste tagi: <code>&lt;strong&gt; &lt;em&gt; &lt;br&gt; &lt;a&gt;</code>.</div></details>

        <p class="evk-nl-sub">Formularz zapisu (komunikaty pod formularzem)</p>
        <div class="evo-field"><label for="evo-f-form_success">Sukces (zapis natychmiastowy)</label>
            <input id="evo-f-form_success" type="text" name="form_success" value="<?php echo esc_attr($val('form_success')); ?>"></div>
        <div class="evo-field"><label for="evo-f-form_pending">Oczekuje na potwierdzenie (double opt-in)</label>
            <input id="evo-f-form_pending" type="text" name="form_pending" value="<?php echo esc_attr($val('form_pending')); ?>"></div>
        <div class="evo-field"><label for="evo-f-form_already">Adres już zapisany</label>
            <input id="evo-f-form_already" type="text" name="form_already" value="<?php echo esc_attr($val('form_already')); ?>"></div>

        <p class="evk-nl-sub">Strona potwierdzenia zapisu</p>
        <div class="evk-nl-pair">
            <div class="evo-field" style="margin:0;"><label for="evo-f-confirm_ok_title">Tytuł (OK)</label>
                <input id="evo-f-confirm_ok_title" type="text" name="confirm_ok_title" value="<?php echo esc_attr($val('confirm_ok_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label for="evo-f-confirm_ok_msg">Treść (OK)</label>
                <input id="evo-f-confirm_ok_msg" type="text" name="confirm_ok_msg" value="<?php echo esc_attr($val('confirm_ok_msg')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label for="evo-f-confirm_bad_title">Tytuł (błąd)</label>
                <input id="evo-f-confirm_bad_title" type="text" name="confirm_bad_title" value="<?php echo esc_attr($val('confirm_bad_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label for="evo-f-confirm_bad_msg">Treść (błąd)</label>
                <input id="evo-f-confirm_bad_msg" type="text" name="confirm_bad_msg" value="<?php echo esc_attr($val('confirm_bad_msg')); ?>"></div>
        </div>

        <p class="evk-nl-sub">Wypisanie — pytanie potwierdzające</p>
        <div class="evk-nl-pair">
            <div class="evo-field" style="margin:0;"><label for="evo-f-unsub_confirm_title">Tytuł</label>
                <input id="evo-f-unsub_confirm_title" type="text" name="unsub_confirm_title" value="<?php echo esc_attr($val('unsub_confirm_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label for="evo-f-unsub_confirm_msg">Treść</label>
                <input id="evo-f-unsub_confirm_msg" type="text" name="unsub_confirm_msg" value="<?php echo esc_attr($val('unsub_confirm_msg')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label for="evo-f-unsub_confirm_btn">Tekst przycisku</label>
                <input id="evo-f-unsub_confirm_btn" type="text" name="unsub_confirm_btn" value="<?php echo esc_attr($val('unsub_confirm_btn')); ?>"></div>
        </div>

        <p class="evk-nl-sub">Wypisanie — wynik</p>
        <div class="evk-nl-pair">
            <div class="evo-field" style="margin:0;"><label for="evo-f-unsub_ok_title">Tytuł (OK)</label>
                <input id="evo-f-unsub_ok_title" type="text" name="unsub_ok_title" value="<?php echo esc_attr($val('unsub_ok_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label for="evo-f-unsub_ok_msg">Treść (OK)</label>
                <input id="evo-f-unsub_ok_msg" type="text" name="unsub_ok_msg" value="<?php echo esc_attr($val('unsub_ok_msg')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label for="evo-f-unsub_bad_title">Tytuł (błąd)</label>
                <input id="evo-f-unsub_bad_title" type="text" name="unsub_bad_title" value="<?php echo esc_attr($val('unsub_bad_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label for="evo-f-unsub_bad_msg">Treść (błąd)</label>
                <input id="evo-f-unsub_bad_msg" type="text" name="unsub_bad_msg" value="<?php echo esc_attr($val('unsub_bad_msg')); ?>"></div>
        </div>

    </div>

    <div class="evo-box">
        <h3>E-mail potwierdzający zapis (double opt-in)</h3>
        <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Placeholdery: <code>{site}</code> (nazwa witryny), <code>{list}</code> (np. „ do listy „Aktualności""). W treści dozwolone proste tagi.</div></details>
        <div class="evo-field"><label for="evo-f-confirm_subject">Temat wiadomości</label>
            <input id="evo-f-confirm_subject" type="text" name="confirm_subject" value="<?php echo esc_attr($val('confirm_subject')); ?>"></div>
        <div class="evo-field"><label for="evo-f-confirm_email_heading">Nagłówek</label>
            <input id="evo-f-confirm_email_heading" type="text" name="confirm_email_heading" value="<?php echo esc_attr($val('confirm_email_heading')); ?>"></div>
        <div class="evo-field"><label for="evo-f-confirm_email_text">Treść</label>
            <input id="evo-f-confirm_email_text" type="text" name="confirm_email_text" value="<?php echo esc_attr($val('confirm_email_text')); ?>"></div>
        <div class="evo-field"><label for="evo-f-confirm_email_button">Tekst przycisku</label>
            <input id="evo-f-confirm_email_button" type="text" name="confirm_email_button" value="<?php echo esc_attr($val('confirm_email_button')); ?>"></div>

    </div>

    <div class="evo-save-bar">
        <button type="submit" class="button button-primary">Zapisz ustawienia</button>
        <?php evoke_one_komunikat_zapisu(!empty($_GET['nl_saved'])); ?>
    </div>
</form>
