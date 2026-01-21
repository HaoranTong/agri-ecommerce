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

        $out_trade_no = $decrypted['out_trade_no'] ?? '';
        $trade_state = $decrypted['trade_state'] ?? '';
        $transaction_id = $decrypted['transaction_id'] ?? '';
        $total = isset($decrypted['amount']['total']) ? (int) $decrypted['amount']['total'] : null;

        $order = self::find_order_by_out_trade_no($out_trade_no);
        if (!$order) {
            return new WP_Error('order_not_found', '订单不存在', ['status' => 404]);
        }

        if ($total !== null) {
            $expected = (int) round((float) $order->get_total() * 100);
            if ($expected !== $total) {
                return new WP_Error('wechatpay_amount_mismatch', '支付金额不一致', ['status' => 400]);
            }
        }

        if ($trade_state === 'SUCCESS') {
            if (!$order->get_date_paid()) {
                $order->payment_complete($transaction_id ?: '');
            }
            $order->update_meta_data('_myshop_payment_status', 'paid');
        } elseif (in_array($trade_state, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
            $order->update_meta_data('_myshop_payment_status', 'failed');
        } else {
            $order->update_meta_data('_myshop_payment_status', 'pending');
        }

        if ($transaction_id) {
            $order->update_meta_data('_myshop_wechat_transaction_id', $transaction_id);
        }
        $order->save();

        return rest_ensure_response([
            'code' => 'SUCCESS',
            'message' => 'OK'
        ]);
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
        $payload = [
            'appid' => $pay_appid,
            'mchid' => MYSHOP_WECHAT_MCH_ID,
            'description' => sprintf('MyShop Order #%s', $order->get_order_number()),
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
        $ciphertext = $resource['ciphertext'] ?? '';
        $nonce = $resource['nonce'] ?? '';
        $associated_data = $resource['associated_data'] ?? '';
        $tag = $resource['tag'] ?? '';

        if ($ciphertext === '' || $nonce === '' || $tag === '') {
            return new WP_Error('wechatpay_invalid_resource', '回调数据不完整', ['status' => 400]);
        }

        $key = MYSHOP_WECHAT_API_V3_KEY;
        if (!is_string($key) || strlen($key) !== 32) {
            return new WP_Error('wechatpay_invalid_key', 'APIv3 密钥无效', ['status' => 500]);
        }

        $ciphertext_raw = base64_decode($ciphertext);
        $plain = openssl_decrypt(
            $ciphertext_raw,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associated_data
        );

        if ($plain === false) {
            return new WP_Error('wechatpay_decrypt_failed', '回调解密失败', ['status' => 400]);
    }

        $data = json_decode($plain, true);
        if (!is_array($data)) {
            return new WP_Error('wechatpay_invalid_decrypted', '回调解密结果异常', ['status' => 400]);
        }

        return $data;
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
