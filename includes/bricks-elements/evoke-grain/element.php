<?php
defined( 'ABSPATH' ) || exit;

/**
 * Evoke ONE — Ziarno
 *
 * Ziarno filmowe z shadera, na całe okno przeglądarki, przewijane z treścią.
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
		return 'Ziarno';
	}

	public function get_keywords() {
		return [ 'evoke', 'ziarno', 'grain', 'szum', 'noise', 'film', 'nakładka', 'overlay' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'evk-grain' );
		wp_enqueue_style( 'evk-grain' );
	}

	public function set_controls() {

		$this->controls['intensywnosc'] = [
			'tab'         => 'content',
			'label'       => 'Intensywność',
			'type'        => 'number',
			'min'         => 0, 'max' => 1, 'step' => 0.01,
			'default'     => 0.08,
			'description' => 'Ta sama skala co „Intensywność szumu" w elemencie Wave Background — '
				. 'przy tej samej wartości ziarno wygląda tak samo, bo liczy je ta sama formuła.',
		];

		$this->controls['przesiew'] = [
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
		$this->controls['przewijaj'] = [
			'tab'         => 'content',
			'label'       => 'Przewijanie z treścią',
			'type'        => 'checkbox',
			'default'     => true,
			'description' => 'Ziarno wędruje razem z treścią zamiast stać w miejscu na ekranie.',
		];

		$this->controls['mnoznik_scrolla'] = [
			'tab'         => 'content',
			'label'       => 'Siła przewijania',
			'type'        => 'number',
			'min'         => 0, 'max' => 4, 'step' => 0.1,
			'default'     => 1,
			'required'    => [ 'przewijaj', '=', true ],
			'description' => 'Ile ziarno przesuwa się na piksel przewinięcia; jeden to jeden do '
				. 'jednego, mniej i więcej daje efekt głębi. Widać to przy przesiewie '
				. 'NIERUCHOMYM — przy „co klatkę" wzór i tak powstaje od nowa co klatkę.',
		];

		$this->controls['warstwa'] = [
			'tab'         => 'content',
			'label'       => 'Warstwa (z-index)',
			'type'        => 'number',
			'default'     => 9990,
			'description' => 'Domyślnie wysoko, bo to nakładka filmowa i ma leżeć NAD treścią. '
				. 'Obniż, jeśli wolisz mieć ziarno pod tekstem. Kanwa nie łapie kliknięć '
				. 'niezależnie od warstwy.',
		];

		$this->controls['auto_jakosc'] = [
			'tab'         => 'content',
			'label'       => 'Automat jakości',
			'type'        => 'checkbox',
			'default'     => true,
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
		$mnoznik      = evk_flaga( $s, 'przewijaj', true )
			? max( 0, min( 4, (float) ( $s['mnoznik_scrolla'] ?? 1 ) ) )
			: 0.0;
		$warstwa      = (int) ( $s['warstwa'] ?? 9990 );
		$auto         = evk_flaga( $s, 'auto_jakosc', true );

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

		echo '<div ' . $this->render_attributes( '_root' ) . '></div>';
	}
}
