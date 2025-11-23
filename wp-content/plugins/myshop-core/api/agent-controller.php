<?php

class Agent_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/agents/profile', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_profile'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/agents/apply', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'apply'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'region' => ['required' => false, 'type' => 'string'],
                'parent_agent_code' => ['required' => false, 'type' => 'string'],
                'team_target' => ['required' => false],
                'level' => ['required' => false, 'type' => 'integer']
            ]
        ]);

        register_rest_route('myshop/v1', '/agents/team-stats', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_team_stats'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
    }

    public static function get_profile($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $agent_table = $wpdb->prefix . 'myshop_agents';
        $agent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$agent_table} WHERE agent_user_id = %d LIMIT 1",
            $user->ID
        ));

        if (!$agent) {
            return new WP_Error('agent_not_found', '您还不是代理商', ['status' => 404]);
        }

        $team_size = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$agent_table} WHERE parent_agent_id = %d",
            $agent->id
        ));

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'agent_code'       => $agent->agent_code,
                'status'           => $agent->status,
                'level'            => (int) $agent->level,
                'region'           => $agent->region,
                'joined_at'        => $agent->joined_at,
                'invite_qr'        => $agent->invite_qr,
                'team_target'      => $agent->team_target ? json_decode($agent->team_target, true) : null,
                'team_size'        => $team_size,
                'parent_agent_id'  => $agent->parent_agent_id ? (int) $agent->parent_agent_id : null
            ]
        ]);
    }

    public static function apply($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $agent_table = $wpdb->prefix . 'myshop_agents';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$agent_table} WHERE agent_user_id = %d LIMIT 1",
            $user->ID
        ));

        if ($existing) {
            if ($existing->status === 'terminated') {
                // 允许重新激活
                $wpdb->update(
                    $agent_table,
                    [
                        'status' => 'active',
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $existing->id],
                    ['%s', '%s'],
                    ['%d']
                );
            }

            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'agent_code' => $existing->agent_code,
                    'status'     => $existing->status
                ],
                'message' => '已存在代理商记录'
            ]);
        }

        $params = $request->get_json_params();
        $region = isset($params['region']) ? sanitize_text_field($params['region']) : '';
        $level = isset($params['level']) ? max(1, absint($params['level'])) : 1;
        $target = isset($params['team_target']) ? wp_json_encode($params['team_target']) : null;
        $parent_code = isset($params['parent_agent_code']) ? sanitize_text_field($params['parent_agent_code']) : '';

        $parent_agent_id = null;
        if ($parent_code) {
            $parent_agent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$agent_table} WHERE agent_code = %s",
                $parent_code
            ));
            if (!$parent_agent_id) {
                return new WP_Error('parent_agent_not_found', '上级代理不存在', ['status' => 404]);
            }
        }

        $agent_code = self::generate_unique_agent_code();
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $agent_table,
            [
                'agent_user_id'   => $user->ID,
                'parent_agent_id' => $parent_agent_id !== null ? (int) $parent_agent_id : null,
                'agent_code'      => $agent_code,
                'level'           => $level,
                'region'          => $region ?: null,
                'status'          => 'active',
                'joined_at'       => $now,
                'invite_qr'       => null,
                'team_target'     => $target,
                'created_at'      => $now,
                'updated_at'      => $now
            ],
                ['%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('agent_apply_failed', '代理商创建失败', ['status' => 500]);
        }

        self::log_agent_action($user->ID, 'apply', '用户申请成为代理商', $user->ID, [
            'agent_code' => $agent_code,
            'region'     => $region,
            'level'      => $level
        ]);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'agent_code' => $agent_code,
                'status'     => 'active'
            ]
        ]);
    }

    public static function get_team_stats($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $agent_table = $wpdb->prefix . 'myshop_agents';
        $agent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$agent_table} WHERE agent_user_id = %d LIMIT 1",
            $user->ID
        ));

        if (!$agent) {
            return new WP_Error('agent_not_found', '您还不是代理商', ['status' => 404]);
        }

        $direct_agents = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$agent_table} WHERE parent_agent_id = %d",
            $agent->id
        ));

        $commission_table = $wpdb->prefix . 'myshop_commissions';
        $pending_commission = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$commission_table} WHERE commission_type = 'agent' AND agent_id = %d AND status = 'pending'",
            $user->ID
        ));

        $paid_commission = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$commission_table} WHERE commission_type = 'agent' AND agent_id = %d AND status = 'paid'",
            $user->ID
        ));

        $recent_members = $wpdb->get_results($wpdb->prepare(
            "SELECT agent_user_id, level, joined_at FROM {$agent_table} WHERE parent_agent_id = %d ORDER BY joined_at DESC LIMIT 5",
            $agent->id
        ));

        $members = [];
        foreach ($recent_members as $row) {
            $member_user = get_userdata($row->agent_user_id);
            $members[] = [
                'user_id'   => (int) $row->agent_user_id,
                'nickname'  => $member_user ? ($member_user->display_name ?: $member_user->user_login) : '',
                'level'     => (int) $row->level,
                'joined_at' => $row->joined_at
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'direct_agents'       => $direct_agents,
                'pending_commission'  => number_format($pending_commission, 2, '.', ''),
                'paid_commission'     => number_format($paid_commission, 2, '.', ''),
                'recent_team_members' => $members
            ]
        ]);
    }

    private static function generate_unique_agent_code() {
        global $wpdb;
        $agent_table = $wpdb->prefix . 'myshop_agents';

        do {
            $code = 'AG' . strtoupper(wp_generate_password(6, false));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$agent_table} WHERE agent_code = %s",
                $code
            ));
        } while ($exists);

        return $code;
    }

    private static function log_agent_action($agent_user_id, $action, $reason, $operator_id, $payload = []) {
        global $wpdb;
        $log_table = $wpdb->prefix . 'myshop_agent_audit_logs';

        $wpdb->insert(
            $log_table,
            [
                'agent_user_id' => $agent_user_id,
                'action'        => $action,
                'reason'        => $reason,
                'operator_id'   => $operator_id,
                'payload'       => $payload ? wp_json_encode($payload) : null,
                'created_at'    => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );
    }
}
