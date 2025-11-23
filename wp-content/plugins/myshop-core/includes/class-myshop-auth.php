<?php

class MyShop_Auth {
    const SECRET_KEY = 'myshop_jwt_secret_key_123456';
    const TOKEN_TTL = 604800; // 7 days

    public static function generate_token($user_id, $openid) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $user_id,
            'openid' => $openid,
            'exp' => time() + self::TOKEN_TTL
        ]);

        $b64_head = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $b64_payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        $signature = hash_hmac('sha256', "$b64_head.$b64_payload", self::SECRET_KEY, true);
        $b64_sig = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return "$b64_head.$b64_payload.$b64_sig";
    }

   
    public static function mock_wechat_openid($code) {
        if (!is_string($code) || $code === '') {
            return false;
        }

        if ($code === 'test') {
            return 'oMockUser1234567890ab';
        }

        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '', $code);
        if ($normalized === '') {
            return false;
        }

        $hash = substr(hash('sha256', $normalized), 0, 18);
        return 'oMockUser' . $hash;
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
        $user = self::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }
        return true;
    }

    public static function get_user_from_request($request) {
        if ($request instanceof \WP_REST_Request) {
            $token = self::extract_token_from_request($request);
        } else {
            $token = null;
        }

        if (!$token) {
            return new WP_Error('unauthorized', '无效的令牌', ['status' => 401]);
        }

        $user = self::validate_token($token);
        if (!$user) {
            return new WP_Error('unauthorized', '无效的令牌', ['status' => 401]);
        }

        return $user;
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
        return get_userdata((int) $payload['user_id']);
    }

    public static function extract_token_from_request($request) {
        $auth_header = $request->get_header('Authorization');
        if (!$auth_header) {
            $auth_header = $request->get_header('authorization');
        }
        if (!$auth_header || !preg_match('/^Bearer\s+(.+)$/', $auth_header, $matches)) {
            return null;
        }
        return $matches[1];
    }
}