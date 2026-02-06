<?php

class Commission_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/commissions', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_commissions'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20],
                'status'   => ['type' => 'string', 'required' => false],
                'type'     => ['type' => 'string', 'required' => false]
            ]
        ]);

        register_rest_route('myshop/v1', '/commissions/summary', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_summary'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/commissions/payout', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'request_payout'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'amount'        => ['type' => 'number', 'required' => true],
                'payout_method' => ['type' => 'string', 'required' => false],
                'account_name'  => ['type' => 'string', 'required' => false],
                'account_no'    => ['type' => 'string', 'required' => false],
                'bank_name'     => ['type' => 'string', 'required' => false]
            ]
        ]);

        register_rest_route('myshop/v1', '/commissions/payouts', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_payouts'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20]
            ]
        ]);
    }

    public static function list_commissions($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_commissions';
        $user_id = (int) $user->ID;

        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = absint($request->get_param('per_page') ?: 20);
        if ($per_page > 100) {
            $per_page = 100;
        } elseif ($per_page < 1) {
            $per_page = 20;
        }

        $where = ['earner_id = %d'];
        $params = [$user_id];

        $status_param = $request->get_param('status');
        if ($status_param) {
            $status = sanitize_text_field($status_param);
            $where[] = 'status = %s';
            $params[] = $status;
        }

        $type_param = $request->get_param('type');
        if ($type_param) {
            $type = sanitize_text_field($type_param);
            $where[] = 'commission_type = %s';
            $params[] = $type;
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$params
        ));

        $offset = ($page - 1) * $per_page;
        $params_with_limit = array_merge($params, [$per_page, $offset]);

        $sql = "SELECT id, order_id, amount, currency, commission_type, referrer_id, agent_id, status,
                       expected_payout_at, paid_at, note, created_at
                FROM {$table}
                {$where_sql}
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_with_limit));

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'                 => (int) $row->id,
                'order_id'           => (int) $row->order_id,
                'amount'             => number_format((float) $row->amount, 2, '.', ''),
                'currency'           => $row->currency,
                'commission_type'    => $row->commission_type,
                'referrer_id'        => $row->referrer_id ? (int) $row->referrer_id : null,
                'agent_id'           => $row->agent_id ? (int) $row->agent_id : null,
                'status'             => $row->status,
                'expected_payout_at' => $row->expected_payout_at,
                'paid_at'            => $row->paid_at,
                'note'               => $row->note,
                'created_at'         => $row->created_at
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $items,
            // 兼容前端旧结构：直接返回 commissions 列表
            'commissions' => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $per_page ? (int) ceil($total / $per_page) : 1
            ]
        ]);
    }

    public static function get_summary($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_commissions';
        $user_id = (int) $user->ID;

        $status_totals = [
            'pending'  => '0.00',
            'approved' => '0.00',
            'rejected' => '0.00',
            'paid'     => '0.00'
        ];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, SUM(amount) AS total
             FROM {$table}
             WHERE earner_id = %d
             GROUP BY status",
            $user_id
        ));

        foreach ($rows as $row) {
            if (isset($status_totals[$row->status])) {
                $status_totals[$row->status] = number_format((float) $row->total, 2, '.', '');
            }
        }

        $today = current_time('Y-m-d');
        $month_start = date('Y-m-01', strtotime($today));

        $monthly = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM {$table}
             WHERE earner_id = %d AND status = 'paid' AND paid_at >= %s",
            $user_id,
            $month_start
        ));

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'totals_by_status' => $status_totals,
                'paid_this_month'  => number_format((float) $monthly, 2, '.', '')
            ]
        ]);
    }

    public static function request_payout($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $params = $request->get_json_params();
        $amount = isset($params['amount']) ? (float) $params['amount'] : 0.0;
        $payout_method = isset($params['payout_method']) ? sanitize_text_field($params['payout_method']) : 'manual';
        $account_name = isset($params['account_name']) ? sanitize_text_field($params['account_name']) : null;
        $account_no = isset($params['account_no']) ? sanitize_text_field($params['account_no']) : null;
        $bank_name = isset($params['bank_name']) ? sanitize_text_field($params['bank_name']) : null;

        if ($amount <= 0) {
            return new WP_Error('invalid_amount', '提现金额无效', ['status' => 400]);
        }

        $min_amount = (float) get_option('myshop_commission_payout_min', 0);
        if ($min_amount > 0 && $amount < $min_amount) {
            return new WP_Error('payout_below_minimum', '未达到提现门槛', ['status' => 400]);
        }

        $payout_table = $wpdb->prefix . 'myshop_commission_payouts';
        $commission_table = $wpdb->prefix . 'myshop_commissions';
        $user_id = (int) $user->ID;

        $in_progress = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$payout_table} WHERE earner_id = %d AND status = 'processing'",
            $user_id
        ));
        if ($in_progress > 0) {
            return new WP_Error('payout_in_progress', '佣金正在处理，请稍后重试', ['status' => 409]);
        }

        $eligible_amount = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM {$commission_table}
             WHERE earner_id = %d AND status = 'approved' AND (settlement_batch IS NULL OR settlement_batch = '')",
            $user_id
        ));

        if ($eligible_amount + 0.0001 < $amount) {
            return new WP_Error('insufficient_balance', '可提现金额不足', ['status' => 409]);
        }

        $batch = wp_date('Y-m-\WW', current_time('timestamp'));
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $payout_table,
            [
                'earner_id' => $user_id,
                'amount' => $amount,
                'payout_method' => $payout_method,
                'account_name' => $account_name,
                'account_no' => $account_no,
                'bank_name' => $bank_name,
                'settlement_batch' => $batch,
                'status' => 'processing',
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now
            ],
            ['%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('payout_create_failed', '提现申请失败', ['status' => 500]);
        }

        $remaining = $amount;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, amount FROM {$commission_table}
             WHERE earner_id = %d AND status = 'approved' AND (settlement_batch IS NULL OR settlement_batch = '')
             ORDER BY created_at ASC",
            $user_id
        ));

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $wpdb->update(
                $commission_table,
                [
                    'settlement_batch' => $batch,
                    'expected_payout_at' => $now,
                    'updated_at' => $now
                ],
                ['id' => (int) $row->id],
                ['%s', '%s', '%s'],
                ['%d']
            );
            $remaining -= (float) $row->amount;
        }

        $response = rest_ensure_response([
            'success' => true,
            'data' => [
                'payout_id' => (int) $wpdb->insert_id,
                'amount' => number_format($amount, 2, '.', ''),
                'status' => 'processing',
                'settlement_batch' => $batch,
                'requested_at' => mysql2date('c', $now)
            ]
        ]);
        $response->set_status(201);
        return $response;
    }

    public static function list_payouts($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_commission_payouts';
        $user_id = (int) $user->ID;

        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = absint($request->get_param('per_page') ?: 20);
        if ($per_page > 100) {
            $per_page = 100;
        } elseif ($per_page < 1) {
            $per_page = 20;
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE earner_id = %d",
            $user_id
        ));

        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, amount, status, settlement_batch, requested_at, paid_at, note
             FROM {$table}
             WHERE earner_id = %d
             ORDER BY requested_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ));

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'payout_id' => (int) $row->id,
                'amount' => number_format((float) $row->amount, 2, '.', ''),
                'status' => $row->status,
                'settlement_batch' => $row->settlement_batch,
                'requested_at' => $row->requested_at,
                'paid_at' => $row->paid_at,
                'note' => $row->note
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $per_page ? (int) ceil($total / $per_page) : 1
            ]
        ]);
    }
}
