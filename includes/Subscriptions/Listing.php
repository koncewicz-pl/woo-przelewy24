<?php

namespace WC_P24\Subscriptions;

use WC_P24\Models\Database\Subscription;
use WC_P24\Subscriptions\Product\Product;

if (!defined('ABSPATH')) {
    exit;
}

class Listing extends \WP_List_Table
{
    public function get_columns()
    {
        return [
            'cb'        => '<input type="checkbox" />',
            'id'        => __('ID', 'woocommerce-p24'),
            'user'      => __('User', 'woocommerce-p24'),
            'product'   => __('Product', 'woocommerce-p24'),
            'frequency' => __('Frequency', 'woocommerce-p24'),
            'created'   => __('Subscription start', 'woocommerce-p24'),
            'order'     => __('Initial Order', 'woocommerce-p24'),
            'renewals'  => __('Renewals', 'woocommerce-p24'),
            'checked'   => __('Last checked', 'woocommerce-p24'),
            'valid'     => __('Next payment', 'woocommerce-p24'),
            'status'    => __('Status', 'woocommerce-p24'),
            'actions'   => __('Actions', 'woocommerce-p24'),
        ];
    }

    public function column_default($item, $column_name)
    {
        $result = '';

        switch ($column_name) {
            case 'user':
                $result = '<div style="min-width: 0; word-wrap: break-word;"><strong>' . esc_html($item->customer_name) . '</strong><br>'
                    . '<a href="mailto:' . esc_attr($item->customer_email) . '" style="color: #666; text-decoration: none; overflow-wrap: break-word;">'
                    . esc_html($item->customer_email) . '</a></div>';
                break;

            case 'product':
                $result = '<a href="' . esc_url(get_edit_post_link($item->get_product_id())) . '" class="row-title"><strong>' . esc_html($item->product_title) . '</strong></a>';
                break;

            case 'frequency':
                $product = $item->get_product();
                if ($product instanceof Product) {
                    $days = $product->get_days();
                    $result = esc_html(sprintf(_n('Every %d day', 'Every %d days', $days, 'woocommerce-p24'), $days));
                } else {
                    $result = '-';
                }
                break;

            case 'created':
                $result = $this->format_date_time_cell($item->get_created_at());
                break;

            case 'order':
                $initial_order_id = $item->get_initial_order_id();
                if ($initial_order_id) {
                    $order = wc_get_order($initial_order_id);
                    $edit_url = $order ? $order->get_edit_order_url() : get_edit_post_link($initial_order_id);
                    $result = sprintf(
                        '<a href="%s" class="row-title" target="_blank">#%s</a>',
                        esc_url($edit_url),
                        esc_html($initial_order_id)
                    );
                } else {
                    $result = '-';
                }
                break;

            case 'renewals':
                $sub_id = $item->get_id();
                $orders = wc_get_orders([
                    'meta_key'   => '_p24_subscription_renew',
                    'meta_value' => $sub_id,
                    'status'     => ['completed', 'processing'],
                    'return'     => 'ids',
                    'limit'      => -1,
                ]);
                $renewals_count = count($orders);

                if ($renewals_count > 0) {
                    if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                        $orders_url = admin_url('admin.php?page=wc-orders');
                    } else {
                        $orders_url = admin_url('edit.php?post_type=shop_order');
                    }

                    $url = add_query_arg('p24_sub_id', $sub_id, $orders_url);
                    $result = sprintf('<a href="%s">%d</a>', esc_url($url), esc_html($renewals_count));
                } else {
                    $result = '0';
                }
                break;

            case 'checked':
                $result = $this->format_date_time_cell($item->get_checked_at());
                break;

            case 'valid':
                $valid_to_raw = $item->get_valid_to();
                if (!$valid_to_raw) {
                    $result = '-';
                    break;
                }

                $highlight = $item->is_overdue() || $item->is_pending_suspension();
                $color = $highlight ? 'color: #d63638; font-weight: bold;' : '';
                $result = '<span style="' . esc_attr($color) . '">' . esc_html(wp_date('Y-m-d', $valid_to_raw->getTimestamp())) . '</span>';
                if ($item->is_overdue()) {
                    $result .= '<br><small style="color: #d63638;">' . esc_html__('Overdue', 'woocommerce-p24') . '</small>';
                } elseif ($item->is_pending_suspension()) {
                    $result .= '<br><small style="color: #94660c;">' . esc_html__('Pending suspension', 'woocommerce-p24') . '</small>';
                }
                break;

            case 'status':
                $result = sprintf(
                    '<span style="border-radius: 4px; padding: 4px 8px; font-weight: 600; display: inline-block; line-height: 1; %s">%s</span>',
                    esc_attr($this->get_status_inline_style($item)),
                    esc_html($item->get_status_label())
                );

                if ((int) $item->get_status() === Subscription::STATUS_CANCELLED) {
                    $cancelled_by = $item->get_cancelled_by_label();

                    if ($cancelled_by) {
                        $result .= '<br><small style="color: #666;">' . esc_html($cancelled_by) . '</small>';
                    }
                }
                break;
        }

        return $result;
    }

    public function column_id($item)
    {
        return '#' . esc_html($item->get_id());
    }

    public function column_actions($item)
    {
        $status = (int) $item->get_status();
        $sub_id = $item->get_id();
        $buttons = '';

        $confirm_resume = __('Are you sure you want to resume this subscription?', 'woocommerce-p24');
        $confirm_suspend = __('Are you sure you want to suspend this subscription?', 'woocommerce-p24');
        $confirm_cancel = __('Cancel this subscription?', 'woocommerce-p24');
        $confirm_delete = __('Are you sure you want to delete this subscription?', 'woocommerce-p24');

        if ($status === Subscription::STATUS_SUSPENDED) {
            $buttons .= $this->action_button('resume', $sub_id, __('Resume', 'woocommerce-p24'), 'button-primary', $confirm_resume);
            $buttons .= $this->action_button('cancel', $sub_id, __('Cancel subscription', 'woocommerce-p24'), '', $confirm_cancel, 'color: #b32d2e; border-color: #b32d2e;');
        } elseif (in_array($status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PROCESSING], true)) {
            $buttons .= $this->action_button('suspend', $sub_id, __('Suspend', 'woocommerce-p24'), '', $confirm_suspend);
        } elseif ($item->is_pending_deletable()) {
            $buttons .= $this->action_button('delete', $sub_id, __('Delete', 'woocommerce-p24'), '', $confirm_delete, 'color: #b32d2e; border-color: #b32d2e;');
        }

        return $buttons;
    }

    private function action_button(string $action, int $sub_id, string $label, string $extra_class = '', string $confirm = '', string $inline_style = ''): string
    {
        $url = wp_nonce_url(
            add_query_arg(
                ['page' => 'p24-subscriptions', 'p24_action' => $action, 'subscription_id' => $sub_id],
                admin_url('admin.php')
            ),
            'p24_subscription_action_' . $sub_id
        );

        $class = trim('button button-small ' . $extra_class);
        $onclick = $confirm ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '';
        $style = $inline_style ? ' style="' . esc_attr($inline_style) . '"' : '';

        return sprintf('<a href="%s" class="%s"%s%s>%s</a>', esc_url($url), esc_attr($class), $style, $onclick, esc_html($label));
    }

    private function format_date_time_cell(?\DateTime $datetime): string
    {
        if (!$datetime) {
            return '-';
        }

        return esc_html(wp_date('Y-m-d', $datetime->getTimestamp()))
            . '<br><small style="color: #777;">'
            . esc_html(wp_date('H:i', $datetime->getTimestamp()))
            . '</small>';
    }

    private function get_status_inline_style(Subscription $item): string
    {
        if ($item->is_overdue()) {
            return 'background: #f8d7da; color: #842029;';
        }

        if ($item->is_pending_suspension()) {
            return 'background: #f8dda7; color: #94660c;';
        }

        switch ($item->get_status()) {
            case Subscription::STATUS_ACTIVE:
                return 'background: #c6e1c6; color: #5b841b;';
            case Subscription::STATUS_SUSPENDED:
                return 'background: #f8dda7; color: #94660c;';
            case Subscription::STATUS_PENDING:
            case Subscription::STATUS_PROCESSING:
                return 'background: #c8d7e1; color: #2e4453;';
            default:
                return 'background: #e5e5e5; color: #777777;';
        }
    }

    protected function get_views()
    {
        $status = $this->get_param('status');
        $retry_days = Subscriptions::retry_days();

        $views = [
            ''          => __('All', 'woocommerce-p24'),
            'active'    => __('Active', 'woocommerce-p24'),
            'pending'   => __('Pending payment', 'woocommerce-p24'),
            'processing'=> __('Pending payment (renewal)', 'woocommerce-p24'),
            'suspended' => __('Suspended', 'woocommerce-p24'),
            'cancelled' => __('Cancelled', 'woocommerce-p24'),
            'inactive'  => __('Inactive', 'woocommerce-p24'),
        ];

        if ($retry_days > 0) {
            $views = [
                ''          => __('All', 'woocommerce-p24'),
                'active'    => __('Active', 'woocommerce-p24'),
                'overdue'   => __('Overdue', 'woocommerce-p24'),
                'pending'   => __('Pending payment', 'woocommerce-p24'),
                'processing'=> __('Pending payment (renewal)', 'woocommerce-p24'),
                'suspended' => __('Suspended', 'woocommerce-p24'),
                'cancelled' => __('Cancelled', 'woocommerce-p24'),
                'inactive'  => __('Inactive', 'woocommerce-p24'),
            ];
        }

        $current_page = add_query_arg([
            'page'    => 'p24-subscriptions',
            'status'  => sanitize_key($_GET['status'] ?? ''),
            'orderby' => sanitize_key($_GET['orderby'] ?? ''),
            'order'   => sanitize_key($_GET['order'] ?? ''),
        ], admin_url('admin.php'));

        $links = [];

        foreach ($views as $key => $view) {
            $class = $status === $key ? 'class="current"' : '';
            $url = add_query_arg(['status' => $key], $current_page);
            $links[] = '<a href="' . esc_url($url) . '" ' . $class . '>' . esc_html($view) . '</a>';
        }

        return $links;
    }

    public function get_param(string $name): string
    {
        return $this->get_params()[$name];
    }

    public function get_params(): array
    {
        $statuses = ['active', 'pending', 'processing', 'suspended', 'cancelled', 'inactive'];

        if (Subscriptions::retry_days() > 0) {
            $statuses[] = 'overdue';
        }

        $values = [
            'order'   => ['asc', 'desc'],
            'orderby' => ['id', 'user', 'product', 'created', 'checked', 'valid'],
            'status'  => $statuses,
        ];

        $result = [];

        foreach ($values as $key => $possible_values) {
            $value = !empty($_GET[$key]) ? sanitize_key($_GET[$key]) : '';

            if (!in_array($value, $possible_values, true)) {
                $value = '';
            }

            $result[$key] = $value;
        }

        return $result;
    }

    protected function get_sortable_columns()
    {
        $sortable = [
            'id'      => ['id', true],
            'user'    => ['user', true],
            'product' => ['product', true],
            'checked' => ['checked', true],
            'valid'   => ['valid', true],
        ];

        if (Subscription::has_created_at_column()) {
            $sortable['created'] = ['created', true];
        }

        return $sortable;
    }

    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="element[]" value="%s" />', esc_attr($item->get_id()));
    }

    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $now = new \DateTime();
        $params = $this->get_params();

        $where = [];
        $order = $params['order'] ? strtoupper($params['order']) : 'ASC';
        $order_by = 'customer_name, product_id';

        $order_option = [
            'id'      => 't.id',
            'user'    => 'customer_name',
            'checked' => 't.checked_at',
            'valid'   => 't.valid_to',
            'product' => 'product_title',
        ];

        if (Subscription::has_created_at_column()) {
            $order_option['created'] = 't.created_at';
        }

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

        if (isset($order_option[$params['orderby']])) {
            $order_by = $order_option[$params['orderby']];
        }

        if (isset($where_options[$params['status']])) {
            $where = $where_options[$params['status']];
        }

        $select_fields = [
            't.id', 't.order_id', 't.valid_to', 't.checked_at', 't.status', 't.product_id',
            'user.user_nicename AS customer_name', 'user.user_email AS customer_email',
            'product.post_title AS product_title',
        ];

        if (Subscription::has_created_at_column()) {
            $select_fields[] = 't.created_at';
        }

        if (Subscription::has_start_order_id_column()) {
            $select_fields[] = 't.start_order_id';
        }

        if (Subscription::has_cancelled_by_column()) {
            $select_fields[] = 't.cancelled_by';
        }

        $this->items = Subscription::findAll([
            'select' => $select_fields,
            'where'  => $where,
            'order'  => $order_by . ' ' . $order,
        ]);
    }

    public function display()
    {
        $singular = $this->_args['singular'] ?? '';

        $this->display_tablenav('top');

        echo '<div class="p24-subscriptions-table-scroll">';

        $this->screen->render_screen_reader_content('heading_list');
        ?>
        <table class="wp-list-table <?php echo esc_attr(implode(' ', $this->get_table_classes())); ?>">
            <thead>
            <tr>
                <?php $this->print_column_headers(); ?>
            </tr>
            </thead>
            <tbody id="the-list"<?php
            if ($singular) {
                echo " data-wp-lists='list:" . esc_attr($singular) . "'";
            }
            ?>>
                <?php $this->display_rows_or_placeholder(); ?>
            </tbody>
            <tfoot>
            <tr>
                <?php $this->print_column_headers(false); ?>
            </tr>
            </tfoot>
        </table>
        <?php

        echo '</div>';

        $this->display_tablenav('bottom');
    }
}
