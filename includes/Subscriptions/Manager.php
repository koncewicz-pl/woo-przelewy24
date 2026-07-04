<?php

namespace WC_P24\Subscriptions;

use WC_Order;
use WC_P24\API\Resources\Card_Resource;
use WC_P24\Core;
use WC_P24\Models\Database\Reference;
use WC_P24\Models\Database\Subscription;
use WC_P24\Models\Simple\Card_Simple;
use WC_P24\Models\Transaction;
use WC_P24\Subscriptions\Product\Product;
use WC_P24\Utilities\Logger;
use WC_P24\Utilities\Notice;

class Manager
{
    public string $cron_time = '';

    public function __construct()
    {
        $this->cron_time = 'daily';

        add_filter('cron_schedules', [$this, 'cron_schedules']);

        if (!wp_next_scheduled('przelewy24_cron_event_renew')) {
            wp_schedule_event(time(), $this->cron_time, 'przelewy24_cron_event_renew');
        }

        add_action('przelewy24_cron_event_renew', [$this, 'find_subscription_to_extend']);
        add_action('p24_renew_subscription', [$this, 'find_subscription_to_extend']);

        add_action('woocommerce_order_status_pending', [$this, 'create_pending_subscriptions_on_pending_order'], 10, 2);
        add_action('woocommerce_before_delete_order_item', [$this, 'on_remove_order_item']);
        add_action('woocommerce_before_delete_order', [$this, 'on_remove_order']);

        add_action('przelewy24_after_verify_transaction', [$this, 'handle_subscriptions'], 20);

        // After Plugin::bootstrap (init:10); same-priority init callbacks added during bootstrap may not run.
        add_action('init', [$this, 'notice'], 20);
    }

    public function cron_schedules($schedules): array
    {
        $schedules['every5mins'] = [
            'interval' => 60 * 5,
            'display' => 'Every 5 minutes'
        ];

        $schedules['everymin'] = [
            'interval' => 60,
            'display' => 'Every minute'
        ];

        return $schedules;
    }

    public function notice(): void
    {
        new Notice(__('Subscription Przelewy24 requires <strong>Przelewy24 - Card payment</strong> to be enabled', 'woocommerce-p24'), Notice::INFO, false, 999, function () {
            if (!Subscriptions::is_enabled()) {
                return false;
            }

            // Gateways_Manager::$gateways is empty in admin until WC loads gateways (e.g. Plugins screen).
            if (!function_exists('WC') || !WC()) {
                return false;
            }

            $payment_gateways = WC()->payment_gateways()->payment_gateways();
            $card_gateway = $payment_gateways[Core::CARD_IN_SHOP_METHOD] ?? null;
            $card_gateway_is_enabled = $card_gateway && $card_gateway->is_enabled();

            return !$card_gateway_is_enabled;
        });
    }

    public function handle_subscriptions(Transaction $transaction): void
    {
        $order = $transaction->order;
        $has_subscription = $order->get_meta('_p24_has_subscription');

        if (!$has_subscription) return;

        $resource = new Card_Resource();
        $response = $resource->get_info($transaction->get_order_id());

        if (!empty($response['data'])) {
            $card = new Card_Simple($response['data']);
            $card_id = Reference::save_reference($card, $order);

            if ($card_id) {
                Helper::activate_subscription($order, $card_id);
            }
        }
    }

    public function on_remove_order_item($item_id): void
    {
        $order_id = wc_get_order_id_by_order_item_id($item_id);
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) return;

        $item = $order->get_item($item_id);
        $this->cancel_subscription($item);
    }

    public function on_remove_order($order_id): void
    {
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) return;

        foreach ($order->get_items() as $item) {
            $this->cancel_subscription($item);
        }
    }

    public function cancel_subscription($item): void
    {
        $subscription_id = $item->get_meta('_p24_subscription_id');

        if (!$subscription_id) return;

        $subscription = Subscription::get($subscription_id);
        $subscription && $subscription->cancel(Subscription::CANCELLED_BY_SYSTEM);
    }

    public function create_pending_subscriptions_on_pending_order($order_id, $order): void
    {
        $items = $order->get_items();
        $has_subscription = false;

        try {
            foreach ($items as $item) {
                $product = $item->get_product();

                if ($product instanceof Product) {
                    if ($item->get_meta('_p24_subscription_id')) continue;

                    $has_subscription = true;
                    $subscription = new Subscription();
                    $subscription->set_customer_id((int)$order->get_customer_id());
                    $subscription->set_product_id($product->get_id());
                    $subscription->set_valid_to(new \DateTime());
                    $subscription->set_status(Subscription::STATUS_PENDING);
                    $subscription->set_order_id((int)$order_id);
                    $subscription->set_created_at(new \DateTime());
                    $subscription->set_start_order_id((int)$order_id);

                    if ($subscription->save()) {
                        $item->update_meta_data('_p24_subscription_id', $subscription->get_id());
                    }
                }
            }

            if ($has_subscription) {
                $order->update_meta_data('_p24_has_subscription', true);
                $order->save();
            }
        } catch (\Exception $e) {
            Logger::log($e->getMessage(), Logger::EXCEPTION);
        }
    }

    public function find_subscription_to_extend(): void
    {
        $retry_days = Subscriptions::retry_days();

        self::sync_exhausted_subscription_statuses();

        $today_end = new \DateTime();
        $today_end->setTime(23, 59, 59);

        $retry_from = new \DateTime();
        $retry_from->modify('-' . $retry_days . ' days');
        $retry_from->setTime(0, 0, 0);

        $subscriptions = Subscription::findAll([
            'where' => [
                't.valid_to <= %s AND t.valid_to >= %s AND t.status IN (%d, %d) AND card_id IS NOT NULL',
                $today_end->format('Y-m-d H:i:s'),
                $retry_from->format('Y-m-d H:i:s'),
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_PROCESSING,
            ],
        ]);

        $first = array_shift($subscriptions);

        if ($first) {
            try {
                $first->renew();
                $first->save();
            } catch (\Exception $e) {
                Logger::log('[P24] Subscription renew failed for #' . $first->get_id() . ': ' . $e->getMessage(), Logger::EXCEPTION);
            }

            if (!empty($subscriptions)) {
                wp_schedule_single_event(time() + 60, 'p24_renew_subscription');
            }
        }
    }

    public static function sync_exhausted_subscription_statuses(): int
    {
        $retry_days = Subscriptions::retry_days();
        $cutoff = new \DateTime();
        $cutoff->modify('-' . $retry_days . ' days');
        $cutoff->setTime(0, 0, 0);

        $expired = Subscription::findAll([
            'where' => [
                't.valid_to < %s AND t.status IN (%d, %d)',
                $cutoff->format('Y-m-d H:i:s'),
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_PROCESSING,
            ],
        ]);

        $suspended = 0;

        foreach ($expired as $subscription) {
            if ($subscription->suspense()) {
                $suspended++;
                Logger::log(sprintf('[P24] Subscription #%d suspended after retry window (%d days).', $subscription->get_id(), $retry_days));
            }
        }

        return $suspended;
    }
}
