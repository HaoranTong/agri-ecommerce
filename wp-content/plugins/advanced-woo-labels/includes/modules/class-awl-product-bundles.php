<?php

/**
 * AWL WooCommerce Product Bundles plugin integration
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Product_Bundles')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Product_Bundles {

        /**
         * @var AWL_Product_Bundles The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Product_Bundles Instance
         *
         * Ensures only one instance of AWL_Product_Bundles is loaded or can be loaded.
         *
         * @static
         * @return AWL_Product_Bundles - Main instance
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

            add_filter( 'awl_label_condition_match_rule', array( $this, 'awl_label_condition_match_rule' ), 10, 3 );

        }

        /*
         * Rewrite stock_status display conditions. Fix bundle products stock status
         */
        public function awl_label_condition_match_rule( $match_rule, $condition_name, $condition_rule ) {

            if ( 'stock_status' === $condition_name ) {

                global $product;

                if ( $product->is_type( 'bundle' ) ) {

                    global $product;
                    $value = $product->get_stock_status();

                    if ( ! $product->is_in_stock() ) {
                        $value = 'outofstock';
                    }

                    $compare_value = $condition_rule['value'];

                    if ( 'equal' == $condition_rule['operator'] ) {
                        $match_rule = ($compare_value == $value);
                    } else {
                        $match_rule = ($compare_value != $value);
                    }

                }

            }

            return $match_rule;

        }

    }

endif;

AWL_Product_Bundles::instance();