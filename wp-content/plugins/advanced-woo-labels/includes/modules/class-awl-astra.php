<?php

/**
 * AWL Avada theme plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Astra')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Astra {

        /**
         * @var AWL_Astra The single instance of the class
         */
        protected static $_instance = null;

        protected $data = array();

        /**
         * Main AWL_Astra Instance
         *
         * Ensures only one instance of AWL_Astra is loaded or can be loaded.
         *
         * @static
         * @return AWL_Astra - Main instance
         */
        public static function instance()
        {
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /**
         * Constructor
         */
        public function __construct() {

            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

            add_action( 'awl_hide_default_stock_flash', array( $this, 'hide_default_stock_flash' ), 1 );

            add_action( 'astra_woo_qv_product_image', array( $this, 'astra_woo_qv_product_image' ), 1 );

            if ( AWL()->get_settings( 'show_default_sale' ) === 'false' ) {
                add_filter( 'astra_addon_shop_cards_buttons_html', array( $this, 'astra_addon_shop_cards_buttons_html' )  );
            }

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['single']['woocommerce_product_thumbnails'] = array( 'priority' => 10, 'js' => array( '.woocommerce-product-gallery > .flex-viewport', 'append' ) );
            $hooks['before_title']['archive'] = array( 'astra_woo_shop_title_before' => array( 'priority' => 10 ) );
            $hooks['before_title']['single'] = array( 'astra_woo_single_title_before' => array( 'priority' => 10 ) );

            if ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'ast_load_product_quick_view' ) {
                $hooks['on_image']['archive']['post_thumbnail_html'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'post_thumbnail_html' ), 'args' => 4 );
            }

            return $hooks;

        }

        /*
         * Display on_image labels
         */
        public function post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size ) {

            if ( isset( $this->data['is_quick_view'] ) && $this->data['is_quick_view'] ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'on_image' );
                $this->data['is_quick_view'] = false;
            }

            return $html;

        }

        /*
         * Hide default out-of-stock flash if this option is enables
         */
        public function hide_default_stock_flash() {

            remove_action( 'woocommerce_shop_loop_item_title', 'astra_woo_shop_out_of_stock', 8 );
            add_filter( 'astra_woo_shop_out_of_stock_string', 'AWL_Integrations_Callbacks::return_empty_string', 999 );

        }

        /*
         * Hide sale flash
         */
        public function astra_addon_shop_cards_buttons_html( $markup ) {
            $markup = str_replace( 'class="ast-on-card-button ast-onsale-card', 'style="display:none;" class="ast-on-card-button ast-onsale-card', $markup );
            return $markup;
        }

        public function astra_woo_qv_product_image() {
            $this->data['is_quick_view'] = true;
        }

    }

endif;

AWL_Astra::instance();