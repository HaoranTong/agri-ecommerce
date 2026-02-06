<?php
class MyShop_Loader {
    public static function init() {
        $includes = [
            'includes/class-myshop-auth.php',
            'includes/class-myshop-wechat.php',
            'includes/class-myshop-commission-service.php',
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

        if (class_exists('MyShop_Commission_Service') && method_exists('MyShop_Commission_Service', 'boot')) {
            MyShop_Commission_Service::boot();
        }

        add_action('init', function () {
            register_post_status('wc-return-requested', [
                'label' => '申请退货',
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop('申请退货 <span class="count">(%s)</span>', '申请退货 <span class="count">(%s)</span>')
            ]);
        });

        add_filter('woocommerce_order_statuses', function ($statuses) {
            $new_statuses = [];
            foreach ($statuses as $key => $label) {
                $new_statuses[$key] = $label;
                if ($key === 'wc-processing') {
                    $new_statuses['wc-return-requested'] = '申请退货';
                }
            }
            if (!isset($new_statuses['wc-return-requested'])) {
                $new_statuses['wc-return-requested'] = '申请退货';
            }
            return $new_statuses;
        });

        $status_label_filter = function ($statuses) {
            if (isset($statuses['wc-on-hold'])) {
                $statuses['wc-on-hold'] = '已发货/运输中';
            }
            if (isset($statuses['wc-return-requested'])) {
                $statuses['wc-return-requested'] = '申请退货';
            }
            return $statuses;
        };

        add_filter('woocommerce_order_statuses', $status_label_filter);
        add_filter('wc_order_statuses', $status_label_filter);

        add_filter('woocommerce_payment_complete_order_status', function ($status, $order_id, $order) {
            if (!$order instanceof WC_Order) {
                return $status;
            }

            $is_gift_card_order = $order->get_meta('_myshop_is_gift_card_order', true) === 'yes';
            if ($is_gift_card_order) {
                return $status;
            }

            $needs_shipping = $order->needs_shipping_address()
                || $order->get_shipping_address_1()
                || $order->get_shipping_city()
                || $order->get_shipping_state();

            return $needs_shipping ? 'processing' : $status;
        }, 20, 3);
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