<?php
class Auth_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/auth/login', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'login'],
            'permission_callback' => '__return_true'
        ]);
    }

    public static function login($request) {
        $code = $request->get_param('code');
        if (!$code) return new WP_Error('missing_code', '缺少登录码', ['status' => 400]);

        $login_result = MyShop_Auth::get_wechat_login_result($code);
        if (is_wp_error($login_result)) {
            MyShop_Auth::log_debug('login failed', [
                'error' => $login_result->get_error_message()
            ]);
            return $login_result;
        }

        $openid = is_array($login_result) ? ($login_result['openid'] ?? '') : $login_result;
        if (!$openid) return new WP_Error('invalid_code', '无效的登录码', ['status' => 401]);

        $user_id = MyShop_Auth::get_or_create_user_by_openid($openid);
        if (!$user_id) return new WP_Error('user_creation_failed', '用户创建失败', ['status' => 500]);

        if (is_array($login_result)) {
            if (!empty($login_result['session_key'])) {
                update_user_meta($user_id, '_wechat_session_key', $login_result['session_key']);
            }
            if (!empty($login_result['unionid'])) {
                update_user_meta($user_id, '_wechat_unionid', $login_result['unionid']);
            }
        }

        $token = MyShop_Auth::generate_token($user_id, $openid);
        return rest_ensure_response([
            'success' => true,
            'data' => compact('user_id', 'token', 'openid')
        ]);
    }
}