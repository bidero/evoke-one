<?php
if (!defined('ABSPATH')) exit;
/**
 * EVOKE One — Moduł Schema.org (JSON-LD @graph)
 */
class EVK_Schema {
    private static $instance = null;
    // ----------------------------------------------------------------
    // Domyślne ustawienia
    // ----------------------------------------------------------------
    private $defaults = [
        'enabled'          => 1,
        // Dane organizacji
        'org_type'         => 'Organization',
        'operator_name'    => '',   // wydawca strony (Organization); puste = site_name
        'site_name'        => '',
        'telephone'        => '',
        'email'            => '',
        'street_address'   => '',
        'locality'         => '',
        'postal_code'      => '',
        'country'          => 'PL',
        'favicon_url'      => '',
        'contact_type'     => 'customer service',
        // Dane firmy lokalnej / miejsca (typy LocalBusiness i pochodne → węzeł #place)
        'geo_lat'          => '',
        'geo_lng'          => '',
        'price_range'      => '',
        'amenities'        => '',   // jedna linia = jedno udogodnienie (amenityFeature)
        'has_map'          => '',   // URL do Map Google (hasMap)
        'opening_hours'    => '',   // jedna linia = jedna reguła, np. "Pn-Pt 08:00-20:00"
        'area_served'      => '',   // jedna linia = jeden obszar (areaServed)
        // TouristAttraction
        'attraction_name'  => '',
        // Social sameAs (JSON array string)
        'social_links'     => '',
        // Opisy per język (JSON string: {"pl":"...","en":"...","de":"..."})
        'descriptions'     => '{}',
        // Flagi włączające poszczególne bloki
        'block_website'    => 1,
        'block_org'        => 1,
        'block_breadcrumb' => 1,
        'block_webpage'    => 1,
        'block_article'    => 1,
        'block_faq'        => 1,
        'block_product'    => 1,
        'block_attraction' => 0,
        // Dodatkowe obiekty/usługi podrzędne (JSON: [{"type":..,"name":..,"description":..}])
        'sub_entities'     => '[]',
        // WooCommerce: lista walut per język (JSON: {"en":"EUR","de":"EUR"})
        'lang_currencies'  => '{"en":"EUR","de":"EUR"}',
    ];
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
    public function get_settings(): array {
        return wp_parse_args(get_option('evk_schema', []), $this->defaults);
    }
    public function register_settings(): void {
        register_setting('evoke_one_schema', 'evk_schema', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }
    public function sanitize_settings($input): array {
        $input = is_array($input) ? $input : [];
        $clean = [];
        // 'enabled' zarządzany przez AJAX toggle — zachowaj gdy brak w POST
        $clean['enabled'] = evk_preserve_toggle($input, 'evk_schema', 'enabled', 1);
        // Checkboxy
        $checkboxes = [
            'block_website', 'block_org', 'block_breadcrumb',
            'block_webpage', 'block_article', 'block_faq', 'block_product',
            'block_attraction',
        ];
        foreach ($checkboxes as $key) {
            $clean[$key] = !empty($input[$key]) ? 1 : 0;
        }
        // Typ działalności — tylko z listy dozwolonych
        $org_type = sanitize_text_field($input['org_type'] ?? 'Organization');
        $clean['org_type'] = array_key_exists($org_type, self::org_types()) ? $org_type : 'Organization';
        // Teksty jednoliniowe
        $texts = [
            'operator_name', 'site_name', 'telephone', 'email', 'street_address',
            'locality', 'postal_code', 'country', 'favicon_url', 'contact_type',
            'geo_lat', 'geo_lng', 'price_range', 'attraction_name',
        ];
        foreach ($texts as $key) {
            $clean[$key] = sanitize_text_field($input[$key] ?? '');
        }
        // Współrzędne — tylko liczby (kropka dziesiętna, opcjonalny minus)
        foreach (['geo_lat', 'geo_lng'] as $key) {
            $clean[$key] = str_replace(',', '.', $clean[$key]);
            if ($clean[$key] !== '' && !is_numeric($clean[$key])) $clean[$key] = '';
        }
        // Link do mapy
        $clean['has_map'] = esc_url_raw($input['has_map'] ?? '');
        // Pola wieloliniowe (jedna linia = jedna pozycja)
        foreach (['amenities', 'opening_hours', 'area_served'] as $key) {
            $clean[$key] = sanitize_textarea_field($input[$key] ?? '');
        }
        // JSON-y (opisy, social, waluty)
        foreach (['descriptions', 'social_links', 'lang_currencies'] as $key) {
            $raw = $input[$key] ?? '{}';
            json_decode($raw); // test poprawności
            $clean[$key] = (json_last_error() === JSON_ERROR_NONE) ? $raw : $this->defaults[$key];
        }
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
    $clean['descriptions'] = $input['descriptions'] ?? $this->defaults['descriptions'];
}
// Social links
if (isset($_POST['evk_schema_socials'])) {
    $lines = array_filter(array_map('esc_url_raw', explode("\n", wp_unslash($_POST['evk_schema_socials']))));
    $clean['social_links'] = wp_json_encode(array_values($lines));
} else {
    $clean['social_links'] = $input['social_links'] ?? $this->defaults['social_links'];
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
    $clean['lang_currencies'] = $input['lang_currencies'] ?? $this->defaults['lang_currencies'];
}
// Podrzędne obiekty/usługi (repeater — równoległe tablice type/name/description)
if (isset($_POST['evk_schema_sub']) && is_array($_POST['evk_schema_sub'])) {
    $sub_raw = wp_unslash($_POST['evk_schema_sub']);
    $types   = (array) ($sub_raw['type'] ?? []);
    $names   = (array) ($sub_raw['name'] ?? []);
    $descs   = (array) ($sub_raw['description'] ?? []);
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
        ];
    }
    $clean['sub_entities'] = wp_json_encode($subs, JSON_UNESCAPED_UNICODE);
} else {
    $clean['sub_entities'] = $input['sub_entities'] ?? $this->defaults['sub_entities'];
}
        return $clean;
    }
    // ================================================================
    // GENEROWANIE GRAFU
    // ================================================================
    public function render_graph(): void {
        if (is_admin()) return;
        if (function_exists('tl_is_bricks_editor') && tl_is_bricks_editor()) return;
        $s = $this->get_settings();
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
        if (is_singular()) {
            global $post;
            $permalink = get_permalink($post->ID);
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
                $graph[] = $this->build_webpage($post, $permalink, $home_url, $og_image);
            }
            // 5. Article / BlogPosting
            if (!empty($s['block_article']) && is_single() && get_post_type() === 'post') {
                $graph[] = $this->build_article($post, $permalink, $home_url, $og_image);
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

return [
    '@type'           => 'WebSite',
    '@id'             => $home_url . '#website',
    'url'             => $home_url,
    'name'            => $s['site_name'] ?: get_bloginfo('name'),
    'description'     => $description,
    'publisher'       => ['@id' => $home_url . '#organization'],
            'potentialAction' => [
                '@type'        => 'SearchAction',
                'target'       => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $home_url . '?s={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
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
        // ContactPoint
        if ($s['telephone']) {
            $org['contactPoint'] = [
                '@type'             => 'ContactPoint',
                'telephone'         => $s['telephone'],
                'contactType'       => $s['contact_type'] ?: 'customer service',
                'availableLanguage' => $this->get_available_languages(),

            ];
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
private function build_article(WP_Post $post, string $permalink, string $home_url, string $og_image): array {
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
        'breadcrumb'       => ['@id' => $permalink . '#breadcrumb'],
    ];

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

private function build_webpage(WP_Post $post, string $permalink, string $home_url, string $og_image): array {
    $s = $this->get_settings();

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
    private function extract_faq(int $post_id): array {
        $bricks_data = get_post_meta($post_id, '_bricks_page_data', true);
        if (!is_array($bricks_data)) return [];
        $faq = [];
        array_walk_recursive($bricks_data, function ($value, $key) use (&$faq) {
            if ($key === 'items' && is_array($value)) {
                foreach ($value as $item) {
                    $title   = $item['title']   ?? '';
                    $content = $item['content']  ?? '';
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
        });
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
        $amenities = array_values(array_filter(array_map('trim', explode("\n", (string) $s['amenities']))));
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
        $areas = array_values(array_filter(array_map('trim', explode("\n", (string) $s['area_served']))));
        if (!empty($areas)) {
            $place['areaServed'] = $areas;
        }
        // Powiązanie z wydawcą strony
        if (!empty($s['block_org'])) {
            $place['parentOrganization'] = ['@id' => $home_url . '#organization'];
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