<?php
/**
 * 付款凭证管理页面
 */

class MyShop_Payment_Proof_Manager {

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu_page']);
        add_action('add_meta_boxes', [self::class, 'add_order_meta_box']);
        
        // 在订单列表添加付款凭证列（兼容传统和HPOS）
        add_filter('manage_edit-shop_order_columns', [self::class, 'add_order_column']);
        add_filter('woocommerce_shop_order_list_table_columns', [self::class, 'add_order_column']); // HPOS
        
        add_action('manage_shop_order_posts_custom_column', [self::class, 'render_order_column'], 10, 2);
        add_action('woocommerce_shop_order_list_table_custom_column', [self::class, 'render_order_column_hpos'], 10, 2); // HPOS
        
        // 添加快速查看凭证的弹窗样式和脚本
        add_action('admin_footer', [self::class, 'add_lightbox_script']);
        
        // 处理清理操作
        if (isset($_POST['myshop_cleanup_proofs']) && check_admin_referer('myshop_cleanup_proofs')) {
            add_action('admin_notices', [self::class, 'handle_cleanup_action']);
        }
    }
    
    /**
     * 清理指定天数之前的已完成订单凭证
     */
    public static function handle_cleanup_action() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $days = absint($_POST['cleanup_days'] ?? 90);
        $date_before = date('Y-m-d', strtotime("-{$days} days"));
        
        $args = [
            'limit' => -1,
            'status' => ['completed'],
            'date_before' => $date_before,
            'meta_query' => [
                [
                    'key' => '_myshop_payment_proof_path',
                    'compare' => 'EXISTS'
                ]
            ]
        ];
        
        $orders = wc_get_orders($args);
        $deleted_count = 0;
        $total_size = 0;
        
        foreach ($orders as $order) {
            $proof_path = get_post_meta($order->get_id(), '_myshop_payment_proof_path', true);
            if ($proof_path && file_exists($proof_path)) {
                $total_size += filesize($proof_path);
                if (unlink($proof_path)) {
                    delete_post_meta($order->get_id(), '_myshop_payment_proof_path');
                    delete_post_meta($order->get_id(), '_myshop_payment_proof_url');
                    $deleted_count++;
                }
            }
        }
        
        echo '<div class="notice notice-success"><p>';
        echo sprintf('已清理 %d 个已完成订单的付款凭证，释放空间 %s', $deleted_count, size_format($total_size));
        echo '</p></div>';
    }
    
    /**
     * 在订单列表添加付款凭证列
     */
    public static function add_order_column($columns) {
        // 在"操作"列之前插入（传统：order_actions，HPOS：wc_actions）
        $new_columns = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order_actions' || $key === 'wc_actions') {
                $new_columns['payment_proof'] = '💳 付款凭证';
            }
            $new_columns[$key] = $label;
        }
        return $new_columns;
    }
    
    /**
     * 渲染订单列表的付款凭证列
     */
    public static function render_order_column($column, $post_id) {
        if ($column !== 'payment_proof') {
            return;
        }
        
        // 优先使用新存储方式
        $proof_url = get_post_meta($post_id, '_myshop_payment_proof_url', true);
        $proof_path = get_post_meta($post_id, '_myshop_payment_proof_path', true);
        
        // 兼容旧版本媒体库存储
        if (!$proof_url) {
            $proof_id = get_post_meta($post_id, '_myshop_payment_proof', true);
            $proof_url = $proof_id ? wp_get_attachment_url($proof_id) : '';
        }
        
        $submitted_at = get_post_meta($post_id, '_myshop_payment_proof_submitted_at', true);
        
        if (!$proof_url) {
            echo '<span style="color: #999; font-size: 12px;">未上传</span>';
            return;
        }
        
        // 检查文件是否存在
        $file_exists = !$proof_path || file_exists($proof_path);
        
        ?>
        <div style="text-align: center;">
            <a href="<?php echo esc_url($proof_url); ?>" 
               class="myshop-proof-preview" 
               data-image="<?php echo esc_attr($proof_url); ?>"
               data-order="<?php echo esc_attr($post_id); ?>"
               title="点击查看付款凭证">
                <span style="font-size: 32px; cursor: pointer; display: block; line-height: 1;">
                    <?php echo $file_exists ? '🖼️' : '⚠️'; ?>
                </span>
            </a>
            <?php if ($submitted_at): ?>
                <small style="display: block; color: #666; font-size: 11px; margin-top: 4px;">
                    <?php echo date('m-d H:i', strtotime($submitted_at)); ?>
                </small>
            <?php endif; ?>
            <?php if (!$file_exists): ?>
                <small style="display: block; color: #d63638; font-size: 11px;">
                    文件缺失
                </small>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * 渲染订单列表的付款凭证列（HPOS版本）
     */
    public static function render_order_column_hpos($column, $order) {
        if ($column !== 'payment_proof') {
            return;
        }
        
        // HPOS 中 $order 是 WC_Order 对象
        if (!is_a($order, 'WC_Order')) {
            return;
        }
        
        $post_id = $order->get_id();
        
        // 优先使用新存储方式
        $proof_url = $order->get_meta('_myshop_payment_proof_url', true);
        $proof_path = $order->get_meta('_myshop_payment_proof_path', true);
        
        // 兼容旧版本媒体库存储
        if (!$proof_url) {
            $proof_id = $order->get_meta('_myshop_payment_proof', true);
            $proof_url = $proof_id ? wp_get_attachment_url($proof_id) : '';
        }
        
        $submitted_at = $order->get_meta('_myshop_payment_proof_submitted_at', true);
        
        if (!$proof_url) {
            echo '<span style="color: #999; font-size: 12px;">未上传</span>';
            return;
        }
        
        // 检查文件是否存在
        $file_exists = !$proof_path || file_exists($proof_path);
        
        ?>
        <div style="text-align: center;">
            <a href="<?php echo esc_url($proof_url); ?>" 
               class="myshop-proof-preview" 
               data-image="<?php echo esc_attr($proof_url); ?>"
               data-order="<?php echo esc_attr($post_id); ?>"
               title="点击查看付款凭证">
                <span style="font-size: 32px; cursor: pointer; display: block; line-height: 1;">
                    <?php echo $file_exists ? '🖼️' : '⚠️'; ?>
                </span>
            </a>
            <?php if ($submitted_at): ?>
                <small style="display: block; color: #666; font-size: 11px; margin-top: 4px;">
                    <?php echo date('m-d H:i', strtotime($submitted_at)); ?>
                </small>
            <?php endif; ?>
            <?php if (!$file_exists): ?>
                <small style="display: block; color: #d63638; font-size: 11px;">
                    文件缺失
                </small>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * 添加图片预览弹窗脚本
     */
    public static function add_lightbox_script() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'])) {
            return;
        }
        ?>
        <style>
            .myshop-lightbox {
                display: none;
                position: fixed;
                z-index: 999999;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.9);
                animation: fadeIn 0.3s;
            }
            .myshop-lightbox.active {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .myshop-lightbox-content {
                max-width: 90%;
                max-height: 90%;
                position: relative;
                animation: zoomIn 0.3s;
            }
            .myshop-lightbox img {
                max-width: 100%;
                max-height: 90vh;
                border-radius: 8px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
            }
            .myshop-lightbox-close {
                position: absolute;
                top: -40px;
                right: 0;
                color: white;
                font-size: 40px;
                font-weight: bold;
                cursor: pointer;
                background: none;
                border: none;
                padding: 0;
                line-height: 1;
            }
            .myshop-lightbox-close:hover {
                color: #ff6b6b;
            }
            .myshop-lightbox-info {
                position: absolute;
                bottom: -60px;
                left: 0;
                right: 0;
                text-align: center;
                color: white;
                font-size: 14px;
            }
            .myshop-lightbox-actions {
                position: absolute;
                bottom: -100px;
                left: 50%;
                transform: translateX(-50%);
                display: flex;
                gap: 10px;
            }
            .myshop-lightbox-btn {
                padding: 10px 20px;
                background: white;
                color: #333;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                text-decoration: none;
                display: inline-block;
            }
            .myshop-lightbox-btn:hover {
                background: #f0f0f0;
            }
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes zoomIn {
                from { transform: scale(0.8); }
                to { transform: scale(1); }
            }
        </style>
        
        <div id="myshop-proof-lightbox" class="myshop-lightbox">
            <div class="myshop-lightbox-content">
                <button class="myshop-lightbox-close">&times;</button>
                <img src="" alt="付款凭证">
                <div class="myshop-lightbox-info">
                    <span id="myshop-lightbox-order">订单 #<span></span></span>
                </div>
                <div class="myshop-lightbox-actions">
                    <a href="#" class="myshop-lightbox-btn" target="_blank">🔍 新窗口打开</a>
                    <a href="#" class="myshop-lightbox-btn myshop-edit-order">📝 编辑订单</a>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            const lightbox = $('#myshop-proof-lightbox');
            const lightboxImg = lightbox.find('img');
            const lightboxOrder = lightbox.find('#myshop-lightbox-order span');
            const openNewTab = lightbox.find('.myshop-lightbox-btn[target="_blank"]');
            const editOrder = lightbox.find('.myshop-edit-order');
            
            // 点击预览图标
            $(document).on('click', '.myshop-proof-preview', function(e) {
                e.preventDefault();
                const imageUrl = $(this).data('image');
                const orderId = $(this).data('order');
                
                lightboxImg.attr('src', imageUrl);
                lightboxOrder.text(orderId);
                openNewTab.attr('href', imageUrl);
                editOrder.attr('href', 'post.php?post=' + orderId + '&action=edit');
                lightbox.addClass('active');
            });
            
            // 点击关闭按钮
            lightbox.find('.myshop-lightbox-close').on('click', function() {
                lightbox.removeClass('active');
            });
            
            // 点击背景关闭
            lightbox.on('click', function(e) {
                if (e.target === this) {
                    lightbox.removeClass('active');
                }
            });
            
            // ESC键关闭
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && lightbox.hasClass('active')) {
                    lightbox.removeClass('active');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * 添加管理菜单
     */
    public static function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            '付款凭证管理',
            '付款凭证',
            'manage_woocommerce',
            'myshop-payment-proofs',
            [self::class, 'render_admin_page']
        );
    }

    /**
     * 在订单编辑页面添加付款凭证元框
     */
    public static function add_order_meta_box() {
        // 传统订单编辑页面
        add_meta_box(
            'myshop_payment_proof',
            '💳 付款凭证',
            [self::class, 'render_order_meta_box'],
            'shop_order',
            'side',
            'high'
        );
        
        // HPOS 订单编辑页面
        add_meta_box(
            'myshop_payment_proof',
            '💳 付款凭证',
            [self::class, 'render_order_meta_box'],
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }

    /**
     * 渲染订单编辑页面的付款凭证元框
     */
    public static function render_order_meta_box($post_or_order) {
        // 兼容传统模式（$post）和 HPOS 模式（$order）
        if (is_a($post_or_order, 'WC_Order')) {
            // HPOS 模式
            $order = $post_or_order;
            $order_id = $order->get_id();
        } else {
            // 传统模式
            $order_id = $post_or_order->ID;
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            echo '<p style="color: #999;">无法获取订单信息</p>';
            return;
        }
        
        // 优先使用新存储方式
        $proof_url = get_post_meta($order_id, '_myshop_payment_proof_url', true);
        $proof_path = get_post_meta($order_id, '_myshop_payment_proof_path', true);
        
        // 兼容旧版本媒体库存储
        if (!$proof_url) {
            $proof_id = get_post_meta($order_id, '_myshop_payment_proof', true);
            if ($proof_id) {
                $proof_url = wp_get_attachment_url($proof_id);
            }
        }
        
        $submitted_at = get_post_meta($order_id, '_myshop_payment_proof_submitted_at', true);

        if (!$proof_url) {
            echo '<p style="color: #999;">用户尚未上传付款凭证</p>';
            return;
        }

        // 检查文件是否存在
        $file_exists = $proof_path ? file_exists($proof_path) : true;
        $file_size = $proof_path && $file_exists ? size_format(filesize($proof_path)) : '未知';

        ?>
        <div class="myshop-payment-proof-box">
            <p><strong>提交时间：</strong><br><?php echo esc_html($submitted_at ?: '未知'); ?></p>
            
            <?php if ($proof_path): ?>
                <p style="font-size: 12px; color: #666;">
                    <strong>文件大小：</strong><?php echo esc_html($file_size); ?><br>
                    <strong>存储方式：</strong>独立目录
                    <?php if (!$file_exists): ?>
                        <br><span style="color: #d63638;">⚠️ 文件不存在</span>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p style="font-size: 12px; color: #999;">
                    <em>（旧版本数据：存储在媒体库）</em>
                </p>
            <?php endif; ?>
            
            <div style="margin: 15px 0;">
                <a href="<?php echo esc_url($proof_url); ?>" target="_blank">
                    <img src="<?php echo esc_url($proof_url); ?>" 
                         style="max-width: 100%; height: auto; border: 2px solid #ddd; border-radius: 4px;" 
                         alt="付款凭证" />
                </a>
            </div>
            
            <p style="margin-top: 10px;">
                <a href="<?php echo esc_url($proof_url); ?>" class="button button-primary" target="_blank">
                    🔍 查看原图
                </a>
                <?php if ($proof_path): ?>
                    <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($proof_path); ?>'); alert('文件路径已复制');">
                        📋 复制路径
                    </button>
                <?php endif; ?>
            </p>
            
            <p style="font-size: 12px; color: #666; margin-top: 15px;">
                <strong>提示：</strong>确认收款后，请将订单状态更新为"处理中"或"已完成"。
            </p>
        </div>
        <?php
    }

    /**
     * 渲染付款凭证管理页面
     */
    public static function render_admin_page() {
        // 获取所有包含付款凭证的订单（支持新旧两种存储方式）
        $args = [
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_myshop_payment_proof_url',
                    'compare' => 'EXISTS'
                ],
                [
                    'key' => '_myshop_payment_proof',
                    'compare' => 'EXISTS'
                ]
            ]
        ];
        
        $orders = wc_get_orders($args);
        
        ?>
        <div class="wrap">
            <h1>💳 付款凭证管理</h1>
            <p>共有 <strong><?php echo count($orders); ?></strong> 个订单上传了付款凭证</p>
            
            <div style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
                <h3 style="margin-top: 0;">🗑️ 清理工具</h3>
                <form method="post" onsubmit="return confirm('确定要清理已完成订单的付款凭证吗？此操作不可恢复！');">
                    <?php wp_nonce_field('myshop_cleanup_proofs'); ?>
                    <p>
                        <label>清理 
                            <input type="number" name="cleanup_days" value="90" min="1" style="width: 80px;"> 
                            天前已完成订单的付款凭证
                        </label>
                        <button type="submit" name="myshop_cleanup_proofs" class="button">
                            开始清理
                        </button>
                    </p>
                    <p style="font-size: 12px; color: #666;">
                        ⚠️ 建议：仅清理已完成且超过90天的订单凭证。清理后无法恢复，请谨慎操作。
                    </p>
                </form>
            </div>
            
            <?php if (empty($orders)): ?>
                <div class="notice notice-info">
                    <p>暂无用户上传付款凭证</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;">订单号</th>
                            <th style="width: 100px;">客户</th>
                            <th style="width: 80px;">金额</th>
                            <th style="width: 100px;">订单状态</th>
                            <th style="width: 150px;">凭证提交时间</th>
                            <th style="width: 200px;">付款凭证</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): 
                            // 优先使用新存储方式
                            $proof_url = get_post_meta($order->get_id(), '_myshop_payment_proof_url', true);
                            $proof_path = get_post_meta($order->get_id(), '_myshop_payment_proof_path', true);
                            
                            // 兼容旧版本媒体库存储
                            if (!$proof_url) {
                                $proof_id = get_post_meta($order->get_id(), '_myshop_payment_proof', true);
                                $proof_url = $proof_id ? wp_get_attachment_url($proof_id) : '';
                            }
                            
                            $submitted_at = get_post_meta($order->get_id(), '_myshop_payment_proof_submitted_at', true);
                            
                            // 跳过没有凭证的订单
                            if (!$proof_url) {
                                continue;
                            }
                            
                            // 订单状态颜色
                            $status_colors = [
                                'pending' => '#f0ad4e',
                                'processing' => '#5bc0de',
                                'on-hold' => '#f0ad4e',
                                'completed' => '#5cb85c',
                                'cancelled' => '#d9534f',
                                'refunded' => '#d9534f',
                                'failed' => '#d9534f'
                            ];
                            $status = $order->get_status();
                            $status_color = $status_colors[$status] ?? '#999';
                        ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>">
                                        #<?php echo $order->get_order_number(); ?>
                                    </a>
                                </strong>
                            </td>
                            <td>
                                <?php 
                                $customer = $order->get_user();
                                echo $customer ? esc_html($customer->display_name) : '游客';
                                ?>
                            </td>
                            <td>
                                <strong style="color: #e74c3c;">
                                    ¥<?php echo $order->get_total(); ?>
                                </strong>
                            </td>
                            <td>
                                <span style="display: inline-block; padding: 4px 10px; background: <?php echo esc_attr($status_color); ?>; color: white; border-radius: 3px; font-size: 12px;">
                                    <?php echo wc_get_order_status_name($status); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo esc_html($submitted_at ?: '未知'); ?>
                                <?php if ($proof_path): ?>
                                    <br><small style="color: #666;">📁 独立目录</small>
                                <?php else: ?>
                                    <br><small style="color: #999;">📚 媒体库</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($proof_url): ?>
                                    <a href="<?php echo esc_url($proof_url); ?>" target="_blank">
                                        <img src="<?php echo esc_url($proof_url); ?>" 
                                             style="max-width: 100px; max-height: 100px; height: auto; border: 2px solid #ddd; border-radius: 4px; cursor: pointer; object-fit: cover;"
                                             alt="付款凭证" />
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">无图片</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($proof_url); ?>" 
                                   class="button button-small" 
                                   target="_blank">
                                    🔍 查看原图
                                </a>
                                <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>" 
                                   class="button button-small button-primary">
                                    📝 编辑订单
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-left: 4px solid #00a0d2;">
                <h3>💡 使用说明</h3>
                <ul style="line-height: 1.8;">
                    <li><strong>凭证存储位置：</strong>付款凭证图片保存在独立目录，不占用媒体库空间
                        <ul style="margin-top: 5px; margin-left: 20px;">
                            <li>📁 新版本：<code>/wp-content/uploads/payment-proofs/年/月/</code></li>
                            <li>📚 旧数据：<code>/wp-content/uploads/</code>（媒体库）</li>
                        </ul>
                    </li>
                    <li><strong>订单状态流程：</strong>
                        <ol style="margin-top: 10px;">
                            <li>用户下单后为"待支付"（pending）</li>
                            <li>上传凭证后自动变为"处理中"（processing）</li>
                            <li>确认收款后手动更新为"已完成"（completed）</li>
                        </ol>
                    </li>
                    <li><strong>查看凭证：</strong>点击缩略图或"查看原图"按钮可以查看完整凭证</li>
                    <li><strong>订单管理：</strong>点击"编辑订单"可以进入订单详情页面，在右侧侧边栏可以看到付款凭证</li>
                    <li><strong>存储优势：</strong>
                        <ul style="margin-top: 5px; margin-left: 20px;">
                            <li>✅ 独立目录管理，不与产品图片混淆</li>
                            <li>✅ 按年月自动分类，便于归档和清理</li>
                            <li>✅ 不占用媒体库空间，提升后台性能</li>
                            <li>✅ 可添加 .htaccess 防止目录遍历</li>
                            <li>✅ 兼容旧版本数据（媒体库存储）</li>
                        </ul>
                    </li>
                    <li><strong>文件命名规则：</strong><code>order-{订单ID}-{随机ID}.jpg</code>，便于识别和追溯</li>
                </ul>
            </div>
        </div>
        
        <style>
            .myshop-payment-proof-box img {
                transition: transform 0.3s ease;
            }
            .myshop-payment-proof-box img:hover {
                transform: scale(1.05);
            }
        </style>
        <?php
    }
}

// 初始化
MyShop_Payment_Proof_Manager::init();
