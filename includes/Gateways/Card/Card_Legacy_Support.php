<?php
namespace WC_P24\Gateways\Card;
if (!defined('ABSPATH')) {
    exit;
}
use WC_P24\Helper;
use WC_P24\Render;
trait Card_Legacy_Support
{
    private function init_legacy()
    {
        add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
    }

    public function process_payment($order_id): array
    {
        return $this->process_on_payment_url($order_id);
    }

    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        $result = $this->get_receipt_config();

        if ($order instanceof \WC_Order) {
            $recurring = Helper::order_has_subscription_product($order);
            $result['recurring'] = $recurring;
            $result['hasSubscription'] = $recurring;
            if ($recurring) {
                $order->update_meta_data('_p24_has_subscription', true);
                $order->save();
            }
        }

        $result['url'] = Card_Webhooks::get_process_card_url();
        $result['orderId'] = $order_id;
        $result['orderKey'] = ($order instanceof \WC_Order) ? $order->get_order_key() : null;

        $result['clickToPay'] = $result['clickToPay'] ?? [];
        $result['clickToPay']['email'] = $result['clickToPay']['email'] ?? (($order instanceof \WC_Order) ? $order->get_billing_email() : null);
        $result['clickToPay']['enabled'] = $result['clickToPay']['enabled'] ?? false;

        $result['redirect'] = ($order instanceof \WC_Order) ? $order->get_checkout_order_received_url() : '';

        $result['i18n'] = $result['i18n'] ?? [];
        $result['i18n']['label'] = $result['i18n']['label'] ?? [];
        $result['i18n']['label']['save_oneclick'] = $result['i18n']['label']['save_oneclick'] ?? __('Store my card for convenient future checkouts.', 'woocommerce-p24');
        $result['i18n']['label']['save_recurring'] = $result['i18n']['label']['save_recurring'] ?? __('I agree to save my card and authorize future recurring charges in accordance with the terms and conditions.', 'woocommerce-p24');
        $result['i18n']['label']['regulation'] = $result['i18n']['label']['regulation'] ?? __('I accept the terms and conditions.', 'woocommerce-p24');
        $result['i18n']['error'] = $result['i18n']['error'] ?? [];
        $result['i18n']['error']['rules'] = $result['i18n']['error']['rules'] ?? __('You must accept the terms and conditions.', 'woocommerce-p24');

        $result['oneClick'] = $result['oneClick'] ?? [];
        $result['oneClick']['enabled'] = $result['oneClick']['enabled'] ?? false;
        $result['oneClick']['items'] = $result['oneClick']['items'] ?? [];

        Render::template('receipt_page_card', ['config' => $result]);
    }
}