<?php

class Config_Controller {
    const OPTION_KEY = 'myshop_public_config';

    public static function register_routes() {
        register_rest_route('myshop/v1', '/config/public', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_public_config'],
            'permission_callback' => '__return_true'
        ]);
    }

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

        return rest_ensure_response($payload);
    }
}
