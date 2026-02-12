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
            <?php if ($filters['tab'] === 'bindings'): ?>
                <label style="margin-left: 10px;">
                    邀请人ID：
                    <input type="number" name="inviter_id" placeholder="仅绑定关系" value="<?php echo esc_attr($filters['inviter_id']); ?>" />
                </label>
            <?php endif; ?>
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
        $inviter_focus_id = (int) $filters['inviter_id'];

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
                    inviter.display_name AS inviter_name, inviter.user_login AS inviter_login,
                    invitee.display_name AS invitee_name, invitee.user_login AS invitee_login
             FROM {$referral_table} r
             LEFT JOIN {$users_table} inviter ON inviter.ID = r.inviter_id
             LEFT JOIN {$users_table} invitee ON invitee.ID = r.invitee_id
             {$where_sql}
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d",
            ...array_merge($params, [$per_page, $offset])
        ));

        ?>
        <?php self::render_inviter_ranking($referral_table, $users_table); ?>
        <?php if ($inviter_focus_id > 0): ?>
            <?php self::render_inviter_tree($referral_table, $users_table, $inviter_focus_id); ?>
        <?php endif; ?>
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
                        <td><?php echo self::render_user_link($row->inviter_name, $row->inviter_login, (int) $row->inviter_id); ?></td>
                        <td><?php echo self::render_user_link($row->invitee_name, $row->invitee_login, (int) $row->invitee_id); ?></td>
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
                    inviter.display_name AS inviter_name, inviter.user_login AS inviter_login,
                    invitee.display_name AS invitee_name, invitee.user_login AS invitee_login
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
                        <td><?php echo self::render_user_link($row->invitee_name, $row->invitee_login, (int) $row->customer_id); ?></td>
                        <td><?php echo self::render_user_link($row->inviter_name, $row->inviter_login, (int) $row->inviter_id); ?></td>
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
                    u.display_name AS user_name, u.user_login AS user_login
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
                        <td><?php echo self::render_user_link($row->user_name, $row->user_login, (int) $row->user_id); ?></td>
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
                'inviter_id' => $filters['inviter_id'],
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
            'inviter_id' => absint($_GET['inviter_id'] ?? 0),
            'paged' => max(1, absint($_GET['paged'] ?? 1)),
            'per_page' => 20
        ];
    }

    private static function render_inviter_tree($referral_table, $users_table, $inviter_id) {
        global $wpdb;

        $inviter_id = (int) $inviter_id;
        if ($inviter_id <= 0) {
            return;
        }

        $inviter = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, display_name, user_login FROM {$users_table} WHERE ID = %d",
            $inviter_id
        ));

        $inviter_level = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT level FROM {$referral_table} WHERE invitee_id = %d LIMIT 1",
            $inviter_id
        ));
        if ($inviter_level < 0) {
            $inviter_level = 0;
        }

        $pattern = '%/' . $inviter_id . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.inviter_id, r.invitee_id, r.level, r.path, r.channel_code, r.first_order_status, r.created_at,
                    invitee.display_name AS invitee_name, invitee.user_login AS invitee_login
             FROM {$referral_table} r
             LEFT JOIN {$users_table} invitee ON invitee.ID = r.invitee_id
             WHERE r.inviter_id = %d OR r.path LIKE %s
             ORDER BY r.level ASC, r.created_at ASC",
            $inviter_id,
            $pattern
        ));

        $level_counts = [];
        $level1 = [];
        $level2_by_parent = [];
        $total = 0;

        foreach ($rows as $row) {
            $path = $row->path ?: '';
            $matches_inviter = self::path_contains_user($path, $inviter_id) || (int) $row->inviter_id === $inviter_id;
            if (!$matches_inviter) {
                continue;
            }
            $relative_level = (int) $row->level - $inviter_level;
            if ($relative_level <= 0) {
                continue;
            }
            $total += 1;
            if (!isset($level_counts[$relative_level])) {
                $level_counts[$relative_level] = 0;
            }
            $level_counts[$relative_level] += 1;

            if ($relative_level === 1) {
                $level1[(int) $row->invitee_id] = $row;
            } elseif ($relative_level === 2) {
                $parent_id = (int) $row->inviter_id;
                if (!isset($level2_by_parent[$parent_id])) {
                    $level2_by_parent[$parent_id] = [];
                }
                $level2_by_parent[$parent_id][] = $row;
            }
        }

        $inviter_label = $inviter
            ? self::format_user_label($inviter->display_name ?? '', $inviter->user_login ?? '', $inviter_id)
            : '用户#' . $inviter_id;
        ?>
        <div style="padding: 12px 16px; margin-bottom: 16px; background: #fff; border: 1px solid #e5e5e5;">
            <h3 style="margin:0 0 10px;">邀请人详情：<?php echo esc_html($inviter_label); ?></h3>
            <p style="margin:0 0 8px;">全级别推荐人数：<?php echo (int) $total; ?></p>
            <?php if (!empty($level_counts)): ?>
                <table class="widefat striped" style="max-width:360px;">
                    <thead><tr><th>层级</th><th>人数</th></tr></thead>
                    <tbody>
                        <?php foreach ($level_counts as $lvl => $count): ?>
                            <tr>
                                <td><?php echo (int) $lvl; ?> 级</td>
                                <td><?php echo (int) $count; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color:#888;">暂无下级关系数据。</p>
            <?php endif; ?>

            <h4 style="margin:16px 0 8px;">一级 / 二级树状关系</h4>
            <?php if (empty($level1)): ?>
                <p style="color:#888;">暂无一级邀请用户。</p>
            <?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($level1 as $user_id => $row): ?>
                        <?php
                        $label = self::format_user_label($row->invitee_name ?? '', $row->invitee_login ?? '', (int) $row->invitee_id);
                        $children = $level2_by_parent[$user_id] ?? [];
                        ?>
                        <li style="margin-bottom:6px;">
                            <details>
                                <summary>
                                    <?php echo self::render_user_link($row->invitee_name ?? '', $row->invitee_login ?? '', (int) $row->invitee_id); ?>
                                    <span style="color:#666;">（二级 <?php echo (int) count($children); ?>）</span>
                                </summary>
                                <?php if (empty($children)): ?>
                                    <div style="margin:6px 0 0 12px; color:#888;">暂无二级邀请用户</div>
                                <?php else: ?>
                                    <ul style="margin:6px 0 0 12px;">
                                        <?php foreach ($children as $child): ?>
                                            <li>
                                                <?php echo self::render_user_link($child->invitee_name ?? '', $child->invitee_login ?? '', (int) $child->invitee_id); ?>
                                                <span style="color:#888;">（<?php echo esc_html($child->created_at); ?>）</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </details>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_inviter_ranking($referral_table, $users_table) {
        global $wpdb;

        $start = date('Y-m-d 00:00:00', strtotime('-30 days', current_time('timestamp')));
        $end = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.inviter_id, COUNT(*) AS total,
                    u.display_name AS inviter_name, u.user_login AS inviter_login
             FROM {$referral_table} r
             LEFT JOIN {$users_table} u ON u.ID = r.inviter_id
             WHERE r.created_at BETWEEN %s AND %s
             GROUP BY r.inviter_id
             ORDER BY total DESC
             LIMIT 10",
            $start,
            $end
        ));

        ?>
        <div style="margin: 0 0 16px; padding: 12px 16px; background:#fff; border:1px solid #e5e5e5;">
            <h3 style="margin:0 0 10px;">邀请人排行（近30天新增下级）</h3>
            <table class="widefat striped" style="max-width: 520px;">
                <thead>
                    <tr>
                        <th>邀请人</th>
                        <th style="width:120px;">新增人数</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="2" style="text-align:center;color:#999;">暂无数据</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo self::render_user_link($row->inviter_name, $row->inviter_login, (int) $row->inviter_id); ?></td>
                            <td><?php echo (int) $row->total; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <p style="margin:8px 0 0;color:#666;">默认统计最近30天新增绑定人数，Top 10。</p>
        </div>
        <?php
    }

    private static function render_user_link($display_name, $user_login, $user_id) {
        $label = self::format_user_label($display_name, $user_login, $user_id);
        $url = admin_url('user-edit.php?user_id=' . (int) $user_id);
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
    }

    private static function format_user_label($display_name, $user_login, $user_id) {
        $name = trim((string) $display_name);
        if ($name === '' || $name === '微信用户') {
            $name = trim((string) $user_login);
        }
        if ($name === '') {
            $name = '用户#' . (int) $user_id;
        }
        return $name . '（ID:' . (int) $user_id . '）';
    }

    private static function path_contains_user($path, $user_id) {
        if (!$path) {
            return false;
        }
        $pattern = '/(^|\\/)' . preg_quote((string) $user_id, '/') . '(\\/|$)/';
        return preg_match($pattern, $path) === 1;
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
