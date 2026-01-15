<?php

class Gift_Card_Controller {
    private const DEFAULT_DELIVERY_MODES = ['digital_share', 'printable'];
    private const SHARE_TOKEN_TTL_DAYS = 7;
    private const QR_SCHEME = 'myshop://giftcard';
    private const DEFAULT_SHARE_THEME = 'default';
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
                'delivery_mode'=> ['required' => false, 'type' => 'string'],
                'channel'      => ['required' => false, 'type' => 'string'],
                'message'      => ['required' => false, 'type' => 'string'],
                'theme'        => ['required' => false, 'type' => 'string'],
                'format'       => ['required' => false, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards/share/(?P<card_number>[A-Za-z0-9\-]+)/revoke', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'revoke_share_card'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'card_number' => ['required' => true, 'type' => 'string']
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

        register_rest_route('myshop/v1', '/gift-cards/share/(?P<token>[A-Za-z0-9]+)/redeem', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'claim_shared_card'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'token' => ['required' => true]
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards/(?P<card_number>[A-Za-z0-9\-]+)/share-history', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_share_history'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'card_number' => ['required' => true, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards/redeem', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'redeem_card'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'card_number' => ['required' => true, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/gift-cards/share-styles', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'list_share_styles'],
            'permission_callback' => '__return_true'
        ]);
    }

    public static function boot() {
        add_action('woocommerce_order_status_completed', [self::class, 'handle_order_status_completed'], 10, 1);
        add_action('woocommerce_order_status_processing', [self::class, 'handle_order_status_completed'], 10, 1);
        // 监听订单状态变化，确保从任何状态变为 completed 时都能激活购物卡
        add_action('woocommerce_order_status_changed', [self::class, 'handle_order_status_changed'], 10, 4);
    }

    /**
     * 处理订单状态变化（从任何状态变为 completed）
     * 确保订单完成时，该订单关联的所有购物卡都是 active 状态
     */
    public static function handle_order_status_changed($order_id, $old_status, $new_status, $order) {
        // 只处理状态变为 completed 的情况
        if ($new_status !== 'completed') {
            return;
        }

        $is_gift_order = $order->get_meta('_myshop_is_gift_card_order');
        if (empty($is_gift_order)) {
            return;
        }

        // 激活该订单关联的所有 pending_activation 或 locked 状态的购物卡
        $activated = self::activate_cards_by_order_id($order_id);
        if ($activated > 0) {
            $order->add_order_note(sprintf('订单状态变为已完成，已激活该订单关联的购物卡 %d 张', $activated));
        } else {
            // 即使没有激活，也记录日志以便调试
            error_log(sprintf('[GiftCard] Order #%d status changed to completed, but no cards were activated. Old status: %s', $order_id, $old_status));
        }
    }

    public static function handle_order_status_completed($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $is_gift_order = $order->get_meta('_myshop_is_gift_card_order');
        if (empty($is_gift_order)) {
            return;
        }

        $already_issued = $order->get_meta('_myshop_issued_gift_cards');
        if (!empty($already_issued)) {
            if ($order->has_status('completed')) {
                $activated = self::activate_pending_cards($already_issued);
                if ($activated > 0) {
                    $order->add_order_note(sprintf('礼品卡已激活 %d 张', $activated));
                }
                // 额外检查：如果订单已完成，确保该订单关联的所有购物卡都是 active 状态
                // 这可以处理购物卡被手动锁定后，订单状态再次变为 completed 的情况
                $additional_activated = self::activate_cards_by_order_id($order_id);
                if ($additional_activated > 0) {
                    $order->add_order_note(sprintf('额外激活该订单关联的购物卡 %d 张', $additional_activated));
                }
            }
            return;
        }

        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            $order->add_order_note('礼品卡生成失败：订单缺少用户信息');
            return;
        }

        $template_id = absint($order->get_meta('_myshop_giftcard_template_id'));
        $giftcard_mode = $order->get_meta('_myshop_giftcard_mode');
        $giftcard_hint = $order->get_meta('_myshop_giftcard_hint');
        $payload = self::decode_meta_json($order->get_meta('_myshop_giftcard_payload'));
        $snapshot = self::decode_meta_json($order->get_meta('_myshop_giftcard_snapshot'));
        if (!$snapshot) {
            $snapshot = self::build_snapshot_from_order($order);
        }

        $template = $template_id ? self::get_template_row($template_id) : self::find_template_for_mode($giftcard_mode);
        if (!$template) {
            $order->add_order_note('礼品卡生成失败：未找到匹配的模板');
            return;
        }

        $result = self::insert_card_from_order($order, $template, $snapshot, $payload, $giftcard_hint);
        if (is_wp_error($result)) {
            $order->add_order_note('礼品卡生成失败：' . $result->get_error_message());
            return;
        }

        $order->update_meta_data('_myshop_issued_gift_cards', [$result]);
        $order->save();
        $order->add_order_note(sprintf('礼品卡已生成，卡号 %s', $result['card_number']));
    }

    public static function revoke_share_card($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $card_number = sanitize_text_field($request->get_param('card_number'));
        if ($card_number === '') {
            return new WP_Error('invalid_card_number', '礼品卡号不能为空', ['status' => 400]);
        }

        $card = self::get_card_by_number($card_number);
        if (!$card) {
            return new WP_Error('card_not_found', '礼品卡不存在', ['status' => 404]);
        }

        $current_holder_id = self::get_card_holder_user_id($card);
        if ($current_holder_id !== (int) $user->ID) {
            return new WP_Error('card_forbidden', '无权操作该礼品卡', ['status' => 403]);
        }

        if (empty($card->share_token)) {
            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'card_number' => $card->card_number,
                    'share_state' => self::determine_share_state($card)
                ]
            ]);
        }

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $updated = $wpdb->update(
            $cards_table,
            [
                'share_token'            => null,
                'share_channel'          => null,
                'share_token_expires_at' => current_time('mysql'),
                'updated_at'             => current_time('mysql')
            ],
            ['id' => $card->id],
            ['%s','%s','%s','%s'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error('revoke_failed', '撤销分享失败', ['status' => 500]);
        }

        $card->share_token = null;
        $card->share_channel = null;
        $card->share_token_expires_at = current_time('mysql');

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'card_number' => $card->card_number,
                'share_state' => self::determine_share_state($card)
            ]
        ]);
    }

    public static function get_share_history($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $card_number = sanitize_text_field($request->get_param('card_number'));
        if ($card_number === '') {
            return new WP_Error('invalid_card_number', '礼品卡号不能为空', ['status' => 400]);
        }

        $card = self::get_card_by_number($card_number);
        if (!$card) {
            return new WP_Error('card_not_found', '礼品卡不存在', ['status' => 404]);
        }

        $current_holder_id = self::get_card_holder_user_id($card);
        if ($current_holder_id !== (int) $user->ID) {
            return new WP_Error('card_forbidden', '无权查看该礼品卡分享记录', ['status' => 403]);
        }

        $logs = self::get_share_logs((int) $card->id, 50);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'card_number' => $card->card_number,
                'share_history' => $logs
            ]
        ]);
    }

    public static function list_cards($request) {
        global $wpdb;

        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $templates_table = $wpdb->prefix . 'myshop_gift_card_templates';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, t.name AS template_name, t.delivery_modes AS template_delivery_modes, t.print_template_url AS template_print_template_url
             FROM {$cards_table} c
             LEFT JOIN {$templates_table} t ON c.template_id = t.id
             WHERE c.redeemer_id = %d OR (c.redeemer_id IS NULL AND c.purchaser_id = %d)
             ORDER BY c.created_at DESC",
            $user->ID,
            $user->ID
        ));

        $cards = [];
        foreach ($rows as $row) {
            $template_modes = self::normalize_delivery_modes($row->template_delivery_modes ?? '');
            $share_meta = self::decode_share_meta($row->share_meta ?? null);
            $initial_amount = $row->initial_amount !== null ? (string) $row->initial_amount : null;
            $balance_amount = $row->balance !== null ? (string) $row->balance : null;
            $message = is_array($share_meta) ? ($share_meta['message'] ?? null) : null;
            $theme = is_array($share_meta) ? ($share_meta['theme'] ?? self::DEFAULT_SHARE_THEME) : self::DEFAULT_SHARE_THEME;
            // 如果状态为 NULL 或空字符串，默认视为 'active'（兼容历史数据）
            $card_status = !empty(trim($row->status ?? '')) ? trim($row->status) : 'active';
            $cards[] = [
                'template_id'   => (int) $row->template_id,
                'card_number'   => $row->card_number,
                'status'        => $card_status,
                'bind_status'   => $row->bind_status,
                'template_type' => $row->template_type,
                'initial_amount'=> $initial_amount,
                'balance'       => $balance_amount,
                'expires_at'    => $row->expires_at,
                'redeemer_id'   => $row->redeemer_id ? (int) $row->redeemer_id : null,
                'purchaser_id'  => (int) $row->purchaser_id,
                'created_at'    => $row->created_at,
                'updated_at'    => $row->updated_at,
                'template_name' => $row->template_name ?: null,
                'delivery_modes'=> $template_modes,
                'print_template_url' => self::resolve_print_template_url($row->template_print_template_url ?? ''),
                'share_state'   => self::determine_share_state($row),
                'share_meta'    => $share_meta,
                'shared_at'     => $row->shared_at,
                'shared_count'  => isset($row->shared_count) ? (int) $row->shared_count : 0,
                'share_token_expires_at' => $row->share_token_expires_at,
                'purchase_order_id' => $row->order_id ? (int) $row->order_id : null,
                'card_snapshot' => [
                    'card_number'   => $row->card_number,
                    'template_name' => $row->template_name ?: null,
                    'initial_amount'=> $initial_amount,
                    'balance'       => $balance_amount,
                    'expires_at'    => $row->expires_at,
                    'message'       => $message,
                    'theme'         => $theme
                ],
                'share_history' => self::get_share_logs((int) $row->id, 5)
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

        if ($card_number === '') {
            return new WP_Error('invalid_payload', '礼品卡号缺失', ['status' => 400]);
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

        if (strtotime($card->expires_at) < current_time('timestamp')) {
            return new WP_Error('card_expired', '礼品卡已过期', ['status' => 400]);
        }

        $current_holder_id = self::get_card_holder_user_id($card);
        if ($current_holder_id !== (int) $user->ID) {
            return new WP_Error('not_card_owner', '仅持有人可使用该购物卡', ['status' => 403]);
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
            'redeemer_id'         => $user->ID,
            'status'              => 'active',
            'bind_status'         => 'bound',
            'share_token'         => null,
            'share_channel'       => null,
            'share_token_expires_at' => null,
            'share_meta'          => null,
            'shared_at'           => null,
            'shared_count'        => 0,
            'print_package_url'   => self::resolve_print_template_url($template->print_template_url ?? ''),
            'pin_code_hash'       => null,
            'pin_reveal_limit'    => 0,
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
        if (!in_array($delivery_mode, self::DEFAULT_DELIVERY_MODES, true)) {
            $delivery_mode = 'digital_share';
        }
        $channel = isset($params['channel']) ? sanitize_text_field($params['channel']) : 'miniprogram';
        $message = isset($params['message']) ? wp_strip_all_tags($params['message']) : '';
        $theme = isset($params['theme']) ? sanitize_key($params['theme']) : self::DEFAULT_SHARE_THEME;
        $format = isset($params['format']) ? sanitize_key($params['format']) : 'qr';
        if (!in_array($format, ['qr', 'pdf', 'both'], true)) {
            $format = 'qr';
        }

        $card = self::get_card_by_number($card_number);
        if (!$card) {
            return new WP_Error('card_not_found', '礼品卡不存在', ['status' => 404]);
        }

        $template = self::get_template_row((int) $card->template_id);
        $allowed_modes = $template ? self::normalize_delivery_modes($template->delivery_modes ?? '') : self::DEFAULT_DELIVERY_MODES;

        $current_holder_id = self::get_card_holder_user_id($card);
        if ($current_holder_id !== (int) $user->ID) {
            return new WP_Error('card_forbidden', '无权分享该礼品卡', ['status' => 403]);
        }

        if ($card->status !== 'active') {
            return new WP_Error('card_invalid', '仅可分享状态为可用的礼品卡', ['status' => 400]);
        }

        // 检查购物卡是否已经分享（如果已分享且未过期，不能重复分享）
        $share_state = self::determine_share_state($card);
        if ($share_state === 'shared') {
            // 检查分享token是否过期
            if ($card->share_token_expires_at && strtotime($card->share_token_expires_at) >= current_time('timestamp')) {
                return new WP_Error('card_already_shared', '该购物卡已分享，请先撤销分享后再重新分享', ['status' => 400]);
            }
        }

        if (!in_array($delivery_mode, $allowed_modes, true)) {
            return new WP_Error('delivery_mode_not_allowed', '该礼品卡模板未启用此交付方式', ['status' => 400]);
        }

        $token  = self::generate_share_token();
        $expiry = date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' +' . self::SHARE_TOKEN_TTL_DAYS . ' days'));
        $now = current_time('mysql');
        $print_template_source = ($template && !empty($template->print_template_url)) ? $template->print_template_url : '';
        $print_template_url = self::resolve_print_template_url($print_template_source);
        $share_template_config = $template ? self::decode_share_template_config($template->share_template_config ?? null) : null;
        $share_meta = [
            'message' => $message ?: null,
            'theme'   => $theme ?: self::DEFAULT_SHARE_THEME,
            'format'  => $format,
            'template'=> [
                'print_template_url' => $print_template_url,
                'share_template_config' => $share_template_config
            ]
        ];
        $share_meta_json = wp_json_encode($share_meta);
        $shared_count = isset($card->shared_count) ? (int) $card->shared_count : 0;

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $updated = $wpdb->update(
            $cards_table,
            [
                'share_token'            => $token,
                'share_channel'          => $channel,
                'share_token_expires_at' => $expiry,
                'share_meta'             => $share_meta_json,
                'shared_at'              => $now,
                'shared_count'           => $shared_count + 1
            ],
            ['id' => $card->id],
            ['%s','%s','%s','%s','%s','%d'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error('share_failed', '礼品卡分享失败', ['status' => 500]);
        }

        self::log_share_event((int) $card->id, (int) $user->ID, $delivery_mode, $channel, $token);

        // 获取分享样式配置
        $share_style_config = null;
        if ($theme && $theme !== self::DEFAULT_SHARE_THEME) {
            $share_style_config = self::get_share_style_config($theme);
        }
        
        $share_payload = self::build_share_urls($token, $qr_payload, $share_style_config, $message, $card, $template);

        return rest_ensure_response([
            'success' => true,
            'data' => array_merge(
                [
                    'card_number'   => $card->card_number,
                    'template_name' => $template ? $template->name : null,
                    'delivery_mode' => $delivery_mode,
                    'channel'       => $channel,
                    'expires_at'    => $expiry,
                    'print_template_url' => $print_template_url,
                    'allowed_delivery_modes' => $allowed_modes,
                    'share_meta'    => $share_meta,
                    'share_state'   => 'shared',
                    'card_snapshot' => self::build_card_snapshot($card, $template, $share_meta),
                    'share_history' => self::get_share_logs((int) $card->id, 5)
                ],
                $share_payload
            )
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
        $share_meta = self::decode_share_meta($card->share_meta ?? null);
        
        // 获取分享样式配置
        $theme = is_array($share_meta) ? ($share_meta['theme'] ?? self::DEFAULT_SHARE_THEME) : self::DEFAULT_SHARE_THEME;
        $share_style_config = null;
        if ($theme && $theme !== self::DEFAULT_SHARE_THEME) {
            $share_style_config = self::get_share_style_config($theme);
        }
        
        $message = is_array($share_meta) ? ($share_meta['message'] ?? null) : null;
        $qr_payload = self::QR_SCHEME . '?token=' . rawurlencode($card->share_token);
        $share_payload = self::build_share_urls($card->share_token, $qr_payload, $share_style_config, $message, $card, $template);

        return rest_ensure_response([
            'success' => true,
            'data' => array_merge(
                [
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
                    'share_meta'        => $share_meta,
                    'share_state'       => self::determine_share_state($card),
                    'template'          => $template ? self::format_template($template, true) : null,
                    'card_snapshot'     => self::build_card_snapshot($card, $template, $share_meta),
                    'share_history'     => self::get_share_logs((int) $card->id, 10)
                ],
                $share_payload
            )
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

        $current_holder_id = self::get_card_holder_user_id($card);
        if ($current_holder_id === (int) $user->ID) {
            return new WP_Error('card_self_claim', '不可领取自己分享的礼品卡', ['status' => 400]);
        }

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $updated = $wpdb->update(
            $cards_table,
            [
                'redeemer_id' => $user->ID,
                'bind_status' => 'bound',
                'share_token' => null,
                'share_channel' => null,
                'share_token_expires_at' => current_time('mysql'),
                'updated_at'  => current_time('mysql')
            ],
            ['id' => $card->id],
            ['%d','%s','%s','%s','%s','%s'],
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

    private static function decode_meta_json($raw) {
        if (empty($raw)) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function find_template_for_mode($mode) {
        global $wpdb;

        $table = $wpdb->prefix . 'myshop_gift_card_templates';
        $type = 'custom_bundle';

        switch ($mode) {
            case 'stored_value':
                $type = 'fixed_amount';
                break;
            case 'bundle':
                $type = 'product_bundle';
                break;
            case 'custom':
            default:
                $type = 'custom_bundle';
                break;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT 1",
            $type
        ));

        if (!$row) {
            $row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1");
        }

        return $row ?: null;
    }

    private static function insert_card_from_order($order, $template, $snapshot, $payload, $hint) {
        global $wpdb;

        if (!$order instanceof \WC_Order) {
            return new WP_Error('invalid_order', '无效订单对象');
        }

        $user_id = $order->get_customer_id();
        if (!$user_id) {
            return new WP_Error('giftcard_missing_user', '订单缺少购卡用户');
        }

        $now = current_time('mysql', true);
        $order_value = self::calculate_order_value($order);
        $expires_at = gmdate('Y-m-d H:i:s', strtotime($now . ' +' . (int) ($template->valid_days ?? 365) . ' days'));
        $card_number = self::generate_unique_card_number();
        $initial_amount = $order_value;
        if ($template->type === 'fixed_amount' && $template->fixed_amount !== null && (float) $template->fixed_amount > 0) {
            $initial_amount = (float) $template->fixed_amount;
        }

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $insert_data = [
            'card_number'         => $card_number,
            'template_id'         => (int) $template->id,
            'template_type'       => $template->type,
            'initial_amount'      => $initial_amount,
            'balance'             => $initial_amount,
            'currency'            => $template->currency,
            'linked_product_id'   => $template->product_id ?: null,
            'linked_variation_ids'=> $template->variation_ids,
            'bundle_config'       => !empty($snapshot) ? wp_json_encode($snapshot) : null,
            'purchaser_id'        => $user_id,
            'redeemer_id'         => $user_id,
            'order_id'            => $order->get_id(),
            'status'              => $order->has_status('completed') ? 'active' : 'pending_activation',
            'bind_status'         => 'bound',
            'share_token'         => null,
            'share_channel'       => null,
            'share_token_expires_at' => null,
            'share_meta'          => null,
            'shared_at'           => null,
            'shared_count'        => 0,
            'print_package_url'   => self::resolve_print_template_url($template->print_template_url ?? ''),
            'pin_code_hash'       => null,
            'pin_reveal_limit'    => 0,
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
            $insert_data['amount'] = $initial_amount;
        }
        if (in_array('user_id', $known_columns, true)) {
            $insert_data['user_id'] = $user_id;
        }

        $insert_formats = [];
        foreach ($insert_data as $column => $value) {
            switch ($column) {
                case 'template_id':
                case 'purchaser_id':
                case 'order_id':
                case 'shared_count':
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
                default:
                    $insert_formats[] = '%s';
            }
        }

        $inserted = $wpdb->insert($cards_table, $insert_data, $insert_formats);
        if ($inserted === false) {
            return new WP_Error('card_create_failed', sprintf('礼品卡生成失败: %s', $wpdb->last_error ?: 'unknown error'));
        }

        return [
            'card_number' => $card_number,
            'template_id' => (int) $template->id,
            'order_id'    => $order->get_id(),
            'issued_at'   => $now
        ];
    }

    private static function activate_pending_cards($issued_cards) {
        global $wpdb;

        if (empty($issued_cards) || !is_array($issued_cards)) {
            return 0;
        }

        $table = $wpdb->prefix . 'myshop_gift_cards';
        $activated = 0;
        $now = current_time('mysql', true);

        foreach ($issued_cards as $record) {
            if (empty($record['card_number'])) {
                continue;
            }

            // 激活 pending_activation、locked、NULL 或空字符串状态的购物卡（订单完成时应自动激活）
            // 但排除 void 和 redeemed 状态，这些状态不应该被自动激活
            // 注意：NULL 或空字符串状态也需要激活，因为可能是历史数据或创建时未正确设置状态
            $card_number = $record['card_number'];
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'active', updated_at = %s 
                 WHERE card_number = %s AND (status IN ('pending_activation', 'locked') OR status IS NULL OR status = '')",
                $now,
                $card_number
            ));

            if ($updated !== false && $updated > 0) {
                $activated += $updated;
            }
        }

        return $activated;
    }

    /**
     * 通过订单 ID 激活该订单关联的所有购物卡（pending_activation 或 locked 状态）
     * 用于处理订单完成时，确保该订单关联的购物卡都是 active 状态
     */
    private static function activate_cards_by_order_id($order_id) {
        global $wpdb;

        if (!$order_id) {
            return 0;
        }

        $table = $wpdb->prefix . 'myshop_gift_cards';
        $now = current_time('mysql', true);

        // 先查询该订单关联的所有购物卡及其状态，用于调试
        $cards_before = $wpdb->get_results($wpdb->prepare(
            "SELECT card_number, status FROM {$table} WHERE order_id = %d",
            $order_id
        ));

        // 查找该订单关联的所有 pending_activation、locked、NULL 或空字符串状态的购物卡并激活
        // 注意：NULL 或空字符串状态也需要激活，因为可能是历史数据或创建时未正确设置状态
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'active', updated_at = %s 
             WHERE order_id = %d AND (status IN ('pending_activation', 'locked') OR status IS NULL OR status = '')",
            $now,
            $order_id
        ));

        // 记录调试信息
        if (!empty($cards_before)) {
            $statuses = array_map(function($card) {
                return $card->card_number . ':' . $card->status;
            }, $cards_before);
            error_log(sprintf('[GiftCard] Order #%d: Cards before activation: %s, Updated: %d', 
                $order_id, implode(', ', $statuses), $updated !== false ? (int) $updated : 0));
        }

        return $updated !== false ? (int) $updated : 0;
    }

    private static function build_snapshot_from_order($order) {
        if (!$order instanceof \WC_Order) {
            return [];
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'meta'         => self::extract_item_meta($item)
            ];
        }

        $order_total = self::calculate_order_value($order);
        $payable_total = (float) $order->get_total();
        $discount_amount = max(0, $order_total - $payable_total);
        $points_used = (int) $order->get_meta('_points_used', true);
        $points_discount = (float) $order->get_meta('_points_discount_amount', true);

        return [
            'items'    => $items,
            'total'    => $order_total,
            'payable_total' => $payable_total,
            'discount_amount' => $discount_amount,
            'points_used' => $points_used,
            'points_discount_amount' => $points_discount,
            'currency' => $order->get_currency()
        ];
    }

    private static function extract_item_meta($item) {
        $meta_data = [];
        foreach ($item->get_meta_data() as $meta) {
            if (!isset($meta->key)) {
                continue;
            }
            $meta_data[$meta->key] = $meta->value;
        }
        return $meta_data;
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
            'print_template_url' => $include_config ? self::resolve_print_template_url($row->print_template_url ?? '') : null
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

    private static function calculate_order_value($order) {
        if (!$order instanceof \WC_Order) {
            return 0.0;
        }

        $items_subtotal = (float) $order->get_subtotal();
        $items_tax = method_exists($order, 'get_subtotal_tax') ? (float) $order->get_subtotal_tax() : 0.0;

        $shipping_total = 0.0;
        foreach ($order->get_shipping_methods() as $shipping) {
            $shipping_total += (float) $shipping->get_total() + (float) $shipping->get_total_tax();
        }

        $fee_total = 0.0;
        foreach ($order->get_fees() as $fee) {
            $fee_amount = (float) $fee->get_total();
            if ($fee_amount > 0) {
                $fee_total += $fee_amount + (float) $fee->get_total_tax();
            }
        }

        $value = $items_subtotal + $items_tax + $shipping_total + $fee_total;
        if ($value <= 0) {
            $value = (float) $order->get_total();
        }

        return round($value, 2);
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

    private static function get_share_logs($card_id, $limit = 5) {
        global $wpdb;
        $card_id = absint($card_id);
        if ($card_id <= 0) {
            return [];
        }

        $table = $wpdb->prefix . 'myshop_gift_card_share_logs';
        $query = $wpdb->prepare(
            "SELECT id, delivery_mode, channel, share_token, print_package_url, ip_address, created_at
             FROM {$table}
             WHERE card_id = %d
             ORDER BY id DESC",
            $card_id
        );

        $limit = absint($limit);
        if ($limit > 0) {
            $query .= ' LIMIT ' . $limit;
        }

        $rows = $wpdb->get_results($query);
        if (empty($rows)) {
            return [];
        }

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = [
                'id' => (int) $row->id,
                'delivery_mode' => $row->delivery_mode,
                'channel' => $row->channel,
                'share_token' => $row->share_token,
                'print_package_url' => $row->print_package_url,
                'ip_address' => $row->ip_address,
                'created_at' => $row->created_at
            ];
        }

        return $logs;
    }

    private static function normalize_delivery_modes($encoded) {
        $decoded = [];
        if (is_array($encoded)) {
            $decoded = $encoded;
        } elseif (is_string($encoded) && $encoded !== '') {
            $decoded = json_decode($encoded, true);
        }

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $modes = array_values(array_intersect(self::DEFAULT_DELIVERY_MODES, $decoded));
        return !empty($modes) ? $modes : self::DEFAULT_DELIVERY_MODES;
    }

    private static function decode_share_meta($raw) {
        if (empty($raw)) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function decode_share_template_config($raw) {
        if (empty($raw)) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function determine_share_state($card) {
        $status = $card->status ?? '';
        $share_token = $card->share_token ?? '';
        $share_expire = $card->share_token_expires_at ?? '';
        $bind_status = $card->bind_status ?? '';

        if ($status === 'redeemed') {
            return 'consumed';
        }

        if (!empty($share_token)) {
            if (!empty($share_expire) && strtotime($share_expire) < current_time('timestamp')) {
                return 'expired';
            }
            return 'shared';
        }

        $holder = self::get_card_holder_user_id($card);
        if ($holder > 0 && $bind_status === 'bound') {
            return 'bound';
        }

        return 'none';
    }

    private static function get_card_holder_user_id($card) {
        if (isset($card->redeemer_id) && (int) $card->redeemer_id > 0) {
            return (int) $card->redeemer_id;
        }
        return isset($card->purchaser_id) ? (int) $card->purchaser_id : 0;
    }

    private static function build_share_urls($token, $qr_payload = null, $share_style_config = null, $message = null, $card = null, $template = null) {
        if (empty($token)) {
            return [
                'share_token' => null,
                'share_url' => null,
                'mini_program_path' => null,
                'qr_payload' => null,
                'qr_image_url' => null,
                'mini_program_qr' => null
            ];
        }

        $mini_program_path = '/pages/shopping-card/claim?token=' . rawurlencode($token);
        if (empty($qr_payload)) {
            $qr_payload = self::QR_SCHEME . '?token=' . rawurlencode($token);
        }
        $share_url = add_query_arg(
            ['giftcard_token' => rawurlencode($token)],
            home_url('/')
        );

        // 生成二维码图片URL（带模板图案的版本）
        $qr_image_url = self::generate_qr_image_url($token, $qr_payload, $share_style_config, $message, $card, $template);

        return [
            'share_token' => $token,
            'share_url' => $share_url,
            'mini_program_path' => $mini_program_path,
            'qr_payload' => $qr_payload,
            'qr_image_url' => $qr_image_url,
            'mini_program_qr' => $qr_image_url // 兼容字段
        ];
    }

    /**
     * 获取分享样式配置
     * 
     * @param string $style_id 样式ID
     * @return array|null 样式配置
     */
    private static function get_share_style_config($style_id) {
        $styles_dir = MYSHOP_PLUGIN_DIR . 'assets/giftcard/';
        
        // 尝试匹配样式文件
        $possible_files = [
            $styles_dir . 'share-style-' . $style_id . '.json',
            $styles_dir . 'share-' . $style_id . '.json',
            $styles_dir . 'share-default.json'
        ];
        
        foreach ($possible_files as $file) {
            if (file_exists($file) && is_readable($file)) {
                $content = file_get_contents($file);
                if ($content !== false) {
                    $config = json_decode($content, true);
                    if (is_array($config)) {
                        return $config;
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * 生成二维码图片URL（带模板图案）
     * 
     * @param string $token 分享token
     * @param string $qr_payload 二维码内容
     * @param array|null $share_style_config 分享样式配置
     * @param string|null $message 祝福语
     * @param object|null $card 购物卡对象
     * @param object|null $template 模板对象
     * @return string|null 二维码图片URL
     */
    private static function generate_qr_image_url($token, $qr_payload, $share_style_config = null, $message = null, $card = null, $template = null) {
        // 检查PHP GD库是否可用
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            // 如果GD库不可用，使用在线服务生成基础二维码
            $qr_size = 600;
            $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/';
            return $qr_api_url . '?size=' . $qr_size . 'x' . $qr_size . '&data=' . rawurlencode($qr_payload);
        }

        try {
            // 生成带模板图案的二维码图片
            $image_url = self::generate_styled_qr_image($token, $qr_payload, $share_style_config, $message, $card, $template);
            if ($image_url) {
                return $image_url;
            }
        } catch (Exception $e) {
            error_log('[GiftCard] 生成带模板二维码失败: ' . $e->getMessage());
        }

        // 如果生成失败，回退到在线服务
        $qr_size = 600;
        $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/';
        return $qr_api_url . '?size=' . $qr_size . 'x' . $qr_size . '&data=' . rawurlencode($qr_payload);
    }

    /**
     * 生成带模板图案的二维码图片
     * 
     * @param string $token 分享token
     * @param string $qr_payload 二维码内容
     * @param array|null $style_config 样式配置
     * @param string|null $message 祝福语
     * @param object|null $card 购物卡对象
     * @param object|null $template 模板对象
     * @return string|null 图片URL
     */
    private static function generate_styled_qr_image($token, $qr_payload, $style_config = null, $message = null, $card = null, $template = null) {
        // 图片尺寸 - 改为方形，小巧美观
        $canvas_size = 600; // 方形画布，600x600像素
        $canvas_width = $canvas_size;
        $canvas_height = $canvas_size;
        $qr_size = 360; // 二维码尺寸
        $padding = 40; // 内边距

        // 创建画布
        $canvas = imagecreatetruecolor($canvas_width, $canvas_height);
        if (!$canvas) {
            return null;
        }

        // 设置背景色（从样式配置或使用默认）
        $bg_color = imagecolorallocate($canvas, 255, 255, 255); // 默认白色
        if ($style_config && isset($style_config['background_color'])) {
            $bg_rgb = self::hex_to_rgb($style_config['background_color']);
            if ($bg_rgb) {
                $bg_color = imagecolorallocate($canvas, $bg_rgb['r'], $bg_rgb['g'], $bg_rgb['b']);
            }
        }
        imagefill($canvas, 0, 0, $bg_color);

        // 如果有背景图片，加载并绘制
        if ($style_config && !empty($style_config['background_image'])) {
            $bg_image = self::load_image_from_url($style_config['background_image']);
            if ($bg_image) {
                $bg_w = imagesx($bg_image);
                $bg_h = imagesy($bg_image);
                imagecopyresampled($canvas, $bg_image, 0, 0, 0, 0, $canvas_width, $canvas_height, $bg_w, $bg_h);
                imagedestroy($bg_image);
            }
        }

        // 生成二维码图片（使用在线服务生成基础二维码）
        $qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qr_size . 'x' . $qr_size . '&data=' . rawurlencode($qr_payload);
        $qr_image = self::load_image_from_url($qr_image_url);
        
        // 计算二维码位置（居中，根据祝福语动态调整）
        $qr_x = ($canvas_width - $qr_size) / 2;
        // 如果有祝福语，预留更多空间；否则二维码更靠上
        $message_height = $message ? 60 : 0;
        $qr_y = $padding + $message_height + 20;
        
        if ($qr_image) {
            // 绘制二维码
            imagecopyresampled($canvas, $qr_image, $qr_x, $qr_y, 0, 0, $qr_size, $qr_size, $qr_size, $qr_size);
            imagedestroy($qr_image);
        }

        // 添加文字信息
        $text_color = imagecolorallocate($canvas, 51, 51, 51); // 深灰色，更美观
        $hint_color = imagecolorallocate($canvas, 153, 153, 153); // 浅灰色，用于提示文字
        if ($style_config && isset($style_config['text_color'])) {
            $text_rgb = self::hex_to_rgb($style_config['text_color']);
            if ($text_rgb) {
                $text_color = imagecolorallocate($canvas, $text_rgb['r'], $text_rgb['g'], $text_rgb['b']);
            }
        }

        $font_path = self::get_font_path();
        $use_ttf = $font_path && function_exists('imagettftext');

        // 添加祝福语（在二维码上方，美化样式）
        if ($message) {
            $message_y = $padding + 30;
            if ($use_ttf) {
                // 处理长文本换行，限制宽度，每行最多15个字符
                $message_lines = self::wrap_text($message, 15, $canvas_width - ($padding * 2));
                $line_height = 38; // 行高
                $font_size = 28; // 字体大小
                
                foreach ($message_lines as $index => $line) {
                    // 计算文字居中位置
                    $bbox = imagettfbbox($font_size, 0, $font_path, $line);
                    $text_width = $bbox[4] - $bbox[0];
                    $text_x = ($canvas_width - $text_width) / 2;
                    $y_pos = $message_y + ($index * $line_height);
                    imagettftext($canvas, $font_size, 0, $text_x, $y_pos, $text_color, $font_path, $line);
                }
            } else {
                // 使用内置字体，居中显示
                $text_x = ($canvas_width - mb_strlen($message, 'UTF-8') * 6) / 2;
                imagestring($canvas, 3, $text_x, $message_y, mb_substr($message, 0, 30, 'UTF-8'), $text_color);
            }
        }

        // 添加提示文字（在二维码下方，美化样式）
        $hint_text = '长按或扫码识别领取购物卡';
        $hint_y = $qr_y + $qr_size + 25;
        if ($use_ttf) {
            // 计算文字居中位置，使用稍小的字体
            $hint_font_size = 20;
            $bbox = imagettfbbox($hint_font_size, 0, $font_path, $hint_text);
            $text_width = $bbox[4] - $bbox[0];
            $text_x = ($canvas_width - $text_width) / 2;
            imagettftext($canvas, $hint_font_size, 0, $text_x, $hint_y, $hint_color, $font_path, $hint_text);
        } else {
            $text_x = ($canvas_width - mb_strlen($hint_text, 'UTF-8') * 6) / 2;
            imagestring($canvas, 2, $text_x, $hint_y - 10, mb_substr($hint_text, 0, 20, 'UTF-8'), $hint_color);
        }

        // 保存图片到服务器
        $upload_dir = wp_upload_dir();
        $giftcard_dir = $upload_dir['basedir'] . '/giftcards';
        if (!file_exists($giftcard_dir)) {
            wp_mkdir_p($giftcard_dir);
        }

        $filename = 'giftcard-' . $token . '-' . time() . '.png';
        $filepath = $giftcard_dir . '/' . $filename;
        $fileurl = $upload_dir['baseurl'] . '/giftcards/' . $filename;

        if (imagepng($canvas, $filepath, 9)) {
            imagedestroy($canvas);
            return $fileurl;
        }

        imagedestroy($canvas);
        return null;
    }

    /**
     * 从URL加载图片
     */
    private static function load_image_from_url($url) {
        if (empty($url)) {
            return null;
        }

        // 如果是本地文件路径
        if (strpos($url, 'http') !== 0) {
            if (file_exists($url)) {
                $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
                switch ($ext) {
                    case 'jpg':
                    case 'jpeg':
                        return imagecreatefromjpeg($url);
                    case 'png':
                        return imagecreatefrompng($url);
                    case 'gif':
                        return imagecreatefromgif($url);
                }
            }
            return null;
        }

        // 从URL下载图片
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }

        // 创建临时文件
        $temp_file = wp_tempnam('giftcard-');
        file_put_contents($temp_file, $body);

        // 根据内容类型加载图片
        $image = null;
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (strpos($content_type, 'image/png') !== false) {
            $image = imagecreatefrompng($temp_file);
        } elseif (strpos($content_type, 'image/jpeg') !== false || strpos($content_type, 'image/jpg') !== false) {
            $image = imagecreatefromjpeg($temp_file);
        } elseif (strpos($content_type, 'image/gif') !== false) {
            $image = imagecreatefromgif($temp_file);
        }

        unlink($temp_file);
        return $image;
    }

    /**
     * 十六进制颜色转RGB
     */
    private static function hex_to_rgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return null;
        }
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }

    /**
     * 文本换行处理
     */
    private static function wrap_text($text, $max_chars_per_line, $canvas_width) {
        $lines = [];
        $words = mb_str_split($text, 1, 'UTF-8');
        $current_line = '';
        
        foreach ($words as $char) {
            $test_line = $current_line . $char;
            // 简单估算：中文字符占2个位置，英文字符占1个位置
            $estimated_width = mb_strlen($test_line, 'UTF-8') * 20; // 假设每个字符20像素
            
            if ($estimated_width > $canvas_width - 100 || mb_strlen($test_line, 'UTF-8') > $max_chars_per_line) {
                if (!empty($current_line)) {
                    $lines[] = $current_line;
                    $current_line = $char;
                }
            } else {
                $current_line = $test_line;
            }
        }
        
        if (!empty($current_line)) {
            $lines[] = $current_line;
        }
        
        return $lines;
    }

    /**
     * 获取字体路径（使用系统字体或默认字体）
     */
    private static function get_font_path() {
        // 尝试使用系统字体
        $font_paths = [
            'C:/Windows/Fonts/simsun.ttc', // Windows 中文字体
            'C:/Windows/Fonts/msyh.ttc',  // Windows 微软雅黑
            '/System/Library/Fonts/STHeiti Light.ttc', // macOS 中文字体
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', // Linux 字体
        ];
        
        foreach ($font_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        // 如果没有找到字体，返回null（将使用默认字体，可能不支持中文）
        return null;
    }

    private static function build_card_snapshot($card, $template, $share_meta) {
        return [
            'card_number'   => $card->card_number,
            'template_name' => $template ? $template->name : null,
            'initial_amount'=> isset($card->initial_amount) ? (string) $card->initial_amount : null,
            'balance'       => isset($card->balance) ? (string) $card->balance : null,
            'expires_at'    => $card->expires_at,
            'message'       => is_array($share_meta) ? ($share_meta['message'] ?? null) : null,
            'theme'         => is_array($share_meta) ? ($share_meta['theme'] ?? self::DEFAULT_SHARE_THEME) : self::DEFAULT_SHARE_THEME
        ];
    }

    private static function resolve_print_template_url($custom_url) {
        if (!empty($custom_url)) {
            return esc_url_raw($custom_url);
        }

        return plugins_url('assets/giftcard/print-default.html', self::get_plugin_main_file());
    }

    private static function get_plugin_main_file() {
        static $plugin_file = null;
        if (!$plugin_file) {
            $plugin_file = trailingslashit(MYSHOP_PLUGIN_DIR) . 'myshop-core.php';
        }
        return $plugin_file;
    }

    /**
     * 获取所有可用的分享样式列表
     */
    public static function list_share_styles($request) {
        $styles_dir = MYSHOP_PLUGIN_DIR . 'assets/giftcard/';
        $styles = [];
        
        // 读取所有 share-*.json 文件（包括 share-style-*.json 和 share-default.json）
        $pattern = $styles_dir . 'share-*.json';
        $files = glob($pattern);
        
        if (!$files || !is_array($files)) {
            return rest_ensure_response([
                'success' => true,
                'data' => []
            ]);
        }
        
        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            $style = json_decode($content, true);
            if (!$style || !is_array($style)) {
                continue;
            }
            
            // 提取样式信息
            $style_id = $style['id'] ?? basename($file, '.json');
            $style_name = $style['name'] ?? '未命名样式';
            
            // 如果JSON中没有id字段，从文件名提取
            if (empty($style['id'])) {
                $style['id'] = $style_id;
            }
            
            // 如果JSON中没有name字段，设置默认名称
            if (empty($style['name'])) {
                $style['name'] = $style_name;
            }
            
            // 如果没有预览图，使用占位图
            if (empty($style['preview_image'])) {
                $style['preview_image'] = 'https://dummyimage.com/300x400/cccccc/666666&text=' . urlencode($style_name);
            }
            
            $styles[] = [
                'id' => $style['id'],
                'name' => $style['name'],
                'preview_image' => $style['preview_image'] ?? '',
                'config' => $style
            ];
        }
        
        // 按ID排序
        usort($styles, function($a, $b) {
            return strcmp($a['id'], $b['id']);
        });
        
        return rest_ensure_response([
            'success' => true,
            'data' => $styles
        ]);
    }
}
