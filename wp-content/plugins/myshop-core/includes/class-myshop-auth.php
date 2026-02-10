<?php

class MyShop_Auth {
    private static function log_auth($message, $context = null) {
        if (!defined('MYSHOP_AUTH_DEBUG') || !MYSHOP_AUTH_DEBUG) {
            return;
        }
        try {
            $dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? ABSPATH . 'wp-content' : __DIR__);
            $log_path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'myshop-auth.log';
            $ts = gmdate('Y-m-d H:i:s');
            $payload = '';
            if (!is_null($context)) {
                $payload = ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            @file_put_contents($log_path, '[' . $ts . '] ' . $message . $payload . PHP_EOL, FILE_APPEND);
        } catch (Exception $e) {
            // ignore logging failures
        }
    }
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

        // 🔥 支持预定义的测试用户（用于开发测试）
        $test_users = self::get_test_users();
        if (isset($test_users[$code])) {
            return $test_users[$code]['openid'];
        }

        // 兼容旧的 test 代码
        if ($code === 'test') {
            return 'oMockUser1234567890ab';
        }

        // 其他code生成随机openid
        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '', $code);
        if ($normalized === '') {
            return false;
        }

        $hash = substr(hash('sha256', $normalized), 0, 18);
        return 'oMockUser' . $hash;
    }
    
    public static function get_wechat_login_result($code) {
        if (!is_string($code) || $code === '') {
            return new WP_Error('missing_code', '缺少登录码', ['status' => 400]);
        }

        $allow_test = defined('MYSHOP_ALLOW_TEST_LOGIN') && MYSHOP_ALLOW_TEST_LOGIN;
        if ($allow_test) {
            $test_users = self::get_test_users();
            if (isset($test_users[$code])) {
                return [
                    'openid' => $test_users[$code]['openid'],
                    'session_key' => null,
                    'unionid' => null
                ];
            }
            if ($code === 'test') {
                return [
                    'openid' => 'oMockUser1234567890ab',
                    'session_key' => null,
                    'unionid' => null
                ];
            }
        }

        $app_id = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : (defined('MYSHOP_WECHAT_APP_ID') ? MYSHOP_WECHAT_APP_ID : '');
        $secret = defined('MYSHOP_MINIAPP_APP_SECRET') ? MYSHOP_MINIAPP_APP_SECRET : '';

        if ($app_id === '' || $secret === '') {
            if ($allow_test) {
                $openid = self::mock_wechat_openid($code);
                if (!$openid) {
                    return new WP_Error('invalid_code', '无效的登录码', ['status' => 401]);
                }
                return [
                    'openid' => $openid,
                    'session_key' => null,
                    'unionid' => null
                ];
            }

            self::log_debug('wechat login not configured', [
                'app_id' => $app_id ? 'set' : 'missing',
                'secret' => $secret ? 'set' : 'missing'
            ]);
            return new WP_Error('wechat_not_configured', '微信登录未配置', ['status' => 500]);
        }

        $url = add_query_arg([
            'appid' => $app_id,
            'secret' => $secret,
            'js_code' => $code,
            'grant_type' => 'authorization_code'
        ], 'https://api.weixin.qq.com/sns/jscode2session');

        $response = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            self::log_debug('wechat login request failed', [
                'message' => $response->get_error_message()
            ]);
            return new WP_Error('wechat_login_failed', '微信登录失败', ['status' => 502]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            self::log_debug('wechat login invalid response', [
                'body' => substr((string) $body, 0, 200)
            ]);
            return new WP_Error('wechat_login_failed', '微信登录失败', ['status' => 502]);
        }

        if (!empty($data['errcode'])) {
            $message = $data['errmsg'] ?? '微信登录失败';
            self::log_debug('wechat login error', [
                'errcode' => $data['errcode'],
                'errmsg' => $message
            ]);
            return new WP_Error('wechat_login_failed', $message, ['status' => 401]);
        }

        if (empty($data['openid'])) {
            return new WP_Error('wechat_login_failed', '微信登录失败', ['status' => 401]);
        }

        return [
            'openid' => $data['openid'],
            'session_key' => $data['session_key'] ?? null,
            'unionid' => $data['unionid'] ?? null
        ];
    }

    public static function log_debug($message, $context = null) {
        if (defined('MYSHOP_AUTH_DEBUG') && !MYSHOP_AUTH_DEBUG) {
            return;
        }

        $prefix = '[' . date('c') . '] MyShop Auth: ';
        $line = $prefix . $message;
        if ($context !== null) {
            $line .= ' ' . wp_json_encode($context);
        }
        $line .= PHP_EOL;

        $log_file = defined('WP_CONTENT_DIR')
            ? WP_CONTENT_DIR . '/myshop-auth.log'
            : __DIR__ . '/myshop-auth.log';

        @file_put_contents($log_file, $line, FILE_APPEND);
    }
    
    /**
     * 获取测试用户列表
     */
    public static function get_test_users() {
        $default_users = [
            'test001' => [
                'openid' => 'oTest_User_001_FixedOpenID',
                'nickname' => '测试用户001',
                'phone' => '13800138001',
                'description' => '主测试账号 - 用于日常功能测试'
            ],
            'test002' => [
                'openid' => 'oTest_User_002_FixedOpenID',
                'nickname' => '测试用户002',
                'phone' => '13800138002',
                'description' => '副测试账号 - 用于多用户场景测试'
            ],
            'test003' => [
                'openid' => 'oTest_User_003_FixedOpenID',
                'nickname' => '测试用户003',
                'phone' => '13800138003',
                'description' => '测试账号003 - 用于代理商功能测试'
            ],
            'admin' => [
                'openid' => 'oTest_Admin_999_FixedOpenID',
                'nickname' => '管理员测试账号',
                'phone' => '13900139000',
                'description' => '管理员账号 - 用于权限测试'
            ]
        ];
        
        // 允许通过配置文件或数据库自定义测试用户
        $custom_users = get_option('myshop_test_users', []);
        
        return array_merge($default_users, $custom_users);
    }
    
    /**
     * 添加自定义测试用户
     */
    public static function add_test_user($code, $openid, $nickname = '', $phone = '', $description = '') {
        $custom_users = get_option('myshop_test_users', []);
        
        $custom_users[$code] = [
            'openid' => $openid,
            'nickname' => $nickname ?: "测试用户_{$code}",
            'phone' => $phone,
            'description' => $description
        ];
        
        update_option('myshop_test_users', $custom_users);
        
        return true;
    }
    
    /**
     * 删除自定义测试用户
     */
    public static function delete_test_user($code) {
        $custom_users = get_option('myshop_test_users', []);
        
        if (isset($custom_users[$code])) {
            unset($custom_users[$code]);
            update_option('myshop_test_users', $custom_users);
            return true;
        }
        
        return false;
    }

    public static function get_or_create_user_by_openid($openid) {
        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_wechat_openid' AND meta_value = %s",
            $openid
        ));
        if ($user_id) {
            $user = get_userdata((int) $user_id);
            if ($user instanceof WP_User) {
                return (int) $user_id;
            }
            // Cleanup orphaned openid mapping and treat as new user.
            delete_user_meta((int) $user_id, '_wechat_openid');
        }

        // 检查是否为测试用户
        $test_users = self::get_test_users();
        $is_test_user = false;
        $test_code = '';
        $test_data = null;
        
        foreach ($test_users as $code => $user) {
            if ($user['openid'] === $openid) {
                $is_test_user = true;
                $test_code = $code;
                $test_data = $user;
                break;
            }
        }
        
        // 根据是否为测试用户设置不同的用户名格式
        if ($is_test_user) {
            $username = 'test_' . $test_code;  // 例如: test_test001, test_admin
            $email = $test_code . '@test.myshop.local';
            $display_name = $test_data['nickname'];
        } else {
            $username = 'wx_user_' . substr(md5($openid), 0, 8);  // 真实用户用 wx_ 前缀
            $email = 'wx_' . substr(md5($openid), 0, 12) . '@wechat.myshop.local';
            $display_name = '微信用户';
        }
        
        $user_id = wp_create_user($username, wp_generate_password(16, true, true), $email);
        if (is_wp_error($user_id)) return false;
        
        // 保存 openid
        update_user_meta($user_id, '_wechat_openid', $openid);
        
        // 更新用户信息
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $display_name,
            'nickname' => $display_name,
            'first_name' => $display_name,
        ]);
        
        // 如果是测试用户，保存额外信息
        if ($is_test_user) {
            if (!empty($test_data['phone'])) {
                update_user_meta($user_id, 'billing_phone', $test_data['phone']);
                update_user_meta($user_id, 'phone', $test_data['phone']);
            }
            update_user_meta($user_id, '_test_user_code', $test_code);
            update_user_meta($user_id, '_is_test_user', '1');
        }
        
        return $user_id;
    }

    public static function find_user_id_by_openid($openid) {
        if (!is_string($openid) || $openid === '') {
            return null;
        }
        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_wechat_openid' AND meta_value = %s",
            $openid
        ));
        if ($user_id) {
            $user = get_userdata((int) $user_id);
            if ($user instanceof WP_User) {
                return (int) $user_id;
            }
            delete_user_meta((int) $user_id, '_wechat_openid');
        }
        return null;
    }
    public static function check_permission($request) {
        $user = self::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }
        return true;
    }

    public static function has_operator_permission($user) {
        if (!$user instanceof WP_User) {
            return false;
        }

        return user_can($user, 'manage_woocommerce') || user_can($user, 'manage_options');
    }

    public static function get_user_from_request($request) {
        if ($request instanceof \WP_REST_Request) {
            $token = self::extract_token_from_request($request);
        } else {
            $token = null;
        }

        if (!$token) {
            $route = $request instanceof \WP_REST_Request ? $request->get_route() : null;
            $method = $request instanceof \WP_REST_Request ? $request->get_method() : null;
            self::log_auth('unauthorized: missing token', [
                'route' => $route,
                'method' => $method
            ]);
            return new WP_Error('unauthorized', '无效的令牌', ['status' => 401]);
        }

        $user = self::validate_token($token);
        if (!$user) {
            $route = $request instanceof \WP_REST_Request ? $request->get_route() : null;
            $method = $request instanceof \WP_REST_Request ? $request->get_method() : null;
            self::log_auth('unauthorized: invalid token', [
                'route' => $route,
                'method' => $method
            ]);
            return new WP_Error('unauthorized', '无效的令牌', ['status' => 401]);
        }

        return $user;
    }

    public static function validate_token($token) {
        if (!is_string($token) || empty($token)) {
            return false;
        }
        $token = trim($token);
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        [$h, $p, $s] = $parts;
        $h = self::base64url_decode($h);
        $p = self::base64url_decode($p);
        if ($h === false || $p === false) {
            return false;
        }
        $payload = json_decode($p, true);
        if (!$payload || !isset($payload['user_id']) || !isset($payload['exp']) || $payload['exp'] < time()) {
            return false;
        }
        $signing_input = $parts[0] . '.' . $parts[1];
        $expected_raw = hash_hmac('sha256', $signing_input, self::SECRET_KEY, true);
        $expected_sig = rtrim(strtr(base64_encode($expected_raw), '+/', '-_'), '=');
        if (!hash_equals($expected_sig, $parts[2])) {
            return false;
        }
        return get_userdata((int) $payload['user_id']);
    }

    private static function base64url_decode($input) {
        if (!is_string($input) || $input === '') {
            return false;
        }
        $input = strtr($input, '-_', '+/');
        $pad = strlen($input) % 4;
        if ($pad) {
            $input .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($input);
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
