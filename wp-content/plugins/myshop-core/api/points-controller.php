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
                'status'   => ['type' => 'string', 'required' => false]
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
            'expiry_days' => 365
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
    
    /**
     * 获取积分规则列表
     */
    public static function get_rules($request) {
        $settings = self::get_internal_settings();
        $rules = [
            'enable_points' => (bool) $settings['enable_points'],
            'earn_rate' => (float) $settings['earn_rate'],
            'min_order_amount' => (float) $settings['min_order_amount'],
            'register_bonus' => (int) $settings['register_bonus'],
            'daily_signin_points' => (int) $settings['daily_signin_points'],
            'enable_points_discount' => (bool) $settings['enable_points_discount'],
            'redeem_rate' => (int) $settings['redeem_rate'],
            'min_points_to_use' => (int) $settings['min_points_to_use'],
            'max_discount_percent' => (float) $settings['max_discount_percent'],
            'min_order_amount_to_use' => (float) $settings['min_order_amount_to_use'],
            'enable_expiry' => (bool) $settings['enable_expiry'],
            'expiry_days' => (int) $settings['expiry_days']
        ];

        return rest_ensure_response([
            'success' => true,
            'data' => $rules
        ]);
    }
}
