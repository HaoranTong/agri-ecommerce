<?php

/**
 * Advanced Woo Search plugin integration
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_AWS')) :

    /**
     * Class for main plugin functions
     */
    class AWL_AWS {

        /**
         * @var AWL_AWS Custom data
         */
        public $data = array();

        /**
         * @var AWL_AWS The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_AWS Instance
         *
         * Ensures only one instance of AWL_AWS is loaded or can be loaded.
         *
         * @static
         * @return AWL_AWS - Main instance
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

            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

            if ( AWL()->get_settings( 'show_default_sale' ) === 'false' ) {
                add_filter( 'aws_search_pre_filter_products', array( $this, 'aws_search_pre_filter_products' ) );
            }

            add_filter( 'awl_label_rules', array( $this, 'label_rules' ), 1 );

            add_filter( 'awl_labels_condition_rules', array( $this, 'condition_rules' ), 1 );

            add_filter( 'awl_label_container_styles', array( $this, 'awl_label_container_styles' ), 1, 3 );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {
            
            $hooks['on_image']['archive']['aws_title_search_result'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'title_search_result' ), 'args' => 3 );
            
            return $hooks;

        }

        /*
         * Display labels inside AWS plugin ajax box
         */
        public function title_search_result( $title, $post_id, $product ) {
            if ( defined( 'DOING_AJAX' ) && DOING_AJAX  ) {
                $title = '<div class="aws_result_labels">' . AWL_Label_Display::instance()->show_label( 'on_image' ) . AWL_Label_Display::instance()->show_label( 'before_title' ) . '</div>' . $title;
            }
            return $title;
        }

        /*
         * Hide default sale flash if this option is enables
         */
        public function aws_search_pre_filter_products( $products_array ) {
            if ( $products_array ) {
                foreach ( $products_array as $product_key => $product_item ) {
                    $products_array[$product_key]['on_sale'] = '';
                }
            }
            return $products_array;
        }

        /*
         * Add new label conditions for admin
         */
        public function label_rules( $options ) {

            $options['Special'][] = array(
                "name" => __( "AWS: Is inside ajax results", "advanced-woo-labels" ),
                "id"   => "aws_is_ajax_results",
                "type" => "bool",
                "operators" => "equals",
            );

            $options['Special'][] = array(
                "name" => __( "AWS: Is inside results page", "advanced-woo-labels" ),
                "id"   => "aws_is_results_page",
                "type" => "bool",
                "operators" => "equals",
            );

            return $options;

        }

        /*
         * Add custom condition rule method
         */
        public function condition_rules( $rules ) {
            $rules['aws_is_ajax_results'] = array( $this, 'aws_is_ajax_results' );
            $rules['aws_is_results_page'] = array( $this, 'aws_is_results_page' );
            return $rules;
        }

        /*
         * Change label cntainer styles
         */
        public function awl_label_container_styles( $styles, $position_type, $labels ) {
            if ( isset( $styles['flex-direction'] ) && $styles['flex-direction'] === 'column' ) {

                global $wp_filter, $wp_current_filter;
                $current_filter = array_slice( $wp_current_filter, -2, 1 );

                $current_filter = isset( $current_filter[0] ) ? $current_filter[0] : false;

                if ( $current_filter && array_search( 'aws_title_search_result', $wp_current_filter ) !== false ) {
                    $styles['flex-direction'] = 'row';
                    $styles['align-items'] = 'stretch';
                    $styles['gap'] = '5px';
                }

            }
            return $styles;
        }

        /*
         * Condition: Is AWS plugin ajax results
         */
        public function aws_is_ajax_results( $condition_rule ) {
            global $product;

            $match = false;
            $operator = $condition_rule['operator'];
            $value = $condition_rule['value'];
            $compare_value = 'false';

            if ( is_ajax() && isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'aws_action' ) {
                $compare_value = 'true';
            }

            if ( 'equal' == $operator ) {
                $match = ($compare_value == $value);
            } elseif ( 'not_equal' == $operator ) {
                $match = ($compare_value != $value);
            }

            return $match;

        }

        /*
         * Condition: Is AWS plugin results page
         */
        public function aws_is_results_page( $condition_rule ) {
            global $product;

            $match = false;
            $operator = $condition_rule['operator'];
            $value = $condition_rule['value'];
            $compare_value = 'false';

            if ( isset( $_REQUEST['type_aws'] ) && $_REQUEST['type_aws'] === 'true' && isset( $_REQUEST['post_type'] ) && $_REQUEST['post_type'] === 'product' ) {
                $compare_value = 'true';
            }

            if ( 'equal' == $operator ) {
                $match = ($compare_value == $value);
            } elseif ( 'not_equal' == $operator ) {
                $match = ($compare_value != $value);
            }

            return $match;

        }

    }

endif;

AWL_AWS::instance();