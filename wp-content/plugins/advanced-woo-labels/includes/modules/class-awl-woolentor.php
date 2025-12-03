<?php

/**
 * AWL WooLentor ( ShopLentor ) plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Woolentor')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Woolentor {

        /**
         * @var AWL_Woolentor Custom data
         */
        public $data = array();

        /**
         * @var AWL_Woolentor The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Woolentor Instance
         *
         * Ensures only one instance of AWL_Woolentor is loaded or can be loaded.
         *
         * @static
         * @return AWL_Woolentor - Main instance
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

            add_action( 'awl_hide_default_sale_flash', array( $this, 'hide_default_sale_flash' ), 1 );

            add_action( 'awl_hide_default_stock_flash', array( $this, 'hide_default_stock_flash' ), 1 );

            add_action( 'wp_head', array( $this, 'wp_head_styles' ) );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive_woolentor']['wp_get_attachment_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'wp_get_attachment_image' ) );
            $hooks['on_image']['archive_woolentor']['woocommerce_product_get_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'wp_get_attachment_image' ) );

            $hooks['before_title']['archive_woolentor']['the_title'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'the_title' ) );
            $hooks['before_title']['archive_woolentor']['woolentor_universal_before_title'] = array( 'priority' => 10 );

            $hooks['before_title']['single']['render_block'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'render_block' ), 'args' => 2 );

            return $hooks;

        }

        /*
         * Add label to image
         */
        public function wp_get_attachment_image( $html ) {
            $is_woolentor_block = false;
            foreach ( debug_backtrace() as $trace_part ) {
                if ( isset( $trace_part['class'] ) && (
                    strpos( $trace_part['class'], 'Woolentor_Universal_Product' )
                    || strpos( $trace_part['class'], 'Woolentor_Product_Accordion' )
                    || strpos( $trace_part['class'], 'Woolentor_Wl_Custom_Archive_Layout_Widget' )
                    || strpos( $trace_part['class'], 'Woolentor_Wl_Related_Product_Widget' )
                    || strpos( $trace_part['class'], 'Woolentor_Wl_Product_Upsell_Custom_Widget' )
                ) ) {
                    $is_woolentor_block = true;
                    break;
                }
            }
            if ( $is_woolentor_block ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'on_image' );
            }
            return $html;
        }

        /*
         * Add label to title
         */
        public function the_title( $title ) {
            $is_woolentor_block = false;
            foreach ( debug_backtrace() as $trace_part ) {
                if ( isset( $trace_part['class'] ) && ( strpos( $trace_part['class'], 'Woolentor_Universal_Product' ) || strpos( $trace_part['class'], 'Woolentor_Product_Accordion' ) || strpos( $trace_part['class'], 'Woolentor_Product_Image_Accordion' ) ) ) {
                    $is_woolentor_block = true;
                    break;
                }
            }
            if ( $is_woolentor_block ) {
                $title = AWL_Label_Display::instance()->show_label( 'before_title' ) . $title;
            }
            return $title;
        }

        /*
         * Add label before title
         */
        function render_block( $block_content = '', $block = [] ) {
            if ( isset( $block['blockName'] ) && 'woolentor/product-title' === $block['blockName'] ) {
                $block_content = AWL_Label_Display::instance()->show_label( 'before_title' ) . $block_content;
            }
            return $block_content;
        }

        /*
         * Hide default sale flash if this option is enables
         */
        public function hide_default_sale_flash() {
            add_action( 'wp_head', array( $this, 'woolentor_hide_sale_flash' ) );
        }

        /*
         * Hide default out-of-stock flash if this option is enables
         */
        public function hide_default_stock_flash() {
            add_action( 'wp_head', array( $this, 'woolentor_hide_stock_flash' ) );
        }

        public function woolentor_hide_sale_flash() {
            echo '<style>.ht-product-label { display: none !important; } .ht-stockout.ht-product-label { display: block !important; }</style>';
        }

        public function woolentor_hide_stock_flash() {
            echo '<style>.ht-stockout.ht-product-label { display: none !important; }</style>';
        }

        /*
         * Add addition styles
         */
        public function wp_head_styles() {
            echo '<style>.wl_product-accordion .card-body .product-thumbnail { position: relative; }</style>';
        }

    }

endif;

AWL_Woolentor::instance();