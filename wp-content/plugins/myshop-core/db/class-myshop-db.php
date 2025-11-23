<?php
class MyShop_DB {
    const VERSION = '1.1.0';
    const OPTION_KEY = 'myshop_db_version';

    public static function install() {
        self::create_tables();
        update_option(self::OPTION_KEY, self::VERSION);
    }

    public static function upgrade() {
        $installed_version = get_option(self::OPTION_KEY);
        if (!$installed_version) {
            self::install();
            return;
        }

        if (version_compare($installed_version, self::VERSION, '<')) {
            self::create_tables();
            update_option(self::OPTION_KEY, self::VERSION);
        }
    }

    private static function create_tables() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        $tables = [
            "CREATE TABLE {$prefix}myshop_gift_card_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                type ENUM('fixed_amount','product_bundle') NOT NULL,
                fixed_amount DECIMAL(10,2) NULL,
                currency CHAR(3) NOT NULL DEFAULT 'CNY',
                product_id BIGINT UNSIGNED NULL,
                variation_ids LONGTEXT NULL,
                bundle_items LONGTEXT NULL,
                delivery_modes LONGTEXT NOT NULL,
                share_template_config LONGTEXT NULL,
                print_template_url VARCHAR(255) NULL,
                valid_days INT NOT NULL DEFAULT 365,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_gift_cards (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                card_number VARCHAR(32) NOT NULL,
                template_id BIGINT UNSIGNED NOT NULL,
                template_type ENUM('fixed_amount','product_bundle') NOT NULL,
                initial_amount DECIMAL(10,2) NULL,
                balance DECIMAL(10,2) NULL,
                currency CHAR(3) NOT NULL DEFAULT 'CNY',
                linked_product_id BIGINT UNSIGNED NULL,
                linked_variation_ids LONGTEXT NULL,
                bundle_config LONGTEXT NULL,
                purchaser_id BIGINT UNSIGNED NOT NULL,
                redeemer_id BIGINT UNSIGNED NULL,
                order_id BIGINT UNSIGNED NULL,
                bind_status ENUM('unbound','bound') NOT NULL DEFAULT 'unbound',
                status ENUM('active','redeemed','locked','expired','cancelled') NOT NULL DEFAULT 'active',
                share_token VARCHAR(64) NULL,
                share_channel VARCHAR(32) NULL,
                share_token_expires_at DATETIME NULL,
                print_package_url VARCHAR(255) NULL,
                pin_code_hash VARCHAR(255) NULL,
                pin_revealed_at DATETIME NULL,
                pin_reveal_limit TINYINT UNSIGNED NOT NULL DEFAULT 1,
                pin_reveal_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_card_number (card_number),
                KEY idx_template (template_id),
                KEY idx_purchaser (purchaser_id),
                KEY idx_redeemer (redeemer_id),
                KEY idx_status (status),
                KEY idx_share_token (share_token)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_gift_card_redemptions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                card_id BIGINT UNSIGNED NOT NULL,
                template_id BIGINT UNSIGNED NOT NULL,
                redeemer_id BIGINT UNSIGNED NOT NULL,
                redeem_type ENUM('deduct','exchange') NOT NULL,
                channel VARCHAR(32) NOT NULL DEFAULT 'miniprogram',
                operator_id BIGINT UNSIGNED NULL,
                used_amount DECIMAL(10,2) NOT NULL,
                balance_after DECIMAL(10,2) NOT NULL,
                target_order_id BIGINT UNSIGNED NULL,
                redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_card (card_id),
                KEY idx_redeemer (redeemer_id)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_gift_card_share_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                card_id BIGINT UNSIGNED NOT NULL,
                operator_id BIGINT UNSIGNED NOT NULL,
                delivery_mode ENUM('digital_share','printable') NOT NULL,
                channel VARCHAR(32) NOT NULL,
                share_token VARCHAR(64) NULL,
                print_package_url VARCHAR(255) NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_card (card_id),
                KEY idx_operator (operator_id)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_point_ledger (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                type ENUM('earn','spend','adjust','expire') NOT NULL,
                delta INT NOT NULL,
                balance_after INT NOT NULL,
                reference_order_id BIGINT UNSIGNED NULL,
                reservation_id VARCHAR(64) NULL,
                status ENUM('pending','confirmed','released') NOT NULL DEFAULT 'confirmed',
                channel VARCHAR(32) NOT NULL DEFAULT 'order',
                operator_id BIGINT UNSIGNED NULL,
                expire_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (user_id),
                KEY idx_reservation (reservation_id)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_commissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                earner_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'CNY',
                commission_type ENUM('referral','agent') NOT NULL,
                referrer_id BIGINT UNSIGNED NULL,
                agent_id BIGINT UNSIGNED NULL,
                settlement_batch VARCHAR(50) NULL,
                status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
                expected_payout_at DATETIME NULL,
                paid_at DATETIME NULL,
                note VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_order (order_id),
                KEY idx_earner (earner_id),
                KEY idx_status (status)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_agents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                agent_user_id BIGINT UNSIGNED NOT NULL,
                parent_agent_id BIGINT UNSIGNED NULL,
                agent_code VARCHAR(20) NOT NULL,
                level TINYINT NOT NULL DEFAULT 1,
                region VARCHAR(50) NULL,
                status ENUM('active','frozen','terminated') NOT NULL DEFAULT 'active',
                joined_at DATETIME NOT NULL,
                invite_qr VARCHAR(255) NULL,
                team_target LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_agent_user (agent_user_id),
                UNIQUE KEY uniq_agent_code (agent_code),
                KEY idx_parent (parent_agent_id)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_agent_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                agent_user_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(50) NOT NULL,
                reason VARCHAR(255) NULL,
                operator_id BIGINT UNSIGNED NOT NULL,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_agent (agent_user_id)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_referrals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inviter_id BIGINT UNSIGNED NOT NULL,
                invitee_id BIGINT UNSIGNED NOT NULL,
                level TINYINT NOT NULL DEFAULT 1,
                channel_code VARCHAR(50) NULL,
                first_order_status ENUM('pending','completed','expired') NOT NULL DEFAULT 'pending',
                first_order_id BIGINT UNSIGNED NULL,
                first_order_completed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_relation (inviter_id, invitee_id),
                KEY idx_inviter (inviter_id),
                KEY idx_invitee (invitee_id)
            ) ENGINE=InnoDB $charset",

            "CREATE TABLE {$prefix}myshop_invitation_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inviter_id BIGINT UNSIGNED NULL,
                channel VARCHAR(50) NULL,
                scene VARCHAR(100) NULL,
                landing_page VARCHAR(150) NULL,
                extra LONGTEXT NULL,
                recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_inviter (inviter_id),
                KEY idx_channel (channel)
            ) ENGINE=InnoDB $charset"
        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }
}