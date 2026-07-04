<?php

namespace WC_P24\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

trait Paywall_Blocks_Helper
{
    /**
     * @param array<string, mixed> $payment_data
     * @return array<string, mixed>
     */
    protected function flatten_blocks_payment_data(array $payment_data): array
    {
        if (empty($payment_data['paymentMethodData']) || !is_array($payment_data['paymentMethodData'])) {
            return $payment_data;
        }

        $flat = [];
        foreach ($payment_data['paymentMethodData'] as $item) {
            if (!is_array($item) || !array_key_exists('key', $item)) {
                continue;
            }
            $key = $item['key'];
            $value = array_key_exists('value', $item) ? $item['value'] : '';
            $flat[$key] = $value;
        }

        return array_merge($payment_data, $flat);
    }

    /**
     * @param array<string, mixed> $payment_data
     */
    protected function resolve_paywall_method_id(array $payment_data): int
    {
        if (!isset($payment_data['method'])) {
            return 0;
        }

        $method = $payment_data['method'];

        if ($method === false || $method === '' || $method === null) {
            return 0;
        }

        return (int) $method;
    }

    /**
     * @param array<string, mixed> $payment_data
     */
    protected function resolve_regulation_accepted(array $payment_data): bool
    {
        if (!array_key_exists('regulation', $payment_data)) {
            return false;
        }

        $regulation = $payment_data['regulation'];

        if (is_bool($regulation)) {
            return $regulation;
        }

        if (is_int($regulation) || is_float($regulation)) {
            return (int) $regulation !== 0;
        }

        if (is_string($regulation)) {
            $regulation = strtolower(trim($regulation));

            return in_array($regulation, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $regulation;
    }

    protected function validate_paywall_checkout(int $method, bool $regulation): void
    {
        if ($method > 0 && !$regulation) {
            throw new \Exception(
                __('You have to accept the terms and conditions for using the chosen method', 'woocommerce-p24')
            );
        }
    }
}
