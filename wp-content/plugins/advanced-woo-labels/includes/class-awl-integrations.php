<?php
/**
 * AWL plugin integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'AWL_Integrations' ) ) :

    /**
     * Class for main plugin functions
     */
    class AWL_Integrations {
        
        /**
         * @var AWL_Integrations The single instance of the class
         */
        protected static $_instance = null;

        /**
         * @var AWL_Integrations Current theme name
         */
        public $current_theme = '';

        /**
         * @var AWL_Integrations Init theme name
         */
        public $child_theme = '';

        /**
         * @var AWL_Integrations Active plugins arrray
         */
        public $active_plugins = array();

        /**
         * Main AWL_Integrations Instance
         *
         * @static
         * @return AWL_Integrations - Main instance
         */
        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /**
         * Constructor
         */
        public function __construct() {

            $theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : false;

            if ( $theme ) {
                $this->current_theme = $theme->get( 'Name' );
                $this->child_theme = $theme->get( 'Name' );
                if ( $theme->parent() ) {
                    $this->current_theme = $theme->parent()->get( 'Name' );
                }
            }

            $active_plugins = get_option( 'active_plugins', array() );

            if ( is_multisite() ) {
                $network_active_plugins = get_site_option( 'active_sitewide_plugins', array() );
                $active_plugins = array_merge( $active_plugins, array_keys( $network_active_plugins ) );
            }

            $this->active_plugins = $active_plugins;

            $this->includes();

            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 1 );

            add_filter( 'awl_label_container_styles', array( $this, 'awl_label_container_styles' ), 1, 3 );

            add_action( 'wp_head', array( $this, 'wp_head_styles' ) );

            add_action( 'awl_hide_default_sale_flash', array( $this, 'hide_default_sale_flash' ), 1 );

            add_action( 'awl_hide_default_stock_flash', array( $this, 'hide_default_stock_flash' ), 1 );

        }

        /**
         * Include files
         */
        public function includes() {

            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-wp-cli.php' );
            }

            if ( function_exists('has_blocks') ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-blocks.php' );
            }

            if ( defined( 'WOOLENTOR_VERSION' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-woolentor.php' );
            }

            if ( defined( 'WOOLEMENTOR' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-woolementor.php' );
            }

            if ( class_exists( 'SP_WooCommerce_Product_Slider' ) || defined('SP_WPS_VERSION') || class_exists( 'SP_WooCommerce_Product_Slider_PRO' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-sp-slider.php' );
            }

            if ( defined( 'WDR_VERSION' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-wdr.php' );
            }

            if ( function_exists( 'AWS' ) || function_exists( 'AWS_PRO' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-aws.php' );
            }

            if ( defined( 'ELEMENTOR_VERSION' ) || defined( 'ELEMENTOR_PRO_VERSION' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-elementor.php' );
            }

            if ( class_exists( 'Jet_Woo_Builder' ) || class_exists( 'Jet_Engine' ) || class_exists( 'Jet_Smart_Filters' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-jet-plugins.php' );
            }

            if ( defined( 'YITH_YWDPD_VERSION' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-yith-discounts.php' );
            }

            if ( class_exists( 'FLBuilder' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-bb.php' );
            }

            if ( defined( 'ET_BUILDER_PLUGIN_DIR' ) || function_exists( 'et_setup_theme' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-divi.php' );
            }

            if ( class_exists( 'WC_Product_Table_Plugin' ) || class_exists('Barn2\Plugin\WC_Product_Table\Product_Table') ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-barn-tables.php' );
            }

            if ( defined( "UNLIMITED_ELEMENTS_VERSION" ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-unlimites-elements.php' );
            }

            if ( class_exists( 'XforWC_Product_Filters' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-prdctfltr.php' );
            }

            if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-wpml.php' );
            }

            if ( class_exists( 'ShopEngine' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-shopengine.php' );
            }

            if ( defined('KADENCE_BLOCKS_PATH') ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-kadence.php' );
            }

            if ( defined('UAGB_FILE') ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-spectra.php' );
            }

            if ( defined( 'WPB_VC_VERSION' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-wpbakery.php' );
            }

            if ( defined('POLYLANG_VERSION') ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-polylang.php' );
            }

            if ( defined( 'WPF_DIR' ) || defined( 'WPF_SITE_URL' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-wpf.php' );
            }

            if ( defined( 'BREAKDANCE_PLUGIN_URL' ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-breakdance.php' );
            }

            // Discount Rules and Dynamic Pricing for WooCommerce
            if ( in_array( 'easy-woocommerce-discounts/easy-woocommerce-discounts.php', $this->active_plugins ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-easy-discounts.php' );
            }

            // Essential Addons for Elementor
            if ( in_array( 'essential-addons-for-elementor-lite/essential_adons_elementor.php', $this->active_plugins ) || in_array( 'essential-addons-for-elementor/essential_adons_elementor.php', $this->active_plugins ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-essential-addons.php' );
            }

            //  WooCommerce Product Bundles
            if ( in_array( 'woocommerce-product-bundles/woocommerce-product-bundles.php', $this->active_plugins ) ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-product-bundles.php' );
            }

            if ( 'Avada' === $this->current_theme ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-avada.php' );
            }

            if ( 'Flatsome' === $this->current_theme ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-flatsome.php' );
            }

            if ( 'Bricks' === $this->current_theme ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-bricks.php' );
            }

            if ( 'Astra' === $this->current_theme && defined('ASTRA_EXT_DIR') ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-astra.php' );
            }

            if ( 'Martfury' === $this->current_theme ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-martfury.php' );
            }

            if ( 'Virtue' === $this->current_theme ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-virtue.php' );
            }

            if ( 'XStore' === $this->current_theme || 'Xstore' === $this->current_theme ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-xstore.php' );
            }

            if ( 'Zephyr' === $this->current_theme ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-zephyr.php' );
            }

            if ( 'Woodmart' === $this->current_theme ) {
                include_once( AWL_DIR . '/includes/modules/class-awl-woodmart.php' );
            }

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks = array(
                'on_image' => array(
                    'archive' => array(
                        'woocommerce_before_shop_loop_item_title' => array( 'priority' => 10 ),
                        'woocommerce_product_get_image' => array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::woocommerce_product_get_image', 'args' => 3 ),
                        'woocommerce_blocks_product_grid_item_html' => array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::woocommerce_blocks_product_grid_item_html_on_image', 'args' => 3 )
                    ),
                    'single' => array(
                        'woocommerce_product_thumbnails' => array( 'priority' => 10 )
                    ),
                ),
                'before_title' => array(
                    'archive' => array(
                        'woocommerce_shop_loop_item_title' => array( 'priority' => 9 ),
                        'woocommerce_blocks_product_grid_item_html' => array( 'priority' => 11, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::woocommerce_blocks_product_grid_item_html_before_title', 'args' => 3 )
                    ),
                    'single' => array(
                        'woocommerce_single_product_summary' => array( 'priority' => 4 )
                    ),
                ),
            );

            if ( is_singular( 'product' ) ) {
                if ( get_post_meta( get_queried_object_id(), '_product_image_gallery', true ) ) {
                    $hooks['on_image']['single'] = array( 'woocommerce_product_thumbnails' => array( 'priority' => 10, 'js' =>  array( '.woocommerce-product-gallery .flex-viewport, .woocommerce-product-gallery__wrapper', 'append' ) ) );
                }
            }

            switch ( $this->current_theme ) {

                case 'Aurum':
                    $hooks['on_image']['archive'] = array( 'get_template_part_tpls/woocommerce-item-thumbnail' => array( 'priority' => 10 ) );
                    $hooks['before_title']['archive'] = array( 'aurum_before_shop_loop_item_title' => array( 'priority' => 10 ) );
                    $hooks['on_image']['single'] = array( 'woocommerce_before_single_product_summary' => array( 'priority' => 25, 'js' =>  array( '.product-images-container .product-images--main', 'append' ) ) );
                    break;

                case 'Betheme':
                    $hooks['on_image']['archive'] = array(
                        'post_thumbnail_html' => array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::post_thumbnail_html', 'args' => 4 ),
                        'woocommerce_placeholder_img' => array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::betheme_woocommerce_placeholder_img', 'args' => 3 )
                    );
                    $hooks['before_title']['archive'] = array( 'woocommerce_after_shop_loop_item_title' => array( 'priority' => 10 ) );
                    break;

                case 'Porto':
                    $hooks['on_image']['single'] = array( 'woocommerce_single_product_image_html' => array( 'priority' => 10, 'type' => 'filter'  ) );
                    break;

                case 'Devita':
                    $hooks['on_image']['archive'] = array( 'woocommerce_before_shop_loop_item' => array( 'priority' => 10 ) );
                    break;

                case 'Electro':
                    $hooks['on_image']['archive'] = array( 'electro_template_loop_product_thumbnail' => array( 'priority' => 10, 'type' => 'filter' ) );
                    break;

                case 'firezy':
                    $hooks['before_title']['archive'] = array( 'woocommerce_after_shop_loop_item_title' => array( 'priority' => 10 ) );
                    break;

                case 'GreenMart':
                    $hooks['before_title']['archive'] = array( 'woocommerce_before_shop_loop_item_title' => array( 'priority' => 20 ) );
                    break;

                case 'HandMade':
                    $hooks['before_title']['archive'] = array( 'woocommerce_after_shop_loop_item_title' => array( 'priority' => 1 ) );
                    $hooks['on_image']['single'] = array( 'woocommerce_single_product_image_html' => array( 'priority' => 10, 'type' => 'filter' ) );
                    break;

                case 'Jupiter':
                    $hooks['on_image']['archive'] = array( 'woocommerce_after_shop_loop_item' => array( 'priority' => 10 ) );
                    $hooks['before_title']['archive'] = array( 'woocommerce_before_shop_loop_item' => array( 'priority' => 10 ) );
                    break;

                case 'MetroStore':
                    $hooks['on_image']['archive'] = array( 'post_thumbnail_html' => array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::post_thumbnail_html', 'args' => 4 ) );
                    break;

                case 'Enfold':
                    $hooks['on_image']['archive']['post_thumbnail_html'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::post_thumbnail_html', 'args' => 4 );
                    break;

                case 'TechStore':
                    $hooks['on_image']['archive'] = array( 'woocommerce_before_shop_loop_item_title' => array( 'priority' => 9, 'js' => array( '.hw-thumb', 'append' ) ) );
                    $hooks['before_title']['archive'] = array( 'woocommerce_before_shop_loop_item_title' => array( 'priority' => 10 ) );
                    break;

                case 'Kallyas':
                    $hooks['on_image']['archive'] = array( 'woocommerce_before_shop_loop_item' => array( 'priority' => 10, 'js' => array( '.kw-prodimage', 'append' ) ) );
                    break;

                case 'OceanWP';
                    $hooks['on_image']['archive'] = array( 'ocean_before_archive_product_image' => array( 'priority' => 10 ) );
                    $hooks['before_title']['archive'] = array( 'ocean_before_archive_product_categories' => array( 'priority' => 1 ), 'ocean_before_archive_product_title' => array( 'priority' => 1 ) );
                    $hooks['on_image']['single']['ocean_woo_quick_view_product_image'] = array( 'priority' => 10 );
                    $hooks['before_title']['single']['ocean_before_single_product_title'] = array( 'priority' => 10 );
                    break;

                case 'Shopkeeper';
                    $hooks['on_image']['archive'] = array( 'woocommerce_shop_loop_item_thumbnail' => array( 'priority' => 1 ) );
                    $hooks['before_title']['archive'] = array( 'woocommerce_shop_loop_item_thumbnail' => array( 'priority' => 10 ) );
                    $hooks['before_title']['single'] = array( 'woocommerce_single_product_summary_single_title' => array( 'priority' => 1 ) );
                    break;

                case 'Orchid Store':
                    $hooks['on_image']['archive'] = array( 'orchid_store_product_thumbnail' => array( 'priority' => 1 ) );
                    $hooks['before_title']['archive'] = array( 'orchid_store_shop_loop_item_title' => array( 'priority' => 5 ) );
                    break;

                case 'TheGem':
                    $hooks['before_title']['archive'] = array( 'woocommerce_before_shop_loop_item_title' => array( 'priority' => 10 ) );
                    $hooks['before_title']['single'] = array( 'thegem_woocommerce_single_product_right' => array( 'priority' => 1 ) );
                    $hooks['on_image']['single'] = array( 'thegem_woocommerce_single_product_left' => array( 'priority' => 1 ) );
                    break;

                case 'Oxygen':
                    $hooks['before_title']['archive'] = array( 'oxygen_woocommerce_after_loop_item_title' => array( 'priority' => 10, 'js' => array( '.woocommerce-loop-product__title', 'before' ) ) );
                    $hooks['on_image']['single'] = array( 'oxygen_woocommerce_single_product_before_images' => array( 'priority' => 10 ) );
                    break;

                case 'Konado':
                    $hooks['on_image']['archive'] = array( 'woocommerce_before_shop_loop_item' => array( 'priority' => 10 ) );
                    $hooks['before_title']['archive'] = array( 'woocommerce_after_shop_loop_item' => array( 'priority' => 10, 'js' => array( '.product-name', 'prepend' ) ) );
                    break;

                case 'Stockie':
                    $hooks['on_image']['archive'] = array( 'woocommerce_sale_flash' => array( 'priority' => 10, 'type' => 'filter' ) );
                    $hooks['before_title']['archive'] = array( 'woocommerce_sale_flash' => array( 'priority' => 15, 'type' => 'filter', 'js' => array( '.font-titles', 'before' ) ) );
                    break;

                case 'Martfury':
                    $hooks['on_image']['archive'] = array( 'martfury_after_product_loop_thumbnail' => array( 'priority' => 10 ) );
                    break;

                case 'BoxShop':
                    $hooks['on_image']['single'] = array( 'boxshop_before_product_image' => array( 'priority' => 10 ) );
                    $hooks['before_title']['archive'] = array( 'woocommerce_after_shop_loop_item' => array( 'priority' => 10 ) );
                    break;

                case 'Rehub theme':
                    $hooks['on_image']['archive']['rh_woo_thumbnail_loop'] = array( 'priority' => 10 );
                    $hooks['before_title']['archive']['rh_woo_thumbnail_loop'] = array( 'priority' => 10 );
                    $hooks['before_title']['single']['rh_woo_single_product_title'] = array( 'priority' => 10 );
                    break;

                case 'Royal':
                    $hooks['on_image']['single']['woocommerce_single_product_image_html'] = array( 'priority' => 10, 'type' => 'filter'  );
                    break;

                case 'Uncode':
                    $hooks['on_image']['archive'] = array( 'uncode_entry_visual_after_image' => array( 'priority' => 10 ) );
                    $hooks['before_title']['archive']= array( 'uncode_inner_entry_after_title' => array( 'priority' => 10 ) );
                    break;

                case 'Total':
                    $hooks['on_image']['archive'] = array( 'wpex_woocommerce_loop_thumbnail_before' => array( 'priority' => 10 ) );
                    break;

                case 'Blocksy':
                    $hooks['on_image']['archive'] = array(
                        'blocksy:woocommerce:product-card:thumbnail:start' => array( 'priority' => 10 ),
                        'woocommerce_product_get_image' => array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::wrap_thumb_container_filter', 'args' => 1 ),
                    );
                    break;

                case 'Basel':
                    $hooks['on_image']['single'] = array( 'woocommerce_before_single_product_summary' => array( 'priority' => 10, 'js' => array( '.woocommerce-product-gallery figure', 'append' ) ) );
                    break;

                case 'Kapee':
                    $hooks['on_image']['single'] = array( 'kapee_product_gallery_top' => array( 'priority' => 10 ) );
                    break;

                case 'TeeSpace':
                    $hooks['on_image']['single']['woocommerce_before_single_product_summary'] = array( 'priority' => 10 );
                    break;

                case 'Woostify':
                    $hooks['on_image']['single']['woostify_product_images_box_end'] = array( 'priority' => 10 );
                    $hooks['on_image']['archive']['woocommerce_before_shop_loop_item_title'] = array( 'priority' => 21 );
                    break;

                case 'The7':
                    $hooks['on_image']['archive']['dt_woocommerce_shop_loop_images'] = array( 'priority' => 10 );
                    break;

                case 'Elessi Theme':
                    $hooks['on_image']['single']['woocommerce_product_thumbnails'] = array( 'priority' => 10, 'js' => array( '.nasa-main-wrap .product-images-slider', 'append' ) );
                    $hooks['on_image']['single']['woocommerce_single_product_lightbox_before'] = array( 'priority' => 10 );
                    $hooks['before_title']['single']['woocommerce_single_product_lightbox_summary'] = array( 'priority' => 10 );
                    break;

                case 'Open Shop':
                    $hooks['on_image']['archive']['open_shop_woo_qv_product_image'] = array( 'priority' => 10 );
                    $hooks['before_title']['archive']['open_shop_woo_quick_view_product_summary'] = array( 'priority' => 1 );
                    break;

                case 'Hitek':
                    $hooks['on_image']['single']['xts_before_single_product_main_gallery'] = array( 'priority' => 10 );
                    break;

                case 'TastyDaily':
                    $hooks['on_image']['archives']['woocommerce_before_shop_loop_item_title'] = array( 'priority' => 10, 'js' => array( '.c-product-grid__thumb-wrap', 'append' ) );
                    $hooks['on_image']['single']['woocommerce_after_single_product_summary'] = array( 'priority' => 10, 'js' => array( '.c-product__col-1', 'append' ) );
                    $hooks['before_title']['single']['woocommerce_single_product_summary'] = array( 'priority' => 1, 'js' => array( '.c-product__title', 'before' ) );
                    break;

                case 'Shopical':
                    if ( ! is_singular('product') ) {
                        $hooks['on_image']['archives']['shopical_woocommerce_after_shop_loop_item_title'] = array( 'priority' => 1, 'js' => array( '.product-image-wrapper', 'append' ) );
                    }
                    $hooks['before_title']['archives']['shopical_woocommerce_after_shop_loop_item_title'] = array( 'priority' => 2, 'js' => array( '.product-title a', 'before' ) );
                    break;

                case 'Shoptimizer';
                    $hooks['on_image']['archives']['woocommerce_before_shop_loop_item_title'] = array( 'priority' => 4 );
                    break;

            }

            // Oxygen builder
            if ( class_exists( 'OxyWooCommerce' ) ) {
                $hooks['on_image']['archive']['woocommerce_product_get_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::woocommerce_product_get_image', 'args' => 3 );
                $hooks['before_title']['archive']['woocommerce_before_shop_loop_item_title'] = array( 'priority' => 10 );
                $hooks['on_image']['single']['woocommerce_product_thumbnails'] = array( 'priority' => 10 );
            }

            if ( class_exists( 'Iconic_WooThumbs' ) ) {
                $hooks['on_image']['single']['iconic_woothumbs_before_images_wrap'] = array( 'priority' => 10 );
            }

            // Product Gallery Slider for Woocommerce ( Formerly Twist )
            if ( in_array( 'twist/twist.php', $this->active_plugins ) ) {
                if ( wp_is_mobile() ) {
                    $hooks['on_image']['single']['wpgs_after_image_gallery'] = array( 'priority' => 10, 'js' => array( '.mob-image .wpgs-image', 'prepend' ) );
                } else {
                    $hooks['on_image']['single']['wpgs_after_image_gallery'] = array( 'priority' => 10, 'js' => array( '.wpgs-image', 'prepend' ) );
                }
            }

            // Additional Variation Images Gallery for WooCommerce plugin
            if ( class_exists( 'Woo_Variation_Gallery' ) || defined( 'WOO_VARIATION_GALLERY_PLUGIN_VERSION' ) ) {
                $hooks['on_image']['single']['woo_variation_product_gallery_start'] = array( 'priority' => 10, 'js' => array( '.veb-variation-gallery-slider-wrapper', 'append' ) );
            }

            // Premium Addons for Elementor
            if ( defined('PREMIUM_ADDONS_VERSION') ) {
                $hooks['before_title']['archive']['pa_woo_product_before_title'] = array( 'priority' => 10 );
                $hooks['on_image']['archive']['pa_woo_product_before_details_wrap_start'] = array( 'priority' => 10, 'js' => array( '.premium-woo-product-thumbnail', 'append' ) );
                $hooks['on_image']['single']['premium_woo_qv_image'] = array( 'priority' => 10 );
                $hooks['before_title']['single']['premium_woo_quick_view_product'] = array( 'priority' => 10 );
            }

            // WooPack plugin
            if ( class_exists('WooPack') ) {
                $hooks['on_image']['archive']['woopack_loop_before_product_image'] = array( 'priority' => 10 );
                $hooks['before_title']['archive']['woopack_loop_before_product_title'] = array( 'priority' => 10 );
            }

            // Thrive Builder
            if ( class_exists('Thrive_Theme') || defined('TVE_TCB_ROOT_PATH') ) {
                $hooks['on_image']['archive']['tcb_post_list_article_content'] = array( 'priority' => 10, 'type' => 'filter', 'js' => array( '.tve-cb .thrv-content-box', 'append' ) );
                $hooks['before_title']['archive']['tcb_post_list_article_content'] = array( 'priority' => 11, 'type' => 'filter', 'js' => array( '.tcb-post-title a', 'before' ) );
            }

            // Product Slider for WooCommerce
            if ( defined( 'SP_WPSPRO_PATH' ) || defined( 'SP_WPS_PATH' ) ) {
                $hooks['before_title']['archive']['sp_wpspro_before_product_title'] = array( 'priority' => 10 );
                $hooks['on_image']['archive']['sp_wpspro_before_product_thumbnail'] = array( 'priority' => 10 );
            }

            // CommerceKit by CommerceGurus
            if ( class_exists( 'CommerceGurus_Gallery' ) ) {
                $hooks['on_image']['single']['commercekit_before_gallery'] = array( 'priority' => 10, 'js' => array( '#commercegurus-pdp-gallery .swiper-container', 'append' ) );
            }

            // Product Video Gallery for Woocommerce
            if ( defined('NICKX_PLUGIN_VERSION') ) {
                $hooks['on_image']['single']['woocommerce_before_single_product_summary'] = array( 'priority' => 10, 'js' => array( '.images > div', 'append' ) );
            }

            // Ultimate addons for Beaver Builder plugin
            if ( in_array( 'bb-ultimate-addon/bb-ultimate-addon.php', $this->active_plugins ) ) {
                $hooks['on_image']['archive']['uabb_woo_products_before_summary_wrap'] = array( 'priority' => 10, 'js' => array( '.uabb-woo-products-thumbnail-wrap', 'append' ) );
                $hooks['before_title']['archive']['uabb_woo_products_title_before'] = array( 'priority' => 10 );
            }

            // Variation Images Gallery for WooCommerce
            if ( in_array( 'woo-product-variation-gallery/woo-product-variation-gallery.php', $this->active_plugins ) ) {
                $hooks['on_image']['single']['rtwpvg_product_badge'] = array( 'priority' => 10 );
            }

            return $hooks;

        }

        /*
         * Change labels container styles
         */
        public function awl_label_container_styles( $styles, $position_type, $labels ) {

            global $wp_filter, $wp_current_filter;
            $current_filter = array_slice( $wp_current_filter, -2, 1 );

            $current_filter = isset( $current_filter[0] ) ? $current_filter[0] : false;

            if ( $current_filter ) {

                $filter_obj = $wp_filter[$current_filter];
                $priority = method_exists( $filter_obj, 'current_priority' ) ? $filter_obj->current_priority() : 10;

                $hooks = AWL_Helpers::get_hooks();
                if ( is_array( $hooks ) && ! empty( $hooks ) ) {
                    foreach( $hooks as $position => $hooks_list_type ) {
                        foreach ( $hooks_list_type as $hooks_display => $hooks_list ) {
                            foreach ( $hooks_list as $hook_name => $hook_vars ) {
                                $hook_priority = isset( $hook_vars['priority'] ) ? $hook_vars['priority'] : 10;
                                if ( $hook_name === $current_filter && isset( $hook_vars['js'] ) && $hook_priority === $priority ) {
                                    $styles['display'] = 'none';
                                    break 3;
                                }
                            }
                        }
                    }
                }

            }

            if ( 'Avada' === $this->current_theme ) {
                $styles['z-index'] = '99';
            }

            if ( 'Twenty Twenty' === $this->current_theme && in_array( 'woocommerce_shop_loop_item_title', $wp_current_filter ) ) {
                $styles['margin-top'] = '10px';
            }

            if ( 'TheGem' === $this->current_theme && in_array( 'thegem_woocommerce_single_product_right', $wp_current_filter ) ) {
                $styles['margin-bottom'] = '10px';
            }

            if ( 'TheGem' === $this->current_theme && in_array( 'thegem_woocommerce_single_product_left', $wp_current_filter ) ) {
                $styles['margin-left'] = '21px';
                $styles['margin-right'] = '21px';
            }

            if ( 'Oxygen' === $this->current_theme ) {
                $styles['z-index'] = '1001';
            }

            if ( 'Oxygen' === $this->current_theme && in_array( 'oxygen_woocommerce_single_product_before_images', $wp_current_filter ) ) {
                $styles['margin-left'] = '15px';
                $styles['margin-right'] = '15px';
            }

            if ( 'Konado' === $this->current_theme && in_array( 'woocommerce_after_shop_loop_item', $wp_current_filter ) ) {
                $styles['justify-content'] = 'center';
                $styles['margin-bottom'] = '6px';
            }

            if ( 'Stockie' === $this->current_theme ) {
                $styles['display'] = 'flex';
            }

            if ( 'BoxShop' === $this->current_theme ) {
                $styles['z-index'] = '999';
            }

            if ( 'BoxShop' === $this->current_theme && in_array( 'woocommerce_after_shop_loop_item', $wp_current_filter ) ) {
                $styles['justify-content'] = 'center';
                $styles['margin-top'] = '8px';
                $styles['margin-bottom'] = '5px';
            }

            if ( 'Rehub theme' === $this->current_theme && in_array( 'rh_woo_single_product_title', $wp_current_filter ) ) {
                $styles['margin-bottom'] = '15px';
            }

            if ( 'Rehub theme' === $this->current_theme && in_array( 'rh_woo_thumbnail_loop', $wp_current_filter ) && $position_type === 'before_title' ) {
                $styles['justify-content'] = 'center';
                $styles['margin-top'] = '14px';
            }

            return $styles;

        }

        /*
         * Add custom styles
         */
        public function wp_head_styles() {

            $output = '';

            if ( 'Shopical' === $this->current_theme ) {
                $output .= '<style>.products .product-image-wrapper { position: relative; }</style>';
            }

            // WooCommerce Load More Products plugin
            if ( defined( 'BeRocket_Load_More_Products_version' ) ) {
                $output .= '<script>
                    jQuery(document).on( "berocket_lmp_end", function() {
                        window.document.dispatchEvent(new Event("AWLTriggerJsReplace", {
                            bubbles: true,
                            cancelable: true
                        }));
                    } );
                </script>';
            }

            echo $output;

        }

        /*
         * Hide default sale flash if this option is enables
         */
        public function hide_default_sale_flash() {

            if ( 'BoxShop' === $this->current_theme ) {
                remove_action( 'boxshop_before_product_image', 'boxshop_template_loop_product_label', 10 );
                remove_action( 'woocommerce_after_shop_loop_item_title', 'boxshop_template_loop_product_label', 1 );
            }

            if ( 'Woostify' === $this->current_theme ) {
                remove_action( 'woocommerce_before_shop_loop_item_title', 'woostify_change_sale_flash', 23 );
                remove_action( 'woostify_product_images_box_end', 'woostify_change_sale_flash', 10 );
                remove_action( 'woocommerce_before_single_product_summary', 'woostify_change_sale_flash', 25 );
            }

            if ( 'Hitek' === $this->current_theme ) {
                remove_action( 'woocommerce_sale_flash', 'xts_product_labels', 100 );
            }

            if ( class_exists( 'Iconic_WooThumbs' ) ) {
                remove_action( 'iconic_woothumbs_before_images', 'woocommerce_show_product_sale_flash', 10 );
            }

            if ( in_array( 'bb-ultimate-addon/bb-ultimate-addon.php', $this->active_plugins ) ) {
                add_filter( 'uabb_woo_products_sale_flash', function ( $image ) { return ''; }, 100 );
            }

            add_filter( 'woocommerce_blocks_product_grid_item_html', 'AWL_Integrations_Callbacks::woocommerce_blocks_product_grid_item_html_hide_bagge', 10, 3 );

        }

        /*
         * Hide default out-of-stock flash if this option is enables
         */
        public function hide_default_stock_flash() {

            if ( 'Woostify' === $this->current_theme ) {
                remove_action( 'woocommerce_before_single_product_summary', 'woostify_print_out_of_stock_label', 30 );
                remove_action( 'woostify_product_images_box_end', 'woostify_print_out_of_stock_label', 20 );
                remove_action( 'woocommerce_before_shop_loop_item_title', 'woostify_print_out_of_stock_label', 15 );
            }

            if ( 'OceanWP' === $this->current_theme ) {
                add_action( 'wp_head', function () {
                    echo '<style>.outofstock-badge { display:none; }</style>';
                } );
            }

            if ( 'TastyDaily' === $this->current_theme ) {
                add_filter( 'woocommerce_stock_html', function ( $html ) {
                    return '';
                } );
            }

            if ( 'Blocksy' === $this->current_theme ) {

                add_filter( 'blocksy:woocommerce:product-card:badges', function ( $badges ) {
                    $new_badges = array();
                    if ( ! empty( $badges ) ) {
                        foreach ( $badges as $badge ) {
                            if ( strpos( $badge, 'out-of-stock' ) === false ) {
                                $new_badges[] = $badge;
                            }
                        }
                    }
                    return $new_badges;
                } );

                add_filter( 'blocksy:woocommerce:single:after-sale-badge', function ( $badges ) {
                    $new_badges = array();
                    if ( ! empty( $badges ) ) {
                        foreach ( $badges as $badge ) {
                            if ( strpos( $badge, 'out-of-stock' ) === false ) {
                                $new_badges[] = $badge;
                            }
                        }
                    }
                    return $new_badges;
                } );

            }

        }

    }

endif;