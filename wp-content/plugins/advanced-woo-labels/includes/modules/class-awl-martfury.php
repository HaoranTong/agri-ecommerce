<?php

/**
 * AWL martfury theme support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Martfury')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Martfury {

        /**
         * @var AWL_Martfury Custom data
         */
        public $data = array();

        /**
         * @var AWL_Martfury The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Martfury Instance
         *
         * Ensures only one instance of AWL_Martfury is loaded or can be loaded.
         *
         * @static
         * @return AWL_Martfury - Main instance
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

            add_action( 'elementor/frontend/widget/before_render', array( $this, 'before_render' ) );
            add_action( 'elementor/frontend/widget/after_render', array( $this, 'after_render' ) );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive']['woocommerce_product_get_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'woocommerce_product_get_image' ), 'args' => 3 );
            $hooks['before_title']['archive']['woocommerce_product_title'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'woocommerce_product_title' ), 'args' => 2 );
            $hooks['before_title']['archive']['martfury_woo_shop_loop_item_title'] = array( 'priority' => 5 );

            $hooks['before_title']['single']['martfury_single_product_summary'] = array( 'priority' => 4 );
            $hooks['before_title']['single']['martfury_single_product_deal_summary'] = array( 'priority' => 4 );


            return $hooks;

        }

        /*
         * Add labels for 'on image' position
         */
        public function woocommerce_product_get_image( $image, $obj, $size ) {
            if ( isset( $this->data['show_labels'] ) && $this->data['show_labels'] ) {
                $image = '<div style="position:relative;">' . $image . AWL_Label_Display::instance()->show_label( 'on_image' ) . '</div>';
            }
            return $image;
        }

        /*
         * Add labels for 'before title' position
         */
        public function woocommerce_product_title( $name, $product ) {
            if ( isset( $this->data['show_labels'] ) && $this->data['show_labels'] ) {
                $name = AWL_Label_Display::instance()->show_label( 'before_title' ) . $name;
            }
            return $name;
        }

        function before_render( $block ) {

            $name = $block->get_name();

            $add_to = array( 'martfury-recently-viewed-products-carousel' );

            if ( array_search( $name, $add_to ) !== false ) {
                $this->data['show_labels'] = true;
            }

        }

        function after_render( $block ) {
            $this->data['show_labels'] = false;
        }


    }

endif;

AWL_Martfury::instance();