<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Admin_Helpers' ) ) :

    /**
     * Class for admin help methods
     */
    class AWL_Admin_Helpers {

        /*
         * Get available stock statuses
         * @return array
         */
        static public function get_stock_statuses() {

            $options = array();

            if ( function_exists( 'wc_get_product_stock_status_options' ) ) {
                $values = wc_get_product_stock_status_options();
            } else {
                $values = apply_filters(
                    'woocommerce_product_stock_status_options',
                    array(
                        'instock'     => __( 'In stock', 'woocommerce' ),
                        'outofstock'  => __( 'Out of stock', 'woocommerce' ),
                        'onbackorder' => __( 'On backorder', 'woocommerce' ),
                    )
                );
            }

            foreach ( $values as $value_val => $value_name ) {
                $options[$value_val] = $value_name;
            }

            return $options;

        }

        /*
         * Get available product visibilities
         * @return array
         */
        static public function get_visibilities() {

            $options = array();

            if ( function_exists( 'wc_get_product_visibility_options' ) ) {
                $values = wc_get_product_visibility_options();
            } else {
                $values = apply_filters(
                    'woocommerce_product_visibility_options',
                    array(
                        'visible' => __( 'Shop and search results', 'woocommerce' ),
                        'catalog' => __( 'Shop only', 'woocommerce' ),
                        'search'  => __( 'Search results only', 'woocommerce' ),
                        'hidden'  => __( 'Hidden', 'woocommerce' ),
                    )
                );
            }

            foreach ( $values as $value_val => $value_name ) {
                $options[$value_val] = $value_name;
            }

            return $options;

        }

        /*
         * Get available products
         * @return array
         */
        static public function get_products() {

            $options = array();

            $args = array(
                'posts_per_page' => -1,
                'post_type'      => 'product'
            );

            $products = get_posts( $args );

            if ( ! empty( $products ) ) {
                foreach ( $products as $product ) {
                    $options[$product->ID] = $product->post_title;
                }
            }

            return $options;

        }

        /*
         * Get specific product
         * @return array
         */
        static public function get_product( $id = 0 ) {

            $value = $id;

            if ( $id ) {
                $product_object = wc_get_product( $id );
                if ( $product_object ) {
                    $formatted_name = $product_object->get_formatted_name();
                    $value = rawurldecode( wp_strip_all_tags( $formatted_name ) );
                }
            }

            return $value;

        }

        /*
         * Search for products via product name
         * @param string $name Search string
         * @return array
         */
        static public function search_for_products( $name = '' ) {

            $products = array();
            $include_variations = false;
            $limit = 30;

            if ( class_exists('WC_Data_Store') ) {

                $data_store = WC_Data_Store::load( 'product' );
                $ids        = $data_store->search_products( $name, '', (bool) $include_variations, false, $limit, array(), array() );

                foreach ( $ids as $id ) {

                    $product_object = wc_get_product( $id );

                    if ( ! wc_products_array_filter_readable( $product_object ) ) {
                        continue;
                    }

                    $formatted_name = $product_object->get_formatted_name();
                    $products[] = array(
                        'id' => $product_object->get_id(),
                        'text' => rawurldecode( wp_strip_all_tags( $formatted_name ) )
                    );

                }

            }

            return $products;

        }

        /*
         * Get available taxonomies_terms
         * @param $name string Tax name
         * @return array
         */
        static public function get_tax_terms( $name = false ) {

            if ( ! $name ) {
                return false;
            }

            $tax = get_terms( array(
                'taxonomy'   => $name,
                'hide_empty' => false,
            ) );

            $options = array();

            if ( $name && $name === 'product_shipping_class' ) {
                $options['none'] = __( "No shipping class", "advanced-woo-labels" );
            }

            if ( ! empty( $tax ) ) {
                foreach ( $tax as $tax_item ) {
                    $options[$tax_item->term_id] = $tax_item->name;
                }
            }

            /**
             * Filter options array of taxonomy terms
             * @since 1.68
             * @param array $options Terms array
             * @param string $name Taxonomy name
             */
            $options = apply_filters( 'awl_label_options_get_tax_terms', $options, $name );

            return $options;

        }

        /*
         * Get available sale discount formats
         * @return array
         */
        static public function get_sale_discount() {

            $options = array();

            $values = array(
                'percents' => __( 'percents', 'advanced-woo-labels' ),
                'amount'   => __( 'amount', 'advanced-woo-labels' ),
            );

            foreach ( $values as $value_val => $value_name ) {
                $options[$value_val] = $value_name;
            }

            return $options;

        }

        /*
         * Get all available pages
         * @return array
         */
        static public function get_pages( $name = '' ) {

            if ( $name ) {

                $pages_query = new WP_Query( array(
                    'post_type' => 'page',
                    's' => $name,
                ) );

                $pages = $pages_query->posts;

            } else {

                $pages = get_pages( array( 'parent' => 0, 'hierarchical' => 0 ) );

            }

            $options = array();

            if ( $pages && ! empty( $pages ) ) {

                foreach( $pages as $page ) {

                    $title = $page->post_title ? $page->post_title :  __( "(no title)", "advanced-woo-labels" );

                    $options[] = array(
                        'id'  => $page->ID,
                        'text' => $title
                    );

                    $child_pages = get_pages( array( 'child_of' => $page->ID ) );

                    if ( $child_pages && ! empty( $child_pages ) ) {

                        foreach( $child_pages as $child_page ) {

                            $page_prefix = '';
                            $parents_number = sizeof( $child_page->ancestors );

                            if ( $parents_number && is_int( $parents_number ) ) {
                                $page_prefix = str_repeat( "-", $parents_number );
                            }

                            $title = $child_page->post_title ? $child_page->post_title :  __( "(no title)", "advanced-woo-labels" );
                            $title = $page_prefix . $title;

                            $options[] = array(
                                'id'  => $child_page->ID,
                                'text' => $title
                            );

                        }

                    }

                }

            }

            return $options;

        }

        /*
         * Get specific page name by page id
         * $param int $page_id Specific page id
         * @return string
         */
        static public function get_page_by_id( $page_id ) {

            $value = get_the_title( $page_id );

            if ( ! $value ) {
                $value  = __( "(no title)", "advanced-woo-labels" );
            }

            return $value;

        }

        /*
         * Get all available users
         * @return array
         */
        static public function get_users( $name = '' ) {

            if ( $name ) {

                $users = get_users(array(
                    'search' => '*' . $name . '*',
                    'search_columns' => array(
                        'user_login',
                        'user_nicename',
                        'display_name',
                    ),
                ));

            } else {

                $users = get_users();

            }

            $options = array();

            if ( $users && ! empty( $users ) ) {
                foreach( $users as $user ) {
                    $options[] = array(
                        'id'  => $user->ID,
                        'text' => $user->display_name . ' (' . $user->user_nicename . ')'
                    );
                }
            }

            return $options;

        }

        /*
         * Get specific username by users id
         * $param int $user_id Specific user id
         * @return string
         */
        static public function get_user_by_id( $user_id ) {

            $value = $user_id;

            $user = get_userdata( $user_id );

            if ( $user ) {
                $value = $user->display_name . ' (' . $user->user_nicename . ')';
            }

            return $value;

        }

        /*
         * Get all available user roles
         * @return array
         */
        static public function get_user_roles() {

            global $wp_roles;

            $roles = $wp_roles->roles;
            $options = array();

            if ( $roles && ! empty( $roles ) ) {

                if ( is_multisite() ) {
                    $options['super_admin'] = __( 'Super Admin', 'advanced-woo-labels' );
                }

                foreach( $roles as $role_slug => $role ) {
                    $options[$role_slug] = $role['name'];
                }

                $options['non-logged'] = __( 'Visitor ( not logged-in )', 'advanced-woo-labels' );

            }

            return $options;

        }

        /*
         * Get available price formats
         * @return array
         */
        static public function get_price() {

            $options = array();

            $values = array(
                'current' => __( 'Current', 'advanced-woo-labels' ),
                'sale'    => __( 'Sale', 'advanced-woo-labels' ),
                'regular' => __( 'Regular', 'advanced-woo-labels' ),
            );

            foreach ( $values as $value_val => $value_name ) {
                $options[$value_val] = $value_name;
            }

            return $options;

        }

        /*
         * Get available sales number periods
         * @return array
         */
        static public function get_time_periods() {

            $options = array();

            $values = array(
                'all'   => __( 'all time', 'advanced-woo-labels' ),
                'hour'  => __( 'last 24 hours', 'advanced-woo-labels' ),
                'week'  => __( 'last 7 days', 'advanced-woo-labels' ),
                'month' => __( 'last month', 'advanced-woo-labels' ),
                'year'  => __( 'last year', 'advanced-woo-labels' ),
            );

            foreach ( $values as $value_val => $value_name ) {
                $options[$value_val] = $value_name;
            }

            return $options;

        }

        /*
         * Get available week days
         * @return array
         */
        static public function get_week_days() {

            $options = array();

            $values = array(
                'monday'    => __( 'Monday', 'advanced-woo-labels' ),
                'tuesday'   => __( 'Tuesday', 'advanced-woo-labels' ),
                'wednesday' => __( 'Wednesday', 'advanced-woo-labels' ),
                'thursday'  => __( 'Thursday', 'advanced-woo-labels' ),
                'friday'    => __( 'Friday', 'advanced-woo-labels' ),
                'saturday'  => __( 'Saturday', 'advanced-woo-labels' ),
                'sunday'    => __( 'Sunday', 'advanced-woo-labels' ),
            );

            foreach ( $values as $value_val => $value_name ) {
                $options[$value_val] = $value_name;
            }

            return $options;

        }

        /*
        * Get active site languages
        * @return array
        */
        static public function get_languages() {

            $options = array();

            if ( AWL_Helpers::is_lang_plugin_active() ) {

                if ( has_filter('wpml_active_languages') ) {

                    $languages = apply_filters( 'wpml_active_languages', NULL );
                    if ( $languages ) {

                        foreach ( $languages as $languages_code => $languages_arr ) {
                            $options[$languages_code] = isset( $languages_arr['native_name'] ) && $languages_arr['native_name'] ? $languages_arr['native_name'] : $languages_code;
                        }
                    }
                }

                elseif ( ( $enabled_languages = get_option('qtranslate_enabled_languages') ) && function_exists( 'qtranxf_getLanguage' ) ) {

                    foreach ( $enabled_languages as $enabled_languages_code ) {
                        $options[$enabled_languages_code] = $enabled_languages_code;
                    }

                }

            }

            return $options;

        }

        /*
         * Sanitize tag
         * @return string
         */
        static public function sanitize_tag( $text ) {
            $text = str_replace( array( '][', ']', '[', '_' ), '-', $text );
            $text = trim( sanitize_title( $text ), '-' );
            return $text;
        }

        /*
         * Remove script/style tags from the string
         * @return string
         */
        static public function remove_tags( $text ) {
            $text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
            return $text;
        }

        /**
         * Get description of available text variables
         * @return string
         */
        static public function get_text_variables_info() {

            $variables = AWL_Admin_Options::include_text_vars();
            $info = '';

            if ( is_array( $variables ) && ! empty( $variables ) ) {

                $info .= "<div class='awl-content-vars'>";

                foreach ( $variables as $variabl_name => $variabl_desc ) {

                    if ( strpos( $variabl_name, '{PRO}' ) !== false ) {
                        $disabled = ' awl-pro-text-var';
                        $variabl_name = str_replace( '{PRO}', '', $variabl_name );
                    } else {
                        $disabled = '';
                    }

                    $info .= "<div class='awl-content-var-item" . $disabled . "' data-text-var-tip='" . esc_attr( $variabl_desc ) . "'>";
                        $info .= "<span>" . $variabl_name . "</span>";
                    $info .= "</div>";

                }

                $info .= "</div>";

            }

            $info .= __( "for more info visit", "advanced-woo-labels" ) . '<a target="_blank" href="https://advanced-woo-labels.com/guide/text-variables/?utm_source=plugin&utm_medium=settings&utm_campaign=awl-pro-plugin"> ' . __( "guide page", "advanced-woo-labels" ) . '</a>';

            return $info;

        }

        /*
         * Get custom text variables description
         * @return string
         */
        static public function get_custom_text_variables() {

            $variables = apply_filters( 'awl_labels_text_vars', array() );
            $variables_arr = array();

            if ( $variables && is_array( $variables ) ) {
                foreach( $variables as $variable_name => $variable_params ) {
                    if ( isset( $variable_params['desc'] ) && is_string( $variable_params['desc'] ) ) {
                        $variables_name = isset( $variable_params['example'] ) && $variable_params['example'] ? $variable_params['example'] : $variable_name;
                        if ( is_string($variables_name) ) {
                            $variables_arr[$variables_name] = stripslashes( $variable_params['desc'] );
                        }
                    }
                }
            }

            return $variables_arr;

        }

        /*
         * Check for incorrect label display conditions and return them
         * @return string
         */
        static public function check_for_incorrect_display_rules( $label ) {

            $incorrect_rules_string = '';
            $check_rules = array( 'product', 'user', 'user_role', 'page', 'page_template' );

            if ( $label && isset( $label['conditions'] ) ) {
                foreach ( $label['conditions'] as $cond_group ) {

                    $maybe_wrong_rules = array();

                    foreach ( $cond_group as $cond_rule ) {
                        $rule_name = $cond_rule['param'];
                        if ( array_search( $rule_name, $check_rules ) !== false && $cond_rule['operator'] === 'equal' ) {
                            $maybe_wrong_rules[$rule_name][] = $cond_rule;
                        }
                        if ( isset( $maybe_wrong_rules[$rule_name] ) && count( $maybe_wrong_rules[$rule_name] ) > 1 ) {
                            foreach ( $maybe_wrong_rules[$rule_name] as $rule ) {
                                $rule_value = isset( $rule['value']  ) ? $rule['value'] : '';
                                $incorrect_rules_string .= $rule['param'] . ' -> ' . 'equal to' . ' -> ' . $rule_value .  '<br>';
                            }
                            break;
                        }
                    }

                    if ( $incorrect_rules_string ) {
                        break;
                    }

                }
            }

            return $incorrect_rules_string;

        }

        /*
         * Get data about advanced plugin integrations
         * @return array
         */
        static public function get_advanced_integrations() {

            $active_plugins = get_option( 'active_plugins', array() );
            if ( is_multisite() ) {
                $network_active_plugins = get_site_option( 'active_sitewide_plugins', array() );
                $active_plugins = array_merge( $active_plugins, array_keys( $network_active_plugins ) );
            }

            $integrations = array();

            if ( class_exists( 'WCFMmp' ) ) {
                $integrations[] = array(
                    'id' => 'wcfm',
                    'name' => __( 'WCFM Multivendor Marketplace plugin.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/wcfm-multivendor-marketplace/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=wcfm',
                );
            }

            if ( class_exists('ACF') ) {
                $integrations[] = array(
                    'id' => 'acf',
                    'name' => __( 'Advanced Custom Fields ( ACF ) plugin.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/advanced-custom-fields-acf-support/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=acf',
                );
            }

            if ( class_exists( 'WC_Product_Table_Plugin' ) ) {
                $integrations[] = array(
                    'id' => 'barntable',
                    'name' => __( 'WooCommerce Product Table by Barn2 plugin.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/woocommerce-product-table-by-barn2-integration/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=barntable',
                );
            }

            if ( class_exists( 'YITH_WCWL' ) ) {
                $integrations[] = array(
                    'id' => 'yithwish',
                    'name' => __( 'YITH WooCommerce Wishlist plugin.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/yith-wishlist-support/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=yithwish',
                );
            }

            if ( class_exists( 'WeDevs_Dokan' ) ) {
                $integrations[] = array(
                    'id' => 'dokan',
                    'name' => __( 'Dokan – WooCommerce Multivendor Marketplace Solution plugin.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/dokan-woocommerce-multivendor-marketplace/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=dokan',
                );
            }

            if ( defined( 'PWB_PLUGIN_VERSION' ) ) {
                $integrations[] = array(
                    'id' => 'pbrands',
                    'name' => __( 'Perfect Brands for WooCommerce plugin.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/perfect-brands-for-woocommerce/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=pbrands',
                );
            }

            if ( defined( 'WCMp_PLUGIN_VERSION' ) || defined( 'MVX_PLUGIN_VERSION' ) ) {
                $integrations[] = array(
                    'id' => 'multivendorx',
                    'name' => __( 'MultiVendorX – WooCommerce Multivendor Marketplace plugin.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/multivendorx/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=multivendorx',
                );
            }

            if ( class_exists( 'WC_Memberships' ) ) {
                $integrations[] = array(
                    'id' => 'wcmember',
                    'name' => __( 'WooCommerce Memberships plugin.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/woocommerce-memberships/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=wcmember',
                );
            }

            if ( class_exists( 'Alg_WC_EAN' ) ) {
                $integrations[] = array(
                    'id' => 'algwcean',
                    'name' => __( 'EAN for WooCommerce.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/ean-for-woocommerce/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=algwcean',
                );
            }

            if ( in_array( 'wc-vendors/class-wc-vendors.php', $active_plugins ) ) {
                $integrations[] = array(
                    'id' => 'wcvendors',
                    'name' => __( 'WC Vendors.', 'advanced-woo-labels' ),
                    'link' => 'https://advanced-woo-labels.com/guide/wc-vendors/?utm_source=wp-plugin&utm_medium=integration_notice&utm_campaign=wcvendors',
                );
            }

            return $integrations;

        }

    }

endif;