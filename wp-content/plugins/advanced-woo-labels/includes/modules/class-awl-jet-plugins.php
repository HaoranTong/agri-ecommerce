<?php

/**
 * Integration with Crocoblock plugins like JetEngine, JetWooBuilder For Elementor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Jet')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Jet {

        /**
         * @var AWL_Jet Custom data
         */
        public $data = array();

        /**
         * @var AWL_Jet The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Jet Instance
         *
         * Ensures only one instance of AWL_Jet is loaded or can be loaded.
         *
         * @static
         * @return AWL_Jet - Main instance
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

            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 3 );

            add_filter( 'awl_js_container_selectors', array( $this, 'awl_js_container_selectors' ), 1 );

            add_filter( 'jet-engine/listing/pre-get-item-content', array( $this, 'jet_pre_get_item_content' ), 10, 2 );

            add_filter( 'jet-engine/elementor-views/frontend/listing-content', array( $this, 'listing_content' ) );

            add_action( 'wp_head', array( $this, 'add_jet_styles' ) );

            if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'jet_smart_filters' ) {
                add_action( 'init', array( $this, 'init' ), 5 );
            }

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive']['jet-woo-builder/templates/products/after-item-thumbnail'] = array( 'priority' => 10 );
            $hooks['on_image']['archive']['jet-woo-builder/templates/products-list/after-item-thumbnail'] = array( 'priority' => 10 );

            if ( is_ajax() ) {

                $hooks['before_title']['archive']['jet-woo-builder/templates/products/after-item-thumbnail'] = array( 'priority' => 10 );
                $hooks['before_title']['archive']['jet-woo-builder/templates/products-list/after-item-thumbnail'] = array( 'priority' => 10 );

            } else {

                $hooks['before_title']['archive']['jet-woo-builder/templates/products/before-item-thumbnail'] = array( 'priority' => 10, 'js' => array( '.jet-woo-product-title', 'prepend' ) );
                $hooks['before_title']['archive']['jet-woo-builder/templates/products-list/before-item-thumbnail'] = array( 'priority' => 10, 'js' => array( '.jet-woo-product-title', 'prepend' ) );

            }

            return $hooks;

        }

        /*
         * JS selector for product container
         */
        public function awl_js_container_selectors( $container ) {

            $container[] = '[data-product-id]';

            return $container;

        }

        /*
         * Check if the listings contains products
         */
        public function jet_pre_get_item_content( $content, $post_obj ) {
            $this->data['is_product_listing'] = false;
            if ( $post_obj && property_exists( $post_obj, 'post_type' ) && in_array( $post_obj->post_type, array( 'product', 'product_variation' ) ) ) {
                $this->data['is_product_listing'] = true;
            }
            return $content;
        }

        /*
         * Add labels for jet listing items
         */
        function listing_content( $content ) {

            if ( isset( $this->data['is_product_listing'] ) && $this->data['is_product_listing'] ) {


                $on_image_label = AWL_Label_Display::instance()->show_label( 'on_image' );
                $on_title_label = AWL_Label_Display::instance()->show_label( 'before_title' );


                if ( strpos( $content, 'awl-position-type-on-image' ) === false ) {
                    $content = preg_replace( '/<img[\S\s]*?>/i', '<div style="position:relative;">${0}' . $on_image_label . '</div>', $content );
                }

                if ( strpos( $content, 'awl-position-type-before-title' ) === false ) {
                    preg_match( '/<h[\d]{1}/i', $content, $matches );
                    if ( $matches ) {
                        $content = preg_replace( '/' . $matches[0] . '/i', $on_title_label . $matches[0], $content );
                    }
                }

            }

            return $content;

        }

        /*
         * Add styles for JET product blocks
         */
        public function add_jet_styles() { ?>

            <style>
                .jet-woo-products-list .jet-woo-product-thumbnail {
                    position: relative;
                }
            </style>

        <?php }

        /*
         * Fix: Call labels hooks for filters ajax actions
         */
        public function init() {
            AWL_Label_Display::instance();
        }

    }

endif;

AWL_Jet::instance();