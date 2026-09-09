<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Wyjście JSON-LD modułu Schema, wyprodukowane przez PRAWDZIWE `render_graph()`.
 *
 * PO CO OSOBNY HARNESS, SKORO `tab.php` UMIE JUŻ ZAKŁADKĘ SCHEMA: zakładka to
 * formularz, a graf to jedyna rzecz, którą moduł naprawdę wystawia światu —
 * leci w <head> KAŻDEJ podstrony. Do 1.161.1 nie sprawdzało go nic: w 51
 * plikach testowych nie występowało ani `@graph`, ani `application/ld+json`.
 * Przebudowa zakładki bez tego pliku byłaby przepisywaniem 893 linii na ślepo.
 *
 * Moduł ładujemy PRAWDZIWY. Atrapą jest wyłącznie WordPress dookoła — i to
 * atrapą Z DANYMI, nie pustą: węzeł, którego nie ma czym wypełnić, nie odróżnia
 * „pole nie wyszło" od „pola nie było".
 *
 * Argument 1: nazwa scenariusza (patrz $scenariusze niżej). Bez argumentu —
 * wypisuje listę i kończy się kodem 1.
 */
require __DIR__ . '/_wp-stubs.php';

// ── Atrapy WP, których nie ma we wspólnym pliku ─────────────────────────────
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function get_bloginfo($what = '') {
    $map = [
        'name'        => 'Witryna testowa',
        'description' => 'Opis z ustawień WordPressa',
        'language'    => 'pl-PL',
    ];
    return $map[$what] ?? '';
}

/*
 * Wpisy trzymamy jako WP_Post, bo `build_breadcrumbs()` i `build_article()`
 * mają go w sygnaturze jako TYP. Atrapa na stdClass przechodzi cicho tylko
 * dopóki ktoś nie doda deklaracji typu — a tu już jest.
 */
class WP_Post {
    public $ID = 0;
    public $post_author = 1;
    public $post_type = 'page';
    public $post_title = '';
    public $post_name = '';
    public $post_parent = 0;
    public $post_date = '2026-01-15T10:00:00+01:00';
    public $post_modified = '2026-02-03T18:30:00+01:00';
    public function __construct(array $pola = []) {
        foreach ($pola as $k => $v) $this->$k = $v;
    }
}

$GLOBALS['strony']      = [];   // ID => WP_Post
$GLOBALS['permalinki']  = [];   // ID => adres
$GLOBALS['kategorie']   = [];   // ID => [nazwy]
$GLOBALS['seo_meta']    = [];   // ID => ['title'=>..,'desc'=>..,'og_image'=>..]
$GLOBALS['menu_items']  = [];
$GLOBALS['lang']        = 'pl';
$GLOBALS['jest_produkt'] = false;

function get_permalink($id = 0) { return $GLOBALS['permalinki'][(int) $id] ?? ''; }
function get_the_title($id = 0) { return $GLOBALS['strony'][(int) $id]->post_title ?? ''; }
function get_post_type($post = null) { return $GLOBALS['strony'][(int) $GLOBALS['current_post']]->post_type ?? ''; }
function get_post_ancestors($id = 0) {
    $out = [];
    $p = $GLOBALS['strony'][(int) $id] ?? null;
    while ($p && $p->post_parent && isset($GLOBALS['strony'][$p->post_parent])) {
        $out[] = $p->post_parent;
        $p = $GLOBALS['strony'][$p->post_parent];
    }
    return $out;   // od najbliższego rodzica — tak jak w WordPressie
}
function is_singular($types = '') { return !empty($GLOBALS['current_post']); }
function is_single($p = '') {
    return !empty($GLOBALS['current_post'])
        && ($GLOBALS['strony'][(int) $GLOBALS['current_post']]->post_type ?? '') === 'post';
}
function get_the_date($format = '', $post = null) { return $post->post_date ?? ''; }
function get_the_modified_date($format = '', $post = null) { return $post->post_modified ?? ''; }
function get_the_excerpt($id = 0) { return $GLOBALS['strony'][(int) $id]->excerpt ?? ''; }
function get_the_category($id = 0) {
    return array_map(
        static function ($n) { return (object) ['name' => $n]; },
        $GLOBALS['kategorie'][(int) $id] ?? []
    );
}
function get_author_posts_url($id) { return 'https://example.test/autor/' . (int) $id . '/'; }
function wp_strip_all_tags($s, $br = false) { return trim(strip_tags((string) $s)); }

/*
 * Łańcuch źródeł tytułu i opisu jest PRAWDZIWY, nie skrócony: `build_webpage()`
 * pyta `evk_seo_get_meta()` i dopiero po pustej odpowiedzi schodzi do tytułu
 * wpisu i nazwy witryny. Atrapa oddająca zawsze komplet wycinała dwa z trzech
 * ogniw i fallbacki były w testach nieosiągalne.
 */
function evk_seo_get_meta($id) {
    return ($GLOBALS['seo_meta'][(int) $id] ?? []) + ['title' => '', 'desc' => '', 'og_image' => ''];
}

function get_current_lang() { return $GLOBALS['lang']; }
function tl_get_languages() { return $GLOBALS['jezyki'] ?? []; }
$GLOBALS['jezyki'] = [];

function get_nav_menu_locations() { return $GLOBALS['menu_items'] ? ['main' => 7] : []; }
function wp_get_nav_menu_items($id) {
    return array_map(static function ($u) { return (object) ['url' => $u]; }, $GLOBALS['menu_items']);
}

/*
 * WooCommerce jest ZAWSZE zadeklarowany, a o stronę produktu pyta flaga.
 * Dzięki temu „Woo zainstalowane, ale to nie jest produkt" jest osiągalnym
 * przypadkiem — a to on, a nie brak wtyczki, obowiązuje na 99% podstron sklepu.
 */
function is_product() { return (bool) $GLOBALS['jest_produkt']; }
function get_woocommerce_currency() { return 'PLN'; }
function wp_get_attachment_url($id) { return $GLOBALS['attachments'][(int) $id] ?? ''; }
function wc_get_product($id) {
    if (empty($GLOBALS['produkt'])) return null;
    return new class($GLOBALS['produkt']) {
        private $d;
        public function __construct($d) { $this->d = $d; }
        public function get_name() { return $this->d['name']; }
        public function get_short_description() { return $this->d['short']; }
        public function get_description() { return $this->d['long']; }
        public function get_sku() { return $this->d['sku']; }
        public function get_image_id() { return $this->d['image_id']; }
        public function get_price() { return $this->d['price']; }
        public function is_in_stock() { return $this->d['in_stock']; }
    };
}

// ── Scenariusze ────────────────────────────────────────────────────────────
// Każdy ustawia $GLOBALS['options']['evk_schema'] i kontekst strony.
// Nazwy pól są takie, jak w $defaults modułu — wp_parse_args dopełnia resztę.

$scenariusze = [

    /* Świeża instalacja: moduł włączony, nic nie wypełnione, strona główna.
       Pilnuje, żeby pusta konfiguracja nie drukowała śmieci — pustych nazw,
       węzłów bez treści, `null` w JSON-ie. */
    'minimalny' => function () {
        $GLOBALS['options']['evk_schema'] = ['enabled' => 1];
    },

    /* Komplet danych firmy lokalnej. Jedyny scenariusz, w którym powstaje
       węzeł #place — czyli rozdział „wydawca strony" / „fizyczny obiekt",
       na którym stoi cała reszta grafu. */
    'firma' => function () {
        $GLOBALS['jezyki'] = ['en' => ['name' => 'English'], 'de' => ['name' => 'Deutsch']];
        $GLOBALS['options']['evk_schema'] = [
            'enabled'        => 1,
            'org_type'       => 'LodgingBusiness',
            'site_name'      => 'Ośrodek Nad Jeziorem',
            'operator_name'  => 'Fundacja Wypoczynek',
            'telephone'      => '+48 111 222 333',
            'email'          => 'kontakt@przyklad.test',
            'contact_type'   => 'reservations',
            'street_address' => 'Leśna 4',
            'locality'       => 'Mikołajki',
            'postal_code'    => '11-730',
            'country'        => 'PL',
            'favicon_url'    => '/wp-content/uploads/logo.png',
            'geo_lat'        => '53.8021',
            'geo_lng'        => '21.5731',
            'price_range'    => '$$',
            'has_map'        => 'https://maps.example.test/x',
            // Trzy zapisy dni naraz: zakres, lista i „codziennie". Jeden
            // wystarczyłby na „parser działa", ale nie na „działa dla zapisu,
            // który klient wpisze naprawdę".
            'opening_hours'  => "Pn-Pt 08:00-20:00\nSob, Nd 09:00-14:00\nCodziennie 22:00-23:00",
            'amenities'      => "Wi-Fi\nParking\n",
            'area_served'    => "Mazury\nWarmia",
            'social_links'   => '["https://facebook.example.test/o","https://instagram.example.test/o"]',
            'descriptions'   => '{"pl":"Opis po polsku","en":"English description"}',
        ];
    },

    /* Ten sam obiekt widziany po angielsku. Osobny scenariusz, bo język
       zmienia DWIE rzeczy naraz — adres bazowy (#organization żyje pod
       /en/) i wybór opisu — a przy jednym wspólnym pliku wzorcowym nie
       dałoby się powiedzieć, która z nich się zepsuła. */
    'firma-en' => function () use (&$scenariusze) {
        $scenariusze['firma']();
        $GLOBALS['lang'] = 'en';
    },

    /* Atrakcja turystyczna i encje podrzędne — jedyne miejsce, gdzie graf
       buduje @id z licznika (#entity-1, #entity-2) i wiąże węzły przez
       containedInPlace. Dwa wiersze repeatera są celowo do odrzucenia. */
    'atrakcja' => function () {
        $GLOBALS['options']['evk_schema'] = [
            'enabled'         => 1,
            'org_type'        => 'Campground',
            'site_name'       => 'Ośrodek Nad Jeziorem',
            'street_address'  => 'Leśna 4',
            'locality'        => 'Mikołajki',
            'postal_code'     => '11-730',
            'geo_lat'         => '53.8021',
            'geo_lng'         => '21.5731',
            'block_attraction' => 1,
            'attraction_name' => 'Półwysep nad jeziorem',
            'descriptions'    => '{"pl":"Opis po polsku"}',
            'sub_entities'    => json_encode([
                ['type' => 'Beach',            'name' => 'Plaża główna', 'description' => 'Piaszczysta'],
                ['type' => 'ParkingFacility',  'name' => 'Parking'],
                // Odrzucane: typ spoza sub_entity_types() i wiersz bez nazwy.
                // Numeracja @id musi je POMINĄĆ, nie zostawić dziury.
                ['type' => 'Service',          'name' => 'Spływy'],
                ['type' => 'Playground',       'name' => '   '],
                ['type' => 'Restaurant',       'name' => 'Karczma'],
            ]),
        ];
    },

    /* Podstrona: WebPage + okruszki z rodzicem. Tytuł i opis idą przez
       zakładkę SEO, nie przez tytuł wpisu — to inne ogniwo łańcucha. */
    'podstrona' => function () {
        $GLOBALS['options']['evk_schema'] = [
            'enabled'   => 1,
            'site_name' => 'Witryna testowa',
        ];
        $GLOBALS['strony'][10] = new WP_Post(['ID' => 10, 'post_title' => 'Oferta']);
        $GLOBALS['strony'][11] = new WP_Post(['ID' => 11, 'post_title' => 'Domki', 'post_parent' => 10]);
        $GLOBALS['permalinki'] = [
            10 => 'https://example.test/oferta/',
            11 => 'https://example.test/oferta/domki/',
        ];
        $GLOBALS['seo_meta'][11] = [
            'title'    => 'Domki nad jeziorem — oferta',
            'desc'     => 'Opis z zakładki SEO',
            'og_image' => 'https://example.test/img/domki.jpg',
        ];
        $GLOBALS['current_post'] = 11;
    },

    /* Wpis bloga: BlogPosting z autorem, datami i kategoriami. Meta Bricksa
       niesie akordeon w kształcie, jakiego szuka extract_faq(). */
    'wpis' => function () {
        $GLOBALS['options']['evk_schema'] = [
            'enabled'   => 1,
            'site_name' => 'Witryna testowa',
        ];
        $GLOBALS['strony'][21] = new WP_Post([
            'ID' => 21, 'post_type' => 'post', 'post_title' => 'Sezon otwarty',
            'excerpt' => 'Zapraszamy od maja.',
        ]);
        $GLOBALS['permalinki'][21] = 'https://example.test/blog/sezon-otwarty/';
        $GLOBALS['kategorie'][21]  = ['Aktualności', 'Wydarzenia'];
        $GLOBALS['post_meta'][21]['_bricks_page_data'] = [
            ['name' => 'accordion', 'settings' => ['items' => [
                ['title' => 'Czy jest parking?', 'content' => '<p>Tak, bezpłatny.</p>'],
                ['title' => 'Od kiedy sezon?',   'content' => 'Od 1 maja.'],
            ]]],
        ];
        $GLOBALS['current_post'] = 21;
    },

    /* Produkt WooCommerce. Waluta idzie z mapy per język, nie z Woo —
       i tylko tu widać, że mapa w ogóle działa. */
    'produkt' => function () {
        $GLOBALS['options']['evk_schema'] = [
            'enabled'         => 1,
            'site_name'       => 'Witryna testowa',
            'lang_currencies' => '{"en":"EUR","de":"EUR"}',
        ];
        $GLOBALS['lang'] = 'en';
        $GLOBALS['strony'][31] = new WP_Post(['ID' => 31, 'post_type' => 'product', 'post_title' => 'Kajak']);
        $GLOBALS['permalinki'][31] = 'https://example.test/en/sklep/kajak/';
        $GLOBALS['current_post'] = 31;
        $GLOBALS['jest_produkt'] = true;
        $GLOBALS['attachments'][55] = 'https://example.test/img/kajak.jpg';
        $GLOBALS['produkt'] = [
            'name' => 'Kajak dwuosobowy', 'short' => 'Wypożyczenie na dobę',
            'long' => 'Opis długi', 'sku' => 'KAJ-2', 'image_id' => 55,
            'price' => '120.00', 'in_stock' => true,
        ];
    },

    /* WebSite włączony, Organization odhaczony — układ osiągalny jednym
       kliknięciem w panelu. Interesuje nas, czy `publisher` nie zostaje
       wskazaniem na węzeł, którego w grafie nie ma.

       WPIS, a nie strona, i to celowo: `publisher` ustawiają DWA węzły —
       WebSite i BlogPosting — a druga z nich powstaje wyłącznie na wpisie.
       Pierwsza wersja tego scenariusza była stroną i pokazywała jedno
       wiszące wskazanie zamiast dwóch, więc naprawa jednego miejsca
       wyglądałaby na komplet. */
    'bez-org' => function () {
        $GLOBALS['options']['evk_schema'] = [
            'enabled' => 1, 'site_name' => 'Ośrodek', 'block_org' => 0,
            'org_type' => 'Hotel', 'street_address' => 'Leśna 4', 'locality' => 'Mikołajki',
        ];
        $GLOBALS['strony'][10] = new WP_Post([
            'ID' => 10, 'post_type' => 'post', 'post_title' => 'Oferta',
        ]);
        $GLOBALS['permalinki'][10] = 'https://example.test/oferta/';
        $GLOBALS['current_post'] = 10;
    },

    /* Ten sam wpis z akordeonem, ale z odhaczonym blokiem FAQPage.
       Kontrola do naprawy FAQ: „węzeł powstaje" przechodzi także wtedy,
       gdy powstaje bez względu na ustawienie. */
    'faq-off' => function () use (&$scenariusze) {
        $scenariusze['wpis']();
        $GLOBALS['options']['evk_schema']['block_faq'] = 0;
    },

    /* KONTROLA NEGATYWNA 1 — moduł wyłączony. Ma nie wyjść NIC.
       Bez niej „graf się drukuje" i „graf się drukuje zawsze" to jedno
       sprawdzenie, a różnica między nimi to cudze dane w cudzym <head>. */
    'wylaczony' => function () {
        $GLOBALS['options']['evk_schema'] = ['enabled' => 0, 'site_name' => 'Ośrodek'];
    },

    /* KONTROLA NEGATYWNA 2 — moduł włączony, wszystkie bloki odhaczone.
       Graf wychodzi pusty, więc `render_graph()` ma się wycofać przed
       wydrukiem znacznika, a nie wypisać pusty @graph. */
    'bloki-off' => function () {
        $GLOBALS['options']['evk_schema'] = [
            'enabled' => 1, 'site_name' => 'Ośrodek',
            'block_website' => 0, 'block_org' => 0, 'block_breadcrumb' => 0,
            'block_webpage' => 0, 'block_article' => 0, 'block_faq' => 0,
            'block_product' => 0, 'block_attraction' => 0,
        ];
    },
];

// ── Uruchomienie ───────────────────────────────────────────────────────────
$nazwa = $argv[1] ?? '';
if (!isset($scenariusze[$nazwa])) {
    fwrite(STDERR, "Scenariusze: " . implode(', ', array_keys($scenariusze)) . "\n");
    exit(1);
}

$GLOBALS['options']['home'] = 'https://example.test';
$scenariusze[$nazwa]();

require dirname(__DIR__, 2) . '/includes/90-schema.php';

global $post;
$post = $GLOBALS['strony'][(int) $GLOBALS['current_post']] ?? null;

EVK_Schema::get_instance()->render_graph();
