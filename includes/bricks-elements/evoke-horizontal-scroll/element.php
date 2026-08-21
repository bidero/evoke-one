<?php
defined( 'ABSPATH' ) || exit;

class Evk_Horizontal_Scroll_Element extends \Bricks\Element {

	public $category = EVK_BRICKS_CATEGORY;
	public $name     = 'evk-horizontal-scroll';
	public $icon     = 'ti-arrows-horizontal';
	public $tag      = 'div';
	public $nestable = true;

	// Etykieta musi się zgadzać z evk_elements_registry()['hscroll']['label'].
	public function get_label() {
		return 'Horizontal Scroll';
	}

	public function get_keywords() {
		return [ 'evoke', 'horizontal', 'scroll', 'poziomy', 'pin', 'slider' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'evk-horizontal-scroll' );
		wp_enqueue_style( 'evk-horizontal-scroll' );
	}

	// Element dodawany przyciskiem "+" wewnątrz nestable.
	public function get_nestable_item() {
		return [
			'name'     => 'block',
			'label'    => esc_html__( 'Panel', 'evk-horizontal-scroll' ),
			'settings' => [],
			'children' => [
				[
					'name'     => 'heading',
					'settings' => [
						'text' => esc_html__( 'Panel', 'evk-horizontal-scroll' ),
						'tag'  => 'h2',
					],
				],
			],
		];
	}

	// Domyślne dzieci przy pierwszym dodaniu elementu.
	public function get_nestable_children() {
		$children = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$item                                    = $this->get_nestable_item();
			$item['children'][0]['settings']['text'] = 'Panel ' . $i;
			$children[]                              = $item;
		}
		return $children;
	}

	public function set_control_groups() {
		$this->control_groups['evk_progress'] = [
			'title' => esc_html__( 'Pasek postępu', 'evk-horizontal-scroll' ),
			'tab'   => 'content',
		];
	}

	public function set_controls() {

		// ── INFO ────────────────────────────────────────────────────────────
		$this->controls['note0'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Jak używać', 'evk-horizontal-scroll' ),
		];
		$this->controls['note1'] = [
			'tab'         => 'content',
			'type'        => 'info',
			'description' => esc_html__( 'Wrzuć w środku kolejne panele (sekcje / bloki). Na froncie ułożą się poziomo: scroll w pionie przesuwa je w poziomie, a po ostatnim panelu strona przewija się dalej normalnie. W edytorze panele są ułożone pionowo (do edycji).', 'evk-horizontal-scroll' ),
		];

		// ── UKŁAD ───────────────────────────────────────────────────────────
		$this->controls['sep_layout'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Układ paneli', 'evk-horizontal-scroll' ),
		];

		$this->controls['width_mode'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Szerokość panelu', 'evk-horizontal-scroll' ),
			'type'        => 'select',
			'options'     => [
				'fill'     => esc_html__( 'Wypełnij element (100% szerokości)', 'evk-horizontal-scroll' ),
				'viewport' => esc_html__( 'Pełny ekran (100vw)', 'evk-horizontal-scroll' ),
				'auto'     => esc_html__( 'Z buildera — nie ruszaj rozmiarów', 'evk-horizontal-scroll' ),
			],
			'default'     => 'fill',
			'description' => esc_html__(
				'Dwa pierwsze tryby narzucają panelom szerokość i wysokość. '
				. '„Z buildera" nie rusza żadnego rozmiaru: karty stylujesz zwyczajnie '
				. 'w Bricksie, a skrypt liczy tylko, o ile przesunąć taśmę. Ten tryb '
				. 'wybierz, gdy karty mają być WĘŻSZE od ekranu i jechać pod nieruchomym '
				. 'nagłówkiem — razem z opcją „Co przypiąć".',
				'evk-horizontal-scroll'
			),
		];

		/*
		 * Co zostaje przypięte do ekranu.
		 *
		 * Domyślnie sam element — tak działało do 1.100.0 i tak zostaje każdemu,
		 * kto niczego nie zmienia. Przypięcie PRZODKA daje układ, w którym sekcja
		 * z nagłówkiem stoi w miejscu, a pod nagłówkiem jedzie taśma kart.
		 */
		$this->controls['pin_target'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Co przypiąć', 'evk-horizontal-scroll' ),
			'type'        => 'select',
			'options'     => [
				'self'     => esc_html__( 'Ten element', 'evk-horizontal-scroll' ),
				'parent'   => esc_html__( 'Bezpośredniego rodzica', 'evk-horizontal-scroll' ),
				'selector' => esc_html__( 'Przodka wskazanego selektorem', 'evk-horizontal-scroll' ),
			],
			'default'     => 'self',
			'description' => esc_html__(
				'Przypięcie przodka zostawia nagłówek sekcji nieruchomo na ekranie, '
				. 'a przesuwa tylko taśmę kart.',
				'evk-horizontal-scroll'
			),
		];

		$this->controls['pin_selector'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Selektor przodka', 'evk-horizontal-scroll' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => '.moja-sekcja',
			'required'    => [ 'pin_target', '=', 'selector' ],
			'description' => esc_html__(
				'Szukany jest PRZODEK tego elementu, nie pierwszy pasujący na stronie — '
				. 'dzięki temu dwie takie sekcje na jednej stronie nie wchodzą sobie w drogę. '
				. 'Gdy nic nie pasuje, element przypina sam siebie i mówi o tym w konsoli.',
				'evk-horizontal-scroll'
			),
		];

		$this->controls['panel_height'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Wysokość panelu', 'evk-horizontal-scroll' ),
			'type'        => 'text',
			'inline'      => true,
			'default'     => '100vh',
			'placeholder' => '100vh',
			'description' => esc_html__( 'np. 100vh, 800px. Pusta = automatyczna.', 'evk-horizontal-scroll' ),
			// W trybie „z buildera" skrypt nie rusza wysokości, więc pole nic nie robi.
			'required'    => [ 'width_mode', '!=', 'auto' ],
		];

		// ── ANIMACJA ──────────────────────────────────────────────────────────
		$this->controls['sep_anim'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Animacja / ScrollTrigger', 'evk-horizontal-scroll' ),
		];

		$this->controls['scrub'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Płynność scrolla (scrub)', 'evk-horizontal-scroll' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 3,
			'step'        => 0.1,
			'default'     => 1,
			'description' => esc_html__( '0 = przyklejone do scrolla, wyższa wartość = bardziej miękkie podążanie.', 'evk-horizontal-scroll' ),
		];

		$this->controls['start_offset'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Start (ScrollTrigger)', 'evk-horizontal-scroll' ),
			'type'        => 'text',
			'inline'      => true,
			'default'     => 'top top',
			'placeholder' => 'top top',
			'description' => esc_html__(
				'Punkt rozpoczęcia przypięcia, np. „top top". Przyjmuje też przesunięcie: '
				. '„top top+=100" przypnie sto pikseli niżej — tyle, ile zajmuje przyklejony nagłówek.',
				'evk-horizontal-scroll'
			),
		];

		// ── SNAP ──────────────────────────────────────────────────────────────
		$this->controls['sep_snap'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Snap', 'evk-horizontal-scroll' ),
		];

		$this->controls['snap'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Przyciągaj do paneli', 'evk-horizontal-scroll' ),
			'type'        => 'checkbox',
			'default'     => true,
			'description' => esc_html__( 'Po zatrzymaniu scrolla widok dociąga się do najbliższego panelu.', 'evk-horizontal-scroll' ),
		];

		$this->controls['snap_duration'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Czas snapu (s)', 'evk-horizontal-scroll' ),
			'type'     => 'number',
			'min'      => 0.1,
			'max'      => 2,
			'step'     => 0.05,
			'default'  => 0.5,
			'required' => [ 'snap', '=', true ],
		];

		// ── RESPONSYWNOŚĆ ─────────────────────────────────────────────────────
		$this->controls['sep_responsive'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Responsywność', 'evk-horizontal-scroll' ),
		];

		$this->controls['disable_below'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Wyłącz poniżej (px)', 'evk-horizontal-scroll' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 2000,
			'step'        => 1,
			'default'     => 991,
			'description' => esc_html__( 'Poniżej tej szerokości ekranu panele układają się pionowo (bez przypięcia). 0 = nigdy nie wyłączaj.', 'evk-horizontal-scroll' ),
		];

		// ── PASEK POSTĘPU ─────────────────────────────────────────────────────
		$this->controls['progressbar'] = [
			'group'   => 'evk_progress',
			'tab'     => 'content',
			'label'   => esc_html__( 'Włącz pasek postępu', 'evk-horizontal-scroll' ),
			'type'    => 'checkbox',
			'default' => false,
		];

		$this->controls['progressbar_style'] = [
			'group'       => 'evk_progress',
			'tab'         => 'content',
			'label'       => esc_html__( 'Styl', 'evk-horizontal-scroll' ),
			'type'        => 'select',
			'options'     => [
				'bar'      => esc_html__( 'Jedna kreska rosnąca', 'evk-horizontal-scroll' ),
				'segments' => esc_html__( 'Segmenty — po jednym na kartę', 'evk-horizontal-scroll' ),
			],
			'default'     => 'bar',
			'inline'      => true,
			'required'    => [ 'progressbar', '=', true ],
			'description' => esc_html__(
				'Segmentów jest tyle, ile paneli; bieżący jest podświetlony.',
				'evk-horizontal-scroll'
			),
		];

		$this->controls['progressbar_position'] = [
			'group'    => 'evk_progress',
			'tab'      => 'content',
			'label'    => esc_html__( 'Pozycja', 'evk-horizontal-scroll' ),
			'type'     => 'select',
			'options'  => [
				'top'    => esc_html__( 'Góra', 'evk-horizontal-scroll' ),
				'bottom' => esc_html__( 'Dół', 'evk-horizontal-scroll' ),
			],
			'default'  => 'top',
			'inline'   => true,
			'required' => [ 'progressbar', '=', true ],
		];

		$this->controls['progressbar_thickness'] = [
			'group'       => 'evk_progress',
			'tab'         => 'content',
			'label'       => esc_html__( 'Grubość', 'evk-horizontal-scroll' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'placeholder' => '4px',
			'css'         => [
				[
					'property' => 'height',
					'selector' => '.evk-hscroll__progress',
				],
			],
			'required'    => [ 'progressbar', '=', true ],
		];

		$this->controls['progressbar_bg'] = [
			'group'    => 'evk_progress',
			'tab'      => 'content',
			'label'    => esc_html__( 'Kolor tła', 'evk-horizontal-scroll' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => 'background-color',
					'selector' => '.evk-hscroll__progress',
				],
			],
			'required' => [ 'progressbar', '=', true ],
		];

		$this->controls['progressbar_color'] = [
			'group'    => 'evk_progress',
			'tab'      => 'content',
			'label'    => esc_html__( 'Kolor paska', 'evk-horizontal-scroll' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => 'background-color',
					'selector' => '.evk-hscroll__progress-bar',
				],
			],
			'required' => [ 'progressbar', '=', true, 'progressbar_style', '=', 'bar' ],
		];

		/*
		 * Segmenty mają własne kolory, a nie te od paska.
		 *
		 * Pasek ma dwie warstwy: tło pudełka i kreskę na nim. Segmenty mają
		 * kreski nieaktywne i jedną aktywną — to inne role, a nie te same pod
		 * inną nazwą, więc dostają własne pola zamiast dziedziczyć mylące.
		 * Wartości jadą zmiennymi CSS, którymi arkusz maluje segmenty.
		 */
		$this->controls['seg_gap'] = [
			'group'       => 'evk_progress',
			'tab'         => 'content',
			'label'       => esc_html__( 'Odstęp segmentów', 'evk-horizontal-scroll' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'placeholder' => '8px',
			'css'         => [
				[
					'property' => '--evk-seg-gap',
					'selector' => '',
				],
			],
			'required'    => [ 'progressbar', '=', true, 'progressbar_style', '=', 'segments' ],
		];

		$this->controls['seg_off'] = [
			'group'    => 'evk_progress',
			'tab'      => 'content',
			'label'    => esc_html__( 'Kolor segmentu nieaktywnego', 'evk-horizontal-scroll' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--evk-seg-off',
					'selector' => '',
				],
			],
			'required' => [ 'progressbar', '=', true, 'progressbar_style', '=', 'segments' ],
		];

		$this->controls['seg_on'] = [
			'group'    => 'evk_progress',
			'tab'      => 'content',
			'label'    => esc_html__( 'Kolor segmentu aktywnego', 'evk-horizontal-scroll' ),
			'type'     => 'color',
			'css'      => [
				[
					'property' => '--evk-seg-on',
					'selector' => '',
				],
			],
			'required' => [ 'progressbar', '=', true, 'progressbar_style', '=', 'segments' ],
		];
	}

	public function render() {
		$settings = $this->settings;

		$width_mode    = ! empty( $settings['width_mode'] ) ? $settings['width_mode'] : 'fill';
		$panel_height  = isset( $settings['panel_height'] ) && $settings['panel_height'] !== '' ? $settings['panel_height'] : '100vh';
		$scrub         = isset( $settings['scrub'] ) && $settings['scrub'] !== '' ? (float) $settings['scrub'] : 1;
		$start_offset  = ! empty( $settings['start_offset'] ) ? $settings['start_offset'] : 'top top';
		$snap          = ! isset( $settings['snap'] ) ? true : ! empty( $settings['snap'] );
		$snap_duration = isset( $settings['snap_duration'] ) && $settings['snap_duration'] !== '' ? (float) $settings['snap_duration'] : 0.5;
		$disable_below = isset( $settings['disable_below'] ) && $settings['disable_below'] !== '' ? (int) $settings['disable_below'] : 991;
		$progressbar   = ! empty( $settings['progressbar'] );
		$pb_position   = ! empty( $settings['progressbar_position'] ) ? $settings['progressbar_position'] : 'top';
		$pb_style      = ! empty( $settings['progressbar_style'] ) ? $settings['progressbar_style'] : 'bar';
		$pin_target    = ! empty( $settings['pin_target'] ) ? $settings['pin_target'] : 'self';
		$pin_selector  = ! empty( $settings['pin_selector'] ) ? $settings['pin_selector'] : '';

		$cfg = esc_attr( wp_json_encode( [
			'widthMode'    => $width_mode,
			'pinTarget'    => $pin_target,
			'pinSelector'  => $pin_selector,
			'progressStyle'=> $pb_style,
			'scrub'        => $scrub,
			'startOffset'  => $start_offset,
			'snap'         => $snap,
			'snapDuration' => $snap_duration,
			'disableBelow' => $disable_below,
			'progressBar'  => $progressbar,
		] ) );

		// Detekcja edytora Bricks.
		$is_builder = (
			( defined( 'BRICKS_IS_BUILDER' ) && BRICKS_IS_BUILDER ) ||
			( isset( $_GET['bricks'] ) && $_GET['bricks'] === 'run' ) ||
			( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
		);

		$this->set_attribute( '_root', 'class', 'evk-hscroll' );
		$this->set_attribute( '_root', 'style', '--evk-panel-h:' . esc_attr( $panel_height ) );

		// Konfiguracja tylko na froncie — w edytorze brak inicjalizacji JS.
		if ( ! $is_builder ) {
			$this->set_attribute( '_root', 'data-evk-hscroll', $cfg );
		}

		echo '<div ' . $this->render_attributes( '_root' ) . '>';

		// Pasek postępu — tylko na froncie i gdy włączony.
		if ( $progressbar && ! $is_builder ) {
			echo '<div class="evk-hscroll__progress evk-hscroll__progress--' . esc_attr( $pb_position ) . '">';
			echo '<div class="evk-hscroll__progress-bar"></div>';
			echo '</div>';
		}

		echo '<div class="evk-hscroll__track">';
		echo \Bricks\Frontend::render_children( $this );
		echo '</div>';
		echo '</div>';
	}
}
