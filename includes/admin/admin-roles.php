<?php if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Admin: Role Manager
 */
if (!current_user_can('manage_evk_roles')) {
    echo '<div class="notice notice-error"><p>Brak uprawnień.</p></div>';
    return;
}

settings_errors('evk_role_manager');

global $wp_roles;
if (!isset($wp_roles)) $wp_roles = new WP_Roles();

$action    = sanitize_key($_GET['role_action'] ?? 'list');
$edit_role = sanitize_key($_GET['edit_role']   ?? '');
$base_url  = add_query_arg(['tab' => 'admin_panel', 'sub' => 'roles'], admin_url('options-general.php?page=evoke-one'));

if ($action === 'edit' && $edit_role && $edit_role !== 'administrator' && isset(get_editable_roles()[$edit_role])):
    $role     = get_role($edit_role);
    $role_obj = get_editable_roles()[$edit_role];
    $all_caps = evk_role_get_all_caps();
    $has_caps = array_keys(array_filter($role->capabilities));
    $pages    = get_posts(['post_type' => 'page', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $restrict = evk_role_get_restrictions()[$edit_role] ?? [];
    ?>
    <a href="<?php echo esc_url($base_url); ?>" class="button" style="margin-bottom:16px;">← Powrót do listy ról</a>
    <h3>Edycja roli: <strong><?php echo esc_html(translate_user_role($role_obj['name'])); ?></strong></h3>

    <form method="post">
        <?php wp_nonce_field('evk_edit_role', 'evk_role_nonce'); ?>
        <input type="hidden" name="evk_role_action" value="edit_role">
        <input type="hidden" name="role_id" value="<?php echo esc_attr($edit_role); ?>">

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
            <div>
                <div class="evo-box">
                    <h3>Uprawnienia (capabilities)</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px;max-height:400px;overflow-y:auto;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;">
                        <?php foreach ($all_caps as $cap): ?>
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:2px;">
                            <input type="checkbox" name="capabilities[<?php echo esc_attr($cap); ?>]" value="1"
                                   <?php checked(in_array($cap, $has_caps, true)); ?>>
                            <code style="font-size:11px;"><?php echo esc_html($cap); ?></code>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                </div>

                <div class="evo-box">
                    <h3>Ograniczenie edycji stron</h3>
                    <p style="font-size:12px;color:#6b7280;">Zostaw puste = dostęp do wszystkich. Zaznacz = tylko te strony.</p>
                    <div style="max-height:300px;overflow-y:auto;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px;">
                        <?php foreach ($pages as $page): ?>
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px;">
                            <input type="checkbox" name="page_restrictions[]" value="<?php echo $page->ID; ?>"
                                   <?php checked(in_array($page->ID, $restrict, true)); ?>>
                            <?php echo esc_html($page->post_title); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
                </div>

        <div class="evo-box">
            <h3 style="margin-top:24px;">Dostęp do Evoke ONE</h3>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px;margin-bottom:20px;display:flex;flex-direction:column;gap:10px;">
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="evk_tl_access" value="1"
                           <?php checked($role->has_cap('evk_access_translations')); ?>>
                    <div>
                        <span style="font-weight:500;">Tłumaczenia</span>
                        <div class="evo-desc" style="margin-top:2px;">Rola może otwierać i edytować tłumaczenia.</div>
                    </div>
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="evk_nl_access" value="1"
                           <?php checked($role->has_cap('evk_access_newsletter')); ?>>
                    <div>
                        <span style="font-weight:500;">Newsletter</span>
                        <div class="evo-desc" style="margin-top:2px;">Rola może zarządzać listami, szablonami i kampaniami newslettera.</div>
                    </div>
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="evk_msg_access" value="1"
                           <?php checked($role->has_cap('evk_access_messages')); ?>>
                    <div>
                        <span style="font-weight:500;">Wiadomości</span>
                        <div class="evo-desc" style="margin-top:2px;">Rola może otwierać i czytać skrzynkę wiadomości z formularzy.</div>
                    </div>
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="evk_maint_access" value="1"
                           <?php checked($role->has_cap('evk_access_maintenance')); ?>>
                    <div>
                        <span style="font-weight:500;">Tryb konserwacji</span>
                        <div class="evo-desc" style="margin-top:2px;">Rola może włączać i wyłączać tryb konserwacji z paska administratora.</div>
                    </div>
                </label>
            </div>

        
        </div>

<div class="evo-save-bar"><?php submit_button('Zapisz rolę', 'primary', 'submit', false); ?></div>
    </form>

<?php elseif ($action === 'add'): ?>
    <a href="<?php echo esc_url($base_url); ?>" class="button" style="margin-bottom:16px;">← Powrót do listy ról</a>
    <h3>Dodaj nową rolę</h3>
    <form method="post" style="max-width:500px;">
        <?php wp_nonce_field('evk_add_role', 'evk_role_nonce'); ?>
        <input type="hidden" name="evk_role_action" value="add_role">
        <div class="evo-field">
            <label>Nazwa roli</label>
            <input type="text" name="role_name" placeholder="np. Menedżer" required>
        </div>
        <div class="evo-field">
            <label>Identyfikator (slug)</label>
            <input type="text" name="role_slug" placeholder="np. manager" pattern="[a-z0-9_-]+" required>
            <div class="evo-desc">Tylko małe litery, cyfry, myślniki i podkreślenia.</div>
        </div>
        <div class="evo-field">
            <label>Skopiuj uprawnienia z roli</label>
            <select name="copy_from">
                <option value="">— brak (pusta rola) —</option>
                <?php foreach ($wp_roles->get_names() as $slug => $name): ?>
                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html(translate_user_role($name)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="evo-save-bar"><?php submit_button('Dodaj rolę', 'primary', 'submit', false); ?></div>
    </form>

<?php else: // Lista ról ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <p style="margin:0;color:#6b7280;font-size:13px;">Administrator jest chroniony i nie może być modyfikowany z tego panelu.</p>
        <a href="<?php echo esc_url(add_query_arg('role_action', 'add', $base_url)); ?>" class="button button-primary">+ Dodaj nową rolę</a>
    </div>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th>Rola</th>
            <th>Slug</th>
            <th>Uprawnienia</th>
            <th style="width:150px;">Akcje</th>
        </tr></thead>
        <tbody>
        <?php foreach (get_editable_roles() as $slug => $data):
            $is_core = evk_role_is_core($slug);
        ?>
        <tr>
            <td><strong><?php echo esc_html(translate_user_role($data['name'])); ?></strong>
                <?php if ($is_core): ?><span style="color:#6b7280;font-size:11px;"> (core)</span><?php endif; ?>
            </td>
            <td><code><?php echo esc_html($slug); ?></code></td>
            <td style="font-size:12px;color:#6b7280;"><?php echo count($data['capabilities']); ?> uprawnień</td>
            <td>
                <?php if ($slug !== 'administrator'): ?>
                <a href="<?php echo esc_url(add_query_arg(['role_action' => 'edit', 'edit_role' => $slug], $base_url)); ?>" class="button button-small">Edytuj</a>
                <?php if (!$is_core): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Usunąć rolę <?php echo esc_js(translate_user_role($data['name'])); ?>?');">
                    <?php wp_nonce_field('evk_delete_role', 'evk_role_nonce'); ?>
                    <input type="hidden" name="evk_role_action" value="delete_role">
                    <input type="hidden" name="role_id" value="<?php echo esc_attr($slug); ?>">
                    <button type="submit" class="button button-small" style="color:#dc2626;">Usuń</button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <span style="color:#9ca3af;font-size:12px;">— chroniony —</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
