<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Conditions_Check' ) ) :

    /**
     * AWL Conditions check class
     */
    class AWL_Conditions_Check {

        protected $conditions = null;
        protected $rule = null;


        /*
         * Constructor
         */
        public function __construct( $conditions ) {

            $this->conditions = $conditions;

        }


        /*
         * Match condition
         */
        public function match() {

            if ( empty( $this->conditions ) || ! is_array( $this->conditions ) ) {
                return false;
            }

            if ( ! isset( $GLOBALS['product'] ) ) {
                return false;
            }

            /**
             * Filter condition functions
             * @since 1.21
             * @param array Array of custom condition functions
             */
            $custom_match_functions = apply_filters( 'awl_labels_condition_rules', array() );

            $match = false;

            foreach ( $this->conditions as $condition_group ) {

                $rules_match = true;

                if ( $condition_group && ! empty( $condition_group ) ) {

                    foreach( $condition_group as $condition_rule ) {

                        /**
                         * Filter condition rule parameters
                         * @since 1.68
                         * @param array $this->rule Condition parameters
                         */
                        $condition_rule = apply_filters( 'awl_label_condition_rule', $condition_rule );

                        $this->rule = $condition_rule;

                        $condition_name = $condition_rule['param'];

                        if ( isset( $custom_match_functions[$condition_name] ) ) {
                            $match_rule = call_user_func( $custom_match_functions[$condition_name], $condition_rule );
                        } elseif ( method_exists( $this, 'match_' . $condition_name ) ) {
                            $match_rule = call_user_func( array( $this, 'match_' . $condition_name ) );
                        } else {
                            $match_rule = true;
                        }

                        /**
                         * Filter the result of condition rule
                         * @since 1.68
                         * @param bool $match_rule Result of condition matching
                         * @param string $condition_name Condition name
                         * @param array $condition_rule Condition parameters
                         */
                        $match_rule = apply_filters( 'awl_label_condition_match_rule', $match_rule, $condition_name, $condition_rule );

                        if ( ! $match_rule ) {
                            $rules_match = false;
                            break;
                        }

                    }

                }

                if ( $rules_match ) {
                    $match = true;
                    break;
                }

            }


            return $match;

        }


        /*
         * Compare values
         * @param $value
         * @return bool
         */
        private function compare_values( $compare_value ) {

            global $product;

            /**
             * Filter condition value before compare
             * @since 1.23
             * @param string|integer $compare_value Value to compare with
             * @param array $this->rule Condition parameters
             * @param object $product Current product
             */
            $compare_value = apply_filters( 'awl_label_condition_compare_value', $compare_value, $this->rule, $product );

            $match = false;
            $value = isset( $this->rule['value'] ) ? $this->rule['value'] : '';
            $operator = $this->rule['operator'];

            if ( is_bool( $compare_value )  ) {
                $compare_value = $compare_value ? 'true' : 'false';
            }

            if ( 'equal' == $operator ) {
                $match = ($compare_value == $value);
            } elseif ( 'not_equal' == $operator ) {
                $match = ($compare_value != $value);
            } elseif ( 'greater' == $operator ) {
                $match = ($compare_value >= $value);
            } elseif ( 'less' == $operator ) {
                $match = ($compare_value <= $value);
            } elseif ( 'contains' == $operator ) {
                $match = strpos( $compare_value, $value) !== false;
            } elseif ( 'not_contains' == $operator ) {
                $match = strpos( $compare_value, $value) === false;
            }

            return $match;

        }


        /*
         * Product stock status rule
         */
        public function match_stock_status() {

            global $product;
            $value = $product->get_stock_status();

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }


        /*
         * Product visibility rule
         */
        public function match_visibility() {

            global $product;

            if ( method_exists( $product, 'get_catalog_visibility' ) ) {
                $value = $product->get_catalog_visibility();
            } elseif ( method_exists( $product, 'get_visibility' ) ) {
                $value = $product->get_visibility();
            } else  {
                $value = $product->visibility;
            }

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }


        /*
         * Product price rule
         */
        public function match_price() {

            global $product;

            $this->rule['value'] = floatval( $this->rule['value'] );

            if ( isset( $this->rule['suboption'] ) && $this->rule['suboption'] === 'sale' ) {
                $value = $product->get_sale_price();
            } elseif( isset( $this->rule['suboption'] ) && $this->rule['suboption'] === 'regular' ) {
                $value = $product->get_regular_price();
            } else {
                $value = $product->get_price();
            }

            $value = floatval( $value );

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }

        /*
         * Product sale discount rule
         */
        public function match_sale_discount() {

            global $product;

            if ( ! isset( $this->rule['suboption'] ) ) {
                return false;
            }

            if ( $this->rule['suboption'] === 'percents' ) {
                $value = AWL_Product_Data::get_discount_percent( $product );
            }

            if ( $this->rule['suboption'] === 'amount' ) {
                $value = AWL_Product_Data::get_discount_amount( $product );
            }

            $decimals = strpos(strrev($this->rule['value']), ".");
            if ( ! $decimals ) {
                $decimals = 0;
            }

            $value = round( $value, $decimals );

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }

        /*
         * Product quantity rule
         */
        public function match_quantity() {

            global $product;

            $value = AWL_Product_Data::get_quantity( $product );

            if ( is_bool( $value ) && ! $value ) {
                return false;
            }

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }


        /*
         * Product shipping class rule
         */
        public function match_shipping_class() {
            global $product;
            $value = $product->get_shipping_class_id();
            if ( ! $value ) {
                $value = 'none';
            }
            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );
        }


        /*
         * Product rating rule
         */
        public function match_rating() {
            global $product;

            $value = $product->get_average_rating();

            if ( ! $value ) {
                return false;
            }

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }


        /*
         * Product reviews count rule
         */
        public function match_reviews_count() {
            global $product;

            $date_query = 'all';

            if ( isset( $this->rule['suboption'] ) && $this->rule['suboption'] !== 'all' ) {

                $date_query = array();

                switch ( $this->rule['suboption'] ) {
                    case 'hour':
                        $date_query =  array( array( 'after' => '24 hours ago' ) );
                        break;
                    case 'week':
                        $date_query =  array( array( 'after' => '1 week ago' ) );
                        break;
                    case 'month':
                        $date_query =  array( array( 'after' => '30 days ago' ) );
                        break;
                    case 'year':
                        $date_query =  array( array( 'after' => '1 year ago' ) );
                        break;
                }

            }

            $value = AWL_Product_Data::get_reviews_count( $date_query, $product );

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }


        /*
         * Product sale status rule
         */
        public function match_sale_status() {
            global $product;
            $value = AWL_Product_Data::is_on_sale( $product );
            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );
        }


        /*
         * Product featured rule
         */
        public function match_featured() {
            global $product;
            $value = $product->is_featured();
            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );
        }


        /*
         * Product has image rule
         */
        public function match_has_image() {
            global $product;
            $value = !! $product->get_image_id();
            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );
        }


        /*
         * Product has gallery rule
         */
        public function match_has_gallery() {
            global $product;
            $value = !! $product->get_gallery_image_ids();
            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );
        }


        /*
         * Product rule
         */
        public function match_product() {
            global $product;
            $value = $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ? $product->get_parent_id() : $product->get_id();
            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );
        }


        /*
         * Product category rule
         */
        public function match_product_category() {
            global $product;

            $product_id = $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ? $product->get_parent_id() : $product->get_id();
            $value = has_term( $this->rule['value'], 'product_cat', $product_id );
            $operator = $this->rule['operator'];

            if ( ! $value && apply_filters( 'awl_labels_condition_include_descendants', false ) ) {
                $assigned = wc_get_product_term_ids( $product_id, 'product_cat' );
                foreach ( $assigned as $term_id ) {
                    if ( term_is_ancestor_of( $this->rule['value'], (int) $term_id, 'product_cat' ) ) {
                        $value = true;
                    }
                }
            }

            if ( 'equal' == $operator ) {
                return $value;
            } else {
                return !$value;
            }

        }


        /*
         * Product tag rule
         */
        public function match_product_tag() {
            global $product;

            $product_id = $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ? $product->get_parent_id() : $product->get_id();
            $value = has_term( $this->rule['value'], 'product_tag', $product_id );
            $operator = $this->rule['operator'];

            if ( 'equal' == $operator ) {
                return $value;
            } else {
                return !$value;
            }

        }


        /*
         * User rule
         */
        public function match_user() {

            if ( ! is_user_logged_in() ) {
                return false;
            }

            $value = get_current_user_id();

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }


        /*
         * User role rule
         */
        public function match_user_role() {

            $role = $this->rule['value'];

            if ( is_user_logged_in() ) {
                global $current_user;
                $roles = (array) $current_user->roles;
            } else {
                $roles = array( 'non-logged' );
            }

            $value = array_search( $role, $roles ) !== false;

            if ( 'equal' == $this->rule['operator'] ) {
                return $value;
            } else {
                return !$value;
            }

        }


        /*
         * Page rule
         */
        public function match_page() {

            global $wp_query;

            if ( is_shop() ) {
                $value = wc_get_page_id( 'shop' );
            } elseif ( is_cart() ) {
                $value = wc_get_page_id( 'cart' );
            } elseif ( is_checkout() ) {
                $value = wc_get_page_id( 'checkout' );
            } elseif ( is_account_page() ) {
                $value = wc_get_page_id( 'myaccount' );
            } else {
                $value = $wp_query->get_queried_object_id();
            }

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }


        /*
         * Page language rule
         */
        public function match_page_language() {

            if ( ! AWL_Helpers::is_lang_plugin_active() ) {
                return true;
            }

            $value = AWL_Helpers::get_current_lang();

            return call_user_func_array( array( $this, 'compare_values' ), array( $value ) );

        }


    }

endif;