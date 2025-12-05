<?php

/**
 * AWL Discount Rules and Dynamic Pricing for WooCommerce plugin integration
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Easy_Discounts')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Easy_Discounts {

        /**
         * @var AWL_Easy_Discounts The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Easy_Discounts Instance
         *
         * Ensures only one instance of AWL_Easy_Discounts is loaded or can be loaded.
         *
         * @static
         * @return AWL_Easy_Discounts - Main instance
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

            // fix text vars
            add_filter( 'awl_label_text_var_value', array( $this, 'awl_label_text_var_value' ), 1, 4 );

            // fix display conditions
            add_filter( 'awl_label_condition_match_rule', array( $this, 'awl_label_condition_match_rule' ), 1, 3 );

        }

        /*
         * Fix sale date display conditions
         */
        public function awl_label_condition_match_rule( $match_rule, $condition_name, $condition_rule ) {

            if ( 'sale_date' === $condition_name ) {

                global $product;

                if ( ! $product->is_on_sale() && ! $product->get_sale_price() ) {
                    return $match_rule;
                }

                $sales = $this->get_product_sale_dates( $product );

                if ( ! $sales ) {
                    return $match_rule;
                }

                $time_frame = strpos( $condition_rule['suboption'], 'hours' ) !== false ? 'hours' : 'days';
                $compared = 0;
                $value = 0;
                $value_set = $condition_rule['value'];

                // Sale starts from rules
                if ( isset( $sales['start'] ) && $sales['start'] && ( $condition_rule['suboption'] === 'start_hours' || $condition_rule['suboption'] === 'start_days' ) ) {
                    $compared = date_i18n( 'Y-m-d-H', $sales['start'] );
                    $value = date_i18n( 'Y-m-d-H', strtotime( "-$value_set $time_frame", time() ) );
                }

                // Sale ends in rules
                if ( isset( $sales['end'] ) && $sales['end'] && ( $condition_rule['suboption'] === 'end_hours' || $condition_rule['suboption'] === 'end_days' ) ) {
                    $value = date_i18n( 'Y-m-d-H', $sales['end'] );
                    $compared = date_i18n( 'Y-m-d-H', strtotime( "+$value_set $time_frame", time() ) );
                }

                $value_set = $compared;
                $operator = $condition_rule['operator'];

                if ( 'equal' == $operator ) {
                    $match_rule = ($value == $value_set);
                } elseif ( 'not_equal' == $operator ) {
                    $match_rule = ($value != $value_set);
                } elseif ( 'greater' == $operator ) {
                    $match_rule = ($value >= $value_set);
                } elseif ( 'less' == $operator ) {
                    $match_rule = ($value <= $value_set);
                }

            }

            return $match_rule;

        }

        /*
         * Fix sale date text variable
         */
        public function awl_label_text_var_value( $text, $matches, $replacement, $product ) {

            if ( '{SALE_ENDS}' === $matches ) {

                if ( ! $product->is_on_sale() && ! $product->get_sale_price() ) {
                    return $text;
                }

                $sales = $this->get_product_sale_dates( $product );
                if ( $sales && isset( $sales['end'] ) && $sales['end'] ) {
                    $text = round( ( $sales['end'] - $sales['now'] ) / ( 60 * 60 * 24 ) );
                }

            }

            return $text;

        }

        /*
         * Get sale dates for current product
         */
        private function get_product_sale_dates( $product ) {

            $sales = array();

            if ( ! function_exists('WCCS') || ! class_exists('WCCS_Public_Product_Pricing') ) {
                return $sales;
            }

            $pricing = WCCS()->pricing;

            if ( ! $pricing ) {
                return $sales;
            }

            $pp = new WCCS_Public_Product_Pricing( $product, $pricing );

            $discounts = $pp ? $pp->get_simple_discounts() : false;

            if ( $discounts ) {

                foreach ( $discounts as $discount ) {

                    $date_times = $discount['date_time'];

                    if ( is_array( $date_times[0] ) && ! isset( $date_times[0]['type'] ) ) {
                        foreach ( $date_times as $group ) {

                            if ( empty( $group ) ) {
                                continue;
                            }

                            foreach ( $group as $date_time ) {

                                if ( ! empty( $date_time ) && ! empty( $date_time['type'] ) ) {
                                    if ( in_array( $date_time['type'], array( 'date', 'date_time' ) ) ) {

                                        if ( ! empty( $date_time['end']['time'] ) || ! empty( $date_time['start']['time'] ) ) {

                                            $format = 'date_time' === $date_time['type'] ? 'Y-m-d H:i' : 'Y-m-d';
                                            $now    = strtotime( date( $format, current_time( 'timestamp' ) ) );

                                            $sales['format'] = $format;
                                            $sales['now'] = $now;

                                            if ( ! empty( $date_time['end']['time'] )  ) {
                                                $end_date = strtotime( date( $format, strtotime( $date_time['end']['time'] ) ) );
                                                if ( false === $end_date || $now > $end_date ) {
                                                    $sales['end'] = false;
                                                } else {
                                                    $sales['end'] = $end_date;
                                                }
                                            }

                                            if ( ! empty( $date_time['start']['time'] )  ) {

                                                $start_date = strtotime( date( $format, strtotime( $date_time['start']['time'] ) ) );
                                                if ( false === $start_date || $now < $start_date ) {
                                                    $sales['start'] = false;
                                                } else {
                                                    $sales['start'] = $start_date;
                                                }

                                            }

                                        }

                                    }
                                }

                            }
                        }
                    }

                }
            }

            return $sales;

        }

    }

endif;

AWL_Easy_Discounts::instance();