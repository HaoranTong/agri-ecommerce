<?php

class MyShop_Wechat {
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
            return $token;
        }

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
        if (!is_array($data) || !isset($data['errcode'])) {
            return new WP_Error('wechat_shipping_failed', '微信发货接口返回异常', ['response' => $body]);
        }

        if ((int) $data['errcode'] !== 0) {
            return new WP_Error('wechat_shipping_failed', $data['errmsg'] ?? '微信发货接口失败', ['response' => $data]);
        }

        return $data;
    }
}
