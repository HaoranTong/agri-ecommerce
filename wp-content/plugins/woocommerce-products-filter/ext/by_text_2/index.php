<?php
if (!defined('ABSPATH'))
    die('No direct access allowed');

final class WOOF_EXT_BY_TEXT_2 extends WOOF_EXT {

    public $type = 'by_html_type';
    public $html_type = 'by_text_2'; //your custom key here
    public $index = 'woof_text';
    public $html_type_dynamic_recount_behavior = 'none';

    public function __construct() {

        parent::__construct();
        $this->init();
    }

    public function get_ext_path() {
        return plugin_dir_path(__FILE__);
    }

    public function get_ext_override_path() {
        return get_stylesheet_directory() . DIRECTORY_SEPARATOR . "woof" . DIRECTORY_SEPARATOR . "ext" . DIRECTORY_SEPARATOR . $this->html_type . DIRECTORY_SEPARATOR;
    }

    public function get_ext_link() {
        return plugin_dir_url(__FILE__);
    }

    public function woof_add_items_keys($keys) {
        $keys[] = $this->html_type;
        return $keys;
    }

    public function init() {
        if (defined('HUSKY_TEXT_INIT') && HUSKY_TEXT_INIT) {
            return false;
        }

        add_filter('woof_add_items_keys', array($this, 'woof_add_items_keys'));
        add_filter('woof_get_request_data', array($this, 'woof_get_request_data'));

        add_filter('woof_dynamic_count_attr', array($this, 'cache_compatibility'), 99, 2);

        add_action('woof_print_html_type_options_' . $this->html_type, array($this, 'woof_print_html_type_options'), 10, 1);
        add_action('woof_print_html_type_' . $this->html_type, array($this, 'print_html_type'), 10, 1);
        add_action('wp_enqueue_scripts', array($this, 'wp_head'), 9);

        add_action('wp_ajax_woof_text_autocomplete', array($this, 'woof_text_autocomplete'));
        add_action('wp_ajax_nopriv_woof_text_autocomplete', array($this, 'woof_text_autocomplete'));

        self::$includes['js']['woof_' . $this->html_type . '_html_items'] = $this->get_ext_link() . 'js/' . $this->html_type . '.js';
        self::$includes['css']['woof_' . $this->html_type . '_html_items'] = $this->get_ext_link() . 'css/' . $this->html_type . '.css';
        self::$includes['js_init_functions'][$this->html_type] = 'woof_init_text'; //we have no init function in this case
        //***
        add_shortcode('woof_text_filter', array($this, 'woof_text_filter'));
    }

    public function woof_get_request_data($request) {
        if (isset($request['s'])) {
            $request['woof_text'] = $request['s'];
        }

        return $request;
    }

    public function cache_compatibility($args, $type) {

        $request = woof()->get_request_data();
        if (isset($request['woof_text']) AND $request['woof_text']) {
            $args['woof_text'] = $request['woof_text'];
        }

        return $args;
    }

    public function wp_head() {

        self::$includes['js_code_custom']['woof_' . $this->html_type . '_html_items'] = $this->get_js();
        self::$includes['css_code_custom'][$this->index] = $this->get_style();
        $request = woof()->get_request_data();
        $search_text = "";
        if (isset($request['woof_text']) AND $request['woof_text']) {
            $search_text = ":" . $request['woof_text'];
        }
        self::$includes['js_lang_custom'][$this->index] = esc_html__('By text', 'woocommerce-products-filter') . $search_text;
        //***
        if (isset(woof()->settings['by_text_2']['autocomplete']) AND woof()->settings['by_text_2']['autocomplete']) {
            wp_enqueue_script('easy-autocomplete', WOOF_LINK . 'js/easy-autocomplete/jquery.easy-autocomplete.min.js', array('jquery'), WOOF_VERSION);
            wp_enqueue_style('easy-autocomplete', WOOF_LINK . 'js/easy-autocomplete/easy-autocomplete.min.css', array(), WOOF_VERSION);
            wp_enqueue_style('easy-autocomplete-theme', WOOF_LINK . 'js/easy-autocomplete/easy-autocomplete.themes.min.css', array(), WOOF_VERSION);
        }
    }

    public function get_js() {

        ob_start();
        ?>
        var woof_text_autocomplete = 0;
        var woof_text_autocomplete_items = 10;
        <?php if (isset(woof()->settings['by_text_2']['autocomplete'])): ?>
            woof_text_autocomplete =<?php echo intval(woof()->settings['by_text_2']['autocomplete']) ?>;
            woof_text_autocomplete_items =<?php echo esc_html(apply_filters('woof_text_autocomplete_items', 10)) ?>;
        <?php endif; ?>

        var woof_post_links_in_autocomplete = 0;
        <?php if (isset(woof()->settings['by_text_2']['post_links_in_autocomplete'])): ?>
            woof_post_links_in_autocomplete =<?php echo intval(woof()->settings['by_text_2']['post_links_in_autocomplete']) ?>;
        <?php endif; ?>

        var how_to_open_links = 0;
        <?php if (isset(woof()->settings['by_text_2']['how_to_open_links'])): ?>
            how_to_open_links =<?php echo intval(woof()->settings['by_text_2']['how_to_open_links']) ?>;
            <?php
        endif;
        return ob_get_clean();
    }

    public function get_style() {

        ob_start();
        if (isset(woof()->settings['by_text_2']['image'])) {
            if (!empty(woof()->settings['by_text_2']['image'])) {
                ?>
                .woof_text_search_container .woof_text_search_go{
                background: url(<?php echo esc_url(woof()->settings['by_text_2']['image']) ?>) !important;
                }
                <?php
            }
        }
        return ob_get_clean();
    }

    //shortcode
    public function woof_text_filter($args = array()) {

        if (!is_array($args)) {
            $args = array();
        }
        $args['loader_img'] = $this->get_ext_link() . 'img/loader.gif';

        if (file_exists($this->get_ext_override_path() . 'views' . DIRECTORY_SEPARATOR . 'shortcodes' . DIRECTORY_SEPARATOR . 'woof_text_filter.php')) {
            return woof()->render_html($this->get_ext_override_path() . 'views' . DIRECTORY_SEPARATOR . 'shortcodes' . DIRECTORY_SEPARATOR . 'woof_text_filter.php', $args);
        }

        return woof()->render_html($this->get_ext_path() . 'views' . DIRECTORY_SEPARATOR . 'shortcodes' . DIRECTORY_SEPARATOR . 'woof_text_filter.php', $args);
    }

    //settings page hook
    public function woof_print_html_type_options() {

        woof()->render_html_e($this->get_ext_path() . 'views' . DIRECTORY_SEPARATOR . 'options.php', array(
            'key' => $this->html_type,
            "woof_settings" => get_option('woof_settings', array())
                )
        );
    }

    public function assemble_query_params(&$meta_query, $wp_query = NULL) {
        add_filter('posts_where', array($this, 'woof_post_text_filter'), 9999, 2); //for searching by text
        return $meta_query;
    }

    public function woof_post_text_filter($where = '', $query = null) {
        global $wpdb, $wp_query;

        $request = woof()->get_request_data();

        //=======================
        // VALIDATION: Check applicability only to product
        //=======================
        if (!defined('DOING_AJAX')) {
            if (!isset($query->query_vars['post_type']) && !isset($query->query_vars['wc_query'])) {
                return $where;
            }
            if ($query->query_vars['post_type'] != "product") {
                if (is_array($query->query_vars['post_type'])) {
                    if (!in_array("product", $query->query_vars['post_type'])) {
                        return $where;
                    }
                } elseif (!isset($query->query_vars['wc_query']) OR $query->query_vars['wc_query'] != 'product_query') {
                    return $where;
                }
            }
        }

        //=======================
        // CHECK: Is there any text to search for?
        //=======================
        if (!isset($request['woof_text']) OR !$request['woof_text']) {
            return $where;
        }

        //=======================
        // CONDITION: Checking woof_products_doing
        //=======================
        /*
         * rudiment, commented
          if (defined('DOING_AJAX')) {
          $conditions = (isset($wp_query->query_vars['post_type']) AND $wp_query->query_vars['post_type'] == 'product') OR WOOF_REQUEST::isset('woof_products_doing');
          } else {
          $conditions = WOOF_REQUEST::isset('woof_products_doing');
          }
         * 
         */

        if (woof()->is_isset_in_request_data('woof_text')) {
            // SAFETY: We normalize the text once at the beginning
            $woof_text_raw = wp_specialchars_decode(trim(urldecode($request['woof_text'])));
            $woof_text_raw = trim(WOOF_HELPER::strtolower($woof_text_raw));
            $woof_text_raw = preg_replace('/\s+/', ' ', $woof_text_raw);

            if (!$woof_text_raw) {
                return $where;
            }

            // ===== MAJOR FIX =====
            // Never interpolate user input directly into SQL!
            // Use $wpdb->prepare() for ALL cases

            $use_like = apply_filters('woof_text_search_like_option', false);
            $search_by_full_word = false;

            if (isset(woof()->settings['by_text_2']['search_by_full_word'])) {
                $search_by_full_word = (int) woof()->settings['by_text_2']['search_by_full_word'];
            }

            $behavior = 'title';
            if (isset(woof()->settings['by_text_2']['behavior'])) {
                $behavior = woof()->settings['by_text_2']['behavior'];
            }

            if (WOOF_REQUEST::isset('auto_search_by_behavior') AND !empty(WOOF_REQUEST::get('auto_search_by_behavior'))) {
                $behavior = WOOF_REQUEST::get('auto_search_by_behavior');
            }

            $text_where = '';

            //=======================
            // BRANCH 1: REGEXP (with validation)
            //=======================
            if (!$use_like) {
                // ReDoS protection: limit the length of the pattern
                if (strlen($woof_text_raw) > 255) {
                    $woof_text_raw = substr($woof_text_raw, 0, 255);
                }

                // Escaping for REGEXP using preg_quote
                $pattern = preg_quote($woof_text_raw, '/');

                // Replace spaces with the REGEXP pattern BEFORE adding word boundaries
                $pattern = str_replace(' ', '(.*)', $pattern);

                // Add word boundaries if needed.
                if ($search_by_full_word) {
                    $pattern = '[[:<:]]' . $pattern . '[[:>:]]';
                }

                // EVERYONE uses $wpdb->prepare() - SAFE!
                switch ($behavior) {
                    case 'content':
                        $text_where = $wpdb->prepare("LOWER(post_content) REGEXP %s", $pattern);
                        break;

                    case 'title_or_content':
                        $text_where = $wpdb->prepare(
                                "(LOWER(post_title) REGEXP %s OR LOWER(post_content) REGEXP %s)",
                                $pattern, $pattern
                        );
                        break;

                    case 'title_and_content':
                        $text_where = $wpdb->prepare(
                                "(LOWER(post_title) REGEXP %s AND LOWER(post_content) REGEXP %s)",
                                $pattern, $pattern
                        );
                        break;

                    case 'excerpt':
                        $text_where = $wpdb->prepare("LOWER(post_excerpt) REGEXP %s", $pattern);
                        break;

                    case 'content_or_excerpt':
                        $text_where = $wpdb->prepare(
                                "(LOWER(post_excerpt) REGEXP %s OR LOWER(post_content) REGEXP %s)",
                                $pattern, $pattern
                        );
                        break;

                    case 'title_or_content_or_excerpt':
                        $text_where = $wpdb->prepare(
                                "(LOWER(post_title) REGEXP %s OR LOWER(post_excerpt) REGEXP %s OR LOWER(post_content) REGEXP %s)",
                                $pattern, $pattern, $pattern
                        );
                        break;

                    default:
                        $text_where = $wpdb->prepare("LOWER(post_title) REGEXP %s", $pattern);
                }
            }
            //=======================
            // BRANCH 2: LIKE (with correct search_by_full_word handling)
            //=======================
            else {
                $words = array_filter(explode(' ', $woof_text_raw));
                $parts = [];

                foreach ($words as $word) {
                    // Escape for LIKE
                    $word_safe = $wpdb->esc_like($word);

                    // We apply search_by_full_word logic
                    if (!$search_by_full_word) {
                        $word_safe = '%' . $word_safe . '%';
                    }

                    switch ($behavior) {
                        case 'content':
                            $parts[] = $wpdb->prepare("LOWER(post_content) LIKE %s", $word_safe);
                            break;

                        case 'title_or_content':
                            $parts[] = $wpdb->prepare(
                                    "(LOWER(post_title) LIKE %s OR LOWER(post_content) LIKE %s)",
                                    $word_safe, $word_safe
                            );
                            break;

                        case 'title_and_content':
                            $parts[] = $wpdb->prepare(
                                    "(LOWER(post_title) LIKE %s AND LOWER(post_content) LIKE %s)",
                                    $word_safe, $word_safe
                            );
                            break;

                        case 'excerpt':
                            $parts[] = $wpdb->prepare("LOWER(post_excerpt) LIKE %s", $word_safe);
                            break;

                        case 'content_or_excerpt':
                            $parts[] = $wpdb->prepare(
                                    "(LOWER(post_excerpt) LIKE %s OR LOWER(post_content) LIKE %s)",
                                    $word_safe, $word_safe
                            );
                            break;

                        case 'title_or_content_or_excerpt':
                            $parts[] = $wpdb->prepare(
                                    "(LOWER(post_title) LIKE %s OR LOWER(post_excerpt) LIKE %s OR LOWER(post_content) LIKE %s)",
                                    $word_safe, $word_safe, $word_safe
                            );
                            break;

                        default:
                            $parts[] = $wpdb->prepare("LOWER(post_title) LIKE %s", $word_safe);
                    }
                }

                $text_where = !empty($parts) ? implode(' AND ', $parts) : '';
            }

            //=======================
            // FEATURE: Search by variation description
            //=======================
            $var_desc_where = '';
            if (!empty(woof()->settings['by_text_2']['search_desc_variant']) && !in_array($behavior, ['excerpt', 'content', 'title'], true)) {

                // SECURE: Using prepare()
                if ($use_like) {
                    $sql = $wpdb->prepare(
                            "SELECT posts.ID
                    FROM {$wpdb->posts} AS posts
                    LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                    WHERE posts.post_type = 'product_variation'
                    AND postmeta.meta_key = '_variation_description'
                    AND postmeta.meta_value LIKE %s",
                            '%' . $wpdb->esc_like($woof_text_raw) . '%'
                    );
                } else {
                    $pattern_var = preg_quote($woof_text_raw, '/');
                    $pattern_var = str_replace(' ', '(.*)', $pattern_var);
                    if ($search_by_full_word) {
                        $pattern_var = '[[:<:]]' . $pattern_var . '[[:>:]]';
                    }

                    $sql = $wpdb->prepare(
                            "SELECT posts.ID
                    FROM {$wpdb->posts} AS posts
                    LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                    WHERE posts.post_type = 'product_variation'
                    AND postmeta.meta_key = '_variation_description'
                    AND LOWER(postmeta.meta_value) REGEXP %s",
                            $pattern_var
                    );
                }

                $product_variations = $wpdb->get_col($sql);

                if (!empty($product_variations)) {
                    $product_ids = array_map('intval', $product_variations);

                    // Obtaining parent IDs securely
                    $ids_string = implode(',', $product_ids);
                    $parent_ids = $wpdb->get_col(
                            "SELECT DISTINCT post_parent
                    FROM {$wpdb->posts}
                    WHERE ID IN ($ids_string) AND post_parent > 0"
                    );

                    $all_ids = array_unique(array_merge($product_ids, (array) $parent_ids));
                    if (!empty($all_ids)) {
                        $var_desc_where = ' OR ' . $wpdb->posts . '.ID IN(' . implode(',', $all_ids) . ')';
                    }
                }
            }

            //=======================
            // FEATURE:Search by SKU
            //=======================
            $sku_where = '';
            if (!empty(woof()->settings['by_text_2']['sku_compatibility'])) {

                // IMPORTANT: Preserve the original URL decode
                $skus_raw = explode(',', $request['woof_text']);
                $skus_raw = array_map('urldecode', $skus_raw);
                $skus_raw = array_map('trim', $skus_raw);
                $skus_raw = array_filter($skus_raw);

                if (!empty($skus_raw)) {
                    $sku_logic = woof()->settings['by_sku']['logic'] ?? 'LIKE';
                    $sku_conditions = [];

                    foreach ($skus_raw as $sku) {
                        if ($sku_logic === '=') {
                            $sku_conditions[] = $wpdb->prepare("postmeta.meta_value = %s", $sku);
                        } else {
                            $sku_conditions[] = $wpdb->prepare("postmeta.meta_value LIKE %s", '%' . $wpdb->esc_like($sku) . '%');
                        }
                    }

                    if (!empty($sku_conditions)) {
                        $where_clause = implode(' OR ', $sku_conditions);

                        $sql = "
                        SELECT posts.ID
                        FROM {$wpdb->posts} AS posts
                        LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                        WHERE posts.post_type IN ('product_variation', 'product')
                        AND postmeta.meta_key = '_sku'
                        AND ($where_clause)
                    ";

                        $product_variations = $wpdb->get_col($sql);

                        if (!empty($product_variations)) {
                            $product_ids = array_map('intval', $product_variations);

                            $ids_string = implode(',', $product_ids);
                            $parent_ids = $wpdb->get_col(
                                    "SELECT DISTINCT post_parent
                            FROM {$wpdb->posts}
                            WHERE ID IN ($ids_string) AND post_parent > 0"
                            );

                            $all_ids = array_unique(array_merge($product_ids, (array) $parent_ids));
                            if (!empty($all_ids)) {
                                $sku_where = ' OR ' . $wpdb->posts . '.ID IN(' . implode(',', $all_ids) . ')';
                            }
                        }
                    }
                }
            }

            //=======================
            // FINAL: We collect WHERE
            //=======================
            if (!empty($text_where)) {
                $final = apply_filters('woof_text_search_query', $text_where . $var_desc_where . $sku_where, $woof_text_raw);
                $where .= " AND ( $final ) ";
            }
        }

        return $where;
    }

    //ajax
    public function woof_text_autocomplete() {
        if (!wp_verify_nonce(WOOF_REQUEST::get('woof_text_search_nonce'), 'text_search_nonce')) {
            die('Stop!');
        }
        $results = array();
        $args = array(
            'nopaging' => true,
            //'fields' => 'ids',
            'post_type' => 'product',
            'post_status' => array('publish'),
            'orderby' => 'title',
            'order' => 'ASC',
            'max_num_pages' => intval(WOOF_REQUEST::get('auto_res_count')) > 0 ? intval(WOOF_REQUEST::get('auto_res_count')) : apply_filters('woof_text_autocomplete_items', 10)
        );

        if (class_exists('SitePress')) {
            //$args['lang'] = ICL_LANGUAGE_CODE;
            $args['lang'] = apply_filters('wpml_current_language', NULL);
        }

        //***

        $_GET['woof_text'] = WOOF_REQUEST::get('phrase');
        if (!empty(WOOF_REQUEST::get('auto_search_by'))) {
            WOOF_REQUEST::set('auto_search_by_behavior', WOOF_REQUEST::get('auto_search_by'));
        }
        add_filter('posts_where', array($this, 'woof_post_text_filter'), 10);
        $query = new WP_Query($args);
        //+++
        //http://easyautocomplete.com/guide
        if ($query->have_posts()) {

            foreach ($query->posts as $p) {
                $data = array(
                    "name" => $p->post_title,
                    "type" => "product"
                );
                if (has_post_thumbnail($p->ID)) {
                    $img_src = wp_get_attachment_image_src(get_post_thumbnail_id($p->ID), 'single-post-thumbnail');
                    $data['icon'] = $img_src[0];
                } else {
                    $data['icon'] = WOOF_LINK . 'img/not-found.jpg';
                }
                $data['link'] = get_post_permalink($p->ID);
                $results[] = $data;
            }
        } else {
            $results[] = array(
                "name" => esc_html__("Products not found!", 'woocommerce-products-filter'),
                "type" => "",
                "link" => "#",
                "icon" => WOOF_LINK . 'img/not-found.jpg'
            );
        }

        die(json_encode($results));
    }
}

WOOF_EXT::$includes['html_type_objects']['by_text_2'] = new WOOF_EXT_BY_TEXT_2();
