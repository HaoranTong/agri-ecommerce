<?php

class Points_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/points/balance', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_balance'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/points/summary', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_summary'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/points/ledger', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_ledger'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20],
                'status'   => ['type' => 'string', 'required' => false],
                'type'     => ['type' => 'string', 'required' => false],
                'channel'  => ['type' => 'string', 'required' => false],
                'channel_prefix' => ['type' => 'string', 'required' => false],
                'from'     => ['type' => 'string', 'required' => false],
                'to'       => ['type' => 'string', 'required' => false]
            ]
        ]);

        register_rest_route('myshop/v1', '/points/spend', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'spend_points'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'points' => ['required' => true, 'type' => 'integer'],
                'reason' => ['required' => false, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/points/grant', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'grant_points'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'points'         => ['required' => true, 'type' => 'integer'],
                'reason'         => ['required' => false, 'type' => 'string'],
                'target_user_id' => ['required' => false, 'type' => 'integer'],
                'target_openid'  => ['required' => false, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/points/settings', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_settings'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('myshop/v1', '/points/rules', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_rules'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('myshop/v1', '/points/missions', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_missions'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/points/missions/(?P<mission_id>[A-Za-z0-9_-]+)/claim', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'claim_mission'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'mission_id' => ['required' => true, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/points/redeem/options', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_redeem_options'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/points/redeem', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'redeem'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'option_id' => ['required' => true, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/points/signin', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'daily_signin'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/points/exchange', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'exchange_points'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'points'        => ['required' => true, 'type' => 'integer'],
                'payout_method' => ['required' => false, 'type' => 'string'],
                'account_name'  => ['required' => false, 'type' => 'string'],
                'account_no'    => ['required' => false, 'type' => 'string'],
                'bank_name'     => ['required' => false, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/points/exchange/rules', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_exchange_rules'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
    }

    private static function get_internal_settings() {
        $defaults = [
            'enable_points' => 1,
            'earn_rate' => 10,
            'min_order_amount' => 0,
            'register_bonus' => 100,
            'daily_signin_points' => 10,
            'enable_points_discount' => 1,
            'redeem_rate' => 100,
            'min_points_to_use' => 100,
            'max_discount_percent' => 50,
            'min_order_amount_to_use' => 0,
            'enable_expiry' => 0,
            'expiry_days' => 365,
            'enable_referral_points' => 0,
            'referral_points_rate_level1' => 0,
            'referral_points_rate_level2' => 0,
            'enable_points_exchange' => 0,
            'exchange_rate' => 100,
            'exchange_min_points' => 100,
            'exchange_min_amount' => 0,
            'exchange_max_amount' => 0,
            'exchange_max_amount_per_day' => 0,
            'exchange_max_requests_per_day' => 0,
            'exchange_fee_rate' => 0
        ];

        $settings = get_option('myshop_points_settings', []);
        return wp_parse_args($settings, $defaults);
    }

    private static function build_expire_at($settings) {
        if (!empty($settings['enable_expiry']) && (int) $settings['expiry_days'] > 0) {
            return date('Y-m-d H:i:s', strtotime('+' . (int) $settings['expiry_days'] . ' days'));
        }
        return null;
    }

    public static function get_balance($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_point_ledger';
        $user_id = (int) $user->ID;

        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        ));

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'pending'",
            $user_id
        ));

        $earned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND type = 'earn'",
            $user_id
        ));

        $spent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(ABS(delta)), 0) FROM {$table} WHERE user_id = %d AND type = 'spend'",
            $user_id
        ));

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'available'      => max($available, 0),
                'pending'        => max($pending, 0),
                'total_earned'   => max($earned, 0),
                'total_spent'    => max($spent, 0)
            ]
        ]);
    }

    public static function get_summary($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_point_ledger';
        $user_id = (int) $user->ID;

        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        ));

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'pending'",
            $user_id
        ));

        $earned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND type = 'earn'",
            $user_id
        ));

        $spent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(ABS(delta)), 0) FROM {$table} WHERE user_id = %d AND type = 'spend'",
            $user_id
        ));

        $now = current_time('mysql');
        $soon = date('Y-m-d H:i:s', strtotime('+30 days', current_time('timestamp')));
        $expiring = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed' AND delta > 0 AND expire_at IS NOT NULL AND expire_at <= %s AND expire_at >= %s",
            $user_id,
            $soon,
            $now
        ));

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'available'      => max($available, 0),
                'pending'        => max($pending, 0),
                'total_earned'   => max($earned, 0),
                'total_spent'    => max($spent, 0),
                'expiring_soon'  => max($expiring, 0),
                'expiring_window_days' => 30
            ]
        ]);
    }

    public static function get_ledger($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_point_ledger';
        $user_id = (int) $user->ID;

        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = absint($request->get_param('per_page') ?: 20);
        if ($per_page > 100) {
            $per_page = 100;
        } elseif ($per_page < 1) {
            $per_page = 20;
        }

        $status_filter = $request->get_param('status');
        $where = ['user_id = %d'];
        $params = [$user_id];

        if ($status_filter) {
            $status = sanitize_text_field($status_filter);
            $where[] = 'status = %s';
            $params[] = $status;
        }

        $type_filter = $request->get_param('type');
        if ($type_filter) {
            $type = sanitize_key($type_filter);
            if ($type) {
                $where[] = 'type = %s';
                $params[] = $type;
            }
        }

        $channel_filter = $request->get_param('channel');
        if ($channel_filter) {
            $channel = sanitize_text_field($channel_filter);
            if (strpos($channel, ',') !== false) {
                $channels = array_filter(array_map('trim', explode(',', $channel)));
                if (!empty($channels)) {
                    $placeholders = implode(',', array_fill(0, count($channels), '%s'));
                    $where[] = "channel IN ({$placeholders})";
                    $params = array_merge($params, $channels);
                }
            } else {
                $where[] = 'channel = %s';
                $params[] = $channel;
            }
        }

        $channel_prefix = $request->get_param('channel_prefix');
        if ($channel_prefix) {
            $prefix = sanitize_text_field($channel_prefix);
            if ($prefix !== '') {
                $where[] = 'channel LIKE %s';
                $params[] = $prefix . '%';
            }
        }

        $from_param = $request->get_param('from');
        if ($from_param) {
            $from_ts = strtotime(sanitize_text_field($from_param));
            if ($from_ts) {
                $where[] = 'created_at >= %s';
                $params[] = date('Y-m-d H:i:s', $from_ts);
            }
        }

        $to_param = $request->get_param('to');
        if ($to_param) {
            $to_ts = strtotime(sanitize_text_field($to_param));
            if ($to_ts) {
                $where[] = 'created_at <= %s';
                $params[] = date('Y-m-d H:i:s', $to_ts);
            }
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$params
        ));

        $offset = ($page - 1) * $per_page;

        $params_with_limit = array_merge($params, [$per_page, $offset]);
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT id, type, delta, balance_after, status, channel, reference_order_id, reservation_id, expire_at, created_at
             FROM {$table} {$where_sql}
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            ...$params_with_limit
        ));

        $items = [];
        foreach ($records as $row) {
            $items[] = [
                'id'                 => (int) $row->id,
                'type'               => $row->type,
                'delta'              => (int) $row->delta,
                'balance_after'      => (int) $row->balance_after,
                'status'             => $row->status,
                'channel'            => $row->channel,
                'reference_order_id' => $row->reference_order_id ? (int) $row->reference_order_id : null,
                'reservation_id'     => $row->reservation_id,
                'expire_at'          => $row->expire_at,
                'created_at'         => $row->created_at
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'page'       => $page,
                'per_page'   => $per_page,
                'total'      => $total,
                'total_pages'=> $per_page ? (int) ceil($total / $per_page) : 1
            ]
        ]);
    }

    public static function spend_points($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $params = $request->get_json_params();
        $points = isset($params['points']) ? (int) $params['points'] : 0;
        $reason = isset($params['reason']) ? sanitize_text_field($params['reason']) : 'manual_spend';

        if ($points <= 0) {
            return new WP_Error('invalid_points', '积分数量必须大于 0', ['status' => 400]);
        }

        $table = $wpdb->prefix . 'myshop_point_ledger';
        $user_id = (int) $user->ID;

        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        ));

        if ($available < $points) {
            return new WP_Error('insufficient_points', '积分不足', ['status' => 400]);
        }

        $balance_after = $available - $points;

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'       => $user_id,
                'type'          => 'spend',
                'delta'         => -$points,
                'balance_after' => $balance_after,
                'status'        => 'confirmed',
                'channel'       => $reason,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('points_spend_failed', '扣减积分失败', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'new_balance' => $balance_after,
                'deducted'    => $points
            ]
        ]);
    }

    public static function get_missions($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $missions = get_option('myshop_points_missions', []);
        if (!is_array($missions)) {
            $missions = [];
        }

        $claimed = get_user_meta($user->ID, '_myshop_points_missions_claimed', true);
        if (!is_array($claimed)) {
            $claimed = [];
        }

        $now = current_time('timestamp');
        $result = [];

        foreach ($missions as $mission) {
            if (!is_array($mission)) {
                continue;
            }

            $mission_id = $mission['mission_id'] ?? ($mission['id'] ?? null);
            if (!$mission_id) {
                continue;
            }

            $expires_at = $mission['expires_at'] ?? null;
            $is_expired = false;
            if ($expires_at) {
                $expires_ts = strtotime($expires_at);
                $is_expired = $expires_ts && $expires_ts < $now;
            }

            $is_claimed = isset($claimed[$mission_id]);
            $status = $is_claimed ? 'completed' : ($is_expired ? 'expired' : ($mission['status'] ?? 'available'));
            $completed_at = $is_claimed ? ($claimed[$mission_id]['completed_at'] ?? null) : null;

            $result[] = [
                'mission_id' => $mission_id,
                'title' => $mission['title'] ?? '',
                'description' => $mission['description'] ?? '',
                'reward_points' => (int) ($mission['reward_points'] ?? 0),
                'status' => $status,
                'progress' => (int) ($mission['progress'] ?? 0),
                'goal' => (int) ($mission['goal'] ?? 1),
                'expires_at' => $expires_at,
                'completed_at' => $completed_at
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $result
        ]);
    }

    public static function claim_mission($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $mission_id = sanitize_text_field($request->get_param('mission_id'));
        if (!$mission_id) {
            return new WP_Error('missing_mission_id', '缺少任务 ID', ['status' => 400]);
        }

        $missions = get_option('myshop_points_missions', []);
        if (!is_array($missions)) {
            $missions = [];
        }

        $mission = null;
        foreach ($missions as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_id = $item['mission_id'] ?? ($item['id'] ?? null);
            if ($item_id === $mission_id) {
                $mission = $item;
                break;
            }
        }

        if (!$mission) {
            return new WP_Error('mission_not_found', '任务不存在', ['status' => 404]);
        }

        if (isset($mission['status']) && $mission['status'] === 'disabled') {
            return new WP_Error('mission_unavailable', '任务暂不可领取', ['status' => 409]);
        }

        $expires_at = $mission['expires_at'] ?? null;
        if ($expires_at && strtotime($expires_at) < current_time('timestamp')) {
            return new WP_Error('mission_expired', '任务已过期', ['status' => 410]);
        }

        $claimed = get_user_meta($user->ID, '_myshop_points_missions_claimed', true);
        if (!is_array($claimed)) {
            $claimed = [];
        }

        if (isset($claimed[$mission_id])) {
            return new WP_Error('mission_already_claimed', '任务奖励已领取', ['status' => 409]);
        }

        $points = (int) ($mission['reward_points'] ?? 0);
        if ($points <= 0) {
            return new WP_Error('invalid_reward_points', '奖励积分无效', ['status' => 400]);
        }

        $table = $wpdb->prefix . 'myshop_point_ledger';
        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user->ID
        ));

        $balance_after = $available + $points;
        $settings = self::get_internal_settings();
        $expire_at = self::build_expire_at($settings);
        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'       => $user->ID,
                'type'          => 'earn',
                'delta'         => $points,
                'balance_after' => $balance_after,
                'status'        => 'confirmed',
                'channel'       => 'mission_reward',
                'reservation_id'=> $mission_id,
                'expire_at'     => $expire_at,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('mission_claim_failed', '任务奖励发放失败', ['status' => 500]);
        }

        $claimed[$mission_id] = [
            'completed_at' => current_time('mysql')
        ];
        update_user_meta($user->ID, '_myshop_points_missions_claimed', $claimed);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'mission_id' => $mission_id,
                'awarded_points' => $points,
                'new_balance' => $balance_after
            ]
        ]);
    }

    public static function get_redeem_options($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $options = get_option('myshop_points_redeem_options', []);
        if (!is_array($options)) {
            $options = [];
        }

        $result = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $option_id = $option['option_id'] ?? ($option['id'] ?? null);
            if (!$option_id) {
                continue;
            }

            $result[] = [
                'option_id' => $option_id,
                'type' => $option['type'] ?? 'coupon',
                'title' => $option['title'] ?? '',
                'cost_points' => (int) ($option['cost_points'] ?? 0),
                'stock' => isset($option['stock']) ? (int) $option['stock'] : null,
                'description' => $option['description'] ?? '',
                'status' => $option['status'] ?? 'active'
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $result
        ]);
    }

    public static function redeem($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $params = $request->get_json_params();
        $option_id = isset($params['option_id']) ? sanitize_text_field($params['option_id']) : '';
        if ($option_id === '') {
            return new WP_Error('missing_option_id', '缺少兑换项 ID', ['status' => 400]);
        }

        $options = get_option('myshop_points_redeem_options', []);
        if (!is_array($options)) {
            $options = [];
        }

        $option_index = null;
        $option = null;
        foreach ($options as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_id = $item['option_id'] ?? ($item['id'] ?? null);
            if ($item_id === $option_id) {
                $option = $item;
                $option_index = $index;
                break;
            }
        }

        if (!$option) {
            return new WP_Error('option_not_found', '兑换项不存在', ['status' => 404]);
        }

        if (isset($option['status']) && $option['status'] !== 'active') {
            return new WP_Error('option_unavailable', '兑换项不可用', ['status' => 409]);
        }

        $cost_points = (int) ($option['cost_points'] ?? 0);
        if ($cost_points <= 0) {
            return new WP_Error('invalid_cost_points', '兑换积分无效', ['status' => 400]);
        }

        if (isset($option['stock'])) {
            $stock = (int) $option['stock'];
            if ($stock <= 0) {
                return new WP_Error('option_out_of_stock', '库存不足', ['status' => 409]);
            }
        }

        $table = $wpdb->prefix . 'myshop_point_ledger';
        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user->ID
        ));

        if ($available < $cost_points) {
            return new WP_Error('insufficient_points', '积分不足', ['status' => 409]);
        }

        $balance_after = $available - $cost_points;
        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'       => $user->ID,
                'type'          => 'spend',
                'delta'         => -$cost_points,
                'balance_after' => $balance_after,
                'status'        => 'confirmed',
                'channel'       => 'redeem',
                'reservation_id'=> $option_id,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('redeem_failed', '积分扣减失败', ['status' => 500]);
        }

        if (isset($option['stock']) && $option_index !== null) {
            $options[$option_index]['stock'] = max(0, (int) $option['stock'] - 1);
            update_option('myshop_points_redeem_options', $options);
        }

        $awarded_coupon_code = null;
        if (($option['type'] ?? '') === 'coupon') {
            $awarded_coupon_code = $option['coupon_code'] ?? null;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'option_id' => $option_id,
                'awarded_coupon_code' => $awarded_coupon_code,
                // 兼容前端字段命名
                'coupon_code' => $awarded_coupon_code,
                'cost_points' => $cost_points,
                'new_balance' => $balance_after
            ]
        ]);
    }

    public static function daily_signin($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $settings = self::get_internal_settings();
        if (empty($settings['enable_points']) || (int) $settings['daily_signin_points'] <= 0) {
            return new WP_Error('signin_disabled', '签到积分未启用', ['status' => 400]);
        }

        $today = date('Y-m-d', current_time('timestamp'));
        $last_signin = get_user_meta($user->ID, '_myshop_last_signin_date', true);
        if ($last_signin === $today) {
            return new WP_Error('signin_already', '今日已签到', ['status' => 409]);
        }

        $table = $wpdb->prefix . 'myshop_point_ledger';
        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user->ID
        ));

        $points = (int) $settings['daily_signin_points'];
        $balance_after = $available + $points;
        $expire_at = self::build_expire_at($settings);

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'       => $user->ID,
                'type'          => 'earn',
                'delta'         => $points,
                'balance_after' => $balance_after,
                'status'        => 'confirmed',
                'channel'       => 'daily_signin',
                'expire_at'     => $expire_at,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('signin_failed', '签到积分发放失败', ['status' => 500]);
        }

        update_user_meta($user->ID, '_myshop_last_signin_date', $today);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'awarded_points' => $points,
                'new_balance' => $balance_after,
                'signed_in_at' => current_time('mysql')
            ]
        ]);
    }

    public static function grant_points($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $access = self::ensure_internal_access($request, $user);
        if (is_wp_error($access)) {
            return $access;
        }

        $params = $request->get_json_params();
        $points = isset($params['points']) ? (int) $params['points'] : 0;
        $reason = isset($params['reason']) ? sanitize_text_field($params['reason']) : 'manual_grant';
        $target_user_id = isset($params['target_user_id']) ? (int) $params['target_user_id'] : 0;
        $target_openid = isset($params['target_openid']) ? sanitize_text_field($params['target_openid']) : '';

        if ($points <= 0) {
            return new WP_Error('invalid_points', '积分数量必须大于 0', ['status' => 400]);
        }

        if (!$target_user_id && $target_openid) {
            $target_user_id = self::resolve_user_id_by_openid($target_openid);
        }

        if (!$target_user_id) {
            $target_user_id = (int) $user->ID;
        }

        if (!$target_user_id) {
            return new WP_Error('invalid_target_user', '无法确定积分接收用户', ['status' => 400]);
        }

        $table = $wpdb->prefix . 'myshop_point_ledger';

        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $target_user_id
        ));

        $balance_after = $available + $points;

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'       => $target_user_id,
                'type'          => 'earn',
                'delta'         => $points,
                'balance_after' => $balance_after,
                'status'        => 'confirmed',
                'channel'       => $reason,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('points_grant_failed', '发放积分失败', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'target_user_id' => $target_user_id,
                'new_balance'    => $balance_after,
                'granted'        => $points
            ]
        ]);
    }

    private static function ensure_internal_access($request, $user) {
        if (user_can($user, 'manage_options')) {
            return true;
        }

        $header_key = $request->get_header('x-myshop-admin-key');
        if (!$header_key) {
            $header_key = $request->get_header('X-Myshop-Admin-Key');
        }

        $expected = defined('MYSHOP_INTERNAL_ADMIN_KEY') ? MYSHOP_INTERNAL_ADMIN_KEY : 'myshop-dev-admin-key';
        if ($header_key && hash_equals($expected, $header_key)) {
            return true;
        }

        return new WP_Error('forbidden', '无权调用该接口', ['status' => 403]);
    }

    private static function resolve_user_id_by_openid($openid) {
        if (!$openid) {
            return 0;
        }

        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_wechat_openid' AND meta_value = %s LIMIT 1",
            $openid
        ));
    }

    /**
     * 获取积分设置（用于前端计算积分抵扣）
     */
    public static function get_settings($request) {
        $defaults = [
            'enable_points' => 1,
            'earn_rate' => 10,
            'min_order_amount' => 0,
            'register_bonus' => 100,
            'daily_signin_points' => 10,
            'enable_points_discount' => 1,
            'redeem_rate' => 100,
            'min_points_to_use' => 100,
            'max_discount_percent' => 50,
            'min_order_amount_to_use' => 0,
            'enable_expiry' => 0,
            'expiry_days' => 365
        ];
        
        $settings = get_option('myshop_points_settings', []);
        $merged = wp_parse_args($settings, $defaults);
        
        // 只返回前端需要的关键设置
        return rest_ensure_response([
            'success' => true,
            'data' => [
                'enable_points_discount' => (bool) $merged['enable_points_discount'],
                'redeem_rate' => (float) $merged['redeem_rate'], // 积分抵扣比例（每X积分=1元）
                'min_points_to_use' => (int) $merged['min_points_to_use'], // 最低使用积分
                'max_discount_percent' => (float) $merged['max_discount_percent'], // 最大抵扣比例（百分比）
                'min_order_amount_to_use' => (float) $merged['min_order_amount_to_use'] // 最低订单金额
            ]
        ]);
    }

    public static function get_exchange_rules($request) {
        $settings = self::get_internal_settings();

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'enable_points_exchange' => (bool) $settings['enable_points_exchange'],
                'exchange_rate' => (float) $settings['exchange_rate'],
                'exchange_min_points' => (int) $settings['exchange_min_points'],
                'exchange_min_amount' => (float) $settings['exchange_min_amount'],
                'exchange_max_amount' => (float) $settings['exchange_max_amount'],
                'exchange_max_amount_per_day' => (float) $settings['exchange_max_amount_per_day'],
                'exchange_max_requests_per_day' => (int) $settings['exchange_max_requests_per_day'],
                'exchange_fee_rate' => (float) $settings['exchange_fee_rate']
            ]
        ]);
    }

    public static function exchange_points($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }
        $verified = self::ensure_verified_identity($user);
        if (is_wp_error($verified)) {
            return $verified;
        }

        $settings = self::get_internal_settings();
        if (empty($settings['enable_points']) || empty($settings['enable_points_exchange'])) {
            return new WP_Error('exchange_disabled', '积分兑换已关闭', ['status' => 403]);
        }

        $params = $request->get_json_params();
        $points = isset($params['points']) ? (int) $params['points'] : 0;
        if ($points <= 0) {
            return new WP_Error('invalid_points', '兑换积分无效', ['status' => 400]);
        }

        $min_points = (int) $settings['exchange_min_points'];
        if ($min_points > 0 && $points < $min_points) {
            return new WP_Error('points_below_minimum', '未达到最低兑换积分', ['status' => 400]);
        }

        $rate = (float) $settings['exchange_rate'];
        if ($rate <= 0) {
            return new WP_Error('invalid_exchange_rate', '兑换比例未配置', ['status' => 500]);
        }

        $gross_amount = $points / $rate;
        $min_amount = (float) $settings['exchange_min_amount'];
        if ($min_amount > 0 && $gross_amount + 0.0001 < $min_amount) {
            return new WP_Error('amount_below_minimum', '未达到最低兑换金额', ['status' => 400]);
        }

        $max_amount = (float) $settings['exchange_max_amount'];
        if ($max_amount > 0 && $gross_amount - 0.0001 > $max_amount) {
            return new WP_Error('amount_exceeds_maximum', '超过单次兑换上限', ['status' => 400]);
        }

        $user_id = (int) $user->ID;
        $ledger_table = $wpdb->prefix . 'myshop_point_ledger';
        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$ledger_table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        ));

        if ($available < $points) {
            return new WP_Error('insufficient_points', '积分不足', ['status' => 400]);
        }

        $today = current_time('Y-m-d');
        $payout_table = $wpdb->prefix . 'myshop_commission_payouts';

        $daily_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$payout_table}
             WHERE earner_id = %d AND note = 'points_exchange' AND requested_at >= %s",
            $user_id,
            $today . ' 00:00:00'
        ));
        $max_requests = (int) $settings['exchange_max_requests_per_day'];
        if ($max_requests > 0 && $daily_count >= $max_requests) {
            return new WP_Error('exchange_too_frequent', '今日兑换次数已达上限', ['status' => 429]);
        }

        $daily_amount = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$payout_table}
             WHERE earner_id = %d AND note = 'points_exchange' AND requested_at >= %s",
            $user_id,
            $today . ' 00:00:00'
        ));
        $max_amount_per_day = (float) $settings['exchange_max_amount_per_day'];
        if ($max_amount_per_day > 0 && ($daily_amount + $gross_amount) - 0.0001 > $max_amount_per_day) {
            return new WP_Error('exchange_daily_limit', '今日兑换金额已达上限', ['status' => 429]);
        }

        $fee_rate = (float) $settings['exchange_fee_rate'];
        $fee = $fee_rate > 0 ? round($gross_amount * ($fee_rate / 100), 2) : 0.0;
        $net_amount = round($gross_amount - $fee, 2);
        if ($net_amount <= 0) {
            return new WP_Error('invalid_net_amount', '兑换金额无效', ['status' => 400]);
        }

        $payout_method = isset($params['payout_method']) ? sanitize_text_field($params['payout_method']) : 'manual';
        $account_name = isset($params['account_name']) ? sanitize_text_field($params['account_name']) : null;
        $account_no = isset($params['account_no']) ? sanitize_text_field($params['account_no']) : null;
        $bank_name = isset($params['bank_name']) ? sanitize_text_field($params['bank_name']) : null;

        $now = current_time('mysql');
        $inserted = $wpdb->insert(
            $payout_table,
            [
                'earner_id' => $user_id,
                'amount' => $net_amount,
                'payout_method' => $payout_method,
                'account_name' => $account_name,
                'account_no' => $account_no,
                'bank_name' => $bank_name,
                'status' => 'processing',
                'requested_at' => $now,
                'note' => 'points_exchange',
                'created_at' => $now,
                'updated_at' => $now
            ],
            ['%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('exchange_create_failed', '兑换申请失败', ['status' => 500]);
        }

        $payout_id = (int) $wpdb->insert_id;
        $balance_after = $available - $points;
        $ledger_inserted = $wpdb->insert(
            $ledger_table,
            [
                'user_id' => $user_id,
                'type' => 'spend',
                'delta' => -$points,
                'balance_after' => $balance_after,
                'status' => 'confirmed',
                'channel' => 'points_exchange',
                'reservation_id' => 'payout:' . $payout_id,
                'created_at' => $now,
                'updated_at' => $now
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($ledger_inserted === false) {
            $wpdb->delete($payout_table, ['id' => $payout_id], ['%d']);
            return new WP_Error('exchange_ledger_failed', '兑换积分失败', ['status' => 500]);
        }

        $response = rest_ensure_response([
            'success' => true,
            'data' => [
                'payout_id' => $payout_id,
                'points' => $points,
                'gross_amount' => number_format($gross_amount, 2, '.', ''),
                'fee' => number_format($fee, 2, '.', ''),
                'amount' => number_format($net_amount, 2, '.', ''),
                'status' => 'processing',
                'requested_at' => mysql2date('c', $now)
            ]
        ]);
        $response->set_status(201);
        return $response;
    }

    private static function ensure_verified_identity($user) {
        if (!$user instanceof WP_User) {
            return new WP_Error('user_invalid', '用户无效', ['status' => 401]);
        }
        $first_name = trim((string) $user->first_name);
        $phone = get_user_meta($user->ID, 'billing_phone', true);
        if (!$phone) {
            $phone = get_user_meta($user->ID, '_wechat_phone', true);
        }
        $phone = trim((string) $phone);
        if ($first_name === '' || $phone === '') {
            return new WP_Error('verification_required', '请先完成实名认证', ['status' => 403]);
        }
        return true;
    }
    
    /**
     * 获取积分规则列表
     */
    public static function get_rules($request) {
        $settings = self::get_internal_settings();
        $rules = [];
        $enable_points = !empty($settings['enable_points']);

        $earn_rate = (float) $settings['earn_rate'];
        if ($earn_rate > 0) {
            $min_order_amount = (float) $settings['min_order_amount'];
            $desc = $min_order_amount > 0
                ? sprintf('订单实付满 %.2f 元起，每消费 1 元获得 %.2f 积分，订单完成后发放', $min_order_amount, $earn_rate)
                : sprintf('每消费 1 元获得 %.2f 积分，订单完成后发放', $earn_rate);
            $rules[] = [
                'rule_id' => 'order_reward',
                'title' => '下单返积分',
                'description' => $desc,
                'status' => $enable_points ? 'active' : 'inactive'
            ];
        }

        $register_bonus = (int) $settings['register_bonus'];
        $rules[] = [
            'rule_id' => 'register_bonus',
            'title' => '注册奖励',
            'description' => $register_bonus > 0 ? sprintf('新用户注册即赠送 %d 积分', $register_bonus) : '新用户注册奖励已关闭',
            'status' => $register_bonus > 0 && $enable_points ? 'active' : 'inactive'
        ];

        $daily_signin_points = (int) $settings['daily_signin_points'];
        $rules[] = [
            'rule_id' => 'daily_signin',
            'title' => '每日签到',
            'description' => $daily_signin_points > 0 ? sprintf('每日签到可得 %d 积分', $daily_signin_points) : '签到奖励已关闭',
            'status' => $daily_signin_points > 0 && $enable_points ? 'active' : 'inactive'
        ];

        $enable_discount = !empty($settings['enable_points_discount']);
        $redeem_rate = (int) $settings['redeem_rate'];
        $min_points = (int) $settings['min_points_to_use'];
        $max_discount = (float) $settings['max_discount_percent'];
        $min_order_amount = (float) $settings['min_order_amount_to_use'];
        $discount_desc = $enable_discount
            ? sprintf('每 %d 积分抵扣 1 元，最低使用 %d 积分，最高抵扣订单金额 %.0f%%，订单满 %.2f 元可用',
                $redeem_rate,
                $min_points,
                $max_discount,
                $min_order_amount
            )
            : '积分抵扣已关闭';
        $rules[] = [
            'rule_id' => 'points_discount',
            'title' => '积分抵扣',
            'description' => $discount_desc,
            'status' => $enable_discount && $enable_points ? 'active' : 'inactive'
        ];

        $enable_expiry = !empty($settings['enable_expiry']);
        $expiry_days = (int) $settings['expiry_days'];
        $expiry_desc = $enable_expiry && $expiry_days > 0
            ? sprintf('积分有效期 %d 天，到期自动失效', $expiry_days)
            : '积分长期有效';
        $rules[] = [
            'rule_id' => 'points_expiry',
            'title' => '积分有效期',
            'description' => $expiry_desc,
            'status' => $enable_points ? 'active' : 'inactive'
        ];

        $enable_referral = !empty($settings['enable_referral_points']);
        $level1_rate = (float) $settings['referral_points_rate_level1'];
        $level2_rate = (float) $settings['referral_points_rate_level2'];
        $referral_desc = $enable_referral
            ? sprintf('一级奖励 %.2f 积分/元，二级奖励 %.2f 积分/元', $level1_rate, $level2_rate)
            : '分销奖励积分已关闭';
        $rules[] = [
            'rule_id' => 'referral_reward',
            'title' => '分销奖励',
            'description' => $referral_desc,
            'status' => $enable_referral && $enable_points ? 'active' : 'inactive'
        ];

        $enable_exchange = !empty($settings['enable_points_exchange']);
        $exchange_desc = $enable_exchange
            ? sprintf('每 %.2f 积分可兑换 1 元，手续费 %.2f%%', (float) $settings['exchange_rate'], (float) $settings['exchange_fee_rate'])
            : '积分兑换已关闭';
        $rules[] = [
            'rule_id' => 'points_exchange',
            'title' => '积分兑换佣金',
            'description' => $exchange_desc,
            'status' => $enable_exchange && $enable_points ? 'active' : 'inactive'
        ];

        return rest_ensure_response([
            'success' => true,
            'data' => $rules
        ]);
    }
}
