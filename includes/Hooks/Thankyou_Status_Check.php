<?php

namespace WC_P24\Hooks;

use WC_Order;
use WC_P24\Core;

class Thankyou_Status_Check
{
    public function __construct()
    {
        add_filter('woocommerce_thankyou_order_received_text', [$this, 'inject_status_loader_legacy'], 20, 2);
        add_action('woocommerce_order_confirmation_status', [$this, 'show_status_loader_blocks'], 25, 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function inject_status_loader_legacy(string $text, $order): string
    {
        if (!$order instanceof WC_Order) return $text;
        if (!$this->supports_payment_method($order)) return $text;
        ob_start();
        include __DIR__ . '/../../templates/thankyou-status-loader.php';
        $loader = ob_get_clean();
        return $text . $loader;
    }

    public function show_status_loader_blocks($order): void
    {
        if (!$order instanceof WC_Order) return;
        if (!$this->supports_payment_method($order)) return;
        include __DIR__ . '/../../templates/thankyou-status-loader.php';
    }

    private function supports_payment_method(WC_Order $order): bool
    {
        return in_array($order->get_payment_method(), [
            Core::MAIN_METHOD,
            Core::CARD_IN_SHOP_METHOD,
            Core::BLIK_IN_SHOP_METHOD,
            Core::GOOGLE_PAY_IN_SHOP_METHOD,
            Core::APPLE_PAY_IN_SHOP_METHOD
        ], true);
    }

    public function enqueue_assets(): void
    {
        if (!is_order_received_page()) return;

        wp_register_style('p24-status-style', false);
        wp_enqueue_style('p24-status-style');
        wp_add_inline_style('p24-status-style', "
            #p24-payment-status {
                border: 1px solid #ddd;
                border-radius: 8px;
                background-color: #f9f9f9b2;
                padding: 16px;
                margin: 20px 0;
                font-size: 18px;
                color: #333;
                text-align: center;
            }
            .p24-loader {
                border: 6px solid #f3f3f3;
                border-top: 6px solid #3498db;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                margin:20px auto;
            }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            #p24-retry-btn {
                display: inline-block;
                margin-top: 10px;
                background: #0073aa;
                color: #fff;
                border: none;
                border-radius: 4px;
                padding: 8px 14px;
                cursor: pointer;
                font-size: 14px;
            }
            #p24-retry-btn:hover {
                background: #005f8d;
            }
        ");

        wp_register_script('p24-status-script', false);
        wp_enqueue_script('p24-status-script');
        wp_add_inline_script('p24-status-script', "
document.addEventListener('DOMContentLoaded', function () {
    const orderId = " . (int) get_query_var('order-received') . ";
    const statusEl = document.getElementById('p24-status-message');
    const loaderEl = document.querySelector('.p24-loader');
    const processingMsg = document.getElementById('p24-processing-msg');
    const initialDelay = 2000;
    const intervalTime = 2500;
    const maxTime = 20000;
    let startedAt = 0;
    let interval = null;
    let timeoutId = null;

    function hideProcessingMsg() {
        if (processingMsg) processingMsg.style.display = 'none';
    }

    function setStatus(html, done = false) {
        if (statusEl) statusEl.innerHTML = html;
        if (done && loaderEl) loaderEl.remove();
    }

    function stopPolling() {
        if (interval) {
            clearInterval(interval);
            interval = null;
        }
        if (timeoutId) {
            clearTimeout(timeoutId);
            timeoutId = null;
        }
    }

    function startPolling() {
        stopPolling();
        startedAt = Date.now();
        timeoutId = setTimeout(function() {
            timeoutId = null;
            checkStatus();
            interval = setInterval(checkStatus, intervalTime);
        }, initialDelay);
    }

    function end(html, allowRetry = false) {
        stopPolling();
        if (allowRetry) {
            html += '<br><button id=\"p24-retry-btn\">" . esc_js(__('Check again', 'woocommerce-p24')) . "</button>';
        }
        setStatus(html, true);
        hideProcessingMsg();
        const retryBtn = document.getElementById('p24-retry-btn');
        if (retryBtn) {
            retryBtn.addEventListener('click', function() {
                if (statusEl) statusEl.innerHTML = '<strong>" . esc_js(__('Rechecking payment status...', 'woocommerce-p24')) . "</strong>';
                const container = document.getElementById('p24-payment-status');
                if (loaderEl && container && !container.contains(loaderEl)) {
                    container.appendChild(loaderEl);
                }
                if (processingMsg) processingMsg.style.display = 'block';
                startPolling();
            });
        }
    }

    function checkStatus() {
        if (Date.now() - startedAt >= maxTime) {
            end('<strong>" . esc_js(__('Payment confirmation has not been received yet.', 'woocommerce-p24')) . "</strong>', true);
            return;
        }

        fetch('" . admin_url('admin-ajax.php') . "?action=check_payment_status&order_id=' + orderId)
            .then(res => res.json())
            .then(data => {
                const status = data?.data?.status ?? null;
                if (data.success && status === 'paid') {
                    end('<strong style=\"color:green; text-align: center;\">" . esc_js(__('Payment has been confirmed.', 'woocommerce-p24')) . "</strong>');
                } else if (data.success && status === 'pending') {
                    setStatus('<strong style=\"color:orange; text-align: center;\">" . esc_js(__('Waiting for payment confirmation', 'woocommerce-p24')) . "</strong>');
                } else {
                    setStatus('<strong>" . esc_js(__('Checking payment status...', 'woocommerce-p24')) . "</strong>');
                }
            })
            .catch(() => {
                setStatus('<strong style=\"color:#b00;\">" . esc_js(__('Could not check payment status.', 'woocommerce-p24')) . "</strong>');
            });
    }

    if (!orderId) {
        setStatus('<strong>" . esc_js(__('Could not identify the order.', 'woocommerce-p24')) . "</strong>', true);
        hideProcessingMsg();
        return;
    }

    startPolling();
});
");
    }
}
