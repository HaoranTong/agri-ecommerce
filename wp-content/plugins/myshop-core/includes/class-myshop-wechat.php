<?php

class MyShop_Wechat {
    private static function log_shipping($stage, $context = null) {
        if (!defined('WP_CONTENT_DIR')) {
            return;
        }

        $log_file = rtrim(WP_CONTENT_DIR, '/\\') . '/myshop-payment.log';
        $line = sprintf(
            "[%s] [wechat_shipping:%s] %s\n",
            date('Y-m-d H:i:s'),
            $stage,
            $context ? wp_json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents($log_file, $line, FILE_APPEND);
    }

    public static function get_access_token() {
        $app_id = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : (defined('MYSHOP_WECHAT_APP_ID') ? MYSHOP_WECHAT_APP_ID : '');
        $secret = defined('MYSHOP_MINIAPP_APP_SECRET') ? MYSHOP_MINIAPP_APP_SECRET : '';

        if ($app_id === '' || $secret === '') {
            return new WP_Error('wechat_not_configured', '微信小程序未配置 AppID 或 AppSecret');
        }

        $cache_key = 'myshop_wechat_access_token';
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['token'])) {
            return $cached['token'];
        }

        $url = add_query_arg([
            'grant_type' => 'client_credential',
            'appid' => $app_id,
            'secret' => $secret
        ], 'https://api.weixin.qq.com/cgi-bin/token');

        $response = wp_remote_get($url, ['timeout' => 15]);
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

        self::log_shipping('request', $payload);

        $url = add_query_arg([
            'access_token' => $token
        ], 'https://api.weixin.qq.com/wxa/sec/order/upload_shipping_info');

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload)
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
            self::log_shipping('error', $data);
            return new WP_Error('wechat_shipping_failed', $data['errmsg'] ?? '微信发货接口失败', ['response' => $data]);
        }

        return $data;
    }
}
