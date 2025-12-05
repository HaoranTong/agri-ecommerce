<?php

/**
 * AWL Woodmart theme support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Woodmart')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Woodmart {

        /**
         * @var AWL_Woodmart Custom data
         */
        public $data = array();

        /**
         * @var AWL_Woodmart The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Woodmart Instance
         *
         * Ensures only one instance of AWL_Woodmart is loaded or can be loaded.
         *
         * @static
         * @return AWL_Woodmart - Main instance
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

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['single'] = array( 'woocommerce_single_product_summary' => array( 'priority' => 10, 'js' =>  array( '.woocommerce-product-gallery figure', 'append' ) ) );
            $hooks['on_image']['single']['woodmart_on_product_image'] = array( 'priority' => 10 );
       
            return $hooks;

        }

        /*
         * Hide default sale flash if this option is enables
         */
        public function hide_default_sale_flash() {
            add_filter( 'woodmart_product_label_output', array( $this, 'hide_sale_flash' ), 10, 1 );
        }

        public function hide_sale_flash( $output ) {
            $output = str_replace( 'span class="onsale product-label"', 'span style="display:none;" class="onsale product-label"', $output );
            return $output;
        }

    }

endif;

AWL_Woodmart::instance();