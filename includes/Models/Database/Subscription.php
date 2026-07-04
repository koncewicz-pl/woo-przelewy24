<?php

namespace WC_P24\Models\Database;

use DateTime;
use WC_Customer;
use WC_Order;
use WC_P24\Gateways\Gateways_Manager;
use WC_P24\Models\Transaction;
use WC_P24\Multicurrency\Multicurrency;
use WC_P24\Subscriptions\Product\Product;
use WC_P24\Subscriptions\Subscriptions;
use WC_P24\Utilities\Logger;
use WC_P24\Utilities\Payment_Methods;
use WC_P24\Utilities\Record;

if (!defined('ABSPATH')) {
    exit;
}

class Subscription extends Record
{
    const STATUS_PENDING = 0;
    const STATUS_ACTIVE = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_SUSPENDED = 3;
    const STATUS_CANCELLED = 4;

    const CANCELLED_BY_CUSTOMER = 1;
    const CANCELLED_BY_ADMIN = 2;
    const CANCELLED_BY_SYSTEM = 3;

    private int $id = 0;
    private int $status = 0;
    private int $customer_id = 0;
    private int $order_id = 0;
    private int $product_id = 0;
    private ?int $card_id = null;
    private ?int $start_order_id = null;
    private ?DateTime $valid_to = null;
    private ?DateTime $checked_at = null;
    private ?DateTime $created_at = null;
    private ?int $cancelled_by = null;
    private ?Reference $card;
    private ?WC_Order $order;
    private ?WC_Customer $customer;
    private ?Product $product;

    public function __construct(int $subscription_id = 0)
    {
        if ($subscription_id > 0) {
            $this->id = $subscription_id;
        }

        if ($this->get_id()) {
            $this->find();
        }
    }

    public static function get(int $subscription_id): ?Subscription
    {
        $subscription = new self($subscription_id);

        return $subscription->get_id() ? $subscription : null;
    }

    public static function table_name(): string
    {
        return self::db()->prefix . 'wc_p24_subscriptions';
    }

    protected static function primary_key(): string
    {
        return 'id';
    }

    protected static function get_fields(): array
    {
        return [
            'id' => ['format' => '%d'],
            'user_id' => ['format' => '%d'],
            'product_id' => ['format' => '%d'],
            'card_id' => ['format' => '%d'],
            'order_id' => ['format' => '%d'],
            'start_order_id' => ['format' => '%d'],
            'valid_to' => ['format' => '%s', 'parse' => function (DateTime $value): string {
                return $value->format('Y-m-d H:i:s');
            }],
            'status' => ['format' => '%d'],
            'checked_at' => ['format' => '%s', 'parse' => function (DateTime $value): string {
                return $value->format('Y-m-d H:i:s');
            }],
            'created_at' => ['format' => '%s', 'parse' => function (DateTime $value): string {
                return $value->format('Y-m-d H:i:s');
            }],
            'cancelled_by' => ['format' => '%d'],
        ];
    }

    public static function maybe_update_database_schema(): void
    {
        global $wpdb;

        $table = self::table_name();

        if (!self::has_created_at_column()) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `created_at` DATETIME DEFAULT NULL");
            self::$has_created_at_column_cache = true;
        }

        if (!self::has_start_order_id_column()) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `start_order_id` BIGINT DEFAULT NULL");
            self::$has_start_order_id_column_cache = true;
        }

        if (!self::has_cancelled_by_column()) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `cancelled_by` TINYINT DEFAULT NULL");
            self::$has_cancelled_by_column_cache = true;
        }
    }

    private static ?bool $has_created_at_column_cache = null;
    private static ?bool $has_start_order_id_column_cache = null;
    private static ?bool $has_cancelled_by_column_cache = null;

    private static function has_column(string $column_name): bool
    {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            $column_name
        ));
    }

    public static function has_created_at_column(): bool
    {
        if (self::$has_created_at_column_cache === null) {
            self::$has_created_at_column_cache = self::has_column('created_at');
        }

        return self::$has_created_at_column_cache;
    }

    public static function has_start_order_id_column(): bool
    {
        if (self::$has_start_order_id_column_cache === null) {
            self::$has_start_order_id_column_cache = self::has_column('start_order_id');
        }

        return self::$has_start_order_id_column_cache;
    }

    public static function has_cancelled_by_column(): bool
    {
        if (self::$has_cancelled_by_column_cache === null) {
            self::$has_cancelled_by_column_cache = self::has_column('cancelled_by');
        }

        return self::$has_cancelled_by_column_cache;
    }

    /**
     * @param DateTime|string|null $value
     */
    private static function validate_datetime($value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function set_primary_key(int $value): void
    {
        $this->new_record = false;
        $this->id = $value;
    }

    public function set_status(int $status)
    {
        $this->attributes['status'] = $status;
        $this->status = $status;
    }

    public function set_card_id(?int $card_id = null): void
    {
        $this->attributes['card_id'] = $card_id;
        $this->card_id = $card_id;
    }

    public function set_order_id(int $order_id): void
    {
        $this->attributes['order_id'] = $order_id;
        $this->order_id = $order_id;
        $this->order = null;
    }

    public function set_customer_id(?int $customer_id = null): void
    {
        if (!$customer_id) {
            $customer_id = get_current_user_id();
        }

        $this->customer_id = $customer_id;
        $this->attributes['user_id'] = $customer_id;
        $this->customer = null;
    }

    public function set_product_id(int $product_id): void
    {
        $this->attributes['product_id'] = $product_id;
        $this->product_id = $product_id;
        $this->product = null;
    }

    public function set_checked_at(DateTime $last_checked): void
    {
        $date = self::validate_datetime($last_checked);

        if (!$date) {
            return;
        }

        $this->attributes['checked_at'] = $date;
        $this->checked_at = $date;
    }

    public function set_valid_to(?DateTime $valid_to = null): void
    {
        $date = $valid_to === null ? null : self::validate_datetime($valid_to);
        $this->attributes['valid_to'] = $date;
        $this->valid_to = $date;
    }

    public function set_created_at(DateTime $created_at): void
    {
        $date = self::validate_datetime($created_at);

        if (!$date) {
            return;
        }

        $this->attributes['created_at'] = $date;
        $this->created_at = $date;
    }

    public function set_start_order_id(?int $order_id): void
    {
        $this->attributes['start_order_id'] = $order_id;
        $this->start_order_id = $order_id;
    }

    public function set_cancelled_by(?int $cancelled_by): void
    {
        $this->attributes['cancelled_by'] = $cancelled_by;
        $this->cancelled_by = $cancelled_by;
    }

    protected function get_primary_key(): int
    {
        return $this->get_id();
    }

    public function get_id(): ?int
    {
        return $this->id;
    }

    public function get_checked_at(): ?DateTime
    {
        return $this->checked_at;
    }

    public function get_created_at(): ?DateTime
    {
        if ($this->created_at !== null) {
            return $this->created_at;
        }

        $order_id = $this->get_start_order_id() ?: $this->get_order_id();

        if (!$order_id) {
            return null;
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return null;
        }

        $created = $order->get_date_created();

        return $created ? new DateTime($created->format('Y-m-d H:i:s')) : null;
    }

    public function get_start_order_id(): ?int
    {
        return $this->start_order_id;
    }

    public function get_initial_order_id(): int
    {
        if (!empty($this->start_order_id)) {
            return (int) $this->start_order_id;
        }

        $order = $this->get_order();

        return $order && $order->get_parent_id() ? (int) $order->get_parent_id() : $this->get_order_id();
    }

    public function get_order_id(): int
    {
        return $this->order_id;
    }

    public function get_card(): ?Reference
    {
        if (!isset($this->card) && $this->get_card_id()) {
            $this->card = Reference::get($this->get_card_id());
        }

        return $this->card;
    }

    public function get_card_id(): ?int
    {
        return $this->card_id;
    }

    public function get_customer_id(): int
    {
        return $this->customer_id;
    }

    public function get_valid_to(): ?DateTime
    {
        return $this->valid_to;
    }

    public function get_product_id(): int
    {
        return $this->product_id;
    }

    public function get_customer(): ?WC_Customer
    {
        if (!isset($this->customer) && $this->get_customer_id()) {
            $this->customer = new WC_Customer($this->get_customer_id());
        }

        return $this->customer;
    }

    public function get_status(): int
    {
        return $this->status;
    }

    public function is_in_retry_window(): bool
    {
        $retry_days = Subscriptions::retry_days();

        if ($retry_days === 0) {
            return false;
        }

        $valid_to = $this->get_valid_to();

        if (!$valid_to) {
            return false;
        }

        $retry_until = clone $valid_to;
        $retry_until->modify('+' . $retry_days . ' days');
        $retry_until->setTime(23, 59, 59);

        return new DateTime() <= $retry_until;
    }

    public function is_overdue(): bool
    {
        return $this->get_status() === self::STATUS_ACTIVE
            && $this->get_valid_to() !== null
            && $this->get_valid_to() < new DateTime()
            && $this->is_in_retry_window();
    }

    public function is_retry_exhausted(): bool
    {
        return !$this->is_in_retry_window()
            && $this->get_valid_to() !== null
            && $this->get_valid_to() < new DateTime();
    }

    public function is_pending_suspension(): bool
    {
        return $this->is_retry_exhausted()
            && in_array($this->get_status(), [self::STATUS_ACTIVE, self::STATUS_PROCESSING], true);
    }

    /**
     * @return array{0: DateTime, 1: DateTime} [retry_from, today_end]
     */
    public static function cron_renewal_window(): array
    {
        $retry_days = Subscriptions::retry_days();
        $today_end = new DateTime();
        $today_end->setTime(23, 59, 59);

        $retry_from = new DateTime();
        $retry_from->modify('-' . $retry_days . ' days');
        $retry_from->setTime(0, 0, 0);

        return [$retry_from, $today_end];
    }

    public function is_valid_to_eligible_for_cron_renewal(?DateTime $valid_to = null): bool
    {
        $valid_to = $valid_to ?? $this->get_valid_to();

        if (!$valid_to) {
            return false;
        }

        [$retry_from, $today_end] = self::cron_renewal_window();

        return $valid_to >= $retry_from && $valid_to <= $today_end;
    }

    private function align_valid_to_for_cron_renewal(): void
    {
        $valid_to = $this->get_valid_to();
        $now = new DateTime();

        if ($valid_to && $valid_to > $now) {
            return;
        }

        if ($this->is_valid_to_eligible_for_cron_renewal($valid_to)) {
            return;
        }

        $due = new DateTime();

        if ($valid_to instanceof DateTime) {
            $due->setTime(
                (int) $valid_to->format('H'),
                (int) $valid_to->format('i'),
                (int) $valid_to->format('s')
            );
        }

        $this->set_valid_to($due);
    }

    public function get_status_label(): string
    {
        if ($this->is_overdue()) {
            return __('Overdue', 'woocommerce-p24');
        }

        if ($this->is_pending_suspension()) {
            return __('Pending suspension', 'woocommerce-p24');
        }

        switch ($this->get_status()) {
            case Subscription::STATUS_ACTIVE:
                return __('Active', 'woocommerce-p24');
            case Subscription::STATUS_PROCESSING:
                return __('Pending payment (renewal)', 'woocommerce-p24');
            case Subscription::STATUS_PENDING:
                return __('Pending payment', 'woocommerce-p24');
            case Subscription::STATUS_SUSPENDED:
                return __('Suspended', 'woocommerce-p24');
            case Subscription::STATUS_CANCELLED:
                return __('Cancelled', 'woocommerce-p24');
            default:
                return __('Inactive', 'woocommerce-p24');
        }
    }

    public function get_cancelled_by(): ?int
    {
        return $this->cancelled_by;
    }

    public function get_cancelled_by_label(): ?string
    {
        switch ($this->get_cancelled_by()) {
            case self::CANCELLED_BY_CUSTOMER:
                return __('by customer', 'woocommerce-p24');
            case self::CANCELLED_BY_ADMIN:
                return __('by admin', 'woocommerce-p24');
            case self::CANCELLED_BY_SYSTEM:
                return __('automatically', 'woocommerce-p24');
            default:
                return null;
        }
    }

    public function get_order(): ?WC_Order
    {
        $this->order = null;

        if (isset($this->order_id)) {
            $order = wc_get_order($this->get_order_id());

            if ($order instanceof WC_Order) {
                $this->order = $order;
            }
        }

        return $this->order;
    }

    /**
     * Order statuses that allow creating a renewal charge (WooCommerce often keeps card orders on processing).
     */
    private function is_order_fulfilled_for_renewal(WC_Order $order): bool
    {
        if ($order->is_paid()) {
            return true;
        }

        $allowed = apply_filters('wc_p24_subscription_renewal_order_statuses', [
            'completed',
            'processing',
            'on-hold',
            'p24-confirmed',
            'p24_confirmed',
            'p24-success',
            'p24_success',
        ]);

        return $order->has_status($allowed);
    }

    /**
     * Last paid order used as billing template for renew (current order_id or parent chain after failed renew).
     */
    private function resolve_renewal_reference_order(): ?WC_Order
    {
        $order = $this->get_order();

        if (!$order instanceof WC_Order && $this->get_start_order_id()) {
            $order = wc_get_order($this->get_start_order_id());
        }

        if (!$order instanceof WC_Order) {
            return null;
        }

        if ($this->is_order_fulfilled_for_renewal($order)) {
            return $order;
        }

        $parent_id = $order->get_parent_id();
        while ($parent_id) {
            $parent = wc_get_order($parent_id);
            if (!$parent instanceof WC_Order) {
                break;
            }
            if ($this->is_order_fulfilled_for_renewal($parent)) {
                return $parent;
            }
            $parent_id = $parent->get_parent_id();
        }

        return null;
    }

    public function get_product(): ?Product
    {
        if (isset($this->product_id)) {
            $this->product = wc_get_product($this->get_product_id());
        }

        return $this->product;
    }

    public function get_downloads(): array
    {
        $order = $this->get_order();

        if (!$order) return [];

        $items = $order->get_downloadable_items();

        $items = array_filter($items, function ($item) {
            return $item['product_id'] == $this->product_id;
        });

        return $items;
    }

    public function is_available(): bool
    {
        if (!in_array($this->get_status(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_PROCESSING], true)) {
            return false;
        }

        $valid_to = $this->get_valid_to();

        return $valid_to instanceof DateTime && $valid_to >= new DateTime();
    }

    public function is_pending_deletable(): bool
    {
        if ($this->get_status() !== self::STATUS_PENDING) {
            return false;
        }

        $created_at = $this->get_created_at();

        if (!$created_at) {
            return false;
        }

        $limit = new DateTime();
        $limit->modify('-24 hours');

        return $created_at < $limit;
    }

    public function is_pending(): bool
    {
        return $this->get_status() == Subscription::STATUS_PENDING;
    }

    public function is_cancelable(): bool
    {
        return in_array($this->get_status(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_PROCESSING]);
    }

    public function activate(int $days, int $card_id): bool
    {
        if (!in_array($this->get_status(), [Subscription::STATUS_PENDING])) {
            return false;
        }

        $valid_to = new DateTime();
        $valid_to->modify('+' . $days . 'days');

        $this->set_card_id($card_id);
        $this->set_checked_at(new DateTime());
        $this->set_valid_to($valid_to);
        $this->set_status(Subscription::STATUS_ACTIVE);

        $order = $this->get_order();
        $order && $this->update_files_expires($order, $this->valid_to);

        return $this->update();
    }

    public function renew(): void
    {
        if (!in_array($this->get_status(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_PROCESSING])) {
            return;
        }

        if ($this->get_status() === self::STATUS_PROCESSING) {
            $pending_renewal = $this->get_order();

            if (
                $pending_renewal instanceof WC_Order
                && (int) $pending_renewal->get_meta('_p24_subscription_renew') === $this->get_id()
                && $pending_renewal->has_status(['pending', 'on-hold', 'failed'])
            ) {
                Logger::log(
                    '[P24] Subscription renew skipped: renewal order still open (subscription #'
                    . $this->get_id() . ', order #' . $pending_renewal->get_id() . ')',
                    Logger::WARNING
                );

                return;
            }
        }

        $reference_order = $this->resolve_renewal_reference_order();

        if (!$reference_order) {
            Logger::log(
                '[P24] Subscription renew skipped: no fulfilled order (subscription #' . $this->get_id()
                . ', order_id ' . $this->get_order_id() . ')',
                Logger::WARNING
            );
            return;
        }

        if (!$this->card_id) {
            $this->suspense();
            $this->save();
            return;
        }

        $product = $this->get_product();

        if (!$product instanceof Product) {
            Logger::log('[P24] Subscription renew skipped: product missing (subscription #' . $this->get_id() . ')', Logger::WARNING);

            return;
        }

        Multicurrency::setup($reference_order->get_currency());

        $order = wc_create_order();
        $parent_id = $reference_order->get_parent_id() ?: $reference_order->get_id();
        $order->set_parent_id($parent_id);
        $order->set_address($reference_order->get_address('billing'), 'billing');
        $order->set_currency($reference_order->get_currency());
        $order->set_customer_id($this->get_customer_id());
        $order->add_product($product, 1);
        $order->update_meta_data('_wc_order_attribution_source_type', 'utm');
        $order->update_meta_data('_wc_order_attribution_utm_source', __('P24 recurring subscription', 'woocommerce-p24'));

        [$item] = array_values($order->get_items());
        $item->update_meta_data('_p24_subscription_id', $this->get_id());

        $order->calculate_totals();
        $order->set_status('pending');
        $order->add_order_note(__('Subscription renew order', 'woocommerce-p24'));
        $order->update_meta_data('_p24_has_subscription', true);
        $order->update_meta_data('_p24_subscription_renew', $this->get_id());
        $order->set_payment_method($reference_order->get_payment_method());
        $order->set_payment_method_title(__('Credit card - renew subscription', 'woocommerce-p24'));

        $this->set_status(Subscription::STATUS_PROCESSING);
        $this->set_checked_at(new DateTime());

        $card = $this->get_card();

        if (!$card) {
            return;
        }

        $order->save();
        $this->set_order_id($order->get_id());
        $this->save();

        $method_id = Gateways_Manager::get_method_id_matching_group(Payment_Methods::CARD_PAYMENT_ALT, $order->get_total());

        $transaction = new Transaction($order->get_id(), $method_id, true);
        $transaction->set_card($card->to_card_simple(), Transaction::CARD_RECURRING);
        $transaction->register();

        try {
            $transaction->do_payment();
        } catch (\Exception $e) {
            $order->add_order_note(__('Attempted card charge failed', 'woocommerce-p24'));
            Logger::log($e->getMessage(), Logger::EXCEPTION);

            if ($this->is_in_retry_window()) {
                $this->set_status(self::STATUS_ACTIVE);
                $this->set_checked_at(new DateTime());
                $this->save();
            } else {
                $this->suspense();
            }
        }
    }

    public function extend(): bool
    {
        if (!in_array($this->get_status(), [Subscription::STATUS_PROCESSING])) {
            return false;
        }

        $order = $this->get_order();

        if (!$order) return false;

        $product = $this->get_product();

        if (!$product instanceof Product) {
            return false;
        }

        $days = $product->get_days();

        $valid_to = new DateTime();
        $valid_to->modify('+' . $days . ' days');

        $this->set_valid_to($valid_to);
        $this->set_checked_at(new DateTime());
        $this->set_status(Subscription::STATUS_ACTIVE);

        $this->update_files_expires($order, $valid_to);

        return $this->update();
    }

    public function update_files_expires(WC_Order $order, DateTime $valid_to): void
    {
        $valid_to = clone $valid_to;
        $valid_to->setTime(0, 0, 0);

        $items = $order->get_downloadable_items();
        $permissions = wc_get_customer_download_permissions($order->get_customer_id());
        $permissions_by_did = [];

        foreach ($permissions as $permission) {
            $permissions_by_did[$permission->download_id] = $permission->permission_id;
        }

        foreach ($items as $download_item) {
            $permission_id = $permissions_by_did[$download_item['download_id']] ?? null;
            if (!$permission_id) continue;
            $download = new \WC_Customer_Download($permission_id);
            if (!$download->get_id()) continue;
            $download->set_access_expires($valid_to->getTimestamp());
            $download->save();
        }
    }

    public function cancel(int $cancelled_by = self::CANCELLED_BY_CUSTOMER): bool
    {
        if (!$this->is_cancelable()) return false;

        $this->set_checked_at(new DateTime());
        $this->set_card_id(null);
        $this->set_cancelled_by($cancelled_by);
        $this->set_status(Subscription::STATUS_CANCELLED);

        return $this->update();
    }

    public function resume(): bool
    {
        if ($this->get_status() !== self::STATUS_SUSPENDED) {
            return false;
        }

        $this->align_valid_to_for_cron_renewal();
        $this->set_checked_at(new DateTime());
        $this->set_status(self::STATUS_ACTIVE);

        return $this->update();
    }

    public function admin_cancel(): bool
    {
        if (!in_array($this->get_status(), [self::STATUS_SUSPENDED, self::STATUS_ACTIVE, self::STATUS_PROCESSING])) {
            return false;
        }

        $this->set_checked_at(new DateTime());
        $this->set_card_id(null);
        $this->set_cancelled_by(self::CANCELLED_BY_ADMIN);
        $this->set_status(self::STATUS_CANCELLED);

        return $this->update();
    }

    public function suspense(): bool
    {
        if (!in_array($this->get_status(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_PROCESSING])) {
            return false;
        }

        $this->set_checked_at(new DateTime());
        $this->set_status(Subscription::STATUS_SUSPENDED);

        return $this->update();
    }

    protected static function joins(): string
    {
        return 'INNER JOIN ' . self::db()->prefix . 'posts as product ON product.ID = t.product_id
                INNER JOIN ' . self::db()->prefix . 'users as user ON user.ID = t.user_id
                LEFT JOIN ' . Reference::table_name() . ' as card ON card.id = t.card_id';
    }

    public function parse(array $data): void
    {
        foreach ($data as $key => $value) {
            if (isset(self::get_fields()[$key])) {
                switch ($key) {
                    case 'id':
                        $this->set_primary_key((int)$value);
                        break;
                    case 'user_id':
                        $this->set_customer_id((int)$value);
                        break;
                    case 'product_id':
                        $this->set_product_id((int)$value);
                        break;
                    case 'order_id':
                        $this->set_order_id((int)$value);
                        break;
                    case 'card_id':
                        $this->set_card_id($value);
                        break;
                    case 'valid_to':
                        $this->set_valid_to($value ? new DateTime($value) : null);
                        break;
                    case 'status':
                        $this->set_status((int)$value);
                        break;
                    case 'checked_at':
                        if ($value) {
                            $this->set_checked_at(new DateTime($value));
                        } else {
                            $this->checked_at = null;
                        }
                        break;
                    case 'created_at':
                        if ($value) {
                            $this->set_created_at(new DateTime($value));
                        }
                        break;
                    case 'start_order_id':
                        $this->set_start_order_id($value !== null ? (int) $value : null);
                        break;
                    case 'cancelled_by':
                        $this->set_cancelled_by($value !== null && $value !== '' ? (int) $value : null);
                        break;
                }
            } else {
                $this->additionals[$key] = $value;
            }
        }
    }
}
