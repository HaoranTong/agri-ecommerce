<?php

/**
 * AWL Zephyr theme support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Zephyr')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Zephyr {

        /**
         * @var AWL_Zephyr Custom data
         */
        public $data = array();

        /**
         * @var AWL_Zephyr The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Zephyr Instance
         *
         * Ensures only one instance of AWL_Zephyr is loaded or can be loaded.
         *
         * @static
         * @return AWL_Zephyr - Main instance
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

            // Display hooks
            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

            // Hide sale flash
            add_action( 'awl_hide_default_sale_flash', array( $this, 'hide_default_sale_flash' ), 1 );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive']['us_grid_listing_post'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'us_grid_listing_post_on_image' ), 'args' => 1 );
            $hooks['before_title']['archive']['us_grid_listing_post'] = array( 'priority' => 9, 'type' => 'filter', 'callback' => array( $this, 'us_grid_listing_post_title' ), 'args' => 1 );

            return $hooks;

        }
        
        public function us_grid_listing_post_on_image( $html ) {

            $label = AWL_Label_Display::instance()->show_label( 'on_image' );

            if ( $label && strpos( $html, 'awl-position-type-on-image' ) === false ) {
                $html = str_replace( '<img', $label . '<img', $html );
            }

            return $html;

        }

        public function us_grid_listing_post_title( $html ) {

            $label = AWL_Label_Display::instance()->show_label( 'before_title' );

            if ( $label && strpos( $html, 'awl-position-type-before-title' ) === false ) {
                $html = str_replace( '<h2', '<div style="margin-top:12px;">' . $label . '</div><h2', $html );
            }

            return $html;
        }

        /*
         * Hide default sale flash if this option is enables
         */
        public function hide_default_sale_flash() {
            add_filter( 'us_grid_listing_post', array( $this, 'us_grid_listing_post_hide_sale' ) );
        }

        /*
         * Hide onsale labels
         */
        public function us_grid_listing_post_hide_sale( $html ) {
            $html = str_replace( 'onsale"', 'onsale" style="display:none;"', $html );
            return $html;
        }

    }

endif;

AWL_Zephyr::instance();