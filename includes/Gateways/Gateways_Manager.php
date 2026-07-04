<?php

namespace WC_P24\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Exception;
use WC_P24\Config;
use WC_P24\Core;
use WC_P24\Gateways\Apple_Pay\Apple_Pay_Block;
use WC_P24\Gateways\Blik\Blik_Block;
use WC_P24\Gateways\Card\Card_Block;
use WC_P24\Gateways\Google_Pay\Google_Pay_Block;
use WC_P24\Gateways\Online_Payments\Online_Payments_Block;
use WC_P24\Helper;
use WC_P24\Installments\Installments;
use WC_P24\Utilities\Payment_Methods;

class Gateways_Manager
{
    static array $gateways = [];
    static array $extra_gateways = [];

    /** @var bool Guards against re-entrancy when syncing cart totals before resolving payment methods. */
    private static $p24_syncing_cart_for_methods = false;

    public function __construct()
    {
        new General_Webhooks();
        new Fee_Manager();

        add_filter('woocommerce_payment_gateways', [$this, 'add_gateways']);
        add_action('wc_payment_gateways_initialized', [$this, 'reorder_gateways']);

        add_filter('woocommerce_available_payment_gateways', [$this, 'filter_gateways'], 1);
        add_action('woocommerce_blocks_loaded', [$this, 'add_payment_blocks']);

        if (function_exists('woocommerce_store_api_register_update_callback')) {
            woocommerce_store_api_register_update_callback(['namespace' => 'przelewy24', 'callback' => [$this, 'refresh_checkout']]);
        }
    }

    public static function get_ids(): array
    {
        return array_map(function ($gateway) {
            return $gateway->id;
        }, self::$gateways);
    }

    public function add_gateways(array $gateways): array
    {
        $main_gateway = new Online_Payments\Gateway();

        self::$gateways = [
            Core::MAIN_METHOD => $main_gateway,
            Core::BLIK_IN_SHOP_METHOD => new Blik\Gateway(),
            Core::CARD_IN_SHOP_METHOD => new Card\Gateway(),
            Core::GOOGLE_PAY_IN_SHOP_METHOD => new Google_Pay\Gateway(),
            Core::APPLE_PAY_IN_SHOP_METHOD => new Apple_Pay\Gateway()
        ];

        foreach ($main_gateway->get_featured_methods() as $gateway) {
            if ($gateway['featured']) {
                self::$extra_gateways[] = new Virtual_Gateway\Gateway($gateway['id'], $gateway['name'], $gateway['mobileImgUrl']);
            }
        }

       Installments::add_as_gateway();

        $gateways = array_merge($gateways, self::$gateways);

        if (!(isset($_GET['tab']) && $_GET['tab'] == 'checkout')) {
            $gateways = array_merge(self::$extra_gateways, $gateways);
        }

        // Enabling must be here not in , because is need to get translations
        if (is_admin()) {
            foreach (self::$gateways as $gateway) {
                if (in_array($gateway->id, [Core::MAIN_METHOD, Core::CARD_IN_SHOP_METHOD])) {
                    $gateway->enable_and_set_defaults();
                }
            }
        }

        return $gateways;
    }

    public function reorder_gateways($order)
    {
        $featured = [];
        $rest = [];

        foreach ($order->payment_gateways as $gateway) {
            if (isset($gateway->is_featured) && isset($gateway->group) && $gateway->group === 'przelewy24') {
                $featured[] = $gateway;
            } else {
                $rest[] = $gateway;
            }
        }

        $order->payment_gateways = array_merge($featured, $rest);
    }

    public function filter_gateways(array $gateways): array
    {
        try {
            if (is_admin()) {
                return $gateways;
            }

            $methods = self::get_available_methods();

            if (empty($methods)) {
                $this->hide_all_gateways($gateways);
                return $gateways;
            }

            foreach ($gateways as $key => $gateway) {
                if (isset($gateway->group) && $gateway->group === 'przelewy24') {
                    $is_available = current(array_filter($methods, function ($method) use ($gateway) {
                        $method_id = isset($method['id']) ? (int) $method['id'] : 0;
                        $check_id = ($method_id === $gateway->method) || in_array($method_id, $gateway->method_alt);

                        return $check_id || $gateway->method === Payment_Methods::APPLE_PAY;
                    }));


                    if (!$is_available && Payment_Methods::PAYWALL_PAYMENT !== $gateway->method) {
                        unset($gateways[$key]);
                    }
                }
            }

        } catch (Exception $e) {
            $this->hide_all_gateways($gateways);
        }


        return $gateways;
    }

    private function hide_all_gateways(&$gateways)
    {
        foreach ($gateways as $key => $gateway) {
            if (in_array($gateway->id, self::get_ids())) {
                unset($gateways[$key]);
            }
        }
    }

    /**
     * @param int|float|string $total Amount in shop currency, or 0 to use the current cart total.
     */
    public static function get_available_methods($total = 0): array
    {
        $amount = (float) $total;

        if ($amount <= 0.0 && isset(WC()->cart) && !WC()->cart->is_empty() && !self::$p24_syncing_cart_for_methods) {
            $cart_total = (float) WC()->cart->get_total('edit');
            if ($cart_total <= 0.0) {
                self::$p24_syncing_cart_for_methods = true;
                WC()->cart->calculate_totals();
                self::$p24_syncing_cart_for_methods = false;
            }
        }

        if ($amount <= 0.0) {
            $amount = isset(WC()->cart) ? (float) WC()->cart->get_total('edit') : 0.0;
        }

        $available_methods = Payment_Methods::get_payment_methods(Helper::to_lowest_unit($amount), Config::get_instance()->get_currency());

        return array_filter($available_methods, static function ($method) {
            if (!is_array($method)) {
                return false;
            }
            if (array_key_exists('status', $method)) {
                return (bool) $method['status'];
            }
            return true;
        });
    }

    /**
     * @param int|float|string $total Order or cart total (WooCommerce often passes a string).
     */
    public static function get_method_id_matching_group(array $group, $total): ?int
    {
        $available_methods = self::get_available_methods($total);

        $payments = array_values(array_filter($available_methods, function ($payment) use ($group) {
            return isset($payment['id']) && in_array((int) $payment['id'], $group, true);
        }));

        if (isset($payments[0]['id'])) {
            return (int) $payments[0]['id'];
        }

        return 0;
    }

    public function add_payment_blocks(): void
    {
        add_action('woocommerce_blocks_payment_method_type_registration', function (PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new Online_Payments_Block());
            $payment_method_registry->register(new Card_Block());
            $payment_method_registry->register(new Blik_Block());
            $payment_method_registry->register(new Apple_Pay_Block());
            $payment_method_registry->register(new Google_Pay_Block());
        });
    }

    public function refresh_checkout(array $data): void
    {
        if (!empty($data['clean'])) {
            WC()->session->set('chosen_payment_method', '');
        }

        if (!empty($data['payment_method'])) {
            WC()->session->set('chosen_payment_method', $data['payment_method']);
        }

        if (WC()->cart) {
            WC()->cart->calculate_totals();
        }
    }
}
