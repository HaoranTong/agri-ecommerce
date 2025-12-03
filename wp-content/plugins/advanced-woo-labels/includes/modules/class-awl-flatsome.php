<?php

/**
 * AWL Flatsome theme plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Flatsome')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Flatsome {

        /**
         * @var AWL_Flatsome The single instance of the class
         */
        protected static $_instance = null;

        protected $data = array();

        /**
         * Main AWL_Flatsome Instance
         *
         * Ensures only one instance of AWL_Flatsome is loaded or can be loaded.
         *
         * @static
         * @return AWL_Flatsome - Main instance
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

            add_action( 'woocommerce_before_single_product_lightbox_summary', array( $this, 'woocommerce_before_single_product_lightbox_summary' ) );

            add_filter( 'awl_label_container_styles', array( $this, 'awl_label_container_styles' ), 1, 3 );

            add_filter( 'awl_enable_labels', array( $this, 'awl_enable_labels' ), 1 );

            add_filter( 'awl_js_container_selectors', array( $this, 'awl_js_container_selectors' ), 1 );

            add_filter( 'awl_labels_single_type_hooks', array( $this, 'awl_labels_single_type_hooks' ), 1 );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            if ( get_theme_mod( 'product_layout' ) === 'stacked-right' ) {

                $hooks['on_image']['single']['woocommerce_single_product_image_thumbnail_html'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'woocommerce_single_product_image_thumbnail_html' ), 'args' => 1 );

            } else {

                $hooks['on_image']['single']['woocommerce_before_single_product_summary'] = array( 'priority' => 10, 'js' => array( '.product-gallery .product-images', 'append' ) );

                $hooks['on_image']['single']['flatsome_sale_flash'] = array( 'priority' => 10 );

            }

            $hooks['on_image']['archive']['flatsome_woocommerce_shop_loop_images'] = array( 'priority' => 12 );
            $hooks['on_image']['archive']['flatsome_product_box_tools_top'] = array( 'priority' => 10, 'js' => array( '.box-image', 'append' )  );

            if ( isset( $_REQUEST['action'] ) && 'flatsome_quickview' === $_REQUEST['action'] ) {
                $hooks['before_title']['single'] = array( 'the_title' => array( 'priority' => 10, 'type' => 'filter', 'before' => true ) );
            }

            return $hooks;

        }

        /*
         * Show label for stacked layout images gallery
         */
        public function woocommerce_single_product_image_thumbnail_html( $html ) {
            $image = AWL_Label_Display::instance()->show_label( 'on_image' );
            if ( $image && ! isset( $this->data['stacked'] ) ) {
                $html = str_replace('<a', $image . '<a', $html );
                $this->data['stacked'] = true;
            }
            return $html;
        }

        /*
         * Product quick view pop-up
         */
        public function woocommerce_before_single_product_lightbox_summary() {
            echo AWL_Label_Display::instance()->show_label( 'on_image' );
        }

        /*
         * Change labels container styles
         */
        public function awl_label_container_styles( $styles, $position_type, $labels ) {

            global $wp_filter, $wp_current_filter;

            if ( $position_type === 'on_image' && in_array( 'flatsome_woocommerce_shop_loop_images', $wp_current_filter ) ) {
                $styles['z-index'] = '0';
            }

            return $styles;

        }

        /*
         * Enable labels for Flatsome preview
         */
        public function awl_enable_labels( $enable ) {

            if ( isset( $_REQUEST['action'] ) && 'flatsome_quickview' === $_REQUEST['action'] ) {
                $enable = true;
            }

            return $enable;

        }

        /*
         * JS selector for product container
         */
        public function awl_js_container_selectors( $container ) {
            $container[] = '.product-small';
            return $container;
        }

        /*
         * Detect single label type
         */
        public function awl_labels_single_type_hooks( $single_hooks ) {
            $single_hooks[] = 'flatsome_after_product_images';
            $single_hooks[] = 'flatsome_sale_flash';
            return $single_hooks;
        }

    }

endif;

AWL_Flatsome::instance();