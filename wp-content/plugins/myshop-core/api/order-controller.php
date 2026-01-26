<?php

class Order_Controller {

    public static function boot() {
        add_action('updated_post_meta', [self::class, 'handle_tracking_meta_update'], 10, 4);
        add_action('added_post_meta', [self::class, 'handle_tracking_meta_update'], 10, 4);
        add_action('woocommerce_order_status_changed', [self::class, 'handle_order_status_changed'], 10, 4);
    }

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

        register_rest_route('myshop/v1', '/orders/(?P<order_id>\d+)/return-request', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'request_return'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'order_id' => ['required' => true, 'type' => 'integer'],
                'reason' => ['required' => false, 'type' => 'string']
            ]
        ]);

        // ✅ 已移除上传支付凭证接口，仅支持微信支付
        // register_rest_route('myshop/v1', '/orders/(?P<order_id>\d+)/upload-payment-proof', [
        //     'methods' => \WP_REST_Server::CREATABLE,
        //     'callback' => [self::class, 'upload_payment_proof'],
        //     'permission_callback' => ['MyShop_Auth', 'check_permission'],
        //     'args' => [
        //         'order_id' => ['required' => true, 'type' => 'integer']
        //     ]
        // ]);

        register_rest_route('myshop/v1', '/orders/(?P<order_id>\d+)/apply-gift-card', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'apply_gift_card_to_order'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'order_id' => ['required' => true, 'type' => 'integer']
            ]
        ]);
    }

    public static function handle_tracking_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }

        $watch_keys = [
            '_myshop_tracking_number',
            '_myshop_tracking_company',
            '_myshop_shipped_at',
            '_tracking_number',
            '_tracking_provider',
            '_wc_shipment_tracking_items',
            '_woo_shipment_tracking_items',
            'tracking_number',
            'tracking_company',
            'date_shipped'
        ];

        if (!in_array($meta_key, $watch_keys, true)) {
            return;
        }

        self::maybe_sync_wechat_shipping($post_id);
    }

    public static function handle_order_status_changed($order_id, $from, $to, $order) {
        if (!$order_id) {
            return;
        }

        if (!in_array($to, ['processing', 'completed'], true)) {
            return;
        }

        self::maybe_sync_wechat_shipping($order_id);
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

        $is_gift_card_order = !empty($params['is_gift_card_order']);
        $giftcard_hint = isset($params['giftcard_hint']) ? sanitize_text_field($params['giftcard_hint']) : '';
        $giftcard_mode = isset($params['giftcard_mode']) ? sanitize_key($params['giftcard_mode']) : '';
        $giftcard_template_id = isset($params['giftcard_template_id']) ? absint($params['giftcard_template_id']) : 0;
        $giftcard_payload = isset($params['giftcard_payload'])
            ? self::sanitize_giftcard_payload($params['giftcard_payload'])
            : [];
        $requires_shipping = !$is_gift_card_order;

        // ✅ 处理积分抵扣
        $points_to_use = isset($params['points_to_use']) ? absint($params['points_to_use']) : 0;
        $points_discount_result = null; // 保存积分抵扣结果，用于后续扣除积分
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
                $points_discount_result = $points_discount; // 保存结果
            }
        }

        // ✅ 处理中国标准收货地址/礼品卡虚拟地址
        $raw_shipping = isset($params['shipping_address']) ? $params['shipping_address'] : null;
        $normalized_address = null;

        if ($raw_shipping) {
            $normalized_address = self::normalize_shipping_address($raw_shipping, $requires_shipping);
            if (is_wp_error($normalized_address)) {
                return $normalized_address;
            }
        } elseif ($requires_shipping) {
            return new WP_Error('missing_address', '请提供收货地址', ['status' => 400]);
        } else {
            $fallback_virtual = self::build_virtual_shipping_payload($user);
            $normalized_address = self::normalize_shipping_address($fallback_virtual, false);
        }

        if ($normalized_address) {
            self::apply_shipping_address($order, $normalized_address);
        }

        $order->calculate_totals();

        if ($is_gift_card_order) {
            $order->update_meta_data('_myshop_is_gift_card_order', 'yes');
            if ($giftcard_hint) {
                $order->update_meta_data('_myshop_giftcard_hint', $giftcard_hint);
            }
            if ($giftcard_mode) {
                $order->update_meta_data('_myshop_giftcard_mode', $giftcard_mode);
            }
            if ($giftcard_template_id) {
                $order->update_meta_data('_myshop_giftcard_template_id', $giftcard_template_id);
            }
            if (!empty($giftcard_payload)) {
                $order->update_meta_data('_myshop_giftcard_payload', wp_json_encode($giftcard_payload));
            }
            $snapshot = self::build_order_snapshot($order);
            if (!empty($snapshot)) {
                $order->update_meta_data('_myshop_giftcard_snapshot', wp_json_encode($snapshot));
            }
        }

        // ✅ 自动保存收货地址到用户地址列表（仅实物订单）
        if ($requires_shipping && $raw_shipping && $normalized_address) {
            self::maybe_save_user_address($user_id, $normalized_address);
        }

        $order->save();
        $order_id = $order->get_id();

        // ✅ 如果使用了积分抵扣，立即扣除积分（而不是等到订单状态变为processing）
        if ($points_to_use > 0 && $points_discount_result !== null) {
            self::deduct_points_for_order($order_id);
        }

        // ✅ 已移除扫码支付二维码逻辑，前端仅使用微信支付

        return rest_ensure_response([
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'total' => $order->get_total(),
            'status' => $order->get_status(),
            'items' => [[
                'product_id' => $variation->get_parent_id(),
                'variation_id' => $variation_id,
                'quantity' => $quantity,
                'price' => $variation->get_price()
            ]]
            // ✅ 已移除 payment_qr_url, customer_service_qr, message 字段
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

            // 获取快递信息（兼容 WooCommerce 运单插件）
            $tracking_meta = self::resolve_tracking_meta($order->get_id());
            $tracking_number = $tracking_meta['tracking_number'];
            $tracking_company = $tracking_meta['tracking_company'];
            $shipped_at = $tracking_meta['shipped_at'];
            
            $is_gift_card_order = $order->get_meta('_myshop_is_gift_card_order', true) === 'yes';
            $giftcard_mode = $order->get_meta('_myshop_giftcard_mode', true);

            $data[] = [
                'order_id'         => $order->get_id(),
                'order_number'     => $order->get_order_number(),
                'status'           => $order->get_status(),
                'total'            => $order->get_total(),
                'created_at'       => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
                'items'            => $items,
                'tracking_number'  => $tracking_number,
                'tracking_company' => $tracking_company,
                'shipped_at'       => $shipped_at,
                'is_gift_card_order' => $is_gift_card_order,
                'giftcard_mode'      => $giftcard_mode ?: null
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

        // ✅ 已移除扫码支付二维码提取逻辑（payment_qr_url, customer_service_qr）

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

        // ✅ 已移除支付凭证相关逻辑（payment_proof_url, has_payment_proof）
        // ✅ 已移除扫码支付相关逻辑（payment_qr_url, customer_service_qr）
        
        // 获取物流信息（兼容 WooCommerce 运单插件）
        $tracking_meta = self::resolve_tracking_meta($order->get_id());
        $tracking_number = $tracking_meta['tracking_number'];
        $tracking_company = $tracking_meta['tracking_company'];
        $shipped_at = $tracking_meta['shipped_at'];

        // 获取优惠券使用信息
        $applied_coupon_code = $order->get_meta('_applied_coupon_code', true);
        $applied_coupon_at = $order->get_meta('_applied_coupon_at', true);
        $coupon_discount = $order->get_total_discount();
        $coupon_info = null;
        if ($applied_coupon_code) {
            $coupons = $order->get_coupon_codes();
            if (!empty($coupons) && in_array($applied_coupon_code, $coupons, true)) {
                $coupon_info = [
                    'code' => $applied_coupon_code,
                    'discount_amount' => number_format((float) $coupon_discount, 2, '.', ''),
                    'applied_at' => $applied_coupon_at
                ];
            }
        }

        // 获取购物卡使用信息
        $gift_card_used_amount = $order->get_meta('_gift_card_used_amount', true);
        $gift_card_number = $order->get_meta('_gift_card_number', true);
        $gift_card_remaining = $order->get_meta('_gift_card_remaining_balance', true);
        $gift_card_info = null;
        if ($gift_card_used_amount && $gift_card_number) {
            $gift_card_info = [
                'card_number' => $gift_card_number,
                'used_amount' => number_format((float) $gift_card_used_amount, 2, '.', ''),
                'remaining_balance' => number_format((float) ($gift_card_remaining ?? 0), 2, '.', '')
            ];
        }

        $original_total = self::calculate_order_gross_total($order);
        $payable_total = (float) $order->get_total();
        $discount_total = max(0, $original_total - $payable_total);
        $points_used = (int) $order->get_meta('_points_used', true);
        $points_discount_amount = (float) $order->get_meta('_points_discount_amount', true);

        $return_requested_at = $order->get_meta('_myshop_return_requested_at', true);
        $return_status = $return_requested_at ? 'requested' : 'none';

        // ✅ 计算积分奖励（支付页显示用）
        $points_reward = 0;
        if ($order->get_status() === 'pending' || $order->get_status() === 'on-hold') {
            // 未支付订单：按实际应付金额计算积分（1:1）
            $points_reward = (int) floor($payable_total);
        }

        return rest_ensure_response([
            'order_id'            => $order->get_id(),
            'order_number'        => $order->get_order_number(),
            'status'              => $order->get_status(),
            'total'               => $payable_total,
            'original_total'      => number_format($original_total, 2, '.', ''),
            'discount_total'      => number_format($discount_total, 2, '.', ''),
            'points_usage'        => $points_used > 0 ? [
                'points_used'      => $points_used,
                'discount_amount'  => number_format($points_discount_amount, 2, '.', '')
            ] : null,
            // ✅ 积分奖励字段（兼容多种命名）
            'points_reward'       => $points_reward,
            'points_earned'       => $points_reward,
            'reward_points'       => $points_reward,
            'earned_points'       => $points_reward,
            'created_at'          => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
            'items'               => $items,
            // ✅ 已移除 payment_qr_url 和 customer_service_qr
            'shipping_address'    => $shipping_address,
            // ✅ 已移除 payment_proof_url, payment_proof_submitted_at, has_payment_proof
            'tracking_number'     => $tracking_number,
            'tracking_company'    => $tracking_company,
            'shipped_at'          => $shipped_at,
            'return_status'       => $return_status,
            'return_requested_at' => $return_requested_at ?: null,
            'coupon_info'         => $coupon_info,
            'gift_card_info'      => $gift_card_info,
            'is_gift_card_order'  => $order->get_meta('_myshop_is_gift_card_order', true) === 'yes',
            'giftcard_mode'       => ($order->get_meta('_myshop_giftcard_mode', true) ?: null)
        ]);
    }

    public static function request_return($request) {
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
            return new WP_Error('invalid_order_id', '无效的订单ID', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', '订单不存在', ['status' => 404]);
        }

        if ($order->get_customer_id() !== $user->ID) {
            return new WP_Error('unauthorized', '无权操作此订单', ['status' => 403]);
        }

        $status = $order->get_status();
        if (!in_array($status, ['processing', 'completed'], true)) {
            return new WP_Error('invalid_order_status', '当前订单状态不允许申请退货', ['status' => 400]);
        }

        if ($order->get_meta('_myshop_return_requested_at', true)) {
            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'return_status' => 'requested',
                    'return_requested_at' => $order->get_meta('_myshop_return_requested_at', true)
                ]
            ]);
        }

        $params = $request->get_json_params();
        $reason = isset($params['reason']) ? sanitize_text_field($params['reason']) : '用户申请退货';

        $requested_at = current_time('mysql');
        $order->update_meta_data('_myshop_return_requested_at', $requested_at);
        $order->update_meta_data('_myshop_return_reason', $reason);
        $order->add_order_note('用户申请退货/售后：' . $reason);
        $order->save();

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'return_status' => 'requested',
                'return_requested_at' => $requested_at
            ]
        ]);
    }

    // ✅ 已弃用：上传支付凭证接口（仅支持微信支付，无需上传凭证）
    /*
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
    */

    private static function sanitize_giftcard_payload($payload) {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            } else {
                return [];
            }
        }

        if (!is_array($payload)) {
            return [];
        }

        $sanitized = [];
        foreach ($payload as $key => $value) {
            $clean_key = is_string($key) ? sanitize_key($key) : $key;
            if (is_array($value)) {
                $sanitized[$clean_key] = self::sanitize_giftcard_payload($value);
            } elseif (is_scalar($value)) {
                $sanitized[$clean_key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    private static function resolve_tracking_meta($order_id) {
        $tracking_number = get_post_meta($order_id, '_myshop_tracking_number', true) ?: '';
        $tracking_company = get_post_meta($order_id, '_myshop_tracking_company', true) ?: '';
        $shipped_at = get_post_meta($order_id, '_myshop_shipped_at', true) ?: '';

        if (!$tracking_number) {
            $tracking_number = get_post_meta($order_id, '_tracking_number', true) ?: '';
            if (!$tracking_number) {
                $tracking_number = get_post_meta($order_id, 'tracking_number', true) ?: '';
            }
        }

        if (!$tracking_company) {
            $tracking_company = get_post_meta($order_id, '_tracking_provider', true) ?: '';
            if (!$tracking_company) {
                $tracking_company = get_post_meta($order_id, '_tracking_company', true) ?: '';
            }
            if (!$tracking_company) {
                $tracking_company = get_post_meta($order_id, 'tracking_company', true) ?: '';
            }
        }

        if (!$shipped_at) {
            $shipped_at = get_post_meta($order_id, '_date_shipped', true) ?: '';
            if (!$shipped_at) {
                $shipped_at = get_post_meta($order_id, 'date_shipped', true) ?: '';
            }
        }

        $tracking_items = get_post_meta($order_id, '_wc_shipment_tracking_items', true);
        if (empty($tracking_items)) {
            $tracking_items = get_post_meta($order_id, '_woo_shipment_tracking_items', true);
        }
        if (is_string($tracking_items)) {
            $tracking_items = maybe_unserialize($tracking_items);
        }

        if (is_array($tracking_items) && !empty($tracking_items)) {
            $first = $tracking_items[0];
            if (!$tracking_number && !empty($first['tracking_number'])) {
                $tracking_number = $first['tracking_number'];
            }
            if (!$tracking_company) {
                if (!empty($first['tracking_provider'])) {
                    $tracking_company = $first['tracking_provider'];
                } elseif (!empty($first['custom_tracking_provider'])) {
                    $tracking_company = $first['custom_tracking_provider'];
                }
            }
            if (!$shipped_at && !empty($first['date_shipped'])) {
                $timestamp = (int) $first['date_shipped'];
                if ($timestamp > 0) {
                    $shipped_at = date('Y-m-d H:i:s', $timestamp);
                }
            }
        }

        return [
            'tracking_number' => (string) $tracking_number,
            'tracking_company' => (string) $tracking_company,
            'shipped_at' => (string) $shipped_at
        ];
    }

    private static function maybe_sync_wechat_shipping($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $tracking_meta = self::resolve_tracking_meta($order_id);
        if (empty($tracking_meta['tracking_number']) || empty($tracking_meta['tracking_company'])) {
            return;
        }

        $order_number_type = 2;
        $order_number_value = $order->get_meta('_myshop_wechat_out_trade_no', true);
        if (!$order_number_value) {
            $transaction_id = $order->get_transaction_id();
            if ($transaction_id) {
                $order_number_type = 1;
                $order_number_value = $transaction_id;
            }
        }
        if (!$order_number_value) {
            self::note_wechat_sync_error($order, '缺少商户单号/微信交易号，无法同步');
            return;
        }

        $sync_key = $tracking_meta['tracking_number'] . '|' . $tracking_meta['tracking_company'];
        $synced_key = get_post_meta($order_id, '_myshop_wechat_shipping_synced_key', true);
        if ($synced_key === $sync_key) {
            return;
        }

        $openid = '';
        $user_id = $order->get_customer_id();
        if ($user_id) {
            $openid = get_user_meta($user_id, '_wechat_openid', true) ?: '';
        }

        $express_company = self::normalize_express_company($tracking_meta['tracking_company']);
        if (!$express_company) {
            return;
        }

        $payload = [
            'order_key' => [
                'order_number_type' => $order_number_type,
                ($order_number_type === 1 ? 'transaction_id' : 'out_trade_no') => $order_number_value
            ],
            'logistics_type' => 1,
            'delivery_mode' => 1,
            'shipping_list' => [
                [
                    'tracking_no' => $tracking_meta['tracking_number'],
                    'express_company' => $express_company
                ]
            ]
        ];

        if ($openid) {
            $payload['payer'] = ['openid' => $openid];
        }

        $response = MyShop_Wechat::upload_shipping_info($payload);
        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            error_log('[MyShop Core] WeChat shipping sync failed: ' . $message);
            self::note_wechat_sync_error($order, '微信订单中心同步失败：' . $message, $sync_key);
            return;
        }

        update_post_meta($order_id, '_myshop_wechat_shipping_synced_key', $sync_key);
        update_post_meta($order_id, '_myshop_wechat_shipping_synced_at', current_time('mysql'));
        $order->add_order_note('已同步微信订单中心发货信息');
    }

    private static function note_wechat_sync_error($order, $message, $sync_key = '') {
        if (!$order) {
            return;
        }

        $last_error = $order->get_meta('_myshop_wechat_shipping_last_error', true);
        $error_key = $sync_key ? $sync_key . '|' . $message : $message;
        if ($last_error === $error_key) {
            return;
        }

        $order->add_order_note($message);
        $order->update_meta_data('_myshop_wechat_shipping_last_error', $error_key);
        $order->save();
    }

    private static function normalize_express_company($raw) {
        if (!$raw) {
            return '';
        }

        $raw = trim(wp_strip_all_tags((string) $raw));
        if ($raw === '') {
            return '';
        }

        $upper = strtoupper($raw);
        $known_codes = ['SF', 'STO', 'YTO', 'ZTO', 'YUNDA', 'JD', 'EMS'];
        if (in_array($upper, $known_codes, true)) {
            return $upper;
        }

        $map = [
            '顺丰' => 'SF',
            '申通' => 'STO',
            '圆通' => 'YTO',
            '中通' => 'ZTO',
            '韵达' => 'YUNDA',
            '京东' => 'JD',
            '邮政' => 'EMS',
            'EMS' => 'EMS'
        ];

        foreach ($map as $keyword => $code) {
            if (stripos($raw, $keyword) !== false) {
                return $code;
            }
        }

        $custom_map = get_option('myshop_wechat_express_map', []);
        if (is_array($custom_map) && !empty($custom_map)) {
            foreach ($custom_map as $keyword => $code) {
                if ($keyword && stripos($raw, (string) $keyword) !== false) {
                    return strtoupper((string) $code);
                }
            }
        }

        return $raw;
    }

    private static function normalize_shipping_address($addr, $strict = true) {
        if (!is_array($addr)) {
            return new WP_Error('invalid_address', '收货地址格式错误', ['status' => 400]);
        }

        $detail_address = '';
        if (!empty($addr['detail_address'])) {
            $detail_address = $addr['detail_address'];
        } elseif (!empty($addr['address'])) {
            $detail_address = $addr['address'];
        }

        $normalized = [
            'name' => sanitize_text_field($addr['name'] ?? ''),
            'phone' => sanitize_text_field($addr['phone'] ?? ''),
            'province' => sanitize_text_field($addr['province'] ?? ''),
            'city' => sanitize_text_field($addr['city'] ?? ''),
            'district' => sanitize_text_field($addr['district'] ?? ''),
            'detail' => sanitize_text_field($detail_address),
            'postal_code' => sanitize_text_field($addr['postcode'] ?? ($addr['postal_code'] ?? ''))
        ];

        if ($strict) {
            foreach (['name', 'phone', 'province', 'city', 'district', 'detail'] as $required_key) {
                if (empty($normalized[$required_key])) {
                    return new WP_Error('invalid_address', '收货地址信息不完整', ['status' => 400]);
                }
            }
        } else {
            if ($normalized['name'] === '') {
                $normalized['name'] = '礼品卡顾客';
            }
            if ($normalized['phone'] === '') {
                $normalized['phone'] = '13800000000';
            }
            if ($normalized['province'] === '') {
                $normalized['province'] = '礼品卡';
            }
            if ($normalized['city'] === '') {
                $normalized['city'] = '无需发货';
            }
            if ($normalized['district'] === '') {
                $normalized['district'] = '数字权益';
            }
            if ($normalized['detail'] === '') {
                $normalized['detail'] = '礼品卡订单，无需物流配送';
            }
            if ($normalized['postal_code'] === '') {
                $normalized['postal_code'] = '000000';
            }
        }

        return $normalized;
    }

    private static function build_virtual_shipping_payload($user) {
        $name = $user->first_name ?: ($user->display_name ?: $user->user_login);
        $phone = get_user_meta($user->ID, 'billing_phone', true);

        return [
            'name' => $name ?: '礼品卡顾客',
            'phone' => $phone ?: '',
            'province' => '',
            'city' => '',
            'district' => '',
            'detail_address' => ''
        ];
    }

    private static function apply_shipping_address($order, array $address) {
        $order->set_address([
            'first_name' => $address['name'],
            'last_name'  => '',
            'address_1'  => $address['detail'],
            'city'       => $address['city'],
            'state'      => $address['province'],
            'postcode'   => $address['postal_code'] ?? '',
            'country'    => 'CN'
        ], 'shipping');

        $order->set_billing_phone($address['phone']);
    }

    private static function maybe_save_user_address($user_id, array $address) {
        $user_addresses = get_user_meta($user_id, '_myshop_addresses', true);
        if (!is_array($user_addresses)) {
            $user_addresses = [];
        }

        foreach ($user_addresses as $existing) {
            if (
                $existing['name'] === $address['name'] &&
                $existing['phone'] === $address['phone'] &&
                $existing['province'] === $address['province'] &&
                $existing['city'] === $address['city'] &&
                $existing['district'] === $address['district'] &&
                $existing['detail'] === $address['detail']
            ) {
                return;
            }
        }

        $user_addresses[] = [
            'id' => uniqid('addr_'),
            'name' => $address['name'],
            'phone' => $address['phone'],
            'province' => $address['province'],
            'city' => $address['city'],
            'district' => $address['district'],
            'detail' => $address['detail'],
            'postal_code' => $address['postal_code'] ?? '',
            'is_default' => empty($user_addresses),
            'created_at' => current_time('mysql')
        ];

        update_user_meta($user_id, '_myshop_addresses', $user_addresses);
    }

    private static function build_order_snapshot($order) {
        if (!$order instanceof WC_Order) {
            return [];
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $meta_data = [];
            foreach ($item->get_meta_data() as $meta) {
                if (!isset($meta->key)) {
                    continue;
                }
                $meta_data[$meta->key] = $meta->value;
            }

            $items[] = [
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'total'        => $item->get_total(),
                'meta'         => $meta_data
            ];
        }

        $gross_total = self::calculate_order_gross_total($order);
        $payable_total = (float) $order->get_total();
        $discount_total = max(0, $gross_total - $payable_total);
        $points_used = (int) $order->get_meta('_points_used', true);
        $points_discount = (float) $order->get_meta('_points_discount_amount', true);

        return [
            'items'       => $items,
            'order_total' => $gross_total,
            'payable_total' => $payable_total,
            'discount_total' => $discount_total,
            'points_used' => $points_used,
            'points_discount_amount' => $points_discount,
            'currency'    => $order->get_currency(),
            'created_at'  => $order->get_date_created() ? $order->get_date_created()->date('c') : current_time('mysql')
        ];
    }

    private static function calculate_order_gross_total($order) {
        if (!$order instanceof WC_Order) {
            return 0.0;
        }

        $items_subtotal = (float) $order->get_subtotal();
        $items_tax = method_exists($order, 'get_subtotal_tax') ? (float) $order->get_subtotal_tax() : 0.0;

        $shipping_total = 0.0;
        foreach ($order->get_shipping_methods() as $shipping) {
            $shipping_total += (float) $shipping->get_total() + (float) $shipping->get_total_tax();
        }

        $fee_total = 0.0;
        foreach ($order->get_fees() as $fee) {
            $fee_amount = (float) $fee->get_total();
            if ($fee_amount > 0) {
                $fee_total += $fee_amount + (float) $fee->get_total_tax();
            }
        }

        $gross = $items_subtotal + $items_tax + $shipping_total + $fee_total;
        if ($gross <= 0) {
            $gross = (float) $order->get_total();
        }

        return round($gross, 2);
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

    /**
     * 使用储值购物卡支付订单
     */
    public static function apply_gift_card_to_order($request) {
        global $wpdb;

        $order_id = absint($request->get_param('order_id'));
        $params = $request->get_json_params();
        $card_number = isset($params['card_number']) ? sanitize_text_field($params['card_number']) : '';

        if (empty($card_number)) {
            return new WP_Error('missing_card_number', '购物卡号不能为空', ['status' => 400]);
        }

        // 验证用户权限
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        // 获取订单
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', '订单不存在', ['status' => 404]);
        }

        // 验证订单所有权
        if ($order->get_customer_id() !== $user->ID) {
            return new WP_Error('unauthorized', '无权操作此订单', ['status' => 403]);
        }

        // 检查订单状态（只能对pending和processing状态的订单使用购物卡）
        $order_status = $order->get_status();
        if (!in_array($order_status, ['pending', 'on-hold', 'processing'], true)) {
            return new WP_Error('invalid_order_status', '当前订单状态不允许使用购物卡', ['status' => 400]);
        }

        // 获取购物卡
        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$cards_table} WHERE card_number = %s LIMIT 1",
            $card_number
        ));

        if (!$card) {
            return new WP_Error('card_not_found', '购物卡不存在', ['status' => 404]);
        }

        // 验证购物卡状态
        if ($card->status !== 'active') {
            return new WP_Error('card_inactive', '购物卡不可用', ['status' => 400]);
        }

        // 验证购物卡持有人与订单用户一致
        if ((int) $card->purchaser_id !== (int) $user->ID && (int) $card->redeemer_id !== (int) $user->ID) {
            return new WP_Error('card_owner_mismatch', '购物卡持有人与订单用户不一致', ['status' => 403]);
        }

        // 验证购物卡类型（只允许储值购物卡）
        if ($card->template_type !== 'fixed_amount') {
            return new WP_Error('invalid_card_type', '只能使用储值购物卡支付', ['status' => 400]);
        }

        // 验证购物卡余额
        $card_balance = floatval($card->balance ?? 0);
        if ($card_balance <= 0) {
            return new WP_Error('card_balance_insufficient', '购物卡余额不足', ['status' => 400]);
        }

        // 获取订单当前应付金额
        $order_total = floatval($order->get_total());
        
        // 检查是否已使用过购物卡
        $already_used_amount = floatval($order->get_meta('_gift_card_used_amount', true) ?: 0);
        if ($already_used_amount > 0) {
            return new WP_Error('gift_card_already_applied', '订单已使用购物卡，不能重复使用', ['status' => 400]);
        }

        // 计算使用金额（不能超过订单金额和购物卡余额）
        $use_amount = min($order_total, $card_balance);
        $remaining_balance = $card_balance - $use_amount;
        $final_order_total = $order_total - $use_amount;

        // 开始事务处理
        $wpdb->query('START TRANSACTION');

        try {
            // 1. 扣减购物卡余额
            $updated = $wpdb->update(
                $cards_table,
                [
                    'balance' => $remaining_balance,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $card->id],
                ['%f', '%s'],
                ['%d']
            );

            if ($updated === false) {
                throw new Exception('更新购物卡余额失败：' . $wpdb->last_error);
            }

            // 2. 如果余额用完，更新购物卡状态为已使用
            if ($remaining_balance <= 0) {
                $wpdb->update(
                    $cards_table,
                    [
                        'status' => 'redeemed',
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $card->id],
                    ['%s', '%s'],
                    ['%d']
                );
            }

            // 3. 更新订单金额（添加购物卡抵扣费用项）
            $fee = new WC_Order_Item_Fee();
            $fee->set_name('购物卡支付');
            $fee->set_amount(-$use_amount);
            $fee->set_total(-$use_amount);
            $order->add_item($fee);

            // 保存购物卡使用信息到订单meta
            $order->update_meta_data('_gift_card_used_amount', $use_amount);
            $order->update_meta_data('_gift_card_number', $card_number);
            $order->update_meta_data('_gift_card_remaining_balance', $remaining_balance);
            $order->update_meta_data('_gift_card_applied_at', current_time('mysql'));

            // 重新计算订单总额
            $order->calculate_totals();
            
            // 如果订单金额为0，自动更新订单状态
            if ($final_order_total <= 0) {
                $order->update_status('processing', __('使用购物卡支付，订单金额已付清', 'myshop-core'));
            } else {
                $order->add_order_note(sprintf('使用购物卡支付 ¥%.2f，订单还需支付 ¥%.2f', $use_amount, $final_order_total));
            }

            $order->save();

            // 提交事务
            $wpdb->query('COMMIT');

            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'order_id' => $order_id,
                    'card_number' => $card_number,
                    'used_amount' => number_format($use_amount, 2, '.', ''),
                    'remaining_balance' => number_format($remaining_balance, 2, '.', ''),
                    'original_total' => number_format($order_total, 2, '.', ''),
                    'final_total' => number_format($final_order_total, 2, '.', '')
                ]
            ]);

        } catch (Exception $e) {
            // 回滚事务
            $wpdb->query('ROLLBACK');
            return new WP_Error('apply_gift_card_failed', '使用购物卡支付失败：' . $e->getMessage(), ['status' => 500]);
        }
    }
}