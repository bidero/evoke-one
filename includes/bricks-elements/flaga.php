<?php
defined( 'ABSPATH' ) || exit;

/**
 * Evoke ONE — jeden odczyt pól włącz/wyłącz dla wszystkich elementów Bricks.
 *
 * PO CO TO ISTNIEJE. W kodzie elementów były TRZY konwencje na to samo pytanie
 * „czy użytkownik to włączył":
 *
 *   1. `checkbox` + `array_key_exists()`        — marquee, jedyna poprawna
 *   2. `checkbox` z `'default' => true` + `! empty()` — offcanvas, circular, hscroll
 *   3. `select` z „tak"/„nie"                    — wave-bg, ziarno
 *
 * Druga jest cicho zepsuta. Bricks przy NIETKNIĘTYM elemencie nie ma klucza
 * w ustawieniach, `! empty()` daje wtedy `false`, więc zadeklarowane
 * `'default' => true` po prostu NIE OBOWIĄZUJE. Nie widać tego ani w panelu
 * buildera (pole jest zaznaczone), ani w kodzie kontrolki — widać dopiero
 * w atrybucie na stronie.
 *
 * CZY BRICKS DOKŁADA DOMYŚLNE DO ZAPISANYCH USTAWIEŃ — nie wiemy i nie da się
 * tego tu sprawdzić (patrz CLAUDE.md: wnioski o builderze wymagają dowodu ze
 * strony). Ta funkcja jest napisana tak, żeby nie trzeba było wiedzieć:
 * poprawnie odpowiada przy obu zachowaniach.
 *
 * DLACZEGO ROZUMIE „tak" I „nie". To NIE jest bałagan, tylko warunek zmiany
 * typu kontrolki bez utraty ustawień. Gałąź jedzie aktualizatorem na żywe
 * strony: zamiana listy wyboru na pole zaznaczenia zostawia w bazie zapisane
 * wcześniej `'nie'`, a `! empty('nie')` to PRAWDA — czyjś wyłączony automat
 * jakości wróciłby włączony. Dopóki ta funkcja czyta oba kształty, stary zapis
 * dalej znaczy to samo co przed zmianą.
 *
 * @param array<string,mixed> $ustawienia `$this->settings` elementu.
 * @param string              $klucz      Nazwa kontrolki.
 * @param bool                $domyslnie  Wartość przy braku klucza.
 */
function evk_flaga( array $ustawienia, string $klucz, bool $domyslnie ): bool {

	/* Klucza nie ma: element nietknięty ALBO zapisany, zanim kontrolka
	   powstała. W obu wypadkach obowiązuje domyślna — i to jest jedyna
	   sytuacja, w której `$domyslnie` w ogóle się liczy. */
	if ( ! array_key_exists( $klucz, $ustawienia ) ) {
		return $domyslnie;
	}

	$w = $ustawienia[ $klucz ];

	// Zapis po starej liście wyboru. Porównanie jest ścisłe, bo `'0' == 'nie'`
	// bywało prawdą w starszych PHP-ach i nie chcemy na tym polegać.
	if ( is_string( $w ) ) {
		if ( 'nie' === $w ) return false;
		if ( 'tak' === $w ) return true;
	}

	return ! empty( $w );
}
