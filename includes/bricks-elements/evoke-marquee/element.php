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

	public function set_control_groups() {
		$this->control_groups['evk_ruch']  = [ 'title' => esc_html__( 'Ruch', 'evoke-one' ),               'tab' => 'content' ];
		$this->control_groups['evk_pauza'] = [ 'title' => esc_html__( 'Pauza poza ekranem', 'evoke-one' ), 'tab' => 'content' ];
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

				'image_height' => [
					'label'       => 'Wysokość obrazu',
					'type'        => 'number',
					'units'       => true,
					'placeholder' => 'z proporcji',
					/* Podana wysokość ZAMRAŻA wiersz taśmy: jego geometria przestaje
					   zależeć od tego, czy pliki zdążyły dojechać — a to właśnie
					   dojeżdżające obrazy potrafią przestawić taśmę w trakcie. */
					'description' => 'Puste — z proporcji obrazu. Podana zamraża geometrię wiersza. '
						. 'Przy obu podanych rozmiarach obraz jest kadrowany, nie rozciągany.',
					'required'    => [ 'type', '!=', 'text' ],
				],
				'image_loading' => [
					'label'       => 'Wczytywanie obrazu',
					'type'        => 'select',
					'options'     => [
						'lazy'  => 'Leniwie — gdy blisko kadru',
						'eager' => 'Od razu',
					],
					'default'     => 'lazy',
					/* DLACZEGO LENIWE SZWANKUJE AKURAT TUTAJ: przeglądarka mierzy
					   odległość od kadru w OBU osiach, a pozycje taśmy leżą
					   w poziomie poza nim. Dalsze obrazy dojeżdżają więc dopiero
					   wtedy, gdy taśma sama je dowiezie — i widać, jak wskakują. */
					'description' => 'Leniwe wczytywanie źle znosi taśmę: dalsze obrazy wskakują '
						. 'w trakcie jazdy. Przy dłuższej taśmie wybierz „od razu".',
					'required'    => [ 'type', '!=', 'text' ],
				],

				/*
				 * ── Galeria z danych dynamicznych ───────────────────────────
				 *
				 * JEDNO pole zamiast czterech. Do 1.103.1 stały tu „skąd galeria",
				 * „klucz grupy", „klucz pola" i „numer wpisu" — czyli ręczne
				 * odtwarzanie tego, co Bricks ma pod piorunkiem. Zgłoszone
				 * z użycia: „mam te dane w danych dynamicznych, wystarczyłoby
				 * podać klucz".
				 *
				 * Skutek uboczny jest tu ważniejszy niż wygoda: element PRZESTAJE
				 * wiedzieć o Evoke Fields. Zadziała z każdym tagiem, który odda
				 * listę numerów załączników — ACF, Metabox, własne pole, cokolwiek.
				 */
				'gallery_tag' => [
					'label'          => 'Galeria (dane dynamiczne)',
					'type'           => 'text',
					'hasDynamicData' => true,
					'placeholder'    => '{evk_field_logotypy__ids}',
					'required'       => [ 'type', '=', 'gallery' ],
					/* Goły tag galerii oddaje ADRES PIERWSZEGO OBRAZU, a nie listę —
					   wtedy nie ma czego pokazać i element mówi o tym na kanwie. */
					'description'    => 'Kliknij piorunek i wybierz wariant oddający LISTĘ ID — '
						. 'w Evoke Fields „(lista ID)", czyli tag z końcówką __ids.',
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
					/* Taśma jedzie w dwóch kopiach, więc każdy obraz trafia na stronę
					   dwa razy — limit liczy obrazy galerii, nie kafelki na taśmie. */
					'description' => 'Kolejność liczy się PRZED limitem: „odwrotna + 3" daje '
						. 'trzy OSTATNIE obrazy galerii.',
				],
			],
		];

		// ── SETTINGS ────────────────────────────────────────────────────────

		$this->controls['direction'] = [
			'group' => 'evk_ruch',
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
			'group' => 'evk_ruch',
			'tab'         => 'content',
			'label'       => 'Odwróć kierunek przy scrollu w górę',
			'type'        => 'checkbox',
			'default'     => false,
			'description' => 'Scroll w górę odwraca kierunek marquee.',
		];

		$this->controls['base_speed'] = [
			'group' => 'evk_ruch',
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
			'group' => 'evk_ruch',
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
			'group' => 'evk_ruch',
			'tab'     => 'content',
			'label'   => 'Maks. przyspieszenie (x razy)',
			'type'    => 'number',
			'min'     => 2,
			'max'     => 20,
			'step'    => 1,
			'default' => 12,
		];

		$this->controls['gap'] = [
			'group' => 'evk_ruch',
			'tab'     => 'content',
			'label'   => 'Odstęp między elementami',
			'type'    => 'number',
			'units'   => true,
			'default' => '80px',
		];

		$this->controls['slow_down'] = [
			'group' => 'evk_ruch',
			'tab'     => 'content',
			'label'   => 'Czas zwalniania (s)',
			'type'    => 'number',
			'min'     => 0.2,
			'max'     => 5,
			'step'    => 0.1,
			'default' => 2,
		];

		// ── PAUZA POZA EKRANEM ─────────────────────────────────────────────────

		// Domyślnie włączone — to dotychczasowe zachowanie, wcześniej zaszyte
		// na sztywno w marquee.js. Wyłączenie ma sens tylko wtedy, gdy pętla
		// musi trwać także niewidoczna (np. dwa marquee zsynchronizowane ze sobą).
		$this->controls['pause_offscreen_off'] = [
			'group' => 'evk_pauza',
			'tab'         => 'content',
			'label'       => 'Nie pauzuj poza ekranem',
			'type'        => 'checkbox',
			'default'     => false,
			/* ODWRÓCONY PRZEŁĄCZNIK — domyślna MUSI być wyłączona.
			   Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać
			   jako „wyłączone", więc pole z `'default' => true` jest nie do
			   wyłączenia. Powody i dowód: evk_wlaczone() w flaga.php. */
			'description' => 'Wstrzymuje pętlę i przestaje reagować na przewijanie, gdy marquee jest poza kadrem.',
		];

		/*
		 * Zapas przed wejściem w kadr.
		 *
		 * WARTOŚĆ UJEMNA NIE JEST POMYŁKĄ i dlatego suwak schodzi do -500:
		 * opóźnia start, aż marquee wjedzie GŁĘBIEJ w kadr. Przy -150 rusza
		 * dopiero wtedy, gdy widać już sto pięćdziesiąt pikseli.
		 *
		 * DO CZEGO TO SŁUŻY NAM, a nie stawiającemu stronę: przy domyślnych 200
		 * marquee rusza, ZANIM je zobaczysz, więc zjeżdżając do niego stroną
		 * widzisz je już rozpędzone — i pauza wygląda, jakby nie działała.
		 * Ujemny zapas jest najprostszym sposobem, żeby ją zobaczyć na oczy.
		 * To notatka diagnostyczna, więc siedzi tutaj, a nie w panelu.
		 */
		$this->controls['pause_offset'] = [
			'group' => 'evk_pauza',
			'tab'         => 'content',
			'label'       => 'Zapas (px)',
			'type'        => 'number',
			'min'         => -500,
			'max'         => 2000,
			'step'        => 50,
			'default'     => 200,
			'required'    => [ 'pause_offscreen_off', '=', false ],
			'description' => 'O ile pikseli przed wejściem w kadr pętla ma już działać. '
				. 'WARTOŚĆ UJEMNA odwrotnie: opóźnia start, aż marquee wjedzie głębiej '
				. 'w kadr.',
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
		// bo taka była dotychczasowa, zaszyta na sztywno wartość. Tu narodził
		// się wzorzec, który od 1.199.0 nazywa się evk_flaga() i obowiązuje
		// w całej wtyczce — zachowanie bez zmian, znika tylko druga kopia.
		$pause_offscreen = evk_wlaczone( $this->settings, 'pause_offscreen', 'pause_offscreen_off' );
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
					? $this->render_image( $poz['id'], $poz['szer'], $poz['wys'] ?? '', $poz['lad'] ?? 'lazy' )
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
			$wys  = $this->wysokosc_obrazu( $item );
			$lad  = $this->wczytywanie_obrazu( $item );

			if ( $type === 'gallery' ) {
				foreach ( $this->ids_galerii( $item ) as $id ) {
					$out[] = [ 'typ' => 'image', 'id' => $id, 'szer' => $szer, 'wys' => $wys, 'lad' => $lad ];
				}
				continue;
			}

			if ( $type === 'image' && ! empty( $item['image']['id'] ) ) {
				$out[] = [ 'typ' => 'image', 'id' => (int) $item['image']['id'],
					'szer' => $szer, 'wys' => $wys, 'lad' => $lad ];
				continue;
			}

			$out[] = [ 'typ' => 'tekst', 'tekst' => $item['text'] ?? '' ];
		}

		return $out;
	}

	/**
	 * Obrazy jednego wiersza „galeria".
	 *
	 * Wartość idzie przez dane dynamiczne Bricksa, więc element nie zna ani
	 * Evoke Fields, ani żadnej innej wtyczki pól — zna tylko listę numerów
	 * załączników, która z tagu wychodzi.
	 */
	private function ids_galerii( $item ) {
		$tag = trim( (string) ( $item['gallery_tag'] ?? '' ) );
		if ( '' === $tag ) {
			return [];
		}

		/* Bez Bricksa tag zostaje surowym napisem — normalizator zrobi z niego
		   pustkę, a nie śmieciowy numer. Warunek jest tu na wypadek wywołania
		   spoza builderowego kontekstu, nie dla realnej strony. */
		$wartosc = $tag;
		if ( function_exists( 'bricks_render_dynamic_data' ) ) {
			// Wpis z kontekstu elementu, a przy jego braku — bieżący z pętli.
			$pid = ! empty( $this->post_id ) ? (int) $this->post_id : (int) get_the_ID();
			$wartosc = bricks_render_dynamic_data( $tag, $pid );
		}

		$ids = self::ids_z_wartosci( $wartosc );

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
	 * Trzy kształty, bo wtyczki pól oddają galerie różnie: tekstem po
	 * przecinkach (tak robi wariant „ids" Evoke Fields — `implode(',', $ids)`),
	 * gołą tablicą numerów albo tablicą wierszy `['img' => ID, …]`.
	 *
	 * Adres obrazu zamiast numeru daje tu PUSTKĘ, i tak ma być: goły tag
	 * galerii oddaje adres pierwszego zdjęcia, a jedno zdjęcie wyglądałoby na
	 * działającą galerię. Lepiej, żeby element powiedział „nie ma czego
	 * pokazać".
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

	/**
	 * Wysokość z wiersza repeatera. Pusta znaczy „z proporcji obrazu".
	 *
	 * W odróżnieniu od szerokości NIE MA tu wartości zastępczej: pusta jest
	 * osobnym, sensownym stanem — i to ona jest domyślna.
	 */
	private function wysokosc_obrazu( $item ) {
		$h = $item['image_height'] ?? '';
		if ( is_array( $h ) ) {
			$v = $h['value'] ?? '';
			/* Sama jednostka bez liczby dałaby `height:px`, czyli regułę
			   odrzucaną po cichu przez przeglądarkę. */
			return ( '' === $v ) ? '' : $v . ( $h['unit'] ?? '' );
		}
		return (string) $h;
	}

	/** Sposób wczytywania z wiersza repeatera — patrz opis kontrolki. */
	private function wczytywanie_obrazu( $item ) {
		return ( ( $item['image_loading'] ?? 'lazy' ) === 'eager' ) ? 'eager' : 'lazy';
	}

	/** Znacznik jednego obrazu — jeden dla obu dróg, żeby się nie rozjechały. */
	private function render_image( $id, $szerokosc, $wysokosc = '', $ladowanie = 'lazy' ) {
		$src = wp_get_attachment_image_src( $id, 'full' );
		$url = $src[0] ?? '';
		if ( ! $url ) {
			return '';
		}
		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );

		/*
		 * WYMIARY Z ZAŁĄCZNIKA JAKO ATRYBUTY — o rezerwację miejsca, nie o wygląd.
		 *
		 * Przy `height: auto` przeglądarka nie zna proporcji, dopóki plik nie
		 * dojedzie, więc rezerwuje ZERO wysokości i dokłada ją dopiero potem.
		 * Wiersz taśmy podskakuje, a razem z nim rośnie cała strona — a strona
		 * rosnąca w trakcie przewijania rozjeżdża wszystkie wyzwalacze poniżej.
		 * Zmierzone na evoke.pl: +992 px wysokości dokumentu w trakcie jednego
		 * przejazdu, przy 48 obrazach galerii.
		 *
		 * Rozmiarem dalej rządzi styl; atrybuty podają wyłącznie proporcje.
		 */
		$wymiary = '';
		$w = (int) ( $src[1] ?? 0 );
		$h = (int) ( $src[2] ?? 0 );
		if ( $w > 0 && $h > 0 ) {
			$wymiary = ' width="' . $w . '" height="' . $h . '"';
		}

		$styl = 'width:' . $szerokosc
			. ';height:' . ( '' === $wysokosc ? 'auto' : $wysokosc )
			. ';display:block;';
		/* Oba rozmiary narzucone znaczą narzucone proporcje — bez kadrowania
		   obraz by się rozciągnął. Przy wysokości „z proporcji" nie ma czego
		   dopasowywać. */
		if ( '' !== $wysokosc ) {
			$styl .= 'object-fit:cover;';
		}

		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . $wymiary
			. ' style="' . esc_attr( $styl ) . '" loading="' . esc_attr( $ladowanie ) . '">';
	}
}
