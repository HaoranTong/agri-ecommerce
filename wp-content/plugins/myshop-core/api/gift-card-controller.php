<?php

class Gift_Card_Controller {
    public static function register_routes() {
        register_rest_route('myshop/v1', '/gift-cards/templates', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_templates'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('myshop/v1', '/gift-cards/templates/(?P<id>\\d+)', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_template'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['required' => true, 'type' => 'integer']
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_cards'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);

        register_rest_route('myshop/v1', '/gift-cards/purchase', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'purchase_card'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'template_id'   => ['required' => true, 'type' => 'integer'],
                'delivery_mode' => ['required' => false, 'type' => 'string'],
                'remark'        => ['required' => false, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards/share', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'share_card'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'card_number'  => ['required' => true, 'type' => 'string'],
                'delivery_mode'=> ['required' => true, 'type' => 'string'],
                'channel'      => ['required' => false, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards/share/(?P<token>[A-Za-z0-9]+)', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_share_detail'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => ['required' => true]
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards/share/(?P<token>[A-Za-z0-9]+)/claim', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'claim_shared_card'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'token' => ['required' => true]
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards/redeem', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'redeem_card'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'card_number' => ['required' => true, 'type' => 'string'],
                'card_pin'    => ['required' => true, 'type' => 'string']
            ]
        ]);
    }

    public static function list_cards($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $table = $wpdb->prefix . 'myshop_gift_cards';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE purchaser_id = %d OR redeemer_id = %d ORDER BY created_at DESC",
            $user->ID,
            $user->ID
        ));

        $cards = [];
        foreach ($rows as $row) {
            $cards[] = [
                'card_number'   => $row->card_number,
                'status'        => $row->status,
                'bind_status'   => $row->bind_status,
                'template_type' => $row->template_type,
                'initial_amount'=> $row->initial_amount !== null ? (string) $row->initial_amount : null,
                'balance'       => $row->balance !== null ? (string) $row->balance : null,
                'expires_at'    => $row->expires_at,
                'redeemer_id'   => $row->redeemer_id ? (int) $row->redeemer_id : null,
                'purchaser_id'  => (int) $row->purchaser_id,
                'created_at'    => $row->created_at,
                'updated_at'    => $row->updated_at
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $cards
        ]);
    }

    public static function redeem_card($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $params = $request->get_json_params();
        $card_number = isset($params['card_number']) ? sanitize_text_field($params['card_number']) : '';
        $card_pin    = isset($params['card_pin']) ? sanitize_text_field($params['card_pin']) : '';

        if ($card_number === '' || $card_pin === '') {
            return new WP_Error('invalid_payload', '礼品卡号或密码缺失', ['status' => 400]);
        }

        $table = $wpdb->prefix . 'myshop_gift_cards';
        $card  = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE card_number = %s LIMIT 1",
            $card_number
        ));

        if (!$card) {
            return new WP_Error('card_not_found', '礼品卡不存在', ['status' => 404]);
        }

        if ($card->status !== 'active') {
            return new WP_Error('card_inactive', '礼品卡不可用', ['status' => 400]);
        }

            if ($card->redeemer_id && (int) $card->redeemer_id !== (int) $user->ID) {
            return new WP_Error('card_already_redeemed', '礼品卡已被使用', ['status' => 400]);
        }

            if (!$card->redeemer_id && $card->bind_status !== 'bound' && (int) $card->purchaser_id !== (int) $user->ID) {
                return new WP_Error('card_not_claimed', '请先领取礼品卡再兑换', ['status' => 400]);
            }

        if (strtotime($card->expires_at) < current_time('timestamp')) {
            return new WP_Error('card_expired', '礼品卡已过期', ['status' => 400]);
        }

        $pin_valid = false;
        if (!empty($card->pin_code_hash)) {
            if (wp_check_password($card_pin, $card->pin_code_hash)) {
                $pin_valid = true;
            } elseif (hash_equals($card->pin_code_hash, $card_pin)) {
                $pin_valid = true;
            }
        } else {
            $pin_valid = true;
        }

        if (!$pin_valid) {
            return new WP_Error('card_invalid_pin', '礼品卡密码错误', ['status' => 401]);
        }

        $now = current_time('mysql', true);

        $update_data = [
            'redeemer_id' => $user->ID,
            'status'      => 'redeemed',
            'bind_status' => 'bound',
            'balance'     => 0,
            'updated_at'  => $now
        ];

        $known_columns = $wpdb->get_col("DESC {$table}", 0);
        if (in_array('card_code', $known_columns, true) && empty($card->card_code)) {
            $update_data['card_code'] = $card->card_number;
        }
        if (in_array('user_id', $known_columns, true) && empty($card->user_id)) {
            $update_data['user_id'] = $card->purchaser_id;
        }

        $update_formats = [];
        foreach ($update_data as $column => $value) {
            switch ($column) {
                case 'redeemer_id':
                case 'user_id':
                    $update_formats[] = '%d';
                    break;
                case 'balance':
                    $update_formats[] = '%f';
                    break;
                default:
                    $update_formats[] = '%s';
            }
        }

        $updated = $wpdb->update(
            $table,
            $update_data,
            ['id' => $card->id],
            $update_formats,
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error('card_redeem_failed', '礼品卡状态更新失败: ' . ($wpdb->last_error ?: 'unknown'), ['status' => 500]);
        }

        $redemptions_table = $wpdb->prefix . 'myshop_gift_card_redemptions';
        $used_amount = $card->balance !== null ? (float) $card->balance : ($card->initial_amount !== null ? (float) $card->initial_amount : 0.0);

        $wpdb->insert(
            $redemptions_table,
            [
                'card_id'       => $card->id,
                'template_id'   => $card->template_id,
                'redeemer_id'   => $user->ID,
                'redeem_type'   => $card->template_type === 'fixed_amount' ? 'deduct' : 'exchange',
                'channel'       => 'miniprogram',
                'used_amount'   => $used_amount,
                'balance_after' => 0,
                'redeemed_at'   => current_time('mysql', true)
            ],
            ['%d','%d','%d','%s','%s','%f','%f','%s']
        );

        return rest_ensure_response([
            'success' => true,
            'message' => '礼品卡兑换成功',
            'data'    => [
                'card_number' => $card->card_number,
                'status'      => 'redeemed'
            ]
        ]);
    }

    public static function list_templates($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'myshop_gift_card_templates';
        $rows = $wpdb->get_results(
            "SELECT id, name, type, fixed_amount, currency, product_id, variation_ids, bundle_items, delivery_modes, share_template_config, print_template_url, valid_days, created_at, updated_at
             FROM {$table}
             ORDER BY id DESC"
        );

        $templates = [];
        foreach ($rows as $row) {
            $templates[] = self::format_template($row);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $templates
        ]);
    }

    public static function get_template($request) {
        $template = self::get_template_row(absint($request->get_param('id')));
        if (!$template) {
            return new WP_Error('template_not_found', '礼品卡模板不存在', ['status' => 404]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => self::format_template($template, true)
        ]);
    }

    public static function purchase_card($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $params = $request->get_json_params();
        $template_id = isset($params['template_id']) ? absint($params['template_id']) : 0;
        if (!$template_id) {
            return new WP_Error('invalid_template', '未知的礼品卡模板', ['status' => 400]);
        }

        $template = self::get_template_row($template_id);
        if (!$template) {
            return new WP_Error('template_not_found', '礼品卡模板不存在', ['status' => 404]);
        }

        $now = current_time('mysql', true);
        $card_number = self::generate_unique_card_number();
        $pin_plain = wp_generate_password(6, false, false);
        $pin_hash  = wp_hash_password($pin_plain);
        $expires_at = gmdate('Y-m-d H:i:s', strtotime($now . ' +' . (int) $template->valid_days . ' days'));

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';

        $insert_data = [
            'card_number'         => $card_number,
            'template_id'         => $template->id,
            'template_type'       => $template->type,
            'initial_amount'      => $template->fixed_amount,
            'balance'             => $template->fixed_amount,
            'currency'            => $template->currency,
            'linked_product_id'   => $template->product_id ?: null,
            'linked_variation_ids'=> $template->variation_ids,
            'bundle_config'       => $template->bundle_items,
            'purchaser_id'        => $user->ID,
            'status'              => 'active',
            'bind_status'         => 'unbound',
            'share_token'         => null,
            'share_channel'       => null,
            'share_token_expires_at' => null,
            'print_package_url'   => $template->print_template_url,
            'pin_code_hash'       => $pin_hash,
            'pin_reveal_limit'    => 3,
            'pin_reveal_count'    => 0,
            'expires_at'          => $expires_at,
            'created_at'          => $now,
            'updated_at'          => $now
        ];

        $known_columns = $wpdb->get_col("DESC {$cards_table}", 0);
        if (in_array('card_code', $known_columns, true)) {
            $insert_data['card_code'] = $card_number;
        }
        if (in_array('amount', $known_columns, true)) {
            $insert_data['amount'] = $template->fixed_amount;
        }
        if (in_array('user_id', $known_columns, true)) {
            $insert_data['user_id'] = $user->ID;
        }

        $insert_formats = [];
        foreach (array_keys($insert_data) as $column) {
            switch ($column) {
                case 'template_id':
                case 'purchaser_id':
                case 'pin_reveal_limit':
                case 'pin_reveal_count':
                case 'user_id':
                    $insert_formats[] = '%d';
                    break;
                case 'initial_amount':
                case 'balance':
                case 'amount':
                    $insert_formats[] = '%f';
                    break;
                case 'created_at':
                case 'updated_at':
                case 'expires_at':
                case 'share_token_expires_at':
                    $insert_formats[] = '%s';
                    break;
                default:
                    $insert_formats[] = '%s';
            }
        }

        $inserted = $wpdb->insert(
            $cards_table,
            $insert_data,
            $insert_formats
        );

        if ($inserted === false) {
            return new WP_Error('card_create_failed', sprintf('礼品卡生成失败: %s', $wpdb->last_error ?: 'unknown error'), ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'card_number' => $card_number,
                'card_pin'    => $pin_plain,
                'template'    => self::format_template($template, true)
            ]
        ]);
    }

    public static function share_card($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $params = $request->get_json_params();
        $card_number = isset($params['card_number']) ? sanitize_text_field($params['card_number']) : '';
        $delivery_mode = isset($params['delivery_mode']) ? sanitize_text_field($params['delivery_mode']) : 'digital_share';
        $channel = isset($params['channel']) ? sanitize_text_field($params['channel']) : 'miniprogram';

        $card = self::get_card_by_number($card_number);
        if (!$card) {
            return new WP_Error('card_not_found', '礼品卡不存在', ['status' => 404]);
        }

        if ((int) $card->purchaser_id !== (int) $user->ID) {
            return new WP_Error('card_forbidden', '无权分享该礼品卡', ['status' => 403]);
        }

        if ($card->status !== 'active') {
            return new WP_Error('card_invalid', '仅可分享状态为可用的礼品卡', ['status' => 400]);
        }

        $token  = self::generate_share_token();
        $expiry = date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' +7 days'));

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $updated = $wpdb->update(
            $cards_table,
            [
                'share_token'            => $token,
                'share_channel'          => $channel,
                'share_token_expires_at' => $expiry,
                'bind_status'            => 'unbound'
            ],
            ['id' => $card->id],
            ['%s','%s','%s','%s'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error('share_failed', '礼品卡分享失败', ['status' => 500]);
        }

        self::log_share_event((int) $card->id, (int) $user->ID, $delivery_mode, $channel, $token);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'share_token'   => $token,
                'delivery_mode' => $delivery_mode,
                'channel'       => $channel,
                'expires_at'    => $expiry
            ]
        ]);
    }

    public static function get_share_detail($request) {
        $token = sanitize_text_field($request->get_param('token'));
        $card = self::get_card_by_share_token($token);
        if (!$card) {
            return new WP_Error('share_not_found', '分享链接已失效', ['status' => 404]);
        }

        if ($card->share_token_expires_at && strtotime($card->share_token_expires_at) < current_time('timestamp')) {
            return new WP_Error('share_expired', '分享链接已过期', ['status' => 410]);
        }

        $template = self::get_template_row((int) $card->template_id);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'card_number'       => $card->card_number,
                'template_id'       => (int) $card->template_id,
                'template_type'     => $card->template_type,
                'initial_amount'    => $card->initial_amount,
                'balance'           => $card->balance,
                'expires_at'        => $card->expires_at,
                'share_channel'     => $card->share_channel,
                'share_token_expires_at' => $card->share_token_expires_at,
                'status'            => $card->status,
                'bind_status'       => $card->bind_status,
                'template'          => $template ? self::format_template($template, true) : null
            ]
        ]);
    }

    public static function claim_shared_card($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $token = sanitize_text_field($request->get_param('token'));
        $card = self::get_card_by_share_token($token);
        if (!$card) {
            return new WP_Error('share_not_found', '分享链接不存在', ['status' => 404]);
        }

        if ($card->share_token_expires_at && strtotime($card->share_token_expires_at) < current_time('timestamp')) {
            return new WP_Error('share_expired', '分享链接已过期', ['status' => 410]);
        }

        if ($card->bind_status === 'bound' || $card->redeemer_id) {
            return new WP_Error('card_already_bound', '礼品卡已经被领取', ['status' => 400]);
        }

        if ((int) $card->purchaser_id === (int) $user->ID) {
            return new WP_Error('card_self_claim', '不可领取自己分享的礼品卡', ['status' => 400]);
        }

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $updated = $wpdb->update(
            $cards_table,
            [
                'redeemer_id' => $user->ID,
                'bind_status' => 'bound',
                'share_token' => null,
                'share_token_expires_at' => current_time('mysql'),
                'updated_at'  => current_time('mysql')
            ],
            ['id' => $card->id],
            ['%d','%s','%s','%s','%s'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error('card_claim_failed', '领取礼品卡失败', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'card_number' => $card->card_number,
                'status'      => 'bound'
            ]
        ]);
    }

    private static function format_template($row, $include_config = false) {
        return [
            'id'                 => (int) $row->id,
            'name'               => $row->name,
            'type'               => $row->type,
            'fixed_amount'       => $row->fixed_amount !== null ? number_format((float) $row->fixed_amount, 2, '.', '') : null,
            'currency'           => $row->currency,
            'product_id'         => $row->product_id ? (int) $row->product_id : null,
            'variation_ids'      => $row->variation_ids ? array_map('intval', array_filter(explode(',', $row->variation_ids))) : [],
            'bundle_items'       => $row->bundle_items ? json_decode($row->bundle_items, true) : null,
            'delivery_modes'     => $row->delivery_modes ? json_decode($row->delivery_modes, true) : [],
            'valid_days'         => (int) $row->valid_days,
            'created_at'         => $row->created_at,
            'updated_at'         => $row->updated_at,
            'share_template_config' => $include_config && !empty($row->share_template_config) ? json_decode($row->share_template_config, true) : null,
            'print_template_url' => $include_config ? ($row->print_template_url ?: null) : null
        ];
    }

    private static function get_template_row($id) {
        global $wpdb;
        if (!$id) {
            return null;
        }
        $table = $wpdb->prefix . 'myshop_gift_card_templates';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));
    }

    private static function generate_unique_card_number() {
        global $wpdb;
        $table = $wpdb->prefix . 'myshop_gift_cards';

        do {
            $number = 'GC' . date('Ymd') . strtoupper(wp_generate_password(6, false));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE card_number = %s",
                $number
            ));
        } while ($exists);

        return $number;
    }

    private static function get_card_by_number($card_number) {
        global $wpdb;
        $table = $wpdb->prefix . 'myshop_gift_cards';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE card_number = %s",
            $card_number
        ));
    }

    private static function get_card_by_share_token($token) {
        global $wpdb;
        $table = $wpdb->prefix . 'myshop_gift_cards';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE share_token = %s",
            $token
        ));
    }

    private static function generate_share_token() {
        return strtoupper(wp_generate_password(10, false));
    }

    private static function log_share_event($card_id, $operator_id, $mode, $channel, $token) {
        global $wpdb;
        $table = $wpdb->prefix . 'myshop_gift_card_share_logs';
        $wpdb->insert(
            $table,
            [
                'card_id'        => $card_id,
                'operator_id'    => $operator_id,
                'delivery_mode'  => $mode,
                'channel'        => $channel,
                'share_token'    => $token,
                'print_package_url' => null,
                'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? '',
                'created_at'     => current_time('mysql')
            ],
            ['%d','%d','%s','%s','%s','%s','%s','%s']
        );
    }
}
