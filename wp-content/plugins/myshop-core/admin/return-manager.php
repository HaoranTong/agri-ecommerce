<?php
/**
 * 退货管理
 */

class MyShop_Return_Manager {
    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_myshop_return_action', [self::class, 'handle_action']);
    }

    public static function register_menu() {
        add_submenu_page(
            'woocommerce',
            '退货管理',
            '退货管理',
            'manage_woocommerce',
            'myshop-return-manager',
            [self::class, 'render_page']
        );
    }

    public static function handle_action() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('权限不足');
        }

        check_admin_referer('myshop_return_action');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $action = isset($_POST['return_action']) ? sanitize_key($_POST['return_action']) : '';
        $note = isset($_POST['admin_note']) ? sanitize_text_field($_POST['admin_note']) : '';

        if (!$order_id || !$action) {
            wp_redirect(admin_url('admin.php?page=myshop-return-manager'));
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_redirect(admin_url('admin.php?page=myshop-return-manager'));
            exit;
        }

        $now = current_time('mysql');
        $status_map = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'refund' => 'refunded'
        ];

        if (isset($status_map[$action])) {
            $order->update_meta_data('_myshop_return_status', $status_map[$action]);
            $order->update_meta_data('_myshop_return_updated_at', $now);
            if ($note) {
                $order->update_meta_data('_myshop_return_admin_note', $note);
            }

            if ($action === 'approve') {
                $order->add_order_note('退货申请已同意' . ($note ? '：' . $note : ''));
                if ($order->get_status() !== 'return-requested') {
                    $order->update_status('return-requested', '退货申请已同意');
                }

                $refund = Payment_Controller::refund_wechat_order($order, $note);
                if (is_wp_error($refund)) {
                    $order->add_order_note('自动退款失败：' . $refund->get_error_message());
                } else {
                    $refund_status = $refund['status'] ?? '';
                    if ($refund_status === 'SUCCESS') {
                        $order->update_meta_data('_myshop_wechat_refund_status', 'success');
                        $order->update_meta_data('_myshop_return_status', 'refunded');
                        if ($order->get_status() !== 'refunded') {
                            $order->update_status('refunded', '微信退款成功');
                        }
                        $order->add_order_note('自动退款成功');
                    } else {
                        $order->update_meta_data('_myshop_wechat_refund_status', strtolower($refund_status ?: 'processing'));
                        $order->add_order_note('自动退款已发起，状态：' . ($refund_status ?: 'PROCESSING'));
                    }
                }
                $order->save();
            } elseif ($action === 'reject') {
                $order->add_order_note('退货申请已拒绝' . ($note ? '：' . $note : ''));
                $prev_status = $order->get_meta('_myshop_return_prev_status', true) ?: 'completed';
                if ($order->get_status() !== $prev_status) {
                    $order->update_status($prev_status, '退货申请已拒绝，恢复订单状态');
                }
            } elseif ($action === 'refund') {
                if ($order->get_status() !== 'refunded') {
                    $order->update_status('refunded', '退货退款完成');
                }
                $order->add_order_note('退货退款已完成' . ($note ? '：' . $note : ''));
            }

            $order->save();
        }

        wp_redirect(admin_url('admin.php?page=myshop-return-manager'));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_myshop_return_status',
                    'compare' => 'IN',
                    'value' => ['requested', 'approved', 'rejected', 'refunded']
                ],
                [
                    'key' => '_myshop_return_requested_at',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        ?>
        <div class="wrap">
            <h1>退货管理</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>订单号</th>
                        <th>用户</th>
                        <th>状态</th>
                        <th>申请时间</th>
                        <th>原因/联系方式</th>
                        <th>图片</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($orders)) : ?>
                    <tr><td colspan="7">暂无退货申请</td></tr>
                <?php else : ?>
                    <?php foreach ($orders as $order) :
                        $order_id = $order->get_id();
                        $status = $order->get_meta('_myshop_return_status', true) ?: 'requested';
                        $requested_at = $order->get_meta('_myshop_return_requested_at', true);
                        $reason = $order->get_meta('_myshop_return_reason', true);
                        $contact = $order->get_meta('_myshop_return_contact', true);
                        $images = $order->get_meta('_myshop_return_images', true);
                        if (is_string($images)) {
                            $decoded = json_decode($images, true);
                            $images = is_array($decoded) ? $decoded : [];
                        }
                        $user = $order->get_user();
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a>
                        </td>
                        <td><?php echo esc_html($user ? $user->display_name : '游客'); ?></td>
                        <td><?php echo esc_html($status); ?></td>
                        <td><?php echo esc_html($requested_at ?: '-'); ?></td>
                        <td>
                            <?php echo esc_html($reason ?: '-'); ?><br>
                            <?php if ($contact) : ?>
                                <small>联系方式：<?php echo esc_html($contact); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($images) && is_array($images)) : ?>
                                <?php foreach ($images as $img) : ?>
                                    <a href="<?php echo esc_url($img); ?>" target="_blank">
                                        <img src="<?php echo esc_url($img); ?>" style="width: 40px; height: 40px; object-fit: cover; margin-right: 6px;" />
                                    </a>
                                <?php endforeach; ?>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('myshop_return_action'); ?>
                                <input type="hidden" name="action" value="myshop_return_action" />
                                <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>" />
                                <select name="return_action">
                                    <option value="approve">同意</option>
                                    <option value="reject">拒绝</option>
                                    <option value="refund">已退款</option>
                                </select>
                                <input type="text" name="admin_note" placeholder="备注" style="width: 120px;" />
                                <button type="submit" class="button">提交</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

MyShop_Return_Manager::init();
