<?php

/**
 * AWL Avada theme plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Avada')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Avada {

        /**
         * @var AWL_Avada The single instance of the class
         */
        protected static $_instance = null;

        protected $data = array();

        /**
         * Main AWL_Avada Instance
         *
         * Ensures only one instance of AWL_Avada is loaded or can be loaded.
         *
         * @static
         * @return AWL_Avada - Main instance
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

            add_action( 'awl_hide_default_sale_flash', array( $this, 'awl_hide_default_sale_flash' ) );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive_avada']['wp_get_attachment_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'wp_get_attachment_image' ) );
            $hooks['on_image']['archive_avada']['post_thumbnail_html'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'wp_get_attachment_image' ) );
            $hooks['on_image']['archive_avada']['fusion_element_post_card_image_content'] = array( 'priority' => 10, 'type' => 'filter' );

            $hooks['before_title']['archive_avada']['fusion_element_title_content'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'fusion_element_title_content' ) );

            return $hooks;

        }

        /*
        * Add label on image
        */
        public function wp_get_attachment_image( $html ) {

            $is_avada_block = false;
            foreach ( debug_backtrace() as $trace_part ) {
                if ( isset( $trace_part['function'] ) && ( 'avada_first_featured_image_markup' === $trace_part['function'] || 'fusion_render_first_featured_image_markup' === $trace_part['function'] ) ) {
                    $is_avada_block = true;
                    break;
                }
            }

            if ( $is_avada_block ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'on_image' );
            }

            return $html;

        }

        /*
         * Add label before title
         */
        public function fusion_element_title_content( $html ) {
            $html = '<div style="margin: 10px 0 0;">' . AWL_Label_Display::instance()->show_label( 'before_title' ) . '</div>' . $html;
            return $html;
        }

        /*
         * Hide default sale badge
         */
        public function awl_hide_default_sale_flash() {
            remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 7 );
        }

    }

endif;

AWL_Avada::instance();