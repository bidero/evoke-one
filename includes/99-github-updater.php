<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Aktualizacje z GitHub
 *
 * Sprawdza najnowsze wydanie (Release) w repozytorium GitHub i podpina je
 * pod natywny mechanizm aktualizacji wtyczek WordPressa.
 *
 * Jak publikować aktualizację:
 *  1. Podbij "Version:" w evoke-one.php (i stałą EVOKE_ONE_VERSION).
 *  2. Wypchnij kod do https://github.com/bidero/evoke-one
 *  3. Utwórz Release z tagiem np. "v1.15.0" (lub "1.15.0").
 *     Opcjonalnie dołącz do wydania asset "evoke-one.zip" — jeśli go nie ma,
 *     użyty zostanie automatyczny zipball GitHuba (folder jest przemianowywany
 *     na "evoke-one" podczas instalacji).
 *
 * REPO PRYWATNE — wymagany token (fine-grained PAT z uprawnieniem
 * "Contents: Read" do tego repozytorium). Token podaje się POZA kodem wtyczki:
 *   - w wp-config.php:  define('EVOKE_ONE_GITHUB_TOKEN', 'github_pat_...');
 *   - lub w opcji WP:   update_option('evk_one_github_token', 'github_pat_...');
 * Stała ma pierwszeństwo. NIE commituj tokenu do repozytorium.
 */

class EVK_GitHub_Updater {

    private const REPO            = 'bidero/evoke-one';
    private const CACHE_KEY       = 'evk_one_gh_release';
    private const CACHE_TTL       = 6 * HOUR_IN_SECONDS;
    private const CACHE_TTL_ERROR = HOUR_IN_SECONDS; // nie młóć API po błędzie

    private static $instance = null;

    /** @var string np. "evoke-one/evoke-one.php" */
    private $basename;

    /** @var string np. "evoke-one" */
    private $slug;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->basename = plugin_basename(EVOKE_ONE_FILE);
        $this->slug     = dirname($this->basename);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api',                            [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection',              [$this, 'fix_source_folder'], 10, 4);
        add_action('upgrader_process_complete',              [$this, 'clear_cache'], 10, 2);
        add_filter('plugin_action_links_' . $this->basename, [$this, 'action_links']);
        add_action('admin_init',                             [$this, 'handle_force_check']);
        add_filter('http_request_args',                      [$this, 'authorize_download'], 10, 2);
    }

    /**
     * Token dostępu do repo (repo prywatne). Stała wp-config ma pierwszeństwo.
     */
    private function token(): string {
        if (defined('EVOKE_ONE_GITHUB_TOKEN') && EVOKE_ONE_GITHUB_TOKEN) {
            return (string) EVOKE_ONE_GITHUB_TOKEN;
        }
        $opt = get_option('evk_one_github_token', '');
        return is_string($opt) ? trim($opt) : '';
    }

    // =====================================================================
    // POBIERANIE INFORMACJI O WYDANIU
    // =====================================================================

    /**
     * Zwraca dane najnowszego wydania: ['version','package','url','body','published_at']
     * lub null gdy brak wydań / błąd API. Wynik jest cache'owany w transiencie.
     */
    private function get_release(): ?array {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return !empty($cached['version']) ? $cached : null;
        }

        $release = $this->fetch_release_from_api();

        if ($release === null) {
            // Cache'uj też negatywny wynik (krócej), żeby nie odpytywać API co request
            set_transient(self::CACHE_KEY, ['version' => ''], self::CACHE_TTL_ERROR);
            return null;
        }

        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    private function fetch_release_from_api(): ?array {
        $data = $this->api_get('https://api.github.com/repos/' . self::REPO . '/releases/latest');

        // Repo bez wydań → spróbuj tagów
        if ($data === null) {
            $tags = $this->api_get('https://api.github.com/repos/' . self::REPO . '/tags');
            if (!is_array($tags) || empty($tags[0]['name'])) return null;
            $tag = $tags[0];
            return [
                'version'      => $this->normalize_version($tag['name']),
                'package'      => 'https://api.github.com/repos/' . self::REPO . '/zipball/' . rawurlencode($tag['name']),
                'url'          => 'https://github.com/' . self::REPO,
                'body'         => '',
                'published_at' => '',
            ];
        }

        if (empty($data['tag_name'])) return null;

        $api_zipball = 'https://api.github.com/repos/' . self::REPO . '/zipball/' . rawurlencode($data['tag_name']);

        if ($this->token() !== '') {
            // Repo prywatne: browser_download_url assetów nie działa bez sesji
            // przeglądarki — pobieramy zipball przez API (nagłówek Authorization
            // dokleja authorize_download()).
            $package = $api_zipball;
        } else {
            // Repo publiczne: preferuj asset .zip (np. evoke-one.zip)
            $package = '';
            foreach (($data['assets'] ?? []) as $asset) {
                $name = strtolower($asset['name'] ?? '');
                if (!empty($asset['browser_download_url'])
                    && substr($name, -4) === '.zip') {
                    $package = $asset['browser_download_url'];
                    break;
                }
            }
            if (!$package) {
                $package = $data['zipball_url'] ?? $api_zipball;
            }
        }

        return [
            'version'      => $this->normalize_version($data['tag_name']),
            'package'      => $package,
            'url'          => $data['html_url'] ?? ('https://github.com/' . self::REPO),
            'body'         => (string) ($data['body'] ?? ''),
            'published_at' => (string) ($data['published_at'] ?? ''),
        ];
    }

    /** GET do API GitHub; null przy błędzie HTTP lub JSON. */
    private function api_get(string $url): ?array {
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            // GitHub API wymaga nagłówka User-Agent
            'User-Agent' => 'EvokeOne-Updater; ' . home_url('/'),
        ];
        if ($this->token() !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token();
        }
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => $headers,
        ]);
        if (is_wp_error($response)) return null;
        if ((int) wp_remote_retrieve_response_code($response) !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : null;
    }

    private function normalize_version(string $tag): string {
        return ltrim(trim($tag), 'vV');
    }

    // =====================================================================
    // WSTRZYKNIĘCIE AKTUALIZACJI DO TRANSIENTU WP
    // =====================================================================

    public function inject_update($transient) {
        if (!is_object($transient)) return $transient;

        $release = $this->get_release();
        if (!$release) return $transient;

        if (version_compare($release['version'], EVOKE_ONE_VERSION, '<=')) {
            // Aktualna wersja — upewnij się, że nie wisi stary wpis
            unset($transient->response[$this->basename]);
            return $transient;
        }

        $item = new stdClass();
        $item->id           = 'github.com/' . self::REPO;
        $item->slug         = $this->slug;
        $item->plugin       = $this->basename;
        $item->new_version  = $release['version'];
        $item->url          = 'https://github.com/' . self::REPO;
        $item->package      = $release['package'];
        $item->icons        = [];
        $item->banners      = [];
        $item->tested       = get_bloginfo('version');
        $item->requires_php = '8.0';

        $transient->response[$this->basename] = $item;
        return $transient;
    }

    // =====================================================================
    // POPUP "ZOBACZ SZCZEGÓŁY WERSJI"
    // =====================================================================

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (($args->slug ?? '') !== $this->slug) return $result;

        $release = $this->get_release();
        if (!$release) return $result;

        $info = new stdClass();
        $info->name          = 'Evoke ONE';
        $info->slug          = $this->slug;
        $info->version       = $release['version'];
        $info->author        = '<a href="https://github.com/' . esc_attr(explode('/', self::REPO)[0]) . '">Evoke Design Studio</a>';
        $info->homepage      = 'https://github.com/' . self::REPO;
        $info->download_link = $release['package'];
        $info->last_updated  = $release['published_at'];
        $info->requires_php  = '8.0';
        $info->sections      = [
            'description' => 'Zintegrowany zestaw narzędzi Evoke Design Studio — Tłumaczenia, Parallax, Konserwacja i inne.',
            'changelog'   => $release['body']
                ? wpautop(esc_html($release['body']))
                : '<p>Szczegóły wydania: <a href="' . esc_url($release['url']) . '" target="_blank">GitHub Releases</a></p>',
        ];

        return $info;
    }

    // =====================================================================
    // NAPRAWA NAZWY FOLDERU (zipball GitHuba → "bidero-evoke-one-abc1234")
    // =====================================================================

    public function fix_source_folder($source, $remote_source, $upgrader, $hook_extra = []) {
        if (($hook_extra['plugin'] ?? '') !== $this->basename) return $source;

        global $wp_filesystem;
        if (!$wp_filesystem) return $source;

        $desired = trailingslashit($remote_source) . $this->slug . '/';
        if (untrailingslashit($source) === untrailingslashit($desired)) return $source;

        if ($wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired), true)) {
            return $desired;
        }
        return $source;
    }

    // =====================================================================
    // AUTORYZACJA POBIERANIA PACZKI (repo prywatne)
    // =====================================================================

    /**
     * WP pobiera paczkę przez download_url() bez naszych nagłówków — doklejamy
     * Authorization do żądań kierowanych do API GitHuba dla naszego repo.
     * (Przekierowanie na codeload.github.com używa podpisanego URL-a.)
     */
    public function authorize_download($args, $url) {
        $token = $this->token();
        if ($token === '') return $args;
        if (strpos($url, 'https://api.github.com/repos/' . self::REPO . '/') !== 0) return $args;

        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = [];
        }
        if (empty($args['headers']['Authorization'])) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        return $args;
    }

    // =====================================================================
    // CZYSZCZENIE CACHE + RĘCZNE SPRAWDZANIE
    // =====================================================================

    public function clear_cache($upgrader, $hook_extra = []): void {
        if (($hook_extra['type'] ?? '') === 'plugin') {
            delete_transient(self::CACHE_KEY);
        }
    }

    public function action_links(array $links): array {
        $url = wp_nonce_url(
            add_query_arg('evk_one_check_update', '1', self_admin_url('plugins.php')),
            'evk_one_check_update'
        );
        $links[] = '<a href="' . esc_url($url) . '">Sprawdź aktualizacje</a>';
        return $links;
    }

    public function handle_force_check(): void {
        if (empty($_GET['evk_one_check_update'])) return;
        if (!current_user_can('update_plugins')) return;
        check_admin_referer('evk_one_check_update');

        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_safe_redirect(self_admin_url('plugins.php?evk_one_checked=1'));
        exit;
    }
}

EVK_GitHub_Updater::get_instance();

// Komunikat po ręcznym sprawdzeniu
add_action('admin_notices', function () {
    if (empty($_GET['evk_one_checked'])) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->id !== 'plugins') return;
    echo '<div class="notice notice-success is-dismissible"><p><strong>Evoke One:</strong> sprawdzono dostępność aktualizacji na GitHub. Jeśli jest nowsze wydanie — pojawi się poniżej przy wtyczce.</p></div>';
});
