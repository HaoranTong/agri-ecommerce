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

register_shutdown_function(function () use ($old_missions, $old_redeem_options, $old_posters) {
    update_option('myshop_points_missions', $old_missions);
    update_option('myshop_points_redeem_options', $old_redeem_options);
    update_option('myshop_promo_posters', $old_posters);
});

do_action('rest_api_init');

$login_response = call_api('POST', '/myshop/v1/auth/login', ['code' => 'test001']);
assert_true($login_response->get_status() === 200, 'login status');
$login_data = $login_response->get_data();
assert_true(isset($login_data['data']['token']), 'login token');
$token = $login_data['data']['token'];
$auth_header = ['Authorization' => 'Bearer ' . $token];

$user = MyShop_Auth::validate_token($token);
assert_true($user instanceof WP_User, 'user from token');

$mission_id = 'test_mission_' . time();
$redeem_option_id = 'test_option_' . time();

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

$resp = call_api('GET', '/myshop/v1/points/settings', null, $auth_header);
assert_true($resp->get_status() === 200, 'points/settings status');
$points_settings_payload = $resp->get_data();
assert_true(isset($points_settings_payload['data']['redeem_rate']), 'points/settings redeem_rate');

$resp = call_api('GET', '/myshop/v1/gift-cards/share-styles', null, $auth_header);
assert_true($resp->get_status() === 200, 'gift-cards/share-styles status');
$share_styles_payload = $resp->get_data();
assert_true(isset($share_styles_payload['data']), 'gift-cards/share-styles data');

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

fwrite(STDOUT, "ALL REST TESTS PASSED\n");
