<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Konserwacja (tryb konserwacji witryny)
 * Samowystarczalny — definiuje własne zmienne (ładowany z zakładki Narzędzia).
 */
$pages            = get_pages(['post_status' => 'publish', 'sort_column' => 'post_title']);
$selected_page_id = (int) get_option('maintenance_page_id', 0);
$status           = (int) get_option('maintenance_mode', 0);
$bypass_pass      = get_option('maintenance_bypass_password', '');
$bypass_hours     = get_option('maintenance_bypass_hours', 1);
$excluded_paths   = get_option('maintenance_excluded_paths', "/login\n/logmein");
$selected_page_title = '—';
if ($selected_page_id) {
    $p = get_post($selected_page_id);
    if ($p) $selected_page_title = $p->post_title;
}
?>
<div class="evo-status-card">
                <div class="evo-status-icon <?php echo $status ? 'on' : 'off'; ?>">
                    <span class="dashicons <?php echo $status ? 'dashicons-hidden' : 'dashicons-visibility'; ?>"></span>
                </div>
                <div class="evo-status-text">
                    <h3><?php echo $status ? 'Tryb konserwacji: WŁĄCZONY' : 'Tryb konserwacji: WYŁĄCZONY'; ?></h3>
                    <p><?php echo $status ? 'Goście są przekierowywani na stronę konserwacji.' : 'Witryna jest dostępna dla wszystkich odwiedzających.'; ?></p>
                </div>
                <div class="evo-status-actions">
                    <span class="evo-toggle-label"><?php echo $status ? 'Włączony' : 'Wyłączony'; ?></span>
                    <label class="evo-toggle">
                        <input type="checkbox" data-option="maintenance_mode" data-field="_scalar" value="1" <?php checked(1, $status); ?>>
                        <span class="evo-slider"></span>
                    </label>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_maintenance'); ?>
                <?php /* maintenance_mode zapisywany wyłącznie przez AJAX toggle (nie jest już
                         zarejestrowany w grupie evoke_one_maintenance) — zapis formularza
                         nie dotyka stanu włącznika. */ ?>

                <div class="evo-box">
                    <h3>Strona konserwacji</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Wybrana strona zostanie wyświetlona pod oryginalnym adresem URL (bez przekierowania). Wejście przez inny podadres przekieruje automatycznie na <code>/</code>.</div></details>
                    <div class="evo-field">
                        <label>Wybierz stronę</label>
                        <select name="maintenance_page_id">
                            <option value="0">— wybierz stronę —</option>
                            <?php foreach ($pages as $page): ?>
                            <option value="<?php echo $page->ID; ?>" <?php selected($selected_page_id, $page->ID); ?>><?php echo esc_html($page->post_title); ?> (ID: <?php echo $page->ID; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($selected_page_id): ?>
                        <div class="evo-desc">Aktualnie: <strong><?php echo esc_html($selected_page_title); ?></strong> — <a href="<?php echo esc_url(get_edit_post_link($selected_page_id)); ?>" target="_blank">edytuj ↗</a> | <a href="<?php echo esc_url(get_permalink($selected_page_id)); ?>" target="_blank">podgląd ↗</a></div>
                        <?php endif; ?>
                    </div>

                </div>

                <div class="evo-box">
                    <h3>Bypass przez URL</h3>
                    <div class="evo-field">
                        <label>Klucz dostępu (hasło bypass)</label>
                        <input type="text" name="maintenance_bypass_password" value="<?php echo esc_attr($bypass_pass); ?>" placeholder="np. podglad2025" autocomplete="off">
                        <div class="evo-desc">Link dla klienta pojawi się poniżej po zapisaniu.</div>
                        <?php if ($bypass_pass): ?>
                        <div class="evo-bypass-preview"><strong>Link dla klienta:</strong><br><?php echo esc_url(home_url('/?haslo=' . $bypass_pass)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="evo-field">
                        <label>Czas trwania sesji bypass</label>
                        <div class="evo-inline" style="--evo-gap:10px">
                            <input type="number" name="maintenance_bypass_hours" value="<?php echo esc_attr($bypass_hours); ?>" min="1" max="8760">
                            <span class="evo-note-tx">godzin(y)</span>
                        </div>
                        <div class="evo-desc">Maksimum: 8760 (1 rok).</div>
                    </div>

                </div>

                <div class="evo-box">
                    <h3>Wyjątki — ścieżki URL</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Jeśli używasz niestandardowej strony logowania, dodaj jej slug do listy.</div></details>
                    <div class="evo-field">
                        <label>Ścieżki pominięte przez konserwację</label>
                        <textarea name="maintenance_excluded_paths"><?php echo esc_textarea($excluded_paths); ?></textarea>
                        <div class="evo-desc">Jedna ścieżka na linię, zaczynająca się od <code>/</code>. Dopasowanie częściowe.</div>
                        <div class="evo-paths-preview">
                            <span class="evo-path-hardcoded">/wp-login.php ← zawsze</span><br>
                            <span class="evo-path-hardcoded">/wp-admin ← zawsze</span><br>
                            <span class="evo-path-hardcoded">/wp-cron.php ← zawsze</span>
                            <?php foreach (array_filter(array_map('trim', explode("\n", $excluded_paths))) as $p): ?>
                            <br><strong><?php echo esc_html($p); ?></strong>
                            <?php endforeach; ?>
                        </div>
                    </div>

                
                </div>

<div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia konserwacji', 'primary', 'submit', false); ?>
                </div>
            </form>
