<?php

class Payment_Controller {
    // ✅ 仅支持微信支付，已移除 offline 扫码支付
    private const SUPPORTED_PROVIDERS = ['wechat'];
    private const WECHAT_API_BASE = 'https://api.mch.weixin.qq.com';

    public static function register_routes() {
        register_rest_route('myshop/v1', '/payments/create', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'create'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'order_id' => ['required' => true, 'type' => 'integer'],
                'provider' => ['required' => false, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/payments/status', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'status'],
            'permission_callback' => ['MyShop_Auth', 'check_permission'],
            'args' => [
                'order_id' => ['required' => true, 'type' => 'integer'],
                'provider' => ['required' => false, 'type' => 'string']
            ]
        ]);

        register_rest_route('myshop/v1', '/payments/notify/wechat', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'notify_wechat'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('myshop/v1', '/payments/notify/wechat-refund', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'notify_wechat_refund'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('myshop/v1', '/payments/diagnose', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'diagnose'],
            'permission_callback' => ['MyShop_Auth', 'check_permission']
        ]);
    }

    public static function create($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            self::log_debug_always('payment create auth failed', [
                'error' => $user->get_error_message(),
                'data' => $user->get_error_data(),
                'host' => $_SERVER['HTTP_HOST'] ?? '',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'home_url' => home_url(),
                'site_url' => site_url()
            ]);
            return $user;
        }

        $params = $request->get_json_params();
        $order_id = isset($params['order_id']) ? absint($params['order_id']) : 0;
        // ✅ 默认使用微信支付
        $provider = isset($params['provider']) ? sanitize_key($params['provider']) : 'wechat';
        self::log_debug_always('payment create called', [
            'order_id' => $order_id,
            'provider' => $provider,
            'user_id' => $user->ID,
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'home_url' => home_url(),
            'site_url' => site_url(),
            'wp_content_dir' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
            'temp_dir' => sys_get_temp_dir()
        ]);

        if (!$order_id) {
            return new WP_Error('missing_order_id', '缺少订单 ID', ['status' => 400]);
        }

        // ✅ 强制使用微信支付
        if ($provider === '' || $provider === 'offline') {
            $provider = 'wechat';
        }

        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return new WP_Error('payment_method_not_supported', '仅支持微信支付', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', '订单不存在', ['status' => 404]);
        }

        if ((int) $order->get_customer_id() !== (int) $user->ID) {
            return new WP_Error('unauthorized', '无权操作此订单', ['status' => 403]);
        }

        $intent_id = $order->get_meta('_myshop_payment_intent_id', true);
        if (!$intent_id) {
            $intent_id = 'pay_' . uniqid('', true);
            $order->update_meta_data('_myshop_payment_intent_id', $intent_id);
        }
        $order->update_meta_data('_myshop_payment_provider', $provider);
        $order->update_meta_data('_myshop_payment_status', 'pending');
        $order->save();

        $force_debug = self::debug_enabled() || self::debug_requested($request);
        
        // ✅ 已移除offline扫码支付逻辑，仅支持微信支付
        if (!self::wechat_ready()) {
            return new WP_Error('wechat_not_configured', '微信支付未配置', ['status' => 400]);
        }

        return self::create_wechat_payment($order, $user, $force_debug);
    }

    public static function status($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $order_id = absint($request->get_param('order_id'));
        if (!$order_id) {
            return new WP_Error('missing_order_id', '缺少订单 ID', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', '订单不存在', ['status' => 404]);
        }

        if ((int) $order->get_customer_id() !== (int) $user->ID) {
            return new WP_Error('unauthorized', '无权访问此订单', ['status' => 403]);
        }

        $provider = sanitize_key($request->get_param('provider') ?: '');
        $stored_provider = $order->get_meta('_myshop_payment_provider', true);
        if ($provider === '' && $stored_provider) {
            $provider = $stored_provider;
        }

        $status = self::resolve_payment_status($order);
        $paid_at = $order->get_date_paid() ? $order->get_date_paid()->date('c') : null;

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'order_id' => $order->get_id(),
                'provider' => $provider ?: null,
                'status' => $status,
                'paid_at' => $paid_at
            ]
        ]);
    }

    public static function diagnose($request) {
        $app_id = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : (defined('MYSHOP_WECHAT_APP_ID') ? MYSHOP_WECHAT_APP_ID : '');
        $secret = defined('MYSHOP_MINIAPP_APP_SECRET') ? MYSHOP_MINIAPP_APP_SECRET : '';
        $private_key = self::load_private_key();
        $platform_key = self::load_platform_public_key();
        $api_v3_key = defined('MYSHOP_WECHAT_API_V3_KEY') ? MYSHOP_WECHAT_API_V3_KEY : '';

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'app_id_set' => $app_id !== '',
                'app_secret_set' => $secret !== '',
                'mch_id_set' => defined('MYSHOP_WECHAT_MCH_ID'),
                'serial_no_set' => defined('MYSHOP_WECHAT_SERIAL_NO'),
                'platform_serial_set' => defined('MYSHOP_WECHAT_PLATFORM_SERIAL'),
                'api_v3_key_length' => is_string($api_v3_key) ? strlen($api_v3_key) : 0,
                'private_key_loaded' => $private_key ? true : false,
                'platform_key_loaded' => $platform_key ? true : false,
                'openssl_available' => function_exists('openssl_pkey_get_private'),
                'notify_url' => home_url('/wp-json/myshop/v1/payments/notify/wechat'),
                'home_url' => home_url(),
                'site_url' => site_url(),
                'plugin_file' => __FILE__
            ]
        ]);
    }

    public static function notify_wechat($request) {
        // 记录所有回调请求，方便调试
        self::log_debug_always('wechat notify received', [
            'headers' => [
                'signature' => $request->get_header('wechatpay-signature'),
                'timestamp' => $request->get_header('wechatpay-timestamp'),
                'nonce' => $request->get_header('wechatpay-nonce'),
                'serial' => $request->get_header('wechatpay-serial')
            ],
            'body_length' => strlen($request->get_body()),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        if (!self::wechat_ready()) {
            return new WP_Error('wechat_not_configured', '微信支付未配置', ['status' => 400]);
        }

        $body = $request->get_body();
        if ($body === '') {
            return new WP_Error('invalid_payload', '回调体为空', ['status' => 400]);
        }

        $headers = [
            'wechatpay-signature' => $request->get_header('wechatpay-signature'),
            'wechatpay-timestamp' => $request->get_header('wechatpay-timestamp'),
            'wechatpay-nonce' => $request->get_header('wechatpay-nonce'),
            'wechatpay-serial' => $request->get_header('wechatpay-serial')
        ];

        foreach (['wechatpay-signature', 'wechatpay-timestamp', 'wechatpay-nonce'] as $required) {
            if (empty($headers[$required])) {
                self::log_debug_always('wechat notify missing header', ['missing' => $required]);
                return new WP_Error('wechatpay_missing_header', '缺少微信支付回调头', ['status' => 400]);
            }
        }

        if (defined('MYSHOP_WECHAT_PLATFORM_SERIAL') && $headers['wechatpay-serial']) {
            if ($headers['wechatpay-serial'] !== MYSHOP_WECHAT_PLATFORM_SERIAL) {
                self::log_debug_always('wechat notify serial mismatch', [
                    'expected' => MYSHOP_WECHAT_PLATFORM_SERIAL,
                    'received' => $headers['wechatpay-serial']
                ]);
                return new WP_Error('wechatpay_invalid_serial', '微信支付平台证书序列号不匹配', ['status' => 400]);
            }
        }

        if (!self::verify_wechat_signature($headers['wechatpay-timestamp'], $headers['wechatpay-nonce'], $body, $headers['wechatpay-signature'])) {
            self::log_debug_always('wechat notify signature verify failed');
            return new WP_Error('wechatpay_invalid_signature', '微信支付验签失败', ['status' => 400]);
        }

        self::log_debug_always('wechat notify signature verified');

        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['resource'])) {
            self::log_debug_always('wechat notify invalid payload', ['payload_empty' => empty($payload)]);
            return new WP_Error('wechatpay_invalid_payload', '微信支付回调格式错误', ['status' => 400]);
        }

        $resource = $payload['resource'];
        $decrypted = self::decrypt_wechat_resource($resource);
        if (is_wp_error($decrypted)) {
            self::log_debug_always('wechat notify decrypt failed', ['error' => $decrypted->get_error_message()]);
            return $decrypted;
        }

        self::log_debug_always('wechat notify decrypted', [
            'out_trade_no' => $decrypted['out_trade_no'] ?? '',
            'trade_state' => $decrypted['trade_state'] ?? '',
            'transaction_id' => $decrypted['transaction_id'] ?? ''
        ]);

        $out_trade_no = $decrypted['out_trade_no'] ?? '';
        $trade_state = $decrypted['trade_state'] ?? '';
        $transaction_id = $decrypted['transaction_id'] ?? '';
        $total = isset($decrypted['amount']['total']) ? (int) $decrypted['amount']['total'] : null;
        $payer_openid = $decrypted['payer']['openid'] ?? '';

        $order = self::find_order_by_out_trade_no($out_trade_no);
        if (!$order) {
            self::log_debug_always('wechat notify order not found', ['out_trade_no' => $out_trade_no]);
            return new WP_Error('order_not_found', '订单不存在', ['status' => 404]);
        }

        if ($total !== null) {
            $expected = (int) round((float) $order->get_total() * 100);
            if ($expected !== $total) {
                self::log_debug_always('wechat notify amount mismatch', ['expected' => $expected, 'received' => $total]);
                return new WP_Error('wechatpay_amount_mismatch', '支付金额不一致', ['status' => 400]);
            }
        }

        self::log_debug_always('wechat notify processing payment', [
            'order_id' => $order->get_id(),
            'trade_state' => $trade_state
        ]);

        if ($trade_state === 'SUCCESS') {
            if (!$order->get_date_paid()) {
                // 只在有有效transaction_id时才传递，否则传递空字符串
                $order->payment_complete($transaction_id ? $transaction_id : '');
                self::log_debug_always('wechat notify payment completed', ['order_id' => $order->get_id()]);
            } else {
                self::log_debug_always('wechat notify already paid', ['order_id' => $order->get_id()]);
            }
            $order->update_meta_data('_myshop_payment_status', 'paid');

            // ✅ 兜底：若 payment_complete 未更新状态，强制推进到 processing
            // 说明：个别环境下 WooCommerce 未触发网关状态切换，导致后台仍显示“待付款”。
            if ($order->get_status() === 'pending') {
                $order->update_status('processing', '微信支付成功，自动更新订单状态');
            }

            // 订单完成状态由 WooCommerce payment_complete 规则与过滤器统一处理
        } elseif (in_array($trade_state, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
            $order->update_meta_data('_myshop_payment_status', 'failed');
            self::log_debug_always('wechat notify payment failed', ['order_id' => $order->get_id(), 'state' => $trade_state]);
        } else {
            $order->update_meta_data('_myshop_payment_status', 'pending');
            self::log_debug_always('wechat notify payment pending', ['order_id' => $order->get_id(), 'state' => $trade_state]);
        }

        // 只在transaction_id有效且不为空时才保存
        if ($transaction_id && $transaction_id !== '') {
            $order->update_meta_data('_myshop_wechat_transaction_id', $transaction_id);
        }
        if ($payer_openid) {
            $order->update_meta_data('_myshop_wechat_payer_openid', $payer_openid);
        }
        $order->save();

        self::log_debug_always('wechat notify success', ['order_id' => $order->get_id()]);

        return rest_ensure_response([
            'code' => 'SUCCESS',
            'message' => 'OK'
        ]);
    }

    public static function notify_wechat_refund($request) {
        if (!self::wechat_ready()) {
            return new WP_Error('wechat_not_configured', '微信支付未配置', ['status' => 400]);
        }

        $body = $request->get_body();
        if ($body === '') {
            return new WP_Error('invalid_payload', '回调体为空', ['status' => 400]);
        }

        $headers = [
            'wechatpay-signature' => $request->get_header('wechatpay-signature'),
            'wechatpay-timestamp' => $request->get_header('wechatpay-timestamp'),
            'wechatpay-nonce' => $request->get_header('wechatpay-nonce'),
            'wechatpay-serial' => $request->get_header('wechatpay-serial')
        ];

        foreach (['wechatpay-signature', 'wechatpay-timestamp', 'wechatpay-nonce'] as $required) {
            if (empty($headers[$required])) {
                return new WP_Error('wechatpay_missing_header', '缺少微信支付回调头', ['status' => 400]);
            }
        }

        if (defined('MYSHOP_WECHAT_PLATFORM_SERIAL') && $headers['wechatpay-serial']) {
            if ($headers['wechatpay-serial'] !== MYSHOP_WECHAT_PLATFORM_SERIAL) {
                return new WP_Error('wechatpay_invalid_serial', '微信支付平台证书序列号不匹配', ['status' => 400]);
            }
        }

        if (!self::verify_wechat_signature($headers['wechatpay-timestamp'], $headers['wechatpay-nonce'], $body, $headers['wechatpay-signature'])) {
            return new WP_Error('wechatpay_invalid_signature', '微信支付验签失败', ['status' => 400]);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['resource'])) {
            return new WP_Error('wechatpay_invalid_payload', '微信支付回调格式错误', ['status' => 400]);
        }

        $resource = $payload['resource'];
        $decrypted = self::decrypt_wechat_resource($resource);
        if (is_wp_error($decrypted)) {
            return $decrypted;
        }

        $out_refund_no = $decrypted['out_refund_no'] ?? '';
        $refund_status = $decrypted['refund_status'] ?? '';

        if ($out_refund_no === '') {
            return new WP_Error('wechatpay_invalid_payload', '退款回调缺少 out_refund_no', ['status' => 400]);
        }

        $order = self::find_order_by_refund_no($out_refund_no);
        if (!$order) {
            return new WP_Error('order_not_found', '订单不存在', ['status' => 404]);
        }

        if ($refund_status === 'SUCCESS') {
            $order->update_meta_data('_myshop_wechat_refund_status', 'success');
            $order->update_meta_data('_myshop_return_status', 'refunded');
            if ($order->get_status() !== 'refunded') {
                $order->update_status('refunded', '微信退款成功');
            }
            $order->add_order_note('微信退款成功');
            $order->save();
        } elseif ($refund_status === 'PROCESSING') {
            $order->update_meta_data('_myshop_wechat_refund_status', 'processing');
            $order->add_order_note('微信退款处理中');
            $order->save();
        } elseif ($refund_status === 'CLOSED' || $refund_status === 'ABNORMAL') {
            $order->update_meta_data('_myshop_wechat_refund_status', strtolower($refund_status));
            $order->add_order_note('微信退款失败：' . $refund_status);
            $order->save();
        }

        return rest_ensure_response([
            'code' => 'SUCCESS',
            'message' => 'OK'
        ]);
    }

    public static function refund_wechat_order($order, $reason = '') {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', '订单无效');
        }

        if (!self::wechat_ready()) {
            return new WP_Error('wechat_not_configured', '微信支付未配置');
        }

        $transaction_id = $order->get_meta('_myshop_wechat_transaction_id', true);
        if (!$transaction_id) {
            $transaction_id = $order->get_transaction_id();
        }
        $out_trade_no = $order->get_meta('_myshop_wechat_out_trade_no', true);

        if (!$transaction_id && !$out_trade_no) {
            return new WP_Error('missing_trade_no', '缺少 transaction_id 或 out_trade_no');
        }

        $total = (int) round((float) $order->get_total() * 100);
        if ($total < 1) {
            return new WP_Error('invalid_amount', '退款金额无效');
        }

        $out_refund_no = 'RF' . $order->get_id() . gmdate('YmdHis') . wp_rand(1000, 9999);
        $notify_url = home_url('/wp-json/myshop/v1/payments/notify/wechat-refund');

        $payload = [
            'out_refund_no' => $out_refund_no,
            'amount' => [
                'refund' => $total,
                'total' => $total,
                'currency' => 'CNY'
            ],
            'notify_url' => $notify_url
        ];

        if ($transaction_id) {
            $payload['transaction_id'] = $transaction_id;
        } else {
            $payload['out_trade_no'] = $out_trade_no;
        }

        if ($reason) {
            $payload['reason'] = $reason;
        }

        $response = self::wechat_request('POST', '/v3/refund/domestic/refunds', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        $order->update_meta_data('_myshop_wechat_refund_no', $out_refund_no);
        if (!empty($response['refund_id'])) {
            $order->update_meta_data('_myshop_wechat_refund_id', $response['refund_id']);
        }
        if (!empty($response['status'])) {
            $order->update_meta_data('_myshop_wechat_refund_status', strtolower($response['status']));
        }
        $order->save();

        return $response;
    }

    private static function resolve_payment_status($order) {
        if (!$order instanceof WC_Order) {
            return 'pending';
        }

        if ($order->get_date_paid()) {
            return 'paid';
        }

        $status = $order->get_status();
        if (in_array($status, ['processing', 'completed'], true)) {
            return 'paid';
        }
        if (in_array($status, ['cancelled', 'refunded'], true)) {
            return 'canceled';
        }
        if ($status === 'failed') {
            return 'failed';
        }
        return 'pending';
    }

    private static function create_wechat_payment($order, $user, $force_debug = false) {
        $openid = get_user_meta($user->ID, '_wechat_openid', true);
        if (!$openid) {
            self::log_debug('wechat pay missing openid', [
                'order_id' => $order->get_id(),
                'user_id' => $user->ID
            ]);
            self::log_debug_always('wechat pay missing openid', [
                'order_id' => $order->get_id(),
                'user_id' => $user->ID
            ]);
            return new WP_Error('missing_openid', '未找到微信 OpenID', ['status' => 400]);
        }

        $amount = (int) round((float) $order->get_total() * 100);
        if ($amount < 1) {
            return new WP_Error('invalid_amount', '订单金额无效', ['status' => 400]);
        }

        $intent_id = $order->get_meta('_myshop_payment_intent_id', true);
        if (!$intent_id) {
            $intent_id = 'pay_' . uniqid('', true);
            $order->update_meta_data('_myshop_payment_intent_id', $intent_id);
        }

        $out_trade_no = sprintf(
            'MS%010d%08d%06d',
            $order->get_id(),
            time() % 100000000,
            wp_rand(100000, 999999)
        );
        $order->update_meta_data('_myshop_wechat_out_trade_no', $out_trade_no);
        $order->update_meta_data('_myshop_wechat_payer_openid', $openid);
        $order->update_meta_data('_myshop_payment_provider', 'wechat');
        $order->update_meta_data('_myshop_payment_status', 'pending');
        $order->save();

        $notify_url = home_url('/wp-json/myshop/v1/payments/notify/wechat');
        $pay_appid = defined('MYSHOP_MINIAPP_APP_ID') ? MYSHOP_MINIAPP_APP_ID : MYSHOP_WECHAT_APP_ID;
        $debug_context = [
            'order_id' => $order->get_id(),
            'user_id' => $user->ID,
            'appid' => $pay_appid,
            'mchid' => MYSHOP_WECHAT_MCH_ID,
            'openid' => substr($openid, 0, 6) . '***' . substr($openid, -4),
            'amount' => $amount,
            'out_trade_no' => $out_trade_no,
            'notify_url' => $notify_url,
            'home_url' => home_url(),
            'site_url' => site_url()
        ];
        self::log_debug('wechat pay create start', $debug_context);
        self::log_debug_always('wechat pay create start', $debug_context);
        
        // 获取订单商品信息用于 description
        $items = $order->get_items();
        $item_names = [];
        foreach ($items as $item) {
            $item_names[] = $item->get_name();
        }
        $description = !empty($item_names) 
            ? mb_substr(implode('、', $item_names), 0, 127) 
            : sprintf('订单 #%s', $order->get_order_number());
        
        $payload = [
            'appid' => $pay_appid,
            'mchid' => MYSHOP_WECHAT_MCH_ID,
            'description' => $description,
            'out_trade_no' => $out_trade_no,
            'notify_url' => $notify_url,
            'amount' => [
                'total' => $amount,
                'currency' => 'CNY'
            ],
            'payer' => [
                'openid' => $openid
            ]
        ];

        $endpoint = '/v3/pay/transactions/jsapi';
        $response = self::wechat_request('POST', $endpoint, $payload);
        if (is_wp_error($response)) {
            $fail_context = [
                'order_id' => $order->get_id(),
                'out_trade_no' => $out_trade_no,
                'error' => $response->get_error_message(),
                'data' => $response->get_error_data()
            ];
            self::log_debug('wechat pay create failed', $fail_context);
            self::log_debug_always('wechat pay create failed', $fail_context);
            return $response;
        }

        $prepay_id = $response['prepay_id'] ?? '';
        if (!$prepay_id) {
            $missing_context = [
                'order_id' => $order->get_id(),
                'out_trade_no' => $out_trade_no,
                'response' => $response
            ];
            self::log_debug('wechat pay create missing prepay_id', $missing_context);
            self::log_debug_always('wechat pay create missing prepay_id', $missing_context);
            return new WP_Error('wechatpay_prepay_failed', '微信支付下单失败', ['status' => 500]);
        }

        $nonce_str = wp_generate_password(16, false);
        $timestamp = (string) time();
        $package = 'prepay_id=' . $prepay_id;
        $pay_sign = self::sign_wechatpay(sprintf("%s\n%s\n%s\n%s\n", $pay_appid, $timestamp, $nonce_str, $package));

        if (!$pay_sign) {
            return new WP_Error('wechatpay_sign_failed', '微信支付签名失败', ['status' => 500]);
        }

        $order->update_meta_data('_myshop_wechat_prepay_id', $prepay_id);
        $order->save();
        $success_context = [
            'order_id' => $order->get_id(),
            'out_trade_no' => $out_trade_no,
            'prepay_id' => $prepay_id
        ];
        self::log_debug('wechat pay create success', $success_context);
        self::log_debug_always('wechat pay create success', $success_context);

        $response_payload = [
            'success' => true,
            'order_id' => $order->get_id(),  // ✅ 返回订单ID
            'provider' => 'wechat',
            'payment_payload' => [
                'appId' => $pay_appid,
                'timeStamp' => $timestamp,
                'nonceStr' => $nonce_str,
                'package' => $package,
                'signType' => 'RSA',
                'paySign' => $pay_sign
            ]
        ];
        if ($force_debug) {
            $response_payload['debug'] = self::debug_payload();
            $response_payload['debug_payment'] = [
                'appid' => $pay_appid,
                'mchid' => MYSHOP_WECHAT_MCH_ID,
                'openid_masked' => substr($openid, 0, 6) . '***' . substr($openid, -4),
                'order_id' => $order->get_id(),
                'out_trade_no' => $out_trade_no,
                'notify_url' => $notify_url,
                'prepay_id' => $prepay_id
            ];
        }
        return rest_ensure_response($response_payload);
    }

    private static function build_offline_payload() {
        $payment_qr_url = '';
        $customer_service_qr = '';

        if (function_exists('WC')) {
            $gateways = WC()->payment_gateways->payment_gateways();
            if (isset($gateways['cod']) && $gateways['cod']->enabled === 'yes') {
                $description = $gateways['cod']->description;
                preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $description, $matches);
                $image_urls = $matches[1] ?? [];

                if (!empty($image_urls)) {
                    $payment_qr_url = esc_url_raw($image_urls[0]);
                    $customer_service_qr = count($image_urls) > 1 ? esc_url_raw($image_urls[1]) : $payment_qr_url;
                }
            }
        }

        if (!$payment_qr_url) {
            $config = get_option('myshop_public_config', []);
            $payment_qr_url = $config['payment_qr_url'] ?? '';
            $customer_service_qr = $config['customer_service_qr'] ?? '';
        }

        if (!$payment_qr_url) {
            $upload_dir = wp_upload_dir();
            $payment_qr_url = $upload_dir['baseurl'] . '/default-pay-qr.jpg';
            $customer_service_qr = $upload_dir['baseurl'] . '/default-service-qr.jpg';
        }

        return [
            'payment_qr_url' => $payment_qr_url,
            'customer_service_qr' => $customer_service_qr ?: $payment_qr_url,
            'message' => '请扫码向客服付款，并添加企业微信发送付款截图，我们将尽快为您发货。'
        ];
    }

    private static function wechat_request($method, $path, $payload) {
        $body = wp_json_encode($payload);
        $timestamp = (string) time();
        $nonce = wp_generate_password(16, false);
        $message = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = self::sign_wechatpay($message);
        if (!$signature) {
            return new WP_Error('wechatpay_sign_failed', '微信支付签名失败', ['status' => 500]);
        }

        $authorization = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            MYSHOP_WECHAT_MCH_ID,
            $nonce,
            $timestamp,
            MYSHOP_WECHAT_SERIAL_NO,
            $signature
        );

        $response = wp_remote_request(self::WECHAT_API_BASE . $path, [
            'method' => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => $authorization
            ],
            'body' => $body
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($data) ? ($data['message'] ?? '微信支付请求失败') : '微信支付请求失败';
            return new WP_Error('wechatpay_request_failed', $message, ['status' => $status]);
        }

        return is_array($data) ? $data : [];
    }

    private static function sign_wechatpay($message) {
        $private_key = self::load_private_key();
        if (!$private_key) {
            return false;
        }

        $signature = '';
        $ok = openssl_sign($message, $signature, $private_key, 'sha256WithRSAEncryption');
        if (!$ok) {
            return false;
        }
        return base64_encode($signature);
    }

    private static function verify_wechat_signature($timestamp, $nonce, $body, $signature) {
        $public_key = self::load_platform_public_key();
        if (!$public_key) {
            return false;
        }

        $message = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        return openssl_verify($message, base64_decode($signature), $public_key, 'sha256WithRSAEncryption') === 1;
    }

    private static function decrypt_wechat_resource($resource) {
        // 记录原始resource结构
        self::log_debug_always('wechat notify decrypt resource', [
            'has_ciphertext' => isset($resource['ciphertext']),
            'has_nonce' => isset($resource['nonce']),
            'has_associated_data' => isset($resource['associated_data']),
            'has_algorithm' => isset($resource['algorithm']),
            'keys' => array_keys($resource)
        ]);
        
        $ciphertext = $resource['ciphertext'] ?? '';
        $nonce = $resource['nonce'] ?? '';
        $associated_data = $resource['associated_data'] ?? '';

        if ($ciphertext === '' || $nonce === '') {
            self::log_debug_always('wechat notify decrypt missing fields', [
                'ciphertext_empty' => $ciphertext === '',
                'nonce_empty' => $nonce === ''
            ]);
            return new WP_Error('wechatpay_invalid_resource', '回调数据不完整', ['status' => 400]);
        }

        $key = MYSHOP_WECHAT_API_V3_KEY;
        if (!is_string($key) || strlen($key) !== 32) {
            self::log_debug_always('wechat notify api key invalid', ['key_length' => is_string($key) ? strlen($key) : 'not_string']);
            return new WP_Error('wechatpay_invalid_key', 'APIv3 密钥无效', ['status' => 500]);
        }

        // 微信支付V3: ciphertext是base64编码的，解码后包含密文+tag(16字节)
        $ciphertext_raw = base64_decode($ciphertext);
        if ($ciphertext_raw === false || strlen($ciphertext_raw) < 16) {
            self::log_debug_always('wechat notify ciphertext decode failed');
            return new WP_Error('wechatpay_invalid_ciphertext', '密文格式错误', ['status' => 400]);
        }

        // 提取tag(最后16字节)和实际密文
        $tag = substr($ciphertext_raw, -16);
        $ciphertext_data = substr($ciphertext_raw, 0, -16);

        self::log_debug_always('wechat notify decrypt attempt', [
            'ciphertext_len' => strlen($ciphertext_data),
            'tag_len' => strlen($tag),
            'nonce_len' => strlen($nonce)
        ]);

        $plain = openssl_decrypt(
            $ciphertext_data,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associated_data
        );

        if ($plain === false) {
            $error = openssl_error_string();
            self::log_debug_always('wechat notify decrypt openssl failed', ['openssl_error' => $error]);
            return new WP_Error('wechatpay_decrypt_failed', '回调解密失败', ['status' => 400]);
        }

        self::log_debug_always('wechat notify decrypt success', ['plain_length' => strlen($plain)]);

        $data = json_decode($plain, true);
        if (!is_array($data)) {
            self::log_debug_always('wechat notify decrypt json invalid');
            return new WP_Error('wechatpay_invalid_decrypted', '回调解密结果异常', ['status' => 400]);
        }

        return $data;
    }

    private static function find_order_by_refund_no($refund_no) {
        $refund_no = sanitize_text_field($refund_no);
        if ($refund_no === '') {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => '_myshop_wechat_refund_no',
            'meta_value' => $refund_no
        ]);

        return !empty($orders) ? $orders[0] : null;
    }

    private static function find_order_by_out_trade_no($out_trade_no) {
        if (!$out_trade_no || !function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'type' => 'shop_order',
            'meta_key' => '_myshop_wechat_out_trade_no',
            'meta_value' => $out_trade_no
        ]);

        return $orders ? $orders[0] : null;
    }

    private static function load_private_key() {
        if (!defined('MYSHOP_WECHAT_PRIVATE_KEY')) {
            return false;
        }

        $raw = MYSHOP_WECHAT_PRIVATE_KEY;
        if (is_string($raw) && file_exists($raw)) {
            $raw = file_get_contents($raw);
        }
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }
        return openssl_pkey_get_private($raw);
    }

    private static function load_platform_public_key() {
        if (defined('MYSHOP_WECHAT_PLATFORM_PUBLIC_KEY')) {
            $raw = MYSHOP_WECHAT_PLATFORM_PUBLIC_KEY;
            if (is_string($raw) && file_exists($raw)) {
                $raw = file_get_contents($raw);
            }
            if (is_string($raw) && trim($raw) !== '') {
                $key = openssl_pkey_get_public($raw);
                if ($key !== false) {
                    return $key;
                }
            }
        }

        if (!defined('MYSHOP_WECHAT_PLATFORM_CERT')) {
            return false;
        }

        $raw = MYSHOP_WECHAT_PLATFORM_CERT;
        if (is_string($raw) && file_exists($raw)) {
            $raw = file_get_contents($raw);
        }
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }

        $cert = openssl_x509_read($raw);
        if ($cert === false) {
            return false;
        }
        return openssl_pkey_get_public($cert);
    }

    private static function wechat_ready() {
        $has_platform_key = defined('MYSHOP_WECHAT_PLATFORM_PUBLIC_KEY') || defined('MYSHOP_WECHAT_PLATFORM_CERT');

        return defined('MYSHOP_WECHAT_MCH_ID')
            && defined('MYSHOP_WECHAT_APP_ID')
            && defined('MYSHOP_WECHAT_SERIAL_NO')
            && defined('MYSHOP_WECHAT_PRIVATE_KEY')
            && defined('MYSHOP_WECHAT_API_V3_KEY')
            && $has_platform_key
            && defined('MYSHOP_WECHAT_PLATFORM_SERIAL');
        }

    private static function log_debug($message, $context = []) {
        if (defined('MYSHOP_AUTH_DEBUG') && MYSHOP_AUTH_DEBUG) {
            $log_file = WP_CONTENT_DIR . '/myshop-payment.log';
            $timestamp = current_time('mysql');
            $payload = is_array($context) ? $context : ['context' => $context];
            $log_message = sprintf(
                "[%s] MyShop Payment: %s %s\n",
                $timestamp,
                $message,
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
            file_put_contents($log_file, $log_message, FILE_APPEND);
        }
    }

    private static function log_debug_always($message, $context = []) {
        $log_files = [];
        if (defined('WP_CONTENT_DIR') && WP_CONTENT_DIR) {
            $log_files[] = rtrim(WP_CONTENT_DIR, '/\\') . '/debug.log';
        }
        if (defined('ABSPATH') && ABSPATH) {
            $log_files[] = rtrim(ABSPATH, '/\\') . '/wp-content/debug.log';
        }
        $log_files[] = rtrim(sys_get_temp_dir(), '/\\') . '/myshop-payment.log';
        $timestamp = current_time('mysql');
        $payload = is_array($context) ? $context : ['context' => $context];
        $log_message = sprintf(
            "[%s] MyShop Payment: %s %s\n",
            $timestamp,
            $message,
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
        foreach ($log_files as $file) {
            if (!$file) {
                continue;
            }
            @file_put_contents($file, $log_message, FILE_APPEND);
        }
        error_log('MyShop Payment: ' . $message);
    }

    private static function debug_enabled() {
        return defined('MYSHOP_AUTH_DEBUG') && MYSHOP_AUTH_DEBUG;
    }

    private static function debug_requested($request) {
        if (!$request) {
            return false;
        }
        $param = $request->get_param('debug');
        if ($param === null) {
            $params = $request->get_json_params();
            $param = $params['debug'] ?? null;
        }
        return $param === 1 || $param === '1' || $param === true || $param === 'true';
    }

    private static function debug_payload() {
        return [
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'server_name' => $_SERVER['SERVER_NAME'] ?? '',
            'home_url' => home_url(),
            'site_url' => site_url(),
            'wp_content_dir' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
            'plugin_file' => __FILE__
        ];
    }
}
