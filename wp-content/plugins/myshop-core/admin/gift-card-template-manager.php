<?php
if (!defined('ABSPATH')) {
    exit;
}

class MyShop_Gift_Card_Template_Manager {
    const PAGE_SLUG = 'myshop-gift-card-templates';
    const DEFAULT_DELIVERY_MODES = ['digital_share', 'printable'];

    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_myshop_save_gift_card_template', [self::class, 'handle_save']);
        add_action('admin_post_myshop_delete_gift_card_template', [self::class, 'handle_delete']);
    }

    public static function register_menu() {
        add_menu_page(
            __('礼品卡模板', 'myshop'),
            __('礼品卡模板', 'myshop'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [self::class, 'render_page'],
            'dashicons-tickets'
        );
    }

    public static function render_page() {
        global $wpdb;

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        if ($action === 'edit') {
            $template = null;
            if (!empty($_GET['id'])) {
                $template = self::get_template((int) $_GET['id']);
            }
            self::render_editor($template);
            return;
        }

        $templates = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}myshop_gift_card_templates ORDER BY id DESC");
        self::render_list($templates);
    }

    private static function render_list($templates) {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('礼品卡模板管理', 'myshop') . '</h1>';
        echo '<a href="' . esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'action' => 'edit'], admin_url('admin.php'))) . '" class="page-title-action">' . esc_html__('新增模板', 'myshop') . '</a>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>
                <th>' . esc_html__('ID', 'myshop') . '</th>
                <th>' . esc_html__('名称', 'myshop') . '</th>
                <th>' . esc_html__('类型', 'myshop') . '</th>
                <th>' . esc_html__('有效期(天)', 'myshop') . '</th>
                <th>' . esc_html__('交付方式', 'myshop') . '</th>
                <th>' . esc_html__('操作', 'myshop') . '</th>
            </tr></thead><tbody>';

        if (empty($templates)) {
            echo '<tr><td colspan="6">' . esc_html__('暂无模板', 'myshop') . '</td></tr>';
        } else {
            foreach ($templates as $row) {
                $delivery_modes = $row->delivery_modes ? implode(', ', (array) json_decode($row->delivery_modes, true)) : '-';
                $edit_url = add_query_arg(['page' => self::PAGE_SLUG, 'action' => 'edit', 'id' => $row->id], admin_url('admin.php'));
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=myshop_delete_gift_card_template&id=' . $row->id), 'myshop_delete_gift_card_template_' . $row->id);

                echo '<tr>';
                echo '<td>' . esc_html($row->id) . '</td>';
                echo '<td>' . esc_html($row->name) . '</td>';
                echo '<td>' . esc_html(self::get_type_label($row->type)) . '</td>';
                echo '<td>' . esc_html($row->valid_days) . '</td>';
                echo '<td>' . esc_html($delivery_modes) . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url($edit_url) . '">' . esc_html__('编辑', 'myshop') . '</a> | ';
                echo '<a href="' . esc_url($delete_url) . '" onclick="return confirm(\'确认删除该模板？\');">' . esc_html__('删除', 'myshop') . '</a>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private static function render_editor($template) {
        $is_edit = $template !== null;
        $action  = $is_edit ? '编辑模板' : '新增模板';

        $form_action = admin_url('admin-post.php');
        $delivery_modes = $template && $template->delivery_modes ? (array) json_decode($template->delivery_modes, true) : [];
        $bundle_items = $template && $template->bundle_items ? json_decode($template->bundle_items, true) : [];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($action) . '</h1>';
        self::render_help_box();

        echo '<form method="post" action="' . esc_url($form_action) . '">';
        wp_nonce_field('myshop_save_gift_card_template');
        echo '<input type="hidden" name="action" value="myshop_save_gift_card_template" />';
        if ($is_edit) {
            echo '<input type="hidden" name="id" value="' . esc_attr($template->id) . '" />';
        }

        echo '<table class="form-table">';
        self::render_input_row('name', '模板名称', $template->name ?? '', 'text', true);

        echo '<tr>
                <th><label for="type">' . esc_html__('模板类型', 'myshop') . '</label></th>
                <td>
                    <select name="type" id="type">
                        <option value="fixed_amount" ' . selected($template->type ?? '', 'fixed_amount', false) . '>' . esc_html__('储值卡', 'myshop') . '</option>
                        <option value="product_bundle" ' . selected($template->type ?? '', 'product_bundle', false) . '>' . esc_html__('商品兑换卡', 'myshop') . '</option>
                        <option value="custom_bundle" ' . selected($template->type ?? '', 'custom_bundle', false) . '>' . esc_html__('任意组合', 'myshop') . '</option>
                    </select>
                </td>
            </tr>';

        self::render_input_row('fixed_amount', '储值卡面额', $template->fixed_amount ?? '', 'number', false, ['step' => '0.01']);
        self::render_input_row('currency', '币种', $template->currency ?? 'CNY');
        self::render_input_row(
            'product_id',
            '绑定商品ID',
            $template->product_id ?? '',
            'number',
            false,
            [],
            __('仅商品兑换卡需要；任意组合卡会在下单时根据订单快照自动记录。', 'myshop')
        );
        self::render_input_row(
            'variation_ids',
            '绑定变体ID(逗号分隔)',
            $template->variation_ids ?? '',
            'text',
            false,
            [],
            __('示例：101,102。留空视为所有变体；任意组合卡无需填写。', 'myshop')
        );
        self::render_textarea_row(
            'bundle_items',
            '礼包明细(JSON)',
            $bundle_items ? wp_json_encode($bundle_items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '',
            __('仅商品兑换卡需要预设礼包；任意组合卡会写入下单商品快照。示例：[{"product_id":101,"variation_id":202,"quantity":2}]', 'myshop')
        );
        self::render_input_row('valid_days', '有效期(天)', $template->valid_days ?? 365, 'number');

        echo '<tr>
                <th>' . esc_html__('交付方式', 'myshop') . '</th>
                <td>
                    <label><input type="checkbox" name="delivery_modes[]" value="digital_share" ' . checked(in_array('digital_share', $delivery_modes, true), true, false) . '> ' . esc_html__('数字分享', 'myshop') . '</label>
                    <br/>
                    <label><input type="checkbox" name="delivery_modes[]" value="printable" ' . checked(in_array('printable', $delivery_modes, true), true, false) . '> ' . esc_html__('打印卡', 'myshop') . '</label>
                </td>
            </tr>';

        self::render_textarea_row('share_template_config', '分享模板配置(JSON)', $template->share_template_config ?? '', sprintf(__('留空则使用默认模板：%s', 'myshop'), self::get_asset_link('assets/giftcard/share-default.json')));
        self::render_input_row('print_template_url', '打印模板URL', $template->print_template_url ?? '', 'text', false, [], sprintf(__('可填入媒体库文件 URL，留空使用默认模板：%s', 'myshop'), self::get_asset_link('assets/giftcard/print-default.html')));

        echo '</table>';

        submit_button($is_edit ? __('保存模板', 'myshop') : __('创建模板', 'myshop'));
        echo '</form>';

        echo '</div>';
    }

    private static function render_help_box() {
        echo '<div class="notice notice-info" style="margin-top:15px;">';
        echo '<p><strong>' . esc_html__('使用说明', 'myshop') . '</strong></p>';
        echo '<ul style="margin-left:20px;list-style:disc;">';
        echo '<li>' . esc_html__('储值卡适用于多次抵扣，商品兑换卡/任意组合卡将根据模板或订单快照兑换指定商品。', 'myshop') . '</li>';
        echo '<li>' . esc_html__('任意组合卡建议命名为“订单自选礼卡”等描述性名称，兑换内容来自下单时的商品快照。', 'myshop') . '</li>';
        echo '<li>' . sprintf(__('默认分享模板：%s；默认打印模板：%s。可在下方自定义。', 'myshop'), self::get_asset_link('assets/giftcard/share-default.json'), self::get_asset_link('assets/giftcard/print-default.html')) . '</li>';
        echo '<li>' . esc_html__('交付方式在用户分享时选择，模板只需声明允许的模式；未勾选则默认同时开启数字分享与打印。', 'myshop') . '</li>';
        echo '<li>' . esc_html__('任意组合卡无需填写商品/变体/礼包字段，系统将以订单中的 SKU 快照作为兑换依据。', 'myshop') . '</li>';
        echo '</ul>';
        echo '</div>';
    }

    public static function handle_save() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('无权限', 'myshop'));
        }
        check_admin_referer('myshop_save_gift_card_template');

        global $wpdb;
        $table = $wpdb->prefix . 'myshop_gift_card_templates';

        $id    = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $data  = self::sanitize_template_payload($_POST);

        if ($id) {
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $wpdb->insert($table, $data);
        }

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('无权限', 'myshop'));
        }

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('myshop_delete_gift_card_template_' . $id);

        if ($id) {
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'myshop_gift_card_templates', ['id' => $id]);
        }

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private static function sanitize_template_payload($input) {
        $type = sanitize_text_field($input['type'] ?? 'product_bundle');
        $delivery_modes = isset($input['delivery_modes']) && is_array($input['delivery_modes'])
            ? array_values(array_intersect(self::DEFAULT_DELIVERY_MODES, $input['delivery_modes']))
            : [];

        if (empty($delivery_modes)) {
            $delivery_modes = self::DEFAULT_DELIVERY_MODES;
        }

        $bundle_items_json = self::sanitize_json($input['bundle_items'] ?? '');
        $share_template_json = self::sanitize_json($input['share_template_config'] ?? '');

        $product_id = null;
        $variation_ids = '';
        $bundle_json_for_save = null;

        if ($type === 'product_bundle') {
            $product_id = isset($input['product_id']) ? absint($input['product_id']) : null;
            $variation_ids = sanitize_text_field($input['variation_ids'] ?? '');
            $bundle_json_for_save = $bundle_items_json;
        }

        return [
            'name'                 => sanitize_text_field($input['name'] ?? ''),
            'type'                 => $type,
            'fixed_amount'         => isset($input['fixed_amount']) ? (float) $input['fixed_amount'] : null,
            'currency'             => sanitize_text_field($input['currency'] ?? 'CNY'),
            'product_id'           => $product_id,
            'variation_ids'        => $variation_ids,
            'bundle_items'         => $bundle_json_for_save,
            'delivery_modes'       => wp_json_encode($delivery_modes),
            'share_template_config'=> $share_template_json,
            'print_template_url'   => esc_url_raw($input['print_template_url'] ?? ''),
            'valid_days'           => isset($input['valid_days']) ? absint($input['valid_days']) : 365,
            'updated_at'           => current_time('mysql', true)
        ];
    }

    private static function sanitize_json($value) {
        if (empty($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return wp_json_encode($decoded);
        }

        return null;
    }

    private static function render_input_row($name, $label, $value, $type = 'text', $required = false, $attrs = [], $description = '') {
        $attr_html = '';
        foreach ($attrs as $key => $attr_value) {
            $attr_html .= sprintf(' %s="%s"', esc_attr($key), esc_attr($attr_value));
        }

        echo '<tr>
                <th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>
                <td>
                    <input name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '" class="regular-text"' . $attr_html . ($required ? ' required' : '') . '>
                    ' . ($description ? '<p class="description">' . wp_kses_post($description) . '</p>' : '') . '
                </td>
            </tr>';
    }

    private static function render_textarea_row($name, $label, $value, $description = '') {
        echo '<tr>
                <th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>
                <td>
                    <textarea name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" rows="5" class="large-text code">' . esc_textarea($value) . '</textarea>
                    ' . ($description ? '<p class="description">' . wp_kses_post($description) . '</p>' : '') . '
                </td>
            </tr>';
    }

    private static function get_template($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}myshop_gift_card_templates WHERE id = %d",
            $id
        ));
    }

    private static function get_type_label($type) {
        $map = [
            'fixed_amount'   => __('储值卡', 'myshop'),
            'product_bundle' => __('商品兑换卡', 'myshop'),
            'custom_bundle'  => __('任意组合卡', 'myshop')
        ];
        return $map[$type] ?? $type;
    }

    private static function get_asset_link($relative_path) {
        $plugin_file = MYSHOP_PLUGIN_DIR . 'myshop-core.php';
        $url = plugins_url($relative_path, $plugin_file);
        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($relative_path) . '</a>';
    }
}

MyShop_Gift_Card_Template_Manager::init();
