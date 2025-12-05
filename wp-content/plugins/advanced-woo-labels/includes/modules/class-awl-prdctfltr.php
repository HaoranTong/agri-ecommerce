<?php

/**
 * Product Filter for WooCommerce by XforWooCommerce plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Prdctfltr')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Prdctfltr {

        /**
         * @var AWL_Prdctfltr The single instance of the class
         */
        protected static $_instance = null;

        protected $data = array();

        /**
         * Main AWL_Prdctfltr Instance
         *
         * Ensures only one instance of AWL_Prdctfltr is loaded or can be loaded.
         *
         * @static
         * @return AWL_Prdctfltr - Main instance
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

            add_action( 'wp_loaded', array( $this, 'wp_loaded' ), 0 );

        }
        
        /*
         * Init labels display class
         */
        public function wp_loaded() {
            if ( is_ajax() && class_exists( 'AWL_Label_Display' ) ) {
                AWL_Label_Display::instance();
            }
        }

    }

endif;

AWL_Prdctfltr::instance();