<?php

namespace WC_P24\Subscriptions;

use WC_Order;
use WC_P24\Models\Database\Subscription;
use WC_P24\Subscriptions\Product\Product;
use WC_P24\Utilities\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Helper
{
    public static function get_subscription_id_from_query(): int
    {
        return isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0;
    }

    /**
     * @return array<int, array{0: int, 1: Subscription}>
     */
    public static function get_order_subscription_items(?WC_Order $order): array
    {
        $result = [];

        if (!$order instanceof WC_Order) {
            return $result;
        }

        if (!$order->get_meta('_p24_has_subscription')) {
            return $result;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if (!$product instanceof Product) {
                continue;
            }

            $subscription_id = (int) $item->get_meta('_p24_subscription_id');

            if (!$subscription_id) {
                continue;
            }

            $subscription = Subscription::get($subscription_id);

            if (!$subscription || !$subscription->get_id()) {
                continue;
            }

            $quantity = (int) $item->get_quantity();
            $days = (int) ($quantity * $product->get_days());
            $result[] = [$days, $subscription];
        }

        return $result;
    }

    public static function activate_subscription(WC_Order $order, int $card_id): void
    {
        foreach (self::get_order_subscription_items($order) as [$days, $subscription]) {
            switch ($subscription->get_status()) {
                case Subscription::STATUS_PENDING:
                    if (!$subscription->activate($days, $card_id)) {
                        Logger::log(sprintf(
                            '[P24] Subscription activate failed (subscription #%d, order #%d, days %d).',
                            $subscription->get_id(),
                            $order->get_id(),
                            $days
                        ));
                    }
                    break;

                case Subscription::STATUS_PROCESSING:
                    if (!$subscription->extend()) {
                        Logger::log(sprintf(
                            '[P24] Subscription extend failed (subscription #%d, order #%d).',
                            $subscription->get_id(),
                            $order->get_id()
                        ));
                    }
                    break;
            }
        }
    }
}
