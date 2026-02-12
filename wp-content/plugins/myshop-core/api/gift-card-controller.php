<?php

class Gift_Card_Controller {
    private const DEFAULT_DELIVERY_MODES = ['digital_share', 'printable'];
    private const SHARE_TOKEN_TTL_DAYS = 7;
    private const QR_SCHEME = 'myshop://giftcard';
    private const DEFAULT_SHARE_THEME = 'default';
    private const LOG_FILE_NAME = 'myshop-giftcard.log';

    private static function log_giftcard($message, $context = null) {
        try {
            $dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? ABSPATH . 'wp-content' : __DIR__);
            $log_path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . self::LOG_FILE_NAME;
            $ts = gmdate('Y-m-d H:i:s');
            $payload = '';
            if (!is_null($context)) {
                $payload = ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            @file_put_contents($log_path, '[' . $ts . '] ' . $message . $payload . PHP_EOL, FILE_APPEND);
        } catch (Exception $e) {
            // ignore logging failures
        }
    }
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

        register_rest_route('myshop/v1', '/debug/client-log', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'client_log'],
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
            // 即使没有激活，也记录日志以便调试（写入独立日志，避免污染 debug.log）
            self::log_giftcard('order completed: no cards activated', [
                'order_id' => (int) $order_id,
                'old_status' => (string) $old_status
            ]);
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

        if (!$order->is_paid() && !$order->get_date_paid()) {
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
        $scope = $request->get_param('scope');
        $within_days = absint($request->get_param('within_days') ?: 365);
        $within_days = $within_days > 0 ? min($within_days, 3650) : 365;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $within_days . ' days'));

        if ($scope === 'history') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, t.name AS template_name, t.delivery_modes AS template_delivery_modes, t.print_template_url AS template_print_template_url
                 FROM {$cards_table} c
                 LEFT JOIN {$templates_table} t ON c.template_id = t.id
                 WHERE (c.purchaser_id = %d OR c.redeemer_id = %d) AND c.created_at >= %s
                 ORDER BY c.created_at DESC",
                $user->ID,
                $user->ID,
                $cutoff
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, t.name AS template_name, t.delivery_modes AS template_delivery_modes, t.print_template_url AS template_print_template_url
                 FROM {$cards_table} c
                 LEFT JOIN {$templates_table} t ON c.template_id = t.id
                 WHERE c.redeemer_id = %d OR (c.redeemer_id IS NULL AND c.purchaser_id = %d)
                 ORDER BY c.created_at DESC",
                $user->ID,
                $user->ID
            ));
        }

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

        $template = self::get_template_row((int) $card->template_id);
        if (!$template) {
            return new WP_Error('template_not_found', '礼品卡模板不存在', ['status' => 404]);
        }

        if ($card->template_type === 'fixed_amount') {
            return new WP_Error('card_not_exchangeable', '储值卡请在订单支付页使用，无法直接兑换商品', ['status' => 400]);
        }

        $exchange_items = self::resolve_exchange_items($card, $template);
        if (empty($exchange_items)) {
            return new WP_Error('card_items_missing', '礼品卡未绑定可兑换的商品', ['status' => 400]);
        }

        $shipping_address = null;
        if (isset($params['shipping_address']) && is_array($params['shipping_address'])) {
            $shipping_address = self::sanitize_shipping_address($params['shipping_address']);
            if (is_wp_error($shipping_address)) {
                return $shipping_address;
            }
        }

        $exchange_order = self::create_exchange_order($user, $card, $template, $exchange_items, $shipping_address);
        if (is_wp_error($exchange_order)) {
            return $exchange_order;
        }
        $exchange_order_id = $exchange_order->get_id();

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
                'target_order_id' => $exchange_order_id,
                'redeemed_at'   => current_time('mysql', true)
            ],
            ['%d','%d','%d','%s','%s','%f','%f','%d','%s']
        );

        return rest_ensure_response([
            'success' => true,
            'message' => '礼品卡兑换成功',
            'data'    => [
                'card_number' => $card->card_number,
                'status'      => 'redeemed',
                'order_id'    => $exchange_order_id,
                'order_number'=> $exchange_order->get_order_number()
            ]
        ]);
    }

    private static function resolve_exchange_items($card, $template) {
        $items = [];

        if ($card->template_type === 'product_bundle') {
            if (!empty($template->bundle_items)) {
                $decoded = json_decode($template->bundle_items, true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            }

            if (empty($items) && !empty($card->bundle_config)) {
                $decoded = json_decode($card->bundle_config, true);
                if (is_array($decoded)) {
                    $items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
                }
            }
        } else {
            if (!empty($card->bundle_config)) {
                $decoded = json_decode($card->bundle_config, true);
                if (is_array($decoded)) {
                    $items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
                }
            }
        }

        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product_id = isset($item['product_id']) ? absint($item['product_id']) : 0;
            $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
            $quantity = isset($item['quantity']) ? max(1, absint($item['quantity'])) : 1;
            if (!$product_id && !$variation_id) {
                continue;
            }
            $normalized[] = [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $quantity
            ];
        }

        return $normalized;
    }

    private static function create_exchange_order($user, $card, $template, array $items, $shipping_address = null) {
        if (!$user || !($user instanceof \WP_User)) {
            return new WP_Error('invalid_user', '用户信息无效', ['status' => 403]);
        }

        $order = wc_create_order();
        if (!$order) {
            return new WP_Error('order_create_failed', '兑换订单创建失败', ['status' => 500]);
        }

        foreach ($items as $item) {
            $variation_id = !empty($item['variation_id']) ? absint($item['variation_id']) : 0;
            $product_id = !empty($item['product_id']) ? absint($item['product_id']) : 0;
            $quantity = !empty($item['quantity']) ? max(1, absint($item['quantity'])) : 1;

            $product = $variation_id ? wc_get_product($variation_id) : ($product_id ? wc_get_product($product_id) : null);
            if (!$product) {
                $order->delete(true);
                return new WP_Error('redeem_product_missing', '兑换商品不存在或已下架', ['status' => 400]);
            }

            $item_id = $order->add_product($product, $quantity);
            $order_item = $item_id ? $order->get_item($item_id) : null;
            if ($order_item instanceof WC_Order_Item_Product) {
                $order_item->set_subtotal(0);
                $order_item->set_total(0);
                $order_item->save();
            }
        }

        $order->set_customer_id($user->ID);
        $order->set_payment_method('cod');

        $default_address = $shipping_address ?: self::resolve_default_address($user->ID);
        if ($default_address) {
            $order->set_address([
                'first_name' => $default_address['name'],
                'last_name'  => '',
                'address_1'  => $default_address['detail'],
                'city'       => $default_address['city'],
                'state'      => $default_address['province'],
                'postcode'   => $default_address['postal_code'] ?? '',
                'country'    => 'CN'
            ], 'shipping');
            $order->set_billing_phone($default_address['phone']);
        }

        $order->update_meta_data('_myshop_giftcard_redeem', 'yes');
        $order->update_meta_data('_myshop_giftcard_redeem_card', $card->card_number);
        $order->update_meta_data('_myshop_giftcard_template_id', (int) $card->template_id);
        $order->calculate_totals();

        $payable_total = (float) $order->get_total();
        if ($payable_total > 0) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name('购物卡兑换抵扣');
            $fee->set_amount(-$payable_total);
            $fee->set_total(-$payable_total);
            $order->add_item($fee);
            $order->calculate_totals();
        }

        $order->set_status('processing');
        $order->save();

        return $order;
    }

    private static function resolve_default_address($user_id) {
        $user_addresses = get_user_meta($user_id, '_myshop_addresses', true);
        if (!is_array($user_addresses) || empty($user_addresses)) {
            return null;
        }

        $default = null;
        foreach ($user_addresses as $addr) {
            if (!empty($addr['is_default'])) {
                $default = $addr;
                break;
            }
        }

        if (!$default) {
            $default = $user_addresses[0];
        }

        return [
            'name' => $default['name'] ?? '',
            'phone' => $default['phone'] ?? '',
            'province' => $default['province'] ?? '',
            'city' => $default['city'] ?? '',
            'district' => $default['district'] ?? '',
            'detail' => $default['detail'] ?? ($default['detail_address'] ?? ''),
            'postal_code' => $default['postal_code'] ?? ($default['postcode'] ?? '')
        ];
    }

    private static function sanitize_shipping_address($payload) {
        if (!is_array($payload)) {
            return new WP_Error('invalid_address', '收货地址格式错误', ['status' => 400]);
        }

        $name = isset($payload['name']) ? sanitize_text_field($payload['name']) : '';
        $phone = isset($payload['phone']) ? sanitize_text_field($payload['phone']) : '';
        $province = isset($payload['province']) ? sanitize_text_field($payload['province']) : '';
        $city = isset($payload['city']) ? sanitize_text_field($payload['city']) : '';
        $district = isset($payload['district']) ? sanitize_text_field($payload['district']) : '';
        $detail = isset($payload['detail_address']) ? sanitize_text_field($payload['detail_address']) : '';
        $postcode = isset($payload['postcode']) ? sanitize_text_field($payload['postcode']) : '';

        if (empty($name) || empty($phone) || empty($province) || empty($detail)) {
            return new WP_Error('invalid_address', '请填写完整收货信息', ['status' => 400]);
        }

        return [
            'name' => $name,
            'phone' => $phone,
            'province' => $province,
            'city' => $city,
            'district' => $district,
            'detail' => $detail,
            'postal_code' => $postcode
        ];
    }

    public static function list_templates($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'myshop_gift_card_templates';
        $rows = $wpdb->get_results(
            "SELECT *
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

        $fixed_amount = $template->fixed_amount !== null ? (float) $template->fixed_amount : null;
        $amount_param = isset($params['amount']) ? (float) $params['amount'] : null;
        if ($amount_param !== null && $amount_param <= 0) {
            $amount_param = null;
        }

        $purchase_flow = isset($template->purchase_flow) && $template->purchase_flow ? $template->purchase_flow : null;
        if (!$purchase_flow) {
            if ($template->type === 'product_bundle') {
                $purchase_flow = 'bundle';
            } elseif ($template->type === 'custom_bundle') {
                $purchase_flow = 'custom';
            } else {
                $purchase_flow = 'stored_value';
            }
        }

        $final_amount = $fixed_amount;
        if ($purchase_flow === 'stored_value') {
            $amount_options = self::normalize_number_list($template->amount_options ?? null);
            $min_amount = isset($template->min_amount) && $template->min_amount !== null ? (float) $template->min_amount : null;
            $max_amount = isset($template->max_amount) && $template->max_amount !== null ? (float) $template->max_amount : null;

            if ($amount_param !== null) {
                if (!empty($amount_options) && !in_array($amount_param, $amount_options, true)) {
                    return new WP_Error('invalid_amount', '购卡金额不在可选范围内', ['status' => 400]);
                }
                if ($min_amount !== null && $amount_param < $min_amount) {
                    return new WP_Error('invalid_amount', '购卡金额低于最低限制', ['status' => 400]);
                }
                if ($max_amount !== null && $amount_param > $max_amount) {
                    return new WP_Error('invalid_amount', '购卡金额超过最高限制', ['status' => 400]);
                }
                $final_amount = $amount_param;
            } elseif ($fixed_amount !== null) {
                $final_amount = $fixed_amount;
            } elseif (!empty($amount_options)) {
                $final_amount = $amount_options[0];
            } else {
                return new WP_Error('missing_amount', '请提供购卡金额', ['status' => 400]);
            }
        }

        if ($final_amount === null || $final_amount <= 0) {
            return new WP_Error('invalid_amount', '购卡金额无效', ['status' => 400]);
        }

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';

        $insert_data = [
            'card_number'         => $card_number,
            'template_id'         => $template->id,
            'template_type'       => $template->type,
            'initial_amount'      => $final_amount,
            'balance'             => $final_amount,
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
            $insert_data['amount'] = $final_amount;
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
            self::log_giftcard('share attempt: card not found', [
                'card_number' => $card_number,
                'user_id' => (int) $user->ID
            ]);
            return new WP_Error('card_not_found', '礼品卡不存在', ['status' => 404]);
        }

        $template = self::get_template_row((int) $card->template_id);
        $allowed_modes = $template ? self::normalize_delivery_modes($template->delivery_modes ?? '') : self::DEFAULT_DELIVERY_MODES;

        $current_holder_id = self::get_card_holder_user_id($card);
        self::log_giftcard('share attempt', [
            'card_number' => $card->card_number,
            'user_id' => (int) $user->ID,
            'holder_id' => (int) $current_holder_id,
            'status' => $card->status ?? null,
            'share_state' => self::determine_share_state($card)
        ]);
        if ($current_holder_id !== (int) $user->ID) {
            self::log_giftcard('share forbidden', [
                'card_number' => $card->card_number,
                'user_id' => (int) $user->ID,
                'holder_id' => (int) $current_holder_id
            ]);
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
        $referrer_code = null;
        if (class_exists('Referral_Controller')) {
            $referrer_code = Referral_Controller::ensure_referral_code((int) $user->ID);
        }
        $share_meta = [
            'message' => $message ?: null,
            'theme'   => $theme ?: self::DEFAULT_SHARE_THEME,
            'format'  => $format,
            'referrer_code' => $referrer_code,
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

        // 获取分享样式配置（默认主题也要读取默认模板）
        $share_style_config = self::get_share_style_config($theme ?: self::DEFAULT_SHARE_THEME);
        if (!$share_style_config) {
            $share_style_config = self::get_share_style_config(self::DEFAULT_SHARE_THEME);
        }

        $share_payload = self::build_share_urls($token, null, $share_style_config, $message, $card, $template, $referrer_code);
        error_log('[GiftCard] share payload: ' . wp_json_encode([
            'card_number' => $card->card_number,
            'token' => $token,
            'mini_program_path' => $share_payload['mini_program_path'] ?? null,
            'qr_payload' => $share_payload['qr_payload'] ?? null,
            'qr_image_url' => $share_payload['qr_image_url'] ?? null,
            'mini_program_qr' => $share_payload['mini_program_qr'] ?? null
        ]));
        self::log_giftcard('share payload', [
            'card_number' => $card->card_number,
            'token' => $token,
            'mini_program_path' => $share_payload['mini_program_path'] ?? null,
            'qr_payload' => $share_payload['qr_payload'] ?? null,
            'qr_image_url' => $share_payload['qr_image_url'] ?? null,
            'mini_program_qr' => $share_payload['mini_program_qr'] ?? null
        ]);

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
            self::log_giftcard('share detail: token not found', [
                'token' => $token
            ]);
            return new WP_Error('share_not_found', '分享链接已失效', ['status' => 404]);
        }

        if ($card->share_token_expires_at && strtotime($card->share_token_expires_at) < current_time('timestamp')) {
            self::log_giftcard('share detail: token expired', [
                'token' => $card->share_token,
                'expires_at' => $card->share_token_expires_at
            ]);
            return new WP_Error('share_expired', '分享链接已过期', ['status' => 410]);
        }

        $template = self::get_template_row((int) $card->template_id);
        $share_meta = self::decode_share_meta($card->share_meta ?? null);
        
        // 获取分享样式配置（默认主题也要读取默认模板）
        $theme = is_array($share_meta) ? ($share_meta['theme'] ?? self::DEFAULT_SHARE_THEME) : self::DEFAULT_SHARE_THEME;
        $share_style_config = self::get_share_style_config($theme ?: self::DEFAULT_SHARE_THEME);
        if (!$share_style_config) {
            $share_style_config = self::get_share_style_config(self::DEFAULT_SHARE_THEME);
        }
        
        $message = is_array($share_meta) ? ($share_meta['message'] ?? null) : null;
        $referrer_code = is_array($share_meta) ? ($share_meta['referrer_code'] ?? null) : null;
        $share_payload = self::build_share_urls($card->share_token, null, $share_style_config, $message, $card, $template, $referrer_code);
        error_log('[GiftCard] share detail payload: ' . wp_json_encode([
            'card_number' => $card->card_number,
            'token' => $card->share_token,
            'mini_program_path' => $share_payload['mini_program_path'] ?? null,
            'qr_payload' => $share_payload['qr_payload'] ?? null,
            'qr_image_url' => $share_payload['qr_image_url'] ?? null,
            'mini_program_qr' => $share_payload['mini_program_qr'] ?? null
        ]));
        self::log_giftcard('share detail payload', [
            'card_number' => $card->card_number,
            'token' => $card->share_token,
            'mini_program_path' => $share_payload['mini_program_path'] ?? null,
            'qr_payload' => $share_payload['qr_payload'] ?? null,
            'qr_image_url' => $share_payload['qr_image_url'] ?? null,
            'mini_program_qr' => $share_payload['mini_program_qr'] ?? null
        ]);

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
        self::log_giftcard('claim attempt', [
            'token' => $token,
            'user_id' => (int) $user->ID
        ]);
        $referrer_from_param = sanitize_text_field($request->get_param('referrer_code'));
        $card = self::get_card_by_share_token($token);
        if (!$card) {
            error_log(sprintf('[GiftCard] claim failed: share token not found. token=%s, user_id=%d', $token, (int) $user->ID));
            self::log_giftcard('claim failed: token not found', [
                'token' => $token,
                'user_id' => (int) $user->ID
            ]);
            return new WP_Error('share_not_found', '分享链接不存在', ['status' => 404]);
        }

        if ($card->share_token_expires_at && strtotime($card->share_token_expires_at) < current_time('timestamp')) {
            error_log(sprintf('[GiftCard] claim failed: share token expired. token=%s, user_id=%d, expires_at=%s', $token, (int) $user->ID, $card->share_token_expires_at));
            self::log_giftcard('claim failed: token expired', [
                'token' => $token,
                'user_id' => (int) $user->ID,
                'expires_at' => $card->share_token_expires_at
            ]);
            return new WP_Error('share_expired', '分享链接已过期', ['status' => 410]);
        }

        $current_holder_id = self::get_card_holder_user_id($card);
        if ($current_holder_id === (int) $user->ID) {
            error_log(sprintf('[GiftCard] claim blocked: self-claim. token=%s, user_id=%d, card_number=%s', $token, (int) $user->ID, $card->card_number));
            self::log_giftcard('claim blocked: self-claim', [
                'token' => $token,
                'user_id' => (int) $user->ID,
                'card_number' => $card->card_number
            ]);
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
            error_log(sprintf('[GiftCard] claim failed: db update error. token=%s, user_id=%d, card_number=%s, error=%s', $token, (int) $user->ID, $card->card_number, $wpdb->last_error ?: 'unknown'));
            return new WP_Error('card_claim_failed', '领取礼品卡失败', ['status' => 500]);
        }

        error_log(sprintf('[GiftCard] claim success: token=%s, card_number=%s, from_user=%d, to_user=%d', $token, $card->card_number, (int) $current_holder_id, (int) $user->ID));

        // 礼品卡分享绑定推荐关系（仅首登的新用户且未绑定过）
        try {
            $share_meta = self::decode_share_meta($card->share_meta ?? null);
            $referrer_code = is_array($share_meta) ? ($share_meta['referrer_code'] ?? null) : null;
            if (!empty($referrer_from_param) && empty($referrer_code)) {
                $referrer_code = $referrer_from_param;
            }
            $first_login_at = get_user_meta($user->ID, '_myshop_first_login_at', true);
            $within_first_day = false;
            if (!empty($first_login_at)) {
                $first_login_ts = strtotime($first_login_at);
                if ($first_login_ts) {
                    $within_first_day = (current_time('timestamp') - $first_login_ts) <= DAY_IN_SECONDS;
                }
            }
            error_log('[GiftCard] referral bind check: ' . wp_json_encode([
                'token' => $token,
                'invitee_id' => (int) $user->ID,
                'referrer_code' => $referrer_code ?: null,
                'referrer_from_param' => $referrer_from_param ?: null,
                'referrer_from_meta' => is_array($share_meta) ? ($share_meta['referrer_code'] ?? null) : null,
                'first_login_at' => $first_login_at ?: null,
                'within_first_day' => $within_first_day ? 'yes' : 'no',
                'has_referral_controller' => class_exists('Referral_Controller') ? 'yes' : 'no'
            ]));
            if (!empty($referrer_code) && $within_first_day && class_exists('Referral_Controller')) {
                $matched = get_users([
                    'meta_key' => 'myshop_referral_code',
                    'meta_value' => sanitize_text_field($referrer_code),
                    'number' => 1,
                    'fields' => 'ID'
                ]);
                $inviter_id = $matched ? (int) $matched[0] : 0;
                if ($inviter_id > 0) {
                    $bound = Referral_Controller::bind_referral($user->ID, $inviter_id, 'giftcard');
                    self::log_giftcard('referral bind on claim', [
                        'token' => $token,
                        'invitee_id' => (int) $user->ID,
                        'inviter_id' => $inviter_id,
                        'referrer_code' => $referrer_code,
                        'bound' => $bound ? 'yes' : 'no',
                        'referrer_from_param' => $referrer_from_param ?: null,
                        'referrer_from_meta' => is_array($share_meta) ? ($share_meta['referrer_code'] ?? null) : null
                    ]);
                    error_log('[GiftCard] referral bind result: ' . wp_json_encode([
                        'token' => $token,
                        'invitee_id' => (int) $user->ID,
                        'inviter_id' => $inviter_id,
                        'bound' => $bound ? 'yes' : 'no'
                    ]));
                }
            } else {
                error_log('[GiftCard] referral bind skipped: ' . wp_json_encode([
                    'token' => $token,
                    'invitee_id' => (int) $user->ID,
                    'reason' => empty($referrer_code) ? 'missing_referrer_code' : ($within_first_day ? (class_exists('Referral_Controller') ? 'inviter_not_found' : 'missing_referral_controller') : 'outside_first_day')
                ]));
            }
        } catch (Throwable $e) {
            self::log_giftcard('referral bind on claim failed', [
                'token' => $token,
                'user_id' => (int) $user->ID,
                'error' => $e->getMessage()
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'card_number' => $card->card_number,
                'status'      => 'bound'
            ]
        ]);
    }

    public static function client_log($request) {
        $params = $request->get_json_params();
        $event = isset($params['event']) ? sanitize_text_field($params['event']) : 'client_log';
        $payload = isset($params['payload']) ? $params['payload'] : null;
        if (is_string($payload) && strlen($payload) > 4000) {
            $payload = substr($payload, 0, 4000) . '...';
        }
        self::log_giftcard('client: ' . $event, is_array($payload) ? $payload : ['payload' => $payload]);
        return rest_ensure_response(['success' => true]);
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

    private static function normalize_id_list($raw) {
        $decoded = self::decode_meta_json($raw);
        if (is_array($decoded)) {
            return array_values(array_map('intval', $decoded));
        }
        if (is_string($raw)) {
            $parts = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
            return array_values(array_map('intval', $parts));
        }
        return [];
    }

    private static function normalize_number_list($raw) {
        $decoded = self::decode_meta_json($raw);
        if (is_array($decoded)) {
            return array_values(array_map('floatval', $decoded));
        }
        if (is_string($raw)) {
            $parts = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
            return array_values(array_map('floatval', $parts));
        }
        return [];
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

        // 记录调试信息（写入独立日志，避免污染 debug.log）
        if (!empty($cards_before)) {
            $statuses = array_map(function($card) {
                return $card->card_number . ':' . $card->status;
            }, $cards_before);
            self::log_giftcard('order cards before activation', [
                'order_id' => (int) $order_id,
                'cards' => $statuses,
                'updated' => $updated !== false ? (int) $updated : 0
            ]);
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
        $purchase_flow = isset($row->purchase_flow) && $row->purchase_flow ? $row->purchase_flow : null;
        if (!$purchase_flow) {
            if ($row->type === 'product_bundle') {
                $purchase_flow = 'bundle';
            } elseif ($row->type === 'custom_bundle') {
                $purchase_flow = 'custom';
            } else {
                $purchase_flow = 'stored_value';
            }
        }

        $amount_options = self::normalize_number_list($row->amount_options ?? null);
        $allowed_product_ids = self::normalize_id_list($row->allowed_product_ids ?? null);
        $allowed_variation_ids = self::normalize_id_list($row->allowed_variation_ids ?? null);

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
            'print_template_url' => $include_config ? self::resolve_print_template_url($row->print_template_url ?? '') : null,
            'purchase_flow'      => $purchase_flow,
            'amount_options'     => !empty($amount_options) ? $amount_options : null,
            'min_amount'         => isset($row->min_amount) && $row->min_amount !== null ? (float) $row->min_amount : null,
            'max_amount'         => isset($row->max_amount) && $row->max_amount !== null ? (float) $row->max_amount : null,
            'allowed_product_ids' => !empty($allowed_product_ids) ? $allowed_product_ids : null,
            'allowed_variation_ids' => !empty($allowed_variation_ids) ? $allowed_variation_ids : null,
            'max_items'          => isset($row->max_items) && $row->max_items !== null ? (int) $row->max_items : null,
            'max_total'          => isset($row->max_total) && $row->max_total !== null ? (float) $row->max_total : null,
            'success_copywriting' => isset($row->success_copywriting) ? $row->success_copywriting : null
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

    private static function build_share_urls($token, $qr_payload = null, $share_style_config = null, $message = null, $card = null, $template = null, $referrer_code = null) {
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

        $clean_referrer = is_string($referrer_code) ? trim($referrer_code) : '';
        // Gift card share should start at login to bind referral on first login when applicable.
        $mini_program_path = '/pages/auth/login?token=' . rawurlencode($token);
        if ($clean_referrer !== '') {
            $mini_program_path .= '&referrer_code=' . rawurlencode($clean_referrer);
        }
        $share_url = add_query_arg(
            array_filter([
                'giftcard_token' => rawurlencode($token),
                'referrer_code' => $clean_referrer !== '' ? $clean_referrer : null
            ]),
            home_url('/')
        );
        if (empty($qr_payload)) {
            $qr_payload = $share_url;
        }

        $qr_size = null;
        if (is_array($share_style_config) && !empty($share_style_config['qr_size'])) {
            $qr_size = absint($share_style_config['qr_size']);
        }
        $scene = $token;
        if ($clean_referrer !== '') {
            $scene = 'gc_' . $token . '_rc_' . $clean_referrer;
        } else {
            $scene = 'gc_' . $token;
        }
        if (strlen($scene) > 32) {
            $scene = substr($scene, 0, 32);
        }
        $mini_program_qr = self::generate_miniprogram_qr_image($mini_program_path, $token, $qr_size, $scene);
        if ($mini_program_qr && is_array($share_style_config)) {
            $qr_image_for_style = $mini_program_qr;
            $upload_dir = wp_upload_dir();
            if (!empty($upload_dir['baseurl']) && !empty($upload_dir['basedir'])) {
                $baseurl_http = $upload_dir['baseurl'];
                $baseurl_https = set_url_scheme($upload_dir['baseurl'], 'https');
                if (strpos($mini_program_qr, $baseurl_https) === 0 || strpos($mini_program_qr, $baseurl_http) === 0) {
                    $relative = ltrim(str_replace([$baseurl_https, $baseurl_http], '', $mini_program_qr), '/');
                    $local_path = trailingslashit($upload_dir['basedir']) . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    if (file_exists($local_path)) {
                        $qr_image_for_style = $local_path;
                    }
                }
            }
            $share_style_config['qr_image_url'] = $qr_image_for_style;
        }

        // 生成二维码图片URL（带模板图案的版本）
        $env_version = self::resolve_env_version();
        if ($env_version === 'develop') {
            // 开发环境下避免生成复杂模板图，降低阻塞与卡顿
            $qr_image_url = $mini_program_qr ?: $qr_payload;
        } else {
            $qr_image_url = self::generate_qr_image_url($token, $qr_payload, $share_style_config, $message, $card, $template);
        }

        return [
            'share_token' => $token,
            'share_url' => $share_url,
            'mini_program_path' => $mini_program_path,
            'qr_payload' => $qr_payload,
            'qr_image_url' => $qr_image_url,
            'mini_program_qr' => $mini_program_qr ?: $qr_image_url // 兼容字段
        ];
    }

    private static function generate_miniprogram_qr_image($path, $token, $width = null, $scene_override = null) {
        $access_token = self::get_wechat_access_token();
        if (empty($access_token)) {
            error_log('[GiftCard] mini program qr failed: access token missing');
            return null;
        }

        $width = $width ? absint($width) : 430;
        if ($width < 280) {
            $width = 280;
        }
        if ($width > 1280) {
            $width = 1280;
        }

        $env_version = self::resolve_env_version();

        $scene = $scene_override ?: $token;
        if (is_string($scene) && strlen($scene) > 32) {
            $scene = substr($scene, 0, 32);
        }

        $page = 'pages/shopping-card/claim';
        if (is_string($path) && $path !== '') {
            $path = ltrim($path, '/');
            $page = strtok($path, '?') ?: $page;
        }
        $endpoint = 'https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token=' . rawurlencode($access_token);
        $body = wp_json_encode([
            'scene' => $scene,
            'page' => $page,
            'width' => $width,
            'is_hyaline' => false,
            'env_version' => $env_version
        ]);

        $appid_for_log = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : get_option('myshop_wechat_appid');
        error_log('[GiftCard] mini program qr env_version=' . $env_version . ', appid=' . $appid_for_log . ', token=' . $token . ', width=' . $width);
        self::log_giftcard('mini program qr request', [
            'env_version' => $env_version,
            'appid' => $appid_for_log,
            'token' => $token,
            'width' => $width
        ]);

        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('[GiftCard] mini program qr request error: ' . $response->get_error_message());
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $raw = wp_remote_retrieve_body($response);

        if ($status !== 200 || empty($raw)) {
            error_log('[GiftCard] mini program qr invalid response: status=' . $status . ', content_type=' . $content_type . ', size=' . strlen($raw));
            self::log_giftcard('mini program qr invalid response', [
                'status' => $status,
                'content_type' => $content_type,
                'size' => strlen($raw)
            ]);
            return null;
        }

        $upload_dir = wp_upload_dir();
        $file_path = null;
        $png_path = null;
        $jpg_path = null;
        $safe_token = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if (!empty($upload_dir['basedir'])) {
            $dir = trailingslashit($upload_dir['basedir']) . 'myshop/giftcard/qr';
            $png_path = trailingslashit($dir) . sprintf('giftcard_%s.png', $safe_token);
            $jpg_path = trailingslashit($dir) . sprintf('giftcard_%s.jpg', $safe_token);
            $file_path = $png_path;
        }

        $raw_trim = ltrim($raw);
        $looks_like_json = (!empty($content_type) && stripos($content_type, 'application/json') !== false)
            || (!empty($raw_trim) && $raw_trim[0] === '{');
        if ($looks_like_json) {
            error_log('[GiftCard] mini program qr response json: ' . $raw);
            $decoded = json_decode($raw, true);
            self::log_giftcard('mini program qr response json', [
                'status' => $status,
                'content_type' => $content_type,
                'body' => is_array($decoded) ? $decoded : $raw
            ]);
            if ($png_path && file_exists($png_path)) {
                @unlink($png_path);
            }
            if ($jpg_path && file_exists($jpg_path)) {
                @unlink($jpg_path);
            }
            if (is_array($decoded) && isset($decoded['errcode']) && (int) $decoded['errcode'] === 40001) {
                delete_transient('myshop_wechat_access_token');
                $access_token = self::get_wechat_access_token(true);
                if ($access_token) {
                    return self::generate_miniprogram_qr_image($path, $token, $width);
                }
            }
            return null;
        }

        $image_info = @getimagesizefromstring($raw);
        if ($image_info === false) {
            error_log('[GiftCard] mini program qr invalid image response, content_type=' . $content_type . ', size=' . strlen($raw));
            self::log_giftcard('mini program qr invalid image', [
                'content_type' => $content_type,
                'size' => strlen($raw)
            ]);
            if ($png_path && file_exists($png_path)) {
                @unlink($png_path);
            }
            if ($jpg_path && file_exists($jpg_path)) {
                @unlink($jpg_path);
            }
            return null;
        }

        if (empty($upload_dir['basedir']) || empty($upload_dir['baseurl'])) {
            return null;
        }

        $dir = trailingslashit($upload_dir['basedir']) . 'myshop/giftcard/qr';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $mime = $image_info['mime'] ?? '';
        $ext = ($mime === 'image/jpeg') ? 'jpg' : 'png';
        $file_name = sprintf('giftcard_%s.%s', $safe_token, $ext);
        $file_path = ($ext === 'jpg' && $jpg_path) ? $jpg_path : ($png_path ?: trailingslashit($dir) . $file_name);
        if ($png_path && file_exists($png_path) && $file_path !== $png_path) {
            @unlink($png_path);
        }
        if ($jpg_path && file_exists($jpg_path) && $file_path !== $jpg_path) {
            @unlink($jpg_path);
        }
        $written = file_put_contents($file_path, $raw);
        if ($written === false) {
            error_log('[GiftCard] mini program qr write failed: ' . $file_path);
            self::log_giftcard('mini program qr write failed', ['file_path' => $file_path]);
            return null;
        }

        error_log('[GiftCard] mini program qr saved: ' . $file_path . ', size=' . $written . ', mime=' . ($image_info['mime'] ?? 'unknown'));
        self::log_giftcard('mini program qr saved', [
            'file_path' => $file_path,
            'size' => $written,
            'mime' => $image_info['mime'] ?? 'unknown'
        ]);

        $baseurl = set_url_scheme($upload_dir['baseurl'], 'https');
        $mtime = @filemtime($file_path);
        $version = $mtime ? (string) $mtime : (string) time();
        return trailingslashit($baseurl) . 'myshop/giftcard/qr/' . $file_name . '?v=' . $version;
    }

    private static function resolve_env_version() {
        $env_version = 'release';
        if (defined('MYSHOP_MINIAPP_ENV_VERSION')) {
            $candidate = constant('MYSHOP_MINIAPP_ENV_VERSION');
            if (is_string($candidate) && in_array($candidate, ['develop', 'trial', 'release'], true)) {
                return $candidate;
            }
        }
        if (defined('MYSHOP_WECHAT_ENV_VERSION')) {
            $candidate = constant('MYSHOP_WECHAT_ENV_VERSION');
            if (is_string($candidate) && in_array($candidate, ['develop', 'trial', 'release'], true)) {
                return $candidate;
            }
        }
        $configured_env = get_option('myshop_wechat_env_version');
        if (is_string($configured_env) && in_array($configured_env, ['develop', 'trial', 'release'], true)) {
            return $configured_env;
        }

        $home_url = home_url('/');
        if (strpos($home_url, 'dev.') !== false || strpos($home_url, 'localhost') !== false) {
            $env_version = 'develop';
        } elseif (strpos($home_url, 'trial') !== false || strpos($home_url, 'staging') !== false) {
            $env_version = 'trial';
        }

        return $env_version;
    }

    private static function get_wechat_access_token($force_refresh = false) {
        $cached = $force_refresh ? null : get_transient('myshop_wechat_access_token');
        if ($cached) {
            return $cached;
        }

        $appid = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : get_option('myshop_wechat_appid');
        $secret = defined('MYSHOP_MINIAPP_APP_SECRET') ? MYSHOP_MINIAPP_APP_SECRET : get_option('myshop_wechat_secret');
        if (empty($appid) || empty($secret)) {
            error_log('[GiftCard] access token missing appid/secret');
            return null;
        }

        $response = wp_remote_post('https://api.weixin.qq.com/cgi-bin/stable_token', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'grant_type' => 'client_credential',
                'appid' => $appid,
                'secret' => $secret,
                'force_refresh' => $force_refresh ? true : false
            ]),
            'timeout' => 15
        ]);
        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        $expires = isset($data['expires_in']) ? max(300, ((int) $data['expires_in']) - 120) : 6600;
        set_transient('myshop_wechat_access_token', $data['access_token'], $expires);

        return $data['access_token'];
    }

    /**
     * 获取分享样式配置
     * 
     * @param string $style_id 样式ID
     * @return array|null 样式配置
     */
    private static function get_share_style_config($style_id) {
        $option_styles = get_option('myshop_giftcard_share_styles', []);
        if (is_array($option_styles)) {
            foreach ($option_styles as $style) {
                if (!is_array($style)) {
                    continue;
                }
                if (!empty($style['id']) && $style['id'] === $style_id) {
                    return $style;
                }
            }
        }

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
            error_log('[GiftCard] styled qr returned null, fallback to qrserver');
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
        if (!is_array($style_config)) {
            $style_config = [];
        }
        // 图片尺寸
        $canvas_size = isset($style_config['canvas_size']) ? absint($style_config['canvas_size']) : 600;
        if ($canvas_size <= 0) {
            $canvas_size = 600;
        }
        $canvas_width = $canvas_size;
        $canvas_height = $canvas_size;
        $qr_size = isset($style_config['qr_size']) ? absint($style_config['qr_size']) : 360;
        if ($qr_size <= 0) {
            $qr_size = 360;
        }
        $padding = isset($style_config['padding']) ? absint($style_config['padding']) : 40;

        // 创建画布
        $canvas = imagecreatetruecolor($canvas_width, $canvas_height);
        if (!$canvas) {
            return null;
        }

        // 设置背景色（从样式配置或使用默认）
        $bg_color = imagecolorallocate($canvas, 255, 255, 255); // 默认白色
        if (isset($style_config['background_color'])) {
            $bg_rgb = self::hex_to_rgb($style_config['background_color']);
            if ($bg_rgb) {
                $bg_color = imagecolorallocate($canvas, $bg_rgb['r'], $bg_rgb['g'], $bg_rgb['b']);
            }
        }
        imagefill($canvas, 0, 0, $bg_color);

        // 如果有背景图片，加载并绘制
        if (!empty($style_config['background_image'])) {
            $bg_image = self::load_image_from_url($style_config['background_image']);
            if ($bg_image) {
                $bg_w = imagesx($bg_image);
                $bg_h = imagesy($bg_image);
                imagecopyresampled($canvas, $bg_image, 0, 0, 0, 0, $canvas_width, $canvas_height, $bg_w, $bg_h);
            }
        }

        // 生成二维码图片（使用在线服务生成基础二维码）
        $qr_image_url = !empty($style_config['qr_image_url'])
            ? $style_config['qr_image_url']
            : 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qr_size . 'x' . $qr_size . '&data=' . rawurlencode($qr_payload);
        error_log('[GiftCard] styled qr: qr_image_url=' . $qr_image_url);
        $qr_image = self::load_image_from_url($qr_image_url);
        if (!$qr_image && !empty($qr_payload)) {
            $fallback_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qr_size . 'x' . $qr_size . '&data=' . rawurlencode($qr_payload);
            error_log('[GiftCard] styled qr: fallback url=' . $fallback_url);
            $qr_image = self::load_image_from_url($fallback_url);
        }
        if (!$qr_image && !empty($style_config['qr_placeholder_image'])) {
            error_log('[GiftCard] styled qr: placeholder=' . $style_config['qr_placeholder_image']);
            $qr_image = self::load_image_from_url($style_config['qr_placeholder_image']);
        }
        if (!$qr_image) {
            error_log('[GiftCard] styled qr: qr image load failed');
            return null;
        }
        
        // 计算二维码位置（居中，根据祝福语动态调整）
        $qr_x = (int) round(($canvas_width - $qr_size) / 2);
        $message_top = isset($style_config['message_top']) ? absint($style_config['message_top']) : ($padding + 30);
        $message_font_size = isset($style_config['message_font_size']) ? absint($style_config['message_font_size']) : 28;
        $message_line_height = isset($style_config['message_line_height']) ? absint($style_config['message_line_height']) : 38;
        $message_max_chars = isset($style_config['message_max_chars']) ? absint($style_config['message_max_chars']) : 15;

        $message_lines = [];
        if ($message) {
            $message_lines = self::wrap_text($message, $message_max_chars, $canvas_width - ($padding * 2));
        }
        $message_height = $message_lines ? count($message_lines) * $message_line_height : 0;
        $qr_y = isset($style_config['qr_top']) ? absint($style_config['qr_top']) : ($message_top + $message_height + 20);
        if ($qr_y < $padding) {
            $qr_y = $padding;
        }
        
        if ($qr_image) {
            $src_w = imagesx($qr_image);
            $src_h = imagesy($qr_image);
            $dest_w = (int) round($qr_size);
            $dest_h = (int) round($qr_size);
            if ($src_w === $dest_w && $src_h === $dest_h) {
                imagecopy($canvas, $qr_image, (int) round($qr_x), (int) round($qr_y), 0, 0, $dest_w, $dest_h);
            } else {
                imagecopyresized(
                    $canvas,
                    $qr_image,
                    (int) round($qr_x),
                    (int) round($qr_y),
                    0,
                    0,
                    $dest_w,
                    $dest_h,
                    $src_w,
                    $src_h
                );
            }
        }

        // 添加文字信息
        $text_color = imagecolorallocate($canvas, 51, 51, 51); // 深灰色，更美观
        $hint_color = imagecolorallocate($canvas, 153, 153, 153); // 浅灰色，用于提示文字
        if (isset($style_config['text_color'])) {
            $text_rgb = self::hex_to_rgb($style_config['text_color']);
            if ($text_rgb) {
                $text_color = imagecolorallocate($canvas, $text_rgb['r'], $text_rgb['g'], $text_rgb['b']);
            }
        }

        $font_path = self::get_font_path();
        $use_ttf = $font_path && function_exists('imagettftext');

        // 添加祝福语（在二维码上方，美化样式）
        if ($message) {
            $message_y = $message_top;
            if ($use_ttf) {
                $line_height = $message_line_height;
                $font_size = $message_font_size;

                foreach ($message_lines as $index => $line) {
                    // 计算文字居中位置
                    $bbox = imagettfbbox($font_size, 0, $font_path, $line);
                    $text_width = $bbox[4] - $bbox[0];
                    $text_x = (int) round(($canvas_width - $text_width) / 2);
                    $y_pos = (int) round($message_y + ($index * $line_height));
                    imagettftext($canvas, $font_size, 0, $text_x, $y_pos, $text_color, $font_path, $line);
                }
            } else {
                // 使用内置字体，居中显示
                $text_x = (int) round(($canvas_width - mb_strlen($message, 'UTF-8') * 6) / 2);
                imagestring($canvas, 3, $text_x, (int) round($message_y), mb_substr($message, 0, 30, 'UTF-8'), $text_color);
            }
        }

        // 添加提示文字（在二维码下方，美化样式）
        $hint_text = !empty($style_config['hint_text']) ? $style_config['hint_text'] : '长按或扫码识别领取购物卡';
        $hint_font_size = isset($style_config['hint_font_size']) ? absint($style_config['hint_font_size']) : 20;
        $hint_y = isset($style_config['hint_top']) ? absint($style_config['hint_top']) : ($qr_y + $qr_size + 25);
        if ($use_ttf) {
            // 计算文字居中位置，使用稍小的字体
            $bbox = imagettfbbox($hint_font_size, 0, $font_path, $hint_text);
            $text_width = $bbox[4] - $bbox[0];
            $text_x = (int) round(($canvas_width - $text_width) / 2);
            imagettftext($canvas, $hint_font_size, 0, $text_x, (int) round($hint_y), $hint_color, $font_path, $hint_text);
        } else {
            $text_x = (int) round(($canvas_width - mb_strlen($hint_text, 'UTF-8') * 6) / 2);
            imagestring($canvas, 2, $text_x, (int) round($hint_y - 10), mb_substr($hint_text, 0, 20, 'UTF-8'), $hint_color);
        }

        // 保存图片到服务器
        $upload_dir = wp_upload_dir();
        $giftcard_dir = $upload_dir['basedir'] . '/giftcards';
        if (!file_exists($giftcard_dir)) {
            wp_mkdir_p($giftcard_dir);
        }

        $filename = 'giftcard-' . $token . '-' . time() . '.png';
        $filepath = $giftcard_dir . '/' . $filename;
        $baseurl = set_url_scheme($upload_dir['baseurl'], 'https');
        $fileurl = $baseurl . '/giftcards/' . $filename;

        if (imagepng($canvas, $filepath, 9)) {
            error_log('[GiftCard] styled qr saved: ' . $filepath);
            return $fileurl;
        }

        error_log('[GiftCard] styled qr save failed: ' . $filepath);
        return null;
    }

    /**
     * 从URL加载图片
     */
    private static function load_image_from_url($url) {
        if (empty($url)) {
            return null;
        }

        // 如果是上传目录的URL，直接映射为本地路径，避免回源请求
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['baseurl']) && !empty($upload_dir['basedir'])) {
            if (strpos($url, $upload_dir['baseurl']) === 0) {
                $relative = ltrim(str_replace($upload_dir['baseurl'], '', $url), '/');
                $local_path = trailingslashit($upload_dir['basedir']) . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (file_exists($local_path)) {
                    $info = @getimagesize($local_path);
                    if ($info && !empty($info['mime'])) {
                        switch ($info['mime']) {
                            case 'image/jpeg':
                                return imagecreatefromjpeg($local_path);
                            case 'image/png':
                                return imagecreatefrompng($local_path);
                            case 'image/gif':
                                return imagecreatefromgif($local_path);
                        }
                    }
                }
            }
        }

        // 如果是本地文件路径
        if (strpos($url, 'http') !== 0) {
            if (file_exists($url)) {
                $info = @getimagesize($url);
                if ($info && !empty($info['mime'])) {
                    switch ($info['mime']) {
                        case 'image/jpeg':
                            return imagecreatefromjpeg($url);
                        case 'image/png':
                            return imagecreatefrompng($url);
                        case 'image/gif':
                            return imagecreatefromgif($url);
                    }
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

        $option_styles = get_option('myshop_giftcard_share_styles', []);
        if (is_array($option_styles)) {
            foreach ($option_styles as $style) {
                if (!is_array($style)) {
                    continue;
                }
                if (empty($style['id'])) {
                    continue;
                }
                $styles[] = [
                    'id' => $style['id'],
                    'name' => $style['name'] ?? $style['id'],
                    'preview_image' => $style['preview_image'] ?? '',
                    'config' => $style
                ];
            }
        }

        // 读取所有 share-*.json 文件（包括 share-style-*.json 和 share-default.json）
        $pattern = $styles_dir . 'share-*.json';
        $files = glob($pattern);

        if ($files && is_array($files)) {
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
        }

        if (empty($styles)) {
            $styles[] = [
                'id' => 'default',
                'name' => '默认样式',
                'preview_image' => 'https://dummyimage.com/300x400/1e3a8a/ffffff&text=%E9%BB%98%E8%AE%A4%E6%A0%B7%E5%BC%8F',
                'config' => [
                    'id' => 'default',
                    'name' => '默认样式',
                    'default_message' => '送你一份精心准备的好礼，愿你喜欢。'
                ]
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
