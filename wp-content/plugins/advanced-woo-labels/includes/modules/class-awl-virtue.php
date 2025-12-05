<?php

/**
 * AWL Virtue theme support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Virtue')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Virtue {

        /**
         * @var AWL_Virtue The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Virtue Instance
         *
         * Ensures only one instance of AWL_Virtue is loaded or can be loaded.
         *
         * @static
         * @return AWL_Virtue - Main instance
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

            add_filter( 'awl_labels_hooks', array( $this, 'labels_hooks' ), 2 );

            add_action( 'wp_head', array( $this, 'styles' ) );


        }

        /*
         * Change display hooks
         */
        public function labels_hooks( $hooks ) {
            $hooks['on_image']['single']['woocommerce_product_thumbnails'] = array( 'priority' => 10, 'js' => array( '.product_image', 'append' ) );
            return $hooks;
        }

        /*
         * Add custom styles
         */
        public function styles() { ?>

            <style>
                .single .woocommerce-product-gallery .product_image,
                .product .product_item_link {
                    position: relative;
                }
            </style>

        <?php }

    }

endif;

AWL_Virtue::instance();