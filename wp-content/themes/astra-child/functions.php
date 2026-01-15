<?php
// === Astra Child Theme functions and definitions ===

// 测试：确认 functions.php 被加载
add_action('admin_notices', function() {
    echo '<div class="notice notice-success"><p><strong>✅ functions.php 加载成功！</strong></p></div>';
});

// 继承父主题样式
add_action('wp_enqueue_scripts', 'astra_child_enqueue_styles');
function astra_child_enqueue_styles() {
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');
}

// functions.php
add_action('admin_menu', 'myshop_add_config_sync_page');
function myshop_add_config_sync_page() {
    add_options_page(
        '配置同步',
        '配置同步',
        'manage_options',
        'myshop-config-sync',
        'myshop_config_sync_page_callback'
    );
}

function myshop_config_sync_page_callback() {
    require_once get_stylesheet_directory() . '/admin-sync.php';
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
            if (translated) return;

            const shippingEl = document.querySelector('.wcf-ic-shipping-package-name');
            if (shippingEl && shippingEl.textContent.trim() === 'Shipping') {
                shippingEl.textContent = '配送';
                translated = true;
            }

            const welcomeEl = document.querySelector('.wcf-logged-in-customer-info');
            if (welcomeEl && welcomeEl.textContent.includes('Welcome Back')) {
                welcomeEl.innerHTML = welcomeEl.innerHTML.replace(
                    /^(\\s*)Welcome Back(\\s+)/,
                    '$1欢迎回来$2'
                );
                translated = true;
            }

            const thankYou = document.querySelector('.wcf-ic-status h2');
            if (thankYou && thankYou.textContent.includes('Thank you')) {
                thankYou.innerHTML = thankYou.innerHTML.replace(
                    /Thank you, ([^!]+)!/,
                    '感谢您，$1！'
                );
                translated = true;
            }

            const orderUpdates = document.querySelector('.woocommerce-order-status');
            if (orderUpdates && orderUpdates.textContent.trim() === 'Order Updates') {
                orderUpdates.textContent = '订单更新';
                translated = true;
            }

            const continueBtn = document.querySelector('.wcf-ic-button--ty');
            if (continueBtn && continueBtn.textContent.includes('Continue Shopping')) {
                continueBtn.textContent = '欢迎继续购物';
                translated = true;
            }

            const orderNumberEl = document.querySelector('.wcf-ic-status p');
            if (orderNumberEl && orderNumberEl.textContent.trim().startsWith('Order #')) {
                orderNumberEl.textContent = orderNumberEl.textContent.replace(/^Order #/, '订单 #');
                translated = true;
            }
        }

        translateTexts();
        const observer = new MutationObserver(translateTexts);
        observer.observe(document.body, { childList: true, subtree: true });
        setTimeout(translateTexts, 2000);
    })();
    </script>
    <?php
}