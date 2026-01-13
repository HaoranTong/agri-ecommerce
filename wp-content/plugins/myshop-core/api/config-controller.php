<?php
/**
 * File: config-controller.php
 * Path: wp-content/plugins/myshop-core/api/config-controller.php
 *
 * 功能说明：
 * - 提供公开配置接口：GET /wp-json/myshop/v1/config/public
 * - 返回支付二维码、客服二维码、首页轮播图、营销区块等公开配置
 *
 * 使用说明：
 * - 前端通过 /wp-json/myshop/v1/config/public 获取公开配置
 * - 本文件会在返回前将“本站资源URL”（如 /wp-content/uploads/...）统一重写为
 *   “当前请求域名 + https”，以保证开发/预发/生产环境同一套代码可用。
 *
 * 修改说明（2026-01-13）：
 * - 新增：根据当前 REST 请求的 Host / X-Forwarded-Proto 生成 origin
 * - 新增：将 home_slider.img、payment_qr_url、customer_service_qr 中属于本站的资源URL
 *        统一重写为当前域名下的 https 绝对URL，避免出现 agri-ecommerce.test 这类本地域名
 *        导致小程序真机无法加载图片的问题。
 */

class Config_Controller {
    const OPTION_KEY = 'myshop_public_config';

    public static function register_routes() {
        register_rest_route('myshop/v1', '/config/public', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_public_config'],
            'permission_callback' => '__return_true'
        ]);
    }

    /**
     * 获取公开配置（并在返回前重写本站资源URL到当前访问域名）
     */
    public static function get_public_config($request) {
        $config = get_option(self::OPTION_KEY, []);

        $defaults = [
            'payment_qr_url'      => '',
            'customer_service_qr' => '',
            'home_slider'         => [],
            'marketing_blocks'    => [],
            'last_updated_at'     => current_time('c')
        ];

        $payload = wp_parse_args($config, $defaults);

        // 强制转换结构，避免 null / 非数组导致前端崩溃
        $payload['home_slider'] = array_values(array_filter((array) $payload['home_slider'], function ($slide) {
            return is_array($slide) && !empty($slide['img']);
        }));

        $payload['marketing_blocks'] = array_values(array_filter((array) $payload['marketing_blocks'], function ($block) {
            return is_array($block) && !empty($block['code']);
        }));

        if (empty($payload['last_updated_at'])) {
            $payload['last_updated_at'] = current_time('c');
        }

        // =========================
        // [核心修复] 重写本站资源URL
        // =========================
        $payload['payment_qr_url'] = self::normalize_public_asset_url($payload['payment_qr_url'], $request);
        $payload['customer_service_qr'] = self::normalize_public_asset_url($payload['customer_service_qr'], $request);

        $payload['home_slider'] = array_map(function ($slide) use ($request) {
            if (is_array($slide) && !empty($slide['img'])) {
                $slide['img'] = self::normalize_public_asset_url($slide['img'], $request);
            }
            return $slide;
        }, $payload['home_slider']);

        return rest_ensure_response($payload);
    }

    /**
     * 生成当前请求的 origin（协议 + 域名）
     * - 优先使用 X-Forwarded-Proto 判断外部协议（Cloudflare Tunnel/反代场景）
     * - Host 优先从 REST 请求 header 获取，其次从 $_SERVER 获取
     */
    private static function get_request_origin($request) {
        $proto = 'http';

        // 如果 wp-config.php 已做过 HTTPS 识别修复（你们前面加的那段），这里 is_ssl() 通常会为 true
        if (is_ssl()) {
            $proto = 'https';
        } else {
            $xfp = $request->get_header('x-forwarded-proto');
            if (!empty($xfp)) {
                // 可能是 "https" 或 "https,http" 这种格式
                $proto = trim(explode(',', $xfp)[0]);
            }
        }

        $host = $request->get_header('host');
        if (empty($host) && isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        }

        // 兜底：极端情况下 host 仍为空，避免拼出 "https://"
        if (empty($host)) {
            return $proto . '://' . 'localhost';
        }

        return $proto . '://' . $host;
    }

    /**
     * 将“本站资源URL”统一改写为当前请求域名下的绝对URL：
     * - 如果是绝对URL且 path 是 /wp-content/... 或包含 /uploads/，则改写成 当前origin + path(+query)
     * - 如果是相对路径（以 / 开头），则补全为 当前origin + path
     * - 如果是第三方域名（如 gravatar），保持不变
     */
    private static function normalize_public_asset_url($url, $request) {
        if (empty($url) || !is_string($url)) {
            return $url;
        }

        $url = trim($url);
        $origin = self::get_request_origin($request);

        // 处理 //example.com/... 这种协议相对写法
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        // 相对路径：/wp-content/... -> 补全当前域名
        if (strpos($url, '/') === 0) {
            return $origin . $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            // 解析失败就原样返回，避免误伤
            return $url;
        }

        $path = isset($parts['path']) ? $parts['path'] : '';
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';
        $suffix = $path . $query . $fragment;

        $is_absolute = !empty($parts['scheme']) && !empty($parts['host']);

        // 只对“看起来是本站静态资源”的路径做重写，避免把第三方URL改坏
        $is_wp_asset =
            (strpos($path, '/wp-content/') === 0) ||
            (strpos($path, '/wp-includes/') === 0) ||
            (strpos($path, '/uploads/') !== false);

        if ($is_absolute && $is_wp_asset) {
            return $origin . $suffix;
        }

        // 非本站资源（例如 gravatar / 第三方CDN），保持原样
        return $url;
    }
}
