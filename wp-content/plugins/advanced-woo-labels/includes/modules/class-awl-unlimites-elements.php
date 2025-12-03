<?php

/**
 * AWL Unlimited Elements For Elementor plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Unlimited_Elements')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Unlimited_Elements {

        /**
         * @var AWL_Unlimited_Elements The single instance of the class
         */
        protected static $_instance = null;

        protected $data = array();

        /**
         * Main AWL_Unlimited_Elements Instance
         *
         * Ensures only one instance of AWL_Unlimited_Elements is loaded or can be loaded.
         *
         * @static
         * @return AWL_Unlimited_Elements - Main instance
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

            if ( apply_filters( 'awl_disable_unlimited_elements_support', false ) ) {
                return;
            }

            add_action( 'ue_woocommerce_product_integrations', array( $this, 'ue_woocommerce_product_integrations' ) );

            add_filter( 'uc_filter_posts_list', array( $this, 'uc_filter_posts_list' ), 1, 3 );

            add_filter( 'elementor/widget/render_content', array( $this, 'render_content' ), 1, 2 );

        }

        /*
         * Show labels for blocks image
         */
        public function ue_woocommerce_product_integrations( $product_id ) {
            echo '<div style="position:absolute;">' . awl_get_labels_html_by_position( 'on_image', $product_id ) . '</div>';
        }

        /*
         * Show labels for blocks title
         */
        public function uc_filter_posts_list( $arrPosts, $value, $filters ) {

            if ( ! empty( $arrPosts ) ) {
                foreach ( $arrPosts as $arrPost ) {
                    $title_label = awl_get_labels_html_by_position( 'before_title', $arrPost->ID );
                    $title_label = str_replace( 'flex-end;', 'center;', $title_label );
                    $title_label = str_replace( 'flex-start;', 'center;', $title_label );
                    $arrPost->post_title = $title_label . $arrPost->post_title;
                }
            }

            return $arrPosts;
        }

        /*
         * Render blocks content
         */
        function render_content( $widget_content, $block ) {

            $block_categories = $block->get_categories();

            if ( $block_categories && array_search( 'unlimited_elements', $block_categories ) !== false ) {
                if ( 'ucaddon_woocommerce_product_list' === $block->get_name() && strpos( $widget_content, 'awl-product-label' ) !== false ) {
                    $widget_content = str_replace( 'justify-content:center;', 'justify-content:flex-start;', $widget_content );
                }
            }

            return $widget_content;

        }

    }

endif;

AWL_Unlimited_Elements::instance();