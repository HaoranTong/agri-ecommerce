<?php
/**
 * 测试用户管理页面
 * 用于开发测试阶段管理固定的测试账号
 */

class MyShop_Test_Users_Manager {

    public static function init() {
        // 只在管理后台显示
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'add_menu_page']);
            add_action('admin_init', [self::class, 'handle_actions']);
        }
    }
    
    /**
     * 添加管理菜单
     */
    public static function add_menu_page() {
        add_submenu_page(
            'users.php',
            '测试用户管理',
            '🧪 测试用户',
            'manage_options',
            'myshop-test-users',
            [self::class, 'render_page']
        );
    }
    
    /**
     * 处理添加/删除操作
     */
    public static function handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // 添加测试用户
        if (isset($_POST['myshop_add_test_user']) && check_admin_referer('myshop_test_user_add')) {
            $code = sanitize_text_field($_POST['test_code']);
            $openid = sanitize_text_field($_POST['test_openid']);
            $nickname = sanitize_text_field($_POST['test_nickname']);
            $phone = sanitize_text_field($_POST['test_phone']);
            $description = sanitize_textarea_field($_POST['test_description']);
            
            if (!empty($code) && !empty($openid)) {
                MyShop_Auth::add_test_user($code, $openid, $nickname, $phone, $description);
                
                wp_redirect(add_query_arg([
                    'page' => 'myshop-test-users',
                    'message' => 'added'
                ], admin_url('users.php')));
                exit;
            }
        }
        
        // 删除测试用户
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['code'])) {
            check_admin_referer('delete_test_user_' . $_GET['code']);
            
            MyShop_Auth::delete_test_user(sanitize_text_field($_GET['code']));
            
            wp_redirect(add_query_arg([
                'page' => 'myshop-test-users',
                'message' => 'deleted'
            ], admin_url('users.php')));
            exit;
        }
        
        // 创建WordPress用户
        if (isset($_GET['action']) && $_GET['action'] === 'create_wp_user' && isset($_GET['code'])) {
            check_admin_referer('create_wp_user_' . $_GET['code']);
            
            $test_users = MyShop_Auth::get_test_users();
            $code = sanitize_text_field($_GET['code']);
            
            if (isset($test_users[$code])) {
                $openid = $test_users[$code]['openid'];
                $nickname = $test_users[$code]['nickname'];
                $phone = isset($test_users[$code]['phone']) ? $test_users[$code]['phone'] : '';
                
                // 检查是否已存在
                global $wpdb;
                $existing_user_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_wechat_openid' AND meta_value = %s",
                    $openid
                ));
                
                if ($existing_user_id) {
                    // 用户已存在，更新完整信息
                    $username = get_userdata($existing_user_id)->user_login;
                    
                    wp_update_user([
                        'ID' => $existing_user_id,
                        'display_name' => $nickname,
                        'nickname' => $nickname,
                        'first_name' => $nickname,
                    ]);
                    
                    // 更新用户元数据
                    if (!empty($phone)) {
                        update_user_meta($existing_user_id, 'billing_phone', $phone);
                        update_user_meta($existing_user_id, 'phone', $phone);
                    }
                    update_user_meta($existing_user_id, '_test_user_code', $code);
                    update_user_meta($existing_user_id, '_is_test_user', '1');
                    
                    wp_redirect(add_query_arg([
                        'page' => 'myshop-test-users',
                        'message' => 'already_exists',
                        'user_id' => $existing_user_id
                    ], admin_url('users.php')));
                    exit;
                }
                
                // 创建新用户，使用清晰的用户名格式
                $username = 'test_' . $code;  // 例如: test_test001, test_admin
                $email = $code . '@test.myshop.local';  // 使用固定格式的邮箱
                $password = wp_generate_password(16, true, true);
                
                $user_id = wp_create_user($username, $password, $email);
                
                if (is_wp_error($user_id)) {
                    error_log('创建测试用户失败: ' . $user_id->get_error_message());
                    wp_redirect(add_query_arg([
                        'page' => 'myshop-test-users',
                        'message' => 'error'
                    ], admin_url('users.php')));
                    exit;
                }
                
                // 保存openid
                update_user_meta($user_id, '_wechat_openid', $openid);
                
                // 更新用户基本信息
                wp_update_user([
                    'ID' => $user_id,
                    'display_name' => $nickname,
                    'nickname' => $nickname,
                    'first_name' => $nickname,
                    'description' => '测试账号: ' . $code
                ]);
                
                // 保存完整的用户元数据
                if (!empty($phone)) {
                    update_user_meta($user_id, 'billing_phone', $phone);
                    update_user_meta($user_id, 'phone', $phone);
                }
                update_user_meta($user_id, '_test_user_code', $code);
                update_user_meta($user_id, '_is_test_user', '1');
                update_user_meta($user_id, 'test_password', $password); // 保存密码供查看
                
                if (!empty($test_users[$code]['phone'])) {
                    update_user_meta($user_id, 'billing_phone', $test_users[$code]['phone']);
                }
                
                wp_redirect(add_query_arg([
                    'page' => 'myshop-test-users',
                    'message' => 'created',
                    'user_id' => $user_id
                ], admin_url('users.php')));
                exit;
            }
        }
    }
    
    /**
     * 渲染管理页面
     */
    public static function render_page() {
        $test_users = MyShop_Auth::get_test_users();
        
        // 显示消息
        if (isset($_GET['message'])) {
            switch ($_GET['message']) {
                case 'added':
                    echo '<div class="notice notice-success is-dismissible"><p>✓ 测试用户已添加</p></div>';
                    break;
                case 'deleted':
                    echo '<div class="notice notice-success is-dismissible"><p>✓ 测试用户已删除</p></div>';
                    break;
                case 'created':
                    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
                    $user_info = get_userdata($user_id);
                    $username = $user_info ? $user_info->user_login : '';
                    $view_url = admin_url('user-edit.php?user_id=' . $user_id);
                    echo '<div class="notice notice-success is-dismissible" style="padding: 15px;"><p style="font-size: 14px; margin: 0;">';
                    echo '✅ <strong style="font-size: 16px;">WordPress用户已创建成功！</strong><br><br>';
                    echo '📝 <strong>用户名</strong>: <code style="background: #f0f0f0; padding: 4px 8px; border-radius: 3px; font-size: 14px; font-weight: bold; color: #d63384;">' . esc_html($username) . '</code><br>';
                    echo '💡 <strong>提示</strong>: 在 "用户 → 所有用户" 中搜索 <strong>' . esc_html($username) . '</strong> 即可找到<br>';
                    echo '🆔 <strong>用户ID</strong>: ' . $user_id . '<br><br>';
                    echo '<a href="' . $view_url . '" class="button button-primary" style="margin-right: 10px;" target="_blank">📋 查看用户详情</a>';
                    echo '<a href="' . admin_url('users.php?s=' . urlencode($username)) . '" class="button button-secondary" target="_blank">🔍 在用户列表中搜索</a>';
                    echo '</p></div>';
                    break;
                case 'already_exists':
                    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
                    $user_info = get_userdata($user_id);
                    $username = $user_info ? $user_info->user_login : '';
                    $view_url = admin_url('user-edit.php?user_id=' . $user_id);
                    echo '<div class="notice notice-info is-dismissible"><p>ℹ️ 该测试用户的WordPress账号已存在<br>';
                    echo '👤 <strong>用户名</strong>: ' . esc_html($username) . '<br>';
                    echo '🆔 <strong>用户ID</strong>: ' . $user_id . '<br>';
                    echo '<a href="' . $view_url . '" class="button" style="margin-top: 10px;" target="_blank">查看用户详情</a>';
                    echo '</p></div>';
                    break;
                case 'error':
                    echo '<div class="notice notice-error is-dismissible"><p>❌ 创建用户失败，请检查错误日志</p></div>';
                    break;
            }
        }
        ?>
        <div class="wrap">
            <h1 style="display: inline-block;">🧪 测试用户管理</h1>
            <a href="<?php echo admin_url('admin.php?page=myshop-cleanup-test-users'); ?>" 
               class="page-title-action" 
               style="background: #dc3545; border-color: #dc3545; margin-left: 20px;"
               onclick="return confirm('确定要进入清理页面吗？\n\n此操作将删除所有旧的测试用户及其数据！')">
                🗑️ 清理旧测试用户
            </a>
            
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;">💡 使用说明</h3>
                <p><strong>用途：</strong>在开发测试阶段，使用固定的测试code来登录，避免每次清除缓存后openid变化导致用户数据丢失。</p>
                <p><strong>使用方法：</strong></p>
                <ol>
                    <li>在微信开发者工具中，使用下方列出的 <code>测试Code</code> 进行登录</li>
                    <li>每个测试code对应一个固定的openid，数据会持久保存</li>
                    <li>可以随时清除缓存、重新编译，只要使用相同的测试code就能恢复用户数据</li>
                </ol>
                <p><strong>⚠️ 注意：</strong>这些测试用户仅用于开发环境，生产环境请禁用或删除。</p>
            </div>
            
            <h2>📋 现有测试用户</h2>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 120px;">测试Code</th>
                        <th style="width: 200px;">固定OpenID</th>
                        <th style="width: 150px;">昵称</th>
                        <th style="width: 120px;">手机号</th>
                        <th>说明</th>
                        <th style="width: 100px;">WP用户</th>
                        <th style="width: 150px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($test_users)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                                暂无测试用户，请添加
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($test_users as $code => $user): ?>
                            <?php
                            // 检查是否已创建WordPress用户
                            global $wpdb;
                            $wp_user_id = $wpdb->get_var($wpdb->prepare(
                                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_wechat_openid' AND meta_value = %s",
                                $user['openid']
                            ));
                            ?>
                            <tr>
                                <td>
                                    <code style="background: #f0f0f0; padding: 4px 8px; border-radius: 3px; font-weight: bold;">
                                        <?php echo esc_html($code); ?>
                                    </code>
                                </td>
                                <td>
                                    <code style="font-size: 11px; color: #666;">
                                        <?php echo esc_html($user['openid']); ?>
                                    </code>
                                </td>
                                <td><?php echo esc_html($user['nickname']); ?></td>
                                <td><?php echo esc_html($user['phone'] ?? '-'); ?></td>
                                <td><?php echo esc_html($user['description'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($wp_user_id): ?>
                                        <?php 
                                        $wp_user_data = get_userdata($wp_user_id);
                                        $wp_username = $wp_user_data ? $wp_user_data->user_login : 'N/A';
                                        ?>
                                        <a href="<?php echo admin_url('user-edit.php?user_id=' . $wp_user_id); ?>" target="_blank">
                                            <span style="color: #0a9;">✓ 已创建</span><br>
                                            <small>用户名: <strong><?php echo esc_html($wp_username); ?></strong></small><br>
                                            <small>ID: <?php echo $wp_user_id; ?></small>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">未创建</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$wp_user_id): ?>
                                        <a href="<?php echo wp_nonce_url(
                                            add_query_arg([
                                                'page' => 'myshop-test-users',
                                                'action' => 'create_wp_user',
                                                'code' => $code
                                            ], admin_url('users.php')),
                                            'create_wp_user_' . $code
                                        ); ?>" class="button button-small button-primary">
                                            创建用户
                                        </a>
                                    <?php else: ?>
                                        <?php 
                                        $wp_user_data = get_userdata($wp_user_id);
                                        $wp_username = $wp_user_data ? $wp_user_data->user_login : '';
                                        ?>
                                        <a href="<?php echo admin_url('users.php?s=' . urlencode($wp_username)); ?>" 
                                           class="button button-small" 
                                           target="_blank" 
                                           title="在用户列表中搜索 <?php echo esc_attr($wp_username); ?>">
                                            🔍 搜索用户
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php
                                    // 只允许删除自定义用户（不是默认的4个）
                                    $default_codes = ['test001', 'test002', 'test003', 'admin'];
                                    if (!in_array($code, $default_codes)):
                                    ?>
                                        <a href="<?php echo wp_nonce_url(
                                            add_query_arg([
                                                'page' => 'myshop-test-users',
                                                'action' => 'delete',
                                                'code' => $code
                                            ], admin_url('users.php')),
                                            'delete_test_user_' . $code
                                        ); ?>" 
                                        class="button button-small"
                                        onclick="return confirm('确定要删除这个测试用户吗？');">
                                            删除
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;">📱 如何在小程序中使用</h3>
                <p><strong>方法一：修改登录代码（推荐）</strong></p>
                <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto;">// 在 src/pages/auth/login.tsx 或类似文件中
// 原来的代码：
const code = await Taro.login().then(res => res.code);

// 改为测试代码（开发环境）：
const isDev = process.env.NODE_ENV === 'development';
const code = isDev ? '<span style="color: #d63">test001</span>' : await Taro.login().then(res => res.code);</pre>
                
                <p><strong>方法二：配置环境变量</strong></p>
                <pre style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto;">// 在 config/dev.ts 中添加：
export default {
  env: {
    <span style="color: #d63">TEST_USER_CODE: 'test001'</span>
  }
}

// 在登录代码中使用：
const code = process.env.TEST_USER_CODE || await Taro.login().then(res => res.code);</pre>

                <p><strong>方法三：手动输入（临时测试）</strong></p>
                <p>在登录页面添加一个隐藏的输入框，开发时可以直接输入测试code。</p>
            </div>
            
            <h2 style="margin-top: 40px;">➕ 添加自定义测试用户</h2>
            
            <form method="post" action="" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 8px;">
                <?php wp_nonce_field('myshop_test_user_add'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="test_code">测试Code <span style="color: #d63;">*</span></label></th>
                        <td>
                            <input type="text" name="test_code" id="test_code" class="regular-text" required
                                   placeholder="例如: test004">
                            <p class="description">用于登录的测试代码，建议使用易记的字符串（如：test004, dev001等）</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="test_openid">固定OpenID <span style="color: #d63;">*</span></label></th>
                        <td>
                            <input type="text" name="test_openid" id="test_openid" class="regular-text" required
                                   placeholder="例如: oTest_Custom_User_001">
                            <p class="description">固定的OpenID，必须唯一，建议格式：oTest_XXX_XXX</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="test_nickname">昵称</label></th>
                        <td>
                            <input type="text" name="test_nickname" id="test_nickname" class="regular-text"
                                   placeholder="例如: 测试账号004">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="test_phone">手机号</label></th>
                        <td>
                            <input type="text" name="test_phone" id="test_phone" class="regular-text"
                                   placeholder="例如: 13800138004">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="test_description">说明</label></th>
                        <td>
                            <textarea name="test_description" id="test_description" class="large-text" rows="3"
                                      placeholder="例如: 用于测试订单流程"></textarea>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="myshop_add_test_user" class="button button-primary">
                        添加测试用户
                    </button>
                </p>
            </form>
            
            <div style="background: #ffebee; border-left: 4px solid #f44336; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;">⚠️ 安全提醒</h3>
                <ul>
                    <li>测试用户仅用于开发环境，<strong>生产环境请禁用此功能</strong></li>
                    <li>不要在测试账号中存储真实的敏感信息</li>
                    <li>上线前请删除所有自定义测试用户</li>
                    <li>建议在 <code>wp-config.php</code> 中添加环境判断：<br>
                        <code style="background: #fff; padding: 4px 8px;">define('WP_ENVIRONMENT_TYPE', 'development');</code>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }
}

// 初始化
MyShop_Test_Users_Manager::init();
