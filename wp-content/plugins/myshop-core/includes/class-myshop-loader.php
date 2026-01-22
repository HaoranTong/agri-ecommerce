<?php
class MyShop_Loader {
    public static function init() {
        $includes = [
            'includes/class-myshop-auth.php',
            'includes/class-myshop-wechat.php',
            'api/auth-controller.php',
            'api/user-controller.php',
            'api/product-controller.php',
            'api/order-controller.php',
            'api/cart-controller.php',
            'api/config-controller.php',
            'api/invitation-controller.php',
            'api/analytics-controller.php',
            'api/gift-card-controller.php',
            'api/points-controller.php',
            'api/referral-controller.php',
            'api/commission-controller.php',
            'api/agent-controller.php',
            'api/coupon-controller.php',
            'api/promo-controller.php',
            'api/payment-controller.php'
        ];

        foreach ($includes as $relative_path) {
            $absolute = MYSHOP_PLUGIN_DIR . $relative_path;
            if (file_exists($absolute)) {
                require_once $absolute;
            } else {
                error_log('[MyShop Core] Missing include: ' . $relative_path);
            }
        }

        add_action('plugins_loaded', ['MyShop_DB', 'upgrade']);
        add_action('rest_api_init', ['MyShop_Loader', 'register_routes']);

        if (class_exists('Gift_Card_Controller') && method_exists('Gift_Card_Controller', 'boot')) {
            Gift_Card_Controller::boot();
        }

        if (class_exists('Order_Controller') && method_exists('Order_Controller', 'boot')) {
            Order_Controller::boot();
        }
    }

    public static function register_routes() {
        $controllers = [
            'Auth_Controller',
            'User_Controller',
            'Product_Controller',
            'Order_Controller',
            'Cart_Controller',
            'Config_Controller',
            'Invitation_Controller',
            'Analytics_Controller',
            'Gift_Card_Controller',
            'Points_Controller',
            'Referral_Controller',
            'Commission_Controller',
            'Agent_Controller',
            'Coupon_Controller',
            'Promo_Controller',
            'Payment_Controller'
        ];

        foreach ($controllers as $controller) {
            if (class_exists($controller) && method_exists($controller, 'register_routes')) {
                call_user_func([$controller, 'register_routes']);
            }
        }
    }
}