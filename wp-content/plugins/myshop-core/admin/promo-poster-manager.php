<?php

class MyShop_Promo_Poster_Manager {
    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_myshop_save_promo_posters', [self::class, 'handle_save_posters']);
    }

    public static function register_menu() {
        add_menu_page(
            '推广海报',
            '📣 推广海报',
            'manage_options',
            'myshop-promo-posters',
            [self::class, 'render_posters_page'],
            'dashicons-format-image',
            58
        );
    }

    public static function render_posters_page() {
        if (isset($_GET['saved']) && $_GET['saved'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>✓ 海报配置已保存</p></div>';
        }

        $posters = get_option('myshop_promo_posters', []);
        $qr_settings = get_option('myshop_referral_qr_settings', []);
        if (!is_array($qr_settings)) {
            $qr_settings = [];
        }
        $fixed_qr_url = esc_url($qr_settings['fixed_qr_url'] ?? '');
        $logo_url = esc_url($qr_settings['logo_url'] ?? '');
        if (!is_array($posters)) {
            $posters = [];
        }
        ?>
        <div class="wrap">
            <h1>📣 推广海报配置</h1>
            <p class="description">配置推广海报素材、落地页与二维码位置，支持多套模板。到期后自动失效。</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('myshop_save_promo_posters'); ?>
                <input type="hidden" name="action" value="myshop_save_promo_posters" />

                <table class="widefat striped" style="margin-top: 16px;">
                    <thead>
                    <tr>
                        <th>模板编码</th>
                        <th>标题</th>
                        <th>海报图 URL</th>
                        <th>小程序码 URL（可选）</th>
                        <th>落地页路径</th>
                        <th>场景</th>
                        <th>二维码大小</th>
                        <th>二维码位置 (x,y)</th>
                        <th>有效期</th>
                        <th>分享文案</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($posters)) : ?>
                        <tr><td colspan="10" style="text-align:center;color:#999;">暂无海报配置</td></tr>
                    <?php else: ?>
                        <?php foreach ($posters as $index => $poster) : ?>
                            <tr>
                                <td><input type="text" name="posters[<?php echo esc_attr($index); ?>][template_code]" value="<?php echo esc_attr($poster['template_code'] ?? ''); ?>" /></td>
                                <td><input type="text" name="posters[<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr($poster['title'] ?? ''); ?>" /></td>
                                <td><input type="text" style="width: 220px;" name="posters[<?php echo esc_attr($index); ?>][image_url]" value="<?php echo esc_attr($poster['image_url'] ?? ''); ?>" /></td>
                                <td><input type="text" style="width: 220px;" name="posters[<?php echo esc_attr($index); ?>][mini_program_qr]" value="<?php echo esc_attr($poster['mini_program_qr'] ?? ''); ?>" /></td>
                                <td><input type="text" style="width: 180px;" name="posters[<?php echo esc_attr($index); ?>][landing_page]" value="<?php echo esc_attr($poster['landing_page'] ?? ($poster['mini_program_path'] ?? '')); ?>" /></td>
                                <td><input type="text" name="posters[<?php echo esc_attr($index); ?>][scene]" value="<?php echo esc_attr($poster['scene'] ?? ''); ?>" /></td>
                                <td><input type="number" name="posters[<?php echo esc_attr($index); ?>][qr_size]" value="<?php echo esc_attr($poster['qr_size'] ?? ''); ?>" style="width:80px;" /></td>
                                <td>
                                    <input type="number" name="posters[<?php echo esc_attr($index); ?>][qr_x]" value="<?php echo esc_attr($poster['qr_x'] ?? ''); ?>" style="width:70px;" />,
                                    <input type="number" name="posters[<?php echo esc_attr($index); ?>][qr_y]" value="<?php echo esc_attr($poster['qr_y'] ?? ''); ?>" style="width:70px;" />
                                </td>
                                <td><input type="text" name="posters[<?php echo esc_attr($index); ?>][valid_until]" value="<?php echo esc_attr($poster['valid_until'] ?? ''); ?>" /></td>
                                <td><input type="text" style="width: 200px;" name="posters[<?php echo esc_attr($index); ?>][share_text]" value="<?php echo esc_attr($poster['share_text'] ?? ''); ?>" /></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr>
                        <td><input type="text" name="posters[new][template_code]" placeholder="例如 invite-2026" /></td>
                        <td><input type="text" name="posters[new][title]" placeholder="海报标题" /></td>
                        <td><input type="text" style="width: 220px;" name="posters[new][image_url]" placeholder="海报图片URL" /></td>
                        <td><input type="text" style="width: 220px;" name="posters[new][mini_program_qr]" placeholder="可选：小程序码URL" /></td>
                        <td><input type="text" style="width: 180px;" name="posters[new][landing_page]" placeholder="pages/auth/login" /></td>
                        <td><input type="text" name="posters[new][scene]" placeholder="invite/promo" /></td>
                        <td><input type="number" name="posters[new][qr_size]" placeholder="220" style="width:80px;" /></td>
                        <td>
                            <input type="number" name="posters[new][qr_x]" placeholder="x" style="width:70px;" />,
                            <input type="number" name="posters[new][qr_y]" placeholder="y" style="width:70px;" />
                        </td>
                        <td><input type="text" name="posters[new][valid_until]" placeholder="2026-12-31" /></td>
                        <td><input type="text" style="width: 200px;" name="posters[new][share_text]" placeholder="分享文案" /></td>
                    </tr>
                    </tbody>
                </table>

                <h2 style="margin-top:24px;">推广二维码配置</h2>
                <p class="description">用于“推广二维码”入口。若填写统一二维码图片，则直接使用该图片；如留空，则系统自动生成用户专属二维码并在中心叠加店铺 Logo。</p>
                <table class="widefat striped" style="margin-top: 12px;">
                    <tbody>
                        <tr>
                            <th style="width: 220px;">统一二维码图片 URL</th>
                            <td><input type="text" style="width: 420px;" name="referral_qr[fixed_qr_url]" value="<?php echo $fixed_qr_url; ?>" placeholder="可选：统一二维码图片 URL" /></td>
                        </tr>
                        <tr>
                            <th>二维码中心 Logo URL</th>
                            <td><input type="text" style="width: 420px;" name="referral_qr[logo_url]" value="<?php echo $logo_url; ?>" placeholder="可选：店铺 Logo 图片 URL" /></td>
                        </tr>
                    </tbody>
                </table>

                <p style="margin-top:16px;">
                    <button type="submit" class="button button-primary">保存配置</button>
                </p>
            </form>
        </div>
        <?php
    }

    public static function handle_save_posters() {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'myshop_save_promo_posters')) {
            wp_die('安全验证失败');
        }

        $input = $_POST['posters'] ?? [];
        $saved = [];
        foreach ($input as $poster) {
            if (!is_array($poster)) {
                continue;
            }
            $template_code = sanitize_text_field($poster['template_code'] ?? '');
            $title = sanitize_text_field($poster['title'] ?? '');
            $image_url = esc_url_raw($poster['image_url'] ?? '');
            $mini_program_qr = esc_url_raw($poster['mini_program_qr'] ?? '');
            $landing_page = sanitize_text_field($poster['landing_page'] ?? '');

            if ($template_code === '' && $title === '' && $image_url === '') {
                continue;
            }

            $saved[] = [
                'template_code' => $template_code,
                'title' => $title,
                'image_url' => $image_url,
                'mini_program_qr' => $mini_program_qr,
                'landing_page' => $landing_page,
                'scene' => sanitize_text_field($poster['scene'] ?? ''),
                'valid_until' => sanitize_text_field($poster['valid_until'] ?? ''),
                'share_text' => sanitize_text_field($poster['share_text'] ?? ''),
                'qr_size' => isset($poster['qr_size']) ? (int) $poster['qr_size'] : null,
                'qr_x' => isset($poster['qr_x']) ? (int) $poster['qr_x'] : null,
                'qr_y' => isset($poster['qr_y']) ? (int) $poster['qr_y'] : null,
                'qr_padding' => isset($poster['qr_padding']) ? (int) $poster['qr_padding'] : null
            ];
        }

        update_option('myshop_promo_posters', $saved);

        $qr_input = $_POST['referral_qr'] ?? [];
        $qr_settings = [
            'fixed_qr_url' => esc_url_raw($qr_input['fixed_qr_url'] ?? ''),
            'logo_url' => esc_url_raw($qr_input['logo_url'] ?? '')
        ];
        update_option('myshop_referral_qr_settings', $qr_settings);
        wp_redirect(add_query_arg(['page' => 'myshop-promo-posters', 'saved' => 'true'], admin_url('admin.php')));
        exit;
    }
}

MyShop_Promo_Poster_Manager::init();
