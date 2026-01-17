<?php

class Referral_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/referral/code', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_code'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/referrals/my-downlines', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_downlines'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20],
                'level'    => ['type' => 'integer', 'required' => false]
            ]
        ]);

        register_rest_route('myshop/v1', '/referral/members', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_members'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20],
                'level'    => ['type' => 'integer', 'required' => false]
            ]
        ]);

        register_rest_route('myshop/v1', '/referral/summary', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_summary'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
    }

    public static function get_code($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'referral_code' => self::ensure_referral_code($user->ID)
            ]
        ]);
    }

    public static function list_downlines($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_referrals';
        $users_table = $wpdb->users;

        $user_id = (int) $user->ID;
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = absint($request->get_param('per_page') ?: 20);
        if ($per_page > 100) {
            $per_page = 100;
        } elseif ($per_page < 1) {
            $per_page = 20;
        }

        $level_filter = $request->get_param('level');
        $where = ['inviter_id = %d'];
        $params = [$user_id];
        if ($level_filter !== null && $level_filter !== '') {
            $where[] = 'level = %d';
            $params[] = absint($level_filter);
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$params
        ));

        $offset = ($page - 1) * $per_page;
        $params_with_limit = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.invitee_id, r.level, r.first_order_status, r.created_at, r.channel_code,
                    u.user_registered
             FROM {$table} r
             LEFT JOIN {$users_table} u ON u.ID = r.invitee_id
             {$where_sql}
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d",
            ...$params_with_limit
        ));

        $downlines = [];
        foreach ($rows as $row) {
            $invitee_id = (int) $row->invitee_id;
            $phone = get_user_meta($invitee_id, 'billing_phone', true);
            if (!$phone) {
                $phone = get_user_meta($invitee_id, 'phone', true);
            }

            $orders_info = self::get_user_order_stats($invitee_id);

            $downlines[] = [
                'user_id'            => $invitee_id,
                'phone'              => self::mask_phone($phone),
                'registered_at'      => $row->user_registered ?: null,
                'level'              => (int) $row->level,
                'first_order_status' => $row->first_order_status,
                'total_orders'       => $orders_info['count'],
                'lifetime_value'     => $orders_info['lifetime_value'],
                'channel_code'       => $row->channel_code ?: null
            ];
        }

        $level1_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE inviter_id = %d AND level = 1",
            $user_id
        ));
        $level2_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE inviter_id = %d AND level = 2",
            $user_id
        ));
        $first_order_completed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE inviter_id = %d AND first_order_status = 'completed'",
            $user_id
        ));

        return rest_ensure_response([
            'downlines' => $downlines,
            'summary' => [
                'level1_count' => $level1_count,
                'level2_count' => $level2_count,
                'first_order_completed' => $first_order_completed
            ],
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $per_page ? (int) ceil($total / $per_page) : 1
            ]
        ]);
    }

    public static function list_members($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_referrals';
        $users_table = $wpdb->users;

        $user_id = (int) $user->ID;
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = absint($request->get_param('per_page') ?: 20);
        if ($per_page > 100) {
            $per_page = 100;
        } elseif ($per_page < 1) {
            $per_page = 20;
        }

        $level_filter = $request->get_param('level');
        $where = ['inviter_id = %d'];
        $params = [$user_id];
        if ($level_filter !== null && $level_filter !== '') {
            $where[] = 'level = %d';
            $params[] = absint($level_filter);
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$params
        ));

        $offset = ($page - 1) * $per_page;
        $params_with_limit = array_merge($params, [$per_page, $offset]);
        $sql = "SELECT r.invitee_id, r.level, r.first_order_status, r.first_order_id, r.created_at,
                       u.display_name, u.user_login
                FROM {$table} r
                LEFT JOIN {$users_table} u ON u.ID = r.invitee_id
                {$where_sql}
                ORDER BY r.created_at DESC
                LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_with_limit));

        $members = [];
        foreach ($rows as $row) {
            $members[] = [
                'user_id'            => (int) $row->invitee_id,
                'nickname'           => $row->display_name ?: $row->user_login,
                'level'              => (int) $row->level,
                'first_order_status' => $row->first_order_status,
                'first_order_id'     => $row->first_order_id ? (int) $row->first_order_id : null,
                'joined_at'          => $row->created_at
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $members,
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

        $user_id = (int) $user->ID;
        $referral_table = $wpdb->prefix . 'myshop_referrals';
        $commission_table = $wpdb->prefix . 'myshop_commissions';

        $total_invitees = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} WHERE inviter_id = %d",
            $user_id
        ));

        $level1_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} WHERE inviter_id = %d AND level = 1",
            $user_id
        ));

        $level2_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} WHERE inviter_id = %d AND level = 2",
            $user_id
        ));

        $completed_orders = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} WHERE inviter_id = %d AND first_order_status = 'completed'",
            $user_id
        ));

        $pending_orders = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} WHERE inviter_id = %d AND first_order_status = 'pending'",
            $user_id
        ));

        $commission_summary = $wpdb->get_results($wpdb->prepare(
            "SELECT status, SUM(amount) AS total
             FROM {$commission_table}
             WHERE earner_id = %d AND commission_type = 'referral'
             GROUP BY status",
            $user_id
        ));

        $commission_totals = [
            'pending' => '0.00',
            'approved' => '0.00',
            'rejected' => '0.00',
            'paid' => '0.00'
        ];
        foreach ($commission_summary as $row) {
            $status = $row->status;
            if (isset($commission_totals[$status])) {
                $commission_totals[$status] = number_format((float) $row->total, 2, '.', '');
            }
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'referral_code'         => self::ensure_referral_code($user_id),
                'total_invitees'        => $total_invitees,
                'level_one_count'       => $level1_count,
                'level_two_count'       => $level2_count,
                'completed_first_orders'=> $completed_orders,
                'pending_first_orders'  => $pending_orders,
                'commission_totals'     => $commission_totals
            ]
        ]);
    }

    public static function ensure_referral_code($user_id) {
        $code = get_user_meta($user_id, 'myshop_referral_code', true);
        if (!$code) {
            $code = 'U' . $user_id . strtoupper(wp_generate_password(4, false));
            update_user_meta($user_id, 'myshop_referral_code', $code);
        }
        return $code;
    }

    private static function mask_phone($phone) {
        if (!is_string($phone) || $phone === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < 7) {
            return $phone;
        }
        return substr($digits, 0, 3) . '****' . substr($digits, -4);
    }

    private static function get_user_order_stats($user_id) {
        if (!function_exists('wc_get_orders')) {
            return [
                'count' => 0,
                'lifetime_value' => '0.00'
            ];
        }

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status'      => ['processing', 'completed', 'on-hold', 'pending'],
            'limit'       => -1,
            'return'      => 'ids'
        ]);

        $total = 0.0;
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $total += (float) $order->get_total();
            }
        }

        return [
            'count' => count($orders),
            'lifetime_value' => number_format($total, 2, '.', '')
        ];
    }
}
