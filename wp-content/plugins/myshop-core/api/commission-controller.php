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
}
