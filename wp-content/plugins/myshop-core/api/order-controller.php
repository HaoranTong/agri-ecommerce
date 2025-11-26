<?php

class Order_Controller {

    public static function register_routes() {
        register_rest_route('myshop/v1', '/orders', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'create'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'variation_id' => ['required' => true, 'type' => 'integer'],
                'quantity'     => ['required' => true, 'type' => 'integer']
            ]
        ]);

        register_rest_route('myshop/v1', '/orders', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_orders'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/orders/(?P<order_id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_order_detail'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'order_id' => ['required' => true, 'type' => 'integer']
            ]
        ]);

        register_rest_route('myshop/v1', '/orders/(?P<order_id>\d+)/upload-payment-proof', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'upload_payment_proof'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'order_id' => ['required' => true, 'type' => 'integer']
            ]
        ]);
    }

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

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }
        $order->set_customer_id($user->ID);
        $user_id = $user->ID;
        $order->set_payment_method('cod'); // 设置为货到付款

        // ✅ 处理积分抵扣
        $points_to_use = isset($params['points_to_use']) ? absint($params['points_to_use']) : 0;
        if ($points_to_use > 0) {
            $points_discount = self::calculate_points_discount($user_id, $points_to_use, $order);
            if (is_wp_error($points_discount)) {
                return $points_discount;
            }
            
            if ($points_discount['discount_amount'] > 0) {
                // 添加积分抵扣费用项（负数）
                $fee = new WC_Order_Item_Fee();
                $fee->set_name('积分抵扣');
                $fee->set_amount(-$points_discount['discount_amount']);
                $fee->set_total(-$points_discount['discount_amount']);
                $order->add_item($fee);
                
                // 保存使用的积分数
                $order->update_meta_data('_points_used', $points_to_use);
                $order->update_meta_data('_points_discount_amount', $points_discount['discount_amount']);
            }
        }

        // ✅ 处理中国标准收货地址
        if (isset($params['shipping_address'])) {
            $addr = $params['shipping_address'];

            // detail_address 与 address 兼容处理
            $detail_address = null;
            if (isset($addr['detail_address']) && $addr['detail_address'] !== '') {
                $detail_address = $addr['detail_address'];
            } elseif (isset($addr['address']) && $addr['address'] !== '') {
                $detail_address = $addr['address'];
            }

            // 验证必要字段
            if (!isset($addr['name']) || !isset($addr['phone']) || !isset($addr['province']) ||
                !isset($addr['city']) || !isset($addr['district']) || !$detail_address) {
                return new WP_Error('invalid_address', '收货地址信息不完整', ['status' => 400]);
            }

            // 映射到 WooCommerce 地址结构（first_name 存全名，last_name 留空）
            $order->set_address([
                'first_name' => sanitize_text_field($addr['name']),
                'last_name'  => '',
                'address_1'  => sanitize_text_field($detail_address),
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

        // ✅ 自动保存收货地址到用户地址列表
        $user_addresses = get_user_meta($user_id, '_myshop_addresses', true);
        if (!is_array($user_addresses)) {
            $user_addresses = [];
        }

        // 检查是否已存在相同地址
        $address_exists = false;
        foreach ($user_addresses as $addr) {
            if (
                $addr['name'] === $address['name'] &&
                $addr['phone'] === $address['phone'] &&
                $addr['province'] === $address['province'] &&
                $addr['city'] === $address['city'] &&
                $addr['district'] === $address['district'] &&
                $addr['detail'] === $address['detail']
            ) {
                $address_exists = true;
                break;
            }
        }

        // 如果地址不存在，添加到列表
        if (!$address_exists) {
            $new_address = [
                'id' => uniqid('addr_'),
                'name' => $address['name'],
                'phone' => $address['phone'],
                'province' => $address['province'],
                'city' => $address['city'],
                'district' => $address['district'],
                'detail' => $address['detail'],
                'postal_code' => $address['postal_code'] ?? '',
                'is_default' => empty($user_addresses) ? true : false, // 第一个地址设为默认
                'created_at' => current_time('mysql')
            ];
            $user_addresses[] = $new_address;
            update_user_meta($user_id, '_myshop_addresses', $user_addresses);
        }

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

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
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

            // 获取快递信息
            $tracking_number = get_post_meta($order->get_id(), '_myshop_tracking_number', true) ?: '';
            $tracking_company = get_post_meta($order->get_id(), '_myshop_tracking_company', true) ?: '';
            $shipped_at = get_post_meta($order->get_id(), '_myshop_shipped_at', true) ?: '';
            
            $data[] = [
                'order_id'         => $order->get_id(),
                'order_number'     => $order->get_order_number(),
                'status'           => $order->get_status(),
                'total'            => $order->get_total(),
                'created_at'       => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
                'items'            => $items,
                'tracking_number'  => $tracking_number,
                'tracking_company' => $tracking_company,
                'shipped_at'       => $shipped_at
            ];
        }

        return rest_ensure_response($data);
    }

    // 获取单个订单详情
    public static function get_order_detail($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }
        $user_id = $user->ID;

        $order_id = absint($request->get_param('order_id'));
        if (!$order_id) {
            return new WP_Error('invalid_order_id', '无效的订单ID', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', '订单不存在', ['status' => 404]);
        }

        // 验证订单所有权
        if ($order->get_customer_id() !== $user_id) {
            return new WP_Error('unauthorized', '无权访问此订单', ['status' => 403]);
        }

        // 获取订单商品
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            // 获取变体属性（格式化为中文）
            $variation_name = '';
            if ($product && $product->is_type('variation')) {
                $variation_attributes = $product->get_attributes();
                $attr_labels = [];
                
                foreach ($variation_attributes as $taxonomy => $term_slug) {
                    $taxonomy_label = wc_attribute_label($taxonomy);
                    
                    if (taxonomy_exists($taxonomy)) {
                        $term = get_term_by('slug', $term_slug, $taxonomy);
                        $term_name = $term ? $term->name : $term_slug;
                    } else {
                        $term_name = $term_slug;
                    }
                    
                    $attr_labels[] = $taxonomy_label . ': ' . $term_name;
                }
                
                $variation_name = implode(' | ', $attr_labels);
            }
            
            $items[] = [
                'product_id'     => $product ? ($product->is_type('variation') ? $product->get_parent_id() : $product->get_id()) : null,
                'variation_id'   => $product && $product->is_type('variation') ? $product->get_id() : 0,
                'product_name'   => $item->get_name(),
                'variation_name' => $variation_name,
                'quantity'       => $item->get_quantity(),
                'price'          => $item->get_total() / $item->get_quantity(),
                'subtotal'       => $item->get_total()
            ];
        }

        // ✅ 从 WooCommerce 货到付款描述中提取二维码（与创建订单时保持一致）
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

        // 如果没提取到，尝试从 myshop_public_config 读取（向后兼容）
        if (!$payment_qr_url) {
            $config = get_option('myshop_public_config', []);
            $payment_qr_url = $config['payment_qr_url'] ?? '';
            $customer_service_qr = $config['customer_service_qr'] ?? '';
        }

        // 获取收货地址信息
        $shipping_address = [
            'name'           => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
            'phone'          => $order->get_billing_phone(),
            'province'       => $order->get_shipping_state(),
            'city'           => $order->get_shipping_city(),
            'district'       => $order->get_meta('_shipping_district', true) ?: '',
            'detail_address' => $order->get_shipping_address_1(),
            'postcode'       => $order->get_shipping_postcode()
        ];

        // 获取支付凭证状态（兼容新旧两种存储方式）
        $payment_proof_url = get_post_meta($order->get_id(), '_myshop_payment_proof_url', true);
        if (!$payment_proof_url) {
            // 兼容旧版本：从媒体库读取
            $payment_proof_id = get_post_meta($order->get_id(), '_myshop_payment_proof', true);
            $payment_proof_url = $payment_proof_id ? wp_get_attachment_url($payment_proof_id) : '';
        }
        $payment_proof_submitted_at = get_post_meta($order->get_id(), '_myshop_payment_proof_submitted_at', true);
        
        // 获取物流信息
        $tracking_number = get_post_meta($order->get_id(), '_myshop_tracking_number', true) ?: '';
        $tracking_company = get_post_meta($order->get_id(), '_myshop_tracking_company', true) ?: '';
        $shipped_at = get_post_meta($order->get_id(), '_myshop_shipped_at', true) ?: '';

        return rest_ensure_response([
            'order_id'            => $order->get_id(),
            'order_number'        => $order->get_order_number(),
            'status'              => $order->get_status(),
            'total'               => $order->get_total(),
            'created_at'          => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
            'items'               => $items,
            'payment_qr_url'      => $payment_qr_url,
            'customer_service_qr' => $customer_service_qr,
            'shipping_address'    => $shipping_address,
            'payment_proof_url'   => $payment_proof_url,
            'payment_proof_submitted_at' => $payment_proof_submitted_at,
            'has_payment_proof'   => !empty($payment_proof_url),
            'tracking_number'     => $tracking_number,
            'tracking_company'    => $tracking_company,
            'shipped_at'          => $shipped_at
        ]);
    }

    public static function upload_payment_proof($request) {
        $auth = MyShop_Auth::check_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $order_id = absint($request->get_param('order_id'));
        if (!$order_id) {
            return new WP_Error('missing_order_id', '缺少订单 ID', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order || (int) $order->get_customer_id() !== (int) $user->ID) {
            return new WP_Error('order_not_found', '订单不存在或无权访问', ['status' => 404]);
        }

        if (empty($_FILES['proof_image'])) {
            return new WP_Error('upload_failed', '请上传付款截图', ['status' => 422]);
        }

        $file = $_FILES['proof_image'];
        $allowed = ['image/jpeg', 'image/png'];
        if (!in_array($file['type'], $allowed, true)) {
            return new WP_Error('upload_failed', '仅支持 JPG/PNG 图片', ['status' => 422]);
        }

        if ($file['size'] > 5 * MB_IN_BYTES) {
            return new WP_Error('upload_failed', '图片大小超出 5MB 限制', ['status' => 422]);
        }

        // ✅ 保存到独立目录，不使用媒体库
        $upload_dir = wp_upload_dir();
        $proof_dir = $upload_dir['basedir'] . '/payment-proofs/' . date('Y/m');
        $proof_url_base = $upload_dir['baseurl'] . '/payment-proofs/' . date('Y/m');
        
        // 创建目录（如果不存在）
        if (!file_exists($proof_dir)) {
            wp_mkdir_p($proof_dir);
            // 添加 .htaccess 保护（可选，防止直接访问目录列表）
            file_put_contents($proof_dir . '/.htaccess', 'Options -Indexes');
        }
        
        // 生成唯一文件名
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = sprintf('order-%d-%s.%s', $order_id, uniqid(), $ext);
        $filepath = $proof_dir . '/' . $filename;
        $file_url = $proof_url_base . '/' . $filename;
        
        // 移动上传的文件
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return new WP_Error('upload_failed', '文件保存失败', ['status' => 500]);
        }
        
        // 设置文件权限
        chmod($filepath, 0644);

        // 保存文件路径和URL到订单元数据（使用 wp_normalize_path 规范化路径）
        update_post_meta($order_id, '_myshop_payment_proof_path', wp_normalize_path($filepath));
        update_post_meta($order_id, '_myshop_payment_proof_url', $file_url);
        update_post_meta($order_id, '_myshop_payment_proof_submitted_at', current_time('mysql'));
        
        // 更新订单状态为"处理中"（凭证已提交，等待确认）
        $order->update_status('processing', __('用户上传付款凭证，待人工审核。', 'myshop-core'));
        
        $order->save();

        return rest_ensure_response([
            'order_id'             => $order_id,
            'payment_proof_status' => 'submitted',
            'preview_url'          => $file_url,
            'message'              => '付款凭证已提交，请勿重复支付'
        ]);
    }
    
    /**
     * 计算积分抵扣金额
     */
    private static function calculate_points_discount($user_id, $points_to_use, $order) {
        global $wpdb;
        
        // 获取积分设置
        $settings = self::get_points_settings();
        
        // 检查是否启用积分抵扣
        if (!$settings['enable_points_discount']) {
            return new WP_Error('points_discount_disabled', '积分抵扣功能未启用', ['status' => 400]);
        }
        
        // 获取用户当前积分
        $table = $wpdb->prefix . 'myshop_point_ledger';
        $available_points = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        )));
        
        // 检查积分是否足够
        if ($available_points < $points_to_use) {
            return new WP_Error('insufficient_points', '积分不足', ['status' => 400]);
        }
        
        // 检查最低使用积分
        if ($points_to_use < $settings['min_points_to_use']) {
            return new WP_Error('points_too_low', sprintf('最少需要使用 %d 积分', $settings['min_points_to_use']), ['status' => 400]);
        }
        
        // 计算订单金额
        $order->calculate_totals();
        $order_total = $order->get_total();
        
        // 检查订单最低金额
        if ($settings['min_order_amount_to_use'] > 0 && $order_total < $settings['min_order_amount_to_use']) {
            return new WP_Error('order_amount_too_low', sprintf('订单金额需满 ¥%.2f 才能使用积分', $settings['min_order_amount_to_use']), ['status' => 400]);
        }
        
        // 计算可抵扣金额
        $discount_amount = $points_to_use / $settings['redeem_rate'];
        
        // 检查最大抵扣比例
        $max_discount = $order_total * ($settings['max_discount_percent'] / 100);
        if ($discount_amount > $max_discount) {
            $discount_amount = $max_discount;
            $points_to_use = floor($max_discount * $settings['redeem_rate']);
        }
        
        // 不能超过订单金额
        if ($discount_amount > $order_total) {
            $discount_amount = $order_total;
            $points_to_use = floor($order_total * $settings['redeem_rate']);
        }
        
        return [
            'discount_amount' => $discount_amount,
            'points_used' => $points_to_use
        ];
    }
    
    /**
     * 扣除用户积分（订单确认后调用）
     */
    public static function deduct_points_for_order($order_id) {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $points_used = $order->get_meta('_points_used', true);
        if (!$points_used || $points_used <= 0) {
            return;
        }
        
        // 检查是否已经扣除过
        $table = $wpdb->prefix . 'myshop_point_ledger';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND reference_order_id = %d AND channel = 'order_discount'",
            $order->get_customer_id(),
            $order_id
        ));
        
        if ($existing > 0) {
            return; // 已经扣除过了
        }
        
        $user_id = $order->get_customer_id();
        
        // 计算当前余额
        $current_balance = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        )));
        
        $balance_after = $current_balance - $points_used;
        
        // 插入扣除记录
        $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'type' => 'spend',
                'delta' => -$points_used,
                'balance_after' => $balance_after,
                'status' => 'confirmed',
                'channel' => 'order_discount',
                'reference_order_id' => $order_id,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s']
        );
        
        // 添加订单备注
        $discount_amount = $order->get_meta('_points_discount_amount', true);
        $order->add_order_note(sprintf('已使用 %d 积分抵扣 ¥%.2f', $points_used, $discount_amount));
    }
    
    /**
     * 获取积分系统设置
     */
    private static function get_points_settings() {
        $defaults = [
            'enable_points' => 1,
            'earn_rate' => 10,
            'min_order_amount' => 0,
            'register_bonus' => 100,
            'daily_signin_points' => 10,
            'enable_points_discount' => 1,
            'redeem_rate' => 100,
            'min_points_to_use' => 100,
            'max_discount_percent' => 50,
            'min_order_amount_to_use' => 0,
            'enable_expiry' => 0,
            'expiry_days' => 365
        ];
        
        $settings = get_option('myshop_points_settings', []);
        return wp_parse_args($settings, $defaults);
    }
}