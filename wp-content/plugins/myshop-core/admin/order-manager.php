<?php
/**
 * 订单管理页面 - 添加快递单号功能
 */

class MyShop_Order_Manager {

    private static $tracking_save_lock = [];
    private static $status_enforce_lock = [];

    public static function init() {
        // 在订单列表添加快递单号列
        add_filter('manage_edit-shop_order_columns', [self::class, 'add_tracking_column']);
        add_filter('woocommerce_shop_order_list_table_columns', [self::class, 'add_tracking_column']); // HPOS
        
        add_action('manage_shop_order_posts_custom_column', [self::class, 'render_tracking_column'], 10, 2);
        add_action('woocommerce_shop_order_list_table_custom_column', [self::class, 'render_tracking_column_hpos'], 10, 2); // HPOS
        
        // 添加订单编辑页面的快递信息meta box
        add_action('add_meta_boxes', [self::class, 'add_tracking_meta_box']);
        
        // 保存快递单号
        add_action('save_post_shop_order', [self::class, 'save_tracking_number'], 10, 1);
        add_action('woocommerce_update_order', [self::class, 'save_tracking_number_hpos'], 10, 1); // HPOS

        // 订单保存后，确保已录入快递的订单保持“已发货”状态
        add_action('save_post_shop_order', [self::class, 'enforce_shipped_status'], 100, 1);
        add_action('woocommerce_update_order', [self::class, 'enforce_shipped_status_hpos'], 100, 1); // HPOS
        
        // AJAX处理快递单号更新
        add_action('wp_ajax_myshop_update_tracking', [self::class, 'ajax_update_tracking']);
        
        // 添加后台脚本和样式
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }
    
    /**
     * 在订单列表添加快递单号列
     */
    public static function add_tracking_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order_actions' || $key === 'wc_actions') {
                $new_columns['tracking_number'] = '📦 快递单号';
            }
            $new_columns[$key] = $label;
        }
        return $new_columns;
    }
    
    /**
     * 渲染订单列表的快递单号列（传统版本）
     */
    public static function render_tracking_column($column, $post_id) {
        if ($column !== 'tracking_number') {
            return;
        }
        
        $tracking_number = get_post_meta($post_id, '_myshop_tracking_number', true);
        $tracking_company = get_post_meta($post_id, '_myshop_tracking_company', true);
        $shipped_at = get_post_meta($post_id, '_myshop_shipped_at', true);
        
        self::render_tracking_cell($post_id, $tracking_number, $tracking_company, $shipped_at);
    }
    
    /**
     * 渲染订单列表的快递单号列（HPOS版本）
     */
    public static function render_tracking_column_hpos($column, $order) {
        if ($column !== 'tracking_number') {
            return;
        }
        
        if (!is_a($order, 'WC_Order')) {
            return;
        }
        
        $post_id = $order->get_id();
        $tracking_number = get_post_meta($post_id, '_myshop_tracking_number', true);
        $tracking_company = get_post_meta($post_id, '_myshop_tracking_company', true);
        $shipped_at = get_post_meta($post_id, '_myshop_shipped_at', true);
        
        self::render_tracking_cell($post_id, $tracking_number, $tracking_company, $shipped_at);
    }
    
    /**
     * 渲染快递单号单元格（共用）
     */
    private static function render_tracking_cell($order_id, $tracking_number, $tracking_company, $shipped_at) {
        if (empty($tracking_number)) {
            ?>
            <div class="myshop-tracking-cell" data-order-id="<?php echo esc_attr($order_id); ?>">
                <button type="button" class="button button-small myshop-add-tracking" 
                        data-order-id="<?php echo esc_attr($order_id); ?>">
                    ➕ 添加
                </button>
            </div>
            <?php
        } else {
            ?>
            <div class="myshop-tracking-cell" data-order-id="<?php echo esc_attr($order_id); ?>">
                <div style="margin-bottom: 5px;">
                    <strong style="color: #2271b1;"><?php echo esc_html($tracking_number); ?></strong>
                </div>
                <?php if ($tracking_company): ?>
                    <div style="font-size: 11px; color: #666; margin-bottom: 3px;">
                        <?php echo esc_html($tracking_company); ?>
                    </div>
                <?php endif; ?>
                <?php if ($shipped_at): ?>
                    <div style="font-size: 11px; color: #999;">
                        <?php echo date('Y-m-d H:i', strtotime($shipped_at)); ?>
                    </div>
                <?php endif; ?>
                <button type="button" class="button button-small myshop-edit-tracking" 
                        data-order-id="<?php echo esc_attr($order_id); ?>"
                        style="margin-top: 5px;">
                    ✏️ 编辑
                </button>
            </div>
            <?php
        }
    }
    
    /**
     * 添加快递信息Meta Box到订单编辑页面
     */
    public static function add_tracking_meta_box() {
        add_meta_box(
            'myshop_tracking_info',
            '📦 快递信息',
            [self::class, 'render_tracking_meta_box'],
            ['shop_order', 'woocommerce_page_wc-orders'],
            'side',
            'high'
        );
    }
    
    /**
     * 渲染快递信息Meta Box
     */
    public static function render_tracking_meta_box($post_or_order) {
        // 兼容传统和HPOS
        $order_id = is_a($post_or_order, 'WC_Order') ? $post_or_order->get_id() : $post_or_order->ID;
        
        $tracking_number = get_post_meta($order_id, '_myshop_tracking_number', true);
        $tracking_company = get_post_meta($order_id, '_myshop_tracking_company', true);
        $shipped_at = get_post_meta($order_id, '_myshop_shipped_at', true);
        
        wp_nonce_field('myshop_tracking_meta_box', 'myshop_tracking_nonce');
        ?>
        <div class="myshop-tracking-meta-box">
            <p>
                <label for="myshop_tracking_company"><strong>快递公司：</strong></label><br>
                <select name="myshop_tracking_company" id="myshop_tracking_company" style="width: 100%;">
                    <option value="">选择快递公司</option>
                    <option value="顺丰速运" <?php selected($tracking_company, '顺丰速运'); ?>>顺丰速运</option>
                    <option value="中通快递" <?php selected($tracking_company, '中通快递'); ?>>中通快递</option>
                    <option value="圆通速递" <?php selected($tracking_company, '圆通速递'); ?>>圆通速递</option>
                    <option value="申通快递" <?php selected($tracking_company, '申通快递'); ?>>申通快递</option>
                    <option value="韵达快递" <?php selected($tracking_company, '韵达快递'); ?>>韵达快递</option>
                    <option value="邮政EMS" <?php selected($tracking_company, '邮政EMS'); ?>>邮政EMS</option>
                    <option value="京东物流" <?php selected($tracking_company, '京东物流'); ?>>京东物流</option>
                    <option value="德邦快递" <?php selected($tracking_company, '德邦快递'); ?>>德邦快递</option>
                    <option value="极兔速递" <?php selected($tracking_company, '极兔速递'); ?>>极兔速递</option>
                    <option value="百世快递" <?php selected($tracking_company, '百世快递'); ?>>百世快递</option>
                    <option value="其他" <?php selected($tracking_company, '其他'); ?>>其他</option>
                </select>
            </p>
            
            <p>
                <label for="myshop_tracking_number"><strong>快递单号：</strong></label><br>
                <input type="text" name="myshop_tracking_number" id="myshop_tracking_number" 
                       value="<?php echo esc_attr($tracking_number); ?>" 
                       placeholder="请输入快递单号"
                       style="width: 100%;">
            </p>
            
            <p>
                <label for="myshop_shipped_at"><strong>发货时间：</strong></label><br>
                <input type="datetime-local" name="myshop_shipped_at" id="myshop_shipped_at" 
                       value="<?php echo $shipped_at ? date('Y-m-d\TH:i', strtotime($shipped_at)) : ''; ?>"
                       style="width: 100%;">
                <small style="color: #666;">留空则使用当前时间</small>
            </p>
            
            <?php if ($tracking_number): ?>
                <div style="padding: 10px; background: #f0f6fc; border-left: 4px solid #2271b1; margin-top: 10px;">
                    <p style="margin: 0; font-size: 12px; color: #135e96;">
                        <strong>✓ 已录入快递信息</strong><br>
                        发货时间: <?php echo $shipped_at ? date('Y-m-d H:i', strtotime($shipped_at)) : '未记录'; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * 保存快递单号（传统版本）
     */
    public static function save_tracking_number($post_id) {
        // 检查nonce
        if (!isset($_POST['myshop_tracking_nonce']) || 
            !wp_verify_nonce($_POST['myshop_tracking_nonce'], 'myshop_tracking_meta_box')) {
            return;
        }
        
        // 检查权限
        if (!current_user_can('edit_shop_order', $post_id)) {
            return;
        }

        if (!empty(self::$tracking_save_lock[$post_id])) {
            return;
        }
        self::$tracking_save_lock[$post_id] = true;
        
        self::save_tracking_data($post_id);

        unset(self::$tracking_save_lock[$post_id]);
    }
    
    /**
     * 保存快递单号（HPOS版本）
     */
    public static function save_tracking_number_hpos($order_id) {
        if (!isset($_POST['myshop_tracking_nonce']) || 
            !wp_verify_nonce($_POST['myshop_tracking_nonce'], 'myshop_tracking_meta_box')) {
            return;
        }
        
        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        if (!empty(self::$tracking_save_lock[$order_id])) {
            return;
        }
        self::$tracking_save_lock[$order_id] = true;
        
        self::save_tracking_data($order_id);

        unset(self::$tracking_save_lock[$order_id]);
    }
    
    /**
     * 保存快递数据（共用）
     */
    private static function save_tracking_data($order_id) {
        $tracking_number = isset($_POST['myshop_tracking_number']) ? sanitize_text_field($_POST['myshop_tracking_number']) : '';
        $tracking_company = isset($_POST['myshop_tracking_company']) ? sanitize_text_field($_POST['myshop_tracking_company']) : '';
        $shipped_at = isset($_POST['myshop_shipped_at']) ? sanitize_text_field($_POST['myshop_shipped_at']) : '';
        
        // 保存快递公司
        if (!empty($tracking_company)) {
            update_post_meta($order_id, '_myshop_tracking_company', $tracking_company);
        } else {
            delete_post_meta($order_id, '_myshop_tracking_company');
        }
        
        // 保存快递单号
        if (!empty($tracking_number)) {
            update_post_meta($order_id, '_myshop_tracking_number', $tracking_number);
            delete_post_meta($order_id, '_myshop_wechat_shipping_synced_key');
            delete_post_meta($order_id, '_myshop_wechat_shipping_last_error');
            
            // 保存发货时间
            if (!empty($shipped_at)) {
                // 转换为MySQL datetime格式
                $shipped_datetime = date('Y-m-d H:i:s', strtotime($shipped_at));
                update_post_meta($order_id, '_myshop_shipped_at', $shipped_datetime);
            } else {
                // 如果没有指定时间，使用当前时间
                update_post_meta($order_id, '_myshop_shipped_at', current_time('mysql'));
            }
            
            // 自动更新订单状态为"已发货"（使用 on-hold 显示）
            $order = wc_get_order($order_id);
            if ($order && in_array($order->get_status(), ['processing', 'pending'], true)) {
                $order->update_status('on-hold', '订单已发货，快递单号: ' . $tracking_number);
            }
            
            // 添加订单备注
            $order->add_order_note(sprintf(
                '快递信息已更新：%s %s',
                $tracking_company,
                $tracking_number
            ));
        } else {
            delete_post_meta($order_id, '_myshop_tracking_number');
            delete_post_meta($order_id, '_myshop_shipped_at');
        }
    }

    /**
     * 订单保存后，强制保持发货状态（传统）
     */
    public static function enforce_shipped_status($post_id) {
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        self::maybe_force_shipped_status($order, $post_id);
    }

    /**
     * 订单保存后，强制保持发货状态（HPOS）
     */
    public static function enforce_shipped_status_hpos($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        self::maybe_force_shipped_status($order, $order_id);
    }

    /**
     * 如果已录入快递信息且当前为处理中/待付款，则保持为已发货(on-hold)
     */
    private static function maybe_force_shipped_status($order, $order_id) {
        if (!($order instanceof WC_Order)) {
            return;
        }

        if (!empty(self::$status_enforce_lock[$order_id])) {
            return;
        }

        $tracking_number = get_post_meta($order_id, '_myshop_tracking_number', true);
        $tracking_company = get_post_meta($order_id, '_myshop_tracking_company', true);
        if (empty($tracking_number) || empty($tracking_company)) {
            return;
        }

        if (in_array($order->get_status(), ['processing', 'pending'], true)) {
            self::$status_enforce_lock[$order_id] = true;
            $order->update_status('on-hold', '订单已发货（自动保持发货状态）');
            unset(self::$status_enforce_lock[$order_id]);
        }
    }
    
    /**
     * AJAX处理快递单号更新
     */
    public static function ajax_update_tracking() {
        check_ajax_referer('myshop_tracking', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => '权限不足']);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $tracking_number = isset($_POST['tracking_number']) ? sanitize_text_field($_POST['tracking_number']) : '';
        $tracking_company = isset($_POST['tracking_company']) ? sanitize_text_field($_POST['tracking_company']) : '';
        
        if (!$order_id) {
            wp_send_json_error(['message' => '订单ID无效']);
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => '订单不存在']);
        }
        
        if (empty($tracking_number)) {
            wp_send_json_error(['message' => '请输入快递单号']);
        }

        if (!empty(self::$tracking_save_lock[$order_id])) {
            wp_send_json_error(['message' => '订单正在保存，请稍后再试']);
        }
        self::$tracking_save_lock[$order_id] = true;
        
        // 保存快递信息
        update_post_meta($order_id, '_myshop_tracking_number', $tracking_number);
        update_post_meta($order_id, '_myshop_tracking_company', $tracking_company);
        update_post_meta($order_id, '_myshop_shipped_at', current_time('mysql'));
        delete_post_meta($order_id, '_myshop_wechat_shipping_synced_key');
        delete_post_meta($order_id, '_myshop_wechat_shipping_last_error');
        
        // 更新订单状态为"已发货"（使用 on-hold 显示）
        if (in_array($order->get_status(), ['processing', 'pending'], true)) {
            $order->update_status('on-hold', '订单已发货');
        }
        
        // 添加订单备注
        $order->add_order_note(sprintf(
            '快递信息已添加：%s %s',
            $tracking_company,
            $tracking_number
        ));
        
        unset(self::$tracking_save_lock[$order_id]);

        wp_send_json_success([
            'message' => '快递单号保存成功',
            'tracking_number' => $tracking_number,
            'tracking_company' => $tracking_company,
            'shipped_at' => current_time('mysql')
        ]);
    }
    
    /**
     * 加载后台脚本和样式
     */
    public static function enqueue_scripts($hook) {
        // 只在订单列表页面和编辑页面加载
        if (!in_array($hook, ['edit.php', 'post.php', 'woocommerce_page_wc-orders'])) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'shop_order') {
            return;
        }
        
        ?>
        <style>
            .myshop-tracking-cell {
                text-align: center;
            }
            .myshop-tracking-cell button {
                font-size: 11px;
                padding: 3px 8px;
                height: auto;
                line-height: 1.4;
            }
            #myshop-tracking-modal {
                display: none;
                position: fixed;
                z-index: 100000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
            }
            #myshop-tracking-modal .modal-content {
                background-color: #fefefe;
                margin: 10% auto;
                padding: 30px;
                border: 1px solid #888;
                width: 500px;
                max-width: 90%;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            }
            #myshop-tracking-modal .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #e5e5e5;
            }
            #myshop-tracking-modal .modal-header h2 {
                margin: 0;
                font-size: 20px;
            }
            #myshop-tracking-modal .close {
                color: #aaa;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
                background: none;
                border: none;
                padding: 0;
                width: 30px;
                height: 30px;
                line-height: 1;
            }
            #myshop-tracking-modal .close:hover {
                color: #000;
            }
            #myshop-tracking-modal .form-group {
                margin-bottom: 20px;
            }
            #myshop-tracking-modal label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #333;
            }
            #myshop-tracking-modal input[type="text"],
            #myshop-tracking-modal select {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            #myshop-tracking-modal .button-group {
                display: flex;
                gap: 10px;
                margin-top: 25px;
            }
            #myshop-tracking-modal .button-group button {
                flex: 1;
                padding: 10px 20px;
                font-size: 14px;
                border-radius: 4px;
                cursor: pointer;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // 创建模态框HTML
            if ($('#myshop-tracking-modal').length === 0) {
                $('body').append(`
                    <div id="myshop-tracking-modal">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2>📦 添加快递单号</h2>
                                <button class="close">&times;</button>
                            </div>
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>快递公司：</label>
                                    <select id="modal-tracking-company">
                                        <option value="">选择快递公司</option>
                                        <option value="顺丰速运">顺丰速运</option>
                                        <option value="中通快递">中通快递</option>
                                        <option value="圆通速递">圆通速递</option>
                                        <option value="申通快递">申通快递</option>
                                        <option value="韵达快递">韵达快递</option>
                                        <option value="邮政EMS">邮政EMS</option>
                                        <option value="京东物流">京东物流</option>
                                        <option value="德邦快递">德邦快递</option>
                                        <option value="极兔速递">极兔速递</option>
                                        <option value="百世快递">百世快递</option>
                                        <option value="其他">其他</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>快递单号：</label>
                                    <input type="text" id="modal-tracking-number" placeholder="请输入快递单号">
                                </div>
                                <div class="button-group">
                                    <button type="button" class="button button-secondary" id="modal-cancel">取消</button>
                                    <button type="button" class="button button-primary" id="modal-save">保存</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            }
            
            var currentOrderId = 0;
            
            // 打开模态框
            $(document).on('click', '.myshop-add-tracking, .myshop-edit-tracking', function() {
                currentOrderId = $(this).data('order-id');
                
                // 如果是编辑，加载现有数据
                if ($(this).hasClass('myshop-edit-tracking')) {
                    var cell = $(this).closest('.myshop-tracking-cell');
                    var trackingNumber = cell.find('strong').text();
                    var trackingCompany = cell.find('div:eq(1)').text().trim();
                    
                    $('#modal-tracking-number').val(trackingNumber);
                    $('#modal-tracking-company').val(trackingCompany);
                    $('#myshop-tracking-modal .modal-header h2').text('📦 编辑快递单号');
                } else {
                    $('#modal-tracking-number').val('');
                    $('#modal-tracking-company').val('');
                    $('#myshop-tracking-modal .modal-header h2').text('📦 添加快递单号');
                }
                
                $('#myshop-tracking-modal').fadeIn(200);
            });
            
            // 关闭模态框
            $(document).on('click', '#myshop-tracking-modal .close, #modal-cancel', function() {
                $('#myshop-tracking-modal').fadeOut(200);
            });
            
            // 点击背景关闭
            $(document).on('click', '#myshop-tracking-modal', function(e) {
                if (e.target.id === 'myshop-tracking-modal') {
                    $(this).fadeOut(200);
                }
            });
            
            // 保存快递单号
            $(document).on('click', '#modal-save', function() {
                var trackingNumber = $('#modal-tracking-number').val().trim();
                var trackingCompany = $('#modal-tracking-company').val();
                
                if (!trackingNumber) {
                    alert('请输入快递单号');
                    return;
                }
                
                var $button = $(this);
                $button.prop('disabled', true).text('保存中...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'myshop_update_tracking',
                        nonce: '<?php echo wp_create_nonce('myshop_tracking'); ?>',
                        order_id: currentOrderId,
                        tracking_number: trackingNumber,
                        tracking_company: trackingCompany
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#myshop-tracking-modal').fadeOut(200);
                            location.reload(); // 刷新页面显示新数据
                        } else {
                            alert(response.data.message || '保存失败');
                        }
                    },
                    error: function() {
                        alert('网络错误，请重试');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('保存');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

// 初始化
MyShop_Order_Manager::init();
