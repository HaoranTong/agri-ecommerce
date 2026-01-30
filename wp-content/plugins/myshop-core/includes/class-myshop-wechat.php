<?php

class MyShop_Wechat {
    private static function sanitize_payload($payload) {
        if (is_array($payload)) {
            $clean = [];
            foreach ($payload as $key => $value) {
                $clean[$key] = self::sanitize_payload($value);
            }
            return $clean;
        }

        if (is_string($payload)) {
            $checked = wp_check_invalid_utf8($payload, true);
            if ($checked === false) {
                if (function_exists('mb_convert_encoding')) {
                    return mb_convert_encoding($payload, 'UTF-8', 'UTF-8,GBK,GB2312,BIG5');
                }
                if (function_exists('iconv')) {
                    $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $payload);
                    if ($converted !== false) {
                        return $converted;
                    }
                }
                return '';
            }
            return $checked;
        }

        return $payload;
    }
    private static function log_shipping($stage, $context = null) {
        if (!defined('WP_CONTENT_DIR')) {
            return;
        }

        $log_file = rtrim(WP_CONTENT_DIR, '/\\') . '/myshop-payment.log';
        $timestamp = current_time('timestamp');
        $line = sprintf(
            "[%s] [wechat_shipping:%s] %s\n",
            wp_date('Y-m-d H:i:s', $timestamp),
            $stage,
            $context ? wp_json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );
        $written = @file_put_contents($log_file, $line, FILE_APPEND);
        if ($written === false) {
            error_log('[MyShop Core] ' . trim($line));
        }
    }

    public static function get_access_token($force_refresh = false) {
        $app_id = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : (defined('MYSHOP_WECHAT_APP_ID') ? MYSHOP_WECHAT_APP_ID : '');
        $secret = defined('MYSHOP_MINIAPP_APP_SECRET') ? MYSHOP_MINIAPP_APP_SECRET : '';

        if ($app_id === '' || $secret === '') {
            return new WP_Error('wechat_not_configured', '微信小程序未配置 AppID 或 AppSecret');
        }

        $cache_key = 'myshop_wechat_access_token_shipping';
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && !empty($cached['token'])) {
                return $cached['token'];
            }
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        // 优先使用 stable_token，避免 invalid credential
        $response = wp_remote_post('https://api.weixin.qq.com/cgi-bin/stable_token', [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'grant_type' => 'client_credential',
                'appid' => $app_id,
                'secret' => $secret,
                'force_refresh' => $force_refresh ? true : false
            ])
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return new WP_Error('wechat_token_failed', '获取 access_token 失败', ['response' => $data]);
        }

        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 7200;
        set_transient($cache_key, [
            'token' => $data['access_token']
        ], max(60, $expires_in - 300));

        return $data['access_token'];
    }

    public static function upload_shipping_info($payload) {
        $token = self::get_access_token();
        if (is_wp_error($token)) {
            self::log_shipping('token_error', ['error' => $token->get_error_message()]);
            return $token;
        }

        $payload = self::sanitize_payload($payload);
        self::log_shipping('request', $payload);

        $url = add_query_arg([
            'access_token' => $token
        ], 'https://api.weixin.qq.com/wxa/sec/order/upload_shipping_info');

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        self::log_shipping('response', $data ?: $body);
        if (!is_array($data) || !isset($data['errcode'])) {
            return new WP_Error('wechat_shipping_failed', '微信发货接口返回异常', ['response' => $body]);
        }

        if ((int) $data['errcode'] !== 0) {
            if ((int) $data['errcode'] === 40001) {
                // access_token 失效，强制刷新后重试一次
                $retry_token = self::get_access_token(true);
                if (!is_wp_error($retry_token)) {
                    $retry_url = add_query_arg([
                        'access_token' => $retry_token
                    ], 'https://api.weixin.qq.com/wxa/sec/order/upload_shipping_info');

                    $retry_response = wp_remote_post($retry_url, [
                        'timeout' => 15,
                        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                        'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ]);

                    if (!is_wp_error($retry_response)) {
                        $retry_body = wp_remote_retrieve_body($retry_response);
                        $retry_data = json_decode($retry_body, true);
                        self::log_shipping('response_retry', $retry_data ?: $retry_body);
                        if (is_array($retry_data) && isset($retry_data['errcode']) && (int) $retry_data['errcode'] === 0) {
                            return $retry_data;
                        }
                    }
                }
            }
            self::log_shipping('error', $data);
            return new WP_Error('wechat_shipping_failed', $data['errmsg'] ?? '微信发货接口失败', ['response' => $data]);
        }

        return $data;
    }

    public static function send_custom_message($openid, $content) {
        $openid = sanitize_text_field($openid);
        $content = trim((string) $content);
        if ($openid === '' || $content === '') {
            return new WP_Error('wechat_message_invalid', '消息内容或 OpenID 为空');
        }

        $token = self::get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $url = add_query_arg([
            'access_token' => $token
        ], 'https://api.weixin.qq.com/cgi-bin/message/custom/send');

        $payload = [
            'touser' => $openid,
            'msgtype' => 'text',
            'text' => [
                'content' => $content
            ]
        ];

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['errcode'])) {
            return new WP_Error('wechat_message_failed', '微信消息接口返回异常', ['response' => $body]);
        }

        if ((int) $data['errcode'] !== 0) {
            return new WP_Error('wechat_message_failed', $data['errmsg'] ?? '发送失败', ['response' => $data]);
        }

        return $data;
    }
}
