<?php
namespace Bricks;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Evoke ONE — Burger
 *
 * Animowany przycisk menu. Jedna rzecz odróżnia go od wszystkiego, co robi to
 * samo, i to ona jest powodem, dla którego powstał:
 *
 * **TEN PRZYCISK NIE MA WŁASNEGO STANU.**
 *
 * Gotowe burgery wiążą sobie własny `click` i same przełączają swoją klasę.
 * Wygląda to niewinnie, dopóki nie postawi się obok czegoś, co też chce
 * wiedzieć, czy menu jest otwarte — a wtedy jeden stan ma dwóch właścicieli
 * i wygrywa ten, którego nasłuch zarejestrował się później. Objawy są za
 * każdym razem inne i za każdym razem trudne do złapania: burger, który
 * przestaje się animować po zmianie kolejności skryptów; krzyżyk, który
 * zostaje krzyżykiem po zamknięciu menu klawiszem Esc.
 *
 * Tutaj stan wystawia MENU, a burger go tylko CZYTA — z klasy `brx-open`,
 * którą Circular Menu i Offcanvas Menu nakładają swoim przełącznikom
 * (patrz updateTriggerState() / setTrigAria()). Dzięki temu Esc, kliknięcie
 * poza panelem i kliknięcie w link działają bez jednej linijki kodu tutaj.
 *
 * Tryb „sam się przełączam" jest dla użycia BEZ naszego menu — akordeon,
 * panel filtrów, cudzy skrypt. Domyślnie wyłączony, bo przy naszym menu
 * przywracałby dokładnie ten problem, dla którego ten element powstał.
 */
class Evk_Burger extends \Bricks\Element {

	public $category = \EVK_BRICKS_CATEGORY;
	public $name     = 'evk-burger';
	public $icon     = 'ti-menu';
	// Nazwa funkcji JS, którą Bricks woła przy renderowaniu elementu —
	// musi się zgadzać z assets/burger.js.
	public $scripts  = [ 'evk_burger_init' ];

	// Etykieta musi się zgadzać z evk_elements_registry()['burger']['label'].
	public function get_label() {
		return 'Burger';
	}

	public function get_keywords() {
		return [ 'evoke', 'burger', 'hamburger', 'menu', 'toggle', 'nav', 'krzyżyk' ];
	}

	/**
	 * Style — JEDNO miejsce, w którym się je dokłada.
	 *
	 * Wzór, od którego uciekamy, miał `render()` na 750 linii `if/else`:
	 * każdy styl niósł własny, wklejony znacznik. Tutaj znacznik jest
	 * WYLICZANY z liczby kresek, więc nowy styl to wiersz w tej tablicy plus
	 * kilka reguł w arkuszu — bez dotykania PHP.
	 *
	 * `lines` to liczba kresek, nie ozdoba: z niej powstaje znacznik i na niej
	 * stoi arkusz (`:nth-child`). Dwukreskowe i trzykreskowe różnią się w tym
	 * elemencie wyłącznie tą liczbą.
	 */
	public static function styles(): array {
		return [
			// ── Trzykreskowe ───────────────────────────────────────────────
			'cross'     => [ 'label' => esc_html__( 'Krzyżyk — 3 kreski', 'evoke-one' ),     'lines' => 3 ],
			'squeeze'   => [ 'label' => esc_html__( 'Ściśnięcie — 3 kreski', 'evoke-one' ),  'lines' => 3 ],
			'collapse'  => [ 'label' => esc_html__( 'Złożenie — 3 kreski', 'evoke-one' ),    'lines' => 3 ],
			'arrow'     => [ 'label' => esc_html__( 'Strzałka — 3 kreski', 'evoke-one' ),    'lines' => 3 ],
			'plus'      => [ 'label' => esc_html__( 'Plus — 3 kreski', 'evoke-one' ),        'lines' => 3 ],
			'stack'     => [ 'label' => esc_html__( 'Zsunięcie — 3 kreski', 'evoke-one' ),   'lines' => 3 ],
			'stagger'   => [ 'label' => esc_html__( 'Po kolei — 3 kreski', 'evoke-one' ),    'lines' => 3 ],
			// Asymetryczne: RÓŻNE długości kresek już w stanie zamkniętym. To jest
			// nowa cecha, nie tylko nowy wygląd — do 1.78.0 każdy styl miał kreski
			// równe i nie dało się tego zmienić żadnym ustawieniem.
			'uneven'    => [ 'label' => esc_html__( 'Nierówne — 3 kreski', 'evoke-one' ),    'lines' => 3 ],

			// ── Dwukreskowe ────────────────────────────────────────────────
			// Ten sam „Odstęp między kreskami" daje w obu rodzinach TĘ SAMĄ
			// przerwę — dwukreskowe rozsuwają się o połowę tego, co skrajne
			// kreski trzykreskowych. Inaczej jedno ustawienie znaczyłoby dwie
			// różne rzeczy zależnie od wybranego stylu.
			'cross-2'   => [ 'label' => esc_html__( 'Krzyżyk — 2 kreski', 'evoke-one' ),     'lines' => 2 ],
			'minus-2'   => [ 'label' => esc_html__( 'Minus — 2 kreski', 'evoke-one' ),       'lines' => 2 ],
			'chevron-2' => [ 'label' => esc_html__( 'Daszek — 2 kreski', 'evoke-one' ),      'lines' => 2 ],
			'plus-2'    => [ 'label' => esc_html__( 'Plus — 2 kreski', 'evoke-one' ),        'lines' => 2 ],
			'slide-2'   => [ 'label' => esc_html__( 'Zjazd — 2 kreski', 'evoke-one' ),       'lines' => 2 ],
			'pinch-2'   => [ 'label' => esc_html__( 'Ściągnięcie — 2 kreski', 'evoke-one' ), 'lines' => 2 ],
			'swap-2'    => [ 'label' => esc_html__( 'Minięcie — 2 kreski', 'evoke-one' ),    'lines' => 2 ],
			'uneven-2'  => [ 'label' => esc_html__( 'Nierówne — 2 kreski', 'evoke-one' ),    'lines' => 2 ],
		];
	}

	public function set_controls() {

		$options = [];
		foreach ( self::styles() as $key => $def ) $options[ $key ] = $def['label'];

		$this->controls['style'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Styl', 'evoke-one' ),
			'type'    => 'select',
			'options' => $options,
			'default' => 'cross',
			'inline'  => true,
		];

		$this->controls['mode'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Co przełącza', 'evoke-one' ),
			'type'    => 'select',
			'options' => [
				'menu'   => esc_html__( 'Nic — stan bierze z menu Evoke', 'evoke-one' ),
				'target' => esc_html__( 'Wskazany element (selektor)', 'evoke-one' ),
				'self'   => esc_html__( 'Tylko siebie', 'evoke-one' ),
			],
			'default'     => 'menu',
			'description' => esc_html__(
				'DOMYŚLNE „nic" jest właściwe, gdy burger otwiera Circular Menu albo '
				. 'Offcanvas Menu: wskazujesz go wtedy w polu „Własny przełącznik → '
				. 'Selektor CSS" tego menu, a stan wystawia MENU. Dzięki temu kreski '
				. 'wracają na miejsce także wtedy, gdy menu zamknie Esc, kliknięcie poza '
				. 'panelem albo kliknięcie w link. '
				. 'WSKAZANY ELEMENT — dla cudzych rzeczy: kliknięcie nakłada celowi klasę '
				. 'brx-open (tak jak robi to przełącznik Bricksa), a burger idzie za celem, '
				. 'więc zamknięcie go czymkolwiek innym też wraca do kresek. '
				. 'TYLKO SIEBIE — gdy klasa na samym przycisku wystarcza i resztą steruje '
				. 'Twój własny kod. '
				. 'Nie kieruj „wskazanego elementu" na menu Evoke: ono pilnuje brx-open samo '
				. 'i stan miałby dwóch właścicieli.',
				'evoke-one'
			),
		];

		$this->controls['target'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Selektor celu', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => '#moj-panel',
			'required'    => [ 'mode', '=', 'target' ],
			'description' => esc_html__(
				'Pasuje kilka elementów? Klasę dostaną wszystkie, ale stan czytamy '
				. 'z PIERWSZEGO — inaczej rozjechane cele dawałyby przycisk migający '
				. 'między stanami. Gdy cel ma identyfikator, burger dostaje jeszcze '
				. 'aria-controls, żeby czytnik ekranu wiedział, czym ten przycisk steruje.',
				'evoke-one'
			),
		];

		$this->controls['targetClass'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Dodatkowe klasy dla celu', 'evoke-one' ),
			'type'        => 'text',
			'placeholder' => 'brx-open',
			'required'    => [ 'mode', '=', 'target' ],
			'description' => esc_html__(
				'Cel dostaje z automatu brx-open. Jeśli Twój panel otwiera się na innej '
				. 'klasie, dopisz ją tutaj — dojdzie do tamtej. Kilka oddziel spacją.',
				'evoke-one'
			),
		];

		$this->controls['ariaLabel'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Opis dla czytnika ekranu', 'evoke-one' ),
			'type'        => 'text',
			'default'     => esc_html__( 'Menu', 'evoke-one' ),
			'description' => esc_html__(
				'Przycisk nie ma tekstu, więc bez opisu czytnik ekranu przeczyta tylko '
				. '„przycisk". Stan otwarcia idzie osobno, atrybutem aria-expanded.',
				'evoke-one'
			),
		];

		// ── Rozmiar ────────────────────────────────────────────────────────
		$this->controls['sizeSeparator'] = [
			'label' => esc_html__( 'Rozmiar', 'evoke-one' ),
			'type'  => 'separator',
		];

		$this->controls['size'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Pole klikalne', 'evoke-one' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-size', 'selector' => '' ] ],
			'placeholder' => '44px',
			'description' => esc_html__(
				'Bok przycisku. Czterdzieści cztery piksele to dolna granica zalecana '
				. 'dla celu dotykowego — mniejszy da się kliknąć myszą, ale nie palcem.',
				'evoke-one'
			),
		];

		$this->controls['lineWidth'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Szerokość kresek', 'evoke-one' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-line-width', 'selector' => '' ] ],
			'placeholder' => '100%',
			'description' => esc_html__(
				'Osobno od pola klikalnego: krótsze kreski w większym przycisku dają '
				. 'zapas na palec bez zmiany rysunku.',
				'evoke-one'
			),
		];

		$this->controls['shortLine'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Długość krótszej kreski', 'evoke-one' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-short', 'selector' => '' ] ],
			'placeholder' => '60%',
			'required'    => [ 'style', '=', [ 'uneven', 'uneven-2' ] ],
			'description' => esc_html__(
				'Dotyczy wyłącznie stylów NIERÓWNYCH — tam jedna kreska jest krótsza od '
				. 'pozostałych już w stanie zamkniętym. Podana w procentach liczy się od '
				. 'szerokości kresek, więc trzyma proporcję przy każdym rozmiarze przycisku.',
				'evoke-one'
			),
		];

		$this->controls['stroke'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Grubość kreski', 'evoke-one' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-stroke', 'selector' => '' ] ],
			'placeholder' => '2px',
		];

		$this->controls['gap'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Odstęp między kreskami', 'evoke-one' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-gap', 'selector' => '' ] ],
			'placeholder' => '7px',
		];

		$this->controls['radius'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Zaokrąglenie kresek', 'evoke-one' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-radius', 'selector' => '' ] ],
			'placeholder' => '0px',
		];

		// ── Kolory ─────────────────────────────────────────────────────────
		$this->controls['colorSeparator'] = [
			'label'       => esc_html__( 'Kolory', 'evoke-one' ),
			'type'        => 'separator',
			'description' => esc_html__(
				'Kolor po otwarciu zostawiony pusty znaczy „ten sam co przed" — kreski '
				. 'zmieniają wtedy tylko kształt. Ustawiony przenika do niego w tym samym '
				. 'czasie, w którym składa się krzyżyk.',
				'evoke-one'
			),
		];

		$this->controls['color'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Kolor kresek', 'evoke-one' ),
			'type'    => 'color',
			'css'     => [ [ 'property' => '--evk-burger-color', 'selector' => '' ] ],
			'default' => [ 'hex' => '#000000' ],
		];

		$this->controls['colorOpen'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Kolor po otwarciu', 'evoke-one' ),
			'type'  => 'color',
			'css'   => [ [ 'property' => '--evk-burger-color-open', 'selector' => '' ] ],
		];

		// ── Animacja ───────────────────────────────────────────────────────
		$this->controls['animSeparator'] = [
			'label' => esc_html__( 'Animacja', 'evoke-one' ),
			'type'  => 'separator',
		];

		$this->controls['duration'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Czas', 'evoke-one' ),
			'type'        => 'number',
			'unit'        => 'ms',
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-time', 'selector' => '' ] ],
			'placeholder' => '400ms',
		];

		/*
		 * Ta sama lista krzywych, co w Animatorze i obu menu — jedna lista dla
		 * całej wtyczki. Wartości są w zapisie GSAP-a, a burger jedzie na
		 * PRZEJŚCIACH CSS, więc idą przez evk_anim_easing_css(); przeliczenie
		 * robi render(), nie kontrolka `css`, bo tamta wpisałaby surową nazwę,
		 * a nieznana funkcja czasu unieważnia CAŁĄ deklarację `transition`
		 * razem z czasem trwania. Ta sama pułapka, która w 1.61.0 gasiła
		 * przejścia w offcanvas.
		 */
		$easings = [ '' => esc_html__( '— domyślna —', 'evoke-one' ) ];
		if ( function_exists( 'evk_anim_easings' ) ) {
			foreach ( evk_anim_easings() as $e ) $easings[ $e ] = $e;
		}
		$this->controls['easing'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Krzywa', 'evoke-one' ),
			'type'    => 'select',
			'options' => $easings,
			'default' => '',
			'inline'  => true,
		];

		$this->controls['openRotate'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Obrót po otwarciu', 'evoke-one' ),
			'type'        => 'number',
			'unit'        => 'deg',
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-open-rotate', 'selector' => '' ] ],
			'placeholder' => '0deg',
			'description' => esc_html__(
				'Obraca CAŁY rysunek przy otwarciu, niezależnie od tego, co robią same '
				. 'kreski. To jest mnożnik listy stylów, a nie ozdoba: krzyżyk z obrotem 90° '
				. 'to krzyżyk stojący, a daszek z obrotem 90° pokazuje w dół. Dzięki temu '
				. 'lista nie puchnie o pozycje różniące się wyłącznie kierunkiem. '
				. 'Wartości ujemne obracają w drugą stronę.',
				'evoke-one'
			),
		];
	}

	public function enqueue_scripts() {
		wp_enqueue_style( 'evk-burger' );
		wp_enqueue_script( 'evk-burger' );
	}

	public function render() {
		$s      = $this->settings;
		$styles = self::styles();

		$key = ! empty( $s['style'] ) && isset( $styles[ $s['style'] ] ) ? $s['style'] : 'cross';
		$def = $styles[ $key ];

		$this->set_attribute( '_root', 'class', [ 'evk-burger', 'evk-burger--' . $key ] );
		// Stan startowy JAWNIE. Bez tego czytnik ekranu do pierwszego kliknięcia
		// nie ma skąd wiedzieć, że przycisk cokolwiek rozwija — ta sama usterka,
		// którą naprawiliśmy w Circular Menu (1.69.0).
		$this->set_attribute( '_root', 'aria-expanded', 'false' );
		$this->set_attribute( '_root', 'aria-label',
			! empty( $s['ariaLabel'] ) ? $s['ariaLabel'] : esc_html__( 'Menu', 'evoke-one' ) );
		// `type="button"` — bez tego przycisk w formularzu wysyła formularz.
		$this->set_attribute( '_root', 'type', 'button' );

		/*
		 * Tryb. Do 1.76.0 stał tu checkbox „Sam się przełącza" — trzy zachowania
		 * nie mieszczą się w dwóch stanach, więc zastąpiła go lista. Zapisane
		 * strony jadą dalej: brak `mode` z włączonym starym checkboxem znaczy
		 * „tylko siebie". Dwie linijki zamiast cichej zmiany działania u kogoś,
		 * kto nie otworzy tego elementu w builderze.
		 */
		$mode = ! empty( $s['mode'] ) ? $s['mode']
			: ( ! empty( $s['selfToggle'] ) ? 'self' : 'menu' );

		if ( $mode === 'self' ) {
			$this->set_attribute( '_root', 'data-evk-burger-self', '1' );
		} elseif ( $mode === 'target' && ! empty( $s['target'] ) ) {
			$this->set_attribute( '_root', 'data-evk-burger-target', $s['target'] );
			if ( ! empty( $s['targetClass'] ) ) {
				$this->set_attribute( '_root', 'data-evk-burger-target-class', $s['targetClass'] );
			}
		}

		// Krzywa przeliczona na zapis CSS-a — patrz komentarz przy kontrolce.
		$ease = function_exists( 'evk_anim_easing_css' ) && ! empty( $s['easing'] )
			? evk_anim_easing_css( $s['easing'] ) : '';
		if ( $ease !== '' ) {
			$this->set_attribute( '_root', 'style', '--evk-burger-ease:' . $ease );
		}

		/*
		 * Znacznik WYLICZANY z liczby kresek, nie wklejany per styl. Stąd bierze
		 * się różnica między dwukreskowym a trzykreskowym: to jedna liczba
		 * w tablicy stylów, a nie druga gałąź kodu.
		 */
		$lines = str_repeat( '<span class="evk-burger__line"></span>', (int) $def['lines'] );

		echo "<button {$this->render_attributes( '_root' )}>"
		   . '<span class="evk-burger__box">' . $lines . '</span>'
		   . '</button>';
	}
}
