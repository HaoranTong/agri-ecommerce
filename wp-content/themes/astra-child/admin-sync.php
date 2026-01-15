<?php
// admin-sync.php - WordPress 配置同步工具（可审计迭代版 v5.0）
// 原则：
// 1. 同步所有启用插件的配置
// 2. 仅硬排除已知高危项
// 3. 提供完整字段清单 + 插件列表供人工复核
// 作者：Qwen | 日期：2025-12-13

// 强制设置页面编码为 UTF-8
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

if (!current_user_can('manage_options')) {
    wp_die('权限不足');
}

/**
 * 获取安全同步项：包含所有插件配置，仅硬排除高危字段
 */
function myshop_get_safe_sync_options() {
    global $wpdb;

    // === 已注册设置 + 所有选项 ===
    $registered_keys = array_keys(get_registered_settings());
    $all_options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A);

    // === 活跃插件前缀（用于识别插件配置）===
    $plugins = get_option('active_plugins', []);
    $plugin_prefixes = [];
    foreach ($plugins as $plugin) {
        $slug = dirname($plugin);
        $prefix = str_replace('-', '_', $slug) . '_';
        $plugin_prefixes[] = $prefix;
    }

    // === 强制硬黑名单（绝不同步）===
    $hard_blacklist = [
        // 支付敏感信息
        'woocommerce_paypal_settings',
        'woocommerce_stripe_settings',
        'woocommerce_alipay_settings',
        'woocommerce_wechatpay_settings',
        // 运营内容（含环境URL）
        'woocommerce_cod_settings', // 货到付款说明
        'woocommerce_checkout_privacy_policy_text',
        'woocommerce_registration_privacy_policy_text',
        // Elementor 绑定ID
        'elementor_active_kit',
        'elementor_remote_info_cache',
        // Jetpack 私有数据
        'jetpack_private_options',
        'jetpack_secrets',
        // 其他高危项
        'wp_page_for_privacy_policy',
        'users_can_register', // 用户注册开关（可能被滥用），
        'woocommerce_cod_settings',
        'modern_cart_payment_instructions',
        'cartflows_docs_data',
        'astra_sites_recent_import_log_file',
        'elementor_log'
    ];

    // === 黑名单关键词（键名中出现即排除）===
    $blacklist_keywords = ['password', 'secret', 'key', 'token', 'api', 'smtp', 'oauth', 'signature'];

    $safe = [];

    foreach ($all_options as $opt) {
        $key = $opt['option_name'];
        $value = maybe_unserialize($opt['option_value']);

        // 跳过 transient
        if (strpos($key, '_transient') === 0 || strpos($key, '_site_transient') === 0) {
            continue;
        }

        // 硬黑名单
        $skip = false;
        foreach ($hard_blacklist as $bad_key) {
            if (strpos($key, $bad_key) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        // 黑名单关键词
        $lower_key = strtolower($key);
        foreach ($blacklist_keywords as $word) {
            if (strpos($lower_key, $word) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        // 判断是否属于系统或活跃插件
        $is_included = false;
        if (in_array($key, $registered_keys)) {
            $is_included = true;
        } else {
            foreach ($plugin_prefixes as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    $is_included = true;
                    break;
                }
            }
        }

        // 白名单兜底（关键系统项）
        $manual_whitelist = ['template', 'stylesheet', 'active_plugins', 'blogname', 'blogdescription'];
        if (in_array($key, $manual_whitelist)) {
            $is_included = true;
        }

        if ($is_included) {
            $safe[$key] = $value;
        }
    }

    return $safe;
}

/**
 * 获取当前启用的插件列表（用于显示）
 */
function myshop_get_active_plugin_names() {
    $plugins = get_option('active_plugins', []);
    $names = [];
    foreach ($plugins as $plugin_file) {
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        $names[] = $plugin_data['Name'] ?: basename(dirname($plugin_file));
    }
    return $names;
}

// ========================
// 导出处理
// ========================
if (isset($_POST['export_config'])) {
    while (ob_get_level()) ob_end_clean();
    $config = myshop_get_safe_sync_options();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="wp-config-safe-export.json"');
    echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ========================
// 导入处理
// ========================
if (isset($_POST['import_config'])) {
    // 使用 filter_input 获取原始数据，避免 wp_kses 等干扰
    $raw_json = filter_input(INPUT_POST, 'import_json', FILTER_UNSAFE_RAW);
    
    if (!$raw_json) {
        echo '<div class="notice notice-error"><p>❌ 输入为空。</p></div>';
        return;
    }

    // 移除 BOM
    $bom_patterns = ["\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF"];
    foreach ($bom_patterns as $bom) {
        if (strpos($raw_json, $bom) === 0) {
            $raw_json = substr($raw_json, strlen($bom));
            break;
        }
    }

    // 清理前后空白
    $json = trim($raw_json);

    // 输出调试信息（仅用于诊断）
    $first_char = substr($json, 0, 1);
    $ord_first = ord($first_char);
    echo '<div class="notice notice-warning"><p>🔍 首字符: \'' . 
         htmlspecialchars($first_char) . '\' (ASCII: ' . $ord_first . ')</p></div>';

    // 显示前50字符（原始内容）
    echo '<div class="notice notice-info"><p><strong>输入预览:</strong><br/>' .
         '<code style="background:#eee; padding:4px; display:block; white-space:pre-wrap;">' .
         htmlspecialchars(substr($json, 0, 50)) . '</code></p></div>';

    // 尝试解析
    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo '<div class="notice notice-error"><p>❌ JSON 错误：' . htmlspecialchars(json_last_error_msg()) . '</p></div>';
    } else {
        foreach ($data as $key => $value) {
            update_option($key, $value);
        }
        echo '<div class="notice notice-success"><p>✅ 导入成功！共 ' . count($data) . ' 项。</p></div>';
    }
}
// ========================
// 页面渲染
// ========================
$sync_options = myshop_get_safe_sync_options();
$export_count = count($sync_options);
$option_keys = array_keys($sync_options);
$active_plugins = myshop_get_active_plugin_names();
?>

<div class="wrap">
    <h2>WordPress 配置同步工具（可审计迭代版 v5.0）</h2>
    <p style="background:#e6f4ea;padding:12px;border-left:4px solid #34a853;margin:20px 0;">
        ✅ <strong>同步所有启用插件的配置</strong><br>
        🔒 <strong>仅硬排除已知高危字段</strong>（见下方黑名单）<br>
        👁️ <strong>提供完整字段清单 + 插件列表供你复核</strong>
    </p>

    <!-- 当前启用的插件 -->
    <div class="metabox-holder">
        <div class="postbox">
            <h2>当前启用的插件（将同步其配置）</h2>
            <ul style="max-height:200px;overflow:auto;background:#f9f9f9;padding:10px;">
                <?php foreach ($active_plugins as $name): ?>
                    <li><?php echo esc_html($name); ?></li>
                <?php endforeach; ?>
            </ul>
            <p style="font-size:12px;color:#666;">共 <?php echo count($active_plugins); ?> 个插件</p>
        </div>
    </div>

    <!-- 导出区域 -->
    <div class="metabox-holder">
        <div class="postbox">
            <h2>导出安全配置</h2>
            <p>点击下载配置文件。导出后请检查内容，若发现风险字段，请反馈给我加入黑名单。</p>
            
            <!-- 显示当前硬黑名单 -->
            <details style="margin:10px 0;">
                <summary><strong>当前硬黑名单（<?php echo count([/* hard_blacklist */]); ?> 项）</strong></summary>
                <pre style="background:#fef0f0;padding:8px;font-size:11px;max-height:150px;overflow:auto;">
woocommerce_paypal_settings
woocommerce_stripe_settings
woocommerce_alipay_settings
woocommerce_wechatpay_settings
woocommerce_cod_settings
woocommerce_checkout_privacy_policy_text
woocommerce_registration_privacy_policy_text
elementor_active_kit
elementor_remote_info_cache
jetpack_private_options
jetpack_secrets
wp_page_for_privacy_policy
users_can_register
                </pre>
            </details>

            <form method="post" action="">
                <button type="submit" name="export_config" class="button button-primary">⬇️ 下载安全配置文件</button>
            </form>
            <div style="margin-top:15px;">
                <p><strong>本次将导出以下 <span id="export-count"><?php echo $export_count; ?></span> 项：</strong></p>
                <pre id="config-list" style="background:#f8f9fa;padding:10px;border:1px solid #ddd;max-height:300px;overflow:auto;font-size:12px;"></pre>
            </div>
        </div>
    </div>

    <!-- 导入区域 -->
    <div class="metabox-holder">
        <div class="postbox">
            <h2>导入配置</h2>
            <p>粘贴从开发环境导出的 JSON 内容：</p>
            <form method="post" action="">
                <textarea name="import_json" rows="8" cols="80" placeholder="粘贴 JSON 内容..." style="width:100%;font-family:monospace;"></textarea>
                <br><br>
                <button type="submit" name="import_config" class="button button-secondary">📤 导入配置</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const config = <?php echo json_encode($option_keys); ?>;
    document.getElementById('config-list').textContent = config.sort().join('\n');
    document.getElementById('export-count').textContent = config.length;
});
</script>