<?php

class Promo_Controller {
    private static function normalize_mini_program_path($path) {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        $path = ltrim($path, '/');
        if (stripos($path, 'src/') === 0) {
            $path = substr($path, 4);
        }
        return $path;
    }
    private static function url_to_path($url) {
        if (!$url || !is_string($url)) {
            return '';
        }
        $upload_dir = wp_upload_dir();
        $baseurl = $upload_dir['baseurl'] ?? '';
        $basedir = $upload_dir['basedir'] ?? '';
        if (!$baseurl || !$basedir) {
            return '';
        }
        $baseurl_http = set_url_scheme($baseurl, 'http');
        $baseurl_https = set_url_scheme($baseurl, 'https');
        if (strpos($url, $baseurl_http) === 0) {
            return $basedir . substr($url, strlen($baseurl_http));
        }
        if (strpos($url, $baseurl_https) === 0) {
            return $basedir . substr($url, strlen($baseurl_https));
        }
        return '';
    }
    private static function normalize_asset_url($url, $request) {
        if (!$url || !is_string($url)) {
            return $url;
        }
        $parsed = wp_parse_url($url);
        if (empty($parsed['host'])) {
            return $url;
        }
        $host = '';
        if ($request instanceof WP_REST_Request) {
            $host = $request->get_header('host');
        }
        if (!$host) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
        }
        if (!$host) {
            return $url;
        }
        $scheme = 'http';
        if (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ) {
            $scheme = 'https';
        }
        $rebuilt = $scheme . '://' . $host;
        if (!empty($parsed['path'])) {
            $rebuilt .= $parsed['path'];
        }
        if (!empty($parsed['query'])) {
            $rebuilt .= '?' . $parsed['query'];
        }
        if (!empty($parsed['fragment'])) {
            $rebuilt .= '#' . $parsed['fragment'];
        }
        return $rebuilt;
    }
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
        $now_ts = current_time('timestamp');

        if ($template_code) {
            foreach ($posters as $poster) {
                if (!is_array($poster)) {
                    continue;
                }
                if (!self::is_poster_valid($poster, $now_ts)) {
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
                if (!self::is_poster_valid($poster, $now_ts)) {
                    continue;
                }
                if (!empty($poster['scene']) && $poster['scene'] === $scene) {
                    $matched = $poster;
                    break;
                }
            }
        } elseif (!empty($posters)) {
            foreach ($posters as $poster) {
                if (!is_array($poster)) {
                    continue;
                }
                if (!self::is_poster_valid($poster, $now_ts)) {
                    continue;
                }
                $matched = $poster;
                break;
            }
        }

        if (!$matched) {
            return new WP_Error('poster_not_found', '未找到可用的海报模板（可能已过期或未配置）', ['status' => 404]);
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
        $raw_path = $matched['mini_program_path'] ?? $matched['landing_page'] ?? 'pages/index/index';
        $payload['mini_program_path'] = self::normalize_mini_program_path($raw_path);
        // Referral entry should start at login to bind on first login; normal entry stays on landing page.
        if ($scene === 'invite' || !empty($referrer_code)) {
            $payload['mini_program_path'] = 'pages/auth/login';
        }

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

        $personal_code = $referrer_code ?: ($tracking_params['invite_code'] ?? '');
        $landing_page = $payload['mini_program_path'] ?: 'pages/index/index';
        if ($personal_code && class_exists('MyShop_Wechat')) {
            $cache_key = 'myshop_poster_qr_' . md5($payload['template_code'] . '|' . $landing_page);
            if (!is_wp_error($user)) {
                $qr_cache = MyShop_Wechat::get_or_create_user_qr($user->ID, $personal_code, $landing_page, $cache_key, 'myshop/poster-qr');
                if (!is_wp_error($qr_cache)) {
                    $payload['mini_program_qr'] = $qr_cache['url'];
                    $payload['personal_qr'] = $qr_cache['url'];
                }
            } else {
                $binary = MyShop_Wechat::get_mini_program_code($personal_code, $landing_page);
                if (!is_wp_error($binary)) {
                    $saved = MyShop_Wechat::save_qr_image($binary, 'poster-qr-' . substr(md5($personal_code . $landing_page), 0, 12), 'myshop/poster-qr');
                    if (!is_wp_error($saved)) {
                        $payload['mini_program_qr'] = $saved['url'];
                        $payload['personal_qr'] = $saved['url'];
                    }
                }
            }

            if (!empty($payload['poster_url']) && !empty($payload['personal_qr'])) {
                if (!is_wp_error($user)) {
                    $poster_cache_key = 'myshop_poster_cache_' . md5($payload['template_code'] . '|' . $landing_page);
                    $poster_cache = get_user_meta($user->ID, $poster_cache_key, true);
                    if (is_array($poster_cache)) {
                        $cached_url = $poster_cache['url'] ?? '';
                        $cached_image = $poster_cache['image_url'] ?? '';
                        if ($cached_url && $cached_image === $payload['poster_url']) {
                            $cached_path = self::url_to_path($cached_url);
                            if ($cached_path && file_exists($cached_path)) {
                                $payload['personal_poster_url'] = $cached_url;
                            }
                        }
                    }
                }

                if (!empty($payload['personal_poster_url'])) {
                    return rest_ensure_response($payload);
                }

                $compose_options = [];
                if (isset($matched['qr_size'])) $compose_options['qr_size'] = (int) $matched['qr_size'];
                if (isset($matched['qr_x'])) $compose_options['qr_x'] = (int) $matched['qr_x'];
                if (isset($matched['qr_y'])) $compose_options['qr_y'] = (int) $matched['qr_y'];
                if (isset($matched['qr_padding'])) $compose_options['padding'] = (int) $matched['qr_padding'];

                $composed = MyShop_Wechat::compose_poster($payload['poster_url'], $payload['personal_qr'], $compose_options);
                if (!is_wp_error($composed)) {
                    $saved = MyShop_Wechat::save_qr_image(
                        $composed,
                        'poster-' . substr(md5($payload['template_code'] . $personal_code), 0, 12),
                        'myshop/personal-posters'
                    );
                    if (!is_wp_error($saved)) {
                        $payload['personal_poster_url'] = $saved['url'];
                        if (!is_wp_error($user)) {
                            $poster_cache_key = 'myshop_poster_cache_' . md5($payload['template_code'] . '|' . $landing_page);
                            update_user_meta($user->ID, $poster_cache_key, [
                                'url' => $saved['url'],
                                'image_url' => $payload['poster_url'],
                                'updated_at' => current_time('mysql')
                            ]);
                        }
                    }
                }
            }
        }

        $normalized_image = self::normalize_asset_url($payload['image_url'] ?? '', $request);
        if ($normalized_image !== ($payload['image_url'] ?? '')) {
            error_log('[Promo] image_url normalized to ' . $normalized_image);
        }
        $payload['image_url'] = $normalized_image;
        $normalized_poster = self::normalize_asset_url($payload['poster_url'] ?? '', $request);
        if ($normalized_poster !== ($payload['poster_url'] ?? '')) {
            error_log('[Promo] poster_url normalized to ' . $normalized_poster);
        }
        $payload['poster_url'] = $normalized_poster;
        if (!empty($payload['mini_program_qr'])) {
            $payload['mini_program_qr'] = self::normalize_asset_url($payload['mini_program_qr'], $request);
        }
        if (!empty($payload['personal_qr'])) {
            $payload['personal_qr'] = self::normalize_asset_url($payload['personal_qr'], $request);
        }
        if (!empty($payload['personal_poster_url'])) {
            $payload['personal_poster_url'] = self::normalize_asset_url($payload['personal_poster_url'], $request);
        }

        return rest_ensure_response($payload);
    }

    private static function is_poster_valid($poster, $now_ts) {
        if (!is_array($poster)) {
            return false;
        }
        $valid_until = $poster['valid_until'] ?? null;
        if (!$valid_until) {
            return true;
        }
        $valid_ts = strtotime($valid_until);
        if (!$valid_ts) {
            return true;
        }
        return $valid_ts >= $now_ts;
    }
}
