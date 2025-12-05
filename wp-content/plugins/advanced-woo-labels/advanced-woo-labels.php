<?php

/*
Plugin Name: Advanced Woo Labels
Description: Advance WooCommerce product labels plugin
Version: 2.30
Author: ILLID
Author URI: https://advanced-woo-labels.com/
Text Domain: advanced-woo-labels
Requires Plugins: woocommerce
WC requires at least: 3.0.0
WC tested up to: 10.3.0
*/


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'AWL_FILE' ) ) {
    define( 'AWL_FILE', __FILE__ );
}

if ( ! class_exists( 'AWL_Main' ) ) :

/**
 * Main plugin class
 *
 * @class AWL_Main
 */
final class AWL_Main {

    /**
     * @var AWL_Main The single instance of the class
     */
    protected static $_instance = null;

    /**
     * @var AWL_Main Array of all plugin data $data
     */
    private $data = array();

    /**
     * Main AWL_Main Instance
     *
     * Ensures only one instance of AWL_Main is loaded or can be loaded.
     *
     * @static
     * @return AWL_Main - Main instance
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct() {

        $this->define_constants();

        // Check for pro version
        if ( defined( 'AWL_PRO_VERSION' ) ) {
            return;
        }

        $this->data['settings'] = get_option( 'awl_settings' );

        add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ) );

        add_filter( 'plugin_action_links', array( $this, 'add_settings_link' ), 10, 2 );

        load_plugin_textdomain( 'advanced-woo-labels', false, dirname( plugin_basename( __FILE__ ) ). '/languages/' );

        $this->includes();

        add_action( 'init', array( $this, 'init' ), 0 );

        add_action( 'wp', array( $this, 'wp' ), 0 );

        add_action( 'before_woocommerce_init', array( $this, 'declare_wc_features_support' ) );

    }

    /**
     * Define constants
     */
    private function define_constants() {

        $this->define( 'AWL_VERSION', '2.30' );

        $this->define( 'AWL_DIR', plugin_dir_path( AWL_FILE ) );
        $this->define( 'AWL_URL', plugin_dir_url( AWL_FILE ) );
        $this->define( 'AWL_IMG', AWL_URL . '/assets/img/' );

    }

    /**
     * Include required core files used in admin and on the frontend.
     */
    public function includes() {

        include_once( 'includes/class-awl-versions.php' );
        include_once( 'includes/class-awl-taxonomy.php' );
        include_once( 'includes/class-awl-hooks.php' );
        include_once( 'includes/class-awl-integrations.php' );
        include_once( 'includes/class-awl-integrations-callbacks.php' );
        include_once( 'includes/class-awl-conditions.php' );
        include_once( 'includes/class-awl-label-view.php' );
        include_once( 'includes/class-awl-label-text.php' );
        include_once( 'includes/class-awl-helpers.php' );
        include_once( 'includes/class-awl-product-data.php' );
        include_once( 'includes/class-awl-label-display.php' );
        include_once( 'includes/class-awl-shortcodes.php' );
        include_once( 'includes/awl-functions.php' );

        // Admin
        include_once( 'includes/admin/class-awl-admin-duplicate-labels.php' );
        include_once( 'includes/admin/class-awl-admin-ajax.php' );
        include_once( 'includes/admin/class-awl-admin-options.php' );
        include_once( 'includes/admin/class-awl-admin-options-premium.php' );
        include_once( 'includes/admin/class-awl-admin-helpers.php' );
        include_once( 'includes/admin/class-awl-admin-meta-boxes.php' );
        include_once( 'includes/admin/class-awl-admin-page.php' );
        include_once( 'includes/admin/class-awl-admin-page-fields.php' );
        include_once( 'includes/admin/class-awl-admin-page-premium.php' );
        include_once( 'includes/admin/class-awl-admin-label-rules.php' );
        include_once( 'includes/admin/class-awl-admin-label-settings.php' );
        include_once( 'includes/admin/class-awl-admin-hooks-table.php' );
        include_once( 'includes/admin/class-awl-admin-notices.php' );
        include_once( 'includes/admin/class-awl-admin.php' );

    }

    /*
     * Add settings link to plugins
     */
    public function add_settings_link( $links, $file ) {
        $plugin_base = plugin_basename( __FILE__ );

        if ( $file == $plugin_base ) {
            $setting_link = '<a href="' . admin_url( 'edit.php?post_type=awl-labels&page=awl-options' ) . '">' . esc_html__( 'Settings', 'advanced-woo-labels' ) . '</a>';
            array_unshift( $links, $setting_link );

            $labels_link = '<a href="' . admin_url( 'edit.php?post_type=awl-labels' ) . '">' . esc_html__( 'View Labels', 'advanced-woo-labels' ) . '</a>';
            array_unshift( $links, $labels_link );

            $premium_link = '<a href="' . admin_url( 'edit.php?post_type=awl-labels&page=awl-options&tab=premium' ) . '">'.esc_html__( 'Premium Version', 'advanced-woo-labels' ) . '</a>';
            array_unshift( $links, $premium_link );
        }

        return $links;
    }

    /*
     * Init plugin classes
     */
    public function init() {

        AWL_Taxonomy::instance();
        AWL_Integrations::instance();
        AWL_Hooks::instance();

        AWL_Admin_Duplicate_Labels::instance();

        if ( ! isset( $this->data['labels_hook'] ) ) {
            AWL_Label_Display::instance();
        }

    }

    /*
     * Init labels display class
     */
    public function wp() {
        AWL_Label_Display::instance();
        $this->data['labels_hook'] = true;
    }

    /*
	 * Load assets for search form
	 */
    public function load_scripts() {
    }

    /*
    * Get plugin settings
    */
    public function get_settings( $name = false ) {
        $plugin_options = $this->data['settings'];
        $return_value = ! $name ? $plugin_options : ( isset( $plugin_options[ $name ] ) ? $plugin_options[ $name ] : false );
        return $return_value;
    }

    /*
     * Get plugin settings
     */
    public function get_label_settings( $id, $single = true ) {
        $label = get_post_meta( $id, '_awl_label', $single );
        if ( isset( $label['settings'] ) && isset( $label['settings']['text'] ) ) {
            $label['settings']['text'] = urldecode( $label['settings']['text'] );
        }
        return $label;
    }

    /*
     * Get plugin settings
     */
    public function update_label_settings( $id, $label ) {
        if ( isset( $label['settings'] ) && isset( $label['settings']['text'] ) && function_exists( '_wp_emoji_list' ) ) {
            $content = $label['settings']['text'];
            $emoji = _wp_emoji_list( 'partials' );
            foreach ( $emoji as $emojum ) {
                $emoji_char = html_entity_decode( $emojum );
                if ( false !== strpos( $content, $emoji_char ) ) {
                    $content = preg_replace( "/$emoji_char/", '', $content );
                }
            }
            $label['settings']['text'] = urlencode( AWL_Admin_Helpers::remove_tags( $content ) );
        }
        update_post_meta( $id, '_awl_label', $label );
    }

    /*
     * Define constant if not already set
     */
    private function define( $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    /*
     * Declare support for WooCommerce features
     */
    public function declare_wc_features_support() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

}

endif;


/**
 * Returns the main instance of AWL_Main
 *
 * @return AWL_Main
 */
function AWL() {
    return AWL_Main::instance();
}


/*
 * Check if WooCommerce is active
 */
if ( ! awl_is_plugin_active( 'advanced-woo-labels-pro/advanced-woo-labels-pro.php' ) ) {
    if ( awl_is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
        awl_init();
    } else {
        add_action( 'admin_notices', 'awl_install_woocommerce_admin_notice' );
    }
}


/*
 * Check whether the plugin is active by checking the active_plugins list.
 */
function awl_is_plugin_active( $plugin ) {
    return in_array( $plugin, (array) get_option( 'active_plugins', array() ) ) || awl_is_plugin_active_for_network( $plugin );
}


/*
 * Check whether the plugin is active for the entire network
 */
function awl_is_plugin_active_for_network( $plugin ) {
    if ( !is_multisite() )
        return false;

    $plugins = get_site_option( 'active_sitewide_plugins' );
    if ( isset($plugins[$plugin]) )
        return true;

    return false;
}


/*
 * Error notice if WooCommerce plugin is not active
 */
function awl_install_woocommerce_admin_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e( 'Advanced Woo Labels plugin is enabled but not effective. It requires WooCommerce in order to work.', 'advanced-woo-labels' ); ?></p>
    </div>
    <?php
}


/*
 * Init AWL plugin
 */
function awl_init() {
    AWL();
}