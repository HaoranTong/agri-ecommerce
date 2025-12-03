<?php

/**
 * WPBakery builder plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_WPBakery')) :

    /**
     * Class for main plugin functions
     */
    class AWL_WPBakery {

        /**
         * @var AWL_WPBakery Custom data
         */
        public $data = array();

        /**
         * @var AWL_WPBakery The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_WPBakery Instance
         *
         * Ensures only one instance of AWL_WPBakery is loaded or can be loaded.
         *
         * @static
         * @return AWL_WPBakery - Main instance
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

            // Check if inside builder module
            add_filter( 'pre_do_shortcode_tag', array( $this, 'before_render' ), 10, 2 );
            add_filter( 'do_shortcode_tag', array( $this, 'after_render' ), 10, 2 );

            // New label condition options
            add_filter( 'awl_label_rules', array( $this, 'label_rules' ), 1 );
            add_filter( 'awl_labels_condition_rules', array( $this, 'condition_rules' ), 1 );

        }

        public function before_render( $output, $tag ) {
            if ( $tag === 'vc_row' || $tag === 'vc_column' ) {
                $this->data['is_bakery'] = true;
            }
            return false;
        }

        public function after_render( $output, $tag ) {
            if ( $tag === 'vc_row' || $tag === 'vc_column' ) {
                $this->data['is_bakery'] = false;
            }
            return $output;
        }

        /*
         * Add new label conditions for admin
         */
        public function label_rules( $options ) {

            $options['Special'][] = array(
                "name" => __( "WPBakery builder: Is builder block", "advanced-woo-labels" ),
                "id"   => "aws_is_bakery",
                "type" => "bool",
                "operators" => "equals",
            );

            return $options;

        }

        /*
         * Add custom condition rule method
         */
        public function condition_rules( $rules ) {
            $rules['aws_is_bakery'] = array( $this, 'awl_is_bakery' );
            return $rules;
        }

        /*
         * Condition: Is WPBakery block
         */
        public function awl_is_bakery( $condition_rule ) {
            global $product;

            $match = false;
            $operator = $condition_rule['operator'];
            $value = $condition_rule['value'];
            $compare_value = 'false';

            if ( isset( $this->data['is_bakery'] ) && $this->data['is_bakery'] ) {
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

AWL_WPBakery::instance();