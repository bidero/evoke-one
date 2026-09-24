<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Role Manager (logika)
 */

define('EVK_ROLE_RESTRICTIONS_OPTION', 'evk_role_restrictions');

// =========================================================================
// INIT — admin zawsze ma evk_access_translations
// =========================================================================

// Dynamicznie nadaj evk_access_* administratorowi — działa od razu
// bez potrzeby zapisywania do bazy i przeładowania sesji
add_filter('user_has_cap', function (array $caps, array $cap_check, array $args) {
    if (empty($cap_check)) return $caps;
    $cap = $cap_check[0];
    if (!in_array($cap, [
        'evk_access_translations', 'evk_access_newsletter',
        'evk_access_messages', 'evk_access_maintenance', 'evk_access_fields',
    ], true)) return $caps;
    if (!empty($caps['manage_options'])) {
        $caps[$cap] = true;
    }
    return $caps;
}, 1, 3);

add_action('init', function () {
    // Nadaj evk_access_translations administratorowi (zawsze ma dostęp)
    $admin_role = get_role('administrator');
    if ($admin_role && !$admin_role->has_cap('evk_access_translations')) {
        $admin_role->add_cap('evk_access_translations', true);
    }
    if ($admin_role && !$admin_role->has_cap('evk_access_newsletter')) {
        $admin_role->add_cap('evk_access_newsletter', true);
    }
    if ($admin_role && !$admin_role->has_cap('evk_access_messages')) {
        $admin_role->add_cap('evk_access_messages', true);
    }
    if ($admin_role && !$admin_role->has_cap('evk_access_maintenance')) {
        $admin_role->add_cap('evk_access_maintenance', true);
    }
    /* Evoke FIELDS to OSOBNA WTYCZKA. Evoke ONE potrafi tylko nadać
       uprawnienie i pokazać je w Role Managerze — sprawdzić je musi sam FIELDS,
       przy rejestracji swojego menu i we własnych punktach zapisu. Bez tej
       drugiej połowy zaznaczenie tu niczego nie zmieni. */
    if ($admin_role && !$admin_role->has_cap('evk_access_fields')) {
        $admin_role->add_cap('evk_access_fields', true);
    }
    // Nadaj manage_evk_roles administratorowi
    if ($admin_role && !$admin_role->has_cap('manage_evk_roles')) {
        $admin_role->add_cap('manage_evk_roles', true);
    }
});

// TU BYŁO doklejanie `manage_options` rolom z `evk_access_translations` na czas
// żądania, w którym `action` zaczyna się od `tl_`. Zostało USUNIĘTE w 1.127.0
// i nie ma wracać — ani w tej postaci, ani w „poprawionej".
//
// Dwie rzeczy były z tym nie tak, każda sama w sobie wystarczająca:
//
// 1. O PRZYZNANIU UPRAWNIENIA DECYDOWAŁA WARTOŚĆ Z ŻĄDANIA. `admin-ajax.php`
//    również odpala `admin_init`, więc wystarczyło zawołać `tl_import` — punkt,
//    który zapisuje `evk_snippets_advanced_content`, czyli surowy PHP
//    wykonywany potem przez `eval()`. Rola do tłumaczenia fraz dawała w efekcie
//    wykonanie dowolnego kodu na serwerze. Nonce nie był przeszkodą: strona
//    Tłumaczeń, do której ta rola ma dostęp, sama go drukuje.
//
// 2. `add_cap()` ZAPISUJE UPRAWNIENIE DO BAZY (usermeta), a zdejmował je hook
//    `shutdown`. Proces ubity twardo — OOM, timeout FPM — zostawiał
//    `manage_options` na stałe, bez żadnego atakującego.
//
// Uprawnień pilnują teraz same punkty AJAX: `evk_tl_ajax_check()`
// w `includes/30-admin-settings-ajax.php` pyta o `manage_options` LUB
// `evk_access_translations`, a eksport i import dodatkowo zawężają listę
// modułów do `tl_*`. Sprawdza to `tests/php/tl-uprawnienia.php`.

// =========================================================================
// HELPERY
// =========================================================================

function evk_role_get_all_caps(): array {
    global $wp_roles;
    if (!isset($wp_roles)) $wp_roles = new WP_Roles();
    $caps = [];
    foreach ($wp_roles->role_objects as $role) {
        if (is_array($role->capabilities)) {
            foreach (array_keys($role->capabilities) as $cap) {
                if (strpos($cap, 'evk_') === 0) continue;
                if (strpos($cap, 'manage_evk') === 0) continue;
                $caps[] = $cap;
            }
        }
    }
    $caps = array_unique($caps);
    sort($caps);
    return $caps;
}

function evk_role_is_core(string $slug): bool {
    return in_array($slug, ['administrator', 'editor', 'author', 'contributor', 'subscriber'], true);
}

function evk_role_get_restrictions(): array {
    return (array) get_option(EVK_ROLE_RESTRICTIONS_OPTION, []);
}

function evk_role_set_restrictions(string $role_id, array $page_ids): void {
    $all = evk_role_get_restrictions();
    if (empty($page_ids)) {
        unset($all[$role_id]);
    } else {
        $all[$role_id] = array_map('absint', $page_ids);
    }
    update_option(EVK_ROLE_RESTRICTIONS_OPTION, $all);
}

// =========================================================================
// OBSŁUGA FORMULARZY
// =========================================================================

add_action('admin_init', function () {
    if (!current_user_can('manage_evk_roles')) return;
    if (!isset($_POST['evk_role_action'])) return;
    $action = sanitize_key($_POST['evk_role_action']);

    if ($action === 'edit_role') {
        if (!wp_verify_nonce($_POST['evk_role_nonce'] ?? '', 'evk_edit_role')) return;
        $role_id = sanitize_key($_POST['role_id'] ?? '');
        if ($role_id === 'administrator' || !isset(get_editable_roles()[$role_id])) return;
        $role = get_role($role_id);
        if (!$role) return;

        // Standardowe capabilities (bez evk_*)
        $all_caps = evk_role_get_all_caps();
        $new_caps = array_keys(array_filter((array)($_POST['capabilities'] ?? []), fn($v) => $v === '1'));
        foreach ($all_caps as $cap) {
            if (in_array($cap, $new_caps, true)) $role->add_cap($cap, true);
            else $role->remove_cap($cap);
        }

        // Dostęp do Evoke ONE — Tłumaczenia
        if (!empty($_POST['evk_tl_access'])) {
            $role->add_cap('evk_access_translations', true);
        } else {
            $role->remove_cap('evk_access_translations');
        }

        // Dostęp do Evoke ONE — Newsletter
        if (!empty($_POST['evk_nl_access'])) {
            $role->add_cap('evk_access_newsletter', true);
        } else {
            $role->remove_cap('evk_access_newsletter');
        }

        // Dostęp do Evoke ONE — Wiadomości (skrzynka formularzy)
        if (!empty($_POST['evk_msg_access'])) {
            $role->add_cap('evk_access_messages', true);
        } else {
            $role->remove_cap('evk_access_messages');
        }

        // Dostęp do Evoke ONE — Tryb konserwacji (przełącznik w pasku admina)
        if (!empty($_POST['evk_maint_access'])) {
            $role->add_cap('evk_access_maintenance', true);
        } else {
            $role->remove_cap('evk_access_maintenance');
        }

        // Dostęp do Evoke FIELDS (osobna wtyczka — patrz komentarz przy `init`)
        if (!empty($_POST['evk_fields_access'])) {
            $role->add_cap('evk_access_fields', true);
        } else {
            $role->remove_cap('evk_access_fields');
        }

        // Ograniczenia stron
        $pages = array_map('absint', (array)($_POST['page_restrictions'] ?? []));
        evk_role_set_restrictions($role_id, $pages);
        add_settings_error('evk_role_manager', 'saved', 'Rola zaktualizowana.', 'updated');
    }

    if ($action === 'add_role') {
        if (!wp_verify_nonce($_POST['evk_role_nonce'] ?? '', 'evk_add_role')) return;
        $name    = sanitize_text_field($_POST['role_name'] ?? '');
        $slug    = sanitize_key($_POST['role_slug'] ?? '');
        $copy_of = sanitize_key($_POST['copy_from'] ?? '');
        if (empty($name) || empty($slug)) return;
        $caps = ($copy_of && ($src = get_role($copy_of))) ? $src->capabilities : [];
        add_role($slug, $name, $caps);
        add_settings_error('evk_role_manager', 'added', 'Rola dodana.', 'updated');
    }

    if ($action === 'delete_role') {
        if (!wp_verify_nonce($_POST['evk_role_nonce'] ?? '', 'evk_delete_role')) return;
        $role_id = sanitize_key($_POST['role_id'] ?? '');
        if (!evk_role_is_core($role_id)) remove_role($role_id);
        add_settings_error('evk_role_manager', 'deleted', 'Rola usunięta.', 'updated');
    }
}, 10);

// =========================================================================
// OGRANICZENIE EDYCJI STRON
// =========================================================================

/**
 * Strony, które użytkownik wolno edytować — albo `null`, gdy żadna jego rola
 * nie ma ograniczeń.
 *
 * Kilka ról z ograniczeniami sumuje się (lista A ∪ lista B). Administrator nie
 * podlega ograniczeniom nigdy — sprawdzamy ROLĘ, nie uprawnienie, bo to
 * wywołanie leci z wnętrza `map_meta_cap` i pytanie o uprawnienie wróciłoby
 * tutaj rekurencyjnie.
 */
function evk_role_dozwolone_strony(int $user_id): ?array {
    if (!$user_id) return null;
    $user = get_userdata($user_id);
    if (!$user || in_array('administrator', (array) $user->roles, true)) return null;

    $ograniczenia = evk_role_get_restrictions();
    $dozwolone    = null;
    foreach ((array) $user->roles as $rola) {
        if (empty($ograniczenia[$rola])) continue;
        $dozwolone = array_merge($dozwolone ?? [], array_map('intval', (array) $ograniczenia[$rola]));
    }
    return $dozwolone === null ? null : array_values(array_unique($dozwolone));
}

/**
 * Ograniczenie: rola edytuje i usuwa WYŁĄCZNIE zaznaczone strony.
 * Każdy inny wpis dowolnego typu (strony, wpisy, szablony Bricksa…) — bez
 * edycji, usuwania i publikacji. Media zostają poza ograniczeniem: wgrany
 * obrazek jest załącznikiem i jego opis musi dać się poprawić.
 *
 * DLACZEGO `map_meta_cap`, a nie `user_has_cap` jak do 1.229.6. Tamten filtr
 * dostawał uprawnienia JUŻ ZMAPOWANE (`edit_others_pages`,
 * `edit_published_pages`…) i szukał wśród nich `edit_page`/`edit_post`,
 * których po mapowaniu nigdy tam nie ma — wychodził przy każdym wywołaniu.
 * Użytkownik ograniczony do strony A edytował i usuwał stronę B (audyt 1.229.6,
 * tools/audyt/sondy/role-ograniczenia.php). Nawet gdyby warunek łapał, zdjęcie
 * `edit_pages` nie blokowało cudzych opublikowanych stron, bo te wymagają
 * `edit_others_pages`. Tu decydujemy na etapie meta-uprawnienia, z ID wpisu
 * w ręku: `do_not_allow` przegrywa z każdym uprawnieniem roli.
 */
add_filter('map_meta_cap', function (array $caps, string $cap, int $user_id, array $args): array {
    static $meta = ['edit_post', 'edit_page', 'delete_post', 'delete_page', 'publish_post'];
    if (!in_array($cap, $meta, true) || empty($args[0])) return $caps;

    $dozwolone = evk_role_dozwolone_strony($user_id);
    if ($dozwolone === null) return $caps;

    $post_id = (int) $args[0];
    if (get_post_type($post_id) === 'attachment') return $caps;
    if (in_array($post_id, $dozwolone, true)) return $caps;

    $caps[] = 'do_not_allow';
    return $caps;
}, 10, 4);

/**
 * Jednorazowe powiadomienie po aktualizacji: ograniczenia ZACZĘŁY działać.
 *
 * Do 1.229.6 nie blokowały niczego, więc klienci z ograniczoną rolą mogli się
 * przyzwyczaić do edycji stron spoza listy — od tej wersji stracą do nich
 * dostęp. Administrator ma o tym wiedzieć, zanim zadzwoni klient.
 */
const EVK_ROLE_POWIADOMIENIE_OPCJA = 'evk_role_restrictions_notice_1230';

add_action('admin_notices', function () {
    if (!current_user_can('manage_evk_roles') || get_option(EVK_ROLE_POWIADOMIENIE_OPCJA)) return;
    $ograniczenia = array_filter(evk_role_get_restrictions());
    /* Strona bez ograniczeń w chwili aktualizacji nie ma czego ogłaszać — a
       ograniczenia ustawione później od początku działają, więc zdanie
       „do tej pory nie blokowały" byłoby nieprawdą. Zamykamy od razu. */
    if (!$ograniczenia) { update_option(EVK_ROLE_POWIADOMIENIE_OPCJA, 1, false); return; }

    $nazwy = [];
    $role  = wp_roles()->get_names();
    foreach (array_keys($ograniczenia) as $slug) $nazwy[] = translate_user_role($role[$slug] ?? $slug);

    $zamknij = wp_nonce_url(admin_url('admin-post.php?action=evk_role_powiadomienie'), 'evk_role_powiadomienie');
    $ekran   = add_query_arg(['page' => 'evoke-one', 'tab' => 'admin_panel', 'sub' => 'roles'], admin_url('options-general.php'));
    printf(
        '<div class="notice notice-warning"><p><strong>Evoke ONE:</strong> %s</p><p><a class="button" href="%s">%s</a> <a class="button-link" href="%s">%s</a></p></div>',
        esc_html(sprintf(
            'Ograniczenia edycji stron w Role Managerze do tej pory nie blokowały niczego — od tej wersji działają. Role z ograniczeniami: %s. Ich użytkownicy edytują teraz WYŁĄCZNIE zaznaczone strony; wszystkie inne strony i wpisy są dla nich zablokowane.',
            implode(', ', $nazwy)
        )),
        esc_url($ekran), esc_html('Sprawdź ograniczenia'),
        esc_url($zamknij), esc_html('Rozumiem, ukryj')
    );
});

add_action('admin_post_evk_role_powiadomienie', function () {
    if (!current_user_can('manage_evk_roles')) wp_die('Brak uprawnień.', 403);
    check_admin_referer('evk_role_powiadomienie');
    update_option(EVK_ROLE_POWIADOMIENIE_OPCJA, 1, false);
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
});
