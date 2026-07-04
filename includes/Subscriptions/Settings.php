<?php

namespace WC_P24\Subscriptions;

use WC_P24\Utilities\Module_Settings;

class Settings extends Module_Settings
{
    public function get_handle(): string
    {
        return 'subscription';
    }

    public function get_label(): string
    {
        return __('Subscriptions', 'woocommerce-p24');
    }

    public function settings(): array
    {
        return [
            [
                'type' => 'title',
                'title' => __('Subscription configuration', 'woocommerce-p24'),
                'desc' => __('<h3>Configure settings for subscriptions</h3>', 'woocommerce-p24')
                    . __('<ol> <li><strong>Enable the Subscriptions Module</strong> - Make sure the <em>Subscriptions Module</em> is activated.</li> <li><strong>Create a New Product</strong> - Navigate to the <em>"Product data"</em> section.</li> <li><strong>Select the Product Type</strong> - Choose <em>"Subscription P24"</em> as the product type. (Product types define available product details and attributes.)</li> <li><strong>Set Subscription Frequency</strong> - Enter a numeric value in the default WooCommerce field labeled <em>"Download expiry"</em> to define the renewal period.</li> </ol> <p>Once saved, the product will function as a subscription with automatic renewals.</p>', 'woocommerce-p24')

                    . sprintf(
                        __('<p><strong>Renewal policy</strong>: The first charge is attempted on the subscription due date. If payment fails, the system retries for up to %d days (displayed as <em>Overdue</em>; enter <em>0</em> in settings to disable retries). After the retry window, the subscription is <em>Suspended</em> - you can <em>Resume</em> or <em>Cancel</em> it manually.</p>', 'woocommerce-p24'),
                        Subscriptions::retry_days()
                    )
            ],
            [
                'id' => Subscriptions::ENABLE_KEY,
                'type' => 'checkbox',
                'desc' => __('Enable subscriptions module', 'woocommerce-p24'),
                'info' => '<h3><svg class="p24-ui-icon" style="width:22px;"><use href="#p24-icon-info"></use></svg> ' . __('Subscription Setup Guide', 'woocommerce-p24') . '</h3> ' . __('<ol><li><strong>Create a New Product</strong> - Navigate to the <em>"Product data"</em> section.</li> <li><strong>Select the Product Type</strong> - Choose <em>"Subscription P24"</em> as the product type. (Product types define available product details and attributes.)</li> <li><strong>Set Subscription Frequency</strong> - Enter a numeric value in the default WooCommerce field labeled <em>"Download expiry"</em> to define the renewal period.</li> </ol> <p>Once saved, the product will function as a subscription with automatic renewals.</p><img src="https://www.przelewy24.pl/storage/app/media/do-pobrania/gotowe-wtyczki/woocommerce/helper/en_p24_setting-subscription1.png" alt="Subscription Setup Guide" style="max-width: 400px">', 'woocommerce-p24'),
                'default' => 'no'
            ],
            [
                'id' => Subscriptions::RETRY_DAYS_KEY,
                'type' => 'number',
                'title' => __('Number of days to retry charging after subscription due date', 'woocommerce-p24'),
                'desc' => __('If renewal payment fails on the due date, the system will retry for this many days. Enter 0 to disable retries. Maximum: 10.', 'woocommerce-p24'),
                'default' => Subscriptions::RETRY_DAYS_DEFAULT,
                'custom_attributes' => [
                    'min' => 0,
                    'max' => Subscriptions::RETRY_DAYS_MAX,
                    'step' => 1,
                ],
            ],
            [
                'type' => 'sectionend'
            ]
        ];
    }

    public function __construct($without_hooks = false)
    {
        parent::__construct($without_hooks);

        add_filter(
            'woocommerce_admin_settings_sanitize_option_' . Subscriptions::RETRY_DAYS_KEY,
            [Subscriptions::class, 'sanitize_retry_days']
        );
    }
}
