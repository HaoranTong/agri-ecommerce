<?php

/**
 * AWL Product Filter by WBW plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_WPF')) :

    /**
     * Class for main plugin functions
     */
    class AWL_WPF {

        /**
         * @var AWL_WPF The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_WPF Instance
         *
         * Ensures only one instance of AWL_WPF is loaded or can be loaded.
         *
         * @static
         * @return AWL_WPF - Main instance
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

            add_action( 'woocommerce_product_query', array( $this, 'woocommerce_product_query' ), 999 );

            add_filter( 'awl_get_labels', array( $this, 'awl_get_labels' ), 999 );

        }

        /*
         * Fix query to get all available plugin labels
         */
        public function woocommerce_product_query( $q ) {
            if ( $q->query_vars && isset( $q->query_vars['_is_awl_query'] ) && $q->query_vars['_is_awl_query'] ) {
                $q->set( 'post_type', 'awl-labels' );
            }
        }

        /*
         * Fix issue with not found labels
         */
        public function awl_get_labels( $labels ) {

            if ( empty( $labels ) ) {

                global $wpdb;

                $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'awl-labels'";

                $results = $wpdb->get_results( $sql );
                $posts_ids = array();

                if ( !empty( $results ) && !is_wp_error( $results ) && is_array( $results ) ) {
                    foreach ( $results as $search_result ) {
                        $post_id = intval( $search_result->ID );
                        if ( ! in_array( $post_id, $posts_ids ) ) {
                            $posts_ids[] = $post_id;
                        }
                    }
                }

                $labels = $posts_ids;

            }

            return $labels;

        }

    }

endif;

AWL_WPF::instance();