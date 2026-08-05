<?php
defined( 'ABSPATH' ) || exit;

/**
 * Evoke ONE — Stacking Cards
 *
 * Karty nakładają się przy scrollu. Mechanika stoi na `position: sticky`,
 * NIE na ScrollTrigger.pin — sticky nie tworzy pin-spacerów, nie przelicza
 * wysokości i nie rozjeżdża layoutu przy zmianie rozmiaru. GSAP dokłada tylko
 * skalowanie i przygaszanie kart, które zostają pod spodem.
 */
class Evk_Stacking_Cards_Element extends \Bricks\Element {

	public $category = EVK_BRICKS_CATEGORY;
	public $name     = 'evk-stacking-cards';
	public $icon     = 'ti-layers';
	public $tag      = 'div';
	public $nestable = true;

	// Etykieta musi się zgadzać z evk_elements_registry()['stacking_cards']['label'].
	public function get_label() {
		return 'Stacking Cards';
	}

	public function get_keywords() {
		return [ 'evoke', 'stacking', 'cards', 'karty', 'sticky', 'scroll', 'stos' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'evk-stacking-cards' );
		wp_enqueue_style( 'evk-stacking-cards' );
	}

	public function get_nestable_item() {
		return [
			'name'     => 'block',
			'label'    => esc_html__( 'Karta', 'evk-stacking-cards' ),
			'settings' => [
				'_hidden' => [ '_cssClasses' => 'evk-sc-card' ],
			],
			'children' => [
				[
					'name'     => 'heading',
					'settings' => [ 'text' => esc_html__( 'Karta', 'evk-stacking-cards' ), 'tag' => 'h2' ],
				],
			],
		];
	}

	public function get_nestable_children() {
		$children = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$item                                    = $this->get_nestable_item();
			$item['children'][0]['settings']['text'] = 'Karta ' . $i;
			$children[]                              = $item;
		}
		return $children;
	}

	public function set_controls() {

		$this->controls['sep_layout'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Układ', 'evk-stacking-cards' ),
		];

		$this->controls['offset_top'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Offset od góry', 'evk-stacking-cards' ),
			'type'        => 'number',
			'units'       => true,
			'default'     => '80px',
			'description' => esc_html__( 'Na jakiej wysokości karta się zatrzymuje.', 'evk-stacking-cards' ),
		];

		$this->controls['card_gap'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Odstęp między kartami', 'evk-stacking-cards' ),
			'type'        => 'number',
			'units'       => true,
			'default'     => '40px',
			'description' => esc_html__( 'Odstęp w normalnym przepływie, zanim karty się zetkną.', 'evk-stacking-cards' ),
		];

		$this->controls['stagger_offset'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Schodkowanie (px)', 'evk-stacking-cards' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 60,
			'step'        => 1,
			'default'     => 0,
			'description' => esc_html__( 'Każda kolejna karta zatrzymuje się niżej o tę wartość — widać krawędzie kart pod spodem.', 'evk-stacking-cards' ),
		];

		$this->controls['sep_effect'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Efekt kart pod spodem', 'evk-stacking-cards' ),
		];

		$this->controls['shrink'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Zmniejszaj karty pod spodem', 'evk-stacking-cards' ),
			'type'    => 'checkbox',
			'default' => true,
		];

		$this->controls['min_scale'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Skala docelowa', 'evk-stacking-cards' ),
			'type'        => 'number',
			'min'         => 0.5,
			'max'         => 1,
			'step'        => 0.01,
			'default'     => 0.9,
			'required'    => [ 'shrink', '=', true ],
		];

		$this->controls['dim'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Przyciemnienie', 'evk-stacking-cards' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 0.8,
			'step'        => 0.05,
			'default'     => 0.25,
			'description' => esc_html__( 'Karta pod spodem ciemnieje, ale zostaje nieprzezroczysta — nie prześwituje przez nią tło. 0 = bez przyciemniania.', 'evk-stacking-cards' ),
		];

		$this->controls['shadow'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Cień kart', 'evk-stacking-cards' ),
			'type'        => 'checkbox',
			'default'     => true,
			'description' => esc_html__( 'Oddziela karty od siebie — bez cienia stos bywa płaski.', 'evk-stacking-cards' ),
		];

		$this->controls['shadow_value'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Cień (CSS)', 'evk-stacking-cards' ),
			'type'        => 'text',
			'default'     => '0 -8px 30px rgba(0,0,0,.18)',
			'placeholder' => '0 -8px 30px rgba(0,0,0,.18)',
			'required'    => [ 'shadow', '=', true ],
		];

		$this->controls['bottom_space'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Zapas pod stosem', 'evk-stacking-cards' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'automat', 'evk-stacking-cards' ),
			'description' => esc_html__( 'Jak długo gotowy stos stoi, zanim odjedzie w górę. Puste = połowa wysokości ostatniej karty. Można podać dowolną jednostkę CSS, np. 50vh albo 300px. Zapas nie zostawia pustego miejsca — wychodzący stos przykrywa go sobą.', 'evk-stacking-cards' ),
		];

		$this->controls['sep_responsive'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => esc_html__( 'Responsywność', 'evk-stacking-cards' ),
		];

		$this->controls['disable_below'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Wyłącz poniżej (px)', 'evk-stacking-cards' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 2000,
			'step'        => 1,
			'default'     => 768,
			'description' => esc_html__( 'Poniżej tej szerokości karty układają się normalnie, jedna pod drugą. 0 = zawsze włączone.', 'evk-stacking-cards' ),
		];
	}

	public function render() {
		$s = $this->settings;

		$cfg = [
			'offsetTop' => $this->px( $s['offset_top'] ?? '80px', '80px' ),
			'gap'       => $this->px( $s['card_gap'] ?? '40px', '40px' ),
			'stagger'   => (int) ( $s['stagger_offset'] ?? 0 ),
			'shrink'    => ! isset( $s['shrink'] ) || ! empty( $s['shrink'] ),
			'minScale'  => (float) ( $s['min_scale'] ?? 0.9 ),
			'dim'       => (float) ( $s['dim'] ?? 0.25 ),
			'shadow'    => ! isset( $s['shadow'] ) || ! empty( $s['shadow'] ),
			'shadowValue'  => sanitize_text_field( $s['shadow_value'] ?? '' ) ?: '0 -8px 30px rgba(0,0,0,.18)',
			'bottomSpace'  => sanitize_text_field( $s['bottom_space'] ?? '' ),
			'disableBelow' => (int) ( $s['disable_below'] ?? 768 ),
		];

		$this->set_attribute( '_root', 'class', 'evk-sc' );
		$this->set_attribute( '_root', 'data-evk-sc', wp_json_encode( $cfg ) );

		echo '<div ' . $this->render_attributes( '_root' ) . '>';
		echo \Bricks\Frontend::render_children( $this );
		echo '</div>';
	}

	/** Bricks podaje jednostki jako string albo tablicę — sprowadzamy do stringa CSS. */
	private function px( $value, string $fallback ): string {
		if ( is_array( $value ) ) {
			$value = $value['value'] ?? '';
		}
		$value = trim( (string) $value );
		return $value !== '' ? $value : $fallback;
	}
}
