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
        /* Wpis, w którego kontekście Bricks rysuje element — element podaje go
           dalej do danych dynamicznych. */
        public $post_id    = 0;

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

        /**
         * Pudełko zastępcze na kanwie buildera.
         *
         * Prawdziwy Bricks je DRUKUJE, a nie zwraca — element woła je przez
         * `return $this->render_element_placeholder(...)` i liczy na to, że coś
         * z tego wyjdzie. Atrapa robi tak samo, bo inaczej „element mówi, że nie
         * ma czego pokazać" nie dałoby się zmierzyć.
         */
        public function render_element_placeholder($args = []) {
            echo '<div class="bricks-element-placeholder">'
               . esc_html($args['title'] ?? '') . '</div>';
        }

        /**
         * Ikona z kontrolki `'type' => 'icon'`.
         *
         * JEDYNE miejsce, w którym atrapa odwzorowuje KSZTAŁT cudzego API, a nie
         * tylko to, czego element dotyka — i dlatego warto wiedzieć, czego ta
         * atrapa NIE sprawdzi. Prawdziwy Bricks obsługuje tu jeszcze ikony
         * wgrane jako SVG (wtedy wkleja plik) i bierze pod uwagę bibliotekę
         * ikon wybraną w builderze. Odwzorowany jest wariant fontowy, bo tylko
         * on decyduje o tym, co robi element: że ikona wychodzi jednym węzłem
         * wewnątrz naszego opakowania.
         */
        public static function render_icon($icon, $attributes = []) {
            if (empty($icon)) return '';
            $klasy = trim(($icon['library'] ?? '') . ' ' . ($icon['icon'] ?? ''));
            $attributes['class'] = trim(($attributes['class'] ?? '') . ' ' . $klasy);
            $out = '';
            foreach ($attributes as $k => $v) $out .= ' ' . $k . '="' . esc_attr($v) . '"';
            return '<i' . $out . '></i>';
        }
    }
}

if (!class_exists('Bricks\\Frontend')) {
    class Frontend {
        /** Dzieci nestable rysuje builder — element tylko je opakowuje. */
        public static function render_children($element) { return '<!-- dzieci -->'; }
    }
}
