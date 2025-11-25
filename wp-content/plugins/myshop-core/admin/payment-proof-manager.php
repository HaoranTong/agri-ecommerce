<?php
/**
 * 付款凭证管理页面
 */

class MyShop_Payment_Proof_Manager {

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu_page']);
        add_action('add_meta_boxes', [self::class, 'add_order_meta_box']);
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
        add_meta_box(
            'myshop_payment_proof',
            '💳 付款凭证',
            [self::class, 'render_order_meta_box'],
            'shop_order',
            'side',
            'high'
        );
    }

    /**
     * 渲染订单编辑页面的付款凭证元框
     */
    public static function render_order_meta_box($post) {
        $order_id = $post->ID;
        $proof_id = get_post_meta($order_id, '_myshop_payment_proof', true);
        $submitted_at = get_post_meta($order_id, '_myshop_payment_proof_submitted_at', true);

        if (!$proof_id) {
            echo '<p style="color: #999;">用户尚未上传付款凭证</p>';
            return;
        }

        $proof_url = wp_get_attachment_url($proof_id);
        $proof_image = wp_get_attachment_image($proof_id, 'medium', false, ['style' => 'max-width: 100%; height: auto; border: 2px solid #ddd; border-radius: 4px;']);

        ?>
        <div class="myshop-payment-proof-box">
            <p><strong>提交时间：</strong><br><?php echo esc_html($submitted_at ?: '未知'); ?></p>
            
            <div style="margin: 15px 0;">
                <a href="<?php echo esc_url($proof_url); ?>" target="_blank">
                    <?php echo $proof_image; ?>
                </a>
            </div>
            
            <p style="margin-top: 10px;">
                <a href="<?php echo esc_url($proof_url); ?>" class="button button-primary" target="_blank">
                    🔍 查看原图
                </a>
                <a href="<?php echo esc_url(admin_url('post.php?post=' . $proof_id . '&action=edit')); ?>" 
                   class="button" target="_blank">
                    📎 媒体详情
                </a>
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
        // 获取所有包含付款凭证的订单
        $args = [
            'limit' => -1,
            'meta_key' => '_myshop_payment_proof',
            'meta_compare' => 'EXISTS',
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        
        $orders = wc_get_orders($args);
        
        ?>
        <div class="wrap">
            <h1>💳 付款凭证管理</h1>
            <p>共有 <strong><?php echo count($orders); ?></strong> 个订单上传了付款凭证</p>
            
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
                            $proof_id = get_post_meta($order->get_id(), '_myshop_payment_proof', true);
                            $submitted_at = get_post_meta($order->get_id(), '_myshop_payment_proof_submitted_at', true);
                            $proof_url = wp_get_attachment_url($proof_id);
                            $proof_thumb = wp_get_attachment_image_url($proof_id, 'thumbnail');
                            
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
                            </td>
                            <td>
                                <?php if ($proof_thumb): ?>
                                    <a href="<?php echo esc_url($proof_url); ?>" target="_blank">
                                        <img src="<?php echo esc_url($proof_thumb); ?>" 
                                             style="max-width: 100px; height: auto; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;"
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
                    <li><strong>凭证存储位置：</strong>所有付款凭证图片保存在 WordPress 媒体库中（<code>/wp-content/uploads/</code>）</li>
                    <li><strong>订单状态流程：</strong>
                        <ol style="margin-top: 10px;">
                            <li>用户下单后为"待支付"（pending）</li>
                            <li>上传凭证后自动变为"处理中"（processing）</li>
                            <li>确认收款后手动更新为"已完成"（completed）</li>
                        </ol>
                    </li>
                    <li><strong>查看凭证：</strong>点击缩略图或"查看原图"按钮可以查看完整凭证</li>
                    <li><strong>订单管理：</strong>点击"编辑订单"可以进入订单详情页面，在右侧侧边栏可以看到付款凭证</li>
                    <li><strong>媒体管理：</strong>在 WordPress 后台 → 媒体库中可以找到所有上传的凭证图片</li>
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
