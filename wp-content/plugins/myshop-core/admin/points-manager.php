<?php
/**
 * 积分系统管理页面
 * 功能：积分规则设置、自动累积、兑换管理
 */

class MyShop_Points_Manager {

    public static function init() {
        // 添加管理菜单
        add_action('admin_menu', [self::class, 'add_menu_page']);

        // 用户详情页展示积分概况
        add_action('show_user_profile', [self::class, 'render_user_points_profile']);
        add_action('edit_user_profile', [self::class, 'render_user_points_profile']);
        
        // ✅ 订单进入处理中/已发货/已完成时自动发放积分
        add_action('woocommerce_order_status_processing', [self::class, 'auto_grant_points_on_order_complete'], 10, 1);
        add_action('woocommerce_order_status_on-hold', [self::class, 'auto_grant_points_on_order_complete'], 10, 1);
        add_action('woocommerce_order_status_completed', [self::class, 'auto_grant_points_on_order_complete'], 10, 1);
        
        // 订单状态变为processing时扣除积分
        add_action('woocommerce_order_status_processing', [self::class, 'deduct_points_on_order_processing'], 10, 1);
        
        // 订单取消时退还积分
        add_action('woocommerce_order_status_cancelled', [self::class, 'refund_points_on_order_cancel'], 10, 1);
        add_action('woocommerce_order_status_refunded', [self::class, 'refund_points_on_order_cancel'], 10, 1);
        
        // 用户注册时赠送积分
        add_action('user_register', [self::class, 'grant_register_bonus'], 10, 1);
        
        // 保存积分设置
        add_action('admin_init', [self::class, 'handle_save_settings']);
        
        // 保存积分兑换商品设置
        add_action('admin_post_myshop_save_points_redeem_products', [self::class, 'handle_save_redeem_products']);

        // 保存积分任务与兑换项设置
        add_action('admin_post_myshop_save_points_missions', [self::class, 'handle_save_missions']);
        add_action('admin_post_myshop_save_points_redeem_options', [self::class, 'handle_save_redeem_options']);

        // 积分过期处理
        add_action('myshop_points_expire_daily', [self::class, 'expire_points_job']);
        if (!wp_next_scheduled('myshop_points_expire_daily')) {
            wp_schedule_event(time() + 300, 'daily', 'myshop_points_expire_daily');
        }
    }

    /**
     * 用户详情页展示积分概况与明细
     */
    public static function render_user_points_profile($user) {
        if (!$user || !($user instanceof WP_User)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'myshop_point_ledger';
        $user_id = (int) $user->ID;

        $available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        ));

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'pending'",
            $user_id
        ));

        $earned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND type = 'earn'",
            $user_id
        ));

        $spent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(ABS(delta)), 0) FROM {$table} WHERE user_id = %d AND type = 'spend'",
            $user_id
        ));

        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT id, type, delta, balance_after, status, channel, reference_order_id, created_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT 20",
            $user_id
        ));

        $ledger_url = admin_url('admin.php?page=myshop-points-ledger&s=' . $user_id);

        $type_labels = [
            'earn'   => '<span style="color: #0a9">🎁 获得</span>',
            'spend'  => '<span style="color: #f44">💸 消费</span>',
            'adjust' => '<span style="color: #2271b1">🛠 调整</span>',
            'expire' => '<span style="color: #999">⏳ 过期</span>',
            'refund' => '<span style="color: #fa0">↩️ 退款</span>'
        ];

        $status_labels = [
            'confirmed' => '<span style="color: #0a9">✓ 已确认</span>',
            'pending'   => '<span style="color: #fa0">⏳ 待确认</span>',
            'released'  => '<span style="color: #999">↩ 已释放</span>',
            'cancelled' => '<span style="color: #999">✗ 已取消</span>'
        ];

        $channel_labels = [
            'order_complete' => '订单完成',
            'order_discount' => '订单抵扣',
            'order_refund'   => '订单退款',
            'register_bonus' => '注册赠送',
            'daily_signin'   => '每日签到',
            'admin_grant'    => '管理员发放',
            'manual_spend'   => '手动消费'
        ];

        ?>
        <h2>💰 积分概况</h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th>可用积分</th>
                    <td><strong><?php echo number_format(max($available, 0)); ?></strong></td>
                </tr>
                <tr>
                    <th>待确认积分</th>
                    <td><?php echo number_format(max($pending, 0)); ?></td>
                </tr>
                <tr>
                    <th>累计获得</th>
                    <td><?php echo number_format(max($earned, 0)); ?></td>
                </tr>
                <tr>
                    <th>累计消耗</th>
                    <td><?php echo number_format(max($spent, 0)); ?></td>
                </tr>
                <tr>
                    <th>积分记录</th>
                    <td><a href="<?php echo esc_url($ledger_url); ?>" target="_blank">查看完整积分记录</a></td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin-top: 20px;">最近 20 条积分记录</h3>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>类型</th>
                    <th>变动</th>
                    <th>余额</th>
                    <th>状态</th>
                    <th>来源</th>
                    <th>订单ID</th>
                    <th>时间</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: #999; padding: 12px;">暂无积分记录</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?php echo (int) $record->id; ?></td>
                            <td><?php echo $type_labels[$record->type] ?? esc_html($record->type); ?></td>
                            <td>
                                <strong style="color: <?php echo $record->delta > 0 ? '#0a9' : '#f44'; ?>">
                                    <?php echo $record->delta > 0 ? '+' : ''; ?><?php echo number_format((int) $record->delta); ?>
                                </strong>
                            </td>
                            <td><?php echo number_format((int) $record->balance_after); ?></td>
                            <td><?php echo $status_labels[$record->status] ?? esc_html($record->status); ?></td>
                            <td><?php echo $channel_labels[$record->channel] ?? esc_html($record->channel); ?></td>
                            <td><?php echo $record->reference_order_id ? (int) $record->reference_order_id : '-'; ?></td>
                            <td><?php echo esc_html($record->created_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * 添加管理菜单
     */
    public static function add_menu_page() {
        add_menu_page(
            '积分管理',
            '💰 积分管理',
            'manage_options',
            'myshop-points',
            [self::class, 'render_settings_page'],
            'dashicons-money-alt',
            59
        );
        
        add_submenu_page(
            'myshop-points',
            '积分设置',
            '积分设置',
            'manage_options',
            'myshop-points',
            [self::class, 'render_settings_page']
        );
        
        add_submenu_page(
            'myshop-points',
            '积分记录',
            '积分记录',
            'manage_options',
            'myshop-points-ledger',
            [self::class, 'render_ledger_page']
        );
        
        add_submenu_page(
            'myshop-points',
            '积分兑换商品设置',
            '积分兑换商品设置',
            'manage_options',
            'myshop-points-redeem-products',
            [self::class, 'render_redeem_products_page']
        );

        add_submenu_page(
            'myshop-points',
            '积分任务管理',
            '积分任务',
            'manage_options',
            'myshop-points-missions',
            [self::class, 'render_missions_page']
        );

        add_submenu_page(
            'myshop-points',
            '积分兑换项管理',
            '积分兑换项',
            'manage_options',
            'myshop-points-redeem-options',
            [self::class, 'render_redeem_options_page']
        );
    }

    /**
     * 渲染积分任务管理页面
     */
    public static function render_missions_page() {
        $missions = get_option('myshop_points_missions', []);
        if (!is_array($missions)) {
            $missions = [];
        }

        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>✓ 积分任务已保存</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>🎯 积分任务管理</h1>
            <p class="description">配置积分任务（前端任务中心展示）。</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('myshop_save_points_missions'); ?>
                <input type="hidden" name="action" value="myshop_save_points_missions" />

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>删除</th>
                            <th>任务ID</th>
                            <th>标题</th>
                            <th>描述</th>
                            <th>奖励积分</th>
                            <th>目标</th>
                            <th>状态</th>
                            <th>过期时间</th>
                            <th>排序</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($missions)): ?>
                            <tr><td colspan="9" style="text-align:center; color:#999;">暂无任务</td></tr>
                        <?php endif; ?>
                        <?php foreach ($missions as $index => $mission): ?>
                            <tr>
                                <td><input type="checkbox" name="missions[<?php echo esc_attr($index); ?>][delete]" value="1" /></td>
                                <td><input type="text" name="missions[<?php echo esc_attr($index); ?>][mission_id]" value="<?php echo esc_attr($mission['mission_id'] ?? ''); ?>" style="width:160px;" /></td>
                                <td><input type="text" name="missions[<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr($mission['title'] ?? ''); ?>" style="width:160px;" /></td>
                                <td><input type="text" name="missions[<?php echo esc_attr($index); ?>][description]" value="<?php echo esc_attr($mission['description'] ?? ''); ?>" style="width:240px;" /></td>
                                <td><input type="number" name="missions[<?php echo esc_attr($index); ?>][reward_points]" value="<?php echo esc_attr($mission['reward_points'] ?? 0); ?>" min="0" style="width:90px;" /></td>
                                <td><input type="number" name="missions[<?php echo esc_attr($index); ?>][goal]" value="<?php echo esc_attr($mission['goal'] ?? 1); ?>" min="1" style="width:70px;" /></td>
                                <td>
                                    <select name="missions[<?php echo esc_attr($index); ?>][status]">
                                        <option value="available" <?php selected(($mission['status'] ?? 'available'), 'available'); ?>>可用</option>
                                        <option value="disabled" <?php selected(($mission['status'] ?? 'available'), 'disabled'); ?>>停用</option>
                                    </select>
                                </td>
                                <td><input type="date" name="missions[<?php echo esc_attr($index); ?>][expires_at]" value="<?php echo esc_attr(!empty($mission['expires_at']) ? date('Y-m-d', strtotime($mission['expires_at'])) : ''); ?>" /></td>
                                <td><input type="number" name="missions[<?php echo esc_attr($index); ?>][sort]" value="<?php echo esc_attr($mission['sort'] ?? 0); ?>" style="width:70px;" /></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td>新增</td>
                            <td><input type="text" name="missions[new][mission_id]" placeholder="自动生成" style="width:160px;" /></td>
                            <td><input type="text" name="missions[new][title]" placeholder="任务标题" style="width:160px;" /></td>
                            <td><input type="text" name="missions[new][description]" placeholder="任务描述" style="width:240px;" /></td>
                            <td><input type="number" name="missions[new][reward_points]" value="0" min="0" style="width:90px;" /></td>
                            <td><input type="number" name="missions[new][goal]" value="1" min="1" style="width:70px;" /></td>
                            <td>
                                <select name="missions[new][status]">
                                    <option value="available">可用</option>
                                    <option value="disabled">停用</option>
                                </select>
                            </td>
                            <td><input type="date" name="missions[new][expires_at]" /></td>
                            <td><input type="number" name="missions[new][sort]" value="0" style="width:70px;" /></td>
                        </tr>
                    </tbody>
                </table>

                <p><button type="submit" class="button button-primary">保存任务</button></p>
            </form>
        </div>
        <?php
    }

    public static function handle_save_missions() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'myshop_save_points_missions')) {
            wp_die('安全验证失败');
        }

        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }

        $missions_input = $_POST['missions'] ?? [];
        $saved = [];

        foreach ($missions_input as $key => $mission) {
            if (!is_array($mission)) {
                continue;
            }
            if (!empty($mission['delete'])) {
                continue;
            }

            $mission_id = sanitize_text_field($mission['mission_id'] ?? '');
            $title = sanitize_text_field($mission['title'] ?? '');
            $description = sanitize_text_field($mission['description'] ?? '');
            $reward_points = (int) ($mission['reward_points'] ?? 0);
            $goal = max(1, (int) ($mission['goal'] ?? 1));
            $status = ($mission['status'] ?? 'available') === 'disabled' ? 'disabled' : 'available';
            $expires_at = sanitize_text_field($mission['expires_at'] ?? '');
            $sort = (int) ($mission['sort'] ?? 0);

            if ($title === '' && $description === '' && $reward_points <= 0) {
                continue;
            }

            if ($mission_id === '') {
                $mission_id = 'mission_' . wp_generate_password(8, false, false);
            }

            $saved[] = [
                'mission_id' => $mission_id,
                'title' => $title,
                'description' => $description,
                'reward_points' => $reward_points,
                'status' => $status,
                'progress' => 0,
                'goal' => $goal,
                'expires_at' => $expires_at ? date('Y-m-d H:i:s', strtotime($expires_at)) : null,
                'sort' => $sort
            ];
        }

        usort($saved, static function ($a, $b) {
            return ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0);
        });

        update_option('myshop_points_missions', $saved);

        wp_redirect(add_query_arg([
            'page' => 'myshop-points-missions',
            'settings-updated' => 'true'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * 渲染积分兑换项管理页面
     */
    public static function render_redeem_options_page() {
        $options = get_option('myshop_points_redeem_options', []);
        if (!is_array($options)) {
            $options = [];
        }

        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>✓ 积分兑换项已保存</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>🛍️ 积分兑换项管理</h1>
            <p class="description">配置积分兑换项（优惠券、礼品等）。</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('myshop_save_points_redeem_options'); ?>
                <input type="hidden" name="action" value="myshop_save_points_redeem_options" />

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>删除</th>
                            <th>兑换项ID</th>
                            <th>类型</th>
                            <th>标题</th>
                            <th>消耗积分</th>
                            <th>库存</th>
                            <th>状态</th>
                            <th>优惠券码</th>
                            <th>描述</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($options)): ?>
                            <tr><td colspan="9" style="text-align:center; color:#999;">暂无兑换项</td></tr>
                        <?php endif; ?>
                        <?php foreach ($options as $index => $option): ?>
                            <tr>
                                <td><input type="checkbox" name="options[<?php echo esc_attr($index); ?>][delete]" value="1" /></td>
                                <td><input type="text" name="options[<?php echo esc_attr($index); ?>][option_id]" value="<?php echo esc_attr($option['option_id'] ?? ($option['id'] ?? '')); ?>" style="width:160px;" /></td>
                                <td>
                                    <select name="options[<?php echo esc_attr($index); ?>][type]">
                                        <option value="coupon" <?php selected(($option['type'] ?? 'coupon'), 'coupon'); ?>>优惠券</option>
                                        <option value="gift" <?php selected(($option['type'] ?? 'coupon'), 'gift'); ?>>礼品</option>
                                    </select>
                                </td>
                                <td><input type="text" name="options[<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr($option['title'] ?? ''); ?>" style="width:160px;" /></td>
                                <td><input type="number" name="options[<?php echo esc_attr($index); ?>][cost_points]" value="<?php echo esc_attr($option['cost_points'] ?? 0); ?>" min="0" style="width:90px;" /></td>
                                <td><input type="number" name="options[<?php echo esc_attr($index); ?>][stock]" value="<?php echo esc_attr($option['stock'] ?? ''); ?>" min="0" style="width:70px;" /></td>
                                <td>
                                    <select name="options[<?php echo esc_attr($index); ?>][status]">
                                        <option value="active" <?php selected(($option['status'] ?? 'active'), 'active'); ?>>可用</option>
                                        <option value="disabled" <?php selected(($option['status'] ?? 'active'), 'disabled'); ?>>停用</option>
                                    </select>
                                </td>
                                <td><input type="text" name="options[<?php echo esc_attr($index); ?>][coupon_code]" value="<?php echo esc_attr($option['coupon_code'] ?? ''); ?>" style="width:140px;" /></td>
                                <td><input type="text" name="options[<?php echo esc_attr($index); ?>][description]" value="<?php echo esc_attr($option['description'] ?? ''); ?>" style="width:200px;" /></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td>新增</td>
                            <td><input type="text" name="options[new][option_id]" placeholder="自动生成" style="width:160px;" /></td>
                            <td>
                                <select name="options[new][type]">
                                    <option value="coupon">优惠券</option>
                                    <option value="gift">礼品</option>
                                </select>
                            </td>
                            <td><input type="text" name="options[new][title]" placeholder="兑换项标题" style="width:160px;" /></td>
                            <td><input type="number" name="options[new][cost_points]" value="0" min="0" style="width:90px;" /></td>
                            <td><input type="number" name="options[new][stock]" value="" min="0" style="width:70px;" /></td>
                            <td>
                                <select name="options[new][status]">
                                    <option value="active">可用</option>
                                    <option value="disabled">停用</option>
                                </select>
                            </td>
                            <td><input type="text" name="options[new][coupon_code]" placeholder="可选" style="width:140px;" /></td>
                            <td><input type="text" name="options[new][description]" placeholder="描述" style="width:200px;" /></td>
                        </tr>
                    </tbody>
                </table>

                <p><button type="submit" class="button button-primary">保存兑换项</button></p>
            </form>
        </div>
        <?php
    }

    public static function handle_save_redeem_options() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'myshop_save_points_redeem_options')) {
            wp_die('安全验证失败');
        }

        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }

        $options_input = $_POST['options'] ?? [];
        $saved = [];

        foreach ($options_input as $key => $option) {
            if (!is_array($option)) {
                continue;
            }
            if (!empty($option['delete'])) {
                continue;
            }

            $option_id = sanitize_text_field($option['option_id'] ?? '');
            $type = sanitize_text_field($option['type'] ?? 'coupon');
            $title = sanitize_text_field($option['title'] ?? '');
            $cost_points = (int) ($option['cost_points'] ?? 0);
            $stock = $option['stock'] === '' ? null : (int) ($option['stock'] ?? 0);
            $status = ($option['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
            $coupon_code = sanitize_text_field($option['coupon_code'] ?? '');
            $description = sanitize_text_field($option['description'] ?? '');

            if ($title === '' && $cost_points <= 0) {
                continue;
            }

            if ($option_id === '') {
                $option_id = 'option_' . wp_generate_password(8, false, false);
            }

            $saved[] = [
                'option_id' => $option_id,
                'type' => $type ?: 'coupon',
                'title' => $title,
                'cost_points' => $cost_points,
                'stock' => $stock,
                'status' => $status,
                'coupon_code' => $coupon_code,
                'description' => $description
            ];
        }

        update_option('myshop_points_redeem_options', $saved);

        wp_redirect(add_query_arg([
            'page' => 'myshop-points-redeem-options',
            'settings-updated' => 'true'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * 积分过期处理任务
     */
    public static function expire_points_job() {
        global $wpdb;

        $table = $wpdb->prefix . 'myshop_point_ledger';
        $now_mysql = current_time('mysql');

        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$table} WHERE status = 'confirmed' AND delta > 0 AND expire_at IS NOT NULL AND expire_at < %s",
            $now_mysql
        ));

        if (empty($users)) {
            return;
        }

        foreach ($users as $row) {
            $user_id = (int) $row->user_id;
            if ($user_id <= 0) {
                continue;
            }

            $available = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
                $user_id
            ));

            if ($available <= 0) {
                continue;
            }

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, delta FROM {$table} WHERE user_id = %d AND status = 'confirmed' AND delta > 0 AND expire_at IS NOT NULL AND expire_at < %s ORDER BY expire_at ASC, id ASC",
                $user_id,
                $now_mysql
            ));

            $expired_sum = 0;
            $ids_to_clear = [];
            foreach ($rows as $entry) {
                $delta = (int) $entry->delta;
                if ($delta <= 0) {
                    continue;
                }
                if (($available - $expired_sum - $delta) < 0) {
                    break;
                }
                $expired_sum += $delta;
                $ids_to_clear[] = (int) $entry->id;
            }

            if ($expired_sum <= 0 || empty($ids_to_clear)) {
                continue;
            }

            $ids_placeholders = implode(',', array_fill(0, count($ids_to_clear), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET expire_at = NULL, updated_at = %s WHERE id IN ({$ids_placeholders})",
                array_merge([$now_mysql], $ids_to_clear)
            ));

            $balance_after = $available - $expired_sum;
            $wpdb->insert(
                $table,
                [
                    'user_id'       => $user_id,
                    'type'          => 'expire',
                    'delta'         => -$expired_sum,
                    'balance_after' => $balance_after,
                    'status'        => 'confirmed',
                    'channel'       => 'points_expire',
                    'created_at'    => $now_mysql,
                    'updated_at'    => $now_mysql
                ],
                ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
            );
        }
    }
    
    /**
     * 渲染积分设置页面
     */
    public static function render_settings_page() {
        $settings = self::get_settings();
        
        // 显示保存成功消息
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>✓ 积分设置已保存</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>💰 积分系统设置</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('myshop_points_settings'); ?>
                
                <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 8px;">
                    <h2>🎁 积分获取规则</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="enable_points">启用积分系统</label></th>
                            <td>
                                <input type="checkbox" name="enable_points" id="enable_points" value="1" 
                                       <?php checked($settings['enable_points'], 1); ?>>
                                <p class="description">开启后，用户可以通过消费获得积分，并使用积分抵扣订单金额</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="earn_rate">消费积分比例</label></th>
                            <td>
                                消费 ¥1 = 
                                <input type="number" name="earn_rate" id="earn_rate" 
                                       value="<?php echo esc_attr($settings['earn_rate']); ?>" 
                                       min="0" step="0.1" style="width: 100px;">
                                积分
                                <p class="description">例如：设置为 10，用户消费 100 元可获得 1000 积分</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="min_order_amount">最低消费金额</label></th>
                            <td>
                                ¥ <input type="number" name="min_order_amount" id="min_order_amount" 
                                         value="<?php echo esc_attr($settings['min_order_amount']); ?>" 
                                         min="0" step="0.01" style="width: 100px;">
                                <p class="description">订单金额低于此值不发放积分（0 表示不限制）</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="register_bonus">注册赠送积分</label></th>
                            <td>
                                <input type="number" name="register_bonus" id="register_bonus" 
                                       value="<?php echo esc_attr($settings['register_bonus']); ?>" 
                                       min="0" style="width: 100px;">
                                积分
                                <p class="description">新用户注册时赠送的积分（0 表示不赠送）</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="daily_signin_points">每日签到积分</label></th>
                            <td>
                                <input type="number" name="daily_signin_points" id="daily_signin_points" 
                                       value="<?php echo esc_attr($settings['daily_signin_points']); ?>" 
                                       min="0" style="width: 100px;">
                                积分
                                <p class="description">用户每日签到可获得的积分（0 表示禁用签到功能）</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 8px;">
                    <h2>🛒 积分使用规则</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="enable_points_discount">允许积分抵扣</label></th>
                            <td>
                                <input type="checkbox" name="enable_points_discount" id="enable_points_discount" value="1" 
                                       <?php checked($settings['enable_points_discount'], 1); ?>>
                                <p class="description">开启后，用户下单时可以使用积分抵扣订单金额</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="redeem_rate">积分抵扣比例</label></th>
                            <td>
                                <input type="number" name="redeem_rate" id="redeem_rate" 
                                       value="<?php echo esc_attr($settings['redeem_rate']); ?>" 
                                       min="0" step="1" style="width: 100px;">
                                积分 = ¥1
                                <p class="description">例如：设置为 100，使用 100 积分可抵扣 1 元</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="min_points_to_use">最低使用积分</label></th>
                            <td>
                                <input type="number" name="min_points_to_use" id="min_points_to_use" 
                                       value="<?php echo esc_attr($settings['min_points_to_use']); ?>" 
                                       min="0" style="width: 100px;">
                                积分
                                <p class="description">用户账户积分少于此值时不能使用积分抵扣</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="max_discount_percent">最大抵扣比例</label></th>
                            <td>
                                <input type="number" name="max_discount_percent" id="max_discount_percent" 
                                       value="<?php echo esc_attr($settings['max_discount_percent']); ?>" 
                                       min="0" max="100" style="width: 100px;">
                                %
                                <p class="description">积分最多可抵扣订单金额的百分比（例如：50 表示最多抵扣 50%）</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="min_order_amount_to_use">最低订单金额</label></th>
                            <td>
                                ¥ <input type="number" name="min_order_amount_to_use" id="min_order_amount_to_use" 
                                         value="<?php echo esc_attr($settings['min_order_amount_to_use']); ?>" 
                                         min="0" step="0.01" style="width: 100px;">
                                <p class="description">订单金额低于此值不能使用积分抵扣（0 表示不限制）</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 8px;">
                    <h2>🤝 分销奖励积分</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="enable_referral_points">启用分销奖励积分</label></th>
                            <td>
                                <input type="checkbox" name="enable_referral_points" id="enable_referral_points" value="1"
                                       <?php checked($settings['enable_referral_points'], 1); ?>>
                                <p class="description">开启后，邀请用户下单将奖励积分（按订单实付金额计算）</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="referral_points_rate_level1">一级奖励比例</label></th>
                            <td>
                                订单实付 ¥1 =
                                <input type="number" name="referral_points_rate_level1" id="referral_points_rate_level1"
                                       value="<?php echo esc_attr($settings['referral_points_rate_level1']); ?>"
                                       min="0" step="0.1" style="width: 100px;">
                                积分
                                <p class="description">示例：设置为 10，则一级用户订单每 1 元奖励 10 积分</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="referral_points_rate_level2">二级奖励比例</label></th>
                            <td>
                                订单实付 ¥1 =
                                <input type="number" name="referral_points_rate_level2" id="referral_points_rate_level2"
                                       value="<?php echo esc_attr($settings['referral_points_rate_level2']); ?>"
                                       min="0" step="0.1" style="width: 100px;">
                                积分
                                <p class="description">示例：设置为 5，则二级用户订单每 1 元奖励 5 积分</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 8px;">
                    <h2>💸 积分兑换佣金</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="enable_points_exchange">启用积分兑换</label></th>
                            <td>
                                <input type="checkbox" name="enable_points_exchange" id="enable_points_exchange" value="1"
                                       <?php checked($settings['enable_points_exchange'], 1); ?>>
                                <p class="description">开启后，用户可用积分兑换佣金提现</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="exchange_rate">兑换比例</label></th>
                            <td>
                                <input type="number" name="exchange_rate" id="exchange_rate"
                                       value="<?php echo esc_attr($settings['exchange_rate']); ?>"
                                       min="0" step="0.1" style="width: 100px;">
                                积分 = ¥1
                                <p class="description">例如：设置为 100，则 100 积分可兑换 1 元</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="exchange_min_points">最低兑换积分</label></th>
                            <td>
                                <input type="number" name="exchange_min_points" id="exchange_min_points"
                                       value="<?php echo esc_attr($settings['exchange_min_points']); ?>"
                                       min="0" step="1" style="width: 100px;">
                                积分
                                <p class="description">单次申请最低兑换积分（0 表示不限制）</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="exchange_min_amount">最低兑换金额</label></th>
                            <td>
                                ¥ <input type="number" name="exchange_min_amount" id="exchange_min_amount"
                                         value="<?php echo esc_attr($settings['exchange_min_amount']); ?>"
                                         min="0" step="0.01" style="width: 100px;">
                                <p class="description">单次兑换金额低于此值将不可提交（0 表示不限制）</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="exchange_max_amount">单次兑换上限</label></th>
                            <td>
                                ¥ <input type="number" name="exchange_max_amount" id="exchange_max_amount"
                                         value="<?php echo esc_attr($settings['exchange_max_amount']); ?>"
                                         min="0" step="0.01" style="width: 100px;">
                                <p class="description">单次兑换金额上限（0 表示不限制）</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="exchange_max_amount_per_day">每日兑换上限</label></th>
                            <td>
                                ¥ <input type="number" name="exchange_max_amount_per_day" id="exchange_max_amount_per_day"
                                         value="<?php echo esc_attr($settings['exchange_max_amount_per_day']); ?>"
                                         min="0" step="0.01" style="width: 100px;">
                                <p class="description">每日可兑换的总金额（0 表示不限制）</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="exchange_max_requests_per_day">每日申请次数</label></th>
                            <td>
                                <input type="number" name="exchange_max_requests_per_day" id="exchange_max_requests_per_day"
                                       value="<?php echo esc_attr($settings['exchange_max_requests_per_day']); ?>"
                                       min="0" step="1" style="width: 100px;">
                                次
                                <p class="description">每日可提交的兑换次数（0 表示不限制）</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="exchange_fee_rate">手续费比例</label></th>
                            <td>
                                <input type="number" name="exchange_fee_rate" id="exchange_fee_rate"
                                       value="<?php echo esc_attr($settings['exchange_fee_rate']); ?>"
                                       min="0" max="100" step="0.1" style="width: 100px;">
                                %
                                <p class="description">按兑换金额收取手续费（0 表示不收取）</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 8px;">
                    <h2>⏰ 积分有效期</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="enable_expiry">启用积分过期</label></th>
                            <td>
                                <input type="checkbox" name="enable_expiry" id="enable_expiry" value="1" 
                                       <?php checked($settings['enable_expiry'], 1); ?>>
                                <p class="description">开启后，积分将在指定时间后过期</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="expiry_days">有效期天数</label></th>
                            <td>
                                <input type="number" name="expiry_days" id="expiry_days" 
                                       value="<?php echo esc_attr($settings['expiry_days']); ?>" 
                                       min="0" style="width: 100px;">
                                天
                                <p class="description">积分获得后多少天过期（0 表示永久有效）</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p>
                    <button type="submit" name="myshop_save_points_settings" class="button button-primary button-large">
                        💾 保存设置
                    </button>
                </p>
            </form>
            
            <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;">📊 当前设置预览</h3>
                <ul>
                    <li><strong>消费奖励</strong>: 每消费 ¥1 可获得 <?php echo $settings['earn_rate']; ?> 积分</li>
                    <li><strong>积分价值</strong>: 每 <?php echo $settings['redeem_rate']; ?> 积分可抵扣 ¥1</li>
                    <li><strong>最大抵扣</strong>: 最多可抵扣订单金额的 <?php echo $settings['max_discount_percent']; ?>%</li>
                    <li><strong>注册奖励</strong>: 新用户注册赠送 <?php echo $settings['register_bonus']; ?> 积分</li>
                    <li><strong>分销奖励</strong>: 一级 <?php echo $settings['referral_points_rate_level1']; ?> / 二级 <?php echo $settings['referral_points_rate_level2']; ?> 积分/元</li>
                    <li><strong>积分兑换</strong>: 每 <?php echo $settings['exchange_rate']; ?> 积分兑换 ¥1，手续费 <?php echo $settings['exchange_fee_rate']; ?>%</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * 渲染积分记录页面
     */
    public static function render_ledger_page() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'myshop_point_ledger';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;
        
        // 搜索功能
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $where = $search ? $wpdb->prepare("WHERE user_id = %d OR channel LIKE %s", intval($search), '%' . $wpdb->esc_like($search) . '%') : '';
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.display_name, u.user_email 
             FROM {$table} l 
             LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID 
             {$where}
             ORDER BY l.created_at DESC 
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $total_pages = ceil($total / $per_page);
        ?>
        <div class="wrap">
            <h1>📊 积分记录</h1>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="myshop-points-ledger">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索用户ID或来源">
                    <input type="submit" class="button" value="搜索">
                </p>
            </form>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户</th>
                        <th>类型</th>
                        <th>变动</th>
                        <th>余额</th>
                        <th>状态</th>
                        <th>来源</th>
                        <th>订单ID</th>
                        <th>时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #999;">
                                暂无积分记录
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?php echo $record->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($record->display_name); ?></strong><br>
                                    <small><?php echo esc_html($record->user_email); ?></small><br>
                                    <small style="color: #999;">ID: <?php echo $record->user_id; ?></small>
                                </td>
                                <td>
                                    <?php 
                                    $type_labels = [
                                        'earn' => '<span style="color: #0a9">🎁 获得</span>',
                                        'spend' => '<span style="color: #f44">💸 消费</span>',
                                        'refund' => '<span style="color: #fa0">↩️ 退款</span>'
                                    ];
                                    echo $type_labels[$record->type] ?? $record->type;
                                    ?>
                                </td>
                                <td>
                                    <strong style="color: <?php echo $record->delta > 0 ? '#0a9' : '#f44'; ?>">
                                        <?php echo $record->delta > 0 ? '+' : ''; ?><?php echo number_format($record->delta); ?>
                                    </strong>
                                </td>
                                <td><?php echo number_format($record->balance_after); ?></td>
                                <td>
                                    <?php 
                                    $status_labels = [
                                        'confirmed' => '<span style="color: #0a9">✓ 已确认</span>',
                                        'pending' => '<span style="color: #fa0">⏳ 待确认</span>',
                                        'cancelled' => '<span style="color: #999">✗ 已取消</span>'
                                    ];
                                    echo $status_labels[$record->status] ?? $record->status;
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $channel_labels = [
                                        'order_complete' => '订单完成',
                                        'order_discount' => '订单抵扣',
                                        'order_refund' => '订单退款',
                                        'register_bonus' => '注册赠送',
                                        'daily_signin' => '每日签到',
                                        'admin_grant' => '管理员发放',
                                        'manual_spend' => '手动消费'
                                    ];
                                    echo $channel_labels[$record->channel] ?? esc_html($record->channel);
                                    ?>
                                </td>
                                <td>
                                    <?php if ($record->reference_order_id): ?>
                                        <a href="<?php echo admin_url('post.php?post=' . $record->reference_order_id . '&action=edit'); ?>" target="_blank">
                                            #<?php echo $record->reference_order_id; ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('Y-m-d H:i', strtotime($record->created_at)); ?>
                                    <?php if ($record->expire_at): ?>
                                        <br><small style="color: #999;">过期: <?php echo date('Y-m-d', strtotime($record->expire_at)); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * 获取积分设置
     */
    public static function get_settings() {
        $defaults = [
            'enable_points' => 1,
            'earn_rate' => 10,
            'min_order_amount' => 0,
            'register_bonus' => 100,
            'daily_signin_points' => 10,
            'enable_points_discount' => 1,
            'redeem_rate' => 100,
            'min_points_to_use' => 100,
            'max_discount_percent' => 50,
            'min_order_amount_to_use' => 0,
            'enable_expiry' => 0,
            'expiry_days' => 365,
            'enable_referral_points' => 0,
            'referral_points_rate_level1' => 0,
            'referral_points_rate_level2' => 0,
            'enable_points_exchange' => 0,
            'exchange_rate' => 100,
            'exchange_min_points' => 100,
            'exchange_min_amount' => 0,
            'exchange_max_amount' => 0,
            'exchange_max_amount_per_day' => 0,
            'exchange_max_requests_per_day' => 0,
            'exchange_fee_rate' => 0,
            'redeem_allowed_product_ids' => [],
            'redeem_allowed_variation_ids' => []
        ];
        
        $settings = get_option('myshop_points_settings', []);
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * 处理保存积分设置请求
     */
    public static function handle_save_settings() {
        // 检查是否是保存请求
        if (!isset($_POST['myshop_save_points_settings'])) {
            return;
        }
        
        // 验证 nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'myshop_points_settings')) {
            wp_die('安全验证失败');
        }
        
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        // 保存设置
        $settings = [
            'enable_points' => isset($_POST['enable_points']) ? 1 : 0,
            'earn_rate' => floatval($_POST['earn_rate'] ?? 10),
            'min_order_amount' => floatval($_POST['min_order_amount'] ?? 0),
            'register_bonus' => intval($_POST['register_bonus'] ?? 0),
            'daily_signin_points' => intval($_POST['daily_signin_points'] ?? 0),
            'enable_points_discount' => isset($_POST['enable_points_discount']) ? 1 : 0,
            'redeem_rate' => intval($_POST['redeem_rate'] ?? 100),
            'min_points_to_use' => intval($_POST['min_points_to_use'] ?? 0),
            'max_discount_percent' => intval($_POST['max_discount_percent'] ?? 50),
            'min_order_amount_to_use' => floatval($_POST['min_order_amount_to_use'] ?? 0),
            'enable_expiry' => isset($_POST['enable_expiry']) ? 1 : 0,
            'expiry_days' => intval($_POST['expiry_days'] ?? 365),
            'enable_referral_points' => isset($_POST['enable_referral_points']) ? 1 : 0,
            'referral_points_rate_level1' => floatval($_POST['referral_points_rate_level1'] ?? 0),
            'referral_points_rate_level2' => floatval($_POST['referral_points_rate_level2'] ?? 0),
            'enable_points_exchange' => isset($_POST['enable_points_exchange']) ? 1 : 0,
            'exchange_rate' => floatval($_POST['exchange_rate'] ?? 100),
            'exchange_min_points' => intval($_POST['exchange_min_points'] ?? 0),
            'exchange_min_amount' => floatval($_POST['exchange_min_amount'] ?? 0),
            'exchange_max_amount' => floatval($_POST['exchange_max_amount'] ?? 0),
            'exchange_max_amount_per_day' => floatval($_POST['exchange_max_amount_per_day'] ?? 0),
            'exchange_max_requests_per_day' => intval($_POST['exchange_max_requests_per_day'] ?? 0),
            'exchange_fee_rate' => floatval($_POST['exchange_fee_rate'] ?? 0)
        ];
        
        update_option('myshop_points_settings', $settings);
        
        // 重定向回设置页面并显示成功消息
        wp_redirect(add_query_arg([
            'page' => 'myshop-points',
            'settings-updated' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * 订单完成时自动发放积分
     */
    public static function auto_grant_points_on_order_complete($order_id) {
        global $wpdb;
        
        $settings = get_option('myshop_points_settings', []);
        $settings = wp_parse_args($settings, [
            'enable_points' => 1,
            'earn_rate' => 10,
            'min_order_amount' => 0,
            'enable_expiry' => 0,
            'expiry_days' => 365
        ]);

        // 检查是否启用积分系统
        if (empty($settings['enable_points'])) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $user_id = $order->get_customer_id();
        if (!$user_id) {
            return;
        }
        
        $order_total = $order->get_total();
        
        // 检查最低消费金额
        if ($settings['min_order_amount'] > 0 && $order_total < $settings['min_order_amount']) {
            return;
        }
        
        // 检查是否已经发放过积分
        $table = $wpdb->prefix . 'myshop_point_ledger';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND reference_order_id = %d AND channel = 'order_complete'",
            $user_id,
            $order_id
        ));
        
        if ($existing > 0) {
            return; // 已经发放过了
        }
        
        // 计算积分
        $points = floor($order_total * $settings['earn_rate']);
        
        if ($points <= 0) {
            return;
        }
        
        // 计算当前余额
        $current_balance = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        )));
        
        $balance_after = $current_balance + $points;
        
        // 计算过期时间
        $expire_at = null;
        if ($settings['enable_expiry'] && $settings['expiry_days'] > 0) {
            $expire_at = date('Y-m-d H:i:s', strtotime('+' . $settings['expiry_days'] . ' days'));
        }
        
        // 插入积分记录
        $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'type' => 'earn',
                'delta' => $points,
                'balance_after' => $balance_after,
                'status' => 'confirmed',
                'channel' => 'order_complete',
                'reference_order_id' => $order_id,
                'expire_at' => $expire_at,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        
        // 添加订单备注
        $order->add_order_note(sprintf('已发放 %d 积分', $points));
        
        // 更新用户 meta
        update_user_meta($user_id, '_myshop_total_points', $balance_after);
    }
    
    /**
     * 订单状态变为processing时扣除积分
     */
    public static function deduct_points_on_order_processing($order_id) {
        if (class_exists('Order_Controller')) {
            Order_Controller::deduct_points_for_order($order_id);
        }
    }
    
    /**
     * 订单取消时退还积分
     */
    public static function refund_points_on_order_cancel($order_id) {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $points_used = $order->get_meta('_points_used', true);
        if (!$points_used || $points_used <= 0) {
            return;
        }
        
        // 检查是否已经退还过
        $table = $wpdb->prefix . 'myshop_point_ledger';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND reference_order_id = %d AND channel = 'order_refund'",
            $order->get_customer_id(),
            $order_id
        ));
        
        if ($existing > 0) {
            return; // 已经退还过了
        }
        
        $user_id = $order->get_customer_id();
        
        // 计算当前余额
        $current_balance = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(delta), 0) FROM {$table} WHERE user_id = %d AND status = 'confirmed'",
            $user_id
        )));
        
        $balance_after = $current_balance + $points_used;
        
        // 插入退还记录
        $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'type' => 'refund',
                'delta' => $points_used,
                'balance_after' => $balance_after,
                'status' => 'confirmed',
                'channel' => 'order_refund',
                'reference_order_id' => $order_id,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s']
        );
        
        $order->add_order_note(sprintf('已退还 %d 积分', $points_used));
    }
    
    /**
     * 用户注册时赠送积分
     */
    public static function grant_register_bonus($user_id) {
        global $wpdb;
        
        $settings = self::get_settings();
        
        if (!$settings['enable_points'] || $settings['register_bonus'] <= 0) {
            return;
        }
        
        $points = $settings['register_bonus'];
        $table = $wpdb->prefix . 'myshop_point_ledger';
        
        // 计算过期时间
        $expire_at = null;
        if ($settings['enable_expiry'] && $settings['expiry_days'] > 0) {
            $expire_at = date('Y-m-d H:i:s', strtotime('+' . $settings['expiry_days'] . ' days'));
        }
        
        // 插入积分记录
        $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'type' => 'earn',
                'delta' => $points,
                'balance_after' => $points,
                'status' => 'confirmed',
                'channel' => 'register_bonus',
                'expire_at' => $expire_at,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );
        
        // 更新用户 meta
        update_user_meta($user_id, '_myshop_total_points', $points);
    }
    
    /**
     * 渲染积分兑换商品设置页面
     */
    public static function render_redeem_products_page() {
        $settings = self::get_settings();
        $allowed_product_ids = isset($settings['redeem_allowed_product_ids']) ? (array) $settings['redeem_allowed_product_ids'] : [];
        $allowed_variation_ids = isset($settings['redeem_allowed_variation_ids']) ? (array) $settings['redeem_allowed_variation_ids'] : [];
        
        // 自动清理已下架或已删除的商品和变体ID
        $cleaned_settings = self::clean_invalid_redeem_products($allowed_product_ids, $allowed_variation_ids);
        if ($cleaned_settings['has_changes']) {
            $settings['redeem_allowed_product_ids'] = $cleaned_settings['product_ids'];
            $settings['redeem_allowed_variation_ids'] = $cleaned_settings['variation_ids'];
            update_option('myshop_points_settings', $settings);
            $allowed_product_ids = $cleaned_settings['product_ids'];
            $allowed_variation_ids = $cleaned_settings['variation_ids'];
        }
        
        // 获取所有已发布的商品（实际商品，实时同步）
        $products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        // 获取已下架但之前被选中的商品（用于提示管理员）
        $unpublished_products = [];
        if (!empty($allowed_product_ids)) {
            $unpublished_posts = get_posts([
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post_status' => ['draft', 'private', 'pending'],
                'post__in' => $allowed_product_ids,
                'orderby' => 'title',
                'order' => 'ASC'
            ]);
            foreach ($unpublished_posts as $post) {
                $unpublished_products[$post->ID] = $post;
            }
        }
        
        // 显示保存成功消息
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>✓ 积分兑换商品设置已保存</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>🛒 积分兑换商品设置</h1>
            <p class="description">设置哪些商品和变体可以用积分兑换。只有被选中的商品/变体才会在积分兑换页面显示。</p>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('myshop_save_points_redeem_products'); ?>
                <input type="hidden" name="action" value="myshop_save_points_redeem_products" />
                
                <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 8px;">
                    <h2>选择可用积分兑换的商品和变体</h2>
                    
                    <p>
                        <strong>说明：</strong>
                        <br>• 本页面显示的所有商品都是从WooCommerce商品库实时同步的，已发布的商品会自动显示
                        <br>• 商品下架后会自动从积分兑换列表中移除，无需手动操作
                        <br>• 选择商品：表示该商品的所有变体都可以用积分兑换
                        <br>• 选择变体：只允许指定的变体使用积分兑换，优先级高于商品选择
                        <br>• 未选中的商品/变体不会在积分兑换页面显示
                    </p>
                    
                    <?php if (!empty($unpublished_products)): ?>
                        <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 4px;">
                            <strong>⚠️ 提示：以下商品已下架或未发布，但仍在积分兑换列表中：</strong>
                            <ul style="margin: 10px 0 0 20px;">
                                <?php foreach ($unpublished_products as $post): ?>
                                    <li><?php echo esc_html($post->post_title); ?> (ID: <?php echo $post->ID; ?>) - 状态: <?php echo esc_html($post->post_status); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin: 10px 0 0; font-size: 13px; color: #666;">
                                这些商品将在下次保存时自动清理，或现在保存即可清理。
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin: 20px 0;">
                        <div style="margin-bottom: 15px;">
                            <input 
                                type="text" 
                                id="product-search" 
                                placeholder="搜索商品名称..." 
                                style="width: 300px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                            />
                            <span style="margin-left: 10px; color: #666; font-size: 13px;">输入商品名称进行搜索</span>
                        </div>
                        <label style="font-weight: bold; display: block; margin-bottom: 10px;">
                            <input type="checkbox" id="select-all-products" />
                            全选/取消全选所有商品
                        </label>
                    </div>
                    
                    <div id="products-container" style="max-height: 600px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">
                        <?php foreach ($products as $product_post): 
                            $product = wc_get_product($product_post->ID);
                            if (!$product || !in_array($product->get_type(), ['simple', 'variable'])) {
                                continue;
                            }
                            
                            $is_product_selected = in_array($product_post->ID, $allowed_product_ids);
                            $has_variations = $product->is_type('variable');
                            
                            // 获取变体列表
                            $variations = [];
                            if ($has_variations) {
                                $variations = $product->get_available_variations();
                            }
                        ?>
                            <div class="product-item" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #e0e0e0; border-radius: 4px;" data-product-name="<?php echo esc_attr(strtolower($product_post->post_title)); ?>">
                                <label style="font-weight: bold; display: flex; align-items: center; cursor: pointer;">
                                    <input 
                                        type="checkbox" 
                                        name="allowed_product_ids[]" 
                                        value="<?php echo esc_attr($product_post->ID); ?>"
                                        class="product-checkbox"
                                        <?php checked($is_product_selected); ?>
                                        style="margin-right: 8px;"
                                    />
                                    <span class="product-name"><?php echo esc_html($product_post->post_title); ?> (ID: <?php echo $product_post->ID; ?>)</span>
                                    <span style="color: #666; margin-left: 10px; font-size: 12px;">
                                        <?php echo $has_variations ? '[变体商品]' : '[单品]'; ?>
                                    </span>
                                </label>
                                
                                <?php if ($has_variations && !empty($variations)): ?>
                                    <div style="margin-left: 30px; margin-top: 10px; padding-left: 15px; border-left: 2px solid #ddd;">
                                        <p style="font-size: 12px; color: #666; margin-bottom: 8px;">
                                            <strong>变体选择（可选）：</strong>如果不选择任何变体，则所有变体都可用积分兑换
                                        </p>
                                        <?php foreach ($variations as $variation_data): 
                                            $variation_id = $variation_data['variation_id'];
                                            $variation_obj = wc_get_product($variation_id);
                                            if (!$variation_obj) continue;
                                            
                                            $is_variation_selected = in_array($variation_id, $allowed_variation_ids);
                                            $variation_attrs = [];
                                            foreach ($variation_data['attributes'] as $attr_key => $attr_value) {
                                                $taxonomy = str_replace('attribute_', '', $attr_key);
                                                $term = get_term_by('slug', $attr_value, $taxonomy);
                                                if ($term) {
                                                    $variation_attrs[] = $term->name;
                                                }
                                            }
                                            $variation_name = !empty($variation_attrs) ? implode(' | ', $variation_attrs) : '默认规格';
                                        ?>
                                            <label style="display: block; margin-bottom: 5px; cursor: pointer; font-size: 13px;">
                                                <input 
                                                    type="checkbox" 
                                                    name="allowed_variation_ids[]" 
                                                    value="<?php echo esc_attr($variation_id); ?>"
                                                    class="variation-checkbox"
                                                    <?php checked($is_variation_selected); ?>
                                                    style="margin-right: 5px;"
                                                />
                                                <span><?php echo esc_html($variation_name); ?></span>
                                                <span style="color: #999;">(ID: <?php echo $variation_id; ?>)</span>
                                                <span style="color: #0a9;"> - ¥<?php echo number_format($variation_obj->get_price(), 2); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($products)): ?>
                            <p style="text-align: center; padding: 40px; color: #999;">暂无商品，请先在WooCommerce中添加商品</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <p>
                    <button type="submit" name="myshop_save_redeem_products" class="button button-primary button-large">
                        💾 保存设置
                    </button>
                </p>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                // 全选/取消全选商品
                $('#select-all-products').on('change', function() {
                    $('.product-item:visible .product-checkbox').prop('checked', this.checked);
                });
                
                // 如果所有商品都已选中，自动勾选全选
                $('.product-checkbox').on('change', function() {
                    var visibleCheckboxes = $('.product-item:visible .product-checkbox');
                    var allChecked = visibleCheckboxes.length > 0 && 
                        visibleCheckboxes.filter(':checked').length === visibleCheckboxes.length;
                    $('#select-all-products').prop('checked', allChecked);
                });
                
                // 搜索功能
                $('#product-search').on('input', function() {
                    var searchTerm = $(this).val().toLowerCase().trim();
                    $('.product-item').each(function() {
                        var productName = $(this).data('product-name') || '';
                        if (searchTerm === '' || productName.indexOf(searchTerm) !== -1) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * 处理保存积分兑换商品设置请求
     */
    public static function handle_save_redeem_products() {
        // 验证 nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'myshop_save_points_redeem_products')) {
            wp_die('安全验证失败');
        }
        
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        // 获取当前设置
        $settings = self::get_settings();
        
        // 处理商品ID列表
        $allowed_product_ids = [];
        if (isset($_POST['allowed_product_ids']) && is_array($_POST['allowed_product_ids'])) {
            $allowed_product_ids = array_map('intval', $_POST['allowed_product_ids']);
            $allowed_product_ids = array_filter($allowed_product_ids); // 移除0值
            $allowed_product_ids = array_unique($allowed_product_ids); // 去重
        }
        
        // 处理变体ID列表
        $allowed_variation_ids = [];
        if (isset($_POST['allowed_variation_ids']) && is_array($_POST['allowed_variation_ids'])) {
            $allowed_variation_ids = array_map('intval', $_POST['allowed_variation_ids']);
            $allowed_variation_ids = array_filter($allowed_variation_ids); // 移除0值
            $allowed_variation_ids = array_unique($allowed_variation_ids); // 去重
        }
        
        // 自动清理已下架或已删除的商品和变体ID
        $cleaned = self::clean_invalid_redeem_products($allowed_product_ids, $allowed_variation_ids);
        $allowed_product_ids = $cleaned['product_ids'];
        $allowed_variation_ids = $cleaned['variation_ids'];
        
        // 更新设置
        $settings['redeem_allowed_product_ids'] = $allowed_product_ids;
        $settings['redeem_allowed_variation_ids'] = $allowed_variation_ids;
        
        update_option('myshop_points_settings', $settings);
        
        // 重定向回设置页面并显示成功消息
        wp_redirect(add_query_arg([
            'page' => 'myshop-points-redeem-products',
            'settings-updated' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * 清理无效的积分兑换商品和变体ID
     * 移除已下架、已删除或不存在的商品/变体
     * 
     * @param array $product_ids 商品ID数组
     * @param array $variation_ids 变体ID数组
     * @return array 清理后的商品ID和变体ID，以及是否有变更
     */
    public static function clean_invalid_redeem_products($product_ids, $variation_ids) {
        $has_changes = false;
        
        // 清理商品ID：只保留已发布且存在的商品
        $cleaned_product_ids = [];
        if (!empty($product_ids)) {
            $valid_products = get_posts([
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'post__in' => $product_ids,
                'fields' => 'ids'
            ]);
            
            $cleaned_product_ids = array_map('intval', $valid_products);
            
            if (count($cleaned_product_ids) !== count($product_ids)) {
                $has_changes = true;
            }
        }
        
        // 清理变体ID：只保留存在且所属商品已发布的变体
        $cleaned_variation_ids = [];
        if (!empty($variation_ids)) {
            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                
                // 检查变体是否存在
                if (!$variation || !$variation->is_type('variation')) {
                    $has_changes = true;
                    continue;
                }
                
                // 获取父商品ID
                $parent_id = $variation->get_parent_id();
                if (!$parent_id) {
                    $has_changes = true;
                    continue;
                }
                
                // 检查父商品是否已发布
                $parent_post = get_post($parent_id);
                if (!$parent_post || $parent_post->post_status !== 'publish') {
                    $has_changes = true;
                    continue;
                }
                
                // 检查变体是否在库存中（可选）
                if (!$variation->is_in_stock() && !$variation->backorders_allowed()) {
                    // 如果缺货且不允许缺货销售，可以考虑移除，但这里先保留
                    // 实际使用时，API接口会再次过滤库存状态
                }
                
                $cleaned_variation_ids[] = (int) $variation_id;
            }
            
            $cleaned_variation_ids = array_unique($cleaned_variation_ids);
            
            if (count($cleaned_variation_ids) !== count($variation_ids)) {
                $has_changes = true;
            }
        }
        
        return [
            'product_ids' => $cleaned_product_ids,
            'variation_ids' => $cleaned_variation_ids,
            'has_changes' => $has_changes
        ];
    }
}

// 初始化
MyShop_Points_Manager::init();
