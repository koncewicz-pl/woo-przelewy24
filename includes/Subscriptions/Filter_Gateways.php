<?php

namespace WC_P24\Subscriptions;

use WC_Order;
use WC_P24\Subscriptions\Product\Product;

if (!defined('ABSPATH')) {
    exit;
}

class Filter_Gateways
{
    public function __construct()
    {
        add_filter('woocommerce_available_payment_gateways', [$this, 'filter_gateways'], 257, 1);
    }

    public function filter_gateways(array $gateways): array
    {
        $order_id = get_query_var('order-pay');
        $is_order_pay = (bool) $order_id;
        $order = $is_order_pay ? wc_get_order((int) $order_id) : null;

        $line_items = isset(WC()->cart) ? WC()->cart->get_cart() : [];

        if ($is_order_pay) {
            if (!$order instanceof WC_Order) {
                return $gateways;
            }
            $line_items = $order->get_items();
        }

        $has_subscription_product = false;

        foreach ($line_items as $item) {
            if (is_object($item) && is_callable([$item, 'get_product'])) {
                $product = $item->get_product();
            } else {
                $product_id = is_array($item) ? (int) ($item['product_id'] ?? 0) : 0;
                $product = $product_id ? wc_get_product($product_id) : false;
            }

            if ($product && in_array($product->get_type(), [Product::TYPE])) {
                $has_subscription_product = true;
                break;
            }
        }

        if ($has_subscription_product) {
            foreach ($gateways as $key => $gateway) {
                if (!in_array('p24-subscription', $gateway->supports)) {
                    unset($gateways[$key]);
                }
            }
        }

        return $gateways;
    }

}
