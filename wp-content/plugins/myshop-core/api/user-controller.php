<?php
class User_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/user/profile', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_profile'],
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

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'user_id' => $user->ID,
                'username' => $user->user_login,
                'nickname' => $user->display_name,
                'avatar' => get_avatar_url($user->ID),
                'openid' => $openid,
                'referral_code' => $referral_code
            ]
        ]);
    }
}