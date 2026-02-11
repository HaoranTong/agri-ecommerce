<?php
if (!defined('ABSPATH')) {
    exit;
}

class MyShop_Operations_Dashboard {
    const PAGE_SLUG = 'myshop-operations-dashboard';

    public static function init() {
        add_action('admin_menu', [self::class, 'register_menu']);
    }

    public static function register_menu() {
        add_menu_page(
            'MyShop 运营控制台',
            '📊 运营控制台',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page'],
            'dashicons-chart-area',
            57
        );

        add_submenu_page(
            self::PAGE_SLUG,
            '社交裂变数据',
            '社交裂变数据',
            'manage_options',
            'myshop-referral-analytics',
            ['MyShop_Referral_Analytics_Manager', 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限访问该页面');
        }

        $sections = [
            '基础配置' => [
                [
                    'title' => '系统配置',
                    'desc' => '小程序/通知/快递映射等基础配置',
                    'url' => admin_url('admin.php?page=myshop-config')
                ],
                [
                    'title' => '轮播图管理',
                    'desc' => 'PC 端轮播图短代码配置',
                    'url' => admin_url('admin.php?page=myshop-slider')
                ]
            ],
            '社交裂变' => [
                [
                    'title' => '社交裂变数据',
                    'desc' => '绑定关系、订单转化、奖励积分汇总',
                    'url' => admin_url('admin.php?page=myshop-referral-analytics')
                ],
                [
                    'title' => '推广海报',
                    'desc' => '推广海报素材与落地页配置',
                    'url' => admin_url('admin.php?page=myshop-promo-posters')
                ]
            ],
            '积分与分销' => [
                [
                    'title' => '积分设置',
                    'desc' => '积分规则、分销奖励积分与兑换设置',
                    'url' => admin_url('admin.php?page=myshop-points')
                ],
                [
                    'title' => '积分明细',
                    'desc' => '用户积分流水记录',
                    'url' => admin_url('admin.php?page=myshop-points-ledger')
                ],
                [
                    'title' => '分销结算',
                    'desc' => '分销提现申请与佣金明细',
                    'url' => admin_url('admin.php?page=myshop-commission-payouts')
                ]
            ],
            '购物卡' => [
                [
                    'title' => '购物卡模板',
                    'desc' => '购物卡商品模板与交付方式',
                    'url' => admin_url('admin.php?page=myshop-gift-card-templates')
                ],
                [
                    'title' => '购物卡资产',
                    'desc' => '购物卡状态查询与安全操作',
                    'url' => admin_url('admin.php?page=myshop-gift-card-center')
                ],
                [
                    'title' => '分享模板',
                    'desc' => '购物卡分享模板配置',
                    'url' => admin_url('admin.php?page=myshop-gift-card-share-styles')
                ]
            ],
            '订单与售后' => [
                [
                    'title' => '订单列表',
                    'desc' => 'WooCommerce 订单管理',
                    'url' => admin_url('edit.php?post_type=shop_order')
                ],
                [
                    'title' => '退货管理',
                    'desc' => '售后退货审核与退款',
                    'url' => admin_url('admin.php?page=myshop-return-manager')
                ]
            ],
            '运维工具' => [
                [
                    'title' => '测试用户',
                    'desc' => '开发环境测试账号管理',
                    'url' => admin_url('users.php?page=myshop-test-users')
                ],
                [
                    'title' => '清理测试用户',
                    'desc' => '仅开发环境可用',
                    'url' => admin_url('admin.php?page=myshop-cleanup-test-users')
                ]
            ]
        ];

        ?>
        <div class="wrap">
            <h1>📊 MyShop 运营控制台</h1>
            <p class="description">集中入口 + 快速导航，不改变现有功能位置，避免后台菜单分散。</p>
            <style>
                .myshop-ops-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-top: 20px; }
                .myshop-ops-card { background: #fff; border: 1px solid #e5e5e5; border-radius: 10px; padding: 16px; }
                .myshop-ops-card h3 { margin: 0 0 10px; font-size: 16px; }
                .myshop-ops-card a { display: block; padding: 8px 0; text-decoration: none; }
                .myshop-ops-card a strong { display: block; color: #1d2327; }
                .myshop-ops-card a span { color: #6b7280; font-size: 12px; }
            </style>

            <div class="myshop-ops-grid">
                <?php foreach ($sections as $title => $items): ?>
                    <div class="myshop-ops-card">
                        <h3><?php echo esc_html($title); ?></h3>
                        <?php foreach ($items as $item): ?>
                            <a href="<?php echo esc_url($item['url']); ?>">
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <span><?php echo esc_html($item['desc']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}

