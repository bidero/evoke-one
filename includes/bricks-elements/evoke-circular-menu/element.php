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
		/*
		 * Ta sama lista krzywych, co w Animatorze i w Offcanvas Menu — jedna
		 * lista dla całej wtyczki znaczy, że dorzucenie krzywej działa wszędzie
		 * naraz i że użytkownik uczy się jednego słownika.
		 *
		 * Wcześniej stała tu własna kopia z samymi RODZINAMI GSAP-a („power2",
		 * „back") plus pole na wartość wpisywaną ręcznie. Rodzina bez kierunku
		 * nie jest tym samym co krzywa: wspólna lista niesie „power2.out",
		 * „power2.inOut" i „back.out(1.7)" — czyli warianty, których kopia nie
		 * miała wcale, a po które trzeba było sięgać osobnym polem tekstowym.
		 *
		 * BEZ przeliczania na CSS. Offcanvas jedzie na przejściach CSS i musi
		 * tłumaczyć nazwy przez evk_anim_easing_css(); to menu animuje GSAP-em
		 * (tl.to(panel, { ease })), a GSAP rozumie te nazwy wprost. Wspólna jest
		 * LISTA, nie tłumaczenie.
		 */
		$easings = [ '' => esc_html__( '— domyślna —', 'evk-circular-menu' ) ];
		if ( function_exists( 'evk_anim_easings' ) ) {
			foreach ( evk_anim_easings() as $e ) $easings[ $e ] = $e;
		}
		$this->controls['easing'] = [
			'hasDynamicData' => false,
			'tab'         => 'content',
			'label'       => esc_html__( 'Krzywa', 'evk-circular-menu' ),
			'type'        => 'select',
			'options'     => $easings,
			'inline'      => true,
			'default'     => '',
		];

		$this->controls['contentDelay'] = [
			'hasDynamicData' => false,
			'tab'         => 'content',
			'label'       => esc_html__( 'Opóźnienie treści (s)', 'evk-circular-menu' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 3,
			'step'        => 0.05,
			'inline'      => true,
			'placeholder' => '0',
			'description' => esc_html__(
				'Odstęp między rozwinięciem kadru a ruszeniem animacji w środku. '
				. 'Bez niego kadr i treść startują w tej samej klatce i całość wygląda '
				. 'sztywno — nie widać, co po czym następuje. Przez czas odstępu treść '
				. 'stoi w stanie POCZĄTKOWYM swojej animacji, więc nic nie miga. '
				. 'Zero wyłącza odstęp.',
				'evk-circular-menu'
			),
		];

		$this->controls['animateExit'] = [
			'hasDynamicData' => false,
			'tab'     => 'content',
			'label'   => esc_html__( 'Animuj wyjście treści', 'evk-circular-menu' ),
			'type'    => 'checkbox',
			'inline'  => true,
			'small'   => true,
			'default' => false,
			'description' => esc_html__(
				'Przy zamykaniu treść najpierw wychodzi, a dopiero potem zwija się kadr. '
				. 'Domyślnie wychodzi TĄ SAMĄ animacją, którą weszła, tylko od końca — '
				. 'bez ustawiania czegokolwiek. Chcesz innego wyjścia? Ustaw elementowi '
				. 'animację z wyzwalaczem „Zamknięcie menu" — ona wygra z cofaniem. '
				. 'Bez ustawienia czekania menu czeka na całą animację, ale nie dłużej '
				. 'niż sekundę.',
				'evk-circular-menu'
			),
		];

		$this->controls['exitWait'] = [
			'hasDynamicData' => false,
			'tab'         => 'content',
			'label'       => esc_html__( 'Czekanie na wyjście (s)', 'evk-circular-menu' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 3,
			'step'        => 0.05,
			'inline'      => true,
			'placeholder' => esc_html__( 'cały czas animacji', 'evk-circular-menu' ),
			'required'    => [ 'animateExit', '=', true ],
			'description' => esc_html__(
				'Ile menu czeka z zwijaniem kadru na wychodzącą treść. '
				. 'ZERO znaczy „naraz": kadr zamyka się RAZEM z animacją linków, '
				. 'zamiast po niej — treść nie musi zdążyć zniknąć, zanim cokolwiek '
				. 'ruszy. Puste pole to całkowity czas animacji wyjścia (najwyżej '
				. 'sekunda), czyli ruchy jeden po drugim. Wartość większa niż sama '
				. 'animacja daje chwilę ciszy, zanim menu się zamknie.',
				'evk-circular-menu'
			),
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
		/*
		 * Ścieżka dla stron zapisanych PRZED wspólną listą krzywych. Kontrolki
		 * „Własny easing" już nie ma, ale jej wartość siedzi w bazie i bez tego
		 * przejścia easing „custom" pojechałby do GSAP-a jako dosłowne słowo
		 * „custom" — czyli nazwa, której GSAP nie zna, więc po cichu zamieniłby
		 * ją na krzywą domyślną. Trzy linijki zamiast cichej zmiany ruchu na
		 * stronach, które nikt nie otworzy w builderze.
		 */
		if ( $easing === 'custom' ) {
			$easing = ! empty( $settings['customEasing'] ) ? $settings['customEasing'] : 'none';
		}
		$contentDelay      = isset( $settings['contentDelay'] ) && $settings['contentDelay'] !== ''
			? (string) $settings['contentDelay'] : '0';
		$animateExit       = ! empty( $settings['animateExit'] )       ? '1' : '0';
		// Puste = „cały czas animacji", wyliczane w JS. Jawne ZERO musi przejść
		// jako '0' — `! empty()` potraktowałoby je jak brak wartości i ruchy
		// wróciłyby do grania jeden po drugim mimo wybrania „naraz".
		$exitWait          = isset( $settings['exitWait'] ) && $settings['exitWait'] !== ''
			? (string) $settings['exitWait'] : '';
		$customtoggle      = ! empty( $settings['customtoggle'] )      ? $settings['customtoggle']      : '';
		$lockBodyScrolling = ! empty( $settings['lockBodyScrolling'] ) ? '1' : '0';
		$closeOnEsc        = ! empty( $settings['closeOnEsc'] )        ? '1' : '0';

		$this->set_attribute( '_root', 'class',                                 'evk-cm' );
		$this->set_attribute( '_root', 'data-portal',                           $portalToBody );
		$this->set_attribute( '_root', 'data-duration',                         $duration );
		$this->set_attribute( '_root', 'data-easing',                           $easing );
		$this->set_attribute( '_root', 'data-content-delay',                    $contentDelay );
		$this->set_attribute( '_root', 'data-anim-exit',                        $animateExit );
		$this->set_attribute( '_root', 'data-exit-wait',                        $exitWait );
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
