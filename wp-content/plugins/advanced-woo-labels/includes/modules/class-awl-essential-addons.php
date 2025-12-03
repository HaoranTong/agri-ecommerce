<?php

/**
 * AWL Essential Addons for Elementor plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Essential_Addons')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Essential_Addons {

        /**
         * @var AWL_Essential_Addons Custom data
         */
        public $data = array();

        /**
         * @var AWL_Essential_Addons The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Essential_Addons Instance
         *
         * Ensures only one instance of AWL_Essential_Addons is loaded or can be loaded.
         *
         * @static
         * @return AWL_Essential_Addons - Main instance
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

            if ( apply_filters( 'awl_disable_essential_addons_support', false ) ) {
                return;
            }

            // Display hooks
            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

            // Add custom styles
            add_action( 'eael_woo_single_product_image', array( $this, 'eael_woo_single_product_image' ) );

            // Add custom scripts
            add_action( 'wp_footer', array( $this, 'wp_footer' ) );

            // Hide default labels
            add_action( 'awl_hide_default_sale_flash', array( $this, 'hide_default_sale_flash' ), 1 );
            add_action( 'awl_hide_default_stock_flash', array( $this, 'hide_default_stock_flash' ), 1 );

            // New label condition options
            add_filter( 'awl_label_rules', array( $this, 'label_rules' ), 1 );
            add_filter( 'awl_labels_condition_rules', array( $this, 'condition_rules' ), 1 );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            // Single product ( quick view )
            $hooks['on_image']['single']['eael_woo_single_product_image'] = array( 'priority' => 10 );
            $hooks['before_title']['single']['eael_woo_single_product_summary'] = array( 'priority' => 10 );
            $hooks['on_image']['single']['wp_get_attachment_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'woocommerce_product_get_image' ) );

            // Blocks
            $hooks['on_image']['archive']['woocommerce_product_get_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'woocommerce_product_get_image' ) );
            $hooks['before_title']['archive']['woocommerce_product_get_image'] = array( 'priority' => 9, 'type' => 'filter', 'callback' => array( $this, 'show_title' ), 'js' => array( '.eael-product-title', 'before' ) );

            return $hooks;

        }


        /*
         * Show on_image label
         */
        public function woocommerce_product_get_image( $image ) {

            $is_essential_addon = $this->is_essential_addon();

            if ( $is_essential_addon ) {
                $this->data['is_essential'] = true;
                $image = $image . AWL_Label_Display::instance()->show_label( 'on_image' );
            }

            $this->data['is_essential'] = false;

            return $image;

        }

        /*
         * Show before_title label
         */
        public function show_title( $title ) {

            $is_essential_addon = $this->is_essential_addon();

            if ( $is_essential_addon ) {
                $this->data['is_essential'] = true;
                $title = $title . AWL_Label_Display::instance()->show_label( 'before_title' );
            }

            $this->data['is_essential'] = false;

            return $title;
        }

        /*
         * Check if inside essential addons blocks and it is one of supported
         */
        private function is_essential_addon() {

            $is_essential_addon = false;

            foreach ( debug_backtrace() as $trace_part ) {
                if ( isset( $trace_part['class'] ) ) {

                    if ( strpos( $trace_part['class'], 'Essential_Addons' ) === false  ) {
                        continue;
                    }

                    if ( strpos( $trace_part['class'], 'Woo_Product_Carousel' ) !== false ||
                        strpos( $trace_part['class'], 'Product_Grid' ) !== false ||
                        strpos( $trace_part['class'], 'Woo_Product_Gallery' ) !== false ||
                        strpos( $trace_part['class'], 'Woo_Product_Slider' ) !== false ) {
                        $is_essential_addon = true;
                    }

                    if ( strpos( $trace_part['class'], 'Woo_Product_Slider' ) !== false ) {
                        $this->data['slider'] = true;
                    }

                    // Fix labels duplicates for product image widget
                    if ( strpos( $trace_part['class'], 'Woo_Product_Images' ) !== false && ! isset( $this->data['image'] ) ) {
                        $is_essential_addon = true;
                        $this->data['image'] = true;
                    }

                }
            }

            return $is_essential_addon;

        }

        /*
         * Fix image label placement
         */
        public function eael_woo_single_product_image() {

            echo '<style>.eael-product-image-wrap { position:relative; }</style>';

        }

        /*
         * Add additional styles and scripts
         */
        public function wp_footer() {

            if ( isset( $this->data['slider']  ) && $this->data['slider']  ) {
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                    setTimeout(function() {
                        jQuery(".eael-product-slider .awl-position-type-before-title").css("display", "none");
                        document.dispatchEvent(new Event("AWLTriggerJsReplace"));
                    }, 10);
                }, false);
                </script>';
            }

            if ( isset( $this->data['hide_default_sale_flash'] ) && $this->data['hide_default_sale_flash'] ) {
                echo '<style>
                    .eael-onsale,
                    .eael-product-wrap .onsale{
                        display: none !important;
                    }
                </style>';
            }

            if ( isset( $this->data['hide_default_stock_flash'] ) && $this->data['hide_default_stock_flash'] ) {
                echo '<style>
                    .eael-product-wrap .outofstock-badge {
                        display: none !important;
                    }
                </style>';
            }

        }

        public function hide_default_sale_flash() {
            $this->data['hide_default_sale_flash'] = true;
        }

        public function hide_default_stock_flash() {
            $this->data['hide_default_stock_flash'] = true;
        }

        /*
         * Add new label conditions for admin
         */
        public function label_rules( $options ) {

            $options['Special'][] = array(
                "name" => __( "Essential Addons: Is essential block", "advanced-woo-labels" ),
                "id"   => "awl_is_essential",
                "type" => "bool",
                "operators" => "equals",
            );

            return $options;

        }

        /*
         * Add custom condition rule method
         */
        public function condition_rules( $rules ) {
            $rules['awl_is_essential'] = array( $this, 'awl_is_essential' );
            return $rules;
        }

        /*
         * Condition: Is Essential block
         */
        public function awl_is_essential( $condition_rule ) {
            global $product;

            $match = false;
            $operator = $condition_rule['operator'];
            $value = $condition_rule['value'];
            $compare_value = 'false';

            if ( isset( $this->data['is_essential'] ) && $this->data['is_essential'] ) {
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

AWL_Essential_Addons::instance();