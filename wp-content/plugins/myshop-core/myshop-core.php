<?php
/**
 * Plugin Name: MyShop Core
 * Description: 微信小程序无头电商后端核心插件，提供 JWT 认证、自定义 API、分销、代理商、虚拟购物卡等功能。
 * Version: 1.1.0
 * Author: Your Team
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.3
 * Plugin Type: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MYSHOP_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once MYSHOP_PLUGIN_DIR . 'db/class-myshop-db.php';
require_once MYSHOP_PLUGIN_DIR . 'includes/class-myshop-loader.php';

add_action('before_woocommerce_init', function () {
    if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

register_activation_hook(__FILE__, ['MyShop_DB', 'install']);

MyShop_Loader::init();