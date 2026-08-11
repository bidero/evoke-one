<?php
namespace Bricks;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Evoke ONE — Offcanvas Menu
 *
 * Jeden element, dwa tryby (patrz docs/offcanvas-menu-szkic.md):
 *
 *  • „swobodny panel" — jeden panel, treść w całości z buildera;
 *  • „poziomy"        — kilka paneli, przejścia atrybutami na dowolnym
 *                       elemencie: `data-evk-oc-go="uslugi"` i `data-evk-oc-back`.
 *
 * Oba tryby dzielą CAŁĄ mechanikę: wysuwanie, przyciemnienie, blokada
 * przewijania, pułapka fokusu, Esc, powrót fokusu na trigger. Różni je tylko
 * to, ile paneli jest w środku — dlatego to jeden element, a nie dwa.
 *
 * Panele są dziećmi nestable, nie są generowane z menu WordPressa. To była
 * świadoma decyzja: pozycją menu może być cokolwiek — kafelek z obrazkiem,
 * siatka, blok kontaktowy — bo element nie narzuca znaczników.
 */
class Evk_Offcanvas_Menu extends \Bricks\Element {

	public $category = \EVK_BRICKS_CATEGORY;
	public $name     = 'evk-offcanvas-menu';
	public $icon     = 'ti-layout-sidebar-left';
	// Nazwa funkcji JS, którą Bricks woła po wyrenderowaniu elementu w canvasie
	// — musi się zgadzać z assets/offcanvas-menu.js.
	public $scripts  = [ 'evk_offcanvas_menu_init' ];
	public $nestable = true;

	// Etykieta MUSI się zgadzać z evk_elements_registry()['offcanvas_menu']['label'].
	public function get_label() {
		return 'Offcanvas Menu';
	}

	public function get_keywords() {
		return [ 'evoke', 'offcanvas', 'menu', 'nav', 'burger', 'drawer', 'panel', 'sidebar' ];
	}

	/**
	 * Trzy dzieci domyślne: trigger i dwa panele.
	 *
	 * Dwa, nie jeden — w trybie „poziomy" jeden panel nie pokazuje niczego,
	 * czego nie umie „swobodny panel", więc świeżo wstawiony element od razu
	 * ma na czym pokazać przejście. W trybie swobodnym drugi panel po prostu
	 * się usuwa.
	 */
	public function get_nestable_children() {
		return [
			[
				'name'     => 'div',
				'label'    => esc_html__( 'Trigger (burger)', 'evoke-one' ),
				'settings' => [ '_hidden' => [ '_cssClasses' => 'evk-oc-trigger' ] ],
			],
			[
				'name'     => 'block',
				'label'    => esc_html__( 'Panel startowy', 'evoke-one' ),
				'settings' => [ '_hidden' => [ '_cssClasses' => 'evk-oc-panel' ] ],
			],
			[
				'name'     => 'block',
				'label'    => esc_html__( 'Panel podrzędny', 'evoke-one' ),
				'settings' => [ '_hidden' => [ '_cssClasses' => 'evk-oc-panel' ] ],
			],
		];
	}

	public function set_controls() {

		$this->controls['mode'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Tryb', 'evoke-one' ),
			'type'        => 'select',
			'options'     => [
				'single' => esc_html__( 'Swobodny panel (jeden)', 'evoke-one' ),
				'levels' => esc_html__( 'Poziomy (kilka paneli)', 'evoke-one' ),
			],
			'default'     => 'single',
			'description' => esc_html__(
				'W trybie „poziomy" przejście robi dowolny element z atrybutem data-evk-oc-go="ID panelu", '
				. 'a powrót — data-evk-oc-back. ID panelu ustawiasz atrybutem data-panel; bez niego liczy się kolejność.',
				'evoke-one'
			),
		];

		$this->controls['startPanel'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Panel startowy (ID)', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'pierwszy w kolejności', 'evoke-one' ),
			'required'    => [ 'mode', '=', 'levels' ],
		];

		$this->controls['side'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Z której strony', 'evoke-one' ),
			'type'    => 'select',
			'options' => [
				'left'   => esc_html__( 'Z lewej', 'evoke-one' ),
				'right'  => esc_html__( 'Z prawej', 'evoke-one' ),
				'top'    => esc_html__( 'Z góry', 'evoke-one' ),
				'bottom' => esc_html__( 'Z dołu', 'evoke-one' ),
			],
			'default' => 'right',
		];

		$this->controls['panelWidth'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Szerokość / wysokość panelu', 'evoke-one' ),
			'type'    => 'text',
			'default' => 'min(420px, 100%)',
			'css'     => [ [ 'property' => '--evk-oc-size', 'selector' => '' ] ],
		];

		$this->controls['duration'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Czas wysuwania (s)', 'evoke-one' ),
			'type'    => 'number',
			'min'     => 0,
			'max'     => 3,
			'step'    => 0.05,
			'default' => 0.35,
		];

		// Ta sama lista, co w Animatorze — jedna lista dla całej wtyczki znaczy,
		// że dorzucenie krzywej działa wszędzie naraz.
		$easings = [ '' => esc_html__( '— domyślny —', 'evoke-one' ) ];
		if ( function_exists( 'evk_anim_easings' ) ) {
			foreach ( evk_anim_easings() as $e ) $easings[ $e ] = $e;
		}
		$this->controls['easing'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Krzywa', 'evoke-one' ),
			'type'    => 'select',
			'options' => $easings,
			'default' => '',
		];

		$this->controls['triggerSelector'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Dodatkowy trigger (selektor)', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => '.moj-burger',
			'description' => esc_html__( 'Gdy burger siedzi poza tym elementem — np. w nagłówku zbudowanym osobno.', 'evoke-one' ),
		];

		$this->controls['escGoesBack'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Esc cofa o poziom', 'evoke-one' ),
			'type'        => 'checkbox',
			'default'     => true,
			'description' => esc_html__( 'Na panelu startowym Esc zamyka. Wyłączone: Esc zawsze zamyka.', 'evoke-one' ),
			'required'    => [ 'mode', '=', 'levels' ],
		];

		$this->controls['closeOnLinkClick'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Zamknij po kliknięciu w odnośnik', 'evoke-one' ),
			'type'    => 'checkbox',
			'default' => true,
		];

		$this->controls['lockScroll'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Blokuj przewijanie strony', 'evoke-one' ),
			'type'    => 'checkbox',
			'default' => true,
		];

		$this->controls['toBody'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Przenieś do <body>', 'evoke-one' ),
			'type'        => 'checkbox',
			'default'     => true,
			'description' => esc_html__( 'Panel nie jest wtedy ograniczany przez overflow:hidden ani position rodziców.', 'evoke-one' ),
		];

		$this->controls['openInBuilder'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Trzymaj otwarte w builderze', 'evoke-one' ),
			'type'    => 'checkbox',
			'default' => false,
		];
	}

	public function enqueue_scripts() {
		wp_enqueue_style(
			'evk-offcanvas-menu',
			EVK_OFFCANVAS_MENU_URL . 'assets/offcanvas-menu.css',
			[],
			EVK_OFFCANVAS_MENU_VERSION
		);
		wp_enqueue_script(
			'evk-offcanvas-menu-js',
			EVK_OFFCANVAS_MENU_URL . 'assets/offcanvas-menu.js',
			[ 'bricks-scripts' ],
			EVK_OFFCANVAS_MENU_VERSION,
			true
		);
	}

	public function render() {
		$s = $this->settings;

		$this->set_attribute( '_root', 'class', 'evk-oc' );
		$this->set_attribute( '_root', 'data-mode',       ! empty( $s['mode'] ) ? $s['mode'] : 'single' );
		$this->set_attribute( '_root', 'data-side',       ! empty( $s['side'] ) ? $s['side'] : 'right' );
		$this->set_attribute( '_root', 'data-duration',   isset( $s['duration'] ) && $s['duration'] !== '' ? (string) $s['duration'] : '0.35' );
		$this->set_attribute( '_root', 'data-easing',     ! empty( $s['easing'] ) ? $s['easing'] : '' );
		$this->set_attribute( '_root', 'data-start',      ! empty( $s['startPanel'] ) ? $s['startPanel'] : '' );
		$this->set_attribute( '_root', 'data-trigger',    ! empty( $s['triggerSelector'] ) ? $s['triggerSelector'] : '' );

		/*
		 * Wartości logiczne wprost jako '1'/'0', nie przez pominięcie atrybutu.
		 * Checkbox z `default => true` przy odznaczeniu przysyła pustą wartość,
		 * a nie brak klucza — `! empty()` czyta to poprawnie, ale JS musi
		 * dostać jawne „nie", inaczej nie odróżni go od „nie ustawiono".
		 */
		$this->set_attribute( '_root', 'data-esc-back',   ! empty( $s['escGoesBack'] )      ? '1' : '0' );
		$this->set_attribute( '_root', 'data-close-link', ! empty( $s['closeOnLinkClick'] ) ? '1' : '0' );
		$this->set_attribute( '_root', 'data-lock',       ! empty( $s['lockScroll'] )       ? '1' : '0' );
		$this->set_attribute( '_root', 'data-portal',     ! empty( $s['toBody'] )           ? '1' : '0' );
		$this->set_attribute( '_root', 'data-open-builder', ! empty( $s['openInBuilder'] )  ? '1' : '0' );

		echo "<div {$this->render_attributes( '_root' )}>"
		   . Frontend::render_children( $this )
		   . '</div>';
	}
}
