<?php
/**
 * 分销佣金结算后台管理
 */

class MyShop_Commission_Manager {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu_page']);
        add_action('admin_post_myshop_update_commission_payout', [self::class, 'handle_update_payout']);
        add_action('admin_post_myshop_update_commission_status', [self::class, 'handle_update_commission_status']);
    }

    public static function add_menu_page() {
        add_menu_page(
            '分销结算',
            '💸 分销结算',
            'manage_options',
            'myshop-commission-payouts',
            [self::class, 'render_payouts_page'],
            'dashicons-money',
            60
        );

        add_submenu_page(
            'myshop-commission-payouts',
            '提现申请',
            '提现申请',
            'manage_options',
            'myshop-commission-payouts',
            [self::class, 'render_payouts_page']
        );

        add_submenu_page(
            'myshop-commission-payouts',
            '佣金明细',
            '佣金明细',
            'manage_options',
            'myshop-commission-ledger',
            [self::class, 'render_commissions_page']
        );
    }

    private static function render_notice() {
        if (empty($_GET['myshop_updated'])) {
            return;
        }

        $message = sanitize_text_field($_GET['myshop_updated']);
        $labels = [
            'payout_paid' => '提现已标记为已打款',
            'payout_rejected' => '提现已驳回',
            'payout_cancelled' => '提现已取消',
            'commission_approved' => '佣金已通过审核',
            'commission_rejected' => '佣金已驳回'
        ];

        if (isset($labels[$message])) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓ ' . esc_html($labels[$message]) . '</strong></p></div>';
        }
    }

    public static function render_payouts_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'myshop_commission_payouts';

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $earner_id = isset($_GET['earner_id']) ? absint($_GET['earner_id']) : 0;
        $batch = isset($_GET['batch']) ? sanitize_text_field($_GET['batch']) : '';
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = 20;

        $where = [];
        $params = [];

        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ($earner_id > 0) {
            $where[] = 'earner_id = %d';
            $params[] = $earner_id;
        }
        if ($batch !== '') {
            $where[] = 'settlement_batch = %s';
            $params[] = $batch;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$params
        ));

        $offset = ($page - 1) * $per_page;
        $params_with_limit = array_merge($params, [$per_page, $offset]);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, earner_id, amount, payout_method, account_name, account_no, bank_name,
                    settlement_batch, status, requested_at, paid_at, note
             FROM {$table}
             {$where_sql}
             ORDER BY requested_at DESC
             LIMIT %d OFFSET %d",
            ...$params_with_limit
        ));

        $status_labels = [
            'processing' => '<span style="color: #fa0;">⏳ 处理中</span>',
            'paid' => '<span style="color: #0a9;">✓ 已打款</span>',
            'rejected' => '<span style="color: #f44;">✗ 已驳回</span>',
            'cancelled' => '<span style="color: #999;">⊘ 已取消</span>'
        ];

        $total_pages = $per_page ? (int) ceil($total / $per_page) : 1;
        $base_url = admin_url('admin.php?page=myshop-commission-payouts');

        ?>
        <div class="wrap">
            <h1>💸 分销提现申请</h1>
            <p class="description">审核提现申请并发起打款，支持按状态与推广员筛选。</p>

            <?php self::render_notice(); ?>

            <form method="get" action="" style="margin: 10px 0 20px;">
                <input type="hidden" name="page" value="myshop-commission-payouts" />
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="processing" <?php selected($status, 'processing'); ?>>处理中</option>
                    <option value="paid" <?php selected($status, 'paid'); ?>>已打款</option>
                    <option value="rejected" <?php selected($status, 'rejected'); ?>>已驳回</option>
                    <option value="cancelled" <?php selected($status, 'cancelled'); ?>>已取消</option>
                </select>
                <input type="number" name="earner_id" placeholder="推广员ID" value="<?php echo esc_attr($earner_id ?: ''); ?>" />
                <input type="text" name="batch" placeholder="结算批次" value="<?php echo esc_attr($batch); ?>" />
                <button type="submit" class="button">筛选</button>
            </form>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>推广员</th>
                        <th>金额</th>
                        <th>方式</th>
                        <th>收款信息</th>
                        <th>批次</th>
                        <th>状态</th>
                        <th>申请时间</th>
                        <th>打款时间</th>
                        <th>备注</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; color: #999; padding: 12px;">暂无提现申请</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $user = get_userdata((int) $row->earner_id);
                            $user_label = $user ? $user->display_name . ' (#' . (int) $row->earner_id . ')' : '用户 #' . (int) $row->earner_id;
                            $user_link = admin_url('user-edit.php?user_id=' . (int) $row->earner_id);
                            $account_parts = array_filter([
                                $row->account_name ? '姓名: ' . $row->account_name : '',
                                $row->account_no ? '账号: ' . $row->account_no : '',
                                $row->bank_name ? '开户行: ' . $row->bank_name : ''
                            ]);
                            $account_info = $account_parts ? implode(' | ', $account_parts) : '-';
                            ?>
                            <tr>
                                <td><?php echo (int) $row->id; ?></td>
                                <td><a href="<?php echo esc_url($user_link); ?>" target="_blank"><?php echo esc_html($user_label); ?></a></td>
                                <td><strong><?php echo esc_html(number_format((float) $row->amount, 2)); ?></strong></td>
                                <td><?php echo esc_html($row->payout_method); ?></td>
                                <td><?php echo esc_html($account_info); ?></td>
                                <td><?php echo esc_html($row->settlement_batch ?: '-'); ?></td>
                                <td><?php echo $status_labels[$row->status] ?? esc_html($row->status); ?></td>
                                <td><?php echo esc_html($row->requested_at); ?></td>
                                <td><?php echo esc_html($row->paid_at ?: '-'); ?></td>
                                <td><?php echo esc_html($row->note ?: '-'); ?></td>
                                <td>
                                    <?php if ($row->status === 'processing'): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 6px;">
                                            <?php wp_nonce_field('myshop_update_commission_payout'); ?>
                                            <input type="hidden" name="action" value="myshop_update_commission_payout" />
                                            <input type="hidden" name="payout_id" value="<?php echo (int) $row->id; ?>" />
                                            <input type="hidden" name="action_type" value="mark_paid" />
                                            <input type="text" name="note" placeholder="备注" style="width: 120px;" />
                                            <button type="submit" class="button button-primary">标记已打款</button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('myshop_update_commission_payout'); ?>
                                            <input type="hidden" name="action" value="myshop_update_commission_payout" />
                                            <input type="hidden" name="payout_id" value="<?php echo (int) $row->id; ?>" />
                                            <input type="hidden" name="action_type" value="reject" />
                                            <input type="text" name="note" placeholder="驳回原因" style="width: 120px;" />
                                            <button type="submit" class="button">驳回</button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div style="margin-top: 15px;">
                    <?php
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $link = add_query_arg([
                            'page' => 'myshop-commission-payouts',
                            'paged' => $i,
                            'status' => $status,
                            'earner_id' => $earner_id ?: '',
                            'batch' => $batch
                        ], $base_url);
                        if ($i === $page) {
                            echo '<span style="margin-right: 6px; font-weight: bold;">' . $i . '</span>';
                        } else {
                            echo '<a style="margin-right: 6px;" href="' . esc_url($link) . '">' . $i . '</a>';
                        }
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_commissions_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'myshop_commissions';

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $earner_id = isset($_GET['earner_id']) ? absint($_GET['earner_id']) : 0;
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = 20;

        $where = [];
        $params = [];

        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ($type !== '') {
            $where[] = 'commission_type = %s';
            $params[] = $type;
        }
        if ($earner_id > 0) {
            $where[] = 'earner_id = %d';
            $params[] = $earner_id;
        }
        if ($order_id > 0) {
            $where[] = 'order_id = %d';
            $params[] = $order_id;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$params
        ));

        $offset = ($page - 1) * $per_page;
        $params_with_limit = array_merge($params, [$per_page, $offset]);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id, earner_id, amount, currency, commission_type, referrer_id, agent_id,
                    settlement_batch, status, expected_payout_at, paid_at, note, created_at
             FROM {$table}
             {$where_sql}
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            ...$params_with_limit
        ));

        $status_labels = [
            'pending' => '<span style="color: #fa0;">⏳ 待审核</span>',
            'approved' => '<span style="color: #0a9;">✓ 已通过</span>',
            'rejected' => '<span style="color: #f44;">✗ 已驳回</span>',
            'paid' => '<span style="color: #2271b1;">✔ 已结算</span>'
        ];

        $total_pages = $per_page ? (int) ceil($total / $per_page) : 1;
        $base_url = admin_url('admin.php?page=myshop-commission-ledger');

        ?>
        <div class="wrap">
            <h1>📒 佣金明细</h1>
            <p class="description">审核佣金与查询结算记录。</p>

            <?php self::render_notice(); ?>

            <form method="get" action="" style="margin: 10px 0 20px;">
                <input type="hidden" name="page" value="myshop-commission-ledger" />
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="pending" <?php selected($status, 'pending'); ?>>待审核</option>
                    <option value="approved" <?php selected($status, 'approved'); ?>>已通过</option>
                    <option value="rejected" <?php selected($status, 'rejected'); ?>>已驳回</option>
                    <option value="paid" <?php selected($status, 'paid'); ?>>已结算</option>
                </select>
                <select name="type">
                    <option value="">全部类型</option>
                    <option value="referral" <?php selected($type, 'referral'); ?>>邀请佣金</option>
                    <option value="agent" <?php selected($type, 'agent'); ?>>代理佣金</option>
                </select>
                <input type="number" name="earner_id" placeholder="推广员ID" value="<?php echo esc_attr($earner_id ?: ''); ?>" />
                <input type="number" name="order_id" placeholder="订单ID" value="<?php echo esc_attr($order_id ?: ''); ?>" />
                <button type="submit" class="button">筛选</button>
            </form>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>订单</th>
                        <th>推广员</th>
                        <th>金额</th>
                        <th>类型</th>
                        <th>状态</th>
                        <th>结算批次</th>
                        <th>预计结算</th>
                        <th>结算时间</th>
                        <th>备注</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; color: #999; padding: 12px;">暂无佣金记录</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $user = get_userdata((int) $row->earner_id);
                            $user_label = $user ? $user->display_name . ' (#' . (int) $row->earner_id . ')' : '用户 #' . (int) $row->earner_id;
                            $user_link = admin_url('user-edit.php?user_id=' . (int) $row->earner_id);
                            $order_link = admin_url('post.php?post=' . (int) $row->order_id . '&action=edit');
                            ?>
                            <tr>
                                <td><?php echo (int) $row->id; ?></td>
                                <td><a href="<?php echo esc_url($order_link); ?>" target="_blank">#<?php echo (int) $row->order_id; ?></a></td>
                                <td><a href="<?php echo esc_url($user_link); ?>" target="_blank"><?php echo esc_html($user_label); ?></a></td>
                                <td><strong><?php echo esc_html(number_format((float) $row->amount, 2)); ?></strong></td>
                                <td><?php echo esc_html($row->commission_type); ?></td>
                                <td><?php echo $status_labels[$row->status] ?? esc_html($row->status); ?></td>
                                <td><?php echo esc_html($row->settlement_batch ?: '-'); ?></td>
                                <td><?php echo esc_html($row->expected_payout_at ?: '-'); ?></td>
                                <td><?php echo esc_html($row->paid_at ?: '-'); ?></td>
                                <td><?php echo esc_html($row->note ?: '-'); ?></td>
                                <td>
                                    <?php if ($row->status === 'pending'): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 6px;">
                                            <?php wp_nonce_field('myshop_update_commission_status'); ?>
                                            <input type="hidden" name="action" value="myshop_update_commission_status" />
                                            <input type="hidden" name="commission_id" value="<?php echo (int) $row->id; ?>" />
                                            <input type="hidden" name="action_type" value="approve" />
                                            <button type="submit" class="button button-primary">通过</button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('myshop_update_commission_status'); ?>
                                            <input type="hidden" name="action" value="myshop_update_commission_status" />
                                            <input type="hidden" name="commission_id" value="<?php echo (int) $row->id; ?>" />
                                            <input type="hidden" name="action_type" value="reject" />
                                            <input type="text" name="note" placeholder="驳回原因" style="width: 120px;" />
                                            <button type="submit" class="button">驳回</button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div style="margin-top: 15px;">
                    <?php
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $link = add_query_arg([
                            'page' => 'myshop-commission-ledger',
                            'paged' => $i,
                            'status' => $status,
                            'type' => $type,
                            'earner_id' => $earner_id ?: '',
                            'order_id' => $order_id ?: ''
                        ], $base_url);
                        if ($i === $page) {
                            echo '<span style="margin-right: 6px; font-weight: bold;">' . $i . '</span>';
                        } else {
                            echo '<a style="margin-right: 6px;" href="' . esc_url($link) . '">' . $i . '</a>';
                        }
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_update_payout() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('myshop_update_commission_payout');

        $payout_id = isset($_POST['payout_id']) ? absint($_POST['payout_id']) : 0;
        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
        $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';

        if ($payout_id <= 0 || $action_type === '') {
            wp_safe_redirect(admin_url('admin.php?page=myshop-commission-payouts'));
            exit;
        }

        global $wpdb;
        $payout_table = $wpdb->prefix . 'myshop_commission_payouts';
        $commission_table = $wpdb->prefix . 'myshop_commissions';

        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT id, earner_id, settlement_batch, status FROM {$payout_table} WHERE id = %d",
            $payout_id
        ));

        if (!$payout) {
            wp_safe_redirect(admin_url('admin.php?page=myshop-commission-payouts'));
            exit;
        }

        $now = current_time('mysql');
        $redirect_status = '';

        if ($action_type === 'mark_paid' && $payout->status === 'processing') {
            $wpdb->update(
                $payout_table,
                [
                    'status' => 'paid',
                    'paid_at' => $now,
                    'note' => $note ?: null,
                    'updated_at' => $now
                ],
                ['id' => (int) $payout->id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );

            if (!empty($payout->settlement_batch)) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$commission_table}
                     SET status = 'paid', paid_at = %s, updated_at = %s
                     WHERE settlement_batch = %s AND earner_id = %d AND status = 'approved'",
                    $now,
                    $now,
                    $payout->settlement_batch,
                    (int) $payout->earner_id
                ));
            }

            $redirect_status = 'payout_paid';
        } elseif ($action_type === 'reject' && $payout->status === 'processing') {
            $wpdb->update(
                $payout_table,
                [
                    'status' => 'rejected',
                    'note' => $note ?: null,
                    'updated_at' => $now
                ],
                ['id' => (int) $payout->id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            if (!empty($payout->settlement_batch)) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$commission_table}
                     SET settlement_batch = NULL, expected_payout_at = NULL, updated_at = %s
                     WHERE settlement_batch = %s AND earner_id = %d AND status = 'approved'",
                    $now,
                    $payout->settlement_batch,
                    (int) $payout->earner_id
                ));
            }

            $redirect_status = 'payout_rejected';
        }

        $redirect_url = add_query_arg(
            ['myshop_updated' => $redirect_status],
            admin_url('admin.php?page=myshop-commission-payouts')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    public static function handle_update_commission_status() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('myshop_update_commission_status');

        $commission_id = isset($_POST['commission_id']) ? absint($_POST['commission_id']) : 0;
        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
        $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';

        if ($commission_id <= 0 || $action_type === '') {
            wp_safe_redirect(admin_url('admin.php?page=myshop-commission-ledger'));
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'myshop_commissions';

        $commission = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE id = %d",
            $commission_id
        ));

        if (!$commission) {
            wp_safe_redirect(admin_url('admin.php?page=myshop-commission-ledger'));
            exit;
        }

        $now = current_time('mysql');
        $redirect_status = '';

        if ($action_type === 'approve' && $commission->status === 'pending') {
            $wpdb->update(
                $table,
                [
                    'status' => 'approved',
                    'updated_at' => $now
                ],
                ['id' => (int) $commission->id],
                ['%s', '%s'],
                ['%d']
            );
            $redirect_status = 'commission_approved';
        } elseif ($action_type === 'reject' && $commission->status === 'pending') {
            $wpdb->update(
                $table,
                [
                    'status' => 'rejected',
                    'note' => $note ?: null,
                    'settlement_batch' => null,
                    'expected_payout_at' => null,
                    'paid_at' => null,
                    'updated_at' => $now
                ],
                ['id' => (int) $commission->id],
                ['%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            $redirect_status = 'commission_rejected';
        }

        $redirect_url = add_query_arg(
            ['myshop_updated' => $redirect_status],
            admin_url('admin.php?page=myshop-commission-ledger')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }
}
