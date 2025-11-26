<?php
class User_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/user/profile', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_profile'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
        
        // 添加 /me 路由（与 /user/profile 功能相同）
        register_rest_route('myshop/v1', '/me', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_profile'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
        
        // 更新用户资料
        register_rest_route('myshop/v1', '/user/profile', [
            'methods' => \WP_REST_Server::EDITABLE,
            'callback' => [self::class, 'update_profile'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
    }

    public static function get_profile($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $openid = get_user_meta($user->ID, '_wechat_openid', true);
        $referral_code = 'REF' . str_pad($user->ID, 6, '0', STR_PAD_LEFT);
        
        // 获取积分余额
        global $wpdb;
        $table_name = $wpdb->prefix . 'myshop_point_ledger';
        $points_balance = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) 
             FROM {$table_name}
             WHERE user_id = %d AND status = 'confirmed'",
            $user->ID
        ));

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'user_id' => $user->ID,
                'username' => $user->user_login,
                'nickname' => $user->display_name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->user_email,
                'phone' => get_user_meta($user->ID, 'billing_phone', true),
                'avatar' => get_avatar_url($user->ID),
                'openid' => $openid,
                'referral_code' => $referral_code,
                'points_balance' => intval($points_balance),
                'is_test_user' => get_user_meta($user->ID, '_is_test_user', true) === '1',
                'test_code' => get_user_meta($user->ID, '_test_user_code', true)
            ]
        ]);
    }
    
    public static function update_profile($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }
        
        $params = $request->get_json_params();
        
        // 可更新的字段
        $update_data = [];
        if (isset($params['nickname'])) {
            $update_data['display_name'] = sanitize_text_field($params['nickname']);
            $update_data['nickname'] = sanitize_text_field($params['nickname']);
        }
        if (isset($params['first_name'])) {
            $update_data['first_name'] = sanitize_text_field($params['first_name']);
        }
        if (isset($params['last_name'])) {
            $update_data['last_name'] = sanitize_text_field($params['last_name']);
        }
        
        // 更新 WP 用户
        if (!empty($update_data)) {
            $update_data['ID'] = $user->ID;
            $result = wp_update_user($update_data);
            if (is_wp_error($result)) {
                return $result;
            }
        }
        
        // 更新用户元数据
        if (isset($params['phone'])) {
            $phone = sanitize_text_field($params['phone']);
            update_user_meta($user->ID, 'billing_phone', $phone);
            update_user_meta($user->ID, 'phone', $phone);
        }
        
        // 返回更新后的资料
        return self::get_profile($request);
    }
}