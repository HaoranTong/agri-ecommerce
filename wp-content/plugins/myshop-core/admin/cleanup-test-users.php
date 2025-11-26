<?php
/**
 * 测试用户清理脚本
 * 用途：删除旧的测试用户数据，以便重新创建
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 仅允许管理员执行
if (!current_user_can('manage_options')) {
    wp_die('权限不足');
}

// 确认执行
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>清理测试用户</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 50px; max-width: 800px; margin: 0 auto; }
            .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; }
            .danger { background: #f8d7da; border-left: 4px solid #dc3545; padding: 20px; margin: 20px 0; }
            .button { display: inline-block; padding: 12px 24px; margin: 10px 5px; border-radius: 4px; text-decoration: none; }
            .btn-danger { background: #dc3545; color: white; }
            .btn-secondary { background: #6c757d; color: white; }
        </style>
    </head>
    <body>
        <h1>🗑️ 清理测试用户数据</h1>
        
        <div class="warning">
            <h3>⚠️ 警告</h3>
            <p>此操作将：</p>
            <ol>
                <li><strong>删除所有旧的测试用户</strong>（用户名包含 agri_user_ 或 wx_user_）</li>
                <li><strong>删除这些用户的所有订单</strong></li>
                <li><strong>删除这些用户的积分记录</strong></li>
                <li><strong>删除这些用户的其他关联数据</strong></li>
            </ol>
        </div>

        <div class="danger">
            <h3>🔴 重要提醒</h3>
            <p><strong>此操作不可恢复！</strong></p>
            <p>建议在执行前：</p>
            <ul>
                <li>✅ 确认当前是开发环境</li>
                <li>✅ 已备份数据库</li>
                <li>✅ 确认要删除的用户不包含重要数据</li>
            </ul>
        </div>

        <h3>将被删除的用户：</h3>
        <?php
        global $wpdb;
        
        // 查找所有测试用户
        $test_users = $wpdb->get_results("
            SELECT ID, user_login, user_email, display_name 
            FROM {$wpdb->users} 
            WHERE user_login LIKE 'agri_user_%' 
               OR user_login LIKE 'wx_user_%'
               OR user_login LIKE 'test_%'
            ORDER BY ID
        ");
        
        if (empty($test_users)) {
            echo '<p style="color: green;">✓ 没有找到需要清理的测试用户</p>';
        } else {
            echo '<table border="1" cellpadding="10" style="width: 100%; border-collapse: collapse;">';
            echo '<tr><th>ID</th><th>用户名</th><th>邮箱</th><th>昵称</th></tr>';
            foreach ($test_users as $user) {
                echo '<tr>';
                echo '<td>' . $user->ID . '</td>';
                echo '<td>' . esc_html($user->user_login) . '</td>';
                echo '<td>' . esc_html($user->user_email) . '</td>';
                echo '<td>' . esc_html($user->display_name) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            
            echo '<p><strong>共找到 ' . count($test_users) . ' 个用户</strong></p>';
        }
        ?>

        <div style="margin-top: 40px;">
            <a href="?page=myshop-cleanup-test-users&confirm=yes" class="button btn-danger" 
               onclick="return confirm('确定要删除吗？此操作不可恢复！')">
                🗑️ 确认删除
            </a>
            <a href="<?php echo admin_url('users.php?page=myshop-test-users'); ?>" class="button btn-secondary">
                ← 返回
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 执行清理
echo '<!DOCTYPE html><html><head><title>清理中...</title></head><body style="font-family: Arial; padding: 50px;">';
echo '<h1>正在清理...</h1>';
echo '<pre style="background: #f5f5f5; padding: 20px; border-radius: 4px;">';

global $wpdb;

// 查找所有测试用户
$test_users = $wpdb->get_results("
    SELECT ID, user_login 
    FROM {$wpdb->users} 
    WHERE user_login LIKE 'agri_user_%' 
       OR user_login LIKE 'wx_user_%'
       OR user_login LIKE 'test_%'
");

if (empty($test_users)) {
    echo "✓ 没有找到需要清理的用户\n";
} else {
    require_once(ABSPATH . 'wp-admin/includes/user.php');
    
    foreach ($test_users as $user) {
        echo "删除用户: {$user->user_login} (ID: {$user->ID})...\n";
        
        // 删除用户的订单
        $orders = wc_get_orders([
            'customer_id' => $user->ID,
            'limit' => -1
        ]);
        
        foreach ($orders as $order) {
            echo "  - 删除订单 #{$order->get_id()}\n";
            $order->delete(true); // true = 强制删除
        }
        
        // 删除积分记录
        $deleted_points = $wpdb->delete(
            $wpdb->prefix . 'myshop_point_ledger',
            ['user_id' => $user->ID],
            ['%d']
        );
        if ($deleted_points) {
            echo "  - 删除 {$deleted_points} 条积分记录\n";
        }
        
        // 删除用户（包括所有元数据）
        if (wp_delete_user($user->ID, null)) {
            echo "  ✓ 用户删除成功\n\n";
        } else {
            echo "  ✗ 用户删除失败\n\n";
        }
    }
    
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "✓ 清理完成！共删除 " . count($test_users) . " 个用户\n";
}

echo '</pre>';
echo '<p><a href="' . admin_url('users.php?page=myshop-test-users') . '" style="display: inline-block; padding: 12px 24px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px;">返回测试用户管理</a></p>';
echo '</body></html>';
exit;
