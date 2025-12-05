<?php

/**
 * AWL Xstore theme support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Xstore')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Xstore {

        /**
         * @var AWL_Xstore Custom data
         */
        public $data = array();

        /**
         * @var AWL_Xstore The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Xstore Instance
         *
         * Ensures only one instance of AWL_Xstore is loaded or can be loaded.
         *
         * @static
         * @return AWL_Xstore - Main instance
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

            // Display hooks
            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

            // Hide sale flash
            add_action( 'awl_hide_default_sale_flash', array( $this, 'hide_default_sale_flash' ), 1 );

            // Hide stock flash
            add_action( 'awl_hide_default_stock_flash', array( $this, 'hide_default_stock_flash' ), 1 );

            // Add custom css
            add_action( 'wp_head', array( $this, 'add_custom_styles' ) );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['single']['woocommerce_single_product_image_thumbnail_html'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'xstore_single_image' ), 'args' => 1 );
            $hooks['on_image']['archive']['etheme_product_grid_list_product_element_image'] = array( 'priority' => 10, 'type' => 'filter' );

            $hooks['before_title']['archive']['before_etheme_product_grid_list_product_element_title'] = array( 'priority' => 10 );
            $hooks['before_title']['archive']['woocommerce_before_shop_loop_item_title'] = array( 'priority' => 20, 'callback' => array( $this, 'xstore_title_centered' ) );
            if ( get_option( 'etheme_single_product_builder', false ) ) {
                $hooks['before_title']['single']['woocommerce_single_product_image_thumbnail_html'] = array( 'priority' => 11, 'type' => 'filter', 'callback' => array( $this, 'xstore_single_image_title' ), 'args' => 1, 'js' => array( '.product_title.entry-title', 'before' ) );
            }

            return $hooks;

        }

        /*
         * Hide default sale flash if this option is enables
         */
        public function hide_default_sale_flash() {
            remove_filter( 'woocommerce_sale_flash', 'etheme_woocommerce_sale_flash', 20, 3 );
            add_filter( 'etheme_product_gallery_sale_flash', '__return_false' );
        }

        /*
         * Hide default out-of-stock flash if this option is enables
         */
        public function hide_default_stock_flash() {
            add_filter( 'woocommerce_get_stock_html', 'AWL_Integrations_Callbacks::return_empty_string', 999 );
        }

        /*
         * Add custom css
         */
        public function add_custom_styles() {
            echo '<style>.single-product-builder .related-products .product-content-image .advanced-woo-labels, .single-product-builder .bought-together-products .advanced-woo-labels { display: none !important; }</style>';
        }

        public function xstore_single_image( $html ) {
            if ( strpos( $html, 'thumbnail-item' ) === false ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'on_image' );
            }
            return $html;
        }

        public function xstore_single_image_title( $html ) {
            if ( strpos( $html, 'woocommerce-main-image pswp-main-image' ) !== false && strpos( $html, 'awl-position-type-before-title' ) === false  ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'before_title' );
            }
            return $html;
        }

        public function xstore_title_centered() {
            $label = AWL_Label_Display::instance()->show_label( 'before_title' );
            $label = str_replace( 'justify-content:flex-start;', 'justify-content:center;', $label );
            echo '<div style="margin-top: 10px;">' . $label . '</div>';
        }

    }

endif;

AWL_Xstore::instance();