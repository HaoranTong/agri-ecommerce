<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


if ( ! class_exists( 'AWL_Helpers' ) ) :

    /**
     * Class for plugin help methods
     */
    class AWL_Helpers {

        /**
         * Get labels hooks
         * @since 1.0
         * @return array List of label hooks
         */
        static public function get_hooks() {

            /**
             * Filter labels hooks array
             * @since 1.00
             * @param array $hooks
             */
            $hooks = apply_filters( 'awl_labels_hooks', array() );

            return $hooks;

        }

        /**
         * Get js selectors
         * @since 1.0
         * @return array List of js selectors
         */
        static public function get_js_selectors() {

            $selectors = array();
            $hooks = AWL_Helpers::get_hooks();

            if ( is_array( $hooks ) && ! empty( $hooks ) ) {
                foreach( $hooks as $position => $hooks_list_type ) {
                    foreach ( $hooks_list_type as $hooks_display => $hooks_list ) {
                        if ( $hooks_display === 'single' && ! is_singular( 'product' ) ) { continue; }
                        foreach ( $hooks_list as $hook_name => $hook_vars ) {
                            if ( isset( $hook_vars['js'] ) && is_array( $hook_vars['js'] ) ) {
                                $label_selector = isset( $hook_vars['label_js_selector'] ) && is_string( $hook_vars['label_js_selector'] ) ? $hook_vars['label_js_selector'] : '.awl-position-type-' . sanitize_title( str_replace( '_', '-', $position ) );
                                $selectors[$label_selector] = $hook_vars['js'];
                            }
                        }
                    }
                }
            }

            /**
             * Filter js selectors for labels
             * @since 1.00
             * @param array $selectors Array of css selectors
             */
            $selectors = apply_filters( 'awl_js_selectors', $selectors );

            return $selectors;

        }

        /**
         * Get Labels post type
         * @since 1.0
         * @param  array $args Array of arguments
         * @return array List of label posts
         */
        static public function get_awl_labels( $args = array() ) {

            $labels = get_posts( array(
                'post_type'              => 'awl-labels',
                'post_status'            => 'publish',
                'posts_per_page'         => -1,
                'meta_key'               => '_awl_label_priority',
                'orderby'                => 'meta_value_num date',
                'order'                  => 'DESC',
                'update_post_term_cache' => false,
                'fields'                 => 'ids',
                'no_found_rows'          => 1,
                'suppress_filters'       => false,
                '_is_awl_query'          => true,
            ) );

            return apply_filters( 'awl_get_labels', $labels );

        }

        /**
         * Match label condition
         * @since 1.0
         * @param  array $conditions List of label conditions
         * @return bool
         */
        static public function match_conditions( $conditions ) {

            $condition = new AWL_Conditions_Check( $conditions );
            return $condition->match();

        }

        /**
         * Get label text
         * @since 1.0
         * @param  array $label_settings Array of label settings
         * @return string
         */
        static public function get_label_text( $label_settings ) {

            if ( ! $label_settings || ! isset( $label_settings['text'] ) ) {
                return '';
            }

            $label_text = stripslashes( $label_settings['text'] );

            $html_entities = array( '<script>', '</script>', '<noscript>', '</noscript>' );
            foreach ( $html_entities as $html_entity ) {
                $label_text = str_replace( $html_entity, '', $label_text );
            }

            if ( strpos( $label_text,  '{' ) !== false && strpos( $label_text, '}' ) !== false ) {
                $label_text_obj = new AWL_Label_Text( $label_text );
                $label_text = $label_text_obj->text();
            }

            $label_text = wp_kses( $label_text, AWL_Helpers::kses_allowed_tags() );

            /**
             * Filter labels text
             * @since 1.00
             * @param string $label_text
             * @param array $label_settings
             */
            $label_text = apply_filters( 'awl_label_text', $label_text, $label_settings );

            return $label_text;

        }

        /**
         * Get array of allowed tags for label text
         * @since 1.94
         * @return array
         */
        static public function kses_allowed_tags() {

            $kses_defaults = wp_kses_allowed_html( 'post' );

            $svg_args = array(
                'svg'   => array(
                    'class'           => true,
                    'aria-hidden'     => true,
                    'aria-labelledby' => true,
                    'role'            => true,
                    'xmlns'           => true,
                    'width'           => true,
                    'height'          => true,
                    'viewbox'         => true,
                ),
                'g'     => array( 'fill' => true ),
                'title' => array( 'title' => true ),
                'path'  => array(
                    'd'               => true,
                    'fill'            => true
                )
            );

            $allowed_tags = array_merge( $kses_defaults, $svg_args );

            return $allowed_tags;

        }

        /**
         * Get label markup
         * @since 1.0
         * @param array $labels Array of labels
         * @param string $label_position_type Labels position
         * @return string
         */
        static public function get_label_html( $labels, $label_position_type ) {

            if ( ! $labels || ! is_array( $labels ) || empty( $labels ) ) {
                return '';
            }

            $global_settings = AWL()->get_settings();

            $label_html_obj = new AWL_Label_View( $labels, $label_position_type, $global_settings );

            return $label_html_obj->html();

        }

        /**
         * Get label type ( single or archive )
         * @since 1.0
         * @return string
         */
        static public function get_label_type() {
            global $wp_current_filter;

            $last_hook = end ( $wp_current_filter );
            $single_hooks = array( 'woocommerce_product_thumbnails', 'woocommerce_single_product_summary', 'woocommerce_before_single_product_summary', 'woocommerce_before_single_product', 'woocommerce_after_single_product_summary', 'wpgs_after_image_gallery', 'woo_variation_product_gallery_start', 'woocommerce_single_product_image_thumbnail_html' );

            /**
             * Filter array of hooks to detect single label type
             * @since 1.69
             * @param array $single_hooks
             */
            $single_hooks = apply_filters( 'awl_labels_single_type_hooks', $single_hooks );

            if ( is_singular('product') && in_array( $last_hook, $single_hooks ) ) {
                return 'single';
            } else {
                return 'archive';
            }

        }

        /**
         * Get all available labels positions
         * @since 1.0
         * @return array
         */
        static public function get_labels_positions() {

            $settings_array = AWL_Admin_Options::include_label_settings();
            $positions = array();

            foreach ( $settings_array as $settings ) {
                foreach ( $settings as $setting ) {
                    if ( $setting && isset( $setting['id'] ) && $setting['id'] === 'position_type' && isset( $setting['choices'] ) ) {
                        foreach( $setting['choices'] as $pos_slug => $pos_name  ) {
                            $positions[] = array( 'slug' => $pos_slug, 'name' => $pos_name );
                        }
                        break;
                    }
                }
            }

            /**
             * Filter labels positions
             * @since 1.00
             * @param array $positions
             */
            $positions = apply_filters( 'awl_labels_positions', $positions );

            return $positions;

        }

        /**
         * Get standard WooCommerce hooks
         * @since 1.0
         * @return array
         */
        static public function get_woocommerce_hooks() {

            $hooks = array(
                'woocommerce_before_shop_loop_item',
                'woocommerce_before_shop_loop_item_title',
                'woocommerce_shop_loop_item_title',
                'woocommerce_after_shop_loop_item_title',
                'woocommerce_after_shop_loop_item',
                'woocommerce_before_single_product',
                'woocommerce_before_single_product_summary',
                'woocommerce_single_product_summary',
                'woocommerce_after_single_product_summary',
                'woocommerce_after_single_product',
            );

            /**
             * Filter WooCommerce hooks
             * @since 1.00
             * @param array $hooks
             */
            $hooks = apply_filters( 'awl_woocommerce_hooks', $hooks );

            return $hooks;

        }

        /*
         * Check weather one of language plugin active or not
         * @return bool
         */
        static public function is_lang_plugin_active() {
            return function_exists( 'pll_current_language' ) || function_exists( 'qtranxf_getLanguage' ) || has_filter( 'wpml_active_languages' );
        }

        /*
         * Get current active site language
         *
         * @return string Language code
         */
        static public function get_current_lang() {

            $current_lang = '';

            if ( ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_current_language' ) ) ) {

                if ( has_filter('wpml_current_language') ) {
                    $current_lang = apply_filters( 'wpml_current_language', NULL );
                } elseif ( function_exists( 'pll_current_language' ) ) {
                    $current_lang = pll_current_language();
                }

            } elseif( function_exists( 'qtranxf_getLanguage' ) ) {

                $current_lang = qtranxf_getLanguage();

            }

            return $current_lang;

        }

    }

endif;