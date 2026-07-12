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
        'site_name'        => '',
        'telephone'        => '',
        'email'            => '',
        'street_address'   => '',
        'locality'         => '',
        'postal_code'      => '',
        'country'          => 'PL',
        'favicon_url'      => '',
        'contact_type'     => 'customer service',
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
        ];
        foreach ($checkboxes as $key) {
            $clean[$key] = !empty($input[$key]) ? 1 : 0;
        }
        // Teksty jednoliniowe
        $texts = [
            'site_name', 'telephone', 'email', 'street_address',
            'locality', 'postal_code', 'country', 'favicon_url', 'contact_type',
        ];
        foreach ($texts as $key) {
            $clean[$key] = sanitize_text_field($input[$key] ?? '');
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
        // 2. Organization
        if (!empty($s['block_org'])) {
            $graph[] = $this->build_organization($s, $home_url, $lang);
        }
        // Bloki per-strona
        if (is_singular()) {
            global $post;
            $permalink = get_permalink($post->ID);
            $og_image  = function_exists('get_final_og_image_url') ? get_final_og_image_url() : '';
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
        // Opis per język
        $descriptions = json_decode($s['descriptions'], true) ?: [];
        $description  = $descriptions[$lang] ?? $descriptions['pl'] ?? get_bloginfo('description');
        // Języki dostępne (z modułu tłumaczeń lub fallback)
        $org = [
            '@type'        => 'Organization',
            '@id'          => $home_url . '#organization',
            'name'         => $site_name,
            'url'          => $home_url,
            'description'  => $description,
            'address'      => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $s['street_address'],
                'addressLocality' => $s['locality'],
                'postalCode'      => $s['postal_code'],
                'addressCountry'  => $s['country'],
            ],
        ];
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
                'caption' => $site_name,
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

    // Tytuł — fallback przez SEO meta lub nazwę strony
$bricks_settings = $bricks_settings ?? maybe_unserialize(get_post_meta($post->ID, '_bricks_page_settings', true));
$title = '';

if (!empty($bricks_settings['metaTitle'])) {
    $raw = $bricks_settings['metaTitle'];
    if (class_exists('\Bricks\Frontend') && method_exists('\Bricks\Frontend', 'render_dynamic_data')) {
        $title = \Bricks\Frontend::render_dynamic_data($raw, $post);
    } elseif (function_exists('bricks_render_dynamic_data')) {
        $title = bricks_render_dynamic_data($raw, $post);
    } else {
        $title = $raw;
    }
    $title = wp_strip_all_tags($title);
}

if (empty(trim($title))) {
    $title = get_the_title($post->ID);
}
if (empty(trim($title))) {
    $title = $s['site_name'] ?: get_bloginfo('name');
}

    // Opis — SEO meta description, potem excerpt, potem tagline
$description = '';

// Bricks Builder SEO meta description — z renderowaniem dynamic data
$bricks_settings = maybe_unserialize(get_post_meta($post->ID, '_bricks_page_settings', true));
if (!empty($bricks_settings['metaDescription'])) {
    $raw = $bricks_settings['metaDescription'];
    // Renderuj tagi dynamic data przez Bricks
    if (class_exists('\Bricks\Frontend') && method_exists('\Bricks\Frontend', 'render_dynamic_data')) {
        $description = \Bricks\Frontend::render_dynamic_data($raw, $post);
    } elseif (function_exists('bricks_render_dynamic_data')) {
        $description = bricks_render_dynamic_data($raw, $post);
    } else {
        $description = $raw;
    }
    $description = wp_strip_all_tags($description);
}

// Fallback
if (empty($description)) {
    $description = get_bloginfo('description');
}

    $page = [
        '@type'       => 'WebPage',
        '@id'         => $permalink . '#webpage',
        'url'         => $permalink,
        'name'        => $title,
        'description' => wp_strip_all_tags($description),
        'isPartOf'    => ['@id' => $home_url . '#website'],
        'breadcrumb'  => ['@id' => $permalink . '#breadcrumb'],
    ];

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
    // ================================================================
    // HELPERS
    // ================================================================
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