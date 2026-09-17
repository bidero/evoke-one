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

	/**
	 * UKŁAD PANELU: osiem sekcji, od „co to jest" do „gdzie to leży".
	 *
	 * Do 1.204.0 stało tu dwadzieścia osiem kontrolek jednym ciągiem, bez ani
	 * jednego separatora, i 4647 znaków opisów — przy czym dwanaście kontrolek
	 * niosło cały ten tekst, a trzynaście nie miało opisu wcale. Dla porównania
	 * fala ma pięćdziesiąt siedem kontrolek, dwanaście separatorów i pięć razy
	 * mniej tekstu, bo szczegóły siedzą w komentarzach PHP-a.
	 *
	 * ZASADA PODZIAŁU TEKSTU. W opisie kontrolki zostaje jedno–dwa zdania: co to
	 * robi i co się stanie. Mechanika, powody i „dlaczego akurat tak" idą do
	 * komentarza nad kontrolką — tam szuka się ich czytając kod, a nie składając
	 * menu. Instrukcje obsługi, które trzeba mieć POD RĘKĄ w builderze, dostają
	 * osobną kontrolkę typu `info` pod bramką, żeby nie wisiały nad każdym,
	 * kto ich nie potrzebuje.
	 */
	/**
	 * SEKCJE SĄ ZWIJANYMI GRUPAMI BRICKSA — jak w Circular Menu i fali.
	 *
	 * SIEDEM SEKCJI ZESZŁO DO SZEŚCIU GRUP, i to nie jest upraszczanie dla
	 * samego upraszczania: grupa musi mieścić swoje bramki, bo warunek
	 * `required` wskazujący pole z innej grupy jest w tej wtyczce bez pokrycia
	 * (patrz `controls.test.js`). Trzy bramki offcanvasu przecinały sekcje
	 * i obie zmiany wynikają wprost z tego:
	 *
	 *  · „Przejścia między panelami" (dwie kontrolki) wchodzą do „Trybu".
	 *    Obie są bramkowane na `mode = levels`, dokładnie jak „Wejście
	 *    w podmenu", „Panel startowy" i „Opóźnienie panelu podrzędnego".
	 *  · „Esc nie cofa o poziom" PRZENOSI SIĘ z „Zamykania" do „Trybu" — też jest
	 *    bramkowane na `mode = levels`. To jedyne pole, które zmienia sąsiedztwo
	 *    wbrew swojej nazwie, i warto o tym wiedzieć: „Tryb" jest teraz grupą
	 *    wszystkiego, co istnieje WYŁĄCZNIE w trybie poziomów.
	 */
	public function set_control_groups() {
		$this->control_groups['evk_tryb']        = [ 'title' => esc_html__( 'Tryb', 'evoke-one' ),        'tab' => 'content' ];
		$this->control_groups['evk_wyglad']      = [ 'title' => esc_html__( 'Wygląd', 'evoke-one' ),      'tab' => 'content' ];
		$this->control_groups['evk_otwieranie']  = [ 'title' => esc_html__( 'Otwieranie', 'evoke-one' ),  'tab' => 'content' ];
		$this->control_groups['evk_zamykanie']   = [ 'title' => esc_html__( 'Zamykanie', 'evoke-one' ),   'tab' => 'content' ];
		$this->control_groups['evk_przelacznik'] = [ 'title' => esc_html__( 'Przełącznik', 'evoke-one' ), 'tab' => 'content' ];
		$this->control_groups['evk_warstwy']     = [ 'title' => esc_html__( 'Warstwy', 'evoke-one' ),     'tab' => 'content' ];
	}

	public function set_controls() {

		/* LISTA KRZYWYCH POWSTAJE NA POCZĄTKU, bo używają jej DWIE kontrolki
		   z różnych grup: „Krzywa" w Otwieraniu i „Krzywa przejścia między
		   panelami" w Trybie. Stała niżej, tuż przed pierwszą z nich — i przy
		   przejściu na grupy (1.209.0), gdy „Krzywa przejścia" powędrowała
		   do Trybu, została za nią. Objaw: `Undefined variable $easings`
		   i lista wariantów pusta. Złapał to PHPStan, nie przebieg
		   przeglądarkowy — dla testów opcje są tylko tablicą.

		   Ta sama lista co w Animatorze: jedna dla całej wtyczki znaczy, że
		   dorzucenie krzywej działa wszędzie naraz. */
		$easings = [ '' => esc_html__( '— domyślny —', 'evoke-one' ) ];
		if ( function_exists( 'evk_anim_easings' ) ) {
			foreach ( evk_anim_easings() as $e ) $easings[ $e ] = $e;
		}

		/* NA SAMEJ GÓRZE, PRZED PIERWSZYM SEPARATOREM, tak jak „Otwórz
		   w builderze" w Circular Menu. To jedyna kontrolka, której się używa
		   PODCZAS składania menu, a nie przy jego ustawianiu: bez niej panel
		   zamyka się i każda poprawka treści zaczyna się od otwierania go od
		   nowa. Zgłoszone z użycia, pilnuje tego tests/offcanvas.test.js. */
		$this->controls['openInBuilder'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Trzymaj otwarte w builderze', 'evoke-one' ),
			'type'    => 'checkbox',
			'default' => false,
		];

		$this->controls['mode'] = [
			'group'       => 'evk_tryb',
			'tab'         => 'content',
			'label'       => esc_html__( 'Tryb', 'evoke-one' ),
			'type'        => 'select',
			'options'     => [
				'single' => esc_html__( 'Swobodny panel (jeden)', 'evoke-one' ),
				'levels' => esc_html__( 'Poziomy (kilka paneli)', 'evoke-one' ),
			],
			'default'     => 'single',
			'description' => esc_html__(
				'Swobodny panel to jeden ekran. Poziomy pozwalają wchodzić w podmenu i wracać.',
				'evoke-one'
			),
		];

		/* INSTRUKCJA POD BRAMKĄ, NIE W OPISIE TRYBU.
		 *
		 * To jedyny tekst w tym elemencie, który trzeba mieć otwarty PRZY
		 * PRACY — reszta odpowiada na pytanie raz. Wisiał pod kontrolką „Tryb",
		 * czyli także przed każdym, kto wybrał swobodny panel i nigdy nie będzie
		 * go potrzebował. Kontrolka `info` pod tym samym warunkiem co reszta
		 * poziomów pokazuje go dokładnie wtedy, kiedy ma sens. */
		$this->controls['pomocPoziomy'] = [
			'group'       => 'evk_tryb',
			'tab'      => 'content',
			'type'     => 'info',
			'required' => [ 'mode', '=', 'levels' ],
			/* `description`, NIE `content` — tak wygląda jedyna kontrolka `info`
			   w tej wtyczce (evoke-horizontal-scroll/element.php:70) i nie ma
			   powodu, żeby ta była inna. Bricksa nie da się tu sprawdzić, więc
			   idziemy za działającym wzorcem z sąsiada, a nie za zgadywaniem. */
			'description' => esc_html__(
				'JAK OTWORZYĆ DRUGI PANEL: zaznacz przycisk lub odnośnik w panelu startowym '
				. 'i dodaj mu (Style → Atrybuty) atrybut data-evk-oc-go o wartości 1 — panele '
				. 'bez nazwy liczą się po kolejności, więc 1 to drugi panel. '
				. 'Powrót: data-evk-oc-back (bez wartości). Zamknięcie: data-evk-oc-close. '
				. 'Wolisz nazwy zamiast numerów? Nadaj panelowi atrybut data-panel i tę samą '
				. 'nazwę wpisz jako wartość data-evk-oc-go.',
				'evoke-one'
			),
		];

		/* CZEGO NIE MA W OPISIE, a warto wiedzieć czytając kod:
		   · podmenu jest ZAWSZE JEDNO — wejście w kolejne podmienia poprzednie,
		     a „wstecz" i Esc wracają wprost do panelu głównego;
		   · gdy poziomy przestają mieścić się w oknie (na telefonie zwykle od
		     razu), menu samo wraca do pokazywania jednego panelu;
		   · menu z góry i z dołu zawsze jedzie trybem „rodzic wyjeżdża całkiem",
		     bo poszerzanie ma sens wyłącznie w poziomie. */
		$this->controls['levelStyle'] = [
			'group'       => 'evk_tryb',
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
				'Przy poszerzaniu panel główny zostaje widoczny obok podmenu. Na wąskim '
				. 'oknie menu i tak wraca do pokazywania jednego panelu.',
				'evoke-one'
			),
		];

		$this->controls['startPanel'] = [
			'group'       => 'evk_tryb',
			'tab'         => 'content',
			'label'       => esc_html__( 'Panel startowy (ID)', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'pierwszy w kolejności', 'evoke-one' ),
			'required'    => [ 'mode', '=', 'levels' ],
		];

		/* Bez odstępu oba ruchy zaczynają i kończą się równo, więc nie widać,
		   co po czym następuje — całość wygląda sztywno. Z odstępem najpierw
		   poszerza się kadr (albo wyjeżdża poprzedni panel podrzędny), a potem
		   dopiero nowy dojeżdża. */
		$this->controls['subDelay'] = [
			'group'       => 'evk_tryb',
			'tab'         => 'content',
			'label'       => esc_html__( 'Opóźnienie panelu podrzędnego (s)', 'evoke-one' ),
			'type'        => 'number',
			'step'        => 0.05,
			'min'         => 0,
			'placeholder' => esc_html__( '45% czasu przejścia', 'evoke-one' ),
			'required'    => [ 'mode', '=', 'levels' ],
			'description' => esc_html__(
				'Odstęp między zrobieniem miejsca a wjazdem panelu podrzędnego. Zero wyłącza.',
				'evoke-one'
			),
		];

		/*
		 * Czas i krzywa TAŚMY — osobno od kadru.
		 *
		 * To jest sedno efektu, nie kosmetyka: wspólny czas daje ruch liniowy,
		 * bo menu wjeżdża i panele przesuwają się dokładnie tak samo. Rozdzielone
		 * czasy sprawiają, że przejście między panelami ma własne tempo.
		 */
		$this->controls['panelDuration'] = [
			'group'       => 'evk_tryb',
			'tab'         => 'content',
			'label'       => esc_html__( 'Czas przejścia między panelami (s)', 'evoke-one' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 3,
			'step'        => 0.05,
			'placeholder' => esc_html__( 'jak wysuwanie', 'evoke-one' ),
			'required'    => [ 'mode', '=', 'levels' ],
			'description' => esc_html__(
				'Własne tempo przejścia między panelami. Puste = to samo co wysuwanie kadru.',
				'evoke-one'
			),
		];

		$this->controls['panelEasing'] = [
			'group'       => 'evk_tryb',
			'tab'      => 'content',
			'label'    => esc_html__( 'Krzywa przejścia między panelami', 'evoke-one' ),
			'type'     => 'select',
			'options'  => $easings,
			'default'  => '',
			'required' => [ 'mode', '=', 'levels' ],
		];

		$this->controls['escGoesBack_off'] = [
			'group'       => 'evk_tryb',
			'tab'         => 'content',
			'label'       => esc_html__( 'Esc nie cofa o poziom', 'evoke-one' ),
			'type'        => 'checkbox',
			'default'     => false,
			/* ODWRÓCONY PRZEŁĄCZNIK — domyślna MUSI być wyłączona.
			   Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać
			   jako „wyłączone", więc pole z `'default' => true` jest nie do
			   wyłączenia. Powody i dowód: evk_wlaczone() w flaga.php. */
			'required'    => [ 'mode', '=', 'levels' ],
			'description' => esc_html__( 'Na panelu startowym Esc zamyka. Wyłączone: Esc zawsze zamyka.', 'evoke-one' ),
		];

		$this->controls['side'] = [
			'group'       => 'evk_wyglad',
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
			'group'       => 'evk_wyglad',
			'tab'         => 'content',
			'label'       => esc_html__( 'Szerokość / wysokość panelu', 'evoke-one' ),
			'type'        => 'text',
			'default'     => 'min(420px, 100%)',
			'css'         => [ [ 'property' => '--evk-oc-size', 'selector' => '' ] ],
			'description' => esc_html__( 'Dowolna miara CSS; zapisuje --evk-oc-size.', 'evoke-one' ),
		];

		/* Kadr stoi, panele jadą po nim jak po taśmie — dlatego kolor kadru widać
		   wyłącznie w szczelinie między nimi. Puste pole jest tu wartością
		   DZIAŁAJĄCĄ, nie brakiem ustawienia: skrypt bierze wtedy kolor wprost
		   z panelu, na którym właśnie jesteśmy. */
		$this->controls['bgColor'] = [
			'group'       => 'evk_wyglad',
			'tab'         => 'content',
			'label'       => esc_html__( 'Tło menu', 'evoke-one' ),
			'type'        => 'color',
			'css'         => [ [ 'property' => '--evk-oc-bg', 'selector' => '' ] ],
			'description' => esc_html__(
				'Widać je tam, gdzie nie sięga panel. Puste pole znaczy „jak panel" — '
				. 'kolor bierze się wtedy z panelu, na którym jesteś.',
				'evoke-one'
			),
		];

		/* GRADIENT NA KADRZE, NIE NA PANELU — i to jest cała różnica.
		   ZGŁOSZONE Z UŻYCIA: „jak nałożę gradient na 1 panel, podczas zmiany
		   paneli odjeżdża i zastaje tło menu". Panele jadą na taśmie, więc ich
		   tło jedzie razem z nimi; kadr stoi. Gradient położony tutaj leży pod
		   wszystkimi panelami naraz i przy przejściu się nie rusza — a panele są
		   domyślnie przezroczyste, więc prześwieca przez nie bez ustawiania
		   czegokolwiek. Własny gradient na panelu nadal działa i przykrywa ten
		   spodni. W trybie „kadr się poszerza" rozciąga się razem z menu. */
		$this->controls['bgGradient'] = [
			'group'       => 'evk_wyglad',
			'tab'         => 'content',
			'label'       => esc_html__( 'Gradient tła menu', 'evoke-one' ),
			'type'        => 'gradient',
			'css'         => [ [ 'property' => '--evk-oc-bg-img', 'selector' => '' ] ],
			'description' => esc_html__(
				'Leży POD panelami, więc przy przechodzeniu między nimi stoi w miejscu '
				. 'zamiast odjeżdżać razem z panelem. Kładzie się na kolorze z pola wyżej.',
				'evoke-one'
			),
		];

		$this->controls['scrimColor'] = [
			'group'       => 'evk_wyglad',
			'tab'     => 'content',
			'label'   => esc_html__( 'Przyciemnienie strony', 'evoke-one' ),
			'type'    => 'color',
			'default' => [ 'rgb' => 'rgba(15, 23, 42, 0.55)' ],
			'css'     => [ [ 'property' => '--evk-oc-scrim', 'selector' => '' ] ],
		];

		/*
		 * Jak menu wchodzi na ekran.
		 *
		 * 'slide'  — kadr wysuwa się zza krawędzi i WWOZI TREŚĆ ZE SOBĄ.
		 *            Zachowanie od 1.56.0 i domyślne: nikomu nic nie podmienia
		 *            się pod ręką po aktualizacji.
		 * 'reveal' — treść stoi w miejscu, a przesuwa się sama płaszczyzna
		 *            panelu — jego krawędź przejeżdża po treści i ją odsłania.
		 * 'curve'  — to samo, ale krawędź jest krzywą Béziery.
		 *
		 * Cała różnica siedzi w arkuszu (przeciw-transformacja na
		 * `.evk-oc-hold`), więc czas i krzywa niżej rządzą wszystkimi tak samo.
		 */
		$this->controls['openEffect'] = [
			'group'       => 'evk_otwieranie',
			'tab'     => 'content',
			'label'   => esc_html__( 'Efekt otwierania', 'evoke-one' ),
			'type'    => 'select',
			'options' => [
				'slide'  => esc_html__( 'Wysuwanie — panel wjeżdża razem z treścią', 'evoke-one' ),
				'reveal' => esc_html__( 'Odsłanianie — treść stoi, panel ją odsłania', 'evoke-one' ),
				'curve'  => esc_html__( 'Wygięta ściana — krawędź odsłaniania jest krzywą', 'evoke-one' ),
			],
			'default' => 'slide',
		];

		/* Steruje punktem kontrolnym Béziery: przy pełnej sile środek brzegu
		   wyprzedza krawędź o ćwierć panelu. */
		$this->controls['curveIntensity'] = [
			'group'       => 'evk_otwieranie',
			'tab'         => 'content',
			'label'       => esc_html__( 'Siła wygięcia', 'evoke-one' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 1,
			'step'        => 0.05,
			'default'     => 1,
			'required'    => [ 'openEffect', '=', 'curve' ],
			'description' => esc_html__( 'Zero daje prostą krawędź, czyli zwykłe odsłanianie.', 'evoke-one' ),
		];

		$this->controls['duration'] = [
			'group'       => 'evk_otwieranie',
			'tab'     => 'content',
			'label'   => esc_html__( 'Czas wysuwania (s)', 'evoke-one' ),
			'type'    => 'number',
			'min'     => 0,
			'max'     => 3,
			'step'    => 0.05,
			'default' => 0.35,
		];

		$this->controls['easing'] = [
			'group'       => 'evk_otwieranie',
			'tab'     => 'content',
			'label'   => esc_html__( 'Krzywa', 'evoke-one' ),
			'type'    => 'select',
			'options' => $easings,
			'default' => '',
		];

		/* Domyślnie treść wychodzi TĄ SAMĄ animacją, którą weszła, tylko od
		   końca — bez ustawiania czegokolwiek. Kto chce innego wyjścia, ustawia
		   elementowi animację z wyzwalaczem „Zamknięcie menu"; ona wygrywa
		   z cofaniem. Bez ustawienia czekania menu czeka na całą animację, ale
		   nie dłużej niż sekundę. Atrybut na korzeniu: data-anim-exit. */
		$this->controls['animateExit'] = [
			'group'       => 'evk_zamykanie',
			'tab'         => 'content',
			'label'       => esc_html__( 'Animuj wyjście treści', 'evoke-one' ),
			'type'        => 'checkbox',
			'default'     => false,
			'description' => esc_html__(
				'Przy zamykaniu treść najpierw wychodzi, a dopiero potem kadr wjeżdża za '
				. 'krawędź. Domyślnie tą samą animacją co wejście, tylko od końca.',
				'evoke-one'
			),
		];

		/* Wartość większa niż sama animacja daje chwilę ciszy, zanim menu się
		   zamknie. Puste pole to całkowity czas animacji wyjścia, najwyżej
		   sekunda — czyli ruchy jeden po drugim. */
		$this->controls['exitWait'] = [
			'group'       => 'evk_zamykanie',
			'tab'         => 'content',
			'label'       => esc_html__( 'Czekanie na wyjście (s)', 'evoke-one' ),
			'type'        => 'number',
			'min'         => 0,
			'max'         => 3,
			'step'        => 0.05,
			'placeholder' => esc_html__( 'cały czas animacji', 'evoke-one' ),
			'required'    => [ 'animateExit', '=', true ],
			'description' => esc_html__(
				'Ile menu czeka z wyjazdem kadru na wychodzącą treść. ZERO znaczy „naraz".',
				'evoke-one'
			),
		];

		$this->controls['closeOnLinkClick_off'] = [
			'group'       => 'evk_zamykanie',
			'tab'     => 'content',
			'label'   => esc_html__( 'Nie zamykaj po kliknięciu w odnośnik', 'evoke-one' ),
			'type'    => 'checkbox',
			'default' => false,
			/* ODWRÓCONY PRZEŁĄCZNIK — domyślna MUSI być wyłączona.
			   Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać
			   jako „wyłączone", więc pole z `'default' => true` jest nie do
			   wyłączenia. Powody i dowód: evk_wlaczone() w flaga.php. */
		];

		$this->controls['lockScroll_off'] = [
			'group'       => 'evk_zamykanie',
			'tab'     => 'content',
			'label'   => esc_html__( 'Nie blokuj przewijania strony', 'evoke-one' ),
			'type'    => 'checkbox',
			'default' => false,
			/* ODWRÓCONY PRZEŁĄCZNIK — domyślna MUSI być wyłączona.
			   Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać
			   jako „wyłączone", więc pole z `'default' => true` jest nie do
			   wyłączenia. Powody i dowód: evk_wlaczone() w flaga.php. */
		];

		$this->controls['triggerSelector'] = [
			'group'       => 'evk_przelacznik',
			'tab'         => 'content',
			'label'       => esc_html__( 'Dodatkowy trigger (selektor)', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => '.moj-burger',
			'description' => esc_html__( 'Gdy burger siedzi poza tym elementem — np. w nagłówku zbudowanym osobno.', 'evoke-one' ),
		];

		/* Przy otwartym menu przełącznik dostaje z automatu brx-open (konwencja
		   Bricksa), is-active (konwencja burgerów) oraz swoją pierwszą klasę
		   z końcówką --opened. Wszystkie schodzą przy każdym zamknięciu, także
		   klawiszem Esc i kliknięciem w tło. To pole jest na wypadek burgera,
		   który animuje się na jeszcze innej klasie. */
		$this->controls['toggleClass'] = [
			'group'       => 'evk_przelacznik',
			'tab'         => 'content',
			'label'       => esc_html__( 'Klasy otwarcia przełącznika', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => 'brx-open  is-active',
			'description' => esc_html__(
				'Dodatkowa klasa dla Twojego burgera — obok brx-open, is-active i własnej '
				. 'klasy z końcówką --opened, które dochodzą same. Kilka oddziel spacją.',
				'evoke-one'
			),
		];

		$this->controls['toBody_off'] = [
			'group'       => 'evk_warstwy',
			'tab'         => 'content',
			'label'       => esc_html__( 'Nie przenoś do <body>', 'evoke-one' ),
			'type'        => 'checkbox',
			'default'     => false,
			/* ODWRÓCONY PRZEŁĄCZNIK — domyślna MUSI być wyłączona.
			   Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać
			   jako „wyłączone", więc pole z `'default' => true` jest nie do
			   wyłączenia. Powody i dowód: evk_wlaczone() w flaga.php. */
			'description' => esc_html__( 'Panel nie jest wtedy ograniczany przez overflow:hidden ani position rodziców.', 'evoke-one' ),
		];

		/*
		 * Nagłówek NAD otwartym menu.
		 *
		 * Zgłoszone z użycia: „kiedy powłoka jest w <body>, nie mogę
		 * spowodować, żeby nagłówek był ponad nim (tam, gdzie jest burger)".
		 *
		 * Samo podniesienie nagłówkowi `z-index` często nie wystarcza, i to
		 * nie z powodu za małej liczby: liczy się WARSTWA NAJDALSZEGO PRZODKA,
		 * który tworzy kontekst nakładania. Gdy nagłówek siedzi w opakowaniu
		 * z `transform`, `filter` albo własnym `z-index`, to opakowanie
		 * rozstrzyga za niego, a jego własna liczba nie ma jak wyjść na
		 * zewnątrz. Skrypt szuka tego przodka i podnosi jego, na czas
		 * otwarcia — patrz `podniesNaglowek()`.
		 */
		$this->controls['headerAbove'] = [
			'group'       => 'evk_warstwy',
			'tab'         => 'content',
			'label'       => esc_html__( 'Przełącznik nad menu', 'evoke-one' ),
			'type'        => 'checkbox',
			'default'     => false,
			'description' => esc_html__(
				'Na czas otwarcia stawia przełącznik ponad panelem, żeby burger został '
				. 'widoczny i klikalny. Samo podniesienie mu z-indeksu zwykle NIE pomaga.',
				'evoke-one'
			),
		];

		/* Dwie pierwsze drogi przenoszą węzeł na czas otwarcia na koniec strony
		   i zostawiają w nagłówku niewidoczną przekładkę tej samej wielkości,
		   więc nic się nie przebudowuje. Trzecia nie rusza drzewa wcale —
		   podnosi warstwę jednego przodka, przez co nad panel wjeżdża cały pasek
		   RAZEM Z TŁEM; wymaga, żeby ten przodek był pozycjonowany. */
		$this->controls['raiseMode'] = [
			'group'       => 'evk_warstwy',
			'tab'         => 'content',
			'label'       => esc_html__( 'Co nad menu', 'evoke-one' ),
			'type'        => 'select',
			'options'     => [
				'przelacznik' => esc_html__( 'Sam przełącznik', 'evoke-one' ),
				'wskazane'    => esc_html__( 'Przełącznik i wskazane elementy', 'evoke-one' ),
				'naglowek'    => esc_html__( 'Cały nagłówek', 'evoke-one' ),
			],
			'default'     => 'przelacznik',
			'required'    => [ 'headerAbove', '=', true ],
			'description' => esc_html__(
				'Dwie pierwsze drogi wyjmują sam węzeł i zostawiają przekładkę, więc nagłówek '
				. 'zostaje nietknięty. CAŁY NAGŁÓWEK wjeżdża razem z tłem, ale wymaga '
				. 'pozycjonowanego przodka.',
				'evoke-one'
			),
		];

		$this->controls['raiseSelector'] = [
			'group'       => 'evk_warstwy',
			'tab'         => 'content',
			'label'       => esc_html__( 'Co jeszcze wyjąć (selektor)', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => '.logo',
			/* JEDEN warunek, nie łańcuch — łańcuchy w Bricksie nie działają
			   i pilnuje tego tests/bricks-required.test.js. Sam tryb wystarczy:
			   jest widoczny dopiero przy włączonym przełączniku wyżej. */
			'required'    => [ 'raiseMode', '=', 'wskazane' ],
			'description' => esc_html__(
				'Co ma wyjechać nad menu razem z przełącznikiem — na przykład logo. '
				. 'Elementy leżące w panelu są pomijane, bo jadą z nim do <body>.',
				'evoke-one'
			),
		];

		/* Działa tylko wtedy, gdy powłoka i ten element leżą w tym samym
		   kontekście nakładania; jeśli nie, właściwą drogą jest przełącznik
		   „Przełącznik nad menu" wyżej, a nie większa liczba tutaj. */
		$this->controls['shellZ'] = [
			'group'       => 'evk_warstwy',
			'tab'         => 'content',
			'label'       => esc_html__( 'Warstwa menu (z-index)', 'evoke-one' ),
			'type'        => 'number',
			'placeholder' => '99990',
			'css'         => [ [ 'property' => '--evk-oc-z', 'selector' => '' ] ],
			'description' => esc_html__(
				'Warstwa całej powłoki (--evk-oc-z). Domyślne 99990 jest celowo wysokie, '
				. 'żeby panel nie chował się pod niczyim nagłówkiem.',
				'evoke-one'
			),
		];
	}

	public function enqueue_scripts() {
		wp_enqueue_style(
			'evk-offcanvas-menu',
			EVK_OFFCANVAS_MENU_URL . 'assets/offcanvas-menu.css',
			[],
			EVK_OFFCANVAS_MENU_VERSION
		);
		/*
		 * GSAP TYLKO PRZY WYGIĘTEJ ŚCIANIE.
		 *
		 * Reszta menu jedzie na przejściach CSS i biblioteki nie potrzebuje —
		 * a doładowanie jej wszystkim byłoby kilkadziesiąt kilobajtów na każdej
		 * stronie z offcanvasem, za efekt, którego prawie nikt nie włącza.
		 *
		 * Sam efekt bez niej nie da się zrobić: ścieżki nie zapisze ani promień
		 * narożnika, ani `clip-path: path()` w procentach, a `d` jako właściwość
		 * CSS nie działa w Firefoksie. Zostaje wpisywanie atrybutu co klatkę.
		 *
		 * Zależność, nie samo enqueue: kolejność ma znaczenie, bo nasz skrypt
		 * buduje oś czasu zaraz po starcie.
		 */
		$deps = [ 'bricks-scripts' ];

		/* Pomocnik od warstw — wspólny z Circular Menu (assets/js/warstwy.js).
		   Zależność, nie samo enqueue: skrypt czyta `window.evkWarstwy` przy
		   pierwszym otwarciu menu, a to bywa wcześniej niż stopka zdąży się
		   wykonać po swojemu. */
		if ( function_exists( 'evk_register_warstwy' ) ) {
			evk_register_warstwy();
			$deps[] = 'evk-warstwy';
		}

		if ( ( $this->settings['openEffect'] ?? '' ) === 'curve' ) {
			if ( function_exists( 'evk_register_gsap_libs' ) ) {
				evk_register_gsap_libs();
				$deps[] = 'evk-gsap';
			}
		}

		wp_enqueue_script(
			'evk-offcanvas-menu-js',
			EVK_OFFCANVAS_MENU_URL . 'assets/offcanvas-menu.js',
			$deps,
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
		// Jawne zero MUSI przejść — `! empty()` wzięłoby je za brak wartości
		// i siła wróciłaby do pełnej mimo wykręcenia jej do zera.
		$this->set_attribute( '_root', 'data-curve-intensity',
			isset( $s['curveIntensity'] ) && $s['curveIntensity'] !== '' ? (string) $s['curveIntensity'] : '1' );
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
		$this->set_attribute( '_root', 'data-esc-back',   evk_wlaczone( $s, 'escGoesBack', 'escGoesBack_off' )      ? '1' : '0' );
		$this->set_attribute( '_root', 'data-close-link', evk_wlaczone( $s, 'closeOnLinkClick', 'closeOnLinkClick_off' ) ? '1' : '0' );
		$this->set_attribute( '_root', 'data-lock',       evk_wlaczone( $s, 'lockScroll', 'lockScroll_off' )       ? '1' : '0' );
		$this->set_attribute( '_root', 'data-anim-exit', ! empty( $s['animateExit'] )      ? '1' : '0' );
		// Puste = „cały czas animacji", wyliczane w JS. Jawne ZERO musi przejść
		// jako '0' — `! empty()` potraktowałoby je jak brak wartości i ruchy
		// wróciłyby do grania jeden po drugim mimo wybrania „naraz".
		$this->set_attribute( '_root', 'data-exit-wait',
			isset( $s['exitWait'] ) && $s['exitWait'] !== '' ? (string) $s['exitWait'] : '' );
		$this->set_attribute( '_root', 'data-portal',     evk_wlaczone( $s, 'toBody', 'toBody_off' )           ? '1' : '0' );
		$this->set_attribute( '_root', 'data-header-above', ! empty( $s['headerAbove'] )   ? '1' : '0' );
		$this->set_attribute( '_root', 'data-raise-mode',
			! empty( $s['raiseMode'] ) ? $s['raiseMode'] : 'przelacznik' );
		$this->set_attribute( '_root', 'data-raise-selector',
			! empty( $s['raiseSelector'] ) ? $s['raiseSelector'] : '' );
		$this->set_attribute( '_root', 'data-open-builder', ! empty( $s['openInBuilder'] )  ? '1' : '0' );

		echo "<div {$this->render_attributes( '_root' )}>"
		   . Frontend::render_children( $this )
		   . '</div>';
	}
}
