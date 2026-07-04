<?php

namespace WC_P24\Subscriptions;

use WC_P24\Assets;
use WC_P24\Models\Database\Subscription;
use WC_P24\Render;
use WC_P24\Subscriptions\Product\Product;
use WC_P24\Utilities\Account_Page;
use WC_P24\Utilities\Ajax;

if (!defined('ABSPATH')) {
    exit;
}

class Client_Page extends Account_Page
{
    public function __construct()
    {
        add_filter('woocommerce_customer_get_downloadable_products', [$this, 'filter_downloads'], 9999);
        add_filter('woocommerce_my_account_my_orders_query', [$this, 'filter_orders_by_subscription']);
        add_action('woocommerce_before_account_orders', [$this, 'display_subscription_orders_header']);
        Ajax::add_action('cancel_subscription', [$this, 'cancel_subscription'], true);
        $this->assets();

        parent::__construct();
    }

    protected function get_subscription_id_from_query(): int
    {
        return Helper::get_subscription_id_from_query();
    }

    public function filter_orders_by_subscription(array $args): array
    {
        $subscription_id = $this->get_subscription_id_from_query();

        if (!$subscription_id || !is_user_logged_in()) {
            return $args;
        }

        $subscription = Subscription::get($subscription_id);

        if (!$subscription || $subscription->get_customer_id() !== get_current_user_id()) {
            return $args;
        }

        global $wpdb;

        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT oi.order_id
             FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
             WHERE oim.meta_key = '_p24_subscription_id' AND oim.meta_value = %d",
            $subscription_id
        ));

        $user_id = get_current_user_id();
        $order_ids = array_values(array_filter(array_map('absint', $order_ids), function (int $order_id) use ($user_id): bool {
            $order = wc_get_order($order_id);

            return $order instanceof \WC_Order && (int) $order->get_customer_id() === $user_id;
        }));

        $ids = !empty($order_ids) ? $order_ids : [0];
        $args['post__in'] = $ids;
        $args['include'] = $ids;

        return $args;
    }

    public function display_subscription_orders_header(): void
    {
        $subscription_id = $this->get_subscription_id_from_query();

        if (!$subscription_id || !is_user_logged_in()) {
            return;
        }

        $subscription = Subscription::get($subscription_id);

        if (!$subscription || $subscription->get_customer_id() !== get_current_user_id()) {
            return;
        }

        $product = $subscription->get_product();
        $product_title = $product ? $product->get_name() : __('Subscription', 'woocommerce-p24');
        $orders_url = wc_get_account_endpoint_url('orders');
        $subscriptions_url = wc_get_account_endpoint_url($this->tab_name());

        echo '<p class="p24-subscription-orders-header">';
        echo esc_html__('Showing orders for subscription:', 'woocommerce-p24') . ' ';
        echo '<strong>' . esc_html($product_title) . '</strong>. ';
        echo '<a href="' . esc_url($orders_url) . '">' . esc_html__('View all orders', 'woocommerce-p24') . '</a>';
        echo ' | <a href="' . esc_url($subscriptions_url) . '">' . esc_html__('Back to subscriptions', 'woocommerce-p24') . '</a>';
        echo '</p>';
    }

    public function cancel_subscription()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Permission denied', 'woocommerce-p24')], 403);
        }

        check_ajax_referer('cancel_subscription', 'nonce');

        $user_id = get_current_user_id();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if (!$id) {
            wp_send_json_error(['message' => __('Subscription cannot be cancelled', 'woocommerce-p24')]);
        }

        $subscriptions = Subscription::findAll([
            'where' => ['user.ID = %d AND t.id = %d', $user_id, $id],
            'limit' => 1
        ]);

        if (empty($subscriptions[0])) {
            $message = __('Subscription cannot be cancelled', 'woocommerce-p24');
            wc_add_notice($message, 'error');
            wp_send_json_error(['error' => true, 'message' => $message]);
            exit;
        }

        $subscription = $subscriptions[0];

        if ($subscription->cancel()) {
            $message = __('Subscription was successfully cancelled', 'woocommerce-p24');
            wc_add_notice($message);
            wp_send_json_success(['success' => true, 'message' => $message]);
        }

        $message = __('This subscription cannot be cancelled in its current status.', 'woocommerce-p24');
        wc_add_notice($message, 'error');
        wp_send_json_error(['error' => true, 'message' => $message]);
    }

    public function add_tab_name($items)
    {
        if (!is_user_logged_in() || !User_Subscription_Helper::has_any_subscription(get_current_user_id())) {
            return $items;
        }

        return parent::add_tab_name($items);
    }


    public function tab_name(): string
    {
        return 'p24-subscriptions';
    }

    public function filter_downloads(array $downloads): array
    {
        foreach ($downloads as $key => $download) {
            $product = wc_get_product($download['product_id']);

            if ($product && $product->get_type() === Product::TYPE) {
                unset($downloads[$key]);
            }
        }

        return $downloads;
    }

    public function render_page(): void
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        $user_id = get_current_user_id();

        if (!User_Subscription_Helper::has_any_subscription($user_id)) {
            wp_safe_redirect(wc_get_account_endpoint_url('dashboard'));
            exit;
        }

        $subscriptions = Subscription::findAll([
            'select' => ['t.*', 'product.post_title AS product_title'],
            'where' => ['user.ID = %d', $user_id]
        ]);

        $nonce = wp_create_nonce('cancel_subscription');

        Render::template('subscription/account-tab', ['subscriptions' => $subscriptions, 'nonce' => $nonce], true);
    }

    public function assets()
    {
        Assets::add_script_asset('przelewy24-account-subscriptions-js', 'account-subscriptions.js', false, function () {
            if (!is_user_logged_in() || !is_account_page()) {
                return false;
            }

            return is_wc_endpoint_url($this->tab_name())
                || (is_wc_endpoint_url('orders') && $this->get_subscription_id_from_query() > 0);
        });

        Assets::add_script_localize('przelewy24-account-subscriptions-js', 'przelewy24SubscriptionsParams', [
            'url' => add_query_arg(['action' => 'cancel_subscription'], admin_url('admin-ajax.php')),
            'confirmCancel' => __('Are you sure you want to cancel this subscription?', 'woocommerce-p24'),
        ]);
    }

    public function get_title(): string
    {
        return _x('P24 Subscriptions', 'customer account page title', 'woocommerce-p24');
    }

    public function get_menu_label(): string
    {
        return _x('P24 Subscriptions', 'customer account menu label', 'woocommerce-p24');
    }

}
