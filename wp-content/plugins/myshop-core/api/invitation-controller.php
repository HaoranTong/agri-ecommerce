<?php

class Invitation_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/invitations/summary', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_summary'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/invitations/track', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'track'],
            'permission_callback' => '__return_true'
        ]);
    }

    public static function get_summary($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $inviter_id = (int) $user->ID;
        $referral_table = $wpdb->prefix . 'myshop_referrals';
        $commission_table = $wpdb->prefix . 'myshop_commissions';

        $total_invites = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} WHERE inviter_id = %d",
            $inviter_id
        ));

        $first_order_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} WHERE inviter_id = %d AND first_order_status = 'completed'",
            $inviter_id
        ));

        $pending_rewards = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$commission_table} WHERE earner_id = %d AND status = 'pending'",
            $inviter_id
        ));

        $latest_invite = $wpdb->get_row($wpdb->prepare(
            "SELECT invitee_id, first_order_status, created_at FROM {$referral_table} WHERE inviter_id = %d ORDER BY created_at DESC LIMIT 1",
            $inviter_id
        ));

        $response = [
            'invite_code'       => self::ensure_invite_code($inviter_id),
            'total_invites'     => $total_invites,
            'first_order_count' => $first_order_count,
            'pending_rewards'   => number_format($pending_rewards ?: 0, 2, '.', ''),
            'latest_invite'     => null
        ];

        if ($latest_invite) {
            $response['latest_invite'] = [
                'invitee_user_id'   => (int) $latest_invite->invitee_id,
                'nickname'          => self::get_user_nickname($latest_invite->invitee_id),
                'invited_at'        => mysql2date('c', $latest_invite->created_at),
                'first_order_status'=> $latest_invite->first_order_status
            ];
        }

        return rest_ensure_response($response);
    }

    public static function track($request) {
        global $wpdb;

        $params = $request->get_json_params();
        $inviter_id   = isset($params['inviter_id']) ? absint($params['inviter_id']) : null;
        $channel      = isset($params['channel']) ? sanitize_text_field($params['channel']) : null;
        $scene        = isset($params['scene']) ? sanitize_text_field($params['scene']) : null;
        $landing_page = isset($params['landing_page']) ? sanitize_text_field($params['landing_page']) : null;
        $extra        = isset($params['extra']) ? wp_json_encode($params['extra']) : null;

        $table = $wpdb->prefix . 'myshop_invitation_logs';
        $data = [
            'channel'      => $channel,
            'scene'        => $scene,
            'landing_page' => $landing_page,
            'extra'        => $extra,
            'recorded_at'  => current_time('mysql', true)
        ];
        $format = ['%s', '%s', '%s', '%s', '%s'];

        if ($inviter_id) {
            $data = ['inviter_id' => $inviter_id] + $data;
            array_unshift($format, '%d');
        }

        $inserted = $wpdb->insert($table, $data, $format);

        if ($inserted === false) {
            return new WP_Error('invite_track_failed', '记录渠道数据失败', ['status' => 500]);
        }

        return rest_ensure_response([
            'log_id'      => (int) $wpdb->insert_id,
            'recorded_at' => mysql2date('c', current_time('mysql', true))
        ]);
    }

    private static function ensure_invite_code($user_id) {
        $code = get_user_meta($user_id, 'myshop_referral_code', true);
        if (!$code) {
            $code = 'U' . $user_id . strtoupper(wp_generate_password(4, false));
            update_user_meta($user_id, 'myshop_referral_code', $code);
        }
        return $code;
    }

    private static function get_user_nickname($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return '';
        }
        return $user->display_name ?: $user->user_login;
    }
}
