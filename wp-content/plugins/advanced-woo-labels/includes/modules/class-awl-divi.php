<?php

/**
 * AWL Divi plugin/theme support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Divi')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Divi {

        /**
         * @var AWL_Divi The single instance of the class
         */
        protected static $_instance = null;

        protected $data = array();

        /**
         * Main AWL_Divi Instance
         *
         * Ensures only one instance of AWL_Divi is loaded or can be loaded.
         *
         * @static
         * @return AWL_Divi - Main instance
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

            if ( ( ( is_shop() && $GLOBALS && isset( $GLOBALS['et_builder_used_in_wc_shop'] ) && $GLOBALS['et_builder_used_in_wc_shop'] ) ||
                ( get_post_meta( get_queried_object_id(), '_et_pb_use_builder', true ) === 'on' )
            )
            ) {
                $hooks['on_image']['archive'] = array(
                    'woocommerce_product_get_image' => array( 'priority' => 10, 'type' => 'filter', 'callback' => 'AWL_Integrations_Callbacks::woocommerce_product_get_image', 'args' => 3 ),
                    'woocommerce_before_shop_loop_item' => array( 'priority' => 10, 'js' => array( '.et_shop_image', 'append' ) )
                );
            }

            // Divi builder single product page
            if ( function_exists('et_builder_dynamic_module_framework') && function_exists('et_builder_is_frontend') ) {
                $et_dynamic_module_framework = et_builder_dynamic_module_framework();
                $enabled = et_builder_is_frontend() && 'on' === $et_dynamic_module_framework;
                if ( $enabled ) {
                    if ( is_singular('product') && $_et_dynamic_cached_shortcodes = get_post_meta( get_queried_object_id(), '_et_dynamic_cached_shortcodes', true ) ) {
                        if ( array_search( 'et_pb_wc_title', $_et_dynamic_cached_shortcodes ) !== false ) {
                            $hooks['before_title']['single'] = array( 'et_pb_wc_title_shortcode_output' => array( 'priority' => 10, 'type' => 'filter', 'before' => true ));
                        }
                        if ( array_search( 'et_pb_wc_gallery', $_et_dynamic_cached_shortcodes ) !== false ) {
                            $hooks['on_image']['single']['et_pb_wc_gallery_shortcode_output'] = array( 'priority' => 10, 'type' => 'filter', 'before' => true );
                        }
                    }
                }
            }

            return $hooks;

        }

    }

endif;

AWL_Divi::instance();