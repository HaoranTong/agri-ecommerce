<?php

/**
 * AWL Polylang plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Polylang')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Polylang {

        /**
         * @var AWL_Polylang Custom data
         */
        public $data = array();

        /**
         * @var AWL_Polylang The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Polylang Instance
         *
         * Ensures only one instance of AWL_Polylang is loaded or can be loaded.
         *
         * @static
         * @return AWL_Polylang - Main instance
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

            add_filter( 'pll_get_post_types', array( $this, 'pll_get_post_types' ), 10, 2 );

        }

        /*
         * Enable translation for labels post types
         */
        public function pll_get_post_types( $post_types, $is_settings ) {
            if ( $is_settings ) {
                $post_types['awl-labels'] = 'awl-labels';
            }
            return $post_types;
        }

    }

endif;

AWL_Polylang::instance();