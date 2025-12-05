<?php

/**
 * AWL Product Slider for WooCommerce plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_SP_Slider')) :

    /**
     * Class for main plugin functions
     */
    class AWL_SP_Slider {

        /**
         * @var AWL_SP_Slider Custom data
         */
        public $data = array();

        /**
         * @var AWL_SP_Slider The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_SP_Slider Instance
         *
         * Ensures only one instance of AWL_SP_Slider is loaded or can be loaded.
         *
         * @static
         * @return AWL_SP_Slider - Main instance
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

            add_action( 'wp_footer', array( $this, 'wp_footer_styles' ) );

            add_filter( 'awl_js_container_selectors', array( $this, 'awl_js_container_selectors' ) );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive_slide']['post_thumbnail_html'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'post_thumbnail_html' ), 'args' => 4 );
            $hooks['before_title']['archive_slide']['post_thumbnail_html'] = array( 'priority' => 9, 'type' => 'filter', 'callback' => array( $this, 'post_thumbnail_html_title' ), 'args' => 4, 'js' =>  array( '.wpsf-product-title', 'prepend' )  );

            $hooks['on_image']['archive_slide']['sp_wpspro_product_image'] = array( 'priority' => 10, 'type' => 'filter' );
            $hooks['before_title']['archive_slide']['sp_wpspro_product_image'] = array( 'priority' => 9, 'type' => 'filter', 'js' =>  array( '.wpsf-product-title', 'prepend' )  );

            return $hooks;

        }

        /*
         * Display on_image labels
         */
        public function post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size ) {

            $is_slider = $this->is_inside_slider();

            if ( $is_slider ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'on_image' );
            }

            return $html;

        }

        /*
        * Display before_title labels
        */
        public function post_thumbnail_html_title( $html, $post_id, $post_thumbnail_id, $size ) {

            $is_slider = $this->is_inside_slider();

            if ( $is_slider ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'before_title' );
            }

            return $html;

        }

        /*
         * Is slider condition
         */
        private function is_inside_slider() {
            $is_slider = false;
            foreach ( debug_backtrace() as $trace_part ) {
                if ( isset( $trace_part['class'] ) && in_array( $trace_part['class'], array( 'SP_WPS_ShortCode', 'ShapedPlugin\WooProductSlider\Frontend\Frontend' ) ) ) {
                    $is_slider = true;
                    break;
                }
            }
            return $is_slider;
        }

        /*
         * Add addition styles
         */
        public function wp_footer_styles() {
            echo '<style>#wps-slider-section .wps-product-image, .sp-wps-product-image-area { position: relative; }</style>';
        }

        /*
         * Add custom container selector for js hooks
         */
        public function awl_js_container_selectors( $product_container ) {
            $product_container[] = '.wpsf-product';
            return $product_container;
        }

    }

endif;

AWL_SP_Slider::instance();