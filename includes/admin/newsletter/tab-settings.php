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

<?php if (!empty($_GET['nl_saved'])): ?>
<div class="notice notice-success inline" style="margin:0 0 16px;"><p>Ustawienia zapisane.</p></div>
<?php endif; ?>

<form method="post" action="">
    <?php wp_nonce_field('evk_nl_settings', 'evk_nl_settings_nonce'); ?>
    <input type="hidden" name="evk_nl_action" value="save_settings">

    <!-- ── WYGLĄD FORMULARZA (shortcode) ────────────────────────────── -->
    <div class="evo-box">
        <h3>Wygląd formularza zapisu</h3>
        <div class="evo-info-box" style="margin-bottom:14px;">
            <span class="dashicons dashicons-info"></span>
            <div>
                Wstaw formularz shortcodem:
                <code>[evk_newsletter_form list="<?php echo (int) $example_id; ?>" consent="Wyrażam zgodę..."]</code>.
                Możesz podać własne klasy poniżej (dopisywane do elementów) i wyłączyć domyślne style, aby formularz przejął wygląd z Twojego motywu.
                Atrybuty shortcode mają priorytet: <code>class</code>, <code>input_class</code>, <code>button_class</code>, <code>styles="0"</code>.
            </div>
        </div>

        <label style="display:flex;align-items:center;gap:10px;font-size:14px;cursor:pointer;margin-bottom:16px;">
            <input type="checkbox" name="form_default_styles" value="1" <?php checked($ap['default_styles']); ?>>
            <span>Używaj domyślnych stylów Evoke ONE (odznacz, jeśli stylujesz własnymi klasami)</span>
        </label>

        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:8px;">
            <div class="evo-field" style="margin:0;">
                <label>Klasy kontenera</label>
                <input type="text" name="form_wrap_class" value="<?php echo esc_attr($ap['wrap']); ?>" placeholder="np. my-form">
            </div>
            <div class="evo-field" style="margin:0;">
                <label>Klasy pola e-mail</label>
                <input type="text" name="form_input_class" value="<?php echo esc_attr($ap['input']); ?>" placeholder="np. form-control">
            </div>
            <div class="evo-field" style="margin:0;">
                <label>Klasy przycisku</label>
                <input type="text" name="form_button_class" value="<?php echo esc_attr($ap['button']); ?>" placeholder="np. btn btn-primary">
            </div>
            <div class="evo-field" style="margin:0;">
                <label>Klasy zgody (checkbox)</label>
                <input type="text" name="form_consent_class" value="<?php echo esc_attr($ap['consent']); ?>" placeholder="np. form-check">
            </div>
        </div>

    </div>

    <!-- ── LIST-UNSUBSCRIBE ─────────────────────────────────────────── -->
    <div class="evo-box">
        <h3>List-Unsubscribe (pasek „wypisz" w kliencie)</h3>
        <div class="evo-info-box" style="margin-bottom:14px;">
            <span class="dashicons dashicons-info"></span>
            <div>
                Pasek wypisu działa już dzięki linkowi <code>https</code> (one-click Gmail/Apple). Opcjonalnie możesz dodać adres <code>mailto</code> dla starszych klientów —
                <strong>wymaga skrzynki, którą monitorujesz</strong> i ręcznie/automatycznie obsługujesz prośby o wypis. Puste = tylko https (zalecane, jeśli nie masz takiej skrzynki).
            </div>
        </div>
        <div class="evo-field" style="margin-bottom:8px;">
            <label>Adres mailto do wypisu (opcjonalnie)</label>
            <input type="email" name="unsub_mailto" value="<?php echo esc_attr($o['unsub_mailto'] ?? ''); ?>" placeholder="np. newsletter@twojadomena.pl" style="max-width:340px;">
        </div>

    </div>

    <!-- ── TEKSTY ───────────────────────────────────────────────────── -->
    <div class="evo-box">
        <h3>Teksty komunikatów</h3>
        <div class="evo-info-box" style="margin-bottom:14px;">
            <span class="dashicons dashicons-info"></span>
            <div>W tekstach możesz użyć <code>{email}</code> — zostanie podmieniony na adres subskrybenta. W opisach (nie tytułach) dozwolone proste tagi: <code>&lt;strong&gt; &lt;em&gt; &lt;br&gt; &lt;a&gt;</code>.</div>
        </div>

        <p style="font-weight:600;color:#374151;margin:18px 0 8px;font-size:13px;">Formularz zapisu (komunikaty pod formularzem)</p>
        <div class="evo-field"><label>Sukces (zapis natychmiastowy)</label>
            <input type="text" name="form_success" value="<?php echo esc_attr($val('form_success')); ?>"></div>
        <div class="evo-field"><label>Oczekuje na potwierdzenie (double opt-in)</label>
            <input type="text" name="form_pending" value="<?php echo esc_attr($val('form_pending')); ?>"></div>
        <div class="evo-field"><label>Adres już zapisany</label>
            <input type="text" name="form_already" value="<?php echo esc_attr($val('form_already')); ?>"></div>

        <p style="font-weight:600;color:#374151;margin:20px 0 8px;font-size:13px;">Strona potwierdzenia zapisu</p>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;">
            <div class="evo-field" style="margin:0;"><label>Tytuł (OK)</label>
                <input type="text" name="confirm_ok_title" value="<?php echo esc_attr($val('confirm_ok_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label>Treść (OK)</label>
                <input type="text" name="confirm_ok_msg" value="<?php echo esc_attr($val('confirm_ok_msg')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label>Tytuł (błąd)</label>
                <input type="text" name="confirm_bad_title" value="<?php echo esc_attr($val('confirm_bad_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label>Treść (błąd)</label>
                <input type="text" name="confirm_bad_msg" value="<?php echo esc_attr($val('confirm_bad_msg')); ?>"></div>
        </div>

        <p style="font-weight:600;color:#374151;margin:20px 0 8px;font-size:13px;">Wypisanie — pytanie potwierdzające</p>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;">
            <div class="evo-field" style="margin:0;"><label>Tytuł</label>
                <input type="text" name="unsub_confirm_title" value="<?php echo esc_attr($val('unsub_confirm_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label>Treść</label>
                <input type="text" name="unsub_confirm_msg" value="<?php echo esc_attr($val('unsub_confirm_msg')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label>Tekst przycisku</label>
                <input type="text" name="unsub_confirm_btn" value="<?php echo esc_attr($val('unsub_confirm_btn')); ?>"></div>
        </div>

        <p style="font-weight:600;color:#374151;margin:20px 0 8px;font-size:13px;">Wypisanie — wynik</p>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;">
            <div class="evo-field" style="margin:0;"><label>Tytuł (OK)</label>
                <input type="text" name="unsub_ok_title" value="<?php echo esc_attr($val('unsub_ok_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label>Treść (OK)</label>
                <input type="text" name="unsub_ok_msg" value="<?php echo esc_attr($val('unsub_ok_msg')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label>Tytuł (błąd)</label>
                <input type="text" name="unsub_bad_title" value="<?php echo esc_attr($val('unsub_bad_title')); ?>"></div>
            <div class="evo-field" style="margin:0;"><label>Treść (błąd)</label>
                <input type="text" name="unsub_bad_msg" value="<?php echo esc_attr($val('unsub_bad_msg')); ?>"></div>
        </div>

    </div>

    <div class="evo-box">
        <h3>E-mail potwierdzający zapis (double opt-in)</h3>
        <div class="evo-info-box" style="margin-bottom:14px;">
            <span class="dashicons dashicons-info"></span>
            <div>Placeholdery: <code>{site}</code> (nazwa witryny), <code>{list}</code> (np. „ do listy „Aktualności""). W treści dozwolone proste tagi.</div>
        </div>
        <div class="evo-field"><label>Temat wiadomości</label>
            <input type="text" name="confirm_subject" value="<?php echo esc_attr($val('confirm_subject')); ?>"></div>
        <div class="evo-field"><label>Nagłówek</label>
            <input type="text" name="confirm_email_heading" value="<?php echo esc_attr($val('confirm_email_heading')); ?>"></div>
        <div class="evo-field"><label>Treść</label>
            <input type="text" name="confirm_email_text" value="<?php echo esc_attr($val('confirm_email_text')); ?>"></div>
        <div class="evo-field"><label>Tekst przycisku</label>
            <input type="text" name="confirm_email_button" value="<?php echo esc_attr($val('confirm_email_button')); ?>"></div>

        <p style="margin-top:24px;">
            <button type="submit" class="button button-primary">Zapisz ustawienia</button>
        </p>
    </div>

</form>
