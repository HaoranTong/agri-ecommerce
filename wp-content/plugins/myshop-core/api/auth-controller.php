<?php
class Auth_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/auth/login', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'login'],
            'permission_callback' => '__return_true'
        ]);

        // ✅ 新增：绑定微信手机号接口
        register_rest_route('myshop/v1', '/auth/phone', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'bind_phone'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'code' => ['required' => true, 'type' => 'string'],
                'phone_code' => ['required' => false, 'type' => 'string'],
                'encrypted_data' => ['required' => false, 'type' => 'string'],
                'iv' => ['required' => false, 'type' => 'string']
            ]
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

        $existing_user_id = MyShop_Auth::find_user_id_by_openid($openid);
        $is_new_user = !$existing_user_id;

        $user_id = $existing_user_id ?: MyShop_Auth::get_or_create_user_by_openid($openid);
        if (!$user_id) return new WP_Error('user_creation_failed', '用户创建失败', ['status' => 500]);

        if (is_array($login_result)) {
            if (!empty($login_result['session_key'])) {
                update_user_meta($user_id, '_wechat_session_key', $login_result['session_key']);
            }
            if (!empty($login_result['unionid'])) {
                update_user_meta($user_id, '_wechat_unionid', $login_result['unionid']);
            }
        }
        if ($is_new_user && !get_user_meta($user_id, '_myshop_first_login_at', true)) {
            update_user_meta($user_id, '_myshop_first_login_at', current_time('mysql'));
        }

        // 保存微信昵称和头像（如果前端提供）
        $json_params = $request->get_json_params();
        MyShop_Auth::log_debug('login profile payload', [
            'has_nickname' => !empty($json_params['nickname']),
            'has_avatar' => !empty($json_params['avatar'])
        ]);
        if (!empty($json_params['nickname'])) {
            update_user_meta($user_id, '_wechat_nickname', sanitize_text_field($json_params['nickname']));
            // 同时更新 WordPress 显示名称
            wp_update_user([
                'ID' => $user_id,
                'display_name' => sanitize_text_field($json_params['nickname'])
            ]);
        }
        if (!empty($json_params['avatar'])) {
            update_user_meta($user_id, '_wechat_avatar', esc_url_raw($json_params['avatar']));
        }

        $referrer_code = isset($json_params['referrer_code']) ? sanitize_text_field($json_params['referrer_code']) : null;
        $inviter_id = isset($json_params['inviter_id']) ? absint($json_params['inviter_id']) : 0;
        $channel_param = !empty($json_params['channel']) ? sanitize_text_field($json_params['channel']) : null;
        $scene_param = !empty($json_params['scene']) ? sanitize_text_field($json_params['scene']) : null;
        $landing_page = !empty($json_params['landing_page']) ? sanitize_text_field($json_params['landing_page']) : null;
        $channel_code = null;
        if ($channel_param) {
            $channel_code = $channel_param;
        } elseif ($scene_param) {
            $channel_code = $scene_param;
        }
        if (!$referrer_code && $scene_param && self::is_referral_scene_fallback_enabled()) {
            $referrer_code = self::parse_referrer_from_scene($scene_param);
        }

        if ($is_new_user && ($inviter_id || $referrer_code)) {
            if (!$inviter_id && $referrer_code) {
                $matched = get_users([
                    'meta_key' => 'myshop_referral_code',
                    'meta_value' => $referrer_code,
                    'number' => 1,
                    'fields' => 'ID'
                ]);
                $inviter_id = $matched ? (int) $matched[0] : 0;
            }
            if ($inviter_id > 0 && class_exists('Referral_Controller')) {
                // 仅新用户首次登录可绑定推荐关系，避免存量用户反向/重复绑定
                Referral_Controller::bind_referral($user_id, $inviter_id, $channel_code);
            }
        }

        self::store_attribution_meta($user_id, $channel_param, $scene_param, $landing_page, $referrer_code);

        $wechat_nickname = get_user_meta($user_id, '_wechat_nickname', true);
        $wechat_avatar = get_user_meta($user_id, '_wechat_avatar', true);
        $phone = get_user_meta($user_id, '_wechat_phone', true);
        if (!$phone) {
            $phone = get_user_meta($user_id, 'billing_phone', true);
        }

        $user = get_userdata($user_id);
        $display_name = $user ? $user->display_name : '';
        $has_profile = !empty($wechat_nickname) || !empty($wechat_avatar) || ($display_name && $display_name !== '微信用户');
        $has_realname = $user ? !empty($user->first_name) : false;
        $has_phone = !empty($phone);

        $token = MyShop_Auth::generate_token($user_id, $openid);
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'user_id' => $user_id,
                'token' => $token,
                'openid' => $openid,
                'is_new_user' => $is_new_user,
                'is_new' => $is_new_user,
                'has_profile' => $has_profile,
                'has_realname' => $has_realname,
                'has_phone' => $has_phone,
                'wechat_nickname' => $wechat_nickname ?: null,
                'wechat_avatar' => $wechat_avatar ?: null,
                'phone' => $phone ?: null
            ]
        ]);
    }

    private static function store_attribution_meta($user_id, $channel, $scene, $landing_page, $referrer_code) {
        if (!$user_id) {
            return;
        }

        if (!$channel && !$scene && !$landing_page && !$referrer_code) {
            return;
        }

        if ($channel) {
            update_user_meta($user_id, '_myshop_attr_channel', $channel);
        }
        if ($scene) {
            update_user_meta($user_id, '_myshop_attr_scene', $scene);
        }
        if ($landing_page) {
            update_user_meta($user_id, '_myshop_attr_landing_page', $landing_page);
        }
        if ($referrer_code) {
            update_user_meta($user_id, '_myshop_attr_referrer_code', $referrer_code);
        }

        update_user_meta($user_id, '_myshop_attr_recorded_at', current_time('mysql'));
    }

    private static function is_referral_scene_fallback_enabled() {
        return defined('MYSHOP_REFERRAL_SCENE_FALLBACK') && MYSHOP_REFERRAL_SCENE_FALLBACK;
    }

    private static function parse_referrer_from_scene($scene) {
        $scene = trim((string) $scene);
        if ($scene === '') {
            return null;
        }
        if (strpos($scene, 'gc_') === 0) {
            $rest = substr($scene, 3);
            $rc_index = strpos($rest, '_rc_');
            if ($rc_index !== false) {
                return substr($rest, $rc_index + 4);
            }
        }
        if (strpos($scene, 'rc_') === 0) {
            return substr($scene, 3);
        }
        if (preg_match('/^U\\d+[A-Za-z0-9]{4}$/', $scene)) {
            return $scene;
        }
        return null;
    }

    /**
     * 绑定微信手机号
     * 支持新版 phone_code 和旧版 encryptedData+iv 两种方式
     */
    public static function bind_phone($request) {
        try {
            $user = MyShop_Auth::get_user_from_request($request);
            if (is_wp_error($user)) {
                return $user;
            }

            $params = $request->get_json_params();
            $code = $params['code'] ?? '';
            $phone_code = $params['phone_code'] ?? '';
            $encrypted_data = $params['encrypted_data'] ?? '';
            $iv = $params['iv'] ?? '';

            error_log('=== BIND_PHONE REQUEST ===');
            error_log('User ID: ' . $user->ID);
            error_log('Has code: ' . (!empty($code) ? 'YES' : 'NO'));
            error_log('Has phone_code: ' . (!empty($phone_code) ? 'YES' : 'NO'));
            error_log('Has encrypted_data: ' . (!empty($encrypted_data) ? 'YES' : 'NO'));
            error_log('Has iv: ' . (!empty($iv) ? 'YES' : 'NO'));

            if (!$code && !$phone_code && !($encrypted_data && $iv)) {
                return new WP_Error('missing_code', '缺少登录码', ['status' => 400]);
            }

            $phone = null;

            // 优先使用新版 phone_code（微信小程序基础库 2.21.2+）
            if ($phone_code) {
                error_log('Using NEW phone_code API, code: ' . substr($phone_code, 0, 15) . '...');
                $phone = self::get_phone_from_code($phone_code);
            }
            // 兼容旧版 encryptedData + iv
            elseif ($encrypted_data && $iv) {
                $session_key = get_user_meta($user->ID, '_wechat_session_key', true);
                error_log('Using LEGACY decrypt, has_session_key: ' . (!empty($session_key) ? 'YES' : 'NO'));
                if ($session_key) {
                    $phone = self::decrypt_phone_data($encrypted_data, $iv, $session_key);
                }
            } else {
                error_log('ERROR: No phone_code, encrypted_data or iv provided!');
            }

            if (is_wp_error($phone)) {
                error_log('Phone result is WP_Error: ' . $phone->get_error_message());
                return $phone;
            }

            if (!$phone) {
                error_log('Phone is NULL - decryption failed');
                return new WP_Error('phone_decrypt_failed', '手机号解密失败', ['status' => 400]);
            }

            // 保存手机号到用户元数据
            update_user_meta($user->ID, '_wechat_phone', $phone);
            update_user_meta($user->ID, 'billing_phone', $phone);

            error_log('BIND_PHONE SUCCESS: ' . $phone);

            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'phone' => $phone
                ]
            ]);
        } catch (Throwable $e) {
            error_log('[MyShop Core] bind_phone exception: ' . $e->getMessage());
            return new WP_Error('bind_phone_exception', '手机号绑定失败，请稍后再试', ['status' => 500]);
        }
    }

    /**
     * 新版：通过 phone_code 获取手机号
     */
    private static function get_phone_from_code($phone_code) {
        $app_id = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : '';
        $app_secret = defined('MYSHOP_MINIAPP_APP_SECRET') ? MYSHOP_MINIAPP_APP_SECRET : '';

        error_log('get_phone_from_code: APP_ID=' . $app_id . ', has_secret=' . (!empty($app_secret) ? 'YES' : 'NO'));

        if (!$app_id || !$app_secret) {
            error_log('ERROR: Missing MYSHOP_MINIAPP_APP_ID or MYSHOP_MINIAPP_APP_SECRET');
            return new WP_Error('missing_config', '小程序配置缺失', ['status' => 500]);
        }

        $access_token = self::get_access_token();
        if (is_wp_error($access_token)) {
            error_log('get_phone_from_code: access_token error ' . $access_token->get_error_message());
            return $access_token;
        }
        if (is_array($access_token) && !empty($access_token['token'])) {
            $access_token = $access_token['token'];
        }
        if (!$access_token || !is_string($access_token)) {
            error_log('get_phone_from_code: access_token is empty or invalid');
            return new WP_Error('wechat_access_token_empty', '微信 access_token 为空', ['status' => 500]);
        }
        error_log('get_phone_from_code: access_token=' . substr($access_token, 0, 20) . '...');

        $url = sprintf(
            'https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token=%s',
            $access_token
        );

        $body_data = ['code' => $phone_code];
        error_log('Calling WeChat API with body: ' . json_encode($body_data));

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body_data)
        ]);

        if (is_wp_error($response)) {
            error_log('wp_remote_post ERROR: ' . $response->get_error_message());
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log('WeChat API response: ' . json_encode($body));
        
        if (isset($body['errcode']) && $body['errcode'] !== 0) {
            error_log('WeChat API error: ' . $body['errcode'] . ' - ' . ($body['errmsg'] ?? 'Unknown'));
            return new WP_Error(
                'wechat_api_error',
                $body['errmsg'] ?? '微信接口调用失败',
                ['status' => 400]
            );
        }

        $phone = $body['phone_info']['purePhoneNumber'] ?? $body['phone_info']['phoneNumber'] ?? null;
        error_log('Extracted phone: ' . ($phone ?? 'NULL'));
        
        return $phone;
    }

    /**
     * 旧版：解密 encryptedData
     */
    private static function decrypt_phone_data($encrypted_data, $iv, $session_key) {
        $app_id = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : '';
        
        $session_key = base64_decode($session_key);
        $encrypted_data = base64_decode($encrypted_data);
        $iv = base64_decode($iv);

        $decrypted = openssl_decrypt(
            $encrypted_data,
            'AES-128-CBC',
            $session_key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            return new WP_Error('decrypt_failed', '数据解密失败', ['status' => 400]);
        }

        $data = json_decode($decrypted, true);
        
        if (!isset($data['purePhoneNumber'])) {
            return new WP_Error('invalid_data', '解密数据格式错误', ['status' => 400]);
        }

        // 验证 appId
        if (isset($data['watermark']['appid']) && $data['watermark']['appid'] !== $app_id) {
            return new WP_Error('appid_mismatch', 'AppID 不匹配', ['status' => 400]);
        }

        return $data['purePhoneNumber'];
    }

    /**
     * 获取微信 access_token
     */
    private static function get_access_token() {
        $cache_key = 'myshop_wechat_access_token_phone';
        $cached = get_transient($cache_key);
        
        if ($cached) {
            if (is_array($cached) && !empty($cached['token'])) {
                return $cached['token'];
            }
            if (is_string($cached)) {
                return $cached;
            }
        }

        $app_id = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : '';
        $app_secret = defined('MYSHOP_MINIAPP_APP_SECRET') ? MYSHOP_MINIAPP_APP_SECRET : '';

        $url = sprintf(
            'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=%s&secret=%s',
            $app_id,
            $app_secret
        );

        $response = wp_remote_get($url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            // 缓存 7000 秒（微信 token 有效期 7200 秒）
            set_transient($cache_key, $body['access_token'], 7000);
            return $body['access_token'];
        }

        return new WP_Error('wechat_token_failed', '获取微信 access_token 失败', ['response' => $body]);
    }
}
