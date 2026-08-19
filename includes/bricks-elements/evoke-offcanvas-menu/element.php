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
				'JAK OTWORZYĆ DRUGI PANEL: zaznacz przycisk lub odnośnik w panelu startowym '
				. 'i dodaj mu atrybut (zakładka Style → Atrybuty) o nazwie data-evk-oc-go '
				. 'i wartości 1 — panele bez nazwy liczą się po kolejności, więc 1 to drugi panel. '
				. 'Powrót: atrybut data-evk-oc-back (bez wartości). Zamknięcie: data-evk-oc-close. '
				. 'Chcesz nazw zamiast numerów? Nadaj panelowi atrybut data-panel o dowolnej nazwie '
				. 'i tę samą nazwę wpisz jako wartość data-evk-oc-go.',
				'evoke-one'
			),
		];

		$this->controls['levelStyle'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Wejście w podmenu', 'evoke-one' ),
			'type'        => 'select',
			'options'     => [
				'expand' => esc_html__( 'Kadr się poszerza (rodzic zostaje widoczny)', 'evoke-one' ),
				'slide'  => esc_html__( 'Rodzic wyjeżdża całkiem', 'evoke-one' ),
			],
			'default'     => 'expand',
			'required'    => [ 'mode', '=', 'levels' ],
			'description' => esc_html__(
				'Przy poszerzaniu menu rośnie o szerokość jednego panelu, a panel główny '
				. 'przesuwa się w bok i zostaje widoczny obok podmenu. Podmenu jest ZAWSZE '
				. 'JEDNO: wejście w kolejne podmienia poprzednie, a „wstecz" i Esc wracają '
				. 'wprost do panelu głównego. '
				. 'Gdy poziomy przestają mieścić się w oknie — na telefonie zwykle od razu — '
				. 'menu samo wraca do pokazywania jednego panelu. '
				. 'Menu z góry i z dołu zawsze jedzie trybem „rodzic wyjeżdża całkiem": '
				. 'poszerzanie ma sens tylko w poziomie.',
				'evoke-one'
			),
		];

		$this->controls['subDelay'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Opóźnienie panelu podrzędnego (s)', 'evoke-one' ),
			'type'        => 'number',
			'step'        => 0.05,
			'min'         => 0,
			'placeholder' => esc_html__( '45% czasu przejścia', 'evoke-one' ),
			'required'    => [ 'mode', '=', 'levels' ],
			'description' => esc_html__(
				'Odstęp między zrobieniem miejsca a wjazdem panelu podrzędnego. '
				. 'Bez niego oba ruchy zaczynają i kończą się równo, więc nie widać, '
				. 'co po czym następuje — całość wygląda sztywno. Z opóźnieniem najpierw '
				. 'poszerza się kadr (albo wyjeżdża poprzedni panel podrzędny), a potem '
				. 'dopiero nowy dojeżdża. Zero wyłącza odstęp.',
				'evoke-one'
			),
		];

		$this->controls['bgColor'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Tło menu', 'evoke-one' ),
			'type'        => 'color',
			'css'         => [ [ 'property' => '--evk-oc-bg', 'selector' => '' ] ],
			'description' => esc_html__(
				'Tło samego kadru — widać je tam, gdzie nie sięga żaden panel: '
				. 'przez chwilę po poszerzeniu menu, zanim dojedzie panel podrzędny. '
				. 'PUSTE POLE ZNACZY „jak panel": kolor bierze się wtedy wprost z panelu, '
				. 'na którym właśnie jesteś, więc pasek jest niewidoczny bez ustawiania '
				. 'czegokolwiek. Ustaw ręcznie tylko wtedy, gdy chcesz go widzieć.',
				'evoke-one'
			),
		];

		$this->controls['scrimColor'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Przyciemnienie strony', 'evoke-one' ),
			'type'    => 'color',
			'default' => [ 'rgb' => 'rgba(15, 23, 42, 0.55)' ],
			'css'     => [ [ 'property' => '--evk-oc-scrim', 'selector' => '' ] ],
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

		/*
		 * Jak menu wchodzi na ekran.
		 *
		 * 'slide'  — kadr wysuwa się zza krawędzi i WWOZI TREŚĆ ZE SOBĄ.
		 *            Zachowanie od 1.56.0 i domyślne: nikomu nic nie podmienia
		 *            się pod ręką po aktualizacji.
		 * 'reveal' — treść stoi w miejscu, a przesuwa się sama płaszczyzna
		 *            panelu — jego krawędź przejeżdża po treści i ją odsłania.
		 *
		 * Cała różnica siedzi w arkuszu (przeciw-transformacja na
		 * `.evk-oc-hold`), więc czas i krzywa niżej rządzą obydwoma tak samo.
		 */
		$this->controls['openEffect'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Efekt otwierania', 'evoke-one' ),
			'type'    => 'select',
			'options' => [
				'slide'  => esc_html__( 'Wysuwanie — panel wjeżdża razem z treścią', 'evoke-one' ),
				'reveal' => esc_html__( 'Odsłanianie — treść stoi, panel ją odsłania', 'evoke-one' ),
			],
			'default' => 'slide',
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

		/*
		 * Czas i krzywa TAŚMY — osobno od kadru.
		 *
		 * To jest sedno efektu, nie kosmetyka: wspólny czas daje ruch liniowy,
		 * bo menu wjeżdża i panele przesuwają się dokładnie tak samo. Rozdzielone
		 * czasy sprawiają, że przejście między panelami ma własne tempo.
		 * Puste = to samo co kadr.
		 */
		$this->controls['panelDuration'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Czas przejścia między panelami (s)', 'evoke-one' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 3,
			'step'        => 0.05,
			'placeholder' => esc_html__( 'jak wysuwanie', 'evoke-one' ),
			'required'    => [ 'mode', '=', 'levels' ],
		];

		$this->controls['panelEasing'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Krzywa przejścia między panelami', 'evoke-one' ),
			'type'     => 'select',
			'options'  => $easings,
			'default'  => '',
			'required' => [ 'mode', '=', 'levels' ],
		];

		$this->controls['animateExit'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Animuj wyjście treści', 'evoke-one' ),
			'type'        => 'checkbox',
			'default'     => false,
			'description' => esc_html__(
				'Przy zamykaniu treść menu najpierw wychodzi, a dopiero potem kadr '
				. 'wjeżdża za krawędź. Domyślnie wychodzi TĄ SAMĄ animacją, którą weszła, '
				. 'tylko od końca — bez ustawiania czegokolwiek. Chcesz innego wyjścia? '
				. 'Ustaw elementowi animację z wyzwalaczem „Zamknięcie menu" — ona wygra '
				. 'z cofaniem. Bez ustawienia czekania menu czeka na całą animację, '
				. 'ale nie dłużej niż sekundę.',
				'evoke-one'
			),
		];

		$this->controls['exitWait'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Czekanie na wyjście (s)', 'evoke-one' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 3,
			'step'        => 0.05,
			'placeholder' => esc_html__( 'cały czas animacji', 'evoke-one' ),
			'required'    => [ 'animateExit', '=', true ],
			'description' => esc_html__(
				'Ile menu czeka z wyjazdem kadru na wychodzącą treść. '
				. 'ZERO znaczy „naraz": menu wyjeżdża RAZEM z animacją linków, '
				. 'zamiast po niej — treść nie musi zdążyć zniknąć, zanim cokolwiek '
				. 'ruszy. Puste pole to całkowity czas animacji wyjścia (najwyżej '
				. 'sekunda), czyli ruchy jeden po drugim. Wartość większa niż sama '
				. 'animacja daje chwilę ciszy, zanim menu się zamknie.',
				'evoke-one'
			),
		];

		$this->controls['triggerSelector'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Dodatkowy trigger (selektor)', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => '.moj-burger',
			'description' => esc_html__( 'Gdy burger siedzi poza tym elementem — np. w nagłówku zbudowanym osobno.', 'evoke-one' ),
		];

		$this->controls['toggleClass'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Klasy otwarcia przełącznika', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => 'brx-open  is-active',
			'description' => esc_html__(
				'Przy otwartym menu przełącznik dostaje z automatu brx-open (konwencja '
				. 'Bricksa), is-active (konwencja burgerów) oraz swoją pierwszą klasę '
				. 'z końcówką --opened. To pole jest na wypadek, gdy Twój burger animuje '
				. 'się na jeszcze innej klasie: wpisz ją tutaj, a dojdzie do tamtych. '
				. 'Kilka oddziel spacją. Klasy schodzą przy każdym zamknięciu — także '
				. 'klawiszem Esc i kliknięciem w tło.',
				'evoke-one'
			),
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
		$this->set_attribute( '_root', 'data-effect',     ! empty( $s['openEffect'] ) ? $s['openEffect'] : 'slide' );
		$this->set_attribute( '_root', 'data-level-style', ! empty( $s['levelStyle'] ) ? $s['levelStyle'] : 'expand' );
		$this->set_attribute( '_root', 'data-duration',   isset( $s['duration'] ) && $s['duration'] !== '' ? (string) $s['duration'] : '0.35' );
		/*
		 * Krzywe przeliczone na zapis CSS-a.
		 *
		 * Lista kontrolki jest listą GSAP-a — jedna dla całej wtyczki, żeby
		 * użytkownik uczył się jednego słownika. Ale to menu jedzie na
		 * PRZEJŚCIACH CSS, a CSS nazwy `power2.out` nie zna: nieznana funkcja
		 * czasu unieważnia CAŁĄ deklarację `transition`, razem z czasem
		 * trwania. Wybranie krzywej gasiło więc przejście do zera — menu
		 * przestawało wyjeżdżać, a panele przeskakiwały, przez co podmenu
		 * wyglądało, jakby zasłaniało rodzica zamiast go wypychać.
		 * Przeliczenie siedzi w evk_anim_easing_css() i jest jedno na wtyczkę.
		 */
		$css_ease  = function_exists( 'evk_anim_easing_css' ) && ! empty( $s['easing'] )
			? evk_anim_easing_css( $s['easing'] ) : '';
		$css_pease = function_exists( 'evk_anim_easing_css' ) && ! empty( $s['panelEasing'] )
			? evk_anim_easing_css( $s['panelEasing'] ) : '';

		$this->set_attribute( '_root', 'data-easing',     $css_ease );
		$this->set_attribute( '_root', 'data-panel-duration', isset( $s['panelDuration'] ) && $s['panelDuration'] !== '' ? (string) $s['panelDuration'] : '' );
		// Puste = 45% czasu przejścia, wyliczane w JS. Jawne zero MUSI przejść
		// jako '0' — `! empty()` potraktowałoby je jak brak wartości i odstęp
		// wróciłby mimo wyłączenia.
		$this->set_attribute( '_root', 'data-sub-delay', isset( $s['subDelay'] ) && $s['subDelay'] !== '' ? (string) $s['subDelay'] : '' );
		$this->set_attribute( '_root', 'data-panel-easing',   $css_pease );
		$this->set_attribute( '_root', 'data-start',      ! empty( $s['startPanel'] ) ? $s['startPanel'] : '' );
		$this->set_attribute( '_root', 'data-trigger',    ! empty( $s['triggerSelector'] ) ? $s['triggerSelector'] : '' );
		$this->set_attribute( '_root', 'data-toggle-class', ! empty( $s['toggleClass'] ) ? $s['toggleClass'] : '' );

		/*
		 * Wartości logiczne wprost jako '1'/'0', nie przez pominięcie atrybutu.
		 * Checkbox z `default => true` przy odznaczeniu przysyła pustą wartość,
		 * a nie brak klucza — `! empty()` czyta to poprawnie, ale JS musi
		 * dostać jawne „nie", inaczej nie odróżni go od „nie ustawiono".
		 */
		$this->set_attribute( '_root', 'data-esc-back',   ! empty( $s['escGoesBack'] )      ? '1' : '0' );
		$this->set_attribute( '_root', 'data-close-link', ! empty( $s['closeOnLinkClick'] ) ? '1' : '0' );
		$this->set_attribute( '_root', 'data-lock',       ! empty( $s['lockScroll'] )       ? '1' : '0' );
		$this->set_attribute( '_root', 'data-anim-exit', ! empty( $s['animateExit'] )      ? '1' : '0' );
		// Puste = „cały czas animacji", wyliczane w JS. Jawne ZERO musi przejść
		// jako '0' — `! empty()` potraktowałoby je jak brak wartości i ruchy
		// wróciłyby do grania jeden po drugim mimo wybrania „naraz".
		$this->set_attribute( '_root', 'data-exit-wait',
			isset( $s['exitWait'] ) && $s['exitWait'] !== '' ? (string) $s['exitWait'] : '' );
		$this->set_attribute( '_root', 'data-portal',     ! empty( $s['toBody'] )           ? '1' : '0' );
		$this->set_attribute( '_root', 'data-open-builder', ! empty( $s['openInBuilder'] )  ? '1' : '0' );

		echo "<div {$this->render_attributes( '_root' )}>"
		   . Frontend::render_children( $this )
		   . '</div>';
	}
}
