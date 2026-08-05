<?php
defined( 'ABSPATH' ) || exit;

class Evk_Scroll_Reading_Element extends \Bricks\Element {

	public $category = EVK_BRICKS_CATEGORY;
	public $name     = 'evk-scroll-reading';
	public $icon     = 'ti-align-left';
	public $tag      = 'div';
	public $nestable = true;   // ← kluczowe

	// Etykieta musi się zgadzać z evk_elements_registry()['scroll_reading']['label'].
	public function get_label() {
		return 'Scroll Reading';
	}

	public function get_keywords() {
		return [ 'evoke', 'scroll', 'reading', 'text', 'tekst', 'split', 'highlight' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'evk-scroll-reading' );
		wp_enqueue_style( 'evk-scroll-reading' );
	}

	public function set_controls() {

		// ── KOLORY ─────────────────────────────────────────────────────────

		$this->controls['color_active'] = [
			'tab'     => 'content',
			'label'   => 'Kolor aktywny',
			'type'    => 'color',
			'default' => [ 'hex' => '#000000' ],
		];

		$this->controls['color_dim'] = [
			'tab'     => 'content',
			'label'   => 'Kolor dim (wyjściowy)',
			'type'    => 'color',
			'default' => [ 'hex' => '#aaaaaa' ],
		];

		// ── SPLIT ──────────────────────────────────────────────────────────

		$this->controls['sep_split'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Podział tekstu',
		];

		$this->controls['split_type'] = [
			'tab'         => 'content',
			'label'       => 'Podziel na',
			'type'        => 'select',
			'options'     => [
				'words' => 'Słowa',
				'chars' => 'Znaki',
				'lines' => 'Linie',
			],
			'default'     => 'words',
			'description' => 'Podział na znaki bywa czytany literami przez czytniki ekranu — nadaje się do krótkich napisów, nie do akapitów.',
		];

		// ── SCROLL TRIGGER ─────────────────────────────────────────────────

		$this->controls['sep_st'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'ScrollTrigger',
		];

		$this->controls['st_start'] = [
			'tab'     => 'content',
			'label'   => 'Start',
			'type'    => 'text',
			'default' => 'top 90%',
		];

		$this->controls['st_end'] = [
			'tab'     => 'content',
			'label'   => 'End',
			'type'    => 'text',
			'default' => 'bottom 20%',
		];

		$this->controls['scrub'] = [
			'tab'     => 'content',
			'label'   => 'Scrub',
			'type'    => 'number',
			'min'     => 0,
			'max'     => 5,
			'step'    => 0.5,
			'default' => 1,
		];

		$this->controls['stagger'] = [
			'tab'     => 'content',
			'label'   => 'Stagger (s)',
			'type'    => 'number',
			'min'     => 0,
			'max'     => 1,
			'step'    => 0.02,
			'default' => 0.05,
		];
	}

	private function resolve_color( $val, string $fallback ): string {
		if ( empty( $val ) ) return $fallback;

		if ( is_array( $val ) ) {
			// 1. hex — wartość konkretna z color pickera
			if ( ! empty( $val['hex'] ) ) return $val['hex'];

			// 2. Bricks zapisuje zmienne (var(--x)) oraz globalne kolory / rgba w 'raw' lub 'rgb'
			$raw = $val['raw'] ?? $val['rgb'] ?? '';
			if ( $raw ) return $raw; // np. var(--moj-kolor), rgba(...) — rozwijane w JS

			return $fallback;
		}

		return (string) $val ?: $fallback;
	}

	public function render() {
		$split_type   = $this->settings['split_type'] ?? 'words';
		$st_start     = $this->settings['st_start']   ?? 'top 90%';
		$st_end       = $this->settings['st_end']      ?? 'bottom 20%';
		$scrub        = $this->settings['scrub']       ?? 1;
		$stagger      = $this->settings['stagger']     ?? 0.05;
		$color_active = $this->resolve_color( $this->settings['color_active'] ?? null, '#000000' );
		$color_dim    = $this->resolve_color( $this->settings['color_dim']    ?? null, '#aaaaaa' );

		$cfg = esc_attr( json_encode( [
			'splitType'   => $split_type,
			'colorActive' => $color_active,
			'colorDim'    => $color_dim,
			'start'       => $st_start,
			'end'         => $st_end,
			'scrub'       => (float) $scrub,
			'stagger'     => (float) $stagger,
		] ) );

		$this->set_attribute( '_root', 'class', 'evk-scroll-reading' );
		$this->set_attribute( '_root', 'data-evk-sr', $cfg );

		echo '<div ' . $this->render_attributes( '_root' ) . '>';
		echo \Bricks\Frontend::render_children( $this );
		echo '</div>';
	}
}
