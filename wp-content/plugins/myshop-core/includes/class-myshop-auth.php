<?php

class MyShop_Auth {
    const SECRET_KEY = 'myshop_jwt_secret_key_123456';

    public static function generate_token($user_id, $openid) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $user_id,
            'openid' => $openid,
            'exp' => time() + 3600
        ]);

        $b64_head = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $b64_payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        $signature = hash_hmac('sha256', "$b64_head.$b64_payload", self::SECRET_KEY, true);
        $b64_sig = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return "$b64_head.$b64_payload.$b64_sig";
    }

   
    public static function mock_wechat_openid($code) {
        return $code === 'test' ? 'oMockUser1234567890ab' : false;
    }

    public static function get_or_create_user_by_openid($openid) {
        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_wechat_openid' AND meta_value = %s",
            $openid
        ));
        if ($user_id) return $user_id;

        $username = 'agri_user_' . uniqid();
        $email = 'user_' . time() . '@example.com';
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        if (is_wp_error($user_id)) return false;
        update_user_meta($user_id, '_wechat_openid', $openid);
        return $user_id;
    }
public static function check_permission($request) {
    $auth_header = $request->get_header('Authorization');
    if (!$auth_header || !preg_match('/^Bearer\s+(.+)$/', $auth_header, $matches)) {
        return new WP_Error('unauthorized', '无效的令牌', array('status' => 401));
    }
    $token = $matches[1];
    $user = self::validate_token($token);
    if (!$user) {
        return new WP_Error('unauthorized', '无效的令牌', array('status' => 401));
    }
    return true;
}

public static function validate_token($token) {
    if (!is_string($token) || empty($token)) {
        return false;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    [$h, $p, $s] = $parts;
    $h = str_pad(strtr($h, '-_', '+/'), strlen($h) % 4, '=', STR_PAD_RIGHT);
    $p = str_pad(strtr($p, '-_', '+/'), strlen($p) % 4, '=', STR_PAD_RIGHT);
    $s = str_pad(strtr($s, '-_', '+/'), strlen($s) % 4, '=', STR_PAD_RIGHT);
    $payload = json_decode(base64_decode($p), true);
    if (!$payload || !isset($payload['user_id']) || !isset($payload['exp']) || $payload['exp'] < time()) {
        return false;
    }
    $expected_sig = hash_hmac('sha256', "$h.$p", self::SECRET_KEY, true);
    $provided_sig = base64_decode($s);
    if ($provided_sig === false || !hash_equals($expected_sig, $provided_sig)) {
        return false;
    }
    return get_userdata($payload['user_id']);
}
}