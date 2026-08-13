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
	 *
	 * `short` znaczy „ten styl czyta kontrolkę »Długość krótszej kreski«".
	 * Stoi tutaj, a nie w warunku przy kontrolce, żeby dołożenie stylu
	 * asymetrycznego nadal było JEDNYM wierszem — inaczej lista w `required`
	 * po cichu rozjeżdżałaby się z rejestrem i pole chowałoby się przy stylu,
	 * który na nim stoi.
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
			'uneven'    => [ 'label' => esc_html__( 'Nierówne — 3 kreski', 'evoke-one' ),    'lines' => 3, 'short' => true ],
			// Asymetria z KRAWĘDZIĄ: krótkie kreski nie są już wyśrodkowane, tylko
			// dosunięte do lewej albo do prawej. Różnica wobec „nierównych" jest
			// widoczna bez ruszania przyciskiem — i to ona wymagała wyrównania,
			// które trzyma się swojej krawędzi także przy piśmie od prawej.
			'zigzag'    => [ 'label' => esc_html__( 'Zygzak — 3 kreski', 'evoke-one' ),      'lines' => 3, 'short' => true ],
			'steps'     => [ 'label' => esc_html__( 'Schodki — 3 kreski', 'evoke-one' ),     'lines' => 3, 'short' => true ],

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
			'uneven-2'  => [ 'label' => esc_html__( 'Nierówne — 2 kreski', 'evoke-one' ),    'lines' => 2, 'short' => true ],
			'uneven-right-2' => [ 'label' => esc_html__( 'Nierówne z prawej — 2 kreski', 'evoke-one' ), 'lines' => 2, 'short' => true ],
			'split-2'   => [ 'label' => esc_html__( 'Rozstrzelone — 2 kreski', 'evoke-one' ), 'lines' => 2, 'short' => true ],
		];
	}

	/**
	 * Pozycje tekstu przy ikonie. Klucz idzie wprost w klasę-modyfikator
	 * (`evk-burger--tekst-<klucz>`), więc arkusz i lista nie mogą się rozjechać.
	 */
	public static function text_positions(): array {
		return [
			'za'    => esc_html__( 'Za ikoną', 'evoke-one' ),
			'przed' => esc_html__( 'Przed ikoną', 'evoke-one' ),
			'nad'   => esc_html__( 'Nad ikoną', 'evoke-one' ),
			'pod'   => esc_html__( 'Pod ikoną', 'evoke-one' ),
		];
	}

	public function set_controls() {

		$options = [];
		foreach ( self::styles() as $key => $def ) $options[ $key ] = $def['label'];

		/*
		 * ŹRÓDŁO IKONY — jedna oś zamiast trzech doklejonych funkcji.
		 *
		 * Do 1.78.0 burger rysował kreski, bo taki się urodził. Ale jego wartością
		 * nigdy nie były kreski, tylko instalacja stanu: spięcie z menu, tryb celu,
		 * aria-expanded, redukcja ruchu, powrót do stanu zamkniętego przy Esc.
		 * To wszystko jest niezależne od tego, CO przycisk pokazuje — więc kreski
		 * przestają być wbudowanym założeniem i stają się jedną z możliwości.
		 */
		$this->controls['iconSource'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Co pokazuje przycisk', 'evoke-one' ),
			'type'    => 'select',
			'options' => [
				'kreski' => esc_html__( 'Kreski — animowane style', 'evoke-one' ),
				'ikona'  => esc_html__( 'Własne ikony (zamknięta i otwarta)', 'evoke-one' ),
				'brak'   => esc_html__( 'Nic — sam tekst', 'evoke-one' ),
			],
			'default'     => 'kreski',
			'inline'      => true,
			'description' => esc_html__(
				'Tekst dochodzi do KAŻDEGO z tych trzech — także do kresek. „Nic" razem '
				. 'z wypełnionym tekstem daje wariant czysto tekstowy, np. MENU / ZAMKNIJ.',
				'evoke-one'
			),
		];

		$this->controls['style'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Styl', 'evoke-one' ),
			'type'    => 'select',
			'options' => $options,
			'default' => 'cross',
			'inline'  => true,
			// Warunek na „różne od", a nie na „równe kreskom": nieustawione pole
			// czyta się jak puste, więc porównanie z domyślną wartością chowałoby
			// listę stylów w świeżo wstawionym elemencie.
			'required' => [ 'iconSource', '!=', [ 'ikona', 'brak' ] ],
		];

		$this->controls['iconClosed'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Ikona — zamknięte', 'evoke-one' ),
			'type'        => 'icon',
			'required'    => [ 'iconSource', '=', 'ikona' ],
		];

		$this->controls['iconOpen'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Ikona — otwarte', 'evoke-one' ),
			'type'        => 'icon',
			'required'    => [ 'iconSource', '=', 'ikona' ],
			'description' => esc_html__(
				'Pusta znaczy „ta sama co zamknięta" — przycisk zmienia wtedy tylko kolor. '
				. 'Obie leżą NA SOBIE, więc przełączenie nie przesuwa niczego obok. '
				. 'Ikony rysowane obrysem zostają obrysem, a kolor biorą z pól niżej. '
				. 'Jeśli mimo to widzisz pod ikoną prostokąt albo koło, których nie da się '
				. 'zdjąć — jest w samym pliku SVG i trzeba innego pliku.',
				'evoke-one'
			),
		];

		$this->controls['iconSize'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Wielkość ikony', 'evoke-one' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-icon-size', 'selector' => '' ] ],
			'placeholder' => '24px',
			'required'    => [ 'iconSource', '=', 'ikona' ],
		];

		// ── Tekst ──────────────────────────────────────────────────────────
		// OSOBNA oś, nie wariant źródła: dwa sloty, bo napis przy otwartym menu
		// zwykle brzmi inaczej niż przy zamkniętym.
		$this->controls['textSeparator'] = [
			'label' => esc_html__( 'Tekst', 'evoke-one' ),
			'type'  => 'separator',
		];

		$this->controls['textClosed'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Tekst — zamknięte', 'evoke-one' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => 'MENU',
		];

		$this->controls['textOpen'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Tekst — otwarte', 'evoke-one' ),
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => 'ZAMKNIJ',
			'description' => esc_html__(
				'Pusty znaczy „ten sam co zamknięty", więc przy niezmiennym napisie '
				. 'wystarczy jedno pole. Oba napisy leżą NA SOBIE i przycisk ma szerokość '
				. 'dłuższego z nich — dzięki temu przełączenie nie przesuwa sąsiadów. '
				. 'Gdy tekst jest wpisany, opis dla czytnika ekranu NIE wychodzi: nazwą '
				. 'przycisku staje się to, co widać.',
				'evoke-one'
			),
		];

		$this->controls['textPosition'] = [
			'tab'      => 'content',
			'label'    => esc_html__( 'Pozycja tekstu', 'evoke-one' ),
			'type'     => 'select',
			'options'  => self::text_positions(),
			'default'  => 'za',
			'inline'   => true,
		];

		$this->controls['textGap'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Odstęp od ikony', 'evoke-one' ),
			'type'        => 'number',
			'units'       => true,
			'inline'      => true,
			'css'         => [ [ 'property' => '--evk-burger-text-gap', 'selector' => '' ] ],
			'placeholder' => '8px',
			'description' => esc_html__(
				'Przyjmuje wartości UJEMNE i przy węższych kreskach to one są zwykle '
				. 'potrzebne. Pudełko rysunku jest kwadratem o boku pola klikalnego, więc '
				. 'kreski krótsze niż pełna szerokość zostawiają w nim pustkę i napis stoi '
				. 'od nich dalej, niż mówi ta wartość — przy kresce 60% jest to około '
				. 'dziewięciu pikseli. Minus tę pustkę odejmuje.',
				'evoke-one'
			),
		];

		/*
		 * Padding SAMEGO NAPISU, a nie przycisku. Padding korzenia daje Bricks
		 * natywnie w zakładce Styl, ale odsuwa napis RAZEM z rysunkiem — więc
		 * nie da się nim ustawić jednego względem drugiego, a to jedyny powód,
		 * dla którego ta kontrolka istnieje. Stąd celowanie wprost w slot,
		 * tak jak Circular Title celuje typografią w `.evk-arc__inner`.
		 */
		$this->controls['textPadding'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Wewnętrzny odstęp napisu', 'evoke-one' ),
			'type'        => 'dimensions',
			'css'         => [ [ 'property' => 'padding', 'selector' => '.evk-burger__text' ] ],
			'description' => esc_html__(
				'Do wyrównania napisu z ikoną, gdy krój odstawia go od jej linii. '
				. 'Przycisk ŚRODKUJE pudełko napisu, więc nierówna góra i dół przesuwają '
				. 'go o POŁOWĘ różnicy — cztery piksele u góry dają dwa piksele w dół. '
				. 'Przy napisie nad ikoną albo pod nią tak samo działają lewa i prawa.',
				'evoke-one'
			),
		];

		$this->controls['modeSeparator'] = [
			'label' => esc_html__( 'Działanie', 'evoke-one' ),
			'type'  => 'separator',
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
			// Widoczny tekst wyklucza ten opis, więc pole schodzi z oczu razem
			// z powodem, dla którego istnieje.
			'required'    => [ 'textClosed', '=', '' ],
			'description' => esc_html__(
				'Przycisk BEZ TEKSTU nie ma czego przeczytać, więc czytnik ekranu powie '
				. 'tylko „przycisk". Gdy wpiszesz tekst, ten opis nie wychodzi wcale: '
				. 'przykryłby widoczny napis, a nazwa inna od tego, co widać, psuje '
				. 'sterowanie głosem — użytkownik mówi „kliknij MENU", a przeglądarka '
				. 'szuka czegoś innego. Stan otwarcia idzie osobno, atrybutem aria-expanded.',
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
			// Lista stylów asymetrycznych IDZIE Z REJESTRU. Wpisana tu z ręki
			// rozjeżdżałaby się przy każdym nowym stylu, a objawem byłoby pole
			// schowane akurat tam, gdzie jest potrzebne.
			'required'    => [ 'style', '=', array_keys( array_filter(
				self::styles(), function ( $d ) { return ! empty( $d['short'] ); } ) ) ],
			'description' => esc_html__(
				'Dotyczy wyłącznie stylów ASYMETRYCZNYCH — tam co najmniej jedna kreska '
				. 'jest krótsza od pozostałych już w stanie zamkniętym. Podana w procentach '
				. 'liczy się od boku pola klikalnego, więc trzyma proporcję przy każdym '
				. 'rozmiarze przycisku. W „schodkach" wylicza się z niej także kreska '
				. 'środkowa, żeby jedno pole sterowało całą proporcją.',
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
		/*
		 * DWIE NIEZALEŻNE PARY, i to jest jedyna rzecz, którą trzeba tu wiedzieć.
		 * Rysunek ma swoją parę, napis swoją — żadne pole nie maluje cudzego
		 * kawałka. Do 1.81.0 pole „po otwarciu" było jedno i malowało wszystko,
		 * co przycisk pokazywał; dołożenie węższego pola dla napisu zrobiło
		 * z tamtego połowę pary, ale zostawiło mu ogólną nazwę. Wychodziło z tego
		 * zestawienie, w którym pole WĘŻSZE brzmi konkretniej niż ogólne — więc
		 * czytało się je jako to właściwe i kreski zostawały w swoim kolorze.
		 * Stąd nazwy mówiące wprost, co które maluje.
		 */
		$this->controls['colorSeparator'] = [
			'label'       => esc_html__( 'Kolory', 'evoke-one' ),
			'type'        => 'separator',
			'description' => esc_html__(
				'Rysunek i napis mają OSOBNE pary pól i nie malują się nawzajem. '
				. 'W każdej parze pole „po otwarciu" zostawione puste znaczy „ten sam '
				. 'kolor co przed".',
				'evoke-one'
			),
		];

		$this->controls['color'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Kolor kresek i ikony', 'evoke-one' ),
			'type'        => 'color',
			'css'         => [ [ 'property' => '--evk-burger-color', 'selector' => '' ] ],
			'default'     => [ 'hex' => '#000000' ],
			'description' => esc_html__(
				'Dotyczy rysunku — i kresek, i własnej ikony. Napis ma własne pola niżej '
				. 'i za tym kolorem NIE idzie.',
				'evoke-one'
			),
		];

		$this->controls['colorOpen'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Kolor kresek i ikony po otwarciu', 'evoke-one' ),
			'type'        => 'color',
			'css'         => [ [ 'property' => '--evk-burger-color-open', 'selector' => '' ] ],
			'description' => esc_html__(
				'Pusty znaczy „ten sam co przed" — rysunek zmienia wtedy tylko kształt. '
				. 'Ustawiony przenika do niego w tym samym czasie, w którym składa się '
				. 'krzyżyk. To pole NIE dotyczy napisu.',
				'evoke-one'
			),
		];

		/*
		 * Napis był jedyną widoczną częścią przycisku BEZ koloru stanu otwartego:
		 * kreski mają swój, ikony mają swój, a tekst dziedziczył kolor przycisku
		 * i zostawał z nim do końca.
		 *
		 * Tylko stan otwarty. Kolor zamkniętego napisu daje natywna typografia
		 * Bricksa — napis dziedziczy go z przycisku — więc własna kontrolka
		 * dublowałaby to, co już działa. Pod „Kolor kresek" napisu nie podpinamy:
		 * przemalowałoby to tekst każdemu, kto ustawił kolor kresek.
		 */
		$this->controls['textColorOpen'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Kolor napisu po otwarciu', 'evoke-one' ),
			'type'        => 'color',
			'css'         => [ [ 'property' => '--evk-burger-text-color-open', 'selector' => '' ] ],
			// Bez warunku widoczności: tekst bierze się z KTÓREGOKOLWIEK z dwóch
			// pól, a `required` umie patrzeć tylko na jedno. Schowanie tej
			// kontrolki przy wypełnionym samym „otwartym" byłoby gorsze niż
			// pokazanie jej o jeden raz za dużo.
			'description' => esc_html__(
				'Dotyczy WYŁĄCZNIE napisu — kreski i ikona mają własne pole wyżej '
				. 'i za tym kolorem nie idą. Pusty znaczy „ten sam co przed otwarciem": '
				. 'napis trzyma wtedy kolor wzięty z typografii przycisku. Ustawiony '
				. 'przenika do niego w tym samym czasie, w którym składa się krzyżyk.',
				'evoke-one'
			),
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

		$source = ! empty( $s['iconSource'] ) && in_array( $s['iconSource'], [ 'kreski', 'ikona', 'brak' ], true )
			? $s['iconSource'] : 'kreski';

		/*
		 * Pusty slot znaczy „ten sam co drugi" — w OBIE strony. Dzięki temu jedno
		 * wypełnione pole daje niezmienny napis, a każdy stan przycisku ma
		 * widoczną nazwę. Gdyby zapasowa wartość szła tylko w jedną stronę,
		 * wypełnienie samego „otwartego" zostawiłoby stan zamknięty bez nazwy.
		 */
		$closed  = isset( $s['textClosed'] ) ? trim( (string) $s['textClosed'] ) : '';
		$opened  = isset( $s['textOpen'] )   ? trim( (string) $s['textOpen'] )   : '';
		$hasText = $closed !== '' || $opened !== '';
		if ( $closed === '' ) $closed = $opened;
		if ( $opened === '' ) $opened = $closed;

		$classes = [ 'evk-burger', 'evk-burger--' . $key ];
		if ( $hasText ) {
			$pos = ! empty( $s['textPosition'] ) && isset( self::text_positions()[ $s['textPosition'] ] )
				? $s['textPosition'] : 'za';
			$classes[] = 'evk-burger--z-tekstem';
			$classes[] = 'evk-burger--tekst-' . $pos;
		}
		$this->set_attribute( '_root', 'class', $classes );

		// Stan startowy JAWNIE. Bez tego czytnik ekranu do pierwszego kliknięcia
		// nie ma skąd wiedzieć, że przycisk cokolwiek rozwija — ta sama usterka,
		// którą naprawiliśmy w Circular Menu (1.69.0).
		$this->set_attribute( '_root', 'aria-expanded', 'false' );
		/*
		 * Opis dla czytnika ekranu WYŁĄCZNIE przy przycisku bez tekstu.
		 * Przy widocznym napisie `aria-label` by go PRZYKRYŁ, a nazwa inna niż
		 * to, co widać, psuje sterowanie głosem: użytkownik mówi „kliknij MENU",
		 * przeglądarka szuka „Menu" (WCAG 2.5.3). Nazwą staje się wtedy sam tekst.
		 */
		if ( ! $hasText ) {
			$this->set_attribute( '_root', 'aria-label',
				! empty( $s['ariaLabel'] ) ? $s['ariaLabel'] : esc_html__( 'Menu', 'evoke-one' ) );
		}
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
		 * Rysunek. Trzy źródła, ale ANI JEDNEJ gałęzi w tym, co dzieje się dalej:
		 * stan i tak przychodzi klasą `brx-open`, a przełączanie — zarówno ikon,
		 * jak i napisów — robi wyłącznie arkusz. Dlatego `burger.js` nie wie o tej
		 * partii nic i nie musiał się zmienić.
		 */
		$rysunek = '';

		if ( $source === 'kreski' ) {
			/*
			 * Znacznik WYLICZANY z liczby kresek, nie wklejany per styl. Stąd bierze
			 * się różnica między dwukreskowym a trzykreskowym: to jedna liczba
			 * w tablicy stylów, a nie druga gałąź kodu.
			 */
			$rysunek = '<span class="evk-burger__box">'
				. str_repeat( '<span class="evk-burger__line"></span>', (int) $def['lines'] )
				. '</span>';
		} elseif ( $source === 'ikona' ) {
			$zamk = self::burger_icon( $s['iconClosed'] ?? null );
			// Pusta „otwarta" znaczy „ta sama co zamknięta" — ten sam zapasowy
			// zapis co przy tekście i przy kolorze po otwarciu.
			$otw  = self::burger_icon( $s['iconOpen'] ?? null );
			if ( $otw === '' ) $otw = $zamk;
			$rysunek = '<span class="evk-burger__icons">'
				. '<span class="evk-burger__icon evk-burger__icon--zamk">' . $zamk . '</span>'
				. '<span class="evk-burger__icon evk-burger__icon--otw">' . $otw . '</span>'
				. '</span>';
		}

		if ( $hasText ) {
			/*
			 * Oba napisy WYCHODZĄ ZAWSZE i leżą na sobie (siatka w arkuszu).
			 * Renderowanie tylko bieżącego byłoby prostsze, ale wymagałoby skryptu
			 * i dawałoby przycisk zmieniający szerokość w trakcie animacji —
			 * a razem z nią przesuwałoby wszystko, co stoi obok.
			 */
			$rysunek .= '<span class="evk-burger__text">'
				. '<span class="evk-burger__label evk-burger__label--zamk">' . esc_html( $closed ) . '</span>'
				. '<span class="evk-burger__label evk-burger__label--otw">' . esc_html( $opened ) . '</span>'
				. '</span>';
		}

		echo "<button {$this->render_attributes( '_root' )}>" . $rysunek . '</button>';
	}

	/**
	 * Ikona przez natywną kontrolkę Bricksa.
	 *
	 * `render_icon()` jest jedynym miejscem w tym elemencie, które opiera się na
	 * KSZTAŁCIE cudzego API — stąd osłona. Bez niej podmiana tej metody w Bricksie
	 * wywalałaby całą stronę, a nie gasiła jedną ikonę.
	 */
	private static function burger_icon( $icon ): string {
		if ( empty( $icon ) ) return '';
		if ( ! method_exists( '\Bricks\Element', 'render_icon' ) ) return '';
		return (string) self::render_icon( $icon );
	}
}
