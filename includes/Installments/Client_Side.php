<?php

namespace WC_P24\Installments;

use WC_P24\Assets;
use WC_P24\Config;
use WC_P24\Helper;
use WC_P24\Subscriptions\Product\Product;
use WC_P24\Utilities\Payment_Methods;
use WC_Product;

class Client_Side
{
    private bool $simulatorEnabled = false;

    public function __construct()
    {
        $widgetEnabled = Installments::show_widget_on_product();
        $this->simulatorEnabled = Installments::show_simulator();

        $widgetPosition = Installments::get_product_widget_position();
        if (empty($widgetPosition) || !is_string($widgetPosition)) {
            $widgetPosition = 'before';
        }

        if ($widgetEnabled) {
            $positionPriorities = [
                'after_summary_15' => 15,
                'after_summary_25' => 25,
            ];

            if (strpos($widgetPosition, 'after_summary') === 0) {
                $priority = isset($positionPriorities[$widgetPosition]) ? $positionPriorities[$widgetPosition] : 25;
                add_action('woocommerce_single_product_summary', [$this, 'add_widget_and_simulator_on_product'], $priority);
            } else {
                add_action('woocommerce_' . $widgetPosition . '_add_to_cart_form', [$this, 'add_widget_and_simulator_on_product']);
            }

            if (Installments::show_widget_on_checkout() && WC()->cart && Installments::is_amount_in_installment_widget_range((float) WC()->cart->total)) {
                add_action('woocommerce_review_order_before_payment', [$this, 'add_widget_and_simulator_on_checkout']);
            }

            Assets::add_script_localize('p24-installments', 'p24InstallmentsData', function () {
                $result = [];
                if (is_product()) {
                    $result = self::get_product_data();
                } else if (is_checkout()) {
                    $result = self::get_checkout_data();
                }
                return $result;
            });

            Assets::add_script_asset('p24-installments', 'installment.js', false);
        }
    }

    private function product_price_in_range($price)
    {
        return Installments::is_amount_in_installment_widget_range((float) $price);
    }

    private function is_installment_allowed_for_product(?WC_Product $product): bool
    {
        if (!$product) {
            return false;
        }

        return $product->get_type() !== Product::TYPE;
    }

    public function is_product_and_in_range(): bool
    {
        if (!is_product()) {
            return false;
        }

        $product = wc_get_product(get_the_ID());
        if (!$this->is_installment_allowed_for_product($product)) {
            return false;
        }

        $price = (float) wc_get_price_including_tax($product);
        return $this->product_price_in_range($price);
    }

    public function add_widget_and_simulator_on_checkout()
    {
        if (!Installments::show_widget_on_checkout()) {
            return;
        }

        if (!function_exists('WC') || !WC()->cart || !WC()->cart->total) {
            return;
        }

        $total = (float) WC()->cart->total;

        if (!Installments::is_amount_in_installment_widget_range($total)) {
            return;
        }

        $this->render_markup();
    }

    public function add_widget_and_simulator_on_product()
    {
        if (!$this->is_product_and_in_range()) {
            return;
        }

        $this->render_markup();
    }

    private function render_markup()
    {
        $show = Installments::show_simulator() ? 'true' : 'false';
        echo '<p24-installment id="p24_installments" show-modal="' . $show . '" type="' . Installments::get_type_of_widget() . '"></p24-installment>';
    }

    static function get_checkout_data()
    {
        $config = Config::get_instance();
        $total = isset(WC()->cart) ? (float) WC()->cart->total : 0.0;
        $should_show = Installments::is_amount_in_installment_widget_range($total);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                'CHECKOUT TOTAL: ' . $total .
                ' | MIN: ' . Installments::get_min_product_price() .
                ' | MAX: ' . Installments::get_max_product_price() .
                ' | SHOW: ' . ($should_show ? 'YES' : 'NO')
            );
        }

        return [
            'config' => [
                'sign' => Installments::get_signature(),
                'posid' => (string) $config->get_merchant_id(),
                'method' => (string) Payment_Methods::P24_INSTALLMENTS,
                'amount' => Helper::to_lowest_unit((float) $total),
                'currency' => $config->get_currency(),
                'lang' => 'pl',
                'test' => !$config->is_live(),
                'cms' => 'woo',
                'position' => Installments::get_product_widget_position() ?? 'before',
                'where' => 'checkout'
            ],
            'show' => $should_show,
            'showSimulator' => $should_show,
            'widgetType' => Installments::get_type_of_widget()
        ];
    }


    static function get_product_data()
    {
        $product = wc_get_product(get_the_ID());
        $config = Config::get_instance();

        if (!is_product() || !$product) {
            return [];
        }

        if ($product->get_type() === Product::TYPE) {
            return [
                'show' => false,
                'showSimulator' => false,
            ];
        }

        $price = (float) wc_get_price_including_tax($product);

        return [
            'config' => [
                'sign' => Installments::get_signature(),
                'posid' => (string) $config->get_merchant_id(),
                'method' => (string) Payment_Methods::P24_INSTALLMENTS,
                'amount' => Helper::to_lowest_unit($price),
                'currency' => $config->get_currency(),
                'lang' => 'pl',
                'test' => !$config->is_live(),
                'cms' => 'woo',
                'position' => Installments::get_product_widget_position() ?? 'before',
                'where' => 'product'
            ],
            'show' => Installments::is_amount_in_installment_widget_range($price),
            'showSimulator' => Installments::show_simulator(),
            'widgetType' => Installments::get_type_of_widget() ?? 'mini'
        ];
    }
}
