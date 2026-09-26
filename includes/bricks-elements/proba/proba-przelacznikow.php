<?php
defined( 'ABSPATH' ) || exit;

/**
 * Evoke ONE — próba przełączników (1.243.0). TYLKO NA STRONIE TESTOWEJ.
 *
 * Rejestruje się wyłącznie po `define( 'EVK_BRICKS_PROBA', true );`
 * w wp-config.php (loader.php). Nie ma go w rejestrze elementów ani w panelu.
 * Zniknie, gdy próba odpowie na pytania niżej.
 *
 * PO CO. Przełączniki elementów Evoke są „na odwrót" („Bez cienia kart",
 * „Nie przyciągaj do paneli"), bo pole zaznaczenia z domyślnym WŁĄCZONYM
 * nie dawało się wyłączyć — powody w flaga.php. JSON świeżo wstawionego
 * Stacking Cards ze strony zgłaszającego (Bricks 2.4.1) pokazał, że Bricks
 * zapisuje w nowym elemencie niepuste wartości domyślne. Zwykłe „Włącz…"
 * zadziałałoby więc w NOWYCH elementach, gdyby dało się je odróżnić od
 * starych. Próba sprawdza na prawdziwym Bricksie to, czego tu nie widać:
 *
 *   1. czy ukryte pole z wartością domyślną (`proba_znacznik`) zapisuje się
 *      w nowym elemencie — jawne `proba_jawny` jest kontrolą;
 *   2. co zostaje po odznaczeniu pola domyślnie zaznaczonego (`proba_wlacz`):
 *      brak klucza, `false` czy `null`;
 *   3. czy warunek `required` pokazuje pole zależnie od znacznika, także gdy
 *      klucza nie ma wcale (wklejony „stary" element bez znacznika);
 *   4. co zostaje po wyczyszczeniu pola tekstowego: brak klucza czy pusty
 *      napis (etap przeniesienia tłumaczeń do pól).
 *
 * ŚWIADOMIE ŁAMIE regułę 0 z tests/php/domyslne-wlaczone.php (żadne pole
 * zaznaczenia z domyślnym włączonym) — to ją właśnie sprawdza. Plik nie
 * nazywa się „element.php", więc strażnicy wszystkich elementów go pomijają;
 * pilnuje go osobny test (bricks-proba).
 */
class Evk_Proba_Przelacznikow_Element extends \Bricks\Element {

	public $category = EVK_BRICKS_CATEGORY;
	public $name     = 'evk-proba-przelacznikow';
	public $icon     = 'ti-check-box';
	public $tag      = 'div';

	public function get_label() {
		return 'Evoke — próba przełączników';
	}

	public function set_controls() {

		$this->controls['proba_znacznik'] = [
			'tab'      => 'content',
			'label'    => 'Znacznik (ukryty)',
			'type'     => 'number',
			'default'  => 2,
			// Klucza `proba_nigdy` nie ma żadna kontrolka — pole nie pokaże się nigdy.
			'required' => [ 'proba_nigdy', '=', 'tak' ],
		];

		$this->controls['proba_jawny'] = [
			'tab'     => 'content',
			'label'   => 'Znacznik jawny (kontrola)',
			'type'    => 'number',
			'default' => 2,
		];

		$this->controls['proba_wlacz'] = [
			'tab'     => 'content',
			'label'   => 'Włącz A (domyślnie włączone)',
			'type'    => 'checkbox',
			'default' => true,
		];

		$this->controls['proba_nowy'] = [
			'tab'      => 'content',
			'label'    => 'Włącz B (tylko w nowym elemencie)',
			'type'     => 'checkbox',
			'default'  => true,
			'required' => [ 'proba_znacznik', '=', 2 ],
		];

		$this->controls['proba_stary_off'] = [
			'tab'      => 'content',
			'label'    => 'Wyłącz B (tylko w starym elemencie)',
			'type'     => 'checkbox',
			'required' => [ 'proba_znacznik', '!=', 2 ],
		];

		$this->controls['proba_tekst'] = [
			'tab'   => 'content',
			'label' => 'Tekst (wpisz, zapisz, wyczyść)',
			'type'  => 'text',
		];
	}

	/** Stan jednego klucza tak, jak leży w ustawieniach — bez interpretacji. */
	private function stan( string $klucz ): string {
		if ( ! array_key_exists( $klucz, $this->settings ) ) {
			return 'brak klucza';
		}
		return (string) wp_json_encode( $this->settings[ $klucz ], JSON_UNESCAPED_UNICODE );
	}

	/** Klucze wypisane wprost: na froncie Bricks nie musi ładować kontrolek. */
	const KLUCZE = [ 'proba_znacznik', 'proba_jawny', 'proba_wlacz', 'proba_nowy', 'proba_stary_off', 'proba_tekst' ];

	public function render() {
		$linie = [];
		foreach ( self::KLUCZE as $klucz ) {
			$linie[] = $klucz . ': ' . $this->stan( $klucz );
		}
		$linie[] = '';
		$linie[] = 'Wszystkie ustawienia:';
		$linie[] = (string) wp_json_encode( $this->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		echo '<div ' . $this->render_attributes( '_root' ) . '><pre class="evk-proba">'
			. esc_html( implode( "\n", $linie ) ) . '</pre></div>';
	}
}
