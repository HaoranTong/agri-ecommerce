<?php

class Analytics_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/analytics/channel', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'channel_overview'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
    }

    public static function channel_overview($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $from = $request->get_param('from');
        $to   = $request->get_param('to');

        $conditions = [];
        $params     = [];
        if ($from) {
            $conditions[] = 'recorded_at >= %s';
            $params[]     = date('Y-m-d 00:00:00', strtotime($from));
        }
        if ($to) {
            $conditions[] = 'recorded_at <= %s';
            $params[]     = date('Y-m-d 23:59:59', strtotime($to));
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        $table = $wpdb->prefix . 'myshop_invitation_logs';

        $sql = "SELECT channel, COUNT(*) AS visits FROM {$table} {$where} GROUP BY channel ORDER BY visits DESC";
        $results = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);

        $channels = [];
        foreach ($results as $row) {
            $channel_key = $row->channel ?: 'unknown';
            $channels[] = [
                'channel'      => $channel_key,
                'visits'       => (int) $row->visits,
                'new_users'    => self::count_new_users_by_channel($channel_key, $from, $to),
                'first_orders' => self::count_first_orders_by_channel($channel_key, $from, $to),
                'gmv'          => self::sum_gmv_by_channel($channel_key, $from, $to)
            ];
        }

        return rest_ensure_response([
            'range' => [
                'from' => $from,
                'to'   => $to
            ],
            'channels'   => $channels,
            'pagination' => [
                'page'       => 1,
                'page_size'  => count($channels),
                'total_pages'=> 1
            ]
        ]);
    }

    private static function count_new_users_by_channel($channel, $from, $to) {
        global $wpdb;
        $table = $wpdb->prefix . 'myshop_referrals';
        $conditions = [];
        $params = [];

        if ($channel === 'unknown') {
            $conditions[] = '(channel_code IS NULL OR channel_code = "")';
        } else {
            $conditions[] = 'channel_code = %s';
            $params[] = $channel;
        }
        if ($from) {
            $conditions[] = 'created_at >= %s';
            $params[] = date('Y-m-d 00:00:00', strtotime($from));
        }
        if ($to) {
            $conditions[] = 'created_at <= %s';
            $params[] = date('Y-m-d 23:59:59', strtotime($to));
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT COUNT(*) FROM {$table} {$where}";
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql));
    }

    private static function count_first_orders_by_channel($channel, $from, $to) {
        global $wpdb;
        $table = $wpdb->prefix . 'myshop_referrals';
        $conditions = ['first_order_status = \'completed\''];
        $params = [];

        if ($channel === 'unknown') {
            $conditions[] = '(channel_code IS NULL OR channel_code = "")';
        } else {
            $conditions[] = 'channel_code = %s';
            $params[] = $channel;
        }
        if ($from) {
            $conditions[] = 'first_order_completed_at >= %s';
            $params[] = date('Y-m-d 00:00:00', strtotime($from));
        }
        if ($to) {
            $conditions[] = 'first_order_completed_at <= %s';
            $params[] = date('Y-m-d 23:59:59', strtotime($to));
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $sql = "SELECT COUNT(*) FROM {$table} {$where}";
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql));
    }

    private static function sum_gmv_by_channel($channel, $from, $to) {
        global $wpdb;
        $referral_table = $wpdb->prefix . 'myshop_referrals';
        $ordermeta_table = $wpdb->prefix . 'postmeta';
        $orders_table = $wpdb->prefix . 'posts';

        $conditions = ['r.first_order_status = \'completed\''];
        $params = [];

        if ($channel === 'unknown') {
            $conditions[] = '(r.channel_code IS NULL OR r.channel_code = "")';
        } else {
            $conditions[] = 'r.channel_code = %s';
            $params[] = $channel;
        }
        if ($from) {
            $conditions[] = 'r.first_order_completed_at >= %s';
            $params[] = date('Y-m-d 00:00:00', strtotime($from));
        }
        if ($to) {
            $conditions[] = 'r.first_order_completed_at <= %s';
            $params[] = date('Y-m-d 23:59:59', strtotime($to));
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);
        $sql = "SELECT SUM( CAST(pm.meta_value AS DECIMAL(10,2)) )
                FROM {$referral_table} r
                INNER JOIN {$orders_table} o ON o.ID = r.first_order_id
                INNER JOIN {$ordermeta_table} pm ON pm.post_id = o.ID AND pm.meta_key = '_order_total'
                {$where}";

        $total = $params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql);
        return number_format((float) $total, 2, '.', '');
    }
}
