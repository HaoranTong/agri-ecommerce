<?php

/**
 * AWL support for blocks builder
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Blocks')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Blocks {

        /**
         * @var AWL_Blocks Custom data
         */
        public $data = array();

        /**
         * @var AWL_Blocks The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Blocks Instance
         *
         * Ensures only one instance of AWL_Blocks is loaded or can be loaded.
         *
         * @static
         * @return AWL_Blocks - Main instance
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

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive']['render_block_woocommerce/product-image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'render_image' ), 'args' => 1 );

            $hooks['before_title']['archive']['render_block_core/post-title'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'render_title' ), 'args' => 1 );

            return $hooks;

        }

        public function render_image( $block_content ) {
            return '<div style="position:relative;">' . $block_content . AWL_Label_Display::instance()->show_label( 'on_image' ) . '</div>';
        }

        public function render_title( $block_content ) {
            return AWL_Label_Display::instance()->show_label( 'before_title' ) . $block_content;
        }

    }

endif;

AWL_Blocks::instance();