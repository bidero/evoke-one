<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Parallax
 */

class EVK_Parallax {

    private static $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = $this->get_settings();
        if (!empty($settings['enabled'])) {
            add_action('wp_enqueue_scripts',              [$this, 'enqueue_scripts']);
            add_filter('bricks/dynamic_tags_list',        [$this, 'register_bricks_tag']);
            add_filter('bricks/dynamic_data/render_tag',  [$this, 'render_bricks_tag'], 10, 3);
            add_filter('bricks/dynamic_data/render_content', [$this, 'render_bricks_content'], 10, 3);
            add_filter('bricks/frontend/render_data',     [$this, 'render_bricks_content'], 10, 2);
        }
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function get_settings(): array {
        $defaults = ['enabled' => 0];
        $saved    = get_option('evk_parallax', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    public function register_settings(): void {
        register_setting('evoke_one_parallax', 'evk_parallax_value', [
            'type'              => 'number',
            'default'           => 0.3,
            'sanitize_callback' => [self::class, 'sanitize_parallax_value'],
        ]);
        register_setting('evoke_one_parallax', 'evk_parallax_scale', [
            'type'              => 'number',
            'default'           => 1.2,
            'sanitize_callback' => [self::class, 'sanitize_scale_value'],
        ]);
        register_setting('evoke_one_parallax', 'evk_parallax', [
            'sanitize_callback' => [self::class, 'sanitize_settings'],
        ]);
    }

    public static function sanitize_settings($input): array {
        return ['enabled' => evk_preserve_toggle($input, 'evk_parallax')];
    }

    public function enqueue_scripts(): void {
        wp_enqueue_script(
            'evk-parallax',
            EVOKE_ONE_URL . 'assets/js/parallax.js',
            [],
            EVOKE_ONE_VERSION,
            true
        );
        wp_localize_script('evk-parallax', 'evkParallaxSettings', [
            'defaultValue' => $this->get_parallax_value(),
            'defaultScale' => $this->get_scale_value(),
        ]);
    }

    public function get_parallax_value(): float {
        return floatval(get_option('evk_parallax_value', 0.3));
    }

    public function get_scale_value(): float {
        return floatval(get_option('evk_parallax_scale', 1.2));
    }

    public function register_bricks_tag($tags): array {
        $tags[] = ['name' => '{evk_parallax}',       'label' => 'Evoke Parallax - Wartość', 'group' => 'Evoke Parallax'];
        $tags[] = ['name' => '{evk_parallax_scale}',  'label' => 'Evoke Parallax - Skala',   'group' => 'Evoke Parallax'];
        return $tags;
    }

    public function render_bricks_tag($tag, $post, $context) {
        if ($tag === 'evk_parallax')       return $this->get_parallax_value();
        if ($tag === 'evk_parallax_scale') return $this->get_scale_value();
        return $tag;
    }

    public function render_bricks_content($content, $post = null, $context = 'text') {
        if (is_array($content)) return $content;
        $content = str_replace('{evk_parallax}',       $this->get_parallax_value(), $content);
        $content = str_replace('{evk_parallax_scale}', $this->get_scale_value(),    $content);
        return $content;
    }

    public static function sanitize_parallax_value($value): float {
        return max(-1.0, min(1.0, floatval($value)));
    }

    public static function sanitize_scale_value($value): float {
        return max(1.0, min(2.0, floatval($value)));
    }
}

EVK_Parallax::get_instance();

function evk_get_parallax_value(): float {
    return EVK_Parallax::get_instance()->get_parallax_value();
}

function evk_get_parallax_scale(): float {
    return EVK_Parallax::get_instance()->get_scale_value();
}
