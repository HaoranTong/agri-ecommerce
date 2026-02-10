<?php

class MyShop_Maintenance {
    private const FLAG_FILE = '.maintenance_flag';
    private const WP_MAINT_FILE = '.maintenance';

    public static function boot() {
        add_filter('rest_pre_dispatch', [__CLASS__, 'block_rest'], 9, 3);
        add_action('admin_init', [__CLASS__, 'block_admin'], 0);
        add_action('template_redirect', [__CLASS__, 'block_frontend'], 0);
    }

    private static function is_enabled(): bool {
        return file_exists(ABSPATH . self::FLAG_FILE) || file_exists(ABSPATH . self::WP_MAINT_FILE);
    }

    private static function should_bypass(): bool {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        return false;
    }

    private static function render_page(string $title, string $message): void {
        nocache_headers();
        status_header(200);
        $content = sprintf(
            '<div style="max-width:640px;margin:12vh auto;padding:24px 28px;border-radius:12px;background:#fff;box-shadow:0 12px 32px rgba(0,0,0,0.08);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
                <h1 style="margin:0 0 12px;font-size:20px;">%s</h1>
                <p style="margin:0 0 12px;line-height:1.6;">%s</p>
                <p style="margin:0;color:#6b7280;font-size:14px;">系统正在更新中，请稍后刷新重试。</p>
            </div>',
            esc_html($title),
            esc_html($message)
        );
        wp_die($content, $title, ['response' => 200, 'back_link' => false]);
    }

    public static function block_rest($result, $server, $request) {
        if (self::should_bypass() || !self::is_enabled()) {
            return $result;
        }

        return new WP_Error(
            'maintenance',
            '系统维护中，请稍后再试',
            ['status' => 503]
        );
    }

    public static function block_admin(): void {
        if (self::should_bypass() || !self::is_enabled()) {
            return;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        self::render_page('系统维护中', '正在执行系统更新，为确保数据安全，暂时停止后台操作。');
    }

    public static function block_frontend(): void {
        if (self::should_bypass() || !self::is_enabled()) {
            return;
        }

        if (is_admin()) {
            return;
        }

        self::render_page('系统维护中', '系统正在更新，为避免下单或操作异常，服务暂时关闭。');
    }
}

