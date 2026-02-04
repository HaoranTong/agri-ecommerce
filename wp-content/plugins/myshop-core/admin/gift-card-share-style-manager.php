<?php
if (!defined('ABSPATH')) {
    exit;
}

class MyShop_Gift_Card_Share_Style_Manager {
    const PAGE_SLUG = 'myshop-gift-card-share-styles';
    const OPTION_KEY = 'myshop_giftcard_share_styles';

    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_myshop_save_giftcard_share_style', [self::class, 'handle_save']);
        add_action('admin_post_myshop_delete_giftcard_share_style', [self::class, 'handle_delete']);
    }

    public static function register_menu() {
        add_submenu_page(
            MyShop_Gift_Card_Manager::PAGE_SLUG,
            __('购物卡分享模板', 'myshop'),
            __('分享模板', 'myshop'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('无权限访问该页面', 'myshop'));
        }

        wp_enqueue_media();

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        if ($action === 'edit') {
            $style = null;
            if (!empty($_GET['id'])) {
                $style = self::get_style(sanitize_key($_GET['id']));
            }
            self::render_editor($style);
            return;
        }

        $styles = self::get_all_styles();
        self::render_list($styles);
    }

    private static function render_list($styles) {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('购物卡分享模板', 'myshop') . '</h1>';
        echo '<p class="description">' . esc_html__('用于配置分享卡片的背景、二维码位置与祝福语排版。', 'myshop') . '</p>';
        echo '<a href="' . esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'action' => 'edit'], admin_url('admin.php'))) . '" class="page-title-action">' . esc_html__('新增模板', 'myshop') . '</a>';

        if (empty($styles)) {
            echo '<p>' . esc_html__('暂无模板，请新增一个模板。', 'myshop') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped" style="margin-top:15px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'myshop') . '</th>';
        echo '<th>' . esc_html__('名称', 'myshop') . '</th>';
        echo '<th>' . esc_html__('预览图', 'myshop') . '</th>';
        echo '<th>' . esc_html__('默认祝福语', 'myshop') . '</th>';
        echo '<th>' . esc_html__('操作', 'myshop') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($styles as $style) {
            $edit_url = add_query_arg(['page' => self::PAGE_SLUG, 'action' => 'edit', 'id' => $style['id']], admin_url('admin.php'));
            $delete_url = wp_nonce_url(admin_url('admin-post.php?action=myshop_delete_giftcard_share_style&id=' . $style['id']), 'myshop_delete_giftcard_share_style_' . $style['id']);
            $preview = $style['preview_image'] ?: '';
            echo '<tr>';
            echo '<td><strong>' . esc_html($style['id']) . '</strong></td>';
            echo '<td>' . esc_html($style['name']) . '</td>';
            echo '<td>' . ($preview ? '<img src="' . esc_url($preview) . '" style="width:80px;height:auto;border:1px solid #ddd;" />' : '-') . '</td>';
            echo '<td>' . esc_html($style['default_message'] ?? '') . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '">' . esc_html__('编辑', 'myshop') . '</a> | ';
            echo '<a href="' . esc_url($delete_url) . '" onclick="return confirm(\'确认删除该模板？\');" style="color:#b32d2e;">' . esc_html__('删除', 'myshop') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private static function render_editor($style) {
        $is_edit = $style !== null;
        $action_title = $is_edit ? __('编辑分享模板', 'myshop') : __('新增分享模板', 'myshop');
        $style = $style ?: self::default_style();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($action_title) . '</h1>';
        echo '<p class="description">' . esc_html__('可配置二维码尺寸与祝福语排版，图片请使用媒体库上传。', 'myshop') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('myshop_save_giftcard_share_style');
        echo '<input type="hidden" name="action" value="myshop_save_giftcard_share_style" />';
        echo '<input type="hidden" name="original_id" value="' . esc_attr($style['id']) . '" />';

        echo '<table class="form-table">';
        self::render_input_row('id', '模板ID', $style['id'], 'text', true);
        self::render_input_row('name', '模板名称', $style['name'], 'text', true);
        self::render_media_row('preview_image', '预览图', $style['preview_image']);
        self::render_media_row('background_image', '背景图', $style['background_image']);
        self::render_input_row('background_color', '背景色', $style['background_color'], 'text', false, ['placeholder' => '#FFFFFF']);
        self::render_input_row('text_color', '文字颜色', $style['text_color'], 'text', false, ['placeholder' => '#333333']);
        self::render_input_row('qr_placeholder_image', '标准二维码图片', $style['qr_placeholder_image'], 'text', false, ['placeholder' => 'https://...']);
        self::render_textarea_row('default_message', '默认祝福语', $style['default_message']);

        echo '<tr><th colspan="2"><h3>' . esc_html__('祝福语排版', 'myshop') . '</h3></th></tr>';
        self::render_input_row('message_font_size', '祝福语字体大小', $style['message_font_size'], 'number');
        self::render_input_row('message_line_height', '祝福语行高', $style['message_line_height'], 'number');
        self::render_input_row('message_top', '祝福语顶部位置(px)', $style['message_top'], 'number');
        self::render_input_row('message_max_chars', '每行字数上限', $style['message_max_chars'], 'number');

        echo '<tr><th colspan="2"><h3>' . esc_html__('二维码排版', 'myshop') . '</h3></th></tr>';
        self::render_input_row('qr_size', '二维码尺寸(px)', $style['qr_size'], 'number');
        self::render_input_row('qr_top', '二维码顶部位置(px)', $style['qr_top'], 'number');
        self::render_input_row('hint_text', '提示文字', $style['hint_text']);
        self::render_input_row('hint_font_size', '提示字体大小', $style['hint_font_size'], 'number');
        self::render_input_row('hint_top', '提示文字顶部位置(px)', $style['hint_top'], 'number');

        echo '</table>';

        submit_button($is_edit ? __('保存模板', 'myshop') : __('创建模板', 'myshop'));
        echo '</form>';
        echo '</div>';
    }

    private static function render_input_row($name, $label, $value, $type = 'text', $required = false, $attrs = []) {
        $attr_html = '';
        foreach ($attrs as $key => $val) {
            $attr_html .= ' ' . esc_attr($key) . '="' . esc_attr($val) . '"';
        }
        echo '<tr>';
        echo '<th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" ' . ($required ? 'required' : '') . $attr_html . ' /></td>';
        echo '</tr>';
    }

    private static function render_textarea_row($name, $label, $value) {
        echo '<tr>';
        echo '<th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td><textarea name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" rows="3" class="large-text">' . esc_textarea($value) . '</textarea></td>';
        echo '</tr>';
    }

    private static function render_media_row($name, $label, $value) {
        echo '<tr>';
        echo '<th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>';
        echo '<td>';
        echo '<input type="text" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text myshop-media-input" />';
        echo ' <button type="button" class="button myshop-media-btn" data-target="' . esc_attr($name) . '">' . esc_html__('选择图片', 'myshop') . '</button>';
        if ($value) {
            echo '<div style="margin-top:8px;"><img src="' . esc_url($value) . '" style="max-width:160px;border:1px solid #ddd;" /></div>';
        }
        echo '</td>';
        echo '</tr>';
    }

    public static function handle_save() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('无权限', 'myshop'));
        }
        check_admin_referer('myshop_save_giftcard_share_style');

        $style = self::sanitize_payload($_POST);
        if (empty($style['id'])) {
            wp_die(__('模板ID不能为空', 'myshop'));
        }

        $styles = self::get_all_styles();
        $styles = array_filter($styles, static function ($item) use ($style) {
            return $item['id'] !== $style['id'];
        });
        $styles[] = $style;

        update_option(self::OPTION_KEY, array_values($styles));

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('无权限', 'myshop'));
        }

        $id = isset($_GET['id']) ? sanitize_key($_GET['id']) : '';
        check_admin_referer('myshop_delete_giftcard_share_style_' . $id);

        $styles = self::get_all_styles();
        $styles = array_filter($styles, static function ($item) use ($id) {
            return $item['id'] !== $id;
        });
        update_option(self::OPTION_KEY, array_values($styles));

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private static function sanitize_payload($input) {
        $style = [
            'id' => sanitize_key($input['id'] ?? ''),
            'name' => sanitize_text_field($input['name'] ?? ''),
            'preview_image' => esc_url_raw($input['preview_image'] ?? ''),
            'background_image' => esc_url_raw($input['background_image'] ?? ''),
            'background_color' => sanitize_text_field($input['background_color'] ?? '#FFFFFF'),
            'text_color' => sanitize_text_field($input['text_color'] ?? '#333333'),
            'qr_placeholder_image' => esc_url_raw($input['qr_placeholder_image'] ?? ''),
            'default_message' => sanitize_textarea_field($input['default_message'] ?? ''),
            'message_font_size' => absint($input['message_font_size'] ?? 28),
            'message_line_height' => absint($input['message_line_height'] ?? 38),
            'message_top' => absint($input['message_top'] ?? 70),
            'message_max_chars' => absint($input['message_max_chars'] ?? 15),
            'qr_size' => absint($input['qr_size'] ?? 360),
            'qr_top' => absint($input['qr_top'] ?? 140),
            'hint_text' => sanitize_text_field($input['hint_text'] ?? '长按或扫码识别领取购物卡'),
            'hint_font_size' => absint($input['hint_font_size'] ?? 20),
            'hint_top' => absint($input['hint_top'] ?? 520),
            'updated_at' => current_time('mysql')
        ];
        return $style;
    }

    private static function get_all_styles() {
        $styles = get_option(self::OPTION_KEY, []);
        if (!is_array($styles)) {
            $styles = [];
        }
        if (empty($styles)) {
            $styles[] = self::default_style();
        }
        return array_values($styles);
    }

    private static function get_style($id) {
        $styles = self::get_all_styles();
        foreach ($styles as $style) {
            if ($style['id'] === $id) {
                return $style;
            }
        }
        return null;
    }

    private static function default_style() {
        return [
            'id' => 'default',
            'name' => '默认样式',
            'preview_image' => '',
            'background_image' => '',
            'background_color' => '#FFFFFF',
            'text_color' => '#333333',
            'qr_placeholder_image' => 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&data=https%3A%2F%2Ffanbaoer.com',
            'default_message' => '送你一份精心准备的好礼，愿你喜欢。',
            'message_font_size' => 28,
            'message_line_height' => 38,
            'message_top' => 70,
            'message_max_chars' => 15,
            'qr_size' => 360,
            'qr_top' => 140,
            'hint_text' => '长按或扫码识别领取购物卡',
            'hint_font_size' => 20,
            'hint_top' => 520,
            'updated_at' => current_time('mysql')
        ];
    }
}

MyShop_Gift_Card_Share_Style_Manager::init();

add_action('admin_footer', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'myshop_page_' . MyShop_Gift_Card_Share_Style_Manager::PAGE_SLUG) {
        return;
    }
    ?>
    <script>
      (function() {
        const buttons = document.querySelectorAll('.myshop-media-btn');
        if (!buttons.length || !window.wp || !wp.media) return;
        buttons.forEach((btn) => {
          btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const frame = wp.media({
              title: '选择图片',
              button: { text: '使用该图片' },
              multiple: false
            });
            frame.on('select', () => {
              const attachment = frame.state().get('selection').first().toJSON();
              if (input) {
                input.value = attachment.url || '';
              }
            });
            frame.open();
          });
        });
      })();
    </script>
    <?php
});
