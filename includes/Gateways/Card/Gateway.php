<?php

namespace WC_P24\Gateways\Card;

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;
use WC_P24\API\Resources\Card_Resource;
use WC_P24\Config;
use WC_P24\Core;
use WC_P24\Gateways\Fee;
use WC_P24\Gateways\Gateways_Manager;
use WC_P24\Gateways\Payment_Helpers;
use WC_P24\Gateways\Settings_Helper;
use WC_P24\Helper;
use WC_P24\Models\Database\Reference;
use WC_P24\Models\Simple\Card_Simple;
use WC_P24\Models\Transaction;
use WC_P24\Utilities\Payment_Methods;
use WC_P24\Utilities\Sanitizer;
use WC_P24\Utilities\Validator;
use WC_P24\Utilities\Webhook;
use WC_Payment_Gateway;

class Gateway extends WC_Payment_Gateway
{
    use Payment_Helpers;
    use Settings_Helper;
    use Card_Legacy_Support;

    public ?Webhook $webhooks = null;

    public function __construct()
    {
        $this->id = Core::CARD_IN_SHOP_METHOD;
        $this->icon = apply_filters('woocommerce_gateway_icon', WC_P24_PLUGIN_URL . 'assets/card.svg', $this->id);
        $this->method = Payment_Methods::CARD_PAYMENT;
        $this->method_alt = Payment_Methods::CARD_PAYMENT_ALT;
        $this->description = $this->get_option('description');
        $this->supports = ['products', 'refunds', 'p24-subscription'];
        $this->method_title = __('Przelewy24 - Card payment', 'woocommerce-p24');
        /* translators: %s: URL to Przelewy24 general settings in WooCommerce admin. */
        $this->method_description = sprintf(__('Card payment option on the shop <br /><a href="%s">General configuration</a>', 'woocommerce-p24'), Core::get_settings_url());
        $this->title = $this->get_option('title') ?: __('Card payment', 'woocommerce-p24');

        new Fee($this);
        $this->webhooks = new Card_Webhooks($this);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_rest_checkout_process_payment_with_context', [$this, 'process_payment_rest'], 10, 2);
        add_action('przelewy24_after_verify_transaction', [$this, 'save_card_reference'], 10, 1);

        $this->init_form_fields();
        $this->init_legacy();
    }

    public function one_click_enabled(): bool
    {
        return $this->get_option('one_click_enabled') == 'yes';
    }

    public function click_to_pay_enabled(): bool
    {
        $enabled = $this->get_option('c2p_enabled') == 'yes';
        $for = $this->get_option('c2p_for');
        $result = $enabled;

        if ($for === 'logged_in') {
            $result = is_user_logged_in() ? $enabled : false;
        } elseif ($for === 'logged_out') {
            $result = is_user_logged_in() ? false : $enabled;
        }

        $result = is_user_logged_in() && $this->one_click_enabled() ? false : $result;

        return $result;
    }

    public function init_form_fields(): void
    {
        $this->form_fields = array_merge([
            'enabled' => [
                'type' => 'checkbox',
                'label' => __('Enable Przelewy24 - card payments in-store', 'woocommerce-p24'),
                'custom_attributes' => ['data-enabled' => true],
                'info' => '<svg class="p24-ui-icon" style="width:22px;"><use href="#p24-icon-info"></use></svg> ' . __('<h3>Card Payments</h3>Enabling this option allows customers to pay directly using their credit or debit cards within the store. The payment form is securely embedded on the website, ensuring a seamless and user-friendly experience for buyers. <br/><br/><strong>Requirements:</strong><ul><li>Before enabling this option, please contact Przelewy24 to activate the necessary services on your account.</li><li>Ensure your store has an SSL certificate to secure customer data.</li></ul><br/><em>Example view for the buyer:</em><br/><img src="https://www.przelewy24.pl/storage/app/media/do-pobrania/gotowe-wtyczki/woocommerce/helper/en_karta_lvl0.png" alt="Card payment example" style="max-width: 400px">.', 'woocommerce-p24'),
                'information' => [
                    'type' => 'description',
                    'description' => __('Enabling this option allows for card payments directly within the store using a dedicated card form displayed on the website.', 'woocommerce-p24'),
                ]
            ],
            'title' => [
                'type' => 'text',
                'title' => __('Payment title', 'woocommerce-p24'),
                'description' => __('Name of payment visible at checkout', 'woocommerce-p24'),
                'default' => __('Card payment', 'woocommerce-p24'),
            ],
            'description' => [
                'type' => 'textarea',
                'title' => __('Description', 'woocommerce-p24'),
                'description' => __('Description of payment which the user sees during checkout', 'woocommerce-p24')
            ],
            'c2p_enabled' => [
                'type' => 'checkbox',
                'title' => __('Click to Pay enabled', 'woocommerce-p24'),
                'label' => __('Enable Click to Pay functionality', 'woocommerce-p24'),
                'default' => 'no',
            ],
            'c2p_for' => [
                'type' => 'select',
                'title' => __('Click to Pay enabled for', 'woocommerce-p24'),
                'default' => 'both',
                'options' => [
                    'both' => __('both, logged and non-logged users', 'woocommerce-p24'),
                    'logged_in' => __('logged users', 'woocommerce-p24'),
                    'logged_out' => __('non-logged users', 'woocommerce-p24')
                ],
            ],
            'one_click_enabled' => [
                'type' => 'checkbox',
                'title' => __('One Click Card Payment', 'woocommerce-p24'),
                'label' => __('Enable one click card payment', 'woocommerce-p24'),
                'description' => __('If enabled, prevents use of Click to Pay for logged users', 'woocommerce-p24'),
                'info' => '<svg class="p24-ui-icon" style="width:22px;"><use href="#p24-icon-info"></use></svg> ' . __('<h3>One Click Card Payment</h3>Enabling this option allows for card payments using a saved alias. This functionality is available only for logged-in buyers who have an account in the store. It becomes accessible after the customer\'s first payment, provided they have agreed to save their card details. <br/><strong>Before turning this option on, please contact P24 to enable the necessary services on your account for on-site card payments.</strong><br/><br/><em>Example view for the buyer:</em><br/><img src="https://www.przelewy24.pl/storage/app/media/do-pobrania/gotowe-wtyczki/woocommerce/helper/en_karta_oneclick.png" alt="One Click Card Payment" style="max-width: 400px">', 'woocommerce-p24'),
                'default' => 'no',
            ],
            'styles' => [
                'type' => 'code',
                'title' => __('Styling options', 'woocommerce-p24'),
                'default' => '{
                    "lang": "pl",
                    "loader": true,
                    "errorMessage": false,
                    "agreement": {
                        "contentEnabled": { "enabled": false, "checkboxEnabled": false }
                    }
                }',
                /* translators: %s: URL to Przelewy24 developer documentation for card widget styling (JSON). */
                'description' => sprintf(__('Widget styling settings in JSON format, detailed documentation available <a href="%s" target="_blank">here</a>', 'woocommerce-p24'), 'https://developers.przelewy24.pl/extended/index.php?pl#tag/Inicjalizacja-formularza/Stylowanie-oraz-opcje-formularza'),
            ]
        ], $this->fee_settings());
    }

    public function save_card_reference(Transaction $transaction): void
    {
        $order = $transaction->order;
        $save_reference = $order->get_meta('_p24_save_card', true);

        if ($save_reference) {
            $resource = new Card_Resource();
            $response = $resource->get_info($transaction->get_order_id());

            if (!empty($response['data'])) {
                $card = new Card_Simple($response['data']);
                Reference::save_reference($card, $order);
                $order->delete_meta_data('_p24_save_card');
            }
        }
    }

    private function sanitize(array $data): array
    {
        $sanitizer = new Sanitizer($data, [
            'regulation' => FILTER_VALIDATE_BOOLEAN,
            'type' => Sanitizer::sanitize_key_as_filter(),
            'save' => FILTER_VALIDATE_BOOLEAN,
            'oneclick' => FILTER_SANITIZE_NUMBER_INT,
            'recurringConsent' => FILTER_VALIDATE_BOOLEAN,
            'recurringConsentAt' => Sanitizer::sanitize_string_as_filter(),
        ]);

        return $sanitizer->run();
    }

    private function validate(array $data): void
    {
        $validator = new Validator();

        if (!$data['regulation']) {
            $validator->add_error('You have to accept the terms and conditions for using this method');
        }

        if (empty($data['type'])) {
            $validator->add_error('Transaction type not provided');
        }

        if (!in_array($data['type'], ['card-data', 'one-click'])) {
            $validator->add_error('Wrong transaction type');
        }

        if ($data['type'] === 'one-click' && empty($data['oneclick'])) {
            $validator->add_error('One click id not provided');
        }

        if ($validator->has_errors()) {
            throw new \Exception($validator->get_first_error());
        }
    }

    /**
     * @param array<string, mixed> $payment_data Raw payment payload (before narrowing to sanitized keys).
     */
    private function validate_card_ref_id(array $payment_data): void
    {
        $ref_id = $this->extract_card_ref_id($payment_data);

        if ($ref_id === '') {
            throw new \Exception(
                __('Card tokenization reference (refId) is missing. Please tokenize the card again and retry payment.', 'woocommerce-p24')
            );
        }
    }

    /**
     * @param array<string, mixed> $payment_data
     */
    private function extract_card_ref_id(array $payment_data): string
    {
        foreach (['refId', 'refid', 'ref_id'] as $key) {
            if (!empty($payment_data[$key]) && is_scalar($payment_data[$key])) {
                return Sanitizer::sanitize_token((string) $payment_data[$key]);
            }
        }

        if (!empty($payment_data['cardData'])) {
            $card_data = $payment_data['cardData'];
            if (is_string($card_data)) {
                $decoded = json_decode($card_data, true);
                $card_data = is_array($decoded) ? $decoded : [];
            }
            if (is_array($card_data)) {
                foreach (['refId', 'refid', 'ref_id'] as $key) {
                    if (!empty($card_data[$key]) && is_scalar($card_data[$key])) {
                        return Sanitizer::sanitize_token((string) $card_data[$key]);
                    }
                }
            }
        }

        return '';
    }

    /**
     * Store API may send booleans or integers; filter_var( ..., FILTER_VALIDATE_BOOLEAN ) mishandles those.
     */
    private function normalize_payment_boolean_flags(array &$data): void
    {
        foreach (['regulation', 'save', 'recurringConsent'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            if (is_bool($v)) {
                $data[$key] = $v ? '1' : '0';
            } elseif (is_int($v) || is_float($v)) {
                $data[$key] = ((int) $v) !== 0 ? '1' : '0';
            }
        }
    }

    public function payment(\WC_Order $order, array $payment_data = []): array
    {
        if (!empty($payment_data['paymentMethodData']) && is_array($payment_data['paymentMethodData'])) {
            $flat = [];
            foreach ($payment_data['paymentMethodData'] as $item) {
                if (!is_array($item)) continue;
                if (!array_key_exists('key', $item)) continue;
                $k = $item['key'];
                $v = array_key_exists('value', $item) ? $item['value'] : '';
                if (is_array($v) || is_object($v)) {
                    $v = json_encode($v);
                }
                if (is_bool($v)) {
                    $v = $v ? '1' : '0';
                }
                $flat[$k] = $v;
            }
            $payment_data = array_merge($payment_data, $flat);
        }

        $card_data = $payment_data;

        $this->normalize_payment_boolean_flags($payment_data);

        $payment_data = $this->sanitize($payment_data);

        if (Helper::order_has_subscription_product($order)) {
            $subscription_consent = !empty($payment_data['recurringConsent']) || !empty($payment_data['save']);
            if (!$subscription_consent) {
                throw new \Exception(
                    __('You must agree to recurring card charges for a subscription order.', 'woocommerce-p24')
                );
            }
        }

        $this->validate($payment_data);

        if ($payment_data['type'] === 'card-data') {
            $this->validate_card_ref_id($card_data);
        }

        $result = [
            'redirect' => $order->get_checkout_order_received_url(),
            'status'   => 'pending'
        ];

        $type = $payment_data['type'];
        $save = $payment_data['save'];
        $recurring_consent = !empty($payment_data['recurringConsent']);
        $recurring_consent_at = !empty($payment_data['recurringConsentAt']) ? sanitize_text_field($payment_data['recurringConsentAt']) : '';

        if (Helper::order_has_subscription_product($order) && ($save || $recurring_consent)) {
            $order->update_meta_data('_p24_recurring_consent', 'yes');

            if ($recurring_consent && $recurring_consent_at) {
                $order->update_meta_data('_p24_recurring_consent_at', $recurring_consent_at);
            } else {
                $order->update_meta_data('_p24_recurring_consent_at', current_time('mysql'));
            }

            $order->save();
        }

        $accept_rules = $payment_data['regulation'];
        $method_id = Gateways_Manager::get_method_id_matching_group(Payment_Methods::CARD_PAYMENT_ALT, $order->get_total());

        $transaction = new Transaction($order->get_id(), $method_id, $accept_rules);

        $card_payment_type = Transaction::CARD_STANDARD;

        // Recurring registration (first charge for a subscription product): P24 expects transactionType `initial`,
        // whether the shopper tokenizes a new card (`card-data`) or pays with a saved reference (`one-click`).
        if (Helper::order_has_subscription_product($transaction->order)) {
            $card_payment_type = Transaction::CARD_INITIAL;
        } elseif ($type === 'c2p') {
            $card_payment_type = Transaction::CARD_C2P;
        } elseif ($save && $type !== 'one-click') {
            $order->update_meta_data('_p24_save_card', true);
            $order->save_meta_data();
            $card_payment_type = Transaction::CARD_INITIAL;
        }

        // Orders created in Subscription::renew() carry this meta: merchant recurring debit uses `recurring` + doPayment.
        $is_subscription_renew_order = (bool) $order->get_meta('_p24_subscription_renew', true);
        $finalize_with_do_payment = false;

        switch ($type) {
            case 'card-data':
                $effective_card_type = $is_subscription_renew_order ? Transaction::CARD_RECURRING : $card_payment_type;
                $transaction->set_card(new Card_Simple($card_data), $effective_card_type);
                if ($is_subscription_renew_order) {
                    $finalize_with_do_payment = true;
                }
                break;

            case 'one-click':
                if (!$this->one_click_enabled()) {
                    throw new \Exception(__('One-click card payment is disabled in the gateway settings.', 'woocommerce-p24'));
                }
                $one_click_id = (int) $payment_data['oneclick'];
                $reference = Reference::get_and_check($one_click_id, (int) $order->get_customer_id());

                if (!$reference) {
                    throw new \Exception(__('The selected saved card is not available for this order or account.', 'woocommerce-p24'));
                }

                if ($is_subscription_renew_order) {
                    $transaction->set_card($reference->to_card_simple(), Transaction::CARD_RECURRING);
                    $finalize_with_do_payment = true;
                } else {
                    $transaction->set_one_click(Transaction::ONE_CLICK_CARD);
                    $type_to_set = $card_payment_type === Transaction::CARD_INITIAL ? Transaction::CARD_INITIAL : Transaction::CARD_ONE_CLICK;
                    $transaction->set_card($reference->to_card_simple(), $type_to_set);
                }
                break;
        }

        $transaction->register();
        if ($finalize_with_do_payment) {
            $transaction->do_payment();
        }
        // Whitelabel continues in the browser; renewals use doPayment above.

        $result['token'] = $transaction->get_token();
        $result['success'] = true;

        return $result;
    }

    public function process_payment_rest(PaymentContext $context, PaymentResult &$payment_result): void
    {
        if ($context->payment_method === Core::CARD_IN_SHOP_METHOD) {
            try {
                $details = $this->payment($context->order, $context->payment_data);
                $payment_result->set_payment_details($details);
                $payment_result->set_status($details['status']);
            } catch (\Exception $e) {
                $payment_result->set_payment_details(['message' => $e->getMessage()]);
                $payment_result->set_status('error');
            }
        }
    }

    public function get_receipt_config(): array
    {
        $get_one_clicks = is_checkout();
        $config = Config::get_instance();

        $settings = Card_Helper::generate_keys_for_tokenization();
        $options = json_decode($this->get_option('styles'));
        $settings['options'] = $options ?: [];

        $one_click_enabled = $this->one_click_enabled() && is_user_logged_in();
        $one_click_items = [];

        if ($get_one_clicks && $one_click_enabled) {
            $current_user = wp_get_current_user();

            $one_click_items = Reference::findAll([
                'where' => ["user.ID = %d AND t.type != 'blik'", (int)$current_user->ID]
            ]);

            $one_click_items = array_map(function ($card) {
                return [
                    'id'         => $card->get_id(),
                    'type'       => $card->get_type(),
                    'last_digits'=> $card->get_info(),
                    'valid_to'   => $card->get_valid_to()->format('m/Y'),
                    'logo'       => $card->get_icon()
                ];
            }, $one_click_items);
        }

        $recurring = Helper::cart_has_subscription_product();

        return [
            'mode' => $config->get_mode_prefix(),
            'lang' => Helper::get_language(),
            'config' => $settings,
            'i18n' => [
                'label' => [
                    'save' => __('Save card reference for future payments', 'woocommerce-p24'),
                    'save_oneclick'  => __('Store my card for convenient future checkouts.', 'woocommerce-p24'),
                    'save_recurring' => __('I agree to save my card and authorize future recurring charges in accordance with the terms and conditions.', 'woocommerce-p24'),
                    'submit' => __('Pay by card', 'woocommerce-p24'),
                    'regulation' => sprintf(
                        __('I hereby state that I have read the <a href="%1$s" target="_blank">regulations</a> and <a href="%2$s" target="_blank">information obligation</a> of "Przelewy24"',
                            'woocommerce-p24'),
                        Core::get_rules_url(),
                        Core::get_tos_url()
                    ),
                ],
                'error' => [
                    'generic' => _x(
                        'We could not complete this payment. Please try again or choose a different payment method.',
                        'Shown when payment or 3DS fails without a specific reason',
                        'woocommerce-p24'
                    ),
                    'rules' => __('You have to accept the terms and conditions for using the chosen method', 'woocommerce-p24'),
                    'recurring_consent_required' => __('You must agree to recurring card charges to purchase a subscription.', 'woocommerce-p24'),
                ],
                'use_saved' => __('Use saved payment methods - on-click', 'woocommerce-p24'),
                'or' => __('or use new card details', 'woocommerce-p24'),
                'use_new_card' => __('Use new card', 'woocommerce-p24'),
                'waiting_3ds' => __('Please wait… we are preparing the payment verification. Do not close this window - the confirmation screen will appear shortly.', 'woocommerce-p24'),
            ],
            'recurring' => $recurring,
            'hasSubscription' => $recurring,
            'oneClick' => [
                'enabled' => $one_click_enabled,
                'items' => $one_click_items
            ],
            'clickToPay' => [
                'enabled' => $this->click_to_pay_enabled(),
                'email' => ''
            ]
        ];
    }
}
