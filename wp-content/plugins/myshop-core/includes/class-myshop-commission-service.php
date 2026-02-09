<?php

class MyShop_Commission_Service {
    public static function boot() {
        add_action('woocommerce_order_status_completed', [self::class, 'handle_order_completed'], 10, 1);
        add_action('woocommerce_order_status_processing', [self::class, 'handle_order_completed'], 10, 1);
        add_action('woocommerce_order_status_refunded', [self::class, 'handle_order_cancelled'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [self::class, 'handle_order_cancelled'], 10, 1);
        add_action('woocommerce_order_status_failed', [self::class, 'handle_order_cancelled'], 10, 1);
    }

    public static function handle_order_completed($order_id) {
        if (!$order_id || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (get_post_meta($order_id, '_commission_processed', true) === '1') {
            return;
        }

        $total = (float) $order->get_total();
        if ($total <= 0) {
            update_post_meta($order_id, '_commission_processed', '1');
            return;
        }

        $customer_id = (int) $order->get_customer_id();
        if ($customer_id <= 0) {
            update_post_meta($order_id, '_commission_processed', '1');
            return;
        }

        global $wpdb;
        $referral_table = $wpdb->prefix . 'myshop_referrals';
        $commission_table = $wpdb->prefix . 'myshop_commissions';
        $now = current_time('mysql');

        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT inviter_id, level, channel_code, first_order_status FROM {$referral_table} WHERE invitee_id = %d LIMIT 1",
            $customer_id
        ));

        if ($referral) {
            $points_settings = self::get_points_settings();
            $use_points_reward = !empty($points_settings['enable_points']) && !empty($points_settings['enable_referral_points']);

            if ($referral->first_order_status !== 'completed') {
                $wpdb->update(
                    $referral_table,
                    [
                        'first_order_status' => 'completed',
                        'first_order_id' => $order_id,
                        'first_order_completed_at' => $now
                    ],
                    ['invitee_id' => $customer_id],
                    ['%s', '%d', '%s'],
                    ['%d']
                );

                self::backfill_attribution($order_id, $customer_id, $referral);
            }

            if ($use_points_reward) {
                $level1_rate = (float) $points_settings['referral_points_rate_level1'];
                self::grant_referral_points(
                    (int) $referral->inviter_id,
                    $order_id,
                    $total,
                    $level1_rate,
                    1,
                    $points_settings
                );
            } else {
                $channel_code = $referral->channel_code ?: null;
                $level1_rate = self::get_commission_rate(1, $channel_code);
                self::create_commission_if_needed(
                    $commission_table,
                    $order_id,
                    (int) $referral->inviter_id,
                    $total,
                    $level1_rate,
                    $order->get_currency(),
                    (int) $referral->inviter_id
                );
            }

            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT inviter_id FROM {$referral_table} WHERE invitee_id = %d LIMIT 1",
                (int) $referral->inviter_id
            ));
            if ($parent && (int) $parent->inviter_id > 0 && (int) $parent->inviter_id !== (int) $referral->inviter_id) {
                if ($use_points_reward) {
                    $level2_rate = (float) $points_settings['referral_points_rate_level2'];
                    self::grant_referral_points(
                        (int) $parent->inviter_id,
                        $order_id,
                        $total,
                        $level2_rate,
                        2,
                        $points_settings
                    );
                } else {
                    $level2_rate = self::get_commission_rate(2, $channel_code);
                    self::create_commission_if_needed(
                        $commission_table,
                        $order_id,
                        (int) $parent->inviter_id,
                        $total,
                        $level2_rate,
                        $order->get_currency(),
                        (int) $referral->inviter_id
                    );
                }
            }
        }

        update_post_meta($order_id, '_commission_processed', '1');
    }

    public static function handle_order_cancelled($order_id) {
        if (!$order_id) {
            return;
        }

        global $wpdb;
        $commission_table = $wpdb->prefix . 'myshop_commissions';
        $referral_table = $wpdb->prefix . 'myshop_referrals';
        $ledger_table = $wpdb->prefix . 'myshop_point_ledger';
        $now = current_time('mysql');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$commission_table}
             SET status = 'rejected', note = 'order_cancelled', updated_at = %s
             WHERE order_id = %d AND status IN ('pending', 'approved')",
            $now,
            $order_id
        ));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$referral_table}
             SET first_order_status = 'expired', first_order_completed_at = NULL
             WHERE first_order_id = %d AND first_order_status = 'completed'",
            $order_id
        ));

        $reward_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, COALESCE(SUM(delta), 0) AS total_points
             FROM {$ledger_table}
             WHERE reference_order_id = %d AND channel LIKE %s AND type = 'earn' AND status = 'confirmed'
             GROUP BY user_id",
            $order_id,
            'referral_reward%'
        ));

        foreach ($reward_rows as $row) {
            $user_id = (int) $row->user_id;
            $reward_points = (int) $row->total_points;
            if ($user_id <= 0 || $reward_points <= 0) {
                continue;
            }

            $existing_reversal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$ledger_table}
                 WHERE reference_order_id = %d AND channel = 'referral_reward_reversal' AND user_id = %d",
                $order_id,
                $user_id
            ));
            if ($existing_reversal > 0) {
                continue;
            }

            $available = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(delta), 0) FROM {$ledger_table} WHERE user_id = %d AND status = 'confirmed'",
                $user_id
            ));
            $balance_after = $available - $reward_points;
            $wpdb->insert(
                $ledger_table,
                [
                    'user_id' => $user_id,
                    'type' => 'adjust',
                    'delta' => -$reward_points,
                    'balance_after' => $balance_after,
                    'status' => 'confirmed',
                    'channel' => 'referral_reward_reversal',
                    'reference_order_id' => $order_id,
                    'created_at' => $now,
                    'updated_at' => $now
                ],
                ['%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s']
            );
        }
    }

    private static function get_commission_rate($level, $channel_code = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'myshop_commission_policies';
        $now = current_time('mysql');

        $rate = null;
        if ($channel_code) {
            $rate = $wpdb->get_var($wpdb->prepare(
                "SELECT rate FROM {$table}
                 WHERE level = %d AND channel = %s
                   AND effective_from <= %s
                   AND (effective_to IS NULL OR effective_to >= %s)
                 ORDER BY effective_from DESC
                 LIMIT 1",
                $level,
                $channel_code,
                $now,
                $now
            ));
        }

        if ($rate === null) {
            $rate = $wpdb->get_var($wpdb->prepare(
                "SELECT rate FROM {$table}
                 WHERE level = %d AND (channel IS NULL OR channel = '')
                   AND effective_from <= %s
                   AND (effective_to IS NULL OR effective_to >= %s)
                 ORDER BY effective_from DESC
                 LIMIT 1",
                $level,
                $now,
                $now
            ));
        }

        if ($rate === null) {
            $option_key = $level === 1 ? 'myshop_commission_rate_level1' : 'myshop_commission_rate_level2';
            $rate = get_option($option_key, 0);
        }

        return (float) $rate;
    }

    private static function create_commission_if_needed($table, $order_id, $earner_id, $order_total, $rate, $currency, $referrer_id) {
        if ($rate <= 0) {
            return;
        }

        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND earner_id = %d AND commission_type = 'referral'",
            $order_id,
            $earner_id
        ));

        if ($exists > 0) {
            return;
        }

        $amount = round($order_total * ($rate / 100), 2);
        if ($amount <= 0) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'order_id' => $order_id,
                'user_id' => $earner_id,
                'earner_id' => $earner_id,
                'amount' => $amount,
                'currency' => $currency,
                'commission_type' => 'referral',
                'referrer_id' => $referrer_id,
                'status' => 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%d', '%d', '%f', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private static function backfill_attribution($order_id, $customer_id, $referral) {
        $channel = get_user_meta($customer_id, '_myshop_attr_channel', true);
        $scene = get_user_meta($customer_id, '_myshop_attr_scene', true);
        $landing_page = get_user_meta($customer_id, '_myshop_attr_landing_page', true);

        if (!$channel && !$scene && !$landing_page) {
            return;
        }

        if ($referral && empty($referral->channel_code)) {
            $fallback = $channel ?: $scene;
            if ($fallback) {
                global $wpdb;
                $referral_table = $wpdb->prefix . 'myshop_referrals';
                $wpdb->update(
                    $referral_table,
                    ['channel_code' => $fallback],
                    ['invitee_id' => (int) $customer_id],
                    ['%s'],
                    ['%d']
                );
            }
        }

        $meta_map = [
            '_myshop_attr_channel' => $channel,
            '_myshop_attr_scene' => $scene,
            '_myshop_attr_landing_page' => $landing_page
        ];

        foreach ($meta_map as $key => $value) {
            if ($value && !get_post_meta($order_id, $key, true)) {
                update_post_meta($order_id, $key, $value);
            }
        }
    }

    private static function get_points_settings() {
        $defaults = [
            'enable_points' => 1,
            'enable_expiry' => 0,
            'expiry_days' => 365,
            'enable_referral_points' => 0,
            'referral_points_rate_level1' => 0,
            'referral_points_rate_level2' => 0
        ];
        $settings = get_option('myshop_points_settings', []);
        return wp_parse_args($settings, $defaults);
    }

    private static function build_points_expire_at($settings) {
        if (!empty($settings['enable_expiry']) && (int) $settings['expiry_days'] > 0) {
            return date('Y-m-d H:i:s', strtotime('+' . (int) $settings['expiry_days'] . ' days'));
        }
        return null;
    }

    private static function grant_referral_points($user_id, $order_id, $order_total, $rate, $level, $settings) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || $rate <= 0) {
            return;
        }

        $points = (int) floor($order_total * $rate);
        if ($points <= 0) {
            return;
        }

        global $wpdb;
        $ledger_table = $wpdb->prefix . 'myshop_point_ledger';
        $channel = $level === 2 ? 'referral_reward_l2' : 'referral_reward_l1';

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ledger_table}
             WHERE user_id = %d AND reference_order_id = %d AND channel = %s AND type = 'earn'",
            $user_id,
            $order_id,
            $channel
        ));
        if ($exists > 0) {
            return;
        }

        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$ledger_table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        ));

        $expire_at = self::build_points_expire_at($settings);
        $now = current_time('mysql');
        $wpdb->insert(
            $ledger_table,
            [
                'user_id' => $user_id,
                'type' => 'earn',
                'delta' => $points,
                'balance_after' => $available + $points,
                'status' => 'confirmed',
                'channel' => $channel,
                'reference_order_id' => $order_id,
                'reservation_id' => 'referral_order_' . $order_id . '_l' . (int) $level,
                'expire_at' => $expire_at,
                'created_at' => $now,
                'updated_at' => $now
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }
}
