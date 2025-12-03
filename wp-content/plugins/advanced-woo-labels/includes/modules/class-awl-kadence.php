<?php

/**
 * AWL Kadence plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Kadence')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Kadence {

        /**
         * @var AWL_Kadence Custom data
         */
        public $data = array();

        /**
         * @var AWL_Kadence The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Kadence Instance
         *
         * Ensures only one instance of AWL_Kadence is loaded or can be loaded.
         *
         * @static
         * @return AWL_Kadence - Main instance
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

            add_action( 'kadence_blocks_post_loop_start', array( $this, 'kadence_is_block' ), 1  );
            add_action( 'kadence_blocks_portfolio_loop_image', array( $this, 'kadence_is_block' ), 1  );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['before_title']['archive']['kadence_blocks_portfolio_loop_content_inner'] = array( 'priority' => 19 );
            $hooks['before_title']['archive']['kadence_blocks_post_loop_header'] = array( 'priority' => 19 );
            $hooks['on_image']['archive']['post_thumbnail_html'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'post_thumbnail_html' ), 'args' => 4 );

            return $hooks;

        }

        /*
         * Display on_image labels
         */
        public function post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size ) {

            if ( isset( $this->data['is_grid_block'] ) && $this->data['is_grid_block'] ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'on_image' );
                $this->data['is_grid_block'] = false;
            }

            return $html;

        }

        public function kadence_is_block() {
            $this->data['is_grid_block'] = true;
        }
        
    }

endif;

AWL_Kadence::instance();