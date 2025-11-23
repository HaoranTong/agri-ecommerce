<?php

class Agent_Controller {
    const DEFAULT_CONTRACT_DAYS = 365;

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
                'level' => ['required' => false, 'type' => 'integer'],
                'active_days' => ['required' => false, 'type' => 'integer']
            ]
        ]);

        register_rest_route('myshop/v1', '/agents/team-stats', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_team_stats'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'agent_code' => ['required' => false, 'type' => 'string']
            ]
        ]);
    }

    public static function get_profile($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        self::sync_agent_active_flags();

        $agent_table = $wpdb->prefix . 'myshop_agents';
        $agents = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$agent_table} WHERE agent_user_id = %d ORDER BY level DESC, joined_at DESC",
            $user->ID
        ));

        if (!$agents) {
            return new WP_Error('agent_not_found', '您还不是代理商', ['status' => 404]);
        }

        $primary_agent = $agents[0];
        $team_size = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$agent_table} WHERE parent_agent_id = %d",
            $primary_agent->id
        ));

        $assignments = array_map(static function ($record) {
            return [
                'agent_code'      => $record->agent_code,
                'level'           => (int) $record->level,
                'region_zone'     => $record->region_zone,
                'region_province' => $record->region_province,
                'region_city'     => $record->region_city,
                'status'          => $record->status,
                'is_active'       => (bool) $record->is_active,
                'active_until'    => $record->active_until,
                'joined_at'       => $record->joined_at
            ];
        }, $agents);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'agent_code'       => $primary_agent->agent_code,
                'status'           => $primary_agent->status,
                'is_active'        => (bool) $primary_agent->is_active,
                'active_until'     => $primary_agent->active_until,
                'level'            => (int) $primary_agent->level,
                'region_zone'      => $primary_agent->region_zone,
                'region_province'  => $primary_agent->region_province,
                'region_city'      => $primary_agent->region_city,
                'region'           => $primary_agent->region ?: self::combine_region($primary_agent->region_zone, $primary_agent->region_province, $primary_agent->region_city),
                'joined_at'        => $primary_agent->joined_at,
                'invite_qr'        => $primary_agent->invite_qr,
                'team_target'      => $primary_agent->team_target ? json_decode($primary_agent->team_target, true) : null,
                'team_size'        => $team_size,
                'parent_agent_id'  => $primary_agent->parent_agent_id ? (int) $primary_agent->parent_agent_id : null,
                'assignments'      => $assignments
            ]
        ]);
    }

    public static function apply($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        self::sync_agent_active_flags();

        $agent_table = $wpdb->prefix . 'myshop_agents';
        $params = $request->get_json_params();
        $region_zone = isset($params['region_zone']) ? sanitize_text_field($params['region_zone']) : '';
        $region_province_input = isset($params['region_province']) ? sanitize_text_field($params['region_province']) : '';
        $region_city_input = isset($params['region_city']) ? sanitize_text_field($params['region_city']) : '';
        $region_province = $region_province_input !== '' ? $region_province_input : null;
        $region_city = $region_city_input !== '' ? $region_city_input : null;
        $region = isset($params['region']) ? sanitize_text_field($params['region']) : '';
        $level = isset($params['level']) ? max(1, absint($params['level'])) : 1;
        $target = isset($params['team_target']) ? wp_json_encode($params['team_target']) : null;
        $parent_code = isset($params['parent_agent_code']) ? sanitize_text_field($params['parent_agent_code']) : '';
        $active_days = isset($params['active_days']) ? max(30, absint($params['active_days'])) : self::DEFAULT_CONTRACT_DAYS;
        $now_timestamp = current_time('timestamp');
        $active_until = date_i18n('Y-m-d H:i:s', $now_timestamp + ($active_days * DAY_IN_SECONDS));

        if ($region_zone === '') {
            return new WP_Error('invalid_region_zone', '请选择代理大区', ['status' => 400]);
        }

        if ($region_province === null && $region_city !== null) {
            return new WP_Error('invalid_region_province', '请选择代理省份', ['status' => 400]);
        }

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

        if ($parent_agent_id === null) {
            $parent_agent_id = self::auto_assign_parent_id($region_zone, $region_province, $region_city);
        }

        $region_key = method_exists('MyShop_DB', 'build_region_key')
            ? MyShop_DB::build_region_key($region_zone, $region_province, $region_city)
            : strtolower(($region_zone ?: '__') . '#' . ($region_province ?: '__') . '#' . ($region_city ?: '__'));

        $region_conflict = $wpdb->get_var($wpdb->prepare(
            "SELECT agent_code FROM {$agent_table} WHERE region_key = %s AND status IN ('active','pending','frozen') AND is_active = 1 LIMIT 1",
            $region_key
        ));

        if ($region_conflict) {
            return new WP_Error('region_occupied', '该区域已有代理，请选择其他区域', ['status' => 409]);
        }

        $agent_code = self::generate_unique_agent_code();
        $now = current_time('mysql');
        $region_label = $region ?: self::combine_region($region_zone, $region_province, $region_city);

        $inserted = $wpdb->insert(
            $agent_table,
            [
                'agent_user_id'    => $user->ID,
                'parent_agent_id'  => $parent_agent_id !== null ? (int) $parent_agent_id : null,
                'agent_code'       => $agent_code,
                'level'            => $level,
                'region_zone'      => $region_zone,
                'region_province'  => $region_province,
                'region_city'      => $region_city,
                'region'           => $region_label ?: null,
                'region_key'       => $region_key,
                'active_until'     => $active_until,
                'is_active'        => 1,
                'status'           => 'active',
                'joined_at'        => $now,
                'invite_qr'        => null,
                'team_target'      => $target,
                'created_at'       => $now,
                'updated_at'       => $now
            ],
            ['%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('agent_apply_failed', '代理商创建失败', ['status' => 500]);
        }

        if ($region_province !== null && $region_city === null) {
            self::refresh_child_bindings($region_zone, $region_province);
        }

        self::log_agent_action($user->ID, 'apply', '用户申请成为代理商', $user->ID, [
            'agent_code'     => $agent_code,
            'region_zone'    => $region_zone,
            'region_province'=> $region_province,
            'region_city'    => $region_city,
            'region'         => $region_label,
            'level'          => $level
        ]);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'agent_code'      => $agent_code,
                'status'          => 'active',
                'active_until'    => $active_until,
                'is_active'       => true,
                'parent_agent_id' => $parent_agent_id !== null ? (int) $parent_agent_id : null
            ]
        ]);
    }

    public static function get_team_stats($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        self::sync_agent_active_flags();

        $agent_table = $wpdb->prefix . 'myshop_agents';
        $agent_code = $request->get_param('agent_code') ? sanitize_text_field($request->get_param('agent_code')) : '';

        if ($agent_code !== '') {
            $agent = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$agent_table} WHERE agent_user_id = %d AND agent_code = %s LIMIT 1",
                $user->ID,
                $agent_code
            ));
        } else {
            $agent = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$agent_table} WHERE agent_user_id = %d ORDER BY level DESC, joined_at DESC LIMIT 1",
                $user->ID
            ));
        }

        if (!$agent) {
            return new WP_Error('agent_not_found', '您还不是代理商', ['status' => 404]);
        }

        $descendants = self::collect_descendant_agents($agent->id);
        $direct_agents = 0;
        $members = [];

        foreach ($descendants as $row) {
            if ((int) $row->parent_agent_id === (int) $agent->id) {
                $direct_agents++;
            }

            $member_user = get_userdata($row->agent_user_id);
            $members[] = [
                'user_id'   => (int) $row->agent_user_id,
                'nickname'  => $member_user ? ($member_user->display_name ?: $member_user->user_login) : '',
                'level'     => (int) $row->level,
                'joined_at' => $row->joined_at,
                'depth'     => (int) $row->depth
            ];
        }

        usort($members, static function ($a, $b) {
            return strcmp($b['joined_at'], $a['joined_at']);
        });

        $recent_members = array_slice($members, 0, 5);
        $team_total_agents = count($descendants);
        $indirect_agents = max(0, $team_total_agents - $direct_agents);

        $commission_table = $wpdb->prefix . 'myshop_commissions';
        $pending_commission = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$commission_table} WHERE commission_type = 'agent' AND agent_id = %d AND status = 'pending'",
            $user->ID
        ));

        $paid_commission = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$commission_table} WHERE commission_type = 'agent' AND agent_id = %d AND status = 'paid'",
            $user->ID
        ));

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'agent_code'        => $agent->agent_code,
                'direct_agents'       => $direct_agents,
                'indirect_agents'     => $indirect_agents,
                'team_total_agents'   => $team_total_agents,
                'pending_commission'  => number_format($pending_commission, 2, '.', ''),
                'paid_commission'     => number_format($paid_commission, 2, '.', ''),
                'recent_team_members' => $members ? $recent_members : []
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

    private static function combine_region($zone, $province, $city) {
        $parts = array_filter([
            $zone ?: null,
            $province ?: null,
            $city ?: null
        ], static function ($part) {
            return $part !== null && $part !== '';
        });

        return $parts ? implode(' ', $parts) : null;
    }

    private static function auto_assign_parent_id($zone, $province, $city) {
        if ($city === null) {
            return null;
        }

        return self::find_active_agent_id($zone, $province, null);
    }

    private static function find_active_agent_id($zone, $province = null, $city = null) {
        global $wpdb;
        $agent_table = $wpdb->prefix . 'myshop_agents';

        $conditions = ['is_active = 1'];
        $params = [];

        $conditions[] = 'region_zone = %s';
        $params[] = $zone;

        if ($province === null) {
            $conditions[] = 'region_province IS NULL';
        } else {
            $conditions[] = 'region_province = %s';
            $params[] = $province;
        }

        if ($city === null) {
            $conditions[] = 'region_city IS NULL';
        } else {
            $conditions[] = 'region_city = %s';
            $params[] = $city;
        }

        $sql = "SELECT id FROM {$agent_table} WHERE " . implode(' AND ', $conditions) . " ORDER BY joined_at DESC LIMIT 1";

        $result = $wpdb->get_var($wpdb->prepare($sql, ...$params));

        return $result ? (int) $result : null;
    }

    private static function refresh_child_bindings($zone, $province) {
        global $wpdb;
        $agent_table = $wpdb->prefix . 'myshop_agents';

        if ($province === null) {
            return;
        }

        $parent_id = self::find_active_agent_id($zone, $province, null);

        if ($parent_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$agent_table} SET parent_agent_id = %d WHERE region_zone = %s AND region_province = %s AND region_city IS NOT NULL",
                $parent_id,
                $zone,
                $province
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$agent_table} SET parent_agent_id = NULL WHERE region_zone = %s AND region_province = %s AND region_city IS NOT NULL",
                $zone,
                $province
            ));
        }
    }

    private static function collect_descendant_agents($root_agent_id) {
        global $wpdb;
        $agent_table = $wpdb->prefix . 'myshop_agents';

        $queue = [$root_agent_id];
        $depth_map = [$root_agent_id => 0];
        $descendants = [];

        while (!empty($queue)) {
            $placeholders = implode(',', array_fill(0, count($queue), '%d'));
            $sql = "SELECT id, agent_user_id, parent_agent_id, level, joined_at FROM {$agent_table} WHERE parent_agent_id IN ({$placeholders})";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$queue));
            $queue = [];

            foreach ($rows as $row) {
                $parent_id = (int) $row->parent_agent_id;
                $depth = (isset($depth_map[$parent_id]) ? $depth_map[$parent_id] : 0) + 1;
                $row->depth = $depth;
                $descendants[] = $row;
                $queue[] = (int) $row->id;
                $depth_map[(int) $row->id] = $depth;
            }
        }

        return $descendants;
    }

    private static function sync_agent_active_flags() {
        global $wpdb;
        $agent_table = $wpdb->prefix . 'myshop_agents';
        $now = current_time('mysql');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$agent_table} SET is_active = 0 WHERE is_active = 1 AND active_until IS NOT NULL AND active_until < %s",
            $now
        ));

        $inactive_parents = $wpdb->get_results(
            "SELECT DISTINCT region_zone, region_province FROM {$agent_table} WHERE is_active = 0 AND region_city IS NULL AND region_province IS NOT NULL"
        );

        foreach ($inactive_parents as $row) {
            self::refresh_child_bindings($row->region_zone, $row->region_province);
        }

        self::repair_orphan_city_agents();
    }

    private static function repair_orphan_city_agents() {
        global $wpdb;
        $agent_table = $wpdb->prefix . 'myshop_agents';

        $orphans = $wpdb->get_results(
            "SELECT id, region_zone, region_province FROM {$agent_table} WHERE parent_agent_id IS NULL AND region_city IS NOT NULL AND region_province IS NOT NULL"
        );

        foreach ($orphans as $orphan) {
            $parent_id = self::find_active_agent_id($orphan->region_zone, $orphan->region_province, null);
            if ($parent_id) {
                $wpdb->update(
                    $agent_table,
                    ['parent_agent_id' => $parent_id],
                    ['id' => $orphan->id],
                    ['%d'],
                    ['%d']
                );
            }
        }
    }
}
