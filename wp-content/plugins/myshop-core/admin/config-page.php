<?php
/**
 * MyShop 配置管理页面
 * 统一管理小程序和PC端的轮播图、二维码等配置
 */

// 注册管理菜单
add_action('admin_menu', 'myshop_register_config_menu');

function myshop_register_config_menu() {
    add_menu_page(
        'MyShop 配置',          // 页面标题
        'MyShop 配置',          // 菜单标题
        'manage_options',       // 权限（管理员）
        'myshop-config',        // 菜单 slug
        'myshop_config_page',   // 回调函数
        'dashicons-admin-settings', // 图标
        58                      // 菜单位置
    );
}

function myshop_config_page() {
    // 加载媒体库
    wp_enqueue_media();
    
    // 保存配置
    if (isset($_POST['myshop_save_config']) && check_admin_referer('myshop_config_save')) {
        $config = get_option('myshop_public_config', []);
        $home_slider = [];
        
        // 处理轮播图数据
        if (!empty($_POST['slider_images'])) {
            foreach ($_POST['slider_images'] as $index => $img) {
                if (!empty($img)) {
                    $home_slider[] = [
                        'img' => sanitize_text_field($img),
                        'link' => sanitize_text_field($_POST['slider_links'][$index] ?? ''),
                        'title' => sanitize_text_field($_POST['slider_titles'][$index] ?? ''),
                        'order' => (int)($index + 1)
                    ];
                }
            }
        }
        
        // 保存其他配置
        $config['home_slider'] = $home_slider;
        $config['payment_qr_url'] = sanitize_text_field($_POST['payment_qr_url'] ?? '');
        $config['customer_service_qr'] = sanitize_text_field($_POST['customer_service_qr'] ?? '');
        $config['last_updated_at'] = current_time('c');
        
        update_option('myshop_public_config', $config);
        
        echo '<div class="notice notice-success is-dismissible"><p><strong>✓ 配置已保存！</strong></p></div>';
    }
    
    // 获取当前配置
    $config = get_option('myshop_public_config', []);
    $home_slider = $config['home_slider'] ?? [];
    $payment_qr_url = $config['payment_qr_url'] ?? '';
    $customer_service_qr = $config['customer_service_qr'] ?? '';
    
    // 如果轮播图为空，添加一个空行
    if (empty($home_slider)) {
        $home_slider = [['img' => '', 'link' => '', 'title' => '']];
    }
    ?>
    
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-admin-settings" style="font-size: 28px; margin-right: 8px;"></span>
            MyShop 配置管理
        </h1>
        
        <p class="description" style="margin-bottom: 20px;">
            管理小程序和PC端的轮播图、二维码等公共配置。所有修改会实时同步到小程序和PC端。
        </p>
        
        <form method="post" action="">
            <?php wp_nonce_field('myshop_config_save'); ?>
            
            <!-- 轮播图配置 -->
            <div style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">
                    <span class="dashicons dashicons-images-alt2" style="color: #2271b1;"></span>
                    轮播图配置
                </h2>
                <p class="description">
                    📱 小程序首页轮播图 | 💻 PC端可通过 API 获取 | 推荐尺寸：750×400px（比例 15:8）
                </p>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 60px;">排序</th>
                            <th style="width: 150px;">图片预览</th>
                            <th>图片 URL</th>
                            <th>标题（可选）</th>
                            <th>跳转链接</th>
                            <th style="width: 100px;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="slider-container">
                        <?php foreach ($home_slider as $index => $slide): ?>
                        <tr class="slider-row">
                            <td style="text-align: center; font-weight: bold; color: #666;">
                                <?php echo $index + 1; ?>
                            </td>
                            <td>
                                <div class="slider-preview" style="width: 120px; height: 64px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border-radius: 4px; overflow: hidden;">
                                    <?php if (!empty($slide['img'])): ?>
                                        <img src="<?php echo esc_attr($slide['img']); ?>" style="max-width: 100%; max-height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 12px;">无图片</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <input type="text" 
                                       name="slider_images[]" 
                                       value="<?php echo esc_attr($slide['img'] ?? ''); ?>" 
                                       class="regular-text slider-img-input" 
                                       placeholder="https://...">
                                <button type="button" class="button upload-image-btn" style="margin-top: 5px;">
                                    <span class="dashicons dashicons-upload" style="margin-top: 4px;"></span> 上传图片
                                </button>
                            </td>
                            <td>
                                <input type="text" 
                                       name="slider_titles[]" 
                                       value="<?php echo esc_attr($slide['title'] ?? ''); ?>" 
                                       class="regular-text" 
                                       placeholder="轮播图标题（可选）">
                            </td>
                            <td>
                                <input type="text" 
                                       name="slider_links[]" 
                                       value="<?php echo esc_attr($slide['link'] ?? ''); ?>" 
                                       class="regular-text" 
                                       placeholder="/pages/product/detail?id=123">
                                <p class="description" style="margin: 5px 0 0;">
                                    示例：<code>/pages/product/detail?id=8422</code>
                                </p>
                            </td>
                            <td>
                                <button type="button" class="button button-link-delete remove-slider" style="color: #b32d2e;">
                                    <span class="dashicons dashicons-trash"></span> 删除
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <button type="button" class="button" id="add-slider" style="margin-top: 10px;">
                    <span class="dashicons dashicons-plus-alt" style="margin-top: 4px;"></span> 添加轮播图
                </button>
            </div>
            
            <!-- 二维码配置 -->
            <div style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">
                    <span class="dashicons dashicons-admin-users" style="color: #2271b1;"></span>
                    二维码配置
                </h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="payment_qr_url">付款码 URL</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="payment_qr_url" 
                                   name="payment_qr_url" 
                                   value="<?php echo esc_attr($payment_qr_url); ?>" 
                                   class="regular-text" 
                                   placeholder="https://...">
                            <p class="description">小程序订单支付时显示的收款码图片</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="customer_service_qr">客服二维码 URL</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="customer_service_qr" 
                                   name="customer_service_qr" 
                                   value="<?php echo esc_attr($customer_service_qr); ?>" 
                                   class="regular-text" 
                                   placeholder="https://...">
                            <p class="description">小程序客服入口的企业微信二维码</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- API 预览 -->
            <div style="background: #f6f7f7; padding: 15px; margin-bottom: 20px; border-left: 4px solid #2271b1;">
                <h3 style="margin-top: 0;">📡 API 接口预览</h3>
                <p>
                    <strong>接口地址：</strong>
                    <code style="background: #fff; padding: 4px 8px; border-radius: 3px;">
                        <?php echo home_url('/wp-json/myshop/v1/config/public'); ?>
                    </code>
                    <button type="button" class="button button-small" onclick="window.open('<?php echo home_url('/wp-json/myshop/v1/config/public'); ?>', '_blank')">
                        测试接口
                    </button>
                </p>
                <p class="description">
                    小程序通过此接口获取轮播图和二维码配置。保存后立即生效，无需编译。
                </p>
            </div>
            
            <?php submit_button('💾 保存所有配置', 'primary large', 'myshop_save_config'); ?>
        </form>
    </div>
    
    <style>
        .slider-row:hover {
            background-color: #f6f7f7;
        }
        .upload-image-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        #add-slider {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .remove-slider {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // 图片预览更新
        $(document).on('input', '.slider-img-input', function() {
            var input = $(this);
            var preview = input.closest('tr').find('.slider-preview');
            var url = input.val();
            
            if (url) {
                preview.html('<img src="' + url + '" style="max-width: 100%; max-height: 100%; object-fit: cover;">');
            } else {
                preview.html('<span style="color: #999; font-size: 12px;">无图片</span>');
            }
        });
        
        // 添加轮播图
        $('#add-slider').on('click', function() {
            var index = $('#slider-container tr').length + 1;
            var row = `
                <tr class="slider-row">
                    <td style="text-align: center; font-weight: bold; color: #666;">${index}</td>
                    <td>
                        <div class="slider-preview" style="width: 120px; height: 64px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border-radius: 4px;">
                            <span style="color: #999; font-size: 12px;">无图片</span>
                        </div>
                    </td>
                    <td>
                        <input type="text" name="slider_images[]" class="regular-text slider-img-input" placeholder="https://...">
                        <button type="button" class="button upload-image-btn" style="margin-top: 5px;">
                            <span class="dashicons dashicons-upload" style="margin-top: 4px;"></span> 上传图片
                        </button>
                    </td>
                    <td>
                        <input type="text" name="slider_titles[]" class="regular-text" placeholder="轮播图标题（可选）">
                    </td>
                    <td>
                        <input type="text" name="slider_links[]" class="regular-text" placeholder="/pages/product/detail?id=123">
                        <p class="description" style="margin: 5px 0 0;">示例：<code>/pages/product/detail?id=8422</code></p>
                    </td>
                    <td>
                        <button type="button" class="button button-link-delete remove-slider" style="color: #b32d2e;">
                            <span class="dashicons dashicons-trash"></span> 删除
                        </button>
                    </td>
                </tr>
            `;
            $('#slider-container').append(row);
            
            // 更新排序号
            updateSliderOrder();
        });
        
        // 删除轮播图
        $(document).on('click', '.remove-slider', function() {
            if ($('#slider-container tr').length > 1) {
                $(this).closest('tr').remove();
                updateSliderOrder();
            } else {
                alert('至少保留一行！');
            }
        });
        
        // 更新排序号
        function updateSliderOrder() {
            $('#slider-container tr').each(function(index) {
                $(this).find('td:first').text(index + 1);
            });
        }
        
        // 上传图片（集成 WordPress 媒体库）
        $(document).on('click', '.upload-image-btn', function(e) {
            e.preventDefault();
            var button = $(this);
            var input = button.prev('input.slider-img-input');
            var preview = button.closest('tr').find('.slider-preview');
            
            var mediaUploader = wp.media({
                title: '选择轮播图',
                button: {
                    text: '使用此图片'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                input.val(attachment.url);
                preview.html('<img src="' + attachment.url + '" style="max-width: 100%; max-height: 100%; object-fit: cover;">');
            });
            
            mediaUploader.open();
        });
    });
    </script>
    <?php
}
