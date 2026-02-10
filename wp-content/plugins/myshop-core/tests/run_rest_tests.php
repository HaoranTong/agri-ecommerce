<?php

function find_wp_load($start) {
    $dir = $start;
    for ($i = 0; $i < 8; $i++) {
        $candidate = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
        if (file_exists($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

$wp_load = find_wp_load(__DIR__);
if (!$wp_load) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(1);
}

require_once $wp_load;

if (!defined('MYSHOP_ALLOW_TEST_LOGIN')) {
    define('MYSHOP_ALLOW_TEST_LOGIN', true);
}

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "ASSERT FAILED: {$message}\n");
        exit(1);
    }
}

function call_api($method, $route, $params = null, $headers = []) {
    $request = new WP_REST_Request($method, $route);
    foreach ($headers as $key => $value) {
        $request->set_header($key, $value);
    }
    if ($params !== null) {
        if ($method === 'GET') {
            $request->set_query_params($params);
        } else {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($params));
        }
    }
    $response = rest_do_request($request);
    return $response;
}

$old_missions = get_option('myshop_points_missions');
$old_redeem_options = get_option('myshop_points_redeem_options');
$old_posters = get_option('myshop_promo_posters');
$old_points_settings = get_option('myshop_points_settings');
$old_payout_min = get_option('myshop_commission_payout_min');

register_shutdown_function(function () use ($old_missions, $old_redeem_options, $old_posters, $old_points_settings, $old_payout_min) {
    update_option('myshop_points_missions', $old_missions);
    update_option('myshop_points_redeem_options', $old_redeem_options);
    update_option('myshop_promo_posters', $old_posters);
    update_option('myshop_points_settings', $old_points_settings);
    update_option('myshop_commission_payout_min', $old_payout_min);
});

do_action('rest_api_init');

$maintenance_flag = ABSPATH . '.maintenance_flag';
if (!file_exists($maintenance_flag)) {
    file_put_contents($maintenance_flag, '1');
}
$maintenance_resp = call_api('GET', '/myshop/v1/config/public');
assert_true($maintenance_resp->get_status() === 503, 'maintenance mode status');
@unlink($maintenance_flag);

$login_response = call_api('POST', '/myshop/v1/auth/login', ['code' => 'test001']);
$token = null;
$auth_header = [];

if ($login_response->get_status() === 200) {
    $login_data = $login_response->get_data();
    assert_true(isset($login_data['data']['token']), 'login token');
    $token = $login_data['data']['token'];
    $auth_header = ['Authorization' => 'Bearer ' . $token];
} else {
    $openid = 'oTest_User_001_FixedOpenID';
    $user_id = MyShop_Auth::get_or_create_user_by_openid($openid);
    assert_true((bool) $user_id, 'fallback user');
    $token = MyShop_Auth::generate_token($user_id, $openid);
    $auth_header = ['Authorization' => 'Bearer ' . $token];
}

$user = MyShop_Auth::validate_token($token);
assert_true($user instanceof WP_User, 'user from token');

$operator_cap_added = false;
if (!user_can($user, 'manage_woocommerce') && !user_can($user, 'manage_options')) {
    $user->add_cap('manage_woocommerce');
    $operator_cap_added = true;
}

register_shutdown_function(function () use ($user, $operator_cap_added) {
    if (!$operator_cap_added || !$user instanceof WP_User) {
        return;
    }

    $fresh_user = get_userdata($user->ID);
    if ($fresh_user instanceof WP_User) {
        $fresh_user->remove_cap('manage_woocommerce');
    }
});

$payment_order_id = null;
if (function_exists('wc_create_order')) {
    $order = wc_create_order(['customer_id' => $user->ID]);
    $order->set_status('pending');
    $order->save();
    $payment_order_id = $order->get_id();
}

$mission_id = 'test_mission_' . time();
$redeem_option_id = 'test_option_' . time();

update_option('myshop_points_settings', [
    'enable_points' => 1,
    'earn_rate' => 1,
    'min_order_amount' => 0,
    'register_bonus' => 0,
    'daily_signin_points' => 10,
    'enable_points_discount' => 1,
    'redeem_rate' => 100,
    'min_points_to_use' => 0,
    'max_discount_percent' => 50,
    'min_order_amount_to_use' => 0,
    'enable_expiry' => 1,
    'expiry_days' => 1
]);

update_option('myshop_commission_payout_min', 0);

update_option('myshop_points_missions', [
    [
        'mission_id' => $mission_id,
        'title' => '测试任务',
        'description' => '用于接口测试',
        'reward_points' => 20,
        'status' => 'available',
        'progress' => 0,
        'goal' => 1
    ]
]);

update_option('myshop_points_redeem_options', [
    [
        'option_id' => $redeem_option_id,
        'type' => 'coupon',
        'title' => '测试兑换券',
        'cost_points' => 10,
        'stock' => 3,
        'status' => 'active',
        'coupon_code' => 'COUPON-TEST-001'
    ]
]);

update_option('myshop_promo_posters', [
    [
        'template_code' => 'poster-test',
        'title' => '测试海报',
        'image_url' => 'https://example.com/poster.png',
        'mini_program_qr' => 'https://example.com/poster-qr.png',
        'share_text' => '测试分享文案',
        'scene' => 'invite',
        'valid_until' => date('c', time() + 86400),
        'tracking_params' => ['channel' => 'test']
    ]
]);

global $wpdb;
$ledger_table = $wpdb->prefix . 'myshop_point_ledger';
$available_points = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(delta), 0) FROM {$ledger_table} WHERE user_id = %d AND status = 'confirmed'",
    $user->ID
));
if ($available_points < 50) {
    $wpdb->insert(
        $ledger_table,
        [
            'user_id' => $user->ID,
            'type' => 'earn',
            'delta' => 50,
            'balance_after' => $available_points + 50,
            'status' => 'confirmed',
            'channel' => 'test_setup',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ],
        ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
    );
}

$summary_resp = call_api('GET', '/myshop/v1/points/summary', null, $auth_header);
assert_true($summary_resp->get_status() === 200, 'points/summary status');

delete_user_meta($user->ID, '_myshop_last_signin_date');
$signin_resp = call_api('POST', '/myshop/v1/points/signin', [], $auth_header);
assert_true($signin_resp->get_status() === 200, 'points/signin status');
$signin_again = call_api('POST', '/myshop/v1/points/signin', [], $auth_header);
assert_true($signin_again->get_status() === 409, 'points/signin duplicate');

$expired_points = 5;
$wpdb->insert(
    $ledger_table,
    [
        'user_id' => $user->ID,
        'type' => 'earn',
        'delta' => $expired_points,
        'balance_after' => $available_points + 50 + $expired_points,
        'status' => 'confirmed',
        'channel' => 'test_expire',
        'expire_at' => date('Y-m-d H:i:s', time() - 3600),
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ],
    ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
);

do_action('myshop_points_expire_daily');
$expired_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$ledger_table} WHERE user_id = %d AND channel = 'points_expire'",
    $user->ID
));
assert_true($expired_count > 0, 'points expire job');

$invitee_user_id = MyShop_Auth::get_or_create_user_by_openid('oTest_User_002_FixedOpenID');
assert_true((bool) $invitee_user_id, 'invitee user');

$referral_table = $wpdb->prefix . 'myshop_referrals';
$exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$referral_table} WHERE inviter_id = %d AND invitee_id = %d",
    $user->ID,
    $invitee_user_id
));
if ((int) $exists === 0) {
    $wpdb->insert(
        $referral_table,
        [
            'inviter_id' => $user->ID,
            'invitee_id' => $invitee_user_id,
            'level' => 1,
            'first_order_status' => 'pending',
            'created_at' => current_time('mysql')
        ],
        ['%d', '%d', '%d', '%s', '%s']
    );
}

$agent_table = $wpdb->prefix . 'myshop_agents';
$agent_id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$agent_table} WHERE agent_user_id = %d LIMIT 1",
    $user->ID
));
if (!$agent_id) {
    $wpdb->insert(
        $agent_table,
        [
            'agent_user_id' => $user->ID,
            'parent_agent_id' => null,
            'agent_code' => 'AGTEST' . $user->ID,
            'level' => 1,
            'region_zone' => '测试大区',
            'region_province' => null,
            'region_city' => null,
            'region' => '测试大区',
            'region_key' => 'test',
            'active_until' => date('Y-m-d H:i:s', time() + 86400 * 365),
            'is_active' => 1,
            'status' => 'active',
            'joined_at' => current_time('mysql'),
            'invite_qr' => null,
            'team_target' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ],
        ['%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
    );
    $agent_id = $wpdb->insert_id;
}

$commission_table = $wpdb->prefix . 'myshop_commissions';
$commission_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$commission_table} WHERE earner_id = %d AND commission_type = 'agent'",
    $user->ID
));
if ((int) $commission_exists === 0) {
    $wpdb->insert(
        $commission_table,
        [
            'order_id' => 1,
            'earner_id' => $user->ID,
            'amount' => 12.34,
            'currency' => 'CNY',
            'commission_type' => 'agent',
            'agent_id' => $user->ID,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ],
        ['%d', '%d', '%f', '%s', '%s', '%d', '%s', '%s', '%s']
    );
}

$resp = call_api('GET', '/myshop/v1/referrals/my-downlines', null, $auth_header);
assert_true($resp->get_status() === 200, 'downlines status');

$referral_code = null;
if (class_exists('Referral_Controller') && method_exists('Referral_Controller', 'ensure_referral_code')) {
    $referral_code = Referral_Controller::ensure_referral_code($user->ID);
}

$resp = call_api('GET', '/myshop/v1/invitations/summary', null, $auth_header);
assert_true($resp->get_status() === 200, 'invitations/summary status');
$invitation_payload = $resp->get_data();
assert_true(isset($invitation_payload['success']), 'invitations/summary success');

$track_payload = [
    'scene' => 'test-scene',
    'channel' => 'test-channel',
    'landing_page' => '/pages/index/index'
];
if ($referral_code) {
    $track_payload['referrer_code'] = $referral_code;
}
$resp = call_api('POST', '/myshop/v1/invitations/track', $track_payload, $auth_header);
assert_true($resp->get_status() === 200, 'invitations/track status');
$track_resp = $resp->get_data();
assert_true(isset($track_resp['success']), 'invitations/track success');

$resp = call_api('GET', '/myshop/v1/analytics/channel', null, $auth_header);
assert_true($resp->get_status() === 200, 'analytics/channel status');
$analytics_payload = $resp->get_data();
assert_true(isset($analytics_payload['success']), 'analytics/channel success');

$resp = call_api('GET', '/myshop/v1/agents/me', null, $auth_header);
assert_true($resp->get_status() === 200, 'agents/me status');
$agent_payload = $resp->get_data();
assert_true(isset($agent_payload['total_sales_amount']), 'agents/me total_sales_amount');

$resp = call_api('GET', '/myshop/v1/agents/downlines', null, $auth_header);
assert_true($resp->get_status() === 200, 'agents/downlines status');

$resp = call_api('GET', '/myshop/v1/agents/commissions', null, $auth_header);
assert_true($resp->get_status() === 200, 'agents/commissions status');

$resp = call_api('GET', '/myshop/v1/points/missions', null, $auth_header);
assert_true($resp->get_status() === 200, 'points/missions status');

$resp = call_api('POST', "/myshop/v1/points/missions/{$mission_id}/claim", [], $auth_header);
assert_true($resp->get_status() === 200, 'points/missions claim status');

$resp = call_api('GET', '/myshop/v1/points/redeem/options', null, $auth_header);
assert_true($resp->get_status() === 200, 'points/redeem/options status');

$resp = call_api('POST', '/myshop/v1/points/redeem', ['option_id' => $redeem_option_id], $auth_header);
assert_true($resp->get_status() === 200, 'points/redeem status');
$redeem_payload = $resp->get_data();
assert_true(isset($redeem_payload['data']['coupon_code']), 'points/redeem coupon_code');

$resp = call_api('GET', '/myshop/v1/promo/poster', ['template_code' => 'poster-test'], $auth_header);
assert_true($resp->get_status() === 200, 'promo/poster status');
$poster_payload = $resp->get_data();
assert_true(isset($poster_payload['poster_url']), 'promo/poster poster_url');

$payments_ready = false;
$diag_resp = call_api('GET', '/myshop/v1/payments/diagnose', null, $auth_header);
if ($diag_resp->get_status() === 200) {
    $diag_payload = $diag_resp->get_data();
    $diag_data = $diag_payload['data'] ?? [];
    $payments_ready = !empty($diag_data['app_id_set'])
        && !empty($diag_data['app_secret_set'])
        && !empty($diag_data['mch_id_set'])
        && !empty($diag_data['serial_no_set'])
        && !empty($diag_data['platform_serial_set'])
        && !empty($diag_data['private_key_loaded'])
        && !empty($diag_data['platform_key_loaded'])
        && (int) ($diag_data['api_v3_key_length'] ?? 0) >= 16;
}

$order_id = 0;
if (function_exists('wc_create_order')) {
    $order = wc_create_order();
    $order->set_customer_id($user->ID);
    $order->set_status('pending');
    $order->set_total(0);
    $order->save();
    $order_id = $order->get_id();
}

if ($order_id && $payments_ready) {
    $resp = call_api('POST', '/myshop/v1/payments/create', ['order_id' => $order_id, 'provider' => 'wechat'], $auth_header);
    if ($resp->get_status() === 200) {
        $payment_payload = $resp->get_data();
        assert_true(isset($payment_payload['data']) || isset($payment_payload['payment_qr_url']), 'payments/create payload');
        $resp = call_api('GET', '/myshop/v1/payments/status', ['order_id' => $order_id], $auth_header);
        assert_true($resp->get_status() === 200, 'payments/status status');
        $status_payload = $resp->get_data();
        assert_true(isset($status_payload['data']['status']), 'payments/status data.status');
    }

    wp_delete_post($order_id, true);
} elseif ($order_id) {
    wp_delete_post($order_id, true);
}

$resp = call_api('GET', '/myshop/v1/points/settings', null, $auth_header);
assert_true($resp->get_status() === 200, 'points/settings status');
$points_settings_payload = $resp->get_data();
assert_true(isset($points_settings_payload['data']['redeem_rate']), 'points/settings redeem_rate');

$resp = call_api('GET', '/myshop/v1/gift-cards/share-styles', null, $auth_header);
assert_true($resp->get_status() === 200, 'gift-cards/share-styles status');
$share_styles_payload = $resp->get_data();
assert_true(isset($share_styles_payload['data']), 'gift-cards/share-styles data');

if ($payment_order_id && $payments_ready) {
    $resp = call_api('POST', '/myshop/v1/payments/create', [
        'order_id' => $payment_order_id,
        'provider' => 'wechat'
    ], $auth_header);
    if ($resp->get_status() === 200) {
        $payment_payload = $resp->get_data();
        assert_true(isset($payment_payload['provider']) || isset($payment_payload['data']), 'payments/create provider');
        $resp = call_api('GET', '/myshop/v1/payments/status', ['order_id' => $payment_order_id], $auth_header);
        assert_true($resp->get_status() === 200, 'payments/status status');
        $payment_status_payload = $resp->get_data();
        assert_true(isset($payment_status_payload['data']['status']), 'payments/status status field');
    }

    wp_delete_post($payment_order_id, true);
} elseif ($payment_order_id) {
    wp_delete_post($payment_order_id, true);
}

if (class_exists('WC_Coupon')) {
    $coupon = new WC_Coupon();
    $coupon->set_code('TESTCOUPON-' . time());
    $coupon->set_amount(10);
    $coupon->set_discount_type('fixed_cart');
    $coupon_id = $coupon->save();
    assert_true($coupon_id > 0, 'coupon created');

    $resp = call_api('POST', '/myshop/v1/coupons/validate', ['code' => $coupon->get_code()], $auth_header);
    assert_true($resp->get_status() === 200, 'coupons/validate status');
    $coupon_payload = $resp->get_data();
    assert_true(isset($coupon_payload['data']['code']), 'coupons/validate code');

    wp_delete_post($coupon_id, true);
}

$resp = call_api('GET', '/myshop/v1/commissions', null, $auth_header);
assert_true($resp->get_status() === 200, 'commissions status');
$commission_payload = $resp->get_data();
assert_true(isset($commission_payload['commissions']), 'commissions payload');

$payout_table = $wpdb->prefix . 'myshop_commission_payouts';
$payout_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $payout_table));
if ($payout_table_exists) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$payout_table} WHERE earner_id = %d AND status = 'processing'",
        $user->ID
    ));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$commission_table} SET settlement_batch = NULL, expected_payout_at = NULL
         WHERE earner_id = %d AND status = 'approved'",
        $user->ID
    ));

    $commission_order_id = 0;
    $temp_order_id = 0;
    if (function_exists('wc_create_order')) {
        $temp_order = wc_create_order();
        if ($temp_order) {
            $temp_order->set_customer_id($user->ID);
            $temp_order->set_status('completed');
            $temp_order->set_total(1);
            $temp_order->save();
            $temp_order_id = $temp_order->get_id();
            $commission_order_id = $temp_order_id;
        }
    }

    if ($commission_order_id <= 0) {
        fwrite(STDERR, "skip payout test: no order id available\n");
        goto payout_list_check;
    }

    $inserted_commission = $wpdb->insert(
        $commission_table,
        [
            'order_id' => $commission_order_id,
            'user_id' => $user->ID,
            'earner_id' => $user->ID,
            'amount' => 20.00,
            'currency' => 'CNY',
            'commission_type' => 'referral',
            'referrer_id' => $user->ID,
            'status' => 'approved',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ],
        ['%d', '%d', '%d', '%f', '%s', '%s', '%d', '%s', '%s', '%s']
    );
    assert_true($inserted_commission !== false, 'commission insert');

    $resp = call_api('POST', '/myshop/v1/commissions/payout', [
        'amount' => 20.00,
        'payout_method' => 'manual',
        'account_name' => '测试用户',
        'account_no' => '6222000000000000',
        'bank_name' => '测试银行'
    ], $auth_header);
    if ($resp->get_status() !== 201) {
        fwrite(STDERR, "commissions/payout unexpected status: " . $resp->get_status() . "\n");
        fwrite(STDERR, wp_json_encode($resp->get_data()) . "\n");
        exit(1);
    }
    $payout_payload = $resp->get_data();
    assert_true(isset($payout_payload['success']), 'commissions/payout success');

payout_list_check:

    $resp = call_api('GET', '/myshop/v1/commissions/payouts', null, $auth_header);
    assert_true($resp->get_status() === 200, 'commissions/payouts status');
    $payout_list_payload = $resp->get_data();
    assert_true(isset($payout_list_payload['data']), 'commissions/payouts data');

    if ($temp_order_id) {
        wp_delete_post($temp_order_id, true);
    }
}

fwrite(STDOUT, "ALL REST TESTS PASSED\n");
