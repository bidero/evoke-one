<?php
namespace Bricks;
if ( ! defined( 'ABSPATH' ) ) exit;

class Evk_Circular_Menu extends \Bricks\Element {

	public $category = \EVK_BRICKS_CATEGORY;
	public $name     = 'evk-circular-menu';
	public $icon     = 'ti-menu-alt';
	// Nazwa funkcji JS, którą Bricks woła przy renderowaniu elementu —
	// musi się zgadzać z assets/circular-menu.js.
	public $scripts  = ['evk_circular_menu_init'];
	public $nestable = true;

	// Etykieta musi się zgadzać z evk_elements_registry()['circular_menu']['label'].
	public function get_label() {
		return 'Circular Menu';
	}

	public function get_keywords() {
		return [ 'evoke', 'circular', 'menu', 'hamburger', 'nav', 'toggle', 'fullscreen' ];
	}

	/**
	 * Dwa dzieci domyślne:
	 *  1. div z klasą evk-cm-trigger  — trigger (wrzuć tu przycisk)
	 *  2. block (div) z klasą evk-cm-content — panel menu
	 *
	 * JS szuka: .evk-cm-trigger  i  .evk-cm-content
	 */
	public function get_nestable_children() {
		return [
			[
				'name'  => 'div',
				'label' => esc_html__( 'Trigger (burger)', 'evk-circular-menu' ),
				'settings' => [
					'_hidden' => [
						'_cssClasses' => 'evk-cm-trigger',
					],
				],
			],
			[
				'name'  => 'block',
				'label' => esc_html__( 'Zawartość menu', 'evk-circular-menu' ),
				'settings' => [
					'_hidden' => [
						'_cssClasses' => 'evk-cm-content',
					],
				],
			],
		];
	}

	public function set_controls() {

		$this->controls['openbuilder'] = [
			'hasDynamicData' => false,
			'tab'   => 'content',
			'label' => esc_html__( 'Otwórz w builderze', 'evk-circular-menu' ),
			'type'  => 'checkbox',
		];

		// ----- Lokalizacja -----
		$this->controls['locationSeparator'] = [
			'label' => esc_html__( 'Lokalizacja', 'evk-circular-menu' ),
			'type'  => 'separator',
		];
		$this->controls['portalToBody'] = [
			'hasDynamicData' => false,
			'tab'    => 'content',
			'label'  => esc_html__( 'Portal do &lt;body&gt;', 'evk-circular-menu' ),
			'type'   => 'checkbox',
			'inline' => true,
			'small'  => true,
			'default' => true,
			'description' => esc_html__( 'Przenosi panel menu bezpośrednio do <body>, dzięki czemu nie jest ograniczany przez overflow:hidden ani position rodziców.', 'evk-circular-menu' ),
		];
		$this->controls['fromTop'] = [
			'hasDynamicData' => false,
			'tab'         => 'content',
			'label'       => esc_html__( 'Góra (punkt rozwinięcia)', 'evk-circular-menu' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [
				[
					'property' => '--evk-cm-from-top',
					'selector' => '.evk-cm-content',
				],
			],
			'placeholder' => '24px',
			'default'     => '24px',
		];
		$this->controls['fromLeft'] = [
			'hasDynamicData' => false,
			'tab'         => 'content',
			'label'       => esc_html__( 'Lewa (punkt rozwinięcia)', 'evk-circular-menu' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [
				[
					'property' => '--evk-cm-from-left',
					'selector' => '.evk-cm-content',
				],
			],
			'placeholder' => '24px',
			'default'     => '24px',
		];

		// ----- Custom toggle -----
		$this->controls['toggleSeparator'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Własny przełącznik', 'evk-circular-menu' ),
			'type'        => 'separator',
			'description' => esc_html__( 'Elementy z tą klasą będą otwierać/zamykać menu.', 'evk-circular-menu' ),
		];
		$this->controls['customtoggle'] = [
			'label'       => esc_html__( 'Selektor CSS', 'evk-circular-menu' ),
			'type'        => 'text',
			'placeholder' => '.moj-burger',
		];
		$this->controls['lockBodyScrolling'] = [
			'label'   => esc_html__( 'Blokuj scroll strony', 'evk-circular-menu' ),
			'type'    => 'checkbox',
			'inline'  => true,
			'small'   => true,
			'default' => false,
		];

		// ----- Animacja -----
		$this->controls['animationSeparator'] = [
			'label' => esc_html__( 'Animacja', 'evk-circular-menu' ),
			'type'  => 'separator',
		];
		$this->controls['duration'] = [
			'label'       => esc_html__( 'Czas trwania', 'evk-circular-menu' ),
			'type'        => 'number',
			'unit'        => 's',
			'inline'      => true,
			'placeholder' => '0.4',
		];
		$this->controls['easing'] = [
			'hasDynamicData' => false,
			'tab'     => 'content',
			'label'   => esc_html__( 'GSAP easing', 'evk-circular-menu' ),
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
				'custom'  => 'własny',
			],
			'inline'      => true,
			'placeholder' => 'none',
		];
		$this->controls['customEasing'] = [
			'hasDynamicData' => false,
			'tab'         => 'content',
			'label'       => esc_html__( 'Własny easing', 'evk-circular-menu' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => 'back.out(1.7)',
			'default'     => 'back.out(1.7)',
			'required'    => [ 'easing', '=', 'custom' ],
		];

		// ----- Styl zawartości -----
		$this->controls['contentseparator'] = [
			'label'       => esc_html__( 'Styl zawartości', 'evk-circular-menu' ),
			'description' => esc_html__( 'Możesz też edytować style bezpośrednio na elemencie Zawartość menu.', 'evk-circular-menu' ),
			'type'        => 'separator',
		];
		$this->controls['width'] = [
			'hasDynamicData' => false,
			'tab'         => 'content',
			'label'       => esc_html__( 'Szerokość', 'evk-circular-menu' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [
				[
					'property' => 'width',
					'selector' => '.evk-cm-content',
				],
			],
			'placeholder' => '100svw',
			'default'     => '100svw',
		];
		$this->controls['height'] = [
			'hasDynamicData' => false,
			'tab'         => 'content',
			'label'       => esc_html__( 'Wysokość', 'evk-circular-menu' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [
				[
					'property' => 'height',
					'selector' => '.evk-cm-content',
				],
			],
			'placeholder' => '100svh',
			'default'     => '100svh',
		];
		$this->controls['background'] = [
			'hasDynamicData' => false,
			'tab'   => 'content',
			'label' => esc_html__( 'Tło', 'evk-circular-menu' ),
			'type'  => 'background',
			'units' => true,
			'css'   => [
				[
					'property' => 'background',
					'selector' => '.evk-cm-content',
				],
			],
			'default' => [
				'color' => [ 'hex' => '#c4c4c4' ],
			],
		];

		// ----- Dostępność -----
		$this->controls['accessibilitySeparator'] = [
			'label' => esc_html__( 'Dostępność', 'evk-circular-menu' ),
			'type'  => 'separator',
		];
		$this->controls['closeOnEsc'] = [
			'label'   => esc_html__( 'Zamknij klawiszem ESC', 'evk-circular-menu' ),
			'type'    => 'checkbox',
			'inline'  => true,
			'small'   => true,
			'default' => true,
		];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'evk-gsap' ); // wspólny handle Evoke ONE (dedup)
		wp_enqueue_style(
			'evk-circular-menu',
			EVK_CIRCULAR_MENU_URL . 'assets/circular-menu.css',
			[],
			EVK_CIRCULAR_MENU_VERSION
		);
		wp_enqueue_script(
			'evk-circular-menu-js',
			EVK_CIRCULAR_MENU_URL . 'assets/circular-menu.js',
			[ 'evk-gsap', 'bricks-scripts' ],
			EVK_CIRCULAR_MENU_VERSION,
			true
		);
	}

	public function render() {
		$settings = $this->settings;

		$openbuilder       = ! empty( $settings['openbuilder'] )       ? $settings['openbuilder']       : 0;
		$portalToBody      = ! empty( $settings['portalToBody'] )      ? '1' : '0';
		$duration          = ! empty( $settings['duration'] )          ? $settings['duration']          : '0.4';
		$easing            = ! empty( $settings['easing'] )            ? $settings['easing']            : 'none';
		if ( $easing === 'custom' ) {
			$easing = ! empty( $settings['customEasing'] ) ? $settings['customEasing'] : 'none';
		}
		$customtoggle      = ! empty( $settings['customtoggle'] )      ? $settings['customtoggle']      : '';
		$lockBodyScrolling = ! empty( $settings['lockBodyScrolling'] ) ? '1' : '0';
		$closeOnEsc        = ! empty( $settings['closeOnEsc'] )        ? '1' : '0';

		$this->set_attribute( '_root', 'class',                                 'evk-cm' );
		$this->set_attribute( '_root', 'data-portal',                           $portalToBody );
		$this->set_attribute( '_root', 'data-duration',                         $duration );
		$this->set_attribute( '_root', 'data-easing',                           $easing );
		$this->set_attribute( '_root', 'data-customtoggle',                     $customtoggle );
		$this->set_attribute( '_root', 'data-lock-scroll',                      $lockBodyScrolling );
		$this->set_attribute( '_root', 'data-open-builder',                     $openbuilder );
		$this->set_attribute( '_root', 'data-close-on-esc',                     $closeOnEsc );

		$output  = "<div {$this->render_attributes( '_root' )}>";
		$output .= Frontend::render_children( $this );
		$output .= "</div>";

		echo $output;
	}
}
