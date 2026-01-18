<?php

class Payment_Controller {
    private const SUPPORTED_PROVIDERS = ['wechat', 'offline'];
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
    }

    public static function create($request) {
        $user = MyShop_Auth::get_user_from_request($request);
        if (is_wp_error($user)) {
            return $user;
        }

        $params = $request->get_json_params();
        $order_id = isset($params['order_id']) ? absint($params['order_id']) : 0;
        $provider = isset($params['provider']) ? sanitize_key($params['provider']) : 'offline';

        if (!$order_id) {
            return new WP_Error('missing_order_id', '缺少订单 ID', ['status' => 400]);
        }

        if ($provider === '') {
            $provider = 'offline';
        }

        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return new WP_Error('payment_method_not_supported', '不支持的支付方式', ['status' => 400]);
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

        if ($provider === 'offline') {
            $offline_payload = self::build_offline_payload();
            return rest_ensure_response([
                'success' => true,
                'provider' => 'offline',
                'payment_intent_id' => $intent_id,
                'payment_qr_url' => $offline_payload['payment_qr_url'],
                'customer_service_qr' => $offline_payload['customer_service_qr'],
                'message' => $offline_payload['message']
            ]);
        }

        if (!self::wechat_ready()) {
            return new WP_Error('wechat_not_configured', '微信支付未配置', ['status' => 400]);
        }

        return self::create_wechat_payment($order, $user);
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

    private static function create_wechat_payment($order, $user) {
        $openid = get_user_meta($user->ID, '_wechat_openid', true);
        if (!$openid) {
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

        $out_trade_no = sprintf('myshop_%d_%s', $order->get_id(), preg_replace('/[^A-Za-z0-9]/', '', $intent_id));
        $order->update_meta_data('_myshop_wechat_out_trade_no', $out_trade_no);
        $order->update_meta_data('_myshop_payment_provider', 'wechat');
        $order->update_meta_data('_myshop_payment_status', 'pending');
        $order->save();

        $notify_url = home_url('/wp-json/myshop/v1/payments/notify/wechat');
        $payload = [
            'appid' => MYSHOP_WECHAT_APP_ID,
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
            return $response;
        }

        $prepay_id = $response['prepay_id'] ?? '';
        if (!$prepay_id) {
            return new WP_Error('wechatpay_prepay_failed', '微信支付下单失败', ['status' => 500]);
        }

        $nonce_str = wp_generate_password(16, false);
        $timestamp = (string) time();
        $package = 'prepay_id=' . $prepay_id;
        $pay_sign = self::sign_wechatpay(sprintf("%s\n%s\n%s\n%s\n", MYSHOP_WECHAT_APP_ID, $timestamp, $nonce_str, $package));

        if (!$pay_sign) {
            return new WP_Error('wechatpay_sign_failed', '微信支付签名失败', ['status' => 500]);
        }

        $order->update_meta_data('_myshop_wechat_prepay_id', $prepay_id);
        $order->save();

        return rest_ensure_response([
            'success' => true,
            'provider' => 'wechat',
            'payment_payload' => [
                'appId' => MYSHOP_WECHAT_APP_ID,
                'timeStamp' => $timestamp,
                'nonceStr' => $nonce_str,
                'package' => $package,
                'signType' => 'RSA',
                'paySign' => $pay_sign
            ]
        ]);
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
}
