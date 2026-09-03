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
    <a href="<?php echo esc_url($base_url); ?>" class="button evo-mb">← Powrót do listy ról</a>
    <h3>Edycja roli: <strong><?php echo esc_html(translate_user_role($role_obj['name'])); ?></strong></h3>

    <form method="post">
        <?php wp_nonce_field('evk_edit_role', 'evk_role_nonce'); ?>
        <input type="hidden" name="evk_role_action" value="edit_role">
        <input type="hidden" name="role_id" value="<?php echo esc_attr($edit_role); ?>">

        <div class="evo-grid-21" style="--evo-gap:24px">
            <div>
                <div class="evo-box">
                    <h3>Uprawnienia (capabilities)</h3>
                    <div class="evo-scroll-box evo-grid" style="--evo-col:220px;--evo-gap:4px;--evo-scroll-h:400px">
                        <?php foreach ($all_caps as $cap): ?>
                        <label class="evo-ep-row">
                            <input type="checkbox" name="capabilities[<?php echo esc_attr($cap); ?>]" value="1"
                                   <?php checked(in_array($cap, $has_caps, true)); ?>>
                            <code class="evo-mono-xs"><?php echo esc_html($cap); ?></code>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Ograniczenie edycji stron</h3>
                    <p class="evo-hint">Zostaw puste = dostęp do wszystkich. Zaznacz = tylko te strony.</p>
                    <div class="evo-scroll-box" style="--evo-scroll-h:300px">
                        <?php foreach ($pages as $page): ?>
                        <label class="evo-ep-row">
                            <input type="checkbox" name="page_restrictions[]" value="<?php echo $page->ID; ?>"
                                   <?php checked(in_array($page->ID, $restrict, true)); ?>>
                            <?php echo esc_html($page->post_title); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php /* DRUGA KOLUMNA SIATKI.
                     Do 1.138.0 `.evo-grid-21` (2fr 1fr) dostawała jedno dziecko,
                     a znacznik zamykający stał przed tym boksem — prawa trzecia część
                     ekranu zostawała pusta, a dostępy lądowały pod spodem.
                     Zgłoszone z użycia: „Role manager nie zajmuje całej
                     dostępnej szerokości". */ ?>
            <div class="evo-box">
            <h3>Dostęp do Evoke ONE</h3>
            <div class="evo-inset evo-stack evo-mb-lg" style="--evo-gap:10px">
                <label class="evo-check">
                    <input type="checkbox" name="evk_tl_access" value="1"
                           <?php checked($role->has_cap('evk_access_translations')); ?>>
                    <div>
                        <span class="evo-strong-500">Tłumaczenia</span>
                        <div class="evo-desc evo-m0">Rola może otwierać i edytować tłumaczenia.</div>
                    </div>
                </label>
                <label class="evo-check">
                    <input type="checkbox" name="evk_nl_access" value="1"
                           <?php checked($role->has_cap('evk_access_newsletter')); ?>>
                    <div>
                        <span class="evo-strong-500">Newsletter</span>
                        <div class="evo-desc evo-m0">Rola może zarządzać listami, szablonami i kampaniami newslettera.</div>
                    </div>
                </label>
                <label class="evo-check">
                    <input type="checkbox" name="evk_msg_access" value="1"
                           <?php checked($role->has_cap('evk_access_messages')); ?>>
                    <div>
                        <span class="evo-strong-500">Wiadomości</span>
                        <div class="evo-desc evo-m0">Rola może otwierać i czytać skrzynkę wiadomości z formularzy.</div>
                    </div>
                </label>
                <label class="evo-check">
                    <input type="checkbox" name="evk_maint_access" value="1"
                           <?php checked($role->has_cap('evk_access_maintenance')); ?>>
                    <div>
                        <span class="evo-strong-500">Tryb konserwacji</span>
                        <div class="evo-desc evo-m0">Rola może włączać i wyłączać tryb konserwacji z paska administratora.</div>
                    </div>
                </label>
                <label class="evo-check">
                    <input type="checkbox" name="evk_fields_access" value="1"
                           <?php checked($role->has_cap('evk_access_fields')); ?>>
                    <div>
                        <span class="evo-strong-500">Evoke FIELDS</span>
                        <div class="evo-desc evo-m0">
                            Rola może otwierać zakładkę Evoke FIELDS.
                            <strong>Uprawnienie nadaje Evoke ONE, ale sprawdzić je musi sama wtyczka FIELDS</strong>
                            — dopóki tego nie robi, zaznaczenie nic nie zmieni.
                        </div>
                    </div>
                </label>
            </div>

            </div>
        </div>

<div class="evo-save-bar"><?php submit_button('Zapisz rolę', 'primary', 'submit', false); ?></div>
    </form>

<?php elseif ($action === 'add'): ?>
    <a href="<?php echo esc_url($base_url); ?>" class="button evo-mb">← Powrót do listy ról</a>
    <h3>Dodaj nową rolę</h3>
    <form method="post" class="evo-w" style="--evo-w:500px">
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
    <div class="evo-row-between evo-mb">
        <p class="evo-m0 evo-muted evo-note-tx">Administrator jest chroniony i nie może być modyfikowany z tego panelu.</p>
        <a href="<?php echo esc_url(add_query_arg('role_action', 'add', $base_url)); ?>" class="button button-primary">+ Dodaj nową rolę</a>
    </div>
    <?php /* Bez `.evo-tbl-wrap`: ta tabela ma `table-layout: fixed`, więc mieści
             się w kolumnie sama. Sprawdzone mutacją — zdjęcie owijki nie zapaliło
             żadnego pomiaru, a `adm-roles` jest mierzona przy 390 i 360 px. */ ?>
    <?php /* Bez klas rdzenia — patrz komentarz przy tabeli SEO. Układ
             `fixed` zostaje: to on trzyma tę tabelę w kolumnie przy 390 i 360 px,
             co pilnują pomiary w tests/panel-start.test.js. */ ?>
    <table class="evo-tbl evo-tbl-fixed">
        <thead><tr>
            <th>Rola</th>
            <th>Slug</th>
            <th>Uprawnienia</th>
            <th class="evo-w" style="--evo-w:150px">Akcje</th>
        </tr></thead>
        <tbody>
        <?php foreach (get_editable_roles() as $slug => $data):
            $is_core = evk_role_is_core($slug);
        ?>
        <tr>
            <td><strong><?php echo esc_html(translate_user_role($data['name'])); ?></strong>
                <?php if ($is_core): ?><span class="evo-hint-sm"> (core)</span><?php endif; ?>
            </td>
            <td><code><?php echo esc_html($slug); ?></code></td>
            <td class="evo-hint"><?php echo count($data['capabilities']); ?> uprawnień</td>
            <td>
                <?php if ($slug !== 'administrator'): ?>
                <a href="<?php echo esc_url(add_query_arg(['role_action' => 'edit', 'edit_role' => $slug], $base_url)); ?>" class="button button-small">Edytuj</a>
                <?php if (!$is_core): ?>
                <form method="post" class="evo-inline-block" onsubmit="return confirm('Usunąć rolę <?php echo esc_js(translate_user_role($data['name'])); ?>?');">
                    <?php wp_nonce_field('evk_delete_role', 'evk_role_nonce'); ?>
                    <input type="hidden" name="evk_role_action" value="delete_role">
                    <input type="hidden" name="role_id" value="<?php echo esc_attr($slug); ?>">
                    <button type="submit" class="button button-small evo-danger-tx">Usuń</button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <span class="evo-hint evo-faint">— chroniony —</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
