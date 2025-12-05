<?php

/**
 * AWL Woo Discount Rules plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Wdr')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Wdr {

        /**
         * @var AWL_Wdr The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Wdr Instance
         *
         * Ensures only one instance of AWL_Wdr is loaded or can be loaded.
         *
         * @static
         * @return AWL_Wdr - Main instance
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

            add_filter( 'awl_product_price', array( $this, 'awl_product_price' ), 1, 2 );
            add_filter( 'awl_product_sale_price', array( $this, 'awl_product_sale_price' ), 1, 2 );
            add_filter( 'awl_enable_discounts_cache', array( $this, 'awl_enable_discounts_cache' ), 1, 2 );

        }

        /*
         * Filter product price
         */
        public function awl_product_price( $price, $product ) {
            $discount = apply_filters( 'advanced_woo_discount_rules_get_product_discount_price_from_custom_price', $price, $product, 1, $price, 'discounted_price', true, true );
            if ( $discount ) {
                $price = $discount;
            }
            return $price;
        }

        /*
         * Filter product sale price
         */
        public function awl_product_sale_price( $price, $product ) {
            $reg_price = $product->get_regular_price();
            $discount = apply_filters( 'advanced_woo_discount_rules_get_product_discount_price_from_custom_price', $reg_price, $product, 1, $reg_price, 'discounted_price', true, true );
            if ( $discount && $discount < $price ) {
                $price = $discount;
            }
            return $price;
        }

        /*
         * Disable cache for products discounts values
         */
        public function awl_enable_discounts_cache( $enable ) {
            return false;
        }

    }

endif;

AWL_Wdr::instance();