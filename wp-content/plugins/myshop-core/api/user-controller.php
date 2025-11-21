<?php
class User_Controller {
    public static function get_profile($request) {
        $headers = $request->get_headers();
        $auth = $headers['authorization'][0] ?? '';
        if (strpos($auth, 'Bearer ') !== 0) {
            return new WP_Error('missing_token', '缺少授权令牌', ['status' => 401]);
        }

        $user = MyShop_Auth::validate_token(substr($auth, 7));
        if (!$user) return new WP_Error('invalid_token', '令牌无效', ['status' => 401]);

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