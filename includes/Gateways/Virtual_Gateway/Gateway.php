<?php

namespace WC_P24\Gateways\Virtual_Gateway;

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;
use WC_P24\Core;
use WC_P24\Gateways\Fee;
use WC_P24\Gateways\Gateways_Manager;
use WC_P24\Gateways\Paywall_Blocks_Helper;
use WC_P24\Gateways\Payment_Helpers;
use WC_Payment_Gateway;

class Gateway extends WC_Payment_Gateway
{
    use Paywall_Blocks_Helper;
    use Payment_Helpers;
    use Virtual_Gateway_Legacy_Support;

    public bool $is_featured = true;

    public function __construct(int $method, string $name, string $icon = null)
    {
        $this->method = $method;
        $this->id = 'p24-online-payments-' . $method;
        $this->description = ' ';

        $this->title = $name;
        $this->supports = ['products', 'refunds'];
        $this->enabled = 'yes';

        new Fee(Gateways_Manager::$gateways[Core::MAIN_METHOD], $this);

        if ($icon) {
            $this->icon = apply_filters('woocommerce_gateway_icon', $icon, $this->id);
        }

        add_action('woocommerce_after_checkout_validation', [$this, 'legacy_checkout_validation'], 10, 2);
        add_action('woocommerce_rest_checkout_process_payment_with_context', [$this, 'process_payment_rest'], 10, 2);
    }

    public function process_payment_rest(PaymentContext $context, PaymentResult &$payment_result): void
    {
        if ($context->payment_method !== $this->id) {
            return;
        }

        try {
            $payment_data = $this->flatten_blocks_payment_data($context->payment_data);
            $regulation = $this->resolve_regulation_accepted($payment_data);

            if (!$regulation) {
                throw new \Exception(
                    __('You have to accept the terms and conditions for using the chosen method', 'woocommerce-p24')
                );
            }

            $details = $this->process_on_paywall($context->order->get_id(), $this->method, $regulation);

            $payment_result->set_payment_details($details);
            $payment_result->set_status('success');

            if (!empty($details['redirect'])) {
                $payment_result->set_redirect_url($details['redirect']);
            }
        } catch (\Exception $e) {
            $payment_result->set_payment_details(['message' => $e->getMessage()]);
            $payment_result->set_status('error');
        }
    }
}
