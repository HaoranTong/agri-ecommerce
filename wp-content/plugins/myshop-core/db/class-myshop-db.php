<?php
class MyShop_DB {
    public static function activate() {
        self::create_tables();
        update_option('myshop_db_version', '1.0.0');
    }

    private static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $tables = [
            "{$wpdb->prefix}myshop_gift_cards" => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}myshop_gift_cards (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_code VARCHAR(50) NOT NULL UNIQUE,
                    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    status ENUM('active','used','expired') DEFAULT 'active',
                    user_id BIGINT UNSIGNED DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES {$wpdb->users}(ID) ON DELETE SET NULL
                ) $charset;",
            "{$wpdb->prefix}myshop_commissions" => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}myshop_commissions (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    order_id BIGINT UNSIGNED NOT NULL,
                    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    status ENUM('pending','paid','failed') DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES {$wpdb->users}(ID) ON DELETE CASCADE,
                    FOREIGN KEY (order_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE
                ) $charset;",
            "{$wpdb->prefix}myshop_referral_codes" => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}myshop_referral_codes (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(20) NOT NULL UNIQUE,
                    user_id BIGINT UNSIGNED NOT NULL,
                    used_count INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES {$wpdb->users}(ID) ON DELETE CASCADE
                ) $charset;"
        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }
}