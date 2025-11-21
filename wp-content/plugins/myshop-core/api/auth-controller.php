<?php
class Auth_Controller {
    public static function login($request) {
        $code = $request->get_param('code');
        if (!$code) return new WP_Error('missing_code', '缺少登录码', ['status' => 400]);

        $openid = MyShop_Auth::mock_wechat_openid($code);
        if (!$openid) return new WP_Error('invalid_code', '无效的登录码', ['status' => 401]);

        $user_id = MyShop_Auth::get_or_create_user_by_openid($openid);
        if (!$user_id) return new WP_Error('user_creation_failed', '用户创建失败', ['status' => 500]);

        $token = MyShop_Auth::generate_token($user_id, $openid);
        return rest_ensure_response([
            'success' => true,
            'data' => compact('user_id', 'token', 'openid')
        ]);
    }
}