<?php
class MyShop_Loader {
    public static function init() {
        // 加载认证类
        require_once MYSHOP_PLUGIN_DIR . 'includes/class-myshop-auth.php';
        // 加载数据库类
        require_once MYSHOP_PLUGIN_DIR . 'db/class-myshop-db.php';
        // 加载控制器
        require_once MYSHOP_PLUGIN_DIR . 'api/auth-controller.php';
        require_once MYSHOP_PLUGIN_DIR . 'api/user-controller.php';
        require_once MYSHOP_PLUGIN_DIR . 'api/product-controller.php';
        require_once MYSHOP_PLUGIN_DIR . 'api/order-controller.php';
        require_once MYSHOP_PLUGIN_DIR . 'api/cart-controller.php'; // ✅ 加载购物车控制器

        // 注册激活钩子
        register_activation_hook(MYSHOP_PLUGIN_DIR . 'myshop-core.php', ['MyShop_DB', 'activate']);

        // 注册 REST API
        add_action('rest_api_init', ['MyShop_Loader', 'register_routes']);
    }

    public static function register_routes() {
        // Auth
        register_rest_route('myshop/v1', '/auth/login', [
            'methods' => 'POST',
            'callback' => ['Auth_Controller', 'login'],
            'permission_callback' => '__return_true'
        ]);

        // User
        register_rest_route('myshop/v1', '/user/profile', [
            'methods' => 'GET',
            'callback' => ['User_Controller', 'get_profile'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        // Products
        register_rest_route('myshop/v1', '/products', [
            'methods' => 'GET',
            'callback' => ['Product_Controller', 'list_products'],
            'permission_callback' => '__return_true'
        ]);
        register_rest_route('myshop/v1', '/products/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => ['Product_Controller', 'get_detail'],
            'permission_callback' => '__return_true',
            'args' => ['id' => ['required' => true]]
        ]);

        // Orders
        register_rest_route('myshop/v1', '/orders', [
            'methods' => 'POST',
            'callback' => ['Order_Controller', 'create'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'variation_id' => ['required' => true],
                'quantity' => ['required' => true]
            ]
        ]);

        // ✅ 新增：获取订单列表
    register_rest_route('myshop/v1', '/orders', [
        'methods' => 'GET',
        'callback' => ['Order_Controller', 'list_orders'],
        'permission_callback' => ['MyShop_Auth', 'check_permission']
    ]);

            // Cart
        register_rest_route('myshop/v1', '/cart', [
            'methods' => 'GET',
            'callback' => ['Cart_Controller', 'get_cart'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/cart', [
            'methods' => 'POST',
            'callback' => ['Cart_Controller', 'add_to_cart'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'variation_id' => ['required' => true, 'type' => 'integer'],
                'quantity'     => ['required' => true, 'type' => 'integer']
            ]
        ]);
    }
}