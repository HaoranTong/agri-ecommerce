<?php

/**
 * YITH WooCommerce Dynamic Pricing and Discounts plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Yith_Discounts')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Yith_Discounts
    {
        /**
         * @var AWL_Yith_Discounts Custom data
         */
        public $data = array();

        /**
         * @var AWL_Yith_Discounts The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Yith_Discounts Instance
         *
         * Ensures only one instance of AWL_Yith_Discounts is loaded or can be loaded.
         *
         * @static
         * @return AWL_Yith_Discounts - Main instance
         */
        public static function instance() {
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /*
         * Constructor
         */
        public function __construct() {

            add_filter( 'awl_product_sale_price', array( $this, 'awl_product_sale_price' ), 1, 2 );

            add_filter( 'awl_is_on_sale', array( $this, 'awl_is_on_sale' ), 1, 2 );

            add_filter( 'yith_ywdpd_single_bulk_discount', array( $this, 'yith_ywdpd_single_bulk_discount' ), 10, 2 );
            add_filter( 'ywdpd_change_base_price', array( $this, 'yith_ywdpd_single_bulk_discount' ), 10, 2 );

            add_filter( 'awl_enable_discounts_cache', array( $this, 'awl_enable_discounts_cache' ), 1, 2 );

        }

        /*
         * Get discounted price
         */
        public function awl_product_sale_price( $sale_price, $product  ) {

            $yith_sale_price = $this->get_yith_discount( $product );

            if ( $yith_sale_price && ( ! $sale_price || ( $sale_price !== $yith_sale_price ) ) ) {
                $sale_price = $yith_sale_price;
            }

            return $sale_price;

        }

        /*
        * Get product sale status
        */
        public function awl_is_on_sale( $is_on_sale, $product  ) {
            if ( ! $is_on_sale ) {
                $yith_sale_price = $this->get_yith_discount( $product );
                if ( $yith_sale_price ) {
                    $is_on_sale = true;
                }
            }
            return $is_on_sale;
        }

        /*
        * Yith discounted price
        */
        private function get_yith_discount( $product ) {

            $sale_price = false;
            $product_id = $product->get_id();
            $price_html = $product->get_price_html();
            $regular_price = $product->get_price();

            $thousand_sep = get_option('woocommerce_price_thousand_sep');
            if ( $thousand_sep ) {
                $price_html = str_replace( $thousand_sep, '', $price_html );
            }

            $decimal_sep = get_option('woocommerce_price_decimal_sep');
            if ( $decimal_sep && $decimal_sep !== '.' ) {
                $price_html = str_replace( $decimal_sep, '.', $price_html );
            }

            $regex = '/\d[\.\d]*/i';

            if ( isset( $this->data['prices'] ) && isset( $this->data['prices'][$product_id] ) ) {

                if ( preg_match_all( $regex, $price_html, $matches ) ) {

                    $min_val = min( array_map('floatval', $matches[0]) );

                    if ( $min_val && $min_val != $regular_price ) {
                        $sale_price = $min_val;
                    }

                }

            }

            return $sale_price;

        }

        /*
         * Get new html for prices
         */
        public function yith_ywdpd_single_bulk_discount( $new_price, $product ) {
            $product_id = $product->get_id();
            $this->data['prices'][$product_id] = $new_price;
            return $new_price;
        }

        /*
         * Disable cache for products discounts values
         */
        public function awl_enable_discounts_cache( $enable ) {
            return false;
        }

    }

endif;

AWL_Yith_Discounts::instance();