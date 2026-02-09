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
        $app_id = defined('MYSHOP_MINIAPP_APP_ID')
            ? MYSHOP_MINIAPP_APP_ID
            : (defined('MYSHOP_WECHAT_APP_ID') ? MYSHOP_WECHAT_APP_ID : get_option('myshop_wechat_appid', ''));
        $secret = defined('MYSHOP_MINIAPP_APP_SECRET')
            ? MYSHOP_MINIAPP_APP_SECRET
            : get_option('myshop_wechat_secret', '');

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

    public static function get_mini_program_code($scene, $page, $width = 430, $env_version = null) {
        $scene = sanitize_text_field($scene);
        $page = ltrim(sanitize_text_field($page), '/');
        if ($scene === '' || $page === '') {
            return new WP_Error('wechat_qr_invalid', '小程序码参数无效');
        }

        if (strlen($scene) > 32) {
            return new WP_Error('wechat_qr_scene_too_long', 'scene 参数过长');
        }

        $token = self::get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $payload = [
            'scene' => $scene,
            'page' => $page,
            'width' => max(280, min(1280, (int) $width)),
            'check_path' => false
        ];

        if ($env_version) {
            $payload['env_version'] = $env_version;
        }

        $url = add_query_arg([
            'access_token' => $token
        ], 'https://api.weixin.qq.com/wxa/getwxacodeunlimit');

        $response = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $body = wp_remote_retrieve_body($response);

        if (is_string($content_type) && strpos($content_type, 'image') !== false) {
            return $body;
        }

        $data = json_decode($body, true);
        if (is_array($data) && isset($data['errcode']) && (int) $data['errcode'] !== 0) {
            return new WP_Error('wechat_qr_failed', $data['errmsg'] ?? '生成小程序码失败', ['response' => $data]);
        }

        return new WP_Error('wechat_qr_failed', '生成小程序码失败', ['response' => $body]);
    }

    public static function save_qr_image($binary, $filename_base, $subdir = 'myshop/qr') {
        if (!$binary) {
            return new WP_Error('wechat_qr_empty', '二维码数据为空');
        }

        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return new WP_Error('wechat_qr_upload_failed', $upload['error']);
        }

        $dir = trailingslashit($upload['basedir']) . $subdir;
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('wechat_qr_dir_failed', '二维码目录创建失败');
        }

        $filename = sanitize_file_name($filename_base) . '.png';
        $filepath = trailingslashit($dir) . $filename;
        $written = file_put_contents($filepath, $binary);
        if ($written === false) {
            return new WP_Error('wechat_qr_write_failed', '二维码保存失败');
        }

        $url = trailingslashit($upload['baseurl']) . trim($subdir, '/') . '/' . $filename;
        return [
            'path' => $filepath,
            'url' => $url
        ];
    }

    public static function get_or_create_user_qr($user_id, $scene, $page, $cache_key, $subdir = 'myshop/qr', $options = []) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('wechat_qr_user_invalid', '用户无效');
        }

        $logo_url = '';
        if (is_array($options) && !empty($options['logo_url'])) {
            $logo_url = esc_url_raw($options['logo_url']);
        }

        $cache = get_user_meta($user_id, $cache_key, true);
        if (
            is_array($cache)
            && ($cache['scene'] ?? '') === $scene
            && ($cache['page'] ?? '') === $page
            && ($cache['logo_url'] ?? '') === $logo_url
            && !empty($cache['url'])
        ) {
            return $cache;
        }

        $binary = self::get_mini_program_code($scene, $page);
        if (is_wp_error($binary)) {
            return $binary;
        }

        if ($logo_url) {
            $binary = self::overlay_logo_on_qr($binary, $logo_url);
        }

        $hash_seed = $scene . '|' . $page . '|' . $user_id . '|' . $logo_url;
        $hash = substr(md5($hash_seed), 0, 12);
        $saved = self::save_qr_image($binary, "qr-{$hash}", $subdir);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $payload = [
            'scene' => $scene,
            'page' => $page,
            'url' => $saved['url'],
            'logo_url' => $logo_url,
            'updated_at' => current_time('mysql')
        ];
        update_user_meta($user_id, $cache_key, $payload);
        return $payload;
    }

    public static function overlay_logo_on_qr($qr_binary, $logo_url, $options = []) {
        if (!$qr_binary || !$logo_url) {
            return $qr_binary;
        }

        if (!extension_loaded('gd')) {
            return $qr_binary;
        }

        $logo_body = wp_remote_retrieve_body(wp_remote_get($logo_url, ['timeout' => 15]));
        if (!$logo_body) {
            return $qr_binary;
        }

        $qr_img = imagecreatefromstring($qr_binary);
        $logo_img = imagecreatefromstring($logo_body);
        if (!$qr_img || !$logo_img) {
            if ($qr_img) {
                imagedestroy($qr_img);
            }
            if ($logo_img) {
                imagedestroy($logo_img);
            }
            return $qr_binary;
        }

        $qr_w = imagesx($qr_img);
        $qr_h = imagesy($qr_img);
        $ratio = is_array($options) && isset($options['logo_ratio']) ? (float) $options['logo_ratio'] : 0.2;
        if ($ratio <= 0 || $ratio > 0.5) {
            $ratio = 0.2;
        }

        $logo_size = (int) round(min($qr_w, $qr_h) * $ratio);
        $logo_resized = imagecreatetruecolor($logo_size, $logo_size);
        imagealphablending($logo_resized, false);
        imagesavealpha($logo_resized, true);
        imagecopyresampled(
            $logo_resized,
            $logo_img,
            0,
            0,
            0,
            0,
            $logo_size,
            $logo_size,
            imagesx($logo_img),
            imagesy($logo_img)
        );

        $dst_x = (int) round(($qr_w - $logo_size) / 2);
        $dst_y = (int) round(($qr_h - $logo_size) / 2);
        imagealphablending($qr_img, true);
        imagecopy($qr_img, $logo_resized, $dst_x, $dst_y, 0, 0, $logo_size, $logo_size);

        ob_start();
        imagepng($qr_img);
        $output = ob_get_clean();

        imagedestroy($qr_img);
        imagedestroy($logo_img);
        imagedestroy($logo_resized);

        return $output ?: $qr_binary;
    }

    public static function compose_poster($background_url, $qr_url, $options = []) {
        if (!extension_loaded('gd')) {
            return new WP_Error('wechat_poster_gd_missing', 'GD 未启用');
        }

        $bg_body = wp_remote_retrieve_body(wp_remote_get($background_url, ['timeout' => 15]));
        $qr_body = wp_remote_retrieve_body(wp_remote_get($qr_url, ['timeout' => 15]));
        if (!$bg_body || !$qr_body) {
            return new WP_Error('wechat_poster_download_failed', '海报素材下载失败');
        }

        $bg_img = imagecreatefromstring($bg_body);
        $qr_img = imagecreatefromstring($qr_body);
        if (!$bg_img || !$qr_img) {
            return new WP_Error('wechat_poster_image_failed', '海报图片解析失败');
        }

        $bg_w = imagesx($bg_img);
        $bg_h = imagesy($bg_img);

        $qr_size = isset($options['qr_size']) ? (int) $options['qr_size'] : (int) round($bg_w * 0.22);
        $padding = isset($options['padding']) ? (int) $options['padding'] : (int) round($bg_w * 0.06);
        $qr_x = isset($options['qr_x']) ? (int) $options['qr_x'] : ($bg_w - $qr_size - $padding);
        $qr_y = isset($options['qr_y']) ? (int) $options['qr_y'] : ($bg_h - $qr_size - $padding);

        $qr_resized = imagecreatetruecolor($qr_size, $qr_size);
        imagealphablending($qr_resized, false);
        imagesavealpha($qr_resized, true);
        imagecopyresampled($qr_resized, $qr_img, 0, 0, 0, 0, $qr_size, $qr_size, imagesx($qr_img), imagesy($qr_img));

        imagecopy($bg_img, $qr_resized, $qr_x, $qr_y, 0, 0, $qr_size, $qr_size);

        ob_start();
        imagepng($bg_img);
        $output = ob_get_clean();

        imagedestroy($bg_img);
        imagedestroy($qr_img);
        imagedestroy($qr_resized);

        return $output;
    }
}
