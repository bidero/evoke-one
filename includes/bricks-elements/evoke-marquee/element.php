<?php
defined( 'ABSPATH' ) || exit;

class Evk_Marquee_Element extends \Bricks\Element {

	public $category = EVK_BRICKS_CATEGORY;
	public $name     = 'evk-marquee';
	public $icon     = 'ti-infinite';
	public $tag      = 'div';

	// Etykieta musi się zgadzać z evk_elements_registry()['marquee']['label'].
	public function get_label() {
		return 'Marquee';
	}

	public function get_keywords() {
		return [ 'evoke', 'marquee', 'ticker', 'scroll', 'infinite' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'evk-marquee' );
		wp_enqueue_style( 'evk-marquee' );
	}

	public function set_controls() {

		// ── CONTENT ─────────────────────────────────────────────────────────

		$this->controls['items'] = [
			'tab'           => 'content',
			'label'         => 'Elementy',
			'type'          => 'repeater',
			'titleProperty' => 'text',
			'default'       => [
				[ 'type' => 'text', 'text' => 'EVOKE DESIGN STUDIO' ],
				[ 'type' => 'text', 'text' => 'EVOKE DESIGN STUDIO' ],
			],
			'fields'        => [
				'type'  => [
					'label'   => 'Typ',
					'type'    => 'select',
					'options' => [
						'text'  => 'Tekst',
						'image' => 'Obraz',
					],
					'default' => 'text',
				],
				'text'  => [
					'label'    => 'Tekst',
					'type'     => 'text',
					'default'  => 'EVOKE DESIGN STUDIO',
					'required' => [ 'type', '=', 'text' ],
				],
				'image' => [
					'label'    => 'Obraz',
					'type'     => 'image',
					'required' => [ 'type', '=', 'image' ],
				],
				'image_width' => [
					'label'    => 'Szerokość obrazu',
					'type'     => 'number',
					'units'    => true,
					'default'  => '120px',
					'required' => [ 'type', '=', 'image' ],
				],
			],
		];

		// ── SETTINGS ────────────────────────────────────────────────────────

		$this->controls['sep_settings'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Ustawienia ruchu',
		];

		$this->controls['direction'] = [
			'tab'     => 'content',
			'label'   => 'Kierunek bazowy',
			'type'    => 'select',
			'options' => [
				'left'  => 'Lewo ←',
				'right' => 'Prawo →',
			],
			'default' => 'left',
		];

		$this->controls['reverse_on_scroll_up'] = [
			'tab'         => 'content',
			'label'       => 'Odwróć kierunek przy scrollu w górę',
			'type'        => 'checkbox',
			'default'     => false,
			'description' => 'Scroll w górę odwraca kierunek marquee.',
		];

		$this->controls['base_speed'] = [
			'tab'         => 'content',
			'label'       => 'Prędkość bazowa (px/s)',
			'type'        => 'number',
			'min'         => 10,
			'max'         => 500,
			'step'        => 10,
			'default'     => 80,
			'description' => 'Im wyższa wartość, tym szybszy marquee.',
		];

		$this->controls['scroll_divisor'] = [
			'tab'         => 'content',
			'label'       => 'Siła przyspieszenia przy scrollu',
			'type'        => 'number',
			'min'         => 50,
			'max'         => 1000,
			'step'        => 25,
			'default'     => 300,
			'description' => 'Im mniejsza wartość, tym silniejsze przyspieszenie.',
		];

		$this->controls['max_scale'] = [
			'tab'     => 'content',
			'label'   => 'Maks. przyspieszenie (x razy)',
			'type'    => 'number',
			'min'     => 2,
			'max'     => 20,
			'step'    => 1,
			'default' => 12,
		];

		$this->controls['gap'] = [
			'tab'     => 'content',
			'label'   => 'Odstęp między elementami',
			'type'    => 'number',
			'units'   => true,
			'default' => '80px',
		];

		$this->controls['slow_down'] = [
			'tab'     => 'content',
			'label'   => 'Czas zwalniania (s)',
			'type'    => 'number',
			'min'     => 0.2,
			'max'     => 5,
			'step'    => 0.1,
			'default' => 2,
		];
	}

	public function render() {
		$items        = $this->settings['items']        ?? [];
		$direction            = $this->settings['direction']            ?? 'left';
		$reverse_on_scroll_up = ! empty( $this->settings['reverse_on_scroll_up'] );
		$base_speed   = $this->settings['base_speed']   ?? 80;
		$divisor      = $this->settings['scroll_divisor'] ?? 300;
		$max_scale    = $this->settings['max_scale']    ?? 12;
		$gap          = $this->settings['gap']          ?? '80px';
		$slow_down    = $this->settings['slow_down']    ?? 2;

		if ( empty( $items ) ) {
			return $this->render_element_placeholder( [ 'title' => 'Dodaj elementy w zakładce Treść.' ] );
		}

		$cfg = esc_attr( json_encode( [
			'baseSpeed'         => (float) $base_speed,
			'divisor'           => (float) $divisor,
			'maxScale'          => (float) $max_scale,
			'slowDown'          => (float) $slow_down,
			'direction'         => $direction,
			'reverseOnScrollUp' => $reverse_on_scroll_up,
		] ) );

		$gap_css    = is_array( $gap ) ? ( $gap['value'] . $gap['unit'] ) : $gap;
		// Wykryj builder: AJAX render elementu lub iframe buildera
		$is_builder = (
			( defined( 'BRICKS_IS_BUILDER' ) && BRICKS_IS_BUILDER ) ||
			( isset( $_GET['bricks'] ) && $_GET['bricks'] === 'run' ) ||
			( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
		);

		$this->set_attribute( '_root', 'class', 'evk-marquee-container' );
		$this->set_attribute( '_root', 'style', '--evk-gap:' . esc_attr( $gap_css ) );
		if ( ! $is_builder ) {
			$this->set_attribute( '_root', 'data-evk-marquee', $cfg );
		}

		echo '<div ' . $this->render_attributes( '_root' ) . '>';
		echo '<div class="evk-marquee-inner' . ( $is_builder ? ' evk-marquee-no-anim' : '' ) . '">';

		// Renderuj dwie kopie zestawu dla płynnej pętli
		for ( $copy = 0; $copy < 2; $copy++ ) {
			echo '<div class="evk-marquee-track" aria-hidden="' . ( $copy > 0 ? 'true' : 'false' ) . '">';
			foreach ( $items as $i => $item ) {
				$type = $item['type'] ?? 'text';
				echo '<span class="evk-marquee-item">';
				if ( $type === 'image' && ! empty( $item['image']['id'] ) ) {
					$w   = is_array( $item['image_width'] ?? '' )
						? ( $item['image_width']['value'] . $item['image_width']['unit'] )
						: ( $item['image_width'] ?? '120px' );
					$url = wp_get_attachment_image_url( $item['image']['id'], 'full' );
					$alt = get_post_meta( $item['image']['id'], '_wp_attachment_image_alt', true );
					echo '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" style="width:' . esc_attr( $w ) . ';height:auto;display:block;" loading="lazy">';
				} else {
					echo '<span>' . esc_html( $item['text'] ?? '' ) . '</span>';
				}
				echo '</span>';
			}
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
	}
}
