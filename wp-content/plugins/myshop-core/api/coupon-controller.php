<?php

class Coupon_Controller {

    public static function register_routes() {
        register_rest_route('myshop/v1', '/coupons/validate', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'validate_coupon'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/orders/(?P<order_id>\d+)/apply-coupon', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'apply_coupon_to_order'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'order_id' => ['required' => true, 'type' => 'integer']
            ]
        ]);
    }

    /**
     * 验证优惠券
     */
    public static function validate_coupon($request) {
        $params = $request->get_json_params();
        $code = isset($params['code']) ? sanitize_text_field($params['code']) : '';
        
        if (empty($code)) {
            return new WP_Error('missing_code', '优惠券代码不能为空', ['status' => 400]);
        }

        // 使用 WooCommerce 原生优惠券类
        $coupon = new \WC_Coupon($code);

        // 检查优惠券是否存在
        if (!$coupon->get_id()) {
            return new WP_Error('coupon_not_found', '优惠券不存在', ['status' => 404]);
        }

        // 验证优惠券是否有效（检查过期、使用次数等）
        $is_valid = $coupon->is_valid();
        
        if (!$is_valid) {
            $errors = wc_get_notices('error');
            $error_message = '优惠券无效';
            
            if (!empty($errors)) {
                $error_message = $errors[0]['notice'] ?? $error_message;
                wc_clear_notices(); // 清除通知，避免影响后续请求
            }
            
            return new WP_Error('coupon_invalid', $error_message, ['status' => 400]);
        }

        // 获取优惠券信息
        $discount_type = $coupon->get_discount_type();
        $amount = floatval($coupon->get_amount());
        $description = $coupon->get_description();
        $minimum_amount = $coupon->get_minimum_amount();
        $maximum_amount = $coupon->get_maximum_amount();

        // 计算折扣金额（需要订单金额，这里先返回基本信息）
        $discount_info = [
            'code' => $code,
            'discount_type' => $discount_type,
            'amount' => $amount,
            'description' => $description,
            'minimum_amount' => $minimum_amount > 0 ? (string) $minimum_amount : null,
            'maximum_amount' => $maximum_amount > 0 ? (string) $maximum_amount : null
        ];

        return rest_ensure_response([
            'success' => true,
            'data' => $discount_info
        ]);
    }

    /**
     * 应用优惠券到订单
     */
    public static function apply_coupon_to_order($request) {
        $order_id = absint($request->get_param('order_id'));
        $params = $request->get_json_params();
        $coupon_code = isset($params['coupon_code']) ? sanitize_text_field($params['coupon_code']) : '';

        if (empty($coupon_code)) {
            return new WP_Error('missing_coupon_code', '优惠券代码不能为空', ['status' => 400]);
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

        // 检查订单状态（只能对pending和on-hold状态的订单应用优惠券）
        $order_status = $order->get_status();
        if (!in_array($order_status, ['pending', 'on-hold', 'processing'], true)) {
            return new WP_Error('invalid_order_status', '当前订单状态不允许使用优惠券', ['status' => 400]);
        }

        // 验证优惠券
        $coupon = new \WC_Coupon($coupon_code);
        if (!$coupon->get_id()) {
            return new WP_Error('coupon_not_found', '优惠券不存在', ['status' => 404]);
        }

        // 验证优惠券是否有效
        $is_valid = $coupon->is_valid();
        if (!$is_valid) {
            $errors = wc_get_notices('error');
            $error_message = '优惠券无效';
            if (!empty($errors)) {
                $error_message = $errors[0]['notice'] ?? $error_message;
                wc_clear_notices();
            }
            return new WP_Error('coupon_invalid', $error_message, ['status' => 400]);
        }

        // 检查订单金额是否满足优惠券要求
        $order_total = $order->get_total();
        $minimum_amount = $coupon->get_minimum_amount();
        if ($minimum_amount > 0 && $order_total < $minimum_amount) {
            return new WP_Error('order_amount_too_low', sprintf('订单金额需满 ¥%.2f 才能使用此优惠券', $minimum_amount), ['status' => 400]);
        }

        // 检查是否已使用过优惠券
        $existing_coupons = $order->get_coupon_codes();
        if (in_array($coupon_code, $existing_coupons, true)) {
            return new WP_Error('coupon_already_applied', '该优惠券已使用', ['status' => 400]);
        }

        // 应用优惠券到订单
        try {
            // 清除之前的计算
            $order->remove_order_items('coupon');
            
            // 添加优惠券
            $order->apply_coupon($coupon_code);
            
            // 重新计算订单总额
            $order->calculate_totals();
            
            // 保存订单
            $order->save();

            // 保存优惠券信息到订单meta（用于记录）
            $order->update_meta_data('_applied_coupon_code', $coupon_code);
            $order->update_meta_data('_applied_coupon_at', current_time('mysql'));
            $order->save_meta_data();

            // 获取折扣金额
            $discount_total = $order->get_total_discount();
            $final_total = $order->get_total();

            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'order_id' => $order_id,
                    'coupon_code' => $coupon_code,
                    'discount_amount' => number_format((float) $discount_total, 2, '.', ''),
                    'original_total' => number_format((float) ($final_total + $discount_total), 2, '.', ''),
                    'final_total' => number_format((float) $final_total, 2, '.', ''),
                    'coupon_description' => $coupon->get_description()
                ]
            ]);

        } catch (Exception $e) {
            return new WP_Error('apply_coupon_failed', '应用优惠券失败：' . $e->getMessage(), ['status' => 500]);
        }
    }
}
