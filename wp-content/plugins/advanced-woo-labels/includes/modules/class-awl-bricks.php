<?php

/**
 * AWL Bricks Builder support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Bricks')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Bricks {

        /**
         * @var AWL_Bricks Custom data
         */
        public $data = array();

        /**
         * @var AWL_Bricks The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Bricks Instance
         *
         * Ensures only one instance of AWL_Bricks is loaded or can be loaded.
         *
         * @static
         * @return AWL_Bricks - Main instance
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

            add_filter( 'bricks/elements/woocommerce-products/control_groups', array( $this, 'bricks_control_groups' ) );
            add_filter( 'bricks/elements/woocommerce-products/controls', array( $this, 'bricks_controls' ) );

            add_filter( 'bricks/content/html_after_begin', array( $this, 'bricks_html_after_begin' ), 10, 2 );
            add_filter( 'bricks/content/html_before_end', array( $this, 'bricks_html_before_end' ), 10, 2 );

            // New label condition options
            add_filter( 'awl_label_rules', array( $this, 'label_rules' ), 1 );
            add_filter( 'awl_labels_condition_rules', array( $this, 'condition_rules' ), 1 );

            // Disable labels if needed
            add_filter( 'awl_show_labels_for_product', array( $this, 'disable_labels' ), 1, 2 );

        }

        /*
         * Add new controls group for woocommerce-products element
         */
        public function bricks_control_groups( $control_groups ) {

            $control_groups['awl'] = array(
                'tab'      => 'content',
                'title'    => esc_html__( 'Advanced Woo Labels', 'advanced-woo-labels' ),
            );

            return $control_groups;

        }

        /*
         * Add new controls for woocommerce-products element
         */
        public function bricks_controls( $controls ) {

            $controls['awl_disable'] = array(
                'tab'      => 'content',
                'group'    => 'awl',
                'label'    => esc_html__( 'Disable labels', 'advanced-woo-labels' ),
                'type'     => 'checkbox'
            );

            return $controls;

        }

        public function bricks_html_after_begin( $html_after_begin, $bricks_data ) {

           $this->data['show_labels'] = true;

           if ( $bricks_data ) {
               foreach ( $bricks_data as $element_data ) {
                   if ( isset( $element_data['name'] ) && $element_data['name'] === 'woocommerce-products' ) {
                       $this->data['is_bricks'] = true;
                       if ( isset( $element_data['settings'] ) && isset( $element_data['settings']['awl_disable'] ) && $element_data['settings']['awl_disable'] ) {
                           $this->data['show_labels'] = false;
                       }
                       break;
                   }
               }
           }

            return $html_after_begin;

        }

        public function bricks_html_before_end( $html_before_end, $bricks_data ) {

            $this->data['show_labels'] = true;

            if ( $bricks_data ) {
                foreach ( $bricks_data as $element_data ) {
                    if ( isset( $element_data['name'] ) && $element_data['name'] === 'woocommerce-products' ) {
                        $this->data['is_bricks'] = false;
                        break;
                    }
                }
            }

            return $html_before_end;

        }

        /*
         * Add new label conditions for admin
         */
        public function label_rules( $options ) {

            $options['Special'][] = array(
                "name" => __( "Bricks: Is WooCommerce block", "advanced-woo-labels" ),
                "id"   => "awl_is_bricks",
                "type" => "bool",
                "operators" => "equals",
            );

            return $options;

        }

        /*
         * Add custom condition rule method
         */
        public function condition_rules( $rules ) {
            $rules['awl_is_bricks'] = array( $this, 'awl_is_bricks' );
            return $rules;
        }

        /*
         * Condition: Is Bricks WooCommerce products element
         */
        public function awl_is_bricks( $condition_rule ) {
            global $product;

            $match = false;
            $operator = $condition_rule['operator'];
            $value = $condition_rule['value'];
            $compare_value = 'false';

            if ( isset( $this->data['is_bricks'] ) && $this->data['is_bricks'] ) {
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
         * Enable or not products labels
         */
        public function disable_labels( $disable, $product ) {
            if ( isset( $this->data['show_labels'] ) && ! $this->data['show_labels'] ) {
                $disable = true;
            }
            return $disable;
        }
        
    }

endif;

AWL_Bricks::instance();