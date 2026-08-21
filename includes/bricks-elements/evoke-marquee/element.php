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
						'text'    => 'Tekst',
						'image'   => 'Obraz',
						'gallery' => 'Galeria (Evoke Fields)',
					],
					'default' => 'text',
					'description' => 'Galeria to JEDEN wiersz, z którego wychodzi tyle obrazów, '
						. 'ile ich w niej jest — dokładasz zdjęcie w Evoke Fields, marquee nadąża samo.',
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
					// Także dla galerii: jedna galeria to jeden rozmiar, a osobne
					// pole na to samo tylko myliłoby.
					'required' => [ 'type', '!=', 'text' ],
				],

				/*
				 * ── Galeria z Evoke Fields ──────────────────────────────────
				 *
				 * Trzy źródła, bo galeria bywa w trzech miejscach: przy tej
				 * stronie, na stronie ustawień (jedna dla całej witryny) albo
				 * przy zupełnie innym wpisie.
				 */
				'gallery_source' => [
					'label'    => 'Skąd galeria',
					'type'     => 'select',
					'options'  => [
						'post'    => 'Z bieżącego wpisu',
						'option'  => 'Ze strony ustawień',
						'post_id' => 'Ze wskazanego wpisu',
					],
					'default'  => 'post',
					'required' => [ 'type', '=', 'gallery' ],
				],
				'gallery_group' => [
					'label'       => 'Klucz grupy ustawień',
					'type'        => 'text',
					'placeholder' => 'moja_grupa',
					'required'    => [ 'type', '=', 'gallery', 'gallery_source', '=', 'option' ],
				],
				'gallery_key' => [
					'label'       => 'Klucz pola galerii',
					'type'        => 'text',
					'placeholder' => 'logotypy',
					'required'    => [ 'type', '=', 'gallery' ],
					'description' => 'Klucz z Evoke Fields. Gdy pola nie ma albo jest puste, '
						. 'wiersz po prostu znika — reszta taśmy jedzie dalej.',
				],
				'gallery_post_id' => [
					'label'    => 'Numer wpisu (ID)',
					'type'     => 'number',
					'required' => [ 'type', '=', 'gallery', 'gallery_source', '=', 'post_id' ],
				],
				'gallery_order' => [
					'label'    => 'Kolejność',
					'type'     => 'select',
					'options'  => [
						'as-is'   => 'Jak w galerii',
						'reverse' => 'Odwrotna',
						'random'  => 'Losowa',
					],
					'default'     => 'as-is',
					'required'    => [ 'type', '=', 'gallery' ],
					'description' => 'Losowanie zapada w PHP, więc na stronie trzymanej '
						. 'w pełnym cache\'u zamraża się jeden układ.',
				],
				'gallery_limit' => [
					'label'       => 'Ile obrazów',
					'type'        => 'number',
					'min'         => 0,
					'placeholder' => 'wszystkie',
					'required'    => [ 'type', '=', 'gallery' ],
					'description' => 'Kolejność liczy się PRZED limitem: „odwrotna + 3" '
						. 'daje trzy OSTATNIE obrazy galerii. Każdy obraz trafia na stronę '
						. 'dwa razy, bo taśma jedzie w dwóch kopiach.',
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

		// ── PAUZA POZA EKRANEM ─────────────────────────────────────────────────

		$this->controls['sep_pause'] = [
			'tab'   => 'content',
			'type'  => 'separator',
			'label' => 'Pauza poza ekranem',
		];

		// Domyślnie włączone — to dotychczasowe zachowanie, wcześniej zaszyte
		// na sztywno w marquee.js. Wyłączenie ma sens tylko wtedy, gdy pętla
		// musi trwać także niewidoczna (np. dwa marquee zsynchronizowane ze sobą).
		$this->controls['pause_offscreen'] = [
			'tab'         => 'content',
			'label'       => 'Pauzuj poza ekranem',
			'type'        => 'checkbox',
			'default'     => true,
			'description' => 'Wstrzymuje pętlę i przestaje reagować na przewijanie, gdy marquee jest poza kadrem.',
		];

		$this->controls['pause_offset'] = [
			'tab'         => 'content',
			'label'       => 'Zapas (px)',
			'type'        => 'number',
			'min'         => -500,
			'max'         => 2000,
			'step'        => 50,
			'default'     => 200,
			'required'    => [ 'pause_offscreen', '=', true ],
			'description' => 'O ile pikseli przed wejściem w kadr pętla ma już działać. '
				. 'WARTOŚĆ UJEMNA robi coś odwrotnego i to nie jest pomyłka: opóźnia start, '
				. 'aż marquee wjedzie GŁĘBIEJ w kadr. Przy -150 rusza dopiero, gdy widać już '
				. 'sto pięćdziesiąt pikseli. Przydaje się, gdy chcesz sprawdzić, czy pauza '
				. 'w ogóle działa: przy domyślnych 200 marquee rusza, ZANIM je zobaczysz, '
				. 'więc zjeżdżając do niego stroną widzisz je już rozpędzone i wygląda to '
				. 'jak brak pauzy.',
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
		// Brak klucza = element zapisany przed 1.36.0. Domyślnie włączone,
		// bo taka była dotychczasowa, zaszyta na sztywno wartość.
		$pause_offscreen = ! array_key_exists( 'pause_offscreen', $this->settings )
			|| ! empty( $this->settings['pause_offscreen'] );
		$pause_offset    = $this->settings['pause_offset'] ?? 200;

		// Wykryj builder: AJAX render elementu lub iframe buildera.
		// Liczone TU, a nie niżej: od tego zależy, czy pusty wynik ma powiedzieć
		// o sobie na kanwie, czy zniknąć po cichu na froncie.
		$is_builder = (
			( defined( 'BRICKS_IS_BUILDER' ) && BRICKS_IS_BUILDER ) ||
			( isset( $_GET['bricks'] ) && $_GET['bricks'] === 'run' ) ||
			( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
		);

		if ( empty( $items ) ) {
			return $this->render_element_placeholder( [ 'title' => 'Dodaj elementy w zakładce Treść.' ] );
		}

		/*
		 * Rozwinięcie galerii pada PRZED pętlą kopii — i to nie jest
		 * optymalizacja, tylko poprawność.
		 *
		 * Taśma jedzie w dwóch kopiach i zapętla się właśnie tym, że druga jest
		 * co do znaku identyczna z pierwszą. Losowanie w środku pętli dałoby
		 * dwa RÓŻNE układy i widoczny przeskok na złączeniu.
		 */
		$pozycje = $this->rozwin_pozycje( $items );

		if ( empty( $pozycje ) ) {
			// Na froncie cisza: pusta galeria nie ma prawa drukować pudełka
			// zastępczego w środku strony.
			return $is_builder
				? $this->render_element_placeholder( [ 'title' => 'Nie ma czego pokazać — sprawdź klucz galerii.' ] )
				: null;
		}

		$cfg = esc_attr( json_encode( [
			'baseSpeed'         => (float) $base_speed,
			'divisor'           => (float) $divisor,
			'maxScale'          => (float) $max_scale,
			'slowDown'          => (float) $slow_down,
			'direction'         => $direction,
			'reverseOnScrollUp' => $reverse_on_scroll_up,
			'pauseOffscreen'    => $pause_offscreen,
			// BEZ zaciskania do zera. Zapas ujemny zwęża strefę grania zamiast ją
			// poszerzać — to jedyny sposób, żeby sprawdzić na żywej stronie, czy
			// pauza działa, bo przy domyślnych 200 px marquee rusza, zanim wjedzie
			// w kadr, i wygląda to jak brak pauzy.
			'pauseOffset'       => (int) $pause_offset,
		] ) );

		$gap_css    = is_array( $gap ) ? ( $gap['value'] . $gap['unit'] ) : $gap;

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
			foreach ( $pozycje as $poz ) {
				$html = $poz['typ'] === 'image'
					? $this->render_image( $poz['id'], $poz['szer'] )
					: '<span>' . esc_html( $poz['tekst'] ) . '</span>';
				// Obraz, którego nie ma w bibliotece, dawałby puste pudełko
				// rozpychające odstępy taśmy — lepiej, żeby zniknął cały.
				if ( $html === '' ) {
					continue;
				}
				echo '<span class="evk-marquee-item">' . $html . '</span>';
			}
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Wiersze repeatera → płaska lista pozycji do narysowania.
	 *
	 * Jeden wiersz „galeria" rozwija się w tyle pozycji, ile obrazów ma pole
	 * w Evoke Fields. Reszta przechodzi jeden do jednego.
	 */
	private function rozwin_pozycje( $items ) {
		$out = [];

		foreach ( $items as $item ) {
			$type = $item['type'] ?? 'text';
			$szer = $this->szerokosc_obrazu( $item );

			if ( $type === 'gallery' ) {
				foreach ( $this->ids_galerii( $item ) as $id ) {
					$out[] = [ 'typ' => 'image', 'id' => $id, 'szer' => $szer ];
				}
				continue;
			}

			if ( $type === 'image' && ! empty( $item['image']['id'] ) ) {
				$out[] = [ 'typ' => 'image', 'id' => (int) $item['image']['id'], 'szer' => $szer ];
				continue;
			}

			$out[] = [ 'typ' => 'tekst', 'tekst' => $item['text'] ?? '' ];
		}

		return $out;
	}

	/**
	 * Obrazy jednego wiersza „galeria".
	 *
	 * Evoke Fields może w ogóle nie być zainstalowane — wtedy wiersz znika,
	 * a strona nie ma prawa się wywrócić na nieznanej funkcji.
	 */
	private function ids_galerii( $item ) {
		if ( ! function_exists( 'evk_get_field' ) ) {
			return [];
		}

		$klucz = trim( (string) ( $item['gallery_key'] ?? '' ) );
		if ( $klucz === '' ) {
			return [];
		}

		$zrodlo = $item['gallery_source'] ?? 'post';

		if ( $zrodlo === 'option' ) {
			if ( ! function_exists( 'evk_get_option_field' ) ) {
				return [];
			}
			$grupa = trim( (string) ( $item['gallery_group'] ?? '' ) );
			if ( $grupa === '' ) {
				return [];
			}
			$surowe = evk_get_option_field( $grupa, $klucz );
		} else {
			// Zero znaczy w Evoke Fields „bieżący wpis" (includes/api.php:
			// `$post_id = $post_id ?: (int) get_the_ID();`).
			$post_id = ( $zrodlo === 'post_id' ) ? (int) ( $item['gallery_post_id'] ?? 0 ) : 0;
			$surowe  = evk_get_field( $klucz, $post_id, 'ids' );
		}

		$ids = self::ids_z_wartosci( $surowe );

		/* Kolejność PRZED limitem. „Odwrotna + 3" ma dać trzy OSTATNIE obrazy
		   galerii, a nie trzy pierwsze ustawione tyłem. */
		$kolejnosc = $item['gallery_order'] ?? 'as-is';
		if ( 'reverse' === $kolejnosc ) {
			$ids = array_reverse( $ids );
		} elseif ( 'random' === $kolejnosc ) {
			shuffle( $ids );
		}

		$limit = isset( $item['gallery_limit'] ) && $item['gallery_limit'] !== ''
			? (int) $item['gallery_limit'] : 0;
		if ( $limit > 0 ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		return $ids;
	}

	/**
	 * Wartość pola galerii → lista numerów załączników.
	 *
	 * Trzy kształty, bo Evoke Fields oddaje różne rzeczy różnymi drogami:
	 * pole wpisu z wariantem „ids" wraca TEKSTEM po przecinkach
	 * (`implode(',', $ids)`), a pole ze strony ustawień wraca SUROWĄ tablicą
	 * wierszy `['img' => ID, 'cat' => kategoria]`. Bez wspólnego wejścia
	 * wariant „ze strony ustawień" po cichu nie pokazywałby nic.
	 */
	public static function ids_z_wartosci( $wartosc ) {
		if ( is_string( $wartosc ) ) {
			$wartosc = ( '' === trim( $wartosc ) ) ? [] : explode( ',', $wartosc );
		}
		if ( ! is_array( $wartosc ) ) {
			return [];
		}

		$ids = [];
		foreach ( $wartosc as $poz ) {
			$id = is_array( $poz ) ? (int) ( $poz['img'] ?? 0 ) : (int) $poz;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Szerokość z wiersza repeatera — wspólna dla obrazu i całej galerii. */
	private function szerokosc_obrazu( $item ) {
		$w = $item['image_width'] ?? '';
		if ( is_array( $w ) ) {
			return ( $w['value'] ?? '' ) . ( $w['unit'] ?? '' );
		}
		return ( '' === $w ) ? '120px' : $w;
	}

	/** Znacznik jednego obrazu — jeden dla obu dróg, żeby się nie rozjechały. */
	private function render_image( $id, $szerokosc ) {
		$url = wp_get_attachment_image_url( $id, 'full' );
		if ( ! $url ) {
			return '';
		}
		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt )
			. '" style="width:' . esc_attr( $szerokosc ) . ';height:auto;display:block;" loading="lazy">';
	}
}
