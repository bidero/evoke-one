<?php
namespace Bricks;

// Tylko z wiersza poleceń — patrz _wp-stubs.php.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

/**
 * Minimalne atrapy Bricksa — tyle, ile potrzebuje `element.php` elementu Evoke.
 *
 * Sens jest ten sam co przy atrapach WordPressa: sprawdzamy PRAWDZIWĄ tablicę
 * kontrolek i prawdziwe `render()`, a nie ich opis w teście. Atrapa nie udaje
 * Bricksa — nie ma tu ani jednej reguły, którą Bricks narzuca elementom.
 * Odwzorowane jest wyłącznie to, czego element dotyka: `$controls`, `$settings`
 * i para `set_attribute()` / `render_attributes()`.
 *
 * Czego ta atrapa NIE sprawdzi i co trzeba wiedzieć, zanim się jej zaufa:
 * czy Bricks przyjmie dany typ kontrolki, czy `required` wskazuje istniejące
 * pole i czy nazwa elementu nie koliduje z inną. To są pytania do buildera,
 * nie do testu jednostkowego.
 */
if (!class_exists('Bricks\\Element')) {
    class Element {
        public $settings   = [];
        public $controls   = [];
        public $attributes = [];

        public function set_attribute($key, $attr, $value = null) {
            if (is_array($value)) $value = implode(' ', $value);
            $this->attributes[$key][$attr][] = (string) $value;
        }

        public function render_attributes($key) {
            $out = [];
            foreach ($this->attributes[$key] ?? [] as $attr => $vals) {
                $out[] = $attr . '="' . esc_attr(implode(' ', $vals)) . '"';
            }
            return implode(' ', $out);
        }
    }
}

if (!class_exists('Bricks\\Frontend')) {
    class Frontend {
        /** Dzieci nestable rysuje builder — element tylko je opakowuje. */
        public static function render_children($element) { return '<!-- dzieci -->'; }
    }
}
