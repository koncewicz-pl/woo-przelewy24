<?php

namespace WC_P24\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

use WC_P24\Multicurrency\Multicurrency;

class Fee
{
    public \WC_Payment_Gateway $gateway;
    public $virtual_gateway;

    public function __construct($gateway, $virtual_gateway = null)
    {
        $this->gateway = $gateway;
        $this->virtual_gateway = $virtual_gateway;

        if ($this->is_enabled()) {
            Fee_Manager::add_fee($this);
        }
    }


    public function is_enabled(): bool
    {
        return $this->gateway->get_option('fee_enabled') === 'yes';
    }

    public function get_fee_name(): string
    {
        return $this->gateway->get_option('fee_name');
    }

    public function get_fee_value(): float
    {
        $raw = $this->gateway->get_option('fee_value');
        // Accept both "4.25" and "4,25" regardless of locale/input method.
        // wc_format_decimal handles thousand separators + locale decimal separator safely.
        $value = (float) wc_format_decimal((string) $raw, wc_get_price_decimals());

        if (Multicurrency::is_enabled()) {
            $value *= Multicurrency::get_config()->get_multiplier();
        }

        return $value;
    }
}
