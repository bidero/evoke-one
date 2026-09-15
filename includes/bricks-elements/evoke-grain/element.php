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

		$this->controls['mnoznik_scrolla'] = [
			'tab'         => 'content',
			'label'       => 'Przewijanie z treścią',
			'type'        => 'number',
			'min'         => 0, 'max' => 4, 'step' => 0.1,
			'default'     => 1,
			'description' => 'Ile ziarno przesuwa się na każdy piksel przewinięcia. '
				. 'Jeden znaczy „przyklejone do treści", zero — „przyklejone do ekranu". '
				. 'Wartości pomiędzy dają efekt głębi.',
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
			'type'        => 'select',
			'options'     => [ 'tak' => 'Tak', 'nie' => 'Nie' ],
			'default'     => 'tak',
			'description' => 'Gdy klatki zaczynają wypadać z budżetu, ziarno samo przechodzi '
				. 'na nieruchome zamiast dokładać się do zacinania. Wyłącz tylko wtedy, '
				. 'gdy mierzysz.',
		];
	}

	public function render() {
		$s = $this->settings;

		$intensywnosc = max( 0, min( 1, (float) ( $s['intensywnosc'] ?? 0.08 ) ) );
		$przesiew     = ( $s['przesiew'] ?? 'klatka' ) === 'stop' ? 'stop' : 'klatka';
		$mnoznik      = max( 0, min( 4, (float) ( $s['mnoznik_scrolla'] ?? 1 ) ) );
		$warstwa      = (int) ( $s['warstwa'] ?? 9990 );
		$auto         = ( $s['auto_jakosc'] ?? 'tak' ) !== 'nie';

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
