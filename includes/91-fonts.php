<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Optymalizacja czcionek (anti-FOUT)
 *
 * FOUT przy lokalnych czcionkach bierze się stąd, że reguła @font-face jest
 * odkrywana dopiero po sparsowaniu CSS, więc plik czcionki zaczyna się pobierać
 * za późno i „przeskakuje" po pierwszym malowaniu. Preload każe przeglądarce
 * pobrać pliki najwcześniej jak się da (przy parsowaniu <head>, przed CSS), więc
 * są zwykle gotowe przed pierwszym malowaniem i swap w ogóle nie występuje.
 *
 * Rozwiązanie czysto ADDYTYWNE: emituje tylko <link rel="preload"> / preconnect.
 * Nie ukrywa tekstu, nie zmienia renderowania, layoutu ani przejść/animacji.
 */

class EVK_Fonts {

    private static $instance = null;

    private $defaults = [
        'enabled'    => 0,
        'preload'    => '',   // URL-e/ścieżki plików czcionek, jedna na linię
        'preconnect' => '',   // opcjonalne hosty do preconnect (fonty z zewn. CDN)
    ];

    public static function get_instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        if (empty($this->get_settings()['enabled'])) return; // nic nie rób gdy wyłączone
        // Priorytet 1 = jak najwcześniej w <head>, przed arkuszami stylów
        add_action('wp_head', [$this, 'render_head'], 1);
    }

    public function get_settings(): array {
        return wp_parse_args(get_option('evk_fonts', []), $this->defaults);
    }

    public function register_settings(): void {
        register_setting('evoke_one_fonts', 'evk_fonts', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        $clean = [];
        // 'enabled' zarządzany przez AJAX toggle — zachowaj gdy brak w POST
        $clean['enabled']    = evk_preserve_toggle($input, 'evk_fonts');
        $clean['preload']    = $this->sanitize_lines($input['preload'] ?? '', true);
        $clean['preconnect'] = $this->sanitize_lines($input['preconnect'] ?? '', false);
        return $clean;
    }

    /**
     * Czyści listę URL-i (jedna na linię). $allow_relative — dopuść ścieżki
     * zaczynające się od „/" (pliki na tym samym hoście).
     */
    private function sanitize_lines($raw, bool $allow_relative): string {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($allow_relative && $line[0] === '/') {
                $out[] = esc_url_raw($line, ['http', 'https']) ?: $line;
                continue;
            }
            // Pełne URL-e / hosty — tylko http(s)
            if (!preg_match('#^https?://#i', $line)) continue;
            $u = esc_url_raw($line);
            if ($u) $out[] = $u;
        }
        return implode("\n", array_values(array_unique($out)));
    }

    // =====================================================================
    // FRONTEND — <head>
    // =====================================================================

    public function render_head(): void {
        if (is_admin()) return;
        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) return;

        $s   = $this->get_settings();
        $out = '';

        // preconnect — dla czcionek serwowanych z zewnętrznego hosta
        foreach ($this->lines($s['preconnect']) as $host) {
            $out .= '<link rel="preconnect" href="' . esc_url($host) . '" crossorigin>' . "\n";
        }

        // preload plików czcionek (fonty zawsze pobierane w trybie CORS → crossorigin)
        foreach ($this->lines($s['preload']) as $url) {
            $abs  = $this->to_absolute($url);
            $type = $this->font_mime($abs);
            if ($type === '') continue; // nieznane rozszerzenie — nie preloaduj na ślepo
            $out .= '<link rel="preload" href="' . esc_url($abs) . '" as="font" type="'
                  . esc_attr($type) . '" crossorigin>' . "\n";
        }

        if ($out !== '') {
            echo "\n<!-- Evoke ONE: preload czcionek -->\n" . $out;
        }
    }

    private function lines(string $raw): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $l) {
            $l = trim($l);
            if ($l !== '') $out[] = $l;
        }
        return $out;
    }

    private function to_absolute(string $url): string {
        if (preg_match('#^https?://#i', $url)) return $url;
        if ($url !== '' && $url[0] === '/') return untrailingslashit(home_url()) . $url;
        return $url;
    }

    /** Typ MIME na podstawie rozszerzenia; '' dla nieobsługiwanych. */
    private function font_mime(string $url): string {
        $ext = strtolower(pathinfo(strtok($url, '?'), PATHINFO_EXTENSION));
        $map = [
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
        ];
        return $map[$ext] ?? '';
    }

    /**
     * Skanuje typowe lokalizacje samodzielnie hostowanych czcionek i zwraca
     * znalezione pliki .woff2 jako URL-e (pomoc przy wypełnianiu pola preload).
     * Wyłącznie na potrzeby UI admina — bounded, płytki glob.
     */
    public static function detect_local_fonts(int $limit = 40): array {
        $upload = wp_upload_dir();
        $base   = trailingslashit($upload['basedir']);
        $baseurl= trailingslashit($upload['baseurl']);
        $found  = [];

        $candidates = [
            'omgf', 'fonts', 'local-google-fonts', 'bricks', 'sfg',
        ];
        foreach ($candidates as $dir) {
            $path = $base . $dir;
            if (!is_dir($path)) continue;
            // Ten poziom + jeden podkatalog głębiej
            foreach (array_merge(
                (array) glob($path . '/*.woff2'),
                (array) glob($path . '/*/*.woff2')
            ) as $file) {
                if (!is_string($file)) continue;
                $rel = str_replace($base, '', $file);
                $found[] = $baseurl . str_replace('\\', '/', $rel);
                if (count($found) >= $limit) break 2;
            }
        }
        return array_values(array_unique($found));
    }
}

EVK_Fonts::get_instance();
