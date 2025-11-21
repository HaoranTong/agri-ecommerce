<?php

class Order_Controller {

    public static function create($request) {
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
        if ($variation->get_stock_quantity() < $quantity) {
            return new WP_Error('insufficient_stock', '库存不足', ['status' => 400]);
        }

        $order = wc_create_order();
        $order->add_product($variation, $quantity);

        // ✅ 从 token 获取 user_id（与 list_orders 一致）
        $token = preg_replace('/^Bearer\s+/', '', $request->get_header('Authorization'));
        $user = MyShop_Auth::validate_token($token);
        if (!$user || !isset($user->ID)) {
            return new WP_Error('unauthorized', '用户未登录', ['status' => 401]);
        }
        $order->set_customer_id($user->ID);
        $order->set_payment_method('cod'); // 设置为货到付款

        // ✅ 处理中国标准收货地址
        if (isset($params['shipping_address'])) {
            $addr = $params['shipping_address'];

            // 验证必要字段
            if (!isset($addr['name']) || !isset($addr['phone']) || !isset($addr['province']) ||
                !isset($addr['city']) || !isset($addr['district']) || !isset($addr['detail_address'])) {
                return new WP_Error('invalid_address', '收货地址信息不完整', ['status' => 400]);
            }

            // 映射到 WooCommerce 地址结构（first_name 存全名，last_name 留空）
            $order->set_address([
                'first_name' => sanitize_text_field($addr['name']),
                'last_name'  => '',
                'address_1'  => sanitize_text_field($addr['detail_address']),
                'city'       => sanitize_text_field($addr['city']),
                'state'      => sanitize_text_field($addr['province']), // WooCommerce 的 state 对应“省”
                'postcode'   => isset($addr['postcode']) ? sanitize_text_field($addr['postcode']) : '',
                'country'    => 'CN'
            ], 'shipping');

            $order->set_billing_phone(sanitize_text_field($addr['phone']));
        } else {
            return new WP_Error('missing_address', '请提供收货地址', ['status' => 400]);
        }

        $order->calculate_totals();

        // ✅ 从 WooCommerce 货到付款描述中提取二维码 URL
        $payment_qr_url = '';
        $customer_service_qr = '';

        $gateways = WC()->payment_gateways->payment_gateways();
        if (isset($gateways['cod']) && $gateways['cod']->enabled === 'yes') {
            $description = $gateways['cod']->description;
            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $description, $matches);
            $image_urls = $matches[1] ?? [];

            if (!empty($image_urls)) {
                $payment_qr_url = esc_url_raw($image_urls[0]); // 第一张：收款码
                $customer_service_qr = count($image_urls) > 1 ? esc_url_raw($image_urls[1]) : $payment_qr_url; // 第二张：客服码
            }
        }

        // 如果没提取到，使用默认占位图（可选）
        if (!$payment_qr_url) {
            $upload_dir = wp_upload_dir();
            $payment_qr_url = $upload_dir['baseurl'] . '/default-pay-qr.jpg';
            $customer_service_qr = $upload_dir['baseurl'] . '/default-service-qr.jpg';
        }

        return rest_ensure_response([
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(), // ← 新增 order_number 字段
            'total' => $order->get_total(),
            'status' => $order->get_status(),
            'items' => [[
                'product_id' => $variation->get_parent_id(),
                'variation_id' => $variation_id,
                'quantity' => $quantity,
                'price' => $variation->get_price()
            ]],
            'payment_qr_url' => $payment_qr_url,
            'customer_service_qr' => $customer_service_qr, // ← 字段名修正
            'message' => '请扫码向客服付款，并添加企业微信发送付款截图，我们将尽快为您发货。'
        ]);
    }

    // ✅ 获取当前用户的订单列表
    public static function list_orders($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        // 从 token 中获取 user_id（不能依赖 get_current_user_id）
        $token = preg_replace('/^Bearer\s+/', '', $request->get_header('Authorization'));
        $user = MyShop_Auth::validate_token($token);
        if (!$user || !isset($user->ID)) {
            return new WP_Error('unauthorized', '用户未登录', ['status' => 401]);
        }
        $user_id = $user->ID;

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => -1,
            'orderby'     => 'date',
            'order'       => 'DESC'
        ]);

        $data = [];
        foreach ($orders as $order) {
            $items = [];
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $items[] = [
                    'product_id'   => $product ? $product->get_id() : null,
                    'variation_id' => $product && $product->is_type('variation') ? $product->get_id() : null,
                    'name'         => $item->get_name(),
                    'quantity'     => $item->get_quantity(),
                    'price'        => $item->get_total()
                ];
            }

            $data[] = [
                'id'         => $order->get_id(),
                'number'     => $order->get_order_number(),
                'status'     => $order->get_status(),
                'total'      => $order->get_total(),
                'created_at' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
                'items'      => $items
            ];
        }

        return rest_ensure_response($data);
    }
}