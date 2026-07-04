<?php

namespace WC_P24\Subscriptions;

use WC_P24\Models\Database\Subscription;
use WC_P24\Subscriptions\Product\Product;
use WC_P24\Utilities\Ajax;

class Export
{
    const ACTION = 'subscription-export';

    public function __construct()
    {
        Ajax::add_action(self::ACTION, [$this, 'export'], true);
    }

    public static function get_url(): string
    {
        $args = ['action' => self::ACTION];

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        if ($status) {
            $args['status'] = $status;
        }

        $url = add_query_arg($args, admin_url('admin-ajax.php'));

        return wp_nonce_url($url, self::ACTION);
    }

    private static function get_where_for_status(string $status_filter): array
    {
        $now = new \DateTime();
        $retry_days = Subscriptions::retry_days();
        $retry_from = new \DateTime();
        $retry_from->modify('-' . $retry_days . ' days');

        $where_options = [
            'active' => [
                't.status = %d AND t.valid_to > %s',
                Subscription::STATUS_ACTIVE,
                $now->format('Y-m-d H:i:s'),
            ],
            'overdue' => [
                't.status = %d AND t.valid_to < %s AND t.valid_to >= %s',
                Subscription::STATUS_ACTIVE,
                $now->format('Y-m-d H:i:s'),
                $retry_from->format('Y-m-d H:i:s'),
            ],
            'pending' => ['t.status = %d', Subscription::STATUS_PENDING],
            'processing' => ['t.status = %d', Subscription::STATUS_PROCESSING],
            'suspended' => ['t.status = %d', Subscription::STATUS_SUSPENDED],
            'cancelled' => ['t.status = %d', Subscription::STATUS_CANCELLED],
            'inactive' => [
                't.valid_to < %s AND t.status NOT IN (%d, %d, %d)',
                $now->format('Y-m-d H:i:s'),
                Subscription::STATUS_SUSPENDED,
                Subscription::STATUS_CANCELLED,
                Subscription::STATUS_ACTIVE,
            ],
        ];

        return $where_options[$status_filter] ?? [];
    }

    private static function get_export_status_slug(): string
    {
        $statuses = ['active', 'pending', 'processing', 'suspended', 'cancelled', 'inactive'];

        if (Subscriptions::retry_days() > 0) {
            $statuses[] = 'overdue';
        }

        $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';

        if ($status_filter === '' || !in_array($status_filter, $statuses, true)) {
            return 'all';
        }

        return $status_filter;
    }

    private static function format_export_status(Subscription $subscription): string
    {
        $status = $subscription->get_status_label();

        if ((int) $subscription->get_status() !== Subscription::STATUS_CANCELLED) {
            return $status;
        }

        $cancelled_by = $subscription->get_cancelled_by_label();

        if (!$cancelled_by) {
            return $status;
        }

        return $status . ' (' . $cancelled_by . ')';
    }

    public function export()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied', 'woocommerce-p24'), 403);
        }

        check_ajax_referer(self::ACTION);

        $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $where = self::get_where_for_status($status_filter);

        $subscriptions = Subscription::findAll([
            'select' => ['t.*', 'user.user_nicename AS customer_name', 'user.user_email AS customer_email', 'product.post_title AS product_title'],
            'where'  => $where,
            'order'  => 't.valid_to, product_title, customer_email, t.id',
        ]);

        $filename = 'p24_subscriptions_export_' . self::get_export_status_slug() . '_' . date('d-m-Y_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $stdout = fopen('php://output', 'w');

        fputs($stdout, "\xEF\xBB\xBF");

        fputcsv($stdout, [
            __('ID', 'woocommerce-p24'),
            __('User', 'woocommerce-p24'),
            __('Email', 'woocommerce-p24'),
            __('Frequency', 'woocommerce-p24'),
            __('Subscription start', 'woocommerce-p24'),
            __('Initial Order', 'woocommerce-p24'),
            __('Renewals', 'woocommerce-p24'),
            __('Last checked', 'woocommerce-p24'),
            __('Next payment', 'woocommerce-p24'),
            __('Product', 'woocommerce-p24'),
            __('Status', 'woocommerce-p24'),
        ]);

        foreach ($subscriptions as $row) {
            $valid_to = $row->get_valid_to();
            $valid_to_str = $valid_to ? wp_date('d-m-Y H:i', $valid_to->getTimestamp()) : '-';

            $product = $row->get_product();
            if ($product instanceof Product) {
                $days = $product->get_days();
                $frequency_text = sprintf(_n('Every %d day', 'Every %d days', $days, 'woocommerce-p24'), $days);
            } else {
                $frequency_text = '-';
            }

            $created_at = $row->get_created_at();
            $checked_at = $row->get_checked_at();

            $orders = wc_get_orders([
                'meta_key'   => '_p24_subscription_renew',
                'meta_value' => $row->get_id(),
                'status'     => ['completed', 'processing'],
                'return'     => 'ids',
                'limit'      => 1000,
            ]);

            fputcsv($stdout, [
                $row->get_id(),
                $row->customer_name,
                $row->customer_email,
                $frequency_text,
                $created_at ? wp_date('d-m-Y H:i', $created_at->getTimestamp()) : '-',
                $row->get_initial_order_id() ? '#' . $row->get_initial_order_id() : '-',
                count($orders),
                $checked_at ? wp_date('d-m-Y H:i', $checked_at->getTimestamp()) : '-',
                $valid_to_str,
                $row->product_title,
                self::format_export_status($row),
            ]);
        }

        exit;
    }
}
