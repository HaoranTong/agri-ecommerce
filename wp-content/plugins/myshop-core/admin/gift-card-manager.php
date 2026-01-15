<?php
if (!defined('ABSPATH')) {
    exit;
}

class MyShop_Gift_Card_Manager {
    const PAGE_SLUG = 'myshop-gift-card-center';
    const STATUS_MAP = [
        'pending_activation' => '待激活',
        'active'             => '可用',
        'locked'             => '已锁定',
        'void'               => '已作废',
        'redeemed'           => '已兑换',
        'expired'            => '已过期'
    ];

    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_myshop_lock_gift_card', [self::class, 'handle_status_change']);
        add_action('admin_post_myshop_unlock_gift_card', [self::class, 'handle_status_change']);
        add_action('admin_post_myshop_void_gift_card', [self::class, 'handle_status_change']);
    }

    public static function register_menu() {
        add_menu_page(
            __('购物卡资产管理', 'myshop'),
            __('购物卡管理', 'myshop'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [self::class, 'render_page'],
            'dashicons-portfolio',
            58
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('无权限访问该页面', 'myshop'));
        }

        $filters = self::get_filters();
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            self::export_cards($filters);
            exit;
        }

        $records = self::query_cards($filters);

        if (!empty($_GET['gc_notice'])) {
            self::render_notice(sanitize_text_field($_GET['gc_notice']));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('购物卡资产管理', 'myshop') . '</h1>';
        echo '<p class="description">' . esc_html__('支持根据卡号、用户、状态进行筛选，可执行锁定/作废等安全操作。', 'myshop') . '</p>';
        echo '<style>
            .myshop-giftcard-filters input[type="text"],
            .myshop-giftcard-filters input[type="number"],
            .myshop-giftcard-filters select { min-width: 120px; }
            .link-danger { color: #c62828; }
            .card-history-panel ul { list-style: disc; padding-left: 20px; }
        </style>';

        self::render_filter_form($filters);
        self::render_table($records, $filters);

        if (!empty($_GET['show_history']) && !empty($filters['card_number'])) {
            self::render_history_panel($filters['card_number']);
        }
        echo '</div>';
    }

    private static function get_filters() {
        return [
            'card_number' => isset($_GET['card_number']) ? sanitize_text_field($_GET['card_number']) : '',
            'holder'      => isset($_GET['holder']) ? sanitize_text_field($_GET['holder']) : '',
            'status'      => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'per_page'    => isset($_GET['per_page']) ? max(10, min(100, absint($_GET['per_page']))) : 20,
            'paged'       => isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1,
        ];
    }

    private static function query_cards($filters) {
        global $wpdb;

        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $users_table = $wpdb->users;
        $where = ['1=1'];

        if ($filters['card_number']) {
            $where[] = $wpdb->prepare('c.card_number LIKE %s', '%' . $filters['card_number'] . '%');
        }
        if ($filters['holder']) {
            $like = '%' . $filters['holder'] . '%';
            $where[] = $wpdb->prepare('(p.user_login LIKE %s OR p.user_email LIKE %s OR r.user_login LIKE %s OR r.user_email LIKE %s)', $like, $like, $like, $like);
        }
        if ($filters['status'] && isset(self::STATUS_MAP[$filters['status']])) {
            $where[] = $wpdb->prepare('c.status = %s', $filters['status']);
        }

        $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($filters['paged'] - 1) * $filters['per_page'];

        $sql = "
            SELECT SQL_CALC_FOUND_ROWS
                c.*,
                p.user_login AS purchaser_login,
                p.user_email AS purchaser_email,
                r.user_login AS redeemer_login,
                r.user_email AS redeemer_email
            FROM {$cards_table} c
            LEFT JOIN {$users_table} p ON p.ID = c.purchaser_id
            LEFT JOIN {$users_table} r ON r.ID = c.redeemer_id
            {$where_clause}
            ORDER BY c.created_at DESC
            LIMIT %d OFFSET %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $filters['per_page'], $offset));
        $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

        return [
            'items' => $rows,
            'total' => $total,
            'pages' => $filters['per_page'] ? ceil($total / $filters['per_page']) : 1,
        ];
    }

    private static function render_filter_form($filters) {
        echo '<form method="get" class="myshop-giftcard-filters" style="margin:20px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
        echo '<label style="margin-right:12px;">卡号：<input type="text" name="card_number" value="' . esc_attr($filters['card_number']) . '" /></label>';
        echo '<label style="margin-right:12px;">用户（账号/邮箱）：<input type="text" name="holder" value="' . esc_attr($filters['holder']) . '" /></label>';
        echo '<label style="margin-right:12px;">状态：<select name="status">';
        echo '<option value="">' . esc_html__('全部', 'myshop') . '</option>';
        foreach (self::STATUS_MAP as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($filters['status'], $value, false), esc_html($label));
        }
        echo '</select></label>';
        echo '<label style="margin-right:12px;">每页显示：<input type="number" min="10" max="100" name="per_page" value="' . esc_attr($filters['per_page']) . '" style="width:70px;" /></label>';
        submit_button(__('筛选', 'myshop'), 'primary', '', false);
        echo '&nbsp;';
        echo '<button type="submit" name="export" value="csv" class="button button-secondary">' . esc_html__('导出 CSV', 'myshop') . '</button>';
        echo '</form>';
    }

    private static function render_table($records, $filters) {
        $items = $records['items'];
        $total = $records['total'];

        if (empty($items)) {
            echo '<div class="notice notice-info"><p>' . esc_html__('暂无符合条件的购物卡记录。', 'myshop') . '</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        $headers = [
            'card_number' => '卡号',
            'template'    => '模板/类型',
            'amount'      => '面值/余额',
            'holder'      => '当前持有人',
            'status'      => '状态',
            'share_state' => '分享状态',
            'updated_at'  => '更新时间',
            'actions'     => '操作',
        ];
        foreach ($headers as $key => $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($items as $card) {
            $holder_login = $card->redeemer_login ?: $card->purchaser_login ?: '-';
            $holder_email = $card->redeemer_email ?: $card->purchaser_email ?: '';
            // 确保状态值存在，如果为空则默认为 'active'
            // 使用 trim 和 strtolower 确保状态值正确匹配
            $card_status = trim(strtolower($card->status ?: 'active'));
            $status_label = self::STATUS_MAP[$card_status] ?? ($card_status ?: '未知');
            $share_state = $card->share_state ?: 'none';
            $actions = self::build_action_links($card);

            echo '<tr>';
            printf('<td><strong>%s</strong><br/><small>订单：#%s</small></td>', esc_html($card->card_number), esc_html($card->order_id ?: '-'));
            printf('<td>%s<br/><small>%s</small></td>', esc_html($card->template_name ?: '-'), esc_html($card->template_type ?: '-'));
            printf('<td>¥%s<br/><small>剩余 ¥%s</small></td>', esc_html(number_format_i18n((float) $card->initial_amount, 2)), esc_html(number_format_i18n((float) $card->balance, 2)));
            printf('<td>%s<br/><small>%s</small></td>', esc_html($holder_login), esc_html($holder_email));
            printf('<td>%s<br/><small>绑定：%s</small></td>', esc_html($status_label), esc_html($card->bind_status ?: 'bound'));
            printf('<td>%s</td>', esc_html($share_state === 'none' ? '未分享' : $share_state));
            printf('<td><small>%s</small></td>', esc_html($card->updated_at));
            printf('<td>%s</td>', $actions);
            echo '</tr>';
        }

        echo '</tbody></table>';

        self::render_pagination($records['pages'], $filters);
    }

    private static function build_action_links($card) {
        $actions = [];
        $base_args = [
            'page'        => self::PAGE_SLUG,
            'card_number' => $card->card_number,
        ];

        // 确保状态值存在，如果为空则默认为 'active'
        // 使用 trim 和 strtolower 确保状态值正确匹配
        $card_status = trim(strtolower($card->status ?: 'active'));

        if ($card_status === 'active') {
            $actions[] = self::build_post_link('myshop_lock_gift_card', $card->card_number, '锁定');
            $actions[] = self::build_post_link('myshop_void_gift_card', $card->card_number, '作废', true);
        } elseif ($card_status === 'pending_activation' || $card_status === 'locked') {
            $actions[] = self::build_post_link('myshop_unlock_gift_card', $card->card_number, '激活');
            $actions[] = self::build_post_link('myshop_void_gift_card', $card->card_number, '作废', true);
        } elseif (in_array($card_status, ['redeemed', 'expired', 'void'], true)) {
            // 对于已兑换、已过期、已作废的卡片，只显示查看记录
            // 不显示锁头图标，因为这不是一个可操作的状态
        } else {
            // 未知状态，显示锁头图标但添加提示，并提供激活按钮作为备用
            $actions[] = '<span class="dashicons dashicons-lock" title="状态：' . esc_attr($card_status) . '"></span>';
            // 对于未知状态，也提供激活按钮，以防状态值不匹配
            $actions[] = self::build_post_link('myshop_unlock_gift_card', $card->card_number, '激活');
        }

        $history_url = add_query_arg([
            'page'         => self::PAGE_SLUG,
            'card_number'  => $card->card_number,
            'show_history' => '1'
        ], admin_url('admin.php'));

        $actions[] = '<a href="' . esc_url($history_url) . '">' . esc_html__('查看记录', 'myshop') . '</a>';

        return implode(' | ', $actions);
    }

    private static function build_post_link($action, $card_number, $label, $danger = false) {
        $url = admin_url('admin-post.php');
        $form_id = $action . '_' . $card_number;
        $nonce = wp_create_nonce($action . '_' . $card_number);
        $class = $danger ? 'link-danger' : '';

        $form = sprintf(
            '<form id="%1$s" action="%2$s" method="post" style="display:none;">%3$s<input type="hidden" name="action" value="%4$s"/><input type="hidden" name="card_number" value="%5$s"/></form>',
            esc_attr($form_id),
            esc_url($url),
            wp_nonce_field($action . '_' . $card_number, '_wpnonce', true, false),
            esc_attr($action),
            esc_attr($card_number)
        );

        $link = sprintf(
            '<a href="#" class="%4$s" onclick="event.preventDefault();if(confirm(\'确认执行该操作？\')){document.getElementById(\'%1$s\').submit();}">%2$s</a>',
            esc_attr($form_id),
            esc_html($label),
            esc_attr($card_number),
            esc_attr($class)
        );

        return $form . $link;
    }

    private static function render_pagination($pages, $filters) {
        if ($pages <= 1) {
            return;
        }

        $current = $filters['paged'];
        $base_url = remove_query_arg('paged');
        echo '<div class="tablenav"><div class="tablenav-pages">';

        for ($i = 1; $i <= $pages; $i++) {
            $url = esc_url(add_query_arg(array_merge($filters, ['paged' => $i]), $base_url));
            $class = $i === $current ? 'class="page-numbers current"' : 'class="page-numbers"';
            echo "<a {$class} href=\"{$url}\">{$i}</a>";
        }

        echo '</div></div>';
    }

    private static function render_history_panel($card_number) {
        global $wpdb;
        $cards_table = $wpdb->prefix . 'myshop_gift_cards';
        $card = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$cards_table} WHERE card_number = %s", $card_number));

        if (!$card) {
            echo '<div class="notice notice-warning" style="margin-top:20px;"><p>' . esc_html__('未找到该卡片，可能已被删除。', 'myshop') . '</p></div>';
            return;
        }

        $logs_table = $wpdb->prefix . 'myshop_gift_card_share_logs';
        $redeem_table = $wpdb->prefix . 'myshop_gift_card_redemptions';

        $share_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$logs_table} WHERE card_id = %d ORDER BY created_at DESC LIMIT 20",
            $card->id
        ));
        $redeem_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$redeem_table} WHERE card_id = %d ORDER BY redeemed_at DESC LIMIT 20",
            $card->id
        ));

        echo '<div class="card-history-panel" style="margin-top:24px;">';
        echo '<h2>' . esc_html(sprintf('卡号 %s 操作记录', $card_number)) . '</h2>';

        echo '<h3>' . esc_html__('分享记录', 'myshop') . '</h3>';
        if (empty($share_logs)) {
            echo '<p>' . esc_html__('暂无分享记录。', 'myshop') . '</p>';
        } else {
            echo '<ul>';
            foreach ($share_logs as $log) {
                printf(
                    '<li>%s · %s (%s)</li>',
                    esc_html($log->created_at),
                    esc_html($log->delivery_mode ?: 'digital_share'),
                    esc_html($log->channel ?: 'miniprogram')
                );
            }
            echo '</ul>';
        }

        echo '<h3>' . esc_html__('使用记录', 'myshop') . '</h3>';
        if (empty($redeem_logs)) {
            echo '<p>' . esc_html__('暂无兑换/抵扣记录。', 'myshop') . '</p>';
        } else {
            echo '<ul>';
            foreach ($redeem_logs as $log) {
                printf(
                    '<li>%s · %s · ¥%s</li>',
                    esc_html($log->redeemed_at),
                    esc_html($log->redeem_type),
                    esc_html(number_format_i18n((float) $log->used_amount, 2))
                );
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    public static function handle_status_change() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('无权限执行此操作', 'myshop'));
        }

        $action = sanitize_key($_POST['action'] ?? '');
        $card_number = isset($_POST['card_number']) ? sanitize_text_field(wp_unslash($_POST['card_number'])) : '';

        if (!$card_number || !wp_verify_nonce($_POST['_wpnonce'] ?? '', $action . '_' . $card_number)) {
            wp_die(__('非法请求', 'myshop'));
        }

        $status_map = [
            'myshop_lock_gift_card'   => ['locked', '锁定成功'],
            'myshop_unlock_gift_card' => ['active', '激活成功'],
            'myshop_void_gift_card'   => ['void', '已作废']
        ];

        if (!isset($status_map[$action])) {
            wp_die(__('未知操作', 'myshop'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'myshop_gift_cards';
        $result = $wpdb->update(
            $table,
            [
                'status'     => $status_map[$action][0],
                'updated_at' => current_time('mysql', true)
            ],
            ['card_number' => $card_number],
            ['%s', '%s'],
            ['%s']
        );

        $notice = $result === false ? 'error' : $status_map[$action][1];
        wp_redirect(add_query_arg([
            'page'       => self::PAGE_SLUG,
            'gc_notice'  => $notice
        ], admin_url('admin.php')));
        exit;
    }

    private static function export_cards($filters) {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('无权限导出数据', 'myshop'));
        }

        $filters['per_page'] = 5000;
        $filters['paged'] = 1;
        $records = self::query_cards($filters);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=gift-cards-' . date('Ymd-His') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Card Number', 'Template', 'Type', 'Initial Amount', 'Balance', 'Status', 'Share State', 'Holder', 'Order ID', 'Updated At']);

        foreach ($records['items'] as $card) {
            fputcsv($output, [
                $card->card_number,
                $card->template_name,
                $card->template_type,
                $card->initial_amount,
                $card->balance,
                $card->status,
                $card->share_state,
                $card->redeemer_login ?: $card->purchaser_login,
                $card->order_id,
                $card->updated_at
            ]);
        }
        fclose($output);
        exit;
    }

    private static function render_notice($code) {
        $messages = [
            '锁定成功' => ['updated', '卡片已锁定。'],
            '激活成功' => ['updated', '卡片已激活。'],
            '已作废'   => ['error', '卡片已作废，无法恢复。'],
            'error'    => ['error', '操作失败，请查看日志。']
        ];
        $message = $messages[$code] ?? ['updated', $code];
        printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr($message[0]), esc_html($message[1]));
    }
}

MyShop_Gift_Card_Manager::init();

