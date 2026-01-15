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

        register_rest_route('myshop/v1', '/cart/(?P<variation_id>\d+)', [
            'methods' => \WP_REST_Server::EDITABLE,
            'callback' => [self::class, 'update_item'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'variation_id' => ['required' => true, 'type' => 'integer'],
                'quantity'     => ['required' => true, 'type' => 'integer']
            ]
        ]);

        register_rest_route('myshop/v1', '/cart/(?P<variation_id>\d+)', [
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => [self::class, 'remove_item'],
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

            // 获取父产品和变体信息
            $parent_product = null;
            $variation_name = '';
            $attributes = [];
            
            if ($product->is_type('variation')) {
                $parent_product = wc_get_product($product->get_parent_id());
                $product_name = $parent_product ? $parent_product->get_name() : $product->get_name();
                
                // 获取变体属性（格式化为中文标签）
                $variation_attributes = $product->get_attributes();
                $attr_labels = [];
                
                foreach ($variation_attributes as $taxonomy => $term_slug) {
                    // 获取属性的中文名称
                    $taxonomy_label = wc_attribute_label($taxonomy);
                    
                    // 获取属性值的中文标签
                    if (taxonomy_exists($taxonomy)) {
                        $term = get_term_by('slug', $term_slug, $taxonomy);
                        $term_name = $term ? $term->name : $term_slug;
                    } else {
                        $term_name = $term_slug;
                    }
                    
                    $attr_labels[] = $taxonomy_label . ': ' . $term_name;
                }
                
                $variation_name = implode(' | ', $attr_labels);
            } else {
                $product_name = $product->get_name();
                $variation_name = '';
            }

            $result[] = [
                'product_id'    => $product->is_type('variation') ? $product->get_parent_id() : $product->get_id(),
                'variation_id'  => $product->is_type('variation') ? $product->get_id() : 0,
                'product_name'  => $product_name,
                'variation_name' => $variation_name,
                'quantity'      => $data['quantity'],
                'price'         => $product->get_price(),
                'image_url'     => $product->get_image_id() ? wp_get_attachment_image_src($product->get_image_id(), 'thumbnail')[0] : ''
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

    public static function update_item($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $variation_id = absint($request['variation_id']);
        if (!$variation_id) {
            return new WP_Error('invalid_variation', '无效的 SKU', ['status' => 400]);
        }

        $params = $request->get_json_params();
        $quantity = isset($params['quantity']) ? intval($params['quantity']) : 0;

        $cart = get_user_meta($user->ID, '_myshop_cart', true);
        if (!is_array($cart)) {
            $cart = [];
        }

        if ($quantity <= 0) {
            if (isset($cart[$variation_id])) {
                unset($cart[$variation_id]);
                update_user_meta($user->ID, '_myshop_cart', $cart);
            }
            return rest_ensure_response([
                'success' => true,
                'message' => '已从购物车移除'
            ]);
        }

        $variation = wc_get_product($variation_id);
        if (!$variation || !$variation->is_type('variation')) {
            return new WP_Error('invalid_variation', '无效的 SKU', ['status' => 400]);
        }

        $cart[$variation_id] = ['quantity' => $quantity];
        update_user_meta($user->ID, '_myshop_cart', $cart);

        return rest_ensure_response([
            'success' => true,
            'message' => '购物车数量已更新',
            'quantity' => $quantity
        ]);
    }

    public static function remove_item($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $variation_id = absint($request['variation_id']);
        if (!$variation_id) {
            return new WP_Error('invalid_variation', '无效的 SKU', ['status' => 400]);
        }

        $cart = get_user_meta($user->ID, '_myshop_cart', true);
        if (!is_array($cart)) {
            $cart = [];
        }

        if (isset($cart[$variation_id])) {
            unset($cart[$variation_id]);
            update_user_meta($user->ID, '_myshop_cart', $cart);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => '已从购物车移除'
        ]);
    }
}