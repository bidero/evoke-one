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

	/**
	 * UKŁAD PANELU: sześć sekcji, nazwanych TAK SAMO jak w Offcanvas Menu.
	 *
	 * Dwa elementy robiące podobną rzecz mają się otwierać tak samo — stąd
	 * Lokalizacja · Wygląd · Animacja · Zamykanie · Przełącznik · Warstwy,
	 * a nie własny słownik dla każdego z nich.
	 *
	 * Do 1.205.0 stało tu pięć sekcji, w których dwie kontrolki leżały nie tam,
	 * gdzie ich szukać: „Blokuj scroll strony" pod nagłówkiem „Własny
	 * przełącznik", a „Zamknij klawiszem ESC" sama jedna w sekcji „Dostępność".
	 * Obie robią to samo co reszta Zamykania.
	 *
	 * ZASADA PODZIAŁU TEKSTU, ta sama co w offcanvasie od 1.204.0: w opisie
	 * kontrolki zostaje jedno–dwa zdania (co robi, co się stanie), a mechanika
	 * i powody idą do komentarza nad kontrolką.
	 */
	public function set_controls() {

		/* PRZED PIERWSZYM SEPARATOREM, tak jak w Offcanvas Menu: to jedyna
		   kontrolka używana PODCZAS składania menu, a nie przy jego ustawianiu. */
		$this->controls['openbuilder'] = [
			'hasDynamicData' => false,
			'tab'   => 'content',
			'label' => esc_html__( 'Otwórz w builderze', 'evk-circular-menu' ),
			'type'  => 'checkbox',
		];

		// ── Lokalizacja ─────────────────────────────────────────────────────
		$this->controls['sep_lokalizacja'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Lokalizacja', 'evk-circular-menu' ),
			'type'  => 'separator',
		];

		$this->controls['portalToBody'] = [
			'hasDynamicData' => false,
			'tab'     => 'content',
			'label'   => esc_html__( 'Portal do &lt;body&gt;', 'evk-circular-menu' ),
			'type'    => 'checkbox',
			'inline'  => true,
			'small'   => true,
			'default' => true,
			'description' => esc_html__( 'Panel nie jest wtedy ograniczany przez overflow:hidden ani position rodziców.', 'evk-circular-menu' ),
		];

		/* Punkt, z którego kadr się rozwija. Zmienne siedzą na `.evk-cm-content`,
		   bo panel jedzie portalem do <body> razem z nimi — reguła Bricksa
		   celująca w `.brxe-XXXX .evk-cm-content` po przeprowadzce przestaje
		   pasować, a zmienna na samym panelu jedzie z nim. */
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

		// ── Wygląd ──────────────────────────────────────────────────────────
		/* ZACZEPY DLA WŁASNEGO CSS-a przy otwartym menu — zostają w opisie
		   sekcji, bo to jedyny tekst tutaj, którego szuka się PISZĄC STYLE,
		   a nie ustawiając element. Reszta dawnego opisu („można edytować
		   style na elemencie Zawartość menu", kiedy dokładnie schodzą klasy)
		   przeniesiona tu, do komentarza: klasy schodzą dopiero, gdy kadr
		   zaczyna się zwijać, więc przez czas wychodzenia treści styl otwartego
		   menu nadal obowiązuje. */
		$this->controls['sep_wyglad'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Wygląd', 'evk-circular-menu' ),
			'type'        => 'separator',
			'description' => esc_html__(
				'Zaczepy przy otwartym menu: panel niesie .is-open, a korzeń elementu '
				. 'i przełącznik — .brx-open.',
				'evk-circular-menu'
			),
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

		/* PRZYCIEMNIENIE TŁA.
		 *
		 * ZGŁOSZONE Z UŻYCIA: „dodanie przyciemnianego tła, kiedy circular menu
		 * nie zajmuje 100% wysokości/szerokości". Panel ma kontrolki szerokości
		 * i wysokości, więc może być mniejszy od ekranu — a wtedy reszta strony
		 * zostaje w pełnym świetle i nie widać, że menu jest otwarte.
		 *
		 * DOMYŚLNIE WYŁĄCZONE, i to jest decyzja, nie zaniedbanie: przy panelu
		 * na pełny ekran przyciemnienia nie widać spod niczego, a włączone
		 * z automatu zmieniłoby wygląd gotowych stron z panelem przezroczystym.
		 */
		$this->controls['scrimEnabled'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Przyciemnij tło strony', 'evk-circular-menu' ),
			'type'        => 'checkbox',
			'default'     => false,
			'description' => esc_html__( 'Ma sens, gdy panel nie zajmuje całego ekranu. Kliknięcie w tło zamyka menu.', 'evk-circular-menu' ),
		];

		$this->controls['scrimColor'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Kolor przyciemnienia', 'evk-circular-menu' ),
			'type'     => 'color',
			'required' => [ 'scrimEnabled', '=', true ],
			/* Zmienna siedzi na PANELU, nie na przyciemnieniu — panel jedzie do
			   <body> razem z nim, a skrypt czyta ją stamtąd przed przeprowadzką
			   i wpisuje wprost, tak samo jak `--evk-cm-from-top`. Reguła Bricksa
			   celuje w `.brxe-XXXX .evk-cm-content` i po portalu przestaje
			   pasować. */
			'css'      => [ [ 'property' => '--evk-cm-scrim', 'selector' => '.evk-cm-content' ] ],
		];

		// ── Animacja ────────────────────────────────────────────────────────
		$this->controls['sep_animacja'] = [
			'tab'   => 'content',
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

		/* Bez odstępu kadr i treść startują w tej samej klatce i całość wygląda
		   sztywno — nie widać, co po czym następuje. Przez czas odstępu treść
		   stoi w stanie POCZĄTKOWYM swojej animacji, więc nic nie miga. */
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
				'Odstęp między rozwinięciem kadru a ruszeniem animacji w środku. Zero wyłącza.',
				'evk-circular-menu'
			),
		];

		// ── Zamykanie ───────────────────────────────────────────────────────
		$this->controls['sep_zamykanie'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Zamykanie', 'evk-circular-menu' ),
			'type'  => 'separator',
		];

		/* Domyślnie treść wychodzi TĄ SAMĄ animacją, którą weszła, tylko od
		   końca — bez ustawiania czegokolwiek. Kto chce innego wyjścia, ustawia
		   elementowi animację z wyzwalaczem „Zamknięcie menu"; ona wygrywa
		   z cofaniem. Bez ustawienia czekania menu czeka na całą animację, ale
		   nie dłużej niż sekundę. */
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
				. 'Domyślnie tą samą animacją co wejście, tylko od końca.',
				'evk-circular-menu'
			),
		];

		/* Wartość większa niż sama animacja daje chwilę ciszy, zanim menu się
		   zamknie. Puste pole to całkowity czas animacji wyjścia, najwyżej
		   sekunda — czyli ruchy jeden po drugim. */
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
				'Ile menu czeka ze zwijaniem kadru na wychodzącą treść. ZERO znaczy „naraz".',
				'evk-circular-menu'
			),
		];

		$this->controls['closeOnEsc'] = [
			'label'   => esc_html__( 'Zamknij klawiszem ESC', 'evk-circular-menu' ),
			'type'    => 'checkbox',
			'inline'  => true,
			'small'   => true,
			'default' => true,
		];

		/* DOMYŚLNIE WYŁĄCZONE, w odróżnieniu od „Blokuj przewijanie strony"
		   w Offcanvas Menu, gdzie jest włączone. Ta różnica jest zastana i tu
		   zostaje: panel circular bywa mniejszy od ekranu, więc blokowanie
		   strony pod nim nie zawsze ma sens. Zmiana domyślnej przestawiłaby
		   gotowe strony, więc jest decyzją zgłaszającego, nie porządków. */
		$this->controls['lockBodyScrolling'] = [
			'label'   => esc_html__( 'Blokuj scroll strony', 'evk-circular-menu' ),
			'type'    => 'checkbox',
			'inline'  => true,
			'small'   => true,
			'default' => false,
		];

		// ── Przełącznik ─────────────────────────────────────────────────────
		$this->controls['sep_przelacznik'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Przełącznik', 'evk-circular-menu' ),
			'type'        => 'separator',
			'description' => esc_html__( 'Elementy z tą klasą będą otwierać i zamykać menu.', 'evk-circular-menu' ),
		];

		$this->controls['customtoggle'] = [
			'label'       => esc_html__( 'Selektor CSS', 'evk-circular-menu' ),
			'type'        => 'text',
			'placeholder' => '.moj-burger',
		];

		/* Przy otwartym menu przełącznik dostaje z automatu brx-open (konwencja
		   Bricksa), is-active (konwencja burgerów) oraz swoją pierwszą klasę
		   z końcówką --opened. Wszystkie schodzą przy każdym zamknięciu, także
		   klawiszem Esc i kliknięciem poza panelem. To pole jest na wypadek
		   burgera, który animuje się na jeszcze innej klasie. */
		$this->controls['toggleClass'] = [
			'hasDynamicData' => false,
			'label'       => esc_html__( 'Klasy otwarcia przełącznika', 'evk-circular-menu' ),
			'type'        => 'text',
			'placeholder' => 'brx-open  is-active',
			'description' => esc_html__(
				'Dodatkowa klasa dla Twojego burgera — obok brx-open, is-active i własnej '
				. 'klasy z końcówką --opened, które dochodzą same. Kilka oddziel spacją.',
				'evk-circular-menu'
			),
		];

		// ── Warstwy ─────────────────────────────────────────────────────────
		$this->controls['sep_warstwy'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Warstwy', 'evk-circular-menu' ),
			'type'  => 'separator',
		];

		/*
		 * Przełącznik NAD otwartym panelem.
		 *
		 * Dla przełącznika siedzącego w nagłówku, który panel przykrywa.
		 * Podniesienie samego przełącznika z-indeksem wtedy NIE pomaga, i to
		 * nie z powodu za małej liczby: nagłówek tworzy kontekst układania
		 * (wystarczy position: relative z własnym z-index, transform, filter
		 * albo opacity poniżej jedynki), a wewnątrz niego z-index dziecka
		 * rywalizuje wyłącznie z rodzeństwem — z panelem rywalizuje cały
		 * nagłówek jako jedna warstwa.
		 *
		 * DWIE RZECZY, KTÓRE KIEDYŚ ZASKOCZYŁY:
		 *  · reguły pisane przez potomka nagłówka (np. „.header .burger")
		 *    przestają na czas otwarcia pasować, bo węzeł jest gdzie indziej;
		 *    style Bricksa i wtyczki to przeżywają, bo jadą po identyfikatorze
		 *    i klasach;
		 *  · bez blokady przewijania przełącznik zostaje w miejscu, gdy strona
		 *    pod panelem się przewija.
		 *
		 * CO DOKŁADNIE wyjeżdża, wybiera kontrolka niżej — dlatego ten opis
		 * mówi tylko PO CO to jest. Do 1.205.0 powtarzał całą jej treść, czyli
		 * wywód o trzech drogach wisiał przed każdym, kto tej opcji nie włączył.
		 */
		$this->controls['raiseToggle'] = [
			'label'       => esc_html__( 'Przełącznik nad panelem', 'evk-circular-menu' ),
			'type'        => 'checkbox',
			'default'     => false,
			'description' => esc_html__(
				'Na czas otwarcia stawia przełącznik ponad panelem, żeby burger został '
				. 'widoczny i klikalny. Samo podniesienie mu z-indeksu zwykle NIE pomaga.',
				'evk-circular-menu'
			),
		];

		/* Dwie pierwsze drogi przenoszą węzeł na czas otwarcia na koniec strony
		   i zostawiają w nagłówku niewidoczną przekładkę tej samej wielkości,
		   więc nic się nie przebudowuje. Trzecia nie rusza drzewa wcale —
		   podnosi warstwę jednego przodka, przez co nad panel wjeżdża cały pasek
		   RAZEM Z TŁEM; wymaga, żeby ten przodek był pozycjonowany, a na
		   niepozycjonowanym element powie o tym w konsoli. */
		$this->controls['raiseMode'] = [
			'label'    => esc_html__( 'Co nad panelem', 'evk-circular-menu' ),
			'type'     => 'select',
			'options'  => [
				'przelacznik' => esc_html__( 'Sam przełącznik', 'evk-circular-menu' ),
				'wskazane'    => esc_html__( 'Przełącznik i wskazane elementy', 'evk-circular-menu' ),
				'naglowek'    => esc_html__( 'Cały nagłówek', 'evk-circular-menu' ),
			],
			'default'  => 'przelacznik',
			'required' => [ 'raiseToggle', '=', true ],
			'description' => esc_html__(
				'Dwie pierwsze drogi wyjmują sam węzeł i zostawiają przekładkę, więc nagłówek '
				. 'zostaje nietknięty. CAŁY NAGŁÓWEK wjeżdża razem z tłem, ale wymaga '
				. 'pozycjonowanego przodka.',
				'evk-circular-menu'
			),
		];

		$this->controls['raiseSelector'] = [
			'label'       => esc_html__( 'Co jeszcze wyjąć (selektor)', 'evk-circular-menu' ),
			'type'        => 'text',
			'placeholder' => '.logo',
			/* JEDEN warunek, nie łańcuch — łańcuchy w Bricksie nie działają
			   i pilnuje tego tests/bricks-required.test.js. Sam tryb wystarczy:
			   jest widoczny dopiero przy włączonym przełączniku wyżej. */
			'required'    => [ 'raiseMode', '=', 'wskazane' ],
			'description' => esc_html__(
				'Co ma wyjechać nad panel razem z przełącznikiem — na przykład logo. '
				. 'Elementy leżące w panelu są pomijane, bo jadą z nim portalem.',
				'evk-circular-menu'
			),
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
		/* Pomocnik od warstw — wspólny z Offcanvas Menu (assets/js/warstwy.js).
		   Zależność, nie samo enqueue: skrypt czyta `window.evkWarstwy` przy
		   pierwszym otwarciu menu. */
		$deps = [ 'evk-gsap', 'bricks-scripts' ];
		if ( function_exists( 'evk_register_warstwy' ) ) {
			evk_register_warstwy();
			$deps[] = 'evk-warstwy';
		}

		wp_enqueue_script(
			'evk-circular-menu-js',
			EVK_CIRCULAR_MENU_URL . 'assets/circular-menu.js',
			$deps,
			EVK_CIRCULAR_MENU_VERSION,
			true
		);
	}

	public function render() {
		$settings = $this->settings;

		$openbuilder       = ! empty( $settings['openbuilder'] )       ? $settings['openbuilder']       : 0;
		$portalToBody      = evk_flaga( $settings, 'portalToBody', true ) ? '1' : '0';
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
		$toggleClass       = ! empty( $settings['toggleClass'] )       ? $settings['toggleClass']       : '';
		$lockBodyScrolling = ! empty( $settings['lockBodyScrolling'] ) ? '1' : '0';
		$raiseToggle       = ! empty( $settings['raiseToggle'] ) ? '1' : '0';
		$raiseMode         = ! empty( $settings['raiseMode'] ) ? $settings['raiseMode'] : 'przelacznik';
		$raiseSelector     = ! empty( $settings['raiseSelector'] ) ? $settings['raiseSelector'] : '';
		$closeOnEsc        = evk_flaga( $settings, 'closeOnEsc', true )   ? '1' : '0';
		$scrim             = ! empty( $settings['scrimEnabled'] )      ? '1' : '0';

		$this->set_attribute( '_root', 'class',                                 'evk-cm' );
		$this->set_attribute( '_root', 'data-portal',                           $portalToBody );
		$this->set_attribute( '_root', 'data-duration',                         $duration );
		$this->set_attribute( '_root', 'data-easing',                           $easing );
		$this->set_attribute( '_root', 'data-content-delay',                    $contentDelay );
		$this->set_attribute( '_root', 'data-anim-exit',                        $animateExit );
		$this->set_attribute( '_root', 'data-exit-wait',                        $exitWait );
		$this->set_attribute( '_root', 'data-customtoggle',                     $customtoggle );
		$this->set_attribute( '_root', 'data-toggle-class',                     $toggleClass );
		$this->set_attribute( '_root', 'data-lock-scroll',                      $lockBodyScrolling );
		$this->set_attribute( '_root', 'data-scrim',                            $scrim );
		$this->set_attribute( '_root', 'data-raise-toggle',                     $raiseToggle );
		$this->set_attribute( '_root', 'data-raise-mode',                       $raiseMode );
		$this->set_attribute( '_root', 'data-raise-selector',                   $raiseSelector );
		$this->set_attribute( '_root', 'data-open-builder',                     $openbuilder );
		$this->set_attribute( '_root', 'data-close-on-esc',                     $closeOnEsc );

		$output  = "<div {$this->render_attributes( '_root' )}>";
		$output .= Frontend::render_children( $this );
		$output .= "</div>";

		echo $output;
	}
}
