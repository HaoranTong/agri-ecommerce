<?php
/**
 * Astra Child Theme functions and definitions
 */

// 继承父主题样式
add_action( 'wp_enqueue_scripts', 'astra_child_enqueue_styles' );
function astra_child_enqueue_styles() {
    wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );
}

// 动态翻译结账页 & 订单确认页文本（支持 CartFlows）
add_action('wp_footer', 'translate_cartflows_checkout_and_thankyou');
function translate_cartflows_checkout_and_thankyou() {
    if (!is_checkout() && !is_order_received_page()) {
        return;
    }
    ?>
    <script>
    (function() {
        let translated = false;

        function translateTexts() {
            if (translated) return; // 避免重复执行

            // ========== 结账页 ==========
            if (!window.location.href.includes('/checkout/order-received/')) {
                const shippingEl = document.querySelector('.wcf-ic-shipping-package-name');
                if (shippingEl && shippingEl.textContent.trim() === 'Shipping') {
                    shippingEl.textContent = '配送';
                    translated = true;
                }

                const welcomeEl = document.querySelector('.wcf-logged-in-customer-info');
                if (welcomeEl && welcomeEl.textContent.includes('Welcome Back')) {
                    welcomeEl.innerHTML = welcomeEl.innerHTML.replace(
                        /^(\s*)Welcome Back(\s+)/,
                        '$1欢迎回来$2'
                    );
                    translated = true;
                }
            }

            // ========== 订单确认页 ==========
            if (window.location.href.includes('/checkout/order-received/')) {
                // Thank you → 感谢您
                const thankYou = document.querySelector('.wcf-ic-status h2');
                if (thankYou && thankYou.textContent.includes('Thank you')) {
                    thankYou.innerHTML = thankYou.innerHTML.replace(
                        /Thank you, ([^!]+)!/,
                        '感谢您，$1！'
                    );
                    translated = true;
                }

                // Order Updates → 更新订单（注意：您页面显示的是“更新订单”，但原文可能是 "Order Updates"）
                const orderUpdates = document.querySelector('.woocommerce-order-status');
                if (orderUpdates && orderUpdates.textContent.trim() === 'Order Updates') {
                    orderUpdates.textContent = '订单更新';
                    translated = true;
                }

                // Continue Shopping → 欢迎继续购物
                const continueBtn = document.querySelector('.wcf-ic-button--ty');
                if (continueBtn && continueBtn.textContent.includes('Continue Shopping')) {
                    continueBtn.textContent = '欢迎继续购物';
                    translated = true;
                }

                // 🔥 关键修复：Order #8404 → 订单 #8404
                const orderNumberEl = document.querySelector('.wcf-ic-status p');
                if (orderNumberEl && orderNumberEl.textContent.trim().startsWith('Order #')) {
                    orderNumberEl.textContent = orderNumberEl.textContent.replace(/^Order #/, '订单 #');
                    translated = true;
                }
            }
        }

        // 立即执行
        translateTexts();

        // 监听 DOM 变化（应对延迟加载）
        const observer = new MutationObserver(translateTexts);
        observer.observe(document.body, { childList: true, subtree: true });

        // 延迟重试（保险）
        setTimeout(translateTexts, 2000);
    })();
    </script>
    <?php
}
// 禁用 Google Fonts（简洁版）
add_action('wp_enqueue_scripts', function() {
    wp_dequeue_style('astra-google-fonts');
    wp_dequeue_style('google-fonts');
}, 999);
// 移除所有 dns-prefetch 到 fonts.googleapis.com
add_action('template_redirect', function() {
    ob_start(function($buffer) {
        return str_replace(
            '<link rel="dns-prefetch" href="//fonts.googleapis.com">',
            '',
            $buffer
        );
    });
});

// 禁用 Gravatar 头像
add_filter('get_avatar', '__return_false');

// 禁用 Gravatar
add_filter('get_avatar', '__return_false');

// （可选）彻底移除 Google Fonts 引用
add_action('wp_enqueue_scripts', function() {
    wp_dequeue_style('astra-google-fonts');
    wp_dequeue_style('google-fonts');
}, 999);
// 允许本地小程序开发跨域
add_action('init', function() {
  if (isset($_SERVER['HTTP_ORIGIN']) && strpos($_SERVER['HTTP_ORIGIN'], '127.0.0.1') !== false) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
  }
});

// 允许未登录用户访问产品列表（公开读取）
add_filter('woocommerce_rest_api_permissions', function($permissions) {
    return array(
        'read' => true,
        'write' => false,
        'delete' => false
    );
});

// 禁用 REST API 的认证要求（仅限开发环境）
add_filter('woocommerce_rest_authentication_errors', '__return_null');