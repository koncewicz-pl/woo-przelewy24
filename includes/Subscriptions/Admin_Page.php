<?php

namespace WC_P24\Subscriptions;

use WC_P24\Models\Database\Subscription;
use WC_P24\Render;
use WC_P24\Utilities\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Page
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_to_menu']);
        add_filter('woocommerce_order_item_display_meta_key', [$this, 'display_order_item_details'], 10, 3);

        add_action('pre_get_posts', [$this, 'filter_orders_by_subscription_cpt']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'filter_orders_by_subscription_hpos']);
    }

    public function add_to_menu(): void
    {
        add_submenu_page('woocommerce', __('P24 Subscriptions', 'woocommerce-p24'), __('P24 Subscriptions', 'woocommerce-p24'), 'manage_options', 'p24-subscriptions', [$this, 'render_page']);
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'woocommerce-p24'));
        }

        $this->handle_action();

        $table = new Listing();
        $table->prepare_items();

        Render::template('admin/subscriptions', [
            'table' => $table,
            'url' => Export::get_url(),
            'sync_url' => self::get_sync_statuses_url(),
            'sync_notice' => self::get_sync_notice(),
        ]);
    }

    public static function get_sync_statuses_url(): string
    {
        return wp_nonce_url(
            add_query_arg(
                ['page' => 'p24-subscriptions', 'p24_action' => 'sync_statuses'],
                admin_url('admin.php')
            ),
            'p24_sync_subscription_statuses'
        );
    }

    private static function get_sync_notice(): ?array
    {
        if (!isset($_GET['p24_synced'])) {
            return null;
        }

        $count = absint($_GET['p24_synced']);

        if ($count > 0) {
            return [
                'type' => 'success',
                'message' => sprintf(
                    /* translators: %d: number of subscriptions suspended */
                    _n(
                        '%d subscription suspended.',
                        '%d subscriptions suspended.',
                        $count,
                        'woocommerce-p24'
                    ),
                    $count
                ),
            ];
        }

        return [
            'type' => 'info',
            'message' => __('No subscriptions required status sync.', 'woocommerce-p24'),
        ];
    }

    private function handle_action(): void
    {
        $action = isset($_GET['p24_action']) ? sanitize_key($_GET['p24_action']) : '';

        if ($action === 'sync_statuses') {
            check_admin_referer('p24_sync_subscription_statuses');

            $count = Manager::sync_exhausted_subscription_statuses();
            Logger::log(sprintf('[P24 Admin] User %d synced subscription statuses (%d suspended).', get_current_user_id(), $count));

            $redirect = add_query_arg('p24_synced', $count, remove_query_arg(['p24_action', '_wpnonce']));
            wp_safe_redirect($redirect);
            exit;
        }

        $subscription_id = Helper::get_subscription_id_from_query();

        if (!$action || !$subscription_id) {
            return;
        }

        check_admin_referer('p24_subscription_action_' . $subscription_id);

        $subscription = Subscription::get($subscription_id);

        if (!$subscription) {
            return;
        }

        $user_id = get_current_user_id();

        switch ($action) {
            case 'resume':
                if ($subscription->resume()) {
                    Logger::log(sprintf('[P24 Admin] User %d manually resumed subscription #%d.', $user_id, $subscription_id));
                }
                break;

            case 'cancel':
                if ($subscription->admin_cancel()) {
                    Logger::log(sprintf('[P24 Admin] User %d manually cancelled subscription #%d.', $user_id, $subscription_id));
                }
                break;

            case 'suspend':
                if ($subscription->suspense()) {
                    Logger::log(sprintf('[P24 Admin] User %d manually suspended subscription #%d.', $user_id, $subscription_id));
                }
                break;

            case 'delete':
                if ($subscription->is_pending_deletable()) {
                    global $wpdb;
                    $wpdb->delete(Subscription::table_name(), ['id' => $subscription_id], ['%d']);
                    Logger::log(sprintf('[P24 Admin] User %d manually deleted pending subscription #%d.', $user_id, $subscription_id));
                }
                break;
        }

        $redirect = remove_query_arg(['p24_action', 'subscription_id', '_wpnonce']);
        wp_safe_redirect($redirect);
        exit;
    }

    public function display_order_item_details($display_key)
    {
        if ($display_key == '_p24_subscription_id') {
            $display_key = __('P24 Subscription ID', 'woocommerce-p24');
        }

        return $display_key;
    }

    private static function get_renewal_subscription_id_from_query(): ?int
    {
        if (empty($_GET['p24_sub_id'])) {
            return null;
        }

        $sub_id = absint($_GET['p24_sub_id']);

        return $sub_id > 0 ? $sub_id : null;
    }

    private static function get_subscription_meta_filter(): ?array
    {
        $sub_id = self::get_renewal_subscription_id_from_query();

        if (!$sub_id) {
            return null;
        }

        return [
            'key'   => '_p24_subscription_renew',
            'value' => $sub_id,
        ];
    }

    public function filter_orders_by_subscription_cpt($query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'shop_order') {
            return;
        }

        $filter = self::get_subscription_meta_filter();

        if ($filter) {
            $meta_query = (array) $query->get('meta_query');
            $meta_query[] = $filter;
            $query->set('meta_query', $meta_query);
        }
    }

    public function filter_orders_by_subscription_hpos(array $args): array
    {
        $filter = self::get_subscription_meta_filter();

        if ($filter) {
            $args['meta_query'][] = $filter;
        }

        return $args;
    }
}
