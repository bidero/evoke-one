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

/**
 * Pole, które MA BYĆ WŁĄCZONE domyślnie — czytane przez ODWRÓCONY przełącznik.
 *
 * DLACZEGO ODWRÓCONY, skoro `evk_flaga()` przyjmuje domyślną. ZGŁOSZONE
 * Z UŻYCIA, w kilku turach: „nie działa wyłączanie cienia kart", „szum
 * w WaveBG nie działa (zawsze widoczny)", „przyciągaj do paneli nie działa",
 * „dolna maska nie działa, górna tak".
 *
 * DOŚWIADCZENIE KONTROLNE SIEDZIAŁO W JEDNYM ELEMENCIE, w dwóch linijkach obok
 * siebie w evoke-wave-bg/element.php:
 *
 *     $mask_enabled     = evk_flaga( $s, 'mask_enabled', true );   // domyślna WŁĄCZONA
 *     $mask_top_enabled = ! empty( $s['mask_top_enabled'] );       // domyślna WYŁĄCZONA
 *
 * Ten sam render(), ten sam gradient, dwie identycznie wyglądające kontrolki
 * w panelu. Różniła je WYŁĄCZNIE domyślna — i tylko ta z domyślną wyłączoną
 * dawała się przełączać. Do tego dowód wprost ze strony zgłaszającego:
 * w źródle HTML `shadow` był `true` PO ODZNACZENIU.
 *
 * WNIOSEK: Bricks przy odznaczeniu nie zapisuje nic, co dałoby się odczytać
 * jako „wyłączone". Brak klucza jest nie do odróżnienia od elementu
 * nietkniętego, więc `'default' => true` na polu zaznaczenia jest w tej wtyczce
 * NIE DO UŻYCIA — żadna poprawka odczytu tego nie obejdzie. Pilnuje tego teraz
 * osobna reguła w tests/php/domyslne-wlaczone.php.
 *
 * Jedyne wyjście zgodne z „przełączniki mają zostać": domyślna WYŁĄCZONA
 * i odwrócony sens. Zaznaczenie zawsze coś zapisuje, więc jest jednoznaczne.
 *
 * STARY KLUCZ CZYTAMY DALEJ i to warunek, nie ozdoba. Na żywych stronach siedzą
 * zapisy z czasów list wyboru (`'nie'`) oraz jawne `false`. Gdyby funkcja ich
 * nie widziała, aktualizacja zapaliłaby komuś maskę albo szum, o które nie
 * prosił — a gałąź jedzie aktualizatorem.
 *
 * @param array<string,mixed> $ustawienia `$this->settings` elementu.
 * @param string              $stary      Klucz sprzed odwrócenia, tylko do odczytu.
 * @param string              $nowy       Klucz odwróconego przełącznika („…_off").
 * @return bool Czy funkcja ma być WŁĄCZONA.
 */
function evk_wlaczone( array $ustawienia, string $stary, string $nowy ): bool {

	/* Nowy przełącznik zaznaczony = użytkownik wyłączył. Ma pierwszeństwo przed
	   wszystkim, bo to jedyne, co na pewno zostało zapisane świadomie. */
	if ( evk_flaga( $ustawienia, $nowy, false ) ) {
		return false;
	}

	/* Stary klucz obecny: element zapisany przed odwróceniem. Czytamy go tą
	   samą drogą co dotąd, żeby `'nie'` i jawne `false` dalej znaczyły to samo. */
	if ( array_key_exists( $stary, $ustawienia ) ) {
		return evk_flaga( $ustawienia, $stary, true );
	}

	// Ani jednego klucza: domyślnie włączone, dokładnie jak dotąd.
	return true;
}
