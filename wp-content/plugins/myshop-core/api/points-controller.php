<?php

class Points_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/points/balance', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_balance'],
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
}
