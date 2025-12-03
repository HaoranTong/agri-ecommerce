<?php

/**
 * AWL WPML plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_WPML')) :

    /**
     * Class for main plugin functions
     */
    class AWL_WPML {

        /**
         * @var AWL_WPML The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_WPML Instance
         *
         * Ensures only one instance of AWL_WPML is loaded or can be loaded.
         *
         * @static
         * @return AWL_WPML - Main instance
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

            add_filter( 'awl_label_options_get_tax_terms', array( $this, 'awl_label_options_get_tax_terms' ), 1, 2 );

            add_filter( 'awl_label_condition_rule', array( $this, 'tax_condition_rule' ), 1 );

            add_filter( 'awl_label_condition_rule', array( $this, 'product_condition_rule' ), 1 );

        }

        /*
         * Add new options terms for other languages
         */
        public function awl_label_options_get_tax_terms( $options, $tax_name ) {

            global $sitepress;

            if ( $sitepress ) {

                $current_lang = $sitepress->get_current_language();
                $default_language = $sitepress->get_default_language();

                if ( $current_lang !== $default_language ) {

                    $sitepress->switch_lang( $default_language );

                    $tax = get_terms( array(
                        'taxonomy'   => $tax_name,
                        'hide_empty' => false,
                    ) );

                    if ( ! empty( $tax ) ) {
                        foreach ( $tax as $tax_item ) {
                            $options[$tax_item->term_id] = $tax_item->name;
                        }
                    }

                    $sitepress->switch_lang( $current_lang );

                }

            }

            return $options;

        }

        /*
         * Fix condition rules for product taxonomies
         */
        public function tax_condition_rule( $condition_rule ) {

            $condition_name = $condition_rule['param'];
            $tax_name = '';

            if ( $condition_name === 'product_category' || $condition_name === 'product_tag' ) {
                $tax_name = $condition_name;
            } elseif ( $condition_name === 'product_taxonomy' || $condition_name === 'product_attributes' ) {
                $tax_name = isset( $condition_rule['suboption'] ) ? $condition_rule['suboption'] : '';
            }

            if ( $tax_name ) {

                global $sitepress;

                if ( $sitepress ) {

                    $current_lang = $sitepress->get_current_language();

                    $trid = isset( $condition_rule['value'] ) ? $sitepress->get_element_trid( intval( $condition_rule['value'] ), 'tax_' . $tax_name ) : false;
                    if ( $trid ) {
                        $translations = $sitepress->get_element_translations( $trid, 'tax_' . $tax_name );
                        if ( $translations && isset( $translations[$current_lang] ) ) {
                            $term_trans = $translations[$current_lang];
                            $condition_rule['value'] = $term_trans->element_id;
                        }
                    }

                }

            }

            return $condition_rule;

        }

        /*
         * Fix condition rules for products
         */
        public function product_condition_rule( $condition_rule ) {

            if ( $condition_rule['param'] === 'product' ) {

                global $sitepress;

                if ( $sitepress ) {

                    $current_lang = $sitepress->get_current_language();

                    $trid = isset( $condition_rule['value'] ) ? $sitepress->get_element_trid( intval( $condition_rule['value'] ), 'post_product' ) : false;
                    if ( $trid ) {
                        $translations = $sitepress->get_element_translations( $trid, 'post_product' );
                        if ( $translations && isset( $translations[$current_lang] ) ) {
                            $term_trans = $translations[$current_lang];
                            $condition_rule['value'] = $term_trans->element_id;
                        }
                    }

                }

            }

            return $condition_rule;

        }

    }

endif;

AWL_WPML::instance();