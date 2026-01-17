<?php

class Promo_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/promo/poster', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_poster'],
            'permission_callback' => '__return_true',
            'args' => [
                'template_code' => ['required' => false, 'type' => 'string'],
                'scene'         => ['required' => false, 'type' => 'string']
            ]
        ]);
    }

    public static function get_poster($request) {
        $template_code = $request->get_param('template_code');
        $scene = $request->get_param('scene');
        // 兼容前端参数命名
        $template_id = $request->get_param('template_id');
        $type = $request->get_param('type');
        $referrer_code = $request->get_param('referrer_code');

        if (!$template_code && $template_id) {
            $template_code = $template_id;
        }
        if (!$scene && $type) {
            $scene = $type;
        }

        $posters = get_option('myshop_promo_posters', []);
        if (!is_array($posters)) {
            $posters = [];
        }

        $matched = null;

        if ($template_code) {
            foreach ($posters as $poster) {
                if (!is_array($poster)) {
                    continue;
                }
                if (!empty($poster['template_code']) && $poster['template_code'] === $template_code) {
                    $matched = $poster;
                    break;
                }
            }
        } elseif ($scene) {
            foreach ($posters as $poster) {
                if (!is_array($poster)) {
                    continue;
                }
                if (!empty($poster['scene']) && $poster['scene'] === $scene) {
                    $matched = $poster;
                    break;
                }
            }
        } elseif (!empty($posters)) {
            $matched = $posters[0];
        }

        if (!$matched) {
            return new WP_Error('poster_not_found', '未找到可用的海报模板', ['status' => 404]);
        }

        $payload = [
            'template_code' => $matched['template_code'] ?? '',
            'title'         => $matched['title'] ?? '',
            'image_url'     => $matched['image_url'] ?? '',
            'mini_program_qr' => $matched['mini_program_qr'] ?? '',
            'share_text'    => $matched['share_text'] ?? '',
            'scene'         => $matched['scene'] ?? ($scene ?: ''),
            'valid_until'   => $matched['valid_until'] ?? null
        ];

        // 兼容前端字段
        $payload['poster_url'] = $payload['image_url'];
        $payload['mini_program_path'] = $matched['mini_program_path'] ?? $payload['mini_program_qr'];

        $tracking_params = [];
        if (!empty($matched['tracking_params']) && is_array($matched['tracking_params'])) {
            $tracking_params = $matched['tracking_params'];
        }

        $user = MyShop_Auth::get_user_from_request($request);
        if (!is_wp_error($user)) {
            if (class_exists('Referral_Controller') && method_exists('Referral_Controller', 'ensure_referral_code')) {
                $tracking_params['invite_code'] = Referral_Controller::ensure_referral_code($user->ID);
            }
        }

        if ($referrer_code) {
            $tracking_params['referrer_code'] = $referrer_code;
        }

        if (!empty($tracking_params)) {
            $payload['tracking_params'] = $tracking_params;
        }

        return rest_ensure_response($payload);
    }
}
