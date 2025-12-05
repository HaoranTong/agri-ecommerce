<?php

/**
 * AWL Shopengine plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Shopengine')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Shopengine {

        /**
         * @var AWL_Shopengine Custom data
         */
        public $data = array();

        /**
         * @var AWL_Shopengine Blocks names array
         */
        public $block_names = array( 'shopengine-product-list', 'shopengine-filterable-product-list' );

        /**
         * @var AWL_Shopengine The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Shopengine Instance
         *
         * Ensures only one instance of AWL_Shopengine is loaded or can be loaded.
         *
         * @static
         * @return AWL_Shopengine - Main instance
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

            add_action( 'elementor/widget/before_render_content', array( $this, 'before_render_content' ) );
            add_filter( 'elementor/widget/render_content', array( $this, 'render_content' ), 10, 2 );

            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

            add_filter( 'awl_js_container_selectors', array( $this, 'awl_js_container_selectors' ), 1 );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {
            $hooks['on_image']['archive']['woocommerce_product_get_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array($this, 'woocommerce_product_get_image'), 'args' => 3 );
            $hooks['before_title']['archive']['woocommerce_product_get_image'] = array( 'priority' => 11, 'type' => 'filter', 'js' => array( '.product-title', 'before' ), 'callback' => array($this, 'woocommerce_product_get_image_2'), 'args' => 3 );
            return $hooks;
        }

        /*
         * Show labels
         */

        public function before_render_content( $block ) {
            if ( array_search( $block->get_name(), $this->block_names ) !== false ) {
                $this->data['show_labels'] = true;
            }
        }

        public function render_content( $widget_content, $block ) {
            $this->data['show_labels'] = false;
            return $widget_content;
        }

        public function woocommerce_product_get_image( $image, $tthis, $size ) {
            if ( isset( $this->data['show_labels'] ) && $this->data['show_labels'] ) {
                $image = $image . AWL_Label_Display::instance()->show_label( 'on_image' );
            }
            return $image;
        }

        public function woocommerce_product_get_image_2( $image, $tthis, $size ) {
            if ( isset( $this->data['show_labels'] ) && $this->data['show_labels'] ) {
                $image = $image . AWL_Label_Display::instance()->show_label( 'before_title' );
            }
            return $image;
        }

        /*
         * JS selector for product container
         */
        public function awl_js_container_selectors( $container ) {
            $container[] = '.shopengine-single-product-item';
            return $container;
        }
        
    }

endif;

AWL_Shopengine::instance();