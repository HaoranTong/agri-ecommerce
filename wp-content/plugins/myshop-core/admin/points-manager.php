<?php
/**
 * 积分系统管理页面
 * 功能：积分规则设置、自动累积、兑换管理
 */

class MyShop_Points_Manager {

    public static function init() {
        // 添加管理菜单
        add_action('admin_menu', [self::class, 'add_menu_page']);
        
        // 订单完成时自动发放积分
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
            'expiry_days' => 365
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
            'expiry_days' => intval($_POST['expiry_days'] ?? 365)
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
        
        $settings = self::get_settings();
        
        // 检查是否启用积分系统
        if (!$settings['enable_points']) {
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
}

// 初始化
MyShop_Points_Manager::init();
