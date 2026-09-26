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
 * nietkniętego, więc `'default' => true` na SAMYM polu zaznaczenia jest w tej
 * wtyczce NIE DO UŻYCIA — żadna poprawka odczytu tego nie obejdzie. Rozróżnia
 * to dopiero ukryty znacznik obok pola (1.247.0, evk_przelacznik_nowy() niżej).
 * Pilnuje tego osobna reguła w tests/php/domyslne-wlaczone.php.
 *
 * Jedyne wyjście zgodne z „przełączniki mają zostać": domyślna WYŁĄCZONA
 * i odwrócony sens. Zaznaczenie zawsze coś zapisuje, więc jest jednoznaczne.
 *
 * STARY KLUCZ CZYTAMY DALEJ i to warunek, nie ozdoba. Na żywych stronach siedzą
 * zapisy z czasów list wyboru (`'nie'`) oraz jawne `false`. Gdyby funkcja ich
 * nie widziała, aktualizacja zapaliłaby komuś maskę albo szum, o które nie
 * prosił — a gałąź jedzie aktualizatorem.
 *
 * NOWE ELEMENTY (1.247.0) mają z powrotem zwykłe „Włącz…" pod STARYM kluczem,
 * obok ukrytego znacznika — patrz evk_przelacznik_nowy() niżej.
 *
 * @param array<string,mixed> $ustawienia `$this->settings` elementu.
 * @param string              $stary      Klucz sprzed odwrócenia; w nowych elementach klucz „Włącz…".
 * @param string              $nowy       Klucz odwróconego przełącznika („…_off").
 * @return bool Czy funkcja ma być WŁĄCZONA.
 */
function evk_wlaczone( array $ustawienia, string $stary, string $nowy ): bool {

	/* Element wstawiony od 1.247.0: zwykłe pole zaznaczenia, domyślnie
	   zaznaczone. Bricks zapisał przy wstawieniu `true`, a odznaczenie usuwa
	   klucz — tu brak klucza znaczy więc „wyłączone". */
	if ( evk_nowy_przelacznik( $ustawienia, $stary ) ) {
		return evk_flaga( $ustawienia, $stary, false );
	}

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

/**
 * Przyrostek ukrytego znacznika przy przełączniku: `shadow` → `shadow_nowy`.
 * Wartość 2 = element wstawiony z przełącznikiem „Włącz…" (1.247.0).
 */
const EVK_ZNACZNIK_NOWEGO = '_nowy';

/**
 * Czy element ma przełącznik `$klucz` w nowej postaci („Włącz…", domyślnie
 * zaznaczony). Rozstrzyga ukryty znacznik, nie sam przełącznik: odznaczone
 * „Włącz…" usuwa klucz i wygląda jak element sprzed zmiany.
 *
 * @param array<string,mixed> $ustawienia `$this->settings` elementu.
 */
function evk_nowy_przelacznik( array $ustawienia, string $klucz ): bool {
	$z = $ustawienia[ $klucz . EVK_ZNACZNIK_NOWEGO ] ?? null;
	return is_numeric( $z ) && (int) $z >= 2;
}

/**
 * Kontrolki przełącznika „Włącz…" dla NOWYCH elementów (1.247.0): ukryty
 * znacznik i zwykłe pole zaznaczenia, domyślnie zaznaczone.
 *
 * DOWÓD ZE STRONY (próba z 1.243.0, Bricks 2.4.1, strona testowa):
 *   · ukryte pole z niepustą wartością domyślną ZAPISUJE SIĘ przy wstawieniu
 *     elementu (`proba_znacznik: 2`);
 *   · odznaczenie pola domyślnie zaznaczonego usuwa klucz;
 *   · element „stary", wklejony bez znacznika, zostaje bez znacznika i bez
 *     wartości domyślnych — po wklejeniu, po zapisie i po odświeżeniu
 *     buildera — a warunek `required` przy braku klucza pokazuje przełącznik
 *     starego elementu.
 * Dlatego nowy element dostaje znacznik 2 i „Włącz…", a stary dalej widzi
 * odwrócone „…_off" (warunek `[ '{klucz}_nowy', '!=', 2 ]` w elemencie).
 *
 * ZNACZNIK PRZY KAŻDYM PRZEŁĄCZNIKU, W JEGO GRUPIE. Warunek wskazujący pole
 * z innej grupy nie jest na Bricksie sprawdzony (controls: „żadna bramka nie
 * przechodzi przez granicę grupy"), a jeden znacznik na element musiałby
 * sięgać do kilku grup. Znacznik chowa się warunkiem na pole, którego nie ma
 * (`evk_nigdy`) — dokładnie tak jak w próbie; bricks-required dopuszcza to
 * wiszące odwołanie tylko przy znaczniku.
 *
 * Wstawiać ZARAZ PO „…_off": z niego bierze grupę, zakładkę, wygląd i opis
 * (opisy „…_off" mówią, co funkcja robi, więc pasują i tu). Widać zawsze
 * jedno z dwóch pól, więc kolejność między nimi nie gra roli.
 *
 * @param string              $klucz    Klucz sprzed odwrócenia (`shadow`), czytany przez evk_wlaczone().
 * @param string              $etykieta Etykieta „Włącz…" (krótko, sama nazwa funkcji).
 * @param array<string,mixed> $off      Definicja odwróconego przełącznika „…_off".
 * @return array<string,array<string,mixed>>
 */
function evk_przelacznik_nowy( string $klucz, string $etykieta, array $off ): array {
	$gdzie = array_intersect_key( $off, [ 'tab' => true, 'group' => true ] );
	$pole  = array_intersect_key( $off, [ 'tab' => true, 'group' => true, 'inline' => true, 'small' => true, 'description' => true ] ) + [
		'label'    => $etykieta,
		'type'     => 'checkbox',
		'default'  => true,
		'required' => [ $klucz . EVK_ZNACZNIK_NOWEGO, '=', 2 ],
	];
	return [
		$klucz . EVK_ZNACZNIK_NOWEGO => $gdzie + [
			'label'    => 'Znacznik nowego elementu',
			'type'     => 'number',
			'default'  => 2,
			'required' => [ 'evk_nigdy', '=', 'tak' ],
		],
		$klucz => $pole,
	];
}
