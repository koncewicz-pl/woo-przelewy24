<?php

namespace WC_P24\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

use WC_P24\Config;
use WC_P24\Models\Refund;
use WC_P24\Models\Transaction;
use WC_P24\UI\Display;
use WC_P24\Utilities\Logger;
use WC_P24\Utilities\Payment_Methods;

trait Payment_Helpers
{
    public int $method = Payment_Methods::PAYWALL_PAYMENT;
    public string $group = 'przelewy24';
    public string $subgroup = '';
    public array $method_alt = [];

    public function is_enabled(): bool
    {
        return $this->enabled === 'yes';
    }

    public function get_title(): string
    {
        if (is_admin()) {
            $screen = get_current_screen();
            if ($screen && $screen->post_type === 'shop_order') {
                return $this->method_title;
            }
        }

        return parent::get_title();
    }


    public function get_transaction_url($order): string
    {
        $prefix = Config::get_instance()->is_live() ? '' : 'sandbox-';
        $token = $order->get_meta(Transaction::TOKEN_KEY, true);

        if ($token) {
            return 'https://' . $prefix . 'go.przelewy24.pl/trnStatus/' . $token;
        }

        return parent::get_transaction_url($order);
    }

    public function process_on_paywall($order_id, ?int $method = null, $accept_rules = false): array
    {
        $transaction = new Transaction($order_id, $method ?? $this->method, $accept_rules);
        $transaction->register();
        $return_url = get_post_meta($order_id, '_p24_return_url', true);

        return [
            'result'    => 'success',
            'redirect'  => $transaction->get_paywall_url(),
            'wl_return' => $return_url
        ];    }

    public function process_on_payment_url($order_id): array
    {
        $order = wc_get_order($order_id);

        return ['result' => 'success', 'redirect' => $order->get_checkout_payment_url(true)];
    }


    public function process_refund($order_id, $amount = null, $reason = ''): \WP_Error
    {
        $message = '';
        $amount = (float)$amount;
        $line_items = $this->extract_line_items_from_request();

        try {
            $refund = new Refund($order_id, $amount, $reason, $line_items);
            $refund->register();
            $message = __('The refund is being processed', 'woocommerce-p24');

            do_action('przelewy24_after_refund_process', wc_get_order($order_id), $amount, $reason, null, $this->id);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            Logger::log($e->getMessage(), Logger::EXCEPTION);
        }

        return new \WP_Error('refund', $message);
    }

    private function extract_line_items_from_request(): ?array
    {
        if (!isset($_POST['line_item_qtys']) || !isset($_POST['line_item_totals'])) {
            return null;
        }

        $line_item_qtys = json_decode(sanitize_text_field(wp_unslash($_POST['line_item_qtys'])), true);
        $line_item_totals = json_decode(sanitize_text_field(wp_unslash($_POST['line_item_totals'])), true);
        $line_item_tax_totals = isset($_POST['line_item_tax_totals']) ? json_decode(sanitize_text_field(wp_unslash($_POST['line_item_tax_totals'])), true) : [];

        if (empty($line_item_qtys) && empty($line_item_totals)) {
            return null;
        }

        $line_items = [];
        $item_ids = array_unique(array_merge(array_keys($line_item_qtys ?? []), array_keys($line_item_totals ?? [])));

        foreach ($item_ids as $item_id) {
            $line_items[$item_id] = [
                'qty' => isset($line_item_qtys[$item_id]) ? max($line_item_qtys[$item_id], 0) : 0,
                'refund_total' => isset($line_item_totals[$item_id]) ? wc_format_decimal($line_item_totals[$item_id]) : 0,
                'refund_tax' => isset($line_item_tax_totals[$item_id]) ? array_filter(array_map('wc_format_decimal', $line_item_tax_totals[$item_id])) : [],
            ];
        }

        if (!empty($line_items)) {
            error_log('[P24 Refund] Extracted line items from request: ' . json_encode($line_items));
        }

        return !empty($line_items) ? $line_items : null;
    }

    public function admin_options()
    {
        $display = new Display();
        $display->set_fields($this->get_form_fields(), $this);

        $display->render();
    }
}
