<?php

namespace WC_P24\Subscriptions;

use WC_P24\Models\Database\Subscription;
use WC_P24\Subscriptions\Product\Product;

if (!defined('ABSPATH')) {
    exit;
}

class User_Subscription_Helper
{
    const STATUS_GUEST = 'guest';
    const STATUS_NO_SUBSCRIPTION = 'no_subscription';
    const STATUS_HAS_SUBSCRIPTION = 'has_subscription';

    const ACCOUNT_ENDPOINT = 'p24-subscriptions';

    /**
     * Get subscription status for current user and product
     *
     * @param Product $product
     * @return string One of: guest, no_subscription, has_subscription
     */
    public static function get_user_subscription_status(Product $product): string
    {
        if (!is_user_logged_in()) {
            return self::STATUS_GUEST;
        }

        $current_user_id = get_current_user_id();
        $product_id = $product->get_id();

        // Check if user has active subscription for this product
        $subscription = self::get_user_active_subscription($current_user_id, $product_id);

        if ($subscription) {
            return self::STATUS_HAS_SUBSCRIPTION;
        }

        return self::STATUS_NO_SUBSCRIPTION;
    }

    /**
     * Get active subscription for user and product
     *
     * @param int $user_id
     * @param int $product_id
     * @return Subscription|null
     */
    public static function get_user_active_subscription(int $user_id, int $product_id): ?Subscription
    {
        $subscriptions = Subscription::findAll([
            'where' => [
                't.user_id = %d AND t.product_id = %d AND t.status IN (%d, %d)',
                $user_id,
                $product_id,
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_PROCESSING
            ],
            'limit' => 1
        ]);

        return !empty($subscriptions) ? $subscriptions[0] : null;
    }

    /**
     * Get all active subscriptions for user
     *
     * @param int $user_id
     * @return array Array of Subscription objects
     */
    public static function get_user_active_subscriptions(int $user_id): array
    {
        return Subscription::findAll([
            'where' => [
                't.user_id = %d AND t.status IN (%d, %d)',
                $user_id,
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_PROCESSING
            ]
        ]);
    }

    /**
     * Get button text and URL based on subscription status
     *
     * @param Product $product
     * @return array{text: string, url: string, class: string}
     */
    public static function get_button_config(Product $product): array
    {
        $status = self::get_user_subscription_status($product);

        switch ($status) {
            case self::STATUS_GUEST:
                return [
                    'text' => __('Log in to start a subscription', 'woocommerce-p24'),
                    'url' => wp_login_url($product->get_permalink()),
                    'class' => 'subscription-button subscription-button--login'
                ];

            case self::STATUS_NO_SUBSCRIPTION:
                return [
                    'text' => __('Start subscription', 'woocommerce-p24'),
                    'url' => add_query_arg(['add-to-cart' => $product->get_id()], $product->get_permalink()),
                    'class' => 'subscription-button subscription-button--start'
                ];

            case self::STATUS_HAS_SUBSCRIPTION:
                $account_url = wc_get_account_endpoint_url(self::ACCOUNT_ENDPOINT);
                return [
                    'text' => __('Manage subscription', 'woocommerce-p24'),
                    'url' => $account_url ?: $product->get_permalink(),
                    'class' => 'subscription-button subscription-button--manage'
                ];

            default:
                return [
                    'text' => __('Learn more', 'woocommerce-p24'),
                    'url' => $product->get_permalink(),
                    'class' => 'subscription-button'
                ];
        }
    }

    /**
     * Check if user can manage their subscriptions
     *
     * @param int $user_id
     * @return bool
     */
    public static function has_any_subscription(int $user_id): bool
    {
        $subscriptions = Subscription::findAll([
            'where' => [
                't.user_id = %d',
                $user_id
            ],
            'limit' => 1
        ]);

        return !empty($subscriptions);
    }
}



