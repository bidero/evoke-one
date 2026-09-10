<?php
if (!defined('ABSPATH')) exit;
/**
 * EVOKE One — Moduł Schema.org (JSON-LD @graph)
 */
class EVK_Schema {
    private static $instance = null;

    /** Klucz meta wpisu, z którego biorą się nadpisania per podstrona. */
    public const META_KEY = '_evk_schema';

    // ================================================================
    // REJESTR PÓL
    // ================================================================
    /**
     * Jedno miejsce, z którego biorą się TRZY rzeczy: wartości domyślne,
     * sanityzacja przy zapisie i informacja, do którego węzła grafu pole trafia.
     *
     * PO CO REJESTR, SKORO BYŁA PŁASKA TABLICA `$defaults`: bo prawda o polu
     * była rozsypana po trzech miejscach. Domyślna wartość stała w `$defaults`,
     * sposób sanityzacji w ręcznie wypisanej liście w `sanitize_settings()`,
     * a przynależność do węzła nigdzie — trzeba było czytać `build_*`.
     * Przy czternastu polach dawało się to unieść. Rozpiska
     * (`docs/schema-rozpiska.md`) przewiduje około osiemdziesięciu sześciu
     * i wtedy pole dopisane do formularza, a zapomniane w liście `$texts`,
     * przestaje się zapisywać BEZ ŻADNEGO OBJAWU: formularz przyjmuje wartość,
     * `sanitize_settings()` jej nie przepisuje, po przeładowaniu pole jest puste.
     *
     * DLACZEGO NAZWY KLUCZY ZOSTAJĄ BEZ PREFIKSU. Rozpiska proponowała
     * przemianowanie na `org_*` / `place_*`. Odrzucone przy pisaniu kodu:
     * te klucze siedzą w bazach żywych stron, a przemianowanie ich to
     * migracja cudzych danych — klasa zmian, która psuje się po cichu.
     * Kolumna `wezel` daje dokładnie to, co miał dawać prefiks, i nie dotyka
     * niczego, co już zapisane. Pola dokładane od wydania 2 dostają prefiksy
     * od razu, bo tam nie ma czego migrować.
     *
     * Kolumna `typ` steruje sanityzacją:
     *   tekst          — sanitize_text_field
     *   wieloliniowe   — sanitize_textarea_field
     *   url            — esc_url_raw
     *   wspolrzedna    — przecinek → kropka, niebędące liczbą → puste
     *   data           — YYYY, YYYY-MM albo YYYY-MM-DD; cokolwiek innego → puste
     *   liczba         — nieujemna liczba całkowita; cokolwiek innego → puste
     *   czas           — HH:MM (doba 24-godzinna); cokolwiek innego → puste
     *   trojstan       — '' (nie podano), '1' (tak) albo '0' (nie)
     *   checkbox       — 0 albo 1
     *   json           — przepuszczane, gdy się parsuje; inaczej wartość domyślna
     *   wlasne         — obsługiwane osobno w sanitize_settings()
     */
    public static function pola(): array {
        return [
            // ── Sterowanie modułem ──────────────────────────────────────
            'enabled'          => ['wezel' => 'modul',        'typ' => 'wlasne',       'domyslnie' => 0],

            // ── Organizacja (#organization) ─────────────────────────────
            'org_type'         => ['wezel' => 'organization', 'typ' => 'wlasne',       'domyslnie' => 'Organization'],
            'operator_name'    => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'site_name'        => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'telephone'        => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'email'            => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'street_address'   => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'locality'         => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'postal_code'      => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'country'          => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => 'PL'],
            'favicon_url'      => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'contact_type'     => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => 'customer service'],
            'social_links'     => ['wezel' => 'organization', 'typ' => 'json',         'domyslnie' => ''],
            'descriptions'     => ['wezel' => 'organization', 'typ' => 'json',         'domyslnie' => '{}'],

            /* Pola dołożone w 1.172.0. Prefiks `org_`, bo od tego wydania nowe
               klucze go dostają — nie ma czego migrować, a przy ~86 polach
               `area_served` (miejsce) i `org_area_served` (organizacja) muszą
               dać się odróżnić na pierwszy rzut oka. */
            'org_knows_about'  => ['wezel' => 'organization', 'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'org_legal_name'   => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'org_alternate'    => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'org_slogan'       => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'org_founding'     => ['wezel' => 'organization', 'typ' => 'data',         'domyslnie' => ''],
            'org_founder'      => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'org_employees'    => ['wezel' => 'organization', 'typ' => 'liczba',       'domyslnie' => ''],
            'org_vat_id'       => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'org_tax_id'       => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'org_brand'        => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'org_fax'          => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => ''],
            'org_award'        => ['wezel' => 'organization', 'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'org_member_of'    => ['wezel' => 'organization', 'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'org_area_served'  => ['wezel' => 'organization', 'typ' => 'wieloliniowe', 'domyslnie' => ''],

            /* Pola organizacji BRAMKOWANE PRESETEM (1.173.0). Klucz `preset`
               mówi, przy których typach działalności pole jest widoczne
               w panelu I emitowane do grafu. */
            'org_nonprofit'    => ['wezel' => 'organization', 'typ' => 'tekst',        'domyslnie' => '',
                                   'preset' => ['organizacja']],
            'org_offer_catalog'=> ['wezel' => 'organization', 'typ' => 'wieloliniowe', 'domyslnie' => '',
                                   'preset' => ['uslugi', 'zdrowie', 'uroda']],

            // ── Miejsce / firma lokalna (#place) ────────────────────────
            'geo_lat'          => ['wezel' => 'place',        'typ' => 'wspolrzedna',  'domyslnie' => ''],
            'geo_lng'          => ['wezel' => 'place',        'typ' => 'wspolrzedna',  'domyslnie' => ''],
            'price_range'      => ['wezel' => 'place',        'typ' => 'tekst',        'domyslnie' => ''],
            'amenities'        => ['wezel' => 'place',        'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'has_map'          => ['wezel' => 'place',        'typ' => 'url',          'domyslnie' => ''],
            'opening_hours'    => ['wezel' => 'place',        'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'area_served'      => ['wezel' => 'place',        'typ' => 'wieloliniowe', 'domyslnie' => ''],

            /* Pola miejsca BRAMKOWANE PRESETEM (1.173.0).
               Każde istnieje WYŁĄCZNIE na typach swojego presetu — to nie jest
               kwestia przydatności, tylko poprawności grafu. */
            'place_checkin'    => ['wezel' => 'place',        'typ' => 'czas',         'domyslnie' => '',
                                   'preset' => ['noclegi']],
            'place_checkout'   => ['wezel' => 'place',        'typ' => 'czas',         'domyslnie' => '',
                                   'preset' => ['noclegi']],
            'place_rooms'      => ['wezel' => 'place',        'typ' => 'liczba',       'domyslnie' => '',
                                   'preset' => ['noclegi']],
            'place_pets'       => ['wezel' => 'place',        'typ' => 'trojstan',     'domyslnie' => '',
                                   'preset' => ['noclegi']],
            'place_languages'  => ['wezel' => 'place',        'typ' => 'wieloliniowe', 'domyslnie' => '',
                                   'preset' => ['noclegi']],
            // starRating żyje i na LodgingBusiness, i na FoodEstablishment —
            // jedyne pole branżowe wspólne dla dwóch presetów.
            'place_stars'      => ['wezel' => 'place',        'typ' => 'liczba',       'domyslnie' => '',
                                   'preset' => ['noclegi', 'gastronomia']],
            'place_cuisine'    => ['wezel' => 'place',        'typ' => 'wieloliniowe', 'domyslnie' => '',
                                   'preset' => ['gastronomia']],
            'place_menu'       => ['wezel' => 'place',        'typ' => 'url',          'domyslnie' => '',
                                   'preset' => ['gastronomia']],
            'place_reservations'=> ['wezel' => 'place',       'typ' => 'trojstan',     'domyslnie' => '',
                                   'preset' => ['gastronomia']],
            'place_drive_thru' => ['wezel' => 'place',        'typ' => 'trojstan',     'domyslnie' => '',
                                   'preset' => ['gastronomia']],
            'place_specialty'  => ['wezel' => 'place',        'typ' => 'wieloliniowe', 'domyslnie' => '',
                                   'preset' => ['zdrowie']],

            /* Pola miejsca WSPÓLNE dla wszystkich branż (1.174.0) — bez klucza
               `preset`, więc widoczne zawsze. */
            'place_special_hours' => ['wezel' => 'place',     'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'place_currencies' => ['wezel' => 'place',        'typ' => 'tekst',        'domyslnie' => ''],
            'place_payment'    => ['wezel' => 'place',        'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'place_public'     => ['wezel' => 'place',        'typ' => 'trojstan',     'domyslnie' => ''],
            'place_free'       => ['wezel' => 'place',        'typ' => 'trojstan',     'domyslnie' => ''],
            'place_smoking'    => ['wezel' => 'place',        'typ' => 'trojstan',     'domyslnie' => ''],
            'place_branch'     => ['wezel' => 'place',        'typ' => 'tekst',        'domyslnie' => ''],
            'place_capacity'   => ['wezel' => 'place',        'typ' => 'liczba',       'domyslnie' => ''],
            'place_photos'     => ['wezel' => 'place',        'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'place_fax'        => ['wezel' => 'place',        'typ' => 'tekst',        'domyslnie' => ''],

            // ── Atrakcja turystyczna (#attraction) ──────────────────────
            'attraction_name'  => ['wezel' => 'attraction',   'typ' => 'tekst',        'domyslnie' => ''],
            'attr_tourist_type'=> ['wezel' => 'attraction',   'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'attr_languages'   => ['wezel' => 'attraction',   'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'attr_hours'       => ['wezel' => 'attraction',   'typ' => 'wieloliniowe', 'domyslnie' => ''],
            'attr_public'      => ['wezel' => 'attraction',   'typ' => 'trojstan',     'domyslnie' => ''],
            'attr_free'        => ['wezel' => 'attraction',   'typ' => 'trojstan',     'domyslnie' => ''],

            // ── Encje podrzędne (repeater) ──────────────────────────────
            'sub_entities'     => ['wezel' => 'entities',     'typ' => 'wlasne',       'domyslnie' => '[]'],

            // Repeater punktów kontaktowych (1.174.0) — patrz build_organization().
            'contact_points'   => ['wezel' => 'organization', 'typ' => 'wlasne',       'domyslnie' => '[]'],

            // ── WooCommerce ─────────────────────────────────────────────
            'lang_currencies'  => ['wezel' => 'product',      'typ' => 'json',         'domyslnie' => '{"en":"EUR","de":"EUR"}'],

            // ── Aktywne bloki JSON-LD ───────────────────────────────────
            'block_website'    => ['wezel' => 'bloki',        'typ' => 'checkbox',     'domyslnie' => 1],
            'block_org'        => ['wezel' => 'bloki',        'typ' => 'checkbox',     'domyslnie' => 1],
            'block_breadcrumb' => ['wezel' => 'bloki',        'typ' => 'checkbox',     'domyslnie' => 1],
            'block_webpage'    => ['wezel' => 'bloki',        'typ' => 'checkbox',     'domyslnie' => 1],
            'block_article'    => ['wezel' => 'bloki',        'typ' => 'checkbox',     'domyslnie' => 1],
            'block_faq'        => ['wezel' => 'bloki',        'typ' => 'checkbox',     'domyslnie' => 1],
            'block_product'    => ['wezel' => 'bloki',        'typ' => 'checkbox',     'domyslnie' => 1],
            'block_attraction' => ['wezel' => 'bloki',        'typ' => 'checkbox',     'domyslnie' => 0],
        ];
    }

    // ================================================================
    // PRESETY BRANŻOWE
    // ================================================================
    /**
     * Preset → etykieta i typy działalności, które do niego należą.
     *
     * PRESET NIE JEST OSOBNYM USTAWIENIEM. Wynika z `org_type`: wybór „Hotel"
     * SAM ustawia preset „noclegi". Rozpiska przewidywała nad sekcjami osobny
     * przełącznik branży, ale dwa sterowniki dla jednej rzeczy pozwalają je
     * rozjechać — wybrać „Hotel" i preset „gastronomia" — i wtedy panel
     * pokazuje pola, których wybrany typ nie ma. Skoro preset ma być
     * mechanizmem POPRAWNOŚCI, nie może dać się ustawić wbrew typowi.
     *
     * PO CO W OGÓLE, SKORO TO TYLKO CHOWANIE PÓL: bo `servesCuisine` istnieje
     * na `FoodEstablishment`, a nie na `Dentist`; `checkinTime` na
     * `LodgingBusiness`, a nie na `HairSalon`. Panel pokazujący wszystkim
     * wszystko nie jest tylko zagracony — PRODUKUJE NIEPRAWIDŁOWY GRAF, gdy
     * ktoś wypełni pole spoza swojego typu.
     *
     * DLACZEGO ZDROWIE I URODA TO DWA PRESETY, A NIE JEDEN. Rozpiska trzymała
     * je razem („zdrowie i uroda"). Sprawdzone przy pisaniu kodu: te typy
     * dziedziczą z różnych gałęzi. `MedicalBusiness` i `Dentist` mają
     * `medicalSpecialty`; `BeautySalon` i `HairSalon` idą przez
     * `HealthAndBeautyBusiness` i tej właściwości NIE mają. Jeden preset
     * dawałby salonowi fryzjerskiemu pole „specjalizacja medyczna" — dokładnie
     * ten błąd, przed którym presety mają bronić.
     */
    public static function presety(): array {
        return [
            'organizacja'   => ['etykieta' => 'Organizacja (bez fizycznego obiektu)',
                                'typy' => ['Organization']],
            'firma-lokalna' => ['etykieta' => 'Firma lokalna (ogólna)',
                                'typy' => ['LocalBusiness']],
            'noclegi'       => ['etykieta' => 'Obiekt noclegowy',
                                'typy' => ['LodgingBusiness', 'Hotel', 'BedAndBreakfast',
                                           'Campground', 'Resort', 'Hostel']],
            'gastronomia'   => ['etykieta' => 'Gastronomia',
                                'typy' => ['Restaurant', 'CafeOrCoffeeShop', 'BarOrPub',
                                           'FoodEstablishment']],
            'sklep'         => ['etykieta' => 'Sklep stacjonarny',
                                'typy' => ['Store']],
            'uslugi'        => ['etykieta' => 'Usługi profesjonalne',
                                'typy' => ['ProfessionalService', 'LegalService', 'FinancialService',
                                           'RealEstateAgent', 'TravelAgency', 'AutoRepair',
                                           'HomeAndConstructionBusiness']],
            'zdrowie'       => ['etykieta' => 'Placówka medyczna',
                                'typy' => ['MedicalBusiness', 'Dentist']],
            'uroda'         => ['etykieta' => 'Uroda i fryzjerstwo',
                                'typy' => ['BeautySalon', 'HairSalon']],
            'sport'         => ['etykieta' => 'Sport, rekreacja, turystyka',
                                'typy' => ['SportsActivityLocation', 'TouristInformationCenter']],
        ];
    }

    /** Preset dla typu działalności. Typ spoza wszystkich list → 'firma-lokalna'. */
    public static function preset_dla_typu(string $org_type): string {
        foreach (self::presety() as $klucz => $preset) {
            if (in_array($org_type, $preset['typy'], true)) return $klucz;
        }
        return 'firma-lokalna';
    }

    /** Czy pole rejestru jest widoczne (i emitowane) przy danym presecie. */
    public static function pole_w_presecie(array $opis, string $preset): bool {
        // Brak klucza `preset` = pole wspólne, widoczne zawsze.
        return !isset($opis['preset']) || in_array($preset, $opis['preset'], true);
    }

    /** Klucze rejestru o danym typie sanityzacji. */
    public static function pola_typu(string $typ): array {
        $out = [];
        foreach (self::pola() as $klucz => $opis) {
            if ($opis['typ'] === $typ) $out[] = $klucz;
        }
        return $out;
    }

    /** Wartości domyślne — wyprowadzane z rejestru, nie trzymane osobno. */
    public static function domyslne(): array {
        $out = [];
        foreach (self::pola() as $klucz => $opis) {
            $out[$klucz] = $opis['domyslnie'];
        }
        return $out;
    }
    // ----------------------------------------------------------------
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    private function __construct() {
        add_action('wp_head',   [$this, 'render_graph'], 15);
        add_action('admin_init', [$this, 'register_settings']);
    }
    // ================================================================
    // USTAWIENIA
    // ================================================================
    /**
     * Dozwolone typy działalności bloku Organization (schema.org).
     * Wszystkie poza 'Organization' dziedziczą z LocalBusiness (= Place),
     * więc obsługują geo, priceRange i amenityFeature.
     */
    public static function org_types(): array {
        return [
            'Organization'             => 'Organizacja (domyślne)',
            'LocalBusiness'            => 'Firma lokalna',
            'LodgingBusiness'          => 'Obiekt noclegowy',
            'Hotel'                    => 'Hotel',
            'BedAndBreakfast'          => 'Pensjonat / B&B',
            'Campground'               => 'Pole namiotowe / kemping',
            'Resort'                   => 'Ośrodek wypoczynkowy',
            'Hostel'                   => 'Hostel / schronisko',
            'Restaurant'               => 'Restauracja',
            'CafeOrCoffeeShop'         => 'Kawiarnia',
            'BarOrPub'                 => 'Bar / pub',
            'FoodEstablishment'        => 'Gastronomia (ogólnie)',
            'Store'                    => 'Sklep stacjonarny',
            'ProfessionalService'      => 'Usługi profesjonalne',
            'MedicalBusiness'          => 'Placówka medyczna',
            'Dentist'                  => 'Gabinet stomatologiczny',
            'SportsActivityLocation'   => 'Obiekt sportowo-rekreacyjny',
            'TravelAgency'             => 'Biuro podróży',
            'TouristInformationCenter' => 'Informacja turystyczna',
            'AutoRepair'               => 'Warsztat samochodowy',
            'BeautySalon'              => 'Salon urody',
            'HairSalon'                => 'Salon fryzjerski',
            'RealEstateAgent'          => 'Biuro nieruchomości',
            'LegalService'             => 'Kancelaria / usługi prawne',
            'FinancialService'         => 'Usługi finansowe',
            'HomeAndConstructionBusiness' => 'Budownictwo / dom i ogród',
        ];
    }
    /**
     * Typy podrzędnych obiektów/usług (repeater). Wyłącznie podtypy Place —
     * dzięki temu można je powiązać z węzłem #place przez containedInPlace.
     */
    public static function sub_entity_types(): array {
        return [
            'Campground'             => 'Pole namiotowe / kemping',
            'SportsActivityLocation' => 'Obiekt sportowy / wypożyczalnia sprzętu',
            'TouristAttraction'      => 'Atrakcja turystyczna',
            'Restaurant'             => 'Restauracja',
            'CafeOrCoffeeShop'       => 'Kawiarnia / bar',
            'EventVenue'             => 'Miejsce wydarzeń / sala',
            'Playground'             => 'Plac zabaw',
            'ParkingFacility'        => 'Parking',
            'Beach'                  => 'Plaża / dostęp do wody',
            'RiverBodyOfWater'       => 'Rzeka / akwen',
            'Park'                   => 'Park / teren zielony',
        ];
    }
    /**
     * Ustawienia obowiązujące dla danej podstrony.
     *
     * TRZY WARSTWY, każda nadpisuje poprzednią:
     *
     *   1. wartości domyślne z rejestru
     *   2. ustawienia globalne (opcja `evk_schema`)
     *   3. NADPISANIA PER PODSTRONA — meta wpisu `_evk_schema`
     *
     * Warstwa 3 jest miejscem, w które wepnie się przyszły metaboks „Schema"
     * przy wpisie albo osobna zakładka w rodzaju ustawień SEO. Nie ma dziś
     * żadnego interfejsu, który by ją zapisywał, i to jest jedyne, czego
     * brakuje: sam mechanizm jest kompletny i sprawdzany
     * (`tests/php/schema-graf.php`, scenariusz `nadpisanie-wpisu`). Dopisanie
     * interfejsu sprowadza się do zapisania tablicy pod tym kluczem meta —
     * bez dotykania budowania grafu.
     *
     * PUSTY ŁAŃCUCH NADPISUJE, BRAK KLUCZA NIE. To rozróżnienie jest tu
     * całą treścią: „wpisz pusto, żeby na tej podstronie nie było telefonu"
     * musi dać się odróżnić od „nie ustawiaj nic na tej podstronie".
     * Meta niosąca wyłącznie ustawione klucze załatwia jedno i drugie —
     * dlatego scalanie idzie po kluczach obecnych w tablicy, a nie po
     * niepustych wartościach.
     *
     * Filtr `evk_schema_settings` dostaje komplet i numer wpisu, więc kod
     * spoza modułu też może dołożyć warstwę, nie czekając na metaboks.
     *
     * @param int $post_id 0 = same ustawienia globalne (strona główna, archiwa).
     */
    public function get_settings(int $post_id = 0): array {
        $ustawienia = wp_parse_args(get_option('evk_schema', []), self::domyslne());

        if ($post_id > 0) {
            $nadpisania = get_post_meta($post_id, self::META_KEY, true);
            if (is_array($nadpisania)) {
                /* Tylko klucze, które modul zna — meta wpisu bywa edytowana
                   z zewnątrz i nie chcemy, żeby dowolny klucz wjeżdżał do
                   ustawień tylnymi drzwiami. */
                foreach (self::pola() as $klucz => $_) {
                    if (array_key_exists($klucz, $nadpisania)) {
                        $ustawienia[$klucz] = $nadpisania[$klucz];
                    }
                }
            }
        }

        return apply_filters('evk_schema_settings', $ustawienia, $post_id);
    }
    public function register_settings(): void {
        register_setting('evoke_one_schema', 'evk_schema', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }
    /**
     * Sanityzuje JEDNĄ wartość zgodnie z typem z rejestru.
     *
     * Osobno, bo tę samą regułę stosuje i zapis ustawień globalnych,
     * i — gdy dojdzie — zapis nadpisań per podstrona. Dwie kopie tej
     * logiki rozjechałyby się przy pierwszym nowym typie pola.
     */
    public static function sanityzuj_wartosc(string $typ, $wartosc, $domyslnie) {
        switch ($typ) {
            case 'checkbox':
                return !empty($wartosc) ? 1 : 0;

            case 'url':
                return esc_url_raw((string) $wartosc);

            case 'wieloliniowe':
                return sanitize_textarea_field((string) $wartosc);

            case 'wspolrzedna':
                // Przecinek dziesiętny na kropkę; cokolwiek innego niż liczba → puste.
                $v = str_replace(',', '.', sanitize_text_field((string) $wartosc));
                return ($v !== '' && !is_numeric($v)) ? '' : $v;

            case 'data':
                /* schema.org przyjmuje Date w ISO 8601. Dopuszczamy trzy
                   ziarnistości, bo „rok założenia" bywa znany co do roku,
                   a wymuszanie pełnej daty kazałoby zmyślać dzień. */
                $d = trim(sanitize_text_field((string) $wartosc));
                return preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $d) ? $d : '';

            case 'czas':
                // schema.org Time w zapisie HH:MM. Godziny otwarcia mają
                // własny parser (parse_opening_hours), ale checkinTime to
                // jedna godzina, nie reguła — własny parser byłby przesadą.
                $t = trim(sanitize_text_field((string) $wartosc));
                if ($t === '') return '';
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '';

            case 'trojstan':
                /* TRZY stany, nie dwa. `smokingAllowed: false` znaczy
                   „u nas się nie pali" i jest deklaracją; brak właściwości
                   znaczy „nie mówimy". Checkbox tych dwóch rzeczy nie
                   odróżnia, więc zamiast niego jest select. */
                /* Tolerancyjnie na liczby, bo opcja bywa zapisana int-em:
                   przez checkbox sprzed 1.174.0, przez `update_option()`
                   z cudzego kodu albo przez import ustawień. Ścisłe
                   porównanie do łańcucha cicho gubiłoby takie wartości. */
                if ($wartosc === 1 || $wartosc === '1' || $wartosc === true)  return '1';
                if ($wartosc === 0 || $wartosc === '0' || $wartosc === false) return '0';
                return '';

            case 'liczba':
                $n = trim(sanitize_text_field((string) $wartosc));
                return preg_match('/^\d+$/', $n) ? $n : '';

            case 'json':
                $raw = (string) $wartosc;
                json_decode($raw);
                return (json_last_error() === JSON_ERROR_NONE) ? $raw : $domyslnie;

            case 'tekst':
            default:
                return sanitize_text_field((string) $wartosc);
        }
    }

    public function sanitize_settings($input): array {
        $input = is_array($input) ? $input : [];
        $clean = [];

        /* PĘTLA PO REJESTRZE, a nie po ręcznie wypisanych listach.
           Wcześniej stały tu cztery listy nazw (`$checkboxes`, `$texts`,
           wieloliniowe, JSON-y) i pole dopisane do formularza, a pominięte
           w liście, przestawało się zapisywać BEZ ŻADNEGO OBJAWU. Teraz
           dopisanie pola do `pola()` wystarcza; nie ma drugiego miejsca,
           w którym można je przeoczyć. */
        foreach (self::pola() as $klucz => $opis) {
            if ($opis['typ'] === 'wlasne') continue;   // niżej, każde ze swojego powodu
            $clean[$klucz] = self::sanityzuj_wartosc(
                $opis['typ'],
                $input[$klucz] ?? $opis['domyslnie'],
                $opis['domyslnie']
            );
        }

        // 'enabled' zarządzany przez AJAX toggle — zachowaj gdy brak w POST
        $clean['enabled'] = evk_preserve_toggle($input, 'evk_schema');

        // Typ działalności — tylko z listy dozwolonych
        $org_type = sanitize_text_field($input['org_type'] ?? 'Organization');
        $clean['org_type'] = array_key_exists($org_type, self::org_types()) ? $org_type : 'Organization';
		// Opisy per język (z osobnych pól formularza)
if (isset($_POST['evk_schema_desc']) && is_array($_POST['evk_schema_desc'])) {
    $descs = [];
    foreach (wp_unslash($_POST['evk_schema_desc']) as $code => $text) {
        $code = sanitize_key($code);
        if ($code) {
            $descs[$code] = sanitize_textarea_field($text);
        }
    }
    $clean['descriptions'] = wp_json_encode($descs, JSON_UNESCAPED_UNICODE);
} else {
    /* Wynik pętli rejestru, a NIE surowe `$input`. Wcześniej stało tu
       sięgnięcie po wejście z pominięciem walidacji JSON-a, którą
       pętla właśnie wykonała — zepsuty JSON przechodził na wylot
       i lądował w opcji. */
    $clean['descriptions'] = $clean['descriptions'];
}
// Social links
if (isset($_POST['evk_schema_socials'])) {
    $lines = array_filter(array_map('esc_url_raw', explode("\n", wp_unslash($_POST['evk_schema_socials']))));
    $clean['social_links'] = wp_json_encode(array_values($lines));
} else {
    /* Wynik pętli rejestru, a NIE surowe `$input`. Wcześniej stało tu
       sięgnięcie po wejście z pominięciem walidacji JSON-a, którą
       pętla właśnie wykonała — zepsuty JSON przechodził na wylot
       i lądował w opcji. */
    $clean['social_links'] = $clean['social_links'];
}
// Waluty per język
if (isset($_POST['evk_schema_curr']) && is_array($_POST['evk_schema_curr'])) {
    $currs = [];
    foreach (wp_unslash($_POST['evk_schema_curr']) as $code => $currency) {
        $code = sanitize_key($code);
        $curr = strtoupper(sanitize_text_field($currency));
        if ($code && $curr) {
            $currs[$code] = $curr;
        }
    }
    $clean['lang_currencies'] = wp_json_encode($currs);
} else {
    /* Wynik pętli rejestru, a NIE surowe `$input`. Wcześniej stało tu
       sięgnięcie po wejście z pominięciem walidacji JSON-a, którą
       pętla właśnie wykonała — zepsuty JSON przechodził na wylot
       i lądował w opcji. */
    $clean['lang_currencies'] = $clean['lang_currencies'];
}
// Punkty kontaktowe (repeater — równoległe tablice type/telephone/email)
if (isset($_POST['evk_schema_contact']) && is_array($_POST['evk_schema_contact'])) {
    $raw    = wp_unslash($_POST['evk_schema_contact']);
    $typy   = (array) ($raw['type'] ?? []);
    $tele   = (array) ($raw['telephone'] ?? []);
    $maile  = (array) ($raw['email'] ?? []);
    $punkty = [];
    foreach ($typy as $i => $typ) {
        $tel  = sanitize_text_field($tele[$i] ?? '');
        $mail = sanitize_text_field($maile[$i] ?? '');
        // Wiersz bez telefonu I bez maila nie jest punktem kontaktowym.
        if ($tel === '' && $mail === '') continue;
        $punkty[] = [
            'type'      => sanitize_text_field($typ),
            'telephone' => $tel,
            'email'     => $mail,
        ];
    }
    $clean['contact_points'] = wp_json_encode($punkty, JSON_UNESCAPED_UNICODE);
} else {
    $clean['contact_points'] = self::sanityzuj_wartosc(
        'json',
        $input['contact_points'] ?? self::pola()['contact_points']['domyslnie'],
        self::pola()['contact_points']['domyslnie']
    );
}

// Podrzędne obiekty/usługi (repeater — równoległe tablice type/name/description)
if (isset($_POST['evk_schema_sub']) && is_array($_POST['evk_schema_sub'])) {
    $sub_raw = wp_unslash($_POST['evk_schema_sub']);
    $types   = (array) ($sub_raw['type'] ?? []);
    $names   = (array) ($sub_raw['name'] ?? []);
    $descs   = (array) ($sub_raw['description'] ?? []);
    $urls    = (array) ($sub_raw['url'] ?? []);
    $tels    = (array) ($sub_raw['telephone'] ?? []);
    $imgs    = (array) ($sub_raw['image'] ?? []);
    $allowed = self::sub_entity_types();
    $subs    = [];
    foreach ($types as $i => $type) {
        $type = sanitize_text_field($type);
        $name = sanitize_text_field($names[$i] ?? '');
        if ($name === '' || !array_key_exists($type, $allowed)) continue;  // wymagany typ + nazwa
        $subs[] = [
            'type'        => $type,
            'name'        => $name,
            'description' => sanitize_textarea_field($descs[$i] ?? ''),
            'url'         => esc_url_raw($urls[$i] ?? ''),
            'telephone'   => sanitize_text_field($tels[$i] ?? ''),
            'image'       => esc_url_raw($imgs[$i] ?? ''),
        ];
    }
    $clean['sub_entities'] = wp_json_encode($subs, JSON_UNESCAPED_UNICODE);
} else {
    /* Bez repeatera w POST bierzemy wartość z wejścia, ale PRZEZ walidację —
       inaczej zepsuty JSON wchodzi do opcji i `build_sub_entities()` cicho
       oddaje pustą listę, a klient widzi zniknięte encje bez komunikatu. */
    $clean['sub_entities'] = self::sanityzuj_wartosc(
        'json',
        $input['sub_entities'] ?? self::pola()['sub_entities']['domyslnie'],
        self::pola()['sub_entities']['domyslnie']
    );
}
        return $clean;
    }
    // ================================================================
    // GENEROWANIE GRAFU
    // ================================================================
    public function render_graph(): void {
        if (is_admin()) return;
        if (function_exists('tl_is_bricks_editor') && tl_is_bricks_editor()) return;

        /* Ustawienia pobierane RAZ, dla bieżącej podstrony, i przekazywane
           dalej — zamiast dosięgane osobno przez `build_webpage()`
           i `build_article()`. Dwa powody: jedno żądanie ma jedną prawdę
           o ustawieniach (przy nadpisaniach per podstrona to przestaje być
           kosmetyką), i pytamy bazę raz zamiast trzy razy. */
        global $post;
        $post_id = (is_singular() && $post) ? (int) $post->ID : 0;
        $s = $this->get_settings($post_id);
        if (empty($s['enabled'])) return;
        $lang     = function_exists('get_current_lang') ? get_current_lang() : 'pl';
        $home_url = $this->home_url($lang);
        $graph    = [];
        // 1. WebSite
        if (!empty($s['block_website'])) {
            $graph[] = $this->build_website($s, $home_url, $lang);

        }
        // 2. Organization — wydawca strony (zawsze czysta Organization)
        if (!empty($s['block_org'])) {
            $graph[] = $this->build_organization($s, $home_url, $lang);
        }
        // 2b. Miejsce / firma lokalna (#place) — gdy wybrano typ działalności
        if ($this->has_place($s)) {
            $graph[] = $this->build_place($s, $home_url, $lang);
        }
        // 2c. TouristAttraction (obiekt/miejsce jako atrakcja turystyczna)
        if (!empty($s['block_attraction'])) {
            $graph[] = $this->build_attraction($s, $home_url, $lang);
        }
        // 2d. Podrzędne obiekty/usługi (pole namiotowe, wypożyczalnia itd.)
        foreach ($this->build_sub_entities($s, $home_url) as $sub) {
            $graph[] = $sub;
        }
        // Bloki per-strona
        if ($post_id) {
            $permalink = get_permalink($post_id);
            // Ten sam łańcuch źródeł co meta tagi (Bricks → zakładka SEO → fallback)
            if (function_exists('evk_seo_get_meta')) {
                $og_image = evk_seo_get_meta($post->ID)['og_image'];
            } else {
                $og_image = function_exists('get_final_og_image_url') ? get_final_og_image_url() : '';
            }
            // 3. BreadcrumbList
            if (!empty($s['block_breadcrumb'])) {
                $bc = $this->build_breadcrumbs($post, $home_url, $s['site_name']);
                if ($bc) $graph[] = $bc;
            }
            // 4. WebPage
            if (!empty($s['block_webpage'])) {
                $graph[] = $this->build_webpage($s, $post, $permalink, $home_url, $og_image);
            }
            // 5. Article / BlogPosting
            if (!empty($s['block_article']) && is_single() && get_post_type() === 'post') {
                $graph[] = $this->build_article($s, $post, $permalink, $home_url, $og_image);
            }
            // 6. FAQPage (Bricks accordions)
            if (!empty($s['block_faq'])) {
                $faq_items = $this->extract_faq($post->ID);
                if (!empty($faq_items)) {
                    $graph[] = [
                        '@type'      => 'FAQPage',
                        '@id'        => $permalink . '#faq',
                        'isPartOf'   => ['@id' => $permalink . '#webpage'],
                        'mainEntity' => $faq_items,
                    ];
                }
            }
            // 7. Product (WooCommerce)
            if (!empty($s['block_product']) && function_exists('is_product') && is_product()) {
                $product = $this->build_product($post->ID, $permalink, $lang, $s);
                if ($product) $graph[] = $product;
            }
        }
        if (empty($graph)) return;
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo "\n\n";
        echo '<script type="application/ld+json">';
        echo json_encode(['@context' => 'https://schema.org', '@graph' => $graph], $flags);
        echo "</script>\n\n";
    }
    // ================================================================
    // BLOKI GRAFU
    // ================================================================
private function build_website(array $s, string $home_url, string $lang): array {
	$descriptions = json_decode($s['descriptions'], true) ?: [];
    $description  = $descriptions[$lang] ?? $descriptions['pl'] ?? get_bloginfo('description');

        $site = [
            '@type'           => 'WebSite',
            '@id'             => $home_url . '#website',
            'url'             => $home_url,
            'name'            => $s['site_name'] ?: get_bloginfo('name'),
            'description'     => $description,
        ];
        // Wydawca TYLKO wtedy, gdy węzeł #organization naprawdę jest w grafie.
        if (!empty($s['block_org'])) {
            $site['publisher'] = ['@id' => $home_url . '#organization'];
        }
        $site['potentialAction'] = [
            '@type'        => 'SearchAction',
            'target'       => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => $home_url . '?s={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ];
        return $site;
    }
    private function build_organization(array $s, string $home_url, string $lang): array {
        $site_name = $s['site_name'] ?: get_bloginfo('name');
        $org_name  = $s['operator_name'] ?: $site_name;
        // Opis per język
        $descriptions = json_decode($s['descriptions'], true) ?: [];
        $description  = $descriptions[$lang] ?? $descriptions['pl'] ?? get_bloginfo('description');
        // Wydawca strony — zawsze czysta Organization; właściwości miejsca
        // (adres, geo, priceRange, udogodnienia, godziny) idą do węzła #place
        $org = [
            '@type'        => 'Organization',
            '@id'          => $home_url . '#organization',
            'name'         => $org_name,
            'url'          => $home_url,
            'description'  => $description,
        ];
        // Adres — także na Organization (zalecane pole Google), gdy podano dane.
        // Ten sam adres trafia na #place; oba węzły to ten sam realny punkt,
        // powiązany przez parentOrganization.
        if ($address = $this->build_address($s)) {
            $org['address'] = $address;
        }
        if ($s['telephone']) {
            $org['telephone'] = $s['telephone'];
        }
        if ($s['email']) {
            $org['email'] = $s['email'];
        }
        /* ContactPoint — repeater z FALLBACKIEM na dotychczasowe zachowanie.
           Firmy mają osobne numery do sprzedaży, rezerwacji i wsparcia,
           a `contactType` jest właśnie od ich rozróżniania. Gdy repeater jest
           pusty, wychodzi dokładnie jeden punkt złożony z telefonu i typu
           kontaktu — czyli to, co moduł robił dotąd. Dzięki temu witryna,
           która repeatera nie tknęła, ma graf bez zmian. */
        $kontakty = json_decode($s['contact_points'] ?? '[]', true);
        $punkty   = [];
        if (is_array($kontakty)) {
            foreach ($kontakty as $k) {
                $tel  = trim((string) ($k['telephone'] ?? ''));
                $mail = trim((string) ($k['email'] ?? ''));
                if ($tel === '' && $mail === '') continue;   // pusty wiersz
                $punkt = [
                    '@type'       => 'ContactPoint',
                    'contactType' => trim((string) ($k['type'] ?? '')) ?: 'customer service',
                ];
                if ($tel !== '')  $punkt['telephone'] = $tel;
                if ($mail !== '') $punkt['email'] = $mail;
                $punkt['availableLanguage'] = $this->get_available_languages();
                $punkty[] = $punkt;
            }
        }
        if (!$punkty && $s['telephone']) {
            $punkty[] = [
                '@type'             => 'ContactPoint',
                'telephone'         => $s['telephone'],
                'contactType'       => $s['contact_type'] ?: 'customer service',
                'availableLanguage' => $this->get_available_languages(),
            ];
        }
        if ($punkty) {
            // Jeden punkt zostaje obiektem, nie jednoelementową tablicą —
            // inaczej graf istniejących witryn zmieniłby kształt bez powodu.
            $org['contactPoint'] = count($punkty) === 1 ? $punkty[0] : $punkty;
        }
        // Logo
        if ($s['favicon_url']) {
            $logo_url = (strpos($s['favicon_url'], 'http') === 0)
                ? $s['favicon_url']
                : untrailingslashit(get_option('home')) . $s['favicon_url'];
            $org['image'] = $logo_url;
            $org['logo']  = [
                '@type'   => 'ImageObject',
                '@id'     => $home_url . '#logo',
                'url'     => $logo_url,
                'caption' => $org_name,
            ];
        }
        // sameAs — najpierw z ustawień, potem auto-detekcja z menu
        $social = json_decode($s['social_links'], true) ?: [];
        if (empty($social)) {
            $social = $this->auto_detect_socials();
        }
        if (!empty($social)) {
            $org['sameAs'] = $social;
        }

        /* Pola dołożone w 1.172.0. KAŻDE wchodzi tylko wtedy, gdy wypełnione —
           dzięki temu graf witryny, która ich nie tknęła, jest bajt w bajt
           taki sam jak przed tym wydaniem. Pilnują tego pliki wzorcowe:
           żaden ze scenariuszy sprzed 1.172.0 nie drgnął. */
        $proste = [
            'org_legal_name' => 'legalName',
            'org_alternate'  => 'alternateName',
            'org_slogan'     => 'slogan',
            'org_founding'   => 'foundingDate',
            'org_vat_id'     => 'vatID',
            'org_tax_id'     => 'taxID',
            'org_fax'        => 'faxNumber',
        ];
        foreach ($proste as $klucz => $wlasciwosc) {
            if (!empty($s[$klucz])) $org[$wlasciwosc] = $s[$klucz];
        }

        if (!empty($s['org_founder'])) {
            $org['founder'] = ['@type' => 'Person', 'name' => $s['org_founder']];
        }
        if (!empty($s['org_brand'])) {
            $org['brand'] = ['@type' => 'Brand', 'name' => $s['org_brand']];
        }
        /* QuantitativeValue, a nie goła liczba: schema.org dopuszcza oba,
           ale forma z jednostką jest tą, którą rozumieją konsumenci bez
           zgadywania, czego dotyczy liczba. */
        if ($s['org_employees'] !== '') {
            $org['numberOfEmployees'] = [
                '@type' => 'QuantitativeValue',
                'value' => (int) $s['org_employees'],
            ];
        }

        /* knowsAbout — pozycja nr 1 z listy zgłaszającego. Jedno pole niesie
           dwie postacie naraz: linia zaczynająca się od adresu staje się
           WSKAZANIEM NA ENCJĘ (Wikipedia, Wikidata), reszta zwykłym tekstem.
           Google czyta oba, ale wskazanie na encję jest mocniejsze — a pole
           przyjmujące wyłącznie adresy zostałoby puste, bo mało kto umie
           znaleźć identyfikator Wikidaty. */
        $tematy = [];
        foreach (self::linie($s['org_knows_about'] ?? '') as $linia) {
            $tematy[] = preg_match('~^https?://~i', $linia)
                ? ['@type' => 'Thing', '@id' => $linia]
                : $linia;
        }
        if ($tematy) $org['knowsAbout'] = $tematy;

        if ($nagrody = self::linie($s['org_award'] ?? '')) {
            $org['award'] = $nagrody;
        }
        if ($obszary = self::linie($s['org_area_served'] ?? '')) {
            $org['areaServed'] = $obszary;
        }

        /* memberOf: „Nazwa | https://adres" — adres opcjonalny. Pionowa kreska,
           bo nazwy zrzeszeń zawierają przecinki i myślniki, a te rozdzielniki
           dzieliłyby połowę realnych wpisów w złym miejscu. */
        $czlonkostwa = [];
        foreach (self::linie($s['org_member_of'] ?? '') as $linia) {
            $czesci = array_map('trim', explode('|', $linia, 2));
            $wpis   = ['@type' => 'Organization', 'name' => $czesci[0]];
            if (!empty($czesci[1])) $wpis['url'] = $czesci[1];
            $czlonkostwa[] = $wpis;
        }
        if ($czlonkostwa) $org['memberOf'] = $czlonkostwa;

        /* Pola bramkowane presetem. Bramka jest TUTAJ, a nie tylko w panelu:
           wartość zapisana przy poprzednim typie działalności zostaje w bazie
           (zmiana typu nie kasuje danych — patrz komentarz przy `sanitize_settings`),
           więc bez tego warunku hotel przerobiony na kancelarię dalej
           wysyłałby godzinę zameldowania. */
        $preset = self::preset_dla_typu($s['org_type'] ?? 'Organization');

        if ($preset === 'organizacja' && !empty($s['org_nonprofit'])) {
            $org['nonprofitStatus'] = $s['org_nonprofit'];
        }
        if (in_array($preset, ['uslugi', 'zdrowie', 'uroda'], true)) {
            $uslugi = self::linie($s['org_offer_catalog'] ?? '');
            if ($uslugi) {
                /* hasOfferCatalog opisuje, co oferuje FIRMA, a nie co zawiera
                   BUDYNEK — dlatego siedzi na #organization, nie na #place.
                   Rozpiska zostawiała to jako pytanie otwarte. */
                $org['hasOfferCatalog'] = [
                    '@type'          => 'OfferCatalog',
                    'name'           => 'Oferta',
                    'itemListElement' => array_map(static function ($nazwa) {
                        return ['@type' => 'Offer', 'itemOffered' =>
                            ['@type' => 'Service', 'name' => $nazwa]];
                    }, $uslugi),
                ];
            }
        }

        return $org;
    }
    private function build_breadcrumbs(WP_Post $post, string $home_url, string $site_name): array {
    $permalink = get_permalink($post->ID);
    $items     = [];
    $items[] = [
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => $site_name ?: get_bloginfo('name'),
        'item'     => $home_url,
    ];
    // Jeśli permalink == home_url, to jest strona główna — jeden poziom wystarczy
    $clean_permalink = untrailingslashit($permalink);
    $clean_home      = untrailingslashit($home_url);
    if ($clean_permalink === $clean_home) {
        return [
            '@type'           => 'BreadcrumbList',
            '@id'             => $permalink . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }
    if ($post->post_type === 'page') {
        $ancestors = array_reverse(get_post_ancestors($post->ID));
        $pos = 2;
        foreach ($ancestors as $ancestor_id) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => get_the_title($ancestor_id),
                'item'     => get_permalink($ancestor_id),
            ];
        }
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => get_the_title($post->ID),
            'item'     => $permalink,
        ];
    } else {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => get_the_title($post->ID),
            'item'     => $permalink,
        ];
    }
    return [
        '@type'           => 'BreadcrumbList',
        '@id'             => $permalink . '#breadcrumb',
        'itemListElement' => $items,
    ];
}
private function build_article(array $s, WP_Post $post, string $permalink, string $home_url, string $og_image): array {
    $author_id   = (int) $post->post_author;
    $author_name = get_the_author_meta('display_name', $author_id);
    $author_url  = get_author_posts_url($author_id);
    $published   = get_the_date('c', $post);
    $modified    = get_the_modified_date('c', $post);
    $title       = get_the_title($post->ID);
    $excerpt     = wp_strip_all_tags(get_the_excerpt($post->ID));

    $article = [
        '@type'            => 'BlogPosting',
        '@id'              => $permalink . '#article',
        'isPartOf'         => ['@id' => $permalink . '#webpage'],
        'url'              => $permalink,
        'headline'         => $title,
        'datePublished'    => $published,
        'dateModified'     => $modified,
        'author'           => [
            '@type' => 'Person',
            '@id'   => $author_url . '#author',
            'name'  => $author_name,
            'url'   => $author_url,
        ],
        'publisher'        => ['@id' => $home_url . '#organization'],
        'inLanguage'       => get_bloginfo('language'),
        /* BEZ `breadcrumb`. Właściwość ma w schema.org dziedzinę WYŁĄCZNIE
           `WebPage`, a BlogPosting to Article → CreativeWork. Okruszki i tak
           są w grafie dwa razy: jako własny węzeł BreadcrumbList i jako
           `WebPage.breadcrumb`, więc usunięcie stąd niczego nie gubi.
           Znalezione audytem właściwość-po-właściwości w 1.173.0; usterka
           istniała, odkąd moduł powstał. */
    ];

    /* Wydawca TYLKO wtedy, gdy węzeł #organization naprawdę jest w grafie —
       tak samo jak w build_website(); oba miejsca ustawiały go bezwarunkowo
       i przy odhaczonym bloku Organization zostawiały wskazanie donikąd.
       Zdejmujemy po zbudowaniu, a nie dopisujemy warunkowo, żeby kolejność
       kluczy została ta sama — inaczej plik wzorcowy pokazywałby przy każdej
       takiej zmianie przetasowanie zamiast różnicy w treści. */
    if (empty($s['block_org'])) {
        unset($article['publisher']);
    }

    if ($excerpt) {
        $article['description'] = $excerpt;
    }

    if ($og_image) {
        $article['image'] = [
            '@type' => 'ImageObject',
            'url'   => $og_image,
        ];
    }

    // Kategorie jako keywords
    $cats = get_the_category($post->ID);
    if (!empty($cats)) {
        $article['keywords'] = implode(', ', wp_list_pluck($cats, 'name'));
    }

    return $article;
}

private function build_webpage(array $s, WP_Post $post, string $permalink, string $home_url, string $og_image): array {

    // Tytuł i opis — ten sam łańcuch źródeł co meta tagi
    // (Bricks: Ustawienia strony → zakładka SEO Evoke → fallback)
    if (function_exists('evk_seo_get_meta')) {
        $meta        = evk_seo_get_meta($post->ID);
        $title       = $meta['title'];
        $description = $meta['desc'];
    } else {
        $title       = get_the_title($post->ID);
        $description = '';
    }

    if (empty(trim((string) $title))) {
        $title = get_the_title($post->ID);
    }
    if (empty(trim((string) $title))) {
        $title = $s['site_name'] ?: get_bloginfo('name');
    }
    if (empty($description)) {
        $description = get_bloginfo('description');
    }

    $page = [
        '@type'       => 'WebPage',
        '@id'         => $permalink . '#webpage',
        'url'         => $permalink,
        'name'        => wp_strip_all_tags($title),
        'description' => wp_strip_all_tags($description),
        'isPartOf'    => ['@id' => $home_url . '#website'],
        'breadcrumb'  => ['@id' => $permalink . '#breadcrumb'],
    ];

    /* Okruszki TYLKO wtedy, gdy węzeł BreadcrumbList naprawdę powstaje.
       Ta sama klasa usterki co `publisher` naprawiony w 1.169.0: wskazanie
       na nieistniejący węzeł jest poprawnym JSON-em, więc nie zauważa go
       ani parser, ani oko. Wyszło z ogólnego sprawdzenia rozwiązywalności
       wskazań, nie z lektury kodu. */
    if (empty($s['block_breadcrumb'])) {
        unset($page['breadcrumb']);
    }

    if ($this->has_place($s)) {
        $page['about'] = ['@id' => $home_url . '#place'];
    } elseif (!empty($s['block_org'])) {
        $page['about'] = ['@id' => $home_url . '#organization'];
    }

    if ($og_image) {
        $page['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $og_image];
    }

    return $page;
}
    /**
     * Pytania i odpowiedzi z akordeonów Bricksa (klucz `items` w ustawieniach
     * elementu).
     *
     * WŁASNY OBCHÓD, A NIE array_walk_recursive — i to jest cała treść naprawy.
     * Poprzednia wersja pytała `if ($key === 'items' && is_array($value))`
     * wewnątrz `array_walk_recursive`, a ta funkcja NIE PODAJE tablic do
     * callbacka: wchodzi w nie i podaje wyłącznie liście. Warunek nie mógł być
     * prawdziwy nigdy, więc blok FAQPage — włączony domyślnie i opisany
     * w panelu jako działający — nie wyemitował ani jednego węzła, odkąd
     * istnieje. Wyszło dopiero przy pisaniu siatki regresyjnej na graf.
     */
    private function extract_faq(int $post_id): array {
        $bricks_data = get_post_meta($post_id, '_bricks_page_data', true);
        if (!is_array($bricks_data)) return [];
        $faq = [];
        $obejdz = static function (array $dane) use (&$obejdz, &$faq): void {
            foreach ($dane as $key => $value) {
                if (!is_array($value)) continue;
                if ($key === 'items') {
                    foreach ($value as $item) {
                        if (!is_array($item)) continue;
                        $title   = $item['title']   ?? '';
                        $content = $item['content'] ?? '';
                        if (!empty($title) && !empty($content)) {
                            $faq[] = [
                                '@type'          => 'Question',
                                'name'           => wp_strip_all_tags($title),
                                'acceptedAnswer' => [
                                    '@type' => 'Answer',
                                    'text'  => wp_strip_all_tags($content),
                                ],
                            ];
                        }
                    }
                }
                /* Schodzimy TAKŻE w gałąź `items`: akordeon bywa zagnieżdżony
                   w innym akordeonie, a pominięcie tego gubiłoby pytania
                   z zakładek i sekcji rozwijanych. */
                $obejdz($value);
            }
        };
        $obejdz($bricks_data);
        return $faq;
    }
    private function build_product(int $post_id, string $permalink, string $lang, array $s): array {
        if (!function_exists('wc_get_product')) return [];
        $product = wc_get_product($post_id);
        if (!$product) return [];
        $currencies = json_decode($s['lang_currencies'], true) ?: [];
        $currency   = $currencies[$lang] ?? get_woocommerce_currency();
        return [
            '@type'       => 'Product',
            '@id'         => $permalink . '#product',
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'sku'         => $product->get_sku(),
            'image'       => wp_get_attachment_url($product->get_image_id()) ?: '',
            'offers'      => [
                '@type'          => 'Offer',
                'url'            => $permalink,
                'priceCurrency'  => $currency,
                'price'          => $product->get_price(),
                'priceValidUntil'=> gmdate('Y-m-d', strtotime('+1 year')),
                'availability' => $product->is_in_stock()
    ? 'https://schema.org/InStock'
    : 'https://schema.org/OutOfStock',
                'seller'         => [
                    '@type' => 'Organization',
                    'name'  => $s['site_name'] ?: get_bloginfo('name'),
                ],
            ],
        ];
    }
    /**
     * Węzeł #place — fizyczny obiekt/firma lokalna (Resort, LodgingBusiness…),
     * osobny od wydawcy strony (#organization). Emitowany, gdy typ
     * działalności jest inny niż „Organizacja".
     */
    private function build_place(array $s, string $home_url, string $lang): array {
        $site_name = $s['site_name'] ?: get_bloginfo('name');
        $descriptions = json_decode($s['descriptions'], true) ?: [];
        $description  = $descriptions[$lang] ?? $descriptions['pl'] ?? get_bloginfo('description');
        $org_type = array_key_exists($s['org_type'] ?? '', self::org_types()) ? $s['org_type'] : 'LocalBusiness';

        $place = [
            '@type'       => $org_type,
            '@id'         => $home_url . '#place',
            'name'        => $site_name,
            'url'         => $home_url,
            'description' => $description,
        ];
        if ($address = $this->build_address($s)) {
            $place['address'] = $address;
        }
        if ($s['telephone']) {
            $place['telephone'] = $s['telephone'];
        }
        if ($s['email']) {
            $place['email'] = $s['email'];
        }
        if ($s['favicon_url']) {
            $place['image'] = (strpos($s['favicon_url'], 'http') === 0)
                ? $s['favicon_url']
                : untrailingslashit(get_option('home')) . $s['favicon_url'];
        }
        if ($geo = $this->build_geo($s)) {
            $place['geo'] = $geo;
        }
        if (!empty($s['price_range'])) {
            $place['priceRange'] = $s['price_range'];
        }
        $amenities = self::linie($s['amenities']);
        if (!empty($amenities)) {
            $place['amenityFeature'] = array_map(static function ($name) {
                return [
                    '@type' => 'LocationFeatureSpecification',
                    'name'  => $name,
                    'value' => true,
                ];
            }, $amenities);
        }
        if (!empty($s['has_map'])) {
            $place['hasMap'] = $s['has_map'];
        }
        $hours = $this->parse_opening_hours((string) $s['opening_hours']);
        if (!empty($hours)) {
            $place['openingHoursSpecification'] = $hours;
        }
        $areas = self::linie($s['area_served']);
        if (!empty($areas)) {
            $place['areaServed'] = $areas;
        }
        // Powiązanie z wydawcą strony
        if (!empty($s['block_org'])) {
            $place['parentOrganization'] = ['@id' => $home_url . '#organization'];
        }

        /* Pola wspólne dla wszystkich branż (1.174.0). Bez bramki presetu —
           każde istnieje na `Place` albo `LocalBusiness`, więc jest prawidłowe
           przy dowolnym typie działalności. */
        if ($swieta = self::parse_special_hours($s['place_special_hours'] ?? '')) {
            $place['specialOpeningHoursSpecification'] = $swieta;
        }
        if (!empty($s['place_currencies'])) $place['currenciesAccepted'] = $s['place_currencies'];
        if ($platnosci = self::linie($s['place_payment'] ?? '')) {
            // paymentAccepted to Text — lista idzie przecinkami, nie tablicą.
            $place['paymentAccepted'] = implode(', ', $platnosci);
        }
        foreach (['place_public' => 'publicAccess',
                  'place_free'   => 'isAccessibleForFree',
                  'place_smoking'=> 'smokingAllowed'] as $klucz => $wlasciwosc) {
            $v = self::trojstan($s[$klucz] ?? '');
            if ($v !== null) $place[$wlasciwosc] = $v;
        }
        if (!empty($s['place_branch'])) $place['branchCode'] = $s['place_branch'];
        if (($s['place_capacity'] ?? '') !== '') {
            $place['maximumAttendeeCapacity'] = (int) $s['place_capacity'];
        }
        if ($zdjecia = self::linie($s['place_photos'] ?? '')) {
            $place['photo'] = array_map(static function ($url) {
                return ['@type' => 'ImageObject', 'url' => $url];
            }, $zdjecia);
        }
        if (!empty($s['place_fax'])) $place['faxNumber'] = $s['place_fax'];

        /* Pola branżowe — emitowane wyłącznie przy pasującym presecie.
           Ta bramka jest powodem, dla którego presety w ogóle istnieją. */
        $preset = self::preset_dla_typu($org_type);

        if ($preset === 'noclegi') {
            if (!empty($s['place_checkin']))  $place['checkinTime']  = $s['place_checkin'];
            if (!empty($s['place_checkout'])) $place['checkoutTime'] = $s['place_checkout'];
            if ($s['place_rooms'] !== '') {
                $place['numberOfRooms'] = [
                    '@type' => 'QuantitativeValue',
                    'value' => (int) $s['place_rooms'],
                ];
            }
            // `false` znaczy „zwierzęta zabronione" i jest deklaracją —
            // dlatego trójstan, a nie checkbox (patrz self::trojstan()).
            $pets = self::trojstan($s['place_pets'] ?? '');
            if ($pets !== null) $place['petsAllowed'] = $pets;
            if ($jezyki = self::linie($s['place_languages'] ?? '')) {
                $place['availableLanguage'] = $jezyki;
            }
        }

        if ($preset === 'gastronomia') {
            if ($kuchnie = self::linie($s['place_cuisine'] ?? '')) {
                $place['servesCuisine'] = $kuchnie;
            }
            if (!empty($s['place_menu'])) $place['hasMenu'] = $s['place_menu'];
            foreach (['place_reservations' => 'acceptsReservations',
                      'place_drive_thru'   => 'hasDriveThroughService'] as $klucz => $wlasciwosc) {
                $v = self::trojstan($s[$klucz] ?? '');
                if ($v !== null) $place[$wlasciwosc] = $v;
            }
        }

        // Jedyne pole branżowe wspólne dla dwóch presetów — starRating istnieje
        // i na LodgingBusiness, i na FoodEstablishment.
        if (in_array($preset, ['noclegi', 'gastronomia'], true) && $s['place_stars'] !== '') {
            $place['starRating'] = [
                '@type'       => 'Rating',
                'ratingValue' => (int) $s['place_stars'],
            ];
        }

        if ($preset === 'zdrowie') {
            if ($specjalizacje = self::linie($s['place_specialty'] ?? '')) {
                $place['medicalSpecialty'] = $specjalizacje;
            }
        }

        return $place;
    }

    private function build_attraction(array $s, string $home_url, string $lang): array {
        $name = $s['attraction_name'] ?: ($s['site_name'] ?: get_bloginfo('name'));
        $descriptions = json_decode($s['descriptions'], true) ?: [];
        $description  = $descriptions[$lang] ?? $descriptions['pl'] ?? '';

        $att = [
            '@type' => 'TouristAttraction',
            '@id'   => $home_url . '#attraction',
            'name'  => $name,
            'url'   => $home_url,
        ];
        if ($description) {
            $att['description'] = $description;
        }
        if ($address = $this->build_address($s)) {
            $att['address'] = $address;
        }
        if ($geo = $this->build_geo($s)) {
            $att['geo'] = $geo;
        }
        if ($s['favicon_url']) {
            $att['image'] = (strpos($s['favicon_url'], 'http') === 0)
                ? $s['favicon_url']
                : untrailingslashit(get_option('home')) . $s['favicon_url'];
        }
        /* Pola atrakcji (1.174.0). `touristType` jest jedyną właściwością
           swoistą dla TouristAttraction; reszta pochodzi z Place, więc jest
           prawidłowa także tutaj. */
        if ($grupy = self::linie($s['attr_tourist_type'] ?? '')) {
            $att['touristType'] = $grupy;
        }
        if ($jezyki = self::linie($s['attr_languages'] ?? '')) {
            $att['availableLanguage'] = $jezyki;
        }
        /* Atrakcja bywa czynna inaczej niż obiekt — plaża od maja do września,
           gdy recepcja cały rok. Dlatego własne godziny, a nie dziedziczone
           z #place; puste pole zostawia atrakcję bez godzin, tak jak dotąd. */
        if ($godziny = $this->parse_opening_hours((string) ($s['attr_hours'] ?? ''))) {
            $att['openingHoursSpecification'] = $godziny;
        }
        foreach (['attr_public' => 'publicAccess',
                  'attr_free'   => 'isAccessibleForFree'] as $klucz => $wlasciwosc) {
            $v = self::trojstan($s[$klucz] ?? '');
            if ($v !== null) $att[$wlasciwosc] = $v;
        }

        // Powiązanie z węzłem miejsca (#place), jeśli istnieje
        if ($this->has_place($s)) {
            $att['containedInPlace'] = ['@id' => $home_url . '#place'];
        }
        return $att;
    }
    /**
     * Podrzędne obiekty/usługi (repeater) — osobne węzły Place powiązane
     * z fizycznym obiektem (#place) przez containedInPlace. Gdy nie ma
     * węzła #place, stoją samodzielnie (bez powiązania — Organization nie
     * jest miejscem, więc containedInPlace byłoby nieprawidłowe).
     */
    private function build_sub_entities(array $s, string $home_url): array {
        $raw = json_decode($s['sub_entities'] ?? '[]', true);
        if (!is_array($raw) || empty($raw)) return [];
        $allowed   = self::sub_entity_types();
        $parent_id = $this->has_place($s) ? $home_url . '#place' : '';
        $out = [];
        $i   = 0;
        foreach ($raw as $entry) {
            $type = $entry['type'] ?? '';
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '' || !array_key_exists($type, $allowed)) continue;
            $i++;
            $node = [
                '@type' => $type,
                '@id'   => $home_url . '#entity-' . $i,
                'name'  => $name,
            ];
            if (!empty($entry['description'])) {
                $node['description'] = (string) $entry['description'];
            }
            // Pola dołożone w 1.174.0 — każde tylko wtedy, gdy wypełnione.
            foreach (['url' => 'url', 'telephone' => 'telephone', 'image' => 'image'] as $k => $w) {
                if (!empty($entry[$k])) $node[$w] = (string) $entry[$k];
            }
            if ($parent_id) {
                $node['containedInPlace'] = ['@id' => $parent_id];
            }
            $out[] = $node;
        }
        return $out;
    }
    // ================================================================
    // HELPERS
    // ================================================================
    /** Adres pocztowy — pusty gdy brak ulicy i miejscowości. */
    /**
     * Pole wieloliniowe → lista niepustych, przyciętych pozycji.
     *
     * Ten sam podział stał wcześniej wpisany z ręki w trzech miejscach
     * (`amenities`, `area_served` i przy godzinach). Przy polach dokładanych
     * w 1.172.0 byłoby ich siedem — a każda kopia to osobna szansa na
     * pominięcie `array_filter` i wpuszczenie do grafu pustej pozycji z pustej
     * linii, czego JSON-LD nie zgłasza w żaden sposób.
     */
    private static function linie($raw): array {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', (string) $raw) ?: []
        ), static function ($linia) { return $linia !== ''; }));
    }

    /**
     * Wartość trójstanowa → `true`/`false` albo `null` (nie emitować).
     *
     * `false` to DEKLARACJA („u nas się nie pali"), a nie brak zdania — i tym
     * różni się od pustego. Bez tego rozróżnienia jedyne, co można powiedzieć,
     * to „palenie dozwolone albo nie wiadomo".
     */
    private static function trojstan($wartosc): ?bool {
        if ($wartosc === '' || $wartosc === null) return null;
        if ($wartosc === '1' || $wartosc === 1 || $wartosc === true)  return true;
        if ($wartosc === '0' || $wartosc === 0 || $wartosc === false) return false;
        return null;
    }

    /**
     * Godziny świąteczne i przerwy → specialOpeningHoursSpecification.
     *
     * Jedna linia = jedna reguła:
     *   2026-12-24 zamknięte
     *   2026-12-24..2026-12-26 zamknięte
     *   2026-12-31 09:00-14:00
     *
     * „Zamknięte" wychodzi jako `opens` i `closes` równe `00:00` — tak Google
     * dokumentuje dzień bez otwarcia. Bez tej konwencji dzień zamknięty jest
     * nie do odróżnienia od dnia, o którym nic nie powiedziano.
     */
    private static function parse_special_hours($raw): array {
        $specs = [];
        foreach (self::linie($raw) as $linia) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s*\.\.\s*(\d{4}-\d{2}-\d{2}))?\s+(.+)$/u', trim($linia), $m)) {
                continue;
            }
            $od = $m[1];
            $do = $m[2] ?: $m[1];
            $reszta = trim($m[3]);

            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)\s*[-–—]\s*([01]\d|2[0-3]):([0-5]\d)$/u', $reszta, $g)) {
                $opens  = $g[1] . ':' . $g[2];
                $closes = $g[3] . ':' . $g[4];
            } else {
                // Cokolwiek innego traktujemy jako „zamknięte" — pole przyjmuje
                // słowo od klienta („zamknięte", „nieczynne", „closed"),
                // a lista dopuszczalnych słów byłaby pułapką na literówkę.
                $opens = $closes = '00:00';
            }

            $specs[] = [
                '@type'       => 'OpeningHoursSpecification',
                'validFrom'   => $od,
                'validThrough' => $do,
                'opens'       => $opens,
                'closes'      => $closes,
            ];
        }
        return $specs;
    }

    private function build_address(array $s): array {
        if (trim((string) $s['street_address']) === '' && trim((string) $s['locality']) === '') {
            return [];
        }
        return [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $s['street_address'],
            'addressLocality' => $s['locality'],
            'postalCode'      => $s['postal_code'],
            'addressCountry'  => $s['country'],
        ];
    }
    private function build_geo(array $s): array {
        if ($s['geo_lat'] === '' || $s['geo_lng'] === '') return [];
        return [
            '@type'     => 'GeoCoordinates',
            'latitude'  => $s['geo_lat'],
            'longitude' => $s['geo_lng'],
        ];
    }
    /** Czy ustawienia definiują osobny węzeł miejsca (#place)? */
    private function has_place(array $s): bool {
        $type = $s['org_type'] ?? 'Organization';
        return $type !== 'Organization' && array_key_exists($type, self::org_types());
    }
    /**
     * Parsuje godziny otwarcia na OpeningHoursSpecification.
     * Jedna linia = jedna reguła: "Pn-Pt 08:00-20:00", "Sob 09:00-14:00",
     * "Codziennie 08:00-20:00". Dni po polsku lub angielsku (skróty).
     */
    private function parse_opening_hours(string $raw): array {
        $days_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $map = [
            'pn' => 'Monday',    'pon' => 'Monday',    'poniedzialek' => 'Monday',
            'wt' => 'Tuesday',   'wto' => 'Tuesday',   'wtorek'       => 'Tuesday',
            'sr' => 'Wednesday', 'sro' => 'Wednesday', 'sroda'        => 'Wednesday',
            'cz' => 'Thursday',  'czw' => 'Thursday',  'czwartek'     => 'Thursday',
            'pt' => 'Friday',    'pia' => 'Friday',    'piatek'       => 'Friday',
            'so' => 'Saturday',  'sob' => 'Saturday',  'sobota'       => 'Saturday',
            'nd' => 'Sunday',    'ndz' => 'Sunday',    'nie' => 'Sunday', 'niedziela' => 'Sunday',
            'mo' => 'Monday', 'tu' => 'Tuesday', 'we' => 'Wednesday', 'th' => 'Thursday',
            'fr' => 'Friday', 'sa' => 'Saturday', 'su' => 'Sunday',
        ];
        $resolve = static function (string $token) use ($map): string {
            foreach ([$token, mb_substr($token, 0, 3), mb_substr($token, 0, 2)] as $key) {
                if (isset($map[$key])) return $map[$key];
            }
            return '';
        };
        $specs = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Godziny na końcu linii: 8:00-20:00 / 08.00–20.00
            if (!preg_match('/(\d{1,2})[:.](\d{2})\s*[-–—]\s*(\d{1,2})[:.](\d{2})\s*$/u', $line, $t)) continue;
            $opens  = sprintf('%02d:%s', (int) $t[1], $t[2]);
            $closes = sprintf('%02d:%s', (int) $t[3], $t[4]);
            $days_part = trim(mb_substr($line, 0, mb_strlen($line) - mb_strlen($t[0])));
            $norm = str_replace(
                ['ś', 'ó', 'ą', 'ę', 'ł', 'ż', 'ź', 'ć', 'ń', '.'],
                ['s', 'o', 'a', 'e', 'l', 'z', 'z', 'c', 'n', ''],
                mb_strtolower($days_part)
            );
            $days = [];
            if ($norm === '' || strpos($norm, 'codzien') !== false || strpos($norm, 'daily') !== false) {
                $days = $days_order;
            } else {
                foreach (explode(',', $norm) as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk === '') continue;
                    if (preg_match('/^([a-z]+)\s*[-–—]\s*([a-z]+)$/u', $chunk, $r)) {
                        $from = array_search($resolve($r[1]), $days_order, true);
                        $to   = array_search($resolve($r[2]), $days_order, true);
                        if ($from === false || $to === false) continue;
                        if ($from <= $to) {
                            $days = array_merge($days, array_slice($days_order, $from, $to - $from + 1));
                        } else { // zakres przez niedzielę, np. Sob-Pn
                            $days = array_merge($days, array_slice($days_order, $from), array_slice($days_order, 0, $to + 1));
                        }
                    } elseif ($day = $resolve($chunk)) {
                        $days[] = $day;
                    }
                }
            }
            $days = array_values(array_intersect($days_order, array_unique($days)));
            if (empty($days)) continue;
            $specs[] = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => $days,
                'opens'     => $opens,
                'closes'    => $closes,
            ];
        }
        return $specs;
    }
	private function get_available_languages(): array {
    // Zawsze dodaj polski
    $langs = ['Polish'];

    // Mapowanie kodów języków na pełne nazwy w języku angielskim (schema.org)
    $lang_names = [
        'en' => 'English',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
        'it' => 'Italian',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'ru' => 'Russian',
        'uk' => 'Ukrainian',
        'lt' => 'Lithuanian',
        'lv' => 'Latvian',
        'et' => 'Estonian',
        'sv' => 'Swedish',
        'no' => 'Norwegian',
        'da' => 'Danish',
        'fi' => 'Finnish',
        'hu' => 'Hungarian',
        'ro' => 'Romanian',
        'bg' => 'Bulgarian',
        'hr' => 'Croatian',
        'sr' => 'Serbian',
        'sl' => 'Slovenian',
        'tr' => 'Turkish',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
    ];

    if (function_exists('tl_get_languages')) {
        foreach (array_keys(tl_get_languages()) as $code) {
            $code = strtolower(trim($code));
            if (isset($lang_names[$code]) && !in_array($lang_names[$code], $langs, true)) {
                $langs[] = $lang_names[$code];
            }
        }
    }

    return $langs;
}
	
    private function home_url(string $lang): string {
        $base = untrailingslashit(get_option('home'));
        return ($lang === 'pl') ? $base . '/' : $base . '/' . $lang . '/';
    }
    private function auto_detect_socials(): array {
        $detected  = [];
        $locations = get_nav_menu_locations();
        $menu_id   = $locations['main'] ?? ($locations['primary'] ?? 0);
        if ($menu_id) {
            $items = wp_get_nav_menu_items($menu_id);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (preg_match('/(facebook\.com|instagram\.com|linkedin\.com|twitter\.com|youtube\.com)/i', $item->url)) {
                        $detected[] = esc_url($item->url);
                    }
                }
            }
        }
        return array_values(array_unique($detected));
    }
}
EVK_Schema::get_instance();