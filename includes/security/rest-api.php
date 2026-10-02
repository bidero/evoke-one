<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Security: Blokowanie REST API
 * Opcja 1: zablokuj cały REST API dla gości (jeden toggle)
 * Opcja 2: zablokuj wybrane endpointy dla gości
 */

// =========================================================================
// BLOKOWANIE
// =========================================================================

/**
 * Czy trasa jest zablokowana dla gościa — kod błędu albo null (1.281.0).
 * Kolejność: wyliczanie użytkowników, blokada całości (poza wyjątkami),
 * wybrane trasy. Trasy z listy dopasowane jak w rdzeniu (`@^…$@i`).
 *
 * @param array<string,mixed> $s Ustawienia z evk_security_get().
 */
function evk_rest_zablokowany(string $trasa, array $s): ?string {
    if (!empty($s['rest_uzytkownicy']) && preg_match('#^/wp/v2/users(/|$)#i', $trasa)) return 'evk_rest_users';
    if (!empty($s['rest_block_all'])) {
        foreach ((array) ($s['rest_wyjatki'] ?? []) as $ns) {
            $ns = '/' . trim((string) $ns, '/');
            if ($ns !== '/' && ($trasa === $ns || strpos($trasa, $ns . '/') === 0)) return null;
        }
        return 'evk_rest_disabled';
    }
    foreach ((array) ($s['disabled_rest_endpoints'] ?? []) as $wzor) {
        $wzor = (string) $wzor;
        if ($wzor === '') continue;
        if ($trasa === $wzor || @preg_match('@^' . $wzor . '$@i', $trasa)) return 'evk_rest_forbidden';
    }
    return null;
}

/* rest_pre_dispatch — po rozpoznaniu użytkownika (także hasłem aplikacji),
   dla każdej trasy i indeksu /wp-json. Zalogowani — zawsze przechodzą. */
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (is_user_logged_in()) return $result;
    $kod = evk_rest_zablokowany((string) $request->get_route(), evk_security_get());
    return $kod === null ? $result : new WP_Error($kod, 'Access Denied', ['status' => 401]);
}, 10, 3);

/* Wyliczanie użytkowników poza REST (1.281.0): `?author=N` przekierowuje
   na /author/login — dla gościa 404; mapa strony WordPressa bez autorów. */
add_action('template_redirect', function (): void {
    if (is_user_logged_in() || !isset($_GET['author']) || !is_numeric($_GET['author'])) return;
    $s = evk_security_get();
    if (empty($s['rest_uzytkownicy'])) return;
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}, 1);
add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
    if ($name === 'users' && !empty(evk_security_get()['rest_uzytkownicy'])) return false;
    return $provider;
}, 10, 2);

// =========================================================================
// HELPER — pobierz endpointy pogrupowane po namespace (dla UI ustawień)
// =========================================================================

function evk_rest_get_endpoints(): array {
    $server  = rest_get_server();
    $routes  = $server->get_routes();
    $grouped = [];

    foreach ($routes as $route => $route_data) {
        $namespace = 'core';
        if (preg_match('#^/([^/]+)/#', $route, $m)) {
            $namespace = $m[1];
        }

        $methods = [];
        foreach ($route_data as $handler) {
            if (isset($handler['methods'])) {
                $m = is_array($handler['methods'])
                    ? array_keys($handler['methods'])
                    : [$handler['methods']];
                $methods = array_merge($methods, $m);
            }
        }

        $grouped[$namespace][] = [
            'route'   => $route,
            'methods' => array_unique($methods),
        ];
    }

    ksort($grouped);
    return $grouped;
}
