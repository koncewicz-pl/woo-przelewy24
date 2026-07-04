<?php

namespace WC_P24;

use WC_P24\Subscriptions\Product\Product;

if (!defined('ABSPATH')) {
    exit;
}

class Helper
{
    public static function get_language(): string
    {
        $locale = explode('_', get_locale());

        return $locale[0];
    }

    /**
     * Formats the full name of the customer based on their order.
     *
     * @param \WC_Order $order The WooCommerce order object.
     * @return string The formatted full name of the customer, or 'No name provided' if both are empty.
     */
    public static function get_customer_name(\WC_Order $order)
    {
        $firstName = $order->get_billing_first_name();
        $lastName = $order->get_billing_last_name();

        if (!empty($firstName) && !empty($lastName)) {
            $clientName = sprintf('%s %s', $firstName, $lastName);
        } elseif (!empty($firstName)) {
            $clientName = $firstName;
        } elseif (!empty($lastName)) {
            $clientName = $lastName;
        } else {
            $clientName = 'No name provided';
        }

        return $clientName;
    }

    public static function to_lowest_unit(float $amount): int
    {
        return (int)round($amount * 100);
    }

    public static function to_higher_unit(float $amount): float
    {
        return round($amount / 100, 2);
    }

    public static function order_has_subscription_product(\WC_Order $order): bool
    {
        $result = false;

        $items = $order->get_items();

        foreach ($items as $item) {
            $product = $item->get_product();

            if ($product instanceof Product) {
                $result = true;
                break;
            }
        }

        return $result;
    }

    public static function cart_has_subscription_product(): bool
    {
        if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product = $cart_item['data'] ?? null;

                if ($product instanceof Product) {
                    return true;
                }

                if (!empty($cart_item['product_id'])) {
                    $product = wc_get_product((int) $cart_item['product_id']);

                    if ($product instanceof Product) {
                        return true;
                    }
                }
            }
        }

        if (function_exists('WC') && isset(WC()->session)) {
            $order_id = WC()->session->get('store_api_draft_order');
            $order = $order_id ? wc_get_order($order_id) : false;

            if ($order instanceof \WC_Order) {
                return self::order_has_subscription_product($order);
            }
        }

        return false;
    }

    public static function anonymize(string $value, int $visible_length = 4): string
    {
        $length = strlen($value);
        $hidden = $length - $visible_length;
        $hidden = $hidden <= 0 ? $length / 2 : $hidden;

        return preg_replace('/^.{' . $hidden . '}/', '*****', $value);
    }

    public static function get_transaction_prefix(): string {
        $woo_version = defined('WC_VERSION') ? WC_VERSION : '';
        $plugin_version = Core::$version;

        return "wpp24{{$woo_version}:{$plugin_version}}";
    }
}
