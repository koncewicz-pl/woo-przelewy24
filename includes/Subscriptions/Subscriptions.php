<?php

namespace WC_P24\Subscriptions;

if (!defined('ABSPATH')) {
    exit;
}

use WC_P24\Models\Database\Subscription;
use WC_P24\Subscriptions\Product\Initiator as Product_Initiator;
use WC_P24\Utilities\Module;

class Subscriptions extends Module
{
    const ENABLE_KEY = 'p24_subscription_enabled';

    const RETRY_DAYS_KEY = 'p24_subscription_retry_days';
    const RETRY_DAYS_DEFAULT = 3;
    const RETRY_DAYS_MAX = 10;

    public function __construct()
    {
        parent::__construct();

        $this->settings = new Settings();

        new Product_Initiator();

        add_action('admin_init', [Subscription::class, 'maybe_update_database_schema']);
        add_action('upgrader_process_complete', [Subscription::class, 'maybe_update_database_schema']);

        if ($this->is_enabled()) {
            new Filter_Gateways();
            new Export();
            new Manager();
            new Client_Page();
        }
    }

    public static function is_enabled(): bool
    {
        return get_option(self::ENABLE_KEY, 'no') === 'yes';
    }

    public static function retry_days(): int
    {
        $days = get_option(self::RETRY_DAYS_KEY, null);

        if ($days === null) {
            return self::RETRY_DAYS_DEFAULT;
        }

        $days = (int) $days;

        if ($days < 0) {
            $days = 0;
        }

        if ($days > self::RETRY_DAYS_MAX) {
            $days = self::RETRY_DAYS_MAX;
        }

        return (int) apply_filters('wc_p24_subscription_retry_days', $days);
    }

    public static function sanitize_retry_days($value): int
    {
        $days = (int) $value;

        if ($days < 0) {
            return 0;
        }

        return min($days, self::RETRY_DAYS_MAX);
    }

    /** @deprecated Charge on due date only. */
    public static function days_to_renew(): int
    {
        return 0;
    }

    protected function on_client(): void
    {
    }

    protected function on_admin(): void
    {
        new Admin_Page();
    }
}
