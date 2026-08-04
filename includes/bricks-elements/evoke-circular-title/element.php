<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Evk_Circular_Title extends \Bricks\Element {

    public $category     = EVK_BRICKS_CATEGORY;
    public $name         = 'evk-circular-title';
    public $icon         = 'ti-reload';
    public $tag          = 'span';
    public $css_selector = '';
    public $scripts      = ['evk_circular_title_init'];

    // Etykieta musi się zgadzać z evk_elements_registry()['circular_title']['label'].
    public function get_label() {
        return 'Circular Title';
    }

    public function get_keywords() {
        return [ 'evoke', 'circular', 'title', 'kołowy', 'tytuł', 'text', 'rotate' ];
    }

    public function set_control_groups() {
        $this->control_groups['gradient'] = [
            'title' => esc_html__( 'Gradient', 'evoke-circular-title' ),
            'tab'   => 'content',
        ];
    }

    public function set_controls() {

        $this->controls['inner_title'] = [
            'tab'            => 'content',
            'label'          => esc_html__( 'Tekst', 'evoke-circular-title' ),
            'type'           => 'textarea',
            'hasDynamicData' => 'text',
            'default'        => 'circular · title · circular · title · ',
            'placeholder'    => esc_html__( 'Wpisz tekst...', 'evoke-circular-title' ),
        ];

        $this->controls['tag'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Tag HTML', 'evoke-circular-title' ),
            'type'        => 'select',
            'options'     => [
                'h1'   => 'h1',
                'h2'   => 'h2',
                'h3'   => 'h3',
                'h4'   => 'h4',
                'h5'   => 'h5',
                'h6'   => 'h6',
                'p'    => 'p',
                'span' => 'span',
                'div'  => 'div',
            ],
            'inline'      => true,
            'placeholder' => 'span',
            'default'     => 'span',
        ];

        $this->controls['link'] = [
            'tab'   => 'content',
            'label' => esc_html__( 'Odnośnik', 'evoke-circular-title' ),
            'type'  => 'link',
        ];

        $this->controls['styleSeparator'] = [
            'label' => esc_html__( 'Styl', 'evoke-circular-title' ),
            'type'  => 'separator',
        ];

        $this->controls['typography'] = [
            'tab'    => 'content',
            'label'  => esc_html__( 'Typografia', 'evoke-circular-title' ),
            'type'   => 'typography',
            'inline' => true,
            'css'    => [
                [
                    'property' => 'typography',
                    'selector' => '.evk-arc__inner',
                ],
            ],
            'default' => [
                'font-weight'    => '700',
                'font-size'      => '16px',
                'text-transform' => 'uppercase',
            ],
            'exclude' => [ 'line-height', 'letter-spacing' ],
        ];

        $this->controls['width'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Wymiary', 'evoke-circular-title' ),
            'type'        => 'number',
            'units'       => true,
            'inline'      => true,
            'css'         => [
                [
                    'property' => '--evk-dimensions',
                    'selector' => '',
                ],
            ],
            'placeholder' => '200px',
        ];

        $this->controls['spacing'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Odstęp liter', 'evoke-circular-title' ),
            'type'        => 'number',
            'units'       => false,
            'inline'      => true,
            'placeholder' => '1',
        ];

        $this->controls['titleGradient'] = [
            'group' => 'gradient',
            'tab'   => 'content',
            'type'  => 'gradient',
            'css'   => [
                [
                    'property' => 'background-image',
                    'selector' => '.evk-arc__inner',
                ],
            ],
        ];

        $this->controls['animationSeparator'] = [
            'label' => esc_html__( 'Animacja', 'evoke-circular-title' ),
            'type'  => 'separator',
        ];

        $this->controls['velocity'] = [
            'hasDynamicData' => false,
            'tab'   => 'content',
            'label' => esc_html__( 'Przyśpieszaj przy scrollu', 'evoke-circular-title' ),
            'type'  => 'checkbox',
            'default' => false,
        ];

        $this->controls['velocityMultiplier'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Mnożnik prędkości', 'evoke-circular-title' ),
            'type'        => 'number',
            'units'       => false,
            'inline'      => true,
            'placeholder' => '3',
            'required'    => [ 'velocity', '=', true ],
        ];

        $this->controls['reverse'] = [
            'hasDynamicData' => false,
            'tab'      => 'content',
            'label'    => esc_html__( 'Odwróć kierunek', 'evoke-circular-title' ),
            'type'     => 'checkbox',
            'default'  => false,
            'rerender' => true,
        ];

        $this->controls['animationduration'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Czas trwania (s)', 'evoke-circular-title' ),
            'type'        => 'number',
            'unit'        => 's',
            'inline'      => true,
            'css'         => [
                [
                    'property' => '--evk-duration',
                    'selector' => '',
                ],
            ],
            'placeholder' => '9',
            'required'    => [ 'scroll', '!=', true ],
        ];

        $this->controls['easing'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Easing', 'evoke-circular-title' ),
            'type'        => 'text',
            'inline'      => true,
            'css'         => [
                [
                    'property' => '--evk-easing',
                    'selector' => '',
                ],
            ],
            'placeholder' => 'linear',
            'required'    => [ 'scroll', '!=', true ],
        ];

        $this->controls['scroll'] = [
            'hasDynamicData' => false,
            'tab'   => 'content',
            'label' => esc_html__( 'Przypisz do scrolla (GSAP)', 'evoke-circular-title' ),
            'type'  => 'checkbox',
        ];

        $this->controls['scrollstart'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Start', 'evoke-circular-title' ),
            'type'        => 'text',
            'inline'      => true,
            'placeholder' => 'top bottom',
            'required'    => [ 'scroll', '=', true ],
        ];

        $this->controls['scrollend'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Koniec', 'evoke-circular-title' ),
            'type'        => 'text',
            'inline'      => true,
            'placeholder' => 'none',
            'required'    => [ 'scroll', '=', true ],
        ];

        $this->controls['scrub'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Scrub', 'evoke-circular-title' ),
            'type'        => 'text',
            'inline'      => true,
            'placeholder' => 'true',
            'required'    => [ 'scroll', '=', true ],
        ];

        $this->controls['scrollrotation'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Obrót (deg)', 'evoke-circular-title' ),
            'type'        => 'text',
            'inline'      => true,
            'placeholder' => '180',
            'required'    => [ 'scroll', '=', true ],
        ];

        $this->controls['scrolleasing'] = [
            'hasDynamicData' => false,
            'tab'     => 'content',
            'label'   => esc_html__( 'GSAP easing', 'evoke-circular-title' ),
            'type'    => 'select',
            'options' => [
                'none'    => 'none',
                'power1'  => 'power1',
                'power2'  => 'power2',
                'power3'  => 'power3',
                'power4'  => 'power4',
                'back'    => 'back',
                'bounce'  => 'bounce',
                'circ'    => 'circ',
                'elastic' => 'elastic',
                'expo'    => 'expo',
                'sine'    => 'sine',
                'steps'   => 'steps',
            ],
            'inline'      => true,
            'placeholder' => 'none',
            'required'    => [ 'scroll', '=', true ],
        ];
    }

    public function enqueue_scripts() {
        wp_enqueue_style(
            'evk-circular-title',
            EVK_CIRCULAR_URL . 'assets/circular-title.css',
            [],
            EVK_CIRCULAR_VERSION
        );
        wp_enqueue_script(
            'evk-circular-title-js',
            EVK_CIRCULAR_URL . 'assets/circular-title.js',
            [ 'bricks-scripts' ],
            EVK_CIRCULAR_VERSION,
            true
        );
    }

    public function render() {
        $s = $this->settings;

        $text           = ! empty( $s['inner_title'] )        ? $s['inner_title']        : 'circular · title · ';
        $spacing        = ! empty( $s['spacing'] )            ? $s['spacing']            : '1';
        $reverse        = ! empty( $s['reverse'] )            ? '1'                      : '0';
        $velocity       = ! empty( $s['velocity'] );
        $velocity_mult  = ! empty( $s['velocityMultiplier'] ) ? $s['velocityMultiplier'] : '3';
        $scroll         = ! empty( $s['scroll'] ) && ! $velocity;
        $scrollstart    = ! empty( $s['scrollstart'] )        ? $s['scrollstart']        : 'top bottom';
        $scrollend      = ! empty( $s['scrollend'] )          ? $s['scrollend']          : 'none';
        $scrub          = ! empty( $s['scrub'] )              ? $s['scrub']              : 'true';
        $scrollrotation = ! empty( $s['scrollrotation'] )     ? $s['scrollrotation']     : '180';
        $scrolleasing   = ! empty( $s['scrolleasing'] )       ? $s['scrolleasing']       : 'none';
        $inner_tag      = ! empty( $s['tag'] )                ? esc_attr( $s['tag'] )    : 'span';

        $this->set_attribute( '_root', 'class', 'evk-arc-title' );
        $this->set_attribute( '_root', 'data-flickering', '1' );
        $this->set_attribute( '_root', 'data-spacing', esc_attr( $spacing ) );

        if ( $reverse === '1' ) {
            $this->set_attribute( '_root', 'data-reverse', '1' );
        }

        $scroll_attrs = '';
        if ( $velocity ) {
            $scroll_attrs = " data-scroll-velocity='true'"
                . " data-velocity-multiplier='" . esc_attr( $velocity_mult ) . "'";
        } elseif ( $scroll ) {
            $scroll_attrs = " data-scroll='true'"
                . " data-scroll-start='" . esc_attr( $scrollstart ) . "'"
                . " data-scroll-end='" . esc_attr( $scrollend ) . "'"
                . " data-scrub='" . esc_attr( $scrub ) . "'"
                . " data-scroll-rotation='" . esc_attr( $scrollrotation ) . "'"
                . " data-scroll-easing='" . esc_attr( $scrolleasing ) . "'";
        }

        if ( ! empty( $s['link'] ) ) {
            $this->set_link_attributes( '_root', $s['link'] );
            $root_tag = 'a';
        } else {
            $root_tag = 'div';
        }

        $text_attr = esc_attr( $text );

        echo "<{$root_tag} {$this->render_attributes( '_root' )}{$scroll_attrs}>";
        echo "<{$inner_tag} class='evk-arc__inner' data-content='{$text_attr}'>{$text}</{$inner_tag}>";
        echo "</{$root_tag}>";
    }
}
