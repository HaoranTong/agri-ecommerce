<?php

/*
 * Promote PRO plugin options
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Admin_Options_Premium' ) ) :

    /**
     * Class for plugin admin panel
     */
    class AWL_Admin_Options_Premium {

        /**
         * @var AWL_Admin_Options_Premium The single instance of the class
         */
        protected static $_instance = null;

        /**
         * @var AWL_Admin_Options_Premium Active plugins arrray
         */
        public $active_plugins = array();

        /**
         * Main AWL_Admin_Options_Premium Instance
         *
         * @static
         * @return AWL_Admin_Options_Premium - Main instance
         */
        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /*
         * Constructor
         */
        public function __construct() {

            $active_plugins = get_option( 'active_plugins', array() );

            if ( is_multisite() ) {
                $network_active_plugins = get_site_option( 'active_sitewide_plugins', array() );
                $active_plugins = array_merge( $active_plugins, array_keys( $network_active_plugins ) );
            }

            $this->active_plugins = $active_plugins;

            add_filter( 'awl_labels_text_vars_description', array( $this, 'awl_labels_text_vars_description' ), 9999 );

            add_filter( 'awl_label_rules', array( $this, 'awl_premium_label_rules' ), 9999 );

            add_filter( 'awl_label_admin_options', array( $this, 'awl_label_admin_options' ), 9999 );

        }

        /*
         * Add PRO only text variables
         */
        public function awl_labels_text_vars_description( $variables ) {

            $pro_text = ' <span class="awl-text-var-pro-tip">' . __( "(Pro)", "advanced-woo-labels" ) . '</span>';

            $pro_variables = array(
                '{SALES_NUM | 7}{PRO}' => __( "Product sales number for the last X days ( or all time ).", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{REVIEWS_NUM | 30}{PRO}' => __( "Number of product reviews for the last X days ( or all time ).", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{RATING}{PRO}' => __( "Average product rating.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{LINK}{PRO}' => __( "Product permalink.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{ADD_TO_CART}{PRO}' => __( "Product add to cart link.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{SHIPPING_CLASS}{PRO}' => __( "Product shipping class.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{WEIGHT}{PRO}' => __( "Product weight.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{LENGTH}{PRO}' => __( "Product length.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{WIDTH}{PRO}' => __( "Product width.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{HEIGHT}{PRO}' => __( "Product height.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{BRAND}{PRO}' => __( "Product brands list. Use with parameter to set max number of brands to display, e.g {BRAND | 1}.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{CATEGORY}{PRO}' => __( "Product categories list. Use with parameter to set max number of categories to display, e.g {CATEGORY | 1}.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{TAG}{PRO}' => __( "Product tags list. Use with parameter to set max number of tags to display, e.g {TAG | 1}.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{TAX:slug}{PRO}' => __( "Product taxonomies list, e.g {TAX:product_cat}.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{TAX_LINK:slug}{PRO}' => __( "Link to product taxonomy page, e.g {TAX_LINK:product_cat}. Will display a link only to the first found product taxonomy.", "advanced-woo-labels" ) . $pro_text,
                '{TAX_IMAGE:slug}{PRO}' => __( "Show product taxonomy image, e.g {TAX_IMAGE:product_cat}. Will display an image of the first found product taxonomy. Use with parameter to set max image size, e.g {TAX_IMAGE:product_cat | 50}.", "advanced-woo-labels" ) . $pro_text,
                '{ATTR:slug}{PRO}' => __( "Attribute values list, e.g {ATTR:color}.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{ATTR_LINK:slug}{PRO}' => __( "Link to product attribute page, e.g {ATTR_LINK:color}. Will display a link only to the first found product attribute.", "advanced-woo-labels" ) . $pro_text,
                '{META:name}{PRO}' => __( "Custom field value, e.g {META:sales}.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{CALC:expression}{PRO}' => __( "Math calculations, e.g {CALC:{PRICE}*2}.", "advanced-woo-labels" ) . ' ' . $pro_text,
                '{FORMAT:expression}{PRO}' => __( "Format numbers from text variables, e.g {FORMAT:10000} or {FORMAT:{CALC:{PRICE}*2}}.", "advanced-woo-labels" ) . ' ' . $pro_text,
            );

            // Dokan – WooCommerce Multivendor Marketplace Solution plugin support
            if ( class_exists( 'WeDevs_Dokan' ) ) {
                $pro_variables['{DOKAN_SHOP_BADGE}{PRO}'] = __( "Dokan shop 'Sold by' badge", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{DOKAN_SHOP_LOGO}{PRO}'] = __( "Dokan shop logo for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{DOKAN_SHOP_NAME}{PRO}'] = __( "Dokan shop name for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{DOKAN_SHOP_LINK}{PRO}'] = __( "Dokan shop link for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{DOKAN_SHOP_RATING}{PRO}'] = __( "Dokan shop avarage rating", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{DOKAN_SHOP_REVIEWS_NUM}{PRO}'] = __( "Dokan shop reviews number", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{DOKAN_PRODUCT_VIEWS_NUM}{PRO}'] = __( "Dokan product views number", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            // MultiVendorX – WooCommerce multivendor marketplace plugin support
            if ( defined( 'WCMp_PLUGIN_VERSION' ) || defined( 'MVX_PLUGIN_VERSION' ) ) {
                $pro_variables['{MULTIVENDORX_SHOP_BADGE}{PRO}'] = __( "MultiVendorX shop 'Sold by' badge", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{MULTIVENDORX_SHOP_LOGO}{PRO}'] = __( "MultiVendorX shop logo for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{MULTIVENDORX_SHOP_NAME}{PRO}'] = __( "MultiVendorX shop name for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{MULTIVENDORX_SHOP_LINK}{PRO}'] = __( "MultiVendorX shop link for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{MULTIVENDORX_SHOP_RATING}{PRO}'] = __( "MultiVendorX shop avarage rating", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{MULTIVENDORX_SHOP_REVIEWS_NUM}{PRO}'] = __( "MultiVendorX shop reviews number", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{MULTIVENDORX_SHOP_PRODUCTS_NUM}{PRO}'] = __( "MultiVendorX shop products number", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{MULTIVENDORX_SHOP_NET_SALES}{PRO}'] = __( "MultiVendorX shop net sales", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{MULTIVENDORX_SHOP_ITEMS_SOLD}{PRO}'] = __( "MultiVendorX shop items sold", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            // WCFM plugin integration
            if ( class_exists( 'WCFMmp' ) ) {
                $pro_variables['{WCFM_SHOP_BADGE}{PRO}'] = __( "WCFM shop 'Sold by' badge", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCFM_SHOP_NAME}{PRO}'] = __( "WCFM shop name for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCFM_SHOP_LOGO}{PRO}'] = __( "WCFM shop logo for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCFM_SHOP_LINK}{PRO}'] = __( "WCFM shop link for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCFM_SHOP_RATING}{PRO}'] = __( "WCFM shop avarage rating", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCFM_SHOP_REVIEWS_NUM}{PRO}'] = __( "WCFM shop reviews number", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            // WC Vendors plugin support
            if ( in_array( 'wc-vendors/class-wc-vendors.php', $this->active_plugins ) ) {
                $pro_variables['{WCVENDORS_SHOP_BADGE}{PRO}'] = __( "WC Vendors shop 'Sold by' badge", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCVENDORS_SHOP_LOGO}{PRO}'] = __( "WC Vendors shop logo for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCVENDORS_SHOP_NAME}{PRO}'] = __( "WC Vendors shop name for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCVENDORS_SHOP_LINK}{PRO}'] = __( "WC Vendors shop link for current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCVENDORS_SHOP_RATING}{PRO}'] = __( "WC Vendors shop avarage rating", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCVENDORS_SHOP_REVIEWS_NUM}{PRO}'] = __( "WC Vendors shop reviews number", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCVENDORS_PRODUCTS_NUM}{PRO}'] = __( "WC Vendors shop products number", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCVENDORS_SALES_NUM}{PRO}'] = __( "WC Vendors shop sales number", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            // WooCommerce Memberships
            if ( class_exists( 'WC_Memberships' ) ) {
                $pro_variables['{WCMEMBER_USER_MEMBERSHIP}{PRO}'] = __( "WooCommerce Memberships: Current user membership plan", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCMEMBER_DISCOUNT_AMOUNT}{PRO}'] = __( "WooCommerce Memberships: Current member product discount amount", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCMEMBER_DISCOUNT_PERCENT}{PRO}'] = __( "WooCommerce Memberships: Current member product discount percentage", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCMEMBER_GRANT_ACCESS_TO}{PRO}'] = __( "WooCommerce Memberships: List of memberships that current product purchase grant access to", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCMEMBER_WHO_CAN_PURCHASE}{PRO}'] = __( "WooCommerce Memberships: List of memberships with access to purchase current product", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{WCMEMBER_RESTRICTED_TO}{PRO}'] = __( "WooCommerce Memberships: List of memberships to which the current product is restricted", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            // ACF
            if ( class_exists('ACF') ) {
                $pro_variables['{ACF:field_name}{PRO}'] = __( "ACF field value, e.g {ACF:my_field}", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            //  EAN for WooCommerce by WPFactory
            if ( class_exists( 'Alg_WC_EAN' ) ) {
                $pro_variables['{EAN}{PRO}'] = __( "EAN product number ( EAN for WooCommerce )", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            // Perfect Brands for WooCommerce
            if ( defined( 'PWB_PLUGIN_VERSION' ) ) {
                $pro_variables['{PBRANDS_NAME}{PRO}'] = __( "Perfect Brands brand name", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{PBRANDS_LOGO}{PRO}'] = __( "Perfect Brands brand logo image", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{PBRANDS_BANNER}{PRO}'] = __( "Perfect Brands brand banner image", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{PBRANDS_LINK}{PRO}'] = __( "Perfect Brands brand link to archive page", "advanced-woo-labels" ) . ' ' . $pro_text;
                $pro_variables['{PBRANDS_BANNER_LINK}{PRO}'] = __( "Perfect Brands brand banner link", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            // YITH Wishlist
            if ( class_exists( 'YITH_WCWL' ) ) {
                $pro_variables['{YITH_WISH_COUNT}{PRO}'] = __( "Total number of wishlists that contain current product", "advanced-woo-labels" ) . ' ' . $pro_text;
            }

            $variables = array_merge( $variables, $pro_variables );

            return $variables;

        }

        /*
         * Add PRO only display rules
         */
        public function awl_premium_label_rules( $options ) {

            $pro_text = ' ' . __( "(Pro)", "advanced-woo-labels" );

            $options['product'][] = array(
                "name" => __( "Product brand", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_brand",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product shipping parameters", "advanced-woo-labels" ) . $pro_text,
                "id"   => "shipping_params",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product type", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_type",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product sku", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_sku",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product GTIN, UPC, EAN, or ISBN", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_gtin",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product age", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_age",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product sale date", "advanced-woo-labels" ) . $pro_text,
                "id"   => "sale_date",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product sales number", "advanced-woo-labels" ) . $pro_text,
                "id"   => "sales",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product is in cart", "advanced-woo-labels" ) . $pro_text,
                "id"   => "in_cart",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product taxonomy", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_taxonomy",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product attributes", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_attributes",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product custom attributes", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_custom_attributes",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['product'][] = array(
                "name" => __( "Product custom fields", "advanced-woo-labels" ) . $pro_text,
                "id"   => "product_meta",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['user'][] = array(
                "name" => __( "User country", "advanced-woo-labels" ) . $pro_text,
                "id"   => "user_country",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['user'][] = array(
                "name" => __( "User language", "advanced-woo-labels" ) . $pro_text,
                "id"   => "user_language",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['user'][] = array(
                "name" => __( "User device", "advanced-woo-labels" ) . $pro_text,
                "id"   => "user_device",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['user'][] = array(
                "name" => __( "User cart", "advanced-woo-labels" ) . $pro_text,
                "id"   => "user_cart",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['user'][] = array(
                "name" => __( "User shop stats", "advanced-woo-labels" ) . $pro_text,
                "id"   => "user_shop_stats",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['page'][] = array(
                "name" => __( "Page template", "advanced-woo-labels" ) . $pro_text,
                "id"   => "page_template",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['page'][] = array(
                "name" => __( "Page type", "advanced-woo-labels" ) . $pro_text,
                "id"   => "page_type",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['page'][] = array(
                "name" => __( "Page archives", "advanced-woo-labels" ) . $pro_text,
                "id"   => "page_archives",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['page'][] = array(
                "name" => __( "Page URL", "advanced-woo-labels" ) . $pro_text,
                "id"   => "page_url",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['date'][] = array(
                "name" => __( "Date", "advanced-woo-labels" ) . $pro_text,
                "id"   => "date_range",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['date'][] = array(
                "name" => __( "Time", "advanced-woo-labels" ) . $pro_text,
                "id"   => "time_range",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );

            $options['date'][] = array(
                "name" => __( "Day of week", "advanced-woo-labels" ) . $pro_text,
                "id"   => "date_week_days",
                "type" => "bool",
                "disabled" => true,
                "operators" => "equals",
            );


            // ACF
            if ( class_exists('ACF') ) {
                $options['Special'][] = array(
                    "name" => __( "ACF fields", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "acf",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            //  EAN for WooCommerce by WPFactory
            if ( class_exists( 'Alg_WC_EAN' ) ) {
                $options['Special'][] = array(
                    "name" => __( "EAN for WooCommerce: Has EAN", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "alg_has_ean",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            // WooCommerce Product Table by Barn2 integration
            if ( class_exists( 'WC_Product_Table_Plugin' ) || class_exists('Barn2\Plugin\WC_Product_Table\Product_Table') ) {
                $options['Special'][] = array(
                    "name" => __( "Product Table: Is inside table", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "aws_is_inside_barn_table",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Special'][] = array(
                    "name" => __( "Product Table: Is inside specific table", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "aws_is_inside_specific_barn_table",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            // Dokan – WooCommerce Multivendor Marketplace Solution plugin support
            if ( class_exists( 'WeDevs_Dokan' ) ) {
                $options['Dokan'][] = array(
                    "name" => __( "Product sold by", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "dokan_sold_by",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Dokan'][] = array(
                    "name" => __( "Is product sold by any vendor", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "dokan_is_sold_by_vendor",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Dokan'][] = array(
                    "name" => __( "Is store page", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "dokan_is_store_page",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Dokan'][] = array(
                    "name" => __( "Is store featured", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "dokan_store_featured",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Dokan'][] = array(
                    "name" => __( "Store rating", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "dokan_store_rating",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Dokan'][] = array(
                    "name" => __( "Store reviews count", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "dokan_store_reviews",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Dokan'][] = array(
                    "name" => __( "Store total sales count", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "dokan_store_sales",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Dokan'][] = array(
                    "name" => __( "Product views", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "dokan_views",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            // MultiVendorX – WooCommerce multivendor marketplace plugin support
            if ( defined( 'WCMp_PLUGIN_VERSION' ) || defined( 'MVX_PLUGIN_VERSION' ) ) {
                $options['MultiVendorX'][] = array(
                    "name" => __( "Product sold by", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "multivendorx_sold_by",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['MultiVendorX'][] = array(
                    "name" => __( "Is product sold by any vendor", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "multivendorx_is_sold_by_vendor",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['MultiVendorX'][] = array(
                    "name" => __( "Is store page", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "multivendorx_is_store_page",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['MultiVendorX'][] = array(
                    "name" => __( "Store rating", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "multivendorx_store_rating",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['MultiVendorX'][] = array(
                    "name" => __( "Store reviews count", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "multivendorx_store_reviews",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['MultiVendorX'][] = array(
                    "name" => __( "Store products count", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "multivendorx_store_products",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['MultiVendorX'][] = array(
                    "name" => __( "Store net sales", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "multivendorx_store_sales",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['MultiVendorX'][] = array(
                    "name" => __( "Store items sold", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "multivendorx_store_solds",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            // WCFM plugin integration
            if ( class_exists( 'WCFMmp' ) ) {
                $options['WCFM'][] = array(
                    "name" => __( "Product sold by", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcfm_sold_by",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCFM'][] = array(
                    "name" => __( "Is product sold by any vendor", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcfm_is_sold_by_vendor",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCFM'][] = array(
                    "name" => __( "Is store page", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcfm_is_store_page",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCFM'][] = array(
                    "name" => __( "Store rating", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcfm_store_rating",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCFM'][] = array(
                    "name" => __( "Store reviews count", "advanced-woo-labels" ). $pro_text,
                    "id"   => "wcfm_store_reviews",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            // WC Vendors plugin support
            if ( in_array( 'wc-vendors/class-wc-vendors.php', $this->active_plugins ) ) {
                $options['WCVendors'][] = array(
                    "name" => __( "Is product sold by", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_sold_by",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCVendors'][] = array(
                    "name" => __( "Is product sold by any vendor", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_is_sold_by_vendor",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCVendors'][] = array(
                    "name" => __( "Is store page", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_is_store_page",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCVendors'][] = array(
                    "name" => __( "Is sold by verified vendor", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_store_verified",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCVendors'][] = array(
                    "name" => __( "Is sold by trusted vendor", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_store_trusted",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCVendors'][] = array(
                    "name" => __( "Vendor store rating", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_store_rating",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCVendors'][] = array(
                    "name" => __( "Vendor store reviews count", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_store_reviews",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCVendors'][] = array(
                    "name" => __( "Vendor store total sales count", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_store_sales",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['WCVendors'][] = array(
                    "name" => __( "Vendor store products number", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "wcvendors_store_products",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            // WooCommerce Memberships
            if ( class_exists( 'WC_Memberships' ) ) {
                $options['Woo Memberships'][] = array(
                    "name" => __( "Current user membership", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "woo_memberships_is_current_member",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Woo Memberships'][] = array(
                    "name" => __( "Current user can purchase", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "woo_memberships_can_purchase",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Woo Memberships'][] = array(
                    "name" => __( "Current user can view", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "woo_memberships_can_view",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Woo Memberships'][] = array(
                    "name" => __( "Current user has discount", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "woo_memberships_has_discount",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Woo Memberships'][] = array(
                    "name" => __( "Product has discount for", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "woo_memberships_has_discount_for",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Woo Memberships'][] = array(
                    "name" => __( "Product grant access to", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "woo_memberships_grant_access_to",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Woo Memberships'][] = array(
                    "name" => __( "Who can purchase product", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "woo_memberships_who_can_purchase",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Woo Memberships'][] = array(
                    "name" => __( "Who can view product", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "woo_memberships_who_can_view",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            if ( class_exists( 'YITH_WCWL' ) ) {
                $options['Special'][] = array(
                    "name" => __( "YITH Wishlist: Is product in current user wishlist", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "yith_wish_is_in_list",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Special'][] = array(
                    "name" => __( "YITH Wishlist: Is wishlist page", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "yith_wish_is_page",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
                $options['Special'][] = array(
                    "name" => __( "YITH Wishlist: Product number of wishlists", "advanced-woo-labels" ) . $pro_text,
                    "id"   => "yith_wish_in_wishlist",
                    "type" => "bool",
                    "disabled" => true,
                    "operators" => "equals",
                );
            }

            return $options;

        }

        /*
         * Add PRO only label options
         */
        public function awl_label_admin_options( $options ) {

            $options['animation'][] = array(
                "name" => __( "Animation", "advanced-woo-labels" ) . ' <a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=pro-option-link&utm_campaign=pricing&utm_content=animation">' . __( "(Pro)", "advanced-woo-labels" ) . '</a>',
                "id"   => "animation",
                "value" => 'none',
                "type"  => "select",
                'choices' => array(
                    'none' => __( 'None', 'advanced-woo-labels' ),
                ),
                "disabled" => true,
            );

            $options['animation'][] = array(
                "name" => __( "Apply to", "advanced-woo-labels" ) . ' <a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=pro-option-link&utm_campaign=pricing&utm_content=animation_apply_to">' . __( "(Pro)", "advanced-woo-labels" ) . '</a>',
                "id"   => "animation_apply_to",
                "value" => 'label',
                "type"  => "select",
                'choices' => array(
                    'label'  => __( 'Label block', 'advanced-woo-labels' ),
                ),
                "class" => "awl-for-text",
                "disabled" => true,
            );

            $options['animation'][] = array(
                "name" => __( "Duration (ms)", "advanced-woo-labels" ) . ' <a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=pro-option-link&utm_campaign=pricing&utm_content=animation_duration">' . __( "(Pro)", "advanced-woo-labels" ) . '</a>',
                "id"   => "animation_duration",
                "min" => "0",
                "step" => "1",
                "value" => '2000',
                "type"  => "number",
                "disabled" => true,
            );

            $options['animation'][] = array(
                "name" => __( "Repeats number", "advanced-woo-labels" ) . ' <a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=pro-option-link&utm_campaign=pricing&utm_content=animation_repeats">' . __( "(Pro)", "advanced-woo-labels" ) . '</a>',
                "id"   => "animation_repeats",
                "min" => "1",
                "step" => "1",
                "value" => '1',
                "type"  => "number",
                "disabled" => true,
            );

            $options['animation'][] = array(
                "name" => __( "Infinite", "advanced-woo-labels" ) . ' <a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=pro-option-link&utm_campaign=pricing&utm_content=animation_infinite">' . __( "(Pro)", "advanced-woo-labels" ) . '</a>',
                "id"   => "animation_infinite",
                "value" => 'false',
                "type"  => "checkbox",
                "disabled" => true,
            );

            $options['animation'][] = array(
                "name" => __( "Delay (ms)", "advanced-woo-labels" ) . ' <a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=pro-option-link&utm_campaign=pricing&utm_content=animation_delay">' . __( "(Pro)", "advanced-woo-labels" ) . '</a>',
                "id"   => "animation_delay",
                "min" => "0",
                "step" => "1",
                "value" => '0',
                "type"  => "number",
                "disabled" => true,
            );

            $options['link'][] = array(
                "name" => __( "Label link", "advanced-woo-labels" ) . ' <a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=pro-option-link&utm_campaign=pricing&utm_content=link_url">' . __( "(Pro)", "advanced-woo-labels" ) . '</a>',
                "id"   => "link_url",
                "value" => '',
                "spoiler" => array(
                    "title" => '* ' . __( "supports variables", "advanced-woo-labels" ),
                    "text"  => AWL_Admin_Helpers::get_text_variables_info( true ),
                ),
                "type"  => "text",
                "disabled" => true,
            );

            $options['link'][] = array(
                "name" => __( "Open in new window", "advanced-woo-labels" ) . ' <a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=pro-option-link&utm_campaign=pricing&utm_content=link_window">' . __( "(Pro)", "advanced-woo-labels" ) . '</a>',
                "id"   => "link_window",
                "value" => 'false',
                "type"  => "checkbox",
                "disabled" => true,
            );

            return $options;

        }

    }

endif;

AWL_Admin_Options_Premium::instance();