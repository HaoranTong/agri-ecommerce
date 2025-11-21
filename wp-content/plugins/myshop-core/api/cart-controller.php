<?php

class Cart_Controller {

    public static function get_cart($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        // ✅ 从 Authorization 头解析 token 获取 user_id（无状态）
        $token = preg_replace('/^Bearer\s+/', '', $request->get_header('Authorization'));
        $user = MyShop_Auth::validate_token($token);
        if (!$user || !isset($user->ID)) {
            return new WP_Error('unauthorized', '用户未登录', ['status' => 401]);
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

        // ✅ 同样从 token 获取 user_id
        $token = preg_replace('/^Bearer\s+/', '', $request->get_header('Authorization'));
        $user = MyShop_Auth::validate_token($token);
        if (!$user || !isset($user->ID)) {
            return new WP_Error('unauthorized', '用户未登录', ['status' => 401]);
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
}