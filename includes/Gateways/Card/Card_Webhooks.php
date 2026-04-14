<?php

namespace WC_P24\Gateways\Card;

if (!defined('ABSPATH')) {
    exit;
}

use WC_Order;
use WC_P24\Models\Database\Reference;
use WC_P24\Models\Simple\Card_Notification;
use WC_P24\Subscriptions\Helper;
use WC_P24\Utilities\Logger;
use WC_P24\Utilities\Webhook;
use WC_Payment_Gateway;

class Card_Webhooks extends Webhook
{
    const PROCESS_CARD = 'process-card';
    const NOTIFICATION_CARD = 'notification-card';
    const ACTION_REGISTER_TRANSACTION_LEGACY = 'register-transaction';
    private WC_Payment_Gateway $gateway;

    public function __construct($gateway)
    {
        parent::__construct();
        $this->gateway = $gateway;
    }

    public function callback(): void
    {
        $action = $this->get_action();

        switch ($action) {
            case self::PROCESS_CARD;
                $this->process();
                break;
            case self::NOTIFICATION_CARD;
                $this->notification();
                break;
        }
    }

    private function process(): void
    {
        try {
            $input = $this->get_input();

            switch ($input['type']) {
                case self::ACTION_REGISTER_TRANSACTION_LEGACY:
                    $result = $this->register_card_transaction();
                    break;
            }

            $order = $this->get_order();
            $redirect = $order->get_meta('_p24_return_url', true);
            if (empty($redirect)) {
                $redirect = $order->get_checkout_order_received_url();
            }

            $result['redirect'] = $redirect;

            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['error' => true, 'message' => $e->getMessage()], 422);
            Logger::log($e->getMessage(), Logger::EXCEPTION);
        }

        exit;
    }


    private function notification(): void
    {
        try {
            $order = $this->load_order();
            if (!$order) {
                return;
            }

            $notification = new Card_Notification($this->get_input());

            if ($notification->has_error()) {
                $this->add_reject_note($order, $notification);
            } else {
                $this->handle_card_and_subscription($order, $notification);
            }

            $notification->message && $order->add_order_note($notification->message);
            $order->save();
        } catch (\Exception $e) {
            Logger::log($e->getMessage(), Logger::EXCEPTION);
        }

        exit;
    }

    private function load_order(): ?WC_Order
    {
        if (empty($_GET[self::ORDER_ID_QUERY_KEY])) {
            throw new \Exception('Order ID not provided');
        }

        $order = wc_get_order((int)$_GET[self::ORDER_ID_QUERY_KEY]);
        return $order instanceof WC_Order ? $order : null;
    }

    private function add_reject_note(WC_Order $order, Card_Notification $notification): void
    {
        $notification->reject_message && $order->add_order_note($notification->reject_message);
    }


    private function handle_card_and_subscription(WC_Order $order, Card_Notification $notification): void
    {
        $has_save_card = $order->get_meta('_p24_save_card');
        $has_subscription = $order->get_meta('_p24_has_subscription');

        if ((!$has_save_card && !$has_subscription) || empty($notification->card)) {
            return;
        }

        $already_saved_card_id = $order->get_meta('_p24_card_id', true);
        if ($already_saved_card_id) {
            $notification->message && $order->add_order_note($notification->message);
            $order->save();
            return;
        }

        $notification->card->set_status(Reference::STATUS_REGISTERED);
        $card_id = Reference::save_reference($notification->card, $order);

        if ($card_id && $has_subscription) {
            $this->create_subscriptions_for_order($order);
            Helper::activate_subscription($order, $card_id);
            $order->update_meta_data('_p24_card_id', $card_id);
        }
    }

    private function create_subscriptions_for_order(WC_Order $order): void
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product instanceof \WC_P24\Subscriptions\Product\Product) {
                continue;
            }

            if ($item->get_meta('_p24_subscription_id')) {
                continue;
            }

            $subscription = new \WC_P24\Models\Database\Subscription();
            $subscription->set_customer_id((int)$order->get_customer_id());
            $subscription->set_product_id($product->get_id());
            $subscription->set_valid_to(new \DateTime());
            $subscription->set_status(\WC_P24\Models\Database\Subscription::STATUS_PENDING);
            $subscription->set_order_id((int)$order->get_id());

            if ($subscription->save()) {
                $item->update_meta_data('_p24_subscription_id', $subscription->get_id());
            }
        }
    }




    private function register_card_transaction(): array
    {
        $payment_details = $this->get_payment_details();
        $order = $this->get_order();

        $result = $this->gateway->payment($order, $payment_details);

        $redirect = $order->get_meta('_p24_return_url', true);
        if (empty($redirect)) {
            $redirect = $order->get_checkout_order_received_url();
        }

        $result['redirect'] = $redirect;

        return $result;
    }


    public static function get_process_card_url(): string
    {
        return self::setup_url(self::PROCESS_CARD);
    }

    public static function get_notification_card_url(string $order_id): string
    {
        return self::setup_url(self::NOTIFICATION_CARD, [
            self::ORDER_ID_QUERY_KEY => $order_id
        ]);
    }
}
