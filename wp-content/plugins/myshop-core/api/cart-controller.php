<?php

class Cart_Controller {

    public static function register_routes() {
        register_rest_route('myshop/v1', '/cart', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_cart'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/cart', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'add_to_cart'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'variation_id' => ['required' => true, 'type' => 'integer'],
                'quantity'     => ['required' => true, 'type' => 'integer']
            ]
        ]);

        register_rest_route('myshop/v1', '/cart', [
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => [self::class, 'clear_cart'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
    }

    public static function get_cart($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }
        $user_id = $user->ID;

        // 使用用户元数据存储购物车
        $cart_items = get_user_meta($user_id, '_myshop_cart', true);
        if (!is_array($cart_items)) {
            $cart_items = [];
        }

        $result = [];
        foreach ($cart_items as $item_id => $data) {
            $product = wc_get_product($item_id);
            if (!$product) {
                continue;
            }

            $result[] = [
                'product_id'   => $product->get_id(),
                'variation_id' => $product->is_type('variation') ? $product->get_id() : null,
                'name'         => $product->get_name(),
                'quantity'     => $data['quantity'],
                'price'        => $product->get_price(),
                'image_url'    => $product->get_image_id() ? wp_get_attachment_image_src($product->get_image_id(), 'thumbnail')[0] : ''
            ];
        }

        return rest_ensure_response($result);
    }

    public static function add_to_cart($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }
        $user_id = $user->ID;

        $params = $request->get_json_params();
        if (!isset($params['variation_id']) || !isset($params['quantity'])) {
            return new WP_Error('invalid_request', '缺少 variation_id 或 quantity', ['status' => 400]);
        }

        $variation_id = absint($params['variation_id']);
        $quantity = max(1, absint($params['quantity']));

        $variation = wc_get_product($variation_id);
        if (!$variation || !$variation->is_type('variation')) {
            return new WP_Error('invalid_variation', '无效的 SKU', ['status' => 400]);
        }

        $cart = get_user_meta($user_id, '_myshop_cart', true);
        if (!is_array($cart)) {
            $cart = [];
        }

        if (isset($cart[$variation_id])) {
            $cart[$variation_id]['quantity'] += $quantity;
        } else {
            $cart[$variation_id] = ['quantity' => $quantity];
        }

        update_user_meta($user_id, '_myshop_cart', $cart);

        return rest_ensure_response([
            'success' => true,
            'message' => '已添加到购物车',
            'cart_count' => count($cart)
        ]);
    }

    public static function clear_cart($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        delete_user_meta($user->ID, '_myshop_cart');

        return rest_ensure_response([
            'success' => true,
            'message' => '购物车已清空'
        ]);
    }
}