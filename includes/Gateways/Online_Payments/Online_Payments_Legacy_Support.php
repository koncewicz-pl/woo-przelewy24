<?php

namespace WC_P24\Gateways\Online_Payments;

if (!defined('ABSPATH')) {
    exit;
}

use WC_P24\Gateways\Paywall_Blocks_Helper;
use WC_P24\Render;

trait Online_Payments_Legacy_Support
{
    use Paywall_Blocks_Helper;

    public function payment_fields(): void
    {
        parent::payment_fields();

        if ($this->has_fields) {
            $settings = $this->get_receipt_config();
            Render::template('gateway/online-payments', $settings);
        }
    }

    public function process_payment($order_id): array
    {
        if (isset($_POST['regulation'])) {
            $regulation = (bool) $_POST['regulation'];
        } else {
            $regulation = (bool) ($_POST['regulation_' . $this->method] ?? false);
        }

        return $this->process_paywall_checkout(
            (int) $order_id,
            [
                'method' => (int) ($_POST['method'] ?? 0),
                'regulation' => $regulation,
            ]
        );
    }

    /**
     * @param array<string, mixed> $payment_data
     */
    protected function process_paywall_checkout(int $order_id, array $payment_data): array
    {
        $method = $this->resolve_paywall_method_id($payment_data);
        $regulation = $this->resolve_regulation_accepted($payment_data);
        $this->validate_paywall_checkout($method, $regulation);

        return $this->process_on_paywall($order_id, $method, $regulation);
    }

    public function legacy_checkout_validation($fields, $errors): void
    {
        if (isset($_POST['payment_method']) && $_POST['payment_method'] === $this->id) {
            if (!empty($_POST['method']) && empty($_POST['regulation_0'])) {
                $errors->add('validation', __('You have to accept the terms and conditions for using the chosen method', 'woocommerce-p24'));
            }
        }
    }
}
