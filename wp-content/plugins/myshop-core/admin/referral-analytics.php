<?php
if (!defined('ABSPATH')) {
    exit;
}

class MyShop_Referral_Analytics_Manager {
    const PAGE_SLUG = 'myshop-referral-analytics';

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限访问该页面');
        }

        global $wpdb;

        $filters = self::get_filters();
        $tab = $filters['tab'];
        $date_range = self::get_date_range($filters['start_date'], $filters['end_date']);
        $start = $date_range['start'];
        $end = $date_range['end'];

        $referral_table = $wpdb->prefix . 'myshop_referrals';
        $ledger_table = $wpdb->prefix . 'myshop_point_ledger';
        $users_table = $wpdb->users;
        $order_stats_table = $wpdb->prefix . 'wc_order_stats';
        $has_order_stats = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $order_stats_table)) === $order_stats_table;

        ?>
        <div class="wrap">
            <h1>👥 社交裂变数据中心</h1>
            <p class="description">用于查看绑定关系、转化订单与奖励积分的核心数据。仅展示，不改变现有业务逻辑。</p>

            <?php self::render_tabs($tab); ?>

            <?php self::render_filters($filters, $date_range); ?>

            <?php
            if ($tab === 'bindings') {
                self::render_bindings($referral_table, $users_table, $filters, $start, $end);
            } elseif ($tab === 'orders') {
                self::render_orders($referral_table, $users_table, $order_stats_table, $has_order_stats, $filters, $start, $end);
            } elseif ($tab === 'points') {
                self::render_points($ledger_table, $users_table, $filters, $start, $end);
            } else {
                self::render_overview($referral_table, $ledger_table, $order_stats_table, $has_order_stats, $filters, $start, $end);
            }
            ?>
        </div>
        <?php
    }

    private static function render_tabs($tab) {
        $tabs = [
            'overview' => '总览',
            'bindings' => '绑定关系',
            'orders' => '裂变订单',
            'points' => '奖励积分'
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $class = $tab === $key ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $key], admin_url('admin.php')));
            echo '<a class="' . $class . '" href="' . $url . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    private static function render_filters($filters, $date_range) {
        ?>
        <form method="get" action="" style="margin: 12px 0 18px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr($filters['tab']); ?>" />
            <label>
                开始日期：
                <input type="date" name="start_date" value="<?php echo esc_attr($date_range['start_date']); ?>" />
            </label>
            <label style="margin-left: 10px;">
                结束日期：
                <input type="date" name="end_date" value="<?php echo esc_attr($date_range['end_date']); ?>" />
            </label>
            <label style="margin-left: 10px;">
                渠道：
                <input type="text" name="channel" placeholder="如 giftcard/qr/poster" value="<?php echo esc_attr($filters['channel']); ?>" />
            </label>
            <button type="submit" class="button">筛选</button>
        </form>
        <?php
    }

    private static function render_overview($referral_table, $ledger_table, $order_stats_table, $has_order_stats, $filters, $start, $end) {
        global $wpdb;

        $where = ['created_at BETWEEN %s AND %s'];
        $params = [$start, $end];
        if ($filters['channel'] !== '') {
            $where[] = 'channel_code = %s';
            $params[] = $filters['channel'];
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total_invitees = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} {$where_sql}",
            ...$params
        ));
        $first_completed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} {$where_sql} AND first_order_status = 'completed'",
            ...$params
        ));
        $first_pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} {$where_sql} AND first_order_status = 'pending'",
            ...$params
        ));

        $points_total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$ledger_table}
             WHERE created_at BETWEEN %s AND %s AND type = 'earn' AND status = 'confirmed' AND channel LIKE %s",
            $start,
            $end,
            'referral_reward%'
        ));

        $orders_total = 0;
        $orders_gmv = 0.0;
        if ($has_order_stats) {
            $order_where = ['os.date_created BETWEEN %s AND %s', "os.status IN ('wc-processing','wc-completed','wc-on-hold')"];
            $order_params = [$start, $end];
            if ($filters['channel'] !== '') {
                $order_where[] = 'r.channel_code = %s';
                $order_params[] = $filters['channel'];
            }
            $order_where_sql = 'WHERE ' . implode(' AND ', $order_where);

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS total_orders, COALESCE(SUM(os.total_sales), 0) AS gmv
                 FROM {$order_stats_table} os
                 INNER JOIN {$referral_table} r ON r.invitee_id = os.customer_id
                 {$order_where_sql}",
                ...$order_params
            ));
            if ($row) {
                $orders_total = (int) $row->total_orders;
                $orders_gmv = (float) $row->gmv;
            }
        }

        ?>
        <div style="display:flex; gap:16px; flex-wrap:wrap;">
            <div class="card" style="padding:16px; min-width:180px;">
                <h3>新增绑定</h3>
                <p style="font-size:20px; margin:0;"><?php echo (int) $total_invitees; ?></p>
                <small>筛选时间内新增绑定人数</small>
            </div>
            <div class="card" style="padding:16px; min-width:180px;">
                <h3>首单完成</h3>
                <p style="font-size:20px; margin:0;"><?php echo (int) $first_completed; ?></p>
                <small>已完成首单的邀请人数</small>
            </div>
            <div class="card" style="padding:16px; min-width:180px;">
                <h3>首单待完成</h3>
                <p style="font-size:20px; margin:0;"><?php echo (int) $first_pending; ?></p>
                <small>仍未完成首单的邀请人数</small>
            </div>
            <div class="card" style="padding:16px; min-width:180px;">
                <h3>裂变订单数</h3>
                <p style="font-size:20px; margin:0;"><?php echo (int) $orders_total; ?></p>
                <small>被推荐人下单次数</small>
            </div>
            <div class="card" style="padding:16px; min-width:180px;">
                <h3>裂变 GMV</h3>
                <p style="font-size:20px; margin:0;"><?php echo esc_html(number_format($orders_gmv, 2)); ?></p>
                <small>被推荐人订单金额合计</small>
            </div>
            <div class="card" style="padding:16px; min-width:180px;">
                <h3>奖励积分</h3>
                <p style="font-size:20px; margin:0;"><?php echo (int) $points_total; ?></p>
                <small>发放的推荐奖励积分</small>
            </div>
        </div>

        <?php if (!$has_order_stats): ?>
            <p style="margin-top:12px;color:#a00;">⚠️ 未检测到 WooCommerce 订单统计表（wc_order_stats），裂变订单统计暂不可用。</p>
        <?php endif; ?>
        <p style="margin-top:10px;color:#666;">积分汇总暂不按渠道过滤，默认统计全部推荐奖励。</p>
        <?php
    }

    private static function render_bindings($referral_table, $users_table, $filters, $start, $end) {
        global $wpdb;

        $page = max(1, $filters['paged']);
        $per_page = $filters['per_page'];
        $offset = ($page - 1) * $per_page;

        $where = ['r.created_at BETWEEN %s AND %s'];
        $params = [$start, $end];
        if ($filters['channel'] !== '') {
            $where[] = 'r.channel_code = %s';
            $params[] = $filters['channel'];
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referral_table} r {$where_sql}",
            ...$params
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.inviter_id, r.invitee_id, r.level, r.channel_code, r.first_order_status, r.created_at,
                    inviter.display_name AS inviter_name, invitee.display_name AS invitee_name
             FROM {$referral_table} r
             LEFT JOIN {$users_table} inviter ON inviter.ID = r.inviter_id
             LEFT JOIN {$users_table} invitee ON invitee.ID = r.invitee_id
             {$where_sql}
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d",
            ...array_merge($params, [$per_page, $offset])
        ));

        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>邀请人</th>
                    <th>被邀请人</th>
                    <th>层级</th>
                    <th>渠道</th>
                    <th>首单状态</th>
                    <th>绑定时间</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;">暂无数据</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html(($row->inviter_name ?: '用户#' . (int) $row->inviter_id)); ?></td>
                        <td><?php echo esc_html(($row->invitee_name ?: '用户#' . (int) $row->invitee_id)); ?></td>
                        <td><?php echo (int) $row->level; ?></td>
                        <td><?php echo esc_html($row->channel_code ?: '-'); ?></td>
                        <td><?php echo esc_html($row->first_order_status); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php self::render_pagination($total, $page, $per_page, $filters); ?>
        <?php
    }

    private static function render_orders($referral_table, $users_table, $order_stats_table, $has_order_stats, $filters, $start, $end) {
        if (!$has_order_stats) {
            echo '<p style="color:#a00;">未检测到 WooCommerce 订单统计表（wc_order_stats），无法展示裂变订单。</p>';
            return;
        }

        global $wpdb;

        $page = max(1, $filters['paged']);
        $per_page = $filters['per_page'];
        $offset = ($page - 1) * $per_page;

        $where = ["os.date_created BETWEEN %s AND %s", "os.status IN ('wc-processing','wc-completed','wc-on-hold')"];
        $params = [$start, $end];
        if ($filters['channel'] !== '') {
            $where[] = 'r.channel_code = %s';
            $params[] = $filters['channel'];
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$order_stats_table} os
             INNER JOIN {$referral_table} r ON r.invitee_id = os.customer_id
             {$where_sql}",
            ...$params
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT os.order_id, os.customer_id, os.total_sales, os.status, os.date_created,
                    r.inviter_id, r.level, r.channel_code,
                    inviter.display_name AS inviter_name, invitee.display_name AS invitee_name
             FROM {$order_stats_table} os
             INNER JOIN {$referral_table} r ON r.invitee_id = os.customer_id
             LEFT JOIN {$users_table} inviter ON inviter.ID = r.inviter_id
             LEFT JOIN {$users_table} invitee ON invitee.ID = os.customer_id
             {$where_sql}
             ORDER BY os.date_created DESC
             LIMIT %d OFFSET %d",
            ...array_merge($params, [$per_page, $offset])
        ));

        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>订单号</th>
                    <th>被邀请人</th>
                    <th>邀请人</th>
                    <th>层级</th>
                    <th>渠道</th>
                    <th>订单金额</th>
                    <th>状态</th>
                    <th>下单时间</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" style="text-align:center;color:#999;">暂无数据</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo (int) $row->order_id; ?></td>
                        <td><?php echo esc_html(($row->invitee_name ?: '用户#' . (int) $row->customer_id)); ?></td>
                        <td><?php echo esc_html(($row->inviter_name ?: '用户#' . (int) $row->inviter_id)); ?></td>
                        <td><?php echo (int) $row->level; ?></td>
                        <td><?php echo esc_html($row->channel_code ?: '-'); ?></td>
                        <td><?php echo esc_html(number_format((float) $row->total_sales, 2)); ?></td>
                        <td><?php echo esc_html($row->status); ?></td>
                        <td><?php echo esc_html($row->date_created); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php self::render_pagination($total, $page, $per_page, $filters); ?>
        <?php
    }

    private static function render_points($ledger_table, $users_table, $filters, $start, $end) {
        global $wpdb;

        $page = max(1, $filters['paged']);
        $per_page = $filters['per_page'];
        $offset = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ledger_table}
             WHERE created_at BETWEEN %s AND %s AND channel LIKE %s",
            $start,
            $end,
            'referral_reward%'
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.user_id, l.delta, l.balance_after, l.reference_order_id, l.channel, l.created_at,
                    u.display_name AS user_name
             FROM {$ledger_table} l
             LEFT JOIN {$users_table} u ON u.ID = l.user_id
             WHERE l.created_at BETWEEN %s AND %s AND l.channel LIKE %s
             ORDER BY l.created_at DESC
             LIMIT %d OFFSET %d",
            $start,
            $end,
            'referral_reward%',
            $per_page,
            $offset
        ));

        ?>
        <p style="color:#666;">积分明细为推荐奖励积分流水（暂不按渠道过滤）。</p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>用户</th>
                    <th>积分变动</th>
                    <th>余额</th>
                    <th>关联订单</th>
                    <th>渠道</th>
                    <th>时间</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;">暂无数据</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html(($row->user_name ?: '用户#' . (int) $row->user_id)); ?></td>
                        <td><?php echo (int) $row->delta; ?></td>
                        <td><?php echo (int) $row->balance_after; ?></td>
                        <td><?php echo $row->reference_order_id ? (int) $row->reference_order_id : '-'; ?></td>
                        <td><?php echo esc_html($row->channel); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php self::render_pagination($total, $page, $per_page, $filters); ?>
        <?php
    }

    private static function render_pagination($total, $page, $per_page, $filters) {
        $total_pages = $per_page ? (int) ceil($total / $per_page) : 1;
        if ($total_pages <= 1) {
            return;
        }

        echo '<div style="margin-top: 12px;">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $link = add_query_arg([
                'page' => self::PAGE_SLUG,
                'tab' => $filters['tab'],
                'start_date' => $filters['start_date'],
                'end_date' => $filters['end_date'],
                'channel' => $filters['channel'],
                'paged' => $i
            ], admin_url('admin.php'));
            if ($i === $page) {
                echo '<span style="margin-right:6px;font-weight:bold;">' . $i . '</span>';
            } else {
                echo '<a style="margin-right:6px;" href="' . esc_url($link) . '">' . $i . '</a>';
            }
        }
        echo '</div>';
    }

    private static function get_filters() {
        return [
            'tab' => sanitize_key($_GET['tab'] ?? 'overview'),
            'start_date' => sanitize_text_field($_GET['start_date'] ?? ''),
            'end_date' => sanitize_text_field($_GET['end_date'] ?? ''),
            'channel' => sanitize_text_field($_GET['channel'] ?? ''),
            'paged' => max(1, absint($_GET['paged'] ?? 1)),
            'per_page' => 20
        ];
    }

    private static function get_date_range($start_date, $end_date) {
        $today = current_time('Y-m-d');
        if (!$end_date) {
            $end_date = $today;
        }
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days', current_time('timestamp')));
        }

        $start = $start_date . ' 00:00:00';
        $end = $end_date . ' 23:59:59';

        return [
            'start' => $start,
            'end' => $end,
            'start_date' => $start_date,
            'end_date' => $end_date
        ];
    }
}

