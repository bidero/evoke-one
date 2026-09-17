<?php
defined( 'ABSPATH' ) || exit;

/**
 * Evoke ONE — Grain
 *
 * Ziarno filmowe z shadera — na całe okno albo w jednej sekcji, przewijane
 * z treścią.
 *
 * SKĄD SIĘ WZIĄŁ. Zgłoszone z użycia przy elemencie fali: „dodatkowy element
 * Bricks tylko z ziarnem. Dodany na stronę wyświetla ziarno z shaderem na całym
 * oknie przeglądarki. Dobrze by było, żeby był przewijany z treścią dla lepszego
 * efektu".
 *
 * DLACZEGO BEZ THREE.JS. Ziarno to jeden trójkąt na pełny ekran i jedna linijka
 * arytmetyki. Pobranie 287 KB biblioteki po to, żeby narysować szum, byłoby
 * dokładnie tym, czego element fali unika dynamicznym importem. Własny moduł
 * WebGL mieści się w kilkudziesięciu linijkach — patrz `assets/grain.js`.
 *
 * DLACZEGO SHADER SIEDZI W PLIKU .js, A NIE W MODULE DRUKOWANYM Z PHP-A.
 * Fala drukuje swój moduł z PHP-a, więc jej shadery żyją w literałach
 * szablonowych wewnątrz łańcucha PHP-a — i odwrotny apostrof w komentarzu
 * potrafi tam wywrócić całość (dwa razy tak było, patrz CLAUDE.md). Ziarno nie
 * ma po co iść tą drogą: nie potrzebuje konfiguracji wplecionej w kod, bo
 * ustawienia jadą atrybutami danych na korzeniu. Pułapka znika z definicji.
 *
 * CZEGO TU NIE MA I DLACZEGO. Żadnego `mix-blend-mode`. Zmierzone na maszynie
 * zgłaszającego (Intel Iris, DPR 1,8): pełnoekranowa warstwa z mieszaniem łamie
 * budżet klatki — 33,2 ms wobec 17,1 ms dla tej samej warstwy bez mieszania.
 * Ziarno niesie więc WŁASNĄ przezroczystość, tak jak shader fali od 1.193.0.
 */
class Evk_Grain_Element extends \Bricks\Element {

	public $category = EVK_BRICKS_CATEGORY;
	public $name     = 'evk-grain';
	public $icon     = 'ti-layout-media-overlay';
	public $tag      = 'div';
	public $scripts  = [ 'evk_grain_init' ];

	// Etykieta MUSI się zgadzać z evk_elements_registry()['grain']['label'].
	public function get_label() {
		return 'Grain';
	}

	public function get_keywords() {
		return [ 'evoke', 'ziarno', 'grain', 'szum', 'noise', 'film', 'nakładka', 'overlay' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'evk-grain' );
		wp_enqueue_style( 'evk-grain' );
	}

	/* GRUPY DOPIERO TERAZ, przy dziewięciu kontrolkach. Przy sześciu zwijany
	   nagłówek był tylko dodatkowym kliknięciem — kontrolka „Zasięg" dokłada
	   trzy pola i trzy decyzje, więc panel zaczyna się rozjeżdżać bez podziału.
	   Reszta wtyczki jest na grupach od 1.209.0.

	   WARUNKI ZOSTAJĄ W SWOICH GRUPACH: „Siła przewijania" pyta o „Przewijanie
	   z treścią" (obie w „Ruch"), a „Selektor sekcji" i „Wtopienie" pytają
	   o „Zasięg ziarna" (wszystkie trzy w „Zasięg"). W całej wtyczce nie ma ani
	   jednej bramki przez granicę grupy i nie wiadomo, czy Bricks ją obsłuży —
	   patrz tests/php/opisy-kontrolek.php. */
	public function set_control_groups() {
		$this->control_groups['evk_wyglad'] = [ 'title' => esc_html__( 'Wygląd', 'evk-grain' ), 'tab' => 'content' ];
		$this->control_groups['evk_zakres'] = [ 'title' => esc_html__( 'Zasięg', 'evk-grain' ), 'tab' => 'content' ];
		$this->control_groups['evk_ruch']   = [ 'title' => esc_html__( 'Ruch', 'evk-grain' ),   'tab' => 'content' ];
	}

	public function set_controls() {

		$this->controls['intensywnosc'] = [
			'group'       => 'evk_wyglad',
			'tab'         => 'content',
			'label'       => 'Intensywność',
			'type'        => 'number',
			'min'         => 0, 'max' => 1, 'step' => 0.01,
			'default'     => 0.08,
			'description' => 'Ta sama skala co „Intensywność szumu" w elemencie Wave Background — '
				. 'przy tej samej wartości ziarno wygląda tak samo, bo liczy je ta sama formuła.',
		];

		$this->controls['przesiew'] = [
			'group'   => 'evk_wyglad',
			'tab'     => 'content',
			'label'   => 'Przesiew',
			'type'    => 'select',
			'options' => [
				'klatka' => 'Co klatkę (jak w fali)',
				'stop'   => 'Nieruchome',
			],
			'default'     => 'klatka',
			'description' => 'Co klatkę daje migotanie taśmy filmowej. NIERUCHOME rysuje jeden '
				. 'kadr i zatrzymuje pętlę — kosztuje wtedy dokładnie tyle, co obrazek, '
				. 'i jest wyjściem dla słabszych maszyn.',
		];

		/*
		 * ZASIĘG — ziarno na całym oknie albo w jednej sekcji.
		 *
		 * ZGŁOSZONE Z UŻYCIA: „dodaj do samej kontrolki grain możliwość
		 * wyświetlania tylko w jednej sekcji z łagodnym przejściem na
		 * górze/dole, a nie na całej stronie".
		 *
		 * KANWA I TAK ZOSTAJE WIELKOŚCI OKNA — sekcja jest maską w shaderze,
		 * nie rozmiarem kanwy. Powody w assets/grain.js przy `this.ogranicz`:
		 * kanwa wielkości sekcji musiałaby jechać razem z nią przy każdym
		 * przewinięciu, a przy sekcji wysokiej na kilka ekranów wracałby
		 * problem, dla którego kanwa nie ma wysokości dokumentu.
		 */
		$this->controls['zakres'] = [
			'group'   => 'evk_zakres',
			'tab'     => 'content',
			'label'   => 'Zasięg ziarna',
			'type'    => 'select',
			'options' => [
				'strona' => 'Całe okno',
				'sekcja' => 'Tylko jedna sekcja',
			],
			'default'     => 'strona',
			'inline'      => true,
			'description' => 'W trybie sekcji ziarno narasta i gaśnie przy jej górnej '
				. 'i dolnej krawędzi.',
		];

		$this->controls['sekcja_selektor'] = [
			'group'       => 'evk_zakres',
			'tab'         => 'content',
			'label'       => 'Selektor sekcji',
			'type'        => 'text',
			'inline'      => true,
			'placeholder' => '.moja-sekcja',
			'required'    => [ 'zakres', '=', 'sekcja' ],
			'description' => 'Pusty = rodzic tego elementu, czyli sekcja lub kontener, '
				. 'w którym go postawiłeś. Podany szuka najpierw PRZODKA. '
				. 'Gdy nic nie pasuje, ziarno zostaje na całym oknie i mówi o tym w konsoli.',
		];

		/* WTOPIENIE W PIKSELACH EKRANU, nie w procentach sekcji — bo ma być tą
		   samą miękkością niezależnie od tego, czy sekcja ma 400 px, czy pięć
		   ekranów. Procent dawałby przy wysokiej sekcji przejście na pół
		   ekranu, a przy niskiej twardą krawędź. */
		$this->controls['wtopienie'] = [
			'group'       => 'evk_zakres',
			'tab'         => 'content',
			'label'       => 'Wtopienie (px)',
			'type'        => 'number',
			'min'         => 0, 'max' => 600, 'step' => 10,
			'default'     => 120,
			'required'    => [ 'zakres', '=', 'sekcja' ],
			'description' => 'Na ilu pikselach ziarno narasta przy krawędzi sekcji. '
				. 'Zero daje twardą krawędź.',
		];

		/* PRZEŁĄCZNIK, A POD NIM SIŁA — bo pierwszą decyzją jest „czy w ogóle",
		 * a dopiero drugą „jak mocno". Wcześniej było tu jedno pole liczbowe
		 * i zero znaczyło „wyłączone", co trzeba było wyczytać z opisu.
		 *
		 * WAŻNE, CZEGO Z PANELU NIE WIDAĆ: przesunięcie działa na oko wyłącznie
		 * przy przesiewie „Nieruchome". Przy „co klatkę" `rysujRaz()` losuje
		 * `uSeed` za każdym razem (assets/grain.js:217), więc cały wzór powstaje
		 * od nowa — zmierzone: dwie kolejne klatki różnią się na 55 082 z 88 000
		 * pikseli. Przesunięcie jak najbardziej trafia do shadera, tylko nie ma
		 * czego przesuwać. Mówi o tym opis kontrolki, bo ukrycie jej pod
		 * przesiewem odbierałoby możliwość ustawienia siły z wyprzedzeniem. */
		$this->controls['przewijaj_off'] = [
			'group'       => 'evk_ruch',
			'tab'         => 'content',
			'label'       => 'Bez przewijania z treścią',
			'type'        => 'checkbox',
			'default'     => false,
			/* ODWRÓCONY PRZEŁĄCZNIK — domyślna MUSI być wyłączona.
			   Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać
			   jako „wyłączone", więc pole z `'default' => true` jest nie do
			   wyłączenia. Powody i dowód: evk_wlaczone() w flaga.php. */
			'description' => 'Ziarno wędruje razem z treścią zamiast stać w miejscu na ekranie.',
		];

		$this->controls['mnoznik_scrolla'] = [
			'group'       => 'evk_ruch',
			'tab'         => 'content',
			'label'       => 'Siła przewijania',
			'type'        => 'number',
			'min'         => 0, 'max' => 4, 'step' => 0.1,
			'default'     => 1,
			'required'    => [ 'przewijaj_off', '=', false ],
			'description' => 'Ile ziarno przesuwa się na piksel przewinięcia; jeden to jeden do '
				. 'jednego, mniej i więcej daje efekt głębi. Widać to przy przesiewie '
				. 'NIERUCHOMYM — przy „co klatkę" wzór i tak powstaje od nowa co klatkę.',
		];

		$this->controls['warstwa'] = [
			'group'       => 'evk_wyglad',
			'tab'         => 'content',
			'label'       => 'Warstwa (z-index)',
			'type'        => 'number',
			'default'     => 9990,
			'description' => 'Domyślnie wysoko, bo to nakładka filmowa i ma leżeć NAD treścią. '
				. 'Obniż, jeśli wolisz mieć ziarno pod tekstem. Kanwa nie łapie kliknięć '
				. 'niezależnie od warstwy.',
		];

		$this->controls['auto_jakosc_off'] = [
			'group'       => 'evk_ruch',
			'tab'         => 'content',
			'label'       => 'Wyłącz automat jakości',
			'type'        => 'checkbox',
			'default'     => false,
			/* ODWRÓCONY PRZEŁĄCZNIK — domyślna MUSI być wyłączona.
			   Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać
			   jako „wyłączone", więc pole z `'default' => true` jest nie do
			   wyłączenia. Powody i dowód: evk_wlaczone() w flaga.php. */
			'description' => 'Gdy klatki zaczynają wypadać z budżetu, ziarno samo przechodzi '
				. 'na nieruchome zamiast dokładać się do zacinania. Wyłącz tylko wtedy, '
				. 'gdy mierzysz.',
		];
	}

	public function render() {
		$s = $this->settings;

		$intensywnosc = max( 0, min( 1, (float) ( $s['intensywnosc'] ?? 0.08 ) ) );
		$przesiew     = ( $s['przesiew'] ?? 'klatka' ) === 'stop' ? 'stop' : 'klatka';
		/* WYŁĄCZONY PRZEŁĄCZNIK ODDAJE ZERO, a nie osobny atrybut — zero już
		   dziś znaczy w skrypcie „przyklejone do ekranu", więc grain.js nie
		   wymaga żadnej zmiany. Mniej kodu po obu stronach. */
		$mnoznik      = evk_wlaczone( $s, 'przewijaj', 'przewijaj_off' )
			? max( 0, min( 4, (float) ( $s['mnoznik_scrolla'] ?? 1 ) ) )
			: 0.0;
		$warstwa      = (int) ( $s['warstwa'] ?? 9990 );
		$auto         = evk_wlaczone( $s, 'auto_jakosc', 'auto_jakosc_off' );
		$zakres       = ( $s['zakres'] ?? 'strona' ) === 'sekcja' ? 'sekcja' : 'strona';
		/* CUDZYSŁÓW NA APOSTROF — jedyny znak, który może wyjść z atrybutu.
		   Selektor jest pierwszym polem TEKSTOWYM tego elementu, które jedzie
		   do atrybutu HTML, a `[data-rola="hero"]` i `[data-rola='hero']` znaczą
		   w CSS-ie dokładnie to samo. Podwójnego ucieczkowania tu nie ma
		   i BYĆ NIE MOŻE: `esc_attr()` puszczone na to, co Bricks i tak
		   ucieknie, zamieniłoby taki selektor w `&amp;quot;` i przestałby
		   działać. Zamiana znaku rozwiązuje to bez zgadywania, co robi builder
		   — a tego na tej maszynie sprawdzić się nie da.
		   `>` i `<` zostają: `>` jest w CSS-ie kombinatorem dziecka, a wewnątrz
		   wartości atrybutu żaden z nich niczego nie zamyka. */
		$sekcja       = str_replace( '"', "'", trim( (string) ( $s['sekcja_selektor'] ?? '' ) ) );
		$wtopienie    = max( 0, min( 600, (int) ( $s['wtopienie'] ?? 120 ) ) );

		/* USTAWIENIA JADĄ ATRYBUTAMI, nie wplecione w kod modułu. Dzięki temu
		   skrypt jest jednym plikiem dla całej strony — przeglądarka pobiera go
		   raz i trzyma w pamięci podręcznej — zamiast osobnym blokiem na każdy
		   wstawiony element. */
		$this->set_attribute( '_root', 'class', 'evk-grain' );
		$this->set_attribute( '_root', 'data-evk-grain', '1' );
		$this->set_attribute( '_root', 'data-intensywnosc', (string) $intensywnosc );
		$this->set_attribute( '_root', 'data-przesiew', $przesiew );
		$this->set_attribute( '_root', 'data-mnoznik', (string) $mnoznik );
		$this->set_attribute( '_root', 'data-warstwa', (string) $warstwa );
		$this->set_attribute( '_root', 'data-auto-jakosc', $auto ? '1' : '0' );
		$this->set_attribute( '_root', 'data-zakres', $zakres );
		$this->set_attribute( '_root', 'data-wtopienie', (string) $wtopienie );
		/* ATRYBUT SELEKTORA TYLKO WTEDY, GDY JEST CO WSTAWIĆ. Pusty
		   `data-sekcja` i jego brak znaczą w skrypcie to samo („weź rodzica"),
		   więc pusty byłby wyłącznie szumem w kodzie źródłowym strony. */
		if ( 'sekcja' === $zakres && '' !== $sekcja ) {
			$this->set_attribute( '_root', 'data-sekcja', $sekcja );
		}

		echo '<div ' . $this->render_attributes( '_root' ) . '></div>';
	}
}
