<?php

/**
 * AWL Breakdance plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Breakdance')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Breakdance {

        /**
         * @var AWL_Breakdance Custom data
         */
        public $data = array();

        /**
         * @var AWL_Breakdance The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Breakdance Instance
         *
         * Ensures only one instance of AWL_Breakdance is loaded or can be loaded.
         *
         * @static
         * @return AWL_Breakdance - Main instance
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

            add_action( 'breakdance_render_element_template', array( $this, 'breakdance_render_element_template' ) );

            add_filter( 'breakdance_render_element_html', array( $this, 'breakdance_render_element_html' ), 10, 2 );

            // New label condition options
            add_filter( 'awl_label_rules', array( $this, 'label_rules' ), 1 );
            add_filter( 'awl_labels_condition_rules', array( $this, 'condition_rules' ), 1 );

        }

        function breakdance_render_element_template() {
            $this->data['is_breakdance'] = true;
        }

        /*
         * Show labels for product title on single page
         */
        function breakdance_render_element_html( $elementHtml, $node ) {

            if ( is_singular('product') && $node && isset( $node['data'] ) && isset( $node['data']['type']  ) && $node['data']['type'] === 'EssentialElements\\WooProductTitle' ) {
                $elementHtml = AWL_Label_Display::instance()->show_label( 'before_title' ) . $elementHtml;
            }

            // Integration for WooCommerce Gallery for Breakdance plugin
            if ( is_singular('product') && $node && isset( $node['data'] ) && isset( $node['data']['type']  ) && $node['data']['type'] === 'WPSix\\WooGallery' ) {
                $on_image_label = AWL_Label_Display::instance()->show_label( 'on_image' ) ;
                if ( strpos( $elementHtml, 'woo-gallery-main-swiper' ) !== false ) {
                    $elementHtml = preg_replace( '/<div class="[\S\s]*?woo-gallery-main-swiper[\S\s]*?>[\S\s]*?<div[\S\s]*?>/i', '${0}' . $on_image_label, $elementHtml );
                } else {
                    $elementHtml = $on_image_label . $elementHtml;
                }
            }
            
            $this->data['is_breakdance'] = false;

            return $elementHtml;

        }

        /*
         * Add new label conditions for admin
         */
        public function label_rules( $options ) {

            $options['Special'][] = array(
                "name" => __( "Breakdance: Is Breakdance block", "advanced-woo-labels" ),
                "id"   => "awl_is_breakdance",
                "type" => "bool",
                "operators" => "equals",
            );

            return $options;

        }

        /*
         * Add custom condition rule method
         */
        public function condition_rules( $rules ) {
            $rules['awl_is_breakdance'] = array( $this, 'awl_is_breakdance' );
            return $rules;
        }

        /*
         * Condition: Is Breakdance block
         */
        public function awl_is_breakdance( $condition_rule ) {
            global $product;

            $match = false;
            $operator = $condition_rule['operator'];
            $value = $condition_rule['value'];
            $compare_value = 'false';

            if ( isset( $this->data['is_breakdance'] ) && $this->data['is_breakdance'] ) {
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

AWL_Breakdance::instance();