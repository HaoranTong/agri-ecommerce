<?php

/**
 * Beaver builder plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_BB')) :

    /**
     * Class for main plugin functions
     */
    class AWL_BB {

        /**
         * @var AWL_BB Custom data
         */
        public $data = array();

        /**
         * @var AWL_BB The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_BB Instance
         *
         * Ensures only one instance of AWL_BB is loaded or can be loaded.
         *
         * @static
         * @return AWL_BB - Main instance
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

            // Add options for modules
            add_filter( 'fl_builder_register_settings_form', array( $this, 'fl_builder_register_settings_form' ), 10, 2 );

            // Disable labels if needed
            add_filter( 'awl_show_labels_for_product', array( $this, 'disable_labels' ), 1, 2 );

            add_action( 'fl_builder_before_render_modules', array( $this, 'fl_builder_before_render_modules' ), 10, 2 );

            // Check is inside BB module
            add_action( 'fl_builder_render_content_start', array( $this, 'before_render' ) );
            add_action( 'fl_builder_render_content_complete', array( $this, 'after_render' ) );

            // New label condition options
            add_filter( 'awl_label_rules', array( $this, 'label_rules' ), 1 );
            add_filter( 'awl_labels_condition_rules', array( $this, 'condition_rules' ), 1 );

        }

        /*
         * Add labels options for BB modules
         */
        public function fl_builder_register_settings_form( $form, $id ) {

            $awl_option = array(
                "type" => "select",
                "label" => "Show Advanced Woo Labels?",
                "default" => "yes",
                "options" => array(
                    'yes' => 'Yes',
                    'no' => 'No'
                )
            );

            if ( 'woocommerce' == $id ) {
                $form['general']['sections']['multiple_products']['fields']['show_awl'] = $awl_option;
            }

            return $form;

        }

        /*
         * Check module settings
         */
        public function fl_builder_before_render_modules( $nodes, $col_id ) {
            if ( $nodes ) {
                foreach ( $nodes as $node ) {
                    if ( $node->type === 'module' && $node->settings ) {
                        if ( property_exists( $node->settings, 'show_awl' ) && $node->settings->show_awl === 'no' ) {
                            $this->data['show_labels'] = false;
                        } else {
                            $this->data['show_labels'] = true;
                        }
                    }

                }
            }
        }
        
        public function before_render( $module ) {
            $this->data['is_beaver'] = true;
            $this->data['show_labels'] = true;
        }

        public function after_render( $module ) {
            $this->data['is_beaver'] = false;
            $this->data['show_labels'] = true;
        }

        /*
         * Enable or not products labels
         */
        function disable_labels( $disable, $product ) {
            if ( isset( $this->data['show_labels'] ) && ! $this->data['show_labels'] ) {
                $disable = true;
            }
            return $disable;
        }
        
        /*
         * Add new label conditions for admin
         */
        public function label_rules( $options ) {

            $options['Special'][] = array(
                "name" => __( "Beaver builder: Is builder block", "advanced-woo-labels" ),
                "id"   => "aws_is_beaver",
                "type" => "bool",
                "operators" => "equals",
            );

            return $options;

        }

        /*
         * Add custom condition rule method
         */
        public function condition_rules( $rules ) {
            $rules['aws_is_beaver'] = array( $this, 'awl_is_beaver' );
            return $rules;
        }

        /*
         * Condition: Is Beaver block
         */
        public function awl_is_beaver( $condition_rule ) {
            global $product;

            $match = false;
            $operator = $condition_rule['operator'];
            $value = $condition_rule['value'];
            $compare_value = 'false';

            if ( isset( $this->data['is_beaver'] ) && $this->data['is_beaver'] ) {
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

AWL_BB::instance();