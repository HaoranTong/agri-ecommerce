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

// 引入后台管理页面
if (is_admin()) {
    require_once MYSHOP_PLUGIN_DIR . 'admin/config-page.php';
    require_once MYSHOP_PLUGIN_DIR . 'admin/payment-proof-manager.php';
    require_once MYSHOP_PLUGIN_DIR . 'admin/order-manager.php';
    require_once MYSHOP_PLUGIN_DIR . 'admin/points-manager.php';
    require_once MYSHOP_PLUGIN_DIR . 'admin/test-users-manager.php';
    
    // 测试用户清理工具（仅开发环境）
    add_action('admin_menu', function() {
        add_submenu_page(
            null, // 不显示在菜单中，只能通过直接访问
            '清理测试用户',
            '清理测试用户',
            'manage_options',
            'myshop-cleanup-test-users',
            function() {
                require_once MYSHOP_PLUGIN_DIR . 'admin/cleanup-test-users.php';
            }
        );
    }, 100);
}

// 引入轮播图短代码（PC端使用）
require_once MYSHOP_PLUGIN_DIR . 'admin/slider-shortcode.php';

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