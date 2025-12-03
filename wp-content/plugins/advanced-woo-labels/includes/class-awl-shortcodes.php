<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Shortcodes' ) ) :

    /**
     * Class for plugin shortcodes generation
     */
    class AWL_Shortcodes {

        /**
         * @var AWL_Shortcodes The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Shortcodes Instance
         *
         * Ensures only one instance of AWL_Shortcodes is loaded or can be loaded.
         *
         * @static
         * @return AWL_Shortcodes - Main instance
         */
        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /*
         * Constructor
         */
        public function __construct() {

            add_shortcode( 'awl_get_product_labels', array( $this, 'awl_get_product_labels' ) );

            add_shortcode( 'awl_get_label_by_id', array( $this, 'awl_get_label_by_id' ) );

        }

        /*
         * Get all labels markup for current product
         * @return string
         */
        public function awl_get_product_labels( $atts ) {

            extract(shortcode_atts(array(
                'position' => 'all',
                'product_id' => ''
            ), $atts));

            $output = '';

            if ( function_exists( 'awl_get_labels_html_by_position' ) ) {
                $output = awl_get_labels_html_by_position( $position, $product_id );
            }

            return $output;

        }

        /*
         * Get label markup by label ID
         * @return string
         */
        public function awl_get_label_by_id( $atts ) {

            extract(shortcode_atts(array(
                'id' => 0,
                'position' => 'before_title'
            ), $atts));

            $output = '';

            if ( $id && function_exists( 'awl_get_label_html_by_id' ) ) {
                $output = awl_get_label_html_by_id( $id, $position );
            }

            return $output;

        }

    }

endif;

AWL_Shortcodes::instance();