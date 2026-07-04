<?php
/**
 * @var \WC_P24\Subscriptions\Product\Product $product
 */

defined('ABSPATH') || exit;

use WC_P24\Render;
use WC_P24\Subscriptions\Product\Product;

if ($product->get_type() !== Product::TYPE) {
    return;
}

$days = $product->get_days();

if ($days <= 0) {
    return;
}

// Determine the renewal period text
if ($days % 30 === 0) {
    $months = $days / 30;
    $period_text = sprintf(
        _n(
            'Subscription renews every %d month',
            'Subscription renews every %d months',
            $months,
            'woocommerce-p24'
        ),
        $months
    );
} elseif ($days % 7 === 0) {
    $weeks = $days / 7;
    $period_text = sprintf(
        _n(
            'Subscription renews every %d week',
            'Subscription renews every %d weeks',
            $weeks,
            'woocommerce-p24'
        ),
        $weeks
    );
} else {
    $period_text = sprintf(
        _n(
            'Subscription renews every %d day',
            'Subscription renews every %d days',
            $days,
            'woocommerce-p24'
        ),
        $days
    );
}

?>

<div class="subscription-period-info">
    <span class="subscription-period-badge">
        <span class="subscription-period-badge__icon">
            <?php Render::template('subscription/icon', ['name' => 'calendar'], true); ?>
        </span>
        <span class="subscription-period-badge__text"><?= esc_html($period_text); ?></span>
    </span>
</div>
